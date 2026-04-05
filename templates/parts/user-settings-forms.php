<?php

declare(strict_types=1);

/**
 * Shared user preference forms (working time, notifications, calendar, model info, privacy).
 * Parents must wrap output in `.settings-container` where appropriate.
 *
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 * @var bool $embedded Embedded in Nextcloud Personal settings (omit Cancel-to-dashboard links)
 *
 * @copyright Copyright (c) 2024-2026
 * @license AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
$embedded = !empty($embedded);
$dashboardUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.page.index');
$gdprExportUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.gdpr.export');
?>

<div id="azc-settings-config"
	hidden
	data-update-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.settings.update')); ?>"
	data-settings-legacy-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.settings.index_api')); ?>"></div>

<section class="settings-section azc-user-settings__section" aria-labelledby="azc-wt-heading">
	<h3 id="azc-wt-heading" class="section-title"><?php p($l->t('Working Time Preferences')); ?></h3>
	<form id="working-time-settings-form" class="form" method="post" action="#">
		<div class="form-group">
			<div class="form-checkbox">
				<input type="checkbox"
					id="auto-break-calculation"
					name="auto_break_calculation"
					aria-describedby="auto-break-calculation-help">
				<label for="auto-break-calculation" class="form-label">
					<?php p($l->t('Calculate breaks automatically')); ?>
				</label>
			</div>
			<p id="auto-break-calculation-help" class="form-help">
				<?php p($l->t('The system will automatically calculate when you need to take breaks according to German labor law. For example, if you work more than 6 hours, you must take at least a 30-minute break.')); ?>
			</p>
		</div>
		<div class="card-actions">
			<button type="submit" class="btn btn--primary">
				<?php p($l->t('Save Settings')); ?>
			</button>
			<?php if (!$embedded) { ?>
				<a href="<?php p($dashboardUrl); ?>" class="btn btn--secondary">
					<?php p($l->t('Cancel')); ?>
				</a>
			<?php } ?>
		</div>
	</form>
</section>

<section class="settings-section azc-user-settings__section" aria-labelledby="azc-notify-heading">
	<h3 id="azc-notify-heading" class="section-title"><?php p($l->t('Notifications')); ?></h3>
	<form id="notification-settings-form" class="form" method="post" action="#">
		<div class="form-group">
			<div class="form-checkbox">
				<input type="checkbox" id="notifications-enabled" name="notifications_enabled">
				<label for="notifications-enabled" class="form-label">
					<?php p($l->t('Enable Notifications')); ?>
				</label>
			</div>
		</div>
		<div class="form-group">
			<div class="form-checkbox">
				<input type="checkbox"
					id="break-reminders"
					name="break_reminders_enabled"
					aria-describedby="break-reminders-help">
				<label for="break-reminders" class="form-label">
					<?php p($l->t('Remind me to take breaks')); ?>
				</label>
			</div>
			<p id="break-reminders-help" class="form-help">
				<?php p($l->t('Get a notification when it\'s time to take a required break. For example, if you work more than 6 hours, you\'ll get a reminder to take at least a 30-minute break.')); ?>
			</p>
		</div>
		<div class="card-actions">
			<button type="submit" class="btn btn--primary">
				<?php p($l->t('Save Settings')); ?>
			</button>
			<?php if (!$embedded) { ?>
				<a href="<?php p($dashboardUrl); ?>" class="btn btn--secondary">
					<?php p($l->t('Cancel')); ?>
				</a>
			<?php } ?>
		</div>
	</form>
</section>

<section class="settings-section azc-user-settings__section" aria-labelledby="azc-model-heading">
	<h3 id="azc-model-heading" class="section-title"><?php p($l->t('Working Time Model')); ?></h3>
	<div id="working-time-model-info" class="info-box" role="status">
		<p><?php p($l->t('Your working time model, vacation days, and working hours are assigned by your administrator. Contact your administrator if you have questions or need changes.')); ?></p>
	</div>
</section>

<section class="settings-section azc-user-settings__section azc-user-settings__section--calendar" aria-labelledby="azc-cal-heading">
	<h3 id="azc-cal-heading" class="section-title"><?php p($l->t('Nextcloud Calendar')); ?></h3>

	<div class="azc-calendar-panel" role="region" aria-labelledby="azc-cal-heading">
		<p class="azc-calendar-panel__privacy" id="azc-cal-privacy-note">
			<?php p($l->t('Privacy note: synced absence titles are generic; event text never includes leave type, reason, or employee names. Shared calendars may still list your account as owner.')); ?>
		</p>

		<div class="azc-calendar-panel__block">
			<h4 id="azc-cal-abs-head" class="azc-calendar-panel__subhead"><?php p($l->t('Absence calendar')); ?></h4>
			<p class="form-help form-help--block" id="azc-cal-abs-desc">
				<?php p($l->t('Approved absences appear as generic all-day events in the calendar "%1$s". Your administrator controls whether sync is on.', [$l->t('ArbeitszeitCheck absences')])); ?>
			</p>
		</div>

		<div class="azc-calendar-panel__block azc-calendar-panel__block--form">
			<h4 id="azc-cal-holidays-head" class="azc-calendar-panel__subhead"><?php p($l->t('Public holidays in your calendar')); ?></h4>
			<form id="calendar-sync-settings-form" class="form azc-calendar-panel__form" method="post" action="#" aria-labelledby="azc-cal-holidays-head">
				<div class="form-group">
					<div class="form-checkbox">
						<input type="checkbox"
							id="nc-calendar-sync-holidays"
							name="nc_calendar_sync_holidays"
							value="1"
							aria-describedby="azc-cal-holidays-head nc-calendar-holidays-help azc-cal-privacy-note">
						<label for="nc-calendar-sync-holidays" class="form-label"><?php p($l->t('Show public holidays (Feiertage) in my Calendar app')); ?></label>
					</div>
					<p id="nc-calendar-holidays-help" class="form-help"><?php p($l->t('Uses your German state under Working time / holidays settings. Updates run at least daily.')); ?></p>
				</div>
				<div class="card-actions">
					<button type="submit" class="btn btn--primary"><?php p($l->t('Save calendar settings')); ?></button>
				</div>
			</form>
		</div>
	</div>
</section>

<section class="settings-section azc-user-settings__section" aria-labelledby="azc-privacy-heading">
	<h3 id="azc-privacy-heading" class="section-title"><?php p($l->t('Your data and privacy')); ?></h3>
	<p><?php p($l->t('Export a copy of your data (GDPR). For account deletion or other requests, contact your administrator.')); ?></p>
	<div class="card-actions">
		<a href="<?php p($gdprExportUrl); ?>"
			class="btn btn--secondary"
			download>
			<?php p($l->t('Export My Data')); ?>
		</a>
	</div>
</section>
