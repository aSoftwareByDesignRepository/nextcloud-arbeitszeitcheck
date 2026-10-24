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
                <section class="azc-card admin-settings-section" aria-labelledby="section-compliance-heading">
                                        <header class="azc-card__header<?php echo empty($azcSettingsShowCardChrome) ? ' azc-card__header--page-title-only' : ''; ?>">
                        <div class="azc-card__header-text">
                            <?php if (!empty($azcSettingsShowCardChrome)): ?>
                            <h2 id="section-compliance-heading" class="azc-card__title"><?php p($l->t('Compliance and working time rules')); ?></h2>
                            <p class="azc-card__lead"><?php p($l->t('Define how ArbeitszeitCheck validates bookings, handles break edge cases, and enforces legal limits.')); ?></p>
                            <?php else: ?>
                            <h2 id="section-compliance-heading" class="azc-card__title visually-hidden"><?php p($l->t('Compliance and working time rules')); ?></h2>
                            <?php endif; ?>
                        </div>
                    </header>
                    <div class="azc-card__body">
                    <h3 class="admin-settings-subsection__title"><?php p($l->t('Compliance checks')); ?></h3>
                <div class="form-group">
                    <label class="form-label"><?php p($l->t('Configured timezone')); ?></label>
                    <p class="form-help">
                        <strong><?php p(\OCP\Server::get(\OCP\IConfig::class)->getAppValue('arbeitszeitcheck', 'app_timezone', 'Europe/Berlin')); ?></strong>
                        — <?php p($l->t('All clock-in/out timestamps and exports use this timezone and should match the server PHP timezone setting.')); ?>
                    </p>
                </div>
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
                        <?php p($l->t('The system will automatically check if working hours follow the configured country labour law (ArbZG, AZG/ARG or ArG). For example, it will warn when required breaks are missing.')); ?>
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
                <h3 class="admin-settings-subsection__title" id="azc-break-policy-heading"><?php p($l->t('Central break rules')); ?></h3>
                <p class="form-help form-help--block">
                    <?php p($l->t('Define organisation-wide break rules here. Statutory minimum breaks always apply — these rules can only tighten them, never weaken them.')); ?>
                </p>
                <?php
                $bp = is_array($settings['breakPolicy'] ?? null) ? $settings['breakPolicy'] : [];
                $bpPolicy = is_array($bp['policy'] ?? null) ? $bp['policy'] : [];
                $bpWarnings = is_array($bp['warnings'] ?? null) ? $bp['warnings'] : [];
                $bpTz = (string)($bp['storageTimezone'] ?? 'Europe/Berlin');
                ?>
                <?php if (!empty($bp['malformed'])): ?>
                <div class="azc-callout azc-callout--warning" role="alert">
                    <div class="azc-callout__body">
                        <p class="azc-callout__text"><?php p($l->t('The stored break policy could not be read completely and invalid parts were ignored. Save this form to rewrite a clean configuration.')); ?></p>
                    </div>
                </div>
                <?php endif; ?>
                <div id="azc-break-policy-editor" data-storage-tz="<?php p($bpTz); ?>">
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox" id="breakPolicyEnforced" name="breakPolicyEnforced"
                                <?php echo !empty($bpPolicy['enforced']) ? 'checked' : ''; ?>
                                aria-describedby="breakPolicyEnforced-help">
                            <label for="breakPolicyEnforced" class="form-label">
                                <?php p($l->t('Enforce central break rules')); ?>
                            </label>
                        </div>
                        <p id="breakPolicyEnforced-help" class="form-help">
                            <?php p($l->t('When enabled, employees cannot turn off automatic break calculation and the rules below apply to everyone.')); ?>
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="breakPolicyMinBreakMinutes" class="form-label"><?php p($l->t('Minimum countable break (minutes)')); ?></label>
                        <input type="number"
                            class="form-input"
                            id="breakPolicyMinBreakMinutes"
                            data-bp-field="min_break_minutes"
                            min="1"
                            max="480"
                            step="1"
                            value="<?php p(isset($bpPolicy['min_break_minutes']) && $bpPolicy['min_break_minutes'] !== null ? (string)$bpPolicy['min_break_minutes'] : ''); ?>"
                            placeholder="<?php p($l->t('Statutory default')); ?>"
                            aria-describedby="breakPolicyMinBreakMinutes-help">
                        <p id="breakPolicyMinBreakMinutes-help" class="form-help">
                            <?php p($l->t('Only breaks at least this long count towards the required break. Leave empty to keep the statutory minimum (e.g. 15 min in Germany).')); ?>
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="breakPolicyPaidCapMinutes" class="form-label"><?php p($l->t('Default paid break time per day (minutes)')); ?></label>
                        <input type="number"
                            class="form-input"
                            id="breakPolicyPaidCapMinutes"
                            data-bp-field="paid_break_cap_minutes"
                            min="0"
                            max="480"
                            step="1"
                            value="<?php p(isset($bpPolicy['paid_break_cap_minutes']) && $bpPolicy['paid_break_cap_minutes'] !== null ? (string)$bpPolicy['paid_break_cap_minutes'] : ''); ?>"
                            placeholder="<?php p($l->t('No organisation default')); ?>"
                            aria-describedby="breakPolicyPaidCapMinutes-help">
                        <p id="breakPolicyPaidCapMinutes-help" class="form-help">
                            <?php p($l->t('Only applies to working time models that count breaks as working time (e.g. TVöD §6 (1) Wechselschicht) and do not set their own cap. Leave empty to credit all recorded breaks; a number caps the paid amount per day. Changing it recalculates existing entries in the background.')); ?>
                        </p>
                    </div>

                    <fieldset class="form-fieldset azc-policy-rows" data-bp-rows="tiers" data-bp-max="8">
                        <legend class="form-legend"><?php p($l->t('Required break by daily working time')); ?></legend>
                        <p class="form-help form-help--block">
                            <?php p($l->t('Add rules such as “more than 6 hours → at least 45 minutes”. Stricter rules win over statutory ones; weaker ones are ignored.')); ?>
                        </p>
                        <div class="azc-policy-rows__list" data-bp-list="tiers"></div>
                        <p class="azc-policy-rows__empty form-help" data-bp-empty="tiers"><?php p($l->t('No organisation rules — only the statutory minimum applies.')); ?></p>
                        <button type="button" class="azc-btn azc-btn--secondary azc-btn--small" data-bp-add="tiers">
                            <?php p($l->t('Add break rule')); ?>
                        </button>
                    </fieldset>

                    <fieldset class="form-fieldset azc-policy-rows" data-bp-rows="windows" data-bp-max="4">
                        <legend class="form-legend"><?php p($l->t('Break windows')); ?></legend>
                        <p class="form-help form-help--block">
                            <?php p($l->t('Optional time windows for breaks, in timezone %s.', [$bpTz])); ?>
                            <?php p($l->t('“Placement” puts automatic breaks inside the window. “Required” means the break must overlap the window by the given minutes.')); ?>
                        </p>
                        <div class="azc-policy-rows__list" data-bp-list="windows"></div>
                        <p class="azc-policy-rows__empty form-help" data-bp-empty="windows"><?php p($l->t('No break windows configured.')); ?></p>
                        <button type="button" class="azc-btn azc-btn--secondary azc-btn--small" data-bp-add="windows">
                            <?php p($l->t('Add break window')); ?>
                        </button>
                    </fieldset>

                    <fieldset class="form-fieldset azc-policy-rows" data-bp-rows="exemptions" data-bp-max="8">
                        <legend class="form-legend"><?php p($l->t('Days without required presence')); ?></legend>
                        <p class="form-help form-help--block">
                            <?php p($l->t('Date ranges (e.g. school holidays) in which binding presence rules from working time models do not apply. Entered once per year; has no effect for models without presence rules.')); ?>
                        </p>
                        <div class="azc-policy-rows__list" data-bp-list="exemptions"></div>
                        <p class="azc-policy-rows__empty form-help" data-bp-empty="exemptions"><?php p($l->t('No exceptions configured.')); ?></p>
                        <button type="button" class="azc-btn azc-btn--secondary azc-btn--small" data-bp-add="exemptions">
                            <?php p($l->t('Add exception')); ?>
                        </button>
                    </fieldset>

                    <div class="azc-callout azc-callout--info azc-policy-preview" id="azc-policy-preview" aria-live="polite" hidden></div>

                    <div class="azc-callout azc-callout--warning azc-policy-confirm" id="azc-policy-confirm" role="alert" hidden>
                        <div class="azc-callout__body">
                            <p id="azc-policy-confirm-lead" class="azc-callout__text"><strong><?php p($l->t('Please confirm these break rules')); ?></strong></p>
                            <ul id="azc-policy-confirm-warnings" class="azc-policy-confirm__list"></ul>
                            <div class="form-checkbox">
                                <input type="checkbox" id="breakPolicyConfirm">
                                <label for="breakPolicyConfirm" class="form-label">
                                    <?php p($l->t('I understand these rules never exceed the statutory minimum and save them anyway.')); ?>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div id="azc-policy-editor-error" class="form-error-container" role="alert" hidden></div>
                </div>

                <template id="azc-bp-tier-row">
                    <div class="azc-policy-row">
                        <span class="azc-policy-row__text"><?php p($l->t('More than')); ?></span>
                        <input type="number" class="form-input" data-bp-key="afterHours" min="0.25" max="24" step="0.25"
                            aria-label="<?php p($l->t('Working hours threshold')); ?>">
                        <span class="azc-policy-row__text"><?php p($l->t('hours → at least')); ?></span>
                        <input type="number" class="form-input" data-bp-key="breakMinutes" min="1" max="480" step="1"
                            aria-label="<?php p($l->t('Break minutes')); ?>">
                        <span class="azc-policy-row__text"><?php p($l->t('minutes break')); ?></span>
                        <button type="button" class="azc-btn azc-btn--danger azc-btn--small azc-policy-row__remove" data-bp-remove
                            aria-label="<?php p($l->t('Remove this rule')); ?>">&times;</button>
                    </div>
                </template>
                <template id="azc-bp-window-row">
                    <div class="azc-policy-row">
                        <input type="time" class="form-input" data-bp-key="start"
                            aria-label="<?php p($l->t('Window start')); ?>">
                        <span class="azc-policy-row__text">–</span>
                        <input type="time" class="form-input" data-bp-key="end"
                            aria-label="<?php p($l->t('Window end')); ?>">
                        <input type="number" class="form-input" data-bp-key="minutes" min="1" max="480" step="1"
                            placeholder="<?php p($l->t('Minutes')); ?>"
                            aria-label="<?php p($l->t('Required minutes in window')); ?>">
                        <select class="form-input" data-bp-key="mode" aria-label="<?php p($l->t('Window mode')); ?>">
                            <option value="placement"><?php p($l->t('Placement')); ?></option>
                            <option value="required"><?php p($l->t('Required')); ?></option>
                        </select>
                        <button type="button" class="azc-btn azc-btn--danger azc-btn--small azc-policy-row__remove" data-bp-remove
                            aria-label="<?php p($l->t('Remove this window')); ?>">&times;</button>
                    </div>
                </template>
                <template id="azc-bp-exemption-row">
                    <div class="azc-policy-row">
                        <input type="date" class="form-input" data-bp-key="start"
                            aria-label="<?php p($l->t('Exception start date')); ?>">
                        <span class="azc-policy-row__text">–</span>
                        <input type="date" class="form-input" data-bp-key="end"
                            aria-label="<?php p($l->t('Exception end date')); ?>">
                        <button type="button" class="azc-btn azc-btn--danger azc-btn--small azc-policy-row__remove" data-bp-remove
                            aria-label="<?php p($l->t('Remove this exception')); ?>">&times;</button>
                    </div>
                </template>
                <script type="application/json" id="azc-break-policy-data"><?php
                    echo json_encode($bpPolicy, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
                ?></script>

                <h3 class="admin-settings-subsection__title"><?php p($l->t('Absence workflow rules')); ?></h3>
                <p class="form-help form-help--block">
                    <?php p($l->t('Define for which absence types a substitute must be chosen before a request can be submitted.')); ?>
                </p>
                <fieldset class="form-fieldset" aria-labelledby="require-substitute-legend">
                    <legend id="require-substitute-legend" class="form-legend"><?php p($l->t('Absences: Substitute required')); ?></legend>
                    <p class="form-help form-help--block">
                        <?php p($l->t('For the selected absence types, a substitute must be designated.')); ?>
                    </p>
                    <?php
                    $requireTypes = $settings['requireSubstituteTypes'] ?? [];
                    $absenceTypesForSubstitute = \OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog::labels($l);
                    foreach ($absenceTypesForSubstitute as $typeKey => $typeLabel):
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
                <h3 class="admin-settings-subsection__title"><?php p($l->t('Break fallback behavior')); ?></h3>
                <p class="form-help form-help--block">
                    <?php p($l->t('Use these settings to prevent users from staying in an open break state for many hours by accident.')); ?>
                </p>
                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" id="breakAutoFallbackEnabled" name="breakAutoFallbackEnabled"
                            <?php echo ($settings['breakAutoFallbackEnabled'] ?? true) ? 'checked' : ''; ?>
                            aria-describedby="breakAutoFallbackEnabled-help breakAutoFallbackMinutes-help">
                        <label for="breakAutoFallbackEnabled" class="form-label">
                            <?php p($l->t('Automatic fallback for very long breaks')); ?>
                        </label>
                    </div>
                    <p id="breakAutoFallbackEnabled-help" class="form-help">
                        <?php p($l->t('If a break is left open for too long, the system automatically clocks out to prevent permanent pause status.')); ?>
                    </p>
                </div>
                <div class="form-group">
                    <label for="breakAutoFallbackMinutes" class="form-label"><?php p($l->t('Auto clock-out after break (minutes)')); ?></label>
                    <input type="number"
                        class="form-input"
                        id="breakAutoFallbackMinutes"
                        name="breakAutoFallbackMinutes"
                        min="15"
                        max="720"
                        step="1"
                        value="<?php p((string)($settings['breakAutoFallbackMinutes'] ?? 180)); ?>"
                        aria-describedby="breakAutoFallbackMinutes-help">
                    <p id="breakAutoFallbackMinutes-help" class="form-help">
                        <?php p($l->t('Recommended: 120 to 240 minutes. After this threshold, an open break is automatically finalized by clocking out.')); ?>
                    </p>
                </div>
                <div class="form-row form-row--inline" role="group" aria-labelledby="breakAutoFallbackEnabled">
                    <div class="form-group">
                        <label for="breakAutoFallbackFlexWindowStart" class="form-label"><?php p($l->t('Flex policy quiet window start hour')); ?></label>
                        <input type="number"
                            class="form-input"
                            id="breakAutoFallbackFlexWindowStart"
                            name="breakAutoFallbackFlexWindowStart"
                            min="0"
                            max="23"
                            step="1"
                            value="<?php p((string)($settings['breakAutoFallbackFlexWindowStart'] ?? 11)); ?>">
                    </div>
                    <div class="form-group">
                        <label for="breakAutoFallbackFlexWindowEnd" class="form-label"><?php p($l->t('Flex policy quiet window end hour')); ?></label>
                        <input type="number"
                            class="form-input"
                            id="breakAutoFallbackFlexWindowEnd"
                            name="breakAutoFallbackFlexWindowEnd"
                            min="1"
                            max="24"
                            step="1"
                            value="<?php p((string)($settings['breakAutoFallbackFlexWindowEnd'] ?? 16)); ?>">
                    </div>
                </div>
                <p class="form-help form-help--note">
                    <?php p($l->t('For non-shift models (flex policy), automatic clock-out is suppressed inside this daytime window. Shift work remains strict.')); ?>
                </p>
                </div><!-- /.azc-card__body -->
                </section>

