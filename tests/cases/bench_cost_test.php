<?php
/*
  What the benchmark spends without being asked.

  Every figure dev/benchmark.sh produces is free except one. The rates column
  runs endpoints/cronjobs/updateexchange.php once per tier at 1, 10 and 100
  accounts, five runs each — on the order of 555 requests to whatever provider
  is configured. The free tier of that provider is a hundred a month, so one
  unguarded run spends half a year of quota, and the counter belongs to the
  account rather than to the key, so replacing the key afterwards does not give
  it back (issue #104).

  The old rule was "measure if the provider answers", which made an expensive
  run the reward for having a working key: a tester who followed the plan, found
  the key working, and proceeded, was doing everything right and still spending
  six months of quota. Documenting the cost does not prevent it — a run that
  cannot be started by accident does.

  So the column is opt-in. What follows guards the default, because the default
  is the whole protection: an --opt-in flag that someone later gives a default of
  1 for convenience puts the cost back with nothing to notice it.
*/

/**
 * @return string
 */
function bench_cost_source()
{
    return file_get_contents(WALLOS_ROOT . '/dev/benchmark.sh');
}

wallos_test('the paid column is off unless asked for', function () {
    $source = bench_cost_source();

    assert_contains('RATES_ENABLED=0', $source, 'the default is off');
    assert_not_contains('RATES_ENABLED=1' . "\n", $source,
        'nothing sets it on at the top level — only the flag does');
    assert_contains('--rates)', $source, 'and there is a flag to turn it on');
});

wallos_test('asking whether it could measure is itself a request', function () {
    // rates-preflight makes a live call to decide whether the column is
    // possible. Running it unconditionally means the "off" path still spends
    // one of the hundred, which is the kind of leak that shows up as a quota
    // gone missing with nothing to point at.
    $source = bench_cost_source();

    $guard = strpos($source, 'if [ "$RATES_ENABLED" = "1" ]');
    $preflight = strpos($source, 'rates-preflight');

    assert_true($guard !== false, 'the preflight is guarded');
    assert_true($preflight !== false && $guard < $preflight,
        'and the guard comes before it, not after');
});

wallos_test('the run says what it spent or how to spend it', function () {
    // Either branch has to leave the reader knowing where they stand: a run
    // that measured should say the cost, and one that did not should say what
    // it skipped rather than let a blank column look like a zero.
    $source = bench_cost_source();

    assert_contains('not-requested', $source, 'the skipped case has its own verdict');
    assert_contains('--rates to measure it', $source, 'and says how to get the column');
    assert_contains('555', $source, 'both places name the price');
});
