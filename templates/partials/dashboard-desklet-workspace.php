<?php

declare(strict_types=1);

/**
 * Compact punch-clock workspace for the Nextcloud dashboard desklet and optional embeds.
 *
 * @var array $_ assigned by DashboardDeskletWorkspaceRenderer
 */

/** @var array $config */
$deskletConfig = is_array($_['deskletConfig'] ?? null) ? $_['deskletConfig'] : [];
$config = $deskletConfig;
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? null;
if (!$l instanceof \OCP\IL10N) {
	$l = \OCP\Server::get(\OCP\L10N\IFactory::class)->get('arbeitszeitcheck');
}
$l10n = is_array($config['l10n'] ?? null) ? $config['l10n'] : [];
$projectCheck = is_array($config['projectCheck'] ?? null) ? $config['projectCheck'] : [];
$projectOptions = [];
if (!empty($projectCheck['linkingEnabled']) && is_array($projectCheck['projects'] ?? null)) {
	foreach ($projectCheck['projects'] as $project) {
		if (!is_array($project)) {
			continue;
		}
		$id = trim((string)($project['id'] ?? ''));
		if ($id === '') {
			continue;
		}
		$label = trim((string)($project['displayName'] ?? $project['name'] ?? ''));
		if ($label === '') {
			$label = $id;
		}
		$projectOptions[] = ['id' => $id, 'label' => $label];
	}
}
$showProjectPicker = $projectOptions !== [];
$showProjectLinkingOff = !empty($projectCheck['available']) && empty($projectCheck['linkingEnabled']);
?>
<div class="dz-workspace" data-arbeitszeitcheck-desklet="1">
	<script type="application/json" id="dz-config"><?php print_unescaped(json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)); ?></script>

	<header class="dz-header-section">
		<h2 class="dz-header__title"><?php p($l10n['deskletTitle'] ?? $l->t('Quick time tracking')); ?></h2>
		<p class="dz-header__desc"><?php p($l10n['deskletLead'] ?? $l->t('Clock in, take a break, or clock out from here.')); ?></p>
	</header>

	<p id="dz-live-status" class="dz-sr-only" aria-live="polite" aria-atomic="true"></p>

	<div id="dz-error-panel" class="dz-error-panel" role="alert" aria-live="assertive" hidden>
		<p id="dz-error" class="dz-error-panel__text"></p>
		<button type="button" class="azc-btn azc-btn--secondary dz-retry" id="dz-retry"><?php p($l10n['tryAgain'] ?? $l->t('Try again')); ?></button>
	</div>

	<p id="dz-feedback" class="dz-feedback" role="status" hidden></p>

	<section class="dz-section dz-status-section" id="dz-status-section" aria-labelledby="dz-status-section-title" aria-busy="false">
		<div class="dz-section__header">
			<h3 id="dz-status-section-title" class="dz-section__title"><?php p($l->t('Your status')); ?></h3>
		</div>

		<div id="dz-capture-notice" class="dz-capture-notice" hidden></div>
		<div id="dz-daily-max-notice" class="dz-capture-notice dz-capture-notice--warning" role="status" hidden></div>
		<?php if ($showProjectLinkingOff): ?>
		<div id="dz-project-linking-off" class="dz-capture-notice dz-capture-notice--neutral" role="status">
			<p class="dz-capture-notice__title"><?php p($l10n['projectLinkingOffTitle'] ?? $l->t('ProjectCheck linking is turned off')); ?></p>
			<p class="dz-capture-notice__text"><?php p($l10n['projectLinkingOffBody'] ?? $l->t('An administrator must enable the ProjectCheck connection in Global settings before you can link hours to a project.')); ?></p>
		</div>
		<?php endif; ?>

		<article class="dz-status-card" id="dz-status-card" data-status="clocked_out" aria-labelledby="dz-status-badge">
			<div class="dz-status-card__header">
				<div class="dz-status-title-wrap">
					<span id="dz-status-icon" class="dz-status-icon" aria-hidden="true"></span>
					<div class="dz-status-headings">
						<p class="dz-status-eyebrow"><?php p($l->t('Current status')); ?></p>
						<p id="dz-status-badge" class="dz-status-badge" data-status="clocked_out"><?php p($l10n['clockedOut'] ?? $l->t('Clocked Out')); ?></p>
						<p id="dz-status-text" class="dz-status-text"></p>
					</div>
				</div>
			</div>
			<div class="dz-status-metrics" role="list">
				<div class="dz-metric" role="listitem">
					<p class="dz-metric__label" id="dz-worked-today-label"><?php p($l10n['workedToday'] ?? $l->t('Worked today')); ?></p>
					<p class="dz-metric__value" id="dz-worked-today" aria-labelledby="dz-worked-today-label">—</p>
				</div>
				<div class="dz-metric" role="listitem">
					<p class="dz-metric__label" id="dz-session-label"><?php p($l10n['sessionDuration'] ?? $l->t('Session')); ?></p>
					<p class="dz-metric__value" id="dz-session-duration" aria-labelledby="dz-session-label">—</p>
				</div>
			</div>
		</article>

		<?php if ($showProjectPicker): ?>
		<div class="dz-project-picker" id="dz-project-picker" role="group" aria-labelledby="dz-project-label">
			<label for="dz-clock-in-project" id="dz-project-label" class="dz-project-picker__label">
				<?php p($l10n['projectLabel'] ?? $l->t('Project')); ?>
			</label>
			<select id="dz-clock-in-project"
				class="dz-project-picker__select"
				aria-describedby="dz-project-help">
				<option value=""><?php p($l10n['projectNone'] ?? $l->t('No project')); ?></option>
				<?php foreach ($projectOptions as $opt): ?>
					<option value="<?php p($opt['id']); ?>"><?php p($opt['label']); ?></option>
				<?php endforeach; ?>
			</select>
			<p id="dz-project-help" class="dz-project-picker__help">
				<?php p($l10n['projectHelp'] ?? $l->t('Optional. Link these hours to a ProjectCheck project, or leave “No project”.')); ?>
			</p>
		</div>
		<?php endif; ?>

		<div class="dz-button-row" role="group" aria-label="<?php p($l->t('Time tracking actions')); ?>">
			<button type="button" class="azc-btn azc-btn--primary" id="dz-clock-in"><?php p($l10n['clockIn'] ?? $l->t('Clock In')); ?></button>
			<button type="button" class="azc-btn azc-btn--secondary" id="dz-start-break"><?php p($l10n['startBreak'] ?? $l->t('Start Break')); ?></button>
			<button type="button" class="azc-btn azc-btn--secondary" id="dz-end-break"><?php p($l10n['endBreak'] ?? $l->t('End Break')); ?></button>
			<button type="button" class="azc-btn azc-btn--danger" id="dz-clock-out"><?php p($l10n['clockOut'] ?? $l->t('Clock Out')); ?></button>
		</div>

		<p id="dz-last-updated" class="dz-last-updated" aria-live="polite"></p>
	</section>

	<?php if (!empty($config['isManager'])): ?>
	<section class="dz-section dz-section--team" aria-labelledby="dz-team-section-title">
		<div class="dz-section__header">
			<h3 id="dz-team-section-title" class="dz-section__title"><?php p($l10n['teamOverview'] ?? $l->t('Team overview')); ?></h3>
		</div>
		<div id="dz-manager-list" class="dz-people-list" role="region" aria-live="polite" aria-busy="false"></div>
	</section>
	<?php endif; ?>

	<?php if (!empty($config['isAdmin'])): ?>
	<section class="dz-section dz-section--admin" aria-labelledby="dz-admin-section-title">
		<div class="dz-section__header">
			<h3 id="dz-admin-section-title" class="dz-section__title"><?php p($l10n['companyOverview'] ?? $l->t('Company overview')); ?></h3>
		</div>
		<div id="dz-admin-list" class="dz-people-list" role="region" aria-live="polite" aria-busy="false"></div>
	</section>
	<?php endif; ?>

	<nav class="dz-link-row" aria-label="<?php p($l->t('Quick navigation')); ?>">
		<a class="azc-btn azc-btn--secondary" href="<?php p($config['dashboardUrl'] ?? ''); ?>"><?php p($l10n['openDashboard'] ?? $l->t('Open full dashboard')); ?></a>
		<a class="azc-btn azc-btn--secondary" href="<?php p($config['timeEntriesUrl'] ?? ''); ?>"><?php p($l10n['openTimeEntries'] ?? $l->t('Open time entries')); ?></a>
	</nav>
</div>
