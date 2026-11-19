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
                <section class="azc-card admin-settings-section" aria-labelledby="section-time-capture-heading">
                                        <header class="azc-card__header<?php echo empty($azcSettingsShowCardChrome) ? ' azc-card__header--page-title-only' : ''; ?>">
                        <div class="azc-card__header-text">
                            <?php if (!empty($azcSettingsShowCardChrome)): ?>
                            <h2 id="section-time-capture-heading" class="azc-card__title"><?php p($l->t('Time recording methods')); ?></h2>
                            <?php else: ?>
                            <h2 id="section-time-capture-heading" class="azc-card__title visually-hidden"><?php p($l->t('Time recording methods')); ?></h2>
                            <?php endif; ?>
                        </div>
                    </header>
                    <div class="azc-card__body">
                    <p id="section-time-capture-intro" class="<?php echo !empty($azcSettingsShowCardChrome) ? 'azc-card__lead' : 'visually-hidden'; ?>">
                        <?php p($l->t('Choose how the organisation records working time. At least one method must stay on.')); ?>
                    </p>
                    <?php
                    $orgClockStampingEnabled = (bool)($settings['clockStampingEnabled'] ?? true);
                    $orgManualTimeEntryEnabled = (bool)($settings['manualTimeEntryEnabled'] ?? true);
                    $employeesUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.admin.users');
                    if ($orgClockStampingEnabled && $orgManualTimeEntryEnabled) {
                        $orgCaptureStatusBadge = $l->t('Both methods active');
                        $orgCaptureStatusText = $l->t('Employees can clock in/out on the dashboard and add manual time entries.');
                        $orgCaptureStatusVariant = 'info';
                    } elseif ($orgClockStampingEnabled) {
                        $orgCaptureStatusBadge = $l->t('Stamping only');
                        $orgCaptureStatusText = $l->t('Employees must use the punch clock. Manual time entries are hidden for everyone.');
                        $orgCaptureStatusVariant = 'success';
                    } else {
                        $orgCaptureStatusBadge = $l->t('Manual entries only');
                        $orgCaptureStatusText = $l->t('Employees add completed work blocks by date and time. The punch clock is hidden for everyone.');
                        $orgCaptureStatusVariant = 'warning';
                    }
                    ?>
                    <div class="admin-time-capture__status" id="admin-time-capture-status" role="status" aria-live="polite" aria-atomic="true">
                        <span class="azc-badge azc-badge--<?php p($orgCaptureStatusVariant); ?>" id="admin-time-capture-status-badge"><?php p($orgCaptureStatusBadge); ?></span>
                        <p id="admin-time-capture-status-text" class="admin-time-capture__status-text"><?php p($orgCaptureStatusText); ?></p>
                    </div>
                    <fieldset class="form-fieldset admin-time-capture-fieldset" aria-labelledby="section-time-capture-heading" aria-describedby="section-time-capture-intro admin-time-capture-status admin-time-capture-error admin-time-capture-employees-note">
                        <legend class="visually-hidden"><?php p($l->t('Organisation-wide time recording methods')); ?></legend>
                        <div class="admin-time-capture__grid" role="group">
                            <label class="admin-time-capture__card">
                                <input type="checkbox"
                                       id="clockStampingEnabled"
                                       name="clockStampingEnabled"
                                       value="1"
                                       class="admin-time-capture__checkbox"
                                       <?php echo $orgClockStampingEnabled ? 'checked' : ''; ?>
                                       aria-describedby="clockStampingEnabled-help">
                                <span class="admin-time-capture__card-body">
                                    <span class="admin-time-capture__card-title"><?php p($l->t('Clock in / out (stamping)')); ?></span>
                                    <span class="admin-time-capture__card-text"><?php p($l->t('Live punch clock on the dashboard and in the mobile app.')); ?></span>
                                </span>
                            </label>
                            <label class="admin-time-capture__card">
                                <input type="checkbox"
                                       id="manualTimeEntryEnabled"
                                       name="manualTimeEntryEnabled"
                                       value="1"
                                       class="admin-time-capture__checkbox"
                                       <?php echo $orgManualTimeEntryEnabled ? 'checked' : ''; ?>
                                       aria-describedby="manualTimeEntryEnabled-help">
                                <span class="admin-time-capture__card-body">
                                    <span class="admin-time-capture__card-title"><?php p($l->t('Manual time entries')); ?></span>
                                    <span class="admin-time-capture__card-text"><?php p($l->t('Add completed work blocks by date and time in the web app.')); ?></span>
                                </span>
                            </label>
                        </div>
                        <p id="clockStampingEnabled-help" class="form-help">
                            <?php p($l->t('Turn off stamping if your organisation records hours only via manual entries — the punch clock disappears for all employees.')); ?>
                        </p>
                        <p id="manualTimeEntryEnabled-help" class="form-help">
                            <?php p($l->t('Turn off manual entries if everyone must use the punch clock only.')); ?>
                        </p>
                        <p id="admin-time-capture-error" class="form-error admin-time-capture__error" role="alert" hidden></p>
                        <p id="admin-time-capture-employees-note" class="form-help form-help--note">
                            <?php
                            print_unescaped($l->t(
                                'Need different rules for one person? Open <a href="%s">Employees</a>, edit the person, and adjust the Time recording section.',
                                [$employeesUrl]
                            ));
                            ?>
                        </p>
                    </fieldset>
                    <?php
                    $offlineStampMaxPastHours = (int)($settings['offlineStampMaxPastHours'] ?? \OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy::DEFAULT_PAST_HOURS);
                    $offlineStampMaxPastHours = \OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy::normalizePastHours($offlineStampMaxPastHours);
                    ?>
                    <fieldset class="form-fieldset" aria-labelledby="offline-stamp-skew-heading">
                        <legend id="offline-stamp-skew-heading" class="form-legend"><?php p($l->t('Offline stamp sync')); ?></legend>
                        <p id="offlineStampMaxPastHours-help" class="form-help">
                            <?php p($l->t('How old a stamp may be when the phone or kiosk uploads it after an outage. Longer windows help over weekends; they also leave more room for wrong device clocks. 24 hours is the safe default.')); ?>
                        </p>
                        <div class="form-group">
                            <label for="offlineStampMaxPastHours"><?php p($l->t('Accept offline stamps up to')); ?></label>
                            <select id="offlineStampMaxPastHours"
                                    name="offlineStampMaxPastHours"
                                    class="form-select"
                                    aria-describedby="offlineStampMaxPastHours-help">
                                <?php foreach (\OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy::PRESET_PAST_HOURS as $presetHours): ?>
                                <option value="<?php p((string)$presetHours); ?>"<?php echo $offlineStampMaxPastHours === $presetHours ? ' selected' : ''; ?>>
                                    <?php
                                    if ($presetHours === 24) {
                                        p($l->t('24 hours (default)'));
                                    } elseif ($presetHours === 48) {
                                        p($l->t('48 hours'));
                                    } else {
                                        p($l->t('72 hours'));
                                    }
                                    ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </fieldset>
                    <?php
                    $autoClockOutEnabled = !empty($settings['autoClockOutEnabled']);
                    $autoClockOutHour = (int)($settings['autoClockOutHour'] ?? \OCA\ArbeitszeitCheck\Constants::AUTO_CLOCK_OUT_DEFAULT_HOUR);
                    $autoClockOutHour = max(0, min(23, $autoClockOutHour));
                    $autoClockOutScope = (string)($settings['autoClockOutScope'] ?? \OCA\ArbeitszeitCheck\Constants::AUTO_CLOCK_OUT_SCOPE_TODAY);
                    if (!in_array($autoClockOutScope, [\OCA\ArbeitszeitCheck\Constants::AUTO_CLOCK_OUT_SCOPE_TODAY, \OCA\ArbeitszeitCheck\Constants::AUTO_CLOCK_OUT_SCOPE_ALL_OPEN], true)) {
                        $autoClockOutScope = \OCA\ArbeitszeitCheck\Constants::AUTO_CLOCK_OUT_SCOPE_TODAY;
                    }
                    ?>
                    <fieldset class="form-fieldset" aria-labelledby="auto-clock-out-heading" aria-describedby="autoClockOutEnabled-help">
                        <legend id="auto-clock-out-heading" class="form-legend"><?php p($l->t('Automatic clock-out')); ?></legend>
                        <div class="form-checkbox">
                            <input type="hidden" name="autoClockOutEnabled" value="0">
                            <input type="checkbox"
                                   id="autoClockOutEnabled"
                                   name="autoClockOutEnabled"
                                   value="1"
                                   <?php echo $autoClockOutEnabled ? 'checked' : ''; ?>
                                   aria-describedby="autoClockOutEnabled-help">
                            <label for="autoClockOutEnabled" class="form-label">
                                <?php p($l->t('Clock out automatically at the scheduled end of day')); ?>
                            </label>
                        </div>
                        <p id="autoClockOutEnabled-help" class="form-help">
                            <?php p($l->t('Closes sessions that are still open at the planned end of day and marks them as automatic. The employee is always notified and asked to review the entry. Off by default.')); ?>
                        </p>
                        <div class="auto-clock-out__controls<?php echo $autoClockOutEnabled ? '' : ' auto-clock-out__controls--disabled'; ?>" id="auto-clock-out-controls">
                            <div class="form-group">
                                <label for="autoClockOutHour"><?php p($l->t('Close open sessions at')); ?></label>
                                <input type="number"
                                       id="autoClockOutHour"
                                       name="autoClockOutHour"
                                       class="form-input"
                                       min="0" max="23" step="1"
                                       value="<?php p((string)$autoClockOutHour); ?>"
                                       <?php echo $autoClockOutEnabled ? '' : 'disabled'; ?>
                                       aria-describedby="autoClockOutHour-help autoClockOutHour-error"
                                       aria-invalid="false">
                                <p id="autoClockOutHour-help" class="form-help">
                                    <?php p($l->t('Hour of day (0–23) in the organisation timezone at which the check runs. A session is only closed when its planned end has already passed.')); ?>
                                </p>
                                <p id="autoClockOutHour-error" class="azc-field-error" hidden></p>
                            </div>
                            <div class="form-group" role="radiogroup" aria-labelledby="autoClockOutScope-label">
                                <span id="autoClockOutScope-label" class="form-label"><?php p($l->t('Which open sessions are covered')); ?></span>
                                <div class="form-checkbox">
                                    <input type="radio"
                                           id="autoClockOutScopeToday"
                                           name="autoClockOutScope"
                                           value="<?php p(\OCA\ArbeitszeitCheck\Constants::AUTO_CLOCK_OUT_SCOPE_TODAY); ?>"
                                           <?php echo $autoClockOutScope === \OCA\ArbeitszeitCheck\Constants::AUTO_CLOCK_OUT_SCOPE_TODAY ? 'checked' : ''; ?>
                                           <?php echo $autoClockOutEnabled ? '' : 'disabled'; ?>>
                                    <label for="autoClockOutScopeToday" class="form-label">
                                        <?php p($l->t('Only sessions started today')); ?>
                                    </label>
                                </div>
                                <div class="form-checkbox">
                                    <input type="radio"
                                           id="autoClockOutScopeAllOpen"
                                           name="autoClockOutScope"
                                           value="<?php p(\OCA\ArbeitszeitCheck\Constants::AUTO_CLOCK_OUT_SCOPE_ALL_OPEN); ?>"
                                           <?php echo $autoClockOutScope === \OCA\ArbeitszeitCheck\Constants::AUTO_CLOCK_OUT_SCOPE_ALL_OPEN ? 'checked' : ''; ?>
                                           <?php echo $autoClockOutEnabled ? '' : 'disabled'; ?>
                                           aria-describedby="autoClockOutScope-help">
                                    <label for="autoClockOutScopeAllOpen" class="form-label">
                                        <?php p($l->t('All open sessions, including older days')); ?>
                                    </label>
                                </div>
                                <p id="autoClockOutScope-help" class="form-help">
                                    <?php p($l->t('Older open sessions are closed at the planned end of the day they started on.')); ?>
                                </p>
                                <p id="autoClockOutScope-error" class="azc-field-error" hidden></p>
                            </div>
                        </div>
                    </fieldset>
                    </div><!-- /.azc-card__body -->
                </section>

