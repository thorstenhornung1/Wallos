<?php
require_once __DIR__ . '/../../includes/cron_run.php';
wallos_cron_begin('updateexchange');

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/currency_provider.php';
wallos_cron_database($db);

require 'settimezone.php';

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

while ($userToUpdateExchange = $usersToUpdateExchange->fetchArray(SQLITE3_ASSOC)) {
    $userId = $userToUpdateExchange['id'];
    echo "For user: " . $userToUpdateExchange['username'] . "<br />";

    // Asked before anything else is resolved or fetched. This job runs on
    // every container start as well as daily, and it used to fetch
    // unconditionally — so deploy frequency alone could exhaust a free
    // provider tier (#117). A refresh that already succeeded today is not
    // repeated; the manual endpoint's force parameter remains the way to
    // insist.
    if (wallos_exchange_rates_fresh($db, $userId)) {
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
