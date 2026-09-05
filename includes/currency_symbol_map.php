<?php
/*
  One code => symbol map per request, instead of a scan of every currency for
  every price the subscription list prints.

  formatPrice() runs at least once per rendered subscription — the price, and
  for a converted row the original as well — and each call used to walk the
  whole $currencies array looking for the row whose code it was formatting, only
  to read that row's display symbol. On the largest seeded dataset that is ten
  thousand rows times the length of the currency list, all of it spent answering
  a question — "what symbol does this code print as" — that has one answer for
  the whole request.

  This is the same move #18 made for the date formatter, applied to the other
  per-row scan the list still carried: the work moved, the answer did not. The
  map is keyed by code and lives for the request, so one lookup answers every
  row.

  The stored value is the currency row's symbol exactly as the scan read it, the
  empty string included. formatPrice() falls back to the code itself when the
  symbol is empty, so the emptiness has to survive into the map rather than being
  resolved away here. Where two rows carry the same code the first wins, because
  the scan stopped at its first match and never saw the rest.
*/

if (!function_exists('wallos_currency_symbol_map')) {

    /**
     * The request-cached code => symbol map for a currency list.
     *
     * Built on the first call and reused after. Wallos resolves one currency
     * list per request — getdbkeys.php builds it for the one authenticated user
     * and threads that array into printSubscriptions() — so a single cached map
     * is the whole request's answer, the way the date formatter caches per
     * (language, pattern).
     *
     * @param array $currencies rows keyed by id, each carrying 'code' and 'symbol'
     * @return array<string, string> code => stored symbol, which may be ''
     */
    function wallos_currency_symbol_map($currencies)
    {
        if (isset($GLOBALS['wallos_currency_symbol_map'])) {
            return $GLOBALS['wallos_currency_symbol_map'];
        }

        $map = [];

        foreach ($currencies as $currency) {
            if (!isset($currency['code'])) {
                continue;
            }

            $code = $currency['code'];

            // First writer wins: the scan broke on the first row whose code
            // matched, so a later row sharing that code never got to speak.
            if (!array_key_exists($code, $map)) {
                $map[$code] = isset($currency['symbol']) ? $currency['symbol'] : '';
            }
        }

        $GLOBALS['wallos_currency_symbol_map'] = $map;
        $GLOBALS['wallos_currency_symbol_map_builds'] =
            ($GLOBALS['wallos_currency_symbol_map_builds'] ?? 0) + 1;

        return $map;
    }

    /**
     * How many times the map has been built this request.
     *
     * One, once the list has rendered — which is the whole point of the cache,
     * and the only claim a hardware-independent test can pin about it. The map's
     * contents are asserted separately; this counts constructions. Production
     * never calls it.
     *
     * @return int
     */
    function wallos_currency_symbol_map_builds()
    {
        return $GLOBALS['wallos_currency_symbol_map_builds'] ?? 0;
    }

    /**
     * Forgets the cached map and its build count.
     *
     * The request path never calls this: a request builds the map once and is
     * done with it. It exists so a test can measure a build from a known start,
     * and so one case's currency list cannot answer the next case's lookups.
     *
     * @return void
     */
    function wallos_currency_symbol_map_reset()
    {
        unset($GLOBALS['wallos_currency_symbol_map'], $GLOBALS['wallos_currency_symbol_map_builds']);
    }
}
