<?php
require_once 'includes/connect.php';
require_once 'includes/checkuser.php';
require_once 'includes/totp_state.php';

require_once 'includes/i18n/languages.php';
require_once 'includes/i18n/getlang.php';
require_once 'includes/i18n/' . $lang . '.php';

require_once 'includes/version.php';
require_once 'includes/theme_helpers.php';

if ($userCount == 0) {
    header("Location: registration.php");
    exit();
}

session_start();

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $db->close();
    header("Location: .");
    exit();
}

if (!isset($_SESSION['totp_user_id'])) {
    $db->close();
    header("Location: login.php");
    exit();
}

$theme = "light";
$updateThemeSettings = false;
if (isset($_COOKIE['theme'])) {
    $theme = sanitize_theme_mode($_COOKIE['theme']);
} else {
    $updateThemeSettings = true;
}

$colorTheme = "blue";
if (isset($_COOKIE['colorTheme'])) {
    $colorTheme = sanitize_color_theme($_COOKIE['colorTheme']);
}

$demoMode = getenv('DEMO_MODE');

$cookieExpire = time() + (30 * 24 * 60 * 60);
$invalidTotp = false;
$totpLocked = false;

if (isset($_POST['one-time-code'])) {
    $totp_code = $_POST['one-time-code'];

    // Brute-force protection: after too many consecutive failed verifications,
    // lock the account for a short period. State is persisted per account (not
    // per session) so it cannot be reset by re-doing the password step.
    $maxTotpAttempts = 5;
    $totpLockoutSeconds = 30;

    $row = wallos_totp_load_state($db, $_SESSION['totp_user_id']);
    if ($row === null) {
        // No enrolment, or the row could not be read. Either way there is
        // nothing to verify against, and continuing would compare a submitted
        // code against null.
        $db->close();
        header('Location: login.php');
        exit();
    }
    $totp_secret = $row['totp_secret'];
    $backupCodes = json_decode($row['backup_codes'], true);
    $failedAttempts = (int) ($row['failed_attempts'] ?? 0);
    $lockoutUntil = (int) ($row['lockout_until'] ?? 0);

    $totpLocked = $lockoutUntil > time();

    // Interpret last_totp_used as a TOTP time-step counter (used to reject reuse
    // of an already-consumed code). Legacy installs stored a raw unix timestamp
    // here, which is far larger than any current step, so normalise those by
    // dividing by the period.
    $currentStep = intdiv(time(), 30);
    $lastUsedStep = wallos_totp_last_used_step($row['last_totp_used'] ?? 0, $currentStep);

    require_once 'libs/OTPHP/FactoryInterface.php';
    require_once 'libs/OTPHP/Factory.php';
    require_once 'libs/OTPHP/ParameterTrait.php';
    require_once 'libs/OTPHP/OTPInterface.php';
    require_once 'libs/OTPHP/OTP.php';
    require_once 'libs/OTPHP/TOTPInterface.php';
    require_once 'libs/OTPHP/TOTP.php';
    require_once 'libs/Psr/Clock/ClockInterface.php';
    require_once 'libs/OTPHP/InternalClock.php';
    require_once 'libs/constant_time_encoding/Binary.php';
    require_once 'libs/constant_time_encoding/EncoderInterface.php';
    require_once 'libs/constant_time_encoding/Base32.php';

    $valid = false;

    if ($totpLocked) {
        // Account is temporarily locked out; do not evaluate the submitted code.
        $invalidTotp = true;
    } else {
        $clock = new OTPHP\InternalClock();

        $totp = OTPHP\TOTP::createFromSecret($totp_secret, $clock);
        $totp->setPeriod(30);

        // Verify the code ourselves so we know which time-step matched. The
        // library's verify() only returns a boolean, but we need the step to
        // reject reuse of an already-consumed code (replay). This mirrors the
        // library's leeway logic: check the previous, current and next step.
        $matchedStep = wallos_totp_matched_step($totp, $totp_code, time());

        $valid = $matchedStep !== null;

        if ($valid && wallos_totp_step_is_replay($matchedStep, $lastUsedStep)) {
            // This code's time-step has already been used; reject the replay.
            $valid = false;
        }

        // If totp is not valid check backup codes
        if (!$valid) {
            // A backup code counts only once it has actually been struck off.
            // Accepting one whose removal failed would leave a single-use code
            // usable indefinitely.
            $valid = wallos_totp_consume_backup_code(
                $db,
                $_SESSION['totp_user_id'],
                $backupCodes,
                $totp_code
            );
        } else {
            // Record the matched time-step so the same code cannot be reused.
            // The login proceeds if this cannot be stored — the credential was
            // genuine — but the replay window is then unguarded, so say so.
            if (!wallos_totp_consume_step($db, $_SESSION['totp_user_id'], $matchedStep)) {
                error_log('Wallos: could not record the used TOTP step for user '
                    . (int) $_SESSION['totp_user_id'] . '; this code stays replayable until it expires');
            }
        }

        // Update brute-force counters based on the result of this attempt.
        if ($valid) {
            if (!wallos_totp_reset_attempts($db, $_SESSION['totp_user_id'])) {
                error_log('Wallos: could not reset TOTP failure count for user '
                    . (int) $_SESSION['totp_user_id']);
            }
        } else {
            $invalidTotp = true;
            $failedAttempts++;

            $failure = wallos_totp_record_failure(
                $db,
                $_SESSION['totp_user_id'],
                $failedAttempts,
                $maxTotpAttempts,
                $totpLockoutSeconds
            );
            $totpLocked = $failure['locked'];

            if (!$failure['stored']) {
                // The counter did not move, so brute-force protection is not
                // holding. Nothing visible changes for the attacker; this line
                // is the only trace.
                error_log('Wallos: could not record a failed TOTP attempt for user '
                    . (int) $_SESSION['totp_user_id'] . '; rate limiting is not in effect');
            }
        }
    }

    if ($valid) {
        $query = "SELECT id, username, main_currency, language FROM \"user\" WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id', $_SESSION['totp_user_id'], SQLITE3_INTEGER);
        $result = $stmt->execute();
        $user = $result === false ? false : $result->fetchArray(SQLITE3_ASSOC);

        if ($user === false) {
            // The second factor was correct but the account behind it cannot be
            // read. Establishing a session from the resulting nulls would log
            // somebody in as nobody.
            $db->close();
            header('Location: login.php');
            exit();
        }

        session_regenerate_id(true);
        $_SESSION['username'] = $user['username'];
        $_SESSION['loggedin'] = true;
        $_SESSION['main_currency'] = $user['main_currency'];
        $_SESSION['userId'] = $user['id'];

        if (!empty($_SESSION['pending_remember_me'])) {
            $token = bin2hex(random_bytes(32));
            $addLoginTokens = "INSERT INTO login_tokens (user_id, token) VALUES (:userId, :token)";
            $addLoginTokensStmt = $db->prepare($addLoginTokens);
            $addLoginTokensStmt->bindParam(':userId', $user['id'], SQLITE3_INTEGER);
            $addLoginTokensStmt->bindParam(':token', $token, SQLITE3_TEXT);

            // Only hand out the cookie if its token was stored. A cookie with
            // no row behind it is not insecure, but it silently stops working:
            // the user ticked "remember me" and gets asked to log in anyway,
            // with nothing to explain why.
            if ($addLoginTokensStmt->execute() !== false) {
                // Logout revokes the token named in the session. Without this
                // line the token has no name there, so an account with 2FA
                // kept its remember-me row across a logout while an account
                // without 2FA did not — the defect of #1184, surviving in the
                // one login path that did not set it.
                $_SESSION['token'] = $token;
                $cookieExpire = time() + (30 * 24 * 60 * 60);
                $cookieValue = $user['username'] . "|" . $token . "|" . $user['main_currency'];
                setcookie('wallos_login', $cookieValue, [
                    'expires'  => $cookieExpire,
                    'samesite' => 'Lax',
                    'httponly' => true,
                ]);
            } else {
                error_log('Wallos: could not store a remember-me token for user ' . (int) $user['id']);
            }
            unset($_SESSION['pending_remember_me']);
        }

        setcookie('language', $user['language'], [
            'expires' => $cookieExpire,
            'samesite' => 'Lax'
        ]);

        if (!isset($_COOKIE['sortOrder'])) {
            setcookie('sortOrder', 'next_payment', [
                'expires' => $cookieExpire,
                'samesite' => 'Lax'
            ]);
        }

        $query = "SELECT color_theme FROM settings WHERE user_id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id', $_SESSION['totp_user_id'], SQLITE3_INTEGER);
        $result = $stmt->execute();
        $settings = $result === false ? false : $result->fetchArray(SQLITE3_ASSOC);
        if ($settings !== false && isset($settings['color_theme'])) {
            setcookie('colorTheme', $settings['color_theme'], [
                'expires' => $cookieExpire,
                'samesite' => 'Lax'
            ]);
        }

        unset($_SESSION['totp_user_id']);

        $db->close();
        header("Location: .");
        exit();
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
        window.color_theme = <?= json_encode($colorTheme, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
    </script>
    <script type="text/javascript" src="scripts/login.js?<?= $version ?>"></script>
    <script type="text/javascript" src="scripts/auth-theme.js?<?= $version ?>"></script>
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
                    <?= translate('insert_totp_code', $i18n) ?>
                </p>
            </header>
            <form action="totp.php" method="post">
                <div class="form-group">
                    <label for="one-time-code"><?= translate('totp_code', $i18n) ?>:</label>
                    <input type="text" id="one-time-code" name="one-time-code" autocomplete="one-time-code" required>
                </div>
                <div class="form-group">
                    <input type="submit" value="<?= translate('login', $i18n) ?>">
                </div>
                <?php
                if ($invalidTotp) {
                    $totpErrorMessage = $totpLocked
                        ? translate('totp_too_many_attempts', $i18n)
                        : translate('totp_code_incorrect', $i18n);
                    ?>
                    <ul class="error-box">
                        <li>
                            <i class="fa-solid fa-triangle-exclamation"></i><?= $totpErrorMessage ?>
                        </li>
                    </ul>
                    <?php
                }
                ?>

            </form>
        </section>
    </div>
</body>

</html>