<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/upcoming_payments.php';

$postData = file_get_contents('php://input');
$data = json_decode($postData, true);
$limit = parse_upcoming_payments_limit($data['value'] ?? null);

if ($limit === null) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]));
}

$stmt = $db->prepare('UPDATE settings SET upcoming_payments_limit = :limit WHERE user_id = :userId');

if ($stmt === false) {
    // bindValue() on false is a fatal, not an error the caller can report, and
    // this endpoint answers JSON the page has to parse (#87).
    error_log('Wallos upcoming_payments_limit: could not prepare the update: '
        . $db->lastErrorMsg());

    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]));
}

$stmt->bindValue(':limit', $limit);
$stmt->bindValue(':userId', $userId);

if ($stmt->execute()) {
    die(json_encode([
        'success' => true,
        'message' => translate('success', $i18n),
    ]));
}

die(json_encode([
    'success' => false,
    'message' => translate('error', $i18n),
]));
