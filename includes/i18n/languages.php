<?php
/*
  Supported languages and the one place that turns any language value into a
  supported identifier.

  Identifiers are canonical BCP-47 tags, the form browsers and identity
  providers use. Legacy values — the underscore spellings this project used
  before, and the "jp" alias — are still accepted as input, so cookies and
  stored preferences keep working; only canonical values are ever written.
*/

/**
 * @return array<string, array{name: string, dir: string}>
 */
function wallos_languages()
{
    return [
        // English first
        "en" => ["name" => "English", "dir" => "ltr"],
        "ar" => ["name" => "العربية", "dir" => "rtl"],
        // Remaining sorted alphabetically by language code
        "ca" => ["name" => "Català", "dir" => "ltr"],
        "cs" => ["name" => "Čeština", "dir" => "ltr"],
        "da" => ["name" => "Dansk", "dir" => "ltr"],
        "de" => ["name" => "Deutsch", "dir" => "ltr"],
        "el" => ["name" => "Ελληνικά", "dir" => "ltr"],
        "es" => ["name" => "Español", "dir" => "ltr"],
        "fr" => ["name" => "Français", "dir" => "ltr"],
        "hu" => ["name" => "Magyar", "dir" => "ltr"],
        "id" => ["name" => "bahasa indonesia", "dir" => "ltr"],
        "it" => ["name" => "Italiano", "dir" => "ltr"],
        "ja" => ["name" => "日本語", "dir" => "ltr"],
        "ko" => ["name" => "한국어", "dir" => "ltr"],
        "nl" => ["name" => "Nederlands", "dir" => "ltr"],
        "pl" => ["name" => "Polski", "dir" => "ltr"],
        "pt" => ["name" => "Português", "dir" => "ltr"],
        "pt-BR" => ["name" => "Português Brasileiro", "dir" => "ltr"],
        "ro" => ["name" => "Română", "dir" => "ltr"],
        "ru" => ["name" => "Русский", "dir" => "ltr"],
        "sl" => ["name" => "Slovenščina", "dir" => "ltr"],
        "sr-Latn" => ["name" => "Srpski", "dir" => "ltr"],
        "sr" => ["name" => "Српски", "dir" => "ltr"],
        "tr" => ["name" => "Türkçe", "dir" => "ltr"],
        "uk" => ["name" => "Українська", "dir" => "ltr"],
        "vi" => ["name" => "Tiếng Việt", "dir" => "ltr"],
        "zh-CN" => ["name" => "简体中文", "dir" => "ltr"],
        "zh-TW" => ["name" => "繁體中文", "dir" => "ltr"],
    ];
}

/**
 * Values that are not BCP-47 but have been stored or sent by Wallos before.
 * Keys are normalised: lower case, hyphen separated.
 *
 * @return array<string, string>
 */
function wallos_language_aliases()
{
    return [
        "jp" => "ja",
        "pt-br" => "pt-BR",
        "sr-lat" => "sr-Latn",
        "sr-latin" => "sr-Latn",
        "zh-cn" => "zh-CN",
        "zh-tw" => "zh-TW",
        "zh-hans" => "zh-CN",
        "zh-hant" => "zh-TW",
    ];
}

/**
 * Applies BCP-47 casing: language lower case, script title case, region upper
 * case. "zh_cn" becomes "zh-CN", "sr-latn" becomes "sr-Latn".
 *
 * @param string $value
 * @return string
 */
function wallos_normalize_language_tag($value)
{
    $parts = explode("-", str_replace("_", "-", trim((string) $value)));
    $normalized = [strtolower(array_shift($parts))];

    foreach ($parts as $part) {
        if (strlen($part) === 4) {
            $normalized[] = ucfirst(strtolower($part));   // script
        } elseif (strlen($part) === 2) {
            $normalized[] = strtoupper($part);            // region
        } else {
            $normalized[] = strtolower($part);
        }
    }

    return implode("-", $normalized);
}

/**
 * Turns any language value into one Wallos supports.
 *
 * Order: exact match, known legacy alias, base language, supplied fallback,
 * English. The result is always a key of wallos_languages(), so callers can
 * use it to load a translation file without checking again.
 *
 *   de       -> de        pt-BR   -> pt-BR      jp      -> ja
 *   de-DE    -> de        pt_br   -> pt-BR      fr-CA   -> fr
 *   de_AT    -> de        sr_lat  -> sr-Latn    en-US   -> en
 *
 * @param string|null $value
 * @param string      $fallback
 * @return string
 */
function wallos_resolve_language($value, $fallback = "en")
{
    $supported = wallos_languages();

    if ($value === null || trim((string) $value) === "") {
        return isset($supported[$fallback]) ? $fallback : "en";
    }

    $tag = wallos_normalize_language_tag($value);

    if (isset($supported[$tag])) {
        return $tag;
    }

    $aliases = wallos_language_aliases();
    $lower = strtolower($tag);

    if (isset($aliases[$lower]) && isset($supported[$aliases[$lower]])) {
        return $aliases[$lower];
    }

    // de-DE and fr-CA have no translation of their own; the base language does.
    $base = strtolower(explode("-", $tag)[0]);
    if (isset($supported[$base])) {
        return $base;
    }

    if (isset($supported[$fallback])) {
        return $fallback;
    }

    return "en";
}

// Kept for the code that iterates the list directly, such as the language
// selectors on the registration and profile pages.
$languages = wallos_languages();

$langname_corrections = [
    "jp" => "ja",
];
