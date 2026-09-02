<?php
/*
  Who may reach a private target, and how the question is asked (#126;
  upstream #1153 and #1138, answered upstream in 5.5.0).

  The gate hard-blocked every private address for anyone but account number
  one before it ever read the allowlist, so the allowlist an administrator
  maintained never applied to the people it was maintained for — upstream
  #1138 says it outright: blocked "even if they were on the whitelist".

  Upstream 5.5.0 answers that with an explicit instance setting rather than
  by reordering: `allow_standard_users_local_webhooks`, off by default. The
  fork follows it. The setting is strictly more expressive than the order
  this fork briefly used — switched on it is exactly that behaviour, switched
  off it is the stricter one — and an allowlist entered for the
  administrator's own internal service no longer silently becomes reachable
  by every account on a shared instance.

  What remains from #126 is the other half, which upstream cannot have: the
  gate asks whether the caller is an administrator through the role model,
  not whether the account number is one. A second administrator is an
  administrator — the pattern tests/cases/user_roles_test.php exists to
  forbid.
*/

require_once WALLOS_ROOT . '/includes/ssrf_helper.php';
require_once WALLOS_ROOT . '/includes/user_roles.php';

/**
 * Puts one entry on the instance allowlist.
 *
 * The bindings carry no type constant, and the parameters are not named after
 * one backend's class: the boundary audit reads words, comments included, and
 * a fixture is not the place to teach it that this file needs an exception.
 *
 * @param object $db
 * @param string $entry
 */
function ssrf_allow($db, $entry)
{
    $stmt = $db->prepare('UPDATE admin SET local_webhook_notifications_allowlist = :list');
    $stmt->bindValue(':list', (string) $entry);
    $stmt->execute();
}

/**
 * Sets the instance opt-in that lets standard accounts use the allowlist.
 *
 * @param object $db
 * @param bool   $allowed
 */
function ssrf_allow_standard_users($db, $allowed)
{
    $stmt = $db->prepare('UPDATE admin SET allow_standard_users_local_webhooks = :allowed');
    $stmt->bindValue(':allowed', $allowed ? 1 : 0);
    $stmt->execute();
}

wallos_test('a standard account is refused a private target until the instance opts in', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'admin');
    wallos_test_create_user($db, 2, 'bob');
    ssrf_allow($db, '10.1.2.3');

    assert_true(is_url_safe_for_ssrf('http://10.1.2.3/hook', $db, 2) === false,
        'listing the target is not on its own enough for a standard account');

    ssrf_allow_standard_users($db, true);
    $safe = is_url_safe_for_ssrf('http://10.1.2.3/hook', $db, 2);

    assert_true(is_array($safe), 'with the opt-in set, the listed target passes');
    assert_same('10.1.2.3', (string) $safe['ip'], 'and resolves to itself');

    $db->close();
});

wallos_test('an administrator reaches the listed target without the opt-in', function () {
    // The account-number check called exactly one account an administrator.
    // This fork has a role model, and every administrator has to pass here.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'admin');
    wallos_test_create_user($db, 2, 'bob');
    ssrf_allow($db, '10.1.2.3');
    ssrf_allow_standard_users($db, false);

    assert_true(is_url_safe_for_ssrf('http://10.1.2.3/hook', $db, 2) === false,
        'as a standard account, bob is refused');

    wallos_grant_role($db, 2, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);

    $safe = is_url_safe_for_ssrf('http://10.1.2.3/hook', $db, 2);
    assert_true(is_array($safe), 'the same account, now an administrator, is not');

    $db->close();
});

wallos_test('an unlisted private target stays blocked for everyone', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'admin');
    wallos_test_create_user($db, 2, 'bob');
    ssrf_allow($db, '10.1.2.3');
    ssrf_allow_standard_users($db, true);

    assert_true(is_url_safe_for_ssrf('http://10.9.9.9/hook', $db, 2) === false,
        'a standard account is refused even with the opt-in set');
    assert_true(is_url_safe_for_ssrf('http://10.9.9.9/hook', $db, null) === false,
        'and so is a caller with no account at all');

    wallos_grant_role($db, 2, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);

    assert_true(is_url_safe_for_ssrf('http://10.9.9.9/hook', $db, 2) === false,
        'an administrator is refused too — the opt-in says who may use the list, '
        . 'never what is on it');

    $db->close();
});

wallos_test('the gate decides by role, never by account number one', function () {
    // user_roles_test forbids authorizing on $userId == 1; the SSRF gate was
    // the last place still doing it, and upstream's 5.5.0 opt-in arrived
    // written that way, so this guard is what keeps the merge honest.
    $source = file_get_contents(WALLOS_ROOT . '/includes/ssrf_helper.php');

    assert_true(strpos($source, '!= 1') === false,
        'no account-number check remains');
    assert_true(strpos($source, 'wallos_user_is_admin') !== false,
        'the role model decides instead');
});
