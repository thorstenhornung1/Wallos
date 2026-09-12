<?php
/*
  The theme cookies, on their way into an inline script.

  `theme` and `colorTheme` are set by the browser and read straight back out
  into a <script> block on every page that renders before a session exists.
  Written as `window.colorTheme = "<?= $colorTheme ?>"`, a cookie value
  containing a quote closes the string and the rest is script — reflected XSS
  against whoever can put a cookie on the origin.

  Upstream 5.5.0 fixed this by validating both cookies against a fixed list
  and encoding what is emitted. It fixed login.php, totp.php and the
  application header, and missed registration.php — the one page of the four
  that is reachable with no account at all. This gate is why that is now a
  fact rather than a coincidence: it finds the pages by looking for the
  emission, so a fifth one added later is covered without this list being
  touched.
*/

wallos_test('no page writes a theme cookie into a script unencoded', function () {
    $emitters = [];

    // includes/header.php emits the same pair for every page behind a session,
    // so it belongs in the search rather than outside it: upstream's own copy
    // of this gate found it, and a list of root pages never would.
    $candidates = array_merge(glob(WALLOS_ROOT . '/*.php'), [WALLOS_ROOT . '/includes/header.php']);

    foreach ($candidates as $file) {
        $source = file_get_contents($file);
        $path = str_replace(WALLOS_ROOT . '/', '', $file);

        // The emission, whatever the variable is called on that page.
        if (preg_match('/window\.(color_?[Tt]heme|theme)\s*=/', $source) !== 1) {
            continue;
        }

        $emitters[] = $path;

        assert_true(preg_match('/window\.(color_?[Tt]heme|theme)\s*=\s*"/', $source) !== 1,
            $path . ' does not interpolate a theme value into a quoted string');
        assert_contains('json_encode($colorTheme, JSON_HEX_TAG', $source,
            $path . ' encodes what it emits');
    }

    // A gate that finds nothing passes every assertion above it.
    assert_true(count($emitters) >= 4,
        'the emitting pages were found: ' . implode(', ', $emitters));
});

wallos_test('every page reading a theme cookie validates it against a list', function () {
    // The encoding above stops the injection; this stops the value reaching
    // the stylesheet ids and the theme-colour meta tag as something that is
    // not one of the themes.
    $readers = [];

    $candidates = array_merge(glob(WALLOS_ROOT . '/*.php'), [WALLOS_ROOT . '/includes/getsettings.php']);

    foreach ($candidates as $file) {
        $source = file_get_contents($file);
        $path = str_replace(WALLOS_ROOT . '/', '', $file);

        foreach ([
            "\$_COOKIE['theme']" => 'sanitize_theme_mode',
            "\$_COOKIE['colorTheme']" => 'sanitize_color_theme',
            "\$_COOKIE['inUseTheme']" => 'sanitize_resolved_theme',
        ] as $cookie => $sanitizer) {
            if (strpos($source, $cookie) === false) {
                continue;
            }

            $readers[] = $path . ':' . $cookie;

            // The cookie may carry a default on the way in —
            // sanitize_theme_mode($_COOKIE['theme'] ?? null) — which is the
            // same read, validated. manifest.php writes it that way because it
            // serves requests that have no cookie at all.
            $call = '/' . preg_quote($sanitizer, '/') . '\s*\(\s*'
                . preg_quote($cookie, '/') . '\s*(?:\?\?[^)]*)?\)/';

            assert_true(preg_match($call, $source) === 1,
                $path . ' validates ' . $cookie . ' before using it');
        }
    }

    assert_true(count($readers) >= 6,
        'the readers were found rather than the glob quietly matching nothing ('
        . count($readers) . ' found)');
});
