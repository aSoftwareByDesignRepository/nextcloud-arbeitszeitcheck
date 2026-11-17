<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;

/**
 * Server-translated strings for js/arbeitszeitcheck-main.js (window.t may be unavailable).
 *
 * @var \OCP\IL10N $l
 */
$l = $l ?? ($_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck'));

try {
	$azcLawProfile = \OCP\Server::get(LaborLawProfileFactory::class)->getProfileForCurrentUser();
} catch (\Throwable) {
	$azcLawProfile = LaborLawProfileFactory::profileForCountry(RegionRegistry::COUNTRY_DE);
}
$azcDailyLaw = $azcLawProfile->lawLabel('daily');
// Effective admin max wins over the country-profile default (same as Capabilities).
try {
	$azcMaxDaily = (int)round(max(1.0, min(24.0, (float)\OCP\Server::get(\OCP\IConfig::class)->getAppValue(
		'arbeitszeitcheck',
		'max_daily_hours',
		(string)$azcLawProfile->dailyMaxHoursDefault
	))));
} catch (\Throwable) {
	$azcMaxDaily = (int)round($azcLawProfile->dailyMaxHoursDefault);
}

$criticalClockOut = $l->t(
	'CRITICAL: Maximum daily working hours (%1$dh) exceeded! Automatically clocking out to comply with labour law (%2$s).',
	[$azcMaxDaily, $azcDailyLaw]
);
// Countries without an averaging window (CH) must not claim compensation in that window.
if ($azcLawProfile->dailyAvgMaxHours !== null || $azcLawProfile->weeklyAvgMaxHours !== null) {
	$approachingMax = $l->t(
		'Note: You are approaching the maximum working hours. Extended hours must be compensated within the averaging window (%s).',
		[$azcDailyLaw]
	);
} else {
	$approachingMax = $l->t(
		'Note: You are approaching the maximum working hours (%1$dh, %2$s).',
		[$azcMaxDaily, $azcDailyLaw]
	);
}
$autoClockedOut = $l->t(
	'Automatically clocked out to comply with labour law (%s).',
	[$azcDailyLaw]
);
$autoClockOutFailed = $l->t(
	'Automatic clock-out (%s) could not be completed. Please clock out manually.',
	[$azcDailyLaw]
);
$autoClockOutGiveUp = $l->t(
	'Automatic clock-out (%s) failed repeatedly. Please clock out manually or contact your administrator.',
	[$azcDailyLaw]
);

$mainUiStringIds = [
	'Are you sure you want to delete this item?',
	'Are you sure you want to delete this time entry?',
	'Time entry deleted successfully',
	'Absence request submitted successfully',
	'Absence request updated',
	'Absence shortened successfully. Your actual last day of absence has been updated.',
	'Absence cancelled successfully.',
	'Are you sure you want to cancel this absence request?',
	'Cancel absence request',
	'Delete time entry',
	'The server returned an unexpected page instead of data. Try reloading the page. If the problem persists, sign in again.',
	'January', 'February', 'March', 'April', 'May', 'June',
	'July', 'August', 'September', 'October', 'November', 'December',
	'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday',
	'Break Time',
	'Public holiday', 'Company holiday', 'Custom holiday',
	'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat',
	'Holiday',
	'Vacation',
	'Sick Leave',
	'Personal Leave',
	'Parental Leave',
	'Special Leave',
	'Unpaid Leave',
	'Home Office',
	'Business Trip',
	'Absence',
	'Clock out and end your working day?',
	'Your time entry will be finalized. To pause and continue working, use "Start Break" instead.',
	// Split-service accounting presentation (split-day spec §7.3/§7.6)
	'Partial service {number}',
	'Interruption',
	'not counted as working time',
	'Break covered by interruption (employer setting)',
	'View calculation',
	'Needs review',
	'Request correction',
	'provisional',
	'Not split: clock-out was not after {cutoff}',
	'Not split: clock-out was not after the cut-off time',
	'Not split: clock-in during interruption',
	'Not split: no interruption today',
	'Not split: crosses midnight',
	'Needs review: time change (DST)',
	'Needs review: stored data could not be read',
	'Counted as working time',
	'Paid working time',
	'Not counted (interruption)',
	'Reason',
	'Calculation status',
	'Calculated',
	'Provisional (still clocked in)',
	'Split requested by an approved correction',
	'Counted as one continuous day by an approved correction',
	'Clock-out after the cut-off time — day split at the scheduled interruption',
];

$mainUiStrings = [];
foreach ($mainUiStringIds as $msgid) {
	$mainUiStrings[$msgid] = $l->t($msgid);
}

// Profile-aware clock-out copy, keyed under both new and legacy msgids so
// js/arbeitszeitcheck-main.js keeps working without a coordinated JS rewrite.
$clockOutMap = [
	'CRITICAL: Maximum daily working hours (%1$dh) exceeded! Automatically clocking out to comply with labour law (%2$s).' => $criticalClockOut,
	'CRITICAL: Maximum daily working hours (10h) exceeded! Automatically clocking out to comply with German labor law (ArbZG §3).' => $criticalClockOut,
	'Note: You are approaching the maximum working hours. Extended hours must be compensated within the averaging window (%s).' => $approachingMax,
	'Note: You are approaching the maximum working hours (%1$dh, %2$s).' => $approachingMax,
	'Note: You are approaching the maximum working hours. Extended hours must be compensated within 6 months (ArbZG §3).' => $approachingMax,
	'Automatically clocked out to comply with labour law (%s).' => $autoClockedOut,
	'Automatically clocked out to comply with German labor law (ArbZG §3).' => $autoClockedOut,
	'Automatic clock-out (%s) could not be completed. Please clock out manually.' => $autoClockOutFailed,
	'Automatic clock-out (ArbZG §3) could not be completed. Please clock out manually.' => $autoClockOutFailed,
	'Automatic clock-out (%s) failed repeatedly. Please clock out manually or contact your administrator.' => $autoClockOutGiveUp,
	'Automatic clock-out (ArbZG §3) failed repeatedly. Please clock out manually or contact your administrator.' => $autoClockOutGiveUp,
];
foreach ($clockOutMap as $key => $value) {
	$mainUiStrings[$key] = $value;
}

?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.mainUiStrings = <?php echo json_encode($mainUiStrings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
window.ArbeitszeitCheck.lawLabels = <?php echo json_encode([
	'daily' => $azcDailyLaw,
	'breaks' => $azcLawProfile->lawLabel('breaks'),
	'rest' => $azcLawProfile->lawLabel('rest'),
	'maxDailyHours' => $azcMaxDaily,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>
