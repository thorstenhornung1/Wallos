<?php
/*
  One-off migration page for the default-name localizer (issues #164/#165).

  This is deliberately a page of its own rather than two blocks inside
  settings.php, and it is meant to be deleted again.

  Renaming the seeded English currency and payment-method names is a one-time
  job for accounts that predate the localized seeding — not a setting anybody
  returns to. Living in settings.php it was split across two sections some 500
  lines apart, which is what broke the dashboard banner: the banner offers both
  halves but a link can only land on one anchor, so an account that renamed its
  currencies was sent to a section with nothing left to do while the payment
  methods still waited far below, and the banner stayed up as if nothing had
  happened.

  Here both halves are one list with one button, which is what the banner
  promises. The page is reachable only from that banner — no navigation entry —
  so it disappears from the product the moment there is nothing left to rename:
  an account with no candidates is redirected to the dashboard rather than
  shown an empty page.

  To remove it in a later version: delete this file, the banner block in
  index.php, scripts/localize.js, and the localize.php branch in
  includes/checkredirect.php. The detection helpers in
  includes/user_provisioning.php stay — they also seed new accounts in their
  own language, which is not going away.
*/

require_once 'includes/header.php';
require_once 'includes/user_provisioning.php';

$accountLanguage = wallos_resolve_language(
    $db->scalar('SELECT language FROM "user" WHERE id = :userId', [':userId' => $userId]));

$currencyCandidates = wallos_default_currency_localization_candidates($db, $userId, $accountLanguage);
$paymentCandidates = wallos_default_payment_method_localization_candidates($db, $userId, $accountLanguage);

// An account with nothing left to rename never reaches this point: the redirect
// lives in includes/checkredirect.php, which runs before header.php prints the
// first byte of the document. Doing it here failed with "headers already sent"
// and left the empty page standing.
?>

<section class="contain localize-page" id="localize-defaults"
    data-endpoint="endpoints/localize/localizedefaults.php">
    <header>
        <h2><?= translate('localize_page_title', $i18n) ?></h2>
    </header>

    <div class="settings-notes">
        <p>
            <i class="fa-solid fa-circle-info"></i>
            <?= translate('localize_defaults_info', $i18n) ?>
        </p>
    </div>

    <?php if (!empty($currencyCandidates)): ?>
        <h3><?= translate('currencies', $i18n) ?></h3>
        <?php foreach ($currencyCandidates as $candidate): ?>
            <label class="localize-defaults-row">
                <input type="checkbox" class="localize-currency-checkbox" value="<?= (int) $candidate['id'] ?>" checked>
                <span class="localize-old"><?= htmlspecialchars($candidate['current']) ?></span>
                <i class="fa-solid fa-arrow-right-long"></i>
                <span class="localize-new"><?= htmlspecialchars($candidate['localized']) ?></span>
            </label>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($paymentCandidates)): ?>
        <h3><?= translate('payment_methods', $i18n) ?></h3>
        <?php foreach ($paymentCandidates as $candidate): ?>
            <label class="localize-defaults-row">
                <input type="checkbox" class="localize-payment-checkbox" value="<?= (int) $candidate['id'] ?>" checked>
                <span class="localize-old"><?= htmlspecialchars($candidate['current']) ?></span>
                <i class="fa-solid fa-arrow-right-long"></i>
                <span class="localize-new"><?= htmlspecialchars($candidate['localized']) ?></span>
            </label>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="buttons">
        <button type="button" class="button thin mobile-grow" onClick="applyLocalizeAll()">
            <?= translate('localize_defaults_apply', $i18n) ?>
        </button>
    </div>
</section>

<script src="scripts/localize.js?<?= $version ?>"></script>

<?php
require_once 'includes/footer.php';
?>
