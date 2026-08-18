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
        foreach ($this->bindings as $parameter => $binding) {
            [$value, $type] = $binding;
            $this->statement->bindValue($parameter, $this->coerce($value, $type), $this->pdoType($value, $type));
        }

        try {
            $this->statement->execute();
        } catch (PDOException $exception) {
            $this->database->recordError($exception->getMessage());

            return false;
        }

        $this->database->recordStatement($this->statement);

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
        if (is_bool($value)) {
            return $value;
        }

        if ($type === SQLITE3_INTEGER && $value !== null) {
            return (int) $value;
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
        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }
        if ($type === SQLITE3_INTEGER || is_int($value)) {
            return PDO::PARAM_INT;
        }

        return PDO::PARAM_STR;
    }
}
