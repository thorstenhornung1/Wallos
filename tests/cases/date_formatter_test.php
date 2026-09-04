<?php
/*
  formatDate() builds one IntlDateFormatter per (language, pattern) for the
  whole request instead of one per rendered row, and the reused formatter
  produces exactly what a freshly constructed one would. This pins both claims:
  the same object comes back for the same (language, pattern), and its output
  equals a fresh formatter's for a spread of dates.

  The formatter needs the intl extension. dev/test.sh's runner image ships
  without it, so the case stands aside there rather than asserting behaviour the
  backend cannot exercise — the same stance the SQLite/PostgreSQL splits take.
*/

require_once WALLOS_ROOT . '/includes/date_formatter.php';

/**
 * Skips the current case when the intl extension is unavailable.
 *
 * @param string $reason
 * @return bool whether the case should stop
 */
function date_formatter_skip_unless_intl($reason)
{
    if (class_exists('IntlDateFormatter')) {
        return false;
    }

    $GLOBALS['wallos_test_skipped'][] = [
        'test' => $GLOBALS['wallos_test_current'],
        'reason' => $reason,
    ];

    return true;
}

wallos_test('one formatter answers a whole request per language and pattern', function () {
    if (date_formatter_skip_unless_intl('IntlDateFormatter needs the intl extension')) {
        return;
    }

    $first = wallos_date_formatter('en', 'MMM d');
    $again = wallos_date_formatter('en', 'MMM d');
    assert_true($first === $again,
        'the same language and pattern return the same formatter instance');

    $otherPattern = wallos_date_formatter('en', 'MMM yyyy');
    assert_true($first !== $otherPattern,
        'a different pattern is a different formatter');
});

wallos_test('the reused formatter formats exactly like a freshly built one', function () {
    if (date_formatter_skip_unless_intl('IntlDateFormatter needs the intl extension')) {
        return;
    }

    // Both patterns formatDate() ever uses, over dates in and out of the current
    // year. A reused formatter must return what a per-call one returned, because
    // that is the change: the construction moved, the output did not.
    foreach (['MMM d', 'MMM yyyy'] as $pattern) {
        $cached = wallos_date_formatter('en', $pattern);
        $fresh = new IntlDateFormatter('en', IntlDateFormatter::SHORT, IntlDateFormatter::NONE, null, null, $pattern);

        foreach (['2026-01-15', '2026-12-31', '2020-06-01', '1999-02-28'] as $date) {
            assert_same(
                $fresh->format(new DateTime($date)),
                $cached->format(new DateTime($date)),
                'reused and fresh formatters agree for ' . $pattern . '/' . $date
            );
        }
    }
});
