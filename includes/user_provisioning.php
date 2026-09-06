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
 * The default currencies, in display order, as code => symbol + name key.
 *
 * A currency's code and symbol are canonical: a Euro is EUR and € whatever the
 * account's language. Only the name reads differently to a German and to an
 * English speaker, so only the name carries a translation key — resolved from
 * the language file at seed time exactly as the category names are. The code
 * and symbol stay literal.
 *
 * No id field. The lists used to carry one, and it was the position in the list
 * rather than the row in the database: registration.php read it straight into
 * user.main_currency, where it names whatever currency row happens to hold that
 * id — another account's, on any installation past the first user. It is
 * corrected a few lines later by looking the code up for real, so nothing
 * breaks; carrying the number at all is what invites the confusion.
 *
 * @var array<string, array{symbol: string, key: string}>
 */
const WALLOS_DEFAULT_CURRENCIES = [
    'EUR' => ['symbol' => '€', 'key' => 'currency_name_eur'],
    'USD' => ['symbol' => '$', 'key' => 'currency_name_usd'],
    'JPY' => ['symbol' => '¥', 'key' => 'currency_name_jpy'],
    'BGN' => ['symbol' => 'лв', 'key' => 'currency_name_bgn'],
    'CZK' => ['symbol' => 'Kč', 'key' => 'currency_name_czk'],
    'DKK' => ['symbol' => 'kr', 'key' => 'currency_name_dkk'],
    'GBP' => ['symbol' => '£', 'key' => 'currency_name_gbp'],
    'HUF' => ['symbol' => 'Ft', 'key' => 'currency_name_huf'],
    'PLN' => ['symbol' => 'zł', 'key' => 'currency_name_pln'],
    'RON' => ['symbol' => 'lei', 'key' => 'currency_name_ron'],
    'SEK' => ['symbol' => 'kr', 'key' => 'currency_name_sek'],
    'CHF' => ['symbol' => 'Fr', 'key' => 'currency_name_chf'],
    'ISK' => ['symbol' => 'kr', 'key' => 'currency_name_isk'],
    'NOK' => ['symbol' => 'kr', 'key' => 'currency_name_nok'],
    'RUB' => ['symbol' => '₽', 'key' => 'currency_name_rub'],
    'TRY' => ['symbol' => '₺', 'key' => 'currency_name_try'],
    'AUD' => ['symbol' => '$', 'key' => 'currency_name_aud'],
    'BRL' => ['symbol' => 'R$', 'key' => 'currency_name_brl'],
    'CAD' => ['symbol' => '$', 'key' => 'currency_name_cad'],
    'CNY' => ['symbol' => '¥', 'key' => 'currency_name_cny'],
    'HKD' => ['symbol' => 'HK$', 'key' => 'currency_name_hkd'],
    'IDR' => ['symbol' => 'Rp', 'key' => 'currency_name_idr'],
    'ILS' => ['symbol' => '₪', 'key' => 'currency_name_ils'],
    'INR' => ['symbol' => '₹', 'key' => 'currency_name_inr'],
    'KRW' => ['symbol' => '₩', 'key' => 'currency_name_krw'],
    'MXN' => ['symbol' => 'Mex$', 'key' => 'currency_name_mxn'],
    'MYR' => ['symbol' => 'RM', 'key' => 'currency_name_myr'],
    'NZD' => ['symbol' => 'NZ$', 'key' => 'currency_name_nzd'],
    'PHP' => ['symbol' => '₱', 'key' => 'currency_name_php'],
    'SGD' => ['symbol' => 'S$', 'key' => 'currency_name_sgd'],
    'THB' => ['symbol' => '฿', 'key' => 'currency_name_thb'],
    'ZAR' => ['symbol' => 'R', 'key' => 'currency_name_zar'],
    'UAH' => ['symbol' => '₴', 'key' => 'currency_name_uah'],
    'TWD' => ['symbol' => 'NT$', 'key' => 'currency_name_twd'],
];

/**
 * The currencies a new account starts with, in display order.
 *
 * The name is translated into the account's language at seed time and stored;
 * afterwards it is plain user data, renamed and edited freely, and a later
 * language switch never rewrites it — the same contract the categories keep.
 * The code and symbol are canonical and identical in every language.
 *
 * @param string $language
 * @return array[] each ['name' => string, 'symbol' => string, 'code' => string]
 */
function wallos_default_currencies($language)
{
    $translations = wallos_translations($language);

    $currencies = [];
    foreach (WALLOS_DEFAULT_CURRENCIES as $code => $currency) {
        $currencies[] = [
            'name' => $translations[$currency['key']] ?? $currency['key'],
            'symbol' => $currency['symbol'],
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
