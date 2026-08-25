<?php
/*
  What this instance is running on, in a form somebody can read.

  wallos_database_configuration() resolves the backend with some care: an
  unknown driver name refuses, a non-numeric port refuses rather than quietly
  connecting to 5432, an unreadable password secret invalidates the
  configuration instead of connecting without one. Every one of those carries a
  comment saying that guessing would hide the mistake.

  And then the result was never shown to anyone. Every caller was internal, so
  SQLite and PostgreSQL were indistinguishable through the interface — which is
  how the test instance ran on SQLite for three days while three reports
  described it as PostgreSQL (issue #102).

  The report deliberately carries no credentials. wallos_pgsql_dsn() already
  leaves the password out of the connection string because DSNs end up in
  exception messages; a panel on the admin page has even less business holding
  one.
*/

require_once __DIR__ . '/configuration.php';

/**
 * A product and version, from whatever the backend answers.
 *
 * @param string            $driver
 * @param string|false|null $raw
 * @return string|null Null when there is nothing to report, rather than a guess.
 */
function wallos_database_version_label($driver, $raw)
{
    if ($raw === null || $raw === false || trim((string) $raw) === '') {
        return null;
    }

    $raw = trim((string) $raw);

    if ($driver === 'sqlite') {
        // A bare number on its own says nothing about which engine produced it.
        return 'SQLite ' . $raw;
    }

    // PostgreSQL answers with a paragraph: product, version, platform, compiler.
    // The first two words are the part anybody reads.
    if (preg_match('/^(\S+)\s+(\S+)/', $raw, $match)) {
        return $match[1] . ' ' . $match[2];
    }

    return $raw;
}

/**
 * Where this instance keeps its data, and whether that was a decision.
 *
 * @param WallosDatabase $db
 * @return array{driver: string, configured: bool, source: string, version: string|null}
 */
function wallos_database_diagnostics($db)
{
    $configuration = wallos_database_configuration();
    $driver = $db->driver();

    // The distinction that matters. An unset WALLOS_DB_DRIVER also produces
    // "sqlite", so the driver name alone cannot tell a deliberate SQLite
    // instance from a PostgreSQL one whose configuration never arrived — and
    // the second is exactly the failure this exists to make visible.
    $configured = isset($configuration['managed']['driver']);

    if ($driver === 'pgsql') {
        $source = $configuration['pgsql']['user'] . '@' . $configuration['pgsql']['host']
            . ':' . $configuration['pgsql']['port'] . '/' . $configuration['pgsql']['name'];
        $version = $db->scalar('SELECT version()');
    } else {
        $source = wallos_database_path();
        // Named through the boundary like every other statement here; the
        // function is SQL, not an engine-specific API call.
        $version = $db->scalar('SELECT sqlite_version()');
    }

    return [
        'driver' => $driver,
        'configured' => $configured,
        'source' => (string) $source,
        'version' => wallos_database_version_label($driver, $version),
    ];
}
