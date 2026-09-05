<?php
/*
  Refreshing the IdP-governed profile fields on every OIDC login.

  When OIDC is configured the identity provider governs a linked account's
  profile centrally: the provider's userinfo claims are the source of truth for
  the user's name, email and language, not a one-off copy taken when the account
  was created. Avatar and admin-role already re-sync on every login; this file
  brings name, email and language alongside them, following the same shape as
  includes/oidc/oidc_avatar.php.

  The rules, which the cases in tests/cases/oidc_profile_sync_test.php pin down:

    1. Only a linked account (`oidc_sub` set) is ever touched. A local user is
       never modified — the provider governs nothing it did not create.
    2. A MISSING claim never wipes a stored value. Only a claim the provider
       actually supplies overrides; a provider that omits `locale` leaves the
       user's language exactly as it was.
    3. Email is adopted only when it is safe: a syntactically valid address, and
       — while `require_email_verified` is on, which is the default — only when
       the provider marks it verified. The bar is never lowered below what that
       setting demands (the same bar the account-link path in
       includes/oidc/handle_oidc_callback.php enforces).
    4. Language is resolved through wallos_resolve_language(), so whatever the
       provider's `locale` is it always lands on a language Wallos supports.

  Nothing here ever fails the login: a write that cannot be made is logged, the
  way the rest of the OIDC code logs — with the user id, never the claim values
  — and the login proceeds with the field left as it was.
*/

require_once __DIR__ . '/../i18n/languages.php';

/**
 * The provider's value for one claim, or null when the provider did not supply
 * it in a usable form.
 *
 * "Did not supply" is deliberately generous: an absent key, a non-string value
 * (a number, a list, a nested object — none of which is a name), and a present
 * but empty/whitespace string all read as null. That is what makes rule 2 hold
 * — a blank `given_name` is treated as "not supplied" and never wipes a stored
 * first name.
 *
 * @param mixed  $claims The decoded userinfo array.
 * @param string $key
 * @return string|null Trimmed value, or null.
 */
function wallos_oidc_claim_string($claims, $key)
{
    if (!is_array($claims) || !array_key_exists($key, $claims)) {
        return null;
    }

    $value = $claims[$key];
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);

    return $trimmed === '' ? null : $trimmed;
}

/**
 * Splits a display `name` claim into [firstname, lastname].
 *
 * The same split includes/oidc/oidc_create_user.php makes, kept in one place so
 * creation and the login refresh agree. A single-token name yields a first name
 * and a null last name, so a `name` of "Alice" never blanks a stored last name
 * that `family_name` would otherwise carry.
 *
 * @param string|null $name
 * @return array{0: string|null, 1: string|null}
 */
function wallos_oidc_split_name($name)
{
    if ($name === null) {
        return [null, null];
    }

    $parts = explode(' ', $name, 2);
    $first = trim($parts[0]);
    $first = $first === '' ? null : $first;

    $last = null;
    if (isset($parts[1]) && trim($parts[1]) !== '') {
        $last = trim($parts[1]);
    }

    return [$first, $last];
}

/**
 * The profile fields the provider governs for this login, mapped to the value
 * to store — containing only the fields the provider actually supplied.
 *
 * A field absent from the returned array is a field the provider said nothing
 * about, and the caller must leave it untouched (rule 2). The values are already
 * normalised: names trimmed, language resolved to a supported tag, email only
 * present when it passed the verification bar.
 *
 * Pure: no database, no I/O, no logging.
 *
 * @param mixed $userInfo     Decoded userinfo claims.
 * @param array $oidcSettings Effective OIDC settings (for require_email_verified).
 * @return array<string, string> Subset of firstname, lastname, email, language.
 */
function wallos_oidc_profile_claims($userInfo, $oidcSettings)
{
    $claims = [];

    // Name. given_name / family_name win; a display `name` fills in whichever
    // half the explicit claims did not supply.
    $given = wallos_oidc_claim_string($userInfo, 'given_name');
    $family = wallos_oidc_claim_string($userInfo, 'family_name');
    [$splitFirst, $splitLast] = wallos_oidc_split_name(wallos_oidc_claim_string($userInfo, 'name'));

    $firstname = $given ?? $splitFirst;
    $lastname = $family ?? $splitLast;

    if ($firstname !== null) {
        $claims['firstname'] = $firstname;
    }
    if ($lastname !== null) {
        $claims['lastname'] = $lastname;
    }

    // Email. Adopted only when valid and — while require_email_verified is on —
    // verified. Never lowers the bar the account-link path already enforces.
    $email = wallos_oidc_claim_string($userInfo, 'email');
    if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $requireVerified = (bool) ($oidcSettings['require_email_verified'] ?? 1);
        $verified = is_array($userInfo) && ($userInfo['email_verified'] ?? false) === true;
        if (!$requireVerified || $verified) {
            $claims['email'] = $email;
        }
    }

    // Language. Present locale only; resolved to a language Wallos supports.
    $locale = wallos_oidc_claim_string($userInfo, 'locale');
    if ($locale !== null) {
        $claims['language'] = wallos_resolve_language($locale);
    }

    return $claims;
}

/**
 * The profile fields the profile page marks provider-managed for a linked user.
 *
 * Read-time, with no live userinfo call available: it reflects which claims the
 * provider is configured to release, from the standard OIDC scope-to-claim
 * mapping. The `email` scope carries the email address; the `profile` scope
 * carries the name and the locale. An instance whose scopes request neither
 * leaves those fields user-editable — the read-time counterpart of rule 2's
 * "a claim the provider does not supply is not governed".
 *
 * @param array $oidcSettings Effective OIDC settings.
 * @return string[] Subset of firstname, lastname, email, language.
 */
function wallos_oidc_managed_profile_fields($oidcSettings)
{
    $scopes = preg_split('/\s+/', strtolower(trim((string) ($oidcSettings['scopes'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY);
    $scopes = $scopes ?: [];

    $fields = [];
    if (in_array('email', $scopes, true)) {
        $fields[] = 'email';
    }
    if (in_array('profile', $scopes, true)) {
        $fields[] = 'firstname';
        $fields[] = 'lastname';
        $fields[] = 'language';
    }

    return $fields;
}

/**
 * Writes one governed profile column for a linked user.
 *
 * The column name is taken from a fixed allowlist and never from the claim data,
 * so it is safe to build the statement with it; the value is always bound.
 * Returns whether the write was made; a failure is logged and never propagated.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $field One of firstname, lastname, email, language.
 * @param string         $value
 * @return bool
 */
function wallos_oidc_write_profile_field($db, $userId, $field, $value)
{
    if (!in_array($field, ['firstname', 'lastname', 'email', 'language'], true)) {
        return false;
    }

    $statement = $db->prepare('UPDATE "user" SET ' . $field . ' = :value WHERE id = :userId');
    if ($statement === false) {
        error_log('[Wallos OIDC] could not prepare the ' . $field . ' update for user ' . $userId);

        return false;
    }

    $statement->bindValue(':value', $value);
    $statement->bindValue(':userId', $userId);

    if ($statement->execute() === false) {
        error_log('[Wallos OIDC] could not update ' . $field . ' for user ' . $userId);

        return false;
    }

    return true;
}

/**
 * Brings a linked account's name, email and language in line with what the
 * provider just said. The login-time counterpart of the avatar and admin-role
 * syncs, called from both linked branches of handle_oidc_callback.php.
 *
 * $userData is updated in place for every field that changes, so the rest of
 * the sign-in — the language cookie oidc_login.php sets, for one — sees the
 * refreshed values on this very login rather than one login later.
 *
 * @param WallosDatabase $db
 * @param array          $userData     The account row; needs id and oidc_sub. Updated in place.
 * @param mixed          $userInfo     Decoded userinfo claims.
 * @param array          $oidcSettings Effective OIDC settings.
 * @return array<string, string> The fields that were changed, for the caller/tests.
 */
function wallos_oidc_maybe_update_profile($db, array &$userData, $userInfo, $oidcSettings)
{
    // Rule 1: only a linked account is ever touched. The callers are already in
    // a linked branch, but this is the invariant the whole feature rests on, so
    // it is enforced here rather than assumed.
    if (trim((string) ($userData['oidc_sub'] ?? '')) === '') {
        return [];
    }

    $userId = (int) $userData['id'];
    $desired = wallos_oidc_profile_claims($userInfo, $oidcSettings);

    $changed = [];
    foreach ($desired as $field => $value) {
        $current = isset($userData[$field]) ? (string) $userData[$field] : '';
        if ($current === (string) $value) {
            continue;
        }

        if (wallos_oidc_write_profile_field($db, $userId, $field, $value)) {
            $userData[$field] = $value;
            $changed[$field] = $value;
        }
    }

    if (!empty($changed)) {
        error_log('[Wallos OIDC] profile refreshed for user ' . $userId
            . ' (' . implode(', ', array_keys($changed)) . ')');
    }

    return $changed;
}
