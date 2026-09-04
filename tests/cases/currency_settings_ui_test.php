<?php
/*
  The settings page half of the Frankfurter provider (#140).

  Three claims, each of which fails silently if it stops being true.

  A provider that needs no credential is configured by being chosen. Everything
  in Wallos used to read "has an api_key" as "is configured", so a complete
  Frankfurter configuration looked exactly like an abandoned one — and the
  update job reports "no currency provider configured" and exits zero.

  Choosing it must not cost anyone their Fixer key. Saving used to mean DELETE
  followed by INSERT, and an empty key meant "clear my credentials". Both are
  correct for a provider that authenticates; both would quietly throw away the
  key of somebody who selected Frankfurter, looked at it, and switched back.

  And the usage area must not invent a quota. There are three states, not two:
  apilayer reports a figure, fixer.io has a quota and reports nothing, and
  Frankfurter has no quota at all. A bar drawn for the second or the third says
  something nobody measured — the failure of #104, which spent a 100-call tier
  behind an empty progress track that read as reassurance.
*/

require_once WALLOS_ROOT . '/includes/integration_config.php';

wallos_test('the provider spellings include the one that needs no key', function () {
    assert_same(0, wallos_parse_currency_provider('fixer'), 'fixer');
    assert_same(1, wallos_parse_currency_provider('apilayer'), 'apilayer');
    assert_same(2, wallos_parse_currency_provider('frankfurter'), 'frankfurter by name');
    assert_same(2, wallos_parse_currency_provider('2'), 'frankfurter by id');
    assert_same(null, wallos_parse_currency_provider('nonesuch'), 'and nothing else');

    assert_true(wallos_currency_provider_needs_key(0), 'fixer.io authenticates');
    assert_true(wallos_currency_provider_needs_key(1), 'apilayer authenticates');
    assert_true(!wallos_currency_provider_needs_key(2), 'Frankfurter does not');

    assert_true(wallos_currency_provider_has_quota(0), 'fixer.io meters requests');
    assert_true(wallos_currency_provider_has_quota(1), 'apilayer meters requests');
    assert_true(!wallos_currency_provider_has_quota(2), 'Frankfurter does not');
});

wallos_test('a submitted configuration with no key is complete, or missing, by provider', function () {
    $frankfurter = wallos_currency_config_from_input(2, '');
    assert_true($frankfurter['valid'], 'Frankfurter needs nothing else');
    assert_same(2, $frankfurter['values']['provider'], 'and is stored as itself');

    $fixer = wallos_currency_config_from_input(0, '');
    assert_true(!$fixer['valid'], 'fixer.io without a key is still incomplete');

    $apilayer = wallos_currency_config_from_input(1, '');
    assert_true(!$apilayer['valid'], 'apilayer without a key too');
});

wallos_test('a stored Frankfurter row is usable, and the key beside it survives', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    // Alice has a Fixer key and is looking at Frankfurter. This is the row the
    // save endpoint leaves behind: the provider changed, the key did not.
    $stmt = $db->prepare("INSERT INTO fixer (api_key, provider, provider_mode, user_id)
                          VALUES ('alice-fixer-key', 2, 'custom', 1)");
    $stmt->execute();

    // Bob never had a key at all, which is the whole point of the provider and
    // the case that used to read as an abandoned configuration.
    $stmt = $db->prepare("INSERT INTO fixer (api_key, provider, provider_mode, user_id)
                          VALUES ('', 2, 'custom', 2)");
    $stmt->execute();
    wallos_reset_config_cache($db);

    $config = wallos_get_effective_currency_config($db, 1);

    assert_same('custom', $config['mode'], 'her own choice, not the instance');
    assert_same(2, (int) $config['values']['provider'], 'Frankfurter');
    assert_true($config['valid'], 'and it is a configuration the update job will use');
    assert_same('alice-fixer-key', $config['values']['api_key'],
        'the Fixer key is still there to switch back to');

    $bob = wallos_get_effective_currency_config($db, 2);

    assert_same('custom', $bob['mode'], 'an empty key is not a fall back to the instance');
    assert_same(2, (int) $bob['values']['provider'], 'Frankfurter');
    assert_true($bob['valid'], 'no key is a complete configuration here, not a missing one');
    assert_true(empty($bob['notes']), 'and nothing complains: ' . implode(' / ', $bob['notes']));

    $db->close();
});

wallos_test('an instance on Frankfurter is configured, not "not configured"', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    putenv('WALLOS_CURRENCY_PROVIDER=frankfurter');
    wallos_reset_config_cache($db);

    $config = wallos_get_instance_currency_config($db);

    assert_same(2, (int) $config['values']['provider'], 'the environment names it');
    assert_true($config['valid'], 'and no key is missing, because none is asked for');
    assert_true(empty($config['notes']), 'nothing to complain about: ' . implode(' / ', $config['notes']));

    wallos_test_reset_env();
    wallos_reset_config_cache($db);
    $db->close();
});

wallos_test('the settings page offers Frankfurter and says what it cannot price', function () {
    $page = file_get_contents(WALLOS_ROOT . '/settings.php');

    assert_true(strpos($page, 'value="2"') !== false, 'the provider select carries the id');
    assert_true(strpos($page, 'Frankfurter') !== false, 'and its name');
    assert_not_contains('<h2>Fixer API Key</h2>', $page,
        'the section is no longer named after one provider');
    assert_true(strpos($page, "translate('exchange_rates'") !== false,
        'it is named after what it configures');

    assert_true(strpos($page, 'currencyApiKeyField') !== false,
        'the key field can be hidden as a whole');
    assert_true(strpos($page, 'frankfurter_no_crypto') !== false,
        'the one real limitation is on the page, not in a commit message');

    // The honest description and the caveat both have to exist in English at
    // minimum; translate() falls back to en.php for every other language.
    // en.php assigns $i18n rather than returning it, so it is loaded into a
    // scope of its own instead of over whatever the caller is holding.
    $english = (static function () {
        require WALLOS_ROOT . '/includes/i18n/en.php';

        return $i18n;
    })();

    foreach (['exchange_rates', 'frankfurter_info', 'frankfurter_no_crypto',
              'currency_provider_without_quota'] as $key) {
        assert_true(isset($english[$key]) && $english[$key] !== '', $key . ' has English text');
    }

    assert_contains('BTC', $english['frankfurter_no_crypto'], 'the caveat names the codes');
    assert_contains('XAU', $english['frankfurter_no_crypto'], 'including the ones that are present');
});

wallos_test('choosing a keyless provider never deletes or rewrites the stored key', function () {
    $endpoint = file_get_contents(WALLOS_ROOT . '/endpoints/currency/fixer_api_key.php');

    $start = strpos($endpoint, 'if ($keylessProvider) {');
    assert_true($start !== false, 'the keyless path exists');

    // Everything up to the next top-level branch. The empty-key DELETE is what
    // this has to come before: an empty key is how a keyless provider arrives.
    $end = strpos($endpoint, 'if ($newApiKey === "") {');
    assert_true($end !== false && $end > $start,
        'and is decided before an empty key is read as "clear my credentials"');

    $branch = substr($endpoint, $start, $end - $start);

    assert_not_contains('DELETE', $branch, 'the branch deletes nothing');
    assert_not_contains('api_key =', $branch, 'and assigns no key');
    assert_contains('UPDATE fixer SET provider', $branch, 'it moves the provider');
    assert_contains("provider_mode = 'custom'", $branch, 'and the mode with it');

    $script = file_get_contents(WALLOS_ROOT . '/scripts/settings.js');
    assert_contains('currencyProviderNeedsKey', $script, 'the page knows which providers need a key');
    assert_not_contains('fixerKey").value = ""', $script, 'and never empties the field to hide it');
});

wallos_test('the usage area keeps three quota states apart and draws one bar', function () {
    $endpoint = file_get_contents(WALLOS_ROOT . '/endpoints/settings/fixer_usage.php');
    $page = file_get_contents(WALLOS_ROOT . '/settings.php');
    $script = file_get_contents(WALLOS_ROOT . '/scripts/settings.js');

    assert_contains('has_quota', $endpoint, 'the endpoint reports whether a quota exists at all');
    assert_contains('provider_reports', $endpoint, 'separately from whether it is reported');
    assert_not_contains('wallos_provider_http_get', $endpoint,
        'and determines neither by spending a request');

    assert_contains('fixerUsageNone', $page, 'the page can say there is no quota');
    assert_contains('fixerUsageUnknown', $page, 'and that there is one nobody reports');
    assert_contains('currency_provider_without_quota', $page, 'in those words');

    assert_contains('has_quota', $script, 'the renderer reads it');
    assert_contains('showLine("fixerUsageNone", !hasQuota)', $script,
        'and shows the no-quota line for exactly that case');

    // The bar is drawn from a figure or not at all. quotaKnown is that figure
    // arriving; nothing else may open the track.
    assert_contains('const quotaKnown = reportsQuota && !!data.total;', $script,
        'a bar needs a reported figure');
    assert_contains('if (quotaKnown) {', $script, 'and is drawn only then');
});
