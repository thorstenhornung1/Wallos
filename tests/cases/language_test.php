<?php
/*
  Language identifiers are canonical BCP-47 tags. Legacy values stay readable —
  cookies and stored preferences from before the change must keep working — but
  only canonical values are ever written.
*/

require_once WALLOS_ROOT . '/includes/i18n/languages.php';

wallos_test('canonical tags resolve to themselves', function () {
    foreach (['en', 'de', 'pt', 'pt-BR', 'sr', 'sr-Latn', 'zh-CN', 'zh-TW', 'ja'] as $tag) {
        assert_same($tag, wallos_resolve_language($tag), $tag . ' is canonical');
    }
});

wallos_test('legacy identifiers keep working', function () {
    // What earlier versions stored in user.language and in the cookie.
    assert_same('pt-BR', wallos_resolve_language('pt_br'), 'pt_br');
    assert_same('sr-Latn', wallos_resolve_language('sr_lat'), 'sr_lat');
    assert_same('zh-CN', wallos_resolve_language('zh_cn'), 'zh_cn');
    assert_same('zh-TW', wallos_resolve_language('zh_tw'), 'zh_tw');
    assert_same('ja', wallos_resolve_language('jp'), 'jp');
});

wallos_test('locales from browsers and identity providers resolve', function () {
    // What an OIDC locale claim or an Accept-Language header looks like.
    assert_same('de', wallos_resolve_language('de-DE'), 'de-DE falls back to the base language');
    assert_same('de', wallos_resolve_language('de_AT'), 'de_AT too');
    assert_same('fr', wallos_resolve_language('fr-CA'), 'fr-CA');
    assert_same('en', wallos_resolve_language('en-US'), 'en-US');
    assert_same('pt-BR', wallos_resolve_language('pt-br'), 'lower case region is normalised');
    assert_same('zh-CN', wallos_resolve_language('zh-hans'), 'script subtag maps to the region variant');
});

wallos_test('an unsupported value falls back', function () {
    assert_same('en', wallos_resolve_language('kl-GL'), 'an unsupported language falls back to English');
    assert_same('de', wallos_resolve_language('kl-GL', 'de'), 'or to the supplied fallback');
    assert_same('en', wallos_resolve_language(null), 'null falls back');
    assert_same('en', wallos_resolve_language(''), 'an empty value falls back');
    assert_same('en', wallos_resolve_language('   '), 'whitespace falls back');
    assert_same('en', wallos_resolve_language('../../etc/passwd'), 'a path never resolves');
});

wallos_test('the result is always loadable', function () {
    // The point of the resolver: callers use the result to build a file path,
    // so it must exist in the registry and on disk.
    $supported = wallos_languages();

    $inputs = ['de-DE', 'pt_br', 'zh_tw', 'jp', 'sr_lat', 'nonsense', '', null, 'ZH-cn'];

    foreach ($inputs as $input) {
        $resolved = wallos_resolve_language($input);

        assert_true(isset($supported[$resolved]),
            var_export($input, true) . ' resolves into the registry (' . $resolved . ')');
        assert_true(is_file(WALLOS_ROOT . '/includes/i18n/' . $resolved . '.php'),
            $resolved . '.php exists');
        assert_true(is_file(WALLOS_ROOT . '/scripts/i18n/' . $resolved . '.js'),
            $resolved . '.js exists');
    }
});

wallos_test('every registered language has both translation files', function () {
    foreach (array_keys(wallos_languages()) as $code) {
        assert_true(is_file(WALLOS_ROOT . '/includes/i18n/' . $code . '.php'), $code . '.php exists');
        assert_true(is_file(WALLOS_ROOT . '/scripts/i18n/' . $code . '.js'), $code . '.js exists');
    }
});

wallos_test('tag normalisation follows BCP-47 casing', function () {
    assert_same('zh-CN', wallos_normalize_language_tag('zh_cn'), 'region upper case');
    assert_same('sr-Latn', wallos_normalize_language_tag('sr-LATN'), 'script title case');
    assert_same('de', wallos_normalize_language_tag('DE'), 'language lower case');
});
