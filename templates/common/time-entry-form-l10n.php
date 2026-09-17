<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Util\TemplateL10n;

/**
 * Server-translated strings for js/time-entry-form.js.
 *
 * @var \OCP\IL10N $l
 */
$l = $l ?? ($_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck'));

try {
	$azcLawProfile = \OCP\Server::get(LaborLawProfileFactory::class)->getProfileForCurrentUser();
} catch (\Throwable) {
	$azcLawProfile = LaborLawProfileFactory::profileForCountry('DE');
}
$azcBreakLaw = $azcLawProfile->lawLabel('breaks');
$azcDailyLaw = $azcLawProfile->lawLabel('daily');
$azcCountry = $azcLawProfile->country;
$azcIsAustria = $azcCountry === \OCA\ArbeitszeitCheck\Support\RegionRegistry::COUNTRY_AT;
$azcIsSwitzerland = $azcCountry === \OCA\ArbeitszeitCheck\Support\RegionRegistry::COUNTRY_CH;

$azcAutoBreakNote = match (true) {
	$azcIsAustria => 'Automatically added for Austrian labour-law compliance (%s)',
	$azcIsSwitzerland => 'Automatically added for Swiss labour-law compliance (%s)',
	default => 'Automatically added for German labor law compliance (%s)',
};
$azcComplianceOk = match (true) {
	$azcIsAustria => 'Compliant with Austrian labour law',
	$azcIsSwitzerland => 'Compliant with Swiss labour law',
	default => 'Compliant with German labor law',
};

$timeEntryFormMessageIds = [
	'breakRowStartLabel' => 'Break %1$s start',
	'breakRowEndLabel' => 'Break %1$s end',
	'autoBreakAddedCompliance' => 'Automatic %s break added for legal compliance',
	'autoBreakNote' => $azcAutoBreakNote,
	'autoBreakDisabled' => 'Automatic break generation disabled',
	'autoBreakStateOn' => 'Enabled',
	'autoBreakStateOff' => 'Disabled',
	'maxBreaksAllowed' => 'Maximum of %d breaks allowed',
	'breakStartHour' => 'Break start hour',
	'breakStartMinute' => 'Break start minute',
	'breakEndHour' => 'Break end hour',
	'breakEndMinute' => 'Break end minute',
	'removeBreak' => 'Remove break',
	'removeThisBreak' => 'Remove this break',
	'remove' => 'Remove',
	'dateRequired' => 'Date is required',
	'invalidDate' => 'Invalid date',
	'dateFuture' => 'Date cannot be in the future',
	'dateTooOld' => 'Date cannot be more than 1 year in the past',
	'complianceMaxHours' => 'Working hours exceed legal maximum (%s)',
	'complianceApproachingMax' => 'Approaching maximum working hours',
	'complianceRecalculatingBreak' => 'Recalculating automatic break...',
	'complianceBreakNotMet' => 'Break requirement not met (%s)',
	'complianceBreakSplitInvalid' => 'Breaks must be one continuous block of the required length, or 2×15 minutes, or 3×10 minutes (AZG §11)',
	'complianceShortShift' => 'Short shift - no breaks required',
	'complianceAuto30' => 'Compliant - automatic 30 min break',
	'complianceManual30' => 'Compliant - 30 min break provided',
	'complianceAuto45' => 'Compliant - automatic 45 min break',
	'complianceManual45' => 'Compliant - 45 min break provided',
	'complianceOk' => $azcComplianceOk,
	'startTimeRequired' => 'Start time is required',
	'endTimeRequired' => 'End time is required',
	'endAfterStart' => 'End time must be after start time',
	'workMin15' => 'Work period must be at least 15 minutes',
	'workMax16' => 'Work period cannot exceed 16 hours',
	'breaksExceedWork' => 'Total break time cannot exceed work time',
	'breakRequiredNone' => 'No breaks required for shifts under %s hours',
	'breakRequired30' => '30 minutes break required (%s)',
	'breakRequired45' => '45 minutes break required (%s)',
	'savedSuccess' => 'Time entry saved successfully',
	'saveError' => 'An error occurred while saving',
	'timeoutError' => 'Request timed out. Please try again.',
	'htmlResponseError' => 'The server returned a login or error page instead of data. Please reload the page or sign in again.',
	'serverError' => 'Server error occurred. Please try again.',
	'missingFieldsError' => 'Please fill in all required fields (date, start time, end time)',
	'invalidDateError' => 'Please enter a valid date',
	'invalidTimesError' => 'Please enter valid start and end times',
	'networkError' => 'Network error occurred',
	'submitting' => 'Submitting...',
	'initFailed' => 'Form initialization failed. Please refresh the page.',
	'justificationRequired' => 'A justification of at least 10 characters is required for manual time entries.',
	'justificationReady' => '{count} of 10+ characters — you can submit',
	'justificationRemaining' => '{remaining} more characters needed ({count} of {min})',
];

$timeEntryFormL10n = [];
foreach ($timeEntryFormMessageIds as $key => $messageId) {
	$timeEntryFormL10n[$key] = TemplateL10n::translate($l, $messageId);
}

// Inject law citations into parametric messages after translation.
$timeEntryFormL10n['autoBreakNote'] = sprintf($timeEntryFormL10n['autoBreakNote'], $azcBreakLaw);
$timeEntryFormL10n['complianceMaxHours'] = sprintf($timeEntryFormL10n['complianceMaxHours'], $azcDailyLaw);
$timeEntryFormL10n['complianceBreakNotMet'] = sprintf($timeEntryFormL10n['complianceBreakNotMet'], $azcBreakLaw);
// Keep legacy keys (breakRequired30/45) for older cached JS, then overwrite
// with profile-driven strings so AT only exposes the AZG §11 30-minute tier.
$timeEntryFormL10n['breakRequired30'] = sprintf($timeEntryFormL10n['breakRequired30'], $azcBreakLaw);
$timeEntryFormL10n['breakRequired45'] = sprintf($timeEntryFormL10n['breakRequired45'], $azcBreakLaw);

$azcAscendingTiers = $azcLawProfile->breakTiersAscending();
$azcFirstTierHours = $azcAscendingTiers !== [] ? (float)$azcAscendingTiers[0]['afterHours'] : 6.0;
$azcFirstTierHoursLabel = abs($azcFirstTierHours - (int)$azcFirstTierHours) < 0.001
	? (string)(int)$azcFirstTierHours
	: rtrim(rtrim(number_format($azcFirstTierHours, 1, '.', ''), '0'), '.');
$timeEntryFormL10n['breakRequiredNone'] = sprintf($timeEntryFormL10n['breakRequiredNone'], $azcFirstTierHoursLabel);

foreach ($azcAscendingTiers as $tier) {
	$minutes = (int)$tier['breakMinutes'];
	$timeEntryFormL10n['breakRequired' . $minutes] = TemplateL10n::translate(
		$l,
		'%1$d minutes break required (%2$s)',
		[$minutes, $azcBreakLaw]
	);
	$timeEntryFormL10n['complianceAuto' . $minutes] = TemplateL10n::translate(
		$l,
		'Compliant - automatic %d min break',
		[$minutes]
	);
	$timeEntryFormL10n['complianceManual' . $minutes] = TemplateL10n::translate(
		$l,
		'Compliant - %d min break provided',
		[$minutes]
	);
	$timeEntryFormL10n['autoBreakDuration' . $minutes] = TemplateL10n::translate(
		$l,
		'%d minutes',
		[$minutes]
	);
}

$azcComplianceParams = [
	'country' => $azcLawProfile->country,
	'breakTiers' => $azcLawProfile->breakTiersAscending(),
	'minBreakMinutes' => $azcLawProfile->minBreakMinutes,
	'maxDailyHoursDefault' => $azcLawProfile->dailyMaxHoursDefault,
	'allowedBreakSplitPatterns' => $azcLawProfile->allowedBreakSplitPatterns,
	'lawLabels' => [
		'breaks' => $azcBreakLaw,
		'daily' => $azcDailyLaw,
		'rest' => $azcLawProfile->lawLabel('rest'),
	],
];

?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
Object.assign(window.ArbeitszeitCheck.l10n, <?php echo json_encode($timeEntryFormL10n, TemplateL10n::JSON_ENCODE_FLAGS); ?>);
window.ArbeitszeitCheck.complianceParams = <?php echo json_encode($azcComplianceParams, TemplateL10n::JSON_ENCODE_FLAGS); ?>;
</script>
