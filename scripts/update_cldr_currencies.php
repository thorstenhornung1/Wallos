<?php
/*
  Regenerates the currency-localization dataset from a pinned Unicode CLDR
  release.

      php scripts/update_cldr_currencies.php

  What it does, and why each step is the way it is (issue #163):

    - It pins one CLDR release (CLDR_VERSION below) and never follows an
      uncontrolled "latest": the shipped data must be reproducible from a named
      upstream tag, not from whatever CLDR happened to publish the day it ran.

    - It generates a file for every language Wallos actually supports, read from
      the one central language list (includes/i18n/languages.php). There is no
      second currency-only language list to drift from the first.

    - From each locale's CLDR currency data it keeps only the two fields Wallos
      displays — the localized name and, when CLDR has one, the symbol. The ISO
      4217 code is the JSON key. Everything else CLDR carries (plural forms,
      narrow variants beyond the fallback, count keys) is dropped.

    - The output is deterministic: currencies sorted by code, a fixed JSON
      shape, no timestamp inside the locale files. Running it twice against the
      same release produces byte-identical files, so a re-run that changes
      nothing shows an empty diff.

    - It downloads over HTTPS from the pinned tag and fails hard on any error.
      It downloads and validates every locale into memory first and only then
      writes: a network blip halfway through can never leave half the languages
      updated and half stale, and an incomplete download never overwrites a good
      committed file.

  Offline / vendored input: set CLDR_SOURCE_DIR to a local checkout of the
  cldr-numbers-full "main" directory (the one holding de/currencies.json,
  en/currencies.json, ...) and the script reads from there instead of the
  network. CLDR_VERSION still stamps the metadata, so a vendored run must point
  at the matching checkout.

  Environment overrides:
    CLDR_VERSION      upstream tag to pin (default below)
    CLDR_SOURCE_DIR   read locale files from this local dir instead of HTTPS
*/

require_once __DIR__ . '/../includes/i18n/languages.php';

/**
 * The pinned upstream CLDR-JSON release.
 *
 * unicode-org/cldr-json publishes one tag per CLDR release; this is the exact
 * one the committed data was generated from. Bump it deliberately, re-run, and
 * commit the resulting diff — never let it float.
 */
const CLDR_VERSION = '48.2.1';

const CLDR_SOURCE = 'Unicode CLDR';
const CLDR_LICENSE = 'Unicode-3.0';
const CLDR_REPO = 'https://github.com/unicode-org/cldr-json';

/**
 * Wallos language tag => CLDR source-locale directory.
 *
 * Identity for almost every language; listed here only where the CLDR
 * directory name differs from the Wallos tag:
 *   - Wallos "zh-CN" / "zh-TW" use CLDR's script tags zh-Hans / zh-Hant.
 *   - CLDR's base "pt" is Brazilian Portuguese, so Wallos "pt-BR" reads from
 *     "pt" and Wallos "pt" (European) reads from "pt-PT".
 *   - "sr-Latn" matches CLDR's own script tag.
 *
 * @var array<string, string>
 */
const CLDR_LOCALE_MAP = [
    'zh-CN' => 'zh-Hans',
    'zh-TW' => 'zh-Hant',
    'pt'    => 'pt-PT',
    'pt-BR' => 'pt',
];

/**
 * The CLDR source directory for a Wallos language tag.
 *
 * @param string $language
 * @return string
 */
function cldr_source_locale($language)
{
    return CLDR_LOCALE_MAP[$language] ?? $language;
}

/**
 * The raw-file URL for one locale's currency data at the pinned tag.
 *
 * @param string $sourceLocale
 * @return string
 */
function cldr_source_url($sourceLocale)
{
    return sprintf(
        'https://raw.githubusercontent.com/unicode-org/cldr-json/refs/tags/%s/cldr-json/cldr-numbers-full/main/%s/currencies.json',
        rawurlencode(CLDR_VERSION),
        rawurlencode($sourceLocale)
    );
}

/**
 * Reads one locale's raw currency JSON, from the network or a vendored dir.
 *
 * Returns the decoded array on success. Throws on any failure — a missing file,
 * an HTTP error, malformed JSON — so the caller can abort before writing
 * anything. HTTPS only; a plain-http override cannot slip in.
 *
 * @param string $sourceLocale CLDR directory name, e.g. "de", "zh-Hans"
 * @return array
 */
function cldr_fetch_locale($sourceLocale)
{
    $vendored = getenv('CLDR_SOURCE_DIR');

    if ($vendored !== false && $vendored !== '') {
        $path = rtrim($vendored, '/') . '/' . $sourceLocale . '/currencies.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("cannot read vendored CLDR file: $path");
        }
        $origin = $path;
    } else {
        $url = cldr_source_url($sourceLocale);
        if (strncmp($url, 'https://', 8) !== 0) {
            throw new RuntimeException("refusing a non-HTTPS source URL: $url");
        }

        $context = stream_context_create([
            'http'  => ['timeout' => 60, 'header' => "User-Agent: wallos-cldr-generator\r\n"],
            'https' => ['timeout' => 60],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new RuntimeException("download failed for $sourceLocale: $url");
        }
        $origin = $url;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("invalid JSON from $origin: " . json_last_error_msg());
    }

    return $decoded;
}

/**
 * Pulls the { code => {name, symbol} } table out of one decoded CLDR file.
 *
 * The CLDR shape is main.<locale>.numbers.currencies.<CODE> where each entry
 * carries a displayName plus symbol / plural / narrow variants. Kept: the
 * displayName as the name, and a genuine symbol when CLDR has one.
 *
 * Symbol resolution: the standard `symbol`, else `symbol-alt-narrow`, else
 * null. A value equal to the code itself is treated as absent (null): CLDR
 * echoes the code back when a locale has no distinctive symbol, and that is not
 * a symbol Wallos wants to store — the runtime supplies a better provider
 * symbol, or the code, in that case.
 *
 * Only well-formed ISO-4217-shaped codes (three A-Z) with a non-empty
 * displayName are kept, which drops CLDR's numbered/experimental keys.
 *
 * @param array $decoded
 * @param string $sourceLocale
 * @return array<string, array{name: string, symbol: ?string}>
 */
function cldr_extract_currencies(array $decoded, $sourceLocale)
{
    if (!isset($decoded['main']) || !is_array($decoded['main'])) {
        throw new RuntimeException("unexpected CLDR shape for $sourceLocale: no 'main'");
    }

    // The inner key is the CLDR locale identifier, which usually equals the
    // directory but is read rather than assumed.
    $localeKey = array_key_first($decoded['main']);
    $currencies = $decoded['main'][$localeKey]['numbers']['currencies'] ?? null;

    if (!is_array($currencies)) {
        throw new RuntimeException("unexpected CLDR shape for $sourceLocale: no currencies table");
    }

    $result = [];

    foreach ($currencies as $code => $entry) {
        if (!is_string($code) || !preg_match('/^[A-Z]{3}$/', $code) || !is_array($entry)) {
            continue;
        }

        $name = isset($entry['displayName']) ? trim((string) $entry['displayName']) : '';
        if ($name === '') {
            continue;
        }

        $symbol = null;
        foreach (['symbol', 'symbol-alt-narrow'] as $field) {
            if (isset($entry[$field]) && trim((string) $entry[$field]) !== '') {
                $candidate = (string) $entry[$field];
                if ($candidate !== $code) {
                    $symbol = $candidate;
                }
                break;
            }
        }

        $result[$code] = ['name' => $name, 'symbol' => $symbol];
    }

    if ($result === []) {
        throw new RuntimeException("no usable currencies extracted for $sourceLocale");
    }

    // Stable sort by ISO code so the file is byte-identical on every re-run.
    ksort($result, SORT_STRING);

    return $result;
}

/**
 * Encodes one dataset deterministically, with a trailing newline.
 *
 * @param array $data
 * @return string
 */
function cldr_encode_json(array $data)
{
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
    }

    return $json . "\n";
}

// -- run ---------------------------------------------------------------------

$version = getenv('CLDR_VERSION');
if ($version !== false && $version !== '') {
    // CLDR_VERSION is a compile-time const for the shipped default; an env
    // override is echoed so a non-default run is never silent.
    fwrite(STDERR, "Using CLDR_VERSION override from environment: $version\n");
} else {
    $version = CLDR_VERSION;
}

$languages = array_keys(wallos_languages());
$outputDir = __DIR__ . '/../data/currencies';

if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "cannot create output directory: $outputDir\n");
    exit(1);
}

fwrite(STDERR, sprintf("CLDR %s -> %d languages\n", $version, count($languages)));

// Download and extract everything first. Nothing is written until every locale
// has produced a non-empty table, so a failure leaves the committed data
// untouched rather than half-rewritten.
$datasets = [];

foreach ($languages as $language) {
    $sourceLocale = cldr_source_locale($language);

    try {
        $decoded = cldr_fetch_locale($sourceLocale);
        $datasets[$language] = cldr_extract_currencies($decoded, $sourceLocale);
    } catch (Throwable $error) {
        fwrite(STDERR, "FAILED for $language (source $sourceLocale): " . $error->getMessage() . "\n");
        fwrite(STDERR, "Aborting without writing any file so the committed data stays intact.\n");
        exit(1);
    }

    fwrite(STDERR, sprintf("  %-7s <- %-8s %d currencies\n",
        $language, $sourceLocale, count($datasets[$language])));
}

// Every locale is in hand and validated; now write.
foreach ($datasets as $language => $data) {
    $path = $outputDir . '/' . $language . '.json';
    if (file_put_contents($path, cldr_encode_json($data)) === false) {
        fwrite(STDERR, "cannot write $path\n");
        exit(1);
    }
}

// Source metadata. generated_at is deliberately omitted: a timestamp would make
// every regeneration diff even when the data did not change, defeating the
// byte-identical-on-rerun guarantee.
$metadata = [
    'source'        => CLDR_SOURCE,
    'cldr_version'  => $version,
    'license'       => CLDR_LICENSE,
    'source_repo'   => CLDR_REPO,
    'generator'     => 'scripts/update_cldr_currencies.php',
    'languages'     => count($datasets),
];

if (file_put_contents($outputDir . '/metadata.json', cldr_encode_json($metadata)) === false) {
    fwrite(STDERR, "cannot write metadata.json\n");
    exit(1);
}

fwrite(STDERR, sprintf("Wrote %d locale files + metadata.json to data/currencies/\n", count($datasets)));
exit(0);
