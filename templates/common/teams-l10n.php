<?php

declare(strict_types=1);

/**
 * Server-translated strings for js/admin-teams.js (window.t may be unavailable).
 *
 * @var \OCP\IL10N $l
 */
$l = $l ?? ($_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck'));

$teamsUiKeys = [
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
	'Remove member',
	'Member removed',
	'Failed to remove member',
	'Remove "%s" as manager?',
	'Remove manager',
	'Manager removed',
	'Failed to remove manager',
];

$teamsL10n = [];
foreach ($teamsUiKeys as $msgid) {
	// Strings with %s must receive arguments; pass literal "%s" so JS can still .replace('%s', …).
	$placeholderCount = substr_count($msgid, '%s');
	if ($placeholderCount > 0) {
		$teamsL10n[$msgid] = $l->t($msgid, array_fill(0, $placeholderCount, '%s'));
	} else {
		$teamsL10n[$msgid] = $l->t($msgid);
	}
}

?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.teamsL10n = <?php echo json_encode($teamsL10n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>
