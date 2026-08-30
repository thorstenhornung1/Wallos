<?php

/**
 * The headers a webhook request should carry (#128; upstream #990).
 *
 * cURL labels a string body application/x-www-form-urlencoded unless told
 * otherwise, so a JSON payload arrived at receivers as one giant form key —
 * the whole JSON as the key, an empty string as the value. Custom headers
 * were only ever a workaround for that default. The rule: when the payload
 * is valid JSON and the custom headers do not already name a Content-Type,
 * say application/json; a custom Content-Type always wins unchanged.
 *
 * @param string     $payload       The body about to be sent.
 * @param array|null $customHeaders Headers the user configured, if any.
 * @return string[]
 */
function wallos_webhook_headers($payload, $customHeaders)
{
    $headers = is_array($customHeaders) ? array_values($customHeaders) : [];

    foreach ($headers as $header) {
        if (stripos((string) $header, 'content-type:') === 0) {
            return $headers;
        }
    }

    json_decode((string) $payload);
    if (trim((string) $payload) !== '' && json_last_error() === JSON_ERROR_NONE) {
        $headers[] = 'Content-Type: application/json';
    }

    return $headers;
}
