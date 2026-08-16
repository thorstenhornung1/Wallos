<?php

/**
 * Mapping an identity provider's claim to the Wallos admin role.
 *
 * The provider says who someone is and may state that they are an
 * administrator. Wallos decides what that means here, and records the outcome
 * as a role row whose source is `oidc` — never touching a local grant.
 *
 * Deliberately provider-neutral: the operator names the claim. Nothing in this
 * file knows what Authentik, Keycloak or anything else happens to call it.
 */

require_once __DIR__ . '/../user_roles.php';

/**
 * Whether admin mapping is configured at all.
 *
 * Both halves are needed — a claim name without a value to look for cannot
 * decide anything. Unset means the feature is off and local behaviour is
 * untouched, which is what an existing installation must see after upgrading.
 *
 * @param array $settings effective OIDC settings
 * @return bool
 */
function wallos_oidc_admin_mapping_configured($settings)
{
    $claim = trim((string) ($settings['admin_claim'] ?? ''));
    $value = trim((string) ($settings['admin_value'] ?? ''));

    return $claim !== '' && $value !== '';
}

/**
 * Whether the claims from the provider say this person administers.
 *
 * The claim may be a list — `groups: ["Admin", "Users"]` — or a single string.
 * Both shapes are common and neither is wrong.
 *
 * Matching is exact. Case-insensitive matching would quietly make `admin` and
 * `Admin` the same group, and a provider where those are two different groups
 * with two different memberships is not a provider to guess about.
 *
 * @param array  $claims   decoded UserInfo / ID token claims
 * @param string $claimName
 * @param string $expected
 * @return bool
 */
function wallos_oidc_claim_grants_admin($claims, $claimName, $expected)
{
    if (!is_array($claims) || $claimName === '' || $expected === '') {
        return false;
    }

    if (!array_key_exists($claimName, $claims)) {
        // The provider did not send the claim. Treated as "not an
        // administrator" rather than as an error: a user who is in no groups at
        // all legitimately produces this.
        return false;
    }

    $value = $claims[$claimName];

    if (is_string($value)) {
        return $value === $expected;
    }

    if (is_array($value)) {
        foreach ($value as $entry) {
            if (is_string($entry) && $entry === $expected) {
                return true;
            }
        }

        return false;
    }

    // Numbers, booleans, nested objects: not something an admin decision should
    // be made from.
    return false;
}

/**
 * Bring the OIDC-derived admin role in line with what the provider just said.
 *
 * Runs on every successful OIDC login, which is what makes revocation work: the
 * role is not granted once and forgotten, it is restated or withdrawn each time
 * the provider vouches for the user.
 *
 * Only rows with source `oidc` are written. A local administrator keeps the
 * role whatever the provider says — otherwise a misconfigured claim name would
 * lock everybody out of the installation, including the person who has to fix
 * the claim name.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param array   $claims
 * @param array   $settings effective OIDC settings
 * @return string 'disabled', 'granted', 'revoked' or 'unchanged'
 */
function wallos_sync_oidc_admin_role($db, $userId, $claims, $settings)
{
    if (!wallos_oidc_admin_mapping_configured($settings)) {
        return 'disabled';
    }

    $userId = (int) $userId;
    if ($userId <= 0) {
        return 'disabled';
    }

    $claimName = trim((string) $settings['admin_claim']);
    $expected = trim((string) $settings['admin_value']);

    $held = in_array(WALLOS_ROLE_SOURCE_OIDC, wallos_user_admin_sources($db, $userId), true);
    $shouldHold = wallos_oidc_claim_grants_admin($claims, $claimName, $expected);

    if ($shouldHold && !$held) {
        wallos_grant_role($db, $userId, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC);

        return 'granted';
    }

    if (!$shouldHold && $held) {
        wallos_revoke_role($db, $userId, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC);

        return 'revoked';
    }

    return 'unchanged';
}
