<?php
declare(strict_types=1);

/**
 * Workforce policy section — optional corrections, hourly leave, year-end reset.
 * SPEC: optional-absence-corrections-hourly-leave-year-end-2026-11 §9.1.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var array $settings
 */

$settings = is_array($settings ?? null) ? $settings : (is_array($_['settings'] ?? null) ? $_['settings'] : []);
$availableGroups = is_array($availableGroups ?? null) ? $availableGroups : (is_array($_['availableGroups'] ?? null) ? $_['availableGroups'] : []);
$azcSettingsShowCardChrome = !empty($azcSettingsShowCardChrome) || !empty($renderAll);

$policy = is_array($settings['workforcePolicy'] ?? null) ? $settings['workforcePolicy'] : [];
$past = is_array($policy['pastCancellation'] ?? null) ? $policy['pastCancellation'] : [];
$ye = is_array($policy['yearEnd'] ?? null) ? $policy['yearEnd'] : [];
$hourly = is_array($policy['hourlyAbsenceTypes'] ?? null) ? $policy['hourlyAbsenceTypes'] : [];
$targetGroups = is_array($ye['targetGroupIds'] ?? null) ? $ye['targetGroupIds'] : [];
$policyRevision = (int)($policy['revision'] ?? 0);
?>
                <section class="azc-card admin-settings-section" aria-labelledby="section-workforce-heading">
                    <header class="azc-card__header<?php echo empty($azcSettingsShowCardChrome) ? ' azc-card__header--page-title-only' : ''; ?>">
                        <div class="azc-card__header-text">
                            <?php if (!empty($azcSettingsShowCardChrome)): ?>
                            <h2 id="section-workforce-heading" class="azc-card__title"><?php p($l->t('Workforce policy')); ?></h2>
                            <p class="azc-card__lead"><?php p($l->t('Optional absence corrections, hour-based leave, and the year-end overtime reset. Everything here is off until you enable it.')); ?></p>
                            <?php else: ?>
                            <h2 id="section-workforce-heading" class="azc-card__title visually-hidden"><?php p($l->t('Workforce policy')); ?></h2>
                            <?php endif; ?>
                        </div>
                    </header>
                    <div class="azc-card__body">
                        <input type="hidden" name="workforcePolicyRevision" value="<?php p((string)$policyRevision); ?>">

                        <fieldset class="form-fieldset" aria-labelledby="wf-corrections-heading" aria-describedby="wf-corrections-help">
                            <legend id="wf-corrections-heading" class="form-legend"><?php p($l->t('Corrections to past absences')); ?></legend>
                            <p id="wf-corrections-help" class="form-help">
                                <?php p($l->t('Lets approved absences from the past be corrected through an auditable request flow instead of edits to a closed record.')); ?>
                            </p>
                            <div class="form-checkbox">
                                <input type="hidden" name="pastCorrectionEnabled" value="0">
                                <input type="checkbox" id="pastCorrectionEnabled" name="pastCorrectionEnabled" value="1"
                                       <?php echo !empty($past['enabled']) ? 'checked' : ''; ?>
                                       aria-describedby="pastCorrectionEnabled-help">
                                <label for="pastCorrectionEnabled" class="form-label">
                                    <?php p($l->t('Allow corrections to past absences')); ?>
                                </label>
                            </div>
                            <p id="pastCorrectionEnabled-help" class="form-help"><?php p($l->t('Master switch for the correction lifecycle. Manager direct changes still record a full audit trail.')); ?></p>
                            <div class="form-checkbox">
                                <input type="hidden" name="employeeCorrectionRequestsEnabled" value="0">
                                <input type="checkbox" id="employeeCorrectionRequestsEnabled" name="employeeCorrectionRequestsEnabled" value="1"
                                       <?php echo !empty($past['employeeCorrectionRequests']) ? 'checked' : ''; ?>>
                                <label for="employeeCorrectionRequestsEnabled" class="form-label">
                                    <?php p($l->t('Employees may file correction requests')); ?>
                                </label>
                            </div>
                            <div class="form-checkbox">
                                <input type="hidden" name="scopedManagerCorrectionApprovalEnabled" value="0">
                                <input type="checkbox" id="scopedManagerCorrectionApprovalEnabled" name="scopedManagerCorrectionApprovalEnabled" value="1"
                                       <?php echo !empty($past['scopedManagerCorrectionApproval']) ? 'checked' : ''; ?>>
                                <label for="scopedManagerCorrectionApprovalEnabled" class="form-label">
                                    <?php p($l->t('Team managers may decide corrections for their teams')); ?>
                                </label>
                            </div>
                        </fieldset>

                        <fieldset class="form-fieldset" aria-labelledby="wf-hourly-heading" aria-describedby="wf-hourly-help">
                            <legend id="wf-hourly-heading" class="form-legend"><?php p($l->t('Hour-based absence requests')); ?></legend>
                            <p id="wf-hourly-help" class="form-help">
                                <?php p($l->t('Employees can request part of a single day in minutes for the selected built-in types.')); ?>
                            </p>
                            <div class="form-checkbox">
                                <input type="hidden" name="hourlyUnpaidLeaveEnabled" value="0">
                                <input type="checkbox" id="hourlyUnpaidLeaveEnabled" name="hourlyUnpaidLeaveEnabled" value="1"
                                       <?php echo in_array('unpaid_leave', $hourly, true) ? 'checked' : ''; ?>>
                                <label for="hourlyUnpaidLeaveEnabled" class="form-label">
                                    <?php p($l->t('Unpaid leave in hours')); ?>
                                </label>
                            </div>
                            <div class="form-checkbox">
                                <input type="hidden" name="hourlyOvertimeCompEnabled" value="0">
                                <input type="checkbox" id="hourlyOvertimeCompEnabled" name="hourlyOvertimeCompEnabled" value="1"
                                       <?php echo in_array('overtime_comp', $hourly, true) ? 'checked' : ''; ?>>
                                <label for="hourlyOvertimeCompEnabled" class="form-label">
                                    <?php p($l->t('Compensatory time off in hours')); ?>
                                </label>
                            </div>
                        </fieldset>

                        <fieldset class="form-fieldset" aria-labelledby="wf-yearend-heading" aria-describedby="wf-yearend-help">
                            <legend id="wf-yearend-heading" class="form-legend"><?php p($l->t('Year-end overtime reset')); ?></legend>
                            <p id="wf-yearend-help" class="form-help">
                                <?php p($l->t('A guided, fully audited reset that zeroes overtime balances at the turn of the year.')); ?>
                            </p>
                            <div class="form-checkbox">
                                <input type="hidden" name="yearEndManualEnabled" value="0">
                                <input type="checkbox" id="yearEndManualEnabled" name="yearEndManualEnabled" value="1"
                                       <?php echo !empty($ye['manualEnabled']) ? 'checked' : ''; ?>
                                       aria-describedby="yearEndManualEnabled-help">
                                <label for="yearEndManualEnabled" class="form-label">
                                    <?php p($l->t('Enable the guided year-end reset')); ?>
                                </label>
                            </div>
                            <p id="yearEndManualEnabled-help" class="form-help"><?php p($l->t('Shows the year-end run wizard in the admin area. The reset always starts from a preview and requires a reason.')); ?></p>
                            <div class="form-checkbox">
                                <input type="hidden" name="yearEndAnnualEnabled" value="0">
                                <input type="checkbox" id="yearEndAnnualEnabled" name="yearEndAnnualEnabled" value="1"
                                       <?php echo !empty($ye['annualEnabled']) ? 'checked' : ''; ?>
                                       aria-describedby="yearEndAnnualEnabled-help">
                                <label for="yearEndAnnualEnabled" class="form-label">
                                    <?php p($l->t('Also reset automatically every January')); ?>
                                </label>
                            </div>
                            <p id="yearEndAnnualEnabled-help" class="form-help"><?php p($l->t('In the first week of January the system runs the same audited reset for the target groups below.')); ?></p>
                            <div class="form-checkbox">
                                <input type="hidden" name="yearEndResetPositive" value="0">
                                <input type="checkbox" id="yearEndResetPositive" name="yearEndResetPositive" value="1"
                                       <?php echo !empty($ye['resetPositive']) ? 'checked' : ''; ?>>
                                <label for="yearEndResetPositive" class="form-label">
                                    <?php p($l->t('Reset positive balances (overtime paid out or removed)')); ?>
                                </label>
                            </div>
                            <div class="form-checkbox">
                                <input type="hidden" name="yearEndWaiveNegative" value="0">
                                <input type="checkbox" id="yearEndWaiveNegative" name="yearEndWaiveNegative" value="1"
                                       <?php echo !empty($ye['waiveNegative']) ? 'checked' : ''; ?>>
                                <label for="yearEndWaiveNegative" class="form-label">
                                    <?php p($l->t('Waive negative balances (undertime forgiven)')); ?>
                                </label>
                            </div>

                            <div class="form-group">
                                <span class="form-label" id="wf-target-groups-label"><?php p($l->t('Groups in scope for the automatic run')); ?></span>
                                <p class="form-help"><?php p($l->t('Manual runs always pick users directly; the annual run resets every member of these groups.')); ?></p>
                                <input type="hidden" name="yearEndTargetGroupIds" value="">
                                <div class="access-groups-list" role="group" aria-labelledby="wf-target-groups-label">
                                    <?php foreach ($availableGroups as $groupOption): ?>
                                        <?php
                                        $groupId = (string)($groupOption['id'] ?? '');
                                        if ($groupId === '') {
                                            continue;
                                        }
                                        $groupDisplayName = (string)($groupOption['displayName'] ?? $groupId);
                                        ?>
                                        <label class="access-groups-item">
                                            <input type="checkbox"
                                                   name="yearEndTargetGroupIds[]"
                                                   value="<?php p($groupId); ?>"
                                                   <?php echo in_array($groupId, $targetGroups, true) ? 'checked' : ''; ?>>
                                            <span class="access-groups-item__label"><?php p($groupDisplayName); ?></span>
                                            <span class="access-groups-item__meta"><?php p($groupId); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </fieldset>
                    </div><!-- /.azc-card__body -->
                </section>
