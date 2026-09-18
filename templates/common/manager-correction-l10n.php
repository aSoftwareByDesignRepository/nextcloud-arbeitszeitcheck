<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCA\ArbeitszeitCheck\Util\TemplateL10n;

/**
 * Server-translated strings for manager time-entry correction UI
 * (manager-time-entries.js, manager-dashboard.js pending approvals).
 *
 * @var \OCP\IL10N $l
 */
$l = $l ?? ($_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck'));

try {
	$azcMgrLawProfile = \OCP\Server::get(LaborLawProfileFactory::class)->getProfileForCurrentUser();
} catch (\Throwable) {
	$azcMgrLawProfile = LaborLawProfileFactory::profileForCountry(RegionRegistry::COUNTRY_DE);
}
$azcMgrMinBreak = max(1, (int)$azcMgrLawProfile->minBreakMinutes);

$managerCorrectionStringIds = [
	'Correct',
	'Correct time entry',
	'Could not open correction dialog.',
	'Changes are applied immediately and the employee is notified. A reason is required for the audit log.',
	'Reason (min. 10 characters)',
	'Apply correction',
	'A reason of at least 10 characters is required.',
	'At least one field to correct is required.',
	'Time entry corrected successfully.',
	'Correction failed.',
	'Entry was modified. Reloading…',
	'No pending time entries.',
	'Error loading pending time entries.',
	'Time entry correction',
	'New manual time entry',
	'Duration',
	'hours',
	'Correction comparison',
	'Current (Ist)',
	'Proposed (Soll)',
	'Start',
	'End',
	'Breaks',
	'Reason:',
	'Time entry correction approved successfully',
	'Manual time entry approved.',
	'Failed to approve time entry correction.',
	'Failed to approve manual time entry.',
	'Time entry correction rejected',
	'Manual time entry rejected.',
	'Failed to reject time entry correction.',
	'Failed to reject manual time entry.',
	'This time entry was already decided by another manager.',
	'Approve',
	'Reject',
	'Cancel',
	'Optional reason for rejection (leave empty for none):',
	'Reason for rejection (optional)',
	'Enter reason for rejection...',
	'Confirm rejection',
	'Reject Request',
	'Failed to approve.',
	'Failed to reject.',
	// Strings used by manager-correction-dialog.js / common/time-entry-clock-form.js.
	'Date',
	'required',
	'dd.mm.yyyy',
	'Today',
	'Working Hours',
	'Start Time',
	'End Time',
	'Actions',
	'Remove',
];

$managerCorrectionL10n = [];
foreach ($managerCorrectionStringIds as $msgid) {
	$managerCorrectionL10n[$msgid] = $l->t($msgid);
}

// Keyed entries (separate from passthrough $l->t()) so JS can request via key.
$managerCorrectionL10n = array_merge($managerCorrectionL10n, [
	'managerCorrectionIntro' => $l->t('Changes are applied immediately and the employee is notified. A reason is required for the audit log.'),
	'managerCorrectionBreaksHelp' => $l->t(
		'Adjust breaks if needed. Each break must be at least %d minutes and within working hours.',
		[$azcMgrMinBreak]
	),
	'correctionWorkingDayLegend' => $l->t('Corrected working day'),
	'correctionDateHelp' => $l->t('Format: dd.mm.yyyy'),
	'correctionNightShiftHint' => $l->t('Night shift: if end is earlier than start (e.g. 22:00–06:00), end counts as the next day.'),
	'correctionBreaksOptional' => $l->t('Breaks (optional)'),
	'correctionBreaksEmpty' => $l->t('No breaks added.'),
	'correctionAddBreak' => $l->t('Add break'),
	'correctionReasonHelp' => $l->t('Required for the audit trail (at least 10 characters).'),
	'invalidDate' => $l->t('Please enter a valid date (dd.mm.yyyy).'),
	'invalidWorkTimes' => $l->t('Please enter valid start and end times.'),
	'invalidBreakTimes' => $l->t('Please enter valid break times.'),
	'breakTooShort' => $l->t('Each break must be at least %d minutes.', [$azcMgrMinBreak]),
	'breakOutsideWork' => $l->t('Breaks must be within working hours.'),
	'breaksOverlap' => $l->t('Breaks must not overlap.'),
	'breakNumber' => $l->t('Break {number}'),
	'reasonRequired' => $l->t('A reason of at least 10 characters is required.'),
	'remove' => $l->t('Remove'),
	'start' => $l->t('Start'),
	'end' => $l->t('End'),
	'correctionCurrentHeading' => $l->t('Currently stored'),
	'correctionCurrentAria' => $l->t('Times currently saved for this entry'),
	'correctionProposedHeading' => $l->t('Corrected times'),
	'correctionProposedHint' => $l->t('Enter the date and times as they should be recorded.'),
	'correctionLabelDate' => $l->t('Date'),
	'correctionLabelStart' => $l->t('Start'),
	'correctionLabelEnd' => $l->t('End'),
	'correctionLabelBreaks' => $l->t('Breaks'),
	'correctionErrorLoadEntry' => $l->t('Could not load the stored times for this entry. Please reload the page and try again.'),
	'correctionLoadingProjects' => $l->t('Loading projects…'),
	'Keep current project link' => $l->t('Keep current project link'),
]);

$azcMgrComplianceParams = [
	'country' => $azcMgrLawProfile->country,
	'breakTiers' => $azcMgrLawProfile->breakTiersAscending(),
	'minBreakMinutes' => $azcMgrMinBreak,
	'maxDailyHoursDefault' => $azcMgrLawProfile->dailyMaxHoursDefault,
	'allowedBreakSplitPatterns' => $azcMgrLawProfile->allowedBreakSplitPatterns,
	'lawLabels' => [
		'breaks' => $azcMgrLawProfile->lawLabel('breaks'),
		'daily' => $azcMgrLawProfile->lawLabel('daily'),
		'rest' => $azcMgrLawProfile->lawLabel('rest'),
	],
];

?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
Object.assign(window.ArbeitszeitCheck.l10n, <?php echo json_encode($managerCorrectionL10n, TemplateL10n::JSON_ENCODE_FLAGS); ?>);
window.ArbeitszeitCheck.complianceParams = window.ArbeitszeitCheck.complianceParams || <?php echo json_encode($azcMgrComplianceParams, TemplateL10n::JSON_ENCODE_FLAGS); ?>;
</script>
