<?php
/*
  The SSRF gate and the allowlist, in the right order (#126; upstream #1153
  and #1138).

  The gate hard-blocked every private address for anyone but account number
  one BEFORE it ever read the allowlist, so the allowlist an administrator
  maintained for exactly this purpose never applied to the people it was
  maintained for — upstream #1138 says it outright: blocked "even if they
  were on the whitelist". validate_smtp_host always did it right. And the
  account-number check ignored this fork's own role model: a second
  administrator was blocked like anyone else, the pattern
  tests/cases/user_roles_test.php exists to forbid.

  The rule now: an allowlisted private target is allowed for every account —
  listing it was the administrative decision. An unlisted private target
  stays blocked for everyone; only the message differs, because an
  administrator can fix the list and a standard user can only ask.
*/

require_once WALLOS_ROOT . '/includes/ssrf_helper.php';
require_once WALLOS_ROOT . '/includes/user_roles.php';

/**
 * Puts one entry on the instance allowlist.
 *
 * @param SQLite3 $db
 * @param string  $entry
 */
function ssrf_allow($db, $entry)
{
    $stmt = $db->prepare('UPDATE admin SET local_webhook_notifications_allowlist = :list');
    $stmt->bindValue(':list', $entry, SQLITE3_TEXT);
    $stmt->execute();
}

wallos_test('an allowlisted private target is allowed for every account', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'admin');
    wallos_test_create_user($db, 2, 'bob');
    ssrf_allow($db, '10.1.2.3');

    $safe = is_url_safe_for_ssrf('http://10.1.2.3/hook', $db, 2);

    assert_true(is_array($safe), 'the listed target passes for a standard account');
    assert_same('10.1.2.3', (string) $safe['ip'], 'and resolves to itself');

    $db->close();
});

wallos_test('an unlisted private target stays blocked for everyone', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'admin');
    wallos_test_create_user($db, 2, 'bob');
    ssrf_allow($db, '10.1.2.3');

    assert_true(is_url_safe_for_ssrf('http://10.9.9.9/hook', $db, 2) === false,
        'a standard account is refused');
    assert_true(is_url_safe_for_ssrf('http://10.9.9.9/hook', $db, null) === false,
        'and so is a caller with no account at all');

    $stmt = $db->prepare("INSERT INTO user_roles (user_id, role, source) VALUES (2, 'admin', 'local')");
    $stmt->execute();

    assert_true(is_url_safe_for_ssrf('http://10.9.9.9/hook', $db, 2) === false,
        'an administrator is refused too — the list is the decision, not the role');

    $db->close();
});

wallos_test('the gate decides by role, never by account number one', function () {
    // user_roles_test forbids authorizing on $userId == 1; the SSRF gate was
    // the last place still doing it.
    $source = file_get_contents(WALLOS_ROOT . '/includes/ssrf_helper.php');

    assert_true(strpos($source, '!= 1') === false,
        'no account-number check remains');
    assert_true(strpos($source, 'wallos_user_is_admin') !== false,
        'the role model decides instead');
});
