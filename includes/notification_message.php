<?php

/*
 * Text builders for the renewal notification cron.
 *
 * Extracted from endpoints/cronjobs/sendnotifications.php (#130) so the message
 * body and its day labels are sourced through translate() in the recipient
 * account's language instead of being hardcoded English, and so the builders
 * can be exercised by the test suite without running the whole cron.
 *
 * $translations is the recipient account's i18n array — the same shape
 * translate() takes as its second argument. Every user-facing word is looked up
 * in it, so a German account receives a German message and every other language
 * falls back to English through translate()'s own en.php fallback.
 */

// Loads the i18n array for one language without disturbing the global $i18n the
// page (or cron) already loaded. require, not require_once: the language file
// assigns $i18n at file scope, so re-including it here fills this function's
// local $i18n, and the static cache means each file is read at most once.
function wallos_notification_i18n($language)
{
    static $cache = [];

    $language = wallos_resolve_language($language);

    if (!array_key_exists($language, $cache)) {
        $i18n = [];
        require __DIR__ . '/i18n/' . $language . '.php';
        $cache[$language] = $i18n;
    }

    return $cache[$language];
}

function getDaysText($days, $translations)
{
    if ($days == 0) {
        return translate('notify_today', $translations);
    } elseif ($days == 1) {
        return translate('notify_tomorrow', $translations);
    } else {
        // %d is the day count; days is always >= 2 here (0 and 1 are handled
        // above), so the plural form is always the correct one.
        return sprintf(translate('notify_in_days', $translations), $days);
    }
}

function buildNotificationMessage($name, $perUser, $periodSummaryLine, $includePeriodSummary, $translations)
{
    if (empty($perUser) && !$includePeriodSummary) {
        return "";
    }

    if (empty($perUser)) {
        return ($name ? $name . ", " : "") . $periodSummaryLine . "\n";
    }

    $renewalLine = translate('subscriptions_up_for_renewal', $translations);

    if ($name) {
        // The standalone line opens with a capital; after "Name, " both English
        // and German want it lower case ("the following" / "die folgenden"). The
        // key stays capitalised and the first letter is folded here — ASCII in
        // en and de, and the en fallback is ASCII too.
        $message = $name . ", " . lcfirst($renewalLine) . "\n";
    } else {
        $message = $renewalLine . "\n";
    }

    foreach ($perUser as $subscription) {
        $dayText = getDaysText($subscription['days'], $translations);
        $message .= $subscription['name'] . " " . translate('notify_for', $translations) . " " . $subscription['formatted_price'] . " (" . $dayText . ")\n";
    }

    if ($includePeriodSummary) {
        $message .= "\n" . $periodSummaryLine . "\n";
    }

    return $message;
}
