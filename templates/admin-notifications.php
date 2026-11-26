<?php
declare(strict_types=1);

/**
 * Admin notification settings — alerts and email delivery only.
 *
 * Design system: SETTINGS-PAGES-STANDARD (chip bar) + DESIGN-SYSTEM (tokens, 44px targets).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

use OCP\Util;

/** @var array $_ */
/** @var \OCP\IL10N $l */

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$settings = is_array($_['settings'] ?? null) ? $_['settings'] : [];
$absenceTypes = is_array($_['absenceTypes'] ?? null) ? $_['absenceTypes'] : [];
$eventTypes = is_array($_['eventTypes'] ?? null) ? $_['eventTypes'] : [];
$policyPages = is_array($_['policyPages'] ?? null) ? $_['policyPages'] : [];
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <div class="azc-admin-policy-layout azc-admin-notifications-layout">
			<?php include __DIR__ . '/common/azc-policy-pages-nav.php'; ?>
            <form id="admin-notifications-form"
				class="form admin-settings-form admin-notifications-form admin-policy-settings-form"
				novalidate
				data-policy-scope="notifications">
                <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">
				<input type="hidden" id="generalNotificationRevision" name="generalNotificationRevision"
					value="<?php p((string)($settings['generalNotificationRevision'] ?? '')); ?>">

                <div class="azc-admin-policy-form__sections">
					<?php include __DIR__ . '/partials/admin-policy-clock-reminders.php'; ?>
					<?php include __DIR__ . '/partials/admin-policy-calendar-email.php'; ?>
					<?php include __DIR__ . '/partials/admin-policy-overtime-alerts.php'; ?>
					<?php include __DIR__ . '/partials/admin-policy-hr-office.php'; ?>
                </div>

                <div class="azc-admin-policy-form__actions azc-admin-policy-form__actions--sticky" role="group" aria-labelledby="admin-notifications-actions-heading">
                    <h2 id="admin-notifications-actions-heading" class="visually-hidden"><?php p($l->t('Save')); ?></h2>
                    <div id="admin-notifications-live" class="admin-notifications-live azc-admin-policy-live" role="status" aria-live="polite" aria-atomic="true"></div>
                    <div class="azc-admin-policy-form__footer">
                        <button type="submit"
                            class="azc-btn azc-btn--primary azc-btn--touch"
                            id="admin-notifications-save"
                            aria-label="<?php p($l->t('Save notification settings')); ?>">
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
window.ArbeitszeitCheck.notificationMatrixMeta = {
	absenceTypes: <?php echo json_encode($absenceTypes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
	eventTypes: <?php echo json_encode($eventTypes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
};
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
window.ArbeitszeitCheck.l10n.notificationsSaved = <?php echo json_encode($l->t('Saved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.invalidRecipients = <?php echo json_encode($l->t('Please enter at least one valid recipient email address.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.invalidBalanceTrafficLightRecipients = <?php echo json_encode($l->t('Please enter at least one valid balance traffic light recipient email address (overtime/undertime).'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.invalidThresholdValues = <?php echo json_encode($l->t('Threshold values must be valid numbers.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.invalidThresholdOrder = <?php echo json_encode($l->t('Yellow thresholds must be less than or equal to red thresholds.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.failedToSaveNotifications = <?php echo json_encode($l->t('Could not save — try again.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.settingsBusyRetrying = <?php echo json_encode($l->t('Still busy — trying again…'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
