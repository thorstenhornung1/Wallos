<?php
/*
  Who may reach the admin page (issue #173).

  admin.php decided this itself, after header.php had printed the document, so
  the Location header was discarded and a non-admin got a page that stopped
  after the navigation instead of being sent away. The content was never
  exposed — the exit still ran — but the redirect was decoration.

  Moving an authorization guard is the kind of change that must not be taken on
  trust, so these cases pin what it now depends on: the redirect runs from
  checkredirect.php (before any output), it asks the role store rather than a
  variable that does not exist yet at that point, and admin.php still refuses to
  render on its own if it is ever reached anyway.
*/

require_once WALLOS_ROOT . '/includes/user_roles.php';

wallos_test('a non-admin is turned away before the admin page renders', function () {
    $redirects = file_get_contents(WALLOS_ROOT . '/includes/checkredirect.php');

    assert_contains("\$currentPage == 'admin.php'", $redirects,
        'checkredirect.php handles the admin page');
    assert_contains('wallos_user_is_admin($db, $userId)', $redirects,
        'and asks the role store, not $isAdmin — which header.php sets later');
    // Not the bare name — the comment above the guard explains why it is not
    // used, so this looks for an actual read of it.
    assert_not_contains('($isAdmin', $redirects,
        'reading $isAdmin here would test an undefined variable, and !undefined is true');
    assert_not_contains('$isAdmin !=', $redirects, 'nor comparing it');
});

wallos_test('the admin page still refuses to render on its own', function () {
    // Defence in depth: the guard above is what redirects, but admin.php must
    // not become a page that renders whatever it is handed simply because
    // something upstream was expected to stop it.
    $admin = file_get_contents(WALLOS_ROOT . '/admin.php');

    assert_contains('if ($isAdmin != 1) {', $admin, 'the page checks the flag itself');
    assert_contains('exit;', $admin, 'and stops rather than continuing');
    assert_not_contains("header('Location", $admin,
        'without attempting a redirect it cannot perform at that point');
});

wallos_test('the role lookup refuses rather than failing open', function () {
    // The property the redirect now rests on. wallos_user_is_admin() answers
    // "no" for an absent user and for a missing role table — a lookup that
    // cannot answer must never grant admin.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5101, 'plain-user');

    assert_true(!wallos_user_is_admin($db, 5101),
        'an account with no admin role is not an admin');
    assert_true(!wallos_user_is_admin($db, 0),
        'no session, no admin');
    assert_true(!wallos_user_is_admin($db, 999999),
        'an account that does not exist is not an admin');

    $db->close();
});
