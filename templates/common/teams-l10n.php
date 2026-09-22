<?php

declare(strict_types=1);

/**
 * Server-translated strings for js/admin-teams.js (window.t may be unavailable).
 *
 * Uses {@see TemplateL10n} so printf placeholders (%n, %1$d, …) stay safe for
 * client-side substitution. Message ids stay as map keys for JS lookup.
 *
 * @var \OCP\IL10N $l
 */
use OCA\ArbeitszeitCheck\Util\TemplateL10n;

$l = $l ?? ($_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck'));

$teamsMessageIds = [
	'Setting saved',
	'Use app teams setting saved',
	'Failed to save setting',
	'Edit unit',
	'Delete unit',
	'Actions for unit',
	'Unit selected',
	'Failed to load structure',
	'Loading…',
	'Remove member',
	'No members',
	'Failed to load members',
	'Remove manager',
	'No managers',
	'Failed to load managers',
	'Are you sure you want to delete the unit "%s"? Members and managers will be unassigned.',
	'Delete',
	'Cancel',
	'Delete "%s"?',
	'Deleting this unit will unassign all members and managers from it. Sub-teams must be handled separately.',
	'Members in this unit: %s',
	'Managers in this unit: %s',
	'Direct sub-units: %s',
	'This action cannot be undone.',
	'Unit deleted',
	'Failed to delete unit',
	'Add unit',
	'Unit name',
	'Parent unit',
	'Create',
	'None (top level)',
	'Unit created',
	'Failed to create unit',
	'Save',
	'Unit updated',
	'Failed to update unit',
	'No users available',
	'All users are already members of this team',
	'Find a person',
	'Start typing their name or login, then pick them from the list.',
	'Search by name or login…',
	'Matching users',
	'No matching users found',
	'Type at least 2 characters to search for a person.',
	'Please select a person from the search results.',
	'Selected: %s',
	'%s results',
	'Showing the first %s matches. Keep typing to narrow it down.',
	'Everyone matching your search is already assigned to this unit.',
	'Add member',
	'Select user',
	'Add',
	'Member added',
	'Failed to add member',
	'Failed to load users',
	'All users are already managers of this team',
	'Add manager',
	'Manager added',
	'Failed to add manager',
	'Remove "%s" from this team?',
	'Member removed',
	'Failed to remove member',
	'Remove "%s" as manager?',
	'Manager removed',
	'Failed to remove manager',
	// Bulk add (F5 / Kraft multi-assign)
	'Add several people…',
	'Add several managers…',
	'Find people',
	'Search and tick people, then confirm to add them to this team.',
	'Add selected',
	'Add selected (%s)',
	'%s selected',
	'Select at least one person.',
	'Add %1$d people to team “%2$s”?',
	'Added %1$d, skipped %2$d, failed %3$d.',
];

$teamsL10n = TemplateL10n::mapFromMessageIds($l, $teamsMessageIds);

$adminUserSearchUrl = (string)($_['adminUserSearchUrl'] ?? '');
?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.teamsL10n = <?php echo json_encode($teamsL10n, TemplateL10n::JSON_ENCODE_FLAGS); ?>;
window.ArbeitszeitCheck.teamsConfig = <?php echo json_encode([
	'adminUserSearchUrl' => $adminUserSearchUrl,
], TemplateL10n::JSON_ENCODE_FLAGS); ?>;
</script>
