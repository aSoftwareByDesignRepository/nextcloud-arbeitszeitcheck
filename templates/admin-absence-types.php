<?php

declare(strict_types=1);

/**
 * Admin page: custom absence types ("weitere betriebliche Fehlzeiten")
 *
 * List + inline editor (same pattern as working-time-models): the editor
 * panel is rendered client-side by admin-absence-types.js, the list is
 * server-rendered so the page works without JS for read access.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$absenceTypes = $_['absenceTypes'] ?? [];
$localeOptions = $_['localeOptions'] ?? [];
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack">

	<section id="cat-editor-panel"
			class="azc-card cat-editor-panel"
			hidden
			aria-labelledby="cat-editor-heading"></section>

	<section class="azc-card custom-absence-types-card" aria-labelledby="custom-absence-types-heading">
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="custom-absence-types-heading" class="azc-card__title"><?php p($l->t('Custom absence types')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Additional absence types your employees can request, e.g. company outing, blood donation, or moving day.')); ?></p>
			</div>
			<div class="azc-card__header-actions">
				<button type="button"
						id="cat-create"
						class="azc-btn azc-btn--primary"
						aria-label="<?php p($l->t('Create new absence type')); ?>">
					<?php p($l->t('New absence type')); ?>
				</button>
			</div>
		</header>
		<div class="azc-card__body">
			<p class="form-help form-help--block cat-hint">
				<?php p($l->t('Employees see these types next to the built-in ones when they request an absence. Deleting a type that is still in use only disables it, so existing records keep working.')); ?>
			</p>
			<div class="table-container" tabindex="0" role="region" aria-label="<?php p($l->t('Custom absence types')); ?>">
				<table class="table table--hover azc-table--responsive" id="cat-table" role="table" aria-label="<?php p($l->t('Custom absence types')); ?>">
					<thead>
						<tr>
							<th scope="col"><?php p($l->t('Name')); ?></th>
							<th scope="col"><?php p($l->t('Status')); ?></th>
							<th scope="col"><?php p($l->t('Appearance')); ?></th>
							<th scope="col"><?php p($l->t('Count as paid')); ?></th>
							<th scope="col"><?php p($l->t('Max. days')); ?></th>
							<th scope="col"><?php p($l->t('In use')); ?></th>
							<th scope="col" class="azc-table-actions-col"><?php p($l->t('Actions')); ?></th>
						</tr>
					</thead>
					<tbody id="cat-tbody">
						<?php if (empty($absenceTypes)): ?>
							<tr>
								<td colspan="7" class="text-center cat-empty">
									<?php p($l->t('No custom absence types yet. Use “New absence type” to create one.')); ?>
								</td>
							</tr>
						<?php else: ?>
							<?php foreach ($absenceTypes as $type): ?>
								<tr data-type-id="<?php p((string)$type['id']); ?>">
									<td data-label="<?php p($l->t('Name')); ?>">
										<span class="cat-name"><?php p($type['resolvedLabel']); ?></span>
										<span class="cat-code" title="<?php p($l->t('Technical identifier')); ?>"><?php p($type['code']); ?></span>
									</td>
									<td data-label="<?php p($l->t('Status')); ?>">
										<?php if ($type['active']): ?>
											<span class="badge badge--success"><?php p($l->t('Active')); ?></span>
										<?php else: ?>
											<span class="badge badge--neutral"><?php p($l->t('Inactive')); ?></span>
										<?php endif; ?>
									</td>
									<td data-label="<?php p($l->t('Appearance')); ?>">
										<span class="cat-swatch cat-swatch--<?php p($type['color']); ?>" aria-hidden="true"></span>
										<span class="visually-hidden"><?php p($type['color']); ?></span>
									</td>
									<td data-label="<?php p($l->t('Count as paid')); ?>">
										<?php echo $type['sollCredit'] ? p($l->t('Yes')) : p($l->t('No')); ?>
									</td>
									<td data-label="<?php p($l->t('Max. days')); ?>"><?php p((string)$type['maxDays']); ?></td>
									<td data-label="<?php p($l->t('In use')); ?>"><?php p((string)$type['absenceCount']); ?></td>
									<td class="actions-cell" data-label="<?php p($l->t('Actions')); ?>">
										<div class="azc-table-actions" role="group" aria-label="<?php p($l->t('Actions')); ?>">
											<button type="button"
													class="btn btn--sm btn--secondary"
													data-action="cat-edit"
													data-type-id="<?php p((string)$type['id']); ?>">
												<?php p($l->t('Edit')); ?>
											</button>
											<button type="button"
													class="btn btn--sm btn--secondary"
													data-action="cat-toggle"
													data-type-id="<?php p((string)$type['id']); ?>"
													data-active="<?php p($type['active'] ? '1' : '0'); ?>">
												<?php echo $type['active'] ? p($l->t('Deactivate')) : p($l->t('Activate')); ?>
											</button>
											<button type="button"
													class="btn btn--sm btn--danger"
													data-action="cat-delete"
													data-type-id="<?php p((string)$type['id']); ?>"
													data-type-name="<?php p($type['resolvedLabel']); ?>"
													data-in-use="<?php p((string)$type['absenceCount']); ?>">
												<?php p($l->t('Delete')); ?>
											</button>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
	window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
	window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
	window.ArbeitszeitCheck.catConfig = {
		colors: <?php echo json_encode($_['allowedColors'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		icons: <?php echo json_encode($_['allowedIcons'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		locales: <?php echo json_encode($localeOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
	};
	<?php
	$catL10n = [
		'editorCreateTitle' => $l->t('New absence type'),
		'editorEditTitle' => $l->t('Edit absence type'),
		'editorLead' => $l->t('Set the name employees see, how the day counts for working time, and how it looks in the calendar. Cancel returns to the list.'),
		'nameLabel' => $l->t('Name'),
		'nameHelp' => $l->t('Shown to employees when they request an absence and in the calendar.'),
		'slugLabel' => $l->t('Identifier (optional)'),
		'slugHelp' => $l->t('Technical code for reports and integrations. Lowercase letters, numbers and underscores. Leave empty to generate it from the name.'),
		'translationsLegend' => $l->t('Translations (optional)'),
		'translationsHelp' => $l->t('Employees with another language see the translation below; everyone else sees the name above.'),
		'sollCreditLabel' => $l->t('Counts as paid absence'),
		'sollCreditHelp' => $l->t('When enabled, planned working hours during this absence count towards the working-time account (like sick leave), provided the paid-absence credit feature is turned on.'),
		'maxDaysLabel' => $l->t('Maximum duration in days'),
		'maxDaysHelp' => $l->t('Longest period employees can request with this type at once.'),
		'colorLabel' => $l->t('Calendar color'),
		'iconLabel' => $l->t('Calendar icon'),
		'activeLabel' => $l->t('Available for new requests'),
		'save' => $l->t('Save'),
		'cancel' => $l->t('Cancel'),
		'created' => $l->t('Absence type created'),
		'updated' => $l->t('Absence type updated'),
		'deleted' => $l->t('Absence type deleted'),
		'deactivated' => $l->t('The type is in use and was deactivated instead — existing records keep working.'),
		'activated' => $l->t('Absence type activated'),
		'deactivatedToggle' => $l->t('Absence type deactivated'),
		'confirmDelete' => $l->t('Delete the absence type “{name}”? Types that are still in use are deactivated instead of deleted, so existing records keep working.'),
		'confirmDeleteUnused' => $l->t('Delete the absence type “{name}”? It is not used in any absence and will be removed permanently.'),
		'confirmDeleteTitle' => $l->t('Delete absence type?'),
		'confirmUnavailable' => $l->t('Could not open the confirmation. Please reload the page and try again.'),
		'delete' => $l->t('Delete'),
		'failedToCreate' => $l->t('Failed to create the absence type'),
		'failedToUpdate' => $l->t('Failed to update the absence type'),
		'failedToDelete' => $l->t('Failed to delete the absence type'),
		'failedToToggle' => $l->t('Failed to change the availability'),
		'error' => $l->t('An error occurred'),
	];
	foreach ($catL10n as $key => $msg):
	?>
	window.ArbeitszeitCheck.l10n[<?php echo json_encode($key); ?>] = <?php echo json_encode($msg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	<?php endforeach; ?>
</script>

</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
