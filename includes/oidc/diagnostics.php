<?php
/*
  What Wallos can say about its own OIDC configuration before anyone attempts a
  login.

  Misconfiguring OIDC is normal; five different mistakes producing the same
  silence is not. Everything here answers one question: what does Wallos
  already know that would have saved the administrator a round trip through the
  identity provider's logs?

  wallos_oidc_checks() is deliberately free of I/O so it can be tested against
  constructed discovery documents. wallos_oidc_diagnostics() adds the fetching.
*/

require_once __DIR__ . '/../oidc_settings.php';
require_once __DIR__ . '/../config_helper.php';

const WALLOS_OIDC_OK = 'ok';
const WALLOS_OIDC_WARNING = 'warning';
const WALLOS_OIDC_ERROR = 'error';
const WALLOS_OIDC_UNKNOWN = 'unknown';

/**
 * Builds one finding.
 *
 * @param string $label
 * @param string $status
 * @param string $detail
 * @return array{label: string, status: string, detail: string}
 */
function wallos_oidc_check($label, $status, $detail)
{
    return ['label' => $label, 'status' => $status, 'detail' => $detail];
}


/**
 * Shortens an identifier to something recognisable but not worth re-typing.
 *
 * A client id is not a secret — it travels in the browser's address bar on
 * every login — but this page exists to be pasted into bug reports, and a
 * forty-character random string reads like a credential to everyone looking at
 * the screenshot afterwards.
 *
 * @param string $value
 * @return string
 */
function wallos_oidc_abbreviate($value)
{
    $value = (string) $value;

    if (strlen($value) <= 16) {
        return $value;
    }

    return substr($value, 0, 8) . '…' . substr($value, -4);
}

/**
 * Evaluates an OIDC configuration.
 *
 * No value that could be a credential is placed in a detail string: the client
 * secret is reported as a state, never as a value, and the client id is only
 * echoed because it is not secret and identifying it is often the point.
 *
 * @param array       $configuration Result of wallos_get_effective_oidc_configuration().
 * @param array|null  $discovery     The provider's discovery document, when one was fetched.
 * @param string|null $discoveryError
 * @param int         $provisionedAccounts Accounts that already logged in through this provider.
 * @return array<int, array{label: string, status: string, detail: string}>
 */
function wallos_oidc_checks($configuration, $discovery = null, $discoveryError = null, $provisionedAccounts = 0)
{
    $settings = $configuration['settings'];
    $managed = $configuration['managed_fields'];
    $checks = [];

    $source = function ($field) use ($managed) {
        return isset($managed[$field]) ? ' (managed by ' . $managed[$field] . ')' : '';
    };

    if ((int) $configuration['enabled'] !== 1) {
        $checks[] = wallos_oidc_check('OIDC', WALLOS_OIDC_WARNING,
            'Disabled. Nothing below is in use.');

        return $checks;
    }

    $checks[] = wallos_oidc_check('OIDC', WALLOS_OIDC_OK, 'Enabled' . $source('enabled') . '.');

    // --- credentials --------------------------------------------------------
    $clientId = trim((string) $settings['client_id']);
    $checks[] = $clientId === ''
        ? wallos_oidc_check('Client ID', WALLOS_OIDC_ERROR, 'Not configured.')
        : wallos_oidc_check('Client ID', WALLOS_OIDC_OK,
            wallos_oidc_abbreviate($clientId) . $source('client_id'));

    $secret = (string) $settings['client_secret'];
    $secretNote = implode(' ', $configuration['notes']);

    if (strpos($secretNote, 'secret file is not readable') !== false) {
        $checks[] = wallos_oidc_check('Client secret', WALLOS_OIDC_ERROR,
            'The configured secret file cannot be read.');
    } elseif ($secret === '') {
        $checks[] = wallos_oidc_check('Client secret', WALLOS_OIDC_ERROR, 'Not configured.');
    } else {
        $checks[] = wallos_oidc_check('Client secret', WALLOS_OIDC_OK,
            'Configured' . $source('client_secret') . '.');
    }

    // --- endpoints ----------------------------------------------------------
    $endpointsPresent = trim((string) $settings['authorization_url']) !== ''
        && trim((string) $settings['token_url']) !== ''
        && trim((string) $settings['user_info_url']) !== '';

    if ($discoveryError !== null) {
        $checks[] = wallos_oidc_check('Discovery', WALLOS_OIDC_ERROR, $discoveryError);
    } elseif ($discovery !== null) {
        $checks[] = wallos_oidc_check('Discovery', WALLOS_OIDC_OK,
            'Document fetched from the issuer.');
    } elseif ($endpointsPresent) {
        // Setting the endpoints individually is a perfectly good way to
        // configure this; not using discovery is not a finding.
        $checks[] = wallos_oidc_check('Discovery', WALLOS_OIDC_OK,
            'Not used; the endpoints are configured individually.');
    } else {
        $checks[] = wallos_oidc_check('Discovery', WALLOS_OIDC_WARNING,
            'No issuer configured, so endpoints cannot be discovered and must be set individually.');
    }

    foreach ([
        'authorization_url' => 'Authorization URL',
        'token_url' => 'Token URL',
        'user_info_url' => 'User info URL',
    ] as $field => $label) {
        $value = trim((string) $settings[$field]);
        $checks[] = $value === ''
            ? wallos_oidc_check($label, WALLOS_OIDC_ERROR, 'Not configured and not offered by discovery.')
            : wallos_oidc_check($label, WALLOS_OIDC_OK, $value);
    }

    // --- redirect -----------------------------------------------------------
    $redirect = trim((string) $settings['redirect_url']);

    if ($redirect === '') {
        $checks[] = wallos_oidc_check('Redirect URL', WALLOS_OIDC_ERROR, 'Not configured.');
    } elseif (!preg_match('#^https?://#i', $redirect)) {
        $checks[] = wallos_oidc_check('Redirect URL', WALLOS_OIDC_ERROR,
            'Must be an absolute URL: ' . $redirect);
    } else {
        // The provider has to be configured with exactly this value; Wallos
        // cannot verify that from here, so it says what it expects.
        $checks[] = wallos_oidc_check('Redirect URL', WALLOS_OIDC_OK,
            $redirect . $source('redirect_url')
            . ' — the provider must list exactly this value.');
    }

    // --- email verification -------------------------------------------------
    if (!empty($settings['require_email_verified'])) {
        if ($provisionedAccounts > 0) {
            // An account exists that came through this provider, so it does
            // report verified addresses. Saying "this may reject everyone" to
            // someone it demonstrably has not rejected is noise.
            $checks[] = wallos_oidc_check('Verified email required', WALLOS_OIDC_OK,
                'Yes' . $source('require_email_verified')
                . '. The provider has already produced ' . $provisionedAccounts
                . ' account(s), so it reports verified addresses.');
        } else {
            $checks[] = wallos_oidc_check('Verified email required', WALLOS_OIDC_WARNING,
                'Logins are rejected unless the provider reports email_verified: true. '
                . 'Providers using a default scope mapping often report false. '
                . 'Set OIDC_REQUIRE_EMAIL_VERIFIED=false to accept them.');
        }
    } else {
        $checks[] = wallos_oidc_check('Verified email required', WALLOS_OIDC_OK,
            'No, the provider\'s email_verified claim is not enforced.');
    }

    // --- auto creation ------------------------------------------------------
    $checks[] = !empty($settings['auto_create_user'])
        ? wallos_oidc_check('Create users automatically', WALLOS_OIDC_OK,
            'Yes' . $source('auto_create_user') . '.')
        : wallos_oidc_check('Create users automatically', WALLOS_OIDC_WARNING,
            'No — an authenticated user without a matching Wallos account is rejected.');

    return $checks;
}

/**
 * Evaluates the live configuration, fetching the discovery document when an
 * issuer is configured.
 *
 * @param SQLite3 $db
 * @return array{checks: array, worst: string}
 */
function wallos_oidc_diagnostics($db)
{
    $configuration = wallos_get_effective_oidc_configuration($db);

    $discovery = $configuration['discovery_document'] ?? null;
    $discoveryError = null;

    foreach ($configuration['notes'] as $note) {
        if (strpos($note, 'OIDC discovery failed') !== false) {
            $discoveryError = $note;
        }
    }

    $provisioned = (int) $db->querySingle(
        "SELECT COUNT(*) FROM user WHERE oidc_sub IS NOT NULL AND oidc_sub != ''"
    );

    $checks = wallos_oidc_checks($configuration, $discovery, $discoveryError, $provisioned);

    return [
        'checks' => $checks,
        'worst' => wallos_oidc_worst_status($checks),
    ];
}

/**
 * The most severe status in a set of findings, for a summary line.
 *
 * @param array $checks
 * @return string
 */
function wallos_oidc_worst_status($checks)
{
    // "unknown" means Wallos cannot tell, not that something is wrong, so it
    // never downgrades the summary.
    foreach ([WALLOS_OIDC_ERROR, WALLOS_OIDC_WARNING] as $status) {
        foreach ($checks as $check) {
            if ($check['status'] === $status) {
                return $status;
            }
        }
    }

    return WALLOS_OIDC_OK;
}

/**
 * Records why an OIDC login failed.
 *
 * The provider's own error body is the most useful thing here and is safe to
 * keep: it carries error codes such as invalid_client or redirect_uri mismatch.
 * Authorization codes, tokens and the client secret never reach this.
 *
 * @param string $reason
 * @param array  $context
 */
function wallos_oidc_log_failure($reason, $context = [])
{
    $details = ['reason=' . $reason];

    foreach ($context as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $value = preg_replace('/[\r\n\s]+/', ' ', (string) $value);
        $details[] = $key . '=' . (function_exists('mb_substr')
            ? mb_substr($value, 0, 300)
            : substr($value, 0, 300));
    }

    error_log('[Wallos OIDC] ' . implode(' ', $details));
}
