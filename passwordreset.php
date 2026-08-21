<?php

require_once 'includes/connect.php';
require_once 'includes/password_reset.php';
require_once 'includes/checkuser.php';
require_once 'includes/integration_config.php';

require_once 'includes/i18n/languages.php';
require_once 'includes/i18n/getlang.php';
require_once 'includes/i18n/' . $lang . '.php';

require_once 'includes/version.php';

$secondsInMonth = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,             
        'httponly' => true,          
        'samesite' => 'Lax'          
    ]);
    session_start();
}
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $db->close();
    header("Location: .");
    exit();
}

$requestMode = true;
$resetMode = false;

$theme = "light";
if (isset($_COOKIE['theme'])) {
    $theme = $_COOKIE['theme'];
}

$colorTheme = "blue";
if (isset($_COOKIE['colorTheme'])) {
    $colorTheme = $_COOKIE['colorTheme'];
}

$settings = $db->querySingle("SELECT * FROM admin", true);

// Password reset needs a usable instance transport and a server_url to build
// the link with. server_url is empty on a fresh installation — the default
// state — so on an instance nobody configured for this, the whole feature is
// inert.
//
// It used to redirect to the front page and say nothing, which is
// indistinguishable from a broken feature: the form answers, the submission
// redirects, no token appears and no message explains it. Two full attempts
// were recorded as failures during a test run before the cause was found by
// reading this file (issue #96). login.php already hides the link when the
// feature is off; this is for everyone who arrives with the URL anyway.
if (!wallos_get_instance_smtp_config($db)['valid'] || $settings['server_url'] == "") {
    header("Location: login.php?reset=unavailable");
    exit();
} else {
    $resetPasswordEnabled = true;
}

$hasSuccessMessage = false;
$hasErrorMessage = false;
$passwordsMismatch = false;
$resetFailed = false;
$hideForm = false;

// A reset token is good for one hour. The window is computed here rather than
// with SQLite's datetime('now', '-1 hour'), which PostgreSQL does not have;
// gmdate() rather than date() because created_at is written by the column's
// CURRENT_TIMESTAMP default, which is UTC whatever timezone PHP is set to.
$tokenValidAfter = gmdate('Y-m-d H:i:s', time() - 3600);

if (isset($_POST['email']) && $_POST['email'] != "" && isset($_GET['submit']) && $_GET['submit'] && !(isset($_GET['token'])) && !(isset($_POST['token']))) {
    $requestMode = true;
    $resetMode = false;
    $email = $_POST['email'];

    $stmt = $db->prepare("SELECT * FROM \"user\" WHERE email = :email");
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    // Nothing here may depend on whether the address is registered — that is
    // why the success message is set for both. It is also why the failure below
    // uses the generic error: it says a request could not be processed, not
    // that an account exists.
    $issued = true;

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $issued = wallos_issue_password_reset($db, $user['id'], $email, $token);
    }

    if ($issued) {
        $hasSuccessMessage = true;
    } else {
        $hasErrorMessage = true;
    }
}

if (isset($_GET['token']) && $_GET['token'] != "" && isset($_GET['email']) && $_GET['email'] != "") {
    $requestMode = false;
    $resetMode = true;
    $token = $_GET['token'];
    $email = $_GET['email'];
    $matchCount = "SELECT COUNT(*) FROM password_resets WHERE token = :token AND email = :email AND created_at > :validAfter";
    $stmt = $db->prepare($matchCount);
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':validAfter', $tokenValidAfter);
    $count = $stmt->execute()->fetchArray(SQLITE3_NUM);
    if ($count[0] == 0) {
        $hasErrorMessage = true;
        $hideForm = true;
    }
}

if (isset($_POST['password']) && $_POST['password'] != "" && isset($_POST['confirm_password']) && $_POST['confirm_password'] != "" && isset($_GET['submit']) && $_GET['submit']) {
    $requestMode = false;
    $resetMode = true;
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];
    $token = $_POST['token'];
    $email = $_POST['email'];
    $resetQuery = "SELECT * FROM password_resets WHERE token = :token AND email = :email AND created_at > :validAfter";
    $stmt = $db->prepare($resetQuery);
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':validAfter', $tokenValidAfter);
    $reset = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($reset) {
        $stmt = $db->prepare("SELECT * FROM \"user\" WHERE email = :email");
        $stmt->bindValue(':email', $reset['email'], SQLITE3_TEXT);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($password == $confirmPassword) {
            // The new password is written first and checked, and the token is
            // only consumed once that succeeded.
            //
            // Both statements used to be unchecked with the success message set
            // unconditionally afterwards. A failed UPDATE told the user the
            // reset had worked while their old password — the one they came
            // here because they had forgotten — still applied, and the token was
            // spent, so requesting a new link produced the same outcome again.
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE \"user\" SET password = :password WHERE id = :id");
            $passwordChanged = false;

            if ($stmt !== false) {
                $stmt->bindValue(':password', $passwordHash, SQLITE3_TEXT);
                $stmt->bindValue(':id', $user['id'], SQLITE3_INTEGER);
                $passwordChanged = $stmt->execute() !== false && $db->changes() > 0;
            }

            if (!$passwordChanged) {
                error_log('Wallos password reset: the password was not changed for user '
                    . $user['id'] . ': ' . $db->lastErrorMsg());
                $hasErrorMessage = true;
                $resetFailed = true;
            } else {
                // Only now is the token spent. A token that survives a failed
                // reset can be used again; one consumed by a failed reset
                // cannot, and the user has no way to tell which happened.
                $stmt = $db->prepare("DELETE FROM password_resets WHERE token = :token");
                if ($stmt !== false) {
                    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
                    if ($stmt->execute() === false) {
                        // The password did change, so this is not a failure the
                        // user can act on — but the token now outlives its use.
                        error_log('Wallos password reset: the used token was not cleared: '
                            . $db->lastErrorMsg());
                    }
                }

                $hasSuccessMessage = true;
                $hideForm = true;
            }
        } else {
            $hasErrorMessage = true;
            $passwordsMismatch = true;
        }
    } else {
        $hasSuccessMessage = false;
        $hasErrorMessage = true;
    }
}

?>
<!DOCTYPE html>
<html dir="<?= $languages[$lang]['dir'] ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="<?= $theme == "light" ? "#FFFFFF" : "#12151C" ?>" />
    <meta name="apple-mobile-web-app-title" content="Wallos">
    <title>Wallos - Subscription Tracker</title>
    <link rel="icon" type="image/png" href="images/icon/favicon.ico" sizes="16x16">
    <link rel="apple-touch-icon" href="images/icon/apple-touch-icon.png">
    <link rel="apple-touch-icon" sizes="152x152" href="images/icon/apple-touch-icon-152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="images/icon/apple-touch-icon-180.png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="styles/theme.css?<?= $version ?>">
    <link rel="stylesheet" href="styles/login.css?<?= $version ?>">
    <link rel="stylesheet" href="styles/themes/red.css?<?= $version ?>" id="red-theme" <?= $colorTheme != "red" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/themes/green.css?<?= $version ?>" id="green-theme" <?= $colorTheme != "green" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/themes/yellow.css?<?= $version ?>" id="yellow-theme" <?= $colorTheme != "yellow" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/themes/purple.css?<?= $version ?>" id="purple-theme" <?= $colorTheme != "purple" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/font-awesome.min.css">
    <link rel="stylesheet" href="styles/barlow.css">
    <link rel="stylesheet" href="styles/login-dark-theme.css?<?= $version ?>" id="dark-theme" <?= $theme == "light" ? "disabled" : "" ?>>
    <script type="text/javascript" src="scripts/auth-theme.js?<?= $version ?>"></script>
    <script type="text/javascript" src="scripts/password-toggle.js?<?= $version ?>"></script>
</head>

<body class="<?= $languages[$lang]['dir'] ?>">
    <button type="button" class="theme-toggle" id="theme-toggle" title="<?= translate('theme', $i18n) ?>"
        aria-label="<?= translate('theme', $i18n) ?>">
        <i class="fa-solid <?= $theme == "dark" ? "fa-sun" : "fa-moon" ?>"></i>
    </button>
    <div class="content auth-split">
        <aside class="auth-brand" aria-hidden="true">
            <div class="auth-brand-logo">
                <?php include "images/siteicons/svg/logo.php"; ?>
            </div>
            <div class="auth-brand-text">
                <h1><?= translate('auth_tagline', $i18n) ?></h1>
                <p><?= translate('auth_tagline_sub', $i18n) ?></p>
            </div>
            <div class="auth-brand-footer">Wallos &mdash; Subscription Tracker</div>
        </aside>
        <section class="container">
            <header>
                <div class="logo-image" title="Wallos - Subscription Tracker">
                    <?php include "images/siteicons/svg/logo.php"; ?>
                </div>
                <p>
                    <?= translate('reset_password', $i18n) ?>
                </p>
            </header>
            <form action="passwordreset.php?submit=true" method="post">
                <?php
                if ($requestMode) {
                    if (!$hideForm) {
                        ?>
                        <div class="form-group">
                            <label for="email"><?= translate('email', $i18n) ?>:</label>
                            <input type="text" id="email" name="email" autocomplete="email" required>
                        </div>
                        <div class="form-group">
                            <input type="submit" value="<?= translate('reset_password', $i18n) ?>">
                        </div>
                        <?php
                    }
                    if ($hasSuccessMessage) {
                        ?>
                        <ul class="success-box">
                            <li><i class="fa-solid fa-check"></i><?= translate('reset_sent_check_email', $i18n) ?></li>
                        </ul>
                        <?php
                    }
                    if ($hasErrorMessage) {
                        ?>
                        <ul class="error-box">
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('error', $i18n) ?></li>
                        </ul>
                        <?php
                    }
                }
                if ($resetMode) {
                    if (!$hideForm) {
                        ?>
                        <div class="form-group">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <label for="password"><?= translate('password', $i18n) ?>:</label>
                            <input type="password" id="password" name="password" autocomplete="new-password" required>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password"><?= translate('confirm_password', $i18n) ?>:</label>
                            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                        </div>
                        <div class="form-group">
                            <input type="submit" value="<?= translate('reset_password', $i18n) ?>">
                        </div>
                        <?php
                    }
                    if ($hasErrorMessage) {
                        if ($passwordsMismatch) {
                            ?>
                            <ul class="error-box">
                                <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('passwords_dont_match', $i18n) ?>
                                </li>
                            </ul>
                            <?php
                        } else {
                            ?>
                            <ul class="error-box">
                                <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('error', $i18n) ?></li>
                            </ul>
                            <?php
                        }
                    }
                    if ($hasSuccessMessage) {
                        ?>
                        <ul class="success-box">
                            <li><i class="fa-solid fa-check"></i><?= translate('password_reset_successful', $i18n) ?></li>
                        </ul>
                        <?php
                    }
                }
                ?>
                <div class="login-form-link">
                    <a href="login.php"><?= translate('login', $i18n) ?></a>
                </div>
            </form>
        </section>
    </div>
    <script type="text/javascript">
        function openRegitrationPage() {
            window.location.href = "registration.php";
        }
    </script>
</body>

</html>