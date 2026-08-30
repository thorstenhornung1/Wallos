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

        // Declared foreign keys are enforced from here on (#92). Three tables
        // have promised ON DELETE CASCADE for years and the promise was never
        // once kept, because this was off and switched on nowhere. The other
        // backend has always enforced the same declarations and its suite is
        // green — the standing proof that the application's write paths
        // survive enforcement. The migration runner pauses it for the length
        // of a chain; rebuilding a referenced table needs the room.
        $this->exec('PRAGMA foreign_keys = ON');
    }

    public function setForeignKeyEnforcement($enabled)
    {
        // A no-op inside a transaction by SQLite's own rules, so callers
        // switch it outside one — the rebuild below does exactly that.
        return $this->exec('PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF'));
    }

    public function foreignKeyViolations()
    {
        // Runs regardless of whether enforcement is on; that is what makes it
        // usable for repair, which has to see the violation it is removing.
        $result = $this->query('PRAGMA foreign_key_check');
        if ($result === false) {
            return [];
        }

        $violations = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $violations[] = [
                'table' => (string) $row['table'],
                'rowid' => $row['rowid'],
                'parent' => (string) $row['parent'],
            ];
        }

        return $violations;
    }

    public function rebuildWithMonotonicIds($table)
    {
        // On a connection of its own, because the swap drops a table and any
        // statement the session has ever left un-finalised answers that with
        // "table is locked" — the migration runner's own history of queries
        // was enough to trigger it. A fresh connection to the same file has
        // no statements by definition; the schema cookie tells every other
        // connection to re-read afterwards. A database without a file (the
        // in-memory kind) has no second way in and rebuilds in place.
        $file = $this->scalar("SELECT file FROM pragma_database_list WHERE name = 'main'");

        if (is_string($file) && $file !== '') {
            $worker = new self($file);
            $rebuilt = $worker->performMonotonicRebuild($table);
            $worker->close();

            return $rebuilt;
        }

        return $this->performMonotonicRebuild($table);
    }

    /**
     * The rebuild itself, on whatever connection this is.
     *
     * @param string $table
     * @return bool
     */
    private function performMonotonicRebuild($table)
    {
        // The storage assigns max+1, so deleting the newest account and
        // creating another reassigns the same id — the #92 inheritance
        // mechanism. The keyword that makes SQLite track the highest id ever
        // used instead cannot be added to an existing column, so the table is
        // rebuilt from its own catalogued definition: create the reshaped
        // twin, copy every row, swap the names, put the indexes and triggers
        // back. The definition comes from the catalogue rather than a
        // hard-coded snapshot so the rebuild is right for whatever column
        // set the migration chain has produced by the time it runs.
        $create = $this->scalar(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name",
            [':name' => $table]
        );

        if (!is_string($create) || $create === '') {
            error_log('Wallos: cannot rebuild ' . $table . ': its definition was not found.');

            return false;
        }

        if (stripos($create, 'AUTOINCREMENT') !== false) {
            // Already monotonic — an interrupted upgrade retrying, most
            // likely. Nothing to do is success.
            return true;
        }

        $needle = 'INTEGER PRIMARY KEY';
        $position = stripos($create, $needle);

        if ($position === false) {
            error_log('Wallos: cannot rebuild ' . $table . ': no integer primary key to make monotonic.');

            return false;
        }

        $reshaped = substr($create, 0, $position + strlen($needle))
            . ' AUTOINCREMENT'
            . substr($create, $position + strlen($needle));

        $temporary = $table . '_monotonic_rebuild';
        $namePattern = '/^\s*CREATE\s+TABLE\s+(?:"' . preg_quote($table, '/') . '"|'
            . preg_quote($table, '/') . ')\s*\(/i';
        $reshaped = preg_replace($namePattern, 'CREATE TABLE "' . $temporary . '" (', $reshaped, 1, $renamed);

        if ($renamed !== 1) {
            error_log('Wallos: cannot rebuild ' . $table . ': its definition was not recognised.');

            return false;
        }

        $quotedName = "'" . str_replace("'", "''", $table) . "'";

        $columns = [];
        $result = $this->query('SELECT name FROM pragma_table_info(' . $quotedName . ')');
        while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = '"' . str_replace('"', '""', $row['name']) . '"';
        }

        if ($columns === []) {
            error_log('Wallos: cannot rebuild ' . $table . ': no columns were found.');

            return false;
        }

        $columnList = implode(', ', $columns);

        // The indexes and triggers that name this table, to be put back after
        // the swap — dropping the table takes them with it.
        $attached = [];
        $result = $this->query("SELECT sql FROM sqlite_master WHERE tbl_name = " . $quotedName
            . " AND type IN ('index', 'trigger') AND sql IS NOT NULL");
        while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
            $attached[] = $row['sql'];
        }

        // Enforcement pauses around the swap: dropping a table other tables
        // reference is exactly what it exists to refuse. Outside the
        // transaction, because inside one the switch is a no-op.
        $enforcing = (int) $this->scalar('PRAGMA foreign_keys') === 1;
        if ($enforcing && $this->setForeignKeyEnforcement(false) === false) {
            return false;
        }

        $swapped = false;

        if ($this->beginTransaction() !== false) {
            $steps = array_merge(
                [
                    $reshaped,
                    'INSERT INTO "' . $temporary . '" (' . $columnList . ')'
                        . ' SELECT ' . $columnList . ' FROM "' . $table . '"',
                    'DROP TABLE "' . $table . '"',
                    'ALTER TABLE "' . $temporary . '" RENAME TO "' . $table . '"',
                ],
                $attached
            );

            $swapped = true;
            foreach ($steps as $step) {
                if ($this->exec($step) === false) {
                    error_log('Wallos: rebuilding ' . $table . ' failed at: ' . $step
                        . ' — ' . $this->lastErrorMsg());
                    $this->rollBack();
                    $swapped = false;

                    break;
                }
            }

            if ($swapped && $this->commit() === false) {
                $this->rollBack();
                $swapped = false;
            }
        }

        if ($enforcing && $this->setForeignKeyEnforcement(true) === false) {
            return false;
        }

        return $swapped;
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
