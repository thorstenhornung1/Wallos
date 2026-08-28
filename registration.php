<?php
require_once 'includes/connect.php';
require_once 'includes/checkuser.php';
ob_start();
require_once 'includes/run_migrations.php';
$migrationOutput = trim(ob_get_clean());

if ($migrationFailure !== null) {
    // A registration page cannot usefully refuse over this, and telling a
    // prospective user about migration state would be noise. Swallowing it
    // silently is the other extreme, and it is how a half-migrated instance
    // serves pages for days without anyone learning of it (issue #103).
    error_log('Wallos: migration ' . basename((string) $migrationFailure)
        . ' failed while opening the registration page. ' . $migrationOutput);
}

require_once 'includes/i18n/languages.php';
require_once 'includes/user_provisioning.php';
require_once 'includes/i18n/getlang.php';
require_once 'includes/i18n/' . $lang . '.php';

require_once 'includes/version.php';

function validate($value)
{
    $value = trim($value);
    $value = stripslashes($value);
    $value = htmlspecialchars($value);
    $value = htmlentities($value);
    return $value;
}

// If logo folder doesn't exist, create it
if (!file_exists('images/uploads/logos')) {
    mkdir('images/uploads/logos', 0777, true);
    mkdir('images/uploads/logos/avatars', 0777, true);
}

// If there's already a user on the database, redirect to login page if registrations are closed or maxn users is reached
$stmt = $db->prepare('SELECT COUNT(*) as "userCount" FROM "user"');
$result = $stmt->execute();
$userCountResult = $result->fetchArray(SQLITE3_ASSOC);
$userCount = $userCountResult['userCount'];

if ($userCount == 0) {
    $setupTokenFile = __DIR__ . '/db/setup_token.db';
    if (!file_exists($setupTokenFile)) {
        $setupToken = bin2hex(random_bytes(32));
        file_put_contents($setupTokenFile, $setupToken);
        error_log("Setup token for database restore: " . $setupToken);
    }
}

if ($userCount > 0) {
    $stmt = $db->prepare('SELECT * FROM admin');
    $result = $stmt->execute();
    $settings = $result->fetchArray(SQLITE3_ASSOC);

    if ($settings['registrations_open'] == 0) {
        header("Location: login.php");
        exit();
    }

    if ($settings['max_users'] != 0) {

        if ($userCount >= $settings['max_users']) {
            header("Location: login.php");
            exit();
        }
    }
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

// The list a new account starts with, and the codes this form offers. Both
// used to be written out here, once more in endpoints/admin/adduser.php and
// once more in includes/oidc/oidc_create_user.php — the same three copies the
// categories had before includes/user_provisioning.php existed.
$currencies = wallos_default_currencies();


$passwordMismatch = false;
$usernameExists = false;
$emailExists = false;
$invalidCurrency = false;
$registrationFailed = false;
$hasErrors = false;
if (isset($_POST['username'])) {
    $username = validate($_POST['username']);
    $firstname = validate($_POST['firstname']);
    $lastname = validate($_POST['lastname']);
    $email = validate($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    // The form offers the codes from the list above and nothing else. A code
    // outside it made array_search() return false, which PHP reads as index 0,
    // so registration silently continued with the wrong currency — and the
    // lookup further down then found nothing and wrote NULL into
    // main_currency, a NOT NULL column with a foreign key on it.
    $main_currency = $_POST['main_currency'];
    $main_currency_index = array_search($main_currency, array_column($currencies, 'code'), true);
    $main_currency_id = null;

    if ($main_currency_index === false) {
        $invalidCurrency = true;
        $hasErrors = true;
    } else {
        // A currency that exists right now, because main_currency is NOT NULL
        // with a foreign key and this account's own copies do not exist yet.
        // The lookup further down moves it onto its own the moment they do.
        //
        // This used to be the position in the list above, which is a row id
        // only on an installation where nobody has registered yet — on any
        // other it names another account's currency until the update lands.
        $main_currency_id = (int) $db->scalar('SELECT MIN(id) FROM currencies');
    }
    $language = wallos_resolve_language($_POST['language'] ?? null);
    $avatar = "images/avatars/0.svg";

    if ($password != $confirm_password) {
        $passwordMismatch = true;
        $hasErrors = true;
    }

    $emailQuery = "SELECT * FROM \"user\" WHERE email = :email";
    $stmtEmail = $db->prepare($emailQuery);
    $stmtEmail->bindValue(':email', $email, SQLITE3_TEXT);
    $resultEmail = $stmtEmail->execute();

    if ($resultEmail->fetchArray()) {
        $emailExists = true;
        $hasErrors = true;
    }

    $usernameQuery = "SELECT * FROM \"user\" WHERE username = :username";
    $stmtUsername = $db->prepare($usernameQuery);
    $stmtUsername->bindValue(':username', $username, SQLITE3_TEXT);
    $resultUsername = $stmtUsername->execute();

    if ($resultUsername->fetchArray()) {
        $usernameExists = true;
        $hasErrors = true;
    }

    $requireValidation = false;

    if ($hasErrors == false) {
        $query = "INSERT INTO \"user\" (username, firstname, lastname, email, password, main_currency, avatar, language, budget, api_key) VALUES (:username, :firstname, :lastname, :email, :password, :main_currency, :avatar, :language, :budget, :api_key)";
        $stmt = $db->prepare($query);
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':firstname', $firstname, SQLITE3_TEXT);
        $stmt->bindValue(':lastname', $lastname, SQLITE3_TEXT);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
        $stmt->bindValue(':main_currency', $main_currency_id, SQLITE3_TEXT);
        $stmt->bindValue(':avatar', $avatar, SQLITE3_TEXT);
        $stmt->bindValue(':language', $language, SQLITE3_TEXT);
        $stmt->bindValue(':budget', 0, SQLITE3_INTEGER);
        $stmt->bindValue(':api_key', bin2hex(random_bytes(32)), SQLITE3_TEXT);
        $result = $stmt->execute();

        if ($result) {

            // Get id of the newly created user
            $userId = $db->lastInsertRowID();

            // On a fresh installation nobody holds the admin role yet, and the
            // administration area would be unreachable. The first account
            // created through this form takes it. OIDC provisioning does not do
            // this on purpose: an account created by whoever authenticates first
            // must not inherit the installation.
            require_once __DIR__ . '/includes/user_roles.php';
            wallos_claim_first_admin($db, $userId);

            // Add username as household member for that user
            if (!wallos_create_household_member($db, $userId, $username)) {
                error_log('Wallos registration: could not create the household member for user '
                    . $userId . ': ' . $db->lastErrorMsg());
            }

            if ($userId > 1) {

                // Add categories for that user, in the language they chose
                wallos_create_default_categories($db, $userId, $language);

                // Add payment methods and currencies for that user. Checked,
                // because an account holding eleven of its thirty-four
                // currencies is not a registration to report as successful
                // (issue #87) — and the failure used to be invisible: the
                // insert result went nowhere and the page said "registered".
                if (!wallos_create_default_payment_methods($db, $userId)) {
                    error_log('Wallos registration: could not create the default payment methods for user '
                        . $userId . ': ' . $db->lastErrorMsg());
                }

                if (!wallos_create_default_currencies($db, $userId)) {
                    error_log('Wallos registration: could not create the default currencies for user '
                        . $userId . ': ' . $db->lastErrorMsg());
                }

                // Retrieve main currency id
                $query = "SELECT id FROM currencies WHERE code = :code AND user_id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':code', $main_currency, SQLITE3_TEXT);
                $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $currency = $result->fetchArray(SQLITE3_ASSOC);

                // The account's own copy of the chosen currency was inserted by
                // the loop above, so this normally finds it. Reading ['id'] off
                // a lookup that found nothing yields NULL, though, and
                // main_currency is NOT NULL with a foreign key: falling back to
                // the account's first currency leaves a usable account behind
                // rather than a half-finished registration.
                $mainCurrencyId = $currency
                    ? $currency['id']
                    : $db->scalar('SELECT id FROM currencies WHERE user_id = :user_id ORDER BY id LIMIT 1',
                        [':user_id' => $userId]);

                if ($mainCurrencyId !== null) {
                    // Update user main currency
                    //
                    // Checked, because this is what moves the account off the
                    // bootstrap currency it was created against. Failing here
                    // silently leaves main_currency pointing at a row belonging
                    // to another account — the cross-account reference issues
                    // #82 and #93 exist to keep out of the database.
                    $query = "UPDATE \"user\" SET main_currency = :main_currency WHERE id = :user_id";
                    $stmt = $db->prepare($query);
                    $moved = false;

                    if ($stmt !== false) {
                        $stmt->bindValue(':main_currency', $mainCurrencyId, SQLITE3_INTEGER);
                        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                        $moved = $stmt->execute() !== false;
                    }

                    if (!$moved) {
                        error_log('Wallos registration: user ' . $userId . ' still points at the currency '
                            . 'it was created against, which belongs to another account: '
                            . $db->lastErrorMsg());
                    }
                }

                // Add settings for that user
                $query = "INSERT INTO settings (dark_theme, monthly_price, convert_currency, remove_background, color_theme, hide_disabled, user_id, disabled_to_bottom, show_original_price, mobile_nav, week_starts_sunday) 
                          VALUES (2, 0, 0, 0, 'blue', 0, :user_id, 0, 0, 0, 0)";
                $stmt = $db->prepare($query);
                $settingsWritten = false;

                if ($stmt !== false) {
                    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $settingsWritten = $stmt->execute() !== false;
                }

                if (!$settingsWritten) {
                    // Every page reads this row. An account without one renders
                    // with whatever the code does when the lookup finds nothing,
                    // which is the state issue #17 is about.
                    error_log('Wallos registration: could not create the settings row for user '
                        . $userId . ': ' . $db->lastErrorMsg());
                }

                // If email verification is required add the user to the email_verification table
                $query = "SELECT * FROM admin";
                $stmt = $db->prepare($query);
                $result = $stmt->execute();
                $settings = $result->fetchArray(SQLITE3_ASSOC);

                if ($settings['require_email_verification'] == 1) {
                    $query = "INSERT INTO email_verification (user_id, email, token, email_sent) VALUES (:user_id, :email, :token, 0)";
                    $stmt = $db->prepare($query);
                    $token = bin2hex(random_bytes(32));
                    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
                    $stmt->bindValue(':email', $email, SQLITE3_TEXT);

                    if ($stmt->execute() === false) {
                        // The account stays unverifiable either way: there is no
                        // token to send and none to accept. Reported rather than
                        // waved through, because the alternative — treating a
                        // failed verification set-up as "no verification needed"
                        // — turns a broken write into a way past the check.
                        error_log('Wallos registration: could not create the email verification token for user '
                            . $userId . ', so the account cannot be verified: ' . $db->lastErrorMsg());
                    }

                    $requireValidation = true;
                }
            }

            $db->close();
            header("Location: login.php?registered=true&requireValidation=$requireValidation");
            exit();
        } else {
            $registrationFailed = true;
        }
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
    <link rel="stylesheet" href="styles/login-dark-theme.css?<?= $version ?>" id="dark-theme" <?= $theme == "light" ? "disabled" : "" ?>>
    <link rel="stylesheet" href="styles/font-awesome.min.css">
    <link rel="stylesheet" href="styles/barlow.css">
    <script type="text/javascript">
        window.update_theme_settings = "<?= $updateThemeSettings ?>";
        window.colorTheme = "<?= $colorTheme ?>";
    </script>
    <script type="text/javascript" src="scripts/registration.js?<?= $version ?>"></script>
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
        <section class="container wide">
            <header>
                <div class="logo-image" title="Wallos - Subscription Tracker">
                    <?php include "images/siteicons/svg/logo.php"; ?>
                </div>
                <p>
                    <?= translate('create_account', $i18n) ?>
                </p>
            </header>
            <form action="registration.php" method="post" class="registration-form">
                <div class="form-group">
                    <label for="username"><?= translate('username', $i18n) ?>:</label>
                    <input type="text" id="username" name="username" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label for="firstname"><?= translate('firstname', $i18n) ?>:</label>
                    <input type="text" id="firstname" name="firstname" autocomplete="given-name">
                </div>
                <div class="form-group">
                    <label for="lastname"><?= translate('lastname', $i18n) ?>:</label>
                    <input type="text" id="lastname" name="lastname" autocomplete="family-name">
                </div>
                <div class="form-group">
                    <label for="email"><?= translate('email', $i18n) ?>:</label>
                    <input type="email" id="email" name="email" autocomplete="email" required>
                </div>
                <div class="form-group">
                    <label for="password"><?= translate('password', $i18n) ?>:</label>
                    <input type="password" id="password" name="password" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password"><?= translate('confirm_password', $i18n) ?>:</label>
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label for="currency"><?= translate('main_currency', $i18n) ?>:</label>
                    <select id="currency" name="main_currency" placeholder="Currency">
                        <?php
                        foreach ($currencies as $currency) {
                            ?>
                            <option value="<?= $currency['code'] ?>"><?= $currency['name'] ?></option>
                            <?php
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="language"><?= translate('language', $i18n) ?>:</label>
                    <select id="language" name="language" placeholder="Language" onchange="changeLanguage(this.value)">
                        <?php
                        foreach ($languages as $code => $language) {
                            $selected = ($code === $lang) ? 'selected' : '';
                            ?>
                            <option value="<?= $code ?>" <?= $selected ?>><?= $language['name'] ?></option>
                            <?php
                        }
                        ?>
                    </select>
                </div>

                <?php
                if ($hasErrors) {
                    ?>
                    <ul class="error-box">
                        <?php
                        if ($passwordMismatch) {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('passwords_dont_match', $i18n) ?>
                            </li>
                            <?php
                        }
                        ?>
                        <?php
                        if ($usernameExists) {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('username_exists', $i18n) ?></li>
                            <?php
                        }
                        ?>
                        <?php
                        if ($emailExists) {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('email_exists', $i18n) ?></li>
                            <?php
                        }
                        ?>
                        <?php
                        if ($invalidCurrency) {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('invalid_currency_code', $i18n) ?>
                            </li>
                            <?php
                        }
                        ?>
                        <?php
                        if ($registrationFailed) {
                            ?>
                            <li><i class="fa-solid fa-triangle-exclamation"></i><?= translate('registration_failed', $i18n) ?>
                            </li>
                            <?php
                        }
                        ?>
                    </ul>
                    <?php
                }
                ?>


                <div class="form-group">
                    <input type="submit" value="<?= translate('register', $i18n) ?>">
                </div>
            </form>
            <?php
            if ($userCount == 0) {
                ?>
                <div class="separator">
                    <input type="button" class="secondary-button" value="<?= translate('restore_database', $i18n) ?>"
                        onClick="openRestoreModal()" />
                </div>
                <?php
            } else {
                ?>
                <div class="login-form-link account-switch">
                    <span><?= translate('already_have_account', $i18n) ?></span>
                    <a href="login.php"><?= translate('login', $i18n) ?></a>
                </div>
                <?php
            }
            ?>
        </section>
    </div>
    <?php if ($userCount == 0) { ?>
    <div id="restoreModalBackdrop" class="modal-backdrop" onclick="closeRestoreModal()">
        <div id="restoreModal" class="subscription-modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><?= translate('restore_database', $i18n) ?></h3>
                <span class="fa-solid fa-xmark close-modal" onclick="closeRestoreModal()"></span>
            </div>
            <div class="modal-body">
                <p><?= translate('restore_database_info', $i18n) ?></p>
                <ul>
                    <li><?= translate('setup_token_docker', $i18n) ?></li>
                    <li><?= translate('setup_token_file', $i18n) ?></li>
                </ul>
                <div class="form-group">
                    <input type="text" id="setupToken" placeholder="<?= translate('setup_token', $i18n) ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <input type="button" class="secondary-button" value="<?= translate('select_backup_file', $i18n) ?>"
                        onClick="openRestoreDBFileSelect()" />
                    <input type="file" name="restoreDBFile" id="restoreDBFile" style="display: none;" onChange="onRestoreFileSelected()" accept=".zip">
                    <span id="restoreFileName" style="font-size: 14px; margin-left: 8px;"></span>
                </div>
                <div class="form-group">
                    <input type="button" value="<?= translate('restore_database', $i18n) ?>" onClick="restoreDBButton()" />
                </div>
            </div>
        </div>
    </div>
    <?php } ?>
    <?php
    require_once 'includes/footer.php';
    ?>
</body>

</html>
