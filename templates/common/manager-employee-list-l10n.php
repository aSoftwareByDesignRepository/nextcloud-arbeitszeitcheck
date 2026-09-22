<?php

declare(strict_types=1);

/**
 * Server-translated strings for manager employee list pages (time entries & absences).
 * Never use json_encode($l->t(...)) inline — build a plain string map first.
 *
 * @var \OCP\IL10N $l
 * @var array $_
 */

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Util\TemplateL10n;

$l = $l ?? ($_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck'));

$page = (string) ($_['managerEmployeeListPage'] ?? 'time-entries');

$sharedMessageIds = [
	'Loading...',
	'Loading results…',
	'Select filters first',
	'Check your filters',
	'No entries found for the selected filters.',
	'Please select start and end date.',
	'Page {page} of {pages}',
	'{count} entries',
	'All in my scope',
	'Type at least 2 characters to search.',
	'Employee search failed.',
	'No matching employees found.',
	'%s results',
	'Selected: %s',
	'Search by name or login…',
	'Invalid date range. Please use valid dates in YYYY-MM-DD format.',
	'Invalid date range. The start date must be before the end date.',
	'Invalid date format. Please use dd.mm.yyyy (e.g., 15.01.2024).',
];

$timeEntriesMessageIds = [
	'Could not load employee time entries.',
	'No matching time entries',
	'No description',
	'Choose a date range to load entries.',
	'Add time entry',
	'Record time for an employee',
	'Select employee',
	'Save time entry',
	'Time entry recorded for the employee.',
	'Could not save time entry.',
	'Project (optional)',
	'No project link',
	'Could not load projects.',
	'Correct time entry',
];

$projectCheckEnabled = !empty($_['projectCheckEnabled']);

$absencesMessageIds = [
	'Could not load employee absences.',
	'No reason',
	'Choose a date range to load absences.',
	'Past record',
	'Select an employee',
	'Absence recorded and approved.',
	'Could not save absence.',
];

$messageIds = array_merge(
	$sharedMessageIds,
	$page === 'absences' ? $absencesMessageIds : $timeEntriesMessageIds,
);

$managerEmployeeListL10n = TemplateL10n::mapFromMessageIds($l, $messageIds);

$managerEmployeeListL10n['pickerTypeToSearch'] = TemplateL10n::translate($l, 'Type at least 2 characters to search.');
$managerEmployeeListL10n['pickerMinSearchHint'] = $managerEmployeeListL10n['pickerTypeToSearch'];
$managerEmployeeListL10n['pickerLoading'] = TemplateL10n::translate($l, 'Loading...');
$managerEmployeeListL10n['pickerSearchError'] = TemplateL10n::translate($l, 'Employee search failed.');
$managerEmployeeListL10n['pickerNoUsersFound'] = TemplateL10n::translate($l, 'No matching employees found.');
$managerEmployeeListL10n['pickerResultsCount'] = TemplateL10n::translate($l, '%s results');
$managerEmployeeListL10n['pickerEmployeeSelected'] = TemplateL10n::translate($l, 'Selected: %s');
$managerEmployeeListL10n['allInMyScope'] = TemplateL10n::translate($l, 'All in my scope');
$managerEmployeeListL10n['pickerIncompleteSelection'] = TemplateL10n::translate(
	$l,
	'Select an employee from the list, or clear the search field to include everyone in your scope.'
);

$maxDays = (int) ($_['maxManagerListDateRangeDays'] ?? Constants::MAX_EXPORT_DATE_RANGE_DAYS);
$managerEmployeeListL10n['dateRangeTooLong'] = TemplateL10n::translate(
	$l,
	'Date range must not exceed %d days. Please narrow the range.',
	[$maxDays],
);

?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
window.ArbeitszeitCheck.dateLocale = <?php echo json_encode($l->getLanguageCode(), TemplateL10n::JSON_ENCODE_FLAGS); ?>;
window.ArbeitszeitCheck.maxManagerListDateRangeDays = <?php echo $maxDays; ?>;
window.ArbeitszeitCheck.projectCheckEnabled = <?php echo $projectCheckEnabled ? 'true' : 'false'; ?>;
Object.assign(window.ArbeitszeitCheck.l10n, <?php echo json_encode($managerEmployeeListL10n, TemplateL10n::JSON_ENCODE_FLAGS); ?>);
</script>
