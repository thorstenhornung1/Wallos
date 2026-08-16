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
