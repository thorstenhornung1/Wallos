<?php
/*
  formatPrice() in includes/list_subscriptions.php used to walk the whole
  $currencies array on every price it rendered, only to read the display symbol
  for the one code it was formatting — O(rows x currencies) of string work for a
  question that has one answer per request. It now reads that symbol from a
  code => symbol map built once per request, the same hoist #18 made for the
  date formatter.

  Two things have to hold for that to be a safe change, and this pins both:

    * the map is built once however many prices are formatted — the whole point
      of the cache, asserted as a build count so the claim is deterministic and
      hardware-independent rather than a timing;
    * the symbol it resolves for a code is exactly the one the per-row scan
      resolved, so the formatted string is byte-for-byte what it was before —
      asserted first on the symbol logic directly, including the empty-symbol
      and duplicate-code edges the scan's first-match-and-break semantics turned
      on, and then on the whole formatted price across a spread of codes and
      amounts.

  The formatted-string case needs the intl extension for NumberFormatter, which
  dev/test.sh's runner image ships without, so it stands aside there rather than
  asserting behaviour the backend cannot exercise — the same stance the date
  formatter case takes.
*/

require_once WALLOS_ROOT . '/includes/currency_symbol_map.php';
require_once WALLOS_ROOT . '/includes/currency_formatter.php';

/**
 * Skips the current case when the intl extension is unavailable.
 *
 * @param string $reason
 * @return bool whether the case should stop
 */
function format_price_skip_unless_intl($reason)
{
    if (class_exists('NumberFormatter')) {
        return false;
    }

    $GLOBALS['wallos_test_skipped'][] = [
        'test' => $GLOBALS['wallos_test_current'],
        'reason' => $reason,
    ];

    return true;
}

/**
 * A currency list shaped the way getdbkeys.php builds it: keyed by id, each row
 * carrying at least a code and a symbol. The edges are deliberate — an empty
 * symbol, and two rows sharing a code — because that is where the scan's
 * first-match-and-break behaviour is visible and the map has to reproduce it.
 *
 * @return array<int, array<string, string>>
 */
function format_price_currencies()
{
    return [
        1 => ['id' => 1, 'code' => 'EUR', 'symbol' => "\u{20AC}"],
        2 => ['id' => 2, 'code' => 'USD', 'symbol' => '$'],
        3 => ['id' => 3, 'code' => 'GBP', 'symbol' => "\u{00A3}"],
        // An empty stored symbol: the scan matched, found nothing to use, and
        // kept the code as the symbol. The map must carry the emptiness so the
        // same fallback happens at lookup time.
        4 => ['id' => 4, 'code' => 'XAU', 'symbol' => ''],
        // Two rows for FST: the scan stops at the first, so 'F1' wins and 'F2'
        // is never seen.
        5 => ['id' => 5, 'code' => 'FST', 'symbol' => 'F1'],
        6 => ['id' => 6, 'code' => 'FST', 'symbol' => 'F2'],
        // Two rows for DUP where the first is empty: the scan stops there too,
        // so the code stands and the non-empty second row does not rescue it.
        7 => ['id' => 7, 'code' => 'DUP', 'symbol' => ''],
        8 => ['id' => 8, 'code' => 'DUP', 'symbol' => 'D2'],
    ];
}

/**
 * The symbol the old per-row scan resolved for a code. Kept here verbatim as
 * the reference the map is measured against.
 *
 * @param string $currencyCode
 * @param array  $currencies
 * @return string
 */
function format_price_scan_symbol($currencyCode, $currencies)
{
    $symbol = $currencyCode;

    foreach ($currencies as $currency) {
        if ($currency['code'] === $currencyCode) {
            if ($currency['symbol'] != "") {
                $symbol = $currency['symbol'];
            }
            break;
        }
    }

    return $symbol;
}

/**
 * The symbol formatPrice() now resolves, through the shared map.
 *
 * @param string $currencyCode
 * @param array  $currencies
 * @return string
 */
function format_price_map_symbol($currencyCode, $currencies)
{
    $symbol = $currencyCode;

    $symbols = wallos_currency_symbol_map($currencies);
    if (isset($symbols[$currencyCode]) && $symbols[$currencyCode] != "") {
        $symbol = $symbols[$currencyCode];
    }

    return $symbol;
}

/**
 * The whole formatted price the old scan produced.
 *
 * @param float  $price
 * @param string $currencyCode
 * @param array  $currencies
 * @return string
 */
function format_price_scan_full($price, $currencyCode, $currencies)
{
    $formattedPrice = CurrencyFormatter::format($price, $currencyCode);
    if (strstr($formattedPrice, $currencyCode)) {
        $formattedPrice = str_replace($currencyCode, format_price_scan_symbol($currencyCode, $currencies), $formattedPrice);
    }

    return $formattedPrice;
}

/**
 * The whole formatted price formatPrice() produces now, through the map.
 *
 * @param float  $price
 * @param string $currencyCode
 * @param array  $currencies
 * @return string
 */
function format_price_map_full($price, $currencyCode, $currencies)
{
    $formattedPrice = CurrencyFormatter::format($price, $currencyCode);
    if (strstr($formattedPrice, $currencyCode)) {
        $formattedPrice = str_replace($currencyCode, format_price_map_symbol($currencyCode, $currencies), $formattedPrice);
    }

    return $formattedPrice;
}

wallos_test('one map answers a whole request, however many prices are formatted', function () {
    wallos_currency_symbol_map_reset();
    assert_same(0, wallos_currency_symbol_map_builds(), 'nothing is built before the first price');

    $currencies = format_price_currencies();

    // The list formats at least one price per row and often two; a hundred
    // lookups here stands in for that. Every one of them must be served from a
    // single construction, which is the entire optimisation.
    for ($i = 0; $i < 100; $i++) {
        wallos_currency_symbol_map($currencies);
        format_price_map_symbol('USD', $currencies);
    }

    assert_same(1, wallos_currency_symbol_map_builds(),
        'the map is built once and reused, not rebuilt per price');
});

wallos_test('the map resolves every code exactly as the per-row scan did', function () {
    wallos_currency_symbol_map_reset();
    $currencies = format_price_currencies();

    // Present-with-symbol, empty-symbol, first-of-a-duplicate, empty-first-of-a
    // -duplicate, and a code no row carries. These are the cases the scan's
    // first-match-and-break turned on, so a map that got any of them wrong here
    // would change a displayed symbol.
    foreach (['EUR', 'USD', 'GBP', 'XAU', 'FST', 'DUP', 'ZZZ'] as $code) {
        assert_same(
            format_price_scan_symbol($code, $currencies),
            format_price_map_symbol($code, $currencies),
            'the map and the scan resolve the same symbol for ' . $code
        );
    }
});

wallos_test('the formatted price is identical before and after, across codes and amounts', function () {
    if (format_price_skip_unless_intl('NumberFormatter needs the intl extension')) {
        return;
    }

    wallos_currency_symbol_map_reset();
    $currencies = format_price_currencies();

    // The claim in full: the formatted string is byte-for-byte what it was. The
    // amounts cross zero, a negative and fractional values so the number side is
    // exercised too, and the codes include ones ICU prints as the code itself —
    // which is the only case that reaches the symbol substitution at all.
    $amounts = [0, 1, 9.99, 1234.5, -42.42, 1000000];
    $codes = ['EUR', 'USD', 'GBP', 'XAU', 'FST', 'DUP', 'ZZZ'];

    foreach ($codes as $code) {
        foreach ($amounts as $amount) {
            assert_same(
                format_price_scan_full($amount, $code, $currencies),
                format_price_map_full($amount, $code, $currencies),
                'the formatted price is unchanged for ' . $code . '/' . $amount
            );
        }
    }
});

wallos_test('the subscription list reads the symbol through the shared map', function () {
    // The wiring: formatPrice() in list_subscriptions.php must actually call the
    // map. Asked of the tokeniser, not strpos, so a mention in a comment or a
    // reintroduced scan beside it would not satisfy it. If formatPrice reverts
    // to its own scan, this is where it is caught.
    assert_true(
        wallos_test_file_calls('includes/list_subscriptions.php', 'wallos_currency_symbol_map'),
        'formatPrice() resolves the symbol through wallos_currency_symbol_map()'
    );
});
