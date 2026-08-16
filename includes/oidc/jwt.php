<?php

/**
 * The minimum needed to verify a signed token from an identity provider.
 *
 * Wallos vendors its dependencies rather than using Composer, and a JWT library
 * would be a large surface for one job. This does exactly that job: decode a
 * compact JWS, turn the provider's published RSA key into something OpenSSL can
 * use, and check the signature.
 *
 * Only RS256/384/512 are supported. Notably absent, on purpose:
 *
 *   - `alg: none`, the classic JWT forgery. There is no code path that accepts
 *     an unsigned token, so there is nothing to trick.
 *   - HMAC algorithms. A verifier that accepts both HMAC and RSA can be fooled
 *     into verifying an attacker's HS256 token against the public RSA key,
 *     which the attacker also has. Refusing the whole family removes the
 *     confusion rather than guarding against it.
 */

/**
 * Base64url decode, strictly.
 *
 * @param string $input
 * @return string|null null when the input is not valid base64url
 */
function wallos_jwt_base64url_decode($input)
{
    if (!is_string($input) || $input === '') {
        return null;
    }

    if (preg_match('/[^A-Za-z0-9\-_]/', $input) === 1) {
        return null;
    }

    $remainder = strlen($input) % 4;
    if ($remainder === 1) {
        return null;
    }
    if ($remainder !== 0) {
        $input .= str_repeat('=', 4 - $remainder);
    }

    $decoded = base64_decode(strtr($input, '-_', '+/'), true);

    return $decoded === false ? null : $decoded;
}

/**
 * Split a compact JWS into its parts without verifying anything.
 *
 * @param string $token
 * @return array|null ['header'=>array, 'payload'=>array, 'signing_input'=>string, 'signature'=>string]
 */
function wallos_jwt_parse($token)
{
    if (!is_string($token)) {
        return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    $headerJson = wallos_jwt_base64url_decode($parts[0]);
    $payloadJson = wallos_jwt_base64url_decode($parts[1]);
    $signature = wallos_jwt_base64url_decode($parts[2]);

    if ($headerJson === null || $payloadJson === null || $signature === null) {
        return null;
    }

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload)) {
        return null;
    }

    return [
        'header' => $header,
        'payload' => $payload,
        'signing_input' => $parts[0] . '.' . $parts[1],
        'signature' => $signature,
    ];
}

/**
 * DER length prefix for a payload of the given length.
 *
 * @param int $length
 * @return string
 */
function wallos_der_length($length)
{
    if ($length < 0x80) {
        return chr($length);
    }

    $bytes = '';
    while ($length > 0) {
        $bytes = chr($length & 0xFF) . $bytes;
        $length >>= 8;
    }

    return chr(0x80 | strlen($bytes)) . $bytes;
}

/**
 * DER INTEGER, unsigned.
 *
 * A leading 0x00 is prepended when the high bit is set, because DER integers
 * are signed and without it the value would read as negative.
 *
 * @param string $bytes big-endian magnitude
 * @return string
 */
function wallos_der_unsigned_integer($bytes)
{
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '') {
        $bytes = "\x00";
    }
    if (ord($bytes[0]) > 0x7F) {
        $bytes = "\x00" . $bytes;
    }

    return "\x02" . wallos_der_length(strlen($bytes)) . $bytes;
}

/**
 * Turn a JWKS RSA entry into a PEM public key.
 *
 * OpenSSL cannot read a JWK, and PHP has no built-in conversion, so the
 * SubjectPublicKeyInfo structure is assembled here:
 *
 *   SEQUENCE {
 *     SEQUENCE { OID 1.2.840.113549.1.1.1, NULL }
 *     BIT STRING { SEQUENCE { INTEGER n, INTEGER e } }
 *   }
 *
 * @param array $jwk
 * @return string|null PEM, or null when the entry is not a usable RSA key
 */
function wallos_jwk_to_pem($jwk)
{
    if (!is_array($jwk) || ($jwk['kty'] ?? '') !== 'RSA') {
        return null;
    }

    $modulus = wallos_jwt_base64url_decode($jwk['n'] ?? '');
    $exponent = wallos_jwt_base64url_decode($jwk['e'] ?? '');

    if ($modulus === null || $exponent === null || $modulus === '' || $exponent === '') {
        return null;
    }

    $rsaPublicKey = "\x30" . wallos_der_length(
        strlen(wallos_der_unsigned_integer($modulus) . wallos_der_unsigned_integer($exponent))
    ) . wallos_der_unsigned_integer($modulus) . wallos_der_unsigned_integer($exponent);

    // OID 1.2.840.113549.1.1.1 (rsaEncryption) followed by NULL parameters.
    $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

    // BIT STRING with zero unused bits.
    $bitString = "\x03" . wallos_der_length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

    $der = "\x30" . wallos_der_length(strlen($algorithm . $bitString)) . $algorithm . $bitString;

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/**
 * Verify an RS256/384/512 signature.
 *
 * @param string $signingInput
 * @param string $signature
 * @param string $pem
 * @param string $algorithm
 * @return bool
 */
function wallos_jwt_verify_signature($signingInput, $signature, $pem, $algorithm)
{
    $digests = [
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS384' => OPENSSL_ALGO_SHA384,
        'RS512' => OPENSSL_ALGO_SHA512,
    ];

    if (!isset($digests[$algorithm])) {
        return false;
    }

    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        return false;
    }

    // openssl_verify returns 1, 0 or -1; only 1 is a valid signature.
    return openssl_verify($signingInput, $signature, $key, $digests[$algorithm]) === 1;
}

/**
 * Verify a token against a JWKS.
 *
 * When the header names a key id, only that key is tried — a provider that
 * publishes several keys says which one it used, and trying the others would
 * accept a token signed with a key meant for something else.
 *
 * @param array $parsed result of wallos_jwt_parse()
 * @param array $jwks   ['keys' => [...]]
 * @return bool
 */
function wallos_jwt_verify_with_jwks($parsed, $jwks)
{
    if (!is_array($parsed) || !is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
        return false;
    }

    $algorithm = $parsed['header']['alg'] ?? '';
    if (!in_array($algorithm, ['RS256', 'RS384', 'RS512'], true)) {
        return false;
    }

    $keyId = $parsed['header']['kid'] ?? null;

    foreach ($jwks['keys'] as $jwk) {
        if (!is_array($jwk)) {
            continue;
        }
        if ($keyId !== null && ($jwk['kid'] ?? null) !== $keyId) {
            continue;
        }
        // A key published for encryption is not a key for verifying signatures.
        if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
            continue;
        }

        $pem = wallos_jwk_to_pem($jwk);
        if ($pem === null) {
            continue;
        }

        if (wallos_jwt_verify_signature($parsed['signing_input'], $parsed['signature'], $pem, $algorithm)) {
            return true;
        }
    }

    return false;
}
