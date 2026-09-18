<?php

declare(strict_types=1);

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
/** @var \OCP\IURLGenerator $urlGenerator */
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);

use OCA\ArbeitszeitCheck\Support\BadgeVariant;

$teamStats = $_['teamStats'] ?? [];
$teamMembers = $_['teamMembers'] ?? [];
?>

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
(function() {
	window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
	window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
	Object.assign(window.ArbeitszeitCheck.l10n, {
		"Absence": <?php echo json_encode($l->t('Absence'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Vacation": <?php echo json_encode($l->t('Vacation'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Sick leave": <?php echo json_encode($l->t('Sick leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Personal leave": <?php echo json_encode($l->t('Personal leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Parental leave": <?php echo json_encode($l->t('Parental leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Special leave": <?php echo json_encode($l->t('Special leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Unpaid leave": <?php echo json_encode($l->t('Unpaid leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Home office": <?php echo json_encode($l->t('Home office'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Business trip": <?php echo json_encode($l->t('Business trip'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
	});
})();
</script>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack">
	<div class="manager-dashboard">
		<?php
		$useAppTeams = (bool)($_['useAppTeams'] ?? true);
		$adminTeamsUrl = (string)($_['adminTeamsUrl'] ?? '');
		if (!$useAppTeams && $adminTeamsUrl !== ''):
		?>
		<?php
		$calloutVariant = 'warning';
		$calloutRole = 'status';
		$calloutTitleId = 'manager-teams-off-title';
		$calloutTitle = $l->t('App teams are disabled');
		$calloutText = $l->t('Only administrators can open this page while teams are off. Employees assigned as group managers cannot approve absences or corrections until you enable app teams and assign managers.');
		$calloutActions = [[
			'href' => $adminTeamsUrl,
			'label' => $l->t('Open team settings'),
			'class' => 'azc-btn azc-btn--secondary azc-btn--sm',
		]];
		include __DIR__ . '/common/alert-callout.php';
		?>
		<?php endif; ?>

		<?php
		$totalMembers = (int)($teamStats['total_members'] ?? 0);
		$activeToday = (int)($teamStats['active_today'] ?? 0);
		$hoursToday = round((float)($teamStats['total_hours_today'] ?? 0), 1);
		$pendingAbsences = (int)($teamStats['pending_absences'] ?? 0);
		?>
		<section class="azc-stat-strip manager-dashboard__stats" aria-label="<?php p($l->t('Team statistics')); ?>">
			<article class="azc-stat-tile"
				aria-label="<?php p($l->n('%n team member', '%n team members', $totalMembers)); ?>">
				<span class="azc-stat-tile__label"><?php p($l->t('Team Members')); ?></span>
				<span class="azc-stat-tile__value" aria-hidden="true"><?php p($totalMembers); ?></span>
			</article>
			<article class="azc-stat-tile azc-stat-tile--primary"
				aria-label="<?php p($l->n('%n team member active today', '%n team members active today', $activeToday)); ?>">
				<span class="azc-stat-tile__label"><?php p($l->t('Active Today')); ?></span>
				<span class="azc-stat-tile__value" aria-hidden="true"><?php p($activeToday); ?></span>
			</article>
			<article class="azc-stat-tile"
				aria-label="<?php p($l->t('%s hours worked today by the team', [(string)$hoursToday])); ?>">
				<span class="azc-stat-tile__label"><?php p($l->t('Hours Today')); ?></span>
				<span class="azc-stat-tile__value" aria-hidden="true"><?php p($hoursToday); ?>h</span>
			</article>
			<a class="azc-stat-tile <?php echo $pendingAbsences > 0 ? 'azc-stat-tile--warning' : 'azc-stat-tile--neutral'; ?>"
				href="#pending-approvals-section"
				aria-label="<?php p($l->n('%n pending absence request — jump to approvals', '%n pending absence requests — jump to approvals', $pendingAbsences)); ?>">
				<span class="azc-stat-tile__label"><?php p($l->t('Pending Absences')); ?></span>
				<span class="azc-stat-tile__value" aria-hidden="true"><?php p($pendingAbsences); ?></span>
				<span class="azc-stat-tile__meta"><?php p($l->t('Jump to approvals')); ?></span>
			</a>
		</section>

		<section class="azc-card manager-dashboard__approvals" id="pending-approvals-section" aria-labelledby="pending-approvals-title">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="pending-approvals-title" class="azc-card__title"><?php p($l->t('Pending approvals')); ?></h2>
					<p class="azc-card__lead">
						<?php p($l->t('Review and approve or reject absence requests and time entries from your team.')); ?>
					</p>
				</div>
			</header>
			<div class="azc-card__body">
				<div class="pending-approvals-tabs" role="tablist" aria-label="<?php p($l->t('Filter pending approvals by type')); ?>">
					<button type="button" class="pending-approvals-tab pending-approvals-tab--active" role="tab" aria-selected="true" aria-controls="pending-absences-panel" id="tab-absences"><?php p($l->t('Absences')); ?></button>
					<button type="button" class="pending-approvals-tab" role="tab" aria-selected="false" aria-controls="pending-time-entries-panel" id="tab-time-entries"><?php p($l->t('Time entries')); ?></button>
				</div>
				<div id="pending-absences-panel" class="pending-approvals-panel" role="tabpanel" aria-labelledby="tab-absences">
					<div id="pending-approvals-list" class="pending-approvals-list" role="region" aria-live="polite" aria-label="<?php p($l->t('List of pending absence requests')); ?>">
						<p class="pending-approvals-loading" id="pending-approvals-loading" aria-hidden="false"><?php p($l->t('Loading…')); ?></p>
						<div id="pending-approvals-items" class="pending-approvals-items" aria-hidden="true"></div>
						<p class="pending-approvals-empty azc-empty-state__text visually-hidden" id="pending-approvals-empty"><?php p($l->t('No pending absence requests.')); ?></p>
					</div>
				</div>
				<div id="pending-time-entries-panel" class="pending-approvals-panel pending-approvals-panel--hidden" role="tabpanel" aria-labelledby="tab-time-entries" aria-hidden="true">
					<div id="pending-time-entries-list" class="pending-approvals-list" role="region" aria-live="polite" aria-label="<?php p($l->t('List of pending time entries')); ?>">
						<p class="pending-approvals-loading" id="pending-time-entries-loading" aria-hidden="true"><?php p($l->t('Loading…')); ?></p>
						<div id="pending-time-entries-items" class="pending-approvals-items" aria-hidden="true"></div>
						<p class="pending-approvals-empty azc-empty-state__text visually-hidden" id="pending-time-entries-empty"><?php p($l->t('No pending time entries.')); ?></p>
					</div>
				</div>
			</div>
		</section>

		<section class="azc-card manager-dashboard__overtime" id="team-overtime-section" aria-labelledby="team-overtime-title">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="team-overtime-title" class="azc-card__title"><?php p($l->t('Team overtime alerts')); ?></h2>
					<p class="azc-card__lead">
						<?php p($l->t('Employees who reached overtime or undertime thresholds, or whose overtime bank needs attention.')); ?>
					</p>
				</div>
				<div class="azc-card__header-actions">
					<a id="team-overtime-export" class="azc-btn azc-btn--secondary azc-btn--sm"
						href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.manager.exportTeamOvertimeCsv')); ?>"
						download
						aria-label="<?php p($l->t('Download team overtime overview as CSV')); ?>">
						<?php p($l->t('Export CSV')); ?>
					</a>
				</div>
			</header>
			<div class="azc-card__body">
				<div id="team-overtime-content" class="team-overtime-content" role="region" aria-live="polite">
					<p class="team-overtime-loading" id="team-overtime-loading"><?php p($l->t('Loading…')); ?></p>
					<div id="team-overtime-summary" class="team-overtime-summary visually-hidden" aria-hidden="true"></div>
				</div>
			</div>
		</section>

		<section class="azc-card manager-dashboard__compliance" id="team-compliance-section" aria-labelledby="team-compliance-title">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="team-compliance-title" class="azc-card__title"><?php p($l->t('Team compliance')); ?></h2>
					<p class="azc-card__lead">
						<?php p($l->t('Overview of working time compliance across your team.')); ?>
					</p>
				</div>
			</header>
			<div class="azc-card__body">
				<div id="team-compliance-content" class="team-compliance-content" role="region" aria-live="polite">
					<p class="team-compliance-loading" id="team-compliance-loading" aria-hidden="false"><?php p($l->t('Loading…')); ?></p>
					<div id="team-compliance-summary" class="team-compliance-summary visually-hidden" aria-hidden="true"></div>
				</div>
			</div>
		</section>

		<section class="azc-card manager-dashboard__team" aria-labelledby="team-members-title">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="team-members-title" class="azc-card__title"><?php p($l->t('Team Members')); ?></h2>
					<p class="azc-card__lead"><?php p($l->t('Who is on your team today, how many hours they logged, and whether they have open absence requests.')); ?></p>
				</div>
			</header>
			<div class="azc-card__body">
				<?php if (empty($teamMembers)): ?>
					<div class="azc-empty-state">
						<p class="azc-empty-state__text"><?php p($l->t('No team members found')); ?></p>
					</div>
				<?php else: ?>
					<div class="table-container" role="region" aria-label="<?php p($l->t('Team members overview')); ?>">
						<table class="table table--hover azc-table--responsive" role="table" aria-label="<?php p($l->t('Team members overview')); ?>">
							<thead>
								<tr>
									<th scope="col"><?php p($l->t('Name')); ?></th>
									<th scope="col"><?php p($l->t('Hours Today')); ?></th>
									<th scope="col"><?php p($l->t('Status')); ?></th>
									<th scope="col"><?php p($l->t('Pending Absences')); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach (($teamMembers ?? []) as $member): ?>
									<tr>
										<td data-label="<?php p($l->t('Name')); ?>"><?php p($member['displayName']); ?></td>
										<td data-label="<?php p($l->t('Hours Today')); ?>"><?php p(round($member['todayHours'], 2)); ?>h</td>
										<td data-label="<?php p($l->t('Status')); ?>">
											<?php
											$statusLabels = [
												'active' => $l->t('Clocked In'),
												'break' => $l->t('On Break'),
												'clocked_out' => $l->t('Clocked Out'),
											];
											$statusLabel = $statusLabels[$member['status']] ?? $member['status'];
											?>
											<span class="badge badge--<?php p(BadgeVariant::forClockStatus((string)($member['status'] ?? ''))); ?>"><?php p($statusLabel); ?></span>
										</td>
										<td data-label="<?php p($l->t('Pending Absences')); ?>"><?php p($member['pendingAbsences']); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</section>
	</div>
</div>

<?php include __DIR__ . '/common/manager-correction-l10n.php'; ?>

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
	window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
	window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
	Object.assign(window.ArbeitszeitCheck.l10n, {
		"No pending absence requests.": <?php echo json_encode($l->t('No pending absence requests.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"No pending time entries.": <?php echo json_encode($l->t('No pending time entries.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Error loading pending approvals.": <?php echo json_encode($l->t('Error loading pending approvals.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Error loading pending time entries.": <?php echo json_encode($l->t('Error loading pending time entries.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"days": <?php echo json_encode($l->t('days'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Approve": <?php echo json_encode($l->t('Approve'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Reject": <?php echo json_encode($l->t('Reject'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Absence approved.": <?php echo json_encode($l->t('Absence approved.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Failed to approve.": <?php echo json_encode($l->t('Failed to approve.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Failed to approve absence.": <?php echo json_encode($l->t('Failed to approve absence.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Optional reason for rejection (leave empty for none):": <?php echo json_encode($l->t('Optional reason for rejection (leave empty for none):'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Reason for rejection (optional)": <?php echo json_encode($l->t('Reason for rejection (optional)'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Enter reason for rejection...": <?php echo json_encode($l->t('Enter reason for rejection...'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Cancel": <?php echo json_encode($l->t('Cancel'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Confirm rejection": <?php echo json_encode($l->t('Confirm rejection'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Reject Request": <?php echo json_encode($l->t('Reject Request'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Absence rejected.": <?php echo json_encode($l->t('Absence rejected.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Failed to reject.": <?php echo json_encode($l->t('Failed to reject.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Failed to reject absence.": <?php echo json_encode($l->t('Failed to reject absence.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Time entry correction": <?php echo json_encode($l->t('Time entry correction'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"New manual time entry": <?php echo json_encode($l->t('New manual time entry'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Duration": <?php echo json_encode($l->t('Duration'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"hours": <?php echo json_encode($l->t('hours'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Original:": <?php echo json_encode($l->t('Original:'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Proposed:": <?php echo json_encode($l->t('Proposed:'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Time entry correction approved successfully": <?php echo json_encode($l->t('Time entry correction approved successfully'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Failed to approve time entry correction.": <?php echo json_encode($l->t('Failed to approve time entry correction.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Time entry correction rejected": <?php echo json_encode($l->t('Time entry correction rejected'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Failed to reject time entry correction.": <?php echo json_encode($l->t('Failed to reject time entry correction.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Unable to load compliance data.": <?php echo json_encode($l->t('Unable to load compliance data.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Error loading team compliance.": <?php echo json_encode($l->t('Error loading team compliance.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Compliant": <?php echo json_encode($l->t('Compliant'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Warnings": <?php echo json_encode($l->t('Warnings'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Critical Violations": <?php echo json_encode($l->t('Critical Violations'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Total Violations": <?php echo json_encode($l->t('Total Violations'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Some team members have compliance issues. Check the Compliance section for details.": <?php echo json_encode($l->t('Some team members have compliance issues. Check the Compliance section for details.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"All team members are compliant.": <?php echo json_encode($l->t('All team members are compliant.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"No team members.": <?php echo json_encode($l->t('No team members.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Absence": <?php echo json_encode($l->t('Absence'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Vacation": <?php echo json_encode($l->t('Vacation'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Sick leave": <?php echo json_encode($l->t('Sick leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Personal leave": <?php echo json_encode($l->t('Personal leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Parental leave": <?php echo json_encode($l->t('Parental leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Special leave": <?php echo json_encode($l->t('Special leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Unpaid leave": <?php echo json_encode($l->t('Unpaid leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Home office": <?php echo json_encode($l->t('Home office'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Business trip": <?php echo json_encode($l->t('Business trip'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
	});
</script>

<?php include __DIR__ . '/common/page-end.php'; ?>
