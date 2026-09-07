<?php
/*
  The release metadata itself, checked the way any other output is checked.

  5.16.1 shipped with an empty includes/version.php. Nothing noticed: the file
  is not code anybody calls, the suite was green, the image built, and the tag
  carried the empty file. It surfaced on the running instance as PHP warnings
  written into the asset URLs, because header.php builds `?v<?= $version ?>`
  and $version was undefined.

  What makes this worth a gate rather than more care next time: with
  display_errors off — every normal installation — the query string would have
  been silently empty instead, identical for every version, so the cache buster
  would never bust anything again. A CSS fix would simply not reach anyone
  holding a warm browser cache, with nothing anywhere to indicate why. The
  failure is invisible exactly where it does the most damage.

  `build` depends on `test`, so a release that gets this wrong now stops before
  an image exists.
*/

wallos_test('the version file declares a usable version', function () {
    $path = WALLOS_ROOT . '/includes/version.php';

    $source = file_get_contents($path);
    assert_true($source !== false && trim($source) !== '',
        'includes/version.php is not empty');

    assert_true((bool) preg_match('/\$version\s*=\s*"(v[0-9]+\.[0-9]+\.[0-9]+)"\s*;/', $source, $match),
        'includes/version.php declares $version as "vMAJOR.MINOR.PATCH"');

    // The value has to survive being included, because that is how every page
    // gets it — a file that parses but leaves $version unset breaks the same way
    // an empty one does.
    $version = null;
    require $path;
    assert_true(isset($version) && $version !== '',
        'including the file actually sets $version');
    assert_same($match[1], $version, 'the declared version is the one that is set');
});

wallos_test('the version matches the changelog it was released with', function () {
    // A release that forgets the version file, or edits one of the two and not
    // the other, ships an image whose $version names a different release than
    // its own changelog. Both are written by the same release step, so they
    // must agree.
    $version = null;
    require WALLOS_ROOT . '/includes/version.php';

    $changelog = file_get_contents(WALLOS_ROOT . '/CHANGELOG.md');
    assert_true((bool) preg_match('/^## \[([0-9]+\.[0-9]+\.[0-9]+)\]/m', $changelog, $match),
        'the changelog names a most recent release');

    assert_same('v' . $match[1], $version,
        'includes/version.php and the newest changelog entry name the same release');
});
