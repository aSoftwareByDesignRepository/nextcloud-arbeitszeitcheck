<?php

declare(strict_types=1);

/**
 * Working time models template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$models = $_['models'] ?? [];
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <div class="section">
            <div class="section-header">
                <h2><?php p($l->t('Working Time Models')); ?></h2>
                <p><?php p($l->t('Set up different work schedules and assign them to employees')); ?></p>
            </div>

            <div class="section-content mb-3">
                <button type="button" 
                        id="create-model" 
                        class="btn btn--primary"
                        aria-label="<?php p($l->t('Create a new working time model')); ?>"
                        title="<?php p($l->t('Click to create a new work schedule. For example, you could create a model for full-time employees (8 hours per day) or part-time employees (4 hours per day).')); ?>">
                    <?php p($l->t('Create New Work Schedule')); ?>
                </button>
            </div>

            <!-- Models Table -->
            <div class="table-container" role="region" aria-label="<?php p($l->t('Working time models')); ?>">
                <table class="table table--hover" id="models-table" role="table" aria-label="<?php p($l->t('Working time models')); ?>">
                <thead>
                    <tr>
                        <th scope="col"><?php p($l->t('Name')); ?></th>
                        <th scope="col"><?php p($l->t('Type')); ?></th>
                        <th scope="col"><?php p($l->t('Weekly Hours')); ?></th>
                        <th scope="col"><?php p($l->t('Daily Hours')); ?></th>
                        <th scope="col"><?php p($l->t('Default')); ?></th>
                        <th scope="col"><?php p($l->t('Actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="models-tbody">
                    <?php if (empty($models)): ?>
                        <tr>
                            <td colspan="6" class="text-center">
                                <?php p($l->t('No working time models found')); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach (($models ?? []) as $model): ?>
                            <tr data-model-id="<?php p($model['id']); ?>">
                                <td><?php p($model['name']); ?></td>
                                <td><?php p($model['type']); ?></td>
                                <td><?php p($model['weeklyHours']); ?>h</td>
                                <td><?php p($model['dailyHours']); ?>h</td>
                                <td>
                                    <?php if ($model['isDefault']): ?>
                                        <span class="badge badge--success"><?php p($l->t('Yes')); ?></span>
                                    <?php else: ?>
                                        <span class="badge"><?php p($l->t('No')); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" 
                                            class="btn btn--sm btn--secondary" 
                                            data-action="edit-model" 
                                            data-model-id="<?php p($model['id']); ?>"
                                            aria-label="<?php p($l->t('Edit this work schedule')); ?>"
                                            title="<?php p($l->t('Click to change the working hours or name of this work schedule')); ?>">
                                        <?php p($l->t('Edit')); ?>
                                    </button>
                                    <button type="button" 
                                            class="btn btn--sm btn--danger" 
                                            data-action="delete-model" 
                                            data-model-id="<?php p($model['id']); ?>"
                                            aria-label="<?php p($l->t('Delete this work schedule')); ?>"
                                            title="<?php p($l->t('Click to delete this work schedule. This cannot be undone, so make sure no employees are using it first.')); ?>">
                                        <?php p($l->t('Delete')); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<!-- Initialize JavaScript -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
    window.ArbeitszeitCheck.l10n.confirmDeleteModel = <?php echo json_encode($l->t('Are you sure you want to delete this work schedule?\n\nThis will permanently remove this schedule. If any employees are using this schedule, you should assign them to a different schedule first.\n\nThis action cannot be undone.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.createModel = <?php echo json_encode($l->t('Create Working Time Model'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.editModel = <?php echo json_encode($l->t('Edit Working Time Model'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.create = <?php echo json_encode($l->t('Create'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.save = <?php echo json_encode($l->t('Save'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.cancel = <?php echo json_encode($l->t('Cancel'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.name = <?php echo json_encode($l->t('Name'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.description = <?php echo json_encode($l->t('Description'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.type = <?php echo json_encode($l->t('Type'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.weeklyHours = <?php echo json_encode($l->t('Weekly Hours'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.dailyHours = <?php echo json_encode($l->t('Daily Hours'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.isDefault = <?php echo json_encode($l->t('Set as Default'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.fullTime = <?php echo json_encode($l->t('Full-Time'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.partTime = <?php echo json_encode($l->t('Part-Time'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.flexible = <?php echo json_encode($l->t('Flexible'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.trustBased = <?php echo json_encode($l->t('Trust-Based'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.shiftWork = <?php echo json_encode($l->t('Shift Work'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.modelNameHelp = <?php echo json_encode($l->t('Enter a name for this work schedule (e.g., "Full-Time", "Part-Time")'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.modelCreated = <?php echo json_encode($l->t('Model created successfully'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.modelUpdated = <?php echo json_encode($l->t('Model updated successfully'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
