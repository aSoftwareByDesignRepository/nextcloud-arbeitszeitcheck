<?php

declare(strict_types=1);

/**
 * Server-translated strings for the manager people overview page
 * (js/manager-people.js — window.t may be unavailable).
 * Never use json_encode($l->t(...)) inline — build a plain string map first.
 *
 * @var \OCP\IL10N $l
 * @var array $_
 */

use OCA\ArbeitszeitCheck\Util\TemplateL10n;

$l = $l ?? ($_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck'));

$messageIds = [
	'Loading…',
	'Loading results…',
	'Check your filters',
	'Could not load the people overview.',
	'No employees in your scope.',
	'There is nobody in your scope to show yet.',
	'No matching employees found.',
	'{count} employees',
	'Page {page} of {pages}',
	'Employee',
	'Vacation remaining',
	'Approved upcoming',
	'Overtime',
	'Open issues',
	'Pending requests',
	'Details',
	'Not available',
	'Not configured',
	'critical',
	'needs attention',
	'days',
	'hours',
	'h',
	'Vacation balance unavailable for this employee.',
	'Overtime balance unavailable for this employee.',
	'Absences',
	'Violations',
	'Employee profile',
	'Invalid year. Please choose a year between 1970 and 2100.',
	'Some values could not be loaded. Try reloading the page.',
	'Clear employee filter',
];

$managerPeopleL10n = TemplateL10n::mapFromMessageIds($l, array_unique($messageIds));

$managerPeopleL10n['pickerTypeToSearch'] = TemplateL10n::translate($l, 'Type at least 2 characters to search.');
$managerPeopleL10n['pickerMinSearchHint'] = $managerPeopleL10n['pickerTypeToSearch'];
$managerPeopleL10n['pickerLoading'] = TemplateL10n::translate($l, 'Loading...');
$managerPeopleL10n['pickerSearchError'] = TemplateL10n::translate($l, 'Employee search failed.');
$managerPeopleL10n['pickerNoUsersFound'] = TemplateL10n::translate($l, 'No matching employees found.');
$managerPeopleL10n['pickerResultsCount'] = TemplateL10n::translate($l, '%s results');
$managerPeopleL10n['pickerEmployeeSelected'] = TemplateL10n::translate($l, 'Selected: %s');
$managerPeopleL10n['allInMyScope'] = TemplateL10n::translate($l, 'All in my scope');
$managerPeopleL10n['pickerIncompleteSelection'] = TemplateL10n::translate(
	$l,
	'Select an employee from the list, or clear the search field to include everyone in your scope.'
);

?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
window.ArbeitszeitCheck.dateLocale = <?php echo json_encode(str_replace('_', '-', $l->getLanguageCode()), TemplateL10n::JSON_ENCODE_FLAGS); ?>;
Object.assign(window.ArbeitszeitCheck.l10n, <?php echo json_encode($managerPeopleL10n, TemplateL10n::JSON_ENCODE_FLAGS); ?>);
</script>
