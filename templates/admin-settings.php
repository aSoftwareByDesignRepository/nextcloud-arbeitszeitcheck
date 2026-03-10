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
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <div class="section">
            <div class="section-header">
                <h2><?php p($l->t('Admin Settings')); ?></h2>
                <p><?php p($l->t('Set up how time tracking works for everyone in your organization')); ?></p>
            </div>

            <?php if (isset($_['error']) && !empty($_['error'])): ?>
                <div class="alert alert--error" role="alert" aria-live="polite">
                    <span class="alert-icon" aria-hidden="true">⚠️</span>
                    <div class="alert-content">
                        <strong class="alert-title"><?php p($l->t('Something went wrong')); ?></strong>
                        <p class="alert-message">
                            <?php 
                            // Make error message more helpful
                            $error = $_['error'];
                            if (strpos($error, 'Exception') !== false || strpos($error, 'Error') !== false || strpos($error, 'SQL') !== false) {
                                p($l->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'));
                            } else {
                                p($error);
                            }
                            ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <form id="admin-settings-form" class="form">
                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" id="autoComplianceCheck" name="autoComplianceCheck" 
                            <?php echo ($settings['autoComplianceCheck'] ?? true) ? 'checked' : ''; ?>>
                        <label for="autoComplianceCheck" class="form-label">
                            <?php p($l->t('Check working time rules automatically')); ?>
                        </label>
                    </div>
                    <p class="form-help">
                        <?php p($l->t('The system will automatically check if working hours follow German labor law. For example, it will warn if someone works more than 8 hours per day without a break.')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" id="requireBreakJustification" name="requireBreakJustification"
                            <?php echo ($settings['requireBreakJustification'] ?? true) ? 'checked' : ''; ?>>
                        <label for="requireBreakJustification" class="form-label">
                            <?php p($l->t('Require a reason when taking breaks')); ?>
                        </label>
                    </div>
                    <p class="form-help">
                        <?php p($l->t('When employees take breaks, they must write a short reason. This helps ensure breaks are used correctly and comply with labor law.')); ?>
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

                <fieldset class="form-fieldset" aria-labelledby="send-ical-legend">
                    <legend id="send-ical-legend" class="form-legend"><?php p($l->t('Absences: Send iCal by email after approval')); ?></legend>
                    <p class="form-help form-help--block">
                        <?php p($l->t('When an absence is approved, the employee can receive an email with an iCalendar (.ics) attachment so they can add the absence to their calendar.')); ?>
                    </p>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="sendIcalApprovedAbsences" name="sendIcalApprovedAbsences" value="1"
                                <?php echo ($settings['sendIcalApprovedAbsences'] ?? true) ? 'checked' : ''; ?>
                                aria-describedby="send-ical-legend">
                            <label for="sendIcalApprovedAbsences" class="form-label">
                                <?php p($l->t('Send iCal email to employee when absence is approved')); ?>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="sendIcalToSubstitute" name="sendIcalToSubstitute" value="1"
                                <?php echo ($settings['sendIcalToSubstitute'] ?? false) ? 'checked' : ''; ?>
                                aria-describedby="send-ical-legend">
                            <label for="sendIcalToSubstitute" class="form-label">
                                <?php p($l->t('Also send iCal to the substitute (if one was selected)')); ?>
                            </label>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="form-fieldset" aria-labelledby="require-substitute-legend">
                    <legend id="require-substitute-legend" class="form-legend"><?php p($l->t('Absences: Require substitute')); ?></legend>
                    <p class="form-help form-help--block">
                        <?php p($l->t('For the selected absence types, employees must specify who will cover for them. This helps ensure work is assigned during time off.')); ?>
                    </p>
                    <?php
                    $requireTypes = $settings['requireSubstituteTypes'] ?? [];
                    $absenceTypes = [
                        'vacation' => $l->t('Vacation'),
                        'sick_leave' => $l->t('Sick Leave'),
                        'personal_leave' => $l->t('Personal Leave'),
                        'parental_leave' => $l->t('Parental Leave'),
                        'special_leave' => $l->t('Special Leave'),
                        'unpaid_leave' => $l->t('Unpaid Leave'),
                        'home_office' => $l->t('Home Office'),
                        'business_trip' => $l->t('Business Trip'),
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

                <div class="form-group">
                    <label for="maxDailyHours" class="form-label">
                        <?php p($l->t('Maximum Hours Per Day')); ?>
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
                        <?php p($l->t('The most hours someone can work in one day. German law allows up to 8 hours normally, or 10 hours in special cases. Example: Enter 10 to allow up to 10 hours per day.')); ?>
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
                        <?php p($l->t('Minimum Rest Time Between Work Days')); ?>
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
                        <?php p($l->t('How many hours of rest someone must have between the end of one work day and the start of the next. German law requires at least 11 hours. Example: If someone finishes work at 6 PM, they cannot start again before 5 AM the next day.')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <label for="defaultWorkingHours" class="form-label">
                        <?php p($l->t('Standard Working Hours Per Day')); ?>
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
                        <?php p($l->t('The normal number of hours people work each day. This is used for new employees until you set their individual working hours. Example: Enter 8 for a standard 8-hour workday.')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <label for="germanState" class="form-label">
                        <?php p($l->t('Which German State?')); ?>
                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                    </label>
                    <select id="germanState" 
                            name="germanState" 
                            class="form-select" 
                            required
                            aria-describedby="germanState-help">
                        <?php
                        $states = ['NW' => 'North Rhine-Westphalia', 'BY' => 'Bavaria', 'BW' => 'Baden-Württemberg', 
                                   'HE' => 'Hesse', 'NI' => 'Lower Saxony', 'RP' => 'Rhineland-Palatinate', 
                                   'SL' => 'Saarland', 'BE' => 'Berlin', 'BB' => 'Brandenburg', 
                                   'HB' => 'Bremen', 'HH' => 'Hamburg', 'MV' => 'Mecklenburg-Western Pomerania', 
                                   'SN' => 'Saxony', 'ST' => 'Saxony-Anhalt', 'SH' => 'Schleswig-Holstein', 
                                   'TH' => 'Thuringia'];
                        $currentState = $settings['germanState'] ?? 'NW';
                        foreach ($states as $code => $name) {
                            $selected = ($currentState === $code) ? 'selected' : '';
                            echo "<option value=\"{$code}\" {$selected}>" . p($l->t($name)) . "</option>";
                        }
                        ?>
                    </select>
                    <p id="germanState-help" class="form-help">
                        <?php p($l->t('Select the German state where your organization is located. This ensures the system follows the correct labor laws for that state.')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <label for="retentionPeriod" class="form-label">
                        <?php p($l->t('How Long to Keep Time Records')); ?>
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
                        <?php p($l->t('How many years to keep time tracking records before they are automatically deleted. German law typically requires keeping records for 2 years. Example: Enter 2 to keep records for 2 years.')); ?>
                    </p>
                </div>

                <div class="card-actions">
                    <button type="submit" 
                            class="btn btn--primary"
                            aria-label="<?php p($l->t('Save all admin settings')); ?>"
                            title="<?php p($l->t('Click to save all settings. Changes will apply to all users.')); ?>">
                        <?php p($l->t('Save Settings')); ?>
                    </button>
                    <a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.index')); ?>"
                       class="btn btn--secondary"
                       aria-label="<?php p($l->t('Cancel and go back to dashboard')); ?>"
                       title="<?php p($l->t('Click to cancel and go back without saving changes')); ?>">
                        <?php p($l->t('Cancel')); ?>
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->
