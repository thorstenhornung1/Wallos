<?php
/*
  Every logo fetch checks for the failure it can get back (#127; upstream
  #1185).

  getLogoFromUrl exists as four local copies, and they drifted: some return
  an array either way, the payment-method ones return a filename string on
  success and a failure ARRAY on error. endpoints/payments/add.php used the
  result unchecked, bound the array into the icon column, and the frontend
  read "Unknown error" — reported upstream as AMEX/Discover failing, which
  is just a logo saveLogo cannot rasterise. The subscription endpoints
  always checked; this pins the check on every call site, whatever shape
  the local copy returns.
*/

wallos_test('every getLogoFromUrl call site checks the failure array', function () {
    $callers = [
        'endpoints/payments/add.php',
        'endpoints/subscription/add.php',
        'api/payment_methods/set_payment_methods.php',
        'api/subscriptions/set_subscriptions.php',
    ];

    $unchecked = [];
    $sites = 0;

    foreach ($callers as $path) {
        $lines = file(WALLOS_ROOT . '/' . $path);

        foreach ($lines as $number => $line) {
            if (strpos($line, '= getLogoFromUrl(') === false) {
                continue;
            }

            $sites++;
            $window = implode('', array_slice($lines, $number + 1, 6));

            if (strpos($window, 'is_array(') === false && strpos($window, "['success']") === false) {
                $unchecked[] = $path . ':' . ($number + 1);
            }
        }
    }

    assert_true($sites >= 6, 'all known call sites were seen (got ' . $sites . ')');
    assert_same([], $unchecked, 'call sites using the result unchecked');
});
