<?php

/**
 * RP-initiated logout.
 *
 * Wallos used to redirect to a configured logout URL with the OIDC *callback*
 * URI as the return target — sending a user who just signed out back through a
 * sign-in endpoint — and with no id_token_hint, which is what providers use to
 * decide which session to end. Without it a provider may refuse, or prompt, or
 * silently do nothing.
 *
 * Everything here is a pure function so the URL Wallos builds can be asserted
 * without a provider, a session, or a browser.
 */

/**
 * Where to send the user to end the provider's session.
 *
 * Resolution order:
 *   1. an explicitly configured logout URL — the operator knows their provider
 *   2. end_session_endpoint from discovery — no reason to make them paste it
 *   3. nothing, meaning Wallos logs out locally and stays put
 *
 * @param array      $settings
 * @param array|null $discoveryDocument
 * @return string|null
 */
function wallos_oidc_end_session_url($settings, $discoveryDocument)
{
    $configured = trim((string) ($settings['logout_url'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    if (is_array($discoveryDocument) && !empty($discoveryDocument['end_session_endpoint'])) {
        $discovered = trim((string) $discoveryDocument['end_session_endpoint']);
        if ($discovered !== '') {
            return $discovered;
        }
    }

    return null;
}

/**
 * Where the provider should send the user back to.
 *
 * Not the callback URI. That endpoint exists to complete a sign-in, and handing
 * it a returning logout is how a logout turns back into a login. The default is
 * the login page carrying a marker, so the page can say what happened.
 *
 * @param array $settings
 * @return string|null
 */
function wallos_oidc_post_logout_redirect_url($settings)
{
    $configured = trim((string) ($settings['post_logout_redirect_url'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    // Derived from the redirect URI, which is the one address Wallos is certain
    // to know about itself. Only its origin and directory are used.
    $redirect = trim((string) ($settings['redirect_url'] ?? ''));
    if ($redirect === '') {
        return null;
    }

    $parts = parse_url($redirect);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }

    $base = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $base .= ':' . $parts['port'];
    }

    $path = $parts['path'] ?? '/';
    // Strip a trailing file name so both '/login.php' and '/' produce the same
    // directory to hang login.php off.
    if (substr($path, -1) !== '/') {
        $path = substr($path, 0, strrpos($path, '/') + 1);
    }
    if ($path === '') {
        $path = '/';
    }

    return $base . $path . 'login.php?logged_out=1';
}

/**
 * Build the end-session request.
 *
 * id_token_hint tells the provider which session to end. state lets Wallos
 * recognise its own return. post_logout_redirect_uri must be registered with
 * the provider or it will be ignored — that is a provider-side setting, not
 * something Wallos can arrange.
 *
 * @param string      $endSessionUrl
 * @param string|null $idToken
 * @param string|null $postLogoutRedirectUrl
 * @param string|null $state
 * @return string
 */
function wallos_oidc_build_end_session_url($endSessionUrl, $idToken, $postLogoutRedirectUrl, $state)
{
    $parameters = [];

    // The redirect and the state ride only with the hint. The certification
    // rule is against sending them alone: a provider that cannot tie
    // post_logout_redirect_uri to a session MUST refuse, and Authentik does,
    // with a 400 the signing-out user sees (#123). A bare end-session
    // request still ends the provider session; only the automatic return is
    // lost, which is the smaller harm.
    if (is_string($idToken) && $idToken !== '') {
        $parameters['id_token_hint'] = $idToken;

        if (is_string($postLogoutRedirectUrl) && $postLogoutRedirectUrl !== '') {
            $parameters['post_logout_redirect_uri'] = $postLogoutRedirectUrl;
        }
        if (is_string($state) && $state !== '') {
            $parameters['state'] = $state;
        }
    }

    if ($parameters === []) {
        return $endSessionUrl;
    }

    // The configured URL may already carry a query string.
    $separator = strpos($endSessionUrl, '?') === false ? '?' : '&';

    return $endSessionUrl . $separator . http_build_query($parameters);
}

/**
 * Whether a returning logout carries the state Wallos issued.
 *
 * Providers are not required to return state, so an absent one is accepted —
 * refusing it would break logout against conforming providers. A state that is
 * present and wrong is not accepted: that is a response to somebody else's
 * request.
 *
 * @param string|null $returned
 * @param string|null $expected
 * @return bool
 */
function wallos_oidc_logout_state_is_valid($returned, $expected)
{
    if (!is_string($returned) || $returned === '') {
        return true;
    }

    if (!is_string($expected) || $expected === '') {
        return false;
    }

    return hash_equals($expected, $returned);
}
