<?php

// This migration canonicalises stored language identifiers.
//
// Wallos derived them from translation file names, which left four values that
// are not valid BCP-47 — the form browsers and identity providers use. Reading
// legacy values keeps working through wallos_resolve_language(), but stored
// values are canonical from here on, so nothing has to convert them again.

$replacements = [
    'pt_br' => 'pt-BR',
    'sr_lat' => 'sr-Latn',
    'zh_cn' => 'zh-CN',
    'zh_tw' => 'zh-TW',
    'jp' => 'ja',
];

foreach ($replacements as $legacy => $canonical) {
    $stmt = $db->prepare('UPDATE user SET language = :canonical WHERE language = :legacy');
    $stmt->bindValue(':canonical', $canonical, SQLITE3_TEXT);
    $stmt->bindValue(':legacy', $legacy, SQLITE3_TEXT);
    $stmt->execute();
}

?>
