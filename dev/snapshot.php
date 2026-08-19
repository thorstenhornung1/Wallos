<?php
/*
  The reading half of dev/snapshot.sh: what a snapshot contains, and what a
  migration would refuse to copy.

      php dev/snapshot.php source                  what the instance runs on
      php dev/snapshot.php inventory <file> [name] the manifest for a snapshot
      php dev/snapshot.php schema-create <name>    a scratch schema to rehearse in
      php dev/snapshot.php schema-drop <name>

  Why a snapshot of a real database exists at all: synthetic fixtures are
  well-formed by construction. Real installations are not. SQLite declares
  foreign keys and does not enforce them unless asked, so years of ordinary use
  leave rows behind that PostgreSQL will refuse the moment they are copied —
  subscriptions pointing at a deleted category, notification rows belonging to
  an account that was removed. No generated dataset produces that pattern,
  because a generator has no history.

  The set of constraints is read from includes/database/pgsql/schema.sql rather
  than from the source file, and deliberately so: the question a migration
  rehearsal asks is not "which references does SQLite declare" but "which ones
  will PostgreSQL enforce when this data arrives".
*/

if (php_sapi_name() !== 'cli') {
    die("This script is meant to be run from the command line.\n");
}

require_once __DIR__ . '/../includes/database/connection.php';
require_once __DIR__ . '/../includes/database/configuration.php';

/**
 * @param string $message
 * @return never
 */
function snapshot_fail($message)
{
    fwrite(STDERR, 'snapshot: ' . $message . "\n");
    exit(1);
}

/** @return string */
function snapshot_baseline_path()
{
    return dirname(__DIR__) . '/includes/database/pgsql/schema.sql';
}

/**
 * Every table the PostgreSQL baseline defines.
 *
 * @return string[]
 */
function snapshot_baseline_tables()
{
    $sql = (string) file_get_contents(snapshot_baseline_path());
    preg_match_all('/CREATE TABLE "([^"]+)"/', $sql, $matches);

    $tables = $matches[1];
    sort($tables);

    return $tables;
}

/**
 * Every foreign key PostgreSQL will enforce, as the baseline declares them.
 *
 * @return array<int, array{name: string, table: string, column: string, parent: string, parentColumn: string}>
 */
function snapshot_baseline_foreign_keys()
{
    $sql = (string) file_get_contents(snapshot_baseline_path());

    $pattern = '/ALTER TABLE "([^"]+)" ADD CONSTRAINT "([^"]+)"\s+FOREIGN KEY \("([^"]+)"\)'
        . '\s+REFERENCES "([^"]+)" \("([^"]+)"\)/';
    preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER);

    $keys = [];
    foreach ($matches as $match) {
        $keys[] = [
            'name' => $match[2],
            'table' => $match[1],
            'column' => $match[3],
            'parent' => $match[4],
            'parentColumn' => $match[5],
        ];
    }

    return $keys;
}

/**
 * How many rows of one table point at a parent row that is not there.
 *
 * NULL is not a violation: an optional reference that is unset is allowed by
 * both backends. Everything else in this query is deliberately plain SQL, so
 * the same statement answers the same question on a snapshot file and on the
 * live database it was taken from.
 *
 * @param WallosDatabase $db
 * @param array          $key
 * @return int|null null when either table is missing from this snapshot
 */
function snapshot_violations($db, $key)
{
    if (!$db->tableExists($key['table']) || !$db->tableExists($key['parent'])) {
        return null;
    }

    $sql = sprintf(
        'SELECT COUNT(*) FROM "%s" c LEFT JOIN "%s" p ON c."%s" = p."%s"'
            . ' WHERE c."%s" IS NOT NULL AND p."%s" IS NULL',
        $key['table'],
        $key['parent'],
        $key['column'],
        $key['parentColumn'],
        $key['column'],
        $key['parentColumn']
    );

    return (int) $db->scalar($sql);
}

/**
 * The manifest: what is in this file, and what a migration would trip over.
 *
 * Written next to the snapshot because a database file says nothing about
 * itself. Six months later the only question that matters about a snapshot is
 * what it was a snapshot of, and a row count answers it in a way a filename
 * never will.
 *
 * @param string $path
 * @param string $name
 * @return string
 */
function snapshot_inventory($path, $name)
{
    if (!is_file($path)) {
        snapshot_fail('no such snapshot: ' . $path);
    }

    // A file is named, so this is the SQLite branch of the boundary — the same
    // one dev/migrate-to-pgsql.php takes for its source, and the reason the two
    // tools can read the same snapshot.
    $db = wallos_database_connect($path);

    $lines = [];
    $lines[] = 'snapshot      ' . $name;
    $lines[] = 'file          ' . basename($path);
    $lines[] = 'bytes         ' . number_format((int) filesize($path), 0, '.', ' ');
    $lines[] = 'taken         ' . date('c');

    $migration = $db->tableExists('migrations')
        ? (string) $db->scalar('SELECT MAX(migration) FROM migrations')
        : '(no migrations table)';
    $applied = $db->tableExists('migrations')
        ? (int) $db->scalar('SELECT COUNT(*) FROM migrations')
        : 0;
    $lines[] = 'migrations    ' . $applied . ' applied, latest ' . $migration;

    $tables = snapshot_baseline_tables();
    $counts = [];
    $total = 0;
    $missing = [];

    foreach ($tables as $table) {
        if (!$db->tableExists($table)) {
            $missing[] = $table;
            continue;
        }

        $rows = (int) $db->scalar('SELECT COUNT(*) FROM "' . $table . '"');
        $counts[$table] = $rows;
        $total += $rows;
    }

    $lines[] = 'rows          ' . number_format($total, 0, '.', ' ')
        . ' in ' . count($counts) . ' of ' . count($tables) . ' baseline table(s)';

    if ($missing !== []) {
        // Not a warning by itself: a snapshot taken before a migration ran is
        // exactly the input a rehearsal wants. migrate-to-pgsql.php refuses on
        // the drift, and this is where the refusal stops being a surprise.
        $lines[] = 'missing       ' . implode(', ', $missing);
    }

    $lines[] = '';
    $lines[] = 'rows per table (empty tables omitted)';
    foreach ($counts as $table => $rows) {
        if ($rows === 0) {
            continue;
        }
        $lines[] = sprintf('  %-34s %8s', $table, number_format($rows, 0, '.', ' '));
    }

    $lines[] = '';
    $lines[] = 'foreign keys PostgreSQL enforces and SQLite does not';

    $violatingRows = 0;
    $violatedKeys = 0;

    foreach (snapshot_baseline_foreign_keys() as $key) {
        $violations = snapshot_violations($db, $key);

        if ($violations === null) {
            $lines[] = sprintf('  %-46s %s', $key['name'], 'table missing from this snapshot');
            continue;
        }

        if ($violations === 0) {
            continue;
        }

        $violatedKeys++;
        $violatingRows += $violations;
        $lines[] = sprintf(
            '  %-46s %6s row(s)  %s.%s -> %s.%s',
            $key['name'],
            number_format($violations, 0, '.', ' '),
            $key['table'],
            $key['column'],
            $key['parent'],
            $key['parentColumn']
        );
    }

    $lines[] = $violatingRows === 0
        ? '  none — every reference resolves'
        : sprintf('  total %s row(s) across %d constraint(s)', number_format($violatingRows, 0, '.', ' '), $violatedKeys);

    $lines[] = '';
    $lines[] = $violatingRows === 0
        ? 'A migration of this snapshot has no orphans to skip.'
        : 'A migration of this snapshot stops on the first of these unless it is given'
            . "\n" . '--skip-orphans, which leaves the rows behind and counts them.';

    $db->close();

    return implode("\n", $lines) . "\n";
}

// ------------------------------------------------------------------ dispatch

// Only when invoked directly: tests/cases/snapshot_test.php includes this file
// for the functions above.
if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== __FILE__) {
    return;
}

$command = isset($argv[1]) ? $argv[1] : '';

switch ($command) {
    case 'source':
        // Which backend the instance is configured for, so the shell can refuse
        // to "snapshot the SQLite database" of an instance that has none. The
        // driver is asked for, not the file: a leftover db/wallos.db on a
        // PostgreSQL instance is exactly the file nobody should be copying,
        // and on one instance it was the rollback backup (issue #91).
        $configuration = wallos_database_configuration();
        printf("%s\t%s\n", $configuration['driver'], $configuration['sqlite']['path']);
        break;

    case 'inventory':
        $path = (string) ($argv[2] ?? '');
        $name = (string) ($argv[3] ?? basename($path, '.db'));
        echo snapshot_inventory($path, $name);
        break;

    case 'schema-create':
    case 'schema-drop':
        $schema = (string) ($argv[2] ?? '');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema) !== 1) {
            snapshot_fail('a schema name must be a plain identifier: ' . $schema);
        }

        $db = wallos_database_connect();
        if ($db->driver() !== 'pgsql') {
            snapshot_fail('the environment does not select PostgreSQL, so there is no schema to '
                . ($command === 'schema-create' ? 'create' : 'drop') . '.');
        }

        if ($command === 'schema-drop') {
            // Refusing outright rather than reading a flag: `public` is where a
            // real instance keeps its tables, and a rehearsal tool that can drop
            // it is one typo away from being the incident.
            if ($schema === 'public') {
                snapshot_fail('refusing to drop the public schema.');
            }

            $db->exec('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
            $db->close();
            break;
        }

        // Answering "created" or "existed" is what lets the caller know whether
        // the schema is its to remove afterwards: a rehearsal against a real
        // target's own schema has to leave that schema standing.
        $existed = (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name',
            [':name' => $schema]
        ) > 0;

        if (!$existed) {
            $db->exec('CREATE SCHEMA ' . $schema);
        }

        echo $existed ? "existed\n" : "created\n";
        $db->close();
        break;

    default:
        fwrite(STDERR, "Usage: php dev/snapshot.php <source|inventory|schema-create|schema-drop> [arguments]\n");
        exit(2);
}
