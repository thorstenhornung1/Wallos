<?php
/*
  What the currency provider will price, and which of our codes it will not.

      podman exec wallos-dev php /var/www/html/dev/currency-symbols.php
      podman exec wallos-dev php /var/www/html/dev/currency-symbols.php --list

  One provider request. No rates are fetched and nothing is written.

  Why this exists. A currency in Wallos is three free-text fields, so an
  invented code is accepted and then sits at rate 1 — "1 Lunarium = 1 Euro" in
  every total on the dashboard, the statistics page and the calendar, with
  nothing on screen to tell it from a real number (#133). The rate endpoint
  cannot report it: it is handed a symbol list and answers with rates, so an
  unknown code either comes back missing or takes the whole request down with
  it, and neither says which code was the problem (#135).

  The provider's own symbols endpoint answers exactly that, which makes this
  the first thing to run when rates look wrong.

  What this is not. It is not a check that a currency exists. Both providers
  list withdrawn codes beside current ones — BYR next to BYN, HRK, LTL, VEF —
  because they price history back to 1999. A code being listed means the
  provider will quote it, not that anybody should be offered it today. ISO 4217
  is the source for that question and this tool does not have it.

  Exit codes: 0 every stored code is known to the provider, 1 at least one is
  not, 2 the provider could not be asked.
*/

require_once __DIR__ . '/../includes/database/connection.php';
require_once __DIR__ . '/../includes/integration_config.php';
require_once __DIR__ . '/../includes/currency_provider.php';

$listAll = in_array('--list', array_slice($argv, 1), true);

$db = wallos_database_connect();

// The instance credential, which is what the scheduled refresh uses. A user
// with their own key is their own case and is not what this diagnoses.
$config = wallos_get_instance_currency_config($db);

if (empty($config['valid'])) {
    fwrite(STDERR, "currency-symbols: the instance currency provider is not configured"
        . ($config['notes'] ? ' — ' . $config['notes'][0] : '') . "\n");
    $db->close();

    exit(2);
}

$answer = wallos_fetch_currency_symbols($config);

if (!$answer['success']) {
    fwrite(STDERR, "currency-symbols: the provider did not answer — " . $answer['message'] . "\n");
    $db->close();

    exit(2);
}

$symbols = $answer['symbols'];
printf("The provider prices %d symbol(s).\n\n", count($symbols));

if ($listAll) {
    foreach ($symbols as $code => $name) {
        printf("  %-6s %s\n", $code, $name);
    }

    echo "\n";
}

// Every code any account holds, and who holds it.
$result = $db->query('SELECT code, user_id, name FROM currencies ORDER BY code, user_id');
$stored = [];

// fetchArray() without a mode constant: it defaults to both key forms, the
// boundary maps that for PostgreSQL, and the constants are what issue #41 is
// confining to the adapter.
while ($result !== false && $row = $result->fetchArray()) {
    $stored[strtoupper((string) $row['code'])][] = $row;
}

$unknown = [];

foreach ($stored as $code => $rows) {
    if (!isset($symbols[$code])) {
        $unknown[$code] = $rows;
    }
}

printf("%d distinct code(s) stored across %d row(s).\n",
    count($stored), array_sum(array_map('count', $stored)));

if ($unknown === []) {
    echo "Every stored code is one the provider prices.\n";
    $db->close();

    exit(0);
}

echo "\nStored codes the provider does not price:\n\n";

foreach ($unknown as $code => $rows) {
    foreach ($rows as $row) {
        printf("  %-6s user %-4d %s\n", $code, (int) $row['user_id'], (string) $row['name']);
    }
}

echo "\nEach of these sits at whatever rate it was given when it was added, and\n"
    . "every total that converts through it is wrong by that much. Removing the\n"
    . "row is the immediate fix; #133 is the reason it could be added at all.\n";

$db->close();

exit(1);
