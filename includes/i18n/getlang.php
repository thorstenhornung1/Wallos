<?php

// Accepts anything a browser or an older Wallos may have stored, and always
// returns a language that exists, so the caller can build a file path from it.
$lang = wallos_resolve_language($_COOKIE['language'] ?? null);

function translate($text, $translations)
{
    if (array_key_exists($text, $translations)) {
        return $translations[$text];
    } else {
        require 'en.php';
        if (array_key_exists($text, $i18n)) {
            return $i18n[$text];
        } else {
            return "[i18n String Missing]";
        }
    }
}

?>