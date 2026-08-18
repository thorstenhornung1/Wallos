<?php

/**
 * A query result shaped like SQLite3Result.
 *
 * Wallos iterates results as `while ($row = $result->fetchArray(SQLITE3_ASSOC))`
 * in several hundred places. Matching that shape is what lets those places stay
 * as they are, which is the whole reason this backend can be added at all
 * without a rewrite.
 */
class WallosPgsqlResult
{
    /** @var PDOStatement|null */
    private $statement;

    /** @var bool */
    private $exhausted = false;

    public function __construct($statement)
    {
        $this->statement = $statement;
    }

    /**
     * The next row, or false when there are none left.
     *
     * @param int $mode SQLITE3_ASSOC, SQLITE3_NUM or SQLITE3_BOTH
     * @return array|false
     */
    public function fetchArray($mode = SQLITE3_BOTH)
    {
        if ($this->statement === null || $this->exhausted) {
            return false;
        }

        $modes = [
            SQLITE3_ASSOC => PDO::FETCH_ASSOC,
            SQLITE3_NUM => PDO::FETCH_NUM,
            SQLITE3_BOTH => PDO::FETCH_BOTH,
        ];

        $row = $this->statement->fetch($modes[$mode] ?? PDO::FETCH_BOTH);

        if ($row === false) {
            // SQLite3Result keeps answering false once drained; a PDO statement
            // that is fetched past its end can throw depending on the driver.
            $this->exhausted = true;

            return false;
        }

        return $row;
    }

    /**
     * @return bool
     */
    public function finalize()
    {
        if ($this->statement !== null) {
            $this->statement->closeCursor();
            $this->statement = null;
        }

        return true;
    }

    /** @return int */
    public function numColumns()
    {
        return $this->statement === null ? 0 : $this->statement->columnCount();
    }

    /**
     * Rewinds so the result can be read again.
     *
     * @return bool
     */
    public function reset()
    {
        if ($this->statement === null) {
            return false;
        }

        $this->statement->execute();
        $this->exhausted = false;

        return true;
    }
}
