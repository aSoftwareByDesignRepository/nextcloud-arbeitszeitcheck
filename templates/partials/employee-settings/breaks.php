<?php
declare(strict_types=1);

/**
 * Employee settings — Breaks (auto-break + read-only working time model).
 *
 * @var \OCP\IL10N $l
 * @var array $complianceProfile
 * @var bool $azcSettingsShowCardChrome
 */

$complianceProfile = is_array($complianceProfile ?? null) ? $complianceProfile : [];
$showChrome = !empty($azcSettingsShowCardChrome);
?>
<section class="settings-section azc-card" aria-labelledby="settings-sections-heading">
	<header class="azc-card__header<?php echo $showChrome ? '' : ' azc-card__header--page-title-only'; ?>">
		<div class="azc-card__header-text">
			<?php if ($showChrome): ?>
				<h2 id="settings-sections-heading" class="azc-card__title"><?php p($l->t('Working Time Preferences')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Control how the app handles your breaks.')); ?></p>
			<?php else: ?>
				<h2 id="settings-sections-heading" class="azc-card__title visually-hidden"><?php p($l->t('Breaks')); ?></h2>
			<?php endif; ?>
		</div>
	</header>
	<div class="azc-card__body">
		<form id="working-time-settings-form" class="settings-form">
			<div class="settings-form__group">
				<div class="settings-form__checkbox">
					<input type="checkbox"
						id="auto-break-calculation"
						name="auto_break_calculation"
						checked
						aria-describedby="auto-break-calculation-help">
					<label for="auto-break-calculation" class="form-label">
						<?php p($l->t('Calculate breaks automatically')); ?>
					</label>
				</div>
				<p id="auto-break-calculation-help" class="settings-form__help">
					<?php
					$azcAutoBreakHelp = match ($complianceProfile['country'] ?? 'DE') {
						'AT' => $l->t('The system will automatically calculate when you need to take breaks according to Austrian working time law (AZG). For example, after more than 6 hours you need at least a 30-minute break.'),
						'CH' => $l->t('The system will automatically calculate when you need to take breaks according to Swiss labour law (ArG). For example, after 5.5 hours you need at least a 15-minute break.'),
						default => $l->t('The system will automatically calculate when you need to take breaks according to German labor law. For example, if you work more than 6 hours, you must take at least a 30-minute break.'),
					};
					p($azcAutoBreakHelp);
					?>
				</p>
			</div>
			<div class="settings-form__group">
				<label for="hours-display" class="form-label"><?php p($l->t('Hours display')); ?></label>
				<select id="hours-display"
					name="hours_display"
					class="form-select"
					aria-describedby="hours-display-help">
					<option value="decimal" selected><?php p($l->t('Decimal hours (5.5)')); ?></option>
					<option value="hours_minutes"><?php p($l->t('Hours and minutes (5h 30)')); ?></option>
				</select>
				<p id="hours-display-help" class="settings-form__help">
					<?php p($l->t('How working time totals are shown in the app. Decimal stays the default; choose hours and minutes if you prefer clock-style durations.')); ?>
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

		<div id="working-time-model-info" class="azc-callout azc-callout--neutral" role="note" aria-labelledby="settings-model-heading">
			<h3 id="settings-model-heading" class="azc-callout__title"><?php p($l->t('Working Time Model')); ?></h3>
			<p class="azc-callout__text"><?php p($l->t('Your working time model, vacation days, and working hours are assigned by your administrator. Contact your administrator if you have questions or need changes.')); ?></p>
		</div>
	</div>
</section>
