<?php
/*
  Web Push (RFC 8030 / 8291 / 8292) — a browser / PWA notification channel.

  Sibling of the Telegram/Pushover/ntfy/Gotify channels: an instance-level VAPID
  keypair (the shared credential) plus each user's own browser subscriptions (the
  personal destinations, one per device). A fired notification is encrypted per
  RFC 8291 and delivered to every one of the user's subscriptions.

  ## The crypto/dependency decision

  Wallos ships a deliberately lean image and does not carry a Composer
  dependency tree, so this is a minimal self-contained implementation on the
  OpenSSL extension rather than a vendored web-push library. Everything a push
  needs is already in the base image:

    * VAPID keypair    EC P-256, openssl_pkey_new()
    * VAPID JWT (ES256) openssl_sign() + a DER->raw signature conversion
    * RFC 8291 payload  openssl_pkey_derive() (ECDH P-256), hash_hkdf() (HKDF),
                        openssl_encrypt() (AES-128-GCM)

  The two hard parts — the ES256 JWT and the aes128gcm content encoding — are
  covered by known-answer tests against the RFC 8291 test vector, so the
  self-implementation is held to the standard rather than trusted.

  All values on the wire are base64url without padding, the encoding VAPID and
  the Push API use throughout.
*/

require_once __DIR__ . '/config_helper.php';
require_once __DIR__ . '/integration_config.php';
require_once __DIR__ . '/ssrf_helper.php';

/**
 * The size every record is padded to, delimiter included.
 *
 * RFC 8291 §4 requires a push service to accept a payload of at least 4096
 * octets, and the aes128gcm header (86 bytes here) and the GCM tag (16) come
 * out of that budget. 2820 leaves room for both with margin, and it is the
 * figure the established PHP and JavaScript implementations settled on, which
 * matters: a size nobody else uses would identify this application as surely as
 * the length it was hiding.
 */
const WALLOS_WEBPUSH_PADDED_RECORD = 2820;

/**
 * The longest plaintext that fits in the record the header promises.
 *
 * RFC 8291 §4 does the arithmetic and states the result: a push service need
 * not accept more than 4096 octets of body, and "absent header (86 octets),
 * padding (minimum 1 octet), and expansion for AEAD_AES_128_GCM (16 octets),
 * this equates to, at most, 3993 octets of plaintext."
 *
 * The number is a real boundary, not a style choice. The header declares a
 * record size of 4096; RFC 8188 §2 has the last record at most that size. A
 * longer payload produces a record that overruns the size its own header
 * announced, and a receiver reading at the declared granularity finds a
 * plaintext octet where the padding delimiter should be — "values other than
 * 0x02 MUST cause the message to be discarded". Such a body is not a push that
 * reveals its length; it is a push that silently arrives nowhere.
 */
const WALLOS_WEBPUSH_MAX_PLAINTEXT = 3993;

/**
 * How long a renewal reminder stays worth delivering, past the renewal itself.
 *
 * The push service holds a message for an offline device and delivers it when
 * the device comes back (RFC 8030 §5.2). The question is how long that should
 * be, and it is a question about *this* message: "Netflix renews in 3 days" is
 * useful up to the renewal and for a short while after — long enough that a
 * phone switched off over a weekend still gets a reminder worth having, and not
 * so long that switching it on after a holiday produces a pile of notices about
 * renewals that happened three weeks ago.
 *
 * Two days past the renewal is that line.
 */
const WALLOS_WEBPUSH_TTL_GRACE = 172800;

/**
 * The ceiling every push service shares: four weeks.
 *
 * RFC 8030 §5.2 sets no maximum — it makes TTL mandatory, and lets a service
 * keep a message for less than asked as long as it says so by returning a TTL
 * header in its response. In practice the large services all stop at 2419200
 * seconds, so nothing above that buys anything.
 *
 * This is also where the previous fixed value came from: it was the ceiling,
 * i.e. "keep this as long as you possibly can", applied to a message that stops
 * being true after a few days.
 */
const WALLOS_WEBPUSH_TTL_MAX = 2419200;

/**
 * The TTL for a reminder covering these subscriptions.
 *
 * One message can name several subscriptions, so it stays worth delivering
 * until the last of them has renewed, plus the grace period. A message with no
 * subscriptions in it — a period summary on its own — gets the grace period.
 *
 * @param array $perUser  the subscriptions in this message, each with a 'days'
 *                        count until renewal
 * @param int   $grace    seconds to keep it past the last renewal
 * @return int seconds, never above what a push service will honour
 */
function wallos_webpush_ttl_for_renewals(array $perUser, $grace = WALLOS_WEBPUSH_TTL_GRACE)
{
    $furthest = 0;

    foreach ($perUser as $subscription) {
        $days = (int) ($subscription['days'] ?? 0);
        if ($days > $furthest) {
            $furthest = $days;
        }
    }

    return min($furthest * 86400 + $grace, WALLOS_WEBPUSH_TTL_MAX);
}

/* -------------------------------------------------------------------------
   base64url
   ------------------------------------------------------------------------- */

/**
 * @param string $binary
 * @return string
 */
function wallos_webpush_b64u_encode($binary)
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

/**
 * @param string $text
 * @return string binary, or '' when the input is not valid base64url
 */
function wallos_webpush_b64u_decode($text)
{
    $text = strtr((string) $text, '-_', '+/');
    $padded = $text . str_repeat('=', (4 - strlen($text) % 4) % 4);
    $decoded = base64_decode($padded, true);

    return $decoded === false ? '' : $decoded;
}

/* -------------------------------------------------------------------------
   Reconstructing OpenSSL key handles from raw EC material

   VAPID and the Push API exchange raw EC points and scalars, not PEM. These
   rebuild the PEM the OpenSSL functions expect from that raw material, using
   the fixed ASN.1 template for the prime256v1 (P-256) curve.
   ------------------------------------------------------------------------- */

/**
 * A P-256 private key PEM (SEC1) from the raw private scalar and public point.
 *
 * @param string $d     32-byte private scalar
 * @param string $point 65-byte uncompressed public point (0x04 || X || Y)
 * @return string PEM
 */
function wallos_webpush_ec_private_pem($d, $point)
{
    // SEQUENCE { version(1), privateKey OCTET STRING(d), [0] namedCurve OID,
    //            [1] BIT STRING(publicPoint) }. Lengths are fixed for P-256.
    $der = "\x02\x01\x01"
        . "\x04\x20" . $d
        . "\xA0\x0A\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07"
        . "\xA1\x44\x03\x42\x00" . $point;
    $der = "\x30" . chr(strlen($der)) . $der;

    return "-----BEGIN EC PRIVATE KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END EC PRIVATE KEY-----\n";
}

/**
 * A P-256 public key PEM (SubjectPublicKeyInfo) from a raw uncompressed point.
 *
 * @param string $point 65-byte uncompressed public point (0x04 || X || Y)
 * @return string PEM
 */
function wallos_webpush_ec_public_pem($point)
{
    // The fixed 26-byte SPKI prefix for an ecPublicKey on prime256v1, then the
    // 65-byte point inside the trailing BIT STRING.
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/**
 * Left-pads a big-endian integer to a fixed width, the width the raw EC
 * encodings require. OpenSSL may hand back a coordinate with a leading zero
 * byte trimmed.
 *
 * @param string $value
 * @param int    $length
 * @return string
 */
function wallos_webpush_pad($value, $length)
{
    return str_pad($value, $length, "\x00", STR_PAD_LEFT);
}

/* -------------------------------------------------------------------------
   One VAPID keypair for the instance, not one per user

   Asked deliberately in 2026-09 rather than inherited, because the keypair
   looks like a credential and therefore looks as though it ought to belong to
   somebody.

   It does not. RFC 8292 §1 calls it a way for an application server "to
   voluntarily identify itself to a push service": the service uses it to
   recognise a sender over time (§1.1) and to refuse anyone who cannot sign for
   a restricted subscription (§4.2). It never touches the message. What keeps a
   notification private is RFC 8291 — the browser generates its own P-256 key
   pair and a sixteen-octet auth secret for each subscription (§3.1, §3.2),
   keeps the private half, and we encrypt to it with a throwaway key per
   message. wallos_webpush_encrypt() below takes only p256dh and auth and makes
   its own ephemeral pair; the VAPID key is not one of its arguments. So the
   secret that protects the content is already per user, in fact per device, and
   it is not ours to hold.

   A per-user keypair would therefore protect no content. It would only split
   the right to *send*, and that right cannot be split here: one cron run sends
   for everyone and needs every share at once, out of one database, in one
   backup. Whatever leaks the instance key leaks all of them.

   It would cost something, twice over. A per-user key would live in the same
   row as that user's endpoint, so a row leak that today yields nothing sendable
   — the subscription is restricted, and the push service answers 403 without a
   valid token — would start yielding something. And a subscription is bound to
   the key it was made with: subscribe() with a different applicationServerKey
   on a registration that already has one rejects with InvalidStateError. On the
   family tablet where two people share a browser that turns the second person's
   login into an error, where one instance key lets the browser hand back its
   single subscription and the unique endpoint move the device to whoever
   subscribed last — the only answer a browser can honestly give.

   Checked against what others do, and the answer was unanimous: Home Assistant,
   Nextcloud, Mastodon and ntfy all keep one keypair per instance, including the
   two that host thousands of mutually distrusting accounts. No project was
   found that keys VAPID per user; the one place the idea appears in public is a
   web-push-php issue proposing it to survive a key rotation, not for security.

   What this does not protect against, plainly: anyone who can read the database
   or a backup can forge notifications to every device in the household. A key
   per user would not have changed that. The thing that does help is keeping the
   key out of the database altogether — WALLOS_VAPID_PRIVATE_KEY, below.
   ------------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
   VAPID keypair (RFC 8292)
   ------------------------------------------------------------------------- */

/**
 * Generates an instance VAPID keypair: an EC P-256 key, returned as the raw
 * base64url values VAPID uses. The public key is the applicationServerKey the
 * browser subscribes with and the `k` value in the Authorization header; the
 * private key never leaves the server.
 *
 * @return array{public: string, private: string}|null null when OpenSSL fails
 */
function wallos_webpush_generate_vapid_keys()
{
    $resource = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);

    if ($resource === false) {
        return null;
    }

    $details = openssl_pkey_get_details($resource);
    if ($details === false || !isset($details['ec']['d'], $details['ec']['x'], $details['ec']['y'])) {
        return null;
    }

    $d = wallos_webpush_pad($details['ec']['d'], 32);
    $point = "\x04" . wallos_webpush_pad($details['ec']['x'], 32) . wallos_webpush_pad($details['ec']['y'], 32);

    return [
        'public' => wallos_webpush_b64u_encode($point),
        'private' => wallos_webpush_b64u_encode($d),
    ];
}

/**
 * The contact URI for the VAPID `sub` claim. Push services want a way to reach
 * the operator; a real email if the instance has one, otherwise a structurally
 * valid placeholder. Overridable with WALLOS_VAPID_SUBJECT.
 *
 * @param WallosDatabase $db
 * @return string
 */
function wallos_webpush_resolve_subject($db)
{
    if (wallos_env_has('WALLOS_VAPID_SUBJECT')) {
        $subject = trim((string) wallos_env('WALLOS_VAPID_SUBJECT'));
        if ($subject !== '') {
            return $subject;
        }
    }

    $smtp = wallos_get_instance_smtp_config($db);
    $fromEmail = trim((string) ($smtp['values']['from_email'] ?? ''));
    if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL) && strpos($fromEmail, 'wallosapp.com') === false) {
        return 'mailto:' . $fromEmail;
    }

    return 'mailto:wallos@localhost';
}

/**
 * The instance VAPID configuration, resolved the way the other shared
 * notification credentials are: an environment override wins, then the instance
 * database, and if neither carries a keypair one is generated on first use and
 * persisted. Never carries a per-user "custom" mode — a VAPID keypair identifies
 * the whole application server, not a person.
 *
 * @param WallosDatabase $db
 * @return array Result structure with public_key, private_key, subject and deliverable.
 */
function wallos_build_instance_webpush_config($db)
{
    $config = wallos_config_result();
    $config['mode'] = 'instance';

    $privateSecret = wallos_env_secret('WALLOS_VAPID_PRIVATE_KEY');
    $publicManaged = wallos_env_has('WALLOS_VAPID_PUBLIC_KEY');

    if ($privateSecret['managed'] || $publicManaged) {
        // The environment owns the keypair; the database is not consulted, and a
        // half-configured environment is invalid rather than silently mixed with
        // a stored key from the other half.
        $public = $publicManaged ? trim((string) wallos_env('WALLOS_VAPID_PUBLIC_KEY')) : '';
        wallos_config_set($config, 'public_key', $public,
            $publicManaged ? 'environment' : 'default', 'WALLOS_VAPID_PUBLIC_KEY');

        if ($privateSecret['managed']) {
            wallos_config_set($config, 'private_key', (string) $privateSecret['value'],
                $privateSecret['source'], $privateSecret['variable']);
            if ($privateSecret['error'] !== null) {
                $config['valid'] = false;
                wallos_config_add_note($config, $privateSecret['error']);
            }
        } else {
            wallos_config_set($config, 'private_key', '', 'default');
        }
    } else {
        $instance = wallos_get_instance_settings($db, 'webpush');
        $public = (string) ($instance['vapid_public_key'] ?? '');
        $private = (string) ($instance['vapid_private_key'] ?? '');

        if ($public === '' || $private === '') {
            $generated = wallos_webpush_generate_vapid_keys();
            if ($generated !== null) {
                // Claimed, not written: the keypair is generated on first use,
                // and first use is whatever request happens to arrive first —
                // an admin opening the settings page, or the notification cron.
                // Two of them at once both find the rows empty and both
                // generate, and a plain write would let one overwrite the other
                // between its two halves.
                //
                // The damage is not a broken page. A browser that already
                // subscribed is bound to the applicationServerKey it saw, so
                // once the stored pair changes the push service rejects every
                // send for it with 403 — and 403 is not 404/410, so the
                // subscription is never cleaned up either. It fails every night
                // from then on, and nothing on any screen says why the
                // notifications stopped.
                //
                // wallos_claim_instance_settings() inserts both halves in one
                // transaction with DO NOTHING and reads back what holds, so the
                // loser adopts the winner's pair instead of replacing it.
                $claimed = wallos_claim_instance_settings($db, 'webpush', [
                    'vapid_public_key' => $generated['public'],
                    'vapid_private_key' => $generated['private'],
                ], ['vapid_private_key']);

                if ($claimed === null) {
                    wallos_config_add_note($config, 'Could not store the generated VAPID keypair.');
                } else {
                    $public = $claimed['vapid_public_key'];
                    $private = $claimed['vapid_private_key'];
                }
            } else {
                wallos_config_add_note($config, 'Could not generate a VAPID keypair (OpenSSL EC support missing?).');
            }
        }

        wallos_config_set($config, 'public_key', $public, $public !== '' ? 'admin' : 'default');
        wallos_config_set($config, 'private_key', $private, $private !== '' ? 'admin' : 'default');
    }

    wallos_config_set($config, 'subject', wallos_webpush_resolve_subject($db), 'default');

    wallos_finalize_notification_config($config, ['public_key', 'private_key'],
        'The Web Push VAPID keypair is not configured.');

    return $config;
}

/**
 * @param WallosDatabase $db
 * @return array Result structure.
 */
function wallos_get_instance_webpush_config($db)
{
    return wallos_config_cached($db, 'webpush:instance',
        fn() => wallos_build_instance_webpush_config($db));
}

/**
 * API and template representation. The private key is a secret and is reported
 * as a status, never as a value; the public key is meant to reach the browser.
 *
 * @param array $config
 * @return array
 */
function wallos_webpush_public_payload($config)
{
    return [
        'public_key' => (string) ($config['values']['public_key'] ?? ''),
        'private_key' => wallos_secret_status($config, 'private_key'),
        'deliverable' => (bool) ($config['values']['deliverable'] ?? false),
        'valid' => (bool) $config['valid'],
    ];
}

/* -------------------------------------------------------------------------
   VAPID JWT (RFC 8292, ES256)
   ------------------------------------------------------------------------- */

/**
 * Converts an ECDSA signature from OpenSSL's DER encoding to the fixed 64-byte
 * r||s concatenation JWS ES256 requires.
 *
 * @param string $der
 * @return string|null 64 bytes, or null when the DER is malformed
 */
function wallos_webpush_der_to_raw_signature($der)
{
    $offset = 0;
    $length = strlen($der);

    if ($length < 8 || ord($der[$offset++]) !== 0x30) {
        return null;
    }

    // Sequence length (short form is all a P-256 signature ever uses).
    $seqLen = ord($der[$offset++]);
    if ($seqLen & 0x80) {
        $offset += $seqLen & 0x7F;
    }

    if ($offset >= $length || ord($der[$offset++]) !== 0x02) {
        return null;
    }
    $rLen = ord($der[$offset++]);
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;

    if ($offset >= $length || ord($der[$offset++]) !== 0x02) {
        return null;
    }
    $sLen = ord($der[$offset++]);
    $s = substr($der, $offset, $sLen);

    // Strip the sign-byte DER adds when the high bit is set, then fix each half
    // to the 32-byte width ES256 wants.
    $r = wallos_webpush_pad(ltrim($r, "\x00"), 32);
    $s = wallos_webpush_pad(ltrim($s, "\x00"), 32);

    if (strlen($r) !== 32 || strlen($s) !== 32) {
        return null;
    }

    return $r . $s;
}

/**
 * The audience of a VAPID JWT: the origin (scheme://host[:port]) of the push
 * endpoint, without its path.
 *
 * @param string $endpoint
 * @return string|null
 */
function wallos_webpush_endpoint_origin($endpoint)
{
    $parts = parse_url($endpoint);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }

    $origin = strtolower($parts['scheme']) . '://' . $parts['host'];

    if (isset($parts['port'])) {
        $scheme = strtolower($parts['scheme']);
        $isDefault = ($scheme === 'https' && (int) $parts['port'] === 443)
            || ($scheme === 'http' && (int) $parts['port'] === 80);
        if (!$isDefault) {
            $origin .= ':' . (int) $parts['port'];
        }
    }

    return $origin;
}

/**
 * Signs a VAPID JWT for one push endpoint.
 *
 * @param string $endpoint     the push endpoint the token is for
 * @param string $subject      the `sub` contact URI
 * @param string $publicKeyB64 base64url VAPID public key (65-byte point)
 * @param string $privateKeyB64 base64url VAPID private scalar (32 bytes)
 * @param int    $ttl          seconds the token is valid (RFC 8292 caps at 24h)
 * @return string|null the compact JWS, or null on failure
 */
function wallos_webpush_vapid_jwt($endpoint, $subject, $publicKeyB64, $privateKeyB64, $ttl = 43200)
{
    $audience = wallos_webpush_endpoint_origin($endpoint);
    if ($audience === null) {
        return null;
    }

    $point = wallos_webpush_b64u_decode($publicKeyB64);
    $d = wallos_webpush_b64u_decode($privateKeyB64);
    if (strlen($point) !== 65 || strlen($d) !== 32) {
        return null;
    }

    $header = ['typ' => 'JWT', 'alg' => 'ES256'];
    $claims = [
        'aud' => $audience,
        'exp' => time() + min((int) $ttl, 86400),
        'sub' => $subject,
    ];

    $signingInput = wallos_webpush_b64u_encode(json_encode($header))
        . '.' . wallos_webpush_b64u_encode(json_encode($claims));

    $privateKey = openssl_pkey_get_private(wallos_webpush_ec_private_pem($d, $point));
    if ($privateKey === false) {
        return null;
    }

    $der = '';
    if (openssl_sign($signingInput, $der, $privateKey, OPENSSL_ALGO_SHA256) === false) {
        return null;
    }

    $raw = wallos_webpush_der_to_raw_signature($der);
    if ($raw === null) {
        return null;
    }

    return $signingInput . '.' . wallos_webpush_b64u_encode($raw);
}

/* -------------------------------------------------------------------------
   RFC 8291 payload encryption (aes128gcm content encoding, RFC 8188)
   ------------------------------------------------------------------------- */

/**
 * Encrypts a push payload for one subscription, producing the aes128gcm body.
 *
 * The server keypair and the record salt are parameters so a known-answer test
 * can pin them to the RFC 8291 vector; in production both are freshly random,
 * which is what makes each message's encryption independent.
 *
 * @param string      $plaintext   the message to deliver
 * @param string      $uaPublic    65-byte client public key (subscription p256dh)
 * @param string      $authSecret  16-byte client auth secret (subscription auth)
 * @param string|null $asPrivate   32-byte server private scalar, or null to generate
 * @param string|null $asPublic    65-byte server public point (required with $asPrivate)
 * @param string|null $salt        16-byte record salt, or null to generate
 * @return string|null the encrypted body, or null on failure
 */
function wallos_webpush_encrypt($plaintext, $uaPublic, $authSecret, $asPrivate = null, $asPublic = null, $salt = null, $padTo = WALLOS_WEBPUSH_PADDED_RECORD)
{
    if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
        return null;
    }

    // Past the ceiling the record no longer fits the size the header declares,
    // and the receiver discards it. Refusing here reaches the caller as "the
    // push payload could not be encrypted" and lands in the notification log;
    // the alternative is a 201 from the push service for something nobody will
    // ever see.
    if (strlen($plaintext) > WALLOS_WEBPUSH_MAX_PLAINTEXT) {
        return null;
    }

    if ($asPrivate === null || $asPublic === null) {
        $generated = wallos_webpush_generate_vapid_keys();
        if ($generated === null) {
            return null;
        }
        $asPrivate = wallos_webpush_b64u_decode($generated['private']);
        $asPublic = wallos_webpush_b64u_decode($generated['public']);
    }

    if (strlen($asPrivate) !== 32 || strlen($asPublic) !== 65) {
        return null;
    }

    if ($salt === null) {
        $salt = random_bytes(16);
    }

    $serverKey = openssl_pkey_get_private(wallos_webpush_ec_private_pem($asPrivate, $asPublic));
    $clientKey = openssl_pkey_get_public(wallos_webpush_ec_public_pem($uaPublic));
    if ($serverKey === false || $clientKey === false) {
        return null;
    }

    $sharedSecret = openssl_pkey_derive($clientKey, $serverKey, 32);
    if ($sharedSecret === false) {
        return null;
    }

    // RFC 8291 §3.4: the ECDH secret and the auth secret produce the input
    // keying material, bound to both parties' public keys.
    $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
    $ikm = hash_hkdf('sha256', $sharedSecret, 32, $keyInfo, $authSecret);

    // RFC 8188 §2.2: the content-encryption key and nonce, keyed by the record
    // salt that travels in the header.
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    // A single record covering the whole payload: 0x02 is the last-record
    // padding delimiter (RFC 8188 §2), followed by the zero bytes that make
    // every push the same size on the wire.
    //
    // Without the zeros the body length is the message length plus a constant,
    // so anyone who can watch the connection to the push service learns how
    // long each notification was without decrypting anything — and these are
    // predictable: "Netflix renews in 3 days" is a different length from
    // "Versicherung wird in 7 Tagen verlängert", and the set of subscriptions
    // an account holds is small. Measured before this: 132 bytes for a short
    // notification, 184 for a typical one. RFC 8188 §2 exists for exactly this
    // and the padding costs nothing a nightly job would notice.
    //
    // A payload between the target and the ceiling keeps the delimiter alone
    // rather than being refused: it still encrypts, it is still correct, and it
    // is only as revealing as every push was before. Nothing here produces one.
    $record = $plaintext . "\x02";

    if ($padTo > 0 && strlen($record) < $padTo) {
        $record = str_pad($record, $padTo, "\x00");
    }

    $padded = $record;

    $tag = '';
    $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) {
        return null;
    }

    // RFC 8188 §2.1 header: salt(16) | record_size(4, big-endian) | idlen(1) |
    // keyid — the keyid being the server's ephemeral public key.
    $header = $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic;

    return $header . $ciphertext . $tag;
}

/* -------------------------------------------------------------------------
   Sending

   The one network touch is factored out behind a function_exists guard so a
   test can stand in for the push service and drive the 404/410 stale-cleanup
   without a socket — the arrangement wallos_oidc_discovery_http_get() uses.
   ------------------------------------------------------------------------- */

if (!function_exists('wallos_webpush_http_post')) {
    /**
     * POSTs an encrypted push to its endpoint.
     *
     * @param string   $url     the push endpoint
     * @param string   $body    the aes128gcm-encoded payload
     * @param string[] $headers request headers
     * @param string   $resolve a curl RESOLVE entry pinning the host to the IP
     *                          the SSRF check already approved
     * @return array{response: string|false, status: int, error: string}
     */
    function wallos_webpush_http_post($url, $body, array $headers, $resolve)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        if ($resolve !== '') {
            curl_setopt($ch, CURLOPT_RESOLVE, [$resolve]);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['response' => $response, 'status' => $status, 'error' => $error];
    }
}

/**
 * Delivers one push to one subscription.
 *
 * The client-supplied endpoint is an outbound request to a target Wallos does
 * not control, so it goes through the SSRF allowlist exactly as the logo and
 * webhook fetches do — a private or reserved address is refused, not fetched.
 *
 * @param WallosDatabase $db
 * @param array          $subscription endpoint, p256dh, auth (base64url)
 * @param string         $payload      the message body to encrypt
 * @param int            $userId       the owner, for the SSRF role decision
 * @param int            $ttl          seconds the push service should hold it
 *                                     for a device that is offline
 * @return array{sent: bool, expired: bool, status: int, error: string}
 */
function wallos_webpush_deliver($db, $subscription, $payload, $userId, $ttl = WALLOS_WEBPUSH_TTL_MAX)
{
    $fail = function ($error, $status = 0, $expired = false) {
        return ['sent' => false, 'expired' => $expired, 'status' => $status, 'error' => $error];
    };

    $config = wallos_get_instance_webpush_config($db);
    if (empty($config['values']['deliverable'])) {
        return $fail('the instance VAPID keypair is not configured');
    }

    $endpoint = (string) ($subscription['endpoint'] ?? '');
    if ($endpoint === '') {
        return $fail('the subscription has no endpoint');
    }

    $safe = is_url_safe_for_ssrf($endpoint, $db, $userId);
    if ($safe === false) {
        return $fail('the push endpoint failed the SSRF check');
    }

    $encrypted = wallos_webpush_encrypt(
        $payload,
        wallos_webpush_b64u_decode((string) ($subscription['p256dh'] ?? '')),
        wallos_webpush_b64u_decode((string) ($subscription['auth'] ?? ''))
    );
    if ($encrypted === null) {
        return $fail('the push payload could not be encrypted');
    }

    $jwt = wallos_webpush_vapid_jwt(
        $endpoint,
        (string) $config['values']['subject'],
        (string) $config['values']['public_key'],
        (string) $config['values']['private_key']
    );
    if ($jwt === null) {
        return $fail('the VAPID token could not be signed');
    }

    $headers = [
        'Content-Encoding: aes128gcm',
        'Content-Type: application/octet-stream',
        'TTL: ' . max(0, min((int) $ttl, WALLOS_WEBPUSH_TTL_MAX)),
        'Authorization: vapid t=' . $jwt . ', k=' . (string) $config['values']['public_key'],
    ];

    $result = wallos_webpush_http_post(
        $endpoint,
        $encrypted,
        $headers,
        $safe['host'] . ':' . $safe['port'] . ':' . $safe['ip']
    );

    // 404 Not Found / 410 Gone: the browser dropped this subscription, and the
    // standard response is to delete it so it is never tried again.
    if ($result['status'] === 404 || $result['status'] === 410) {
        return $fail('the subscription is gone', $result['status'], true);
    }

    if ($result['response'] === false) {
        return $fail($result['error'] !== '' ? $result['error'] : 'no response from the push service', $result['status']);
    }

    if ($result['status'] >= 200 && $result['status'] < 300) {
        return ['sent' => true, 'expired' => false, 'status' => $result['status'], 'error' => ''];
    }

    return $fail('the push service answered HTTP ' . $result['status'], $result['status']);
}

/* -------------------------------------------------------------------------
   Subscription storage

   A user has several subscriptions, one per device, unlike the one-row-per-user
   shape of the other channels. The endpoint is unique across the table, so a
   device re-subscribing replaces its own row rather than accumulating stale
   copies. Ownership is always the server-side session user, never a value from
   the client.
   ------------------------------------------------------------------------- */

/**
 * How many devices one account may keep subscribed.
 *
 * Not a guess at what is reasonable but at what is possible: a household member
 * has a phone, a tablet, a work laptop, maybe a desktop. Twenty is several
 * times that.
 *
 * It is a bound rather than a refusal because of what each row costs at send
 * time. The notification cron makes one HTTP request per subscription per
 * message, each with a five-second connect timeout and a fifteen-second read
 * timeout. A thousand rows is a thousand requests in the nightly run, and if
 * they point somewhere that does not answer, the run stops being nightly — and
 * it is the same run that sends the email, Telegram and webhook notifications
 * for every other member of the household.
 *
 * Nothing here needs an attacker: it needs one signed-in account and a script.
 * But a hard refusal at the limit would mean somebody's new phone silently
 * failing to subscribe because of four devices they threw away, so the oldest
 * is dropped instead.
 */
const WALLOS_WEBPUSH_MAX_DEVICES = 20;

/**
 * A stable, non-reversible handle for one subscription.
 *
 * The settings page has to name a device to remove without putting the endpoint
 * itself into the page and into every request that follows. The endpoint is not
 * a secret, but it is the address that receives this account's notifications,
 * and there is no reason for it to travel further than it must.
 *
 * @param string $endpoint
 * @return string 16 hex characters
 */
function wallos_webpush_device_handle($endpoint)
{
    return substr(hash('sha256', (string) $endpoint), 0, 16);
}

/**
 * A human label for the device that subscribed, from its user agent.
 *
 * "Chrome on Android" is what a person recognises; the endpoint is not. The
 * string is client-supplied, so this never passes it through — it matches
 * against a fixed list and returns words chosen here, which means a crafted
 * user agent cannot put text of its own on the settings page.
 *
 * Order matters: Edge and Opera both carry "Chrome" in their user agent, and
 * every Chrome on iOS carries "Safari".
 *
 * @param string $userAgent
 * @return array{browser: string, platform: string} empty strings when unknown
 */
function wallos_webpush_device_label($userAgent)
{
    $agent = (string) $userAgent;

    $browsers = [
        'Edg' => 'Edge',
        'OPR' => 'Opera',
        'SamsungBrowser' => 'Samsung Internet',
        'Firefox' => 'Firefox',
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
    ];

    $platforms = [
        'Android' => 'Android',
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Windows' => 'Windows',
        'Mac OS X' => 'macOS',
        'Macintosh' => 'macOS',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    $browser = '';
    foreach ($browsers as $needle => $name) {
        if (strpos($agent, $needle) !== false) {
            $browser = $name;
            break;
        }
    }

    $platform = '';
    foreach ($platforms as $needle => $name) {
        if (strpos($agent, $needle) !== false) {
            $platform = $name;
            break;
        }
    }

    return ['browser' => $browser, 'platform' => $platform];
}

/**
 * Whether a PushSubscription has the shape RFC 8291 fixes for it.
 *
 * The two keys are not free-form: §3.1 has the user agent generate a P-256 key
 * pair, whose public half travels in the uncompressed point form §4 spells out
 * — "a 65-octet sequence that starts with a 0x04 octet" — and §3.2 has the auth
 * secret at exactly sixteen octets. Anything else cannot be encrypted to, so a
 * row holding it is a subscription that will never receive a notification.
 *
 * Checked at the door rather than only inside the encryption, for two reasons.
 * A malformed subscription discovered at the next notification run is a silent
 * per-row failure at nine in the morning; discovered here it is a 400 the
 * browser can act on while somebody is still looking at the settings page. And
 * these three values were stored as whatever arrived — one authenticated
 * request could put two megabytes in the table and come back with a fresh
 * endpoint for the next one. An endpoint is a URL a push service issued; two
 * kilobytes is generous for one.
 *
 * @param string $endpoint
 * @param string $p256dh base64url client public key
 * @param string $auth   base64url client auth secret
 * @return bool
 */
function wallos_webpush_subscription_is_wellformed($endpoint, $p256dh, $auth)
{
    if ($endpoint === '' || strlen($endpoint) > 2048) {
        return false;
    }

    // A push endpoint is an absolute http(s) URL. Reserved and private
    // addresses are not judged here — the outbound send routes every endpoint
    // through the SSRF allowlist, which is where that decision belongs and
    // where it can still be made when a name resolves differently later.
    $parsed = parse_url($endpoint);
    if (
        !is_array($parsed) ||
        !isset($parsed['scheme']) ||
        !in_array(strtolower($parsed['scheme']), ['http', 'https'], true) ||
        !filter_var($endpoint, FILTER_VALIDATE_URL)
    ) {
        return false;
    }

    $key = wallos_webpush_b64u_decode($p256dh);
    $secret = wallos_webpush_b64u_decode($auth);

    return strlen($key) === 65 && $key[0] === "\x04" && strlen($secret) === 16;
}

/**
 * Stores (or replaces, on the same endpoint) one browser subscription.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $endpoint
 * @param string         $p256dh   base64url client public key
 * @param string         $auth     base64url client auth secret
 * @return bool
 */
function wallos_webpush_store_subscription($db, $userId, $endpoint, $p256dh, $auth, $userAgent = '')
{
    // ON CONFLICT with excluded.* so no named parameter is bound twice — the
    // upsert idiom that runs on both backends (see the OIDC discovery cache).
    //
    // The WHERE is what stops one account taking another's device. An endpoint
    // is unique, so the row can only be claimed by whoever already owns it, or
    // by somebody who can show the same p256dh — and that is the honest
    // discriminator between the two ways this collision happens:
    //
    //   On a shared family browser there is one registration and therefore one
    //   subscription. The second person to press "enable" gets the *existing*
    //   PushSubscription back from getSubscription(), keys and all, so their
    //   request carries the same p256dh and the device moves to them. That is
    //   the only answer a browser can honestly give, and it stays allowed.
    //
    //   Somebody who merely learned an endpoint string cannot produce its
    //   p256dh: the browser generated that key pair for that subscription and
    //   never handed out the private half. Their write now changes nothing.
    //
    // Before this, either one moved the row, so any account could silently take
    // over another's device by posting its endpoint — and the loser saw no sign
    // of it, because the settings page reads the browser's subscription rather
    // than the stored row.
    $stmt = $db->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, created_at, user_agent)
                          VALUES (:userId, :endpoint, :p256dh, :auth, :createdAt, :userAgent)
                          ON CONFLICT(endpoint) DO UPDATE SET
                              user_id = excluded.user_id,
                              p256dh = excluded.p256dh,
                              auth = excluded.auth,
                              created_at = excluded.created_at,
                              user_agent = excluded.user_agent
                          WHERE push_subscriptions.user_id = excluded.user_id
                             OR push_subscriptions.p256dh = excluded.p256dh');
    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':userId', (int) $userId);
    $stmt->bindValue(':endpoint', (string) $endpoint);
    $stmt->bindValue(':p256dh', (string) $p256dh);
    $stmt->bindValue(':auth', (string) $auth);
    $stmt->bindValue(':createdAt', time());
    // Truncated, never trusted: it is a label, and a label does not need to be
    // longer than this. Rendered escaped, and only ever to the account that
    // stored it.
    $stmt->bindValue(':userAgent', substr((string) $userAgent, 0, 512));

    if ($stmt->execute() === false) {
        return false;
    }

    // Nothing written means the WHERE above refused it: the endpoint belongs to
    // another account and the request could not show its key. Reported as a
    // failure rather than passing for a save, because the caller would
    // otherwise tell somebody their device is subscribed when it is not.
    if ((int) $db->changes() <= 0) {
        return false;
    }

    wallos_webpush_trim_devices($db, $userId);

    return true;
}

/**
 * Drops the oldest subscriptions of one account past the device limit.
 *
 * Run after every successful store rather than checked before it, so a device
 * that is merely re-subscribing is never turned away, and so a table that is
 * already over the limit heals on the next save instead of staying over it.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param int            $limit
 * @return int how many rows were dropped
 */
function wallos_webpush_trim_devices($db, $userId, $limit = WALLOS_WEBPUSH_MAX_DEVICES)
{
    $limit = max(1, (int) $limit);

    // Read the survivors, then delete by endpoint. A DELETE with a subselect
    // over its own table and a LIMIT is not portable across the two backends,
    // and the row count here is bounded by the limit this function enforces.
    $stmt = $db->prepare('SELECT endpoint FROM push_subscriptions
                          WHERE user_id = :userId
                          ORDER BY created_at DESC, endpoint DESC');
    if ($stmt === false) {
        return 0;
    }

    $stmt->bindValue(':userId', (int) $userId);
    $result = $stmt->execute();
    if ($result === false) {
        return 0;
    }

    $endpoints = [];
    while ($row = $result->fetchArray()) {
        $endpoints[] = (string) $row['endpoint'];
    }

    $surplus = array_slice($endpoints, $limit);
    $dropped = 0;

    foreach ($surplus as $endpoint) {
        if (wallos_webpush_delete_by_endpoint($db, $userId, $endpoint)) {
            $dropped++;
        }
    }

    return $dropped;
}

/**
 * The account's subscribed devices, as the settings page shows them.
 *
 * Deliberately not wallos_webpush_user_subscriptions(): that one carries the
 * key material the sender needs, and none of it belongs in a page. This returns
 * a handle, a label and a date — enough to recognise a device and remove it.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @return array<int, array{handle: string, browser: string, platform: string, created_at: int}>
 */
function wallos_webpush_user_devices($db, $userId)
{
    $stmt = $db->prepare('SELECT endpoint, created_at, user_agent FROM push_subscriptions
                          WHERE user_id = :userId
                          ORDER BY created_at DESC, endpoint DESC');
    if ($stmt === false) {
        return [];
    }

    $stmt->bindValue(':userId', (int) $userId);
    $result = $stmt->execute();
    if ($result === false) {
        return [];
    }

    $devices = [];
    while ($row = $result->fetchArray()) {
        $label = wallos_webpush_device_label((string) ($row['user_agent'] ?? ''));
        $devices[] = [
            'handle' => wallos_webpush_device_handle((string) $row['endpoint']),
            'browser' => $label['browser'],
            'platform' => $label['platform'],
            'created_at' => (int) $row['created_at'],
        ];
    }

    return $devices;
}

/**
 * Removes one of the account's own devices, named by its handle.
 *
 * The handle is resolved against this account's rows only, so a handle
 * belonging to somebody else's subscription matches nothing here — the scoping
 * is the same as the delete-by-endpoint path, done one step earlier.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $handle
 * @return bool false when the account has no such device
 */
function wallos_webpush_delete_by_handle($db, $userId, $handle)
{
    $stmt = $db->prepare('SELECT endpoint FROM push_subscriptions WHERE user_id = :userId');
    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':userId', (int) $userId);
    $result = $stmt->execute();
    if ($result === false) {
        return false;
    }

    while ($row = $result->fetchArray()) {
        $endpoint = (string) $row['endpoint'];
        if (hash_equals(wallos_webpush_device_handle($endpoint), (string) $handle)) {
            return wallos_webpush_delete_by_endpoint($db, $userId, $endpoint);
        }
    }

    return false;
}

/**
 * Every subscription belonging to one user.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @return array<int, array{endpoint: string, p256dh: string, auth: string}>
 */
function wallos_webpush_user_subscriptions($db, $userId)
{
    $stmt = $db->prepare('SELECT endpoint, p256dh, auth FROM push_subscriptions
                          WHERE user_id = :userId ORDER BY endpoint');
    if ($stmt === false) {
        return [];
    }

    $stmt->bindValue(':userId', (int) $userId);
    $result = $stmt->execute();
    if ($result === false) {
        return [];
    }

    $subscriptions = [];
    while ($row = $result->fetchArray()) {
        $subscriptions[] = [
            'endpoint' => (string) $row['endpoint'],
            'p256dh' => (string) $row['p256dh'],
            'auth' => (string) $row['auth'],
        ];
    }

    return $subscriptions;
}

/**
 * Deletes one subscription by endpoint, only if it belongs to the given user.
 * The endpoint is the subscription's identity — the browser knows it, and a
 * send that gets 404/410 Gone has it in hand.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $endpoint
 * @return bool
 */
function wallos_webpush_delete_by_endpoint($db, $userId, $endpoint)
{
    $stmt = $db->prepare('DELETE FROM push_subscriptions WHERE endpoint = :endpoint AND user_id = :userId');
    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':endpoint', (string) $endpoint);
    $stmt->bindValue(':userId', (int) $userId);

    return $stmt->execute() !== false;
}
