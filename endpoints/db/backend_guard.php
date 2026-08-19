<?php

/**
 * What backup, restore and import can honestly claim on the configured backend.
 *
 * All three move a file. backup.php zips db/; restore.php and import.php
 * replace db/wallos.db and reconnect. That is the whole of Wallos's durability
 * story on SQLite, and none of it on PostgreSQL: db/ holds only
 * setup_token.db there, and the file a restore installs is not the database the
 * next request reads.
 *
 * Until this guard existed all three answered success anyway — an archive with
 * no data in it, and a restore that replaced a file nothing reads, both
 * reported as "Success". That is worse than a failure, because someone who gets
 * a green result does not take a second backup. They find out at restore time,
 * which is the worst possible moment and usually the only one that counts.
 *
 * Refusing is not the destination. Backend-neutral backup and restore is
 * issue #23; until it exists, refusing makes the gap visible instead of hiding
 * it behind a success message.
 *
 * This file is an include, not an endpoint: it declares functions and returns.
 */

/**
 * Whether the file-copy path speaks for the database this request is using.
 *
 * Asked of the live connection rather than of the environment, because the
 * connection is what the rest of the request reads and writes. The endpoints
 * had the answer in hand all along and never asked for it.
 *
 * @param WallosDatabase $db
 * @return bool
 */
function wallos_db_file_backup_supported($db)
{
    // A connection that cannot say what it is predates the boundary, and every
    // one of those in Wallos is the native sqlite handle — which is the thing
    // the file copy exists for. This cannot fail open onto PostgreSQL: that
    // connection is a WallosPgsqlDatabase and always answers.
    if (!method_exists($db, 'driver')) {
        return true;
    }

    return $db->driver() === 'sqlite';
}

/**
 * How to name a backend, and what its operator would use instead.
 *
 * A table rather than a hardcoded "PostgreSQL", so a third backend gets its own
 * name in the message rather than someone else's tools.
 *
 * @param string $driver
 * @return array{label: string, dump: string, restore: string}
 */
function wallos_db_backend_tools($driver)
{
    $known = [
        'pgsql' => ['label' => 'PostgreSQL', 'dump' => 'pg_dump', 'restore' => 'pg_restore'],
    ];

    return $known[$driver] ?? [
        'label' => $driver,
        'dump' => "your database's own dump tool",
        'restore' => "your database's own restore tool",
    ];
}

/**
 * The refusal, naming the operation, the backend and the tool that does work.
 *
 * A message that only says "not supported" leaves the administrator exactly
 * where the silent success left them. This one names what to run instead and
 * where the database is, because the two facts they need next are which tool
 * and against what.
 *
 * @param string         $operation 'backup', 'restore' or 'import'
 * @param WallosDatabase $db
 * @return string
 */
function wallos_db_file_backup_refusal($operation, $db)
{
    $driver = method_exists($db, 'driver') ? $db->driver() : 'sqlite';
    $tools = wallos_db_backend_tools($driver);
    $backend = $tools['label'];
    $target = 'the database named by WALLOS_DB_HOST, WALLOS_DB_NAME and WALLOS_DB_USER';

    switch ($operation) {
        case 'backup':
            return sprintf(
                'Backup is not supported on %s. This backup copies the SQLite database file, '
                    . 'and on %s that file holds no data, so the archive would contain none either. '
                    . 'Use %s against %s instead.',
                $backend,
                $backend,
                $tools['dump'],
                $target
            );

        case 'restore':
            return sprintf(
                'Restore is not supported on %s. This restore replaces the SQLite database file, '
                    . 'which a %s instance never reads, so nothing in the backup would reach the '
                    . 'database. Use %s against %s instead.',
                $backend,
                $backend,
                $tools['restore'],
                $target
            );

        case 'import':
            return sprintf(
                'Restoring a backup during setup is not supported on %s. This restore replaces the '
                    . 'SQLite database file, which a %s instance never reads. Use %s against %s '
                    . 'instead. The setup token has been kept, so setup can be finished once the '
                    . 'data is in place.',
                $backend,
                $backend,
                $tools['restore'],
                $target
            );
    }

    // Reached only by a caller that invented an operation name, which is a
    // programming error rather than something an administrator can act on.
    throw new InvalidArgumentException('Unknown database operation: ' . $operation);
}
