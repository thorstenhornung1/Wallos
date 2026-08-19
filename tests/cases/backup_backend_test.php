<?php
/*
  Backup and restore refuse on a backend they cannot speak for.

  All three endpoints in endpoints/db/ that move data move a *file*: backup.php
  zips db/, restore.php and import.php replace db/wallos.db. On PostgreSQL that
  file is not the database, and until issue #88 all three said "Success"
  anyway — an archive with no data in it, and a restore that replaced a file
  nothing reads.

  The measurement that opened the issue, on a running instance:

      SQLite file db/wallos.db  -> users: e2e     | subscriptions: 0
      LIVE database (pgsql)     -> users: typtest | subscriptions: 1

  These cases hold two things in place. That the decision is made from the live
  connection, asserted against the connection the application really holds on
  whichever backend the run uses. And that the endpoints still ask — a guard
  nobody calls is the same silent success with more code in front of it.

  The proper implementation is issue #23. Refusing is what makes the gap
  visible until it exists.
*/

require_once WALLOS_ROOT . '/endpoints/db/backend_guard.php';

/**
 * A connection that is nothing but its driver name.
 *
 * Used for the message text only, never for the decision: the decision is
 * asserted below against the object wallos_database_connect() hands a request.
 * A stub here means the wording is checked on both runs, including the SQLite
 * one that has no PostgreSQL server to reach.
 */
class WallosDriverStub
{
    private $driver;

    public function __construct($driver)
    {
        $this->driver = $driver;
    }

    public function driver()
    {
        return $this->driver;
    }
}

/**
 * A connection from before the boundary: it answers queries and nothing else.
 *
 * Wallos has fifteen hundred call sites holding the native sqlite handle, and
 * that class has no driver(). What the guard must not do is take the absence of
 * an answer for a reason to refuse — nor, in the other direction, reach a
 * PostgreSQL connection this way, which it cannot: that one always answers.
 */
class WallosVoicelessConnectionStub
{
    public function query($sql)
    {
        return false;
    }
}

wallos_test('the answer comes from the connection the request holds', function () {
    // The one case that says something different on each backend, and the
    // reason it opens the database rather than reading the environment: what
    // the endpoints have in hand is a connection, and they never asked it.
    $db = wallos_test_open_database();

    if (wallos_test_driver() === 'sqlite') {
        assert_true(wallos_db_file_backup_supported($db), 'the file-copy backup is what SQLite is');

        return;
    }

    assert_true(!wallos_db_file_backup_supported($db), 'PostgreSQL is refused');
    assert_contains('pg_dump', wallos_db_file_backup_refusal('backup', $db), 'and the backup names pg_dump');
    assert_contains('pg_restore', wallos_db_file_backup_refusal('restore', $db), 'and the restore names pg_restore');
});

wallos_test('a refusal names the backend and the tool that does work', function () {
    // "Not supported" on its own leaves the administrator exactly where the
    // silent success left them: holding no backup and no idea what to do next.
    $pgsql = new WallosDriverStub('pgsql');

    $backup = wallos_db_file_backup_refusal('backup', $pgsql);
    assert_contains('PostgreSQL', $backup, 'the backup refusal names the backend');
    assert_contains('pg_dump', $backup, 'and the tool');
    assert_contains('WALLOS_DB_NAME', $backup, 'and where the database is');

    $restore = wallos_db_file_backup_refusal('restore', $pgsql);
    assert_contains('PostgreSQL', $restore, 'the restore refusal names the backend');
    assert_contains('pg_restore', $restore, 'and the tool');
    assert_not_contains('pg_dump', $restore, 'and not the one for the other direction');

    // import.php deletes the setup token on its way through. Refusing keeps it,
    // and saying so is the difference between an install that can still be
    // finished and one whose operator thinks it cannot.
    $import = wallos_db_file_backup_refusal('import', $pgsql);
    assert_contains('pg_restore', $import, 'the setup restore names the tool');
    assert_contains('setup token', $import, 'and says the token was kept');
});

wallos_test('a backend nobody has taught it about gets its own name', function () {
    // Rather than being told to run pg_dump against MySQL.
    $message = wallos_db_file_backup_refusal('backup', new WallosDriverStub('mysql'));

    assert_contains('mysql', $message, 'the driver is named');
    assert_not_contains('pg_dump', $message, 'and PostgreSQL tools are not prescribed');
    assert_not_contains('PostgreSQL', $message, 'nor its name');
});

wallos_test('a connection from before the boundary keeps the file backup', function () {
    // The one place the guard answers "supported" without asking, so it is
    // worth pinning: a connection with no driver() is the native sqlite handle,
    // and refusing there would break backup for every installation that has
    // one.
    assert_true(
        wallos_db_file_backup_supported(new WallosVoicelessConnectionStub()),
        'a connection that cannot answer is the file backend'
    );
});

wallos_test('every endpoint that moves the database file asks first', function () {
    // Not strpos on the source: a comment naming the function satisfied that,
    // and so did requiring the file that defines it. wallos_test_file_calls()
    // asks PHP's tokeniser for a call.
    //
    // The list is derived rather than written down, so an endpoint added to
    // endpoints/db/ later with the same blind spot fails this case instead of
    // shipping.
    $moversOfTheFile = [];

    foreach (glob(WALLOS_ROOT . '/endpoints/db/*.php') as $file) {
        $source = file_get_contents($file);

        // A quoted path into db/ — the directory backup.php zips and the file
        // restore.php and import.php replace. Quoted, so the prose above these
        // guards does not count as a use.
        if (preg_match('#[\'"]\.\./\.\./db/#', $source) === 1) {
            $moversOfTheFile[] = basename($file);
        }
    }

    sort($moversOfTheFile);
    assert_same(['backup.php', 'import.php', 'restore.php'], $moversOfTheFile, 'the endpoints that touch db/');

    foreach ($moversOfTheFile as $endpoint) {
        assert_true(
            wallos_test_file_calls('endpoints/db/' . $endpoint, 'wallos_db_file_backup_supported'),
            $endpoint . ' asks what the backend is'
        );
    }
});

wallos_test('the refusal comes before anything is destroyed', function () {
    // A guard placed after the damage is not a guard. restore.php unlinks
    // db/wallos.db; import.php also deletes the setup token, which is what an
    // operator needs to try again — losing it to an operation that did nothing
    // is the worst outcome in this file.
    //
    // Both needles are looked up rather than compared straight, because a
    // missing one is strpos() === false, and false < 12 is true: the ordering
    // assertion would pass on a file that no longer contains either.
    $comesBefore = function ($path, $first, $second, $message) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);
        $left = strpos($source, $first);
        $right = strpos($source, $second);

        assert_true(
            $left !== false && $right !== false && $left < $right,
            $message . ' (' . $first . ' at ' . var_export($left, true)
                . ', ' . $second . ' at ' . var_export($right, true) . ')'
        );
    };

    $comesBefore(
        'endpoints/db/restore.php',
        'wallos_db_file_backup_supported',
        "unlink('../../db/wallos.db')",
        'restore.php refuses before it removes the database'
    );

    $comesBefore(
        'endpoints/db/import.php',
        'wallos_db_file_backup_supported',
        'unlink($setupTokenFile)',
        'import.php refuses before it deletes the setup token'
    );

    $comesBefore(
        'endpoints/db/import.php',
        'hash_equals',
        'wallos_db_file_backup_supported',
        'and refuses after the token is checked, so the backend is not announced to callers who hold none'
    );
});

wallos_test('migrate.php is deliberately not guarded', function () {
    // It is the one endpoint in the directory that already works on both
    // backends: it runs the chain through the boundary against whatever is
    // configured. Measured on PostgreSQL while #88 was being fixed — it applied
    // the one migration the baseline did not record yet, and the table was
    // there afterwards — so refusing here would break a working operation to
    // protect against a defect it does not have.
    assert_true(
        !wallos_test_file_calls('endpoints/db/migrate.php', 'wallos_db_file_backup_supported'),
        'migrate.php runs the migrations on either backend'
    );
    assert_contains(
        'run_migrations.php',
        file_get_contents(WALLOS_ROOT . '/endpoints/db/migrate.php'),
        'through the same runner the container start uses'
    );
});
