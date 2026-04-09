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
                        <p id="exportMidnightSplitEnabled-example" class="form-help form-help--note">
                            <?php p($l->t('Example for CSV/JSON long layout: row 1 has date = first calendar day, start_time 22:00:00, end_time 23:59:59; row 2 has date = next day, start_time 00:00:00, end_time 06:00:00. Column working_hours is the work time share per segment (the segments sum to the full entry). This is not an extra "break" row — rest breaks remain tied to the original booking; split rows may show empty break columns.')); ?>
                        </p>
                        <p class="form-help form-help--note" id="exportDatevMidnight-note">
                            <?php p($l->t('DATEV export always uses full, unsplit time entries as required by the DATEV payroll format. CSV and JSON exports respect the midnight split setting above when it is enabled.')); ?>
                        </p>
                    </div>
                </section>

                <section class="admin-settings-section" aria-labelledby="section-absences-heading">
                    <h3 id="section-absences-heading" class="admin-settings-section__title"><?php p($l->t('Absences and notifications')); ?></h3>
                <fieldset class="form-fieldset" aria-labelledby="vacation-carryover-expiry-legend">
                    <legend id="vacation-carryover-expiry-legend" class="form-legend"><?php p($l->t('Vacation carryover expiry')); ?></legend>
                    <p class="form-help form-help--block" id="vacation-carryover-expiry-intro">
                        <?php p($l->t('This is the last calendar day in each year when carryover from the opening balance (Resturlaub) may still be used for vacation. You enter each person\'s opening balance per calendar year under Users. After this date, new vacation requests can only use the annual vacation entitlement from the working time model—not carryover. This applies to everyone.')); ?>
                    </p>
                    <p class="form-help form-help--block form-help--note" id="vacation-carryover-expiry-how">
                        <?php p($l->t('Only approved vacation counts. For working days on or before this date, carryover is used before annual entitlement. Approved absences are applied in chronological order (by start date, then id).')); ?>
                    </p>
                    <div class="form-row form-row--inline" role="group" aria-labelledby="vacation-carryover-expiry-legend" aria-describedby="vacation-carryover-expiry-intro vacation-carryover-expiry-how vacation-carryover-expiry-help">
                        <div class="form-group">
                            <label for="vacationCarryoverExpiryMonth" class="form-label"><?php p($l->t('Month (1–12)')); ?></label>
                            <input type="number" class="form-input" id="vacationCarryoverExpiryMonth" name="vacationCarryoverExpiryMonth"
                                min="1" max="12" step="1" required
                                value="<?php p((string)($settings['vacationCarryoverExpiryMonth'] ?? 3)); ?>"
                                aria-describedby="vacation-carryover-expiry-intro vacation-carryover-expiry-how vacation-carryover-expiry-help">
                        </div>
                        <div class="form-group">
                            <label for="vacationCarryoverExpiryDay" class="form-label"><?php p($l->t('Day (1–31)')); ?></label>
                            <input type="number" class="form-input" id="vacationCarryoverExpiryDay" name="vacationCarryoverExpiryDay"
                                min="1" max="31" step="1" required
                                value="<?php p((string)($settings['vacationCarryoverExpiryDay'] ?? 31)); ?>"
                                aria-describedby="vacation-carryover-expiry-intro vacation-carryover-expiry-how vacation-carryover-expiry-help">
                        </div>
                    </div>
                    <p id="vacation-carryover-expiry-help" class="form-help">
                        <?php p($l->t('Typical value in Germany: 31 March (month 3, day 31). If that day does not exist in a month (e.g. 31 February), the last day of that month is used automatically.')); ?>
                    </p>
                    <div class="form-group">
                        <label for="vacationCarryoverMaxDays" class="form-label"><?php p($l->t('Maximum carryover days (optional)')); ?></label>
                        <input type="text" class="form-input" id="vacationCarryoverMaxDays" name="vacationCarryoverMaxDays" inputmode="decimal"
                            placeholder="<?php p($l->t('Empty = no limit')); ?>"
                            value="<?php p((string)($settings['vacationCarryoverMaxDays'] ?? '')); ?>"
                            aria-describedby="vacation-carryover-max-help">
                        <p id="vacation-carryover-max-help" class="form-help">
                            <?php p($l->t('If set, opening carryover per user cannot exceed this many days (Tarifvertrag / company policy). Leave empty for no cap. Imports and admin edits are clamped to this value.')); ?>
                        </p>
                    </div>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="vacationRolloverEnabled" name="vacationRolloverEnabled" value="1"
                                <?php echo ($settings['vacationRolloverEnabled'] ?? true) ? 'checked' : ''; ?>
                                aria-describedby="vacation-rollover-enabled-help">
                            <label for="vacationRolloverEnabled" class="form-label"><?php p($l->t('Automatic vacation rollover job')); ?></label>
                        </div>
                        <p id="vacation-rollover-enabled-help" class="form-help">
                            <?php p($l->t('When enabled, a daily task may copy unused carryover (and optionally unused annual days, see below) into the next calendar year’s opening balance after the carryover deadline, unless a balance already exists for that year. Use the occ command for manual runs.')); ?>
                        </p>
                    </div>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="vacationRolloverIncludeUnusedAnnual" name="vacationRolloverIncludeUnusedAnnual" value="1"
                                <?php echo ($settings['vacationRolloverIncludeUnusedAnnual'] ?? false) ? 'checked' : ''; ?>
                                aria-describedby="vacation-rollover-annual-help">
                            <label for="vacationRolloverIncludeUnusedAnnual" class="form-label"><?php p($l->t('Include unused annual entitlement in rollover (advanced)')); ?></label>
                        </div>
                        <p id="vacation-rollover-annual-help" class="form-help form-help--note">
                            <?php p($l->t('Off by default. Only enable if your collective agreement allows transferring unused annual leave; consult HR / legal. When on, unused annual days for the year may be added to the next year’s carryover opening, subject to the maximum carryover cap above.')); ?>
                        </p>
                    </div>
                </fieldset>
                <fieldset class="form-fieldset" aria-labelledby="send-ical-legend">
                    <legend id="send-ical-legend" class="form-legend"><?php p($l->t('Absences: Send iCal via email')); ?></legend>
                    <p class="form-help form-help--block">
                        <?php p($l->t('For approved absences, an email with an iCal attachment (.ics) can be sent automatically.')); ?>
                    </p>
                    <p class="form-help form-help--block form-help--note">
                        <?php p($l->t('Important: This is best-effort email delivery, not a guaranteed real-time calendar sync. Delivery can be delayed or fail due to mail server/network issues. Source of truth remains ArbeitszeitCheck.')); ?>
                    </p>
                    <p class="form-help form-help--block form-help--note">
                        <?php p($l->t('Privacy note: To reduce sensitive data exposure, iCal details for substitutes/managers intentionally avoid private absence reasons.')); ?>
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
                           step="0.1" 
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
                           step="0.1" 
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
                           step="0.1" 
                           required
                           aria-describedby="defaultWorkingHours-help">
                    <p id="defaultWorkingHours-help" class="form-help">
                        <?php p($l->t('Default daily working hours. Used for new employees until individual models are set. Decimal hours are allowed (e.g. 7.7).')); ?>
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
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
window.ArbeitszeitCheck.l10n.settingsSavedSuccessfully = <?php echo json_encode($l->t('Settings saved successfully'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.failedToSaveSettings = <?php echo json_encode($l->t('Failed to save settings'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.errorSavingSettings = <?php echo json_encode($l->t('An error occurred while saving settings'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.maxDailyHoursRange = <?php echo json_encode($l->t('Maximum daily hours must be between 1 and 24'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.minRestPeriodRange = <?php echo json_encode($l->t('Minimum rest period must be between 1 and 24 hours'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.defaultWorkingHoursRange = <?php echo json_encode($l->t('Default working hours must be between 1 and 24'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.retentionPeriodRange = <?php echo json_encode($l->t('Retention period must be between 1 and 10 years'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.carryoverMonthRange = <?php echo json_encode($l->t('Carryover expiry month must be between 1 and 12'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.carryoverDayRange = <?php echo json_encode($l->t('Carryover expiry day must be between 1 and 31'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.maxCarryoverDaysRange = <?php echo json_encode($l->t('Maximum carryover days must be empty (unlimited) or between 0 and 366'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.valueBetweenMinMax = <?php echo json_encode($l->t('Value must be between {min} and {max}'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
