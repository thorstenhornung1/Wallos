<?php
// Web Push moves onto upstream's shape, so this fork stops holding a second
// implementation of the same channel.
//
// Upstream 5.8.0 shipped Web Push independently (its migration 000059). Its
// tables differ from the ones this fork has carried since 000083:
//
//   * push_subscriptions identifies a row by a surrogate id, with the pair
//     (user_id, endpoint) unique, where this fork used the endpoint itself as
//     the primary key.
//   * created_at is a 'Y-m-d H:i:s' string rather than a Unix epoch.
//   * the instance VAPID keypair sits on the admin row, as PEM, where this
//     fork keeps it in integration_settings as the raw base64url scalar.
//   * push_notifications carries one enabled flag per user; this fork treated
//     a registered device as the opt-in itself.
//
// Adopting the shape is what keeps the two trees from drifting apart for good:
// every improvement this fork made to the channel can then be offered upstream
// as a diff against the code he actually runs. The device rows themselves are
// carried over unchanged, so nobody has to re-register a browser.
//
// The silent failure this migration exists to prevent is the keypair. A push
// subscription is bound to the applicationServerKey the browser saw when it
// subscribed. Let the new code find empty columns and it generates a fresh
// pair, and from then on every push service answers 403 for every device
// already registered. 403 is not 404/410, so nothing is pruned, nothing is
// shown, and the notifications simply stop. So the keypair is converted and
// copied here, and the conversion is inlined rather than required from
// includes/, because a migration has to keep working after the code it was
// written beside has moved on.

$driver = $db->driver();

// ---------------------------------------------------------------------------
// 1. The per-user switch.
// ---------------------------------------------------------------------------

if ($db->exec('CREATE TABLE IF NOT EXISTS push_notifications (
    enabled INTEGER DEFAULT 0,
    user_id INTEGER,
    FOREIGN KEY (user_id) REFERENCES "user"(id)
)') === false) {
    error_log('Wallos: migration 000090 could not create push_notifications: ' . $db->lastErrorMsg());

    return false;
}

// Everybody who has a device registered today had the channel on, because a
// registered device was the whole of the opt-in. Without this row they would
// be switched off by the upgrade and would never be told.
if ($db->tableExists('push_subscriptions')) {
    if ($db->exec('INSERT INTO push_notifications (enabled, user_id)
                   SELECT 1, user_id FROM push_subscriptions
                   WHERE user_id NOT IN (SELECT user_id FROM push_notifications WHERE user_id IS NOT NULL)
                   GROUP BY user_id') === false) {
        error_log('Wallos: migration 000090 could not enable push for accounts that already have devices: '
            . $db->lastErrorMsg());

        return false;
    }
}

// ---------------------------------------------------------------------------
// 2. push_subscriptions takes upstream's shape.
// ---------------------------------------------------------------------------

if ($db->tableExists('push_subscriptions') && !$db->columnExists('push_subscriptions', 'id')) {
    if ($driver === 'pgsql') {
        // The endpoint stops being the primary key, so the constraint goes
        // before the column can be added.
        $steps = [
            'ALTER TABLE "push_subscriptions" DROP CONSTRAINT IF EXISTS "push_subscriptions_pkey"',
            'ALTER TABLE "push_subscriptions" ADD COLUMN "id" SERIAL PRIMARY KEY',
            'ALTER TABLE "push_subscriptions" ALTER COLUMN "created_at" DROP DEFAULT',
            // The same CASE the SQLite branch applies: a row that never had a
            // date keeps having none rather than claiming the epoch.
            'ALTER TABLE "push_subscriptions" ALTER COLUMN "created_at" TYPE TEXT
                USING CASE WHEN COALESCE("created_at", 0) > 0
                           THEN to_char(to_timestamp("created_at"), \'YYYY-MM-DD HH24:MI:SS\')
                           ELSE \'\' END',
            'ALTER TABLE "push_subscriptions" ALTER COLUMN "created_at" SET DEFAULT \'\'',
        ];

        foreach ($steps as $step) {
            if ($db->exec($step) === false) {
                error_log('Wallos: migration 000090 could not reshape push_subscriptions: ' . $db->lastErrorMsg());

                return false;
            }
        }
    } else {
        // SQLite cannot add a primary key to an existing table, so the table
        // is rebuilt. The rows move with it; only created_at changes form,
        // from an epoch to the string upstream stores.
        $steps = [
            'CREATE TABLE push_subscriptions_000090 (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                endpoint TEXT NOT NULL,
                p256dh TEXT NOT NULL,
                auth TEXT NOT NULL,
                user_agent TEXT DEFAULT \'\',
                created_at TEXT DEFAULT \'\',
                FOREIGN KEY (user_id) REFERENCES "user"(id)
            )',
            "INSERT INTO push_subscriptions_000090 (user_id, endpoint, p256dh, auth, user_agent, created_at)
             SELECT user_id, endpoint, p256dh, auth,
                    COALESCE(user_agent, ''),
                    CASE WHEN COALESCE(created_at, 0) > 0
                         THEN strftime('%Y-%m-%d %H:%M:%S', created_at, 'unixepoch')
                         ELSE '' END
             FROM push_subscriptions",
            'DROP INDEX IF EXISTS idx_push_subscriptions_user',
            'DROP TABLE push_subscriptions',
            'ALTER TABLE push_subscriptions_000090 RENAME TO push_subscriptions',
        ];

        foreach ($steps as $step) {
            if ($db->exec($step) === false) {
                error_log('Wallos: migration 000090 could not rebuild push_subscriptions: ' . $db->lastErrorMsg());

                return false;
            }
        }
    }
}

if ($db->exec('CREATE UNIQUE INDEX IF NOT EXISTS push_subscriptions_user_endpoint
               ON push_subscriptions (user_id, endpoint)') === false) {
    error_log('Wallos: migration 000090 could not create the subscription index: ' . $db->lastErrorMsg());

    return false;
}

// ---------------------------------------------------------------------------
// 3. The instance keypair moves to the admin row, in PEM.
// ---------------------------------------------------------------------------

foreach (['vapid_public_key', 'vapid_private_key'] as $column) {
    if (!$db->columnExists('admin', $column)) {
        if ($db->exec('ALTER TABLE admin ADD COLUMN ' . $column . " TEXT DEFAULT ''") === false) {
            error_log('Wallos: migration 000090 could not add admin.' . $column . ': ' . $db->lastErrorMsg());

            return false;
        }
    }
}

/**
 * The stored raw keypair as the PEM the new code expects.
 *
 * A P-256 private key is a 32-byte scalar and the 65-byte uncompressed point
 * it belongs to; PEM is that pair inside the SEC1 ASN.1 envelope. Written out
 * by hand because the numbers are fixed: only the two values vary, so the
 * surrounding bytes can be constants rather than a DER encoder.
 */
$migration000090Pem = function ($privateRaw, $publicRaw) {
    $pad = function ($value) {
        return strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4);
    };

    $d = base64_decode($pad($privateRaw), true);
    $point = base64_decode($pad($publicRaw), true);

    if ($d === false || $point === false || strlen($d) !== 32 || strlen($point) !== 65) {
        return null;
    }

    $der = "\x30\x77"                               // SEQUENCE, 119 bytes
        . "\x02\x01\x01"                            // version 1
        . "\x04\x20" . $d                           // privateKey, 32 bytes
        . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"  // [0] prime256v1
        . "\xa1\x44\x03\x42\x00" . $point;          // [1] BIT STRING, the point

    return "-----BEGIN EC PRIVATE KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END EC PRIVATE KEY-----\n";
};

if ($db->tableExists('integration_settings')) {
    $stored = ['vapid_public_key' => '', 'vapid_private_key' => ''];

    $result = $db->query("SELECT setting_key, setting_value FROM integration_settings
                          WHERE integration = 'webpush'");

    if ($result !== false) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (isset($stored[$row['setting_key']])) {
                $stored[$row['setting_key']] = (string) $row['setting_value'];
            }
        }
    }

    if ($stored['vapid_public_key'] !== '' && $stored['vapid_private_key'] !== '') {
        $pem = $migration000090Pem($stored['vapid_private_key'], $stored['vapid_public_key']);

        if ($pem === null) {
            // Refusing beats writing half a keypair: with one column filled
            // the new code would neither adopt this pair nor generate a
            // working one, and every registered device would go quiet.
            error_log('Wallos: migration 000090 found a stored VAPID keypair it could not convert; '
                . 'the existing subscriptions would stop working. Left in place.');

            return false;
        }

        $stmt = $db->prepare('UPDATE admin SET vapid_public_key = :public, vapid_private_key = :private
                              WHERE id = 1');

        if ($stmt === false) {
            error_log('Wallos: migration 000090 could not prepare the VAPID copy: ' . $db->lastErrorMsg());

            return false;
        }

        $stmt->bindValue(':public', $stored['vapid_public_key'], SQLITE3_TEXT);
        $stmt->bindValue(':private', $pem, SQLITE3_TEXT);

        if ($stmt->execute() === false) {
            error_log('Wallos: migration 000090 could not copy the VAPID keypair to the admin row: '
                . $db->lastErrorMsg());

            return false;
        }
    }
}

return true;
