<?php
/*
  Generates the PostgreSQL baseline schema from a fully migrated SQLite
  database.

      podman exec wallos-dev php /var/www/html/dev/generate-pgsql-schema.php
      podman exec wallos-dev php /var/www/html/dev/generate-pgsql-schema.php --check
      podman exec wallos-dev php /var/www/html/dev/generate-pgsql-schema.php --stdout

  Issue #21 asks for a current-schema baseline for fresh PostgreSQL
  installations rather than a port of the sixty-odd historical SQLite
  migrations. The baseline therefore has to be derived from the one place that
  knows the current schema — a SQLite database that has run createdatabase.php
  and the whole migration chain — instead of being written out by hand and
  drifting the first time someone adds a migration.

  tests/cases/pgsql_schema_test.php runs this generator against the current
  migration chain and fails when the checked-in file disagrees with it.

  Arguments:
      --check         compare with the checked-in file, exit 1 on a difference
      --stdout        write to standard output instead of the file
      --database P    read the schema from P instead of building a fresh one
      --output P      write to P instead of includes/database/pgsql/schema.sql
*/

require_once __DIR__ . '/../includes/database/connection.php';

/** Where the generated baseline lives. */
function wallos_pgsql_schema_path()
{
    return dirname(__DIR__) . '/includes/database/pgsql/schema.sql';
}

/**
 * How a SQLite declared type is spelled in PostgreSQL.
 *
 * BOOLEAN becomes INTEGER on purpose, and it is the single decision this whole
 * file turns on. Wallos writes 0 and 1 into those columns and compares them
 * with `$row['enabled'] == 1` in several hundred places. A real BOOLEAN column
 * hands PHP true and false back, and every one of those comparisons changes
 * meaning at once.
 *
 * DATE stays TEXT for the same reason: Wallos stores '2026-01-01' strings and
 * compares them as strings. A DATE column would return a different value and
 * compare by different rules in queries nobody is rewriting.
 *
 * DATETIME and TIMESTAMP do become TIMESTAMP, because every one of them exists
 * to carry DEFAULT CURRENT_TIMESTAMP and is only ever read back for display.
 */
function wallos_pgsql_schema_types()
{
    return [
        'INTEGER' => 'INTEGER',
        'INT' => 'INTEGER',
        'BOOLEAN' => 'INTEGER',
        'REAL' => 'DOUBLE PRECISION',
        'TEXT' => 'TEXT',
        'VARCHAR(255)' => 'VARCHAR(255)',
        'DATE' => 'TEXT',
        'DATETIME' => 'TIMESTAMP',
        'TIMESTAMP' => 'TIMESTAMP',
    ];
}

/**
 * Column defaults that record when a migration ran rather than what the schema
 * is.
 *
 * migrations/000053.php builds its default out of the current date, so a
 * verbatim copy of the SQLite schema would change every midnight and the drift
 * test would fail for a reason that has nothing to do with the schema.
 * CURRENT_DATE is also what the application itself falls back to when the value
 * is missing — see getDefaultBudgetAnchorDate() — so the meaning is unchanged.
 *
 * @return array table.column => PostgreSQL default expression
 */
function wallos_pgsql_schema_default_overrides()
{
    return [
        // CURRENT_DATE renders through the session DateStyle, and this column is
        // TEXT: with DateStyle=SQL,DMY a new row gets '18/08/2026', which
        // sanitizeBudgetAnchorDate() then rejects and silently replaces with
        // today. to_char pins the format the application actually parses.
        'user.budget_period_anchor_date' => "to_char(CURRENT_DATE, 'YYYY-MM-DD')",
    ];
}

/**
 * Columns whose declared SQLite type is not the type they hold.
 *
 * SQLite's INTEGER is an affinity, not a constraint: a value that is not a
 * well-formed integer is stored as text and nothing complains. Three columns
 * declared INTEGER have never held integers, and PostgreSQL — where the
 * declared type is the type — rejects every write to them.
 *
 * Each entry here is a place where trusting the declaration would produce a
 * schema that is faithful to the SQLite DDL and wrong about the data.
 *
 * @return array<string, string>
 */
function wallos_pgsql_schema_type_overrides()
{
    return [
        // An <input type="date">, bound as TEXT, read with strtotime().
        // Declared INTEGER by migrations/000032.php.
        'subscriptions.start_date' => 'TEXT',
        // storetotalyearlycost.php writes format('Y-m-d') and binds it as TEXT.
        'total_yearly_cost.date' => 'TEXT',
        // Money. set_budget.php casts to float and binds SQLITE3_FLOAT, so a
        // budget with cents is not representable as INTEGER. The column beside
        // it, period_budget, is already REAL.
        'user.budget' => 'DOUBLE PRECISION',
    ];
}

/**
 * Foreign keys the SQLite schema declares that must not be carried over.
 *
 * @return string[] "table.column"
 */
function wallos_pgsql_schema_suppressed_foreign_keys()
{
    return [
        // subscriptions.frequency is a multiplier, not a reference:
        // getPricePerMonth() divides by it, and getdbkeys.php builds the list
        // in PHP as 1..366 while the frequencies table holds 1..31. No code
        // reads that table. Carrying the key over would reject "every 60 days",
        // which the stock form offers, for every user.
        'subscriptions.frequency',
    ];
}

/**
 * An identifier, quoted.
 *
 * Everything is quoted, not just the words PostgreSQL reserves today. Two
 * columns are already named `order` and one table is named `user`, both of
 * which PostgreSQL refuses unquoted; quoting selectively would mean carrying a
 * copy of the PostgreSQL keyword list in here and finding out it was
 * incomplete the day someone adds a column named `check`. Every Wallos
 * identifier is lower case, so quoting changes nothing else.
 *
 * @param string $name
 * @return string
 */
function wallos_pgsql_quote_identifier($name)
{
    return '"' . str_replace('"', '""', $name) . '"';
}

/**
 * A value, as a PostgreSQL literal.
 *
 * @param mixed $value
 * @return string
 */
function wallos_pgsql_quote_value($value)
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        // Enough digits to round-trip a double; PHP's default precision drops
        // some, and a seeded price that changes on regeneration is drift.
        return rtrim(rtrim(sprintf('%.17G', $value), '0'), '.');
    }

    return "'" . str_replace("'", "''", (string) $value) . "'";
}

/**
 * Reads the schema of a fully migrated SQLite database.
 *
 * PRAGMA rather than the CREATE TABLE text in sqlite_master: the stored text is
 * whatever the migration that last touched the table happened to write, down to
 * a missing comma between two foreign keys in `subscriptions` that SQLite
 * accepts and no other parser would.
 *
 * @param WallosDatabase $db
 * @return array
 */
function wallos_pgsql_schema_read($db)
{
    $tables = [];

    $result = $db->query(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name"
    );
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $tables[] = $row['name'];
    }

    $schema = [];

    foreach ($tables as $table) {
        $quoted = wallos_pgsql_quote_identifier($table);

        $columns = [];
        $primaryKey = [];
        $result = $db->query('PRAGMA table_info(' . $quoted . ')');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = [
                'name' => $row['name'],
                'type' => strtoupper(trim((string) $row['type'])),
                'notnull' => (int) $row['notnull'] === 1,
                'default' => $row['dflt_value'],
            ];
            if ((int) $row['pk'] > 0) {
                $primaryKey[(int) $row['pk']] = $row['name'];
            }
        }
        ksort($primaryKey);

        $foreignKeys = [];
        $result = $db->query('PRAGMA foreign_key_list(' . $quoted . ')');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $id = (int) $row['id'];
            if (!isset($foreignKeys[$id])) {
                $foreignKeys[$id] = [
                    'table' => $row['table'],
                    'columns' => [],
                    'references' => [],
                    'on_delete' => strtoupper((string) $row['on_delete']),
                    'on_update' => strtoupper((string) $row['on_update']),
                ];
            }
            $foreignKeys[$id]['columns'][(int) $row['seq']] = $row['from'];
            $foreignKeys[$id]['references'][(int) $row['seq']] = $row['to'];
        }
        foreach ($foreignKeys as &$foreignKey) {
            ksort($foreignKey['columns']);
            ksort($foreignKey['references']);
            $foreignKey['columns'] = array_values($foreignKey['columns']);
            $foreignKey['references'] = array_values($foreignKey['references']);
        }
        unset($foreignKey);

        $suppressed = wallos_pgsql_schema_suppressed_foreign_keys();
        $foreignKeys = array_values(array_filter($foreignKeys, function ($foreignKey) use ($table, $suppressed) {
            foreach ($foreignKey['columns'] as $column) {
                if (in_array($table . '.' . $column, $suppressed, true)) {
                    return false;
                }
            }

            return true;
        }));
        // PRAGMA numbers foreign keys from the bottom of the CREATE TABLE
        // upwards. Sorting by the columns they constrain makes the output
        // independent of that, and of anything a future SQLite changes about it.
        usort($foreignKeys, function ($left, $right) {
            return strcmp(implode(',', $left['columns']), implode(',', $right['columns']));
        });

        $unique = [];
        $indexes = [];
        $result = $db->query('PRAGMA index_list(' . $quoted . ')');
        $indexRows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $indexRows[] = $row;
        }
        foreach ($indexRows as $row) {
            $columnsInIndex = [];
            $inner = $db->query('PRAGMA index_info(' . wallos_pgsql_quote_identifier($row['name']) . ')');
            while ($indexColumn = $inner->fetchArray(SQLITE3_ASSOC)) {
                $columnsInIndex[(int) $indexColumn['seqno']] = $indexColumn['name'];
            }
            ksort($columnsInIndex);
            $columnsInIndex = array_values($columnsInIndex);

            if ($row['origin'] === 'pk') {
                // Already carried by the primary key itself.
                continue;
            }
            if ($row['origin'] === 'u') {
                $unique[] = $columnsInIndex;
                continue;
            }

            $indexes[$row['name']] = [
                'unique' => (int) $row['unique'] === 1,
                'columns' => $columnsInIndex,
            ];
        }
        usort($unique, function ($left, $right) {
            return strcmp(implode(',', $left), implode(',', $right));
        });
        ksort($indexes);

        $schema[$table] = [
            'columns' => $columns,
            'primary_key' => array_values($primaryKey),
            'foreign_keys' => $foreignKeys,
            'unique' => $unique,
            'indexes' => $indexes,
            'rows' => wallos_pgsql_schema_rows($db, $table, $columns, array_values($primaryKey)),
        ];
    }

    return $schema;
}

/**
 * The rows a fresh installation is born with.
 *
 * createdatabase.php seeds the currencies, cycles, frequencies, categories and
 * payment methods every installation needs, and the migration chain adds the
 * admin and settings rows plus its own bookkeeping. A PostgreSQL installation
 * that skips the chain has to be handed all of it, or it comes up with no
 * billing cycles and no currency to price anything in.
 *
 * Columns defaulting to CURRENT_TIMESTAMP are left out so PostgreSQL fills in
 * the moment of installation. Copying the timestamps from the machine that
 * generated the file would be both wrong and different on every regeneration.
 *
 * @param WallosDatabase $db
 * @param string         $table
 * @param array          $columns
 * @param array          $primaryKey
 * @return array{columns: array, values: array}
 */
function wallos_pgsql_schema_rows($db, $table, $columns, $primaryKey)
{
    $names = [];
    foreach ($columns as $column) {
        if ($column['default'] !== null && strtoupper(trim((string) $column['default'])) === 'CURRENT_TIMESTAMP') {
            continue;
        }
        $names[] = $column['name'];
    }

    $selection = [];
    foreach ($names as $name) {
        $selection[] = wallos_pgsql_quote_identifier($name);
    }

    $sql = 'SELECT ' . implode(', ', $selection) . ' FROM ' . wallos_pgsql_quote_identifier($table);
    if ($primaryKey !== []) {
        $order = [];
        foreach ($primaryKey as $name) {
            $order[] = wallos_pgsql_quote_identifier($name);
        }
        $sql .= ' ORDER BY ' . implode(', ', $order);
    }

    $values = [];
    $result = $db->query($sql);
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $values[] = $row;
    }

    return ['columns' => $names, 'values' => $values];
}

/**
 * The PostgreSQL type for one column.
 *
 * @param array $column
 * @param array $primaryKey
 * @param string $table
 * @return string
 */
function wallos_pgsql_schema_column_type($column, $primaryKey, $table)
{
    $overrides = wallos_pgsql_schema_type_overrides();
    $key = $table . '.' . $column['name'];
    if (isset($overrides[$key])) {
        return $overrides[$key];
    }

    $types = wallos_pgsql_schema_types();
    $type = $column['type'];

    if (!isset($types[$type])) {
        throw new RuntimeException(sprintf(
            'No PostgreSQL type for %s.%s declared as "%s". Add it to wallos_pgsql_schema_types().',
            $table,
            $column['name'],
            $type
        ));
    }

    // SQLite's INTEGER PRIMARY KEY is the rowid, and every one of them in
    // Wallos is a generated id — with or without AUTOINCREMENT, which only
    // changes whether SQLite reuses deleted ids.
    if ($primaryKey === [$column['name']] && $types[$type] === 'INTEGER') {
        return 'SERIAL PRIMARY KEY';
    }

    return $types[$type];
}

/**
 * The PostgreSQL spelling of a SQLite column default.
 *
 * @param array  $column
 * @param string $table
 * @return string|null
 */
function wallos_pgsql_schema_column_default($column, $table)
{
    $overrides = wallos_pgsql_schema_default_overrides();
    $key = $table . '.' . $column['name'];
    if (isset($overrides[$key])) {
        return $overrides[$key];
    }

    if ($column['default'] === null) {
        return null;
    }

    $default = trim((string) $column['default']);
    $upper = strtoupper($default);

    if ($upper === 'NULL' || $upper === 'CURRENT_TIMESTAMP') {
        return $upper;
    }

    if ($upper === 'FALSE' || $upper === 'TRUE') {
        if ($column['type'] !== 'BOOLEAN') {
            throw new RuntimeException(sprintf('%s.%s defaults to %s but is not BOOLEAN.', $table, $column['name'], $default));
        }

        // The column is INTEGER now, so its default has to be as well.
        return $upper === 'TRUE' ? '1' : '0';
    }

    if (is_numeric($default)) {
        return $default;
    }

    if (strlen($default) >= 2 && $default[0] === "'" && substr($default, -1) === "'") {
        return $default;
    }

    if (strlen($default) >= 2 && $default[0] === '"' && substr($default, -1) === '"') {
        // SQLite accepts a double-quoted string literal where the standard says
        // identifier, and two migrations rely on it. PostgreSQL reads the same
        // text as a column reference and rejects the statement.
        $inner = substr($default, 1, -1);

        return "'" . str_replace("'", "''", str_replace('""', '"', $inner)) . "'";
    }

    throw new RuntimeException(sprintf(
        'Cannot translate the default of %s.%s: %s',
        $table,
        $column['name'],
        $default
    ));
}

/**
 * Renders the schema as PostgreSQL DDL.
 *
 * @param array $schema
 * @param array $migrations migration paths, in the form run_migrations.php stores
 * @return string
 */
function wallos_pgsql_schema_render($schema, $migrations)
{
    $lines = [];

    $lines[] = '-- Wallos PostgreSQL baseline schema — generated, do not edit by hand.';
    $lines[] = '--';
    $lines[] = '-- Produced by dev/generate-pgsql-schema.php from a SQLite database that has';
    $lines[] = '-- run createdatabase.php and the full migration chain. Issue #21 asks for a';
    $lines[] = '-- current-schema baseline for fresh PostgreSQL installations instead of a port';
    $lines[] = '-- of the historical migrations, so the migrations table below is seeded with';
    $lines[] = '-- every migration already marked as applied and includes/run_migrations.php';
    $lines[] = '-- finds nothing to do.';
    $lines[] = '--';
    $lines[] = '-- Regenerate with:';
    $lines[] = '--   podman exec wallos-dev php /var/www/html/dev/generate-pgsql-schema.php';
    $lines[] = '--';
    $lines[] = '-- tests/cases/pgsql_schema_test.php regenerates it and fails on any difference,';
    $lines[] = '-- so the baseline cannot go stale behind a new migration.';
    $lines[] = '--';
    $lines[] = '-- Three translations are deliberate and none of them is an improvement:';
    $lines[] = '--   * BOOLEAN columns are INTEGER. Wallos writes 0 and 1 and compares them with';
    $lines[] = '--     == 1 everywhere; a real BOOLEAN returns true and false and breaks all of it.';
    $lines[] = '--   * DATE columns are TEXT. Wallos stores and compares \'2026-01-01\' strings.';
    $lines[] = '--   * Every identifier is quoted, because "user" and "order" are reserved words';
    $lines[] = '--     and a keyword list kept in the generator would be wrong eventually.';
    $lines[] = '';
    $lines[] = sprintf('-- %d tables, %d migrations recorded as applied.', count($schema), count($migrations));
    $lines[] = '';

    foreach ($schema as $table => $definition) {
        $lines[] = 'CREATE TABLE ' . wallos_pgsql_quote_identifier($table) . ' (';

        $parts = [];
        foreach ($definition['columns'] as $column) {
            $part = '    ' . wallos_pgsql_quote_identifier($column['name'])
                . ' ' . wallos_pgsql_schema_column_type($column, $definition['primary_key'], $table);

            $default = wallos_pgsql_schema_column_default($column, $table);
            if ($default !== null && substr($part, -18) === 'SERIAL PRIMARY KEY') {
                throw new RuntimeException(sprintf('%s.%s is a generated id and cannot carry a default.', $table, $column['name']));
            }
            if ($default !== null) {
                $part .= ' DEFAULT ' . $default;
            }
            if ($column['notnull']) {
                $part .= ' NOT NULL';
            }

            $parts[] = $part;
        }

        // A single INTEGER primary key is already spelled on the column as
        // SERIAL PRIMARY KEY; anything else needs a table constraint.
        $onTheColumn = wallos_pgsql_schema_serial_column($definition) !== null;
        if ($definition['primary_key'] !== [] && !$onTheColumn) {
            $parts[] = '    PRIMARY KEY (' . wallos_pgsql_schema_column_list($definition['primary_key']) . ')';
        }

        foreach ($definition['unique'] as $columns) {
            $parts[] = '    UNIQUE (' . wallos_pgsql_schema_column_list($columns) . ')';
        }

        $lines[] = implode(",\n", $parts);
        $lines[] = ');';
        $lines[] = '';
    }

    $constraints = [];
    foreach ($schema as $table => $definition) {
        foreach ($definition['foreign_keys'] as $foreignKey) {
            $name = $table . '_' . implode('_', $foreignKey['columns']) . '_fkey';
            $constraint = 'ALTER TABLE ' . wallos_pgsql_quote_identifier($table)
                . ' ADD CONSTRAINT ' . wallos_pgsql_quote_identifier($name)
                . "\n    FOREIGN KEY (" . wallos_pgsql_schema_column_list($foreignKey['columns']) . ')'
                . ' REFERENCES ' . wallos_pgsql_quote_identifier($foreignKey['table'])
                . ' (' . wallos_pgsql_schema_column_list($foreignKey['references']) . ')';

            if ($foreignKey['on_delete'] !== '' && $foreignKey['on_delete'] !== 'NO ACTION') {
                $constraint .= ' ON DELETE ' . $foreignKey['on_delete'];
            }
            if ($foreignKey['on_update'] !== '' && $foreignKey['on_update'] !== 'NO ACTION') {
                $constraint .= ' ON UPDATE ' . $foreignKey['on_update'];
            }

            $constraints[] = $constraint . ';';
        }
    }

    if ($constraints !== []) {
        $lines[] = '-- Foreign keys, added once every table exists rather than inside the CREATE';
        $lines[] = '-- TABLE statements, so this file does not depend on the order tables appear in.';
        $lines[] = '--';
        $lines[] = '-- SQLite does not enforce these unless foreign_keys is switched on, which Wallos';
        $lines[] = '-- never does, so PostgreSQL is the first backend that actually holds the';
        $lines[] = '-- application to them.';
        $lines[] = '';
        foreach ($constraints as $constraint) {
            $lines[] = $constraint;
        }
        $lines[] = '';
    }

    $indexStatements = [];
    foreach ($schema as $table => $definition) {
        foreach ($definition['indexes'] as $name => $index) {
            $indexStatements[] = 'CREATE ' . ($index['unique'] ? 'UNIQUE ' : '') . 'INDEX '
                . wallos_pgsql_quote_identifier($name)
                . ' ON ' . wallos_pgsql_quote_identifier($table)
                . ' (' . wallos_pgsql_schema_column_list($index['columns']) . ');';
        }
    }

    if ($indexStatements !== []) {
        $lines[] = '-- Indexes.';
        $lines[] = '';
        foreach ($indexStatements as $statement) {
            $lines[] = $statement;
        }
        $lines[] = '';
    }

    $data = [];
    $sequences = [];
    foreach ($schema as $table => $definition) {
        if ($definition['rows']['values'] === []) {
            continue;
        }

        $columns = wallos_pgsql_schema_column_list($definition['rows']['columns']);
        $tuples = [];
        foreach ($definition['rows']['values'] as $row) {
            $values = [];
            foreach ($definition['rows']['columns'] as $column) {
                $values[] = wallos_pgsql_quote_value($row[$column]);
            }
            $tuples[] = '    (' . implode(', ', $values) . ')';
        }

        $data[] = 'INSERT INTO ' . wallos_pgsql_quote_identifier($table) . ' (' . $columns . ") VALUES\n"
            . implode(",\n", $tuples) . ';';

        $serial = wallos_pgsql_schema_serial_column($definition);
        if ($serial !== null) {
            $sequences[] = sprintf(
                "SELECT setval(pg_get_serial_sequence('%s', '%s'), (SELECT MAX(%s) FROM %s));",
                str_replace("'", "''", $table),
                str_replace("'", "''", $serial),
                wallos_pgsql_quote_identifier($serial),
                wallos_pgsql_quote_identifier($table)
            );
        }
    }

    if ($data !== []) {
        $lines[] = '-- The rows a fresh installation starts with: the reference data';
        $lines[] = '-- createdatabase.php seeds, and the admin and settings rows the migration';
        $lines[] = '-- chain creates. Columns defaulting to CURRENT_TIMESTAMP are omitted so they';
        $lines[] = '-- record the moment of installation rather than the moment of generation.';
        $lines[] = '';
        foreach ($data as $statement) {
            $lines[] = $statement;
            $lines[] = '';
        }
    }

    if ($sequences !== []) {
        $lines[] = '-- The rows above carry their original ids, which leaves every sequence at 1 and';
        $lines[] = '-- the next insert colliding with seeded data.';
        $lines[] = '';
        foreach ($sequences as $statement) {
            $lines[] = $statement;
        }
        $lines[] = '';
    }

    return implode("\n", $lines);
}

/**
 * The name of the SERIAL column of a table, if it has one.
 *
 * @param array $definition
 * @return string|null
 */
function wallos_pgsql_schema_serial_column($definition)
{
    if (count($definition['primary_key']) !== 1) {
        return null;
    }

    foreach ($definition['columns'] as $column) {
        if ($column['name'] !== $definition['primary_key'][0]) {
            continue;
        }

        $types = wallos_pgsql_schema_types();

        return isset($types[$column['type']]) && $types[$column['type']] === 'INTEGER' ? $column['name'] : null;
    }

    return null;
}

/**
 * @param array $columns
 * @return string
 */
function wallos_pgsql_schema_column_list($columns)
{
    $quoted = [];
    foreach ($columns as $column) {
        $quoted[] = wallos_pgsql_quote_identifier($column);
    }

    return implode(', ', $quoted);
}

/**
 * Every migration, spelled the way includes/run_migrations.php records it.
 *
 * @param string|null $directory
 * @return array
 */
function wallos_pgsql_schema_migrations($directory = null)
{
    $directory = $directory === null ? dirname(__DIR__) . '/migrations' : $directory;

    $migrations = array_map(
        function ($path) {
            return 'migrations/' . basename($path);
        },
        glob($directory . '/*.php') ?: []
    );
    sort($migrations);

    return $migrations;
}

/**
 * The generated baseline for one fully migrated SQLite database.
 *
 * @param string $sqlitePath
 * @return string
 */
function wallos_pgsql_schema_generate($sqlitePath)
{
    $db = wallos_database_connect($sqlitePath);
    $schema = wallos_pgsql_schema_read($db);
    $db->close();

    $migrations = wallos_pgsql_schema_migrations();
    $recorded = [];
    foreach ($schema['migrations']['rows']['values'] as $row) {
        $recorded[] = $row['migration'];
    }
    sort($recorded);

    if ($recorded !== $migrations) {
        // The reference database is what the file is generated from, so a
        // mismatch means it was built from a different migrations directory and
        // the baseline would be wrong in a way nothing downstream could detect.
        throw new RuntimeException(sprintf(
            'The reference database records %d migrations but migrations/ holds %d.',
            count($recorded),
            count($migrations)
        ));
    }

    return wallos_pgsql_schema_render($schema, $migrations);
}

/**
 * Builds a throwaway SQLite database with the current schema.
 *
 * Both scripts resolve their paths from __DIR__, so they run inside a copy of
 * the tree rather than against the installation this is invoked from — the same
 * arrangement tests/bootstrap.php uses, and for the same reason.
 *
 * @return string
 */
function wallos_pgsql_schema_reference_database()
{
    $root = dirname(__DIR__);
    $sandbox = sys_get_temp_dir() . '/wallos-pgsql-schema';

    if (is_dir($sandbox)) {
        wallos_pgsql_schema_remove($sandbox);
    }

    // includes/ but not includes/database: that one is a symlink to the real
    // directory below, and mkdir would take the name first.
    foreach (['endpoints/cronjobs', 'includes', 'migrations', 'db'] as $directory) {
        mkdir($sandbox . '/' . $directory, 0700, true);
    }

    foreach (glob($root . '/migrations/*.php') as $migration) {
        copy($migration, $sandbox . '/migrations/' . basename($migration));
    }
    copy($root . '/endpoints/cronjobs/createdatabase.php', $sandbox . '/endpoints/cronjobs/createdatabase.php');
    copy($root . '/includes/run_migrations.php', $sandbox . '/includes/run_migrations.php');

    // The whole directory as one symlink, rather than a list of the files the
    // sandbox happens to need today. That list has fallen behind twice: once
    // when 543f25e added two requires to createdatabase.php, and again when the
    // PostgreSQL installer joined them — each time the generator died while the
    // test case that builds its own sandbox kept passing, so the tool the
    // instructions point at was the broken one.
    //
    // Symlinked rather than copied: a copy is a second file to PHP, so
    // require_once loads it again and every function is declared twice.
    symlink($root . '/includes/database', $sandbox . '/includes/database');
    symlink($root . '/includes/config_helper.php', $sandbox . '/includes/config_helper.php');

    // The reference is always SQLite: this whole function exists to walk the
    // migration chain forward, and the chain is SQLite statements. Without
    // saying so, createdatabase.php reads the environment — and inside a
    // container configured for PostgreSQL it takes the baseline path, returns,
    // and leaves an empty file behind. The migrations then fail one after
    // another against tables that were never created, which reads as sixty
    // broken migrations rather than one unset variable.
    putenv('WALLOS_DB_DRIVER=sqlite');

    $databaseFile = $sandbox . '/db/wallos.db';

    putenv('WALLOS_DB_PATH=' . $databaseFile);
    ob_start();
    require $sandbox . '/endpoints/cronjobs/createdatabase.php';
    $db = wallos_database_connect($databaseFile);
    require $sandbox . '/includes/run_migrations.php';
    $db->close();
    ob_end_clean();
    putenv('WALLOS_DB_PATH');

    return $databaseFile;
}

/**
 * @param string $path
 * @return void
 */
function wallos_pgsql_schema_remove($path)
{
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . '/' . $entry;
        if (is_dir($child) && !is_link($child)) {
            wallos_pgsql_schema_remove($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
}

// Only when invoked directly: tests/cases/pgsql_schema_test.php includes this
// file for the functions above.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $options = ['check' => false, 'stdout' => false, 'database' => null, 'output' => wallos_pgsql_schema_path()];

    for ($i = 1; $i < $argc; $i++) {
        switch ($argv[$i]) {
            case '--check':
                $options['check'] = true;
                break;
            case '--stdout':
                $options['stdout'] = true;
                break;
            case '--database':
                $options['database'] = $argv[++$i] ?? null;
                break;
            case '--output':
                $options['output'] = $argv[++$i] ?? null;
                break;
            default:
                fwrite(STDERR, 'Unknown argument: ' . $argv[$i] . "\n");
                exit(2);
        }
    }

    $sqlitePath = $options['database'] ?? wallos_pgsql_schema_reference_database();
    $sql = wallos_pgsql_schema_generate($sqlitePath);

    if ($options['stdout']) {
        echo $sql;
        exit(0);
    }

    if ($options['check']) {
        $current = is_file($options['output']) ? file_get_contents($options['output']) : null;

        if ($current === $sql) {
            echo "The checked-in schema matches the current migration chain.\n";
            exit(0);
        }

        fwrite(STDERR, $current === null
            ? $options['output'] . " does not exist.\n"
            : $options['output'] . " is out of date. Regenerate it with dev/generate-pgsql-schema.php.\n");
        exit(1);
    }

    file_put_contents($options['output'], $sql);
    printf("Wrote %s (%d bytes).\n", $options['output'], strlen($sql));
}
