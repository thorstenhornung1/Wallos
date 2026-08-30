<?php
require_once 'includes/header.php';
require_once 'includes/oidc_settings.php';
require_once 'includes/oidc/diagnostics.php';
require_once 'includes/cron/diagnostics.php';
require_once 'includes/database/diagnostics.php';
require_once 'includes/ssrf_helper.php';
require_once 'includes/integration_config.php';

if ($isAdmin != 1) {
    header('Location: index.php');
    exit;
}

// get admin settings from admin table
$stmt = $db->prepare('SELECT * FROM admin');
$result = $stmt->execute();
$settings = $result->fetchArray(SQLITE3_ASSOC);

$oidcConfiguration = wallos_get_effective_oidc_configuration($db);
$oidcSettings = $oidcConfiguration['settings'];
$oidcManagedFields = $oidcConfiguration['managed_fields'];
$oidcNotes = $oidcConfiguration['notes'];

$oidcDiagnostics = wallos_oidc_diagnostics($db);

// What the scheduled jobs last reported. The container healthcheck covers nginx
// and php-fpm, so a container whose cron has not started a job in a fortnight is
// still reported healthy; this is the only place that says otherwise.
$cronDiagnostics = wallos_cron_diagnostics($db);
// Which backend is in use was previously answerable only from outside the
// application, by reading the container's environment (issue #102).
$databaseDiagnostics = wallos_database_diagnostics($db);

$ssrfConfiguration = wallos_get_effective_ssrf_allowlist($db);
$ssrfManagedFields = $ssrfConfiguration['is_managed'] ? ['allowlist' => 'SSRF_ALLOWLIST'] : [];

// Instance integrations: SMTP lives in the admin table, the currency and AI
// provider in integration_settings. Environment managed fields are shown with
// their effective value but cannot be edited here.
$smtpConfiguration = wallos_get_instance_smtp_config($db);
$smtpPasswordStatus = wallos_secret_status($smtpConfiguration, 'password');
$currencyConfiguration = wallos_get_instance_currency_config($db);
$currencyKeyStatus = wallos_secret_status($currencyConfiguration, 'api_key');
$aiConfiguration = wallos_get_instance_ai_config($db);
$aiKeyStatus = wallos_secret_status($aiConfiguration, 'api_key');
$languageConfiguration = wallos_get_instance_language_config($db);

function oidc_input_attrs($field, $managedFields)
{
    return isset($managedFields[$field]) ? 'disabled data-managed-by="' . htmlspecialchars($managedFields[$field]) . '"' : '';
}

// get user accounts, and who among them administers this installation
require_once __DIR__ . '/includes/user_roles.php';
$stmt = $db->prepare('SELECT id, username, email FROM "user" ORDER BY id ASC');
$result = $stmt->execute();

$users = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $users[] = $row;
}
$userCount = is_array($users) ? count($users) : 0;

// One query for the whole list rather than a role lookup per row.
$adminIds = [];
$roleResult = $db->query("SELECT DISTINCT user_id FROM user_roles WHERE role = 'admin'");
while ($roleResult !== false && $roleRow = $roleResult->fetchArray(SQLITE3_ASSOC)) {
    $adminIds[(int) $roleRow['user_id']] = true;
}
$adminCount = count($adminIds);

$loginDisabledAllowed = $userCount == 1 && $settings['registrations_open'] == 0;
?>

<section class="contain settings">

    <section class="account-section">
        <header>
            <h2><?= translate('registrations', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="form-group-inline">
                <input type="checkbox" id="registrations" <?= $settings['registrations_open'] ? 'checked' : '' ?> />
                <label for="registrations"><?= translate('enable_user_registrations', $i18n) ?></label>
            </div>
            <div class="form-group">
                <label for="maxUsers"><?= translate('maximum_number_users', $i18n) ?></label>
                <input type="number" id="maxUsers" autocomplete="off" value="<?= $settings['max_users'] ?>" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('max_users_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('registrations_disable_login_info', $i18n) ?>
                </p>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="requireEmail" <?= $settings['require_email_verification'] ? 'checked' : '' ?>
                    <?= $smtpConfiguration['valid'] ? '' : 'disabled' ?> />
                <label for="requireEmail">
                    <?= translate('require_email_verification', $i18n) ?>
                </label>
            </div>
            <?php
            if (!$smtpConfiguration['valid']) {
                ?>
                <div class="settings-notes">
                    <p>
                        <i class="fa-solid fa-circle-info"></i>
                        <?= translate('configure_smtp_settings_to_enable', $i18n) ?>
                    </p>
                </div>
                <?php
            }
            ?>
            <div class="form-group">
                <label for="defaultLanguage"><?= translate('default_language', $i18n) ?></label>
                <select id="defaultLanguage" <?= wallos_managed_input_attrs($languageConfiguration, 'language') ?>>
                    <?php foreach (wallos_languages() as $code => $language): ?>
                        <option value="<?= $code ?>" <?= $languageConfiguration['values']['language'] === $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars($language['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('default_language_info', $i18n) ?>
                </p>
                <?= wallos_render_managed_notes($languageConfiguration, $i18n) ?>
            </div>
            <div class="form-group">
                <label for="serverUrl"><?= translate('server_url', $i18n) ?></label>
                <input type="text" id="serverUrl" autocomplete="off" value="<?= htmlspecialchars($settings['server_url']) ?>" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('server_url_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('server_url_password_reset', $i18n) ?>
                </p>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="disableLogin" <?= $settings['login_disabled'] ? 'checked' : '' ?>
                    <?= $loginDisabledAllowed ? '' : 'disabled' ?> />
                <label for="disableLogin"><?= translate('disable_login', $i18n) ?></label>
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <?= translate('disable_login_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <?= translate('disable_login_info2', $i18n) ?>
                </p>
            </div>
            <div class="buttons">
                <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                    id="saveAccountRegistrations" onClick="saveAccountRegistrationsButton()" />
            </div>
        </div>
    </section>

    <?php
    if ($userCount >= 0) {
        ?>

        <section class="account-section">
            <header>
                <h2><?= translate('user_management', $i18n) ?></h2>
            </header>
            <div class="user-list">
                <?php
                foreach ($users as $user) {
                    $userIsAdmin = isset($adminIds[(int) $user['id']]);
                    $userIcon = $userIsAdmin ? 'fa-user-shield' : 'fa-user';
                    // What needs protecting is not a particular id, it is that
                    // somebody can still reach this page afterwards.
                    $userIsLastAdmin = $userIsAdmin && $adminCount <= 1;
                    ?>
                    <div class="form-group-inline" data-userid="<?= $user['id'] ?>">
                        <div class="user-list-row">
                            <div title="<?= translate('username', $i18n) ?>">
                                <div class="user-list-icon">
                                    <i class="fa-solid <?= $userIcon ?>"></i>
                                </div>
                                <?= htmlspecialchars($user['username']) ?>
                            </div>
                            <div title="<?= translate('email', $i18n) ?>">
                                <div class="user-list-icon">
                                    <i class="fa-solid fa-at"></i>
                                </div>
                                <a href="mailto:<?= htmlspecialchars($user['email']) ?>"><?= htmlspecialchars($user['email']) ?></a>
                            </div>
                        </div>
                        <div>
                            <?php
                            if (!$userIsLastAdmin) {
                                ?>
                                <button class="image-button medium" onClick="removeUser(<?= $user['id'] ?>)"
                                    title="<?= translate('delete_user', $i18n) ?>">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                                <?php
                            } else {
                                ?>
                                <button class="image-button medium disabled" disabled
                                    title="<?= translate('delete_user', $i18n) ?>">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                                <?php
                            }
                            ?>

                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('delete_user_info', $i18n) ?>
                </p>
            </div>
            <h2><?= translate('create_user', $i18n) ?></h2>
            <div class="form-group">
                <input type="text" id="newUsername" autocomplete="off"
                    placeholder="<?= translate('username', $i18n) ?>" />
            </div>
            <div class="form-group">
                <input type="email" id="newEmail" autocomplete="off"
                    placeholder="<?= translate('email', $i18n) ?>" />
            </div>
            <div class="form-group-inline">
                <input type="password" id="newPassword" autocomplete="off"
                    placeholder="<?= translate('password', $i18n) ?>" />
                <input type="submit" class="thin" value="<?= translate('add', $i18n) ?>" id="addUser"
                    onClick="addUserButton()" />
            </div>
        </section>

        <?php
    }
    ?>

    <section class="account-section">
        <header>
            <h2><?= translate('database_diagnostics', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-database" aria-hidden="true"></i>
                    <strong><?= translate('database_backend', $i18n) ?>:</strong>
                    <?= htmlspecialchars($databaseDiagnostics['version']
                        ?? $databaseDiagnostics['driver']) ?>
                    <?php if (!$databaseDiagnostics['configured']): ?>
                        <!-- An unset WALLOS_DB_DRIVER yields sqlite too, so saying
                             which of the two produced this is the whole point. -->
                        <em>(<?= translate('database_backend_default', $i18n) ?>)</em>
                    <?php endif; ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    <strong><?= translate('database_source', $i18n) ?>:</strong>
                    <?= htmlspecialchars($databaseDiagnostics['source']) ?>
                </p>
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('database_diagnostics_hint', $i18n) ?>
                </p>
            </div>
        </div>
    </section>

    <section class="account-section">
        <header>
            <h2><?= translate('cron_diagnostics', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="settings-notes">
                <?php foreach ($cronDiagnostics['checks'] as $check): ?>
                    <?php
                    $icon = [
                        'ok' => 'fa-circle-check',
                        'warning' => 'fa-triangle-exclamation',
                        'error' => 'fa-circle-xmark',
                        'unknown' => 'fa-circle-question',
                    ][$check['status']] ?? 'fa-circle-info';
                    ?>
                    <p>
                        <i class="fa-solid <?= $icon ?>" aria-hidden="true"></i>
                        <strong><?= htmlspecialchars($check['label']) ?>:</strong>
                        <?= htmlspecialchars($check['detail']) ?>
                    </p>
                <?php endforeach; ?>
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('cron_diagnostics_hint', $i18n) ?>
                </p>
            </div>
        </div>
    </section>

    <section class="account-section">
        <header>
            <h2><?= translate('oidc_settings', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="form-group-inline">
                <input type="checkbox" id="oidcEnabled" <?= $oidcConfiguration['enabled'] ? 'checked' : '' ?>
                    <?= oidc_input_attrs('enabled', $oidcManagedFields) ?>
                    onchange="toggleOidcEnabled()" />
                <label for="oidcEnabled"><?= translate('oidc_oauth_enabled', $i18n) ?></label>
            </div>
            <div class="form-group">
                <input type="text" id="oidcName" placeholder="Provider Name" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['name']) ?>" <?= oidc_input_attrs('name', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcIssuer" placeholder="<?= translate('oidc_issuer', $i18n) ?>"
                    autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['issuer'] ?? '') ?>"
                    <?= oidc_input_attrs('issuer', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcClientId" placeholder="Client ID" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['client_id']) ?>" <?= oidc_input_attrs('client_id', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <?php
                // Rendered as an empty password field, never carrying the stored
                // value. A pre-filled secret survives in the browser cache, in a
                // saved page and in any screenshot of this screen — and with
                // OIDC_CLIENT_SECRET_FILE it would put a secret deliberately kept
                // out of the database into the HTML of every admin page load.
                //
                // Leaving it empty on save keeps the stored secret; see
                // wallos_save_oidc_settings().
                $secretIsSet = trim((string) ($oidcSettings['client_secret'] ?? '')) !== '';
                ?>
                <input type="password" id="oidcClientSecret" autocomplete="new-password"
                    placeholder="<?= $secretIsSet ? translate('oidc_client_secret_set', $i18n) : 'Client Secret' ?>"
                    value="" <?= oidc_input_attrs('client_secret', $oidcManagedFields) ?> />
            </div>
            <?php if ($secretIsSet && !isset($oidcManagedFields['client_secret'])): ?>
                <div class="form-group-inline">
                    <?php
                    // Since an empty secret field means "keep the stored
                    // secret", a provider switched to a public client needs
                    // this explicit request to get rid of it (#124). Only
                    // offered while a secret is stored and the environment
                    // does not manage it.
                    ?>
                    <input type="checkbox" id="oidcClearClientSecret" onchange="toggleOidcClearClientSecret()" />
                    <label for="oidcClearClientSecret"><?= translate('oidc_clear_client_secret', $i18n) ?></label>
                </div>
            <?php endif; ?>
            <div class="form-group">
                <input type="text" id="oidcAuthUrl" placeholder="Auth URL" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['authorization_url']) ?>" <?= oidc_input_attrs('authorization_url', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcTokenUrl" placeholder="Token URL" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['token_url']) ?>" <?= oidc_input_attrs('token_url', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcUserInfoUrl" placeholder="User Info URL" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['user_info_url']) ?>" <?= oidc_input_attrs('user_info_url', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcRedirectUrl" placeholder="Redirect URL" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['redirect_url']) ?>" <?= oidc_input_attrs('redirect_url', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcLogoutUrl" placeholder="Logout URL" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['logout_url']) ?>" <?= oidc_input_attrs('logout_url', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcUserIdentifierField" placeholder="User Identifier Field" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['user_identifier_field']) ?>" <?= oidc_input_attrs('user_identifier_field', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcScopes" placeholder="Scopes" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['scopes']) ?>" <?= oidc_input_attrs('scopes', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="hidden" id="oidcAuthStyle" placeholder="Auth Style" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['auth_style']) ?>" />
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="oidcAutoCreateUser" <?= $oidcSettings['auto_create_user'] ? 'checked' : '' ?>
                    <?= oidc_input_attrs('auto_create_user', $oidcManagedFields) ?> />
                <label for="oidcAutoCreateUser"><?= translate('create_user_automatically', $i18n) ?></label>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="oidcPasswordLoginDisabled"
                    <?= $oidcSettings['password_login_disabled'] ? 'checked' : '' ?>
                    <?= oidc_input_attrs('password_login_disabled', $oidcManagedFields) ?> />
                <label for="oidcPasswordLoginDisabled"><?= translate('disable_password_login', $i18n) ?></label>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="oidcRequireEmailVerified"
                    <?= $oidcSettings['require_email_verified'] ? 'checked' : '' ?>
                    <?= oidc_input_attrs('require_email_verified', $oidcManagedFields) ?> />
                <label for="oidcRequireEmailVerified"><?= translate('require_email_verified_linking', $i18n) ?></label>
            </div>
            <div class="form-group">
                <input type="text" id="oidcPostLogoutRedirectUrl"
                    placeholder="<?= translate('oidc_post_logout_redirect_url', $i18n) ?>" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['post_logout_redirect_url'] ?? '') ?>"
                    <?= oidc_input_attrs('post_logout_redirect_url', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcAdminClaim" placeholder="<?= translate('oidc_admin_claim', $i18n) ?>"
                    autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['admin_claim'] ?? '') ?>"
                    <?= oidc_input_attrs('admin_claim', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcAdminValue" placeholder="<?= translate('oidc_admin_value', $i18n) ?>"
                    autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['admin_value'] ?? '') ?>"
                    <?= oidc_input_attrs('admin_value', $oidcManagedFields) ?> />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('oidc_admin_claim_hint', $i18n) ?>
                </p>
            </div>
            <?php if (!empty($oidcManagedFields) || !empty($oidcNotes)): ?>
                <div class="settings-notes">
                    <?php if (!empty($oidcManagedFields)): ?>
                        <p>
                            <i class="fa-solid fa-circle-info"></i>
                            OIDC fields managed by environment variables are shown here but cannot be edited in the UI.
                        </p>
                    <?php endif; ?>
                    <?php foreach ($oidcNotes as $oidcNote): ?>
                        <p>
                            <i class="fa-solid fa-circle-info"></i>
                            <?= htmlspecialchars($oidcNote) ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="settings-notes">
                <p><strong><?= translate('oidc_diagnostics', $i18n) ?></strong></p>
                <?php foreach ($oidcDiagnostics['checks'] as $check): ?>
                    <?php
                    $icon = [
                        'ok' => 'fa-circle-check',
                        'warning' => 'fa-triangle-exclamation',
                        'error' => 'fa-circle-xmark',
                        'unknown' => 'fa-circle-question',
                    ][$check['status']] ?? 'fa-circle-info';
                    ?>
                    <p>
                        <i class="fa-solid <?= $icon ?>" aria-hidden="true"></i>
                        <strong><?= htmlspecialchars($check['label']) ?>:</strong>
                        <?= htmlspecialchars($check['detail']) ?>
                    </p>
                <?php endforeach; ?>
            </div>
            <div class="buttons">
                <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                    id="saveOidcSettings" onClick="saveOidcSettingsButton()" />
            </div>
        </div>

    </section>

    <section class="account-section">
        <header>
            <h2><?= translate('smtp_settings', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="form-group-inline">
                <input type="text" name="smtpaddress" id="smtpaddress" autocomplete="off"
                    placeholder="<?= translate('smtp_address', $i18n) ?>"
                    value="<?= htmlspecialchars($smtpConfiguration['values']['host']) ?>"
                    <?= wallos_managed_input_attrs($smtpConfiguration, 'host') ?> />
                <input type="text" name="smtpport" id="smtpport" autocomplete="off"
                    placeholder="<?= translate('port', $i18n) ?>" class="one-third"
                    value="<?= htmlspecialchars($smtpConfiguration['values']['port']) ?>"
                    <?= wallos_managed_input_attrs($smtpConfiguration, 'port') ?> />
            </div>
            <div class="form-group-inline">
                <div>
                    <input type="radio" name="encryption" id="encryptionnone" value="none"
                        <?= $smtpConfiguration['values']['encryption'] == "none" ? "checked" : "" ?>
                        <?= wallos_managed_input_attrs($smtpConfiguration, 'encryption') ?> />
                    <label for="encryptionnone"><?= translate('none', $i18n) ?></label>
                </div>
                <div>
                    <input type="radio" name="encryption" id="encryptiontls" value="tls"
                        <?= $smtpConfiguration['values']['encryption'] == "tls" ? "checked" : "" ?>
                        <?= wallos_managed_input_attrs($smtpConfiguration, 'encryption') ?> />
                    <label for="encryptiontls"><?= translate('tls', $i18n) ?></label>
                </div>
                <div>
                    <input type="radio" name="encryption" id="encryptionssl" value="ssl"
                        <?= $smtpConfiguration['values']['encryption'] == "ssl" ? "checked" : "" ?>
                        <?= wallos_managed_input_attrs($smtpConfiguration, 'encryption') ?> />
                    <label for="encryptionssl"><?= translate('ssl', $i18n) ?></label>
                </div>
            </div>
            <div class="form-group-inline">
                <input type="text" name="smtpusername" id="smtpusername" autocomplete="off"
                    placeholder="<?= translate('smtp_username', $i18n) ?>"
                    value="<?= htmlspecialchars($smtpConfiguration['values']['username']) ?>"
                    <?= wallos_managed_input_attrs($smtpConfiguration, 'username') ?> />
            </div>
            <?php
            // Secrets are never rendered into the page. An empty field leaves the
            // stored value untouched; removing it is an explicit action.
            ?>
            <div class="form-group-inline">
                <?php if ($smtpPasswordStatus['managed']): ?>
                    <input type="text" id="smtppasswordstatus" disabled
                        data-managed-by="<?= htmlspecialchars($smtpConfiguration['managed_by']['password'] ?? '') ?>"
                        value="<?= $smtpPasswordStatus['configured'] ? translate('configured', $i18n) : translate('not_configured', $i18n) ?>" />
                <?php else: ?>
                    <input type="password" name="smtppassword" id="smtppassword" autocomplete="off"
                        placeholder="<?= $smtpPasswordStatus['configured']
                            ? translate('smtp_password', $i18n) . ' — ' . translate('leave_empty_to_keep', $i18n)
                            : translate('smtp_password', $i18n) ?>" value="" />
                <?php endif; ?>
            </div>
            <?php if (!$smtpPasswordStatus['managed'] && $smtpPasswordStatus['configured']): ?>
                <div class="form-group-inline">
                    <input type="checkbox" id="smtppasswordremove" />
                    <label for="smtppasswordremove"><?= translate('remove_stored_secret', $i18n) ?></label>
                </div>
            <?php endif; ?>
            <div class="form-group-inline">
                <input type="text" name="fromemail" id="fromemail" autocomplete="off"
                    placeholder="<?= translate('from_email', $i18n) ?>"
                    value="<?= htmlspecialchars($smtpConfiguration['values']['from_email']) ?>"
                    <?= wallos_managed_input_attrs($smtpConfiguration, 'from_email') ?> />
            </div>
            <div class="form-group-inline">
                <input type="text" name="smtpfromname" id="smtpfromname" autocomplete="off"
                    placeholder="<?= translate('smtp_from_name', $i18n) ?>"
                    value="<?= htmlspecialchars($smtpConfiguration['values']['from_name']) ?>"
                    <?= wallos_managed_input_attrs($smtpConfiguration, 'from_name') ?> />
            </div>
            <div class="buttons">
                <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('test', $i18n) ?>"
                    id="testSmtpSettings" onClick="testSmtpSettingsButton()" />
                <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                    id="saveSmtpSettings" onClick="saveSmtpSettingsButton()" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i> <?= translate('smtp_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('smtp_usage_info', $i18n) ?>
                </p>
                <?= wallos_render_managed_notes($smtpConfiguration, $i18n) ?>
            </div>
        </div>
    </section>

    <section class="account-section">
        <header>
            <h2><?= translate('instance_integrations', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <h3><?= translate('currency_provider', $i18n) ?></h3>
            <div class="form-group">
                <label for="instanceCurrencyProvider"><?= translate('provider', $i18n) ?>:</label>
                <select id="instanceCurrencyProvider" <?= wallos_managed_input_attrs($currencyConfiguration, 'provider') ?>>
                    <option value="fixer" <?= (int) $currencyConfiguration['values']['provider'] === 0 ? 'selected' : '' ?>>fixer.io</option>
                    <option value="apilayer" <?= (int) $currencyConfiguration['values']['provider'] === 1 ? 'selected' : '' ?>>apilayer.com</option>
                </select>
            </div>
            <div class="form-group-inline">
                <?php if ($currencyKeyStatus['managed']): ?>
                    <input type="text" id="instanceCurrencyApiKeyStatus" disabled
                        data-managed-by="<?= htmlspecialchars($currencyConfiguration['managed_by']['api_key'] ?? '') ?>"
                        value="<?= $currencyKeyStatus['configured'] ? translate('configured', $i18n) : translate('not_configured', $i18n) ?>" />
                <?php else: ?>
                    <input type="password" id="instanceCurrencyApiKey" autocomplete="off"
                        placeholder="<?= $currencyKeyStatus['configured']
                            ? translate('api_key', $i18n) . ' — ' . translate('leave_empty_to_keep', $i18n)
                            : translate('api_key', $i18n) ?>" value="" />
                <?php endif; ?>
            </div>
            <?php if (!$currencyKeyStatus['managed'] && $currencyKeyStatus['configured']): ?>
                <div class="form-group-inline">
                    <input type="checkbox" id="instanceCurrencyApiKeyRemove" />
                    <label for="instanceCurrencyApiKeyRemove"><?= translate('remove_stored_secret', $i18n) ?></label>
                </div>
            <?php endif; ?>

            <h3><?= translate('ai_provider', $i18n) ?></h3>
            <div class="form-group">
                <label for="instanceAiProvider"><?= translate('provider', $i18n) ?>:</label>
                <select id="instanceAiProvider" <?= wallos_managed_input_attrs($aiConfiguration, 'type') ?>>
                    <option value=""><?= translate('not_configured', $i18n) ?></option>
                    <?php foreach (WALLOS_AI_PROVIDERS as $providerOption): ?>
                        <option value="<?= $providerOption ?>" <?= $aiConfiguration['values']['type'] === $providerOption ? 'selected' : '' ?>>
                            <?= $providerOption ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group-inline">
                <input type="text" id="instanceAiBaseUrl" autocomplete="off" placeholder="<?= translate('url', $i18n) ?>"
                    value="<?= htmlspecialchars($aiConfiguration['values']['url']) ?>"
                    <?= wallos_managed_input_attrs($aiConfiguration, 'url') ?> />
            </div>
            <div class="form-group-inline">
                <input type="text" id="instanceAiModel" autocomplete="off" placeholder="<?= translate('ai_model', $i18n) ?>"
                    value="<?= htmlspecialchars($aiConfiguration['values']['model']) ?>"
                    <?= wallos_managed_input_attrs($aiConfiguration, 'model') ?> />
            </div>
            <div class="form-group-inline">
                <?php if ($aiKeyStatus['managed']): ?>
                    <input type="text" id="instanceAiApiKeyStatus" disabled
                        data-managed-by="<?= htmlspecialchars($aiConfiguration['managed_by']['api_key'] ?? '') ?>"
                        value="<?= $aiKeyStatus['configured'] ? translate('configured', $i18n) : translate('not_configured', $i18n) ?>" />
                <?php else: ?>
                    <input type="password" id="instanceAiApiKey" autocomplete="off"
                        placeholder="<?= $aiKeyStatus['configured']
                            ? translate('api_key', $i18n) . ' — ' . translate('leave_empty_to_keep', $i18n)
                            : translate('api_key', $i18n) ?>" value="" />
                <?php endif; ?>
            </div>
            <?php if (!$aiKeyStatus['managed'] && $aiKeyStatus['configured']): ?>
                <div class="form-group-inline">
                    <input type="checkbox" id="instanceAiApiKeyRemove" />
                    <label for="instanceAiApiKeyRemove"><?= translate('remove_stored_secret', $i18n) ?></label>
                </div>
            <?php endif; ?>
            <div class="buttons">
                <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                    id="saveInstanceIntegrations" onClick="saveInstanceIntegrationsButton()" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('instance_integrations_info', $i18n) ?>
                </p>
                <?= wallos_render_managed_notes($currencyConfiguration, $i18n) ?>
                <?= wallos_render_managed_notes($aiConfiguration, $i18n) ?>
            </div>
        </div>
    </section>

    <section class="account-section">
    <header>
        <h2><?= translate('security_settings', $i18n) ?></h2> </header>
    <div class="admin-form">
        <div class="form-group-inline">
            <input type="text" name="local_webhook_notifications_allowlist" id="local_webhook_notifications_allowlist" autocomplete="off"
                placeholder="e.g., 192.168.1.5:8123, homeassistant.local" value="<?= htmlspecialchars($ssrfConfiguration['raw'], ENT_QUOTES, 'UTF-8') ?>" <?= oidc_input_attrs('allowlist', $ssrfManagedFields) ?> />
        </div>

        <div class="buttons">
            <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                id="saveSecuritySettings" onClick="saveSecuritySettingsButton()" />
        </div>

        <div class="settings-notes">
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('ssrf_protection_info', $i18n) ?>
            </p>
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('local_webhook_info', $i18n) ?>
            </p>
            <?php if ($ssrfConfiguration['is_managed']): ?>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('ssrf_allowlist_env_managed', $i18n) ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>

    <?php
    // Get latest version from admin table
    if (!is_null($settings['latest_version'])) {
        $latestVersion = $settings['latest_version'];
        $hasUpdate = version_compare($version, $latestVersion) == -1;
    } else {
        $hasUpdate = false;
    }

    // find unused upload logos

    // Get all logos in the subscriptions table
    $query = 'SELECT logo, logo_variant FROM subscriptions';
    $stmt = $db->prepare($query);
    $result = $stmt->execute();

    $logosOnDisk = [];
    $logosOnDB = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $logosOnDB[] = $row['logo'];
        $logosOnDB[] = $row['logo_variant'];
    }

    // Get all logos in the payment_methods table
    $query = 'SELECT icon FROM payment_methods';
    $stmt = $db->prepare($query);
    $result = $stmt->execute();

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!strstr($row['icon'], "images/uploads/icons/")) {
            $logosOnDB[] = $row['icon'];
        }
    }

    $logosOnDB = array_unique($logosOnDB);

    // Get all logos in the uploads folder
    $uploadDir = 'images/uploads/logos/';
    $uploadFiles = scandir($uploadDir);

    foreach ($uploadFiles as $file) {
        if ($file != '.' && $file != '..' && $file != 'avatars') {
            $logosOnDisk[] = ['logo' => $file];
        }
    }

    // Find unused logos
    $unusedLogos = [];
    foreach ($logosOnDisk as $disk) {
        $found = false;
        foreach ($logosOnDB as $dbLogo) {
            if ($disk['logo'] == $dbLogo) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $unusedLogos[] = $disk;
        }
    }

    $logosToDelete = count($unusedLogos);

    ?>

    <section class="account-section">
        <header>
            <h2>
                <?= translate('maintenance_tasks', $i18n) ?>
            </h2>
        </header>
        <div class="maintenance-tasks">
            <h3><?= translate('update', $i18n) ?></h3>
            <div class="form-group">
                <?php
                if ($hasUpdate) {
                    ?>
                    <div class="updates-list">
                        <p><?= translate('new_version_available', $i18n) ?>.</p>
                        <p>
                            <?= translate('current_version', $i18n) ?>:
                            <span>
                                <?= $version ?>
                                <a href="https://github.com/ellite/Wallos/releases/tag/<?= $version ?>" target="_blank">
                                    <i class="fa-solid fa-external-link"></i>
                                </a>
                            </span>
                        </p>
                        <p>
                            <?= translate('latest_version', $i18n) ?>:
                            <span>
                                <?= $latestVersion ?>
                                <a href="https://github.com/ellite/Wallos/releases/tag/<?= $latestVersion ?>"
                                    target="_blank">
                                    <i class="fa-solid fa-external-link"></i>
                                </a>
                            </span>
                        </p>
                    </div>
                    <?php
                } else {
                    ?>
                    <?= translate('on_current_version', $i18n) ?>
                    <?php
                }
                ?>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="updateNotification" <?= $settings['update_notification'] ? 'checked' : '' ?>
                    onchange="toggleUpdateNotification()" />
                <label for="updateNotification"><?= translate('show_update_notification', $i18n) ?></label>
            </div>
            <h3><?= translate('orphaned_logos', $i18n) ?></h3>
            <div class="form-group-inline">
                <input type="button" class="button thin mobile-grow" value="<?= translate('delete', $i18n) ?>"
                    id="deleteUnusedLogos" onClick="deleteUnusedLogosButton()" <?= $logosToDelete == 0 ? 'disabled' : '' ?> />
                <span class="number-of-logos bold"><?= $logosToDelete ?></span>
                <?= translate('orphaned_logos', $i18n) ?>
            </div>
            <h3><?= translate('cronjobs', $i18n) ?></h3>
            <div>
                <div class="inline-row">
                    <input type="button" value="Check for Updates" class="button tiny mobile-grow"
                        onclick="executeCronJob('checkforupdates')">
                    <input type="button" value="Send Notifications" class="button tiny mobile-grow"
                        onclick="executeCronJob('sendnotifications')">
                    <input type="button" value="Send Cancellation Notifications" class="button tiny mobile-grow"
                        onclick="executeCronJob('sendcancellationnotifications')">
                    <input type="button" value="Send Password Reset Emails" class="button tiny mobile-grow"
                        onclick="executeCronJob('sendresetpasswordemails')">
                    <input type="button" value="Send Verification Emails" class="button tiny mobile-grow"
                        onclick="executeCronJob('sendverificationemails')">
                    <input type="button" value="Update Exchange Rates" class="button tiny mobile-grow"
                        onclick="executeCronJob('updateexchange')">
                    <input type="button" value="Update Next Payments" class="button tiny mobile-grow"
                        onclick="executeCronJob('updatenextpayment')">
                    <input type="button" value="Store Total Yearly Cost" class="button tiny mobile-grow"
                        onclick="executeCronJob('storetotalyearlycost')">
                    <input type="button" value="Generate AI Recommendations" class="button tiny mobile-grow"
                        onclick="executeCronJob('generaterecommendations')">    
                </div>
                <div class="inline-row">
                    <textarea id="cronjobResult" class="thin" readonly></textarea>
                </div>
            </div>
        </div>
    </section>

    <section class="account-section">
        <header>
            <h2><?= translate('backup_and_restore', $i18n) ?></h2>
        </header>
        <div class="form-group-inline">
            <input type="button" class="button thin mobile-grow" value="<?= translate('backup', $i18n) ?>" id="backupDB"
                onClick="backupDBButton()" />
            <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('restore', $i18n) ?>"
                id="restoreDB" onClick="openRestoreDBFileSelect()" />
            <input type="file" name="restoreDBFile" id="restoreDBFile" style="display: none;" onChange="restoreDBButton()"
                accept=".zip">
        </div>
        <div class="settings-notes">
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('restore_info', $i18n) ?>
            </p>
        </div>
    </section>

</section>
<script src="scripts/admin.js?<?= $version ?>"></script>

<?php
require_once 'includes/footer.php';
?>
