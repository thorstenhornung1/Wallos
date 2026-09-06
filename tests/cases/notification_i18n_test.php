<?php
/*
  German i18n coverage and keyed notification texts (issues #130 and #160).

  Two guarantees, both backend-independent (no database), so the same assertions
  run under SQLite and PostgreSQL:

    * de.php defines every key en.php defines — the completeness proof for the
      German locale, and a guard against future drift. de may hold extra keys
      (category_* and one legacy alias); en is the canonical set de must cover.

    * the renewal notification builders source their text through translate() in
      the recipient account's language: a German account gets a German subject,
      body, price connector and day labels; an English account still gets
      English. No English string is hardcoded at the keyed sites any more.
*/

require_once WALLOS_ROOT . '/includes/i18n/languages.php';
require_once WALLOS_ROOT . '/includes/i18n/getlang.php';
require_once WALLOS_ROOT . '/includes/notification_message.php';

// Loads a locale file into a fresh array without touching any global $i18n.
function wallos_test_load_i18n($file)
{
    $i18n = [];
    require $file;
    return $i18n;
}

wallos_test('de.php defines every key en.php defines', function () {
    $en = wallos_test_load_i18n(WALLOS_ROOT . '/includes/i18n/en.php');
    $de = wallos_test_load_i18n(WALLOS_ROOT . '/includes/i18n/de.php');

    $missing = array_diff(array_keys($en), array_keys($de));

    assert_true(
        empty($missing),
        'de.php must define every en.php key; missing: ' . implode(', ', $missing)
    );
});

wallos_test('the notification keys #130 adds exist in both en and de', function () {
    $en = wallos_test_load_i18n(WALLOS_ROOT . '/includes/i18n/en.php');
    $de = wallos_test_load_i18n(WALLOS_ROOT . '/includes/i18n/de.php');

    foreach (['subscriptions_up_for_renewal', 'notify_for', 'notify_today',
              'notify_tomorrow', 'notify_in_days', 'wallos_notification'] as $key) {
        assert_true(isset($en[$key]), $key . ' is defined in en.php');
        assert_true(isset($de[$key]), $key . ' is defined in de.php');
        assert_true($en[$key] !== '' && $de[$key] !== '', $key . ' is non-empty in both');
    }
});

wallos_test('day labels render in the account language', function () {
    $de = wallos_notification_i18n('de');
    $en = wallos_notification_i18n('en');

    assert_same('Heute', getDaysText(0, $de), 'today, German');
    assert_same('Morgen', getDaysText(1, $de), 'tomorrow, German');
    assert_same('In 3 Tagen', getDaysText(3, $de), 'in N days, German');

    assert_same('Today', getDaysText(0, $en), 'today, English');
    assert_same('Tomorrow', getDaysText(1, $en), 'tomorrow, English');
    assert_same('In 3 days', getDaysText(3, $en), 'in N days, English');
});

wallos_test('a renewal message body renders German for a German account', function () {
    $de = wallos_notification_i18n('de');

    $perUser = [[
        'name' => 'Netflix',
        'formatted_price' => '9,99 €',
        'days' => 2,
    ]];

    $message = buildNotificationMessage('Max', $perUser, 'ZUSAMMENFASSUNG', false, $de);

    assert_contains('folgenden Abonnements stehen zur Verlängerung an', $message,
        'the renewal line is German');
    assert_contains('Netflix für 9,99 €', $message, 'the price connector is German');
    assert_contains('(In 2 Tagen)', $message, 'the day label is German');
    assert_not_contains('up for renewal', $message, 'no English leaks into a German message');
    assert_not_contains(' for ', $message, 'the English price connector is gone');
});

wallos_test('a renewal message body still renders English for an English account', function () {
    $en = wallos_notification_i18n('en');

    $perUser = [[
        'name' => 'Netflix',
        'formatted_price' => '$9.99',
        'days' => 2,
    ]];

    $message = buildNotificationMessage('Max', $perUser, 'SUMMARY', false, $en);

    assert_contains('the following subscriptions are up for renewal', $message,
        'the renewal line is English');
    assert_contains('Netflix for $9.99', $message, 'the English price connector');
    assert_contains('(In 2 days)', $message, 'the English day label');
});

wallos_test('the notification subject is sourced from the account language', function () {
    $de = wallos_notification_i18n('de');
    $en = wallos_notification_i18n('en');

    assert_same('Wallos Benachrichtigung', translate('wallos_notification', $de),
        'German subject');
    assert_same('Wallos Notification', translate('wallos_notification', $en),
        'English subject');
});
