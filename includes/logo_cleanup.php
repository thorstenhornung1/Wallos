<?php

// Deletes a logo file from the logos directory unless a subscription (logo or
// logo_variant) or a payment method (icon) still names it. clone.php copies a
// logo filename into a second row, so a file detached from one row can still
// be in use; the check runs across all users since the directory is shared.
// $filename is the bare name as stored (e.g. "1712-netflix.png"); $logosDir
// may or may not have a trailing slash.
function deleteLogoFileIfUnused($db, $filename, $logosDir)
{
    $filename = trim((string) $filename);
    if ($filename === '') {
        return;
    }

    // Never step outside the logos directory, whatever the stored value is.
    $filename = basename($filename);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return;
    }

    // Three placeholders for one value rather than :name three times. SQLite
    // repeats a named parameter happily; PDO with native prepares does not, so
    // the shared query would have thrown on PostgreSQL the moment anything
    // called it. The table name is the only thing that differs between the two
    // halves, so the cost of spelling the parameter out three times is nil.
    $stmt = $db->prepare(
        'SELECT 1 FROM subscriptions WHERE logo = :logo OR logo_variant = :variant
         UNION ALL
         SELECT 1 FROM payment_methods WHERE icon = :icon
         LIMIT 1'
    );

    if ($stmt === false) {
        // A query that cannot even be prepared is not evidence that the file is
        // unused. Keeping it is the safe answer: an orphan costs disk, a
        // wrongly deleted logo costs the image off somebody's subscription.
        return;
    }

    $stmt->bindValue(':logo', $filename);
    $stmt->bindValue(':variant', $filename);
    $stmt->bindValue(':icon', $filename);
    $result = $stmt->execute();

    if ($result && $result->fetchArray()) {
        return; // still in use
    }

    $path = rtrim($logosDir, '/') . '/' . $filename;
    if (is_file($path)) {
        unlink($path);
    }
}
