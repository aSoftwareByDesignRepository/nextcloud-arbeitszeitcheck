<?php

declare(strict_types=1);

/**
 * Page bootstrap for time-entries (list + create/edit): entries, API routes, shared l10n.
 *
 * @var string $mode
 * @var array $entries
 * @var \OCP\IURLGenerator $urlGenerator
 * @var bool $monthClosureEnabled
 */
$mode = $mode ?? ($_['mode'] ?? 'list');
$entries = $entries ?? ($_['entries'] ?? []);
$urlGenerator = $urlGenerator ?? ($_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class));
$monthClosureEnabled = $monthClosureEnabled ?? ($_['monthClosureEnabled'] ?? false);
$l = $l ?? ($_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck'));

$entriesPayload = array_map(static function ($entry) {
	return [
		'id' => $entry->getId(),
		'startTime' => $entry->getStartTime()->format('c'),
		'endTime' => $entry->getEndTime() ? $entry->getEndTime()->format('c') : null,
		'status' => $entry->getStatus(),
		'description' => $entry->getDescription(),
	];
}, $entries);

$listL10n = [
	'skipToForm' => $l->t('Skip to form'),
	'skipToTimeEntryForm' => $l->t('Skip to time entry form'),
	'confirmDelete' => $l->t('Are you sure you want to delete this time entry?'),
	'confirmDeleteTimeEntry' => $l->t('Are you sure you want to delete this time entry?\n\nThis will permanently remove this record of your working time. This action cannot be undone.'),
	'confirmDeleteTimeEntryTitle' => $l->t('Delete time entry'),
	'cannotDeleteTimeEntryTitle' => $l->t('Cannot delete time entry'),
	'deleteImpactCheckFailed' => $l->t('This page is out of date. Reload the page, then try again.'),
	'error' => $l->t('An error occurred'),
	'deleted' => $l->t('Time entry deleted successfully'),
	'autoBreakDuration30' => $l->t('30 minutes'),
	'autoBreakDuration45' => $l->t('45 minutes'),
];

if ($monthClosureEnabled) {
	$listL10n += [
		'monthClosureStatusOpen' => $l->t('Open (month status)'),
		'monthClosureStatusFinalized' => $l->t('Finalized'),
		'monthClosureStatusFinalizedAuto' => $l->t('Finalized automatically'),
		'monthClosureDeadline' => $l->t('Please finalize this month by {date} (end of the configured grace period). After that, it may be sealed automatically if it is still open.'),
		'monthClosureFinalizedSuccess' => $l->t('Month finalized.'),
		'monthClosureError' => $l->t('Error'),
		'monthClosureLoading' => $l->t('Loading…'),
		'monthClosureNoPeriods' => $l->t('No completed months with time entries yet.'),
		'monthClosureNoPeriodsHint' => $l->t('Record working time in a month first. After that calendar month has ended, you can seal it here.'),
		'monthClosurePeriodsLoadError' => $l->t('Could not load months.'),
		'monthClosureLoadingPeriods' => $l->t('Loading months…'),
		'monthClosurePdfDownloadAria' => $l->t('Download revision-safe monthly certificate PDF for {period}'),
		'monthClosureStatusError' => $l->t('Could not load status. Try again.'),
	];
}

$apiUrl = [
	'timeEntries' => $urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiIndex'),
	'create' => $urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiStore'),
	'update' => $urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiUpdate', ['id' => '__ID__']),
	'delete' => $urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiDelete', ['id' => '__ID__']),
	'deletionImpact' => $urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.getDeletionImpact', ['id' => '__ID__']),
	'export' => $urlGenerator->linkToRoute('arbeitszeitcheck.export.timeEntries'),
	'requestCorrection' => $urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.requestCorrection', ['id' => '__ID__']),
	'cancelCorrection' => $urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.cancelCorrection', ['id' => '__ID__']),
	'timeEntriesList' => $urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries'),
];

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

include __DIR__ . '/hours-display-bootstrap.php';

?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.page = 'time-entries';
window.ArbeitszeitCheck.mode = <?php echo json_encode($mode, $jsonFlags); ?>;
window.ArbeitszeitCheck.correctionMinuteStep = <?php echo (int)\OCA\ArbeitszeitCheck\Support\TimePickerMinuteStep::resolve(\OCP\Server::get(\OCP\IConfig::class)); ?>;
window.ArbeitszeitCheck.hoursDisplay = <?php echo json_encode($azcHoursDisplayMode, $jsonFlags); ?>;
window.ArbeitszeitCheck.entries = <?php echo json_encode($entriesPayload, $jsonFlags); ?>;
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
Object.assign(window.ArbeitszeitCheck.l10n, <?php echo json_encode($listL10n, $jsonFlags); ?>);
window.ArbeitszeitCheck.apiUrl = <?php echo json_encode($apiUrl, $jsonFlags); ?>;
</script>
