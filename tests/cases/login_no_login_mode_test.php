<?php
/*
  login.php's single-user no-login branch, and the exit it was missing (F6).

  When admin.login_disabled is set, login.php signs user 1 in with no
  credentials and redirects. Both arms of that branch — the one that finds the
  admin account missing and re-enables login, and the one that signs the user in
  — closed $db and set a redirect header, then fell through. Execution continued
  past the branch into code that calls wallos_get_effective_oidc_configuration($db)
  and renders the login form, reusing a database it had just closed. The fix is
  an exit() after each redirect.

  This is a source-structural check rather than a behavioural one: login.php is a
  full page render with session, cookie and i18n side effects that a harness
  would have to measure around rather than through. The check is scoped to the
  branch and fails if either exit() is removed.
*/

wallos_test('login.php exits after the no-login redirect instead of reusing a closed database',
    function () {
        $source = file_get_contents(WALLOS_ROOT . '/login.php');

        $start = strpos($source, "if (\$adminRow['login_disabled'] == 1) {");
        assert_true($start !== false, 'the no-login branch is present');

        // The branch runs up to the next statement after it. Scoping the search
        // to the block keeps the identical redirects elsewhere in the file (the
        // already-logged-in redirect above it, the password-login redirect below)
        // out of the assertion.
        $blockEnd = strpos($source, "if (isset(\$_SESSION['totp_user_id']))", $start);
        assert_true($blockEnd !== false && $blockEnd > $start, 'the branch is bounded');
        $block = substr($source, $start, $blockEnd - $start);

        // Each arm must exit after its redirect. Remove either exit() and the
        // corresponding match drops to zero and this fails.
        assert_true(
            preg_match('/header\("Location: login\.php"\);\s*exit\(\);/', $block) === 1,
            'the missing-admin arm exits after redirecting');
        assert_true(
            preg_match('/header\("Location: \."\);\s*exit\(\);/', $block) === 1,
            'the signed-in arm exits after redirecting');

        // The reuse the exits protect is genuinely downstream: the closed-then-
        // reused call sits after the branch, which is what makes a fall-through a
        // bug rather than a harmless tidiness point.
        $reuse = strpos($source, 'wallos_get_effective_oidc_configuration($db)');
        assert_true($reuse !== false && $reuse > $blockEnd,
            'the $db reuse is after the branch, so a fall-through would reach a closed handle');
    }
);
