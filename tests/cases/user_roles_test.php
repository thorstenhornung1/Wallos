<?php
/*
  Administrator rights as a role, not as an id.

  Wallos used to define "administrator" as `id == 1`. Whoever landed in the
  database first held the role, it could not be granted or revoked, there could
  only ever be one, and with OIDC auto-provisioning it went to whoever happened
  to authenticate first.

  The rule these cases enforce: authorization is a row, and an OIDC
  synchronisation can never take away a local administrator.
*/

require_once WALLOS_ROOT . '/includes/user_roles.php';
require_once WALLOS_ROOT . '/includes/api_admin.php';

/**
 * @param SQLite3 $db
 * @param int     $userId
 */
function roles_make_local_admin($db, $userId)
{
    wallos_grant_role($db, $userId, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);
}

/**
 * @param SQLite3 $db
 * @param int     $userId
 */
function roles_make_oidc_admin($db, $userId)
{
    wallos_grant_role($db, $userId, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC);
}

/**
 * Gives a user an API key without going through the application.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param string  $key
 */
function roles_set_api_key($db, $userId, $key)
{
    $stmt = $db->prepare('UPDATE "user" SET api_key = :key WHERE id = :id');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

// ---------------------------------------------------------------- migration

wallos_test('the migration gives an existing user 1 the local admin role', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    // The fixture database runs createdatabase.php and every migration, and
    // createdatabase.php seeds no users — so migration 000058 had nobody to
    // promote. Running it again against a database that does have user 1 is
    // what upgrading a real installation looks like.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    require WALLOS_ROOT . '/migrations/000058.php';

    assert_true(wallos_user_is_admin($db, 1), 'the existing administrator keeps the role');
    assert_same(['local'], wallos_user_admin_sources($db, 1),
        'granted locally, so an OIDC sync cannot revoke it');

    $db->close();
});

wallos_test('the role migration can run twice', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    require WALLOS_ROOT . '/migrations/000058.php';
    require WALLOS_ROOT . '/migrations/000058.php';

    assert_same(1, wallos_count_admins($db), 'still exactly one administrator');

    $db->close();
});

wallos_test('a fresh install does not hand the role to whoever is first', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    // No user rows at all: the migration must not create a role pointing at an
    // account that does not exist, and the first account to appear afterwards —
    // an OIDC login, say — must not inherit the installation.
    $db = wallos_test_open_database();

    require WALLOS_ROOT . '/migrations/000058.php';
    wallos_test_create_user($db, 1, 'whoever-logged-in-first');

    assert_same(0, wallos_count_admins($db), 'nobody is an administrator yet');
    assert_true(!wallos_user_is_admin($db, 1), 'being user 1 is not an authorization decision');

    $db->close();
});

wallos_test('the first locally registered account can administer', function () {
    // Otherwise a new installation has an administration area nobody can reach.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    assert_true(wallos_claim_first_admin($db, 1), 'the first account takes the role');
    assert_true(wallos_user_is_admin($db, 1), 'and can administer');

    $db->close();
});

wallos_test('the second account does not take the role', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    wallos_claim_first_admin($db, 1);

    assert_true(!wallos_claim_first_admin($db, 2), 'an administrator already exists');
    assert_true(!wallos_user_is_admin($db, 2), 'bob is an ordinary user');

    $db->close();
});

wallos_test('an installation whose first account was deleted keeps an administrator', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    // The realistic upgrade trap. Delete the original account, sign in through
    // OIDC, and the new account gets id 7 — SQLite never reuses ids. Migration
    // 000058 then finds no user 1 and gives the role to nobody, leaving the
    // admin area unreachable.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 7, 'signed-up-later');

    require WALLOS_ROOT . '/migrations/000058.php';
    assert_same(0, wallos_count_admins($db), 'nobody yet, because there is no user 1');

    require WALLOS_ROOT . '/migrations/000062.php';

    assert_same(1, wallos_count_admins($db), 'the oldest surviving account takes it');
    assert_true(wallos_user_is_admin($db, 7), 'and can reach the admin area');

    $db->close();
});

wallos_test('the repair never overrides an existing administrator', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 3, 'carol');
    wallos_test_create_user($db, 9, 'dave');
    wallos_grant_role($db, 9, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);

    require WALLOS_ROOT . '/migrations/000062.php';

    assert_same(1, wallos_count_admins($db), 'still one administrator');
    assert_true(wallos_user_is_admin($db, 9), 'the one who already had it');
    assert_true(!wallos_user_is_admin($db, 3), 'the older account was not promoted');

    $db->close();
});

wallos_test('the repair does nothing on an empty installation', function () {
    if (wallos_test_skip_unless_sqlite('replays a SQLite migration')) {
        return;
    }

    $db = wallos_test_open_database();

    require WALLOS_ROOT . '/migrations/000062.php';

    assert_same(0, wallos_count_admins($db), 'no users, no roles');

    $db->close();
});

// ------------------------------------------------------------------ helper

wallos_test('the admin helper answers for every combination of sources', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    assert_true(!wallos_user_is_admin($db, 1), 'no role at all');

    roles_make_local_admin($db, 1);
    assert_true(wallos_user_is_admin($db, 1), 'local role');

    wallos_revoke_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);
    roles_make_oidc_admin($db, 1);
    assert_true(wallos_user_is_admin($db, 1), 'oidc role');

    roles_make_local_admin($db, 1);
    assert_true(wallos_user_is_admin($db, 1), 'both roles');
    assert_same(['local', 'oidc'], wallos_user_admin_sources($db, 1), 'both sources recorded');

    $db->close();
});

wallos_test('losing the OIDC claim does not remove a local administrator', function () {
    // The reason `source` exists. A provider that stops sending the admin claim
    // must not be able to lock the local recovery administrator out.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    roles_make_local_admin($db, 1);
    roles_make_oidc_admin($db, 1);

    wallos_revoke_role($db, 1, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_OIDC);

    assert_true(wallos_user_is_admin($db, 1), 'the local grant survives');
    assert_same(['local'], wallos_user_admin_sources($db, 1), 'only the OIDC row went');

    $db->close();
});

wallos_test('granting the same role twice is not an error', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    roles_make_local_admin($db, 1);
    roles_make_local_admin($db, 1);

    assert_same(['local'], wallos_user_admin_sources($db, 1), 'one row, not two');

    $db->close();
});

wallos_test('an administrator holding two sources counts once', function () {
    // Counting rows here would report two administrators and make it look safe
    // to delete the only one.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    roles_make_local_admin($db, 1);
    roles_make_oidc_admin($db, 1);

    assert_same(1, wallos_count_admins($db), 'one person, two grants');
    assert_true(wallos_is_last_admin($db, 1), 'and deleting them would leave nobody');

    $db->close();
});

wallos_test('the last administrator is protected, earlier ones are not', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    wallos_test_create_user($db, 3, 'carol');
    roles_make_local_admin($db, 1);
    roles_make_local_admin($db, 2);

    assert_true(!wallos_is_last_admin($db, 1), 'two administrators, so alice may go');
    assert_true(!wallos_is_last_admin($db, 3), 'carol is not an administrator at all');

    wallos_revoke_role($db, 2, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);

    assert_true(wallos_is_last_admin($db, 1), 'now alice is the only way in');

    $db->close();
});

wallos_test('multiple administrators are possible', function () {
    // Impossible under the old rule: there was only ever one user with id 1.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 7, 'bob');
    roles_make_local_admin($db, 1);
    roles_make_local_admin($db, 7);

    assert_same(2, wallos_count_admins($db), 'two administrators');
    assert_true(wallos_user_is_admin($db, 7), 'an id far from 1 administers');

    $db->close();
});

// ----------------------------------------------------------- API authorization

wallos_test('the API admits an administrator whose id is not 1', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5, 'bob');
    roles_set_api_key($db, 5, 'bob-key');
    roles_make_local_admin($db, 5);

    $verdict = wallos_resolve_admin_api_user($db, 'bob-key');

    assert_true($verdict['ok'], 'admitted');
    assert_same(5, (int) $verdict['user']['id'], 'and it is bob');

    $db->close();
});

wallos_test('the API refuses a non-administrator whose id is 1', function () {
    // The case the old code could not express at all.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    roles_set_api_key($db, 1, 'alice-key');

    $verdict = wallos_resolve_admin_api_user($db, 'alice-key');

    assert_true(!$verdict['ok'], 'refused');
    assert_same('not_admin', $verdict['reason'], 'and says why');

    $db->close();
});

wallos_test('the API refuses a missing or unknown key', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    roles_make_local_admin($db, 1);

    assert_same('missing_key', wallos_resolve_admin_api_user($db, null)['reason'], 'no key');
    assert_same('missing_key', wallos_resolve_admin_api_user($db, '')['reason'], 'empty key');
    assert_same('unknown_key', wallos_resolve_admin_api_user($db, 'nope')['reason'], 'wrong key');

    $db->close();
});

wallos_test('an unreadable role table denies rather than admits', function () {
    // If the migration has not run, the lookup fails. Failing open would turn a
    // deployment mistake into an authorization hole.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    roles_make_local_admin($db, 1);
    $db->query('DROP TABLE user_roles');

    assert_true(!wallos_user_is_admin($db, 1), 'no table means no administrator');
    assert_same(0, wallos_count_admins($db), 'and no count');

    $db->close();
});

// -------------------------------------------------------------- consolidation

wallos_test('no admin authorization is left comparing against id 1', function () {
    $paths = [
        'includes/validate_endpoint_admin.php',
        'includes/header.php',
        'endpoints/admin/deleteuser.php',
        'api/admin/get_admin_settings.php',
        'api/admin/set_admin_settings.php',
        'api/admin/get_oidc_settings.php',
        'api/admin/set_oidc_settings.php',
        'api/admin/set_disable_password_login.php',
        'admin.php',
    ];

    foreach ($paths as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        assert_true(
            preg_match('/\$userId\s*[!=]==?\s*1\b/', $source) !== 1,
            $path . ' must not authorize on $userId == 1'
        );
        assert_true(
            preg_match('/\$user\[.id.\]\s*[!=]==?\s*1\b/', $source) !== 1,
            $path . ' must not authorize on $user[id] == 1'
        );
        assert_true(
            preg_match('/\$_SESSION\[.userId.\]\s*[!=]==?\s*1\b/', $source) !== 1,
            $path . ' must not authorize on the session id'
        );
    }
});

wallos_test('every admin API endpoint goes through the shared guard', function () {
    foreach (glob(WALLOS_ROOT . '/api/admin/*.php') as $endpoint) {
        $source = file_get_contents($endpoint);

        assert_true(
            strpos($source, 'wallos_require_admin_api_user') !== false,
            basename($endpoint) . ' should use the shared admin guard'
        );
    }
});

wallos_test('OIDC provisioning does not hand out the admin role', function () {
    // Deliberate: an account created because somebody authenticated must not
    // inherit the installation. Local registration claims the first admin role;
    // OIDC does not.
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/oidc_create_user.php');

    assert_true(
        strpos($source, 'wallos_claim_first_admin') === false,
        'OIDC provisioning must not claim the administrator role'
    );
    assert_true(
        strpos($source, 'wallos_grant_role') === false,
        'OIDC provisioning must not grant roles at creation time'
    );
});
