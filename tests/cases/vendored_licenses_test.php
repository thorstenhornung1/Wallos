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
