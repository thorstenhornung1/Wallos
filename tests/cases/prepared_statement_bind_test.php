<?php
/*
  Issue #157 — the two prepared-statement bind/placeholder mismatches this sweep
  fixed, proven at the real database boundary on both backends.

  The whole point of the class is that it is invisible on SQLite and fatal on
  PostgreSQL: PDO runs a real prepared statement under ERRMODE_EXCEPTION, so a
  parameter the SQL does not declare makes execute() return false, and the
  fetchArray(false) after it is the 500. SQLite ignores the odd bind and runs the
  statement anyway. Each case below runs the endpoint's own statement through
  wallos_test_open_database() — the same boundary a request holds — and asserts
  the asymmetry directly: the buggy shape fails on PostgreSQL and is tolerated on
  SQLite, and the fixed shape runs on both.

  This is the boundary-level counterpart to the static gate in bind_audit_test.php:
  the gate keeps the source honest, these prove the runtime behaviour the fix is
  for. Reproducing the statement rather than including the endpoint follows
  database_boundary_test.php — the endpoints resolve their requires from the
  request's working directory and exit() through their auth, which a test cannot
  stand up, whereas the statement is the whole of the defect.
*/

wallos_test('get_admin_settings runs its admin query, and the #147 stray :userId bind is fatal only on PostgreSQL', function () {
    $db = wallos_test_open_database();

    // The statement the endpoint runs after the fix: SELECT * FROM "admin", with
    // no bind, because the query names no parameter.
    $fixed = $db->prepare('SELECT * FROM "admin"');
    assert_true($fixed !== false, 'the admin query prepares on ' . $db->driver());
    $fixedResult = $fixed->execute();
    assert_true($fixedResult !== false,
        'and executes on ' . $db->driver() . ' — this is the fix');

    // The #147 shape: :userId bound to a query that declares no parameter.
    $buggy = $db->prepare('SELECT * FROM "admin"');
    assert_true($buggy !== false, 'the same query prepares');
    @$buggy->bindValue(':userId', 1);
    $strayResult = @$buggy->execute();

    if ($db->driver() === 'pgsql') {
        assert_true($strayResult === false,
            'PostgreSQL rejects the stray parameter — execute() is false, and the '
            . 'fetchArray after it was the live 500');
    } else {
        assert_true($strayResult !== false,
            'SQLite ignores the stray bind and runs the query, which is why #147 '
            . 'was invisible until PostgreSQL');
    }
});

wallos_test('savemattermostnotifications updates without binding bot columns the UPDATE does not name', function () {
    $db = wallos_test_open_database();

    // A row already exists, so the endpoint takes the UPDATE branch. mattermost
    // notifications carry no foreign key, so the row can be seeded directly. The
    // INSERT branch binds all five columns and has always been correct — asserted
    // here so the fix is shown not to have touched it.
    $insertSql = 'INSERT INTO mattermost_notifications (enabled, webhook_url, user_id, bot_username, bot_icon_emoji)
                  VALUES (:enabled, :webhook_url, :userId, :bot_username, :bot_icon_emoji)';
    $seed = $db->prepare($insertSql);
    $seed->bindValue(':enabled', 1);
    $seed->bindValue(':webhook_url', 'https://example.com/hook');
    $seed->bindValue(':userId', 1);
    $seed->bindValue(':bot_username', 'wallos');
    $seed->bindValue(':bot_icon_emoji', ':moneybag:');
    assert_true($seed->execute() !== false,
        'the INSERT branch binds all five columns and runs on ' . $db->driver());

    // The UPDATE branch names enabled, webhook_url and user_id only.
    $updateSql = 'UPDATE mattermost_notifications
                  SET enabled = :enabled, webhook_url = :webhook_url WHERE user_id = :userId';

    // The fix: on the UPDATE branch the two bot binds are guarded away, so only
    // the parameters the SQL declares are bound.
    $fixed = $db->prepare($updateSql);
    $fixed->bindValue(':enabled', 0);
    $fixed->bindValue(':webhook_url', 'https://example.com/new');
    $fixed->bindValue(':userId', 1);
    assert_true($fixed->execute() !== false,
        'the fixed UPDATE binds only its own parameters and runs on ' . $db->driver());

    // The bug: bot_username and bot_icon_emoji bound unconditionally, though the
    // UPDATE names neither.
    $buggy = $db->prepare($updateSql);
    $buggy->bindValue(':enabled', 0);
    $buggy->bindValue(':webhook_url', 'https://example.com/x');
    $buggy->bindValue(':userId', 1);
    @$buggy->bindValue(':bot_username', 'wallos');
    @$buggy->bindValue(':bot_icon_emoji', ':robot:');
    $buggyResult = @$buggy->execute();

    if ($db->driver() === 'pgsql') {
        assert_true($buggyResult === false,
            'PostgreSQL rejects the stray bot parameters — an ordinary settings '
            . 'change was reported as a failure');
    } else {
        assert_true($buggyResult !== false,
            'SQLite ignores them and updates the row, hiding the defect');
    }
});
