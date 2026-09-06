<?php
/*
  The one place Wallos turns a currency code into a localized name and symbol.

  Responsibilities are split three ways (issue #163): the ISO 4217 code is the
  canonical identity, the rate provider decides which currencies exist and what
  they are worth, and Unicode CLDR decides how a code is written in a given
  language. This file is the CLDR half. It reads only the committed dataset
  under data/currencies/ — generated from a pinned CLDR release by
  scripts/update_cldr_currencies.php — and never touches the network, ICU, or
  Symfony. The application works fully offline.

  It is display data, not identity: callers key on the code and ask this for the
  label to show. At account creation the resolved label is copied into the
  user's own currency row; from then on the row is user-owned and nothing here
  ever rewrites it (that contract lives in the provisioning and login paths).

  Every function is total: an unknown code, a missing locale file, a currency a
  locale does not translate — none of them error. The worst case is the ISO code
  itself, never an internal key and never an exception.
*/

require_once __DIR__ . '/i18n/languages.php';

if (!function_exists('wallos_currency_locale_data')) {

    /**
     * The { code => {name, symbol} } table for one language, loaded at most once
     * per request.
     *
     * The language is resolved to a supported Wallos tag first, so de-DE, de_AT
     * and de all read data/currencies/de.json — the same normalization the rest
     * of the app uses, not a second copy of it. A missing or malformed file
     * yields an empty table rather than an error: the caller's fallback chain
     * then carries the request the rest of the way.
     *
     * @param string $language any language value; normalized internally
     * @return array<string, array{name: string, symbol: ?string}>
     */
    function wallos_currency_locale_data($language)
    {
        static $cache = [];

        $resolved = wallos_resolve_language($language);

        if (!array_key_exists($resolved, $cache)) {
            $path = __DIR__ . '/../data/currencies/' . $resolved . '.json';
            $data = [];

            if (is_file($path)) {
                $raw = @file_get_contents($path);
                if ($raw !== false) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                }
            }

            $cache[$resolved] = $data;
        }

        return $cache[$resolved];
    }

    /**
     * One currency entry for a language, or null when the language has none.
     *
     * @param string $code   already upper-cased
     * @param string $language
     * @return array{name?: string, symbol?: ?string}|null
     */
    function wallos_currency_entry($code, $language)
    {
        $data = wallos_currency_locale_data($language);

        return isset($data[$code]) && is_array($data[$code]) ? $data[$code] : null;
    }

    /**
     * The localized display name of a currency code.
     *
     * Fallback order (issue #163 §11): the requested locale's CLDR name, then
     * the English CLDR name, then a provider/existing name the caller passes in,
     * and finally the ISO code itself. It never returns an empty string, an
     * internal translation key, or throws — an unknown code like 'XYZ' comes
     * back as 'XYZ'.
     *
     * @param string      $currencyCode ISO 4217 code, any case
     * @param string      $language     account/UI language, any form
     * @param string|null $existingName provider or stored name to prefer over the code
     * @return string
     */
    function wallos_currency_name($currencyCode, $language, $existingName = null)
    {
        $code = strtoupper(trim((string) $currencyCode));

        $entry = wallos_currency_entry($code, $language);
        if ($entry !== null && isset($entry['name']) && trim((string) $entry['name']) !== '') {
            return (string) $entry['name'];
        }

        $english = wallos_currency_entry($code, 'en');
        if ($english !== null && isset($english['name']) && trim((string) $english['name']) !== '') {
            return (string) $english['name'];
        }

        if ($existingName !== null && trim((string) $existingName) !== '') {
            return (string) $existingName;
        }

        return $code;
    }

    /**
     * The localized symbol of a currency code.
     *
     * Fallback order (issue #163 §12): the requested locale's CLDR symbol, then
     * a provider/existing symbol the caller passes in, then the English CLDR
     * symbol, and finally the ISO code. CLDR often has no distinctive symbol for
     * a currency in a locale (the generator stored null there rather than
     * echoing the code back), which is exactly when the provider/existing symbol
     * — the symbol Wallos historically shipped for its default currencies —
     * takes over, keeping лв, kr and Fr instead of degrading to the code.
     *
     * Typed ?string to match the specified signature; in practice it always
     * returns at least the code, never null.
     *
     * @param string      $currencyCode   ISO 4217 code, any case
     * @param string      $language       account/UI language, any form
     * @param string|null $existingSymbol provider or stored symbol to prefer over the code
     * @return string|null
     */
    function wallos_currency_symbol($currencyCode, $language, $existingSymbol = null)
    {
        $code = strtoupper(trim((string) $currencyCode));

        $entry = wallos_currency_entry($code, $language);
        if ($entry !== null && isset($entry['symbol']) && trim((string) $entry['symbol']) !== '') {
            return (string) $entry['symbol'];
        }

        if ($existingSymbol !== null && trim((string) $existingSymbol) !== '') {
            return (string) $existingSymbol;
        }

        $english = wallos_currency_entry($code, 'en');
        if ($english !== null && isset($english['symbol']) && trim((string) $english['symbol']) !== '') {
            return (string) $english['symbol'];
        }

        return $code;
    }

    /**
     * Code, name and symbol together, for a caller that wants all three.
     *
     * @param string      $currencyCode
     * @param string      $language
     * @param string|null $existingName
     * @param string|null $existingSymbol
     * @return array{code: string, name: string, symbol: ?string}
     */
    function wallos_currency_metadata($currencyCode, $language, $existingName = null, $existingSymbol = null)
    {
        $code = strtoupper(trim((string) $currencyCode));

        return [
            'code'   => $code,
            'name'   => wallos_currency_name($code, $language, $existingName),
            'symbol' => wallos_currency_symbol($code, $language, $existingSymbol),
        ];
    }
}
