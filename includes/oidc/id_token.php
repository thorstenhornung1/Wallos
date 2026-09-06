<?php

/**
 * Standards-compliant ID-token validation (OIDC Session Authority v2, Phase 3a
 * / WP2 / §11).
 *
 * Until now the ID token was parsed only for its `sid`, and identity came from
 * UserInfo. That is not enough: the ID token is the OIDC authentication
 * assertion, and an assertion that is not verified is not an assertion. This
 * turns every ID token Wallos accepts — at login AND at silent resume — into
 * something checked the way OIDC Core §3.1.3.7 requires:
 *
 *   - the signature, against the provider's published keys (never a key carried
 *     inside the token — see below);
 *   - an explicit algorithm ALLOWLIST (RS256/384/512), so `alg=none` and the
 *     RSA/HMAC confusion are refused outright rather than guarded against;
 *   - the exact issuer (the configured or discovered one);
 *   - that the audience includes the configured client_id, handling the
 *     multi-audience form, with `azp` checked when the provider sends it;
 *   - `exp`, a reasonable `iat`, a non-empty `sub`;
 *   - the exact `nonce` this authorization transaction carried, so a token
 *     minted for another request cannot be replayed into this one.
 *
 * The signature verification reuses the vetted primitives in jwt.php
 * (wallos_jwt_verify_with_jwks: RS-only, no alg:none, kid-pinned, use:sig) and
 * the JWKS cache in backchannel.php (wallos_oidc_fetch_jwks, migration 000081),
 * which is itself routed through validate_oidc_endpoint_url (#153). Nothing here
 * reads a `jwk`/`x5c` from the token header, so a key embedded in the token is
 * never a key that verifies it.
 *
 * The result carries `signature_verified` separately from `valid`. A discovery-
 * configured provider hands over a jwks_uri and the signature is checked; every
 * claim check runs regardless. A caller that has a jwks_uri MUST refuse a token
 * whose signature did not verify; a legacy install with no discovery (so no
 * jwks_uri) cannot check the signature, but every other check still runs and the
 * caller logs that the signature was unverifiable — bounded degradation, never
 * an open door, because a forged token would still have to carry this
 * transaction's server-side nonce and arrive down the pinned-TLS token channel.
 */

require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/backchannel.php';

/**
 * Whether a UserInfo response belongs to the same subject the validated ID token
 * asserted (OIDC Session Authority v2, §12; test E).
 *
 * OIDC Core §5.3.2 requires the UserInfo `sub` to equal the ID token `sub`; a
 * response whose `sub` differs is a substituted identity and MUST be refused, so
 * the profile of one account can never be attached to another's authenticated
 * session. Compared in constant time. An absent or empty UserInfo `sub` names no
 * subject and cannot match.
 *
 * @param string $idTokenSub the validated ID token subject
 * @param array  $userInfo   the parsed UserInfo response
 * @return bool
 */
function wallos_oidc_userinfo_sub_matches($idTokenSub, $userInfo)
{
    if (!is_string($idTokenSub) || $idTokenSub === '') {
        return false;
    }

    $userInfoSub = is_array($userInfo) && isset($userInfo['sub']) && is_string($userInfo['sub'])
        ? $userInfo['sub']
        : '';

    return $userInfoSub !== '' && hash_equals($idTokenSub, $userInfoSub);
}

/**
 * The provider's JWKS URI for this configuration, read from the discovery
 * document, or '' when the install has no discovery (a manual configuration
 * with no issuer set).
 *
 * The signing keys live at the jwks_uri the provider publishes in its discovery
 * document, so signature verification is available exactly when discovery is —
 * which, for the tested target (Authentik), is always. A caller passes the
 * result into the validator's `jwks_uri` expectation.
 *
 * @param array $oidcConfiguration the array wallos_get_effective_oidc_configuration() returns
 * @return string
 */
function wallos_oidc_discovery_jwks_uri($oidcConfiguration)
{
    $document = isset($oidcConfiguration['discovery_document']) ? $oidcConfiguration['discovery_document'] : null;
    if (!is_array($document) || !isset($document['jwks_uri']) || !is_string($document['jwks_uri'])) {
        return '';
    }

    return trim($document['jwks_uri']);
}

/**
 * The issuer to hold an ID token to for this configuration: the discovery
 * document's issuer when discovery ran (it is authoritative and already checked
 * to equal the configured issuer), the configured issuer otherwise.
 *
 * @param array $oidcConfiguration
 * @return string
 */
function wallos_oidc_expected_issuer($oidcConfiguration)
{
    $document = isset($oidcConfiguration['discovery_document']) ? $oidcConfiguration['discovery_document'] : null;
    if (is_array($document) && isset($document['issuer']) && is_string($document['issuer']) && trim($document['issuer']) !== '') {
        return trim($document['issuer']);
    }

    $settings = isset($oidcConfiguration['settings']) && is_array($oidcConfiguration['settings'])
        ? $oidcConfiguration['settings']
        : [];

    return isset($settings['issuer']) ? trim((string) $settings['issuer']) : '';
}

/**
 * The signing algorithms an ID token may use. RSA signatures only, the same
 * family jwt.php verifies. `alg=none` and every HMAC variant are absent on
 * purpose: an unsigned token has nothing to trick, and a verifier that accepts
 * both HMAC and RSA can be fooled into checking an attacker's HS256 token
 * against the public RSA key the attacker also holds.
 *
 * @return string[]
 */
function wallos_oidc_id_token_allowed_algorithms()
{
    return ['RS256', 'RS384', 'RS512'];
}

/**
 * Whether a JWKS document carries a key with this id.
 *
 * Used to decide whether a signature failure is worth a single JWKS refresh: a
 * kid the cached document does not carry is a provider that has rotated its
 * signing key, which one fresh fetch fixes; a kid that IS present but still does
 * not verify is a bad signature, and refetching the same keys would not change
 * that.
 *
 * @param array  $jwks ['keys' => [...]]
 * @param string $kid
 * @return bool
 */
function wallos_oidc_jwks_has_kid($jwks, $kid)
{
    if (!is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
        return false;
    }

    foreach ($jwks['keys'] as $key) {
        if (is_array($key) && isset($key['kid']) && $key['kid'] === $kid) {
            return true;
        }
    }

    return false;
}

/**
 * Verify an ID token's signature against the provider's JWKS, refreshing the
 * cached keys ONCE when the token names a kid the cache does not carry.
 *
 * @param WallosDatabase $db
 * @param array          $parsed  result of wallos_jwt_parse()
 * @param string         $jwksUri
 * @return bool
 */
function wallos_oidc_verify_id_token_signature($db, $parsed, $jwksUri)
{
    $jwks = wallos_oidc_fetch_jwks($db, $jwksUri);
    if (is_array($jwks) && wallos_jwt_verify_with_jwks($parsed, $jwks)) {
        return true;
    }

    // Only an UNKNOWN kid earns a refresh. A kid the cache already carries that
    // still does not verify is a bad signature, not a stale cache, and refetching
    // the identical keys would only add a network touch to a certain failure.
    $kid = isset($parsed['header']['kid']) ? $parsed['header']['kid'] : null;
    if ($kid === null || wallos_oidc_jwks_has_kid($jwks, $kid)) {
        return false;
    }

    $fresh = wallos_oidc_fetch_jwks($db, $jwksUri, true);
    if ($fresh === null || $fresh === $jwks) {
        return false;
    }

    return wallos_jwt_verify_with_jwks($parsed, $fresh);
}

/**
 * Validate an ID token as the OIDC authentication assertion.
 *
 * Pure but for the JWKS fetch (which the cache makes free in the common case and
 * a test stands in for through the wallos_oidc_jwks_http_get seam), so every
 * rejection can be exercised without a provider.
 *
 * @param WallosDatabase $db
 * @param string         $idToken
 * @param array          $expectations ['issuer'=>string, 'client_id'=>string,
 *                                       'nonce'=>string, 'jwks_uri'=>string]
 * @param int|null       $now
 * @param int            $leeway seconds of clock skew tolerated
 * @return array{valid: bool, error: string|null, claims: array|null, signature_verified: bool}
 */
function wallos_oidc_validate_id_token($db, $idToken, $expectations, $now = null, $leeway = 120)
{
    $now = $now === null ? time() : (int) $now;
    $reject = function ($error) {
        return ['valid' => false, 'error' => $error, 'claims' => null, 'signature_verified' => false];
    };

    $parsed = wallos_jwt_parse($idToken);
    if ($parsed === null) {
        return $reject('malformed_token');
    }

    // The algorithm allowlist, before any key work. This is where alg=none and
    // the HMAC/RSA confusion are refused: an alg outside the list never reaches
    // signature verification at all.
    $algorithm = isset($parsed['header']['alg']) ? $parsed['header']['alg'] : '';
    if (!in_array($algorithm, wallos_oidc_id_token_allowed_algorithms(), true)) {
        return $reject('unsupported_alg');
    }

    // Signature first, when the keys are reachable. Reading claims from a token
    // that has not been verified is how unsigned data ends up trusted.
    $jwksUri = isset($expectations['jwks_uri']) ? trim((string) $expectations['jwks_uri']) : '';
    $signatureVerified = false;
    if ($jwksUri !== '') {
        if (!wallos_oidc_verify_id_token_signature($db, $parsed, $jwksUri)) {
            return $reject('invalid_signature');
        }
        $signatureVerified = true;
    }

    $claims = $parsed['payload'];
    $ok = function ($claims, $signatureVerified) {
        return ['valid' => true, 'error' => null, 'claims' => $claims, 'signature_verified' => $signatureVerified];
    };

    // Issuer: the exact configured/discovered one. Without a configured issuer
    // (a manual install with no discovery) there is nothing to compare against,
    // so the token's own iss is taken as canonical and only required to be
    // present — trailing slashes normalised the way discovery already does.
    $tokenIssuer = isset($claims['iss']) && is_string($claims['iss']) ? $claims['iss'] : '';
    $expectedIssuer = isset($expectations['issuer']) ? trim((string) $expectations['issuer']) : '';
    if ($tokenIssuer === '') {
        return $reject('missing_issuer');
    }
    if ($expectedIssuer !== '' && rtrim($tokenIssuer, '/') !== rtrim($expectedIssuer, '/')) {
        return $reject('wrong_issuer');
    }

    // Audience: the configured client_id must be among the token's audiences,
    // whether aud is a single string or an array (the multi-audience form).
    $clientId = isset($expectations['client_id']) ? (string) $expectations['client_id'] : '';
    if ($clientId === '') {
        return $reject('no_client_id');
    }
    $audience = isset($claims['aud']) ? $claims['aud'] : null;
    $audienceList = is_array($audience) ? $audience : [$audience];
    if (!in_array($clientId, $audienceList, true)) {
        return $reject('wrong_audience');
    }

    // azp when the provider sends it (mandatory reading of §11's "required"):
    // with more than one audience the authorized party MUST be this client, and
    // whenever azp is present at all it must name this client.
    if (array_key_exists('azp', $claims)) {
        $azp = is_string($claims['azp']) ? $claims['azp'] : '';
        if ($azp !== $clientId) {
            return $reject('wrong_azp');
        }
    } elseif (count($audienceList) > 1) {
        // Multiple audiences and no azp to say which client is the authorized
        // party: the token cannot be tied to this client.
        return $reject('missing_azp');
    }

    // exp: present and not past (with skew leeway).
    if (!isset($claims['exp']) || !is_int($claims['exp'])) {
        return $reject('missing_exp');
    }
    if ($claims['exp'] < $now - $leeway) {
        return $reject('expired');
    }

    // iat: present, an integer, and not from the future beyond skew — a
    // "reasonable" issued-at, per §11.
    if (!isset($claims['iat']) || !is_int($claims['iat'])) {
        return $reject('missing_iat');
    }
    if ($claims['iat'] > $now + $leeway) {
        return $reject('issued_in_the_future');
    }

    // A non-empty subject: the immutable half of the (iss, sub) identity key.
    $subject = isset($claims['sub']) && is_string($claims['sub']) ? $claims['sub'] : '';
    if ($subject === '') {
        return $reject('missing_subject');
    }

    // The exact nonce this authorization transaction carried. A token minted for
    // any other request — replayed, injected — fails here. Compared in constant
    // time; a missing or empty expected nonce is a programming error, not a token
    // Wallos may accept, so it is refused rather than skipped.
    $expectedNonce = isset($expectations['nonce']) ? (string) $expectations['nonce'] : '';
    $tokenNonce = isset($claims['nonce']) && is_string($claims['nonce']) ? $claims['nonce'] : '';
    if ($expectedNonce === '' || $tokenNonce === '' || !hash_equals($expectedNonce, $tokenNonce)) {
        return $reject('nonce_mismatch');
    }

    return $ok($claims, $signatureVerified);
}
