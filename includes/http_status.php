<?php
/*
  Reading the outcome of a stream request.

  file_get_contents() reports failure as a bare false, which collapses four
  different situations into one: a refused credential, an exhausted quota, a
  fault at the far end, and nothing answering at all. Callers then say "could
  not be reached", which is right once out of four times and sends the reader
  after a network problem in the other three (issue #101).

  Two facts make the four recoverable, and both are already there:

  PHP populates $http_response_header only when an HTTP response arrived. Its
  absence is the genuine outage; its presence means something answered, even if
  the answer was no.

  With 'ignore_errors' => true in the stream context, a 4xx or 5xx stops being
  false and becomes the body the provider sent — which usually explains itself
  better than any category assigned from outside.

  Both functions take their input as arguments and touch no network, so the
  behaviour can be tested without one.
*/

/**
 * The status code of the response that finally answered.
 *
 * @param array|null $headers Typically $http_response_header.
 * @return int|null Null when no HTTP response arrived at all.
 */
function wallos_http_status_code($headers)
{
    if (!is_array($headers)) {
        return null;
    }

    $status = null;

    foreach ($headers as $header) {
        // Redirects append each hop's headers to the same array, so the last
        // status line is the one describing the response actually received.
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#i', (string) $header, $match)) {
            $status = (int) $match[1];
        }
    }

    return $status;
}

/**
 * Why a currency provider request failed, in terms an administrator can act on.
 *
 * @param int|null   $status Result of wallos_http_status_code().
 * @param array|null $body   The decoded response body, when there was one.
 * @return string
 */
function wallos_provider_failure_message($status, $body)
{
    // Both providers explain themselves in the body, and their wording is more
    // useful than the category: "You have exceeded your daily rate limit" says
    // what to do about it, "quota exhausted" only says what happened.
    $detail = '';
    if (is_array($body) && isset($body['error']['info'])) {
        $detail = trim((string) $body['error']['info']);
    }

    if ($status === null) {
        // No response at all: DNS, a refused connection, a timeout. The only
        // case where the old message was the correct one.
        return 'The currency provider could not be reached.';
    }

    if ($status === 401 || $status === 403) {
        $message = 'The currency provider rejected the API key (HTTP ' . $status . ').';
    } elseif ($status === 429) {
        $message = 'The currency provider refused the request: its quota is exhausted (HTTP 429).';
    } elseif ($status >= 500) {
        $message = 'The currency provider reported a fault of its own (HTTP ' . $status . ').';
    } elseif ($status >= 400) {
        $message = 'The currency provider refused the request (HTTP ' . $status . ').';
    } else {
        // fixer.io answers 200 and puts the refusal in the body, so a 2xx that
        // reaches this function is still a failure — just one that has to be
        // read rather than counted.
        $message = 'The currency provider returned an error.';
    }

    if ($detail !== '') {
        $message .= ' It said: ' . $detail;
    }

    return $message;
}
