<?php

/**
 * The SQLite implementation of the database boundary.
 *
 * Everything dialect-specific lives under includes/database/sqlite/ so the
 * boundary audit (#41) can tell deliberate SQLite code from SQLite leaking into
 * the application. A second backend gets its own directory next to this one.
 *
 * It extends SQLite3 rather than wrapping it, which is what lets the roughly
 * fifteen hundred existing call sites keep working untouched. A boundary nobody
 * can adopt gradually is a boundary that never gets adopted.
 */

require_once __DIR__ . '/../connection.php';

class WallosSqliteDatabase extends SQLite3 implements WallosDatabase
{
    /**
     * @param string   $filename
     * @param int|null $flags
     */
    public function __construct($filename, $flags = null)
    {
        if ($flags === null) {
            parent::__construct($filename);
        } else {
            parent::__construct($filename, $flags);
        }

        // Five seconds, which every call site used to set for itself. SQLite
        // serialises writers, and without a busy timeout a concurrent write
        // fails immediately instead of waiting — the moment two requests
        // overlap, one of them errors.
        $this->busyTimeout(5000);
    }

    public function driver()
    {
        return 'sqlite';
    }

    public function scalar($sql, $parameters = [])
    {
        $statement = $this->prepare($sql);
        if ($statement === false) {
            return null;
        }

        foreach ($parameters as $name => $value) {
            // Positional arrays bind from 1; SQLite3 counts parameters that way
            // and an off-by-one here binds silently to the wrong placeholder.
            $key = is_int($name) ? $name + 1 : $name;
            $statement->bindValue($key, $value);
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
        $statement = $this->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        if ($statement === false) {
            return false;
        }
        $statement->bindValue(':name', $table, SQLITE3_TEXT);
        $result = $statement->execute();

        return $result !== false && $result->fetchArray(SQLITE3_ASSOC) !== false;
    }

    public function columnExists($table, $column)
    {
        // pragma_table_info takes the table name as an argument, and SQLite
        // will not bind a parameter in that position — so the name is quoted
        // rather than bound. Doubling single quotes is the SQLite escape.
        $quoted = "'" . str_replace("'", "''", $table) . "'";

        $statement = $this->prepare("SELECT 1 FROM pragma_table_info(" . $quoted . ") WHERE name = :column LIMIT 1");
        if ($statement === false) {
            return false;
        }
        $statement->bindValue(':column', $column, SQLITE3_TEXT);
        $result = $statement->execute();

        return $result !== false && $result->fetchArray(SQLITE3_ASSOC) !== false;
    }

    /**
     * Every base table that holds rows, sorted.
     *
     * @return string[]
     */
    public function tables()
    {
        $result = $this->query("SELECT name FROM sqlite_master
                                WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
                                ORDER BY name");

        if ($result === false) {
            return [];
        }

        $names = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $names[] = $row['name'];
        }

        return $names;
    }

    public function tablesWithColumn($column)
    {
        // The names are collected before any of them is inspected. Calling
        // columnExists() while this result is still being iterated leaves two
        // statements live on one connection, which SQLite tolerates and which
        // there is no reason to ask of it.
        $result = $this->query("SELECT name FROM sqlite_master
                                WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
                                ORDER BY name");
        if ($result === false) {
            return [];
        }

        $names = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $names[] = $row['name'];
        }

        $matching = [];
        foreach ($names as $name) {
            if ($this->columnExists($name, $column)) {
                $matching[] = $name;
            }
        }

        return $matching;
    }

    public function beginTransaction()
    {
        return $this->exec('BEGIN');
    }

    public function commit()
    {
        return $this->exec('COMMIT');
    }

    public function rollBack()
    {
        return $this->exec('ROLLBACK');
    }

    public function lastInsertId()
    {
        return $this->lastInsertRowID();
    }
}
