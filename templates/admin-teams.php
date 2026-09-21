<?php

declare(strict_types=1);

/**
 * Admin teams template: app-owned teams/departments with members and managers.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$useAppTeams = (bool)($_['useAppTeams'] ?? false);
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack admin-teams">

	<?php if (!$useAppTeams): ?>
	<?php
	$calloutVariant = 'warning';
	$calloutRole = 'status';
	$calloutTitleId = 'teams-enable-app-teams-title';
	$calloutTitle = $l->t('App teams are disabled');
	$calloutText = $l->t('Manager approvals, the manager dashboard, and team-scoped reports require ArbeitszeitCheck teams. Enable the option below and assign at least one manager per unit.');
	$calloutActions = [];
	include __DIR__ . '/common/alert-callout.php';
	?>
	<?php endif; ?>

	<section class="azc-card admin-teams__config" aria-labelledby="teams-config-heading">
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="teams-config-heading" class="azc-card__title"><?php p($l->t('Manager assignment')); ?></h2>
				<p class="azc-card__lead">
					<?php p($l->t('Choose whether managers are resolved from ArbeitszeitCheck teams or from shared Nextcloud groups.')); ?>
				</p>
			</div>
		</header>
		<div class="azc-card__body">
			<div class="azc-switch-field admin-teams__switch">
				<input
					type="checkbox"
					id="use-app-teams"
					name="useAppTeams"
					class="azc-switch-field__input"
					role="switch"
					<?php if ($useAppTeams) {
						p('checked');
					} ?>
					aria-checked="<?php p($useAppTeams ? 'true' : 'false'); ?>"
					aria-describedby="use-app-teams-desc"
				>
				<label for="use-app-teams" class="azc-switch-field__label">
					<span class="azc-switch-field__track" aria-hidden="true"></span>
					<span class="azc-switch-field__text"><?php p($l->t('Use ArbeitszeitCheck teams for approvals')); ?></span>
				</label>
			</div>
			<p id="use-app-teams-desc" class="admin-teams__help">
				<?php p($l->t('When enabled, managers are taken from the organizational structure below. When disabled, managers come from shared Nextcloud groups (default). Changes apply immediately after you toggle.')); ?>
			</p>
		</div>
	</section>

	<section class="azc-card admin-teams__structure" aria-labelledby="teams-list-heading">
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="teams-list-heading" class="azc-card__title"><?php p($l->t('Organizational structure')); ?></h2>
				<p class="azc-card__lead">
					<?php p($l->t('Add units, then select one to manage members and managers.')); ?>
				</p>
			</div>
			<div class="azc-card__header-actions">
				<button type="button" id="admin-teams-add" class="azc-btn azc-btn--primary"
					aria-label="<?php p($l->t('Add new organizational unit')); ?>">
					<?php p($l->t('Add unit')); ?>
				</button>
			</div>
		</header>
		<div class="azc-card__body">
			<div id="admin-teams-tree" class="admin-teams__tree-panel teams-tree org-tree" role="tree" aria-label="<?php p($l->t('Organization structure')); ?>">
				<p id="teams-loading" class="admin-teams__tree-message" aria-live="polite"><?php p($l->t('Loading…')); ?></p>
				<p id="teams-empty" class="admin-teams__tree-message hidden" aria-live="polite"><?php p($l->t('No units yet. Add a unit to build your organization structure.')); ?></p>
			</div>
		</div>
	</section>

	<section id="admin-team-detail" class="azc-card admin-teams__detail hidden" aria-labelledby="team-detail-heading">
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="team-detail-heading" class="azc-card__title">
					<span class="visually-hidden"><?php p($l->t('Selected unit')); ?> </span>
					<span id="team-detail-name"></span>
				</h2>
			</div>
		</header>
		<div class="azc-card__body">
			<div class="admin-teams__tabs team-detail-tabs" role="tablist" aria-label="<?php p($l->t('Team members and managers')); ?>">
				<button type="button" id="tab-members" class="admin-teams__tab" role="tab" aria-selected="true" aria-controls="panel-members">
					<?php p($l->t('Members')); ?>
				</button>
				<button type="button" id="tab-managers" class="admin-teams__tab" role="tab" aria-selected="false" aria-controls="panel-managers">
					<?php p($l->t('Managers')); ?>
				</button>
			</div>
			<div id="panel-members" role="tabpanel" aria-labelledby="tab-members" class="admin-teams__panel team-panel">
				<div class="admin-teams__panel-actions panel-actions">
					<button type="button" id="team-add-member" class="azc-btn azc-btn--secondary"
						aria-label="<?php p($l->t('Add member to team')); ?>">
						<?php p($l->t('Add member')); ?>
					</button>
					<button type="button" id="team-add-members-bulk" class="azc-btn azc-btn--primary"
						aria-label="<?php p($l->t('Add several people to this team')); ?>">
						<?php p($l->t('Add several people…')); ?>
					</button>
				</div>
				<ul id="team-members-list" class="admin-teams__list team-list" aria-label="<?php p($l->t('Team members')); ?>"></ul>
			</div>
			<div id="panel-managers" role="tabpanel" aria-labelledby="tab-managers" class="admin-teams__panel team-panel hidden" hidden>
				<div class="admin-teams__panel-actions panel-actions">
					<button type="button" id="team-add-manager" class="azc-btn azc-btn--secondary"
						aria-label="<?php p($l->t('Add manager to team')); ?>">
						<?php p($l->t('Add manager')); ?>
					</button>
					<button type="button" id="team-add-managers-bulk" class="azc-btn azc-btn--primary"
						aria-label="<?php p($l->t('Add several managers to this team')); ?>">
						<?php p($l->t('Add several managers…')); ?>
					</button>
				</div>
				<ul id="team-managers-list" class="admin-teams__list team-list" aria-label="<?php p($l->t('Team managers')); ?>"></ul>
			</div>
		</div>
	</section>

	<div role="status" aria-live="polite" id="admin-teams-status" class="visually-hidden"></div>

</div>

<?php include __DIR__ . '/common/teams-l10n.php'; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
