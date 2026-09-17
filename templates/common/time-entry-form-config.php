<?php

declare(strict_types=1);

/**
 * Runtime config for js/time-entry-form.js (create/edit pages only).
 *
 * @var string $mode
 * @var \OCP\IURLGenerator $urlGenerator
 * @var \OCA\ArbeitszeitCheck\Db\TimeEntry|null $entry
 */
$mode = $mode ?? ($_['mode'] ?? 'list');
$urlGenerator = $urlGenerator ?? ($_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class));
$entry = $entry ?? ($_['entry'] ?? null);

if ($mode !== 'create' && $mode !== 'edit') {
	return;
}

$submitUrl = $mode === 'create'
	? $urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiStore')
	: $urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiUpdatePost', ['id' => $entry->getId()]);

$timeEntryFormConfig = [
	'breakIndex' => (int)($_['timeEntryFormBreakIndex'] ?? 1),
	'autoBreakEnabled' => (bool)($_['timeEntryFormAutoBreakEnabled'] ?? false),
	'maxDailyHours' => (float)($_['maxDailyHours'] ?? 10),
	'maxBreaks' => 10,
	'minuteStep' => \OCA\ArbeitszeitCheck\Support\TimePickerMinuteStep::resolve(
		\OCP\Server::get(\OCP\IConfig::class)
	),
	'manualTimeEntriesRequireApproval' => $mode === 'create'
		&& !empty($_['manualTimeEntriesRequireApproval']),
	'minJustificationLength' => 10,
	'submitUrl' => $submitUrl,
	'redirectUrl' => $urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries'),
	'timeEntriesListUrl' => $urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries'),
];

?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.timeEntryForm = <?php echo json_encode($timeEntryFormConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>
