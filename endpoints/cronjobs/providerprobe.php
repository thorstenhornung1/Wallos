<?php
/*
  What does the rate provider do with a currency code it does not know?

  The answer decides how bad #135 is, and it cannot be read out of the code —
  it is the provider's behaviour, not ours. There are two possibilities and
  they are far apart:

    * it drops the unknown symbol and prices the rest. Then an invented
      currency sits at the rate 1 it was seeded with and goes into every total
      on the dashboard, the statistics page and the calendar, indistinguishable
      on screen from a correct number (#133). Bad, and quiet.

    * it refuses the whole request. Then — since the scheduled refresh fetches
      the union of every due account's symbols in one call (#9), and the
      refusal cache serves that one answer to every subset (#117) — one
      account's typo stops rate refreshes for every account sharing the
      instance credential, indefinitely, because no freshness row is written
      and tomorrow's run rebuilds the same union (#135). Worse, and equally
      quiet.

  This asks, once, and writes down which one it is.

  It touches no user data. An earlier plan added an invented currency to a real
  account, reset the freshness rows and waited for a scheduled run; that is a
  lot of moving parts to answer a question one request answers. The union is
  not what is being tested — the union is ours and we can read it. What is
  unknown is what the provider does when an unknown symbol is in the list, and
  a list with an unknown symbol in it is enough to find out.

  It runs from startup.sh rather than the crontab, and it runs once: the
  verdict is stored, and a stored verdict means it never asks again. One
  provider request, ever, per installation.
*/

require_once __DIR__ . '/../../includes/cron_run.php';
wallos_cron_begin('providerprobe');

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/integration_config.php';
require_once __DIR__ . '/../../includes/currency_provider.php';
wallos_cron_database($db);

/** A code no provider can know: unassigned in ISO 4217 and not a fixer symbol. */
const WALLOS_PROBE_CODE = 'ZQX';

$settings = wallos_get_instance_settings($db, 'currency');

if (!empty($settings['probe_verdict'])) {
    wallos_cron_done('already answered: ' . $settings['probe_verdict']);
    $db->close();

    return;
}

$config = wallos_get_instance_currency_config($db);

if (empty($config['valid'])) {
    // Not a failure. An installation with no currency provider has nothing to
    // probe, and saying so is the honest outcome — but no verdict is stored,
    // so an installation that gains a key later still gets asked.
    wallos_cron_done('no currency provider configured, nothing to ask');
    $db->close();

    return;
}

// Two real codes so the answer is about the unknown one rather than about an
// empty request, and the unknown one last so it cannot be mistaken for the
// base currency.
$codes = 'EUR,USD,' . WALLOS_PROBE_CODE;
$answer = wallos_fetch_exchange_rates($config, $codes);

if (!empty($answer['transport'])) {
    // A real provider request like any other, counted like any other (#106).
    // The user id is unused for an instance credential and passed as zero
    // rather than borrowing somebody's account for the bookkeeping.
    wallos_count_currency_call($db, $config, 0);
    wallos_store_currency_usage($db, $config, 0, $answer['usage']);
}

if ($answer['success']) {
    $priced = array_change_key_case($answer['rates'] ?? [], CASE_UPPER);
    $known = array_key_exists(WALLOS_PROBE_CODE, $priced);

    $verdict = $known ? 'priced' : 'ignored';
    $detail = $known
        // Nothing expects this. If it happens, the probe code is not as
        // unassigned as believed and the whole answer is about the wrong thing.
        ? 'the provider returned a rate for ' . WALLOS_PROBE_CODE
            . ', so this code is not unknown to it and the probe proved nothing'
        : 'the provider dropped the unknown symbol and priced the rest, so an '
            . 'invented code stays at its seeded rate instead of stopping the '
            . 'refresh (issue 135 is latent, issue 133 is the live one)';
} else {
    $verdict = 'refused';
    $detail = 'the provider refused the whole request over one unknown symbol, '
        . 'so one account can stop rate refreshes for every account sharing the '
        . 'credential (issue 135 is reachable): ' . $answer['message'];
}

wallos_set_instance_setting($db, 'currency', 'probe_verdict', $verdict);
wallos_set_instance_setting($db, 'currency', 'probe_answered_at', gmdate('Y-m-d H:i:s'));
wallos_set_instance_setting($db, 'currency', 'probe_detail', $detail);

wallos_cron_count($verdict);

// Into the container log as well as the row, because this is the one thing
// this release exists to find out and nobody should have to open a database
// to read it.
error_log('[Wallos provider probe] ' . strtoupper($verdict) . ': ' . $detail);

wallos_cron_done($verdict . ' — ' . $detail);
$db->close();
