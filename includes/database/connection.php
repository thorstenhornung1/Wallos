<?php

/**
 * The database boundary.
 *
 * Wallos talks to SQLite through the native SQLite3 extension in roughly
 * fifteen hundred places, and rewriting those is not what this file is for.
 * This is the seam: one place that decides what a connection is, plus the
 * handful of operations whose SQLite spelling would otherwise be scattered
 * across the application — checking whether a table exists, reading a single
 * value, wrapping work in a transaction.
 *
 * The SQLite implementation extends SQLite3, so every existing call site keeps
 * working untouched. That is deliberate: a boundary nobody can adopt gradually
 * is a boundary that never gets adopted.
 *
 * No PostgreSQL here, and no SQL translation. Dialect differences get rewritten
 * on purpose later, where they can be read and reviewed, rather than guessed at
 * by a rewriting engine at runtime.
 */

/**
 * What every backend must provide beyond the query methods themselves.
 *
 * The query methods — prepare, query, exec, close — are not listed here because
 * SQLite3 already declares them and PHP will not let an interface redeclare an
 * inherited signature it cannot match. They are part of the contract all the
 * same; a second backend has to offer them in the same shape.
 */
interface WallosDatabase
{
    /** @return string 'sqlite', later 'pgsql' */
    public function driver();

    /**
     * A single value, with bound parameters.
     *
     * The reason this exists: SQLite3::querySingle() takes no parameters, so
     * every caller that needs one either interpolates into SQL or writes four
     * lines of prepare/bind/execute/fetch. There are forty of them.
     *
     * @param string $sql
     * @param array  $parameters name => value, or positional values
     * @return mixed|null null when there is no row
     */
    public function scalar($sql, $parameters = []);

    /** @return bool */
    public function tableExists($table);

    /** @return bool */
    public function columnExists($table, $column);

    /**
     * Whether declared foreign keys are enforced on this connection.
     *
     * One backend has always enforced them and cannot stop; the other never
     * did until #92 and needs room to pause — the migration runner rebuilds
     * tables other tables reference, and repair work has to be able to look
     * at a violation without tripping over it.
     *
     * @param bool $enabled
     * @return bool
     */
    public function setForeignKeyEnforcement($enabled);

    /**
     * Every row that violates a declared foreign key, as
     * ['table' => ..., 'rowid' => ..., 'parent' => ...].
     *
     * Empty on the backend that enforces unconditionally — nothing violating
     * was ever accepted there. The repair migration reads this instead of
     * guessing (#92).
     *
     * @return array[]
     */
    public function foreignKeyViolations();

    /**
     * Makes the table's integer primary key monotonic: a freed id is never
     * handed out again.
     *
     * This is where the two backends genuinely differ, which is why it lives
     * here rather than in a migration. One assigns max+1 and therefore
     * recycles the newest deleted id — the #92 inheritance mechanism — and
     * fixing that needs a table rebuild; the other draws ids from a sequence
     * that never revisits a freed value and has nothing to do.
     *
     * @param string $table
     * @return bool
     */
    public function rebuildWithMonotonicIds($table);

    /**
     * Every base table carrying a column of this name.
     *
     * It exists so that user deletion can ask the schema which tables hold rows
     * for an account instead of keeping a list of them. The list Wallos used to
     * keep was transcribed into two endpoints, and three tables were missing
     * from both copies — a list that has to be updated by hand is a list that
     * goes stale silently.
     *
     * Views are excluded: a view is not somewhere rows are stored, and deleting
     * through one either fails or writes to the table underneath twice.
     *
     * @param string $column
     * @return string[] table names, sorted
     */
    public function tablesWithColumn($column);

    /**
     * Every base table that holds rows, sorted.
     *
     * The reasoning tablesWithColumn() follows, one step wider: the backup
     * archive has to write out whatever tables exist rather than a list that
     * goes stale the first time a migration adds one (issue #23).
     *
     * Views are excluded for the same reason they are there: a view is not
     * somewhere rows are stored.
     *
     * @return string[] table names, sorted
     */
    public function tables();

    /** @return bool */
    public function beginTransaction();

    /** @return bool */
    public function commit();

    /** @return bool */
    public function rollBack();

    /** @return int|string */
    public function lastInsertId();
}

/**
 * Where the SQLite database lives.
 *
 * It was spelled three different ways — 'db/wallos.db', '../../db/wallos.db',
 * `__DIR__ . '/../db/wallos.db'` — each correct only from the directory its
 * file happened to be included from. Resolving from this file's location makes
 * the answer independent of the working directory, which is the same file in
 * every case that worked before and a working one in the cases that did not.
 *
 * WALLOS_DB_PATH overrides it. Not needed today; it is what a test harness or a
 * second instance on one host would use, and it costs one line.
 *
 * @return string
 */
function wallos_database_path()
{
    $configured = getenv('WALLOS_DB_PATH');
    if (is_string($configured) && trim($configured) !== '') {
        return trim($configured);
    }

    return dirname(__DIR__, 2) . '/db/wallos.db';
}

/**
 * Open a connection to the configured backend.
 *
 * The $path and $flags arguments are SQLite-only and exist for the callers that
 * legitimately name a specific file: the migration runner, the test harness,
 * and createdatabase.php. Passing a path selects SQLite regardless of
 * configuration, because a caller asking for a file means a file.
 *
 * @param string|null $path  null for the configured backend and location
 * @param int|null    $flags SQLite3 open flags
 * @return WallosDatabase
 */
function wallos_database_connect($path = null, $flags = null)
{
    require_once __DIR__ . '/sqlite/database.php';

    if ($path !== null) {
        return $flags === null
            ? new WallosSqliteDatabase($path)
            : new WallosSqliteDatabase($path, $flags);
    }

    require_once __DIR__ . '/configuration.php';
    $configuration = wallos_database_configuration();

    if ($configuration['error'] !== null) {
        // Refusing outright rather than falling back. An instance that quietly
        // starts on a different database than the operator configured comes up
        // empty, and that is indistinguishable from data loss.
        wallos_database_fail($configuration['error']);
    }

    if ($configuration['driver'] === 'pgsql') {
        require_once __DIR__ . '/pgsql/database.php';

        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            wallos_database_fail('WALLOS_DB_DRIVER is pgsql but the pdo_pgsql extension is not installed.');
        }

        try {
            return new WallosPgsqlDatabase(
                wallos_database_pgsql_dsn($configuration['pgsql']),
                $configuration['pgsql']['user'],
                $configuration['pgsql']['password']
            );
        } catch (PDOException $exception) {
            // The message can contain the connection string but never the
            // password, which PDO takes separately.
            wallos_database_fail('Could not connect to PostgreSQL: ' . $exception->getMessage());
        }
    }

    return new WallosSqliteDatabase($configuration['sqlite']['path']);
}

/**
 * Stop, loudly, on a configuration that cannot be used.
 *
 * Everything else in Wallos assumes it has a database. There is no useful
 * degraded mode: a page that renders without one shows an empty account.
 *
 * @param string $message
 * @return void
 */
function wallos_database_fail($message)
{
    error_log('Wallos database configuration: ' . $message);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }

    http_response_code(500);
    die('Database configuration error. See the container log for details.');
}
