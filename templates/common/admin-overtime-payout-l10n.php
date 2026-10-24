<?php

declare(strict_types=1);

/**
 * Server-translated strings for admin overtime payout pages (process + audit).
 *
 * @var \OCP\IL10N $l
 */

use OCA\ArbeitszeitCheck\Util\TemplateL10n;

$l = $l ?? ($_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck'));

$payoutMessageIds = [
	'loadList' => 'Load list',
	'loading' => 'Loading…',
	'confirmOne' => 'Record payout for %s? This cannot be undone.',
	'confirmBulk' => 'Pay out all pending hours for this month? This cannot be undone.',
	'paid' => 'Paid',
	'pending' => 'Pending payout',
	'none' => 'No payout needed',
	'confirmBtn' => 'Confirm payout',
	'summary' => '%1$s pending (%2$s h), %3$s already paid.',
	'done' => 'Done: %1$s paid, %2$s skipped.',
	'empty' => 'No employees with overtime tracking for this month.',
	'error' => 'Could not complete the request. Please try again.',
	'bankDisabled' => 'Enable the overtime bank in notification settings before loading payouts.',
	'invalidYear' => 'Enter a valid year (2000–2100).',
	'invalidMonth' => 'Choose a month between January and December.',
	'confirmTitle' => 'Confirm payout',
	'confirmBulkTitle' => 'Pay out all pending',
	'truncatedWarning' => 'Warning: employee list was truncated; contact support.',
	'scopeCount' => '(%1$s employees in scope)',
	'colEmployee' => 'Employee',
	'colStatus' => 'Status',
	'colEligible' => 'Eligible (h)',
	'colPaid' => 'Paid (h)',
	'colActions' => 'Actions',
];

$auditMessageIds = [
	'loading' => 'Loading…',
	'noRecords' => 'No payout records match these filters.',
	'error' => 'Could not load audit data.',
	'searchError' => 'Employee search failed. Please try again.',
	'resultsCount' => '%n employees found',
	'employeeSelected' => 'Filtering by %s',
	'allEmployees' => 'Showing all employees',
	'invalidYear' => 'Enter a valid year (2000–2100).',
	'auditLog' => 'Activity log',
	'monthPdf' => 'Month-closure PDF',
	'noPdf' => 'PDF unavailable',
	'truncated' => 'Showing %1$s of %2$s records. Narrow your filters to see more.',
	'gapHours' => '%s h unpaid',
	'gapProcess' => 'Process payout',
	'noActions' => 'No links',
	'employeeLabel' => 'Employee (optional)',
	'employeePlaceholder' => 'Search by name or login…',
	'employeeHelp' => 'Leave empty to show all employees. Type at least 2 characters, then pick from the list.',
	'typeToSearch' => 'Type at least 2 characters to search for a person.',
	'noUsersFound' => 'No matching employees found.',
	'clearEmployee' => 'Clear employee',
	'resetFilters' => 'Reset filters',
	'colPeriod' => 'Period',
	'colEmployee' => 'Employee',
	'colHoursPaid' => 'Hours paid',
	'colProcessed' => 'Processed',
	'colActions' => 'Actions',
];

$otPayoutI18n = [];
foreach ($payoutMessageIds as $key => $messageId) {
	$otPayoutI18n[$key] = TemplateL10n::translate($l, $messageId);
}

$otPayoutAuditI18n = [];
foreach ($auditMessageIds as $key => $messageId) {
	$otPayoutAuditI18n[$key] = TemplateL10n::translate($l, $messageId);
}
