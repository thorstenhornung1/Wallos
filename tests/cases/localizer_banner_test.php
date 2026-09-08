<?php
/*
  Issue #165: the dashboard discovery banner that points a still-default account
  at the Settings localizer #164 added.

  The banner carries no detection of its own. Its whole decision is a thin
  predicate over #164's candidate helper -- show it only while the account still
  has rows the localizer could rename and has not dismissed the offer -- so these
  cases pin exactly the four states the issue names: a still-default non-English
  account sees it, an English account does not, a fully-renamed (no-candidate)
  account does not, and a dismissed account does not. Both backends, because the
  candidate check reads the account's rows through the boundary.
*/

require_once WALLOS_ROOT . '/includes/user_provisioning.php';

wallos_test('the banner is offered to a still-default non-English account', function () {
    // User 1 is the createdatabase.php / PostgreSQL-baseline first admin: its
    // currency and payment-method rows are the untouched English seed, which is
    // exactly the account #164 localizes. In German there are candidates, so the
    // banner is offered.
    $db = wallos_test_open_database();

    assert_true(wallos_default_name_localization_candidates($db, 1, 'de') !== [],
        'the account has localization candidates (precondition)');
    assert_true(wallos_should_offer_default_localization_banner($db, 1, 'de', false),
        'the banner is offered when candidates exist and it is not dismissed');

    $db->close();
});

wallos_test('the banner is not offered to an English account', function () {
    // Localizing to English is meaningless, so the candidate helper returns
    // nothing and the banner never appears -- the "English sees nothing" case.
    $db = wallos_test_open_database();

    assert_same([], wallos_default_name_localization_candidates($db, 1, 'en'),
        'an English account has no candidates (precondition)');
    assert_true(!wallos_should_offer_default_localization_banner($db, 1, 'en', false),
        'the banner is not offered to an English account');

    $db->close();
});

wallos_test('the banner is not offered once the account has no candidates left', function () {
    // A fresh account seeded with EUR (a German no-op) and USD ("US Dollar").
    // Rename the one remaining candidate the way a user would, and nothing is
    // left to localize -- the fully-renamed account sees no banner, and it
    // self-suppresses because detection returns none, with no cookie involved.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5001, 'de-account');

    $usdId = wallos_currency_id_for_code($db, 5001, 'USD');
    $stmt = $db->prepare('UPDATE currencies SET name = :name WHERE id = :id');
    $stmt->bindValue(':name', 'My Dollar');
    $stmt->bindValue(':id', $usdId);
    $stmt->execute();

    assert_same([], wallos_default_name_localization_candidates($db, 5001, 'de'),
        'a fully-renamed account has no candidates (precondition)');
    assert_true(!wallos_should_offer_default_localization_banner($db, 5001, 'de', false),
        'the banner is not offered when no candidates remain');

    $db->close();
});

wallos_test('a dismissed banner is not offered even while candidates remain', function () {
    // The candidates are still there -- dismissal is the only thing suppressing
    // the offer, and it is remembered in a cookie, not by changing the data.
    $db = wallos_test_open_database();

    assert_true(wallos_default_name_localization_candidates($db, 1, 'de') !== [],
        'candidates still exist (precondition)');
    assert_true(!wallos_should_offer_default_localization_banner($db, 1, 'de', true),
        'a dismissed banner is not offered');

    $db->close();
});

wallos_test('the dashboard wires the banner to #164 detection', function () {
    // The banner must actually call the decision helper (which itself calls the
    // #164 candidate detector) and must not have grown detection of its own.
    assert_true(wallos_test_file_calls('index.php', 'wallos_should_offer_default_localization_banner'),
        'index.php calls the banner decision helper');
    assert_true(wallos_test_file_calls('includes/user_provisioning.php', 'wallos_default_name_localization_candidates'),
        'the decision helper reuses the #164 candidate detection');
});

// The state the suite could not reach until this fixture existed: one half of
// the migration done, the other still waiting. wallos_test_create_user() seeds
// a single payment method named 'Fixture card' with an empty icon, which is
// never a candidate (the detector matches on the generic icons), so every case
// above reasons about currencies alone. That blind spot is exactly where the
// banner misled a user in practice: the currencies were renamed, the banner
// stayed up because four payment methods still held their English names, and
// the offer looked broken rather than half-finished.
function wallos_test_seed_generic_payment_methods($db, $userId)
{
    $english = wallos_translations('en');
    foreach (WALLOS_DEFAULT_PAYMENT_METHODS as $method) {
        if (!isset($method['key'])) {
            continue;
        }
        $stmt = $db->prepare('INSERT INTO payment_methods (name, icon, enabled, "order", user_id)
                              VALUES (:name, :icon, 1, 1, :userId)');
        $stmt->bindValue(':name', $english[$method['key']] ?? $method['key']);
        $stmt->bindValue(':icon', $method['icon']);
        $stmt->bindValue(':userId', (int) $userId);
        $stmt->execute();
    }
}

wallos_test('the banner stays while a renamed account still has payment methods to do', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5002, 'de-half-done');
    wallos_test_seed_generic_payment_methods($db, 5002);

    // Rename the one currency candidate, the way the user did on the instance.
    $usdId = wallos_currency_id_for_code($db, 5002, 'USD');
    $stmt = $db->prepare('UPDATE currencies SET name = :name WHERE id = :id');
    $stmt->bindValue(':name', 'US-Dollar');
    $stmt->bindValue(':id', $usdId);
    $stmt->execute();

    assert_same([], wallos_default_currency_localization_candidates($db, 5002, 'de'),
        'the currencies are done (precondition)');
    assert_equals(4, count(wallos_default_payment_method_localization_candidates($db, 5002, 'de')),
        'the four generic payment methods are still candidates');
    assert_true(wallos_should_offer_default_localization_banner($db, 5002, 'de', false),
        'the banner stays up while either half still has work');

    $db->close();
});

wallos_test('brands are never candidates, so the offer can actually end', function () {
    // 27 of the seeded payment methods are brand names -- PayPal, Klarna, SEPA --
    // which read the same in every language. If they counted as "still English"
    // the banner could never go away, however much a user localized. They are
    // excluded before any name comparison, by carrying no translation key.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5003, 'de-brands');

    $english = wallos_translations('en');
    foreach (WALLOS_DEFAULT_PAYMENT_METHODS as $method) {
        $name = isset($method['key']) ? ($english[$method['key']] ?? $method['key']) : $method['name'];
        $stmt = $db->prepare('INSERT INTO payment_methods (name, icon, enabled, "order", user_id)
                              VALUES (:name, :icon, 1, 1, :userId)');
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':icon', $method['icon']);
        $stmt->bindValue(':userId', 5003);
        $stmt->execute();
    }

    $candidates = wallos_default_payment_method_localization_candidates($db, 5003, 'de');
    assert_equals(4, count($candidates), 'only the four generic terms are candidates');

    // Localize everything the detector offers, and the offer is over -- no
    // brand keeps it alive.
    wallos_apply_default_name_localization($db, 5003, 'de', null);
    assert_same([], wallos_default_name_localization_candidates($db, 5003, 'de'),
        'nothing is left once the generic terms are renamed');
    assert_true(!wallos_should_offer_default_localization_banner($db, 5003, 'de', false),
        'the banner ends, rather than being held open by the brands');

    $db->close();
});

wallos_test('the banner leads to the migration page, which owns both halves', function () {
    // The banner offers currency *and* payment-method names, so it needs one
    // destination that holds both. It used to link to settings.php#localize-currencies,
    // where a user who had already renamed the currencies landed on a section
    // with nothing left to do while the payment methods waited ~500 lines below.
    $dashboard = file_get_contents(WALLOS_ROOT . '/index.php');
    assert_contains('href="localize.php"', $dashboard,
        'the banner links to the migration page');
    assert_not_contains('settings.php#localize', $dashboard,
        'no link to a half of the job inside the settings page');

    // The page carries both halves and nothing detects on its own.
    assert_true(wallos_test_file_calls('localize.php', 'wallos_default_currency_localization_candidates'),
        'the page previews the currency candidates');
    assert_true(wallos_test_file_calls('localize.php', 'wallos_default_payment_method_localization_candidates'),
        'the page previews the payment-method candidates');

    // The page is meant to disappear on its own: an account with nothing left
    // to rename is sent to the dashboard rather than shown an empty page, which
    // is also what makes the reload after a successful run leave it for good.
    $redirects = file_get_contents(WALLOS_ROOT . '/includes/checkredirect.php');
    assert_contains("\$currentPage == 'localize.php'", $redirects,
        'the redirect runs from checkredirect.php, before the document starts');
    assert_not_contains("header('Location", file_get_contents(WALLOS_ROOT . '/localize.php'),
        'the page itself does not attempt a redirect it cannot perform');

    // Settings no longer carries the migration UI at all.
    $settings = file_get_contents(WALLOS_ROOT . '/settings.php');
    assert_not_contains('localize-defaults', $settings,
        'the settings page no longer hosts the localizer');
});

wallos_test('no page tries to redirect after the document has started', function () {
    // includes/header.php prints <!DOCTYPE html> at line 82. A Location header
    // sent after that first byte is discarded by PHP with "headers already
    // sent" -- but the exit next to it still runs, so the visitor gets a page
    // that stops after the navigation instead of the redirect. It looks like a
    // blank page, and with display_errors off there is not even a warning.
    //
    // That is exactly how localize.php shipped in 5.16.0. The redirect belongs
    // in includes/checkredirect.php, which header.php loads at line 5, well
    // before any output.
    //
    // admin.php carried the same defect and was fixed under #173. The list is
    // empty and may never grow: a page that needs to send someone elsewhere
    // does it from checkredirect.php, before the first byte of output.
    $known = [];

    $offenders = [];
    foreach (glob(WALLOS_ROOT . '/*.php') as $path) {
        $name = basename($path);
        $source = file_get_contents($path);

        $header = strpos($source, "require_once 'includes/header.php'");
        if ($header === false) {
            continue;
        }

        if (preg_match('/header\s*\(\s*[\'"]Location/i', $source, $match, PREG_OFFSET_CAPTURE)
            && $match[0][1] > $header) {
            $offenders[] = $name;
        }
    }

    sort($offenders);
    assert_same($known, $offenders,
        'a page redirects only from checkredirect.php, before the first byte of output');
});
