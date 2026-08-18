<?php
/*
  The database boundary.

  A seam, not a rewrite: SQLite still runs underneath and the roughly fifteen
  hundred existing call sites are untouched. What these cases hold in place is
  that the seam exists, that it behaves exactly as the raw extension did, and
  that the operations whose SQLite spelling would otherwise be scattered around
  the application have one home.
*/

require_once WALLOS_ROOT . '/includes/database/sqlite/database.php';

wallos_test('the connection is the boundary type', function () {
    $db = wallos_test_open_database();

    assert_true($db instanceof WallosDatabase, 'it implements the boundary');
    assert_true($db instanceof SQLite3, 'and is still a SQLite3, so existing call sites work');
    assert_same('sqlite', $db->driver(), 'and says which backend it is');

    $db->close();
});

wallos_test('every call site pattern still works unchanged', function () {
    // The whole point of extending rather than wrapping. If any of these broke,
    // the migration would have to touch every endpoint at once.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $stmt = $db->prepare('SELECT username FROM user WHERE id = :id');
    $stmt->bindValue(':id', 1, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    assert_same('alice', $row['username'], 'prepare/bind/execute/fetchArray');

    assert_same('alice', $db->querySingle('SELECT username FROM user WHERE id = 1'), 'querySingle');
    assert_true($db->exec("UPDATE user SET username = 'alice2' WHERE id = 1"), 'exec');
    assert_same(1, $db->changes(), 'changes');

    $result = $db->query('SELECT id FROM user');
    assert_true($result->fetchArray(SQLITE3_ASSOC) !== false, 'query and result iteration');

    $db->close();
});

// ----------------------------------------------------------------- scalar

wallos_test('scalar reads one value with bound parameters', function () {
    // SQLite3::querySingle() takes no parameters, so every caller needing one
    // either interpolates into SQL or writes four lines. There are forty.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    assert_same('bob', $db->scalar('SELECT username FROM user WHERE id = :id', [':id' => 2]),
        'named parameter');
    assert_same('alice', $db->scalar('SELECT username FROM user WHERE id = ?', [1]),
        'positional parameter');
    assert_same(2, (int) $db->scalar('SELECT COUNT(*) FROM user'), 'no parameters');

    $db->close();
});

wallos_test('scalar returns null rather than false for no row', function () {
    // querySingle() answers false, which is indistinguishable from a stored
    // empty string or a zero once it reaches a caller that compares loosely.
    $db = wallos_test_open_database();

    assert_true($db->scalar('SELECT username FROM user WHERE id = :id', [':id' => 999]) === null,
        'no row is null');

    $db->close();
});

wallos_test('scalar does not interpolate its parameters', function () {
    // The reason it exists rather than string concatenation.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $value = $db->scalar('SELECT username FROM user WHERE username = :name',
        [':name' => "alice' OR '1'='1"]);

    assert_true($value === null, 'the injection attempt matches nothing');
    assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM user'), 'and the table is intact');

    $db->close();
});

wallos_test('a broken statement yields null instead of a fatal error', function () {
    $db = wallos_test_open_database();

    assert_true(@$db->scalar('SELECT nonsense FROM nowhere') === null, 'refused quietly');

    $db->close();
});

// ------------------------------------------------------------ schema checks

wallos_test('tableExists answers for tables that do and do not exist', function () {
    $db = wallos_test_open_database();

    assert_true($db->tableExists('user'), 'user exists');
    assert_true($db->tableExists('user_roles'), 'so does a migrated table');
    assert_true(!$db->tableExists('no_such_table'), 'and this one does not');

    $db->close();
});

wallos_test('columnExists answers for columns that do and do not exist', function () {
    // Spelled as a pragma_table_info query in a dozen migrations. One home now.
    $db = wallos_test_open_database();

    assert_true($db->columnExists('oauth_settings', 'admin_claim'), 'a column added by migration');
    assert_true(!$db->columnExists('oauth_settings', 'no_such_column'), 'and one that is not there');
    assert_true(!$db->columnExists('no_such_table', 'anything'), 'no table means no column');

    $db->close();
});

wallos_test('a table name with a quote does not break the column check', function () {
    // pragma_table_info takes its argument inline — SQLite will not bind a
    // parameter there — so the name is quoted by hand, and hand-quoting is
    // where injection lives.
    $db = wallos_test_open_database();

    assert_true(!$db->columnExists("user') UNION SELECT 1 --", 'anything'), 'no match');
    assert_true($db->tableExists('user'), 'and the database is unharmed');

    $db->close();
});

// ------------------------------------------------------------- transactions

wallos_test('a committed transaction keeps its writes', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $db->beginTransaction();
    $db->exec("UPDATE user SET username = 'changed' WHERE id = 1");
    $db->commit();

    assert_same('changed', $db->scalar('SELECT username FROM user WHERE id = 1'), 'kept');

    $db->close();
});

wallos_test('a rolled back transaction discards its writes', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $db->beginTransaction();
    $db->exec("UPDATE user SET username = 'changed' WHERE id = 1");
    $db->rollBack();

    assert_same('alice', $db->scalar('SELECT username FROM user WHERE id = 1'), 'discarded');

    $db->close();
});

wallos_test('lastInsertId agrees with lastInsertRowID', function () {
    // Both spellings work while call sites migrate.
    $db = wallos_test_open_database();
    $db->exec("INSERT INTO user (username, email, password, main_currency) VALUES ('x', 'x@e.c', 'p', 1)");

    assert_same($db->lastInsertRowID(), $db->lastInsertId(), 'the same answer');
    assert_true($db->lastInsertId() > 0, 'and a real id');

    $db->close();
});

// ------------------------------------------------------------------ the path

wallos_test('the database path resolves independently of the working directory', function () {
    // It used to be spelled three ways — 'db/wallos.db', '../../db/wallos.db',
    // and __DIR__-relative — each correct only from the directory its file
    // happened to be included from.
    putenv('WALLOS_DB_PATH');

    $fromRoot = wallos_database_path();
    $previous = getcwd();
    chdir(sys_get_temp_dir());
    $fromElsewhere = wallos_database_path();
    chdir($previous);

    assert_same($fromRoot, $fromElsewhere, 'the same answer from anywhere');
    assert_true(strpos($fromRoot, '/db/wallos.db') !== false, 'and it is the database');
});

wallos_test('WALLOS_DB_PATH overrides the location', function () {
    putenv('WALLOS_DB_PATH=/tmp/somewhere-else.db');

    assert_same('/tmp/somewhere-else.db', wallos_database_path(), 'overridden');

    putenv('WALLOS_DB_PATH=   ');
    assert_true(strpos(wallos_database_path(), '/db/wallos.db') !== false,
        'blank is not an override');

    putenv('WALLOS_DB_PATH');
});

wallos_test('connections carry the busy timeout', function () {
    // SQLite serialises writers. Without it, a concurrent write fails
    // immediately rather than waiting, the moment two requests overlap — and it
    // used to be set by hand at every call site, which is how one gets missed.
    $source = file_get_contents(WALLOS_ROOT . '/includes/database/sqlite/database.php');

    assert_true(strpos($source, 'busyTimeout(5000)') !== false, 'set in the constructor');

    $connect = file_get_contents(WALLOS_ROOT . '/includes/database/connection.php');
    assert_true(strpos($connect, 'busyTimeout') === false,
        'and not repeated in the backend-neutral factory');
});

wallos_test('nothing opens a raw connection outside the boundary', function () {
    // The seam only holds if it is the only way in.
    $offenders = [];
    $paths = array_merge(
        glob(WALLOS_ROOT . '/*.php'),
        glob(WALLOS_ROOT . '/includes/*.php'),
        glob(WALLOS_ROOT . '/endpoints/*/*.php'),
        glob(WALLOS_ROOT . '/api/*/*.php')
    );

    foreach ($paths as $path) {
        if (strpos(file_get_contents($path), 'new SQLite3(') !== false) {
            $offenders[] = str_replace(WALLOS_ROOT . '/', '', $path);
        }
    }

    assert_same([], $offenders, 'application code opens connections through the factory');
});

wallos_test('application code no longer queries the SQLite schema directly', function () {
    // Ten files asked sqlite_master or pragma_table_info whether a table or
    // column existed. Those are the two questions a second backend answers
    // completely differently, and they were spread across endpoints, includes
    // and API handlers rather than sitting behind the boundary.
    $offenders = [];
    $paths = array_merge(
        glob(WALLOS_ROOT . '/*.php'),
        glob(WALLOS_ROOT . '/includes/*.php'),
        glob(WALLOS_ROOT . '/endpoints/*/*.php'),
        glob(WALLOS_ROOT . '/api/*/*.php')
    );

    foreach ($paths as $path) {
        // createdatabase.php builds the SQLite schema itself; migrations are
        // SQLite-only by design, since a second backend gets a baseline schema
        // rather than a replayed migration chain.
        if (basename($path) === 'createdatabase.php') {
            continue;
        }
        // Assembled rather than written out, so this check does not count as
        // one of the things it is checking for.
        $patterns = 'sqlite' . '_master|pragma' . '_table_info|PRA' . 'GMA ';
        if (preg_match('/' . $patterns . '/', file_get_contents($path)) === 1) {
            $offenders[] = str_replace(WALLOS_ROOT . '/', '', $path);
        }
    }

    assert_same([], $offenders, 'schema questions go through tableExists()/columnExists()');
});
