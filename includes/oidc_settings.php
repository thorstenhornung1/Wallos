<?php

require_once __DIR__ . '/config_helper.php';

function wallos_get_oidc_defaults()
{
    return [
        'name' => '',
        'client_id' => '',
        'client_secret' => '',
        'authorization_url' => '',
        'token_url' => '',
        'user_info_url' => '',
        'redirect_url' => '',
        'logout_url' => '',
        'user_identifier_field' => 'sub',
        'scopes' => 'openid email profile',
        'auth_style' => 'auto',
        'auto_create_user' => 0,
        'password_login_disabled' => 0,
        'require_email_verified' => 1,
        // Which claim, if any, grants the administrator role. Configurable in
        // the admin interface like the rest of the OIDC settings, and
        // overridable by OIDC_ADMIN_CLAIM / OIDC_ADMIN_VALUE like the rest.
        'admin_claim' => '',
        'admin_value' => '',
        // Where the provider returns the user after ending the session. Empty
        // means it is derived from the redirect URL.
        'post_logout_redirect_url' => '',
        // The provider's base URL. Set it and Wallos reads the rest —
        // endpoints, signing keys, end-session URL — from what the provider
        // publishes at /.well-known/openid-configuration.
        'issuer' => '',
    ];
}

function wallos_get_oidc_env_value($name)
{
    return wallos_env($name);
}

function wallos_has_oidc_env_value($name)
{
    return wallos_env_has($name);
}

function wallos_parse_oidc_boolean($value)
{
    return wallos_parse_boolean($value);
}

function wallos_get_db_oidc_settings($db)
{
    $settings = wallos_get_oidc_defaults();

    $stmt = $db->prepare('SELECT * FROM oauth_settings WHERE id = 1');
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if ($row) {
        unset($row['id']);
        $settings = array_merge($settings, $row);
    }

    // Column added via ALTER TABLE may return NULL for existing rows in SQLite
    $settings['require_email_verified'] = $settings['require_email_verified'] ?? 1;

    return $settings;
}

function wallos_get_db_oidc_enabled($db)
{
    $stmt = $db->prepare('SELECT oidc_oauth_enabled FROM admin WHERE id = 1');
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return $row ? (int) $row['oidc_oauth_enabled'] : 0;
}

/**
 * How long a discovery document is reused before being fetched again.
 *
 * The document describes endpoints and key locations, which change rarely and
 * are picked up within the hour when they do. Fetching it per request is what
 * the cache exists to stop: wallos_get_effective_oidc_configuration() runs on
 * every login page render, so without this every visitor waits on the identity
 * provider — up to the ten second timeout when it is unwell.
 */
define('WALLOS_OIDC_DISCOVERY_TTL', 3600);

/**
 * Discovery, reading through a cache in the database.
 *
 * A failed refresh falls back to the cached copy however old it is. A provider
 * that is briefly unreachable should not take the login page down with it, and
 * yesterday's endpoints are almost certainly still today's.
 *
 * @param SQLite3 $db
 * @param string  $issuer
 * @return array [document|null, error|null]
 */
function wallos_get_oidc_discovery_document($db, $issuer)
{
    $issuer = rtrim(trim((string) $issuer), '/');
    if ($issuer === '') {
        return [null, 'OIDC issuer is empty.'];
    }

    $cached = null;
    $stmt = $db->prepare('SELECT document, fetched_at FROM oidc_discovery_cache WHERE issuer = :issuer');
    if ($stmt !== false) {
        $stmt->bindValue(':issuer', $issuer, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result === false ? false : $result->fetchArray(SQLITE3_ASSOC);

        if ($row) {
            $document = json_decode($row['document'], true);
            if (is_array($document)) {
                $cached = $document;
                if ((time() - (int) $row['fetched_at']) < WALLOS_OIDC_DISCOVERY_TTL) {
                    return [$cached, null];
                }
            }
        }
    }

    [$document, $error] = wallos_fetch_oidc_discovery_document($issuer);

    if ($document === null) {
        // Stale beats absent: the alternative is a login page that fails
        // because the provider is having a bad minute.
        return $cached !== null ? [$cached, null] : [null, $error];
    }

    // An upsert on the issuer, which is the table's primary key. SQLite would
    // spell this with a REPLACE, which PostgreSQL has no equivalent of and
    // which deletes the row and inserts a new one rather than updating it.
    $stmt = $db->prepare('INSERT INTO oidc_discovery_cache (issuer, document, fetched_at)
                          VALUES (:issuer, :document, :fetchedAt)
                          ON CONFLICT (issuer) DO UPDATE
                          SET document = excluded.document, fetched_at = excluded.fetched_at');
    if ($stmt !== false) {
        $stmt->bindValue(':issuer', $issuer, SQLITE3_TEXT);
        $stmt->bindValue(':document', json_encode($document), SQLITE3_TEXT);
        $stmt->bindValue(':fetchedAt', time(), SQLITE3_INTEGER);
        $stmt->execute();
    }

    return [$document, null];
}

function wallos_fetch_oidc_discovery_document($issuer)
{
    $issuer = rtrim(trim((string) $issuer), '/');
    if ($issuer === '') {
        return [null, 'OIDC_ISSUER is empty.'];
    }

    $url = $issuer . '/.well-known/openid-configuration';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        $error = $curlError !== '' ? $curlError : 'HTTP ' . $httpCode;
        return [null, 'OIDC discovery failed for ' . $url . ': ' . $error];
    }

    $document = json_decode($response, true);
    if (!is_array($document)) {
        return [null, 'OIDC discovery returned invalid JSON for ' . $url . '.'];
    }

    return [$document, null];
}

function wallos_get_effective_oidc_configuration($db)
{
    $settings = wallos_get_db_oidc_settings($db);
    $managedFields = [];
    $notes = [];
    $discoveryDocument = null;

    $enabled = wallos_get_db_oidc_enabled($db);
    if (wallos_has_oidc_env_value('OIDC_ENABLED')) {
        $parsedEnabled = wallos_parse_oidc_boolean(wallos_get_oidc_env_value('OIDC_ENABLED'));
        if ($parsedEnabled !== null) {
            $enabled = $parsedEnabled;
            $managedFields['enabled'] = 'OIDC_ENABLED';
        } else {
            $notes[] = 'Ignoring invalid boolean value in OIDC_ENABLED.';
        }
    }

    if (wallos_has_oidc_env_value('OIDC_CLIENT_SECRET_FILE')) {
        $managedFields['client_secret'] = 'OIDC_CLIENT_SECRET_FILE';
        $settings['client_secret'] = '';
        $secretFile = trim((string) wallos_get_oidc_env_value('OIDC_CLIENT_SECRET_FILE'));

        if ($secretFile === '') {
            $notes[] = 'OIDC_CLIENT_SECRET_FILE is empty.';
        } else {
            $secret = wallos_read_secret_file($secretFile);
            if ($secret['error'] === null) {
                $settings['client_secret'] = $secret['value'];
            } else {
                $notes[] = 'OIDC client secret file is not readable: ' . $secretFile;
            }
        }
    } elseif (wallos_has_oidc_env_value('OIDC_CLIENT_SECRET')) {
        $settings['client_secret'] = (string) wallos_get_oidc_env_value('OIDC_CLIENT_SECRET');
        $managedFields['client_secret'] = 'OIDC_CLIENT_SECRET';
    }

    if (wallos_has_oidc_env_value('OIDC_AUTO_CREATE_USER')) {
        $parsedValue = wallos_parse_oidc_boolean(wallos_get_oidc_env_value('OIDC_AUTO_CREATE_USER'));
        if ($parsedValue !== null) {
            $settings['auto_create_user'] = $parsedValue;
            $managedFields['auto_create_user'] = 'OIDC_AUTO_CREATE_USER';
        } else {
            $notes[] = 'Ignoring invalid boolean value in OIDC_AUTO_CREATE_USER.';
        }
    }

    if (wallos_has_oidc_env_value('OIDC_DISABLE_PASSWORD_LOGIN')) {
        $parsedValue = wallos_parse_oidc_boolean(wallos_get_oidc_env_value('OIDC_DISABLE_PASSWORD_LOGIN'));
        if ($parsedValue !== null) {
            $settings['password_login_disabled'] = $parsedValue;
            $managedFields['password_login_disabled'] = 'OIDC_DISABLE_PASSWORD_LOGIN';
        } else {
            $notes[] = 'Ignoring invalid boolean value in OIDC_DISABLE_PASSWORD_LOGIN.';
        }
    }

    if (wallos_has_oidc_env_value('OIDC_REQUIRE_EMAIL_VERIFIED')) {
        $parsedValue = wallos_parse_oidc_boolean(wallos_get_oidc_env_value('OIDC_REQUIRE_EMAIL_VERIFIED'));
        if ($parsedValue !== null) {
            $settings['require_email_verified'] = $parsedValue;
            $managedFields['require_email_verified'] = 'OIDC_REQUIRE_EMAIL_VERIFIED';
        } else {
            $notes[] = 'Ignoring invalid boolean value in OIDC_REQUIRE_EMAIL_VERIFIED.';
        }
    }

    foreach (['admin_claim' => 'OIDC_ADMIN_CLAIM', 'admin_value' => 'OIDC_ADMIN_VALUE'] as $field => $variable) {
        if (wallos_has_oidc_env_value($variable)) {
            $settings[$field] = trim((string) wallos_get_oidc_env_value($variable));
            $managedFields[$field] = $variable;
        }
    }

    // Half a mapping decides nothing, and silently ignoring it would leave an
    // operator believing admin rights are being synchronised when they are not.
    $claimSet = ($settings['admin_claim'] ?? '') !== '';
    $valueSet = ($settings['admin_value'] ?? '') !== '';
    if ($claimSet !== $valueSet) {
        $notes[] = $claimSet
            ? 'OIDC_ADMIN_CLAIM is set without OIDC_ADMIN_VALUE, so no admin role is derived from OIDC.'
            : 'OIDC_ADMIN_VALUE is set without OIDC_ADMIN_CLAIM, so no admin role is derived from OIDC.';
    }

    // An issuer from the environment takes precedence, as everywhere else, but
    // a stored one works just as well. Discovery used to run only for the
    // environment variable, which left an installation configured through the
    // admin interface without a discovery document — and so without the signing
    // keys back-channel logout needs, and without an end-session endpoint.
    $issuerFromEnvironment = wallos_has_oidc_env_value('OIDC_ISSUER');
    $issuer = $issuerFromEnvironment
        ? (string) wallos_get_oidc_env_value('OIDC_ISSUER')
        : (string) ($settings['issuer'] ?? '');

    if ($issuerFromEnvironment) {
        $settings['issuer'] = $issuer;
        $managedFields['issuer'] = 'OIDC_ISSUER';

        // The environment owning the issuer means the environment owns the
        // endpoints derived from it; they are replaced rather than merged.
        foreach (['authorization_url', 'token_url', 'user_info_url'] as $field) {
            $settings[$field] = '';
            $managedFields[$field] = 'OIDC_ISSUER';
        }
    }

    if ($issuerFromEnvironment || trim($issuer) !== '') {
        if (trim($issuer) !== '') {
            [$discoveryDocument, $discoveryError] = wallos_get_oidc_discovery_document($db, $issuer);
            if ($discoveryError !== null) {
                $notes[] = $discoveryError;
            } elseif ($discoveryDocument !== null) {
                $discoveryMap = [
                    'authorization_url' => 'authorization_endpoint',
                    'token_url' => 'token_endpoint',
                    'user_info_url' => 'userinfo_endpoint',
                ];

                foreach ($discoveryMap as $field => $documentField) {
                    if (!isset($discoveryDocument[$documentField])) {
                        continue;
                    }
                    // A stored issuer fills in only what was left blank, so an
                    // operator whose provider needs a hand-written endpoint can
                    // still write one. An issuer from the environment replaces
                    // them, which is the behaviour that already existed.
                    if ($issuerFromEnvironment || trim((string) $settings[$field]) === '') {
                        $settings[$field] = $discoveryDocument[$documentField];
                    }
                }
            }
        } elseif ($issuerFromEnvironment) {
            $notes[] = 'Ignoring empty OIDC_ISSUER value.';
        }
    }

    $envFieldMap = [
        'name' => 'OIDC_PROVIDER_NAME',
        'client_id' => 'OIDC_CLIENT_ID',
        'authorization_url' => 'OIDC_AUTH_URL',
        'token_url' => 'OIDC_TOKEN_URL',
        'user_info_url' => 'OIDC_USERINFO_URL',
        'redirect_url' => 'OIDC_REDIRECT_URL',
        'logout_url' => 'OIDC_LOGOUT_URL',
        'user_identifier_field' => 'OIDC_USER_IDENTIFIER',
        'scopes' => 'OIDC_SCOPES',
        'post_logout_redirect_url' => 'OIDC_POST_LOGOUT_REDIRECT_URL',
    ];

    foreach ($envFieldMap as $field => $envVar) {
        if (wallos_has_oidc_env_value($envVar)) {
            $settings[$field] = (string) wallos_get_oidc_env_value($envVar);
            $managedFields[$field] = $envVar;
        }
    }

    $requiredFields = [
        'client_id',
        'client_secret',
        'authorization_url',
        'token_url',
        'user_info_url',
        'redirect_url',
        'user_identifier_field',
    ];
    $isConfigured = true;
    foreach ($requiredFields as $field) {
        if (trim((string) $settings[$field]) === '') {
            $isConfigured = false;
            break;
        }
    }

    return [
        'enabled' => (int) $enabled,
        'settings' => $settings,
        'managed_fields' => $managedFields,
        'notes' => $notes,
        'discovery_document' => $discoveryDocument,
        'is_configured' => $isConfigured,
    ];
}

/**
 * The OIDC settings that can be written, and how each is normalised.
 *
 * One list, because there are two save paths — the admin interface posts to
 * endpoints/admin/saveoidcsettings.php and the API to
 * api/admin/set_oidc_settings.php. They had drifted: the interface trimmed
 * every text field, the API trimmed none, so a client id pasted with a trailing
 * space through the API was stored with it and every later handshake failed as
 * "invalid client" with nothing pointing at the cause.
 *
 * @return array<string, string> field => 'text' | 'int'
 */
function wallos_oidc_writable_fields()
{
    return [
        'name' => 'text',
        'client_id' => 'text',
        'client_secret' => 'text',
        'authorization_url' => 'text',
        'token_url' => 'text',
        'user_info_url' => 'text',
        'redirect_url' => 'text',
        'logout_url' => 'text',
        'user_identifier_field' => 'text',
        'scopes' => 'text',
        'auth_style' => 'text',
        'auto_create_user' => 'int',
        'password_login_disabled' => 'int',
        'require_email_verified' => 'int',
        'admin_claim' => 'text',
        'admin_value' => 'text',
        'post_logout_redirect_url' => 'text',
        'issuer' => 'text',
    ];
}

/**
 * Write OIDC settings, whatever asked for the write.
 *
 * A field absent from $submitted keeps its stored value. Present means write,
 * including writing an empty string — clearing a field has to stay possible.
 * The interface always submits every field, so this only changes what a partial
 * API request does, and leaving values alone is the safer reading of silence.
 *
 * Fields managed by an environment variable are skipped: the environment is
 * the authority for them, and accepting an edit that the next page load
 * discards would be worse than refusing it.
 *
 * @param SQLite3 $db
 * @param array   $submitted     field => value, already keyed by database field
 * @param array   $managedFields field => environment variable name
 * @return array{success: bool, error: string|null, changed: bool}
 */
function wallos_save_oidc_settings($db, $submitted, $managedFields)
{
    $writable = wallos_oidc_writable_fields();
    $settings = wallos_get_db_oidc_settings($db);
    $changed = false;

    foreach ($writable as $field => $type) {
        if (!array_key_exists($field, $submitted) || $submitted[$field] === null) {
            continue;
        }
        if (isset($managedFields[$field])) {
            continue;
        }
        // The secret field is rendered empty rather than pre-filled, so an
        // empty submission means "unchanged", not "clear it". Every other field
        // keeps the general rule that submitting empty clears the value.
        if ($field === 'client_secret' && trim((string) $submitted[$field]) === '') {
            continue;
        }

        $settings[$field] = $type === 'int'
            ? (int) $submitted[$field]
            : trim((string) $submitted[$field]);
        $changed = true;
    }

    if (!$changed) {
        return ['success' => true, 'error' => null, 'changed' => false];
    }

    foreach (['token_url' => 'Token URL', 'user_info_url' => 'User Info URL'] as $field => $label) {
        if ($settings[$field] && validate_oidc_endpoint_url($settings[$field], $db) === false) {
            return [
                'success' => false,
                'error' => 'Security Error: ' . $label . ' must not target link-local or loopback addresses.',
                'changed' => false,
            ];
        }
    }

    $columns = array_keys($writable);
    $exists = (int) $db->querySingle('SELECT COUNT(*) FROM oauth_settings WHERE id = 1') > 0;

    if ($exists) {
        $assignments = [];
        foreach ($columns as $column) {
            $assignments[] = $column . ' = :' . $column;
        }
        $stmt = $db->prepare('UPDATE oauth_settings SET ' . implode(', ', $assignments) . ' WHERE id = 1');
    } else {
        $placeholders = [];
        foreach ($columns as $column) {
            $placeholders[] = ':' . $column;
        }
        $stmt = $db->prepare('INSERT INTO oauth_settings (id, ' . implode(', ', $columns) . ')
                              VALUES (1, ' . implode(', ', $placeholders) . ')');
    }

    if ($stmt === false) {
        return ['success' => false, 'error' => 'Failed to save OIDC configurations.', 'changed' => false];
    }

    foreach ($writable as $field => $type) {
        $stmt->bindValue(
            ':' . $field,
            $type === 'int' ? (int) ($settings[$field] ?? 0) : (string) ($settings[$field] ?? ''),
            $type === 'int' ? SQLITE3_INTEGER : SQLITE3_TEXT
        );
    }

    if ($stmt->execute() === false) {
        return ['success' => false, 'error' => 'Failed to save OIDC configurations.', 'changed' => false];
    }

    return ['success' => true, 'error' => null, 'changed' => true];
}
