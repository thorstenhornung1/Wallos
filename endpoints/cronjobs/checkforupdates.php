<?php

require_once __DIR__ . '/../../includes/cron_run.php';
wallos_cron_begin('checkforupdates');

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
wallos_cron_database($db);

$options = [
    'http' => [
        'header' => "User-Agent: Wallos\r\n"
    ]
];

$repository = 'thorstenhornung1/Wallos'; // Change this to your repository if you fork Wallos
$url = "https://api.github.com/repos/$repository/releases/latest";

$context = stream_context_create($options);
$fetch = file_get_contents($url, false, $context);

if ($fetch === false) {
    // A release check that cannot reach GitHub is a release check that is not
    // happening. It failed here for two days behind a container with no DNS,
    // printing this same sentence into a file every six hours, and the admin
    // page went on showing whatever version it last managed to fetch.
    $reason = error_get_last();
    wallos_cron_fail('could not reach the GitHub releases API: '
        . ($reason === null ? 'no reason reported' : $reason['message']));
}

$latestVersion = json_decode($fetch, true)['tag_name'];

// Check that $latestVersion is a valid version number
if (!preg_match('/^v\d+\.\d+\.\d+$/', $latestVersion)) {
    wallos_cron_fail('the GitHub releases API answered with something that is not a version: '
        . var_export($latestVersion, true));
}

if (!$db->exec("UPDATE admin SET latest_version = '$latestVersion'")) {
    wallos_cron_fail('could not store the latest version: ' . wallos_cron_reason($db));
}

wallos_cron_done('latest release is ' . $latestVersion);


if (php_sapi_name() !== 'cli') {
    include __DIR__ . '/../../includes/version.php';
    if (version_compare($latestVersion, $version) > 0) {
        echo "New version available: $latestVersion";
    } else {
        echo "No new version available, currently on $version";
    }
}
?>