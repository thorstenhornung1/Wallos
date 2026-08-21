<?php
/*
  A backup that describes the data rather than the file it happens to sit in.

  Backup and restore moved db/wallos.db. That is a complete durability story on
  SQLite and none of it on PostgreSQL, where db/ holds only setup_token.db — so
  a PostgreSQL instance had no backup at all through the interface, first
  silently (an empty archive reported as success) and then honestly (refused
  since 5.8.2, issue #23).

  This reads the rows and writes them out:

      manifest.json      what this is, where it came from, and how much of it
      data/<table>.json  one file per table, rows as objects
      uploads/…          the files under images/uploads/

  ## Why rows and not a dump

  A dump belongs to the engine that produced it: pg_dump output does not load
  into SQLite, and neither can be read without the matching binary in the image.
  Rows are the thing both backends agree on, which makes the archive portable in
  the direction that matters — an installation that outgrows SQLite can restore
  into PostgreSQL, which is issue #79 arriving for free.

  ## The three things that make this harder than it looks

  **Order.** PostgreSQL enforces foreign keys; SQLite has never been asked to.
  Rows must go in so that a parent exists before its children, and the order is
  computed from the target database's own constraints rather than written out
  here — a list would be wrong the first time a table is added.

  **Sequences.** Inserting explicit ids does not move a PostgreSQL sequence, so
  the next insert after a restore collides with a row that was just written.
  Every serial column is set past its largest id afterwards. This has no SQLite
  equivalent and is the failure that would otherwise appear hours later, on the
  first write.

  **Secrets.** The archive contains SMTP passwords, API keys and OIDC client
  secrets in clear text, because that is what restoring an installation
  requires. The manifest says so, and so does the documentation.
*/

require_once __DIR__ . '/../database/connection.php';

/** The format this file writes. Read on import; a newer one is refused. */
const WALLOS_ARCHIVE_VERSION = 1;

/**
 * One row of a result, keyed by column name.
 *
 * The mode constant lives here and nowhere else in this file. The result
 * object defaults to returning every column twice — once by name and once by
 * position — which would put each value into the archive twice and restore
 * it into a column called "0". The boundary offers no way to ask for names
 * only without naming the constant (issue #20), so it is named once.
 *
 * @param mixed $result
 * @return array|false
 */
function wallos_archive_fetch($result)
{
    return $result === false ? false : $result->fetchArray(SQLITE3_ASSOC);
}

/**
 * Every base table holding data, in a stable order.
 *
 * Asked of the database rather than listed, so a table added by a migration is
 * in the next backup without anyone remembering this file exists — the same
 * reasoning as wallos_user_deletion_plan().
 *
 * @param WallosDatabase $db
 * @return string[]
 */
function wallos_archive_tables($db)
{
    if ($db->driver() === 'pgsql') {
        $sql = "SELECT table_name FROM information_schema.tables
                WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
                ORDER BY table_name";
    } else {
        $sql = "SELECT name AS table_name FROM sqlite_master
                WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
                ORDER BY name";
    }

    $tables = [];
    $result = $db->query($sql);

    while ($row = wallos_archive_fetch($result)) {
        $tables[] = $row['table_name'];
    }

    return $tables;
}

/**
 * Which table each table must be written after, from the target's own
 * constraints.
 *
 * Only PostgreSQL has any: SQLite carries the declarations but never enforces
 * them, so on SQLite the answer is empty and any order works. Reading them from
 * the database rather than from a list means the order follows the schema.
 *
 * @param WallosDatabase $db
 * @return array<string, string[]> table => tables it depends on
 */
function wallos_archive_dependencies($db)
{
    if ($db->driver() !== 'pgsql') {
        return [];
    }

    $sql = "SELECT tc.table_name AS child, ccu.table_name AS parent
            FROM information_schema.table_constraints tc
            JOIN information_schema.constraint_column_usage ccu
              ON ccu.constraint_name = tc.constraint_name
             AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = current_schema()";

    $dependencies = [];
    $result = $db->query($sql);

    while ($row = wallos_archive_fetch($result)) {
        if ($row['child'] === $row['parent']) {
            // A self reference orders rows within one table, not tables
            // against each other, and would make the sort unsatisfiable.
            continue;
        }

        $dependencies[$row['child']][] = $row['parent'];
    }

    return $dependencies;
}

/**
 * The tables in an order where every parent precedes its children.
 *
 * A plain topological sort. Anything left over after the passes — which would
 * mean a cycle — is appended rather than dropped: a restore that puts a row in
 * the wrong order fails loudly, and one that silently omits a table does not.
 *
 * @param string[]                $tables
 * @param array<string, string[]> $dependencies
 * @return string[]
 */
function wallos_archive_order($tables, $dependencies)
{
    $ordered = [];
    $remaining = $tables;

    // Bounded by the table count: each pass places at least one table unless
    // the remainder is cyclic, and then the loop ends and the rest is appended.
    for ($pass = 0; $pass < count($tables) && $remaining !== []; $pass++) {
        $placed = [];

        foreach ($remaining as $table) {
            $parents = $dependencies[$table] ?? [];
            $waiting = false;

            foreach ($parents as $parent) {
                if (in_array($parent, $remaining, true) && $parent !== $table) {
                    $waiting = true;
                    break;
                }
            }

            if (!$waiting) {
                $ordered[] = $table;
                $placed[] = $table;
            }
        }

        if ($placed === []) {
            break;
        }

        $remaining = array_values(array_diff($remaining, $placed));
    }

    return array_merge($ordered, $remaining);
}

/**
 * Every row of one table.
 *
 * @param WallosDatabase $db
 * @param string         $table
 * @return array[]
 */
function wallos_archive_rows($db, $table)
{
    $rows = [];
    $result = $db->query('SELECT * FROM ' . wallos_archive_quote($db, $table));

    while ($row = wallos_archive_fetch($result)) {
        $rows[] = $row;
    }

    return $rows;
}

/**
 * An identifier, quoted the way the backend wants it.
 *
 * "user" is a reserved word in PostgreSQL and "order" in both, so nothing here
 * can go unquoted. The names come from the schema, never from a request.
 *
 * @param WallosDatabase $db
 * @param string         $name
 * @return string
 */
function wallos_archive_quote($db, $name)
{
    return '"' . str_replace('"', '', $name) . '"';
}

/**
 * What the archive says about itself.
 *
 * @param WallosDatabase $db
 * @param array<string, int> $counts
 * @return array
 */
function wallos_archive_manifest($db, $counts)
{
    return [
        'format' => WALLOS_ARCHIVE_VERSION,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'wallos_version' => wallos_archive_version(),
        'driver' => $db->driver(),
        'tables' => $counts,
        'contains_secrets' => true,
        'note' => 'Rows are stored as data, so this archive restores into either backend. '
            . 'It contains SMTP passwords, API keys and OIDC client secrets in clear text.',
    ];
}

/**
 * @return string
 */
function wallos_archive_version()
{
    $path = __DIR__ . '/../version.php';

    if (!is_file($path)) {
        return 'unknown';
    }

    include $path;

    return isset($version) ? $version : 'unknown';
}

/**
 * Writes the whole installation into a zip.
 *
 * @param WallosDatabase $db
 * @param string         $zipPath  where to write
 * @param string|null    $uploads  directory to include, null to skip
 * @return array{success: bool, error: string|null, tables: int, rows: int}
 */
function wallos_archive_export($db, $zipPath, $uploads = null)
{
    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['success' => false, 'error' => 'could not create the archive', 'tables' => 0, 'rows' => 0];
    }

    $counts = [];
    $total = 0;

    foreach (wallos_archive_tables($db) as $table) {
        $rows = wallos_archive_rows($db, $table);
        $counts[$table] = count($rows);
        $total += count($rows);

        // JSON_UNESCAPED_UNICODE so a currency symbol stays one character and
        // the archive stays readable to anything that opens it.
        $encoded = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            $zip->close();
            @unlink($zipPath);

            return [
                'success' => false,
                'error' => 'could not encode ' . $table . ': ' . json_last_error_msg(),
                'tables' => 0,
                'rows' => 0,
            ];
        }

        $zip->addFromString('data/' . $table . '.json', $encoded);
    }

    $zip->addFromString('manifest.json',
        json_encode(wallos_archive_manifest($db, $counts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    if ($uploads !== null && is_dir($uploads)) {
        wallos_archive_add_directory($zip, $uploads, 'uploads');
    }

    if ($zip->close() === false) {
        @unlink($zipPath);

        return ['success' => false, 'error' => 'could not finish the archive', 'tables' => 0, 'rows' => 0];
    }

    return ['success' => true, 'error' => null, 'tables' => count($counts), 'rows' => $total];
}

/**
 * Adds a directory tree to an open archive.
 *
 * @param ZipArchive $zip
 * @param string     $directory
 * @param string     $prefix
 */
function wallos_archive_add_directory($zip, $directory, $prefix)
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $relative = str_replace($directory, '', $file->getPathname());
        $zip->addFile($file->getPathname(), $prefix . '/' . ltrim(str_replace('\\', '/', $relative), '/'));
    }
}

/**
 * Reads an archive back into an empty-or-not database.
 *
 * Everything happens inside one transaction: a restore that stops halfway is
 * the worst possible outcome, because it leaves an installation that is neither
 * the old one nor the new one and no way to tell which rows are which.
 *
 * The existing rows go first, in reverse dependency order so children are
 * removed before their parents, and the new ones go in forwards. On SQLite the
 * order does not matter — foreign keys are declared and never enforced — and
 * doing it the same way on both backends means the path that runs in
 * production is the path the tests exercise.
 *
 * @param WallosDatabase $db
 * @param string         $zipPath
 * @param string|null    $uploads directory to restore files into, null to skip
 * @return array{success: bool, error: string|null, tables: int, rows: int}
 */
function wallos_archive_import($db, $zipPath, $uploads = null)
{
    $zip = new ZipArchive();

    if ($zip->open($zipPath) !== true) {
        return wallos_archive_failure('the archive could not be opened');
    }

    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

    if (!is_array($manifest) || !isset($manifest['format'])) {
        $zip->close();

        return wallos_archive_failure('this is not a Wallos archive: no manifest');
    }

    if ((int) $manifest['format'] > WALLOS_ARCHIVE_VERSION) {
        $zip->close();

        return wallos_archive_failure('the archive was written by a newer version of Wallos ('
            . htmlspecialchars((string) ($manifest['wallos_version'] ?? '?')) . ')');
    }

    // The tables the target has, not the ones the archive brings: restoring a
    // table this installation no longer has would fail, and one it has gained
    // since is simply left empty.
    $tables = wallos_archive_tables($db);
    $order = wallos_archive_order($tables, wallos_archive_dependencies($db));

    if (!$db->beginTransaction()) {
        $zip->close();

        return wallos_archive_failure('could not start a transaction: ' . $db->lastErrorMsg());
    }

    $restored = 0;
    $touched = 0;

    foreach (array_reverse($order) as $table) {
        if ($db->exec('DELETE FROM ' . wallos_archive_quote($db, $table)) === false) {
            return wallos_archive_abort($db, $zip, 'could not clear ' . $table . ': ' . $db->lastErrorMsg());
        }
    }

    foreach ($order as $table) {
        $contents = $zip->getFromName('data/' . $table . '.json');

        if ($contents === false) {
            // A table the archive does not carry. Older archive, newer schema:
            // leaving it empty is right, and saying nothing about it is not.
            continue;
        }

        $rows = json_decode($contents, true);

        if (!is_array($rows)) {
            return wallos_archive_abort($db, $zip, 'the rows for ' . $table . ' could not be read');
        }

        $touched++;

        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }

            $written = wallos_archive_insert($db, $table, $row);

            if ($written !== true) {
                return wallos_archive_abort($db, $zip, 'could not restore a row of ' . $table . ': ' . $written);
            }

            $restored++;
        }
    }

    if (!wallos_archive_reset_sequences($db, $order)) {
        return wallos_archive_abort($db, $zip, 'could not move the sequences past the restored rows: '
            . $db->lastErrorMsg());
    }

    if (!$db->commit()) {
        return wallos_archive_abort($db, $zip, 'could not commit the restore: ' . $db->lastErrorMsg());
    }

    if ($uploads !== null) {
        wallos_archive_extract_uploads($zip, $uploads);
    }

    $zip->close();

    return ['success' => true, 'error' => null, 'tables' => $touched, 'rows' => $restored];
}

/**
 * Inserts one row, with the column names taken from the row itself.
 *
 * @param WallosDatabase $db
 * @param string         $table
 * @param array          $row
 * @return true|string true, or the reason it failed
 */
function wallos_archive_insert($db, $table, array $row)
{
    $columns = [];
    $placeholders = [];
    $values = [];
    $position = 1;

    foreach ($row as $column => $value) {
        // Column names come out of the archive, which is a file somebody
        // uploaded. Anything that is not a plain identifier is refused rather
        // than quoted and hoped for.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $column) !== 1) {
            return 'the archive names a column that is not an identifier';
        }

        $columns[] = wallos_archive_quote($db, (string) $column);
        $placeholders[] = '?';
        $values[] = $value;
        $position++;
    }

    if ($columns === []) {
        return true;
    }

    $statement = $db->prepare('INSERT INTO ' . wallos_archive_quote($db, $table)
        . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');

    if ($statement === false) {
        return $db->lastErrorMsg();
    }

    foreach (array_values($values) as $index => $value) {
        $statement->bindValue($index + 1, $value);
    }

    return $statement->execute() === false ? $db->lastErrorMsg() : true;
}

/**
 * Moves every serial sequence past the rows just restored.
 *
 * Inserting an explicit id does not advance a PostgreSQL sequence, so without
 * this the first write after a restore collides with a row the restore had just
 * written — hours later, in an unrelated place. SQLite has no equivalent and
 * needs none: its rowid follows the largest value present.
 *
 * @param WallosDatabase $db
 * @param string[]       $tables
 * @return bool
 */
function wallos_archive_reset_sequences($db, $tables)
{
    if ($db->driver() !== 'pgsql') {
        return true;
    }

    // Asked of the schema rather than assumed to be "id". cron_runs is keyed by
    // job and has no id at all, and a restore that stopped there would report a
    // failure for a table that has no sequence to move.
    $columns = $db->query("SELECT table_name, column_name FROM information_schema.columns
                           WHERE table_schema = current_schema()
                             AND column_default LIKE 'nextval(%'");

    $serials = [];

    while ($row = wallos_archive_fetch($columns)) {
        $serials[] = [$row['table_name'], $row['column_name']];
    }

    foreach ($serials as $serial) {
        list($table, $column) = $serial;

        if (!in_array($table, $tables, true)) {
            continue;
        }

        $quotedTable = wallos_archive_quote($db, $table);
        $quotedColumn = wallos_archive_quote($db, $column);

        // The third argument of setval decides whether the value given is the
        // last one used or the next one to hand out. An empty table needs the
        // latter, which is what COUNT(*) > 0 expresses here.
        $statement = "SELECT setval(pg_get_serial_sequence('" . $table . "', '" . $column . "'),
                             COALESCE((SELECT MAX(" . $quotedColumn . ") FROM " . $quotedTable . "), 1),
                             (SELECT COUNT(*) FROM " . $quotedTable . ") > 0)";

        if ($db->exec($statement) === false) {
            return false;
        }
    }

    return true;
}

/**
 * @param WallosDatabase $db
 * @param ZipArchive     $zip
 * @param string         $reason
 * @return array{success: bool, error: string, tables: int, rows: int}
 */
function wallos_archive_abort($db, $zip, $reason)
{
    $db->rollBack();
    $zip->close();

    return wallos_archive_failure($reason);
}

/**
 * @param string $reason
 * @return array{success: bool, error: string, tables: int, rows: int}
 */
function wallos_archive_failure($reason)
{
    return ['success' => false, 'error' => $reason, 'tables' => 0, 'rows' => 0];
}

/**
 * Restores the uploaded files.
 *
 * Entry by entry rather than extractTo(), which follows whatever paths the
 * archive contains — the zip-slip the restore endpoint already refuses. Only
 * names that stay inside the target and carry an image extension are written.
 *
 * @param ZipArchive $zip
 * @param string     $target
 * @return int files written
 */
function wallos_archive_extract_uploads($zip, $target)
{
    $written = 0;
    $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (strpos($name, 'uploads/') !== 0 || substr($name, -1) === '/') {
            continue;
        }

        $relative = substr($name, strlen('uploads/'));

        if ($relative === '' || strpos($relative, '..') !== false || strpos($relative, "\0") !== false) {
            continue;
        }

        $extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowed, true)) {
            continue;
        }

        $destination = rtrim($target, '/') . '/' . $relative;
        $directory = dirname($destination);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            continue;
        }

        $contents = $zip->getFromIndex($i);

        if ($contents !== false && file_put_contents($destination, $contents) !== false) {
            $written++;
        }
    }

    return $written;
}
