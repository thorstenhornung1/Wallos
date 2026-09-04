<?php
/*
  Shared currency exchange provider client.

  The settings page, the manual update endpoint and the cron job all fetch rates
  through this file, using the credentials returned by
  wallos_get_effective_currency_config().
*/

require_once __DIR__ . '/integration_config.php';
require_once __DIR__ . '/http_status.php';

if (!function_exists('wallos_provider_http_get')) {
    /**
     * The one network touch of the provider client, separated so a test can
     * stand in for the provider without a socket — the same reasoning that
     * put the status logic into http_status.php: no test in this suite makes
     * a request, and the one that proved it the expensive way spent half a
     * year of a free tier (#104). A test defines its own version before this
     * file is loaded; the guard lets that stand.
     *
     * @param string   $url
     * @param resource $context
     * @return array{body: string|false, headers: array|null}
     */
    function wallos_provider_http_get($url, $context)
    {
        $body = @file_get_contents($url, false, $context);

        // Populated by PHP only when a response actually arrived, which is
        // what separates a refusal from an outage.
        return [
            'body' => $body,
            'headers' => isset($http_response_header) ? $http_response_header : null,
        ];
    }
}

/**
 * The base currency a provider will actually price in.
 *
 * fixer's free tier prices in EUR and nothing else, so EUR is not a choice
 * there — it is the only answer, and a caller asking for another base would
 * otherwise be silently given EUR-based numbers under a label saying
 * otherwise. Frankfurter prices in any of its currencies, so the user's own
 * main currency is used and the conversion step disappears with it (#140).
 *
 * Normalising here rather than at each call site is what lets the run cache
 * key on a base it can trust: two callers asking fixer for different bases are
 * asking the same question and must share one answer, while two Frankfurter
 * users with different main currencies are not and must not.
 *
 * @param array       $config   Result of wallos_get_effective_currency_config().
 * @param string|null $wanted   The base the caller would like, if it has one.
 * @return string Upper-case ISO code.
 */
function wallos_currency_request_base($config, $wanted = null)
{
    $provider = (int) ($config['values']['provider'] ?? 0);
    $wanted = strtoupper(trim((string) $wanted));

    if ($provider !== 2 || $wanted === '') {
        return 'EUR';
    }

    return $wanted;
}

/**
 * Whether a provider answers in the base it was asked for.
 *
 * The difference decides how a caller turns a response into stored rates: an
 * answer already in the user's own currency is written as it stands, while an
 * EUR-based one has to be divided through by the main currency's own rate.
 *
 * @param array $config Result of wallos_get_effective_currency_config().
 * @return bool
 */
function wallos_currency_provider_honours_base($config)
{
    return (int) ($config['values']['provider'] ?? 0) === 2;
}

/**
 * Turns Frankfurter's /v2/rates answer into the code => rate map the rest of
 * this file speaks in.
 *
 * v2 answers with a flat array of records — [{"base":"EUR","quote":"USD",
 * "rate":1.1612}, ...] — rather than the {"rates":{...}} object of the
 * retired api.frankfurter.app, which answers 301 now.
 *
 * An empty array is the trap worth naming: an unknown base is not an error
 * there, it is HTTP 200 with `[]` (measured 2026-09-04 with base=BTC). That
 * decodes to a perfectly good array, so a caller checking only is_array()
 * calls it a success, stores nothing, and marks the rates refreshed — after
 * which the freshness skip hides it until tomorrow. Null here, and the caller
 * treats it as the refusal it is.
 *
 * @param mixed $decoded json_decode(..., true) of the response body.
 * @return array<string, float>|null Null when the answer is not usable.
 */
function wallos_frankfurter_rates($decoded)
{
    if (!is_array($decoded) || $decoded === []) {
        return null;
    }

    $rates = [];

    foreach ($decoded as $record) {
        if (!is_array($record) || !isset($record['quote'], $record['rate'])) {
            // Not the shape v2 documents. One malformed record is not a reason
            // to discard the rest, but a body made entirely of them leaves
            // $rates empty and is refused below.
            continue;
        }

        $rates[strtoupper((string) $record['quote'])] = (float) $record['rate'];
    }

    return $rates === [] ? null : $rates;
}

/**
 * The explanation Frankfurter puts in its own body.
 *
 * Its errors are {"status":422,"message":"invalid currency: TOOLONG"}, which
 * is neither the {"error":{"info":...}} of fixer nor anything
 * wallos_provider_failure_message() reads. Kept here rather than added to the
 * shared message builder so that not one word of what fixer reports changes.
 *
 * @param mixed $decoded json_decode(..., true) of the response body.
 * @return string Empty when the body says nothing useful.
 */
function wallos_frankfurter_detail($decoded)
{
    if (!is_array($decoded) || !isset($decoded['message']) || !is_string($decoded['message'])) {
        return '';
    }

    return trim($decoded['message']);
}

/**
 * The currency codes safe to put in a Frankfurter request, and the ones left
 * behind.
 *
 * Measured 2026-09-04: a single malformed code answers 422 and takes the whole
 * request with it — `quotes=USD,TOOLONG` returns nothing at all, not USD. A
 * currency in Wallos is three free-text fields, so an invented code is
 * accepted and stored (#133); in the shared union request (#9) that one user's
 * "Lunarium" would stop every other user's rates. So the malformed codes are
 * held back and named, rather than being allowed to refuse the request for
 * everybody.
 *
 * A well-formed code the provider does not price — BTC, ETH; there is no
 * cryptocurrency in either scope of /v2/currencies — is a different matter and
 * is not filtered here: it is simply absent from the answer, and the caller
 * reports it by comparing what it asked for with what came back.
 *
 * @param string[] $codes
 * @return array{0: string[], 1: string[]} Accepted codes, then rejected ones.
 */
function wallos_frankfurter_partition_codes($codes)
{
    $accepted = [];
    $rejected = [];

    foreach ($codes as $code) {
        if (preg_match('/^[A-Za-z]{3}$/', (string) $code)) {
            $accepted[] = strtoupper((string) $code);
        } else {
            $rejected[] = (string) $code;
        }
    }

    return [$accepted, $rejected];
}

/**
 * Fetches exchange rates, in EUR or in the base the provider was asked for.
 *
 * The transport flag says whether this answer cost a request over the wire —
 * false for a refused config and for answers served from the per-run cache.
 * Call sites count provider consumption by it (#106), so a cached answer
 * must never carry the mark of the request it reuses.
 *
 * The base is part of the answer, not just of the request: a caller cannot
 * read the rates correctly without knowing what they are relative to, and the
 * one that assumes is the one that stores GBP-per-EUR as GBP-per-USD.
 *
 * @param array       $config Result of wallos_get_effective_currency_config().
 * @param string      $codes  Comma separated currency codes.
 * @param string|null $base   Wanted base; ignored by providers that price in EUR only.
 * @return array{success: bool, rates: array, base: string, unpriced: string[], usage: array{limit: int|null, used: int|null, limit_day: int|null, used_day: int|null}, message: string, transport: bool}
 */
function wallos_fetch_exchange_rates($config, $codes, $base = null)
{
    $base = wallos_currency_request_base($config, $base);

    $failure = [
        'success' => false,
        'rates' => [],
        // What the rates would have been relative to. Present on a failure as
        // well, because a caller reporting one still wants to name the base it
        // asked about.
        'base' => $base,
        // Codes the caller asked for that this answer does not price. A
        // provider that drops them in silence is the one outcome a user cannot
        // tell from a rate that simply did not move.
        'unpriced' => [],
        // The monthly pair apilayer reports, and the daily pair beside it. A
        // daily limit reached is not a monthly quota exhausted — the first
        // clears by tomorrow's cron, the second does not — so the two are
        // carried apart (#106).
        'usage' => ['limit' => null, 'used' => null, 'limit_day' => null, 'used_day' => null],
        'message' => '',
        'transport' => false,
        // The provider's own status, so a caller can tell a refusal that
        // belongs to the credential — a quota, a rejected key, an outage —
        // from one that belongs to the symbols it asked for. The message says
        // the same thing in prose, and prose is not something to branch on.
        'status' => null,
    ];

    if (empty($config['valid'])) {
        $failure['message'] = $config['notes'][0] ?? 'Currency provider is not configured.';

        return $failure;
    }

    $apiKey = (string) $config['values']['api_key'];
    $provider = (int) $config['values']['provider'];
    $usage = ['limit' => null, 'used' => null, 'limit_day' => null, 'used_day' => null];

    // One shared credential should not spend one provider request per user.
    // Within a run, an earlier answer serves any request whose codes it
    // covers — the same list, or a subset of a union fetched up front (#9).
    // A covering refusal answers too: a quota exhausted for the union is
    // exhausted for every part of it, and asking again with fewer symbols
    // would spend a call to learn the same thing (#117). The caller only
    // reads the rates it owns, so extra rates in a covering answer are
    // simply not looked at.
    static $cache = [];
    $requested = array_filter(array_map('trim', explode(',', $codes)));

    // The base belongs in this key, not just the credential. A cached answer
    // is only reusable by a caller asking the same question, and since #140
    // the base is part of the question: under a key of provider|api_key a USD
    // user is served the EUR user's answer, and the second request never
    // happens. Written and watched to fail before it was written: with the
    // base left out, two users with different main currencies cost one call
    // instead of two, both got bases=EUR, and the USD user's GBP came out at
    // 0.740656 where the provider's own USD-based figure is 0.74065 — the
    // division downstream corrects most of a wrong base, which is exactly what
    // makes this the kind of defect that survives review.
    $credential = $provider . '|' . $apiKey . '|' . $base;

    foreach ($cache as $entry) {
        if ($entry['credential'] === $credential
            && array_diff($requested, $entry['codes']) === []) {
            return $entry['result'];
        }
    }

    // Codes the request will not carry, reported alongside the ones the
    // provider simply did not price. Only the Frankfurter arm fills this;
    // fixer's behaviour with a malformed code is its own business and is not
    // being changed here.
    $malformed = [];

    if ($provider === 2) {
        // No key, no header, and https — there is nothing to authenticate and
        // so nothing that could excuse the plaintext the direct-fixer URLs in
        // this repo still use.
        list($askFor, $malformed) = wallos_frankfurter_partition_codes($requested);

        $apiUrl = 'https://api.frankfurter.dev/v2/rates?base=' . rawurlencode($base)
            . '&quotes=' . rawurlencode(implode(',', $askFor));
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                // Same reason as the arms below: without it a 422 arrives as
                // false and is indistinguishable from the network being down
                // (#101).
                'ignore_errors' => true,
            ],
        ]);
        $http = wallos_provider_http_get($apiUrl, $context);
        $response = $http['body'];
    } elseif ($provider === 1) {
        $apiUrl = "https://api.apilayer.com/fixer/latest?base=EUR&symbols=" . $codes;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'apikey: ' . $apiKey,
                // Without this a 401 arrives as false, indistinguishable from
                // the network being down. With it, the provider's own
                // explanation arrives instead (issue #101).
                'ignore_errors' => true,
            ]
        ]);
        $http = wallos_provider_http_get($apiUrl, $context);
        $response = $http['body'];

        // apilayer reports the quota in its response headers; keep it so the
        // usage bar does not cost an extra API request. Both the monthly and
        // the daily pair arrive on the same response, so capturing the daily
        // one alongside the monthly one is free — and worth it, because the
        // two are different situations (#106): a daily limit reached resolves
        // by tomorrow's cron, an exhausted month does not.
        if (is_array($http['headers'])) {
            $limit = null;
            $remaining = null;
            $limitDay = null;
            $remainingDay = null;
            foreach ($http['headers'] as $header) {
                if (stripos($header, 'x-ratelimit-limit-month:') === 0) {
                    $limit = (int) trim(substr($header, strlen('x-ratelimit-limit-month:')));
                } elseif (stripos($header, 'x-ratelimit-remaining-month:') === 0) {
                    $remaining = (int) trim(substr($header, strlen('x-ratelimit-remaining-month:')));
                } elseif (stripos($header, 'x-ratelimit-limit-day:') === 0) {
                    $limitDay = (int) trim(substr($header, strlen('x-ratelimit-limit-day:')));
                } elseif (stripos($header, 'x-ratelimit-remaining-day:') === 0) {
                    $remainingDay = (int) trim(substr($header, strlen('x-ratelimit-remaining-day:')));
                }
            }
            if ($limit !== null && $remaining !== null) {
                $usage['limit'] = $limit;
                $usage['used'] = $limit - $remaining;
            }
            if ($limitDay !== null && $remainingDay !== null) {
                $usage['limit_day'] = $limitDay;
                $usage['used_day'] = $limitDay - $remainingDay;
            }
        }
    } else {
        $apiUrl = "http://data.fixer.io/api/latest?access_key=" . $apiKey . "&base=EUR&symbols=" . $codes;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
            ]
        ]);
        $http = wallos_provider_http_get($apiUrl, $context);
        $response = $http['body'];
    }

    $status = wallos_http_status_code($http['headers']);

    if ($response === false) {
        $failure['usage'] = $usage;
        $failure['status'] = $status;
        $failure['message'] = wallos_provider_failure_message($status, null);

        // Cached like a success: a provider that was unreachable a moment ago
        // will not answer the next account in the same run either, and
        // retrying per account multiplies timeouts, not information (#117).
        $cache[] = ['credential' => $credential, 'codes' => $requested, 'result' => $failure];

        $failure['transport'] = true;

        return $failure;
    }

    $apiData = json_decode($response, true);

    // Each provider says "here are your rates" in its own shape, and the two
    // shapes have no key in common: fixer answers {"rates":{"USD":1.16}},
    // Frankfurter a flat array of records. Null from either means the answer
    // is not usable, whatever the status line said.
    if ($provider === 2) {
        $parsed = wallos_frankfurter_rates($apiData);
    } else {
        $parsed = (is_array($apiData) && isset($apiData['rates'])) ? $apiData['rates'] : null;
    }

    if ($parsed === null) {
        $failure['usage'] = $usage;
        $failure['status'] = $status;
        $failure['message'] = wallos_provider_failure_message($status, $apiData);

        if ($provider === 2) {
            // A 200 with an empty body is Frankfurter's answer to a base it
            // does not know (measured with base=BTC). The shared message
            // builder can only call that "returned an error", which sends the
            // reader looking for an outage instead of at the one currency that
            // caused it.
            $detail = wallos_frankfurter_detail($apiData);

            if ($status !== null && $status < 400 && $detail === '') {
                $failure['message'] = 'The currency provider does not price ' . $base
                    . ' as a base currency.';
            } elseif ($detail !== '') {
                $failure['message'] .= ' It said: ' . $detail;
            }
        }

        // A key the provider just rejected is rejected for every account
        // sharing it in this run. Before this, a run over N accounts with an
        // exhausted quota spent N calls to learn the same 429 N times —
        // observed on the test instance on 2026-08-28 (#117). The cache key
        // includes the code list, so accounts with different currency lists
        // still ask once each; a key-level negative cache is deliberately
        // not attempted.
        $cache[] = ['credential' => $credential, 'codes' => $requested, 'result' => $failure];

        $failure['transport'] = true;

        return $failure;
    }

    // What was asked for and did not come back. Frankfurter drops a code it
    // does not price in silence — there is no cryptocurrency in either scope
    // of its catalogue, so BTC and ETH leave exactly this trace and no other
    // (measured 2026-09-04). Named rather than counted, because "one currency
    // was not priced" does not tell anyone which subscription is now wrong.
    $unpriced = array_values(array_unique(array_merge(
        $malformed,
        array_diff(array_map('strtoupper', $requested), array_keys($parsed))
    )));

    $fresh = [
        'success' => true,
        'rates' => $parsed,
        'base' => $base,
        'unpriced' => $unpriced,
        'usage' => $usage,
        'message' => '',
        'transport' => false,
        'status' => $status,
    ];

    $cache[] = ['credential' => $credential, 'codes' => $requested, 'result' => $fresh];

    $fresh['transport'] = true;

    return $fresh;
}

/**
 * The currency codes the provider will actually price, and what it calls them.
 *
 * Both providers publish this as their own endpoint, so asking costs one
 * request and no rates. It answers a question the rate endpoint cannot: the
 * rate call is given a symbol list and returns rates, so a code it does not
 * know either comes back missing or takes the whole request down with it, and
 * neither outcome says which code was the problem (#133, #135).
 *
 * What it is not is currency master data. fixer lists withdrawn codes beside
 * current ones — BYR next to BYN, HRK, LTL, VEF — because it prices history
 * back to 1999. So "the provider knows this code" and "this is a currency
 * somebody should be offered today" are two different questions, and only the
 * first one is answered here.
 *
 * @param array $config Result of wallos_get_effective_currency_config().
 * @return array{success: bool, symbols: array<string, string>, usage: array{limit: int|null, used: int|null}, message: string, transport: bool}
 */
function wallos_fetch_currency_symbols($config)
{
    $failure = [
        'success' => false,
        'symbols' => [],
        'usage' => ['limit' => null, 'used' => null],
        'message' => '',
        'transport' => false,
    ];

    if (empty($config['valid'])) {
        $failure['message'] = $config['notes'][0] ?? 'Currency provider is not configured.';

        return $failure;
    }

    $apiKey = (string) $config['values']['api_key'];
    $provider = (int) $config['values']['provider'];

    if ($provider === 2) {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'ignore_errors' => true],
        ]);
        $http = wallos_provider_http_get('https://api.frankfurter.dev/v2/currencies', $context);
    } elseif ($provider === 1) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'apikey: ' . $apiKey,
                // Without this a 401 arrives as false, indistinguishable from
                // the network being down (#101).
                'ignore_errors' => true,
            ],
        ]);
        $http = wallos_provider_http_get('https://api.apilayer.com/fixer/symbols', $context);
    } else {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'ignore_errors' => true],
        ]);
        $http = wallos_provider_http_get(
            'http://data.fixer.io/api/symbols?access_key=' . $apiKey, $context);
    }

    $failure['transport'] = true;
    $status = wallos_http_status_code($http['headers']);

    if ($http['body'] === false) {
        $failure['message'] = wallos_provider_failure_message($status, null);

        return $failure;
    }

    $answer = json_decode($http['body'], true);
    $symbols = [];

    if ($provider === 2) {
        // /v2/currencies answers with records rather than fixer's
        // {"symbols":{...}} object: iso_code, iso_numeric, name, symbol,
        // start_date, end_date. Only the first two fields are read here —
        // this function answers "will the provider price this code", and the
        // dates answer a different question badly. On an active currency
        // end_date is the last date a rate was published (it sat at 2026-09-02
        // to 09-04 across the catalogue, varying by source), so it is a
        // freshness marker wearing a lifecycle date's clothes; only on the 36
        // withdrawn entries under ?scope=all is it a real withdrawal date.
        //
        // The default scope is asked for deliberately: 165 active currencies,
        // which is the set that can be priced today. ?scope=all adds 201-165
        // entries that cannot be.
        if (!is_array($answer) || $answer === []) {
            $failure['message'] = wallos_provider_failure_message($status, $answer);
            $detail = wallos_frankfurter_detail($answer);

            if ($detail !== '') {
                $failure['message'] .= ' It said: ' . $detail;
            }

            return $failure;
        }

        foreach ($answer as $record) {
            if (!is_array($record) || !isset($record['iso_code'])) {
                continue;
            }

            $symbols[strtoupper((string) $record['iso_code'])] =
                (string) ($record['name'] ?? $record['iso_code']);
        }

        if ($symbols === []) {
            $failure['message'] = wallos_provider_failure_message($status, $answer);

            return $failure;
        }

        ksort($symbols);

        return [
            'success' => true,
            'symbols' => $symbols,
            'usage' => ['limit' => null, 'used' => null],
            'message' => '',
            'transport' => true,
        ];
    }

    if (!is_array($answer) || !isset($answer['symbols']) || !is_array($answer['symbols'])) {
        $failure['message'] = wallos_provider_failure_message($status, $answer);

        return $failure;
    }

    foreach ($answer['symbols'] as $code => $name) {
        $symbols[strtoupper((string) $code)] = (string) $name;
    }

    ksort($symbols);

    return [
        'success' => true,
        'symbols' => $symbols,
        'usage' => ['limit' => null, 'used' => null],
        'message' => '',
        'transport' => true,
    ];
}

/**
 * One provider request for everyone the shared credential serves (#9).
 *
 * Called by the scheduled refresh before it walks its users: the accounts
 * that inherit the instance credential and are due today are grouped, the
 * union of their currency codes is fetched once, and the per-user updates
 * that follow are answered from the run cache — the covering-answer rule
 * above is what makes a user's smaller list a hit. Users with their own key
 * are their own group of one and gain nothing here (the issue's rule 8);
 * a user already refreshed today neither fetches nor widens the union, the
 * #117 rule carried forward — symbols nobody due needs are quota spent on
 * nothing.
 *
 * Since #140 the group is the credential AND the base. fixer prices in EUR
 * for everybody, so that stays one group and one request. Frankfurter prices
 * in the user's own main currency, so it is one request per distinct main
 * currency — more calls than before against a provider that meters none of
 * them, and in exchange every user's rates come back already in their own
 * currency. Grouping them together instead would spend one call and answer
 * most of them in somebody else's base.
 *
 * @param SQLite3 $db
 * @param int[]   $userIds Every account the caller is about to refresh.
 * @param bool    $force   Include accounts already refreshed today.
 */
function wallos_prewarm_shared_exchange_rates($db, $userIds, $force = false)
{
    $groups = [];

    foreach ($userIds as $userId) {
        if (!$force && wallos_exchange_rates_fresh($db, $userId)) {
            continue;
        }

        $candidate = wallos_get_effective_currency_config($db, $userId);

        if (empty($candidate['valid']) || ($candidate['mode'] ?? 'instance') !== 'instance') {
            continue;
        }

        // The base this user's own refresh will ask for, so that the answer
        // fetched here is the one their update finds in the run cache.
        $mainCurrencyCode = wallos_user_main_currency_code($db, $userId);

        // A user whose main currency cannot be read has no base to be grouped
        // under, and folding them into EUR would put them in a group whose
        // answer they can never use. Their own update reports the missing main
        // currency, which is the honest place for it. Only asked of providers
        // that price per base — under fixer everyone is in the EUR group
        // whatever their main currency is, exactly as before.
        if ($mainCurrencyCode === null && wallos_currency_provider_honours_base($candidate)) {
            continue;
        }

        $base = wallos_currency_request_base($candidate, $mainCurrencyCode);

        if (!isset($groups[$base])) {
            $groups[$base] = ['config' => $candidate, 'users' => []];
        }

        $groups[$base]['users'][] = (int) $userId;
    }

    foreach ($groups as $base => $group) {
        // One due user's own fetch already is the union; only two or more share.
        if (count($group['users']) < 2) {
            continue;
        }

        $placeholders = implode(', ', array_fill(0, count($group['users']), '?'));
        $stmt = $db->prepare('SELECT DISTINCT code FROM currencies WHERE user_id IN ('
            . $placeholders . ') ORDER BY code');

        if ($stmt === false) {
            continue;
        }

        foreach ($group['users'] as $index => $userId) {
            $stmt->bindValue($index + 1, $userId, SQLITE3_INTEGER);
        }

        $result = $stmt->execute();
        $codes = [];
        while ($result !== false && $row = $result->fetchArray(SQLITE3_ASSOC)) {
            $codes[] = $row['code'];
        }

        if ($codes === []) {
            continue;
        }

        $rates = wallos_fetch_exchange_rates($group['config'], implode(',', $codes), $base);

        // The union request is a real provider call like any other: counted
        // (#106), and its quota headers recorded, whatever the per-user updates
        // after it do.
        if (!empty($rates['transport'])) {
            wallos_count_currency_call($db, $group['config'], $group['users'][0]);
            wallos_store_currency_usage($db, $group['config'], $group['users'][0], $rates['usage']);
        }
    }
}

/**
 * One user's main currency code, or null when it cannot be read.
 *
 * Its own function because two places need the same answer now: the per-user
 * update, which converts against it, and the prewarm, which groups by it. A
 * second copy of the join is a second chance for the two to disagree about
 * which base a user belongs to — and disagreeing means the prewarm fetches a
 * base nobody then asks for, so every user pays for a call and makes their
 * own as well.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return string|null
 */
function wallos_user_main_currency_code($db, $userId)
{
    $stmt = $db->prepare('SELECT c.code FROM "user" u LEFT JOIN currencies c ON u.main_currency = c.id WHERE u.id = :userId');

    if ($stmt === false) {
        return null;
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return ($row && !empty($row['code'])) ? (string) $row['code'] : null;
}

/**
 * Counts one provider request made from this installation.
 *
 * The provider's own figure arrives only in apilayer's response headers;
 * fixer.io reports nothing, which is how a QA round spent six months of a
 * 100-call tier while the usage area stayed empty (#104). This records what
 * Wallos itself sends — per calendar month, with the key's holder: the
 * instance when the key is shared, the user when it is their own. An
 * estimate by design: it cannot see other software using the same key, and
 * it counts attempts whether or not the provider accepted them. Whether the
 * provider's billing period agrees with the calendar month is what the turn
 * of 2026-09-01 is calibrated against.
 *
 * @param SQLite3 $db
 * @param array   $config Result of wallos_get_effective_currency_config().
 * @param int     $userId
 */
function wallos_count_currency_call($db, $config, $userId)
{
    $month = date('Y-m');

    if (($config['mode'] ?? 'instance') === 'instance') {
        $instance = wallos_get_instance_settings($db, 'currency');
        $calls = ($instance['local_calls_month'] ?? '') === $month
            ? (int) ($instance['local_calls'] ?? 0)
            : 0;

        wallos_set_instance_setting($db, 'currency', 'local_calls', (string) ($calls + 1));
        wallos_set_instance_setting($db, 'currency', 'local_calls_month', $month);

        return;
    }

    if (!$db->columnExists('fixer', 'local_calls')) {
        return;
    }

    $stmt = $db->prepare('SELECT local_calls, local_calls_month FROM fixer WHERE user_id = :userId');

    if ($stmt === false) {
        return;
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if (!$row) {
        // No stored key row means nowhere to keep the figure; the request
        // itself already happened, so there is nothing useful to refuse.
        return;
    }

    $calls = ($row['local_calls_month'] ?? '') === $month ? (int) $row['local_calls'] : 0;

    $stmt = $db->prepare('UPDATE fixer SET local_calls = :calls, local_calls_month = :month WHERE user_id = :userId');

    if ($stmt === false) {
        error_log('Wallos: could not record the provider call for user ' . $userId . ': '
            . $db->lastErrorMsg());

        return;
    }

    $stmt->bindValue(':calls', $calls + 1, SQLITE3_INTEGER);
    $stmt->bindValue(':month', $month, SQLITE3_TEXT);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

    // The counter is what makes consumption visible on the settings page; a
    // figure that silently stopped moving would repeat the defect it fixes.
    if ($stmt->execute() === false) {
        error_log('Wallos: could not record the provider call for user ' . $userId . ': '
            . $db->lastErrorMsg());
    }
}

/**
 * The local request count for whoever holds the effective key, in the
 * current calendar month.
 *
 * A month that has turned since the last request answers zero without
 * writing anything; null means the installation cannot count yet — a
 * database from before migration 000069.
 *
 * @param SQLite3 $db
 * @param array   $config Result of wallos_get_effective_currency_config().
 * @param int     $userId
 * @return int|null
 */
function wallos_currency_local_calls($db, $config, $userId)
{
    $month = date('Y-m');

    if (($config['mode'] ?? 'instance') === 'instance') {
        $instance = wallos_get_instance_settings($db, 'currency');

        return ($instance['local_calls_month'] ?? '') === $month
            ? (int) ($instance['local_calls'] ?? 0)
            : 0;
    }

    if (!$db->columnExists('fixer', 'local_calls')) {
        return null;
    }

    $stmt = $db->prepare('SELECT local_calls, local_calls_month FROM fixer WHERE user_id = :userId');

    if ($stmt === false) {
        return null;
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if (!$row) {
        return 0;
    }

    return ($row['local_calls_month'] ?? '') === $month ? (int) $row['local_calls'] : 0;
}

/**
 * Whether one user's rates were already refreshed today.
 *
 * The one answer both refresh paths agree on: the manual endpoint skips a
 * fresh user unless forced, and since #117 the cron and the startup run skip
 * them too — a container start used to spend one provider call per account
 * whatever the rates' age, so deploy frequency alone could exhaust a free
 * tier. A missing or unreadable row answers false, because refusing to
 * refresh over an unestablished freshness would be the wrong default.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return bool
 */
function wallos_exchange_rates_fresh($db, $userId)
{
    $stmt = $db->prepare('SELECT date FROM last_exchange_update WHERE user_id = :userId');

    if ($stmt === false) {
        return false;
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return $row && !empty($row['date']) && $row['date'] >= (new DateTime())->format('Y-m-d');
}

/**
 * Updates the stored exchange rates of one user with the provider credentials
 * that are effective for them.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return array{success: bool, message: string}
 */
function wallos_update_exchange_rates_for_user($db, $userId)
{
    $config = wallos_get_effective_currency_config($db, $userId);

    if (!$config['valid']) {
        return [
            'success' => false,
            'message' => $config['notes'][0] ?? 'Currency provider is not configured.',
        ];
    }

    $codes = "";
    $stmt = $db->prepare('SELECT code FROM currencies WHERE user_id = :userId');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $codes .= $row['code'] . ",";
    }
    $codes = rtrim($codes, ',');

    if ($codes === "") {
        return ['success' => false, 'message' => 'No currencies configured.'];
    }

    // The same answer the prewarm grouped this user by; see
    // wallos_user_main_currency_code() for why it is not asked twice.
    $mainCurrencyCode = wallos_user_main_currency_code($db, $userId);

    if (empty($mainCurrencyCode)) {
        return ['success' => false, 'message' => 'Main currency is not set.'];
    }

    // The user's own currency, for a provider that will price in it. fixer
    // prices in EUR whatever is asked, and wallos_currency_request_base() is
    // what decides which of those two this is.
    $rates = wallos_fetch_exchange_rates($config, $codes,
        wallos_currency_request_base($config, $mainCurrencyCode));

    // Counted per request that went over the wire, not per account: the
    // per-run cache answers repeat asks without spending quota, and the
    // counter has to agree with what the provider saw (#106). The usage stamp
    // follows the same rule: a cached answer (transport === false) carries the
    // figures of the request it reuses, so storing them would re-stamp "last
    // checked" to now for a figure this process obtained earlier — the
    // stale-figure-looks-current failure #106 is named for. Only stamp when
    // this call actually received a response.
    if (!empty($rates['transport'])) {
        wallos_count_currency_call($db, $config, $userId);
        wallos_store_currency_usage($db, $config, $userId, $rates['usage']);
    }

    if (!$rates['success']) {
        return ['success' => false, 'message' => $rates['message']];
    }

    // What the answer is relative to, taken from the answer rather than from
    // what this call asked for — the two differ whenever the run cache serves
    // an earlier request, and reading rates against the wrong base is how a
    // GBP-per-EUR figure gets stored as GBP-per-USD.
    $answerBase = strtoupper((string) ($rates['base'] ?? 'EUR'));

    if ($answerBase === strtoupper((string) $mainCurrencyCode)) {
        // Already priced in the user's own currency: no conversion, and no
        // rounding step to go with it.
        $divisor = 1.0;
    } elseif (!isset($rates['rates'][$mainCurrencyCode]) || !$rates['rates'][$mainCurrencyCode]) {
        return ['success' => false, 'message' => 'The provider did not return a rate for the main currency.'];
    } else {
        $divisor = $rates['rates'][$mainCurrencyCode];
    }

    // One user's rates and their refresh date are one unit of work. Rates are
    // only comparable when they share a conversion base, so a refresh that
    // stops halfway would leave a set that looks plausible and is wrong.
    $db->exec('BEGIN');

    $updateStmt = $db->prepare('UPDATE currencies SET rate = :rate WHERE code = :code AND user_id = :userId');

    foreach ($rates['rates'] as $currencyCode => $rate) {
        // The main currency is 1.0 by definition, and that stays a local rule
        // rather than a number read out of the response. Frankfurter does
        // answer 1.0 for a base asked about itself, so the two agree today;
        // agreeing today is not the same as being safe to depend on.
        $exchangeRate = $currencyCode === $mainCurrencyCode ? 1.0 : $rate / $divisor;

        $updateStmt->bindValue(':rate', $exchangeRate, SQLITE3_TEXT);
        $updateStmt->bindValue(':code', $currencyCode, SQLITE3_TEXT);
        $updateStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

        if (!$updateStmt->execute()) {
            $db->exec('ROLLBACK');

            return ['success' => false, 'message' => 'Rate update failed for ' . $currencyCode . '; the previous rates were kept.'];
        }

        $updateStmt->reset();
    }

    $formattedDate = (new DateTime())->format('Y-m-d');

    // Checked the same way the rate updates above are, and inside the same
    // transaction. Discarding these two results meant the rates could be
    // committed with no record that they had been updated — after which the
    // job either refetches them every run, spending quota on a provider that
    // charges per call, or the page reports rates as older than they are
    // (issue #87).
    $recorded = false;
    $deleteStmt = $db->prepare('DELETE FROM last_exchange_update WHERE user_id = :userId');

    if ($deleteStmt !== false) {
        $deleteStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $recorded = $deleteStmt->execute() !== false;
    }

    if ($recorded) {
        $recorded = false;
        $insertStmt = $db->prepare('INSERT INTO last_exchange_update (date, user_id) VALUES (:date, :userId)');

        if ($insertStmt !== false) {
            $insertStmt->bindValue(':date', $formattedDate, SQLITE3_TEXT);
            $insertStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $recorded = $insertStmt->execute() !== false;
        }
    }

    if (!$recorded) {
        // Rolled back rather than committed with the timestamp missing: the
        // previous rates and their date belong together, and the caller can
        // retry. Same reasoning as the rate loop above.
        $db->exec('ROLLBACK');

        return [
            'success' => false,
            'message' => 'The rates were fetched, but the update could not be recorded; '
                . 'the previous rates were kept.',
        ];
    }

    $db->exec('COMMIT');

    // Named on the way out, because the alternative is a rate that quietly
    // stops moving: a code the provider does not price is not an error and
    // does not fail the refresh, but it is the one outcome that looks
    // identical to a currency whose rate simply did not change. Intersected
    // with this user's own codes — a covering answer from the run cache
    // carries the whole group's misses, and naming somebody else's currency
    // at this user would be worse than saying nothing.
    $mine = array_map('strtoupper', array_filter(array_map('trim', explode(',', $codes))));
    $unpriced = array_values(array_intersect($mine, $rates['unpriced'] ?? []));

    if ($unpriced !== []) {
        sort($unpriced);

        return [
            'success' => true,
            'message' => 'Rates updated successfully! The provider does not price '
                . implode(', ', $unpriced) . '; the previous rate was kept.',
        ];
    }

    return ['success' => true, 'message' => 'Rates updated successfully!'];
}

/**
 * Records provider quota where it belongs: with the instance when the key is
 * shared, with the user when the key is their own. Shared usage must not be
 * presented as if it were private to whoever triggered the update.
 *
 * @param SQLite3 $db
 * @param array   $config Result of wallos_get_effective_currency_config().
 * @param int     $userId
 * @param array   $usage  ['limit' => int|null, 'used' => int|null, 'limit_day' => int|null, 'used_day' => int|null]
 */
function wallos_store_currency_usage($db, $config, $userId, $usage)
{
    if ($usage['limit'] === null || $usage['used'] === null) {
        return;
    }

    // The daily pair, when the provider sent it. Stored beside the monthly one
    // and under the same timestamp — it rode in on the same response — so the
    // settings page can tell a day's limit from a month's quota (#106). Absent
    // (a provider that reports no daily headers) it is simply not written,
    // rather than clobbering an earlier figure with null.
    $usedDay = $usage['used_day'] ?? null;
    $limitDay = $usage['limit_day'] ?? null;

    $updatedAt = date('Y-m-d H:i:s');

    if (($config['mode'] ?? 'instance') === 'instance') {
        wallos_set_instance_setting($db, 'currency', 'usage_used', (string) $usage['used']);
        wallos_set_instance_setting($db, 'currency', 'usage_limit', (string) $usage['limit']);
        wallos_set_instance_setting($db, 'currency', 'usage_updated_at', $updatedAt);

        if ($usedDay !== null && $limitDay !== null) {
            wallos_set_instance_setting($db, 'currency', 'usage_used_day', (string) $usedDay);
            wallos_set_instance_setting($db, 'currency', 'usage_limit_day', (string) $limitDay);
        }

        return;
    }

    if (!$db->columnExists('fixer', 'usage_used')) {
        return;
    }

    // Day columns arrived in migration 000075; a database from before it keeps
    // storing the month alone rather than failing on a column that is not there.
    $storeDay = $usedDay !== null && $limitDay !== null
        && $db->columnExists('fixer', 'usage_used_day')
        && $db->columnExists('fixer', 'usage_limit_day');

    $sql = $storeDay
        ? 'UPDATE fixer SET usage_used = :used, usage_limit = :limit, usage_used_day = :usedDay, usage_limit_day = :limitDay, usage_updated_at = :updatedAt WHERE user_id = :userId'
        : 'UPDATE fixer SET usage_used = :used, usage_limit = :limit, usage_updated_at = :updatedAt WHERE user_id = :userId';

    $stmt = $db->prepare($sql);

    if ($stmt === false) {
        error_log('Wallos: could not record provider quota for user ' . $userId . ': '
            . $db->lastErrorMsg());

        return;
    }

    $stmt->bindValue(':used', $usage['used'], SQLITE3_INTEGER);
    $stmt->bindValue(':limit', $usage['limit'], SQLITE3_INTEGER);
    $stmt->bindValue(':updatedAt', $updatedAt, SQLITE3_TEXT);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

    if ($storeDay) {
        // Bare integer casts rather than the typed bind constants: new code
        // keeps the boundary the audit is shrinking as narrow as it can
        // (#20, #41).
        $stmt->bindValue(':usedDay', (int) $usedDay);
        $stmt->bindValue(':limitDay', (int) $limitDay);
    }

    // Quota is what the settings page shows to explain why refreshes stopped
    // working. A number that silently stayed where it was is worse than none.
    if ($stmt->execute() === false) {
        error_log('Wallos: could not record provider quota for user ' . $userId . ': '
            . $db->lastErrorMsg());
    }
}
