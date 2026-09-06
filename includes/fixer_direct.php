<?php
/*
  The direct fixer.io transport, and the one place that decides http vs https
  for it.

  data.fixer.io serves its FREE tier over http only — https is a paid feature —
  so the direct-fixer path (provider mode 0) has always sent the access_key in
  cleartext in the query string. Three call sites did this independently: the
  rate fetch and the symbol list in includes/currency_provider.php, and the
  main-currency refresh in endpoints/user/save_user.php. This centralises the
  URL building and the try-https-then-fall-back-to-http decision so all three
  share one behaviour (issue #141).

  The decision:

    * https is attempted first, so a PAID plan is protected automatically and
      its key never leaves over http.
    * http is used only when the plan GENUINELY rejects https — fixer.io answers
      an https request on a plan that forbids it with a JSON body carrying error
      code 105 / type https_access_restricted (measured as HTTP 200 with the
      refusal in the body). That, and only that, is the signal to fall back.
    * A bare transport failure (no https response at all) is NOT a fallback
      trigger: it is treated as the provider being unreachable, the same as any
      other outage, rather than an excuse to put the key on the wire in the
      clear. So every http fallback here is a proven https-restriction, and a
      caller can warn about it without false alarms.

  Remembering the answer: a process that has once seen the plan reject https
  goes straight to http for the rest of its life, so a cron run refreshing many
  accounts pays the failed-https round-trip once rather than once per account.
  A brand-new process re-probes https once on its first direct-fixer request —
  one wasted round-trip on the free tier, which is cheap and is what lets an
  upgraded (now paid) plan be noticed and protected without a stored flag going
  stale. Documented here because it is a deliberate trade, not an oversight.

  Self-contained on purpose: no database, no Wallos globals, nothing
  fork-specific. The hunk is vanilla-relevant and can travel upstream unchanged.
  The caller supplies the transport — the same wallos_provider_http_get() seam
  the currency tests already mock — and decides what to do with the http_fallback
  flag the result carries. The one non-silent side effect is an error_log line
  at the fallback point, phrased in provider-neutral terms (apilayer / a paid
  fixer plan); the fork's richer, Frankfurter-mentioning notice lives on the
  settings page.
*/

if (!function_exists('wallos_fixer_direct_url')) {
    /**
     * The direct-fixer URL for an endpoint, over the given scheme.
     *
     * Built by concatenation rather than http_build_query() so the string is
     * byte-for-byte what the three call sites produced before this file existed:
     * access_key first, then the caller's parameters in the order given.
     *
     * @param string               $scheme 'https' or 'http'
     * @param string               $path   'latest' or 'symbols'
     * @param string               $apiKey
     * @param array<string,string> $params Extra query parameters, in order.
     * @return string
     */
    function wallos_fixer_direct_url($scheme, $path, $apiKey, array $params = [])
    {
        $query = 'access_key=' . $apiKey;

        foreach ($params as $name => $value) {
            $query .= '&' . $name . '=' . $value;
        }

        return $scheme . '://data.fixer.io/api/' . $path . '?' . $query;
    }
}

if (!function_exists('wallos_fixer_https_restriction')) {
    /**
     * Reads an https response for the one thing that justifies falling back to
     * http: the plan does not allow https.
     *
     * @param array $result A transport result: ['body' => string|false, ...].
     * @return string '' https is usable (a success, or a failure that is not
     *                about https and must be surfaced as-is); 'plan' the plan
     *                forbids https and http is the documented fallback;
     *                'transport' no https response arrived at all.
     */
    function wallos_fixer_https_restriction($result)
    {
        $body = $result['body'] ?? null;

        if ($body === false || $body === null) {
            return 'transport';
        }

        $decoded = json_decode((string) $body, true);

        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $error = $decoded['error'];
            $code = isset($error['code']) ? (int) $error['code'] : 0;
            $type = strtolower(trim((string) ($error['type'] ?? '')));
            $info = strtolower((string) ($error['info'] ?? ''));

            // 105 / https_access_restricted is fixer.io's own name for it. The
            // wording is checked as well so a renumber on their side does not
            // silently turn the fallback off and strand every free-tier user on
            // a failing https request.
            if ($code === 105
                || strpos($type, 'https_access_restricted') !== false
                || (strpos($info, 'https') !== false
                    && (strpos($info, 'restrict') !== false
                        || strpos($info, 'does not support') !== false
                        || strpos($info, 'not support') !== false))) {
                return 'plan';
            }
        }

        return '';
    }
}

if (!function_exists('wallos_fixer_direct_get')) {
    /**
     * A direct-fixer GET that prefers https and falls back to http only when the
     * plan rejects https.
     *
     * @param string               $path      'latest' or 'symbols'
     * @param string               $apiKey
     * @param array<string,string> $params    Extra query parameters, in order.
     * @param callable|null        $transport function($url): array{body:..., headers:...}
     *                                        Defaults to the wallos_provider_http_get()
     *                                        seam, or file_get_contents() where that
     *                                        client is not loaded (save_user.php).
     * @return array{body: string|false, headers: array|null, http_fallback: bool, https_restricted: bool}
     */
    function wallos_fixer_direct_get($path, $apiKey, array $params = [], $transport = null)
    {
        // Per api_key, for the life of this process. $httpOnly skips the wasted
        // https probe once the plan is known to reject it; $warned keeps the log
        // line to one per key however many accounts share it.
        static $httpOnly = [];
        static $warned = [];

        if ($transport === null) {
            $transport = function ($url) {
                $context = stream_context_create([
                    'http' => ['method' => 'GET', 'ignore_errors' => true],
                ]);

                if (function_exists('wallos_provider_http_get')) {
                    return wallos_provider_http_get($url, $context);
                }

                $body = @file_get_contents($url, false, $context);

                return [
                    'body' => $body,
                    'headers' => isset($http_response_header) ? $http_response_header : null,
                ];
            };
        }

        $hash = md5((string) $apiKey);
        $planRestricted = false;

        if (empty($httpOnly[$hash])) {
            $httpsResult = $transport(wallos_fixer_direct_url('https', $path, $apiKey, $params));
            $signal = wallos_fixer_https_restriction($httpsResult);

            if ($signal !== 'plan') {
                // Either https worked, or it failed for a reason that is not a
                // plan restriction (an outage, a rejected key). Both are the
                // real answer, and neither is a reason to put the key on the
                // wire in the clear.
                $httpsResult['http_fallback'] = false;
                $httpsResult['https_restricted'] = false;

                return $httpsResult;
            }

            // A definite plan refusal: remember it so the rest of this process
            // does not re-probe, and fall through to http.
            $httpOnly[$hash] = true;
            $planRestricted = true;
        } else {
            $planRestricted = true;
        }

        $result = $transport(wallos_fixer_direct_url('http', $path, $apiKey, $params));
        $result['http_fallback'] = true;
        $result['https_restricted'] = $planRestricted;

        // Non-silent, once per key per process. Provider-neutral wording so the
        // line is upstream-safe; the settings page carries the fork's fuller
        // notice, keyless Frankfurter included.
        if (empty($warned[$hash])) {
            $warned[$hash] = true;
            error_log('Wallos: fixer.io serves this plan over http only, so the '
                . 'access_key was sent in cleartext in the request URL. Upgrade to a '
                . 'paid fixer.io plan for https, or use apilayer.com\'s free tier, '
                . 'which is https and sends the key in a header instead of the URL.');
        }

        return $result;
    }
}
