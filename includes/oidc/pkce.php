<?php

/**
 * PKCE (Proof Key for Code Exchange, RFC 7636) for the OIDC authorization-code
 * flow.
 *
 * Wallos is a confidential client, so an intercepted authorization code cannot
 * be redeemed without the client secret, and the session-bound state already
 * binds the callback to the browser. PKCE adds a second binding that does not
 * depend on the secret: the code can only be redeemed by the client that
 * started the flow. That is the direction OAuth 2.1 makes a MUST for every
 * client, confidential ones included. Only S256 is offered; the plain method is
 * never sent.
 *
 * The verifier lives in the session beside the state and is consumed in the
 * same breath (includes/oidc/consume_oidc_callback.php), so the two share one
 * single-use lifecycle.
 */

/**
 * A fresh code verifier: 32 random bytes, base64url without padding, which is a
 * 43-character string well inside RFC 7636's 43-128 range.
 *
 * @return string
 */
function wallos_oidc_generate_code_verifier()
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/**
 * The S256 challenge for a verifier: base64url(sha256(verifier)), unpadded,
 * taken over the raw hash bytes.
 *
 * @param string $codeVerifier
 * @return string
 */
function wallos_oidc_code_challenge($codeVerifier)
{
    return rtrim(strtr(base64_encode(hash('sha256', (string) $codeVerifier, true)), '+/', '-_'), '=');
}

/**
 * The form fields for the authorization-code token exchange.
 *
 * A pure function on purpose: the exchange body is then checkable without a
 * socket, and the caller (includes/oidc/handle_oidc_callback.php) sends exactly
 * these fields.
 *
 * A confidential client sends its secret; a public client has none, and sending
 * client_secret= as an empty parameter is not the same as not authenticating
 * with one — strict providers read the empty value as a failed client
 * authentication, so it is omitted entirely rather than sent blank. The verifier
 * is likewise only added when present: a flow that never issued one, or has
 * already consumed it, sends none, and a provider that ignores PKCE still
 * completes.
 *
 * @param array       $settings     the effective OIDC settings
 * @param string      $code         the authorization code
 * @param string      $redirectUri
 * @param string|null $codeVerifier the PKCE verifier, or null when there is none
 * @return array
 */
function wallos_oidc_token_request_fields($settings, $code, $redirectUri, $codeVerifier = null)
{
    $fields = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'client_id' => $settings['client_id'],
    ];

    if ((string) $settings['client_secret'] !== '') {
        $fields['client_secret'] = $settings['client_secret'];
    }

    if (is_string($codeVerifier) && $codeVerifier !== '') {
        $fields['code_verifier'] = $codeVerifier;
    }

    return $fields;
}
