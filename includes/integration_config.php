<?php
/*
  Effective configuration for the integrations that a Wallos instance can share
  between its users: SMTP, the currency exchange provider and the AI provider.

  Every caller — settings pages, admin pages, endpoints, test buttons and cron
  jobs — must resolve configuration through this file so that a successful test
  proves the configuration that production will actually use.

  Resolution order for a shared integration:

      explicit user override (mode = custom)
              ↓
      instance secret file  (WALLOS_*_FILE)
              ↓
      instance environment  (WALLOS_*)
              ↓
      instance database
              ↓
      application default

  Values coming from the environment are never written back to SQLite.
*/

require_once __DIR__ . '/config_helper.php';

const WALLOS_SMTP_ENCRYPTIONS = ['none', 'tls', 'ssl'];
const WALLOS_AI_PROVIDERS = ['chatgpt', 'gemini', 'openrouter', 'ollama', 'openai-compatible'];
const WALLOS_AI_HOST_PROVIDERS = ['ollama', 'openai-compatible'];

/**
 * @param SQLite3 $db
 * @param string  $integration
 * @return array<string, string> Stored instance values, keyed by setting name.
 */
function wallos_build_instance_settings($db, $integration)
{
    $tableExists = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='integration_settings'");
    if (!$tableExists) {
        return [];
    }

    $stmt = $db->prepare('SELECT setting_key, setting_value FROM integration_settings WHERE integration = :integration');
    $stmt->bindValue(':integration', $integration, SQLITE3_TEXT);
    $result = $stmt->execute();

    $settings = [];
    while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $settings[$row['setting_key']] = (string) $row['setting_value'];
    }

    return $settings;
}

/**
 * Stores one instance value. Passing an empty value removes the row so that
 * "not configured" and "configured as empty string" stay distinguishable.
 *
 * @param SQLite3 $db
 * @param string  $integration
 * @param string  $key
 * @param string  $value
 * @param bool    $isSecret
 * @return bool
 */
function wallos_set_instance_setting($db, $integration, $key, $value, $isSecret = false)
{
    // Anything cached from before this write is now stale.
    wallos_reset_config_cache($db);

    if ($value === null || $value === '') {
        $stmt = $db->prepare('DELETE FROM integration_settings WHERE integration = :integration AND setting_key = :key');
        $stmt->bindValue(':integration', $integration, SQLITE3_TEXT);
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);

        return $stmt->execute() !== false;
    }

    $stmt = $db->prepare('INSERT INTO integration_settings (integration, setting_key, setting_value, is_secret)
                          VALUES (:integration, :key, :value, :isSecret)
                          ON CONFLICT(integration, setting_key)
                          DO UPDATE SET setting_value = :value, is_secret = :isSecret');
    $stmt->bindValue(':integration', $integration, SQLITE3_TEXT);
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', (string) $value, SQLITE3_TEXT);
    $stmt->bindValue(':isSecret', $isSecret ? 1 : 0, SQLITE3_INTEGER);

    return $stmt->execute() !== false;
}

/**
 * Normalises the stored override mode. Anything unknown — including NULL from a
 * freshly added column — means "inherit the instance configuration".
 *
 * @param mixed $mode
 * @return string instance or custom
 */
function wallos_normalize_mode($mode)
{
    return trim((string) $mode) === 'custom' ? 'custom' : 'instance';
}

/**
 * Applies one environment managed secret to a resolver result.
 *
 * Returns true when the environment owns the field, in which case the caller
 * must not consider any database value for it.
 *
 * @param array  $config   Result structure, modified in place.
 * @param string $field
 * @param string $variable Name of the plain environment variable.
 * @return bool
 */
function wallos_apply_env_secret(&$config, $field, $variable)
{
    $secret = wallos_env_secret($variable);

    if (!$secret['managed']) {
        return false;
    }

    wallos_config_set($config, $field, (string) $secret['value'], $secret['source'], $secret['variable']);

    if ($secret['error'] !== null) {
        // A managed secret that cannot be read invalidates the configuration.
        // Falling back to an older database credential would silently use a
        // credential the administrator believes to be replaced.
        $config['valid'] = false;
        wallos_config_add_note($config, $secret['error']);
    }

    return true;
}

/* -------------------------------------------------------------------------
   SMTP
   ------------------------------------------------------------------------- */

/**
 * Instance SMTP transport, used for system mail and by every user who did not
 * pick a custom transport.
 *
 * @param SQLite3 $db
 * @return array Result structure with host, port, encryption, username, password, from_email and from_name.
 */
function wallos_build_instance_smtp_config($db)
{
    $config = wallos_config_result();
    $config['mode'] = 'instance';

    $stmt = $db->prepare('SELECT * FROM admin WHERE id = 1');
    $result = $stmt->execute();
    $admin = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    $admin = $admin ?: [];

    $fields = [
        'host' => ['WALLOS_SMTP_HOST', $admin['smtp_address'] ?? '', ''],
        'port' => ['WALLOS_SMTP_PORT', $admin['smtp_port'] ?? '', 587],
        'encryption' => ['WALLOS_SMTP_ENCRYPTION', $admin['encryption'] ?? '', 'tls'],
        'username' => ['WALLOS_SMTP_USERNAME', $admin['smtp_username'] ?? '', ''],
        'from_email' => ['WALLOS_SMTP_FROM', $admin['from_email'] ?? '', ''],
        'from_name' => ['WALLOS_SMTP_FROM_NAME', $admin['smtp_from_name'] ?? '', ''],
    ];

    foreach ($fields as $field => $definition) {
        [$variable, $adminValue, $default] = $definition;

        if (wallos_env_has($variable)) {
            wallos_config_set($config, $field, trim((string) wallos_env($variable)), 'environment', $variable);
        } elseif (trim((string) $adminValue) !== '') {
            wallos_config_set($config, $field, trim((string) $adminValue), 'admin');
        } else {
            wallos_config_set($config, $field, $default, 'default');
        }
    }

    if (!wallos_apply_env_secret($config, 'password', 'WALLOS_SMTP_PASSWORD')) {
        $password = (string) ($admin['smtp_password'] ?? '');
        wallos_config_set($config, 'password', $password, $password !== '' ? 'admin' : 'default');
    }

    wallos_validate_smtp_config($config);

    return $config;
}

/**
 * Shared validation, applied to instance and custom transports alike.
 *
 * @param array $config Result structure, modified in place.
 */
function wallos_validate_smtp_config(&$config)
{
    $host = trim((string) ($config['values']['host'] ?? ''));
    if ($host === '') {
        $config['valid'] = false;
        wallos_config_add_note($config, 'SMTP host is not configured.');
    }

    $port = (int) ($config['values']['port'] ?? 0);
    if ($port < 1 || $port > 65535) {
        $config['valid'] = false;
        wallos_config_add_note($config, 'SMTP port must be between 1 and 65535.');
    }
    $config['values']['port'] = $port;

    $encryption = strtolower(trim((string) ($config['values']['encryption'] ?? '')));
    if ($encryption === '') {
        $encryption = 'tls';
    } elseif (!in_array($encryption, WALLOS_SMTP_ENCRYPTIONS, true)) {
        wallos_config_add_note($config, 'Unsupported SMTP encryption "' . $encryption . '", falling back to tls.');
        $encryption = 'tls';
    }
    $config['values']['encryption'] = $encryption;

    if (trim((string) ($config['values']['from_email'] ?? '')) === '') {
        $config['values']['from_email'] = 'wallos@wallosapp.com';
        $config['source']['from_email'] = $config['source']['from_email'] ?? 'default';
    }
}

/**
 * Effective SMTP transport for one user, or the instance transport when no user
 * is given (password resets, verification mail and other system email).
 *
 * @param SQLite3  $db
 * @param int|null $userId
 * @return array Result structure, additionally carrying mode, enabled and other_emails.
 */
function wallos_build_effective_smtp_config($db, $userId = null)
{
    if ($userId === null) {
        $config = wallos_get_instance_smtp_config($db);
        $config['values']['enabled'] = 1;
        $config['values']['other_emails'] = '';

        return $config;
    }

    $stmt = $db->prepare('SELECT * FROM email_notifications WHERE user_id = :userId LIMIT 1');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    $row = $row ?: [];

    // Without the mode column — a database that has not run the migration yet —
    // a stored SMTP host is what marks the row as a custom transport.
    $legacyMode = trim((string) ($row['smtp_address'] ?? '')) !== '' ? 'custom' : 'instance';
    $mode = wallos_normalize_mode($row['smtp_mode'] ?? $legacyMode);

    if ($mode === 'custom') {
        $config = wallos_config_result();
        $config['mode'] = 'custom';

        wallos_config_set($config, 'host', trim((string) ($row['smtp_address'] ?? '')), 'user');
        wallos_config_set($config, 'port', (int) ($row['smtp_port'] ?? 587), 'user');
        wallos_config_set($config, 'encryption', (string) ($row['encryption'] ?? 'tls'), 'user');
        wallos_config_set($config, 'username', (string) ($row['smtp_username'] ?? ''), 'user');
        wallos_config_set($config, 'password', (string) ($row['smtp_password'] ?? ''), 'user');
        wallos_config_set($config, 'from_email', (string) ($row['from_email'] ?? ''), 'user');
        wallos_config_set($config, 'from_name', '', 'default');

        wallos_validate_smtp_config($config);
    } else {
        $config = wallos_get_instance_smtp_config($db);
        $config['mode'] = 'instance';

        if (!$config['valid']) {
            wallos_config_add_note($config, 'Instance SMTP is not configured.');
        }
    }

    $config['values']['enabled'] = (int) ($row['enabled'] ?? 0);
    $config['values']['other_emails'] = (string) ($row['other_emails'] ?? '');

    return $config;
}

/**
 * API representation of an effective SMTP transport.
 *
 * Instance credentials belong to the administrator: ordinary users learn that
 * the transport exists and how it connects, never who it authenticates as.
 *
 * @param array $config Result of wallos_get_effective_smtp_config().
 * @return array
 */
function wallos_smtp_public_payload($config)
{
    $payload = [
        'mode' => $config['mode'],
        'host' => $config['values']['host'] ?? '',
        'port' => (int) ($config['values']['port'] ?? 0),
        'encryption' => $config['values']['encryption'] ?? 'tls',
        'from_email' => $config['values']['from_email'] ?? '',
        'password' => wallos_secret_status($config, 'password'),
        'valid' => (bool) $config['valid'],
    ];

    if ($config['mode'] === 'custom') {
        $payload['username'] = $config['values']['username'] ?? '';
    }

    return $payload;
}

/**
 * Builds a custom SMTP configuration from submitted form values, using the same
 * normalisation and validation as a stored transport. Save and test endpoints
 * share this so that a test proves what saving would store.
 *
 * @param array $data Request payload with the smtp* keys used by the settings form.
 * @return array Result structure.
 */
function wallos_smtp_config_from_input($data)
{
    $config = wallos_config_result();
    $config['mode'] = 'custom';

    wallos_config_set($config, 'host', trim((string) ($data['smtpaddress'] ?? '')), 'user');
    wallos_config_set($config, 'port', (int) ($data['smtpport'] ?? 0), 'user');
    wallos_config_set($config, 'encryption', (string) ($data['encryption'] ?? 'tls'), 'user');
    wallos_config_set($config, 'username', (string) ($data['smtpusername'] ?? ''), 'user');
    wallos_config_set($config, 'password', (string) ($data['smtppassword'] ?? ''), 'user');
    wallos_config_set($config, 'from_email', trim((string) ($data['fromemail'] ?? '')), 'user');
    wallos_config_set($config, 'from_name', '', 'default');

    wallos_validate_smtp_config($config);

    return $config;
}

/* -------------------------------------------------------------------------
   Currency exchange provider
   ------------------------------------------------------------------------- */

/**
 * Maps the provider spellings accepted in the environment onto the provider ids
 * Wallos stores (0 = fixer.io, 1 = apilayer.com).
 *
 * @param mixed $value
 * @return int|null
 */
function wallos_parse_currency_provider($value)
{
    $normalized = strtolower(trim((string) $value));

    if (in_array($normalized, ['0', 'fixer', 'fixer.io'], true)) {
        return 0;
    }

    if (in_array($normalized, ['1', 'apilayer', 'apilayer.com'], true)) {
        return 1;
    }

    return null;
}

/**
 * @param SQLite3 $db
 * @return array Result structure with provider and api_key.
 */
function wallos_build_instance_currency_config($db)
{
    $config = wallos_config_result();
    $config['mode'] = 'instance';

    $instance = wallos_get_instance_settings($db, 'currency');

    if (wallos_env_has('WALLOS_CURRENCY_PROVIDER')) {
        $provider = wallos_parse_currency_provider(wallos_env('WALLOS_CURRENCY_PROVIDER'));
        if ($provider === null) {
            $config['valid'] = false;
            wallos_config_add_note($config, 'Unsupported value in WALLOS_CURRENCY_PROVIDER. Use fixer or apilayer.');
            $provider = 0;
        }
        wallos_config_set($config, 'provider', $provider, 'environment', 'WALLOS_CURRENCY_PROVIDER');
    } elseif (isset($instance['provider']) && $instance['provider'] !== '') {
        wallos_config_set($config, 'provider', (int) wallos_parse_currency_provider($instance['provider']), 'admin');
    } else {
        wallos_config_set($config, 'provider', 0, 'default');
    }

    if (!wallos_apply_env_secret($config, 'api_key', 'WALLOS_CURRENCY_API_KEY')) {
        $apiKey = (string) ($instance['api_key'] ?? '');
        wallos_config_set($config, 'api_key', $apiKey, $apiKey !== '' ? 'admin' : 'default');
    }

    if (trim((string) $config['values']['api_key']) === '') {
        $config['valid'] = false;
        wallos_config_add_note($config, 'Instance currency provider API key is not configured.');
    }

    return $config;
}

/**
 * Effective currency provider credentials for one user.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return array Result structure with provider and api_key.
 */
function wallos_build_effective_currency_config($db, $userId)
{
    $stmt = $db->prepare('SELECT * FROM fixer WHERE user_id = :userId LIMIT 1');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    $row = $row ?: [];

    // Without the mode column — a database that has not run the migration yet —
    // a stored key is what marks the row as custom credentials.
    $legacyMode = trim((string) ($row['api_key'] ?? '')) !== '' ? 'custom' : 'instance';
    $mode = wallos_normalize_mode($row['provider_mode'] ?? $legacyMode);

    if ($mode !== 'custom') {
        $config = wallos_get_instance_currency_config($db);
        $config['mode'] = 'instance';

        return $config;
    }

    $config = wallos_config_result();
    $config['mode'] = 'custom';

    wallos_config_set($config, 'provider', (int) ($row['provider'] ?? 0), 'user');
    wallos_config_set($config, 'api_key', (string) ($row['api_key'] ?? ''), 'user');

    if (trim((string) $config['values']['api_key']) === '') {
        $config['valid'] = false;
        wallos_config_add_note($config, 'Custom currency provider API key is not configured.');
    }

    return $config;
}

/**
 * Builds a custom currency configuration from submitted form values so that
 * validating, testing and storing a key all use the same structure.
 *
 * @param mixed $provider
 * @param mixed $apiKey
 * @return array Result structure.
 */
function wallos_currency_config_from_input($provider, $apiKey)
{
    $config = wallos_config_result();
    $config['mode'] = 'custom';

    $parsedProvider = wallos_parse_currency_provider($provider);
    if ($parsedProvider === null) {
        $config['valid'] = false;
        wallos_config_add_note($config, 'Unsupported currency provider.');
        $parsedProvider = 0;
    }

    wallos_config_set($config, 'provider', $parsedProvider, 'user');
    wallos_config_set($config, 'api_key', trim((string) $apiKey), 'user');

    if ($config['values']['api_key'] === '') {
        $config['valid'] = false;
        wallos_config_add_note($config, 'Custom currency provider API key is not configured.');
    }

    return $config;
}

/* -------------------------------------------------------------------------
   AI provider
   ------------------------------------------------------------------------- */

/**
 * Instance AI configuration with validation applied.
 *
 * @param SQLite3 $db
 * @return array Result structure with type, api_key, url and model.
 */
function wallos_build_validated_instance_ai_config($db)
{
    $config = wallos_build_instance_ai_config($db);
    wallos_validate_ai_config($config);

    return $config;
}

/**
 * Resolves the instance AI values without validating them yet, so that a user
 * model override can be applied before validation runs.
 *
 * @param SQLite3 $db
 * @return array Result structure.
 */
function wallos_build_instance_ai_config($db)
{
    $config = wallos_config_result();
    $config['mode'] = 'instance';

    $instance = wallos_get_instance_settings($db, 'ai');

    if (wallos_env_has('WALLOS_AI_PROVIDER')) {
        $type = strtolower(trim((string) wallos_env('WALLOS_AI_PROVIDER')));
        if (!in_array($type, WALLOS_AI_PROVIDERS, true)) {
            $config['valid'] = false;
            wallos_config_add_note($config, 'Unsupported value in WALLOS_AI_PROVIDER: ' . $type);
        }
        wallos_config_set($config, 'type', $type, 'environment', 'WALLOS_AI_PROVIDER');
    } elseif (!empty($instance['provider'])) {
        wallos_config_set($config, 'type', (string) $instance['provider'], 'admin');
    } else {
        wallos_config_set($config, 'type', '', 'default');
    }

    foreach ([['url', 'WALLOS_AI_BASE_URL', 'base_url'], ['model', 'WALLOS_AI_MODEL', 'model']] as $definition) {
        [$field, $variable, $instanceKey] = $definition;

        if (wallos_env_has($variable)) {
            wallos_config_set($config, $field, trim((string) wallos_env($variable)), 'environment', $variable);
        } elseif (!empty($instance[$instanceKey])) {
            wallos_config_set($config, $field, (string) $instance[$instanceKey], 'admin');
        } else {
            wallos_config_set($config, $field, '', 'default');
        }
    }

    if (!wallos_apply_env_secret($config, 'api_key', 'WALLOS_AI_API_KEY')) {
        $apiKey = (string) ($instance['api_key'] ?? '');
        wallos_config_set($config, 'api_key', $apiKey, $apiKey !== '' ? 'admin' : 'default');
    }

    return $config;
}

/**
 * @param array $config Result structure, modified in place.
 */
function wallos_validate_ai_config(&$config)
{
    $type = (string) ($config['values']['type'] ?? '');

    if ($type === '') {
        $config['valid'] = false;
        wallos_config_add_note($config, 'AI provider is not configured.');

        return;
    }

    if (!in_array($type, WALLOS_AI_PROVIDERS, true)) {
        $config['valid'] = false;
        wallos_config_add_note($config, 'Unsupported AI provider: ' . $type);

        return;
    }

    if ($type === 'ollama') {
        // Ollama authenticates through the network, never with an API key.
        $config['values']['api_key'] = '';
    }

    if (in_array($type, WALLOS_AI_HOST_PROVIDERS, true)) {
        if (trim((string) ($config['values']['url'] ?? '')) === '') {
            $config['valid'] = false;
            wallos_config_add_note($config, 'AI provider base URL is not configured.');
        }
    } else {
        $config['values']['url'] = '';

        if (trim((string) ($config['values']['api_key'] ?? '')) === '') {
            $config['valid'] = false;
            wallos_config_add_note($config, 'AI provider API key is not configured.');
        }
    }

    if (trim((string) ($config['values']['model'] ?? '')) === '') {
        $config['valid'] = false;
        wallos_config_add_note($config, 'AI model is not configured.');
    }
}

/**
 * Effective AI configuration for one user.
 *
 * Provider infrastructure may be inherited from the instance, while enabling
 * recommendations, the run schedule and an optional model override always stay
 * with the user.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return array Result structure with type, api_key, url, model, enabled and run_schedule.
 */
function wallos_build_effective_ai_config($db, $userId)
{
    $row = [];
    $tableExists = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='ai_settings'");

    if ($tableExists) {
        $stmt = $db->prepare('SELECT * FROM ai_settings WHERE user_id = :userId LIMIT 1');
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = ($result ? $result->fetchArray(SQLITE3_ASSOC) : false) ?: [];
    }

    // Without the mode column — a database that has not run the migration yet —
    // a stored provider is what marks the row as a custom provider.
    $legacyMode = trim((string) ($row['type'] ?? '')) !== '' ? 'custom' : 'instance';
    $mode = wallos_normalize_mode($row['provider_mode'] ?? $legacyMode);

    if ($mode === 'custom') {
        $config = wallos_config_result();
        $config['mode'] = 'custom';

        wallos_config_set($config, 'type', (string) ($row['type'] ?? ''), 'user');
        wallos_config_set($config, 'api_key', (string) ($row['api_key'] ?? ''), 'user');
        wallos_config_set($config, 'url', (string) ($row['url'] ?? ''), 'user');
        wallos_config_set($config, 'model', (string) ($row['model'] ?? ''), 'user');

        wallos_validate_ai_config($config);
    } else {
        $config = wallos_build_instance_ai_config($db);
        $config['mode'] = 'instance';

        // The model is the one instance value a user may override.
        $userModel = trim((string) ($row['model'] ?? ''));
        if ($userModel !== '') {
            wallos_config_set($config, 'model', $userModel, 'user');
        }

        wallos_validate_ai_config($config);
    }

    $config['values']['enabled'] = (int) ($row['enabled'] ?? 0);
    $config['values']['run_schedule'] = (string) ($row['run_schedule'] ?? 'manual');

    return $config;
}

/**
 * Shapes an effective AI configuration like the ai_settings row that
 * ai_complete() expects.
 *
 * @param array $config Result structure.
 * @return array
 */
function wallos_ai_settings_from_config($config)
{
    return [
        'type' => $config['values']['type'] ?? '',
        'enabled' => $config['values']['enabled'] ?? 0,
        'api_key' => $config['values']['api_key'] ?? '',
        'url' => $config['values']['url'] ?? '',
        'model' => $config['values']['model'] ?? '',
    ];
}

/* -------------------------------------------------------------------------
   Memoized entry points

   Resolution is deterministic within one request or job, so each of these is
   evaluated once. Everything above stays free of caching concerns.
   ------------------------------------------------------------------------- */

/**
 * @param SQLite3 $db
 * @param string  $integration
 * @return array<string, string>
 */
function wallos_get_instance_settings($db, $integration)
{
    return wallos_config_cached($db, 'instance_settings:' . $integration,
        fn() => wallos_build_instance_settings($db, $integration));
}

/**
 * @param SQLite3 $db
 * @return array Result structure.
 */
function wallos_get_instance_smtp_config($db)
{
    return wallos_config_cached($db, 'smtp:instance',
        fn() => wallos_build_instance_smtp_config($db));
}

/**
 * @param SQLite3  $db
 * @param int|null $userId
 * @return array Result structure.
 */
function wallos_get_effective_smtp_config($db, $userId = null)
{
    return wallos_config_cached($db, 'smtp:' . ($userId === null ? 'system' : (int) $userId),
        fn() => wallos_build_effective_smtp_config($db, $userId));
}

/**
 * @param SQLite3 $db
 * @return array Result structure.
 */
function wallos_get_instance_currency_config($db)
{
    return wallos_config_cached($db, 'currency:instance',
        fn() => wallos_build_instance_currency_config($db));
}

/**
 * @param SQLite3 $db
 * @param int     $userId
 * @return array Result structure.
 */
function wallos_get_effective_currency_config($db, $userId)
{
    return wallos_config_cached($db, 'currency:' . (int) $userId,
        fn() => wallos_build_effective_currency_config($db, $userId));
}

/**
 * @param SQLite3 $db
 * @return array Result structure.
 */
function wallos_get_instance_ai_config($db)
{
    return wallos_config_cached($db, 'ai:instance',
        fn() => wallos_build_validated_instance_ai_config($db));
}

/**
 * @param SQLite3 $db
 * @param int     $userId
 * @return array Result structure.
 */
function wallos_get_effective_ai_config($db, $userId)
{
    return wallos_config_cached($db, 'ai:' . (int) $userId,
        fn() => wallos_build_effective_ai_config($db, $userId));
}
