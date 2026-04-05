<?php

declare(strict_types=1);

/**
 * Admin users template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$users = $_['users'] ?? [];
$total = $_['total'] ?? 0;
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <div class="section">
            <div class="section-header">
                <h2><?php p($l->t('Manage Employees')); ?></h2>
                <p><?php p($l->t('Add employees to time tracking and set their work schedules')); ?></p>
            </div>

            <!-- Search and Filters -->
            <div class="section-content">
                <div class="flex flex--between flex--gap mb-3">
                    <label for="user-search" class="visually-hidden"><?php p($l->t('Search users')); ?></label>
                    <input type="text" id="user-search" class="form-input" 
                        placeholder="<?php p($l->t('Search users...')); ?>">
                    <button type="button" id="refresh-users" class="btn btn--secondary">
                        <?php p($l->t('Refresh')); ?>
                    </button>
                </div>

                <!-- Users Table -->
                <div class="table-responsive" role="region" aria-label="<?php p($l->t('Employees list')); ?>">
                    <table class="table" id="users-table" role="table" aria-label="<?php p($l->t('Employees list')); ?>">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Employee Name')); ?></th>
                                <th scope="col"><?php p($l->t('Email Address')); ?></th>
                                <th scope="col"><?php p($l->t('Work Schedule')); ?></th>
                                <th scope="col"><?php p($l->t('Vacation days')); ?></th>
                                <th scope="col"><?php p($l->t('Valid from / to')); ?></th>
                                <th scope="col"><?php p($l->t('Status')); ?></th>
                                <th scope="col"><?php p($l->t('Actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="users-tbody">
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="7" class="text-center">
                                        <?php p($l->t('No users found')); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $formatDate = function ($iso) use ($l) {
                                    if (empty($iso)) return '-';
                                    $d = \DateTime::createFromFormat('Y-m-d', $iso);
                                    return $d ? $d->format('d.m.Y') : $iso;
                                };
                                ?>
                                <?php foreach (($users ?? []) as $user): ?>
                                    <?php
                                    $vacation = $user['vacationDaysPerYear'] ?? null;
                                    $start = $user['workingTimeModelStartDate'] ?? null;
                                    $end = $user['workingTimeModelEndDate'] ?? null;
                                    $validity = $start ? ($formatDate($start) . ($end ? ' – ' . $formatDate($end) : ' – ' . $l->t('ongoing'))) : '-';
                                    ?>
                                    <tr data-user-id="<?php p($user['userId']); ?>">
                                        <td><?php p($user['displayName']); ?></td>
                                        <td><?php p($user['email'] ?? '-'); ?></td>
                                        <td>
                                            <?php if ($user['workingTimeModel']): ?>
                                                <?php p($user['workingTimeModel']['name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted"><?php p($l->t('Not assigned')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php p($vacation !== null ? (string)$vacation : '-'); ?></td>
                                        <td><?php p($validity); ?></td>
                                        <td>
                                            <?php if ($user['enabled']): ?>
                                                <span class="badge badge--success"><?php p($l->t('Enabled')); ?></span>
                                            <?php else: ?>
                                                <span class="badge badge--error"><?php p($l->t('Disabled')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="user-actions" role="group" aria-label="<?php p($l->t('Actions for %s', [$user['displayName']])); ?>">
                                                <button type="button" 
                                                        class="btn btn--sm btn--tertiary" 
                                                        data-action="history-user" 
                                                        data-user-id="<?php p($user['userId']); ?>"
                                                        data-user-name="<?php p($user['displayName']); ?>"
                                                        aria-label="<?php p($l->t('View assignment history for %s', [$user['displayName']])); ?>"
                                                        title="<?php p($l->t('View work schedule history')); ?>">
                                                    <?php p($l->t('History')); ?>
                                                </button>
                                                <button type="button" 
                                                        class="btn btn--sm btn--secondary" 
                                                        data-action="edit-user" 
                                                        data-user-id="<?php p($user['userId']); ?>"
                                                        aria-label="<?php p($l->t('Edit this employee\'s work schedule')); ?>"
                                                        title="<?php p($l->t('Click to change this employee\'s work schedule or other settings')); ?>">
                                                    <?php p($l->t('Edit')); ?>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-info">
                    <p><?php p($l->t('Showing %d of %d users', [count($users), $total])); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<?php
// Prepare holiday / Bundesland states for use in the edit-user modal
$holidayStates = [
    'BW' => 'Baden‑Württemberg',
    'BY' => 'Bayern',
    'BE' => 'Berlin',
    'BB' => 'Brandenburg',
    'HB' => 'Bremen',
    'HH' => 'Hamburg',
    'HE' => 'Hessen',
    'MV' => 'Mecklenburg‑Vorpommern',
    'NI' => 'Niedersachsen',
    'NW' => 'Nordrhein‑Westfalen',
    'RP' => 'Rheinland‑Pfalz',
    'SL' => 'Saarland',
    'SN' => 'Sachsen',
    'ST' => 'Sachsen‑Anhalt',
    'SH' => 'Schleswig‑Holstein',
    'TH' => 'Thüringen',
];
$holidayStatesForJs = [];
foreach ($holidayStates as $code => $name) {
    $holidayStatesForJs[] = [
        'code' => $code,
        'label' => $l->t($name),
    ];
}
?>

<!-- Initialize JavaScript -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
    window.ArbeitszeitCheck.l10n.editUser = <?php echo json_encode($l->t('Edit User'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.save = <?php echo json_encode($l->t('Save'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.cancel = <?php echo json_encode($l->t('Cancel'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.workingTimeModel = <?php echo json_encode($l->t('Working Time Model'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.vacationDaysPerYear = <?php echo json_encode($l->t('Vacation Days Per Year'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.startDate = <?php echo json_encode($l->t('Start Date'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.endDate = <?php echo json_encode($l->t('End Date (Optional)'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.noModel = <?php echo json_encode($l->t('No Model Assigned'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.selectWorkScheduleHelp = <?php echo json_encode($l->t('Select a work schedule to assign to this employee'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.vacationDaysHelp = <?php echo json_encode($l->t('Number of vacation days per year (standard in Germany: 25 days)'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.vacationCarryoverLabel = <?php echo json_encode($l->t('Vacation carryover (opening balance)'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.vacationCarryoverHelp = <?php echo json_encode($l->t('Opening balance of carryover days for the selected calendar year (Resturlaub), e.g. from HR or migration. This is not the annual vacation entitlement from the working time model. The last day carryover can be used is set globally in Admin settings.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.vacationCarryoverYearLabel = <?php echo json_encode($l->t('Year for carryover balance'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.vacationCarryoverYearHelp = <?php echo json_encode($l->t('The calendar year this opening balance belongs to—the same year shown in vacation statistics. Use the year you are booking against (often the current year).'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.endDateHelp = <?php echo json_encode($l->t('Leave empty if the assignment has no end date'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.endDateOptional = <?php echo json_encode($l->t('End Date (Optional)'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.userUpdated = <?php echo json_encode($l->t('User updated successfully'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.currentAssignment = <?php echo json_encode($l->t('Current assignment'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.changeAssignment = <?php echo json_encode($l->t('Change assignment'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.assignmentHistory = <?php echo json_encode($l->t('Assignment history'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.noAssignmentHistory = <?php echo json_encode($l->t('No assignment history'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.active = <?php echo json_encode($l->t('Active'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.ended = <?php echo json_encode($l->t('Ended'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.vacationDays = <?php echo json_encode($l->t('vacation days'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.loading = <?php echo json_encode($l->t('Loading'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.ongoing = <?php echo json_encode($l->t('ongoing'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.notAssigned = <?php echo json_encode($l->t('Not assigned'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.history = <?php echo json_encode($l->t('History'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.close = <?php echo json_encode($l->t('Close'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.workSchedule = <?php echo json_encode($l->t('Work schedule'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.vacationDaysCol = <?php echo json_encode($l->t('Vacation days'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.validFrom = <?php echo json_encode($l->t('Valid from'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.validTo = <?php echo json_encode($l->t('Valid to'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.status = <?php echo json_encode($l->t('Status'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.germanStateLabel = <?php echo json_encode($l->t('Federal state for holidays / calendar'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.germanStateHelp = <?php echo json_encode($l->t('Select the federal state whose holiday calendar applies to this person. If not set, the global default state is used.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.germanStateDefault = <?php echo json_encode($l->t('Use global default state'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.failedToLoadUserDetails = <?php echo json_encode($l->t('Failed to load user details'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.errorLoadingHistory = <?php echo json_encode($l->t('Error loading assignment history'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.ddmmYYYY = <?php echo json_encode($l->t('dd.mm.yyyy'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.states = <?php echo json_encode($holidayStatesForJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
