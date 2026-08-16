<?php
/*
  Turning an identity provider's claim into the Wallos admin role.

  The rule the whole feature rests on: synchronisation writes only rows whose
  source is `oidc`. A local administrator must survive a provider that stops
  sending the claim, or that never sent it, or whose claim name was typed wrong
  — because that administrator is the account that has to fix it.
*/

require_once WALLOS_ROOT . '/includes/user_roles.php';
require_once WALLOS_ROOT . '/includes/oidc/admin_role_sync.php';
require_once WALLOS_ROOT . '/includes/oidc_settings.php';

/**
 * @param string $claim
 * @param string $value
 * @return array
 */
function oidc_role_settings($claim = 'groups', $value = 'Admin')
{
    return ['admin_claim' => $claim, 'admin_value' => $value];
}

// ------------------------------------------------------------ claim matching

wallos_test('a claim list containing the value grants admin', function () {
    assert_true(
        wallos_oidc_claim_grants_admin(['groups' => ['Users', 'Admin']], 'groups', 'Admin'),
        'membership anywhere in the list counts'
    );
});

wallos_test('a claim list without the value does not', function () {
    assert_true(
        !wallos_oidc_claim_grants_admin(['groups' => ['Users', 'Finance']], 'groups', 'Admin'),
        'not a member'
    );
});

wallos_test('a claim sent as a single string is accepted', function () {
    // Providers differ; a single-valued claim is not an error.
    assert_true(
        wallos_oidc_claim_grants_admin(['role' => 'Admin'], 'role', 'Admin'),
        'a string claim matches'
    );
    assert_true(
        !wallos_oidc_claim_grants_admin(['role' => 'User'], 'role', 'Admin'),
        'and a different string does not'
    );
});

wallos_test('a missing claim is simply not an administrator', function () {
    // A user who belongs to no groups produces this legitimately.
    assert_true(
        !wallos_oidc_claim_grants_admin(['email' => 'a@example.com'], 'groups', 'Admin'),
        'absent claim'
    );
    assert_true(!wallos_oidc_claim_grants_admin([], 'groups', 'Admin'), 'no claims at all');
});

wallos_test('matching is exact, not case-insensitive', function () {
    // Where a provider has both `admin` and `Admin` as distinct groups with
    // distinct memberships, guessing is worse than not matching.
    assert_true(
        !wallos_oidc_claim_grants_admin(['groups' => ['admin']], 'groups', 'Admin'),
        'case matters'
    );
    assert_true(
        !wallos_oidc_claim_grants_admin(['groups' => [' Admin']], 'groups', 'Admin'),
        'and so does whitespace'
    );
});

wallos_test('a claim that is not a string or list decides nothing', function () {
    assert_true(!wallos_oidc_claim_grants_admin(['groups' => true], 'groups', 'Admin'), 'boolean');
    assert_true(!wallos_oidc_claim_grants_admin(['groups' => 1], 'groups', 'Admin'), 'number');
    assert_true(
        !wallos_oidc_claim_grants_admin(['groups' => [['name' => 'Admin']]], 'groups', 'Admin'),
        'nested object'
    );
});

// ---------------------------------------------------------- configuration gate

wallos_test('admin mapping is off unless both halves are configured', function () {
    assert_true(!wallos_oidc_admin_mapping_configured([]), 'nothing configured');
    assert_true(!wallos_oidc_admin_mapping_configured(oidc_role_settings('groups', '')), 'no value');
    assert_true(!wallos_oidc_admin_mapping_configured(oidc_role_settings('', 'Admin')), 'no claim');
    assert_true(wallos_oidc_admin_mapping_configured(oidc_role_settings()), 'both set');
});

wallos_test('an unconfigured mapping changes nothing at all', function () {
    // What an existing installation must see after upgrading.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);

    $outcome = wallos_sync_oidc_admin_role($db, 1, ['groups' => ['Users']], []);

    assert_same('disabled', $outcome, 'synchronisation did not run');
    assert_same(['local'], wallos_user_admin_sources($db, 1), 'the local role is untouched');

    $db->close();
});

// ------------------------------------------------------------ synchronisation

wallos_test('the claim grants the role on login', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 4, 'bob');

    $outcome = wallos_sync_oidc_admin_role($db, 4, ['groups' => ['Admin']], oidc_role_settings());

    assert_same('granted', $outcome, 'granted');
    assert_true(wallos_user_is_admin($db, 4), 'bob administers');
    assert_same(['oidc'], wallos_user_admin_sources($db, 4), 'from the provider, not locally');

    $db->close();
});

wallos_test('losing the group revokes the role at the next login', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 4, 'bob');
    wallos_sync_oidc_admin_role($db, 4, ['groups' => ['Admin']], oidc_role_settings());

    $outcome = wallos_sync_oidc_admin_role($db, 4, ['groups' => ['Users']], oidc_role_settings());

    assert_same('revoked', $outcome, 'revoked');
    assert_true(!wallos_user_is_admin($db, 4), 'no longer an administrator');

    $db->close();
});

wallos_test('a claim that stops being sent revokes the role', function () {
    // Not the same as being in a different group: the provider was
    // reconfigured, or the scope was dropped. Either way the assertion that
    // this user administers is gone.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 4, 'bob');
    wallos_sync_oidc_admin_role($db, 4, ['groups' => ['Admin']], oidc_role_settings());

    wallos_sync_oidc_admin_role($db, 4, ['email' => 'bob@example.com'], oidc_role_settings());

    assert_true(!wallos_user_is_admin($db, 4), 'revoked');

    $db->close();
});

wallos_test('a local administrator survives losing the claim', function () {
    // The case the source column exists for. A misconfigured claim name must
    // not lock out the account that can correct the configuration.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);
    wallos_sync_oidc_admin_role($db, 1, ['groups' => ['Admin']], oidc_role_settings());

    assert_same(['local', 'oidc'], wallos_user_admin_sources($db, 1), 'both sources');

    wallos_sync_oidc_admin_role($db, 1, ['groups' => []], oidc_role_settings());

    assert_true(wallos_user_is_admin($db, 1), 'still an administrator');
    assert_same(['local'], wallos_user_admin_sources($db, 1), 'the local grant remains');

    $db->close();
});

wallos_test('a wrong claim name does not silently strip local administrators', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);

    // The operator typed 'group' where the provider sends 'groups'.
    wallos_sync_oidc_admin_role($db, 1, ['groups' => ['Admin']], oidc_role_settings('group', 'Admin'));

    assert_true(wallos_user_is_admin($db, 1), 'alice can still fix the configuration');

    $db->close();
});

wallos_test('repeated logins with the same claim change nothing', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 4, 'bob');

    wallos_sync_oidc_admin_role($db, 4, ['groups' => ['Admin']], oidc_role_settings());
    $outcome = wallos_sync_oidc_admin_role($db, 4, ['groups' => ['Admin']], oidc_role_settings());

    assert_same('unchanged', $outcome, 'nothing to do');
    assert_same(['oidc'], wallos_user_admin_sources($db, 4), 'one row, not two');

    $db->close();
});

wallos_test('synchronisation never touches another user', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 4, 'bob');
    wallos_grant_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC);

    wallos_sync_oidc_admin_role($db, 4, ['groups' => ['Users']], oidc_role_settings());

    assert_same(['oidc'], wallos_user_admin_sources($db, 1), 'alice is unaffected');

    $db->close();
});

// -------------------------------------------------------------- configuration

wallos_test('the environment supplies the claim mapping', function () {
    putenv('OIDC_ADMIN_CLAIM=entitlements');
    putenv('OIDC_ADMIN_VALUE=wallos-admin');

    $db = wallos_test_open_database();
    $configuration = wallos_get_effective_oidc_configuration($db);

    assert_same('entitlements', $configuration['settings']['admin_claim'], 'claim from the environment');
    assert_same('wallos-admin', $configuration['settings']['admin_value'], 'value from the environment');
    assert_same('OIDC_ADMIN_CLAIM', $configuration['managed_fields']['admin_claim'] ?? null,
        'and it is marked as managed');

    $db->close();
});

wallos_test('half a mapping is reported rather than ignored', function () {
    // Otherwise an operator believes admin rights are synchronised when nothing
    // is happening at all.
    putenv('OIDC_ADMIN_CLAIM=groups');

    $db = wallos_test_open_database();
    $configuration = wallos_get_effective_oidc_configuration($db);

    $mentioned = false;
    foreach ($configuration['notes'] as $note) {
        if (strpos($note, 'OIDC_ADMIN_VALUE') !== false) {
            $mentioned = true;
        }
    }

    assert_true($mentioned, 'the missing half is named in the notes');
    assert_true(!wallos_oidc_admin_mapping_configured($configuration['settings']),
        'and the mapping stays off');

    $db->close();
});

wallos_test('nothing is configured by default', function () {
    $db = wallos_test_open_database();
    $configuration = wallos_get_effective_oidc_configuration($db);

    assert_true(!wallos_oidc_admin_mapping_configured($configuration['settings']),
        'admin mapping is off unless an operator turns it on');

    $db->close();
});

wallos_test('the login path synchronises the role', function () {
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/oidc_login.php');

    assert_true(
        strpos($source, 'wallos_sync_oidc_admin_role') !== false,
        'every OIDC sign-in path passes through oidc_login.php, so the sync belongs there'
    );
});
