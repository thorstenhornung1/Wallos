<?php
require_once __DIR__ . '/../../includes/cron_run.php';
wallos_cron_begin('updateexchange');

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/currency_provider.php';
wallos_cron_database($db);

require 'settimezone.php';

// Insisting on a refresh that already happened today, from the command line
// only. The daily skip exists because this job also runs on every container
// start, so deploy frequency alone could exhaust a free provider tier (#117),
// and the web endpoint has had a force parameter all along — but a session is
// exactly what an operator diagnosing a provider does not have.
//
// Recorded in the run detail rather than left to be inferred: a forced run
// replaces the scheduled run's row in cron_runs (#136), and "updated=4" from a
// run somebody triggered by hand reads as the nightly one having worked.
$force = php_sapi_name() === 'cli' && in_array('--force', $argv ?? [], true);

if ($force) {
    wallos_cron_count('forced');
    echo "Forced: rates already refreshed today are fetched again.<br />\n";
}

if (php_sapi_name() == 'cli') {
    $date = new DateTime('now');
    echo "\n" . $date->format('Y-m-d') . " " . $date->format('H:i:s') . "<br />\n";
}

$query = "SELECT id, username FROM \"user\"";
$stmt = $db->prepare($query);
$usersToUpdateExchange = $stmt === false ? false : $stmt->execute();

if ($usersToUpdateExchange === false) {
    wallos_cron_fail('could not read the user list: ' . wallos_cron_reason($db));
}

// Into an array first: the loop below is walked twice in effect — once by the
// union prewarm, once user by user — and a cursor held open across other
// statements is the lock-holding shape #92 just finished hunting down.
$userRows = [];
while ($row = $usersToUpdateExchange->fetchArray(SQLITE3_ASSOC)) {
    $userRows[] = $row;
}

// One request for everyone the shared credential serves (#9): the union of
// the due users' currencies is fetched once, and the per-user updates below
// hit the run cache instead of the provider.
wallos_prewarm_shared_exchange_rates($db, array_column($userRows, 'id'), $force);

foreach ($userRows as $userToUpdateExchange) {
    $userId = $userToUpdateExchange['id'];
    echo "For user: " . $userToUpdateExchange['username'] . "<br />";

    // Asked before anything else is resolved or fetched. This job runs on
    // every container start as well as daily, and it used to fetch
    // unconditionally — so deploy frequency alone could exhaust a free
    // provider tier (#117). A refresh that already succeeded today is not
    // repeated; the manual endpoint's force parameter remains the way to
    // insist.
    if (!$force && wallos_exchange_rates_fresh($db, $userId)) {
        wallos_cron_count('skipped');
        echo "Rates are already current today.<br />";
        continue;
    }

    // Not configured and refused are the same false today, and they are not
    // the same event. An installation with no currency provider is finished,
    // not broken; a key the provider rejects means every price in a second
    // currency is being converted at whatever rate was last fetched, with
    // nothing on any screen saying how old it is.
    $configured = wallos_get_effective_currency_config($db, $userId)['valid'];

    if (!$configured) {
        wallos_cron_count('skipped');
        echo "No currency provider configured for this user.<br />";
        continue;
    }

    $update = wallos_update_exchange_rates_for_user($db, $userId);

    if ($update['success']) {
        wallos_cron_count('updated');
        echo "Rates updated successfully!<br />";
    } else {
        wallos_cron_problem('exchange rates for user ' . $userId . ' were not updated: '
            . $update['message']);
        echo "Exchange rates update failed. " . $update['message'] . "<br />";
    }
}

wallos_cron_done();

$db->close();

?>
