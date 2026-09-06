<?php
/*
  Default data a new account starts with.

  Three paths create accounts — interactive registration, the admin form and
  OIDC auto-provisioning — and each carried its own copy of the category list.
  Three copies drift: they already differed in shape, and a change to one was a
  change the other two silently did not get.

  The seeded values are templates copied into user-owned data. Once an account
  exists its owner renames, adds, removes and reorders them freely, and
  changing their language later never renames what they customised.
*/

require_once __DIR__ . '/i18n/languages.php';
require_once __DIR__ . '/currency_localization.php';

/**
 * Translation keys of the default categories, in display order.
 *
 * The keys are the contract; the English strings live in the language files
 * like every other translation.
 */
const WALLOS_DEFAULT_CATEGORY_KEYS = [
    'no_category',
    'category_entertainment',
    'category_music',
    'category_utilities',
    'category_food_and_beverages',
    'category_health_and_wellbeing',
    'category_productivity',
    'category_banking',
    'category_transport',
    'category_education',
    'category_insurance',
    'category_gaming',
    'category_news_and_magazines',
    'category_software',
    'category_technology',
    'category_cloud_services',
    'category_charity_and_donations',
];

/**
 * The default categories in one language, in display order.
 *
 * @param string $language
 * @return string[]
 */
function wallos_default_categories($language)
{
    $translations = wallos_translations($language);

    $categories = [];
    foreach (WALLOS_DEFAULT_CATEGORY_KEYS as $key) {
        $categories[] = $translations[$key] ?? $key;
    }

    return $categories;
}

/**
 * Creates the default categories for a new account.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param string  $language
 * @return bool
 */
function wallos_create_default_categories($db, $userId, $language)
{
    $stmt = $db->prepare('INSERT INTO categories (name, "order", user_id) VALUES (:name, :order, :user_id)');

    if ($stmt === false) {
        return false;
    }

    foreach (wallos_default_categories($language) as $index => $name) {
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':order', $index + 1, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);

        if ($stmt->execute() === false) {
            return false;
        }

        $stmt->reset();
    }

    return true;
}

/**
 * The default currencies, in display order, as code => fallback symbol.
 *
 * The code is the canonical identity — a Euro is EUR whatever the account's
 * language. The name and symbol are no longer maintained here: they come from
 * Unicode CLDR at seed time (wallos_currency_name / wallos_currency_symbol),
 * which is the authoritative source for how a currency is written in a given
 * language (issue #163). Providing them by hand, in English, was the thing that
 * change removed.
 *
 * The value kept against each code is a fallback symbol only — the symbol
 * Wallos historically shipped. CLDR has a distinctive symbol for some
 * currencies in some locales and none for others (the Bulgarian lev and the
 * Swiss franc, for instance, resolve to their bare code in most locales). Where
 * CLDR has none, this fallback keeps лв and Fr instead of degrading the display
 * to "BGN" and "CHF". It is the "existing/provider symbol" tier of the symbol
 * fallback chain, nothing more.
 *
 * No id field. The lists used to carry one, and it was the position in the list
 * rather than the row in the database: registration.php read it straight into
 * user.main_currency, where it names whatever currency row happens to hold that
 * id — another account's, on any installation past the first user. It is
 * corrected a few lines later by looking the code up for real, so nothing
 * breaks; carrying the number at all is what invites the confusion.
 *
 * @var array<string, string> ISO 4217 code => fallback symbol
 */
const WALLOS_DEFAULT_CURRENCIES = [
    'EUR' => '€',
    'USD' => '$',
    'JPY' => '¥',
    'BGN' => 'лв',
    'CZK' => 'Kč',
    'DKK' => 'kr',
    'GBP' => '£',
    'HUF' => 'Ft',
    'PLN' => 'zł',
    'RON' => 'lei',
    'SEK' => 'kr',
    'CHF' => 'Fr',
    'ISK' => 'kr',
    'NOK' => 'kr',
    'RUB' => '₽',
    'TRY' => '₺',
    'AUD' => '$',
    'BRL' => 'R$',
    'CAD' => '$',
    'CNY' => '¥',
    'HKD' => 'HK$',
    'IDR' => 'Rp',
    'ILS' => '₪',
    'INR' => '₹',
    'KRW' => '₩',
    'MXN' => 'Mex$',
    'MYR' => 'RM',
    'NZD' => 'NZ$',
    'PHP' => '₱',
    'SGD' => 'S$',
    'THB' => '฿',
    'ZAR' => 'R',
    'UAH' => '₴',
    'TWD' => 'NT$',
];

/**
 * The currencies a new account starts with, in display order.
 *
 * The name and symbol are resolved from Unicode CLDR into the account's
 * language at seed time and stored; afterwards they are plain user data, renamed
 * and edited freely, and a later language switch never rewrites them — the same
 * contract the categories keep. The code is canonical and identical in every
 * language.
 *
 * The historic Wallos symbol is passed as the fallback so that a currency CLDR
 * gives no locale symbol for still shows a real symbol rather than its bare
 * code.
 *
 * @param string $language
 * @return array[] each ['name' => string, 'symbol' => string, 'code' => string]
 */
function wallos_default_currencies($language)
{
    $currencies = [];
    foreach (WALLOS_DEFAULT_CURRENCIES as $code => $fallbackSymbol) {
        $currencies[] = [
            'name' => wallos_currency_name($code, $language),
            'symbol' => wallos_currency_symbol($code, $language, $fallbackSymbol),
            'code' => $code,
        ];
    }

    return $currencies;
}

/**
 * The default payment methods, in display order, each a name or a name key
 * plus an icon.
 *
 * A generic term — "Credit Card", "Bank Transfer", "Direct Debit", "Money" —
 * reads differently to a German and to an English speaker, so it carries a
 * translation `key` and is seeded in the account's language the way the
 * categories are. A brand — PayPal, Google Pay, SEPA — is the same word in
 * every language, so it stays a literal `name` with no key.
 *
 * @var array<int, array{name?: string, key?: string, icon: string}>
 */
const WALLOS_DEFAULT_PAYMENT_METHODS = [
    ['name' => 'PayPal', 'icon' => 'images/uploads/icons/paypal.png'],
    ['key' => 'payment_method_credit_card', 'icon' => 'images/uploads/icons/creditcard.png'],
    ['key' => 'payment_method_bank_transfer', 'icon' => 'images/uploads/icons/banktransfer.png'],
    ['key' => 'payment_method_direct_debit', 'icon' => 'images/uploads/icons/directdebit.png'],
    ['key' => 'payment_method_money', 'icon' => 'images/uploads/icons/money.png'],
    ['name' => 'Google Pay', 'icon' => 'images/uploads/icons/googlepay.png'],
    ['name' => 'Samsung Pay', 'icon' => 'images/uploads/icons/samsungpay.png'],
    ['name' => 'Apple Pay', 'icon' => 'images/uploads/icons/applepay.png'],
    ['name' => 'Crypto', 'icon' => 'images/uploads/icons/crypto.png'],
    ['name' => 'Klarna', 'icon' => 'images/uploads/icons/klarna.png'],
    ['name' => 'Amazon Pay', 'icon' => 'images/uploads/icons/amazonpay.png'],
    ['name' => 'SEPA', 'icon' => 'images/uploads/icons/sepa.png'],
    ['name' => 'Skrill', 'icon' => 'images/uploads/icons/skrill.png'],
    ['name' => 'Sofort', 'icon' => 'images/uploads/icons/sofort.png'],
    ['name' => 'Stripe', 'icon' => 'images/uploads/icons/stripe.png'],
    ['name' => 'Affirm', 'icon' => 'images/uploads/icons/affirm.png'],
    ['name' => 'AliPay', 'icon' => 'images/uploads/icons/alipay.png'],
    ['name' => 'Elo', 'icon' => 'images/uploads/icons/elo.png'],
    ['name' => 'Facebook Pay', 'icon' => 'images/uploads/icons/facebookpay.png'],
    ['name' => 'GiroPay', 'icon' => 'images/uploads/icons/giropay.png'],
    ['name' => 'iDeal', 'icon' => 'images/uploads/icons/ideal.png'],
    ['name' => 'Union Pay', 'icon' => 'images/uploads/icons/unionpay.png'],
    ['name' => 'Interac', 'icon' => 'images/uploads/icons/interac.png'],
    ['name' => 'WeChat', 'icon' => 'images/uploads/icons/wechat.png'],
    ['name' => 'Paysafe', 'icon' => 'images/uploads/icons/paysafe.png'],
    ['name' => 'Poli', 'icon' => 'images/uploads/icons/poli.png'],
    ['name' => 'Qiwi', 'icon' => 'images/uploads/icons/qiwi.png'],
    ['name' => 'ShopPay', 'icon' => 'images/uploads/icons/shoppay.png'],
    ['name' => 'Venmo', 'icon' => 'images/uploads/icons/venmo.png'],
    ['name' => 'VeriFone', 'icon' => 'images/uploads/icons/verifone.png'],
    ['name' => 'WebMoney', 'icon' => 'images/uploads/icons/webmoney.png'],
];

/**
 * The payment methods a new account starts with, in display order.
 *
 * A generic method's name is translated into the account's language at seed
 * time and stored; brand names are literal and identical in every language.
 * Afterwards the stored name is plain user data, and a later language switch
 * never rewrites it — the same contract the categories keep.
 *
 * @param string $language
 * @return array[] each ['name' => string, 'icon' => string]
 */
function wallos_default_payment_methods($language)
{
    $translations = wallos_translations($language);

    $methods = [];
    foreach (WALLOS_DEFAULT_PAYMENT_METHODS as $method) {
        $name = isset($method['key'])
            ? ($translations[$method['key']] ?? $method['key'])
            : $method['name'];
        $methods[] = ['name' => $name, 'icon' => $method['icon']];
    }

    return $methods;
}

/**
 * Creates the default currencies for a new account.
 *
 * Returns false on the first write that fails rather than running to the end
 * and reporting success: an account holding eleven of its thirty-four
 * currencies is not a state to report as done, and the caller can say so
 * (issue #87).
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $language
 * @return bool
 */
function wallos_create_default_currencies($db, $userId, $language)
{
    $statement = $db->prepare('INSERT INTO currencies (name, symbol, code, rate, user_id)
                               VALUES (:name, :symbol, :code, 1, :userId)');

    if ($statement === false) {
        return false;
    }

    foreach (wallos_default_currencies($language) as $currency) {
        $statement->bindValue(':name', $currency['name']);
        $statement->bindValue(':symbol', $currency['symbol']);
        $statement->bindValue(':code', $currency['code']);
        $statement->bindValue(':userId', (int) $userId);

        if ($statement->execute() === false) {
            return false;
        }

        $statement->reset();
    }

    return true;
}

/**
 * Creates the default payment methods for a new account.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $language
 * @return bool
 */
function wallos_create_default_payment_methods($db, $userId, $language)
{
    $statement = $db->prepare('INSERT INTO payment_methods (name, icon, "order", user_id)
                               VALUES (:name, :icon, :order, :userId)');

    if ($statement === false) {
        return false;
    }

    foreach (wallos_default_payment_methods($language) as $index => $method) {
        $statement->bindValue(':name', $method['name']);
        $statement->bindValue(':icon', $method['icon']);
        $statement->bindValue(':order', $index + 1);
        $statement->bindValue(':userId', (int) $userId);

        if ($statement->execute() === false) {
            return false;
        }

        $statement->reset();
    }

    return true;
}

/**
 * Adds the account holder as a household member, which is what payer_user_id
 * points at on every subscription they create.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $name
 * @return bool
 */
function wallos_create_household_member($db, $userId, $name)
{
    $statement = $db->prepare('INSERT INTO household (name, user_id) VALUES (:name, :userId)');

    if ($statement === false) {
        return false;
    }

    $statement->bindValue(':name', $name);
    $statement->bindValue(':userId', (int) $userId);

    return $statement->execute() !== false;
}

/**
 * The id of one of an account's own currencies, by code.
 *
 * Scoped to the account on purpose: the same code exists once per account, and
 * a lookup without the owner finds somebody else's row — which is how a
 * cross-account reference gets written by code that looks correct (issue #82).
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $code
 * @return int 0 when the account has no currency with that code
 */
function wallos_currency_id_for_code($db, $userId, $code)
{
    return (int) $db->scalar('SELECT id FROM currencies WHERE code = :code AND user_id = :userId',
        [':code' => $code, ':userId' => $userId]);
}

/* -------------------------------------------------------------------------
   Localizing a first-admin / still-default account's default names (issue #164)
   -------------------------------------------------------------------------

   Once an account exists its currency and payment-method names are user-owned:
   Wallos never auto-overwrites, auto-re-translates on a language switch, or
   silently migrates them. Two situations still leave an account holding the
   plain English seed, though:

     - The first admin. createdatabase.php (SQLite) and the PostgreSQL baseline
       seed the installation with literal English names, and the very first
       account adopts them rather than seeding its own (registration.php only
       calls the helpers above for user ids past the first). On the many
       single-user / all-admin instances that account is the main account.

     - Any account created before a language it later switches to existed, or
       seeded in English and switched afterwards.

   The functions below only ever *offer* to localize such names, and only for a
   row that still exactly equals a known English default — the code/symbol (or
   the icon) anchors the identity and the English name confirms it is untouched.
   A renamed or custom row is never a candidate. The rewrite touches only the
   `name` column: main_currency references currencies by id, never by name, so
   it is FK-safe and purely cosmetic. The apply is re-scoped to the exact
   English name it detected, so a row edited between preview and apply is
   skipped rather than clobbered.
*/

/**
 * Currency codes whose English name Wallos seeded historically differs from the
 * name Unicode CLDR now returns for English.
 *
 * Issue #163 replaced the hand-maintained English currency names with CLDR at
 * seed time. An installation seeded before that switch — its first-admin rows
 * come straight from createdatabase.php / the PostgreSQL baseline — still holds
 * the left-hand spelling. The localizer has to recognise it as the untouched
 * English default, or it would read the older spelling as a user rename and
 * refuse to offer the row. Only the divergences are listed, so the 34-name
 * English list #163 deleted is not quietly reintroduced.
 *
 * @var array<string, string> ISO 4217 code => historically seeded English name
 */
const WALLOS_LEGACY_CURRENCY_NAMES = [
    'CZK' => 'Czech Republic Koruna',
    'GBP' => 'British Pound Sterling',
    'ILS' => 'Israeli New Sheqel',
];

/**
 * The English names a default currency row may still carry untouched: the name
 * CLDR returns for English today, plus the historically seeded spelling where
 * it differed.
 *
 * @param string $code
 * @return string[]
 */
function wallos_currency_english_default_names($code)
{
    $names = [wallos_currency_name($code, 'en')];
    if (isset(WALLOS_LEGACY_CURRENCY_NAMES[$code])) {
        $names[] = WALLOS_LEGACY_CURRENCY_NAMES[$code];
    }

    return array_values(array_unique($names));
}

/**
 * The English symbols a default currency row may still carry untouched: the
 * symbol Wallos historically shipped (the fallback in WALLOS_DEFAULT_CURRENCIES,
 * which is what createdatabase.php and the PostgreSQL baseline seed) and the
 * symbol CLDR returns for English (what the seed helper stores for a later
 * account). These differ for a handful of codes — AUD is '$' from the baseline
 * but 'A$' from CLDR — so both are accepted.
 *
 * @param string $code
 * @return string[]
 */
function wallos_currency_english_default_symbols($code)
{
    $fallback = WALLOS_DEFAULT_CURRENCIES[$code];
    $symbols = [$fallback, wallos_currency_symbol($code, 'en', $fallback)];

    return array_values(array_unique($symbols));
}

/**
 * The still-default English currency rows an account could localize to its
 * language, each with the localized target name.
 *
 * A row qualifies only when its code is one Wallos seeds, its symbol is one of
 * the English defaults for that code, and its name is still a known English
 * default name for it. Localizing to English is meaningless — the rows are
 * already English — so a language that resolves to English yields nothing.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $language account/target language
 * @return array<int, array{type:string, id:int, code:string, current:string, localized:string}>
 */
function wallos_default_currency_localization_candidates($db, $userId, $language)
{
    $language = wallos_resolve_language($language);
    if ($language === 'en') {
        return [];
    }

    $rows = [];
    $statement = $db->prepare('SELECT id, name, symbol, code FROM currencies WHERE user_id = :userId ORDER BY id');
    if ($statement === false) {
        return [];
    }
    $statement->bindValue(':userId', (int) $userId);
    $result = $statement->execute();
    while ($result && $row = $result->fetchArray()) {
        $rows[] = $row;
    }

    $candidates = [];
    foreach ($rows as $row) {
        $code = (string) $row['code'];
        if (!isset(WALLOS_DEFAULT_CURRENCIES[$code])) {
            continue;
        }
        if (!in_array((string) $row['symbol'], wallos_currency_english_default_symbols($code), true)) {
            continue;
        }
        if (!in_array((string) $row['name'], wallos_currency_english_default_names($code), true)) {
            continue;
        }

        $localized = wallos_currency_name($code, $language);
        if ($localized === '' || $localized === $code || $localized === (string) $row['name']) {
            continue;
        }

        $candidates[] = [
            'type' => 'currency',
            'id' => (int) $row['id'],
            'code' => $code,
            'current' => (string) $row['name'],
            'localized' => $localized,
        ];
    }

    return $candidates;
}

/**
 * The still-default English payment-method rows an account could localize, each
 * with the localized target name.
 *
 * Only the generic methods carry a translation key — "Credit Card", "Bank
 * Transfer", "Direct Debit", "Money". A row qualifies when its icon is one of
 * those generic icons and its name is still the English string for that key.
 * Brands (PayPal, SEPA, …) are literal in every language and never candidates.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $language account/target language
 * @return array<int, array{type:string, id:int, key:string, current:string, localized:string}>
 */
function wallos_default_payment_method_localization_candidates($db, $userId, $language)
{
    $language = wallos_resolve_language($language);
    if ($language === 'en') {
        return [];
    }

    $genericByIcon = [];
    foreach (WALLOS_DEFAULT_PAYMENT_METHODS as $method) {
        if (isset($method['key'])) {
            $genericByIcon[$method['icon']] = $method['key'];
        }
    }

    $english = wallos_translations('en');
    $target = wallos_translations($language);

    $rows = [];
    $statement = $db->prepare('SELECT id, name, icon FROM payment_methods WHERE user_id = :userId ORDER BY id');
    if ($statement === false) {
        return [];
    }
    $statement->bindValue(':userId', (int) $userId);
    $result = $statement->execute();
    while ($result && $row = $result->fetchArray()) {
        $rows[] = $row;
    }

    $candidates = [];
    foreach ($rows as $row) {
        $icon = (string) $row['icon'];
        if (!isset($genericByIcon[$icon])) {
            continue;
        }
        $key = $genericByIcon[$icon];
        $englishName = $english[$key] ?? $key;
        if ((string) $row['name'] !== $englishName) {
            continue;
        }

        $localized = $target[$key] ?? $englishName;
        if ($localized === '' || $localized === (string) $row['name']) {
            continue;
        }

        $candidates[] = [
            'type' => 'payment_method',
            'id' => (int) $row['id'],
            'key' => $key,
            'current' => (string) $row['name'],
            'localized' => $localized,
        ];
    }

    return $candidates;
}

/**
 * Every still-default currency and payment-method row an account could localize.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $language account/target language
 * @return array<int, array<string, mixed>>
 */
function wallos_default_name_localization_candidates($db, $userId, $language)
{
    return array_merge(
        wallos_default_currency_localization_candidates($db, $userId, $language),
        wallos_default_payment_method_localization_candidates($db, $userId, $language)
    );
}

/**
 * Whether the dashboard should show the discovery banner that points an account
 * at the Settings localizer (issue #165).
 *
 * A thin decision over #164's detection, no new logic of its own: the banner is
 * offered only while the account still has rows the localizer could rename and
 * has not dismissed the offer. The candidate helper already encodes the rest —
 * it returns nothing for an English account (a language that resolves to 'en'),
 * nothing once every default row is renamed, and nothing for a target that
 * would equal the stored name — so an empty list is exactly "there is nothing
 * to offer". The banner only ever offers; showing it changes nothing.
 *
 * A dismissed account is answered without touching the database.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $language  account/target language
 * @param bool           $dismissed the account's saved dismissal (a cookie)
 * @return bool
 */
function wallos_should_offer_default_localization_banner($db, $userId, $language, $dismissed = false)
{
    if ($dismissed) {
        return false;
    }

    return wallos_default_name_localization_candidates($db, $userId, $language) !== [];
}

/**
 * Whether a candidate is in the caller's confirmed selection.
 *
 * A null selection means "all of them" — the automatic first-admin path, which
 * applies every candidate. Part B passes ['currencies' => int[], 'payment_methods'
 * => int[]] holding the ids the user ticked.
 *
 * @param array|null $selection
 * @param string     $type 'currency' or 'payment_method'
 * @param int        $id
 * @return bool
 */
function wallos_default_name_localization_selected($selection, $type, $id)
{
    if ($selection === null) {
        return true;
    }

    $bucket = $type === 'currency' ? 'currencies' : 'payment_methods';
    $ids = $selection[$bucket] ?? [];

    return in_array((int) $id, array_map('intval', (array) $ids), true);
}

/**
 * Localizes the confirmed still-default names to the account language.
 *
 * The rewrite touches only the name column and is re-scoped to the exact
 * English name detected, so a row a user edited between preview and apply no
 * longer matches and is left alone (no clobber). main_currency references a
 * currency by id, so renaming is FK-safe.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $language account/target language
 * @param array|null     $selection null applies every candidate; otherwise the
 *                                   confirmed ids per type
 * @return array{applied:int, error:bool} how many rows were renamed
 */
function wallos_apply_default_name_localization($db, $userId, $language, $selection = null)
{
    $currencyStatement = $db->prepare(
        'UPDATE currencies SET name = :name WHERE user_id = :userId AND id = :id AND name = :expected');
    $paymentStatement = $db->prepare(
        'UPDATE payment_methods SET name = :name WHERE user_id = :userId AND id = :id AND name = :expected');

    if ($currencyStatement === false || $paymentStatement === false) {
        return ['applied' => 0, 'error' => true];
    }

    // Each type binds its own prepared statement rather than an aliased handle,
    // so the placeholders and binds stay statically paired (dev/bind-audit.php).
    $applied = 0;
    foreach (wallos_default_name_localization_candidates($db, $userId, $language) as $candidate) {
        if (!wallos_default_name_localization_selected($selection, $candidate['type'], $candidate['id'])) {
            continue;
        }

        if ($candidate['type'] === 'currency') {
            $currencyStatement->bindValue(':name', $candidate['localized']);
            $currencyStatement->bindValue(':userId', (int) $userId);
            $currencyStatement->bindValue(':id', (int) $candidate['id']);
            $currencyStatement->bindValue(':expected', $candidate['current']);

            if ($currencyStatement->execute() === false) {
                return ['applied' => $applied, 'error' => true];
            }
            $applied += $db->changes();
            $currencyStatement->reset();
        } else {
            $paymentStatement->bindValue(':name', $candidate['localized']);
            $paymentStatement->bindValue(':userId', (int) $userId);
            $paymentStatement->bindValue(':id', (int) $candidate['id']);
            $paymentStatement->bindValue(':expected', $candidate['current']);

            if ($paymentStatement->execute() === false) {
                return ['applied' => $applied, 'error' => true];
            }
            $applied += $db->changes();
            $paymentStatement->reset();
        }
    }

    return ['applied' => $applied, 'error' => false];
}
