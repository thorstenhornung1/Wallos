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

wallos_test('the admin interface can configure the mapping', function () {
    // Stored with the rest of the OIDC settings rather than being
    // environment-only: whoever can edit the issuer or the client id already
    // controls authentication completely, so singling these two out would have
    // split the configuration across two places for no gain.
    $db = wallos_test_open_database();
    $stmt = $db->prepare("INSERT INTO oauth_settings (id, name, client_id, client_secret,
        authorization_url, token_url, user_info_url, redirect_url, admin_claim, admin_value)
        VALUES (1, 'p', 'c', 's', 'https://a', 'https://t', 'https://u', 'https://r', :claim, :value)");
    $stmt->bindValue(':claim', 'groups', SQLITE3_TEXT);
    $stmt->bindValue(':value', 'Wallos Admins', SQLITE3_TEXT);
    $stmt->execute();

    $configuration = wallos_get_effective_oidc_configuration($db);

    assert_same('groups', $configuration['settings']['admin_claim'], 'claim read from the database');
    assert_same('Wallos Admins', $configuration['settings']['admin_value'], 'value read from the database');
    assert_true(wallos_oidc_admin_mapping_configured($configuration['settings']), 'mapping is on');
    assert_true(!isset($configuration['managed_fields']['admin_claim']),
        'and editable, since no environment variable claims it');

    $db->close();
});

wallos_test('the environment overrides what the interface stored', function () {
    // Same precedence as every other OIDC field: an operator who sets the
    // variable takes the decision away from the interface, and the interface
    // says so rather than silently accepting edits that do nothing.
    $db = wallos_test_open_database();
    $stmt = $db->prepare("INSERT INTO oauth_settings (id, name, client_id, client_secret,
        authorization_url, token_url, user_info_url, redirect_url, admin_claim, admin_value)
        VALUES (1, 'p', 'c', 's', 'https://a', 'https://t', 'https://u', 'https://r', 'groups', 'From UI')");
    $stmt->execute();

    putenv('OIDC_ADMIN_CLAIM=entitlements');
    putenv('OIDC_ADMIN_VALUE=From environment');

    $configuration = wallos_get_effective_oidc_configuration($db);

    assert_same('entitlements', $configuration['settings']['admin_claim'], 'environment wins');
    assert_same('From environment', $configuration['settings']['admin_value'], 'for both halves');
    assert_same('OIDC_ADMIN_CLAIM', $configuration['managed_fields']['admin_claim'] ?? null,
        'and the field is marked managed so the interface disables it');

    $db->close();
});

wallos_test('the migration adds the columns and leaves them empty', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    // An existing installation must upgrade into "no admin mapping", not into
    // some default that starts handing out administrator rights.
    $db = wallos_test_open_database();

    require WALLOS_ROOT . '/migrations/000059.php';

    $columns = [];
    $result = $db->query("SELECT name FROM pragma_table_info('oauth_settings')");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row['name'];
    }

    assert_true(in_array('admin_claim', $columns, true), 'admin_claim exists');
    assert_true(in_array('admin_value', $columns, true), 'admin_value exists');

    $settings = wallos_get_db_oidc_settings($db);
    assert_same('', $settings['admin_claim'], 'empty by default');
    assert_true(!wallos_oidc_admin_mapping_configured($settings), 'so no mapping happens');

    $db->close();
});

wallos_test('the role migration is idempotent for the columns', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    $db = wallos_test_open_database();

    require WALLOS_ROOT . '/migrations/000059.php';
    require WALLOS_ROOT . '/migrations/000059.php';

    $settings = wallos_get_db_oidc_settings($db);
    assert_same('', $settings['admin_claim'], 'still there, still empty');

    $db->close();
});

wallos_test('both save paths go through one writer', function () {
    // Two endpoints save OIDC settings — the admin interface posts to
    // endpoints/, the API to api/. They had drifted: one trimmed every text
    // field, the other trimmed none. Sharing the writer is what stops a field
    // added to one from being silently unsaveable through the other.
    foreach (['endpoints/admin/saveoidcsettings.php', 'api/admin/set_oidc_settings.php'] as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_true(strpos($source, 'wallos_save_oidc_settings') !== false,
            $path . ' should write through the shared function');
        assert_true(strpos($source, 'INSERT INTO oauth_settings') === false,
            $path . ' should not carry its own copy of the write');
    }
});

wallos_test('every writable field is saveable through both paths', function () {
    // The interface maps camelCase names; the API uses database names. A field
    // in the shared list that neither path passes through is unreachable.
    $ui = file_get_contents(WALLOS_ROOT . '/endpoints/admin/saveoidcsettings.php');

    foreach (array_keys(wallos_oidc_writable_fields()) as $field) {
        assert_true(strpos($ui, "'" . $field . "' =>") !== false,
            $field . ' is missing from the interface field map');
    }
});

wallos_test('the interface offers both fields', function () {
    $admin = file_get_contents(WALLOS_ROOT . '/admin.php');
    $script = file_get_contents(WALLOS_ROOT . '/scripts/admin.js');

    assert_true(strpos($admin, 'oidcAdminClaim') !== false, 'the claim field is rendered');
    assert_true(strpos($admin, 'oidcAdminValue') !== false, 'the value field is rendered');
    assert_true(strpos($admin, "oidc_input_attrs('admin_claim'") !== false,
        'and is disabled when an environment variable manages it');
    assert_true(strpos($script, 'oidcAdminClaim') !== false, 'and the save button submits it');
});

wallos_test('nothing is configured by default', function () {
    $db = wallos_test_open_database();
    $configuration = wallos_get_effective_oidc_configuration($db);

    assert_true(!wallos_oidc_admin_mapping_configured($configuration['settings']),
        'admin mapping is off unless an operator turns it on');

    $db->close();
});

wallos_test('the login path synchronises the role', function () {
    // As a call. Commenting out the two lines in oidc_login.php used to leave
    // this green — and with them commented out no OIDC session is ever
    // recorded, so back-channel logout has nothing to revoke and a demoted
    // administrator keeps the role forever.
    assert_true(
        wallos_test_file_calls('includes/oidc/oidc_login.php', 'wallos_sync_oidc_admin_role'),
        'every OIDC sign-in path passes through oidc_login.php, so the sync belongs there'
    );
});

// ------------------------------------------------------------- shared writer

wallos_test('saving trims text on both paths', function () {
    // The API used to store values untrimmed. A client id pasted with a
    // trailing space then failed every handshake as 'invalid client', with
    // nothing in the message pointing at a space nobody can see.
    $db = wallos_test_open_database();

    wallos_save_oidc_settings($db, ['client_id' => '  abc  ', 'name' => " Authentik\n"], []);
    $settings = wallos_get_db_oidc_settings($db);

    assert_same('abc', $settings['client_id'], 'client id trimmed');
    assert_same('Authentik', $settings['name'], 'and so is everything else');

    $db->close();
});

wallos_test('an absent field keeps its stored value', function () {
    $db = wallos_test_open_database();
    wallos_save_oidc_settings($db, ['client_id' => 'abc', 'scopes' => 'openid email'], []);

    wallos_save_oidc_settings($db, ['client_id' => 'def'], []);
    $settings = wallos_get_db_oidc_settings($db);

    assert_same('def', $settings['client_id'], 'the submitted field changed');
    assert_same('openid email', $settings['scopes'], 'the absent one did not');

    $db->close();
});

wallos_test('a field submitted empty is cleared', function () {
    // Distinct from absent: clearing a value has to stay possible.
    $db = wallos_test_open_database();
    wallos_save_oidc_settings($db, ['logout_url' => 'https://auth.example.com/end'], []);

    wallos_save_oidc_settings($db, ['logout_url' => ''], []);

    assert_same('', wallos_get_db_oidc_settings($db)['logout_url'], 'cleared');

    $db->close();
});

wallos_test('an environment-managed field is not writable', function () {
    // Accepting an edit the next page load discards would be worse than
    // refusing it.
    $db = wallos_test_open_database();
    wallos_save_oidc_settings($db, ['client_id' => 'from-ui'], ['client_id' => 'OIDC_CLIENT_ID']);

    assert_same('', wallos_get_db_oidc_settings($db)['client_id'], 'the write was skipped');

    $db->close();
});

wallos_test('integers stay integers', function () {
    $db = wallos_test_open_database();

    wallos_save_oidc_settings($db, ['auto_create_user' => '1', 'require_email_verified' => '0'], []);
    $settings = wallos_get_db_oidc_settings($db);

    assert_same(1, (int) $settings['auto_create_user'], 'checkbox on');
    assert_same(0, (int) $settings['require_email_verified'], 'checkbox off');

    $db->close();
});

wallos_test('submitting nothing is not an error', function () {
    $db = wallos_test_open_database();

    $result = wallos_save_oidc_settings($db, [], []);

    assert_true($result['success'], 'no failure');
    assert_true(!$result['changed'], 'and nothing was written');

    $db->close();
});

// ------------------------------------------------------- clearing the secret

wallos_test('an empty secret submission keeps the stored secret', function () {
    // The interface renders the secret field empty rather than pre-filled, so
    // an empty submission means "unchanged". This is the placeholder rule the
    // clear flag exists to get around.
    $db = wallos_test_open_database();
    wallos_save_oidc_settings($db, ['client_secret' => 's3cret'], []);

    wallos_save_oidc_settings($db, ['client_secret' => '', 'client_id' => 'abc'], []);

    assert_same('s3cret', wallos_get_db_oidc_settings($db)['client_secret'], 'kept');

    $db->close();
});

wallos_test('clearing the secret takes an explicit request', function () {
    // #124: an empty secret is a legitimate target state — a public client
    // authenticates without one — and the placeholder rule made it unreachable
    // through either save path.
    $db = wallos_test_open_database();
    wallos_save_oidc_settings($db, ['client_secret' => 's3cret'], []);

    $result = wallos_save_oidc_settings(
        $db,
        ['client_secret' => '', 'clear_client_secret' => true],
        []
    );

    assert_true($result['success'], 'the clear succeeded');
    assert_true($result['changed'], 'and counted as a change');
    assert_same('', wallos_get_db_oidc_settings($db)['client_secret'], 'the secret is gone');

    $db->close();
});

wallos_test('a new secret and the clear flag together are refused', function () {
    // A contradiction is refused rather than resolved: a specific refusal is
    // what tells a bad request apart from a broken endpoint.
    $db = wallos_test_open_database();
    wallos_save_oidc_settings($db, ['client_secret' => 'old'], []);

    $result = wallos_save_oidc_settings(
        $db,
        ['client_secret' => 'new', 'clear_client_secret' => true],
        []
    );

    assert_true(!$result['success'], 'refused');
    assert_same('old', wallos_get_db_oidc_settings($db)['client_secret'], 'and nothing was written');

    $db->close();
});

wallos_test('the clear flag does not touch an environment-managed secret', function () {
    // Same rule as every other managed field: the environment is the
    // authority, and accepting an edit the next page load discards would be
    // worse than refusing it.
    $db = wallos_test_open_database();
    wallos_save_oidc_settings($db, ['client_secret' => 'stored'], []);

    wallos_save_oidc_settings(
        $db,
        ['clear_client_secret' => true],
        ['client_secret' => 'OIDC_CLIENT_SECRET']
    );

    assert_same('stored', wallos_get_db_oidc_settings($db)['client_secret'], 'skipped');

    $db->close();
});

wallos_test('the clear flag is reachable through both paths', function () {
    $admin = file_get_contents(WALLOS_ROOT . '/admin.php');
    $script = file_get_contents(WALLOS_ROOT . '/scripts/admin.js');
    $ui = file_get_contents(WALLOS_ROOT . '/endpoints/admin/saveoidcsettings.php');
    $api = file_get_contents(WALLOS_ROOT . '/api/admin/set_oidc_settings.php');

    assert_true(strpos($admin, 'oidcClearClientSecret') !== false, 'the checkbox is rendered');
    assert_true(strpos($script, 'oidcClearClientSecret') !== false, 'the save button submits it');
    assert_true(strpos($ui, 'clear_client_secret') !== false, 'the interface endpoint passes it on');
    assert_true(strpos($api, 'clear_client_secret') !== false, 'and so does the API');
});
