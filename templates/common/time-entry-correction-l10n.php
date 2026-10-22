<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;

/**
 * Server-translated strings for js/time-entry-correction.js.
 *
 * @var \OCP\IL10N $l
 */
$l = $l ?? ($_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck'));

try {
	$azcCorrLawProfile = \OCP\Server::get(LaborLawProfileFactory::class)->getProfileForCurrentUser();
} catch (\Throwable) {
	$azcCorrLawProfile = LaborLawProfileFactory::profileForCountry(RegionRegistry::COUNTRY_DE);
}
$azcCorrMinBreak = max(1, (int)$azcCorrLawProfile->minBreakMinutes);

$correctionL10n = [
	'correctionModalTitle' => $l->t('Request Time Entry Correction'),
	'correctionLabelDate' => $l->t('Date'),
	'correctionLabelStart' => $l->t('Start'),
	'correctionLabelEnd' => $l->t('End'),
	'correctionLabelBreaks' => $l->t('Breaks'),
	'correctionLabelWorkLocation' => $l->t('Work location'),
	'workLocationUnspecified' => $l->t('Not specified'),
	'workLocationOffice' => $l->t('Office'),
	'workLocationHome' => $l->t('Home office'),
	'workLocationExternal' => $l->t('External appointment'),
	'correctionLabelDescription' => $l->t('Description'),
	'correctionBreakNumber' => $l->t('Break {number}'),
	'correctionBreakStartHour' => $l->t('Break start hour'),
	'correctionBreakStartMinute' => $l->t('Break start minute'),
	'correctionBreakEndHour' => $l->t('Break end hour'),
	'correctionBreakEndMinute' => $l->t('Break end minute'),
	'correctionRemoveBreak' => $l->t('Remove break'),
	'correctionRemove' => $l->t('Remove'),
	'correctionErrorValidTimes' => $l->t('Please enter valid start and end times.'),
	'correctionErrorPartialBreak' => $l->t('Each break must have both a start and end time, or leave the row empty.'),
	'correctionErrorValidBreakTimes' => $l->t('Please enter valid break times.'),
	'correctionErrorBreakMinDuration' => $l->t('Each break must be at least %d minutes.', [$azcCorrMinBreak]),
	'correctionErrorBreakWithinWork' => $l->t('Breaks must fall within your working hours.'),
	'correctionErrorBreakOverlap' => $l->t('Break times must not overlap.'),
	'correctionErrorBreakSplitInvalid' => $l->t('Breaks must be one continuous block of the required length, or 2×15 minutes, or 3×10 minutes (AZG §11)'),
	'correctionErrorValidDate' => $l->t('Please enter a valid date (dd.mm.yyyy).'),
	'correctionErrorStartEndRequired' => $l->t('Please enter both start and end time.'),
	'correctionErrorEndAfterStart' => $l->t('End time must be after start time.'),
	'correctionErrorNoChanges' => $l->t('Change at least one time or break before submitting.'),
	'correctionErrorJustificationMin' => $l->t('Please provide a reason of at least 10 characters.'),
	'correctionErrorOpenDialog' => $l->t('Could not open correction dialog.'),
	'correctionErrorLoadEntry' => $l->t('Could not load the stored times for this entry. Please reload the page and try again.'),
	'correctionSubmitting' => $l->t('Submitting…'),
	'correctionSubmitSuccess' => $l->t('Correction request submitted. Your manager will review it.'),
	'correctionSubmitError' => $l->t('Could not submit correction.'),
	'confirmCancelCorrection' => $l->t('Withdraw this pending correction? Your original times will be restored.'),
	'correctionWithdrawn' => $l->t('Correction withdrawn.'),
	'correctionWithdrawError' => $l->t('Could not withdraw correction.'),
	'correctionJustificationReady' => $l->t('{count} of 10+ characters — you can submit'),
	'correctionBreakWarning' => $l->t('The saved times do not fully meet the break rules.'),
	'correctionClose' => $l->t('Close'),
	'correctionWithdrawTitle' => $l->t('Withdraw correction'),
];

?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
Object.assign(window.ArbeitszeitCheck.l10n, <?php echo json_encode($correctionL10n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>);
</script>
