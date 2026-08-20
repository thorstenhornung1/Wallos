<?php
require_once 'includes/connect.php';
require_once 'includes/checkuser.php';
require_once 'includes/oidc_settings.php';
require_once 'includes/integration_config.php';

require_once 'includes/i18n/languages.php';
require_once 'includes/i18n/getlang.php';
require_once 'includes/i18n/' . $lang . '.php';

require_once 'includes/version.php';

if ($userCount == 0) {
    header("Location: registration.php");
    exit();
}

$secondsInMonth = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,             
        'httponly' => true,          
        'samesite' => 'Lax'          
    ]);
    session_start();
}
// A provider configured with login.php as its redirect URI lands here rather
// than on the document root. Consumed before anything else, because a callback
// arrives unauthenticated and would otherwise fall through to the login form.
require_once 'includes/oidc/consume_oidc_callback.php';

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $db->close();
    header("Location: .");
    exit();
}

$demoMode = getenv('DEMO_MODE');

$cookieExpire = time() + (30 * 24 * 60 * 60);

// Check if login is disabled
$adminQuery = "SELECT login_disabled FROM admin";
$adminResult = $db->query($adminQuery);
$adminRow = $adminResult->fetchArray(SQLITE3_ASSOC);
if ($adminRow['login_disabled'] == 1) {

    $query = "SELECT id, username, main_currency, language FROM \"user\" WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', 1, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row === false) {
        // Something is wrong with admin user. Reenable login
        //
        // This is the way out of an installation nobody can sign in to, so a
        // failure here leaves the person in a redirect loop with no clue why.
        // It cannot be repaired from the browser, but it can be found in the
        // log rather than guessed at.
        $updateQuery = "UPDATE admin SET login_disabled = 0";
        $updateStmt = $db->prepare($updateQuery);

        if ($updateStmt === false || $updateStmt->execute() === false) {
            error_log('Wallos login: login is disabled and the administrator account is missing, '
                . 'and re-enabling login failed: ' . $db->lastErrorMsg());
        }

        $db->close();
        header("Location: login.php");
    } else {
        $userId = $row['id'];
        $main_currency = $row['main_currency'];
        $username = $row['username'];
        $language = $row['language'];

        session_regenerate_id(true);
        $_SESSION['username'] = $username;
        $_SESSION['loggedin'] = true;
        $_SESSION['main_currency'] = $main_currency;
        $_SESSION['userId'] = $userId;
        setcookie('language', $language, [
            'expires' => $cookieExpire,
            'samesite' => 'Lax'
        ]);

        if (!isset($_COOKIE['sortOrder'])) {
            setcookie('sortOrder', 'next_payment', [
                'expires' => $cookieExpire,
                'samesite' => 'Lax'
            ]);
        }

        $query = "SELECT color_theme FROM settings";
        $stmt = $db->prepare($query);
        $result = $stmt->execute();
        $settings = $result->fetchArray(SQLITE3_ASSOC);
        setcookie('colorTheme', $settings['color_theme'], [
            'expires' => $cookieExpire,
            'samesite' => 'Lax',
        ]);

        $cookieValue = $username . "|" . "abc123ABC" . "|" . $main_currency;
        setcookie('wallos_login', $cookieValue, [
            'expires' => $cookieExpire,
            'samesite' => 'Lax',
            'httponly' => true,
        ]);

        $db->close();
        header("Location: .");
    }
}

if (isset($_SESSION['totp_user_id'])) {
    unset($_SESSION['totp_user_id']);
}

if (isset($_SESSION['token'])) {
    unset($_SESSION['token']);
}


$theme = "light";
$updateThemeSettings = false;
if (isset($_COOKIE['theme'])) {
    $theme = $_COOKIE['theme'];
} else {
    $updateThemeSettings = true;
}

$colorTheme = "blue";
if (isset($_COOKIE['colorTheme'])) {
    $colorTheme = $_COOKIE['colorTheme'];
}

// Check if OIDC is enabled and resolve any environment overrides.
$password_login_disabled = false;
$oidcEnabled = false;
$oidcConfiguration = wallos_get_effective_oidc_configuration($db);
$oidcEnabled = $oidcConfiguration['enabled'] == 1 && $oidcConfiguration['is_configured'];
if ($oidcEnabled) {
    $oidcSettings = $oidcConfiguration['settings'];
    $oidc_name = $oidcSettings['name'] ?? '';
    $password_login_disabled = (int) $oidcSettings['password_login_disabled'] === 1;

    // Generate a CSRF-protecting state string
    $secondsInMonth = 30 * 24 * 60 * 60;
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => $secondsInMonth,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['oidc_state'] = $state;

    // Build the OIDC authorization URL
    $params = http_build_query([
        'response_type' => 'code',
        'client_id' => $oidcSettings['client_id'],
        'redirect_uri' => $oidcSettings['redirect_url'],
        'scope' => $oidcSettings['scopes'],
        'state' => $state,
    ]);

    $oidc_auth_url = rtrim($oidcSettings['authorization_url'], '?') . '?' . $params;
}

$loginFailed = false;

// Returning from the provider's end-session endpoint. State is validated when
// the provider returns one; providers are not required to, so an absent state
// is accepted rather than turning a correct logout into an error page.
require_once __DIR__ . '/includes/oidc/logout.php';
$loggedOut = false;
if (isset($_GET['logged_out'])) {
    $loggedOut = wallos_oidc_logout_state_is_valid(
        $_GET['state'] ?? null,
        $_SESSION['oidc_logout_state'] ?? null
    );
    // Single use: the state has served its purpose the moment it is checked.
    unset($_SESSION['oidc_logout_state']);
}

$hasSuccessMessage = (isset($_GET['validated']) && $_GET['validated'] == "true") || (isset($_GET['registered']) && $_GET['registered'] == true) || $loggedOut ? true : false;
$userEmailWaitingVerification = false;
$oidcErrorKey = null;
if (isset($_POST['username']) && isset($_POST['password'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $rememberMe = isset($_POST['remember']) ? true : false;

    $query = "SELECT id, password, main_currency, language FROM \"user\" WHERE username = :username";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row) {
        $hashedPasswordFromDb = $row['password'];
        $userId = $row['id'];
        $main_currency = $row['main_currency'];
        $language = $row['language'];
        if (password_verify($password, $hashedPasswordFromDb)) {

            // Check if the user is in the email_verification table
            $query = "SELECT 1 FROM email_verification WHERE user_id = :userId";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $verificationMissing = $result->fetchArray(SQLITE3_ASSOC);

            // Check if the user has 2fa enabled
            $query = "SELECT totp_enabled FROM \"user\" WHERE id = :userId";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $totpEnabled = $result->fetchArray(SQLITE3_ASSOC);

            if ($verificationMissing) {
                $userEmailWaitingVerification = true;
                $loginFailed = true;
            } else {
                if ($totpEnabled['totp_enabled'] == 1) {
                    $_SESSION['totp_user_id'] = $userId;
                    if ($rememberMe) {
                        $_SESSION['pending_remember_me'] = true; // defer cookie until TOTP done
                    }
                    $db->close();
                    header("Location: totp.php");
                    exit();
                }

                // No TOTP — safe to create remember-me token now
                if ($rememberMe) {
                    $token = bin2hex(random_bytes(32));
                    $addLoginTokens = "INSERT INTO login_tokens (user_id, token) VALUES (:userId, :token)";
                    $addLoginTokensStmt = $db->prepare($addLoginTokens);
                    $stored = false;

                    if ($addLoginTokensStmt !== false) {
                        $addLoginTokensStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
                        $addLoginTokensStmt->bindParam(':token', $token, SQLITE3_TEXT);
                        $stored = $addLoginTokensStmt->execute() !== false;
                    }

                    // The cookie is only worth having if the row behind it
                    // exists: the next visit looks the token up, finds nothing
                    // and sends the person to the login form — after they were
                    // told they would stay signed in. The insert result used to
                    // go nowhere, so a failure here was invisible until then
                    // (issue #87).
                    if ($stored) {
                        $_SESSION['token'] = $token;
                        $cookieValue = $username . "|" . $token . "|" . $main_currency;
                        setcookie('wallos_login', $cookieValue, [
                            'expires' => $cookieExpire,
                            'samesite' => 'Lax',
                            'httponly' => true,
                        ]);
                    } else {
                        error_log('Wallos login: could not store the remember-me token for user '
                            . $userId . ', so no cookie was set: ' . $db->lastErrorMsg());
                    }
                }

                session_regenerate_id(true);
                $_SESSION['username'] = $username;
                $_SESSION['loggedin'] = true;
                $_SESSION['main_currency'] = $main_currency;
                $_SESSION['userId'] = $userId;
                setcookie('language', $language, [
                    'expires' => $cookieExpire,
                    'samesite' => 'Lax'
                ]);

                if (!isset($_COOKIE['sortOrder'])) {
                    setcookie('sortOrder', 'next_payment', [
                        'expires' => $cookieExpire,
                        'samesite' => 'Lax'
                    ]);
                }

                $query = "SELECT color_theme FROM settings WHERE user_id = :userId";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $settings = $result->fetchArray(SQLITE3_ASSOC);
                setcookie('colorTheme', $settings['color_theme'], [
                    'expires' => $cookieExpire,
                    'samesite' => 'Lax'
                ]);

                $db->close();
                header("Location: .");
                exit();
            }

        } else {
            $loginFailed = true;
        }
    } else {
        $loginFailed = true;
    }
}

//Check if registration is open
$registrations = false;
$resetPasswordEnabled = false;
if (!$password_login_disabled) {
    $adminQuery = "SELECT registrations_open, max_users, server_url FROM admin";
    $adminResult = $db->query($adminQuery);
    $adminRow = $adminResult->fetchArray(SQLITE3_ASSOC);
    $registrationsOpen = $adminRow['registrations_open'];
    $maxUsers = $adminRow['max_users'];

    if ($registrationsOpen == 1 && $maxUsers == 0) {
        $registrations = true;
    } else if ($registrationsOpen == 1 && $maxUsers > 0) {
        $userCountQuery = "SELECT COUNT(id) as \"userCount\" FROM \"user\"";
        $userCountResult = $db->query($userCountQuery);
        $userCountRow = $userCountResult->fetchArray(SQLITE3_ASSOC);
        $userCount = $userCountRow['userCount'];
        if ($userCount < $maxUsers) {
            $registrations = true;
        }
    }

    // Password resets need a usable instance transport, which may come from the
    // environment rather than the database.
    if (wallos_get_instance_smtp_config($db)['valid'] && $adminRow['server_url'] != "") {
        $resetPasswordEnabled = true;
    }
}


if (isset($_GET['error'])) {
    $oidcError = $_GET['error'];
    // Each outcome has its own message: one of them the user can act on by
    // retrying, one they have to take to their provider, and the rest belong to
    // the administrator. "Login failed" for all of them tells nobody anything.
    $oidcErrorMessages = [
        "oidc_user_not_found" => "oidc_user_not_found",
        "oidc_invalid_state" => "oidc_state_mismatch",
        "oidc_state_mismatch" => "oidc_state_mismatch",
        "oidc_session_expired" => "oidc_session_expired",
        "oidc_invalid_response" => "oidc_invalid_response",
        "oidc_email_not_verified" => "oidc_email_not_verified",
        "oidc_invalid_config" => "oidc_invalid_config",
        "oidc_token_exchange_failed" => "oidc_token_exchange_failed",
        "oidc_userinfo_failed" => "oidc_userinfo_failed",
    ];

    if (isset($oidcErrorMessages[$oidcError])) {
        $loginFailed = true;
        $oidcErrorKey = $oidcErrorMessages[$oidcError];
    }
}

?>
<!DOCTYPE html>
<html dir="<?= $languages[$lang]['dir'] ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="<?= $theme == "light" ? "#FFFFFF" : "#12151C" ?>" id="theme-color" />
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
    <script type="text/javascript">
        window.update_theme_settings = "<?= $updateThemeSettings ?>";
        window.color_theme = "<?= $colorTheme ?>";
    </script>
    <script type="text/javascript" src="scripts/login.js?<?= $version ?>"></script>
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
                    <?= translate('please_login', $i18n) ?>
                </p>
            </header>
            <form action="login.php" method="post">
                <?php if (!$password_login_disabled) { ?>
                    <div class="form-group">
                        <label for="username"><?= translate('username', $i18n) ?>:</label>
                        <input type="text" id="username" name="username" autocomplete="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password"><?= translate('password', $i18n) ?>:</label>
                        <input type="password" id="password" name="password" autocomplete="current-password" required>
                    </div>
                    <?php
                    if (!$demoMode) {
                        ?>
                        <div class="form-group-inline">
                            <input type="checkbox" id="remember" name="remember">
                            <label for="remember"><?= translate('stay_logged_in', $i18n) ?></label>
                        </div>
                        <?php
                    }
                    ?>
                    <div class="form-group">
                        <input type="submit" value="<?= translate('login', $i18n) ?>">
                    </div>
                <?php } ?>
                <div class="form-group">
                    <?php
                    if ($oidcEnabled) {
                        if (!$password_login_disabled) {
                            ?>
                            <span class="or-separator"><?= translate('or', $i18n) ?></span>
                            <?php
                        }
                        ?>
                        <a class="button secondary-button" href="<?= htmlspecialchars($oidc_auth_url) ?>">
                            <?= translate('login_with', $i18n) ?>     <?= htmlspecialchars($oidc_name) ?>
                        </a>
                        <?php
                    }
                    ?>
                </div>
                <?php
                if ($loginFailed) {
                    ?>
                    <ul class="error-box">
                        <?php
                        if ($userEmailWaitingVerification) {
                            ?>
                            <li><i
                                    class="fa-solid fa-triangle-exclamation"></i><?= translate('user_email_waiting_verification', $i18n) ?>
                            </li>
                            <?php
                        } elseif ($oidcErrorKey !== null) {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate($oidcErrorKey, $i18n) ?></li>
                            <?php
                        } else {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('login_failed', $i18n) ?></li>
                            <?php
                        }
                        ?>
                    </ul>
                    <?php
                }
                if ($hasSuccessMessage) {
                    ?>
                    <ul class="success-box">
                        <?php
                        if (isset($_GET['validated']) && $_GET['validated'] == "true") {
                            ?>
                            <li><i class="fa-solid fa-check"></i><?= translate('email_verified', $i18n) ?></li>
                            <?php
                        } else if ($loggedOut) {
                            ?>
                            <li><i class="fa-solid fa-check"></i><?= translate('logged_out_successfully', $i18n) ?></li>
                            <?php
                        } else if (isset($_GET['registered']) && $_GET['registered']) {
                            ?>
                                <li><i class="fa-solid fa-check"></i><?= translate('registration_successful', $i18n) ?></li>
                                <?php
                                if (isset($_GET['requireValidation']) && $_GET['requireValidation'] == true) {
                                    ?>
                                    <li><?= translate('user_email_waiting_verification', $i18n) ?></li>
                                <?php
                                }
                        }
                        ?>
                    </ul>
                    <?php
                }

                if ($resetPasswordEnabled) {
                    ?>
                    <div class="login-form-link">
                        <a href="passwordreset.php"><?= translate('forgot_password', $i18n) ?></a>
                    </div>
                    <?php
                }
                ?>
                <?php
                if ($registrations) {
                    ?>
                    <div class="login-form-link account-switch">
                        <span><?= translate('no_account_yet', $i18n) ?></span>
                        <a href="registration.php"><?= translate('register', $i18n) ?></a>
                    </div>
                    <?php
                }
                ?>
            </form>
        </section>
    </div>
</body>

</html>
