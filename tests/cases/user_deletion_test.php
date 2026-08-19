<?php
/*
  Deleting a user account.

  Both flows used to open with DELETE FROM "user" and delete the twenty-odd
  child tables afterwards, outside a transaction, without inspecting a single
  return value. On SQLite that appeared to work, because SQLite enforces foreign
  keys only when a connection asks it to and Wallos never asks. On PostgreSQL
  the parent delete failed, the failure was swallowed at the boundary, and the
  endpoint answered {"success": true} over an account that had lost its
  settings, categories, household members and payment methods but could still
  log in.

  These cases run on both backends, which is the point: the SQLite run holds the
  behaviour in place, and the PostgreSQL run is the one that would have caught
  it in the first place.
*/

require_once WALLOS_ROOT . '/includes/user_deletion.php';

/**
 * A row in every table that stores anything for an account.
 *
 * Keyed by table so the case below can assert that this fixture still covers
 * the whole schema. When somebody adds a table with a user_id column and does
 * not add it here, that assertion is what says so.
 *
 * @param array $references household, category and payment method ids
 * @param int   $currencyId
 * @return array table => INSERT statement, :u bound to the user id
 */
function deletion_fixture_rows($references, $currencyId)
{
    return [
        'ai_recommendations' => 'INSERT INTO ai_recommendations (user_id, type, title, description)
                                 VALUES (:u, \'saving\', \'t\', \'d\')',
        'ai_settings' => 'INSERT INTO ai_settings (user_id, type, enabled, model)
                          VALUES (:u, \'recommendations\', 0, \'gpt\')',
        'categories' => 'INSERT INTO categories (name, "order", user_id) VALUES (\'Extra\', 2, :u)',
        'currencies' => 'INSERT INTO currencies (name, symbol, code, rate, user_id)
                         VALUES (\'Pound\', \'L\', \'GBP\', \'1.2\', :u)',
        'custom_colors' => 'INSERT INTO custom_colors (main_color, accent_color, hover_color, user_id)
                            VALUES (\'#111111\', \'#222222\', \'#333333\', :u)',
        // One of the three tables neither flow deleted from at all.
        'custom_css_style' => 'INSERT INTO custom_css_style (css, user_id) VALUES (\'body {}\', :u)',
        'discord_notifications' => 'INSERT INTO discord_notifications (enabled, webhook_url, user_id)
                                    VALUES (1, \'https://discord.example/hook\', :u)',
        'email_notifications' => 'INSERT INTO email_notifications (enabled, smtp_address, user_id)
                                  VALUES (1, \'smtp.example\', :u)',
        'email_verification' => 'INSERT INTO email_verification (user_id, email, token)
                                 VALUES (:u, \'v@example.com\', \'token\')',
        'fixer' => 'INSERT INTO fixer (api_key, user_id) VALUES (\'key\', :u)',
        'google_search' => 'INSERT INTO google_search (user_id, api_key) VALUES (:u, \'key\')',
        'gotify_notifications' => 'INSERT INTO gotify_notifications (enabled, url, token, user_id)
                                   VALUES (1, \'https://gotify.example\', \'t\', :u)',
        'household' => 'INSERT INTO household (name, email, user_id) VALUES (\'Partner\', \'p@example.com\', :u)',
        'last_exchange_update' => 'INSERT INTO last_exchange_update (date, user_id) VALUES (\'2026-01-01\', :u)',
        // Missing from the self-service flow, which left a working remember-me
        // token behind for an account that no longer existed.
        'login_tokens' => 'INSERT INTO login_tokens (user_id, token) VALUES (:u, \'remember-me\')',
        'mattermost_notifications' => 'INSERT INTO mattermost_notifications (enabled, user_id, webhook_url)
                                       VALUES (1, :u, \'https://mm.example/hook\')',
        'notification_settings' => 'INSERT INTO notification_settings (days, user_id) VALUES (3, :u)',
        'ntfy_notifications' => 'INSERT INTO ntfy_notifications (enabled, host, topic, user_id)
                                 VALUES (1, \'https://ntfy.example\', \'wallos\', :u)',
        'oidc_sessions' => 'INSERT INTO oidc_sessions (user_id, sid, session_id, login_token)
                            VALUES (:u, \'sid\', \'session\', \'token\')',
        'password_resets' => 'INSERT INTO password_resets (user_id, email, token)
                              VALUES (:u, \'r@example.com\', \'token\')',
        'payment_methods' => 'INSERT INTO payment_methods (name, icon, enabled, "order", user_id)
                              VALUES (\'Extra card\', \'\', 1, 2, :u)',
        'pushover_notifications' => 'INSERT INTO pushover_notifications (enabled, user_key, token, user_id)
                                     VALUES (1, \'k\', \'t\', :u)',
        'pushplus_notifications' => 'INSERT INTO pushplus_notifications (enabled, token, user_id)
                                     VALUES (1, \'t\', :u)',
        'serverchan_notifications' => 'INSERT INTO serverchan_notifications (enabled, sendkey, user_id)
                                       VALUES (1, \'k\', :u)',
        'settings' => 'INSERT INTO settings (dark_theme, color_theme, user_id) VALUES (1, \'blue\', :u)',
        // Every foreign key it carries points at a row the same account owns,
        // which is why subscriptions has to be emptied before any of them.
        'subscriptions' => 'INSERT INTO subscriptions
                            (name, price, currency_id, payment_method_id, payer_user_id, category_id, user_id)
                            VALUES (\'Fixture\', 1.5, ' . (int) $currencyId . ', '
                            . (int) $references['payment_method'] . ', '
                            . (int) $references['household'] . ', '
                            . (int) $references['category'] . ', :u)',
        'telegram_notifications' => 'INSERT INTO telegram_notifications (enabled, bot_token, chat_id, user_id)
                                     VALUES (1, \'b\', \'c\', :u)',
        'total_yearly_cost' => 'INSERT INTO total_yearly_cost (user_id, date, cost, currency)
                                VALUES (:u, \'2026-01-01\', 12.5, \'EUR\')',
        'totp' => 'INSERT INTO totp (user_id, totp_secret, backup_codes) VALUES (:u, \'secret\', \'codes\')',
        'uploaded_avatars' => 'INSERT INTO uploaded_avatars (user_id, path) VALUES (:u, \'images/uploads/a.png\')',
        'user_roles' => 'INSERT INTO user_roles (user_id, role, source) VALUES (:u, \'admin\', \'local\')',
        'webhook_notifications' => 'INSERT INTO webhook_notifications (enabled, url, user_id)
                                    VALUES (1, \'https://hook.example\', :u)',
    ];
}

/**
 * Creates an account and gives it a row in every table that references it.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $username
 * @return array the fixture statements that were run, keyed by table
 */
function deletion_seed_user($db, $userId, $username)
{
    wallos_test_create_user($db, $userId, $username);

    $rows = deletion_fixture_rows(
        wallos_test_user_references($db, $userId),
        wallos_test_currency_id($userId, 0)
    );

    foreach ($rows as $table => $sql) {
        $statement = $db->prepare($sql);
        if ($statement === false) {
            wallos_test_fail('the fixture could not prepare its insert into ' . $table
                . ': ' . $db->lastErrorMsg());
            continue;
        }

        $statement->bindValue(':u', $userId);

        if ($statement->execute() === false) {
            wallos_test_fail('the fixture could not insert into ' . $table
                . ': ' . $db->lastErrorMsg());
        }
    }

    return $rows;
}

/**
 * How many rows in the whole database still name this account.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @return array table => row count, only for tables that still have rows
 */
function deletion_remaining_rows($db, $userId)
{
    $remaining = [];

    foreach ($db->tablesWithColumn('user_id') as $table) {
        $count = (int) $db->scalar('SELECT COUNT(*) FROM "' . $table . '" WHERE user_id = :u', [':u' => $userId]);

        if ($count > 0) {
            $remaining[$table] = $count;
        }
    }

    $user = (int) $db->scalar('SELECT COUNT(*) FROM "user" WHERE id = :u', [':u' => $userId]);
    if ($user > 0) {
        $remaining['user'] = $user;
    }

    return $remaining;
}

/**
 * A connection that refuses to prepare the delete for one table.
 *
 * The failure the issue describes is a foreign key PostgreSQL enforces and
 * SQLite does not, so reproducing it identically on both backends is not
 * possible. What is possible, and what actually matters, is that a failure at
 * any point in the plan leaves the account whole — so the failure is injected
 * at the boundary instead, where both backends behave the same.
 *
 * It delegates rather than extends, and implements only what wallos_delete_user
 * calls, deliberately: a full stand-in would have to track two backends.
 */
class WallosDeletionFaultInjector
{
    /** @var WallosDatabase */
    private $database;

    /** @var string */
    private $failOn;

    public function __construct($database, $failOn)
    {
        $this->database = $database;
        $this->failOn = $failOn;
    }

    public function tablesWithColumn($column)
    {
        return $this->database->tablesWithColumn($column);
    }

    public function prepare($sql)
    {
        if (strpos($sql, '"' . $this->failOn . '"') !== false) {
            return false;
        }

        return $this->database->prepare($sql);
    }

    public function changes()
    {
        return $this->database->changes();
    }

    public function lastErrorMsg()
    {
        return 'injected failure on ' . $this->failOn;
    }

    public function beginTransaction()
    {
        return $this->database->beginTransaction();
    }

    public function commit()
    {
        return $this->database->commit();
    }

    public function rollBack()
    {
        return $this->database->rollBack();
    }
}

// ------------------------------------------------------------------ the plan

wallos_test('the tables are derived from the schema rather than listed by hand', function () {
    // Three tables — custom_css_style, ntfy_notifications and
    // serverchan_notifications — were missing from both hand-written copies of
    // the list, and twelve more from both. Asking the schema is what makes that
    // class of omission impossible.
    $db = wallos_test_open_database();

    $owned = $db->tablesWithColumn('user_id');
    $planned = [];
    foreach (wallos_user_deletion_plan($db) as $step) {
        if ($step['table'] !== 'user') {
            $planned[] = $step['table'];
        }
    }

    sort($owned);
    sort($planned);

    assert_same($owned, $planned, 'every table with a user_id column is in the plan');
    assert_true(count($owned) > 25, 'and there are as many of them as the schema has');

    foreach (['custom_css_style', 'ntfy_notifications', 'serverchan_notifications', 'login_tokens'] as $missed) {
        assert_true(in_array($missed, $planned, true), $missed . ' is covered now');
    }

    $db->close();
});

wallos_test('the fixture still covers every table that stores rows for an account', function () {
    // The case that fails when a new user_id-bearing table appears. Deriving
    // the deletion list keeps the code correct by itself; this keeps the test
    // honest, because a table nothing ever writes to proves nothing.
    $db = wallos_test_open_database();

    $covered = array_keys(deletion_fixture_rows(
        ['household' => 1, 'category' => 1, 'payment_method' => 1],
        1
    ));
    $owned = $db->tablesWithColumn('user_id');

    sort($covered);
    sort($owned);

    assert_same($owned, $covered,
        'add the new table to deletion_fixture_rows() so deletion is actually exercised on it');

    $db->close();
});

wallos_test('the plan puts the user row after its children and before its currencies', function () {
    // user.main_currency references currencies, which makes the account row a
    // child of its own currency list. Deleting the currencies first fails, and
    // that is exactly what happened once the user delete had already failed.
    $db = wallos_test_open_database();

    $order = [];
    foreach (wallos_user_deletion_plan($db) as $step) {
        $order[] = $step['table'];
    }

    $subscriptions = array_search('subscriptions', $order, true);
    $user = array_search('user', $order, true);
    $currencies = array_search('currencies', $order, true);

    assert_true($subscriptions === 0, 'subscriptions go first, before the rows they reference');
    assert_true($user > $subscriptions, 'the account row comes after its children');
    assert_true($currencies > $user, 'and its currencies come after the account row');
    assert_true($currencies === count($order) - 1, 'last of all');

    $db->close();
});

// --------------------------------------------------------------- the success

wallos_test('deleting an account leaves no row anywhere naming it', function () {
    $db = wallos_test_open_database();

    deletion_seed_user($db, 2, 'alice');
    deletion_seed_user($db, 3, 'bob');

    $result = wallos_delete_user($db, 2);

    assert_true($result['success'], 'the deletion succeeded: ' . var_export($result['error'], true));
    assert_same([], deletion_remaining_rows($db, 2), 'nothing anywhere still names the account');

    $db->close();
});

wallos_test('deleting an account leaves every other account alone', function () {
    // The live development database holds 36 orphaned household rows, 48
    // orphaned currencies and 48 orphaned categories. A deletion that took too
    // much would be the other way to get there.
    $db = wallos_test_open_database();

    deletion_seed_user($db, 2, 'alice');
    deletion_seed_user($db, 3, 'bob');

    $before = deletion_remaining_rows($db, 3);
    wallos_delete_user($db, 2);
    $after = deletion_remaining_rows($db, 3);

    assert_same($before, $after, 'the other account is untouched, table by table');
    assert_true(count($after) > 25, 'and it really did have rows everywhere');

    $db->close();
});

wallos_test('deleting an account that does not exist is reported as a failure', function () {
    // The old code answered {"success": true} for any id at all, because it
    // never looked at what the delete had done.
    $db = wallos_test_open_database();
    deletion_seed_user($db, 2, 'alice');

    $result = wallos_delete_user($db, 999);

    assert_true(!$result['success'], 'no such account is not a success');
    assert_contains('no user row with id 999', $result['error'], 'and it says so');
    assert_same([], deletion_remaining_rows($db, 999), 'nothing was invented');
    assert_true(count(deletion_remaining_rows($db, 2)) > 25, 'and the real account is untouched');

    $db->close();
});

wallos_test('an id that is not a positive integer deletes nothing', function () {
    // user_id defaults to 1 in half the schema, so a plan run with 0 would
    // empty a table's unclaimed rows on the way past.
    $db = wallos_test_open_database();
    deletion_seed_user($db, 2, 'alice');

    foreach ([0, -1, 'not a number'] as $id) {
        $result = wallos_delete_user($db, $id);
        assert_true(!$result['success'], var_export($id, true) . ' is refused');
    }

    assert_true(count(deletion_remaining_rows($db, 2)) > 25, 'the account is still whole');

    $db->close();
});

// --------------------------------------------------------------- the failure

wallos_test('a failure part way through leaves the account whole', function () {
    // The defect in one sentence: the child deletes that ran before the failure
    // used to stay committed, so the account kept its login and lost its
    // settings, categories, household members and payment methods.
    $db = wallos_test_open_database();

    $rows = deletion_seed_user($db, 2, 'alice');
    $before = deletion_remaining_rows($db, 2);

    // totp sits in the middle of the plan, after a dozen tables have already
    // been emptied and before the account row itself.
    $result = wallos_delete_user(new WallosDeletionFaultInjector($db, 'totp'), 2);

    assert_true(!$result['success'], 'the failure is reported');
    assert_contains('totp', $result['error'], 'and names the table that refused');
    assert_same($before, deletion_remaining_rows($db, 2), 'and nothing at all was deleted');
    assert_true(isset($before['user']), 'the account row included');
    assert_same(count($rows) + 1, count($before), 'every table the fixture wrote to is still there');

    $db->close();
});

wallos_test('a missing table is a failure rather than a silent half deletion', function () {
    // The dropped-table version of the same thing, through the real connection
    // rather than an injected fault: every backend refuses to prepare a
    // statement against a table that is not there.
    $db = wallos_test_open_database();

    deletion_seed_user($db, 2, 'alice');
    $before = deletion_remaining_rows($db, 2);

    // CASCADE only on PostgreSQL, and only to drop the foreign keys pointing at
    // the table; it does not remove any of the rows this case then checks for.
    $db->exec(wallos_test_driver() === 'pgsql' ? 'DROP TABLE "user" CASCADE' : 'DROP TABLE "user"');

    // Deliberately not silenced here: both callers answer JSON, so a PHP
    // warning escaping the routine would be printed ahead of the response body
    // and the browser would see an unparseable answer rather than the failure.
    $result = wallos_delete_user($db, 2);

    assert_true(!$result['success'], 'the deletion failed');

    foreach (['subscriptions', 'totp', 'categories', 'login_tokens', 'settings'] as $table) {
        assert_same($before[$table],
            (int) $db->scalar('SELECT COUNT(*) FROM "' . $table . '" WHERE user_id = :u', [':u' => 2]),
            $table . ' still holds every row it held before');
    }

    $db->close();
});

wallos_test('a foreign key nobody deleted first fails instead of half deleting', function () {
    if (wallos_test_driver() !== 'pgsql') {
        $GLOBALS['wallos_test_skipped'][] = [
            'test' => $GLOBALS['wallos_test_current'],
            'reason' => 'SQLite does not enforce foreign keys unless a connection switches them on',
        ];

        return;
    }

    // The original failure, reproduced: a table that references "user" and is
    // not in the plan, exactly as login_tokens, oidc_sessions, user_roles,
    // totp, custom_css_style, ntfy_notifications and serverchan_notifications
    // all were. The parent delete is refused, and the answer must be a failure
    // over an intact account rather than a success over a gutted one.
    $db = wallos_test_open_database();
    deletion_seed_user($db, 2, 'alice');

    $db->exec('CREATE TABLE deletion_blocker (
                   id SERIAL PRIMARY KEY,
                   owner_id INTEGER NOT NULL REFERENCES "user" ("id")
               )');
    $db->exec('INSERT INTO deletion_blocker (owner_id) VALUES (2)');

    $before = deletion_remaining_rows($db, 2);
    $result = wallos_delete_user($db, 2);

    assert_true(!$result['success'], 'the constraint is reported rather than swallowed');
    assert_contains('deleting from user failed', $result['error'], 'and the failing step is named');
    assert_same($before, deletion_remaining_rows($db, 2), 'the account is exactly as it was');
    assert_true(isset($before['settings']), 'settings included, which used to be deleted first');

    $db->close();
});

// -------------------------------------------------------------- the endpoints

wallos_test('both deletion endpoints go through the one routine and check its answer', function () {
    // The list of tables was transcribed into two files, which is how three of
    // them came to be missing from both copies.
    $endpoints = [
        'endpoints/admin/deleteuser.php',
        'endpoints/settings/deleteaccount.php',
    ];

    foreach ($endpoints as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        // Asked of the tokeniser rather than of strpos, because a comment
        // mentioning the function satisfies strpos and an endpoint that stopped
        // calling it would still pass.
        assert_true(wallos_test_file_calls($path, 'wallos_delete_user'), $path . ' calls the shared routine');
        assert_contains("if (!\$deletion['success'])", $source, $path . ' checks the answer');

        assert_true(stripos($source, 'DELETE FROM') === false,
            $path . ' no longer carries its own copy of the table list');
        assert_true(strpos($source, '$db->prepare(') === false,
            $path . ' does not delete anything by hand');
    }
});

wallos_test('the deletion endpoints report failure with success false', function () {
    // Both files used to end with a single unconditional success response, no
    // matter what the twenty statements above it had done.
    foreach (['endpoints/admin/deleteuser.php', 'endpoints/settings/deleteaccount.php'] as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        $successes = preg_match_all('/"success"\s*=>\s*true/', $source);
        $failures = preg_match_all('/"success"\s*=>\s*false/', $source);

        assert_same(1, $successes, $path . ' has exactly one success answer');
        assert_true($failures >= 2, $path . ' answers false for the guard and for a failed deletion');
    }
});
