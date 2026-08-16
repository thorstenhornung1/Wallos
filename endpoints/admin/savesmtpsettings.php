<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/integration_config.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$configuration = wallos_get_instance_smtp_config($db);

// Environment managed fields keep their environment value; they are never
// written to the database.
$fields = [
    'host' => ['column' => 'smtp_address', 'value' => trim((string) ($data['smtpaddress'] ?? ''))],
    'port' => ['column' => 'smtp_port', 'value' => trim((string) ($data['smtpport'] ?? ''))],
    'encryption' => ['column' => 'encryption', 'value' => (string) ($data['encryption'] ?? 'tls')],
    'username' => ['column' => 'smtp_username', 'value' => (string) ($data['smtpusername'] ?? '')],
    'password' => ['column' => 'smtp_password', 'value' => (string) ($data['smtppassword'] ?? '')],
    'from_email' => ['column' => 'from_email', 'value' => (string) ($data['fromemail'] ?? '')],
    'from_name' => ['column' => 'smtp_from_name', 'value' => (string) ($data['smtpfromname'] ?? '')],
];

$editable = [];
foreach ($fields as $field => $definition) {
    if (empty($configuration['managed'][$field])) {
        $editable[$field] = $definition;
    }
}

// The password is never rendered back into the form, so an empty field means
// "keep the stored password". Removing it is an explicit action.
if (isset($editable['password'])) {
    if (!empty($data['smtppasswordremove'])) {
        $editable['password']['value'] = '';
    } elseif ($editable['password']['value'] === '') {
        unset($editable['password']);
    }
}

$smtpAddress = array_key_exists('host', $editable)
    ? $editable['host']['value']
    : (string) $configuration['values']['host'];
$smtpPortInt = array_key_exists('port', $editable)
    ? (int) $editable['port']['value']
    : (int) $configuration['values']['port'];

if (empty($smtpAddress) || $smtpPortInt < 1 || $smtpPortInt > 65535) {
    die(json_encode([
        "success" => false,
        "message" => translate('fill_all_fields', $i18n)
    ]));
}

if (!validate_smtp_host($smtpAddress, $smtpPortInt, $db)) {
    die(json_encode([
        "success" => false,
        "message" => "Security Error: SMTP host must not target link-local or loopback addresses."
    ]));
}

if (array_key_exists('encryption', $editable) && !in_array($editable['encryption']['value'], WALLOS_SMTP_ENCRYPTIONS, true)) {
    $editable['encryption']['value'] = 'tls';
}

if ($editable === []) {
    die(json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]));
}

$assignments = [];
foreach ($editable as $definition) {
    $assignments[] = $definition['column'] . ' = :' . $definition['column'];
}

$stmt = $db->prepare('UPDATE admin SET ' . implode(', ', $assignments) . ' WHERE id = 1');
foreach ($editable as $field => $definition) {
    $stmt->bindValue(
        ':' . $definition['column'],
        $field === 'port' ? (int) $definition['value'] : $definition['value'],
        $field === 'port' ? SQLITE3_INTEGER : SQLITE3_TEXT
    );
}

if ($stmt->execute()) {
    die(json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]));
}

die(json_encode([
    "success" => false,
    "message" => translate('error', $i18n)
]));
