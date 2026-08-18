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
 * Open a connection.
 *
 * @param string|null $path  null for the configured location
 * @param int|null    $flags SQLite3 open flags
 * @return WallosSqliteDatabase
 */
function wallos_database_connect($path = null, $flags = null)
{
    $path = $path ?? wallos_database_path();

    require_once __DIR__ . '/sqlite/database.php';

    return $flags === null
        ? new WallosSqliteDatabase($path)
        : new WallosSqliteDatabase($path, $flags);
}
