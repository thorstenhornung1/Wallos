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
 * The currencies a new account starts with, in display order.
 *
 * Three paths create accounts and each carried its own copy of this list, the
 * way they all carried their own copy of the categories before this file
 * existed. The three were still identical when they were merged — 34 entries in
 * the same order — which is the only comfortable moment to merge them.
 *
 * No id field. The lists used to carry one, and it was the position in the list
 * rather than the row in the database: registration.php read it straight into
 * user.main_currency, where it names whatever currency row happens to hold that
 * id — another account's, on any installation past the first user. It is
 * corrected a few lines later by looking the code up for real, so nothing
 * breaks; carrying the number at all is what invites the confusion.
 *
 * @return array[] each ['name' => string, 'symbol' => string, 'code' => string]
 */
function wallos_default_currencies()
{
    return [
        ['name' => 'Euro', 'symbol' => '€', 'code' => 'EUR'],
        ['name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD'],
        ['name' => 'Japanese Yen', 'symbol' => '¥', 'code' => 'JPY'],
        ['name' => 'Bulgarian Lev', 'symbol' => 'лв', 'code' => 'BGN'],
        ['name' => 'Czech Republic Koruna', 'symbol' => 'Kč', 'code' => 'CZK'],
        ['name' => 'Danish Krone', 'symbol' => 'kr', 'code' => 'DKK'],
        ['name' => 'British Pound Sterling', 'symbol' => '£', 'code' => 'GBP'],
        ['name' => 'Hungarian Forint', 'symbol' => 'Ft', 'code' => 'HUF'],
        ['name' => 'Polish Zloty', 'symbol' => 'zł', 'code' => 'PLN'],
        ['name' => 'Romanian Leu', 'symbol' => 'lei', 'code' => 'RON'],
        ['name' => 'Swedish Krona', 'symbol' => 'kr', 'code' => 'SEK'],
        ['name' => 'Swiss Franc', 'symbol' => 'Fr', 'code' => 'CHF'],
        ['name' => 'Icelandic Króna', 'symbol' => 'kr', 'code' => 'ISK'],
        ['name' => 'Norwegian Krone', 'symbol' => 'kr', 'code' => 'NOK'],
        ['name' => 'Russian Ruble', 'symbol' => '₽', 'code' => 'RUB'],
        ['name' => 'Turkish Lira', 'symbol' => '₺', 'code' => 'TRY'],
        ['name' => 'Australian Dollar', 'symbol' => '$', 'code' => 'AUD'],
        ['name' => 'Brazilian Real', 'symbol' => 'R$', 'code' => 'BRL'],
        ['name' => 'Canadian Dollar', 'symbol' => '$', 'code' => 'CAD'],
        ['name' => 'Chinese Yuan', 'symbol' => '¥', 'code' => 'CNY'],
        ['name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'code' => 'HKD'],
        ['name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'code' => 'IDR'],
        ['name' => 'Israeli New Sheqel', 'symbol' => '₪', 'code' => 'ILS'],
        ['name' => 'Indian Rupee', 'symbol' => '₹', 'code' => 'INR'],
        ['name' => 'South Korean Won', 'symbol' => '₩', 'code' => 'KRW'],
        ['name' => 'Mexican Peso', 'symbol' => 'Mex$', 'code' => 'MXN'],
        ['name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'code' => 'MYR'],
        ['name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'code' => 'NZD'],
        ['name' => 'Philippine Peso', 'symbol' => '₱', 'code' => 'PHP'],
        ['name' => 'Singapore Dollar', 'symbol' => 'S$', 'code' => 'SGD'],
        ['name' => 'Thai Baht', 'symbol' => '฿', 'code' => 'THB'],
        ['name' => 'South African Rand', 'symbol' => 'R', 'code' => 'ZAR'],
        ['name' => 'Ukrainian Hryvnia', 'symbol' => '₴', 'code' => 'UAH'],
        ['name' => 'New Taiwan Dollar', 'symbol' => 'NT$', 'code' => 'TWD'],
        ];
}

/**
 * The payment methods a new account starts with, in display order.
 *
 * @return array[] each ['name' => string, 'icon' => string]
 */
function wallos_default_payment_methods()
{
    return [
        ['name' => 'PayPal', 'icon' => 'images/uploads/icons/paypal.png'],
        ['name' => 'Credit Card', 'icon' => 'images/uploads/icons/creditcard.png'],
        ['name' => 'Bank Transfer', 'icon' => 'images/uploads/icons/banktransfer.png'],
        ['name' => 'Direct Debit', 'icon' => 'images/uploads/icons/directdebit.png'],
        ['name' => 'Money', 'icon' => 'images/uploads/icons/money.png'],
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
 * @return bool
 */
function wallos_create_default_currencies($db, $userId)
{
    $statement = $db->prepare('INSERT INTO currencies (name, symbol, code, rate, user_id)
                               VALUES (:name, :symbol, :code, 1, :userId)');

    if ($statement === false) {
        return false;
    }

    foreach (wallos_default_currencies() as $currency) {
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
 * @return bool
 */
function wallos_create_default_payment_methods($db, $userId)
{
    $statement = $db->prepare('INSERT INTO payment_methods (name, icon, "order", user_id)
                               VALUES (:name, :icon, :order, :userId)');

    if ($statement === false) {
        return false;
    }

    foreach (wallos_default_payment_methods() as $index => $method) {
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
