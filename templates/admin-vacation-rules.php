<?php

declare(strict_types=1);

/**
 * Admin · Vacation rules (year, carryover, unit, pro-rata) — one job.
 *
 * Entitlement layers live on /admin/vacation-layers (catalog: vacation-entitlement).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

use OCA\ArbeitszeitCheck\Util\TemplateL10n;

/** @var array<string, mixed> $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$settings = is_array($_['vacationPolicySettings'] ?? null) ? $_['vacationPolicySettings'] : [];
$policyPages = is_array($_['policyPages'] ?? null) ? $_['policyPages'] : [];
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack">
	<div class="azc-admin-policy-layout azc-admin-vacation-policy-layout">
		<?php include __DIR__ . '/common/azc-policy-pages-nav.php'; ?>
		<form id="admin-vacation-policy-form"
			class="form admin-settings-form admin-notifications-form admin-policy-settings-form"
			novalidate
			data-policy-scope="vacation">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">
				<input type="hidden" id="generalNotificationRevision" name="generalNotificationRevision"
					value="<?php p((string)($settings['generalNotificationRevision'] ?? '')); ?>">
			<div class="azc-admin-policy-form__sections">
				<?php
				$azcPolicyShowCardChrome = false;
				include __DIR__ . '/partials/admin-policy-vacation.php';
				?>
			</div>
			<div class="azc-admin-policy-form__actions azc-admin-policy-form__actions--sticky" role="group" aria-labelledby="admin-vacation-policy-actions-heading">
				<h2 id="admin-vacation-policy-actions-heading" class="visually-hidden"><?php p($l->t('Save')); ?></h2>
				<div id="admin-vacation-policy-live" class="admin-notifications-live azc-admin-policy-live" role="status" aria-live="polite" aria-atomic="true"></div>
				<div class="azc-admin-policy-form__footer">
					<button type="submit"
						class="azc-btn azc-btn--primary azc-btn--touch"
						id="admin-vacation-policy-save"
						aria-label="<?php p($l->t('Save vacation rules')); ?>">
						<?php p($l->t('Save')); ?>
					</button>
				</div>
			</div>
		</form>
	</div>
</div>

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.adminNotificationsApiUrl = <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.admin.updateNotificationSettings'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.adminNotificationSettings = <?php echo json_encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.adminPolicyPages = <?php echo json_encode($policyPages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.apiUrl = window.ArbeitszeitCheck.apiUrl || {};
window.ArbeitszeitCheck.apiUrl.migrateVacationUnit = <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.admin.migrateVacationUnit'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
window.ArbeitszeitCheck.l10n.notificationsSaved = <?php echo json_encode($l->t('Saved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.invalidCarryoverMaxDays = <?php echo json_encode($l->t('Maximum carryover days must be empty (unlimited) or between 0 and 366'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.invalidCarryoverMaxHours = <?php echo json_encode($l->t('Maximum carryover hours must be empty (unlimited) or between 0 and 4000'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.failedToSaveNotifications = <?php echo json_encode($l->t('Could not save — try again.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.settingsBusyRetrying = <?php echo json_encode($l->t('Still busy — trying again…'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.vacationUnitMigrating = <?php echo json_encode($l->t('Converting vacation unit…'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.vacationUnitMigrated = <?php echo json_encode($l->t('Vacation unit converted successfully.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.vacationUnitMigrateFailed = <?php echo json_encode($l->t('Could not convert vacation unit.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.vacationUnitNeedClientConfirm = <?php echo json_encode($l->t('Tick the Employee app confirmation checkbox before converting to hours.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.vacationUnitFactorHint = <?php echo json_encode($l->t('Same unit selected. Apply updates the hours-per-day factor only (balances stay as they are).'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.vacationUnitHoursPerDayInvalid = <?php echo json_encode($l->t('Hours per day must be between 0.25 and 24.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.vacationUnitConfirmHours = <?php echo json_encode(TemplateL10n::translate($l, 'Convert all open vacation balances and absences to hours using %s hours per day? This cannot be undone without converting back.'), TemplateL10n::JSON_ENCODE_FLAGS); ?>;
window.ArbeitszeitCheck.l10n.vacationUnitConfirmDays = <?php echo json_encode(TemplateL10n::translate($l, 'Convert all open vacation balances back to days using %s hours per day (from the last conversion when available)? Partial-hour bookings become fractional days (for example 4 hours → 0.5 days).'), TemplateL10n::JSON_ENCODE_FLAGS); ?>;
window.ArbeitszeitCheck.l10n.vacationHoursBanssApplied = <?php echo json_encode($l->t('Set conversion factor to 7.7 (BANSS 38.5 ÷ 5).'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.vacationYearMissingHireAckRequired = <?php echo json_encode($l->t('Confirm that people without a hire date will have no vacation until a start date is set.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<?php include __DIR__ . '/common/page-end.php'; ?>
