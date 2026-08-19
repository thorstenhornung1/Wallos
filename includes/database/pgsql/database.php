<?php

/**
 * The PostgreSQL implementation of the database boundary.
 *
 * It presents the SQLite3 surface — prepare, query, exec, querySingle,
 * lastInsertRowID, changes, close — because roughly fifteen hundred call sites
 * use it, and a backend that required rewriting them all at once would never
 * land. PDO does the actual work underneath.
 *
 * What this does NOT do is translate SQL. Dialect differences are fixed at the
 * call site, deliberately and visibly, so they can be reviewed. A rewriting
 * engine would turn every future query into a guess about what it really meant.
 */

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/result.php';
require_once __DIR__ . '/statement.php';

class WallosPgsqlDatabase implements WallosDatabase
{
    /** @var PDO */
    private $pdo;

    /** @var string */
    private $lastError = '';

    /**
     * Whether a statement has failed since the transaction began.
     *
     * PostgreSQL aborts an entire transaction on the first error: every later
     * statement fails with 25P02, and COMMIT on an aborted transaction quietly
     * performs a ROLLBACK and reports success. PDO sees no error, so commit()
     * answered true for a transaction that wrote nothing.
     *
     * SQLite has no aborted-transaction state, so nothing in the application
     * expects this. currency_provider.php wraps a rate refresh in a
     * transaction, checks some statements and not others, and returns
     * success — which would have told the user rates were updated while the
     * database was untouched.
     *
     * @var bool
     */
    private $failedInTransaction = false;

    /**
     * @param string $dsn
     * @param string $user
     * @param string $password
     * @throws PDOException when the connection cannot be established
     */
    public function __construct($dsn, $user, $password)
    {
        $this->pdo = new PDO($dsn, $user, $password, [
            // Errors as exceptions, caught at the boundary and turned into the
            // false that SQLite3 returns. Without this, a failed statement is a
            // silent false somewhere deep in a call site that never checks.
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements, not client-side interpolation.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // SQLite's date('now') and datetime('now') are always UTC, and every
        // comparison in the application is written against that. PostgreSQL
        // resolves CURRENT_TIMESTAMP in the session time zone, which follows
        // the container's TZ — Europe/Berlin in the dev environment, two hours
        // out. A DEFAULT CURRENT_TIMESTAMP column would then be written in
        // local time and compared against a UTC threshold, which shifts the
        // password-reset window by the offset and, depending on the direction,
        // either expires valid tokens or keeps expired ones alive.
        //
        // Pinning the session to UTC makes stored timestamps mean the same
        // thing on both backends, which is what the queries already assume.
        $this->pdo->exec("SET TIME ZONE 'UTC'");
    }

    public function driver()
    {
        return 'pgsql';
    }

    /**
     * @param string $sql
     * @return WallosPgsqlStatement|false
     */
    public function prepare($sql)
    {
        try {
            return new WallosPgsqlStatement($this->pdo->prepare($sql), $this);
        } catch (PDOException $exception) {
            $this->recordError($exception->getMessage());

            return false;
        }
    }

    /**
     * @param string $sql
     * @return WallosPgsqlResult|false
     */
    public function query($sql)
    {
        try {
            $statement = $this->pdo->query($sql);
        } catch (PDOException $exception) {
            $this->recordError($exception->getMessage());

            return false;
        }

        // Deliberately not recording an affected count: SQLite3::changes()
        // ignores reads entirely, and a SELECT overwriting the count is what
        // made the two backends disagree.

        return new WallosPgsqlResult($statement);
    }

    /**
     * SQLite3::exec() answers bool; PDO::exec() answers an affected-row count,
     * where zero is a perfectly successful statement that changed nothing.
     *
     * @param string $sql
     * @return bool
     */
    public function exec($sql)
    {
        try {
            $affected = $this->pdo->exec($sql);
        } catch (PDOException $exception) {
            $this->recordError($exception->getMessage());
            // Zero, not the previous count. SQLite actually keeps the old value
            // here — measured — so this is a deliberate divergence rather than
            // a match: a revocation that reports rows removed because an
            // earlier statement removed some is worse than an honest zero, and
            // two of the six changes() call sites revoke sessions and roles.
            $this->recordAffected(0);

            return false;
        }

        $this->recordAffected($affected === false ? 0 : (int) $affected);

        return $affected !== false;
    }

    /** @var int */
    private $lastAffected = 0;

    /**
     * @param string $sql
     * @param bool   $entireRow
     * @return mixed
     */
    public function querySingle($sql, $entireRow = false)
    {
        try {
            $statement = $this->pdo->query($sql);
        } catch (PDOException $exception) {
            $this->recordError($exception->getMessage());

            return false;
        }

        $row = $statement->fetch($entireRow ? PDO::FETCH_ASSOC : PDO::FETCH_NUM);

        if ($row === false) {
            // What SQLite3 answers for no row: false for a scalar, an empty
            // array for a row. Call sites depend on both.
            return $entireRow ? [] : null;
        }

        return $entireRow ? $row : $row[0];
    }

    public function scalar($sql, $parameters = [])
    {
        $statement = $this->prepare($sql);
        if ($statement === false) {
            return null;
        }

        foreach ($parameters as $name => $value) {
            $statement->bindValue(is_int($name) ? $name + 1 : $name, $value);
        }

        $result = $statement->execute();
        if ($result === false) {
            return null;
        }

        $row = $result->fetchArray(SQLITE3_NUM);

        return $row === false ? null : $row[0];
    }

    public function tableExists($table)
    {
        return (bool) $this->scalar(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = current_schema() AND table_name = :name LIMIT 1',
            [':name' => $table]
        );
    }

    public function columnExists($table, $column)
    {
        return (bool) $this->scalar(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column LIMIT 1',
            [':table' => $table, ':column' => $column]
        );
    }

    public function tablesWithColumn($column)
    {
        // Joined against information_schema.tables rather than reading
        // information_schema.columns alone, because that view lists the columns
        // of views as well, and a view is not somewhere rows are stored.
        $statement = $this->prepare(
            "SELECT c.table_name
             FROM information_schema.columns c
             JOIN information_schema.tables t
               ON t.table_schema = c.table_schema AND t.table_name = c.table_name
             WHERE c.table_schema = current_schema()
               AND t.table_type = 'BASE TABLE'
               AND c.column_name = :column
             ORDER BY c.table_name"
        );

        if ($statement === false) {
            return [];
        }

        $statement->bindValue(':column', $column);
        $result = $statement->execute();

        if ($result === false) {
            return [];
        }

        $names = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $names[] = $row['table_name'];
        }

        return $names;
    }

    public function beginTransaction()
    {
        $this->failedInTransaction = false;

        return $this->pdo->inTransaction() ? true : $this->pdo->beginTransaction();
    }

    public function commit()
    {
        if (!$this->pdo->inTransaction()) {
            return !$this->failedInTransaction;
        }

        if ($this->failedInTransaction) {
            // PostgreSQL has already discarded the work; COMMIT here would
            // report success for a transaction that wrote nothing. Roll back
            // explicitly and say so.
            $this->pdo->rollBack();
            $this->failedInTransaction = false;

            return false;
        }

        return $this->pdo->commit();
    }

    public function rollBack()
    {
        $this->failedInTransaction = false;

        return $this->pdo->inTransaction() ? $this->pdo->rollBack() : true;
    }

    /**
     * The id of the last inserted row.
     *
     * PostgreSQL answers this from the session's most recently used sequence.
     * It is therefore wrong in one case SQLite handles fine: an INSERT that
     * supplies the id explicitly does not touch the sequence, so this returns
     * whatever the previous sequence-using insert produced. Wallos always lets
     * the database assign ids, and a data import that does not must set the
     * sequences itself afterwards.
     *
     * @return int
     */
    public function lastInsertId()
    {
        try {
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $exception) {
            // No sequence has been used in this session yet.
            return 0;
        }
    }

    /** @return int */
    public function lastInsertRowID()
    {
        return $this->lastInsertId();
    }

    /**
     * Rows affected by the most recent statement.
     *
     * SQLite3::changes() is a property of the connection; PDO counts per
     * statement. The most recent one is remembered so the two behave alike.
     *
     * @return int
     */
    public function changes()
    {
        return $this->lastAffected;
    }

    /**
     * Records how many rows the most recent write touched.
     *
     * @param int $rows
     * @internal
     */
    public function recordAffected($rows)
    {
        $this->lastAffected = (int) $rows;
    }

    /** @return string */
    public function lastErrorMsg()
    {
        return $this->lastError;
    }

    /**
     * Accepted and ignored: SQLite serialises writers and needs a busy timeout,
     * PostgreSQL does not have the concept. Present so call sites do not have
     * to ask which backend they are on.
     *
     * @param int $milliseconds
     * @return bool
     */
    public function busyTimeout($milliseconds)
    {
        return true;
    }

    /** @return bool */
    public function close()
    {
        $this->pdo = null;

        return true;
    }

    /**
     * @param string $message
     * @internal
     */
    public function recordError($message)
    {
        $this->lastError = $message;

        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->failedInTransaction = true;
        }
    }
}
