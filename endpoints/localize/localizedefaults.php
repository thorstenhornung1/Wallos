<?php

// Opt-in localizer for an account's still-default currency and payment-method
// names (issue #164 part B). It never renames a row on its own: settings.php
// previews the still-default English rows with a ticked checkbox per row, and
// this endpoint applies only the ones the user confirmed — and only while they
// still exactly equal a known English default, so a row edited in between is
// left untouched. See includes/user_provisioning.php for the detection and the
// FK-safe, name-only rewrite.

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/user_provisioning.php';

// The target language is the account's own, read from the stored value rather
// than the request, so the preview the user saw and the rewrite agree.
$storedLanguage = (string) $db->scalar('SELECT language FROM "user" WHERE id = :userId',
    [':userId' => $userId]);
$language = wallos_resolve_language($storedLanguage);

/**
 * Turns whatever the form sent for one bucket into a list of integer ids.
 * Accepts an array of ids (checkbox values) or a comma-separated string.
 *
 * @param mixed $value
 * @return int[]
 */
function wallos_localize_selected_ids($value)
{
    if (is_array($value)) {
        $parts = $value;
    } elseif (is_string($value) && $value !== '') {
        $parts = explode(',', $value);
    } else {
        return [];
    }

    $ids = [];
    foreach ($parts as $part) {
        $id = (int) trim((string) $part);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return $ids;
}

$selection = [
    'currencies' => wallos_localize_selected_ids($_POST['currencies'] ?? null),
    'payment_methods' => wallos_localize_selected_ids($_POST['payment_methods'] ?? null),
];

if (empty($selection['currencies']) && empty($selection['payment_methods'])) {
    echo json_encode([
        'success' => false,
        'message' => translate('localize_defaults_nothing_selected', $i18n),
    ]);
    exit;
}

$outcome = wallos_apply_default_name_localization($db, $userId, $language, $selection);

if ($outcome['error']) {
    echo json_encode([
        'success' => false,
        'message' => translate('localize_defaults_failed', $i18n),
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'applied' => $outcome['applied'],
    'message' => str_replace('{count}', (string) $outcome['applied'],
        translate('localize_defaults_done', $i18n)),
]);
