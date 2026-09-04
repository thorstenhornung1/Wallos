<?php
/*
  One IntlDateFormatter per (language, pattern) per request.

  formatDate() is called once per subscription while the list renders, and it
  used to build a fresh IntlDateFormatter every call. The formatter is stateless
  with respect to the value it formats — format() takes the date as an argument
  — so building it per row is pure waste: on the largest seeded dataset the list
  formats ten thousand rows and so constructed ten thousand identical
  formatters. The pattern only ever takes two shapes ("MMM d" for the current
  year, "MMM yyyy" otherwise), so at most two formatters answer a whole request.

  The cache is keyed by language and pattern and lives for the request, so the
  same object is reused across rows. Reusing it is safe precisely because
  format() is a pure function of (locale, pattern, value): the same formatter
  fed different dates returns exactly what a freshly built one would.
*/

if (!function_exists('wallos_date_formatter')) {
    /**
     * Returns the request-cached IntlDateFormatter for a language and pattern,
     * falling back to English when the requested language cannot be loaded —
     * the same fallback the per-call construction used.
     *
     * @param string $lang
     * @param string $dateFormat an ICU pattern, e.g. "MMM d" or "MMM yyyy"
     * @return IntlDateFormatter
     */
    function wallos_date_formatter($lang, $dateFormat)
    {
        static $formatters = [];

        $key = $lang . '|' . $dateFormat;

        if (!isset($formatters[$key])) {
            try {
                $formatter = new IntlDateFormatter(
                    $lang,
                    IntlDateFormatter::SHORT,
                    IntlDateFormatter::NONE,
                    null,
                    null,
                    $dateFormat
                );

                if (!$formatter) {
                    throw new Exception('Failed to create IntlDateFormatter with language: ' . $lang);
                }
            } catch (Throwable $e) {
                $formatter = new IntlDateFormatter(
                    'en',
                    IntlDateFormatter::SHORT,
                    IntlDateFormatter::NONE,
                    null,
                    null,
                    $dateFormat
                );
            }

            $formatters[$key] = $formatter;
        }

        return $formatters[$key];
    }
}
