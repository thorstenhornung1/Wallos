<?php

/**
 * A prepared statement shaped like SQLite3Stmt.
 *
 * Wallos binds with `bindValue(':name', $value, SQLITE3_TEXT)` in roughly five
 * hundred places. The type constants are accepted and mostly ignored: PDO
 * infers types well enough, and the one case where it does not — integers bound
 * to a column PostgreSQL treats strictly — is handled below.
 */
class WallosPgsqlStatement
{
    /** @var PDOStatement */
    private $statement;

    /** @var WallosPgsqlDatabase */
    private $database;

    /** @var array */
    private $bindings = [];

    public function __construct($statement, $database)
    {
        $this->statement = $statement;
        $this->database = $database;
    }

    /**
     * @param string|int $parameter ':name', 'name' or a 1-based position
     * @param mixed      $value
     * @param int|null   $type      SQLITE3_* constant, accepted for compatibility
     * @return bool
     */
    public function bindValue($parameter, $value, $type = null)
    {
        $this->bindings[$this->normalise($parameter)] = [$value, $type];

        return true;
    }

    /**
     * SQLite3Stmt::bindParam() binds by reference. Wallos never relies on the
     * reference semantics — it binds and executes immediately — so this behaves
     * like bindValue rather than pretending to something it does not do.
     *
     * @param string|int $parameter
     * @param mixed      $value
     * @param int|null   $type
     * @return bool
     */
    public function bindParam($parameter, &$value, $type = null)
    {
        return $this->bindValue($parameter, $value, $type);
    }

    /**
     * @return WallosPgsqlResult|false
     */
    public function execute()
    {
        try {
            // Binding is inside the try because it throws too. PDO rejects a
            // parameter the statement does not declare, where SQLite3 returns
            // false with a warning — so an uncaught bind failure escaped the
            // boundary as a fatal error rather than the false this contract
            // promises. api/subscriptions/get_ical_feed.php does exactly that:
            // it binds :inactive to a statement whose SQL hardcodes the value.
            foreach ($this->bindings as $parameter => $binding) {
                [$value, $type] = $binding;
                $this->statement->bindValue($parameter, $this->coerce($value, $type), $this->pdoType($value, $type));
            }

            $this->statement->execute();
        } catch (PDOException $exception) {
            $this->database->recordError($exception->getMessage());
            $this->database->recordAffected(0);

            return false;
        }

        // The affected-row count is recorded here rather than by remembering
        // the statement, because SQLite3::changes() belongs to the connection
        // and only ever counts writes. Remembering statements made a SELECT
        // overwrite the count, made exec() and a prepared statement disagree,
        // and — worst — left the previous count in place after a failure, so
        // wallos_revoke_login_token() reported rows removed for a DELETE that
        // never ran.
        $this->database->recordAffected($this->statement->rowCount());

        return new WallosPgsqlResult($this->statement);
    }

    /**
     * Clears the bindings so the statement can be filled in again — the pattern
     * used when inserting many rows in a loop.
     *
     * @return bool
     */
    public function reset()
    {
        $this->bindings = [];
        $this->statement->closeCursor();

        return true;
    }

    /** @return bool */
    public function close()
    {
        $this->statement->closeCursor();

        return true;
    }

    /**
     * SQLite3 accepts ':name', 'name' and positional integers alike.
     *
     * @param string|int $parameter
     * @return string|int
     */
    private function normalise($parameter)
    {
        if (is_int($parameter)) {
            return $parameter;
        }

        return $parameter[0] === ':' ? $parameter : ':' . $parameter;
    }

    /**
     * SQLite is forgiving about types; PostgreSQL is not.
     *
     * The case that matters: SQLite stores 0 and 1 in a column declared BOOLEAN
     * and compares them happily. PostgreSQL rejects an integer where a boolean
     * belongs. Booleans are therefore passed as booleans, and the schema decides
     * what the column is.
     *
     * @param mixed    $value
     * @param int|null $type
     * @return mixed
     */
    private function coerce($value, $type)
    {
        // The declared type wins over the PHP type, and the order matters.
        //
        // This used to test is_bool() first, on the reasoning that PostgreSQL
        // rejects an integer where a boolean belongs. The baseline schema maps
        // every BOOLEAN column to INTEGER on purpose — Wallos compares == 1
        // everywhere — so there is no boolean column to protect, and the
        // check created the hazard it was written to prevent: a PHP false
        // bound with SQLITE3_INTEGER became 'f' and PostgreSQL refused it.
        if ($type === SQLITE3_INTEGER && $value !== null) {
            return (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if ($type === SQLITE3_FLOAT && $value !== null) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * @param mixed    $value
     * @param int|null $type
     * @return int
     */
    private function pdoType($value, $type)
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }
        // Same ordering as coerce(): the schema has no boolean columns, so a
        // bool is on its way into an integer one.
        if ($type === SQLITE3_INTEGER || is_int($value) || is_bool($value)) {
            return PDO::PARAM_INT;
        }

        return PDO::PARAM_STR;
    }
}
