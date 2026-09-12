<?php
// Notes go back to being stored as plain text (upstream 5.7.0, where it is
// migration 000058 — renumbered here for the same reason as 000087).
//
// Notes used to be stored HTML-escaped: htmlspecialchars() at save time,
// decoded again only when filling the edit form. They are now rendered through
// a Markdown parser that escapes at render time, so storing escaped text would
// render it twice — a note saved as "Ben & Jerry's" would read
// "Ben &amp; Jerry&#039;s" on the page.
//
// Idempotent: decoding text that holds no entities changes nothing, so a
// second run is a no-op.
//
// Read into memory before writing. The original iterates a result set while
// updating the table it is reading, which is how migration 000016 recorded
// itself as applied against a table SQLite had refused to drop — the one
// mistake this fork's migration chain has already paid for once.

$rows = [];
$result = $db->query("SELECT id, notes FROM subscriptions
                      WHERE notes IS NOT NULL AND notes != ''");

if ($result === false) {
    error_log('Wallos: migration 000088 could not read the subscription notes: '
        . $db->lastErrorMsg());

    return false;
}

while ($row = $result->fetchArray()) {
    $rows[] = $row;
}

if (method_exists($result, 'finalize')) {
    $result->finalize();
}

$stmt = $db->prepare('UPDATE subscriptions SET notes = :notes WHERE id = :id');

if ($stmt === false) {
    error_log('Wallos: migration 000088 could not prepare the note update: '
        . $db->lastErrorMsg());

    return false;
}

foreach ($rows as $row) {
    $decoded = html_entity_decode((string) $row['notes'], ENT_QUOTES, 'UTF-8');

    if ($decoded === $row['notes']) {
        continue;
    }

    $stmt->bindValue(':notes', $decoded);
    $stmt->bindValue(':id', $row['id']);

    if ($stmt->execute() === false) {
        error_log('Wallos: migration 000088 could not decode the notes of subscription '
            . $row['id'] . ': ' . $db->lastErrorMsg());

        return false;
    }

    $stmt->reset();
}
