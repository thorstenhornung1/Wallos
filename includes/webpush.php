<?php
/*
  Web Push (RFC 8030 / 8291 / 8292) — a browser / PWA notification channel.

  Sibling of the Telegram/Pushover/ntfy/Gotify channels: an instance-level VAPID
  keypair (the shared credential) plus each user's own browser subscriptions (the
  personal destinations, one per device). A fired notification is encrypted per
  RFC 8291 and delivered to every one of the user's subscriptions.

  ## The crypto/dependency decision — EXPERIMENT (branch webpush_external)

  This branch answers one question and nothing else: what does Web Push look
  like when a library does the cryptography instead of our own code? The
  cryptography and the send transport are minishlink/web-push v11 here; every
  other part of the channel — the endpoints, the service worker, the settings
  UI, migration 000083, the send loop in the cron — is untouched.

  What the library owns now:

    * VAPID keypair    VAPID::createVapidKeys()
    * VAPID JWT (ES256) VAPID::getVapidHeaders(), via web-token/jwt-library
    * RFC 8291 payload  Encryption::encrypt(), aes128gcm content coding
    * the HTTP POST     a PSR-18 client (Guzzle), via WebPush::flush()

  What stays ours, because the library has no notion of any of it:

    * per-user scoping   a subscription belongs to the session user, and a
                         notification goes only to that user's devices
    * the SSRF check     the endpoint is whatever a client posted to the
                         subscribe endpoint, and the cron makes the server POST
                         to it every night; is_url_safe_for_ssrf() runs before
                         the library is handed anything, and the approved IP is
                         pinned into the curl handle so DNS cannot move under it
    * the 410 sweep      the library reports it, we delete the row
    * key resolution     WALLOS_VAPID_PRIVATE_KEY / _PUBLIC_KEY (or _FILE) over
                         a database-stored pair; only the generation moved

  Wallos otherwise ships a lean image with no Composer dependency tree, and the
  repository is the deployable artifact — upstream's README tells baremetal
  users to clone it into the webroot — so vendor/ is committed. That is the
  price of this branch, and nginx.conf now refuses /vendor/ over HTTP because a
  Composer tree inside the webroot is otherwise a directory of executable PHP.

  All values on the wire are base64url without padding, the encoding VAPID and
  the Push API use throughout; the library takes and returns the same encoding,
  so the stored p256dh/auth strings are handed over untouched.
*/

require_once __DIR__ . '/config_helper.php';
require_once __DIR__ . '/integration_config.php';
require_once __DIR__ . '/ssrf_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

/**
 * The content coding every current browser accepts and the only one RFC 8291
 * defines. The library still defaults to the legacy "aesgcm" draft encoding
 * when the argument is omitted, so it is passed explicitly at every call site
 * and asserted on the outgoing request in the tests.
 */
const WALLOS_WEBPUSH_CONTENT_ENCODING = 'aes128gcm';

/**
 * How long the push service should hold an undelivered message. Unchanged from
 * the self-implemented version: four weeks.
 */
const WALLOS_WEBPUSH_TTL = 2419200;

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
   message. The library's Encryption::encrypt() takes only p256dh and auth and
   makes its own ephemeral pair; the VAPID key is not one of its arguments. So
   the secret that protects the content is already per user, in fact per device,
   and it is not ours to hold.

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
 * Thin adapter over VAPID::createVapidKeys(), which returns the same two raw
 * base64url values under different array keys and throws where this returns
 * null — the shape the callers and the stored settings already expect.
 *
 * @return array{public: string, private: string}|null null when generation fails
 */
function wallos_webpush_generate_vapid_keys()
{
    try {
        $keys = VAPID::createVapidKeys();
    } catch (\Throwable $error) {
        return null;
    }

    if (!isset($keys['publicKey'], $keys['privateKey'])) {
        return null;
    }

    return [
        'public' => (string) $keys['publicKey'],
        'private' => (string) $keys['privateKey'],
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
                // wallos_set_instance_setting() persists each half and clears
                // the memoized instance settings, so a later read sees the pair.
                wallos_set_instance_setting($db, 'webpush', 'vapid_public_key', $generated['public']);
                wallos_set_instance_setting($db, 'webpush', 'vapid_private_key', $generated['private'], true);
                $public = $generated['public'];
                $private = $generated['private'];
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
   Sending

   Two seams the library does not provide, and both are load-bearing:

   * wallos_webpush_http_client() is the one network touch, behind a
     function_exists guard so a test can stand in for the push service and drive
     404/410 without a socket — the arrangement wallos_oidc_discovery_http_get()
     uses. The library takes a PSR-18 client, so the seam is an object here
     rather than a function call.

   * the client is built per send, not once, because it carries the CURLOPT_RESOLVE
     entry pinning this endpoint's host to the IP the SSRF check already
     approved. Resolving once and connecting again is a window; the library has
     no opinion about it and would simply hand the URL to curl.
   ------------------------------------------------------------------------- */

/**
 * A PSR-3 logger for the library, which otherwise reaches for trigger_error().
 *
 * Its warnings are real diagnostics — a missing extension, an OpenSSL without
 * P-256 — and belong in the error log. Its notices are advice about optional
 * speedups (bcmath/gmp) that would otherwise print on every cron run.
 */
class WallosWebPushLogger extends \Psr\Log\AbstractLogger
{
    /**
     * @param mixed             $level
     * @param string|\Stringable $message
     * @param array             $context
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        if (in_array((string) $level, ['emergency', 'alert', 'critical', 'error', 'warning'], true)) {
            error_log('[Wallos Web Push] ' . $message);
        }
    }
}

if (!function_exists('wallos_webpush_http_client')) {
    /**
     * The PSR-18 client the library POSTs through, pinned to one approved host.
     *
     * @param array{host: string, ip: string, port: int|string} $safe the
     *        is_url_safe_for_ssrf() verdict for this endpoint
     * @return \Psr\Http\Client\ClientInterface
     */
    function wallos_webpush_http_client($safe)
    {
        return new \GuzzleHttp\Client([
            'connect_timeout' => 5,
            'timeout' => 15,
            // Guzzle's PSR-18 sendRequest() sets both of these itself; repeated
            // here so the intent survives a change of entry point. A redirect
            // would leave the address the SSRF check approved.
            'allow_redirects' => false,
            'http_errors' => false,
            'curl' => [
                CURLOPT_RESOLVE => [$safe['host'] . ':' . $safe['port'] . ':' . $safe['ip']],
            ],
        ]);
    }
}

/**
 * Delivers one push to one subscription.
 *
 * The client-supplied endpoint is an outbound request to a target Wallos does
 * not control, so it goes through the SSRF allowlist exactly as the logo and
 * webhook fetches do — a private or reserved address is refused, not fetched,
 * and the library is never handed the endpoint at all. The library sends
 * wherever it is told.
 *
 * @param WallosDatabase $db
 * @param array          $subscription endpoint, p256dh, auth (base64url)
 * @param string         $payload      the message body to encrypt
 * @param int            $userId       the owner, for the SSRF role decision
 * @return array{sent: bool, expired: bool, status: int, error: string}
 */
function wallos_webpush_deliver($db, $subscription, $payload, $userId)
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

    try {
        $webPush = new WebPush(
            [
                'VAPID' => [
                    'subject' => (string) $config['values']['subject'],
                    'publicKey' => (string) $config['values']['public_key'],
                    'privateKey' => (string) $config['values']['private_key'],
                ],
            ],
            ['TTL' => WALLOS_WEBPUSH_TTL],
            wallos_webpush_http_client($safe),
            null,
            null,
            null,
            new WallosWebPushLogger()
        );

        $report = $webPush->sendOneNotification(
            new Subscription(
                $endpoint,
                (string) ($subscription['p256dh'] ?? ''),
                (string) ($subscription['auth'] ?? ''),
                WALLOS_WEBPUSH_CONTENT_ENCODING
            ),
            (string) $payload
        );
    } catch (\Throwable $error) {
        // A malformed keypair, a p256dh that is not a point, an oversized
        // payload: the library signals all of these by throwing, where the
        // self-implemented version returned null. The caller's contract is a
        // result array either way.
        return $fail('the push could not be prepared: ' . $error->getMessage());
    }

    $response = $report->getResponse();
    $status = $response !== null ? (int) $response->getStatusCode() : 0;

    // 404 Not Found / 410 Gone: the browser dropped this subscription, and the
    // standard response is to delete it so it is never tried again. The library
    // classifies it; the deletion is the caller's.
    if ($report->isSubscriptionExpired()) {
        return $fail('the subscription is gone', $status, true);
    }

    // isSuccess() is "not 4xx or 5xx", which would count a 3xx as delivered.
    // Redirects are off, so a 3xx here is a push service doing something we did
    // not follow — the self-implemented version called that a failure too.
    if ($report->isSuccess() && $status >= 200 && $status < 300) {
        return ['sent' => true, 'expired' => false, 'status' => $status, 'error' => ''];
    }

    $reason = $report->getReason();
    if ($reason === '' || $reason === 'OK') {
        $reason = $status > 0
            ? 'the push service answered HTTP ' . $status
            : 'no response from the push service';
    }

    return $fail($reason, $status);
}

/* -------------------------------------------------------------------------
   Subscription storage

   A user has several subscriptions, one per device, unlike the one-row-per-user
   shape of the other channels. The endpoint is unique across the table, so a
   device re-subscribing replaces its own row rather than accumulating stale
   copies. Ownership is always the server-side session user, never a value from
   the client.

   None of this is in the library, which has no concept of a user: its
   Subscription is a data class holding an endpoint and two keys, created fresh
   for each send and owned by nobody.
   ------------------------------------------------------------------------- */

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
function wallos_webpush_store_subscription($db, $userId, $endpoint, $p256dh, $auth)
{
    // ON CONFLICT with excluded.* so no named parameter is bound twice — the
    // upsert idiom that runs on both backends (see the OIDC discovery cache).
    $stmt = $db->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, created_at)
                          VALUES (:userId, :endpoint, :p256dh, :auth, :createdAt)
                          ON CONFLICT(endpoint) DO UPDATE SET
                              user_id = excluded.user_id,
                              p256dh = excluded.p256dh,
                              auth = excluded.auth,
                              created_at = excluded.created_at');
    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':userId', (int) $userId);
    $stmt->bindValue(':endpoint', (string) $endpoint);
    $stmt->bindValue(':p256dh', (string) $p256dh);
    $stmt->bindValue(':auth', (string) $auth);
    $stmt->bindValue(':createdAt', time());

    return $stmt->execute() !== false;
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
