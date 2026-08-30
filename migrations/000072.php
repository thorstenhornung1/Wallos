<?php
// Repairs what years of unenforced foreign keys left behind, before the
// enforcement that is now on at every connect can trip over it (issue #92).
//
// The boundary reports each row violating a declared key. Two kinds exist,
// and they are treated differently on purpose:
//
// Derived rows — tokens, sessions, roles, per-user settings — whose account
// is gone may simply go: they are the litter #81 stopped producing, they
// serve nobody, and 000067 removed most of them already. Each removal is
// reported per table.
//
// Business data is not this migration's call. A subscription pointing at a
// category that does not exist, or an account naming a currency that is
// gone, has no safe automatic answer — deleting it destroys a financial
// record, and inventing a target guesses. The migration names the rows and
// refuses, so the operator decides; it is retried on the next start.
//
// On the backend that always enforced its keys the report is empty by
// construction and this file does nothing.

$violations = $db->foreignKeyViolations();

if ($violations === []) {
    return;
}

// Tables whose rows exist only in service of an account: safe to remove when
// the account is gone.
$disposable = [
    'login_tokens',
    'ntfy_notifications',
    'custom_css_style',
    'totp',
    'serverchan_notifications',
    'user_roles',
    'oidc_sessions',
];

$removable = [];
$blocking = [];

foreach ($violations as $violation) {
    if (in_array($violation['table'], $disposable, true)) {
        $removable[$violation['table']][] = $violation['rowid'];
    } else {
        $blocking[] = $violation;
    }
}

if ($blocking !== []) {
    foreach ($blocking as $violation) {
        error_log('Wallos: migration 000072 found a row it must not decide about: table '
            . $violation['table'] . ', rowid ' . $violation['rowid']
            . ', referencing missing ' . $violation['parent'] . '.');
    }
    error_log('Wallos: migration 000072 stopped. Repair or remove the rows above yourself; '
        . 'the migration retries on the next start and continues once the report is clean.');

    return false;
}

foreach ($removable as $table => $rowids) {
    foreach ($rowids as $rowid) {
        $stmt = $db->prepare('DELETE FROM ' . $table . ' WHERE rowid = :rowid');

        if ($stmt === false || $stmt->bindValue(':rowid', $rowid) === false
            || $stmt->execute() === false) {
            error_log('Wallos: migration 000072 could not remove rowid ' . $rowid
                . ' from ' . $table . ': ' . $db->lastErrorMsg());

            return false;
        }
    }

    error_log('Wallos: migration 000072 removed ' . count($rowids) . ' row(s) from ' . $table
        . ' whose account no longer exists — left over from the years before enforcement.');
}

// The report has to be clean now, or the enforcement this migration prepares
// for would trip on the next write.
if ($db->foreignKeyViolations() !== []) {
    error_log('Wallos: migration 000072 removed the derived orphans and violations remain; stopping.');

    return false;
}
