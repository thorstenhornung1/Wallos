<?php
/*
  Issue #164: localizing an account's still-default currency and payment-method
  names.

  Part A — a fresh install's first admin adopts the English seed
  (createdatabase.php / the PostgreSQL baseline). When an instance default
  language is set it is localized at setup; with none it stays English.

  Part B — an existing account opts in to localize its still-default rows, per
  row, and a renamed or custom row is never touched. The rewrite is name-only,
  so main_currency (a currency id) is never disturbed.

  Both backends: the fixture seeds user 1 with the createdatabase.php English
  defaults, which is exactly the first-admin state Part A targets.
*/

require_once WALLOS_ROOT . '/includes/user_provisioning.php';

/**
 * A candidate list keyed by its code (currencies) or translation key (methods),
 * with the "current -> localized" rename as the value.
 */
function localize_index($candidates)
{
    $index = [];
    foreach ($candidates as $candidate) {
        $label = $candidate['type'] === 'currency' ? $candidate['code'] : $candidate['key'];
        $index[$label] = $candidate['current'] . ' -> ' . $candidate['localized'];
    }

    return $index;
}

/* ---- Part A: the createdatabase.php-seeded first admin -------------------- */

wallos_test('detection covers a createdatabase.php-seeded first admin', function () {
    // The fixture DB is the real createdatabase.php + migration output; user 1's
    // currency and payment-method rows are the literal English seed.
    $db = wallos_test_open_database();

    $index = localize_index(wallos_default_name_localization_candidates($db, 1, 'de'));

    assert_same('US Dollar -> US-Dollar', $index['USD'] ?? '', 'USD is a candidate');
    assert_same('Japanese Yen -> Japanischer Yen', $index['JPY'] ?? '', 'JPY is a candidate');
    // A currency whose seeded English spelling predates the CLDR switch (#163):
    // it must still be recognised as the untouched English default.
    assert_same('Czech Republic Koruna -> Tschechische Krone', $index['CZK'] ?? '',
        'the legacy English spelling is recognised, not mistaken for a rename');
    assert_same('Credit Card -> Kreditkarte', $index['payment_method_credit_card'] ?? '',
        'the generic Credit Card method is a candidate');
    assert_same('Money -> Bargeld', $index['payment_method_money'] ?? '',
        'the generic Money method is a candidate');

    // A currency whose name is identical in the target language is no candidate.
    assert_true(!isset($index['EUR']), 'Euro reads the same in German, so it is left out');
    // A brand is literal in every language and never offered.
    assert_true(!isset($index['PayPal']), 'the brand PayPal is never a candidate');

    $db->close();
});

wallos_test('a fresh first admin is seeded in the instance default language', function () {
    // Applying every candidate is the automatic first-admin path (null selection).
    $db = wallos_test_open_database();

    $outcome = wallos_apply_default_name_localization($db, 1, 'de');
    assert_true(!$outcome['error'], 'the apply reports no error');
    assert_true($outcome['applied'] > 30, 'most of the default names were localized');

    assert_same('US-Dollar', (string) $db->scalar(
        'SELECT name FROM currencies WHERE user_id = 1 AND code = :c', [':c' => 'USD']),
        'USD is now the German name');
    assert_same('Tschechische Krone', (string) $db->scalar(
        'SELECT name FROM currencies WHERE user_id = 1 AND code = :c', [':c' => 'CZK']),
        'the legacy-spelled CZK is now the German name');
    assert_same('Euro', (string) $db->scalar(
        'SELECT name FROM currencies WHERE user_id = 1 AND code = :c', [':c' => 'EUR']),
        'Euro is unchanged');
    assert_same(1, (int) $db->scalar(
        'SELECT COUNT(*) FROM payment_methods WHERE user_id = 1 AND name = :n', [':n' => 'Kreditkarte']),
        'Credit Card is now Kreditkarte');
    assert_same(0, (int) $db->scalar(
        'SELECT COUNT(*) FROM payment_methods WHERE user_id = 1 AND name = :n', [':n' => 'Credit Card']),
        'the English Credit Card name is gone');

    // Idempotent: a second run finds nothing left to do.
    $again = wallos_apply_default_name_localization($db, 1, 'de');
    assert_same(0, $again['applied'], 'the second run localizes nothing');

    $db->close();
});

wallos_test('with no instance default language the English seed stays', function () {
    // Localizing to English is meaningless, so it yields no candidates and
    // renames nothing — the "English stays (current behaviour)" branch of part A.
    $db = wallos_test_open_database();

    assert_same([], wallos_default_name_localization_candidates($db, 1, 'en'),
        'English produces no candidates');

    $outcome = wallos_apply_default_name_localization($db, 1, 'en');
    assert_same(0, $outcome['applied'], 'nothing is localized');

    assert_same('US Dollar', (string) $db->scalar(
        'SELECT name FROM currencies WHERE user_id = 1 AND code = :c', [':c' => 'USD']),
        'USD keeps its English name');
    assert_same('Czech Republic Koruna', (string) $db->scalar(
        'SELECT name FROM currencies WHERE user_id = 1 AND code = :c', [':c' => 'CZK']),
        'the legacy English spelling is not rewritten to the newer CLDR one');
    assert_same(1, (int) $db->scalar(
        'SELECT COUNT(*) FROM payment_methods WHERE user_id = 1 AND name = :n', [':n' => 'Credit Card']),
        'Credit Card keeps its English name');

    $db->close();
});

wallos_test('registration wires the first-admin localizer', function () {
    // Part A runs at first-admin setup. The page must call the localizer and
    // resolve the instance default language it localizes to.
    assert_true(wallos_test_file_calls('registration.php', 'wallos_apply_default_name_localization'),
        'registration.php applies the first-admin localization');
    assert_true(wallos_test_file_calls('registration.php', 'wallos_instance_default_language'),
        'registration.php resolves the instance default language');
});

/* ---- Part B: an existing account opts in --------------------------------- */

/**
 * Adds one payment method to an account and returns its id.
 */
function localize_add_payment_method($db, $userId, $name, $icon, $order)
{
    $stmt = $db->prepare('INSERT INTO payment_methods (name, icon, enabled, "order", user_id)
                          VALUES (:name, :icon, 1, :order, :userId)');
    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':icon', $icon);
    $stmt->bindValue(':order', (int) $order);
    $stmt->bindValue(':userId', (int) $userId);
    $stmt->execute();

    return (int) $db->scalar('SELECT id FROM payment_methods WHERE user_id = :u AND name = :n ORDER BY id DESC',
        [':u' => $userId, ':n' => $name]);
}

wallos_test('an opt-in localizes a still-default row to the account language', function () {
    // The fixture gives user 5001 a USD row named "US Dollar" — a still-default
    // English row — plus its own currencies. A generic Credit Card method is
    // added to exercise the payment side.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5001, 'de-account');

    $creditCardId = localize_add_payment_method($db, 5001, 'Credit Card',
        'images/uploads/icons/creditcard.png', 10);
    $usdId = wallos_currency_id_for_code($db, 5001, 'USD');

    $index = localize_index(wallos_default_name_localization_candidates($db, 5001, 'de'));
    assert_same('US Dollar -> US-Dollar', $index['USD'] ?? '', 'USD is offered');
    assert_same('Credit Card -> Kreditkarte', $index['payment_method_credit_card'] ?? '',
        'the Credit Card method is offered');

    // Confirm both explicitly (Part B is per-row consent).
    $outcome = wallos_apply_default_name_localization($db, 5001, 'de', [
        'currencies' => [$usdId],
        'payment_methods' => [$creditCardId],
    ]);
    assert_same(2, $outcome['applied'], 'exactly the two confirmed rows were localized');

    assert_same('US-Dollar', (string) $db->scalar(
        'SELECT name FROM currencies WHERE id = :id', [':id' => $usdId]), 'USD renamed');
    assert_same('Kreditkarte', (string) $db->scalar(
        'SELECT name FROM payment_methods WHERE id = :id', [':id' => $creditCardId]), 'Credit Card renamed');

    $db->close();
});

wallos_test('only the confirmed rows are localized', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5001, 'de-account');

    $creditCardId = localize_add_payment_method($db, 5001, 'Credit Card',
        'images/uploads/icons/creditcard.png', 10);
    $usdId = wallos_currency_id_for_code($db, 5001, 'USD');

    // Confirm only the currency; leave the payment method out.
    $outcome = wallos_apply_default_name_localization($db, 5001, 'de', [
        'currencies' => [$usdId],
        'payment_methods' => [],
    ]);
    assert_same(1, $outcome['applied'], 'only one row was localized');
    assert_same('US-Dollar', (string) $db->scalar(
        'SELECT name FROM currencies WHERE id = :id', [':id' => $usdId]), 'the confirmed currency changed');
    assert_same('Credit Card', (string) $db->scalar(
        'SELECT name FROM payment_methods WHERE id = :id', [':id' => $creditCardId]),
        'the unconfirmed method was left in English');

    $db->close();
});

wallos_test('a user-renamed row is never a candidate and never touched', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5001, 'de-account');
    $usdId = wallos_currency_id_for_code($db, 5001, 'USD');

    // The owner renamed USD; that is user-owned data now.
    $stmt = $db->prepare('UPDATE currencies SET name = :name WHERE id = :id');
    $stmt->bindValue(':name', 'My Dollar');
    $stmt->bindValue(':id', $usdId);
    $stmt->execute();

    // A renamed generic method is likewise off-limits, even with the seed icon.
    $renamedCard = localize_add_payment_method($db, 5001, 'My Card',
        'images/uploads/icons/creditcard.png', 11);

    $index = localize_index(wallos_default_name_localization_candidates($db, 5001, 'de'));
    assert_true(!isset($index['USD']), 'the renamed currency is not offered');
    assert_true(!isset($index['payment_method_credit_card']), 'the renamed method is not offered');

    // Applying everything must leave both untouched.
    wallos_apply_default_name_localization($db, 5001, 'de');
    assert_same('My Dollar', (string) $db->scalar(
        'SELECT name FROM currencies WHERE id = :id', [':id' => $usdId]), 'the renamed currency is intact');
    assert_same('My Card', (string) $db->scalar(
        'SELECT name FROM payment_methods WHERE id = :id', [':id' => $renamedCard]), 'the renamed method is intact');

    $db->close();
});

wallos_test('a custom row and a brand are never candidates', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5001, 'de-account');

    // A currency whose code Wallos never seeds.
    $stmt = $db->prepare('INSERT INTO currencies (name, symbol, code, rate, user_id)
                          VALUES (:name, :symbol, :code, 1, :userId)');
    $stmt->bindValue(':name', 'Doubloons');
    $stmt->bindValue(':symbol', 'D');
    $stmt->bindValue(':code', 'XXX');
    $stmt->bindValue(':userId', 5001);
    $stmt->execute();

    // A brand method: literal name, brand icon, no translation key.
    $paypal = localize_add_payment_method($db, 5001, 'PayPal', 'images/uploads/icons/paypal.png', 12);

    $index = localize_index(wallos_default_name_localization_candidates($db, 5001, 'de'));
    assert_true(!isset($index['XXX']), 'a custom currency is not offered');
    assert_true(!isset($index['PayPal']), 'a brand method is not offered');

    wallos_apply_default_name_localization($db, 5001, 'de');
    assert_same('Doubloons', (string) $db->scalar(
        'SELECT name FROM currencies WHERE user_id = 5001 AND code = :c', [':c' => 'XXX']),
        'the custom currency is intact');
    assert_same('PayPal', (string) $db->scalar(
        'SELECT name FROM payment_methods WHERE id = :id', [':id' => $paypal]), 'the brand is intact');

    $db->close();
});

wallos_test('a target equal to the current name is a no-op', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5001, 'de-account');

    // The fixture's EUR row is "Euro", which is "Euro" in German too.
    $index = localize_index(wallos_default_name_localization_candidates($db, 5001, 'de'));
    assert_true(!isset($index['EUR']), 'Euro is not offered because the target equals the current name');

    $eurId = wallos_currency_id_for_code($db, 5001, 'EUR');
    wallos_apply_default_name_localization($db, 5001, 'de', ['currencies' => [$eurId], 'payment_methods' => []]);
    assert_same('Euro', (string) $db->scalar(
        'SELECT name FROM currencies WHERE id = :id', [':id' => $eurId]), 'Euro is unchanged');

    $db->close();
});

wallos_test('the rewrite is FK-safe: main_currency keeps its id', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5001, 'de-account');
    $usdId = wallos_currency_id_for_code($db, 5001, 'USD');

    // Point the account's main currency at the row about to be localized.
    $stmt = $db->prepare('UPDATE "user" SET main_currency = :cid WHERE id = :id');
    $stmt->bindValue(':cid', $usdId);
    $stmt->bindValue(':id', 5001);
    $stmt->execute();

    wallos_apply_default_name_localization($db, 5001, 'de', ['currencies' => [$usdId], 'payment_methods' => []]);

    assert_same('US-Dollar', (string) $db->scalar(
        'SELECT name FROM currencies WHERE id = :id', [':id' => $usdId]), 'the name changed');
    assert_same($usdId, (int) $db->scalar('SELECT main_currency FROM "user" WHERE id = :id', [':id' => 5001]),
        'main_currency still points at the same currency id');
    assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM currencies WHERE id = :id AND code <> :c',
        [':id' => $usdId, ':c' => 'USD']), 'the row kept its code');

    $db->close();
});

wallos_test('a row edited between preview and apply is skipped', function () {
    // Detection runs at apply time too, and the update is re-scoped to the exact
    // English name detected: a row whose name changed in between no longer
    // matches and is left alone rather than clobbered.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 5001, 'de-account');
    $usdId = wallos_currency_id_for_code($db, 5001, 'USD');

    // The user confirmed USD from the preview, then renamed it before applying.
    $stmt = $db->prepare('UPDATE currencies SET name = :name WHERE id = :id');
    $stmt->bindValue(':name', 'Renamed Since Preview');
    $stmt->bindValue(':id', $usdId);
    $stmt->execute();

    $outcome = wallos_apply_default_name_localization($db, 5001, 'de',
        ['currencies' => [$usdId], 'payment_methods' => []]);
    assert_same(0, $outcome['applied'], 'the edited row is not localized');
    assert_same('Renamed Since Preview', (string) $db->scalar(
        'SELECT name FROM currencies WHERE id = :id', [':id' => $usdId]), 'the edit is preserved');

    $db->close();
});
