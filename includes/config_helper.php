<?php
/*
  Generic configuration primitives shared by every environment-managed setting.

  These are deliberately provider agnostic: they only know how to read an
  environment variable, how to read a secret file, and how to record where an
  effective value came from. The integration specific resolution lives in
  includes/integration_config.php.
*/

/**
 * Reads an environment variable from the places PHP may expose it.
 *
 * @param string $name
 * @return string|null Null when the variable is not set at all.
 */
function wallos_env($name)
{
    $value = getenv($name);
    if ($value !== false) {
        return $value;
    }

    if (array_key_exists($name, $_ENV)) {
        return $_ENV[$name];
    }

    if (array_key_exists($name, $_SERVER)) {
        return $_SERVER[$name];
    }

    return null;
}

/**
 * @param string $name
 * @return bool
 */
function wallos_env_has($name)
{
    return wallos_env($name) !== null;
}

/**
 * Parses the boolean spellings accepted throughout the configuration layer.
 *
 * @param mixed $value
 * @return int|null 1, 0, or null when the value cannot be parsed.
 */
function wallos_parse_boolean($value)
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return 1;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return 0;
    }

    return null;
}

/**
 * Reads a secret from a file mounted by the deployment platform (Docker
 * Secrets, Kubernetes Secrets, Podman Secrets or any other bind mount).
 *
 * Trailing newlines are stripped because most secret tooling appends one;
 * everything else — including internal spaces — is preserved verbatim.
 *
 * @param string $path
 * @return array{value: string|null, error: string|null}
 */
function wallos_read_secret_file($path)
{
    $path = trim((string) $path);

    if ($path === '') {
        return ['value' => null, 'error' => 'Secret file path is empty.'];
    }

    if (!is_readable($path)) {
        return ['value' => null, 'error' => 'Secret file is not readable: ' . $path];
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        return ['value' => null, 'error' => 'Secret file could not be read: ' . $path];
    }

    return ['value' => rtrim($contents, "\r\n"), 'error' => null];
}

/**
 * Resolves a secret from the environment, preferring the *_FILE variant.
 *
 * A configured but unreadable secret file is reported as an error instead of
 * falling through to the plain variable or the database. Silently using an
 * older credential would hide rotation mistakes.
 *
 * @param string $name Name of the plain variable, e.g. WALLOS_SMTP_PASSWORD.
 * @return array{value: string|null, source: string|null, variable: string|null, managed: bool, error: string|null}
 */
function wallos_env_secret($name)
{
    $fileVariable = $name . '_FILE';

    if (wallos_env_has($fileVariable)) {
        $secretFile = wallos_read_secret_file(wallos_env($fileVariable));

        return [
            'value' => $secretFile['value'],
            'source' => 'environment_file',
            'variable' => $fileVariable,
            'managed' => true,
            'error' => $secretFile['error'],
        ];
    }

    if (wallos_env_has($name)) {
        return [
            'value' => (string) wallos_env($name),
            'source' => 'environment',
            'variable' => $name,
            'managed' => true,
            'error' => null,
        ];
    }

    return [
        'value' => null,
        'source' => null,
        'variable' => null,
        'managed' => false,
        'error' => null,
    ];
}

/**
 * Empty result structure used by every getEffective*Config() resolver.
 *
 * @return array{values: array, source: array, managed: array, managed_by: array, mode: string, valid: bool, notes: string[]}
 */
function wallos_config_result()
{
    return [
        'values' => [],
        'source' => [],
        'managed' => [],
        'managed_by' => [],
        'mode' => 'instance',
        'valid' => true,
        'notes' => [],
    ];
}

/**
 * Records one effective value together with the source it came from.
 *
 * @param array  $config   Result structure, modified in place.
 * @param string $field
 * @param mixed  $value
 * @param string $source   user, environment_file, environment, admin, legacy_user or default.
 * @param string|null $variable Environment variable that manages the field, when applicable.
 */
function wallos_config_set(&$config, $field, $value, $source, $variable = null)
{
    $config['values'][$field] = $value;
    $config['source'][$field] = $source;
    $config['managed'][$field] = in_array($source, ['environment', 'environment_file'], true);

    if ($variable !== null) {
        $config['managed_by'][$field] = $variable;
    }
}

/**
 * @param array  $config Result structure, modified in place.
 * @param string $note
 */
function wallos_config_add_note(&$config, $note)
{
    if ($note !== null && $note !== '' && !in_array($note, $config['notes'], true)) {
        $config['notes'][] = $note;
    }
}

/**
 * Secret representation for APIs and templates. The value itself never leaves
 * the server — callers only learn whether a secret exists and who owns it.
 *
 * @param array  $config Result structure.
 * @param string $field
 * @return array{configured: bool, managed: bool, source: string|null}
 */
function wallos_secret_status($config, $field)
{
    $value = $config['values'][$field] ?? null;

    return [
        'configured' => is_string($value) && $value !== '',
        'managed' => !empty($config['managed'][$field]),
        'source' => $config['source'][$field] ?? null,
    ];
}

/**
 * Renders the admin hints for a resolved configuration: which fields the
 * environment owns, and which problems were found while resolving them. Secret
 * values are never part of this output.
 *
 * @param array $config Result structure.
 * @param array $i18n
 * @return string HTML
 */
function wallos_render_managed_notes($config, $i18n)
{
    $html = '';

    if (!empty($config['managed_by'])) {
        $fields = [];
        foreach ($config['managed_by'] as $field => $variable) {
            if (!empty($config['managed'][$field])) {
                $fields[] = htmlspecialchars($variable);
            }
        }

        if ($fields) {
            $html .= '<p><i class="fa-solid fa-circle-info"></i> '
                . translate('managed_by_environment', $i18n) . ' <span>'
                . implode(', ', array_unique($fields)) . '</span></p>';
        }
    }

    foreach ($config['notes'] as $note) {
        $html .= '<p><i class="fa-solid fa-triangle-exclamation"></i> ' . htmlspecialchars($note) . '</p>';
    }

    return $html;
}

/**
 * Renders the attributes that mark an input as environment managed. Mirrors the
 * behaviour the OIDC section of the admin page already uses.
 *
 * @param array  $config Result structure.
 * @param string $field
 * @return string
 */
function wallos_managed_input_attrs($config, $field)
{
    if (empty($config['managed'][$field])) {
        return '';
    }

    $variable = $config['managed_by'][$field] ?? '';

    return 'disabled data-managed-by="' . htmlspecialchars($variable) . '"';
}
