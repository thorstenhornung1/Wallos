<?php
/*
  CLDR-based currency localization (issue #163).

  Two halves: the committed dataset under data/currencies/ is well-formed and
  complete (the §23 CI checks), and the runtime functions localize a code the
  way the spec's §24 cases require — including the invariants that make the
  stored value user-owned once an account exists.

  The exact spellings are Unicode CLDR's. Where a case pins one ("US-Dollar",
  "Japanischer Yen") it is the CLDR value for that release, not a Wallos
  invention; where a case only needs "some real localized name" it reads the
  dataset rather than hardcoding.
*/

require_once WALLOS_ROOT . '/includes/currency_localization.php';
require_once WALLOS_ROOT . '/includes/user_provisioning.php';

/**
 * The committed dataset, decoded, keyed by Wallos language tag.
 *
 * @return array<string, array>
 */
function wallos_test_currency_datasets()
{
    static $datasets = null;

    if ($datasets === null) {
        $datasets = [];
        foreach (array_keys(wallos_languages()) as $language) {
            $path = WALLOS_ROOT . '/data/currencies/' . $language . '.json';
            $datasets[$language] = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        }
    }

    return $datasets;
}

// -- §23 CI validation -------------------------------------------------------

wallos_test('every supported language has a currency dataset', function () {
    // B) a dataset exists for every supported Wallos language, and it is a
    // language file rather than a second currency-only language list.
    foreach (wallos_test_currency_datasets() as $language => $data) {
        assert_true(is_array($data) && $data !== [],
            $language . '.json exists, is valid JSON and is not empty');
    }
});

wallos_test('the base currency codes are present in every language', function () {
    // C) EUR/USD/GBP/JPY/CHF, plus CZK which the spec calls out by name.
    $base = ['EUR', 'USD', 'GBP', 'JPY', 'CHF', 'CZK'];

    foreach (wallos_test_currency_datasets() as $language => $data) {
        if (!is_array($data)) {
            assert_true(false, $language . '.json did not load');
            continue;
        }
        foreach ($base as $code) {
            assert_true(isset($data[$code]), $language . '.json has ' . $code);
        }
    }
});

wallos_test('every currency entry has a non-empty name', function () {
    // D) every entry has at least a name and E) no empty names. The symbol may
    // be null by design (CLDR has none for that code in that locale); the name
    // never may.
    foreach (wallos_test_currency_datasets() as $language => $data) {
        if (!is_array($data)) {
            assert_true(false, $language . '.json did not load');
            continue;
        }
        foreach ($data as $code => $entry) {
            assert_true(is_array($entry) && isset($entry['name']) && trim((string) $entry['name']) !== '',
                $language . '/' . $code . ' has a non-empty name');
            assert_true(array_key_exists('symbol', $entry),
                $language . '/' . $code . ' carries a symbol field (value may be null)');
        }
    }
});

wallos_test('the metadata records the pinned CLDR release', function () {
    $path = WALLOS_ROOT . '/data/currencies/metadata.json';
    assert_true(is_file($path), 'metadata.json exists');

    $meta = json_decode(file_get_contents($path), true);
    assert_true(is_array($meta), 'metadata.json is valid JSON');
    assert_true(!empty($meta['cldr_version']), 'metadata names a pinned cldr_version');
    assert_same('Unicode-3.0', $meta['license'] ?? null, 'metadata records the Unicode-3.0 license');
    assert_same('Unicode CLDR', $meta['source'] ?? null, 'metadata records Unicode CLDR as the source');
});

// -- §24.1 / §24.2 localized names -------------------------------------------

wallos_test('a German account localizes currency names via CLDR', function () {
    // §24.1 — the spelling is CLDR's, not the test's.
    assert_same('US-Dollar', wallos_currency_name('USD', 'de'), 'USD is US-Dollar in German');
    assert_same('Japanischer Yen', wallos_currency_name('JPY', 'de'), 'JPY is Japanischer Yen in German');
    assert_same('Schweizer Franken', wallos_currency_name('CHF', 'de'), 'CHF is Schweizer Franken in German');
});

wallos_test('an English account localizes currency names via CLDR', function () {
    // §24.2
    assert_same('US Dollar', wallos_currency_name('USD', 'en'), 'USD is US Dollar in English');
    assert_same('Japanese Yen', wallos_currency_name('JPY', 'en'), 'JPY is Japanese Yen in English');
    assert_same('Swiss Franc', wallos_currency_name('CHF', 'en'), 'CHF is Swiss Franc in English');
});

// -- §24.3 the code is language-independent -----------------------------------

wallos_test('the currency code is identical across languages', function () {
    // §24.3 — USD.code === 'USD' regardless of language, and lower-case input
    // normalizes to it.
    foreach (['en', 'de', 'ja', 'fr', 'pt-BR', 'zh-CN'] as $language) {
        assert_same('USD', wallos_currency_metadata('USD', $language)['code'],
            'the code stays USD in ' . $language);
    }
    assert_same('USD', wallos_currency_metadata('usd', 'de')['code'], 'lower-case input normalizes to USD');
});

// -- §24.6 / §24.7 name fallbacks ---------------------------------------------

wallos_test('a currency a locale does not translate falls back to English then the code', function () {
    // §24.6 — data-driven so it survives a CLDR bump: a code English has but
    // German does not must come back with the English name, and an unknown code
    // with the code itself. Neither errors.
    $en = wallos_test_currency_datasets()['en'];
    $de = wallos_test_currency_datasets()['de'];

    $onlyInEnglish = null;
    foreach ($en as $code => $entry) {
        if (!isset($de[$code])) {
            $onlyInEnglish = $code;
            break;
        }
    }

    assert_true($onlyInEnglish !== null, 'the dataset has a code English carries and German does not');
    assert_same($en[$onlyInEnglish]['name'], wallos_currency_name($onlyInEnglish, 'de'),
        $onlyInEnglish . ' falls back to the English CLDR name for a German account');

    assert_same('XYZ', wallos_currency_name('XYZ', 'de'), 'an unknown code falls back to itself');
});

wallos_test('an unknown code never errors and never shows an internal key', function () {
    // §24.7 — wallos_currency_name('XYZ','de') is at least 'XYZ', never an
    // exception, never a translation key.
    $result = wallos_currency_name('XYZ', 'de');
    assert_same('XYZ', $result, 'the code is returned unchanged');
    assert_true(strpos($result, 'currency_name_') === false, 'never an internal key');

    // A blank language must not error either.
    assert_same('EUR', wallos_currency_metadata('EUR', '')['code'], 'a blank language still resolves');
});

// -- §24.8 symbol fallbacks ---------------------------------------------------

wallos_test('symbols fall back CLDR then provider then code', function () {
    // §24.8. CLDR present: JPY has a German symbol.
    assert_same('¥', wallos_currency_symbol('JPY', 'de'), 'JPY uses the CLDR symbol');

    // CLDR absent for CHF in German (the generator stored null): the provider /
    // existing symbol Wallos ships takes over.
    assert_same('Fr', wallos_currency_symbol('CHF', 'de', 'Fr'), 'CHF uses the provider symbol when CLDR has none');

    // No provider symbol either: the code is the last resort, never null.
    assert_same('CHF', wallos_currency_symbol('CHF', 'de'), 'CHF falls to its code with no provider symbol');

    // A code CLDR has never heard of: provider symbol if given, else the code.
    assert_same('Ƶ', wallos_currency_symbol('QQQ', 'de', 'Ƶ'), 'an unknown code takes the provider symbol');
    assert_same('QQQ', wallos_currency_symbol('QQQ', 'de'), 'an unknown code with no symbol falls to itself');
});

// -- §24.9 one function behind all three provisioning paths -------------------

wallos_test('all provisioning paths share one currency source, varying only by language', function () {
    // §24.9. The three account-creating paths all seed through the same helper,
    // so the defaults can differ only by the language passed in.
    foreach (['registration.php', 'endpoints/admin/adduser.php', 'includes/oidc/oidc_create_user.php'] as $path) {
        assert_true(wallos_test_file_calls($path, 'wallos_create_default_currencies'),
            $path . ' seeds currencies through the shared helper');
    }

    $de = wallos_default_currencies('de');
    $en = wallos_default_currencies('en');

    assert_same(array_column($de, 'code'), array_column($en, 'code'),
        'the codes and their order are identical in every language');

    $deUsd = $de[array_search('USD', array_column($de, 'code'), true)];
    $enUsd = $en[array_search('USD', array_column($en, 'code'), true)];
    assert_same('US-Dollar', $deUsd['name'], 'German localizes the name');
    assert_same('US Dollar', $enUsd['name'], 'English localizes the name');
    assert_true($deUsd['name'] !== $enUsd['name'], 'only the language changes the localized result');
});

// -- §24.4 a language switch does not touch stored rows -----------------------

wallos_test('a later language switch leaves stored currency names unchanged', function () {
    // §24.4 — an account created in German keeps "Japanischer Yen" after its UI
    // language is switched to English, even though a fresh English account would
    // get "Japanese Yen". The row is user-owned after creation.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5101, 'switcher');

    assert_true(wallos_create_default_currencies($db, 5101, 'de'), 'German defaults seeded');

    $stored = $db->scalar('SELECT name FROM currencies WHERE user_id = 5101 AND code = :c', [':c' => 'JPY']);
    assert_same('Japanischer Yen', $stored, 'the account stored the German name');

    // Switch the account's UI language, the way the profile page does. A literal
    // update, portable across both backends without a bound parameter.
    $db->exec("UPDATE \"user\" SET language = 'en' WHERE id = 5101");

    $after = $db->scalar('SELECT name FROM currencies WHERE user_id = 5101 AND code = :c', [':c' => 'JPY']);
    assert_same('Japanischer Yen', $after, 'the stored name did not change with the language');

    // And it genuinely differs from what an English account would be given, so
    // the check above is not vacuous.
    assert_true($after !== wallos_currency_name('JPY', 'en'),
        'the English default would have been different');

    $db->close();
});

// -- §24.5 a user rename is never overwritten ---------------------------------

wallos_test('a user-renamed currency survives a language switch and profile sync', function () {
    // §24.5 — the strongest form of the invariant: once the owner has renamed a
    // currency, nothing Wallos does on later logins rewrites it.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5102, 'renamer');

    assert_true(wallos_create_default_currencies($db, 5102, 'de'), 'German defaults seeded');

    // The owner renames JPY to something of their own.
    $db->exec("UPDATE currencies SET name = 'Dollar Urlaub' WHERE user_id = 5102 AND code = 'JPY'");

    // A language switch (what a profile save or an OIDC profile sync writes to
    // the user row) must not reach into the currency rows.
    $db->exec("UPDATE \"user\" SET language = 'en' WHERE id = 5102");

    $after = $db->scalar('SELECT name FROM currencies WHERE user_id = 5102 AND code = :c', [':c' => 'JPY']);
    assert_same('Dollar Urlaub', $after, 'the user-defined name is untouched');

    $db->close();
});

wallos_test('the OIDC profile sync never writes to the currencies table', function () {
    // The source-level guarantee behind §24.5: profile sync manages a defined
    // subset of user fields (firstname, lastname, email, language) and has no
    // business touching currency rows. If it ever gains a write to that table,
    // this fails and the invariant is revisited deliberately.
    $source = file_get_contents(WALLOS_ROOT . '/includes/oidc/oidc_profile_sync.php');
    assert_not_contains('currencies', $source, 'profile sync does not reference the currencies table');
});
