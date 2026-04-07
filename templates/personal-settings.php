<?php

declare(strict_types=1);

/**
 * Personal settings template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <div id="arbeitszeitcheck-personal-settings" class="timetracking-settings section">
            <div class="section-header">
                <h2><?php p($l->t('ArbeitszeitCheck Personal Settings')); ?></h2>
                <p><?php p($l->t('Set up your personal time tracking preferences and notifications')); ?></p>
            </div>

	<div class="timetracking-settings__content">
		<div class="timetracking-settings-section">
			<h3><?php p($l->t('Working Time Preferences')); ?></h3>
			<div class="form-group">
				<label for="vacation-days" class="form-label">
					<?php p($l->t('Vacation Days Per Year')); ?>
				</label>
				<input
					type="number"
					id="vacation-days"
					class="form-input"
					min="0"
					max="366"
					value="25"
					aria-describedby="vacation-days-help"
				>
				<p id="vacation-days-help" class="form-help">
					<?php p($l->t('How many vacation days you get each year. In Germany, the standard is 25 days. Example: Enter 25 if you get 25 vacation days per year.')); ?>
				</p>
			</div>

			<div class="form-group">
				<label for="working-hours" class="form-label">
					<?php p($l->t('Normal Working Hours Per Day')); ?>
				</label>
				<input
					type="number"
					id="working-hours"
					class="form-input"
					min="1"
					max="24"
					step="0.1"
					value="8"
					aria-describedby="working-hours-help"
				>
				<p id="working-hours-help" class="form-help">
					<?php p($l->t('How many hours you normally work each day. The standard is 8 hours. Decimal values are allowed (e.g. 7.74 for a 38.7 h week spread over five days).')); ?>
				</p>
			</div>
		</div>

		<div class="timetracking-settings-section">
			<h3><?php p($l->t('Notifications')); ?></h3>
			<div class="form-group">
				<div class="form-checkbox">
					<input type="checkbox" 
					       id="remind-clock-out" 
					       checked
					       aria-describedby="remind-clock-out-help">
					<label for="remind-clock-out" class="form-label">
						<?php p($l->t('Remind me to clock out')); ?>
					</label>
				</div>
				<p id="remind-clock-out-help" class="form-help">
					<?php p($l->t('Get a notification if you forget to clock out at the end of your work day. This helps make sure your time records are accurate.')); ?>
				</p>
			</div>

			<div class="form-group">
				<div class="form-checkbox">
					<input type="checkbox" 
					       id="remind-breaks" 
					       checked
					       aria-describedby="remind-breaks-help">
					<label for="remind-breaks" class="form-label">
						<?php p($l->t('Remind me to take breaks')); ?>
					</label>
				</div>
				<p id="remind-breaks-help" class="form-help">
					<?php p($l->t('Get a notification when it\'s time to take a required break. For example, if you work more than 6 hours, you\'ll get a reminder to take at least a 30-minute break.')); ?>
				</p>
			</div>
		</div>

		<div class="timetracking-settings-section">
			<h3><?php p($l->t('Download Your Data')); ?></h3>
			<p><?php p($l->t('Download a copy of all your time tracking records. You can save this file for your own records or use it with other programs.')); ?></p>
			<button class="btn btn--secondary" 
			        type="button"
			        aria-label="<?php p($l->t('Download all your time tracking data')); ?>"
			        title="<?php p($l->t('Click to download a file containing all your time tracking records')); ?>">
				<?php p($l->t('Download My Data')); ?>
			</button>
		</div>

		<div class="timetracking-settings-section">
			<h3><?php p($l->t('Your Data Rights')); ?></h3>
			<p><?php p($l->t('You have the right to see all your personal data, correct any mistakes, or ask to have your data deleted. This is required by European data protection law (GDPR).')); ?></p>
			<div class="btn-group">
				<button class="btn btn--secondary" 
				        type="button"
				        aria-label="<?php p($l->t('Request to see all your personal data')); ?>"
				        title="<?php p($l->t('Click to request a copy of all your personal data stored in this system')); ?>">
					<?php p($l->t('See My Data')); ?>
				</button>
				<button class="btn btn--danger" 
				        type="button"
				        aria-label="<?php p($l->t('Request to delete all your personal data')); ?>"
				        title="<?php p($l->t('Click to request deletion of all your personal data. This action cannot be undone.')); ?>">
					<?php p($l->t('Delete My Data')); ?>
				</button>
			</div>
		</div>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->