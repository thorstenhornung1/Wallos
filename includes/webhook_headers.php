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

/**
 * The custom headers a user stored, as request header lines (upstream #1212).
 *
 * One field, four readers, and they did not agree: the notification job
 * decoded it as JSON, the cancellation job split it into lines, and the ntfy
 * blocks mapped a decoded object into "Name: value". So the same stored value
 * behaved differently depending on which job read it, and upstream's
 * notification job ended its whole run on a value the others merely ignored:
 * json_decode() answers null for anything it cannot read, and on PHP 8
 * curl_setopt(CURLOPT_HTTPHEADER, null) is a TypeError, which in a cron job
 * is every remaining channel and every remaining account going unnotified.
 *
 * Both shapes that were already in use are accepted: a JSON object, which is
 * what the ntfy blocks documented by example, and a JSON array of complete
 * header lines, which is what a value handed straight to cURL had to be.
 * Anything else yields no headers, so a typo in an optional field costs the
 * headers rather than the notifications.
 *
 * @param mixed $stored The value as it came out of the database or a form.
 * @return string[] Complete "Name: value" lines, possibly empty.
 */
function wallos_webhook_custom_headers($stored)
{
    if (!is_string($stored) || trim($stored) === '') {
        return [];
    }

    $decoded = json_decode($stored, true);

    if (!is_array($decoded)) {
        return [];
    }

    $headers = [];

    foreach ($decoded as $key => $value) {
        // Anything that cannot be written as one header line is skipped rather
        // than being stringified into something a receiver would reject.
        if (is_array($value) || is_object($value) || $value === null) {
            continue;
        }

        $line = is_int($key)
            ? trim((string) $value)
            : trim((string) $key) . ': ' . trim((string) $value);

        if ($line !== '' && strpos($line, ':') !== false) {
            $headers[] = $line;
        }
    }

    return $headers;
}
