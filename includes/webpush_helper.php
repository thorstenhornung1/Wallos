<?php

/*
  Web Push (RFC 8030 / RFC 8291 / RFC 8292): standard browser push
  notifications, sent directly to the browser vendor's own push service
  (Chrome's, Firefox's, Apple's, ...) with no account of Wallos's own -
  unlike Pushover, Gotify or ntfy, which all need the user to register
  somewhere else first.

  Two things a push message needs that nothing else in this codebase does:

    - VAPID (RFC 8292): a JWT, signed with this installation's own EC
      keypair, that tells the push service which application server sent the
      message, so it can rate-limit and identify abuse by sender rather than
      by subscription.
    - End-to-end encryption (RFC 8291): the push service is an untrusted
      relay that can read the message's metadata but never its payload,
      which is encrypted between this server and the browser using a key
      derived from the subscription's own public key and auth secret - the
      push service is never able to decrypt it.

  Both need elliptic-curve Diffie-Hellman, which ext-openssl only exposed a
  plain function for (openssl_pkey_derive()) starting in PHP 8.1. Wallos
  targets PHP 8.3, so this is written directly against ext-openssl - no
  Composer package, the way every other vendored helper in this codebase is
  (see includes/frankfurter.php for the same approach applied to an HTTP API
  instead of a cryptographic one).

  Every intermediate value this file computes (the ECDH secret, the two HKDF
  outputs, the final ciphertext) is checked byte-for-byte against RFC 8291's
  own published test vector in tests/cases/webpush_test.php - that is a much
  stronger guarantee than "the output looks like a push message", which is
  why the payload encryption function accepts an injectable ephemeral key and
  salt: production always generates both fresh (forward secrecy depends on
  the ephemeral key never being reused across messages), but a test needs the
  RFC's fixed ones to reproduce its fixed output.
*/

require_once __DIR__ . '/config_helper.php';
require_once __DIR__ . '/integration_config.php';

/**
 * The size every record is padded to, delimiter included.
 *
 * Without padding a push message is exactly as long as the sentence inside it,
 * so anyone watching the connection to the push service learns how long each
 * notification was without decrypting anything - and these are predictable:
 * "Netflix renews in 3 days" is a different length from "Versicherung wird in
 * 7 Tagen verlängert", and the set of subscriptions an account holds is small.
 * Measured before this: 132 bytes for a short notification, 184 for a typical
 * one.
 *
 * RFC 8291 §4 requires a push service to accept a payload of at least 4096
 * octets, and the aes128gcm header (86 bytes here) and the GCM tag (16) come
 * out of that budget. 2820 leaves room for both with margin, and it is the
 * figure the established PHP and JavaScript implementations settled on, which
 * matters: a size nobody else uses would identify this application as surely
 * as the length it was hiding.
 */
const WEBPUSH_PADDED_RECORD = 2820;

/**
 * The longest plaintext that fits in the record the header promises.
 *
 * RFC 8291 §4 does the arithmetic and states the result: absent header (86
 * octets), padding (minimum 1 octet) and AEAD expansion (16 octets), "this
 * equates to, at most, 3993 octets of plaintext".
 *
 * The number is a real boundary, not a style choice. The header declares a
 * record size of 4096; RFC 8188 §2 has the last record at most that size. A
 * longer payload produces a record that overruns the size its own header
 * announced, and a receiver reading at the declared granularity finds a
 * plaintext octet where the padding delimiter should be - "values other than
 * 0x02 MUST cause the message to be discarded". Such a body is not a push that
 * reveals its length; it is a push that silently arrives nowhere.
 */
const WEBPUSH_MAX_PLAINTEXT = 3993;

/**
 * How long a renewal reminder stays worth delivering, past the renewal itself.
 *
 * The push service holds a message for an offline device and delivers it when
 * the device comes back (RFC 8030 §5.2). "Netflix renews in 3 days" is useful
 * up to the renewal and for a short while after: long enough that a phone
 * switched off over a weekend still gets a reminder worth having, and not so
 * long that switching it on after a holiday produces a pile of notices about
 * renewals that happened three weeks ago.
 */
const WEBPUSH_TTL_GRACE = 172800;

/**
 * The ceiling every push service shares: four weeks.
 *
 * RFC 8030 §5.2 sets no maximum, but in practice the large services all stop
 * at 2419200 seconds, so nothing above that buys anything. It is also where
 * the fixed value this fork used to send came from - "keep this as long as you
 * possibly can", applied to a message that stops being true after a few days.
 */
const WEBPUSH_TTL_MAX = 2419200;

/**
 * How many devices one account may keep registered.
 *
 * Every device is a row an authenticated request creates, and a browser that
 * clears its site data subscribes again rather than reusing the old row, so
 * the count only ever grows on its own. Twenty is past what a household uses
 * and small enough that the table cannot be grown into a problem.
 */
const WEBPUSH_MAX_DEVICES = 20;

function webpush_base64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webpush_base64url_decode($data)
{
    $data = strtr((string) $data, '-_', '+/');
    $padded = str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=');
    $decoded = base64_decode($padded, true);

    return $decoded === false ? '' : $decoded;
}

/**
 * Left-pads (or, defensively, right-truncates) a big-endian byte string to a
 * fixed length.
 *
 * ext-openssl's EC accessors (openssl_pkey_get_details()['ec']['x'/'y'/'d'],
 * openssl_pkey_derive()'s return value) strip leading zero bytes, but every
 * one of these values is a fixed-width 32-byte P-256 field element or scalar
 * everywhere it is used here - the raw point format, the JWS signature
 * format, and the shared secret all require the width restored.
 *
 * @param string $value
 * @param int    $length
 * @return string
 */
function webpush_fixed_width($value, $length = 32)
{
    if (strlen($value) > $length) {
        return substr($value, -$length);
    }

    return str_pad($value, $length, "\x00", STR_PAD_LEFT);
}

/**
 * A P-256 uncompressed point (65 bytes: 0x04 || X(32) || Y(32)) - what both
 * a push subscription's p256dh and an applicationServerKey are - as an
 * OpenSSL public key resource, for openssl_pkey_derive() and
 * openssl_verify().
 *
 * ext-openssl has no "import a raw point" function; a SubjectPublicKeyInfo
 * DER wrapper around the point does the same thing, and for a fixed named
 * curve every byte of that wrapper except the point itself is constant -
 * this is the standard encoding of "id-ecPublicKey, prime256v1". Verified
 * against ext-openssl's own PEM output for a point it generated itself
 * (same derived ECDH secret either way) before this was trusted for
 * anything real.
 *
 * @param string $rawPoint
 * @return OpenSSLAsymmetricKey|false
 */
function webpush_ec_public_key_from_raw($rawPoint)
{
    if (strlen($rawPoint) !== 65 || $rawPoint[0] !== "\x04") {
        return false;
    }

    $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $der = $prefix . $rawPoint;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";

    $key = openssl_pkey_get_public($pem);

    return $key === false ? false : $key;
}

/**
 * Reads one ASN.1 DER INTEGER at $offset.
 *
 * @param string $der
 * @param int    $offset
 * @return array{0: string|false, 1: int} The integer's raw bytes (with any
 *         ASN.1 sign-avoidance zero pad stripped), and the offset just past
 *         it. [false, $offset] on a malformed input.
 */
function webpush_der_read_integer($der, $offset)
{
    $len = strlen($der);

    if ($offset >= $len || ord($der[$offset]) !== 0x02) {
        return [false, $offset];
    }
    $offset++;

    if ($offset >= $len) {
        return [false, $offset];
    }
    $intLen = ord($der[$offset]);
    $offset++;

    if ($intLen & 0x80) {
        $numLenBytes = $intLen & 0x7f;
        if ($numLenBytes < 1 || $numLenBytes > 2 || $offset + $numLenBytes > $len) {
            return [false, $offset];
        }
        $intLen = 0;
        for ($i = 0; $i < $numLenBytes; $i++) {
            $intLen = ($intLen << 8) | ord($der[$offset + $i]);
        }
        $offset += $numLenBytes;
    }

    if ($intLen < 0 || $offset + $intLen > $len) {
        return [false, $offset];
    }
    $value = substr($der, $offset, $intLen);
    $offset += $intLen;

    // A leading 0x00 that exists only to keep a high first bit from reading
    // as a two's-complement sign is not part of the fixed-width value a JWS
    // wants - r and s are unsigned there.
    while (strlen($value) > 1 && $value[0] === "\x00" && (ord($value[1]) & 0x80)) {
        $value = substr($value, 1);
    }

    return [$value, $offset];
}

/**
 * Converts the DER ECDSA-Sig-Value openssl_sign() produces into the raw
 * r || s concatenation a JWS ES256 signature is.
 *
 * @param string $der
 * @param int    $partLength Width of each of r and s; 32 for P-256/ES256.
 * @return string|false
 */
function webpush_der_signature_to_raw($der, $partLength = 32)
{
    $offset = 0;
    $len = strlen($der);

    if ($len < 8 || ord($der[$offset]) !== 0x30) {
        return false;
    }
    $offset++;

    $seqLen = ord($der[$offset]);
    $offset++;
    if ($seqLen & 0x80) {
        $numLenBytes = $seqLen & 0x7f;
        if ($numLenBytes < 1 || $numLenBytes > 2 || $offset + $numLenBytes > $len) {
            return false;
        }
        $seqLen = 0;
        for ($i = 0; $i < $numLenBytes; $i++) {
            $seqLen = ($seqLen << 8) | ord($der[$offset + $i]);
        }
        $offset += $numLenBytes;
    }

    [$r, $offset] = webpush_der_read_integer($der, $offset);
    if ($r === false) {
        return false;
    }
    [$s, $offset] = webpush_der_read_integer($der, $offset);
    if ($s === false) {
        return false;
    }

    return webpush_fixed_width($r, $partLength) . webpush_fixed_width($s, $partLength);
}

/**
 * openssl.cnf paths worth retrying a failed key operation with.
 *
 * Some PHP-for-Windows builds (XAMPP's included) link an OpenSSL that cannot
 * locate its own default config file, and every openssl_pkey_new()/
 * openssl_pkey_export() call then fails outright - reproducibly, with
 * OpenSSL's own "configuration file routines::no such file" - even for a
 * plain RSA key with no EC or Web Push involved at all. This is an
 * environment problem, not one this file can fix, but it is common enough
 * (XAMPP is a standard local dev setup) that failing outright rather than
 * working around it would leave the feature broken for a lot of installs
 * that would otherwise never touch this at all - Docker, and most native
 * Linux packagings, resolve their default config without any of this.
 *
 * @return string[] Existing, readable candidate paths, in the order to try.
 */
function webpush_openssl_config_candidates()
{
    $candidates = [];

    $envConf = getenv('OPENSSL_CONF');
    if ($envConf !== false && trim($envConf) !== '') {
        $candidates[] = $envConf;
    }

    // Where XAMPP for Windows ships an openssl.cnf next to the php.ini this
    // exact request loaded - true for both the Apache module and the CLI
    // binary, since XAMPP points both at the same php.ini by default.
    $iniFile = php_ini_loaded_file();
    if ($iniFile !== false) {
        $iniDir = dirname($iniFile);
        $candidates[] = $iniDir . '/extras/openssl/openssl.cnf';
        $candidates[] = $iniDir . '/extras/ssl/openssl.cnf';
    }

    return array_values(array_filter($candidates, function ($path) {
        return $path !== '' && is_readable($path);
    }));
}

/**
 * openssl_pkey_new(), retried with a discovered openssl.cnf if the plain
 * call fails - see webpush_openssl_config_candidates() for why that can be
 * necessary at all. The plain call is always tried first and is all that
 * runs on an install where it already works.
 *
 * @param array $options As openssl_pkey_new() takes, without 'config'.
 * @return OpenSSLAsymmetricKey|false
 */
function webpush_openssl_pkey_new($options)
{
    $key = openssl_pkey_new($options);
    if ($key !== false) {
        return $key;
    }

    foreach (webpush_openssl_config_candidates() as $configPath) {
        $key = openssl_pkey_new($options + ['config' => $configPath]);
        if ($key !== false) {
            return $key;
        }
    }

    return false;
}

/**
 * Generates a fresh P-256 keypair for this installation to identify itself
 * to push services with.
 *
 * @return array{public: string, private_pem: string}|false 'public' is the
 *         raw uncompressed point, base64url - what both the VAPID
 *         Authorization header and the frontend's applicationServerKey need.
 *         'private_pem' is kept in PEM, the form openssl_sign() and
 *         openssl_pkey_get_private() both take directly.
 */
function webpush_generate_vapid_keypair()
{
    $key = webpush_openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);

    if ($key === false) {
        return false;
    }

    $details = openssl_pkey_get_details($key);
    if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
        return false;
    }

    $publicRaw = "\x04" . webpush_fixed_width($details['ec']['x']) . webpush_fixed_width($details['ec']['y']);

    // Exporting can hit the same missing-config failure key generation can -
    // the same candidates are worth the same retry.
    if (!openssl_pkey_export($key, $privatePem)) {
        $exported = false;
        foreach (webpush_openssl_config_candidates() as $configPath) {
            if (openssl_pkey_export($key, $privatePem, null, ['config' => $configPath])) {
                $exported = true;
                break;
            }
        }
        if (!$exported) {
            return false;
        }
    }

    return [
        'public' => webpush_base64url_encode($publicRaw),
        'private_pem' => $privatePem,
    ];
}

/**
 * This installation's VAPID keypair, generating and persisting one the
 * first time anything needs it.
 *
 * Stored on the admin row alongside the other instance-wide settings that
 * already live there (smtp_*, server_url) - it identifies the Wallos
 * installation as a whole to the push services it talks to, not any one
 * user, the same way the instance's own SMTP identity is not per-user.
 *
 * @param SQLite3 $db
 * @return array{public: string, private_pem: string}|false
 */
function webpush_get_vapid_keys($db)
{
    // The deployment owns the pair when it says so, the way it owns the SMTP
    // credentials: a key mounted as a file survives the database being
    // restored from a backup taken before it existed, and it keeps the one
    // secret that can forge notifications to every device in the household out
    // of the row everything else reads.
    $environment = wallos_webpush_environment_keys();
    if ($environment !== null) {
        return $environment;
    }

    $row = $db->querySingle('SELECT vapid_public_key, vapid_private_key FROM admin LIMIT 1', true);

    if ($row !== false && !empty($row['vapid_public_key']) && !empty($row['vapid_private_key'])) {
        return ['public' => $row['vapid_public_key'], 'private_pem' => $row['vapid_private_key']];
    }

    $keys = webpush_generate_vapid_keypair();
    if ($keys === false) {
        return false;
    }

    // Only into empty columns, and read back afterwards.
    //
    // Two first uses at once - the settings page of one household member and
    // the notification cron, say - each generated a pair and each wrote it,
    // and the second write won. Every device that had already subscribed with
    // the first key was then answered 403 by its push service for good: 403 is
    // not 404/410, so nothing prunes the row, nothing appears in the settings
    // page, and the notifications simply stop.
    $stmt = $db->prepare("UPDATE admin SET vapid_public_key = :public, vapid_private_key = :private
                          WHERE vapid_public_key IS NULL OR vapid_public_key = ''
                             OR vapid_private_key IS NULL OR vapid_private_key = ''");
    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':public', $keys['public'], SQLITE3_TEXT);
    $stmt->bindValue(':private', $keys['private_pem'], SQLITE3_TEXT);

    if ($stmt->execute() === false) {
        return false;
    }

    $stored = $db->querySingle('SELECT vapid_public_key, vapid_private_key FROM admin LIMIT 1', true);

    if ($stored !== false && !empty($stored['vapid_public_key']) && !empty($stored['vapid_private_key'])) {
        return ['public' => $stored['vapid_public_key'], 'private_pem' => $stored['vapid_private_key']];
    }

    return $keys;
}

/**
 * The keypair the deployment supplied, if it supplied one.
 *
 * WALLOS_VAPID_PUBLIC_KEY is the raw base64url point a browser subscribes
 * with; WALLOS_VAPID_PRIVATE_KEY (or _FILE) is the PEM this server signs with.
 * Both or neither: half a pair would sign with a key no browser subscribed to,
 * which is the silent 403 this function exists to avoid.
 *
 * @return array{public: string, private_pem: string}|null
 */
function wallos_webpush_environment_keys()
{
    $public = trim((string) wallos_env('WALLOS_VAPID_PUBLIC_KEY'));

    // The secret may be a mounted file, so it comes back as a resolution with
    // a source and an error rather than a bare string. An unreadable file is
    // an error to report, not a reason to quietly fall back to the database
    // pair - that would sign with a key no browser subscribed to.
    $secret = wallos_env_secret('WALLOS_VAPID_PRIVATE_KEY');
    $private = (string) ($secret['value'] ?? '');

    if (!empty($secret['error'])) {
        error_log('Wallos: the configured VAPID private key could not be read: ' . $secret['error']);

        return null;
    }

    if ($public === '' || trim($private) === '') {
        return null;
    }

    return ['public' => $public, 'private_pem' => $private];
}

/**
 * Builds and signs a VAPID (RFC 8292) authorization JWT for one push
 * message.
 *
 * @param string $endpoint      The subscription's push service endpoint -
 *                               only its scheme://host[:port] is used, as
 *                               the "aud" claim, per RFC 8292.
 * @param string $subject       A mailto: or https: URL identifying this
 *                               application server, the "sub" claim - shown
 *                               to the push service's operator if it needs
 *                               to reach whoever is sending it messages.
 * @param string $privateKeyPem
 * @return string|false
 */
function webpush_build_vapid_jwt($endpoint, $subject, $privateKeyPem)
{
    $parsedEndpoint = parse_url($endpoint);
    if (!$parsedEndpoint || !isset($parsedEndpoint['scheme'], $parsedEndpoint['host'])) {
        return false;
    }

    $audience = $parsedEndpoint['scheme'] . '://' . $parsedEndpoint['host']
        . (isset($parsedEndpoint['port']) ? ':' . $parsedEndpoint['port'] : '');

    $header = ['typ' => 'JWT', 'alg' => 'ES256'];
    $claims = [
        'aud' => $audience,
        // RFC 8292 caps this at 24 hours; 12 is comfortably within it and
        // well past this cron's own run interval, so a slow run never signs
        // a token that is already expired by the time it is used.
        'exp' => time() + 12 * 3600,
        'sub' => $subject,
    ];

    $segment = webpush_base64url_encode(json_encode($header)) . '.' . webpush_base64url_encode(json_encode($claims));

    $privateKey = openssl_pkey_get_private($privateKeyPem);
    if ($privateKey === false) {
        return false;
    }

    if (!openssl_sign($segment, $der, $privateKey, OPENSSL_ALGO_SHA256)) {
        return false;
    }

    $raw = webpush_der_signature_to_raw($der, 32);
    if ($raw === false) {
        return false;
    }

    return $segment . '.' . webpush_base64url_encode($raw);
}

/**
 * Encrypts one push message payload per RFC 8291 (the aes128gcm content
 * coding, RFC 8188).
 *
 * @param string      $plaintext
 * @param string      $p256dhB64        The subscription's own public key,
 *                                       base64url (browser-supplied).
 * @param string      $authB64          The subscription's auth secret,
 *                                       base64url (browser-supplied).
 * @param string|null $ephemeralKeyPem  Injectable only for tests to
 *                                      reproduce RFC 8291's fixed test
 *                                      vector; null generates a fresh
 *                                      one-time keypair, as production
 *                                      always must - reusing an ephemeral
 *                                      key across messages breaks the
 *                                      forward secrecy the protocol exists
 *                                      to provide.
 * @param string|null $salt             Injectable only for tests, for the
 *                                      same reason; null generates 16 fresh
 *                                      random bytes, as production always
 *                                      must.
 * @return string|false The aes128gcm body (content-coding header followed
 *         by the ciphertext and its GCM tag), ready to POST as-is.
 */
function webpush_encrypt_payload($plaintext, $p256dhB64, $authB64, $ephemeralKeyPem = null, $salt = null, $padTo = WEBPUSH_PADDED_RECORD)
{
    $uaPublicRaw = webpush_base64url_decode($p256dhB64);
    $authSecret = webpush_base64url_decode($authB64);

    if (strlen($uaPublicRaw) !== 65 || strlen($authSecret) !== 16) {
        return false;
    }

    // Refused rather than sent, because the record would overrun the size its
    // own header announces and the browser would discard it without a word.
    // Nothing here produces a payload this long; a future caller might.
    if (strlen($plaintext) > WEBPUSH_MAX_PLAINTEXT) {
        return false;
    }

    $uaPublicKey = webpush_ec_public_key_from_raw($uaPublicRaw);
    if ($uaPublicKey === false) {
        return false;
    }

    if ($ephemeralKeyPem === null) {
        $ephemeralKey = webpush_openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($ephemeralKey === false) {
            return false;
        }
    } else {
        $ephemeralKey = openssl_pkey_get_private($ephemeralKeyPem);
        if ($ephemeralKey === false) {
            return false;
        }
    }

    $ephemeralDetails = openssl_pkey_get_details($ephemeralKey);
    if ($ephemeralDetails === false || !isset($ephemeralDetails['ec']['x'], $ephemeralDetails['ec']['y'])) {
        return false;
    }
    $asPublicRaw = "\x04" . webpush_fixed_width($ephemeralDetails['ec']['x']) . webpush_fixed_width($ephemeralDetails['ec']['y']);

    $sharedSecret = openssl_pkey_derive($uaPublicKey, $ephemeralKey, 0);
    if ($sharedSecret === false) {
        return false;
    }
    $sharedSecret = webpush_fixed_width($sharedSecret);

    if ($salt === null) {
        $salt = random_bytes(16);
    } elseif (strlen($salt) !== 16) {
        return false;
    }

    // RFC 8291 section 3.4: combine the ECDH secret with the subscription's
    // own auth secret, so a push service that only ever sees ua_public in
    // transit still cannot derive this on its own - it never sees auth.
    $keyInfo = "WebPush: info\x00" . $uaPublicRaw . $asPublicRaw;
    $ikm = hash_hkdf('sha256', $sharedSecret, 32, $keyInfo, $authSecret);

    // RFC 8188's own key derivation from that IKM and this record's salt.
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    // A single record holds the whole payload, so there is never a second
    // record to pad between. 0x02 is RFC 8188's "last record" delimiter byte;
    // nothing follows it except the padding.
    //
    // Every push leaves here the same size, because without that each one is
    // exactly as long as the sentence inside it and the push service - or
    // anyone watching that connection - reads the length of a message it
    // cannot decrypt. A payload between the target and the ceiling keeps the
    // delimiter alone rather than being refused: it still encrypts, it is
    // still correct, and it is only as revealing as every push was before.
    $padded = $plaintext . "\x02";

    if ($padTo > 0 && strlen($padded) < $padTo) {
        $padded = str_pad($padded, $padTo, "\x00");
    }

    $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) {
        return false;
    }

    $recordSize = 4096;
    $header = $salt . pack('N', $recordSize) . chr(strlen($asPublicRaw)) . $asPublicRaw;

    return $header . $ciphertext . $tag;
}

/**
 * Sends one push message to one subscription.
 *
 * @param array{endpoint: string, p256dh: string, auth: string} $subscription
 * @param string $payload   Plaintext message body.
 * @param array{public: string, private_pem: string} $vapidKeys
 * @param string $subject   RFC 8292 "sub" claim.
 * @param int    $ttl       Seconds the push service may hold the message for
 *                          before giving up if the device is offline - a
 *                          month is generous and costs nothing unused.
 * @param array{host: string, ip: string, port: int}|null $ssrfPin DNS-pin
 *                          from is_url_safe_for_ssrf()/validate_webhook_url_for_ssrf(),
 *                          to close the DNS-rebinding gap the same way every
 *                          other outbound channel here already does.
 * @return array{success: bool, status: int|null, prune: bool, error: string|null}
 *         'prune' is true only for the responses (404/410) that mean the
 *         push service itself has discarded this subscription - never for a
 *         device merely being offline, which the push service queues
 *         through on its own; see includes/webpush_helper.php's own comment
 *         above webpush_send() usage in the cron job for why.
 */
function webpush_send($subscription, $payload, $vapidKeys, $subject, $ttl = 2419200, $ssrfPin = null)
{
    $body = webpush_encrypt_payload($payload, $subscription['p256dh'], $subscription['auth']);
    if ($body === false) {
        return ['success' => false, 'status' => null, 'prune' => false, 'error' => 'Could not encrypt the payload.'];
    }

    $jwt = webpush_build_vapid_jwt($subscription['endpoint'], $subject, $vapidKeys['private_pem']);
    if ($jwt === false) {
        return ['success' => false, 'status' => null, 'prune' => false, 'error' => 'Could not build the VAPID token.'];
    }

    // Never negative, never past what a push service will honour: a TTL it
    // rejects costs the whole message, and one it silently shortens is a
    // promise this code cannot keep anyway.
    $ttl = max(0, min((int) $ttl, WEBPUSH_TTL_MAX));

    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: ' . $ttl,
        'Authorization: vapid t=' . $jwt . ', k=' . $vapidKeys['public'],
    ];

    $resolve = $ssrfPin !== null ? "{$ssrfPin['host']}:{$ssrfPin['port']}:{$ssrfPin['ip']}" : '';

    $result = webpush_http_post($subscription['endpoint'], $body, $headers, $resolve);
    $response = $result['response'];
    $status = $result['status'];
    $curlError = $result['error'];

    if ($response === false) {
        return ['success' => false, 'status' => null, 'prune' => false, 'error' => $curlError];
    }

    $success = $status >= 200 && $status < 300;
    // 404/410: the push service has no record of this subscription any more
    // (unsubscribed, cleared site data, uninstalled, or its own garbage
    // collection of a very stale one) - safe to forget it. Every other
    // failure - 429 rate limited, a 5xx fault, a malformed request - says
    // nothing about whether the subscription itself is still good, so none
    // of those prune it.
    $prune = in_array($status, [404, 410], true);

    return ['success' => $success, 'status' => $status, 'prune' => $prune, 'error' => $success ? null : $response];
}

/* ---------------------------------------------------------------------------
   The one network touch, behind a function_exists guard so a test can stand in
   for the push service and drive the 404/410 cleanup without a socket - the
   arrangement wallos_oidc_discovery_http_get() uses.
   --------------------------------------------------------------------------- */

if (!function_exists('webpush_http_post')) {
    /**
     * POSTs an encrypted push to its endpoint.
     *
     * @param string   $url     the push endpoint
     * @param string   $body    the aes128gcm-encoded payload
     * @param string[] $headers request headers
     * @param string   $resolve a curl RESOLVE entry pinning the host to the IP
     *                          the SSRF check already approved, or ''
     * @return array{response: string|false, status: int, error: string}
     */
    function webpush_http_post($url, $body, array $headers, $resolve)
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
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['response' => $response, 'status' => $status, 'error' => $error];
    }
}

/* ---------------------------------------------------------------------------
   How long one reminder is worth delivering
   --------------------------------------------------------------------------- */

/**
 * The TTL for a reminder covering these subscriptions.
 *
 * One message can name several subscriptions, so it stays worth delivering
 * until the last of them has renewed, plus the grace period. A message with no
 * subscriptions in it - a period summary on its own - gets the grace period.
 *
 * @param array $perUser the subscriptions in this message, each with a 'days'
 *                       count until renewal
 * @param int   $grace   seconds to keep it past the last renewal
 * @return int seconds, never above what a push service will honour
 */
function webpush_ttl_for_renewals(array $perUser, $grace = WEBPUSH_TTL_GRACE)
{
    $furthest = 0;

    foreach ($perUser as $subscription) {
        $days = (int) ($subscription['days'] ?? 0);
        if ($days > $furthest) {
            $furthest = $days;
        }
    }

    return min($furthest * 86400 + $grace, WEBPUSH_TTL_MAX);
}

/* ---------------------------------------------------------------------------
   Subscriptions: what may be stored, who it belongs to, and how many
   --------------------------------------------------------------------------- */

/**
 * Whether a PushSubscription has the shape RFC 8291 fixes for it.
 *
 * The two keys are not free-form: §3.1 has the user agent generate a P-256 key
 * pair, whose public half travels in the uncompressed point form §4 spells out
 * - "a 65-octet sequence that starts with a 0x04 octet" - and §3.2 has the
 * auth secret at exactly sixteen octets. Anything else cannot be encrypted to,
 * so a row holding it is a subscription that will never receive a
 * notification.
 *
 * Checked at the door rather than only inside the encryption, for two reasons.
 * A malformed subscription discovered at the next notification run is a silent
 * per-row failure at nine in the morning; discovered here it is a 400 the
 * browser can act on while somebody is still looking at the settings page. And
 * these values are stored as whatever arrived - one authenticated request
 * could otherwise put two megabytes in the table and come back with a fresh
 * endpoint for the next one.
 *
 * @param string $endpoint
 * @param string $p256dh base64url client public key
 * @param string $auth   base64url client auth secret
 * @return bool
 */
function webpush_subscription_is_wellformed($endpoint, $p256dh, $auth)
{
    if ($endpoint === '' || strlen($endpoint) > 2048) {
        return false;
    }

    $parsed = parse_url($endpoint);
    if (
        !is_array($parsed)
        || !isset($parsed['scheme'])
        || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)
        || !filter_var($endpoint, FILTER_VALIDATE_URL)
    ) {
        return false;
    }

    $key = webpush_base64url_decode($p256dh);
    $secret = webpush_base64url_decode($auth);

    return strlen($key) === 65 && $key[0] === "\x04" && strlen($secret) === 16;
}

/**
 * The name a device is addressed by in the page and in a request.
 *
 * Not the endpoint: it is the address that receives this account's
 * notifications, and a settings page that lists devices would otherwise put it
 * into the page and into every request that follows. The hash is stable, so
 * the browser can compute it for its own subscription and recognise which row
 * is the device somebody is looking at.
 *
 * @param string $endpoint
 * @return string 16 hex characters
 */
function webpush_device_handle($endpoint)
{
    return substr(hash('sha256', (string) $endpoint), 0, 16);
}

/**
 * A human label for the device that subscribed, from its user agent.
 *
 * "Chrome on Android" is what a person recognises; the endpoint is not. The
 * string is client-supplied, so this never passes it through - it matches
 * against a fixed list and returns words chosen here, which means a crafted
 * user agent cannot put text of its own on the settings page.
 *
 * Order matters: Edge and Opera both carry "Chrome" in their user agent, and
 * every Chrome on iOS carries "Safari".
 *
 * @param string $userAgent
 * @return array{browser: string, platform: string} empty strings when unknown
 */
function webpush_device_label($userAgent)
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
 * Stores, or refreshes, one browser subscription for one account.
 *
 * The endpoint is unique per subscription by nature, but the table allows the
 * same endpoint under two accounts, so this decides who may claim it. The
 * discriminator is the client public key, and it is the honest one for the two
 * ways the collision happens:
 *
 *   On a shared family browser there is one registration and therefore one
 *   subscription. The second person to press "enable" gets the *existing*
 *   PushSubscription back from getSubscription(), keys and all, so their
 *   request carries the same p256dh and the device moves to them. That is the
 *   only answer a browser can honestly give, and it stays allowed.
 *
 *   Somebody who merely learned an endpoint string cannot produce its p256dh:
 *   the browser generated that key pair for that subscription and never handed
 *   out the private half. Their write changes nothing.
 *
 * Without this an account could take over another's device by posting its
 * endpoint, and the loser would see no sign of it - the settings page reads
 * the browser's own subscription rather than the stored row.
 *
 * @param WallosDatabase|SQLite3 $db
 * @param int    $userId
 * @param string $endpoint
 * @param string $p256dh    base64url client public key
 * @param string $auth      base64url client auth secret
 * @param string $userAgent client-supplied, stored only as label material
 * @return bool
 */
function webpush_store_subscription($db, $userId, $endpoint, $p256dh, $auth, $userAgent = '')
{
    $stmt = $db->prepare('SELECT user_id, p256dh FROM push_subscriptions WHERE endpoint = :endpoint');
    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':endpoint', $endpoint, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result === false) {
        return false;
    }

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ((int) $row['user_id'] === (int) $userId) {
            continue;
        }

        if (!hash_equals((string) $row['p256dh'], (string) $p256dh)) {
            return false;
        }

        // Same browser, different person signed in: the device moves rather
        // than being registered twice, so one press of "enable" does not send
        // every future reminder to both accounts.
        $move = $db->prepare('DELETE FROM push_subscriptions WHERE endpoint = :endpoint AND user_id = :userId');
        if ($move === false) {
            return false;
        }

        $move->bindValue(':endpoint', $endpoint, SQLITE3_TEXT);
        $move->bindValue(':userId', (int) $row['user_id'], SQLITE3_INTEGER);

        if ($move->execute() === false) {
            return false;
        }
    }

    $stmt = $db->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, created_at)
                          VALUES (:userId, :endpoint, :p256dh, :auth, :userAgent, :createdAt)
                          ON CONFLICT(user_id, endpoint) DO UPDATE SET
                              p256dh = excluded.p256dh,
                              auth = excluded.auth,
                              user_agent = excluded.user_agent,
                              created_at = excluded.created_at');
    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':endpoint', $endpoint, SQLITE3_TEXT);
    $stmt->bindValue(':p256dh', $p256dh, SQLITE3_TEXT);
    $stmt->bindValue(':auth', $auth, SQLITE3_TEXT);
    $stmt->bindValue(':userAgent', substr((string) $userAgent, 0, 255), SQLITE3_TEXT);
    $stmt->bindValue(':createdAt', date('Y-m-d H:i:s'), SQLITE3_TEXT);

    if ($stmt->execute() === false) {
        return false;
    }

    return webpush_trim_devices($db, $userId);
}

/**
 * Keeps an account's device list at WEBPUSH_MAX_DEVICES, oldest first.
 *
 * @param WallosDatabase|SQLite3 $db
 * @param int $userId
 * @return bool
 */
function webpush_trim_devices($db, $userId)
{
    $stmt = $db->prepare('SELECT id FROM push_subscriptions WHERE user_id = :userId
                          ORDER BY created_at DESC, id DESC');
    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if ($result === false) {
        return false;
    }

    $surplus = [];
    $seen = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $seen++;
        if ($seen > WEBPUSH_MAX_DEVICES) {
            $surplus[] = (int) $row['id'];
        }
    }

    foreach ($surplus as $id) {
        $delete = $db->prepare('DELETE FROM push_subscriptions WHERE id = :id AND user_id = :userId');
        if ($delete === false) {
            return false;
        }

        $delete->bindValue(':id', $id, SQLITE3_INTEGER);
        $delete->bindValue(':userId', $userId, SQLITE3_INTEGER);

        if ($delete->execute() === false) {
            return false;
        }
    }

    return true;
}

/**
 * The devices an account has registered, as the settings page shows them.
 *
 * The endpoint does not leave the server: each device is named by its handle
 * and described by words this file chose.
 *
 * @param WallosDatabase|SQLite3 $db
 * @param int $userId
 * @return array<int, array{handle: string, browser: string, platform: string, created_at: string}>
 */
function webpush_user_devices($db, $userId)
{
    $stmt = $db->prepare('SELECT endpoint, user_agent, created_at FROM push_subscriptions
                          WHERE user_id = :userId ORDER BY created_at DESC, id DESC');
    if ($stmt === false) {
        return [];
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if ($result === false) {
        return [];
    }

    $devices = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $label = webpush_device_label($row['user_agent'] ?? '');

        $devices[] = [
            'handle' => webpush_device_handle($row['endpoint']),
            'browser' => $label['browser'],
            'platform' => $label['platform'],
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $devices;
}

/**
 * Removes one device of one account, named by its handle.
 *
 * Scoped to the account in the same statement rather than after a lookup, so a
 * handle that belongs to somebody else's device matches nothing at all.
 *
 * @param WallosDatabase|SQLite3 $db
 * @param int    $userId
 * @param string $handle
 * @return bool whether a row was removed
 */
function webpush_delete_by_handle($db, $userId, $handle)
{
    $stmt = $db->prepare('SELECT id, endpoint FROM push_subscriptions WHERE user_id = :userId');
    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if ($result === false) {
        return false;
    }

    $target = null;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (hash_equals(webpush_device_handle($row['endpoint']), (string) $handle)) {
            $target = (int) $row['id'];
            break;
        }
    }

    if ($target === null) {
        return false;
    }

    $delete = $db->prepare('DELETE FROM push_subscriptions WHERE id = :id AND user_id = :userId');
    if ($delete === false) {
        return false;
    }

    $delete->bindValue(':id', $target, SQLITE3_INTEGER);
    $delete->bindValue(':userId', $userId, SQLITE3_INTEGER);

    return $delete->execute() !== false;
}

/**
 * The "sub" claim: who a push service reaches if it needs to complain.
 *
 * RFC 8292 §2.1 wants a mailto: or https: URL naming the operator. A real
 * address if the instance has one, otherwise a structurally valid placeholder
 * rather than a shared default that names somebody else's domain.
 * WALLOS_VAPID_SUBJECT overrides both.
 *
 * @param WallosDatabase|SQLite3 $db
 * @return string
 */
function webpush_resolve_subject($db)
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
 * Forgets one device of one account, by the row id the send loop holds.
 *
 * Only ever called for a 404/410: the push service itself has no record of
 * the subscription any more. Every other failure says nothing about whether
 * the subscription is still good, so none of them reach this.
 *
 * @param WallosDatabase|SQLite3 $db
 * @param int $userId
 * @param int $id
 * @return bool
 */
function webpush_prune_subscription($db, $userId, $id)
{
    $stmt = $db->prepare('DELETE FROM push_subscriptions WHERE id = :id AND user_id = :userId');
    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);

    return $stmt->execute() !== false;
}
