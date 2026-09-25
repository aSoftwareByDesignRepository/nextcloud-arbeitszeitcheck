<?php
declare(strict_types=1);

/**
 * Admin overtime settings — bank cap and hour premiums.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$settings = is_array($_['settings'] ?? null) ? $_['settings'] : [];
$policyPages = is_array($_['policyPages'] ?? null) ? $_['policyPages'] : [];

// Premium night preset is passed from controller
$premiumNightPreset = $_['premiumNightPreset'] ?? 'at';
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <div class="azc-admin-policy-layout azc-admin-overtime-settings-layout">
			<?php include __DIR__ . '/common/azc-policy-pages-nav.php'; ?>
            <form id="admin-overtime-settings-form"
				class="form admin-settings-form admin-notifications-form admin-policy-settings-form"
				novalidate
				data-policy-scope="overtime"
				data-premium-night-preset="<?php p($premiumNightPreset); ?>">
                <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">

                <div class="azc-admin-policy-form__sections">
					<?php include __DIR__ . '/partials/admin-policy-overtime-bank.php'; ?>
					<?php include __DIR__ . '/partials/admin-policy-hour-premiums.php'; ?>
                </div>

                <div class="azc-admin-policy-form__actions azc-admin-policy-form__actions--sticky" role="group" aria-labelledby="admin-overtime-settings-actions-heading">
                    <h2 id="admin-overtime-settings-actions-heading" class="visually-hidden"><?php p($l->t('Save')); ?></h2>
                    <div id="admin-overtime-settings-live" class="admin-notifications-live azc-admin-policy-live" role="status" aria-live="polite" aria-atomic="true"></div>
                    <div class="azc-admin-policy-form__footer">
                        <button type="submit"
                            class="azc-btn azc-btn--primary azc-btn--touch"
                            id="admin-overtime-settings-save"
                            aria-label="<?php p($l->t('Save overtime and premium settings')); ?>">
                            <?php p($l->t('Save')); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.adminNotificationsApiUrl = <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.admin.updateNotificationSettings'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.adminNotificationSettings = <?php echo json_encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.adminPolicyPages = <?php echo json_encode($policyPages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
window.ArbeitszeitCheck.l10n.notificationsSaved = <?php echo json_encode($l->t('Saved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.invalidBankFillOrder = <?php echo json_encode($l->t('Bank fill yellow percent must be less than or equal to red percent.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.failedToSaveNotifications = <?php echo json_encode($l->t('Could not save — try again.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.settingsBusyRetrying = <?php echo json_encode($l->t('Still busy — trying again…'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
