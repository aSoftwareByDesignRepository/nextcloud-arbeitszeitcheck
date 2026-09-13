<?php
declare(strict_types=1);

/**
 * Admin global settings section partial.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var array $settings
 */

/** @var \OCP\IL10N $l */
/** @var array $settings */
$settings = is_array($settings ?? null) ? $settings : (is_array($_['settings'] ?? null) ? $_['settings'] : []);
$availableGroups = is_array($availableGroups ?? null) ? $availableGroups : (is_array($_['availableGroups'] ?? null) ? $_['availableGroups'] : []);
$availableAppAdmins = is_array($availableAppAdmins ?? null) ? $availableAppAdmins : (is_array($_['availableAppAdmins'] ?? null) ? $_['availableAppAdmins'] : []);
$availableAccessUsers = is_array($availableAccessUsers ?? null) ? $availableAccessUsers : (is_array($_['availableAccessUsers'] ?? null) ? $_['availableAccessUsers'] : []);
$azcSettingsShowCardChrome = !empty($azcSettingsShowCardChrome) || !empty($renderAll);
?>
                <section class="azc-card admin-settings-section" aria-labelledby="section-hours-heading">
                                        <header class="azc-card__header<?php echo empty($azcSettingsShowCardChrome) ? ' azc-card__header--page-title-only' : ''; ?>">
                        <div class="azc-card__header-text">
                            <?php if (!empty($azcSettingsShowCardChrome)): ?>
                            <h2 id="section-hours-heading" class="azc-card__title"><?php p($l->t('Daily hours and rest periods')); ?></h2>
                            <p class="azc-card__lead"><?php p($l->t('Set baseline working-time limits used for legal checks and default employee setup.')); ?></p>
                            <?php else: ?>
                            <h2 id="section-hours-heading" class="azc-card__title visually-hidden"><?php p($l->t('Daily hours and rest periods')); ?></h2>
                            <?php endif; ?>
                        </div>
                    </header>
                    <div class="azc-card__body">
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
                        <?php p($l->t('Upper limit of daily working time in hours. The suggested default comes from the selected country profile (Germany ArbZG: up to 10; Austria AZG: up to 12; Switzerland ArG: up to 10). Changing the country does not overwrite a value you already set.')); ?>
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
                        <?php p($l->t('Hours of rest between end of work and next start. DACH labour laws (ArbZG, AZG, ArG) generally require at least 11 hours.')); ?>
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
                           step="0.01" 
                           required
                           aria-describedby="defaultWorkingHours-help">
                    <p id="defaultWorkingHours-help" class="form-help">
                        <?php p($l->t('Default daily working hours. Used for new employees until individual models are set. Decimal hours are allowed (e.g. 7.74).')); ?>
                    </p>
                </div>

                <div class="form-group">
                    <label for="timePickerMinuteStep" class="form-label">
                        <?php p($l->t('Time picker minute step')); ?>
                    </label>
                    <?php $minuteStep = (int)($settings['timePickerMinuteStep'] ?? 1); ?>
                    <select id="timePickerMinuteStep"
                            name="timePickerMinuteStep"
                            class="form-select"
                            aria-describedby="timePickerMinuteStep-help">
                        <?php foreach ([1, 5, 10, 15] as $stepOpt): ?>
                            <option value="<?php p((string)$stepOpt); ?>"<?php echo $minuteStep === $stepOpt ? ' selected' : ''; ?>>
                                <?php
                                if ($stepOpt === 1) {
                                    p($l->t('Every minute (11:51)'));
                                } else {
                                    p($l->t('Every %s minutes', [(string)$stepOpt]));
                                }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p id="timePickerMinuteStep-help" class="form-help">
                        <?php p($l->t('Minute choices in manual time entry and corrections. Default is every minute. Choose 5/10/15 if your payroll rounds to those steps.')); ?>
                    </p>
                </div>
                </div><!-- /.azc-card__body -->
                </section>

