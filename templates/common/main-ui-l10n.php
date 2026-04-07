<?php

declare(strict_types=1);

/**
 * Server-translated strings for js/arbeitszeitcheck-main.js (window.t may be unavailable).
 *
 * @var \OCP\IL10N $l
 */
$l = $l ?? ($_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck'));

$mainUiStringIds = [
	'CRITICAL: Maximum daily working hours (10h) exceeded! Automatically clocking out to comply with German labor law (ArbZG §3).',
	'Note: You are approaching the maximum working hours. Extended hours must be compensated within 6 months (ArbZG §3).',
	'Are you sure you want to delete this item?',
	'Are you sure you want to delete this time entry?',
	'Time entry deleted successfully',
	'Absence request submitted successfully',
	'Absence request updated',
	'Absence shortened successfully. Your actual last day of absence has been updated.',
	'Absence cancelled successfully.',
	'Are you sure you want to cancel this absence request?',
	'Absence request cancelled successfully',
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
];

$mainUiStrings = [];
foreach ($mainUiStringIds as $msgid) {
	$mainUiStrings[$msgid] = $l->t($msgid);
}

?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.mainUiStrings = <?php echo json_encode($mainUiStrings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>
