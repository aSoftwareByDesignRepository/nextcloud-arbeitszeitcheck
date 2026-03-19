<?php

declare(strict_types=1);

/**
 * Admin settings template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$settings = $_['settings'] ?? [];
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$apiSettingsUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.admin.updateAdminSettings');
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper" role="main" aria-label="<?php p($l->t('Settings')); ?>">
        <div class="section">
            <div class="section-header">
                <h2><?php p($l->t('Settings for ArbeitszeitCheck')); ?></h2>
                <p><?php p($l->t('Configure how time tracking and compliance checks work for all employees.')); ?></p>
            </div>

            <?php if (isset($_['error']) && !empty($_['error'])): ?>
                <div class="alert alert--error" role="alert" aria-live="polite">
                    <span class="alert-icon" aria-hidden="true">⚠️</span>
                    <div class="alert-content">
                        <strong class="alert-title"><?php p($l->t('An error occurred')); ?></strong>
                        <p class="alert-message">
                            <?php 
                            // Make error message more helpful
                            $error = $_['error'];
                            if (strpos($error, 'Exception') !== false || strpos($error, 'Error') !== false || strpos($error, 'SQL') !== false) {
                                p($l->t('Please try again. If the problem persists, contact your administrator.'));
                            } else {
                                p($error);
                            }
                            ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <form id="admin-settings-form" class="form admin-settings-form" method="post" action="#" novalidate>
                <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken'] ?? ''); ?>">
                <section class="admin-settings-section" aria-labelledby="section-compliance-heading">
                    <h3 id="section-compliance-heading" class="admin-settings-section__title"><?php p($l->t('Compliance and working time rules')); ?></h3>
                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" id="autoComplianceCheck" name="autoComplianceCheck"
                            <?php echo ($settings['autoComplianceCheck'] ?? true) ? 'checked' : ''; ?>
                            aria-describedby="autoComplianceCheck-help">
                        <label for="autoComplianceCheck" class="form-label">
                            <?php p($l->t('Check working time rules automatically')); ?>
                        </label>
                    </div>
                    <p id="autoComplianceCheck-help" class="form-help">
                        <?php p($l->t('The system will automatically check if working hours follow German labor law. For example, it will warn if someone works more than 8 hours per day without a break.')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" id="realtimeComplianceCheck" name="realtimeComplianceCheck"
                            <?php echo ($settings['realtimeComplianceCheck'] ?? true) ? 'checked' : ''; ?>
                            aria-describedby="realtimeComplianceCheck-help">
                        <label for="realtimeComplianceCheck" class="form-label">
                            <?php p($l->t('Real-time compliance check when recording')); ?>
                        </label>
                    </div>
                    <p id="realtimeComplianceCheck-help" class="form-help">
                        <?php p($l->t('Checks working times immediately when saving or editing. Disable only if you run compliance checks exclusively via batch processing.')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" id="complianceStrictMode" name="complianceStrictMode"
                            <?php echo ($settings['complianceStrictMode'] ?? false) ? 'checked' : ''; ?>
                            aria-describedby="complianceStrictMode-help">
                        <label for="complianceStrictMode" class="form-label">
                            <?php p($l->t('Strict mode: Violations block saving')); ?>
                        </label>
                    </div>
                    <p id="complianceStrictMode-help" class="form-help">
                        <?php p($l->t('In default mode, violations are shown but saving is still possible. In strict mode, violations prevent saving the time entry.')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" id="enableViolationNotifications" name="enableViolationNotifications"
                            <?php echo ($settings['enableViolationNotifications'] ?? true) ? 'checked' : ''; ?>>
                        <label for="enableViolationNotifications" class="form-label">
                            <?php p($l->t('Send alerts when working time rules are broken')); ?>
                        </label>
                    </div>
                    <p class="form-help">
                        <?php p($l->t('When someone works too many hours or doesn\'t take required breaks, the system will send a notification to managers and the employee.')); ?>
                    </p>
                </div>
                </section>

                <section class="admin-settings-section" aria-labelledby="section-export-heading">
                    <h3 id="section-export-heading" class="admin-settings-section__title"><?php p($l->t('Exports and reporting')); ?></h3>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox"
                                   id="exportMidnightSplitEnabled"
                                   name="exportMidnightSplitEnabled"
                                   <?php echo ($settings['exportMidnightSplitEnabled'] ?? true) ? 'checked' : ''; ?>
                                   aria-describedby="exportMidnightSplitEnabled-help">
                            <label for="exportMidnightSplitEnabled" class="form-label">
                                <?php p($l->t('Split overnight entries at midnight in CSV/JSON export')); ?>
                            </label>
                        </div>
                        <p id="exportMidnightSplitEnabled-help" class="form-help">
                            <?php p($l->t('When enabled, entries that run across midnight (for example 22:00–06:00) are shown as two lines in the export (before and after 00:00). This is only a visual/export split – all internal working time and ArbZG compliance checks continue to use the original, unsplit entry.')); ?>
                        </p>
                    </div>
                </section>

                <section class="admin-settings-section" aria-labelledby="section-absences-heading">
                    <h3 id="section-absences-heading" class="admin-settings-section__title"><?php p($l->t('Absences and notifications')); ?></h3>
                <fieldset class="form-fieldset" aria-labelledby="send-ical-legend">
                    <legend id="send-ical-legend" class="form-legend"><?php p($l->t('Absences: Send iCal via email')); ?></legend>
                    <p class="form-help form-help--block">
                        <?php p($l->t('For approved absences, an email with an iCal attachment (.ics) can be sent automatically.')); ?>
                    </p>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="sendIcalApprovedAbsences" name="sendIcalApprovedAbsences" value="1"
                                <?php echo ($settings['sendIcalApprovedAbsences'] ?? true) ? 'checked' : ''; ?>
                                aria-describedby="send-ical-legend">
                            <label for="sendIcalApprovedAbsences" class="form-label">
                                <?php p($l->t('Send iCal to the person with approved absence')); ?>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="sendIcalToSubstitute" name="sendIcalToSubstitute" value="1"
                                <?php echo ($settings['sendIcalToSubstitute'] ?? false) ? 'checked' : ''; ?>
                                aria-describedby="send-ical-legend">
                            <label for="sendIcalToSubstitute" class="form-label">
                                <?php p($l->t('Also send iCal to substitute (if selected)')); ?>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="sendIcalToManagers" name="sendIcalToManagers" value="1"
                                <?php echo ($settings['sendIcalToManagers'] ?? false) ? 'checked' : ''; ?>
                                aria-describedby="send-ical-legend">
                            <label for="sendIcalToManagers" class="form-label">
                                <?php p($l->t('Also send iCal to managers (team managers)')); ?>
                            </label>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="form-fieldset" aria-labelledby="email-notifications-legend">
                    <legend id="email-notifications-legend" class="form-legend"><?php p($l->t('Absences: Email notifications for substitution workflow')); ?></legend>
                    <p class="form-help form-help--block">
                        <?php p($l->t('When a substitute is selected, emails can be sent at each step of the approval process.')); ?>
                    </p>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="sendEmailSubstitutionRequest" name="sendEmailSubstitutionRequest" value="1"
                                <?php echo ($settings['sendEmailSubstitutionRequest'] ?? true) ? 'checked' : ''; ?>
                                aria-describedby="email-notifications-legend">
                            <label for="sendEmailSubstitutionRequest" class="form-label">
                                <?php p($l->t('Email substitute when a substitution request is created')); ?>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="sendEmailSubstituteApprovedToEmployee" name="sendEmailSubstituteApprovedToEmployee" value="1"
                                <?php echo ($settings['sendEmailSubstituteApprovedToEmployee'] ?? true) ? 'checked' : ''; ?>
                                aria-describedby="email-notifications-legend">
                            <label for="sendEmailSubstituteApprovedToEmployee" class="form-label">
                                <?php p($l->t('Email employee when substitute approves')); ?>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="sendEmailSubstituteApprovedToManager" name="sendEmailSubstituteApprovedToManager" value="1"
                                <?php echo ($settings['sendEmailSubstituteApprovedToManager'] ?? true) ? 'checked' : ''; ?>
                                aria-describedby="email-notifications-legend">
                            <label for="sendEmailSubstituteApprovedToManager" class="form-label">
                                <?php p($l->t('Email managers when substitute approves (requires app teams)')); ?>
                            </label>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="form-fieldset" aria-labelledby="require-substitute-legend">
                    <legend id="require-substitute-legend" class="form-legend"><?php p($l->t('Absences: Substitute required')); ?></legend>
                    <p class="form-help form-help--block">
                        <?php p($l->t('For the selected absence types, a substitute must be designated.')); ?>
                    </p>
                    <?php
                    $requireTypes = $settings['requireSubstituteTypes'] ?? [];
                    $absenceTypes = [
                        'vacation' => $l->t('Vacation'),
                        'sick_leave' => $l->t('Sick leave'),
                        'personal_leave' => $l->t('Personal reasons'),
                        'parental_leave' => $l->t('Parental leave'),
                        'special_leave' => $l->t('Special leave'),
                        'unpaid_leave' => $l->t('Unpaid leave'),
                        'home_office' => $l->t('Home office'),
                        'business_trip' => $l->t('Business trip'),
                    ];
                    foreach ($absenceTypes as $typeKey => $typeLabel):
                        $checked = in_array($typeKey, $requireTypes, true);
                    ?>
                    <div class="form-group form-group--inline">
                        <div class="form-checkbox">
                            <input type="checkbox" id="requireSubstitute_<?php p($typeKey); ?>" name="requireSubstituteTypes[]" value="<?php p($typeKey); ?>"
                                <?php echo $checked ? 'checked' : ''; ?>
                                aria-describedby="require-substitute-legend">
                            <label for="requireSubstitute_<?php p($typeKey); ?>" class="form-label"><?php p($typeLabel); ?></label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </fieldset>
                </section>

                <section class="admin-settings-section" aria-labelledby="section-hours-heading">
                    <h3 id="section-hours-heading" class="admin-settings-section__title"><?php p($l->t('Daily hours and rest periods')); ?></h3>
                <div class="form-group">
                    <label for="maxDailyHours" class="form-label">
                        <?php p($l->t('Maximum working hours per day (in hours)')); ?>
                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                    </label>
                    <input type="number" 
                           id="maxDailyHours" 
                           name="maxDailyHours" 
                           class="form-input <?php echo isset($_['errors']['maxDailyHours']) ? 'form-input--error' : ''; ?>"
                           value="<?php p($settings['maxDailyHours'] ?? 10); ?>" 
                           min="1" 
                           max="24" 
                           step="0.5" 
                           required
                           aria-describedby="maxDailyHours-help <?php echo isset($_['errors']['maxDailyHours']) ? 'maxDailyHours-error' : ''; ?>"
                           aria-invalid="<?php echo isset($_['errors']['maxDailyHours']) ? 'true' : 'false'; ?>">
                    <p id="maxDailyHours-help" class="form-help">
                        <?php p($l->t('Upper limit of daily working time in hours. German labor law (ArbZG) allows 8 hours normally, up to 10 in special cases.')); ?>
                    </p>
                    <?php if (isset($_['errors']['maxDailyHours'])): ?>
                        <?php 
                        $fieldName = 'maxDailyHours';
                        $errorMessage = is_array($_['errors']['maxDailyHours']) ? $_['errors']['maxDailyHours'][0] : $_['errors']['maxDailyHours'];
                        include __DIR__ . '/common/form-error.php';
                        ?>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="minRestPeriod" class="form-label">
                        <?php p($l->t('Minimum rest period between work days (in hours)')); ?>
                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                    </label>
                    <input type="number" 
                           id="minRestPeriod" 
                           name="minRestPeriod" 
                           class="form-input"
                           value="<?php p($settings['minRestPeriod'] ?? 11); ?>" 
                           min="1" 
                           max="24" 
                           step="0.5" 
                           required
                           aria-describedby="minRestPeriod-help">
                    <p id="minRestPeriod-help" class="form-help">
                        <?php p($l->t('Hours of rest between end of work and next start. German law requires at least 11 hours.')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <label for="defaultWorkingHours" class="form-label">
                        <?php p($l->t('Standard working hours per day')); ?>
                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                    </label>
                    <input type="number" 
                           id="defaultWorkingHours" 
                           name="defaultWorkingHours" 
                           class="form-input"
                           value="<?php p($settings['defaultWorkingHours'] ?? 8); ?>" 
                           min="1" 
                           max="24" 
                           step="0.5" 
                           required
                           aria-describedby="defaultWorkingHours-help">
                    <p id="defaultWorkingHours-help" class="form-help">
                        <?php p($l->t('Default daily working hours. Used for new employees until individual models are set.')); ?>
                    </p>
                </div>
                </section>

                <section class="admin-settings-section" aria-labelledby="section-regional-heading">
                    <h3 id="section-regional-heading" class="admin-settings-section__title"><?php p($l->t('Region and holidays')); ?></h3>
                <div class="form-group">
                    <label for="germanState" class="form-label">
                        <?php p($l->t('Default federal state for holidays')); ?>
                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                    </label>
                    <select id="germanState" 
                            name="germanState" 
                            class="form-select" 
                            required
                            aria-describedby="germanState-help">
                        <?php
                        $states = [
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
                        $currentState = $settings['germanState'] ?? 'NW';
                        foreach ($states as $code => $name) {
                            $selected = ($currentState === $code) ? ' selected' : '';
                            $label = $l->t($name);
                            echo '<option value="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' .
                                htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
                                '</option>';
                        }
                        ?>
                    </select>
                    <p id="germanState-help" class="form-help">
                        <?php p($l->t('Used for statutory holidays and compliance when no specific state is configured for employees or teams.')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" 
                               id="statutoryAutoReseed" 
                               name="statutoryAutoReseed" 
                               value="1"
                               <?php echo ($settings['statutoryAutoReseed'] ?? true) ? 'checked' : ''; ?>
                               aria-describedby="statutoryAutoReseed-help">
                        <label for="statutoryAutoReseed" class="form-label">
                            <?php p($l->t('Auto-restore statutory holidays when viewing calendar')); ?>
                        </label>
                    </div>
                    <p id="statutoryAutoReseed-help" class="form-help">
                        <?php p($l->t('When enabled, missing statutory holidays are added when the calendar is viewed. Disable if you want deleted holidays to stay removed.')); ?>
                    </p>
                </div>
                </section>

                <section class="admin-settings-section" aria-labelledby="section-retention-heading">
                    <h3 id="section-retention-heading" class="admin-settings-section__title"><?php p($l->t('Data retention')); ?></h3>
                <div class="form-group">
                    <label for="retentionPeriod" class="form-label">
                        <?php p($l->t('Data retention period for time records (in years)')); ?>
                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                    </label>
                    <input type="number" 
                           id="retentionPeriod" 
                           name="retentionPeriod" 
                           class="form-input"
                           value="<?php p($settings['retentionPeriod'] ?? 2); ?>" 
                           min="1" 
                           max="10" 
                           required
                           aria-describedby="retentionPeriod-help">
                    <p id="retentionPeriod-help" class="form-help">
                        <?php p($l->t('Number of years to keep time tracking data before automatic deletion (typically at least 2 years).')); ?>
                    </p>
                </div>
                </section>

                <div class="card-actions">
                    <button type="submit" 
                            class="btn btn--primary"
                            aria-label="<?php p($l->t('Save all settings')); ?>"
                            title="<?php p($l->t('Save changes and apply to all users')); ?>">
                        <?php p($l->t('Save all settings')); ?>
                    </button>
                    <a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.index')); ?>"
                       class="btn btn--secondary"
                       aria-label="<?php p($l->t('Cancel and return to overview')); ?>"
                       title="<?php p($l->t('Go back without saving changes')); ?>">
                        <?php p($l->t('Cancel')); ?>
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.adminSettingsApiUrl = <?php echo json_encode($apiSettingsUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
