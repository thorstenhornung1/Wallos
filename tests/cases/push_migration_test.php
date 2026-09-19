<?php
/*
  Migration 000090 moves Web Push onto upstream's shape.

  The cases below are about the two ways such a move goes wrong without saying
  so: a device that has to be registered again, and a keypair that is replaced
  rather than carried over. The second one is the quiet one — a browser is
  bound to the applicationServerKey it subscribed with, and a new pair makes
  every push service answer 403 for it. 403 is not 404/410, so nothing prunes
  the row, nothing appears in the settings page, and the reminders stop.
*/

/**
 * The table as this fork carried it since migration 000083, with rows in it.
 *
 * @param object $db
 * @param array<int, array{0:string,1:int}> $rows user id => [endpoint, created_at as epoch]
 * @return void
 */
function push_migration_old_shape($db, array $rows)
{
    $db->exec('DROP TABLE IF EXISTS push_subscriptions');
    $db->exec('DROP TABLE IF EXISTS push_notifications');
    $db->exec('CREATE TABLE push_subscriptions (
        endpoint TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        created_at INTEGER NOT NULL DEFAULT 0,
        user_agent TEXT DEFAULT \'\'
    )');
    $db->exec('CREATE INDEX idx_push_subscriptions_user ON push_subscriptions (user_id)');

    foreach ($rows as $userId => $row) {
        $stmt = $db->prepare('INSERT INTO push_subscriptions
            (endpoint, user_id, p256dh, auth, created_at, user_agent)
            VALUES (:endpoint, :user, :p256dh, :auth, :created, :agent)');
        $stmt->bindValue(':endpoint', $row[0], SQLITE3_TEXT);
        $stmt->bindValue(':user', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':p256dh', 'p256dh-of-' . $userId, SQLITE3_TEXT);
        $stmt->bindValue(':auth', 'auth-of-' . $userId, SQLITE3_TEXT);
        $stmt->bindValue(':created', $row[1], SQLITE3_INTEGER);
        $stmt->bindValue(':agent', 'Mozilla/5.0 device ' . $userId, SQLITE3_TEXT);
        $stmt->execute();
    }
}

/**
 * A real P-256 pair in the raw form this fork stored it in.
 *
 * @return array{public: string, private: string, point: string}
 */
function push_migration_raw_keypair()
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $details = openssl_pkey_get_details($key);

    $fixed = function ($value) {
        return str_pad($value, 32, "\x00", STR_PAD_LEFT);
    };
    $encode = function ($value) {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    };

    $point = "\x04" . $fixed($details['ec']['x']) . $fixed($details['ec']['y']);

    return [
        'public' => $encode($point),
        'private' => $encode($fixed($details['ec']['d'])),
        'point' => $point,
    ];
}

wallos_test('a device registered before the change keeps its subscription', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'one');
    wallos_test_create_user($db, 2, 'two');

    push_migration_old_shape($db, [
        1 => ['https://push.example/one', 1757894400],
        2 => ['https://push.example/two', 0],
    ]);

    $result = require WALLOS_ROOT . '/migrations/000090.php';
    assert_true($result, 'the migration reports success');

    $rows = [];
    $query = $db->query('SELECT id, user_id, endpoint, p256dh, auth, user_agent, created_at
                         FROM push_subscriptions ORDER BY user_id');
    while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    assert_same(2, count($rows), 'both devices are still registered');
    assert_same('https://push.example/one', $rows[0]['endpoint'], 'the endpoint is unchanged');
    assert_same('p256dh-of-1', $rows[0]['p256dh'], 'the client key is unchanged');
    assert_same('auth-of-1', $rows[0]['auth'], 'the auth secret is unchanged');
    assert_same('Mozilla/5.0 device 1', $rows[0]['user_agent'], 'the label material is unchanged');
    assert_true((int) $rows[0]['id'] > 0, 'the row has the surrogate id upstream identifies it by');

    // The epoch becomes the string upstream stores, and a row that never had a
    // date keeps having none rather than claiming 1970.
    assert_same('2025-09-15 00:00:00', $rows[0]['created_at'], 'the date is converted, not dropped');
    assert_same('', $rows[1]['created_at'], 'a row without a date does not gain one');

    $db->close();
});

wallos_test('an account with a device is not switched off by the upgrade', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'one');
    wallos_test_create_user($db, 2, 'two');
    wallos_test_create_user($db, 3, 'three');

    push_migration_old_shape($db, [
        1 => ['https://push.example/one', 1757894400],
        2 => ['https://push.example/two', 1757894400],
    ]);

    require WALLOS_ROOT . '/migrations/000090.php';

    $enabled = [];
    $query = $db->query('SELECT user_id, enabled FROM push_notifications ORDER BY user_id');
    while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
        $enabled[(int) $row['user_id']] = (int) $row['enabled'];
    }

    assert_same([1 => 1, 2 => 1], $enabled,
        'every account that had a device registered keeps the channel on, and nobody else gains a row');

    $db->close();
});

wallos_test('the keypair the browsers already know is carried over, not replaced', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    $db = wallos_test_open_database();
    $keypair = push_migration_raw_keypair();

    push_migration_old_shape($db, []);
    foreach (['vapid_public_key' => $keypair['public'], 'vapid_private_key' => $keypair['private']] as $key => $value) {
        $stmt = $db->prepare('INSERT INTO integration_settings (integration, setting_key, setting_value, is_secret)
                              VALUES (\'webpush\', :key, :value, 0)');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        $stmt->execute();
    }

    $result = require WALLOS_ROOT . '/migrations/000090.php';
    assert_true($result, 'the migration reports success');

    $admin = $db->querySingle('SELECT vapid_public_key, vapid_private_key FROM admin WHERE id = 1', true);

    assert_same($keypair['public'], $admin['vapid_public_key'],
        'the public key a subscribed browser holds is the one stored now');

    $private = openssl_pkey_get_private($admin['vapid_private_key']);
    assert_true($private !== false, 'the converted private key is one OpenSSL accepts');

    $details = openssl_pkey_get_details($private);
    $fixed = function ($value) {
        return str_pad($value, 32, "\x00", STR_PAD_LEFT);
    };
    $point = "\x04" . $fixed($details['ec']['x']) . $fixed($details['ec']['y']);

    assert_same(bin2hex($keypair['point']), bin2hex($point),
        'the converted key is the same key, not a new one');

    $db->close();
});

wallos_test('a keypair that cannot be converted stops the migration instead of half writing it', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    $db = wallos_test_open_database();
    push_migration_old_shape($db, []);

    // Truncated: the right shape, the wrong length. Writing the public half
    // and an unusable private half would leave an installation that cannot
    // send and cannot recover by generating a new pair either.
    $stmt = $db->prepare('INSERT INTO integration_settings (integration, setting_key, setting_value, is_secret)
                          VALUES (\'webpush\', :key, :value, 0)');
    foreach (['vapid_public_key' => 'BJvZ', 'vapid_private_key' => 'nope'] as $key => $value) {
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        $stmt->execute();
        $stmt->reset();
    }

    $result = require WALLOS_ROOT . '/migrations/000090.php';

    assert_true($result === false, 'the migration refuses rather than reporting success');

    $admin = $db->querySingle('SELECT vapid_public_key, vapid_private_key FROM admin WHERE id = 1', true);
    assert_same('', (string) $admin['vapid_public_key'], 'nothing was written to the admin row');
    assert_same('', (string) $admin['vapid_private_key'], 'not even the half that would have converted');

    $db->close();
});

wallos_test('the migration can run twice', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'one');
    push_migration_old_shape($db, [1 => ['https://push.example/one', 1757894400]]);

    require WALLOS_ROOT . '/migrations/000090.php';
    $second = require WALLOS_ROOT . '/migrations/000090.php';

    assert_true($second, 'the second run reports success');
    assert_same(1, (int) $db->querySingle('SELECT COUNT(*) FROM push_subscriptions'),
        'the device is registered once, not twice');
    assert_same(1, (int) $db->querySingle('SELECT COUNT(*) FROM push_notifications'),
        'the account has one switch, not two');

    $db->close();
});
