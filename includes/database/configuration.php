<?php

/**
 * Which database Wallos should talk to.
 *
 * Declarative and environment-only: choosing a backend is a deployment
 * decision, not something an administrator changes through the web interface
 * while the application is running on top of it.
 *
 * SQLite stays the default. An existing installation that sets nothing keeps
 * exactly what it has.
 */

require_once __DIR__ . '/../config_helper.php';

/** Backends this build knows how to talk to. */
function wallos_database_drivers()
{
    return ['sqlite', 'pgsql'];
}

/**
 * Resolve the connection configuration.
 *
 * Returns a driver, its settings, and — when the configuration cannot be used —
 * an error explaining which variable is at fault. It never falls back to SQLite
 * on a broken PostgreSQL configuration: silently using a different database
 * than the operator asked for is how an instance comes up empty and looks like
 * data loss.
 *
 * @return array{driver: string, sqlite: array, pgsql: array, error: string|null, managed: array}
 */
function wallos_database_configuration()
{
    $configuration = [
        'driver' => 'sqlite',
        'sqlite' => ['path' => wallos_database_path()],
        'pgsql' => [
            'host' => '',
            'port' => 5432,
            'name' => '',
            'user' => '',
            'password' => '',
            'sslmode' => 'prefer',
        ],
        'error' => null,
        'managed' => [],
    ];

    if (wallos_env_has('WALLOS_DB_DRIVER')) {
        $driver = strtolower(trim((string) wallos_env('WALLOS_DB_DRIVER')));
        $configuration['managed']['driver'] = 'WALLOS_DB_DRIVER';

        if (!in_array($driver, wallos_database_drivers(), true)) {
            $configuration['error'] = 'WALLOS_DB_DRIVER is "' . $driver . '"; expected one of: '
                . implode(', ', wallos_database_drivers()) . '.';

            return $configuration;
        }

        $configuration['driver'] = $driver;
    }

    if ($configuration['driver'] === 'sqlite') {
        return $configuration;
    }

    foreach (['host' => 'WALLOS_DB_HOST', 'name' => 'WALLOS_DB_NAME', 'user' => 'WALLOS_DB_USER'] as $field => $variable) {
        if (wallos_env_has($variable)) {
            $configuration['pgsql'][$field] = trim((string) wallos_env($variable));
            $configuration['managed'][$field] = $variable;
        }
    }

    if (wallos_env_has('WALLOS_DB_PORT')) {
        $port = trim((string) wallos_env('WALLOS_DB_PORT'));
        $configuration['managed']['port'] = 'WALLOS_DB_PORT';

        // A port that is not a number is a typo, and connecting to 5432 instead
        // would hide it until someone wonders why the wrong database is in use.
        if ($port === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $configuration['error'] = 'WALLOS_DB_PORT is "' . $port . '"; expected a number between 1 and 65535.';

            return $configuration;
        }

        $configuration['pgsql']['port'] = (int) $port;
    }

    if (wallos_env_has('WALLOS_DB_SSLMODE')) {
        $mode = strtolower(trim((string) wallos_env('WALLOS_DB_SSLMODE')));
        $configuration['managed']['sslmode'] = 'WALLOS_DB_SSLMODE';
        $modes = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

        if (!in_array($mode, $modes, true)) {
            // Refusing beats guessing: a misspelt 'require' that quietly became
            // 'prefer' is an unencrypted connection nobody knows about.
            $configuration['error'] = 'WALLOS_DB_SSLMODE is "' . $mode . '"; expected one of: '
                . implode(', ', $modes) . '.';

            return $configuration;
        }

        $configuration['pgsql']['sslmode'] = $mode;
    }

    $secret = wallos_env_secret('WALLOS_DB_PASSWORD');
    if ($secret['error'] !== null) {
        // An unreadable secret file invalidates the configuration rather than
        // connecting without a password — the same rule as every other secret
        // in Wallos, and the one place where getting it wrong is worst.
        $configuration['error'] = 'WALLOS_DB_PASSWORD_FILE could not be read: ' . $secret['error'];

        return $configuration;
    }
    if ($secret['value'] !== null) {
        $configuration['pgsql']['password'] = $secret['value'];
        $configuration['managed']['password'] = $secret['variable'];
    }

    $missing = [];
    foreach (['host' => 'WALLOS_DB_HOST', 'name' => 'WALLOS_DB_NAME', 'user' => 'WALLOS_DB_USER'] as $field => $variable) {
        if ($configuration['pgsql'][$field] === '') {
            $missing[] = $variable;
        }
    }

    if ($missing !== []) {
        $configuration['error'] = 'WALLOS_DB_DRIVER is pgsql but ' . implode(', ', $missing)
            . ($missing === [] ? '' : (count($missing) === 1 ? ' is' : ' are')) . ' not set.';
    }

    return $configuration;
}

/**
 * The PDO data source name for a resolved PostgreSQL configuration.
 *
 * The password is deliberately absent: PDO takes it as a separate argument, and
 * a DSN tends to end up in exception messages and stack traces.
 *
 * @param array $pgsql
 * @return string
 */
function wallos_database_pgsql_dsn($pgsql)
{
    return sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        $pgsql['host'],
        $pgsql['port'],
        $pgsql['name'],
        $pgsql['sslmode']
    );
}
