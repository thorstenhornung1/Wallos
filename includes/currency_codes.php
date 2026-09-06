<?php
/*
  Whether a currency code may be accepted and stored (#133).

  A currency in Wallos is three free-text fields, so an invented code like
  "LUN" used to be stored without a word — where it kept its seeded rate of 1
  and converted every price at 1:1 while the refresh reported success, a wrong
  total no screen could tell from a right one. This is the check the add and
  edit paths were missing.

  The valid set follows the responsibility split of issue #163: Unicode CLDR
  governs what a code is called, the rate provider governs which codes it will
  price. A code is acceptable when either authority knows it:

    - the offline CLDR/ISO 4217 set shipped under data/currencies/, which needs
      no network and is the answer for an installation with no provider at all;
    - the configured provider's own catalogue, consulted only for a code CLDR
      does not carry — so a provider-supported asset outside ISO, crypto such
      as BTC, is still allowed, exactly as the spec requires, while the common
      ISO add costs no provider request because CLDR answers it first.

  A code neither authority knows is refused, rather than stored and silently
  converted at 1:1. Every function is total: an unreadable dataset or an
  unreachable provider yields "not known", which fails closed (refuse) rather
  than open (silently accept).
*/

require_once __DIR__ . '/currency_localization.php';
require_once __DIR__ . '/currency_provider.php';

if (!function_exists('wallos_currency_code_normalize')) {

    /**
     * Upper-cased and trimmed, the one form every check below compares in.
     *
     * @param mixed $code
     * @return string
     */
    function wallos_currency_code_normalize($code)
    {
        return strtoupper(trim((string) $code));
    }

    /**
     * The structural gate: an ISO code is three letters, provider assets add a
     * few alphanumeric ones (BTC, USDT), and everything else — "€€€", a name
     * typed into the code box, an empty field — is not a code at all and is
     * refused before either catalogue is asked.
     *
     * @param mixed $code
     * @return bool
     */
    function wallos_currency_code_well_formed($code)
    {
        return (bool) preg_match('/^[A-Z0-9]{2,12}$/', wallos_currency_code_normalize($code));
    }

    /**
     * Whether CLDR — the offline canonical ISO 4217 set — carries this code.
     *
     * The English dataset is asked because every code has an English name there;
     * the localized files translate a subset. Needs no network and no provider,
     * which is what lets an installation with no credentials validate at all.
     *
     * @param mixed $code
     * @return bool
     */
    function wallos_currency_code_is_iso($code)
    {
        $code = wallos_currency_code_normalize($code);

        if ($code === '') {
            return false;
        }

        return wallos_currency_entry($code, 'en') !== null;
    }

    /**
     * The codes the effective provider will price, upper-cased, cached for the
     * life of the request.
     *
     * Its own catalogue endpoint answers this without spending a rate request's
     * worth of information on it, and the static cache means several codes
     * checked in one request — a form saving a list — ask the provider once, not
     * once each. A provider that is not configured, or one that cannot be
     * reached, yields an empty set: the code then stands or falls on CLDR alone,
     * which is the honest answer when nothing can confirm provider support.
     *
     * @param WallosDatabase $db
     * @param array   $config Result of wallos_get_effective_currency_config().
     * @return array<string, true>
     */
    function wallos_currency_provider_supported_codes($db, $config)
    {
        static $cache = [];

        $key = (int) ($config['values']['provider'] ?? -1)
            . '|' . (string) ($config['values']['api_key'] ?? '');

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $codes = [];

        if (!empty($config['valid'])) {
            $symbols = wallos_fetch_currency_symbols($config);

            if (!empty($symbols['success']) && !empty($symbols['symbols'])) {
                foreach (array_keys($symbols['symbols']) as $symbol) {
                    $codes[wallos_currency_code_normalize($symbol)] = true;
                }
            }
        }

        $cache[$key] = $codes;

        return $codes;
    }

    /**
     * Whether the effective provider prices this code.
     *
     * Consulted only for a code CLDR does not carry, so BTC reaches here and JPY
     * never does.
     *
     * @param WallosDatabase $db
     * @param array   $config
     * @param mixed   $code
     * @return bool
     */
    function wallos_currency_code_provider_supported($db, $config, $code)
    {
        $code = wallos_currency_code_normalize($code);

        if ($code === '') {
            return false;
        }

        return isset(wallos_currency_provider_supported_codes($db, $config)[$code]);
    }

    /**
     * The one question the endpoints ask: may this code be stored?
     *
     * Well-formed, then known to either authority. The CLDR check comes first
     * because it is free and answers almost every real currency; the provider is
     * asked only for the non-ISO remainder, which is where a supported crypto
     * code earns its acceptance.
     *
     * @param WallosDatabase $db
     * @param array   $config Result of wallos_get_effective_currency_config().
     * @param mixed   $code
     * @return bool
     */
    function wallos_currency_code_acceptable($db, $config, $code)
    {
        if (!wallos_currency_code_well_formed($code)) {
            return false;
        }

        if (wallos_currency_code_is_iso($code)) {
            return true;
        }

        return wallos_currency_code_provider_supported($db, $config, $code);
    }
}
