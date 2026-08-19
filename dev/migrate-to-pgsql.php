<?php
/*
  Moves the contents of an existing SQLite installation into PostgreSQL.

      podman exec \
          -e WALLOS_DB_DRIVER=pgsql -e WALLOS_DB_HOST=postgres \
          -e WALLOS_DB_NAME=wallos -e WALLOS_DB_USER=wallos \
          -e WALLOS_DB_PASSWORD=wallos-dev \
          wallos-dev php /var/www/html/dev/migrate-to-pgsql.php --dry-run

  Issue #21 added PostgreSQL as a backend but excluded the move, so every
  existing installation could only reach it by starting over. Issue #79 is the
  move: schema.sql builds the tables, this fills them.

  The environment selects the target, exactly as it does for the running
  application, so an operator migrates into the database they are about to
  configure rather than into one described twice. The source is a file, named
  with --source or found where the application would look for it.

  Arguments:
      --source PATH       the SQLite file (default: WALLOS_DB_PATH, else db/wallos.db)
      --schema NAME       target schema (default: whatever the connection resolves to)
      --dry-run           report what would happen and write nothing
      --allow-non-empty   copy into a target that already holds data; the
                          existing rows are deleted first
      --skip-orphans      leave behind source rows that violate a foreign key,
                          and count them, instead of refusing
      --help

  Exit codes: 0 success or a clean dry run, 1 refused or failed, 2 bad usage.

  What this deliberately does not do: PostgreSQL to SQLite, incremental sync,
  or migrating while the application is running. Stop the container first.
*/

require_once __DIR__ . '/../includes/database/connection.php';
require_once __DIR__ . '/../includes/database/pgsql/install.php';

/**
 * Tables whose contents belong to the target rather than to the source.
 *
 * `migrations` records which SQLite migrations have been applied. The
 * PostgreSQL baseline seeds it with every migration already marked as done, so
 * includes/run_migrations.php finds nothing to do and never tries to replay
 * SQLite DDL against PostgreSQL. Copying the source's copy over it would work
 * only while the two agree, and would arm that replay the moment they did not.
 *
 * @return string[]
 */
function wallos_migrate_target_owned_tables()
{
    return ['migrations'];
}

// --------------------------------------------------------------------- usage

/** @return string */
function wallos_migrate_usage()
{
    return "Usage: php dev/migrate-to-pgsql.php [options]\n"
        . "\n"
        . "  --source PATH       the SQLite file to read (default: db/wallos.db)\n"
        . "  --schema NAME       target schema (default: the connection's own)\n"
        . "  --dry-run           report what would be copied and write nothing\n"
        . "  --allow-non-empty   copy into a target that already holds data\n"
        . "  --skip-orphans      skip source rows that violate a foreign key\n"
        . "  --help              this text\n"
        . "\n"
        . "The target is taken from the environment, the same way the application\n"
        . "takes it: WALLOS_DB_DRIVER=pgsql plus WALLOS_DB_HOST, WALLOS_DB_NAME,\n"
        . "WALLOS_DB_USER and WALLOS_DB_PASSWORD.\n";
}

/**
 * @param string[] $arguments the argument list without the script name
 * @return array{options: array, error: string|null}
 */
function wallos_migrate_parse_options($arguments)
{
    $options = [
        'source' => null,
        'schema' => null,
        'dry-run' => false,
        'allow-non-empty' => false,
        'skip-orphans' => false,
        'help' => false,
    ];

    $count = count($arguments);

    for ($index = 0; $index < $count; $index++) {
        $argument = $arguments[$index];

        switch ($argument) {
            case '--dry-run':
            case '--allow-non-empty':
            case '--skip-orphans':
                $options[substr($argument, 2)] = true;
                break;

            case '--help':
            case '-h':
                $options['help'] = true;
                break;

            case '--source':
            case '--schema':
                $name = substr($argument, 2);
                if (!isset($arguments[$index + 1])) {
                    return ['options' => $options, 'error' => $argument . ' needs a value.'];
                }
                $options[$name] = $arguments[++$index];
                break;

            default:
                return ['options' => $options, 'error' => 'Unknown argument: ' . $argument];
        }
    }

    if ($options['schema'] !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $options['schema']) !== 1) {
        // The schema name goes into SET search_path, which takes an identifier
        // and not a parameter, so it is checked rather than escaped.
        return ['options' => $options, 'error' => '--schema must be a plain identifier.'];
    }

    return ['options' => $options, 'error' => null];
}

// ---------------------------------------------------------------- identifiers

/**
 * An identifier, quoted for either backend.
 *
 * Everything is quoted, not only the reserved words. `user` is a table and
 * `order` is a column in two tables, both of which PostgreSQL refuses unquoted;
 * quoting selectively would mean carrying a keyword list in here and finding
 * out it was incomplete. Every Wallos identifier is lower case ASCII, so
 * quoting changes nothing else.
 *
 * @param string $name
 * @return string
 */
function wallos_migrate_quote($name)
{
    return '"' . str_replace('"', '""', $name) . '"';
}

// -------------------------------------------------------------------- source

/**
 * Opens the SQLite database without the ability to write to it.
 *
 * SQLITE3_OPEN_READONLY is the guarantee, not a convention: with it, a stray
 * INSERT in this file fails rather than modifying an installation the operator
 * still intends to fall back to if the migration goes wrong.
 *
 * @param string $path
 * @return WallosDatabase
 */
function wallos_migrate_open_source($path)
{
    return wallos_database_connect($path, SQLITE3_OPEN_READONLY);
}

/**
 * Size and modification time, so the report can show the source is untouched.
 *
 * @param string $path
 * @return array{size: int, mtime: int}
 */
function wallos_migrate_source_stat($path)
{
    clearstatcache(true, $path);

    return ['size' => (int) filesize($path), 'mtime' => (int) filemtime($path)];
}

/**
 * @param WallosDatabase $source
 * @return string[]
 */
function wallos_migrate_source_tables($source)
{
    $tables = [];
    $result = $source->query("SELECT name FROM sqlite_master WHERE type = 'table'
                              AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name");

    while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $tables[] = $row['name'];
    }

    return $tables;
}

/**
 * @param WallosDatabase $source
 * @param string         $table
 * @return string[] column names in declaration order
 */
function wallos_migrate_source_columns($source, $table)
{
    $columns = [];
    // pragma_table_info takes its argument inline; SQLite will not bind a
    // parameter in that position.
    $result = $source->query("SELECT name FROM pragma_table_info('"
        . str_replace("'", "''", $table) . "') ORDER BY cid");

    while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row['name'];
    }

    return $columns;
}

// -------------------------------------------------------------------- target

/**
 * @param WallosDatabase $target
 * @return string[]
 */
function wallos_migrate_target_tables($target)
{
    $tables = [];
    $result = $target->query("SELECT table_name FROM information_schema.tables
                              WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
                              ORDER BY table_name");

    while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $tables[] = $row['table_name'];
    }

    return $tables;
}

/**
 * Every column of every target table, with the type PostgreSQL will enforce.
 *
 * One query rather than one per table: forty-two round trips to learn something
 * information_schema will hand over in a single answer is forty-one too many.
 *
 * @param WallosDatabase $target
 * @return array table => column => array{type: string, nullable: bool, default: string|null}
 */
function wallos_migrate_target_columns($target)
{
    $columns = [];
    $result = $target->query("SELECT c.table_name, c.column_name, c.data_type, c.is_nullable, c.column_default
                              FROM information_schema.columns c
                              JOIN information_schema.tables t
                                ON t.table_schema = c.table_schema AND t.table_name = c.table_name
                              WHERE c.table_schema = current_schema() AND t.table_type = 'BASE TABLE'
                              ORDER BY c.table_name, c.ordinal_position");

    while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[$row['table_name']][$row['column_name']] = [
            'type' => $row['data_type'],
            'nullable' => $row['is_nullable'] === 'YES',
            'default' => $row['column_default'],
        ];
    }

    return $columns;
}

/**
 * The foreign keys the target will enforce.
 *
 * These are read from the target rather than from the source on purpose: SQLite
 * has never enforced a foreign key in this project — PRAGMA foreign_keys is off
 * everywhere — so the source's declarations say what someone once intended,
 * while the target's say what is about to be checked against every row.
 *
 * @param WallosDatabase $target
 * @return array<int, array{name: string, child_table: string, child_column: string,
 *                          parent_table: string, parent_column: string, columns: int}>
 */
function wallos_migrate_target_foreign_keys($target)
{
    $keys = [];
    $result = $target->query(
        "SELECT con.conname AS name,
                child.relname AS child_table,
                childcol.attname AS child_column,
                parent.relname AS parent_table,
                parentcol.attname AS parent_column,
                array_length(con.conkey, 1) AS columns
         FROM pg_constraint con
         JOIN pg_class child ON child.oid = con.conrelid
         JOIN pg_class parent ON parent.oid = con.confrelid
         JOIN pg_attribute childcol ON childcol.attrelid = con.conrelid AND childcol.attnum = con.conkey[1]
         JOIN pg_attribute parentcol ON parentcol.attrelid = con.confrelid AND parentcol.attnum = con.confkey[1]
         WHERE con.contype = 'f' AND con.connamespace = current_schema()::regnamespace
         ORDER BY con.conname"
    );

    while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['columns'] = (int) $row['columns'];
        $keys[] = $row;
    }

    return $keys;
}

/**
 * Every column backed by a sequence, and the sequence behind it.
 *
 * pg_get_serial_sequence answers for both SERIAL columns and identity columns,
 * so this keeps working if the baseline ever moves to GENERATED AS IDENTITY.
 *
 * @param WallosDatabase $target
 * @return array<int, array{table: string, column: string, sequence: string}>
 */
function wallos_migrate_target_sequences($target)
{
    $sequences = [];
    $result = $target->query(
        "SELECT c.table_name, c.column_name,
                pg_get_serial_sequence(quote_ident(c.table_schema) || '.' || quote_ident(c.table_name),
                                       c.column_name) AS sequence_name
         FROM information_schema.columns c
         JOIN information_schema.tables t
           ON t.table_schema = c.table_schema AND t.table_name = c.table_name
         WHERE c.table_schema = current_schema() AND t.table_type = 'BASE TABLE'
         ORDER BY c.table_name, c.column_name"
    );

    while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['sequence_name'] === null) {
            continue;
        }

        $sequences[] = [
            'table' => $row['table_name'],
            'column' => $row['column_name'],
            'sequence' => $row['sequence_name'],
        ];
    }

    return $sequences;
}

// ------------------------------------------------------------------ ordering

/**
 * Tables ordered so that a referenced row always exists before the row that
 * references it.
 *
 * The alternative the issue mentions — deferring the constraints for the
 * duration — is not available: PostgreSQL will only defer a constraint that was
 * declared DEFERRABLE, and none of the thirteen in the baseline is. Dropping
 * and recreating them instead would revalidate at the end and fail on exactly
 * the rows this order avoids failing on, only later and with everything already
 * written.
 *
 * @param string[] $tables
 * @param array    $foreignKeys
 * @return array{order: string[], cycle: bool}
 */
function wallos_migrate_table_order($tables, $foreignKeys)
{
    $dependencies = array_fill_keys($tables, []);

    foreach ($foreignKeys as $key) {
        $child = $key['child_table'];
        $parent = $key['parent_table'];

        // A self-reference orders nothing: the rows arrive in one pass and a
        // table cannot be inserted before itself.
        if ($child === $parent || !isset($dependencies[$child]) || !isset($dependencies[$parent])) {
            continue;
        }

        $dependencies[$child][$parent] = true;
    }

    $order = [];
    $placed = [];
    $remaining = $tables;

    while ($remaining !== []) {
        $progress = false;

        foreach ($remaining as $index => $table) {
            foreach (array_keys($dependencies[$table]) as $parent) {
                if (!isset($placed[$parent])) {
                    continue 2;
                }
            }

            $order[] = $table;
            $placed[$table] = true;
            unset($remaining[$index]);
            $progress = true;
        }

        if (!$progress) {
            // A cycle among the foreign keys. The baseline has none, and if one
            // ever appears the copy has to be told about it rather than
            // silently producing an order that cannot work.
            foreach ($remaining as $table) {
                $order[] = $table;
            }

            return ['order' => $order, 'cycle' => true];
        }

        $remaining = array_values($remaining);
    }

    return ['order' => $order, 'cycle' => false];
}

// ------------------------------------------------------------------ preflight

/**
 * How many rows the baseline schema seeds into each table.
 *
 * A freshly installed PostgreSQL database is not empty: schema.sql carries the
 * reference data — currencies, cycles, payment methods, the admin row — that
 * createdatabase.php seeds on SQLite. Treating any row at all as "non-empty"
 * would make every fresh install refuse the one migration it exists for, so the
 * guard needs to know what a fresh install looks like.
 *
 * Counted from the file rather than from a scratch database, because that needs
 * no privileges and no second connection.
 *
 * @param string|null $path
 * @return array<string, int> table => rows seeded
 */
function wallos_migrate_baseline_seed_counts($path = null)
{
    $path = $path ?? dirname(__DIR__) . '/includes/database/pgsql/schema.sql';

    if (!is_readable($path)) {
        return [];
    }

    $sql = (string) file_get_contents($path);
    $counts = [];
    $offset = 0;

    while (preg_match('/INSERT\s+INTO\s+"([^"]+)"\s*\([^)]*\)\s*VALUES/i', $sql, $matches, PREG_OFFSET_CAPTURE, $offset)) {
        $table = $matches[1][0];
        $start = $matches[0][1] + strlen($matches[0][0]);
        $tuples = wallos_migrate_count_tuples($sql, $start);

        $counts[$table] = ($counts[$table] ?? 0) + $tuples['rows'];
        $offset = $tuples['end'];
    }

    return $counts;
}

/**
 * Counts the value tuples of one INSERT, starting just after its VALUES.
 *
 * Written as a scan rather than a regular expression because the seeded rows
 * contain both parentheses and doubled single quotes, and a pattern that
 * handles those is a pattern nobody can check by reading it.
 *
 * @param string $sql
 * @param int    $start
 * @return array{rows: int, end: int}
 */
function wallos_migrate_count_tuples($sql, $start)
{
    $length = strlen($sql);
    $depth = 0;
    $rows = 0;
    $inString = false;

    for ($index = $start; $index < $length; $index++) {
        $character = $sql[$index];

        if ($inString) {
            if ($character === "'") {
                // '' inside a string is an escaped quote, not the end of it.
                if ($index + 1 < $length && $sql[$index + 1] === "'") {
                    $index++;
                    continue;
                }
                $inString = false;
            }
            continue;
        }

        if ($character === "'") {
            $inString = true;
        } elseif ($character === '(') {
            if ($depth === 0) {
                $rows++;
            }
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        } elseif ($character === ';' && $depth === 0) {
            return ['rows' => $rows, 'end' => $index + 1];
        }
    }

    return ['rows' => $rows, 'end' => $length];
}

/**
 * Source rows that the target's foreign keys would reject.
 *
 * Run to a fixpoint because skipping is contagious: a `user` row left behind
 * because its main_currency is missing turns every login token, role and TOTP
 * secret pointing at it into an orphan too. One pass would report the first
 * layer and the copy would then fail on the second.
 *
 * @param WallosDatabase $source
 * @param array          $foreignKeys
 * @param string[]       $sourceTables
 * @return array{skipped: array<string, array<int, bool>>, constraints: array}
 */
function wallos_migrate_orphans($source, $foreignKeys, $sourceTables)
{
    $skipped = [];
    $constraints = [];

    do {
        $added = 0;

        foreach ($foreignKeys as $key) {
            $child = $key['child_table'];
            $parent = $key['parent_table'];

            if (!in_array($child, $sourceTables, true) || !in_array($parent, $sourceTables, true)) {
                continue;
            }

            $childColumn = wallos_migrate_quote($key['child_column']);
            $parentColumn = wallos_migrate_quote($key['parent_column']);

            // rowid is the identity used throughout, because four Wallos tables
            // have no primary key at all and SQLite gives every one of them a
            // rowid regardless.
            $sql = 'SELECT c.rowid AS orphan_rowid FROM ' . wallos_migrate_quote($child) . ' c'
                . ' WHERE c.' . $childColumn . ' IS NOT NULL'
                . ' AND NOT EXISTS (SELECT 1 FROM ' . wallos_migrate_quote($parent) . ' p'
                . ' WHERE p.' . $parentColumn . ' = c.' . $childColumn;

            if (isset($skipped[$parent])) {
                $sql .= ' AND p.rowid NOT IN (' . implode(',', array_map('intval', array_keys($skipped[$parent]))) . ')';
            }

            $sql .= ')';

            if (isset($skipped[$child])) {
                $sql .= ' AND c.rowid NOT IN (' . implode(',', array_map('intval', array_keys($skipped[$child]))) . ')';
            }

            $result = $source->query($sql);

            while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
                $skipped[$child][(int) $row['orphan_rowid']] = true;
                $added++;

                if (!isset($constraints[$key['name']])) {
                    $constraints[$key['name']] = [
                        'child' => $child . '.' . $key['child_column'],
                        'parent' => $parent . '.' . $key['parent_column'],
                        'rows' => 0,
                        'examples' => [],
                    ];
                }

                $constraints[$key['name']]['rows']++;
                if (count($constraints[$key['name']]['examples']) < 5) {
                    $constraints[$key['name']]['examples'][] = (int) $row['orphan_rowid'];
                }
            }
        }
    } while ($added > 0);

    return ['skipped' => $skipped, 'constraints' => $constraints];
}

/**
 * Target columns PostgreSQL treats as a real boolean.
 *
 * The baseline declares them INTEGER on purpose: Wallos writes 0 and 1 and
 * compares with `== 1` in several hundred places, and a BOOLEAN column hands
 * PHP true and false back, which changes the meaning of every one of them at
 * once. This checks rather than assumes, because it is the difference between
 * a migration that works and one where every notification channel reads as off.
 *
 * @param array $targetColumns
 * @return string[] "table.column"
 */
function wallos_migrate_boolean_columns($targetColumns)
{
    $found = [];

    foreach ($targetColumns as $table => $columns) {
        foreach ($columns as $column => $definition) {
            if ($definition['type'] === 'boolean') {
                $found[] = $table . '.' . $column;
            }
        }
    }

    return $found;
}

/**
 * Source values that will not fit the type the target declares.
 *
 * SQLite's INTEGER is an affinity, not a constraint: a value that is not a
 * number is stored as text and nothing complains. Three columns in Wallos are
 * declared INTEGER and have never held integers — subscriptions.start_date,
 * total_yearly_cost.date and user.budget — which is why the baseline overrides
 * their types. This finds the next one before the copy does, and reports it as
 * data rather than as a PostgreSQL error four hundred rows into a table.
 *
 * @param WallosDatabase $source
 * @param array          $targetColumns
 * @param array          $sourcePlan     table => column names being copied
 * @return array<int, array{column: string, type: string, rows: int, examples: string[]}>
 */
function wallos_migrate_type_mismatches($source, $targetColumns, $sourcePlan)
{
    $numeric = ['smallint', 'integer', 'bigint'];
    $fractional = ['real', 'double precision', 'numeric'];
    $mismatches = [];

    foreach ($sourcePlan as $table => $columns) {
        foreach ($columns as $column) {
            $type = $targetColumns[$table][$column]['type'] ?? null;

            if ($type === null || (!in_array($type, $numeric, true) && !in_array($type, $fractional, true))) {
                continue;
            }

            // GLOB rather than a cast: CAST('2026-01-01' AS INTEGER) is 2026 in
            // SQLite, so a cast would call the value acceptable and PostgreSQL
            // would then reject it.
            $allowed = in_array($type, $numeric, true) ? '*[^0-9+-]*' : '*[^0-9eE.+-]*';

            $quoted = wallos_migrate_quote($column);
            $sql = 'SELECT ' . $quoted . ' AS value FROM ' . wallos_migrate_quote($table)
                . ' WHERE ' . $quoted . ' IS NOT NULL'
                . " AND typeof(" . $quoted . ") NOT IN ('integer', 'real')"
                . " AND (CAST(" . $quoted . " AS TEXT) = '' OR CAST(" . $quoted . " AS TEXT) GLOB '" . $allowed . "')";

            $result = $source->query($sql);
            $rows = 0;
            $examples = [];

            while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows++;
                if (count($examples) < 3) {
                    $examples[] = var_export($row['value'], true);
                }
            }

            if ($rows > 0) {
                $mismatches[] = [
                    'column' => $table . '.' . $column,
                    'type' => $type,
                    'rows' => $rows,
                    'examples' => $examples,
                ];
            }
        }
    }

    return $mismatches;
}

/**
 * The migrations each side records as applied.
 *
 * A source that is behind the baseline has fewer columns than the target
 * expects, and the copy would succeed while quietly leaving them at their
 * defaults. A source that is ahead has columns the target does not have yet.
 * Both are worth refusing, and neither is visible in a row count.
 *
 * @param WallosDatabase $source
 * @param WallosDatabase $target
 * @return array{source: string[], target: string[], missing: string[], extra: string[]}
 */
function wallos_migrate_migration_drift($source, $target)
{
    $read = function ($db) {
        $applied = [];
        $result = $db->query('SELECT migration FROM ' . wallos_migrate_quote('migrations'));

        while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
            $applied[] = (string) $row['migration'];
        }

        sort($applied);

        return $applied;
    };

    $sourceApplied = $read($source);
    $targetApplied = $read($target);

    return [
        'source' => $sourceApplied,
        'target' => $targetApplied,
        'missing' => array_values(array_diff($targetApplied, $sourceApplied)),
        'extra' => array_values(array_diff($sourceApplied, $targetApplied)),
    ];
}

/**
 * What the target already holds, measured against a fresh installation.
 *
 * @param WallosDatabase $target
 * @param string[]       $tables
 * @return array{counts: array<string, int>, excess: array<string, int>, total: int}
 */
function wallos_migrate_target_content($target, $tables)
{
    $seeded = wallos_migrate_baseline_seed_counts();
    $counts = [];
    $excess = [];
    $total = 0;

    foreach ($tables as $table) {
        $count = (int) $target->scalar('SELECT COUNT(*) FROM ' . wallos_migrate_quote($table));
        $counts[$table] = $count;
        $total += $count;

        $beyond = $count - ($seeded[$table] ?? 0);
        if ($beyond > 0) {
            $excess[$table] = $beyond;
        }
    }

    return ['counts' => $counts, 'excess' => $excess, 'total' => $total];
}

// ---------------------------------------------------------------------- copy

/**
 * Copies one table.
 *
 * Rows go in one at a time through a single prepared statement rather than in
 * multi-row batches. A Wallos installation is thousands of rows, not millions,
 * so the throughput difference is a second; what the per-row form buys is a
 * failure that names the row, which is the difference between "invalid byte
 * sequence for encoding UTF8" and "subscriptions row 412, column notes".
 *
 * @param WallosDatabase        $source
 * @param WallosDatabase        $target
 * @param string                $table
 * @param string[]              $columns
 * @param array<int, bool>|null $skip    rowids to leave behind
 * @return array{copied: int, skipped: int, error: string|null}
 */
function wallos_migrate_copy_table($source, $target, $table, $columns, $skip = null)
{
    // An empty skip set is no skip set. Keeping the two spellings apart would
    // mean the SELECT below asks for a rowid the loop then never reads, and the
    // loop reading a column the SELECT never asked for.
    if ($skip === []) {
        $skip = null;
    }

    $quotedTable = wallos_migrate_quote($table);
    $quotedColumns = array_map('wallos_migrate_quote', $columns);
    $placeholders = [];

    foreach ($columns as $index => $column) {
        $placeholders[] = ':c' . $index;
    }

    $insert = $target->prepare('INSERT INTO ' . $quotedTable . ' (' . implode(', ', $quotedColumns)
        . ') VALUES (' . implode(', ', $placeholders) . ')');

    if ($insert === false) {
        return ['copied' => 0, 'skipped' => 0, 'error' => $table . ': ' . $target->lastErrorMsg()];
    }

    // rowid is only asked for when it is going to be used; a table declared
    // WITHOUT ROWID has none, and nothing in Wallos should need one otherwise.
    $select = 'SELECT ' . ($skip === null ? '' : 'rowid AS wallos_source_rowid, ')
        . implode(', ', $quotedColumns) . ' FROM ' . $quotedTable;

    $result = $source->query($select);

    if ($result === false) {
        return ['copied' => 0, 'skipped' => 0, 'error' => $table . ': ' . $source->lastErrorMsg()];
    }

    $copied = 0;
    $skipped = 0;
    $read = 0;

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $read++;

        if ($skip !== null && isset($skip[(int) $row['wallos_source_rowid']])) {
            $skipped++;
            continue;
        }

        foreach ($columns as $index => $column) {
            $value = $row[$column] ?? null;
            $insert->bindValue(':c' . $index, $value, wallos_migrate_bind_type($value));
        }

        if ($insert->execute() === false) {
            return [
                'copied' => $copied,
                'skipped' => $skipped,
                'error' => sprintf('%s row %d: %s%s', $table, $read, $target->lastErrorMsg(),
                    wallos_migrate_encoding_hint($row, $columns)),
            ];
        }

        $copied++;
    }

    return ['copied' => $copied, 'skipped' => $skipped, 'error' => null];
}

/**
 * The SQLITE3_* type hint for a value read out of SQLite.
 *
 * The boundary's PostgreSQL statement uses the hint to decide how to bind, and
 * passing the wrong one is how an integer ends up quoted into a numeric column.
 * Floats are passed through as floats rather than formatted here: PHP's default
 * precision of fourteen significant digits is more than any Wallos price, rate
 * or budget carries, and the exact alternative writes 163.77000000000001 into
 * the TEXT-typed columns that hold exchange rates.
 *
 * @param mixed $value
 * @return int|null
 */
function wallos_migrate_bind_type($value)
{
    if (is_int($value)) {
        return SQLITE3_INTEGER;
    }
    if (is_float($value)) {
        return SQLITE3_FLOAT;
    }
    if ($value === null) {
        return SQLITE3_NULL;
    }

    return SQLITE3_TEXT;
}

/**
 * Names the column that is not valid UTF-8, when that is why a row was refused.
 *
 * SQLite stores whatever bytes it was given and PostgreSQL will not, so a
 * database that once ingested a Latin-1 subscription name fails here with a
 * message about an encoding and no hint which of twenty-four columns it means.
 *
 * @param array    $row
 * @param string[] $columns
 * @return string
 */
function wallos_migrate_encoding_hint($row, $columns)
{
    $bad = [];

    foreach ($columns as $column) {
        $value = $row[$column] ?? null;
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            $bad[] = $column;
        }
    }

    return $bad === [] ? '' : ' (not valid UTF-8: ' . implode(', ', $bad) . ')';
}

/**
 * Puts every sequence past the highest id that was just copied.
 *
 * This is the failure the whole issue is about. Rows copied with explicit ids
 * do not advance a sequence, so a migration that stops here works perfectly
 * until the first new subscription and then fails with a duplicate key error
 * naming a constraint, days after the import nobody connects it to.
 *
 * setval(sequence, max + 1, false) rather than setval(sequence, max): the
 * second form is rejected when the table is empty, because a sequence may not
 * be set below its minimum of 1, and an empty table is exactly the case where
 * getting it wrong is silent.
 *
 * @param WallosDatabase $target
 * @param array          $sequences
 * @return array<int, array{sequence: string, column: string, max: int, next: int, ok: bool}>
 */
function wallos_migrate_fix_sequences($target, $sequences)
{
    $report = [];

    foreach ($sequences as $sequence) {
        $column = wallos_migrate_quote($sequence['column']);
        $table = wallos_migrate_quote($sequence['table']);

        $max = (int) $target->scalar('SELECT COALESCE(MAX(' . $column . '), 0) FROM ' . $table);

        // The sequence name comes back from pg_get_serial_sequence already
        // schema-qualified and quoted, so it is passed as a literal to setval
        // and interpolated into the verification query as-is.
        $target->scalar('SELECT setval(:sequence, :next, false)', [
            ':sequence' => $sequence['sequence'],
            ':next' => $max + 1,
        ]);

        $state = $target->query('SELECT last_value, is_called FROM ' . $sequence['sequence']);
        $row = $state === false ? false : $state->fetchArray(SQLITE3_ASSOC);

        $next = $row === false ? 0 : (int) $row['last_value'];
        $called = $row !== false && ($row['is_called'] === true || $row['is_called'] === 't' || $row['is_called'] === 1);

        $report[] = [
            'sequence' => $sequence['sequence'],
            'column' => $sequence['table'] . '.' . $sequence['column'],
            'max' => $max,
            // What the next insert will actually receive.
            'next' => $called ? $next + 1 : $next,
            'ok' => !$called && $next === $max + 1,
        ];
    }

    return $report;
}

// ----------------------------------------------------------------------- run

/**
 * Performs the migration, printing a report as it goes.
 *
 * Split from the command line so the test suite can drive it against a throwaway
 * schema; the arguments are what the CLI resolves rather than what it was given.
 *
 * @param string         $sourcePath
 * @param WallosDatabase $target
 * @param array          $options
 * @return array{ok: bool, error: string|null, tables: array, sequences: array, orphans: array}
 */
function wallos_migrate_run($sourcePath, $target, $options)
{
    $result = [
        'ok' => false,
        'error' => null,
        'dryRun' => (bool) $options['dry-run'],
        'tables' => [],
        'sequences' => [],
        'orphans' => [],
        'warnings' => [],
    ];

    $fail = function ($message) use (&$result) {
        $result['error'] = $message;
        echo "\nRefused: " . $message . "\n";

        return $result;
    };

    if (!is_file($sourcePath) || !is_readable($sourcePath)) {
        return $fail('The source database ' . $sourcePath . ' does not exist or cannot be read.');
    }

    if ($target->driver() !== 'pgsql') {
        return $fail('The target is ' . $target->driver() . ', not pgsql. Set WALLOS_DB_DRIVER=pgsql.');
    }

    $before = wallos_migrate_source_stat($sourcePath);
    $source = wallos_migrate_open_source($sourcePath);

    echo "Wallos SQLite -> PostgreSQL migration\n\n";
    printf("source   %s (%s)\n", $sourcePath, wallos_migrate_bytes($before['size']));
    printf("target   %s, schema %s, %s\n",
        (string) $target->scalar('SELECT current_database()'),
        (string) $target->scalar('SELECT current_schema()'),
        (string) $target->scalar('SELECT version()'));
    printf("mode     %s\n\n", $options['dry-run'] ? 'dry run, nothing is written' : 'copy');

    // ------------------------------------------------------------ the schema

    if (!$target->tableExists('migrations')) {
        if ($options['dry-run']) {
            echo "The target has no schema yet. A real run applies\n"
                . "includes/database/pgsql/schema.sql first, then copies into it.\n";
            $result['ok'] = true;
            $source->close();

            return $result;
        }

        echo "The target has no schema. Applying the baseline...\n";
        $applied = wallos_pgsql_apply_baseline($target);

        if (!$applied['applied']) {
            $source->close();

            return $fail($applied['error']);
        }

        echo "Baseline applied.\n\n";
    }

    $sourceTables = wallos_migrate_source_tables($source);
    $targetTables = wallos_migrate_target_tables($target);
    $targetColumns = wallos_migrate_target_columns($target);
    $foreignKeys = wallos_migrate_target_foreign_keys($target);
    $sequences = wallos_migrate_target_sequences($target);

    $owned = wallos_migrate_target_owned_tables();
    $copyTables = array_values(array_diff(array_intersect($sourceTables, $targetTables), $owned));

    echo "Schema\n";
    printf("  %-28s source %d, target %d\n", 'tables', count($sourceTables), count($targetTables));

    $onlySource = array_values(array_diff($sourceTables, $targetTables));
    $onlyTarget = array_values(array_diff($targetTables, $sourceTables));

    if ($onlySource !== []) {
        printf("  %-28s %s\n", 'only in the source', implode(', ', $onlySource));
        $result['warnings'][] = 'tables only in the source: ' . implode(', ', $onlySource);
    }
    if ($onlyTarget !== []) {
        printf("  %-28s %s\n", 'only in the target', implode(', ', $onlyTarget));
        $result['warnings'][] = 'tables only in the target: ' . implode(', ', $onlyTarget);
    }

    // --------------------------------------------------------- schema drift

    $drift = wallos_migrate_migration_drift($source, $target);
    printf("  %-28s source %d, target %d\n", 'migrations applied',
        count($drift['source']), count($drift['target']));

    if ($drift['missing'] !== []) {
        $source->close();

        return $fail('The source is behind the target schema; it has not applied '
            . implode(', ', $drift['missing']) . '. Start the SQLite instance once so the '
            . 'migration chain finishes, then migrate.');
    }
    if ($drift['extra'] !== []) {
        $source->close();

        return $fail('The source has applied migrations the PostgreSQL baseline does not record: '
            . implode(', ', $drift['extra']) . '. Regenerate includes/database/pgsql/schema.sql.');
    }

    // --------------------------------------------------------- column drift

    $plan = [];
    $columnWarnings = [];

    foreach ($copyTables as $table) {
        $sourceColumns = wallos_migrate_source_columns($source, $table);
        $available = array_keys($targetColumns[$table] ?? []);
        $plan[$table] = array_values(array_intersect($sourceColumns, $available));

        foreach (array_diff($sourceColumns, $available) as $column) {
            $columnWarnings[] = $table . '.' . $column . ' exists only in the source and is not copied';
        }
        foreach (array_diff($available, $sourceColumns) as $column) {
            $definition = $targetColumns[$table][$column];
            $columnWarnings[] = $table . '.' . $column . ' exists only in the target and stays at its default'
                . (!$definition['nullable'] && $definition['default'] === null ? ' (NOT NULL, the copy will fail)' : '');
        }
    }

    printf("  %-28s %s\n", 'columns', $columnWarnings === [] ? 'identical on both sides' : count($columnWarnings) . ' difference(s)');
    foreach ($columnWarnings as $warning) {
        printf("    %s\n", $warning);
        $result['warnings'][] = $warning;
    }

    // ------------------------------------------------------------- booleans

    $booleans = wallos_migrate_boolean_columns($targetColumns);
    printf("  %-28s %s\n", 'boolean columns in target',
        $booleans === [] ? 'none, they are INTEGER as the baseline intends' : implode(', ', $booleans));

    if ($booleans !== []) {
        $source->close();

        return $fail('The target declares ' . count($booleans) . ' column(s) as BOOLEAN: '
            . implode(', ', $booleans) . '. Wallos writes 0 and 1 into them and compares with == 1, '
            . 'so a real boolean changes the meaning of every one of those comparisons.');
    }

    // ---------------------------------------------------------- value types

    $mismatches = wallos_migrate_type_mismatches($source, $targetColumns, $plan);
    printf("  %-28s %s\n", 'values vs declared types',
        $mismatches === [] ? 'every value fits the column it is going into' : count($mismatches) . ' column(s) do not fit');

    foreach ($mismatches as $mismatch) {
        printf("    %s is %s in PostgreSQL, %d source row(s) hold e.g. %s\n",
            $mismatch['column'], $mismatch['type'], $mismatch['rows'], implode(', ', $mismatch['examples']));
    }

    if ($mismatches !== []) {
        $source->close();

        return $fail('The source holds values PostgreSQL cannot store in the declared column type. '
            . 'Either the data is wrong or includes/database/pgsql/schema.sql needs a type override; '
            . 'see wallos_pgsql_schema_type_overrides() in dev/generate-pgsql-schema.php.');
    }

    // --------------------------------------------------------- foreign keys

    echo "\nForeign keys\n";
    printf("  %-28s %d, none of which SQLite has ever enforced\n", 'declared by the target', count($foreignKeys));

    foreach ($foreignKeys as $key) {
        if ($key['columns'] > 1) {
            $result['warnings'][] = $key['name'] . ' spans ' . $key['columns'] . ' columns; only the first is checked here';
        }
    }

    $orphans = wallos_migrate_orphans($source, $foreignKeys, $sourceTables);
    $result['orphans'] = $orphans['constraints'];
    $orphanRows = 0;
    foreach ($orphans['skipped'] as $rowids) {
        $orphanRows += count($rowids);
    }

    if ($orphanRows === 0) {
        printf("  %-28s none\n", 'violations in the source');
    } else {
        printf("  %-28s %d row(s) across %d constraint(s)\n", 'violations in the source',
            $orphanRows, count($orphans['constraints']));

        foreach ($orphans['constraints'] as $name => $violation) {
            printf("    %-40s %5d row(s), %s has no %s (rowid %s%s)\n",
                $name, $violation['rows'], $violation['child'], $violation['parent'],
                implode(', ', $violation['examples']),
                $violation['rows'] > count($violation['examples']) ? ', ...' : '');
        }

        if (!$options['skip-orphans']) {
            $source->close();

            return $fail('The source holds ' . $orphanRows . ' row(s) that PostgreSQL will reject. '
                . 'Delete or repair them in SQLite, or re-run with --skip-orphans to leave them '
                . 'behind and have them counted in the verification below. They are never dropped silently.');
        }

        echo "  --skip-orphans: those rows are left behind and counted below.\n";
    }

    // ----------------------------------------------------- target emptiness

    $content = wallos_migrate_target_content($target, $targetTables);

    echo "\nTarget contents\n";

    if ($content['excess'] === []) {
        printf("  %-28s %d row(s), all of them baseline reference data\n", 'currently holds', $content['total']);
    } else {
        printf("  %-28s %d row(s), %d beyond a fresh installation\n", 'currently holds',
            $content['total'], array_sum($content['excess']));

        foreach ($content['excess'] as $table => $beyond) {
            printf("    %-30s %d row(s) beyond the baseline\n", $table, $beyond);
        }

        if (!$options['allow-non-empty']) {
            $source->close();

            return $fail('The target already holds data. Re-run with --allow-non-empty to delete it '
                . 'and replace it with the source, or point at an empty database.');
        }

        echo "  --allow-non-empty: those rows are deleted before the copy.\n";
    }

    // ----------------------------------------------------------------- plan

    $ordering = wallos_migrate_table_order($copyTables, $foreignKeys);

    if ($ordering['cycle']) {
        $result['warnings'][] = 'the foreign keys contain a cycle; the copy order cannot satisfy all of them';
        echo "\n  The foreign keys contain a cycle. The copy order below cannot satisfy\n"
            . "  all of them and the transaction will fail; the schema needs a deferrable\n"
            . "  constraint before this can work.\n";
    }

    echo "\nPlan\n";
    printf("  %-30s %8s %8s %8s\n", 'table', 'source', 'skipped', 'target');

    $totalRows = 0;

    foreach ($ordering['order'] as $table) {
        $rows = (int) $source->scalar('SELECT COUNT(*) FROM ' . wallos_migrate_quote($table));
        $skipped = isset($orphans['skipped'][$table]) ? count($orphans['skipped'][$table]) : 0;
        $totalRows += $rows - $skipped;

        $result['tables'][$table] = [
            'source' => $rows,
            'skipped' => $skipped,
            'copied' => 0,
            'target' => $content['counts'][$table] ?? 0,
            'status' => 'planned',
        ];

        printf("  %-30s %8d %8d %8d\n", $table, $rows, $skipped, $content['counts'][$table] ?? 0);
    }

    foreach ($owned as $table) {
        printf("  %-30s %8s %8s %8d  kept: the target owns this table\n",
            $table, '-', '-', $content['counts'][$table] ?? 0);
    }

    printf("\n  %d row(s) would be copied into %d table(s).\n", $totalRows, count($ordering['order']));
    printf("  %d sequence(s) would be set past the highest copied id.\n", count($sequences));

    if ($options['dry-run']) {
        echo "\nDry run: nothing was written. The source and the target are unchanged.\n";
        $result['ok'] = true;
        $source->close();

        return $result;
    }

    // ----------------------------------------------------------- the copy

    echo "\nCopying, in one transaction\n";

    if ($target->beginTransaction() === false) {
        $source->close();

        return $fail('Could not start a transaction: ' . $target->lastErrorMsg());
    }

    // Children first, so a delete never trips a foreign key on the way out.
    //
    // The number cleared comes from the counts read a moment ago rather than
    // from changes(), so that this line and the "currently holds" line above it
    // are the same number arrived at once. Two separately derived counts of the
    // same rows is two numbers that can disagree, in a report whose whole job is
    // to be believable.
    $removed = 0;
    foreach (array_reverse($ordering['order']) as $table) {
        if ($target->exec('DELETE FROM ' . wallos_migrate_quote($table)) === false) {
            $target->rollBack();
            $source->close();

            return $fail('Clearing ' . $table . ' failed: ' . $target->lastErrorMsg());
        }
        $removed += $content['counts'][$table] ?? 0;
    }

    printf("  cleared %d existing row(s) from %d table(s)\n", $removed, count($ordering['order']));

    foreach ($ordering['order'] as $table) {
        if ($plan[$table] === []) {
            continue;
        }

        $copy = wallos_migrate_copy_table($source, $target, $table, $plan[$table],
            $orphans['skipped'][$table] ?? null);

        if ($copy['error'] !== null) {
            $target->rollBack();
            $source->close();

            return $fail('Copying failed and nothing was written: ' . $copy['error']);
        }

        $result['tables'][$table]['copied'] = $copy['copied'];
        printf("  %-30s %8d row(s)%s\n", $table, $copy['copied'],
            $copy['skipped'] > 0 ? sprintf(', %d left behind', $copy['skipped']) : '');
    }

    // ------------------------------------------------------------ sequences

    // Inside the transaction so the order is right, but note that PostgreSQL
    // does not roll sequence changes back. A rollback therefore leaves the
    // sequences high, which costs a few unused ids; leaving them low is the
    // duplicate-key failure this whole script exists to prevent.
    $result['sequences'] = wallos_migrate_fix_sequences($target, $sequences);

    echo "\nSequences\n";
    $sequencesOk = true;

    foreach ($result['sequences'] as $sequence) {
        printf("  %-30s highest id %8d, next id %8d  %s\n",
            $sequence['column'], $sequence['max'], $sequence['next'], $sequence['ok'] ? 'ok' : 'NOT SET');

        if (!$sequence['ok']) {
            $sequencesOk = false;
        }
    }

    printf("  %d of %d sequence(s) now start past the highest copied id.\n",
        count(array_filter($result['sequences'], fn($s) => $s['ok'])), count($result['sequences']));

    if (!$sequencesOk) {
        $target->rollBack();
        $source->close();

        return $fail('A sequence could not be set. Committing would leave the next insert '
            . 'colliding with a copied row, which is the failure this migration exists to avoid.');
    }

    // ---------------------------------------------------------- verification

    $verified = wallos_migrate_verify($target, $result['tables']);

    if (!$verified['ok']) {
        $target->rollBack();
        $source->close();
        wallos_migrate_print_verification($result['tables'], $owned, $content['counts']);

        return $fail('The row counts do not match and nothing was written: '
            . implode('; ', $verified['problems']));
    }

    if ($target->commit() === false) {
        $target->rollBack();
        $source->close();

        return $fail('The transaction could not be committed: ' . $target->lastErrorMsg());
    }

    // Re-counted after the commit, so what is reported is what is stored rather
    // than what the transaction believed a moment before it ended.
    wallos_migrate_verify($target, $result['tables']);

    echo "\nVerification, per table, after the commit\n";
    wallos_migrate_print_verification($result['tables'], $owned, $content['counts']);

    // ------------------------------------------------------ the source again

    $after = wallos_migrate_source_stat($sourcePath);
    $untouched = $before === $after;

    printf("\nSource   %s (%s, %s)\n", $untouched ? 'unchanged' : 'CHANGED',
        wallos_migrate_bytes($after['size']), date('c', $after['mtime']));

    if (!$untouched) {
        // The handle was opened SQLITE3_OPEN_READONLY, so this should be
        // impossible; if it ever happens the operator needs to know before they
        // decide the SQLite file is still a usable fallback.
        $result['warnings'][] = 'the source file changed during the migration';
    }

    $source->close();

    $result['ok'] = true;
    echo "\nDone. The data is in PostgreSQL and every sequence is past the ids it copied.\n";

    return $result;
}

/**
 * Compares the copied row counts with what the target actually holds.
 *
 * A count that matches is not proof the data is right — dev/stress-verify.php
 * hashes the content for that — but a count that does not match is proof it is
 * wrong, and it is the one check that costs nothing.
 *
 * @param WallosDatabase $target
 * @param array          $tables passed by reference; the observed counts go back in
 * @return array{ok: bool, problems: string[]}
 */
function wallos_migrate_verify($target, &$tables)
{
    $problems = [];

    foreach ($tables as $table => $counts) {
        $actual = (int) $target->scalar('SELECT COUNT(*) FROM ' . wallos_migrate_quote($table));
        $expected = $counts['source'] - $counts['skipped'];

        $tables[$table]['target'] = $actual;
        $tables[$table]['status'] = $actual === $expected
            ? ($counts['skipped'] > 0 ? 'ok, ' . $counts['skipped'] . ' left behind' : 'ok')
            : 'MISMATCH';

        if ($actual !== $expected) {
            $problems[] = sprintf('%s expected %d, found %d', $table, $expected, $actual);
        }
    }

    return ['ok' => $problems === [], 'problems' => $problems];
}

/**
 * @param array    $tables
 * @param string[] $owned
 * @param array    $targetCounts counts taken before the copy
 * @return void
 */
function wallos_migrate_print_verification($tables, $owned, $targetCounts)
{
    printf("  %-30s %8s %8s %8s  %s\n", 'table', 'source', 'skipped', 'target', 'status');

    foreach ($tables as $table => $counts) {
        printf("  %-30s %8d %8d %8d  %s\n", $table, $counts['source'], $counts['skipped'],
            $counts['target'], $counts['status']);
    }

    foreach ($owned as $table) {
        printf("  %-30s %8s %8s %8d  not copied, the target owns it\n",
            $table, '-', '-', $targetCounts[$table] ?? 0);
    }
}

/**
 * @param int $bytes
 * @return string
 */
function wallos_migrate_bytes($bytes)
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024) . ' KB';
    }

    return round($bytes / (1024 * 1024), 1) . ' MB';
}

// Only when invoked directly: tests/cases/migrate_pgsql_test.php includes this
// file for the functions above.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $parsed = wallos_migrate_parse_options(array_slice($argv, 1));

    if ($parsed['error'] !== null) {
        fwrite(STDERR, $parsed['error'] . "\n\n" . wallos_migrate_usage());
        exit(2);
    }

    if ($parsed['options']['help']) {
        echo wallos_migrate_usage();
        exit(0);
    }

    require_once __DIR__ . '/../includes/database/configuration.php';
    $configuration = wallos_database_configuration();

    if ($configuration['error'] !== null) {
        fwrite(STDERR, $configuration['error'] . "\n");
        exit(2);
    }

    if ($configuration['driver'] !== 'pgsql') {
        fwrite(STDERR, "The environment does not select PostgreSQL, so there is nothing to migrate into.\n"
            . "Set WALLOS_DB_DRIVER=pgsql and the WALLOS_DB_* variables for the target.\n\n"
            . wallos_migrate_usage());
        exit(2);
    }

    // The source is a file, and naming a file is what selects SQLite at the
    // boundary — which is the only way to open it while the environment points
    // the same function at PostgreSQL.
    $sourcePath = $parsed['options']['source'] ?? wallos_database_path();

    $targetDatabase = wallos_database_connect();

    if ($parsed['options']['schema'] !== null) {
        $targetDatabase->exec('SET search_path TO ' . wallos_migrate_quote($parsed['options']['schema']));
    }

    $outcome = wallos_migrate_run($sourcePath, $targetDatabase, $parsed['options']);
    $targetDatabase->close();

    exit($outcome['ok'] ? 0 : 1);
}
