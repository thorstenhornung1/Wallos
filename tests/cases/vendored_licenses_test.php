<?php
/*
  The licences of the libraries Wallos ships inside its own image.

  Wallos is GPL-3.0, so every vendored file has to be under a licence that
  permits that. ApexCharts changed its licensing model with the 5.x line, and
  5.15.2 had been vendored here — a routine "update the chart library" is all it
  takes to reintroduce that, because a newer release is not automatically a
  permitted one and nothing about the file says so at a glance (issue #171).

  These cases are cheap and they fail loudly, which is what a licence
  constraint needs: it cannot be noticed by testing behaviour, since the wrong
  licence works perfectly well.
*/

wallos_test('the bundled chart library is the MIT-licensed ApexCharts release', function () {
    $path = WALLOS_ROOT . '/scripts/libs/apexcharts.min.js';
    $source = file_get_contents($path);
    assert_true($source !== false && $source !== '', 'the library is present');

    // The banner comment the library ships with, which names version and licence.
    $banner = substr($source, 0, 400);

    assert_contains('ApexCharts v4.7.0', $banner,
        'the pinned 4.7.0 release is the one on disk');
    assert_contains('Released under the MIT License', $banner,
        'and it is the MIT-licensed build — 5.x drops this line');
    assert_not_contains('ApexCharts v5', $banner,
        'no 5.x build, whose licence GPL-3.0 cannot carry');
});

wallos_test('the vendored library is documented where licences are collected', function () {
    $licenses = file_get_contents(WALLOS_ROOT . '/THIRD_PARTY_LICENSES.md');

    assert_contains('## ApexCharts', $licenses, 'ApexCharts has an entry');
    assert_contains('4.7.0', $licenses, 'the entry names the pinned version');
    assert_contains('SPDX-License-Identifier: MIT', $licenses, 'and its licence');
    assert_contains('The MIT License (MIT)', $licenses,
        'the licence text itself is shipped, as MIT requires');
});

wallos_test('every Composer package Wallos ships is under a licence GPL-3.0 can carry', function () {
    // vendor/ is committed on the webpush_external branch, so twenty packages
    // now ship inside the image that nobody reviewed one file at a time. The
    // licence is the part that cannot be noticed by testing behaviour: a
    // dependency bumped to an AGPL or a "source available" release works
    // perfectly and is a licence violation. composer.lock records the licence
    // each package declares, so the gate reads that rather than a list somebody
    // has to remember to update.
    $lock = json_decode(file_get_contents(WALLOS_ROOT . '/composer.lock'), true);
    assert_true(is_array($lock['packages'] ?? null), 'composer.lock lists the installed packages');

    // Permissive licences a GPL-3.0 work may include. Deliberately short: a
    // licence that is not on it is a decision, not an oversight.
    $permitted = ['MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'ISC'];

    $offenders = [];
    foreach ($lock['packages'] as $package) {
        foreach ((array) ($package['license'] ?? []) as $license) {
            if (!in_array($license, $permitted, true)) {
                $offenders[] = $package['name'] . ' is ' . $license;
            }
        }

        if (($package['license'] ?? []) === []) {
            $offenders[] = $package['name'] . ' declares no licence';
        }
    }

    assert_same([], $offenders, 'no package carries a licence GPL-3.0 cannot include');

    // And the tree on disk is the tree the lock describes, so the check is not
    // reading a file that has nothing to do with what ships.
    foreach ($lock['packages'] as $package) {
        assert_true(is_dir(WALLOS_ROOT . '/vendor/' . $package['name']),
            $package['name'] . ' is installed, not only locked');
    }
});

wallos_test('the Composer tree is documented where licences are collected', function () {
    $licenses = file_get_contents(WALLOS_ROOT . '/THIRD_PARTY_LICENSES.md');
    $lock = json_decode(file_get_contents(WALLOS_ROOT . '/composer.lock'), true);

    assert_contains('## Composer dependencies', $licenses, 'the vendored tree has an entry');
    assert_contains('minishlink/web-push', $licenses, 'the direct dependency is named');

    // Every package by name, so a new transitive dependency cannot arrive
    // undocumented — the thing that actually happens when a lock file is bumped.
    $missing = [];
    foreach ($lock['packages'] as $package) {
        if (strpos($licenses, $package['name']) === false) {
            $missing[] = $package['name'];
        }
    }

    assert_same([], $missing, 'every installed package is named in the document');
});
