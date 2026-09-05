<?php
/*
  The OIDC "Sign in with <provider>" entry on the login page.

  Rendered inside login.php's form. It reads the login page's own variables from
  the enclosing scope: $oidcEnabled, $password_login_disabled, $oidc_auth_url,
  $oidc_name and $i18n. It lives in its own file so the button can be rendered in
  isolation by a test across its three states — a configured provider name, no
  name, and OIDC disabled — without standing up the whole login page and its
  database, session and redirect side effects.

  The provider name is data: it is interpolated into a translated label, never
  hard-coded to any particular provider. With no name configured the label falls
  back to a neutral, translated string ("Login with your login provider") rather
  than naming one.
*/

if (empty($oidcEnabled)) {
    // No OIDC: the button is absent entirely, so an included page emits nothing.
    return;
}

$oidcProviderName = trim((string) ($oidc_name ?? ''));
$oidcButtonLabel = $oidcProviderName === ''
    ? translate('login_with_provider', $i18n)
    : translate('login_with', $i18n) . ' ' . $oidcProviderName;

// The separator only makes sense when there is a password form to sit beside.
// With password login disabled the button is the sole path and stands alone.
if (empty($password_login_disabled)) {
    ?>
    <span class="or-separator"><?= translate('or', $i18n) ?></span>
    <?php
}
?>
<a class="button secondary-button" href="<?= htmlspecialchars($oidc_auth_url) ?>">
    <?= htmlspecialchars($oidcButtonLabel) ?>
</a>
