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

/**
 * Records a provider request the way every other one is recorded (#106).
 *
 * The user id is unused for an instance credential and passed as zero rather
 * than borrowing somebody's account for the bookkeeping.
 *
 * @param object  $db  the boundary connection; not named after one backend
 * @param array   $config
 * @param array   $answer
 */
function wallos_probe_account($db, $config, $answer)
{
    if (empty($answer['transport'])) {
        return;
    }

    wallos_count_currency_call($db, $config, 0);
    wallos_store_currency_usage($db, $config, 0, $answer['usage']);
}

/**
 * Whether a refusal belongs to the credential rather than to the symbols.
 *
 * A quota, a rejected key, an outage or a fault of the provider's own would
 * have refused any request at all, so such an answer says nothing about
 * unknown symbols. fixer signals an unknown code with error 202 over HTTP 200,
 * which is exactly the case this leaves alone.
 *
 * @param array $answer
 * @return bool
 */
function wallos_probe_refusal_is_about_the_key($answer)
{
    $status = $answer['status'] ?? null;

    return $status === null || $status === 401 || $status === 403
        || $status === 429 || $status >= 500;
}

// The control, and it goes first for a reason beyond method: a refusal is
// cached for the rest of the run and served to any request whose symbols it
// covers (#117), so asking for the smaller list afterwards would be answered
// from the cache and prove nothing. Asked first, it is a real request, and the
// probe below is a superset of it and so is a real request too.
$control = wallos_fetch_exchange_rates($config, 'EUR,USD');
wallos_probe_account($db, $config, $control);

if (!$control['success']) {
    // Nothing is learned and nothing is written down. A stored verdict stops
    // this asking again, so storing one now would cement an answer to a
    // question that was never put — which is how the first version of this
    // probe reported a monthly quota as proof that unknown symbols take a
    // request down.
    $detail = 'inconclusive: the provider refused a request with no unknown '
        . 'symbol in it, so its refusal is about the credential rather than '
        . 'the symbols. Nothing was recorded; this asks again on the next '
        . 'start. It said: ' . $control['message'];

    wallos_cron_count('inconclusive');
    error_log('[Wallos provider probe] INCONCLUSIVE: ' . $detail);
    wallos_cron_done($detail);
    $db->close();

    return;
}

// Two real codes so the answer is about the unknown one rather than about an
// empty request, and the unknown one last so it cannot be mistaken for the
// base currency.
$answer = wallos_fetch_exchange_rates($config, 'EUR,USD,' . WALLOS_PROBE_CODE);
wallos_probe_account($db, $config, $answer);

if (!$answer['success'] && wallos_probe_refusal_is_about_the_key($answer)) {
    // The control worked and this did not, but the reason is one that would
    // have refused anything — a quota reached between the two requests, a
    // rate limit, the provider falling over. Still not an answer about
    // symbols.
    $detail = 'inconclusive: the control request succeeded but the probe was '
        . 'refused for a reason that is not about the symbols. Nothing was '
        . 'recorded; this asks again on the next start. It said: '
        . $answer['message'];

    wallos_cron_count('inconclusive');
    error_log('[Wallos provider probe] INCONCLUSIVE: ' . $detail);
    wallos_cron_done($detail);
    $db->close();

    return;
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
    // The control was priced and this was not, and the refusal is not one of
    // the credential-level kinds. The unknown symbol is the only difference
    // between the two requests.
    $verdict = 'refused';
    $detail = 'the same request succeeded without the unknown symbol and was '
        . 'refused with it, so one account can stop rate refreshes for every '
        . 'account sharing the credential (issue 135 is reachable): '
        . $answer['message'];
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
