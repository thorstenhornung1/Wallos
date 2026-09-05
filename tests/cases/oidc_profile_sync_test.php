<?php
/*
  The identity provider governs a linked account's profile: on every OIDC login
  the userinfo claims are re-applied to the user's name, email and language,
  beside the avatar and admin-role syncs that already run. These cases pin the
  four rules the feature rests on — and prove each one is a real gate by setting
  up the exact situation the rule exists to refuse:

    * every supplied field is written (given_name/family_name, a split display
      name, a verified email, a locale resolved to a supported language);
    * a MISSING claim never wipes a stored value — the provider omitting locale
      leaves the language exactly as it was;
    * only a linked account (oidc_sub set) is ever touched — a local user is
      returned untouched;
    * an unverified email is refused while require_email_verified is on, and
      adopted only when the instance has lowered that bar itself.

  The helper is called exactly as includes/oidc/handle_oidc_callback.php calls
  it, and a wiring case proves both linked branches of that file really call it,
  so deleting either call fails a case. Reads go through the database boundary
  ($db->scalar), never a SQLite-specific API, so the suite runs unchanged on
  PostgreSQL.
*/

require_once WALLOS_ROOT . '/includes/oidc/oidc_profile_sync.php';

/**
 * Links an account to a provider subject and seeds its governed fields, then
 * returns the account row the callback would hold at login time.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $sub
 * @param array          $fields firstname, lastname, email, language
 * @return array
 */
function oidc_ps_link_user($db, $userId, $sub, array $fields)
{
    $columns = ['oidc_sub' => $sub] + $fields;
    foreach ($columns as $column => $value) {
        $statement = $db->prepare('UPDATE "user" SET ' . $column . ' = :value WHERE id = :id');
        $statement->bindValue(':value', $value);
        $statement->bindValue(':id', $userId);
        $statement->execute();
    }

    return ['id' => $userId, 'oidc_sub' => $sub] + $fields;
}

/**
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $column
 * @return string|null
 */
function oidc_ps_get($db, $userId, $column)
{
    return $db->scalar('SELECT ' . $column . ' FROM "user" WHERE id = :id', [':id' => $userId]);
}

/**
 * The settings the sync reads: the verification bar and (for the UI marking)
 * the requested scopes.
 *
 * @param array $overrides
 * @return array
 */
function oidc_ps_settings(array $overrides = [])
{
    return array_merge([
        'name' => 'Example IdP',
        'require_email_verified' => 1,
        'scopes' => 'openid email profile',
    ], $overrides);
}

wallos_test('every supplied claim refreshes the linked account on login', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $userData = oidc_ps_link_user($db, 1, 'sub-alice', [
        'firstname' => 'Old',
        'lastname' => 'Name',
        'email' => 'old@example.com',
        'language' => 'en',
    ]);

    $userInfo = [
        'given_name' => 'Alice',
        'family_name' => 'Smith',
        'email' => 'alice@example.com',
        'email_verified' => true,
        'locale' => 'de-DE',
    ];

    $changed = wallos_oidc_maybe_update_profile($db, $userData, $userInfo, oidc_ps_settings());

    assert_same('Alice', oidc_ps_get($db, 1, 'firstname'), 'firstname comes from given_name');
    assert_same('Smith', oidc_ps_get($db, 1, 'lastname'), 'lastname comes from family_name');
    assert_same('alice@example.com', oidc_ps_get($db, 1, 'email'), 'a verified email is adopted');
    assert_same('de', oidc_ps_get($db, 1, 'language'), 'locale de-DE resolves to the supported language de');

    // The row the rest of the sign-in holds is refreshed in place, so the
    // language cookie oidc_login.php sets reflects this login, not the last one.
    assert_same('de', $userData['language'], 'the in-memory account row is updated in place');
    assert_same('Alice', $changed['firstname'] ?? null, 'the change set reports what was written');

    $db->close();
});

wallos_test('a display name fills first and last, and a single token leaves last alone', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'bob');
    $userData = oidc_ps_link_user($db, 1, 'sub-bob', [
        'firstname' => 'Old',
        'lastname' => 'Keep',
        'email' => 'bob@example.com',
        'language' => 'en',
    ]);

    // No given_name/family_name: the split display name supplies both halves.
    wallos_oidc_maybe_update_profile($db, $userData, ['name' => 'Robert Jones'], oidc_ps_settings());
    assert_same('Robert', oidc_ps_get($db, 1, 'firstname'), 'the first token becomes the first name');
    assert_same('Jones', oidc_ps_get($db, 1, 'lastname'), 'the remainder becomes the last name');

    // A single-token name is not a reason to blank a stored last name.
    wallos_oidc_maybe_update_profile($db, $userData, ['name' => 'Cher'], oidc_ps_settings());
    assert_same('Cher', oidc_ps_get($db, 1, 'firstname'), 'a one-word name still sets the first name');
    assert_same('Jones', oidc_ps_get($db, 1, 'lastname'), 'and leaves the last name as it was');

    $db->close();
});

wallos_test('a missing claim never wipes the stored value', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'carol');
    $userData = oidc_ps_link_user($db, 1, 'sub-carol', [
        'firstname' => 'Carol',
        'lastname' => 'Keeper',
        'email' => 'carol@example.com',
        'language' => 'fr',
    ]);

    // Only given_name is supplied. family_name, locale and email are absent, and
    // an absent claim governs nothing: this is the case that would fail if the
    // sync wrote empty values for claims the provider did not send.
    $changed = wallos_oidc_maybe_update_profile($db, $userData, ['given_name' => 'Caroline'], oidc_ps_settings());

    assert_same('Caroline', oidc_ps_get($db, 1, 'firstname'), 'the supplied first name is taken');
    assert_same('Keeper', oidc_ps_get($db, 1, 'lastname'), 'the absent family_name leaves the last name');
    assert_same('fr', oidc_ps_get($db, 1, 'language'), 'the absent locale leaves the language');
    assert_same('carol@example.com', oidc_ps_get($db, 1, 'email'), 'the absent email leaves the email');
    assert_same(['firstname' => 'Caroline'], $changed, 'only the supplied field is reported changed');

    // An entirely empty userinfo touches nothing at all.
    $changed = wallos_oidc_maybe_update_profile($db, $userData, [], oidc_ps_settings());
    assert_same([], $changed, 'no claims means no change');
    assert_same('Caroline', oidc_ps_get($db, 1, 'firstname'), 'and the fields stand');

    $db->close();
});

wallos_test('a local account is never touched', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'dave');

    // Deliberately NOT linked: oidc_sub stays null. The provider vouches for a
    // name, email and locale, and none of it may reach a local account.
    $statement = $db->prepare('UPDATE "user" SET firstname = :f, email = :e, language = :l WHERE id = :id');
    $statement->bindValue(':f', 'Local');
    $statement->bindValue(':e', 'local@example.com');
    $statement->bindValue(':l', 'en');
    $statement->bindValue(':id', 1);
    $statement->execute();

    $userData = ['id' => 1, 'oidc_sub' => null, 'firstname' => 'Local', 'email' => 'local@example.com', 'language' => 'en'];
    $userInfo = ['given_name' => 'Intruder', 'email' => 'intruder@example.com', 'email_verified' => true, 'locale' => 'de'];

    $changed = wallos_oidc_maybe_update_profile($db, $userData, $userInfo, oidc_ps_settings());

    assert_same([], $changed, 'nothing is written for a local account');
    assert_same('Local', oidc_ps_get($db, 1, 'firstname'), 'the local first name stands');
    assert_same('local@example.com', oidc_ps_get($db, 1, 'email'), 'the local email stands');
    assert_same('en', oidc_ps_get($db, 1, 'language'), 'the local language stands');

    $db->close();
});

wallos_test('an unverified email is refused while require_email_verified is on', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'erin');
    $userData = oidc_ps_link_user($db, 1, 'sub-erin', [
        'firstname' => 'Erin',
        'lastname' => 'Original',
        'email' => 'safe@example.com',
        'language' => 'en',
    ]);

    // The provider offers a different email but does not vouch for it. The name
    // still syncs; the email must not, because the bar is on (the default).
    $userInfo = ['given_name' => 'Erin', 'email' => 'attacker@example.com', 'email_verified' => false];

    wallos_oidc_maybe_update_profile($db, $userData, $userInfo, oidc_ps_settings(['require_email_verified' => 1]));
    assert_same('safe@example.com', oidc_ps_get($db, 1, 'email'), 'an unverified email is not adopted');

    // Only when the instance itself has lowered the bar is the same email taken —
    // the sync honours the setting and never lowers it on its own.
    wallos_oidc_maybe_update_profile($db, $userData, $userInfo, oidc_ps_settings(['require_email_verified' => 0]));
    assert_same('attacker@example.com', oidc_ps_get($db, 1, 'email'),
        'with the bar lowered the email is adopted, matching the account-link path');

    $db->close();
});

wallos_test('a malformed email claim is refused even when marked verified', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'faye');
    $userData = oidc_ps_link_user($db, 1, 'sub-faye', [
        'firstname' => 'Faye',
        'lastname' => 'Valid',
        'email' => 'faye@example.com',
        'language' => 'en',
    ]);

    $userInfo = ['email' => 'not-an-email', 'email_verified' => true];
    wallos_oidc_maybe_update_profile($db, $userData, $userInfo, oidc_ps_settings());
    assert_same('faye@example.com', oidc_ps_get($db, 1, 'email'), 'a syntactically invalid address is never stored');

    $db->close();
});

wallos_test('the managed-field set follows the requested scopes', function () {
    // The read-time marking the profile page uses. With the standard scopes the
    // provider governs all four fields.
    assert_same(['email', 'firstname', 'lastname', 'language'],
        wallos_oidc_managed_profile_fields(['scopes' => 'openid email profile']),
        'openid email profile governs name, email and language');

    // Without the profile scope the name and language are the user's own again —
    // the read-time counterpart of "a claim the provider does not supply".
    assert_same(['email'], wallos_oidc_managed_profile_fields(['scopes' => 'openid email']),
        'without profile scope only the email is governed');

    // Without email scope the address is user-editable.
    assert_same(['firstname', 'lastname', 'language'],
        wallos_oidc_managed_profile_fields(['scopes' => 'openid profile']),
        'without email scope the name and language are governed but the email is not');

    assert_same([], wallos_oidc_managed_profile_fields(['scopes' => 'openid']),
        'openid alone governs nothing');
    assert_same([], wallos_oidc_managed_profile_fields([]),
        'no scopes governs nothing');

    $db = null;
    unset($db);
});

wallos_test('both linked branches of the callback call the profile sync', function () {
    assert_true(
        wallos_test_file_calls('includes/oidc/handle_oidc_callback.php', 'wallos_oidc_maybe_update_profile'),
        'the callback calls the profile sync'
    );

    // The avatar wiring case guards the same shape; here the two linked branches
    // — sub-matched and email-linked — must each carry a call, so removing either
    // fails this case rather than passing on the survivor.
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/handle_oidc_callback.php');
    assert_same(2, substr_count($source, 'wallos_oidc_maybe_update_profile($db'),
        'both the sub-matched and the email-linked branch refresh the profile');
});
