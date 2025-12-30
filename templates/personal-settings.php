<?php

declare(strict_types=1);

/**
 * Personal settings template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

// Script loading is now handled in SettingsController::index() using Util::addScript()
// This replaces the deprecated script() function and follows Nextcloud best practices
style('arbeitszeitcheck', 'arbeitszeitcheck-main');
?>

<div id="arbeitszeitcheck-personal-settings" class="timetracking-settings">
	<div class="timetracking-settings__header">
		<h2><?php p($l->t('ArbeitszeitCheck Personal Settings')); ?></h2>
		<p><?php p($l->t('Configure your personal time tracking preferences')); ?></p>
	</div>

	<div class="timetracking-settings__content">
		<div class="timetracking-settings-section">
			<h3><?php p($l->t('Working Time Preferences')); ?></h3>
			<div class="timetracking-form-group">
				<label for="vacation-days" class="timetracking-form-label">
					<?php p($l->t('Annual Vacation Days')); ?>
				</label>
				<input
					type="number"
					id="vacation-days"
					class="timetracking-form-input"
					min="0"
					max="366"
					value="25"
				>
				<p class="timetracking-form-help">
					<?php p($l->t('Number of vacation days you are entitled to per year')); ?>
				</p>
			</div>

			<div class="timetracking-form-group">
				<label for="working-hours" class="timetracking-form-label">
					<?php p($l->t('Standard Working Hours per Day')); ?>
				</label>
				<input
					type="number"
					id="working-hours"
					class="timetracking-form-input"
					min="1"
					max="24"
					step="0.5"
					value="8"
				>
				<p class="timetracking-form-help">
					<?php p($l->t('Your standard working hours per day')); ?>
				</p>
			</div>
		</div>

		<div class="timetracking-settings-section">
			<h3><?php p($l->t('Notifications')); ?></h3>
			<div class="timetracking-form-group">
				<label class="timetracking-checkbox">
					<input type="checkbox" id="remind-clock-out" checked>
					<span class="timetracking-checkbox__checkmark"></span>
					<?php p($l->t('Remind me to clock out')); ?>
				</label>
				<p class="timetracking-form-help">
					<?php p($l->t('Send notifications if you forget to clock out')); ?>
				</p>
			</div>

			<div class="timetracking-form-group">
				<label class="timetracking-checkbox">
					<input type="checkbox" id="remind-breaks" checked>
					<span class="timetracking-checkbox__checkmark"></span>
					<?php p($l->t('Remind me about mandatory breaks')); ?>
				</label>
				<p class="timetracking-form-help">
					<?php p($l->t('Send notifications for required breaks')); ?>
				</p>
			</div>
		</div>

		<div class="timetracking-settings-section">
			<h3><?php p($l->t('Data Export')); ?></h3>
			<p><?php p($l->t('Download your time tracking data for external use or backup purposes.')); ?></p>
			<button class="timetracking-btn timetracking-btn--secondary">
				<?php p($l->t('Export My Data')); ?>
			</button>
		</div>

		<div class="timetracking-settings-section">
			<h3><?php p($l->t('GDPR Data Rights')); ?></h3>
			<p><?php p($l->t('Under GDPR, you have the right to access, rectify, or delete your personal data.')); ?></p>
			<div class="timetracking-btn-group">
				<button class="timetracking-btn timetracking-btn--secondary">
					<?php p($l->t('Request Data Access')); ?>
				</button>
				<button class="timetracking-btn timetracking-btn--danger">
					<?php p($l->t('Request Data Deletion')); ?>
				</button>
			</div>
		</div>
	</div>
</div>