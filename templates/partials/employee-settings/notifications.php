<?php
declare(strict_types=1);

/**
 * Employee settings — Notification preferences.
 *
 * @var \OCP\IL10N $l
 * @var array $complianceProfile
 * @var bool $azcSettingsShowCardChrome
 */

$complianceProfile = is_array($complianceProfile ?? null) ? $complianceProfile : [];
$showChrome = !empty($azcSettingsShowCardChrome);
?>
<section class="settings-section azc-card" aria-labelledby="settings-notifications-heading">
	<header class="azc-card__header<?php echo $showChrome ? '' : ' azc-card__header--page-title-only'; ?>">
		<div class="azc-card__header-text">
			<?php if ($showChrome): ?>
				<h2 id="settings-notifications-heading" class="azc-card__title"><?php p($l->t('Notifications')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Choose which reminders you want to receive.')); ?></p>
			<?php else: ?>
				<h2 id="settings-notifications-heading" class="azc-card__title visually-hidden"><?php p($l->t('Notifications')); ?></h2>
			<?php endif; ?>
		</div>
	</header>
	<div class="azc-card__body">
		<?php include __DIR__ . '/_section-intro.php'; ?>
		<form id="notification-settings-form" class="settings-form">
			<div class="settings-form__group">
				<div class="settings-form__checkbox">
					<input type="checkbox"
						id="notifications-enabled"
						name="notifications_enabled"
						checked>
					<label for="notifications-enabled" class="form-label">
						<?php p($l->t('Enable Notifications')); ?>
					</label>
				</div>
			</div>
			<div class="settings-form__group">
				<div class="settings-form__checkbox">
					<input type="checkbox"
						id="break-reminders"
						name="break_reminders_enabled"
						checked
						aria-describedby="break-reminders-help">
					<label for="break-reminders" class="form-label">
						<?php p($l->t('Remind me to take breaks')); ?>
					</label>
				</div>
				<p id="break-reminders-help" class="settings-form__help">
					<?php
					$azcBreakReminderHelp = match ($complianceProfile['country'] ?? 'DE') {
						'AT' => $l->t('Get a notification when it\'s time to take a required break. For example, after more than 6 hours you\'ll get a reminder to take at least a 30-minute break (AZG).'),
						'CH' => $l->t('Get a notification when it\'s time to take a required break. For example, after 5.5 hours you\'ll get a reminder to take at least a 15-minute break (ArG).'),
						default => $l->t('Get a notification when it\'s time to take a required break. For example, if you work more than 6 hours, you\'ll get a reminder to take at least a 30-minute break.'),
					};
					p($azcBreakReminderHelp);
					?>
				</p>
			</div>
			<div class="settings-form__group">
				<div class="settings-form__checkbox">
					<input type="checkbox"
						id="missing-clock-in-reminders"
						name="missing_clock_in_reminders_enabled"
						checked
						aria-describedby="missing-clock-in-reminders-help">
					<label for="missing-clock-in-reminders" class="form-label">
						<?php p($l->t('Remind me when I forgot to clock in (for expected workdays)')); ?>
					</label>
				</div>
				<p id="missing-clock-in-reminders-help" class="settings-form__help">
					<?php p($l->t('You receive this reminder only on regular working days. No reminder is sent on weekends, holidays, or approved absences.')); ?>
				</p>
			</div>
			<div class="settings-form__actions">
				<button type="submit"
					class="azc-btn azc-btn--primary"
					aria-label="<?php p($l->t('Save this page')); ?>">
					<?php p($l->t('Save settings')); ?>
				</button>
			</div>
		</form>
	</div>
</section>
