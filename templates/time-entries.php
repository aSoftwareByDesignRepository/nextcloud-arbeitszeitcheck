<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Service\IconCatalog;
use OCA\ArbeitszeitCheck\Support\BadgeVariant;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCA\ArbeitszeitCheck\Support\TimePickerMinutes;
/**
 * Time Entries template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */

// Assets registered by PageController / TimeEntryController

$entries = $_['entries'] ?? [];
$deletionEligibility = $_['deletionEligibility'] ?? [];
$editWindowDays = \OCA\ArbeitszeitCheck\Constants::EDIT_WINDOW_DAYS;
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$stats = $_['stats'] ?? [];
$monthClosureEnabled = !empty($_['monthClosureEnabled']);
$mode = $_['mode'] ?? 'list'; // 'list', 'create', 'edit'
$entry = $_['entry'] ?? null;
$error = $_['error'] ?? null;
$projectCheckEnabled = !empty($_['projectCheckEnabled']);
$projectCheckAvailable = !empty($_['projectCheckAvailable']);
$projectCheckAdminLinkingEnabled = array_key_exists('projectCheckAdminLinkingEnabled', $_)
	? !empty($_['projectCheckAdminLinkingEnabled'])
	: $projectCheckEnabled;
$projectCheckProjects = is_array($_['projectCheckProjects'] ?? null) ? $_['projectCheckProjects'] : [];
$selectedProjectCheckId = $entry ? (string)($entry->getProjectCheckProjectId() ?? '') : '';
$appTimezone = \OCP\Server::get(\OCP\IConfig::class)->getAppValue('arbeitszeitcheck', 'app_timezone', 'Europe/Berlin');
$timeCapture = is_array($_['timeCapture'] ?? null) ? $_['timeCapture'] : [];
$manualTimeEntryEnabled = (bool)($timeCapture['manualTimeEntryEnabled'] ?? true);
require __DIR__ . '/common/user-display-timezone.php';
/** @var \DateTimeZone $arbeitszeitCheckUserDisplayTz */
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack time-entries-page">
        <?php if ($mode === 'list' && $error): ?>
            <div class="azc-callout azc-callout--danger time-entries-page__list-error" role="alert">
                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('alert-triangle', 'azc-callout__icon-svg')); ?></span>
                <p class="azc-callout__text"><?php p($error); ?></p>
            </div>
        <?php endif; ?>
        <?php if ($mode === 'list'): ?>
            <?php
            $tzMismatchList = $appTimezone !== $arbeitszeitCheckUserDisplayTz->getName();
            ?>
            <?php if ($tzMismatchList): ?>
            <details class="azc-callout azc-callout--info arbeitszeit-check-tz-context azc-collapsed-callout">
                <summary class="azc-callout__title"><?php p($l->t('Time zones')); ?></summary>
                <div class="arbeitszeit-check-tz-context__body">
                    <p class="azc-callout__text arbeitszeit-check-tz-context__text" id="arbeitszeit-tz-context-desc">
                        <?php p($l->t('Start and end times are shown in your personal timezone (%2$s). Values are stored using the organization timezone (%1$s) so daylight saving time is handled consistently.', [$appTimezone, $arbeitszeitCheckUserDisplayTz->getName()])); ?>
                    </p>
                    <div class="arbeitszeit-check-tz-context__badges" role="list" aria-label="<?php p($l->t('Time zone summary')); ?>">
                        <div class="timezone-badge timezone-badge--inline" role="listitem" title="<?php p($l->t('Organization reference timezone for stored work times')); ?>">
                            <span class="timezone-badge__label"><?php p($l->t('Organization')); ?>: <?php p($appTimezone); ?></span>
                        </div>
                        <div class="timezone-badge timezone-badge--inline" role="listitem" title="<?php p($l->t('Your Nextcloud account timezone for display')); ?>">
                            <span class="timezone-badge__label"><?php p($l->t('Your display')); ?>: <?php p($arbeitszeitCheckUserDisplayTz->getName()); ?></span>
                        </div>
                    </div>
                </div>
            </details>
            <?php endif; ?>

            <details class="azc-callout azc-callout--info time-entries-page__deletion-guide azc-collapsed-callout">
                <summary id="time-entries-deletion-guide-title" class="azc-callout__title time-entries-page__deletion-guide-title">
                    <?php p($l->t('Changing or removing time entries')); ?>
                </summary>
                <p class="azc-callout__text">
                    <?php p($l->t('For clocked entries less than %d days old: use Edit to change times, or Delete to remove the entry entirely. Older clocked entries: use Request correction. Entries in a finalized month or approved by your manager cannot be deleted here.', [$editWindowDays])); ?>
                </p>
            </details>

            <div class="header-actions azc-page-actions-source">
                <?php if ($manualTimeEntryEnabled): ?>
                <button id="btn-add-entry"
                    class="azc-btn azc-btn--primary"
                    type="button"
                    aria-label="<?php p($l->t('Add a new time entry to record when you worked')); ?>"
                    title="<?php p($l->t('Click to add a new time entry. You can record when you started and finished work, and any breaks you took.')); ?>">
                    <?php p($l->t('Add Time Entry')); ?>
                </button>
                <?php endif; ?>
                <button id="btn-filter"
                    class="azc-btn azc-btn--secondary"
                    type="button"
                    aria-label="<?php p($l->t('Filter time entries by date or status')); ?>"
                    title="<?php p($l->t('Click to show options for filtering your time entries. You can filter by date range or status.')); ?>">
                    <?php p($l->t('Filter')); ?>
                </button>
                <button id="btn-export"
                    class="azc-btn azc-btn--secondary"
                    type="button"
                    aria-label="<?php p($l->t('Download your time entries as a CSV file')); ?>"
                    title="<?php p($l->t('Downloads the last 30 days as CSV (long layout): each row shows start and end times; overnight shifts appear as two rows when your administrator enables midnight split. Use Reports for date range and optional wide layout.')); ?>">
                    <?php p($l->t('Download CSV')); ?>
                </button>
            </div>
            <?php if ($mode === 'list' && !$manualTimeEntryEnabled): ?>
            <div class="azc-callout azc-callout--info time-entries-page__capture-disabled" role="status">
                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('info', 'azc-callout__icon-svg')); ?></span>
                <div class="azc-callout__body">
                    <p class="azc-callout__title"><?php p($l->t('Manual time entries are turned off for you')); ?></p>
                    <p class="azc-callout__text"><?php p($l->t('Your administrator disabled adding hours by hand. You can still view entries and request corrections if something is wrong.')); ?></p>
                </div>
            </div>
            <?php endif; ?>
            <p class="azc-callout azc-callout--neutral time-entries-page__export-hint" id="time-entries-export-hint">
                <span class="azc-callout__text">
                    <?php p($l->t('Quick CSV uses the long layout (columns include start and end times). Overnight entries can appear as two rows after midnight if the administrator enabled split in export settings.')); ?>
                    <?php p($l->t('All exported timestamps use timezone: %s (MEZ/MESZ).', [$appTimezone])); ?>
                </span>
            </p>
        <?php endif; ?>

            <?php if ($mode === 'list' && !empty($stats)): ?>
                <section class="azc-card time-entries-page__stats" aria-label="<?php p($l->t('Time entry statistics')); ?>">
                    <div class="azc-card__body">
                        <div class="stats-grid time-entries-page__stats-grid">
                            <div class="stat-card">
                                <span class="stat-label"><?php p($l->t('Total Entries')); ?></span>
                                <span class="stat-value"><?php p($stats['total_time_entries'] ?? 0); ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-label"><?php p($l->t('This Month')); ?></span>
                                <span class="stat-value"><?php p($stats['entries_this_month'] ?? 0); ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-label"><?php p($l->t('Total Hours')); ?></span>
                                <span class="stat-value"><?php p(round($stats['total_hours'] ?? 0, 2)); ?> h</span>
                            </div>
                        </div>
                    </div>
                </section>
                <?php if ($monthClosureEnabled): ?>
                <section class="azc-card month-closure-section" aria-labelledby="month-closure-heading">
                    <header class="azc-card__header">
                        <div class="azc-card__header-text">
                            <h2 id="month-closure-heading" class="azc-card__title"><?php p($l->t('Monthly record (revision-safe)')); ?></h2>
                            <p class="azc-card__lead month-closure-lead" id="month-closure-intro"><?php p($l->t('Finalize a full calendar month after it has ended, when your times are complete. The app stores a cryptographic snapshot and a PDF you can archive.')); ?></p>
                        </div>
                    </header>
                    <div class="azc-card__body">
                        <div class="month-closure-layout">
                            <div class="month-closure-block month-closure-block--period">
                                <div class="month-closure-field">
                                    <label for="month-closure-period" class="month-closure-field__label" id="month-closure-period-label"><?php p($l->t('Calendar month to finalize')); ?></label>
                                    <p id="month-closure-field-hint" class="form-help month-closure-field__hint"><?php p($l->t('The seal applies to one full calendar month. Only calendar months that have ended and contain at least one time entry are listed.')); ?></p>
                                    <div class="month-closure-toolbar">
                                        <select id="month-closure-period"
                                            class="form-input form-input--inline month-closure-period-select"
                                            aria-busy="true"
                                            aria-labelledby="month-closure-period-label"
                                            aria-describedby="month-closure-intro month-closure-field-hint month-closure-deadline month-closure-blocked"></select>
                                        <span id="month-closure-status" class="month-closure-badge" role="status" aria-live="polite"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="month-closure-block month-closure-block--outcome">
                                <p id="month-closure-blocked" class="month-closure-blocked form-help" role="status" aria-live="polite" hidden></p>
                                <p id="month-closure-deadline" class="month-closure-deadline form-help form-help--note" hidden></p>
                                <div class="month-closure-actions">
                                    <button type="button"
                                        id="month-closure-finalize"
                                        class="azc-btn azc-btn--primary"
                                        disabled
                                        data-confirm-finalize="<?php p($l->t('Really finalize this month? You will not be able to change time entries afterward unless an administrator reopens the month.')); ?>"><?php p($l->t('Finalize month')); ?></button>
                                    <a id="month-closure-pdf" class="azc-btn azc-btn--secondary month-closure-pdf-link" href="#" style="display:none"><?php p($l->t('Download PDF')); ?></a>
                                </div>
                            </div>
                        </div>

                        <div id="month-closure-finalized-notice" class="month-closure-finalized-notice" hidden>
                            <?php
                            $_['message'] = $l->t('This calendar month is finalized. Contact an administrator if a correction must be made.');
                            $_['id'] = 'month-closure-lock-notice';
                            include __DIR__ . '/common/month-closure-lock.php';
                            ?>
                        </div>
                        <p id="month-closure-feedback" class="month-closure-feedback" role="status" aria-live="polite" aria-atomic="true"></p>
                    </div>
                </section>
                <?php endif; ?>
            <?php endif; ?>

        <?php if ($mode === 'create' || $mode === 'edit'): ?>
            <?php
            $manualTimeEntriesRequireApproval = !empty($_['manualTimeEntriesRequireApproval']);
            $timeEntryChangesRequireApproval = !empty($_['timeEntryChangesRequireApproval']);
            $tzMismatchForm = $appTimezone !== $arbeitszeitCheckUserDisplayTz->getName();
            ?>
            <?php if ($tzMismatchForm): ?>
            <details class="azc-callout azc-callout--info time-entries-page__tz-callout azc-collapsed-callout">
                <summary class="azc-callout__title"><?php p($l->t('How times are stored')); ?></summary>
                <div class="azc-callout__body">
                    <p class="azc-callout__text"><?php p($l->t('You enter start and end in your personal timezone (%2$s). The app stores them in the organization timezone (%1$s).', [$appTimezone, $arbeitszeitCheckUserDisplayTz->getName()])); ?></p>
                    <div class="time-entries-page__tz-badges" role="list" aria-label="<?php p($l->t('Time zone summary')); ?>">
                        <span class="timezone-badge timezone-badge--inline" role="listitem"><?php p($l->t('Organization')); ?>: <?php p($appTimezone); ?></span>
                        <span class="timezone-badge timezone-badge--inline" role="listitem"><?php p($l->t('Your display')); ?>: <?php p($arbeitszeitCheckUserDisplayTz->getName()); ?></span>
                    </div>
                </div>
            </details>
            <?php endif; ?>
            <?php if ($mode === 'create' && $manualTimeEntriesRequireApproval): ?>
            <div class="azc-callout azc-callout--info time-entries-page__workflow-callout" role="status">
                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('user-check', 'azc-callout__icon-svg')); ?></span>
                <p class="azc-callout__text"><?php p($l->t('New manual entries need manager approval before they count toward your hours and overtime.')); ?></p>
            </div>
            <?php endif; ?>
            <?php if ($monthClosureEnabled): ?>
            <div class="azc-callout azc-callout--neutral time-entries-page__workflow-callout" role="note">
                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('lock', 'azc-callout__icon-svg')); ?></span>
                <p class="azc-callout__text"><?php p($l->t('Entries in a finalized calendar month cannot be changed unless an administrator reopens that month.')); ?></p>
            </div>
            <?php endif; ?>

            <section class="azc-card time-entries-page__form<?php echo $mode === 'create' ? ' time-entries-page__form--create' : ''; ?>"
                     aria-labelledby="<?php echo $mode === 'create' ? 'azc-page-title' : 'time-entry-form-heading'; ?>">
                <?php if ($mode === 'edit'): ?>
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="time-entry-form-heading" class="azc-card__title"><?php p($l->t('Edit time entry')); ?></h2>
                        <p id="time-entry-form-lead" class="azc-card__lead"><?php p($l->t('Change date, times, breaks, or note for this entry. Only entries you are allowed to edit appear here.')); ?></p>
                    </div>
                </header>
                <?php endif; ?>
                <div class="azc-card__body">
                <?php if ($error): ?>
                    <div class="azc-callout azc-callout--danger" role="alert">
                        <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('alert-triangle', 'azc-callout__icon-svg')); ?></span>
                        <p class="azc-callout__text"><?php p($error); ?></p>
                    </div>
                <?php endif; ?>

                <div class="time-entries-page__form-inner">
                    <form id="time-entry-form"
                          class="form time-entry-request-form"
                          method="POST"
                          action="#"
                          novalidate
                          aria-labelledby="<?php echo $mode === 'create' ? 'azc-page-title' : 'time-entry-form-heading'; ?>"
                          aria-describedby="time-entry-form-required-note time-entry-form-lead">
                        <?php if ($mode === 'create'): ?>
                        <div class="sr-only">
                            <h2 id="time-entry-form-heading"><?php p($l->t('Record working time')); ?></h2>
                            <p id="time-entry-form-lead"><?php p($l->t('Date, working hours, optional breaks, and a short note. Compliance is checked while you type.')); ?></p>
                        </div>
                        <?php endif; ?>
                        <p id="time-entry-form-required-note" class="form-required-note time-entry-form__required-note">
                            <span class="form-required" aria-hidden="true">*</span>
                            <?php p($l->t('Required field')); ?>
                        </p>

                        <?php if ($mode === 'edit' && $entry && $entry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PAUSED && $entry->getEndTime() === null): ?>
                            <div class="azc-callout azc-callout--warning" role="status">
                                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('alert-triangle', 'azc-callout__icon-svg')); ?></span>
                                <p class="azc-callout__text"><?php p($l->t('This session was left unfinished. Set the correct end time and save — the entry will then be recorded as completed.')); ?></p>
                            </div>
                        <?php endif; ?>

                        <div class="time-entry-form__primary">
                        <fieldset class="time-entry-form-fieldset">
                            <legend class="time-entry-form-fieldset__legend"><?php p($l->t('Date and time')); ?></legend>
                            <div class="time-entry-form__date">
                                <div class="form-group">
                                    <label for="entry-date" id="entry-date-label" class="form-label">
                                        <span class="form-label-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('calendar', 'form-label-icon__svg')); ?></span>
                                        <?php p($l->t('Date')); ?>
                                        <span class="form-required" aria-hidden="true">*</span>
                                    </label>
                                    <?php
                                    $entryDatePickerMin = (new \DateTimeImmutable('-1 year'))->format('d.m.Y');
                                    $entryDatePickerMax = (new \DateTimeImmutable('tomorrow'))->format('d.m.Y');
                                    ?>
                                    <div class="form-input-wrapper form-input-wrapper--date">
                                        <input type="text"
                                            id="entry-date"
                                            name="date"
                                            class="form-input datepicker-input"
                                            pattern="\d{2}\.\d{2}\.\d{4}"
                                            placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
                                            value="<?php p($entry ? $entry->getStartTime()->format('d.m.Y') : date('d.m.Y')); ?>"
                                            data-datepicker-min="<?php p($entryDatePickerMin); ?>"
                                            data-datepicker-max="<?php p($entryDatePickerMax); ?>"
                                            inputmode="numeric"
                                            autocomplete="off"
                                            spellcheck="false"
                                            aria-labelledby="entry-date-label"
                                            aria-describedby="entry-date-help entry-date-error"
                                            aria-required="true"
                                            aria-invalid="false"
                                            required>
                                        <button type="button"
                                                id="btn-today"
                                                class="azc-btn azc-btn--sm azc-btn--secondary time-entry-form__today-btn"
                                                aria-label="<?php p($l->t('Set date to today')); ?>"
                                                title="<?php p($l->t('Click to quickly set the date to today')); ?>">
                                            <?php p($l->t('Today')); ?>
                                        </button>
                                    </div>
                                    <p id="entry-date-help" class="form-help"><?php p($l->t('Day you worked (dd.mm.yyyy). Use “Today” for the current day.')); ?></p>
                                    <div id="entry-date-error" role="alert" class="form-error-container" style="display: none;"></div>
                                </div>
                            </div>

                            <p class="time-pair-matrix__intro" id="work-time-intro"><?php p($l->t('Working hours (24-hour clock)')); ?></p>
                            <div class="time-pair-matrix" role="group" aria-labelledby="work-time-intro entry-date-label">
                                <div class="time-pair-matrix__grid time-pair-matrix__grid--header">
                                    <span class="time-pair-matrix__colhead"><?php p($l->t('Start Time')); ?></span>
                                    <span class="time-pair-matrix__colhead"><?php p($l->t('End Time')); ?></span>
                                    <span class="time-pair-matrix__colhead time-pair-matrix__colhead--action" aria-hidden="true"></span>
                                </div>
                                <div class="time-pair-matrix__grid time-pair-matrix__grid--row">
                                <div class="form-group">
                                    <label for="entry-start-time" id="entry-start-time-label" class="form-label">
                                        <span class="form-label-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('clock', 'form-label-icon__svg')); ?></span>
                                        <?php p($l->t('Start Time')); ?>
                                        <span class="form-required" aria-hidden="true">*</span>
                                    </label>
                                    <?php
                                    // Custom 24-hour time input - always shows 24h format regardless of browser locale.
                                    // Prefill in the *user's* display TZ so what they see in the form matches the
                                    // dashboard. Storage TZ conversion happens server-side on submit via
                                    // AppLocalNaiveDateTimeNormalizer, so editing across DST works correctly.
                                    if ($entry) {
                                        $startTimeForForm = (clone $entry->getStartTime())->setTimezone($arbeitszeitCheckUserDisplayTz);
                                        $startTimeValue = $startTimeForForm->format('H:i');
                                    } else {
                                        $startTimeValue = '09:00';
                                    }
                                    $startTimeParts = explode(':', $startTimeValue);
                                    $startHour = $startTimeParts[0] ?? '09';
                                    $startMinute = $startTimeParts[1] ?? '00';
                                    ?>
                                    <div class="time-input-group time-input-group--with-type" 
                                         data-time-input="entry-start-time"
                                         role="group"
                                         aria-labelledby="entry-start-time-label"
                                         aria-describedby="entry-start-time-help entry-start-time-error">
                                        <select id="entry-start-time-hour" 
                                                name="startTimeHour" 
                                                class="form-input time-hour" 
                                                required 
                                                aria-label="<?php p($l->t('Start hour')); ?>"
                                                aria-required="true">
                                            <?php for ($h = 0; $h < 24; $h++): ?>
                                                <option value="<?php p(sprintf('%02d', $h)); ?>" <?php p(sprintf('%02d', $h) === $startHour ? 'selected' : ''); ?>>
                                                    <?php p(sprintf('%02d', $h)); ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                        <span class="time-separator" aria-hidden="true">:</span>
                                        <select id="entry-start-time-minute" 
                                                name="startTimeMinute" 
                                                class="form-input time-minute" 
                                                required 
                                                aria-label="<?php p($l->t('Start minute')); ?>"
                                                aria-required="true">
                                            <?php foreach (TimePickerMinutes::options($startMinute) as $minuteOpt): ?>
                                                <option value="<?php p($minuteOpt); ?>" <?php p($minuteOpt === $startMinute ? 'selected' : ''); ?>>
                                                    <?php p($minuteOpt); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text"
                                               id="entry-start-time-type"
                                               class="form-input time-input-group__type"
                                               inputmode="numeric"
                                               autocomplete="off"
                                               maxlength="5"
                                               placeholder="<?php p($l->t('HH:MM')); ?>"
                                               value="<?php p($startTimeValue); ?>"
                                               aria-label="<?php p($l->t('Or type start time as HH:MM')); ?>">
                                        <input type="hidden" 
                                               id="entry-start-time" 
                                               name="startTime" 
                                               value="<?php p($startTimeValue); ?>" 
                                               required
                                               aria-invalid="false">
                                    </div>
                                    <p id="entry-start-time-help" class="form-help"><?php p($l->t('When you started (e.g. 09:00). Minutes use 5-minute steps — or type any time as HH:MM.')); ?></p>
                                    <div id="entry-start-time-error" role="alert" class="form-error-container" style="display: none;"></div>
                                </div>

                                <div class="form-group">
                                    <label for="entry-end-time" id="entry-end-time-label" class="form-label">
                                        <span class="form-label-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('clock', 'form-label-icon__svg')); ?></span>
                                        <?php p($l->t('End Time')); ?>
                                        <span class="form-required" aria-hidden="true">*</span>
                                    </label>
                                    <?php
                                    // Custom 24-hour time input - always shows 24h format regardless of browser locale.
                                    // As above, prefill in the user's display TZ.
                                    if ($entry && $entry->getEndTime()) {
                                        $endForForm = (clone $entry->getEndTime())->setTimezone($arbeitszeitCheckUserDisplayTz);
                                        $endTimeValue = $endForForm->format('H:i');
                                    } elseif ($entry && $entry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PAUSED && $entry->getUpdatedAt()) {
                                        // Orphan paused row: suggest the moment the session was frozen (updated_at) as end time
                                        $updatedForForm = (clone $entry->getUpdatedAt())->setTimezone($arbeitszeitCheckUserDisplayTz);
                                        $endTimeValue = $updatedForForm->format('H:i');
                                    } else {
                                        $endTimeValue = '17:00';
                                    }
                                    $endTimeParts = explode(':', $endTimeValue);
                                    $endHour = $endTimeParts[0] ?? '17';
                                    $endMinute = $endTimeParts[1] ?? '00';
                                    ?>
                                    <div class="time-input-group time-input-group--with-type" 
                                         data-time-input="entry-end-time"
                                         role="group"
                                         aria-labelledby="entry-end-time-label"
                                         aria-describedby="entry-end-time-help entry-end-time-error">
                                        <select id="entry-end-time-hour" 
                                                name="endTimeHour" 
                                                class="form-input time-hour" 
                                                required 
                                                aria-label="<?php p($l->t('End hour')); ?>"
                                                aria-required="true">
                                            <?php for ($h = 0; $h < 24; $h++): ?>
                                                <option value="<?php p(sprintf('%02d', $h)); ?>" <?php p(sprintf('%02d', $h) === $endHour ? 'selected' : ''); ?>>
                                                    <?php p(sprintf('%02d', $h)); ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                        <span class="time-separator" aria-hidden="true">:</span>
                                        <select id="entry-end-time-minute" 
                                                name="endTimeMinute" 
                                                class="form-input time-minute" 
                                                required 
                                                aria-label="<?php p($l->t('End minute')); ?>"
                                                aria-required="true">
                                            <?php foreach (TimePickerMinutes::options($endMinute) as $minuteOpt): ?>
                                                <option value="<?php p($minuteOpt); ?>" <?php p($minuteOpt === $endMinute ? 'selected' : ''); ?>>
                                                    <?php p($minuteOpt); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text"
                                               id="entry-end-time-type"
                                               class="form-input time-input-group__type"
                                               inputmode="numeric"
                                               autocomplete="off"
                                               maxlength="5"
                                               placeholder="<?php p($l->t('HH:MM')); ?>"
                                               value="<?php p($endTimeValue); ?>"
                                               aria-label="<?php p($l->t('Or type end time as HH:MM')); ?>">
                                        <input type="hidden" 
                                               id="entry-end-time" 
                                               name="endTime" 
                                               value="<?php p($endTimeValue); ?>" 
                                               required
                                               aria-invalid="false">
                                    </div>
                                    <p id="entry-end-time-help" class="form-help"><?php p($l->t('When you finished. Night shifts: use the next calendar day if end is after midnight.')); ?></p>
                                    <div id="entry-end-time-error" role="alert" class="form-error-container" style="display: none;"></div>
                                </div>
                                <div class="time-pair-matrix__action time-pair-matrix__action--spacer" aria-hidden="true"></div>
                                </div>
                            </div>
                        </fieldset>
                        </div><!-- /.time-entry-form__primary -->

                        <!-- Real-time Summary — after primary fields so entry starts above the fold -->
                        <div id="time-summary" 
                             role="status" 
                             aria-live="polite" 
                             aria-atomic="true"
                             class="time-summary-card time-summary-card--secondary">
                            <h4 class="time-summary-title"><?php p($l->t('Day Summary')); ?></h4>
                            <div class="summary-row">
                                <span class="summary-label"><?php p($l->t('Working Hours')); ?>:</span>
                                <span class="summary-amount">
                                    <span id="summary-working-hours" class="summary-value">0.0</span><span class="summary-unit"> h</span>
                                </span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><?php p($l->t('Break Time')); ?>:</span>
                                <span class="summary-amount">
                                    <span id="summary-break-time" class="summary-value">0.0</span><span class="summary-unit"> h</span>
                                </span>
                            </div>
                            <div id="compliance-status" class="compliance-status" role="status" aria-live="polite"></div>
                        </div>

                        <fieldset class="time-entry-form-fieldset time-entry-form-fieldset--breaks">
                            <legend class="time-entry-form-fieldset__legend"><?php p($l->t('Breaks')); ?> <span class="form-optional"><?php p($l->t('(optional)')); ?></span></legend>
                            <?php
                            $existingBreaks = [];
                            $autoBreakFormEnabled = false;
                            try {
                                $azcBreakFormProfile = \OCP\Server::get(LaborLawProfileFactory::class)->getProfileForCurrentUser();
                            } catch (\Throwable) {
                                $azcBreakFormProfile = LaborLawProfileFactory::profileForCountry(RegionRegistry::COUNTRY_DE);
                            }
                            $minBreakDurationSeconds = ($entry instanceof \OCA\ArbeitszeitCheck\Db\TimeEntry)
                                ? $entry->resolveCountableMinBreakMinutes() * 60
                                : max(1, (int)$azcBreakFormProfile->minBreakMinutes) * 60;
                            if ($entry) {
                                $breaksJson = $entry->getBreaks();
                                if ($breaksJson !== null && $breaksJson !== '') {
                                    $breaks = json_decode($breaksJson, true) ?? [];
                                    foreach ($breaks as $break) {
                                        if (!isset($break['start'], $break['end'])) {
                                            continue;
                                        }
                                        try {
                                            $breakStart = (new \DateTime($break['start']))->setTimezone($arbeitszeitCheckUserDisplayTz);
                                            $breakEnd = (new \DateTime($break['end']))->setTimezone($arbeitszeitCheckUserDisplayTz);
                                            $breakDurationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();
                                            if ($breakDurationSeconds < $minBreakDurationSeconds) {
                                                continue;
                                            }
                                            $isAutomatic = !empty($break['automatic']);
                                            if ($isAutomatic) {
                                                $autoBreakFormEnabled = true;
                                            }
                                            $existingBreaks[] = [
                                                'start' => $breakStart->format('H:i'),
                                                'end' => $breakEnd->format('H:i'),
                                                'automatic' => $isAutomatic,
                                            ];
                                        } catch (\Exception $e) {
                                        }
                                    }
                                }
                                if ($entry->getBreakStartTime() && $entry->getBreakEndTime()) {
                                    $breakStart = (clone $entry->getBreakStartTime())->setTimezone($arbeitszeitCheckUserDisplayTz);
                                    $breakEnd = (clone $entry->getBreakEndTime())->setTimezone($arbeitszeitCheckUserDisplayTz);
                                    $breakDurationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();
                                    if ($breakDurationSeconds >= $minBreakDurationSeconds) {
                                        $existingBreaks[] = [
                                            'start' => $breakStart->format('H:i'),
                                            'end' => $breakEnd->format('H:i'),
                                            'automatic' => false,
                                        ];
                                    }
                                }
                            }
                            if ($mode === 'create') {
                                $autoBreakFormEnabled = false;
                            }
                            if ($existingBreaks === []) {
                                $existingBreaks = [['start' => '', 'end' => '', 'automatic' => false]];
                            }
                            $_['timeEntryFormBreakIndex'] = count($existingBreaks);
                            $_['timeEntryFormAutoBreakEnabled'] = $autoBreakFormEnabled;

                            $azcBreakIntroLines = [];
                            foreach ($azcBreakFormProfile->breakTiersAscending() as $azcTier) {
                                $azcBreakIntroLines[] = $l->t(
                                    'From %1$s hours of work: at least %2$d minutes break (%3$s)',
                                    [
                                        \OCA\ArbeitszeitCheck\Support\LaborLawProfile::formatHoursLabel((float)$azcTier['afterHours']),
                                        (int)$azcTier['breakMinutes'],
                                        $azcBreakFormProfile->lawLabel('breaks'),
                                    ]
                                );
                            }
                            $azcBreakIntroText = $azcBreakIntroLines !== []
                                ? implode(' ', $azcBreakIntroLines) . ' ' . $l->t('Enable automatic breaks or add times yourself.')
                                : $l->t('Enable automatic breaks or add times yourself.');
                            ?>
                            <div class="azc-callout azc-semantic-panel azc-semantic-panel--info time-entry-form__break-intro" role="note">
                                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('coffee', 'azc-callout__icon-svg')); ?></span>
                                <p class="azc-callout__text"><?php p($azcBreakIntroText); ?></p>
                            </div>

                            <div class="auto-break-panel <?php p($autoBreakFormEnabled ? 'auto-break-panel--enabled' : 'auto-break-panel--disabled'); ?> azc-callout azc-semantic-panel azc-semantic-panel--success"
                                 role="group"
                                 aria-labelledby="auto-break-panel-title">
                                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('check', 'azc-callout__icon-svg')); ?></span>
                                <div class="auto-break-panel__body">
                                    <p id="auto-break-panel-title" class="auto-break-panel__title"><?php p($l->t('Automatic breaks')); ?></p>
                                    <div class="auto-break-panel__control">
                                        <label class="form-toggle">
                                            <input type="checkbox"
                                                   id="auto-break-enabled"
                                                   <?php if ($autoBreakFormEnabled): ?>checked<?php endif; ?>
                                                   aria-labelledby="auto-break-panel-title auto-break-toggle-label"
                                                   aria-describedby="auto-break-toggle-help auto-break-toggle-state">
                                            <span class="toggle-slider" aria-hidden="true"></span>
                                            <span id="auto-break-toggle-label" class="toggle-label"><?php p($l->t('Automatically add required breaks')); ?></span>
                                        </label>
                                        <span id="auto-break-toggle-state"
                                              class="azc-status-pill auto-break-toggle__status <?php p($autoBreakFormEnabled ? 'azc-status-pill--on auto-break-toggle__status--on' : 'azc-status-pill--off auto-break-toggle__status--off'); ?>"
                                              role="status"
                                              aria-live="polite"><?php p($autoBreakFormEnabled ? $l->t('Enabled') : $l->t('Disabled')); ?></span>
                                    </div>
                                    <p id="auto-break-toggle-help" class="auto-break-panel__help"><?php p($l->t('Turn off only if you want to enter every break manually.')); ?></p>
                                </div>
                            </div>
                            <div id="break-requirement-indicator"
                                 class="break-requirement-indicator azc-callout azc-semantic-panel azc-semantic-panel--warning"
                                 role="status"
                                 aria-live="polite"
                                 hidden>
                                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('alert-triangle', 'azc-callout__icon-svg')); ?></span>
                                <p class="azc-callout__text break-requirement-indicator__text">
                                    <span id="break-requirement-text"></span>
                                </p>
                            </div>

                            <p class="time-pair-matrix__intro time-pair-matrix__intro--breaks" id="break-times-intro"><?php p($l->t('Break times')); ?></p>
                            <div class="time-pair-matrix time-pair-matrix--breaks" role="group" aria-labelledby="break-times-intro">
                                <div class="time-pair-matrix__grid time-pair-matrix__grid--header">
                                    <span class="time-pair-matrix__colhead"><?php p($l->t('Start Time')); ?></span>
                                    <span class="time-pair-matrix__colhead"><?php p($l->t('End Time')); ?></span>
                                    <span class="time-pair-matrix__colhead time-pair-matrix__colhead--action"><?php p($l->t('Actions')); ?></span>
                                </div>
                            <div id="breaks-container">
                                <?php foreach ($existingBreaks as $index => $break):
                                    $breakIsAutomatic = !empty($break['automatic']);
                                ?>
                                    <div class="break-entry" data-break-index="<?php p((string)$index); ?>"<?php if ($breakIsAutomatic): ?> data-auto-break="true"<?php endif; ?>>
                                        <div class="time-pair-matrix__grid time-pair-matrix__grid--row<?php if ($breakIsAutomatic): ?> time-pair-matrix__grid--row--with-note<?php endif; ?>">
                                            <div class="form-group">
                                                <label class="form-label time-pair-matrix__row-label" id="break-<?php p((string)$index); ?>-start-label">
                                                    <span class="form-label-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('coffee', 'form-label-icon__svg')); ?></span>
                                                    <span class="sr-only"><?php p($l->t('Break %1$s start', [(string)($index + 1)])); ?></span>
                                                </label>
                                                <?php 
                                                // Custom 24-hour time input - always shows 24h format
                                                $breakStartValue = $break['start'] ?? '';
                                                $breakStartParts = $breakStartValue ? explode(':', $breakStartValue) : ['', ''];
                                                $breakStartHour = $breakStartParts[0] ?? '';
                                                $breakStartMinute = $breakStartParts[1] ?? '';
                                                ?>
                                                <div class="time-input-group" data-time-input="break-start-<?php p((string)$index); ?>" role="group" aria-labelledby="break-<?php p((string)$index); ?>-start-label">
                                                    <select class="form-input time-hour break-start-time-hour" data-break-index="<?php p((string)$index); ?>" aria-label="<?php p($l->t('Break start hour')); ?>">
                                                        <option value="">--</option>
                                                        <?php for ($h = 0; $h < 24; $h++): ?>
                                                            <option value="<?php p(sprintf('%02d', $h)); ?>" <?php p(sprintf('%02d', $h) === $breakStartHour ? 'selected' : ''); ?>>
                                                                <?php p(sprintf('%02d', $h)); ?>
                                                            </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                    <span class="time-separator" aria-hidden="true">:</span>
                                                    <select class="form-input time-minute break-start-time-minute" data-break-index="<?php p((string)$index); ?>" aria-label="<?php p($l->t('Break start minute')); ?>">
                                                        <option value="">--</option>
                                                        <?php foreach (TimePickerMinutes::options($breakStartMinute !== '' ? $breakStartMinute : null) as $minuteOpt): ?>
                                                            <option value="<?php p($minuteOpt); ?>" <?php p($minuteOpt === $breakStartMinute ? 'selected' : ''); ?>>
                                                                <?php p($minuteOpt); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input type="hidden" class="break-start-time" data-break-index="<?php p((string)$index); ?>" name="breaks[<?php p((string)$index); ?>][start]" value="<?php p($breakStartValue); ?>">
                                                </div>
                                            </div>

                                            <div class="form-group">
                                                <label class="form-label time-pair-matrix__row-label" id="break-<?php p((string)$index); ?>-end-label">
                                                    <span class="form-label-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('coffee', 'form-label-icon__svg')); ?></span>
                                                    <span class="sr-only"><?php p($l->t('Break %1$s end', [(string)($index + 1)])); ?></span>
                                                </label>
                                                    <?php 
                                                    // Custom 24-hour time input - always shows 24h format
                                                    $breakEndValue = $break['end'] ?? '';
                                                    $breakEndParts = $breakEndValue ? explode(':', $breakEndValue) : ['', ''];
                                                    $breakEndHour = $breakEndParts[0] ?? '';
                                                    $breakEndMinute = $breakEndParts[1] ?? '';
                                                    ?>
                                                    <div class="time-input-group" data-time-input="break-end-<?php p((string)$index); ?>" role="group" aria-labelledby="break-<?php p((string)$index); ?>-end-label">
                                                        <select class="form-input time-hour break-end-time-hour" data-break-index="<?php p((string)$index); ?>" aria-label="<?php p($l->t('Break end hour')); ?>">
                                                            <option value="">--</option>
                                                            <?php for ($h = 0; $h < 24; $h++): ?>
                                                                <option value="<?php p(sprintf('%02d', $h)); ?>" <?php p(sprintf('%02d', $h) === $breakEndHour ? 'selected' : ''); ?>>
                                                                    <?php p(sprintf('%02d', $h)); ?>
                                                                </option>
                                                            <?php endfor; ?>
                                                        </select>
                                                        <span class="time-separator" aria-hidden="true">:</span>
                                                        <select class="form-input time-minute break-end-time-minute" data-break-index="<?php p((string)$index); ?>" aria-label="<?php p($l->t('Break end minute')); ?>">
                                                            <option value="">--</option>
                                                            <?php foreach (TimePickerMinutes::options($breakEndMinute !== '' ? $breakEndMinute : null) as $minuteOpt): ?>
                                                                <option value="<?php p($minuteOpt); ?>" <?php p($minuteOpt === $breakEndMinute ? 'selected' : ''); ?>>
                                                                    <?php p($minuteOpt); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <input type="hidden" class="break-end-time" data-break-index="<?php p((string)$index); ?>" name="breaks[<?php p((string)$index); ?>][end]" value="<?php p($breakEndValue); ?>">
                                                    </div>
                                            </div>
                                            <div class="time-pair-matrix__action">
                                                    <button type="button" class="azc-btn azc-btn--sm azc-btn--danger btn-remove-break" data-break-index="<?php p((string)$index); ?>" title="<?php p($l->t('Remove break')); ?>" aria-label="<?php p($l->t('Remove this break')); ?>">
                                                        <?php p($l->t('Remove')); ?>
                                                    </button>
                                            </div>
                                            <?php if ($breakIsAutomatic): ?>
                                                <p class="form-help auto-break-note" role="status"><small><?php
							try {
								$azcBreakLaw = \OCP\Server::get(\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::class)->getProfileForCurrentUser()->lawLabel('breaks');
							} catch (\Throwable) {
								$azcBreakLaw = 'ArbZG §4';
							}
							p($l->t('Automatically added for labour-law compliance (%s)', [$azcBreakLaw]));
						?></small></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            </div>

                            <div class="time-entry-form__add-break">
                                <button type="button" id="btn-add-break" class="azc-btn azc-btn--secondary azc-btn--sm">
                                    <?php p($l->t('Add another break')); ?>
                                </button>
                            </div>
                        </fieldset>

                        <?php if ($projectCheckAvailable && !$projectCheckAdminLinkingEnabled && $mode === 'create'): ?>
                            <?php $azcProjectCheckCalloutContext = 'time-entry'; include __DIR__ . '/partials/projectcheck-linking-disabled-callout.php'; ?>
                        <?php elseif ($projectCheckEnabled): ?>
                        <fieldset class="time-entry-form-fieldset time-entry-form-fieldset--project" aria-describedby="entry-project-help">
                            <legend class="time-entry-form-fieldset__legend">
                                <span class="time-entry-form-fieldset__legend-icon" aria-hidden="true"><?php print_unescaped(\OCA\ArbeitszeitCheck\Service\IconCatalog::render('briefcase', 'time-entry-form-fieldset__legend-icon-svg')); ?></span>
                                <?php p($l->t('Project')); ?> <span class="form-optional"><?php p($l->t('(optional)')); ?></span>
                            </legend>
                            <?php if ($projectCheckAvailable && !$projectCheckAdminLinkingEnabled && $mode === 'edit'): ?>
                                <?php $azcProjectCheckCalloutContext = 'edit'; include __DIR__ . '/partials/projectcheck-linking-disabled-callout.php'; ?>
                            <?php endif; ?>
                            <?php if (empty($projectCheckProjects)): ?>
                                <p id="entry-project-help" class="azc-projectcheck-picker-empty form-help" role="status">
                                    <?php p($l->t('You have no ProjectCheck projects to choose from right now. You can still save your time without a project. Ask your project manager to add you to a project team.')); ?>
                                </p>
                            <?php else: ?>
                            <div class="form-group">
                                <label for="entry-project-check" class="form-label" id="entry-project-check-label">
                                    <?php p($l->t('Link to ProjectCheck project')); ?>
                                </label>
                                <select id="entry-project-check"
                                    name="projectCheckProjectId"
                                    class="form-select time-entry-form__project-select"
                                    aria-labelledby="entry-project-check-label"
                                    aria-describedby="entry-project-help">
                                    <?php
                                    $azcPickerProjects = $projectCheckProjects;
                                    $azcPickerSelectedId = $selectedProjectCheckId;
                                    include __DIR__ . '/common/projectcheck-picker-options.php';
                                    ?>
                                </select>
                                <p id="entry-project-help" class="form-help"><?php p($l->t('Link these hours to a ProjectCheck customer project, or leave it on “No project”. Projects with per-person pricing only appear if you are on the project team.')); ?></p>
                            </div>
                            <?php endif; ?>
                        </fieldset>
                        <?php endif; ?>

                        <fieldset class="time-entry-form-fieldset">
                            <legend class="time-entry-form-fieldset__legend"><?php p($l->t('Note')); ?> <span class="form-optional"><?php p($l->t('(optional)')); ?></span></legend>
                            <div class="form-group">
                                <label for="entry-description" id="entry-description-label" class="form-label">
                                    <?php p($l->t('What did you work on?')); ?>
                                </label>
                                <textarea id="entry-description"
                                    name="description"
                                    class="form-textarea"
                                    rows="4"
                                    aria-labelledby="entry-description-label"
                                    aria-describedby="entry-description-help"
                                    placeholder="<?php p($l->t('e.g. project name, customer visit, training')); ?>"><?php p($entry ? ($entry->getDescription() ?? '') : ''); ?></textarea>
                                <p id="entry-description-help" class="form-help"><?php p($l->t('Short note for you and your manager — not shown on the public calendar.')); ?></p>
                            </div>
                        </fieldset>

                        <?php if ($mode === 'create' && $manualTimeEntriesRequireApproval): ?>
                        <fieldset class="time-entry-form-fieldset time-entry-form-fieldset--justification" aria-labelledby="entry-justification-heading">
                            <legend id="entry-justification-heading" class="time-entry-form-fieldset__legend">
                                <?php p($l->t('Reason for manager')); ?>
                                <span class="form-required" aria-hidden="true">*</span>
                            </legend>
                            <p class="time-entry-form__justification-hint" id="entry-justification-block-hint">
                                <?php p($l->t('Required for the audit trail (at least 10 characters).')); ?>
                            </p>
                            <div class="form-group">
                                <label for="entry-justification" id="entry-justification-label" class="form-label">
                                    <?php p($l->t('Why are you adding this time?')); ?>
                                    <span class="sr-only"><?php p($l->t('required')); ?></span>
                                </label>
                                <textarea id="entry-justification"
                                    name="justification"
                                    class="form-textarea"
                                    rows="4"
                                    required
                                    minlength="10"
                                    maxlength="2000"
                                    aria-required="true"
                                    aria-invalid="false"
                                    aria-labelledby="entry-justification-label"
                                    aria-describedby="entry-justification-block-hint entry-justification-help entry-justification-count entry-justification-error"
                                    placeholder="<?php p($l->t('Short reason for your manager…')); ?>"></textarea>
                                <p id="entry-justification-help" class="form-help"><?php p($l->t('Explain why this time was not stamped automatically — for example a forgotten clock-out or work outside the office.')); ?></p>
                                <p id="entry-justification-count" class="time-entry-form__char-count" aria-live="polite"></p>
                                <div id="entry-justification-error" role="alert" class="form-error-container" style="display: none;"></div>
                            </div>
                        </fieldset>
                        <?php endif; ?>

                        <div class="form-actions time-entry-form__actions" role="group" aria-label="<?php p($l->t('Form actions')); ?>">
                            <button type="submit"
                                    id="submit-button"
                                    class="azc-btn azc-btn--primary">
                                <?php p($mode === 'create' ? $l->t('Save time entry') : $l->t('Update time entry')); ?>
                            </button>
                            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
                               class="azc-btn azc-btn--secondary"
                               aria-label="<?php p($l->t('Cancel and return to time entries list')); ?>">
                                <?php p($l->t('Cancel')); ?>
                            </a>
                        </div>
                    </form>
                </div>
                </div>
            </section>
        <?php else: ?>
            <section id="filter-section" class="azc-card azc-filter-panel time-entries-page__filter" style="display: none;" aria-labelledby="time-entries-filter-title">
                <header class="azc-filter-panel__head">
                    <h2 id="time-entries-filter-title"><?php p($l->t('Filter')); ?></h2>
                    <p class="azc-filter-panel__intro"><?php p($l->t('Narrow the list by date range or status, then click Apply.')); ?></p>
                </header>
                <div class="azc-filter-panel__body">
                    <form class="azc-filter-panel__form" novalidate>
                        <div class="azc-filter-grid time-entries-page__filter-grid" role="group" aria-label="<?php p($l->t('Filter options')); ?>">
                            <div class="azc-filter-field">
                                <label for="filter-start-date" class="azc-filter-field__label"><?php p($l->t('Start Date')); ?></label>
                                <div class="azc-filter-field__control">
                                    <input type="text" id="filter-start-date" name="start_date" class="form-input datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly>
                                </div>
                            </div>
                            <div class="azc-filter-field">
                                <label for="filter-end-date" class="azc-filter-field__label"><?php p($l->t('End Date')); ?></label>
                                <div class="azc-filter-field__control">
                                    <input type="text" id="filter-end-date" name="end_date" class="form-input datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly>
                                </div>
                            </div>
                            <div class="azc-filter-field">
                                <label for="filter-status" class="azc-filter-field__label"><?php p($l->t('Status')); ?></label>
                                <div class="azc-filter-field__control">
                                    <select id="filter-status" name="status" class="form-select">
                                        <option value=""><?php p($l->t('All')); ?></option>
                                        <option value="active"><?php p($l->t('Active')); ?></option>
                                        <option value="completed"><?php p($l->t('Completed')); ?></option>
                                        <option value="pending_approval"><?php p($l->t('Pending Approval')); ?></option>
                                        <option value="paused"><?php p($l->t('Paused (needs attention)')); ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="azc-filter-field azc-filter-field--actions">
                                <span class="azc-filter-field__label" aria-hidden="true">&nbsp;</span>
                                <div class="azc-filter-field__control azc-filter-field__control--actions">
                                    <button id="btn-apply-filter" class="azc-btn azc-btn--primary" type="button"><?php p($l->t('Apply')); ?></button>
                                    <button id="btn-clear-filter" class="azc-btn azc-btn--secondary" type="button"><?php p($l->t('Clear')); ?></button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </section>

            <section class="azc-card time-entries-page__list" aria-label="<?php p($l->t('Time entries list')); ?>" role="region">
                <div class="azc-card__body time-entries-page__list-body">
                <?php
                $pausedEntriesCount = 0;
                foreach (($entries ?? []) as $pausedCheckEntry) {
                    if ($pausedCheckEntry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PAUSED) {
                        $pausedEntriesCount++;
                    }
                }
                if ($pausedEntriesCount > 0):
                ?>
                    <?php
                    $calloutVariant = 'warning';
                    $calloutRole = 'status';
                    $calloutBanner = false;
                    $calloutId = 'paused-entries-banner';
                    $calloutAriaLive = 'polite';
                    $calloutIcon = 'alert-triangle';
                    $calloutTitle = $l->n(
                        'You have %n unfinished session that was not properly clocked out.',
                        'You have %n unfinished sessions that were not properly clocked out.',
                        $pausedEntriesCount
                    );
                    $calloutText = $l->t('Click "Complete" next to the entry below to finalise it with the time it was paused — required breaks are added automatically. You can also click "Edit" to set custom times.');
                    $calloutHint = '';
                    include __DIR__ . '/common/alert-callout.php';
                    ?>
                <?php endif; ?>
                <?php
                $pendingCorrectionCount = (int)($_['pendingCorrectionCount'] ?? 0);
                if ($pendingCorrectionCount > 0):
                    $calloutVariant = 'warning';
                    $calloutRole = 'status';
                    $calloutElement = 'div';
                    $calloutBanner = false;
                    $calloutId = 'pending-correction-banner';
                    $calloutTitleId = 'pending-correction-banner-title';
                    $calloutIcon = 'user-check';
                    $calloutExtraClass = 'pending-correction-banner';
                    $calloutTitle = $l->n(
                        '%n time entry is waiting for your manager\'s approval.',
                        '%n time entries are waiting for your manager\'s approval.',
                        $pendingCorrectionCount
                    );
                    $calloutText = $l->t('The table below shows your proposed times until a decision is made. You can withdraw a request using the Withdraw button.');
                    include __DIR__ . '/common/alert-callout.php';
                endif;
                ?>
                <div class="table-container">
                    <table class="table table--hover azc-table--responsive" id="time-entries-table" role="table" aria-label="<?php p($l->t('Time entries list')); ?>">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Date')); ?></th>
                                <th scope="col"><?php p($l->t('Start Time')); ?></th>
                                <th scope="col"><?php p($l->t('End Time')); ?></th>
                                <th scope="col"><?php p($l->t('Duration')); ?></th>
                                <th scope="col"><?php p($l->t('Break')); ?></th>
                                <th scope="col"><?php p($l->t('Working Hours')); ?></th>
                                <th scope="col"><?php p($l->t('Description')); ?></th>
                                <th scope="col"><?php p($l->t('Status')); ?></th>
                                <th scope="col" class="azc-table-actions-col"><?php p($l->t('Actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($entries)): ?>
                                <?php foreach (($entries ?? []) as $entry): ?>
                                    <?php
                                    $entrySummary = $entry->getSummary();
                                    $isPendingRow = $entry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL;
                                    // Always render STORED (truth) values so Duration/Break/Start/End are consistent.
                                    // Pending state is communicated via the status badge, the row highlight,
                                    // and the dashboard/table banner above. The proposed values stay accessible
                                    // through the request dialog and the manager approval UI.
                                    $rowDisplayStart = $entry->getStartTime() ? (clone $entry->getStartTime())->setTimezone($arbeitszeitCheckUserDisplayTz) : null;
                                    $rowDisplayEnd = $entry->getEndTime() ? (clone $entry->getEndTime())->setTimezone($arbeitszeitCheckUserDisplayTz) : null;
                                    $entrySummaryJson = htmlspecialchars(
                                        (string)json_encode($entrySummary, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                    ?>
                                    <tr data-entry-id="<?php p($entry->getId()); ?>" class="<?php echo $isPendingRow ? 'time-entry-row--pending' : ''; ?>">
                                        <td data-label="<?php p($l->t('Date')); ?>"><?php if ($rowDisplayStart) { p($rowDisplayStart->format('d.m.Y')); } else { p('-'); } ?></td>
                                        <td data-label="<?php p($l->t('Start Time')); ?>"><?php if ($rowDisplayStart) { p($rowDisplayStart->format('H:i')); } else { p('-'); } ?></td>
                                        <td data-label="<?php p($l->t('End Time')); ?>"><?php
                                            if ($rowDisplayEnd) {
                                                $startDate = $rowDisplayStart->format('Y-m-d');
                                                $endDate = $rowDisplayEnd->format('Y-m-d');
                                                // Show date if end time is on a different day
                                                if ($startDate !== $endDate) {
                                                    p($rowDisplayEnd->format('d.m.Y H:i'));
                                                } else {
                                                    p($rowDisplayEnd->format('H:i'));
                                                }
                                            } else {
                                                p('-');
                                            }
                                            ?></td>
                                        <td data-label="<?php p($l->t('Duration')); ?>"><?php p(round($entry->getDurationHours() ?? 0, 2)); ?> h</td>
                                        <td data-label="<?php p($l->t('Break')); ?>">
                                            <?php
                                            // Display break times (start and end) if available
                                            $breakTimes = [];

                                            // Check for breaks in JSON array (multiple breaks)
                                            $breaksJson = $entry->getBreaks();
                                            if ($breaksJson !== null && $breaksJson !== '') {
                                                $breaks = json_decode($breaksJson, true) ?? [];
                                                foreach ($breaks as $break) {
                                                    if (isset($break['start']) && isset($break['end'])) {
                                                        try {
                                                            $breakStart = new \DateTime($break['start']);
                                                            $breakEnd = new \DateTime($break['end']);
                                                            $breakStartDisp = clone $breakStart;
                                                            $breakStartDisp->setTimezone($arbeitszeitCheckUserDisplayTz);
                                                            $breakEndDisp = clone $breakEnd;
                                                            $breakEndDisp->setTimezone($arbeitszeitCheckUserDisplayTz);

                                                            // Only include breaks that meet the profile countable floor (DE/CH 15, AT 10)
                                                            $breakDurationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();
                                                            $minBreakDurationSeconds = $entry->resolveCountableMinBreakMinutes() * 60;

                                                            if ($breakDurationSeconds >= $minBreakDurationSeconds) {
                                                                $breakTimes[] = $breakStartDisp->format('H:i') . ' - ' . $breakEndDisp->format('H:i');
                                                            }
                                                        } catch (\Exception $e) {
                                                            // Skip invalid break times
                                                        }
                                                    }
                                                }
                                            }

                                            // Check for single break (breakStartTime/breakEndTime)
                                            if ($entry->getBreakStartTime() !== null && $entry->getBreakEndTime() !== null) {
                                                $breakStart = clone $entry->getBreakStartTime();
                                                $breakEnd = clone $entry->getBreakEndTime();
                                                $breakStart->setTimezone($arbeitszeitCheckUserDisplayTz);
                                                $breakEnd->setTimezone($arbeitszeitCheckUserDisplayTz);

                                                // Only include breaks that meet the profile countable floor (DE/CH 15, AT 10)
                                                $breakDurationSeconds = $entry->getBreakEndTime()->getTimestamp() - $entry->getBreakStartTime()->getTimestamp();
                                                $minBreakDurationSeconds = $entry->resolveCountableMinBreakMinutes() * 60;

                                                if ($breakDurationSeconds >= $minBreakDurationSeconds) {
                                                    $breakTimes[] = $breakStart->format('H:i') . ' - ' . $breakEnd->format('H:i');
                                                }
                                            }

                                            if (!empty($breakTimes)) {
                                                // Show break times with duration (all dynamic output escaped)
                                                $breakDuration = round($entry->getBreakDurationHours() ?? 0, 2);
                                                $breakTimesStr = implode(', ', $breakTimes);
                                                ?><div title="<?php p($breakTimesStr); ?>"><?php p($breakTimesStr); ?> <span class="text-muted">(<?php p($breakDuration . ' h'); ?>)</span></div><?php
                                            } else {
                                                // Only show duration if no times available
                                                $breakDuration = round($entry->getBreakDurationHours() ?? 0, 2);
                                                if ($breakDuration > 0) {
                                                    p($breakDuration . ' h');
                                                } else {
                                                    p('-');
                                                }
                                            }
                                            ?>
                                        </td>
                                        <td data-label="<?php p($l->t('Working Hours')); ?>">
                                            <?php
                                            // For active/paused entries, calculate duration manually
                                            if (!$entry->getEndTime() && $entry->getStartTime()) {
                                                $sessionStart = $entry->getStartTime();
                                                
                                                // For paused entries, calculate duration from start to when it was paused (updatedAt)
                                                // For active/break entries, calculate from start to now
                                                if ($entry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PAUSED && $entry->getUpdatedAt()) {
                                                    $sessionEnd = $entry->getUpdatedAt();
                                                } else {
                                                    $sessionEnd = new \DateTime();
                                                }
                                                
                                                $sessionDuration = $sessionStart ? ($sessionEnd->getTimestamp() - $sessionStart->getTimestamp()) : 0;

                                                // Subtract break time
                                                $totalBreakDurationHours = $entry->getBreakDurationHours();
                                                $totalBreakDuration = $totalBreakDurationHours * 3600;
                                                $sessionDuration -= $totalBreakDuration;
                                                $sessionDuration = max(0, $sessionDuration);

                                                $workingHours = $sessionDuration / 3600;
                                                
                                                // IMPORTANT: Limit to maximum daily working hours (ArbZG §3: max 10 hours per day)
                                                // This prevents unrealistic values for paused entries that weren't properly clocked out
                                                $maxWorkingHours = 10.0;
                                                $workingHours = min($workingHours, $maxWorkingHours);
                                                
                                                p(round($workingHours, 2)); ?> h
                                            <?php } else {
                                                $workingHours = $entry->getWorkingDurationHours() ?? 0;
                                                // Also limit completed entries to max for display (should already be enforced)
                                                $maxWorkingHours = 10.0;
                                                $workingHours = min($workingHours, $maxWorkingHours);
                                                p(round($workingHours, 2)); ?> h
                                            <?php } ?>
                                        </td>
                                        <td class="description-cell" data-label="<?php p($l->t('Description')); ?>">
                                            <?php p($entry->getDescription() ? substr($entry->getDescription(), 0, 50) : '-'); ?>
                                            <?php if ($entry->getDescription() && strlen($entry->getDescription()) > 50): ?>
                                                <span class="description-more">...</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="<?php p($l->t('Status')); ?>">
                                            <span class="badge badge--<?php p(BadgeVariant::forTimeEntryStatus((string)$entry->getStatus())); ?>"
                                                title="<?php
                                                p(match ($entry->getStatus()) {
                                                    'paused' => $l->t('This session was not properly clocked out. Complete it (one click) or edit the times below.'),
                                                    'pending_approval' => $l->t('Waiting for manager approval'),
                                                    'rejected' => $l->t('Correction request was rejected'),
                                                    'active' => $l->t('Currently tracking'),
                                                    'break' => $l->t('Currently on break'),
                                                    'completed' => $l->t('Closed and counted toward your working time'),
                                                    default => ''
                                                });
                                                ?>">
                                                <?php
                                                $statusKey = $entry->getStatus();
                                                $statusLabel = match ($statusKey) {
                                                    'completed' => $l->t('Completed'),
                                                    'active' => $l->t('Active'),
                                                    'pending_approval' => $l->t('Pending Approval'),
                                                    'break' => $l->t('Break'),
                                                    'paused' => $l->t('Paused'),
                                                    'rejected' => $l->t('Rejected'),
                                                    default => $statusKey
                                                };
                                                p($statusLabel);
                                                ?>
                                            </span>
                                        </td>
                                        <td class="actions-cell" data-label="<?php p($l->t('Actions')); ?>">
                                            <?php $rowActionsEntryId = $entry->getId(); ?>
                                            <div class="azc-row-actions" data-entry-id="<?php p((string)$rowActionsEntryId); ?>">
                                                <button type="button"
                                                    class="btn btn--sm btn--secondary azc-row-actions__toggle"
                                                    aria-expanded="false"
                                                    aria-controls="row-actions-<?php p((string)$rowActionsEntryId); ?>"
                                                    aria-label="<?php p($l->t('Open actions for this entry')); ?>">
                                                    <?php p($l->t('Actions')); ?>
                                                </button>
                                            <div class="azc-table-actions azc-row-actions__panel" id="row-actions-<?php p((string)$rowActionsEntryId); ?>" role="group" aria-label="<?php p($l->t('Actions')); ?>">
                                            <?php
                                            $isPaused = $entry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PAUSED;
                                            // One-click completion for paused entries — the most common recovery action.
                                            // Rendered first and primary-styled so even keyboard/SR users land on it first.
                                            if ($isPaused):
                                            ?>
                                                <button class="btn btn--sm btn--primary btn-complete-entry"
                                                    data-entry-id="<?php p($entry->getId()); ?>"
                                                    title="<?php p($l->t('Complete this paused session now. The end time will be set to when it was paused, and required breaks will be applied automatically.')); ?>"
                                                    type="button"
                                                    aria-label="<?php p($l->t('Complete this paused session')); ?>">
                                                    <span class="btn__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('check', 'btn__icon-svg')); ?></span>
                                                    <?php p($l->t('Complete')); ?>
                                                </button>
                                            <?php endif; ?>
                                            <?php
                                            if ($entry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL):
                                            ?>
                                                <button class="btn btn--sm btn--secondary btn-cancel-correction"
                                                    data-entry-id="<?php p($entry->getId()); ?>"
                                                    type="button"
                                                    aria-label="<?php p($l->t('Withdraw pending correction')); ?>">
                                                    <?php p($l->t('Withdraw')); ?>
                                                </button>
                                            <?php endif; ?>
                                            <?php
                                            $canRequestCorrection = $entry->canRequestCorrection(\OCA\ArbeitszeitCheck\Constants::EDIT_WINDOW_DAYS);
                                            if ($canRequestCorrection && !$entry->canEdit(\OCA\ArbeitszeitCheck\Constants::EDIT_WINDOW_DAYS)):
                                            ?>
                                                <button class="btn btn--sm btn--secondary btn-request-correction"
                                                    data-entry-id="<?php p($entry->getId()); ?>"
                                                    data-entry-summary="<?php echo $entrySummaryJson; ?>"
                                                    type="button"
                                                    aria-label="<?php p($l->t('Request correction for manager approval')); ?>">
                                                    <?php p($l->t('Request correction')); ?>
                                                </button>
                                            <?php endif; ?>
                                            <?php
                                            $canEdit = $entry->canEdit(\OCA\ArbeitszeitCheck\Constants::EDIT_WINDOW_DAYS);
                                            if ($canEdit):
                                            ?>
                                                <button class="btn btn--sm btn--secondary btn-edit-entry"
                                                    data-entry-id="<?php p($entry->getId()); ?>"
                                                    title="<?php p($isPaused ? $l->t('Edit start/end times and breaks for this paused session') : $l->t('Edit this time entry')); ?>"
                                                    type="button"
                                                    aria-label="<?php p($isPaused ? $l->t('Edit times for this paused time entry') : $l->t('Edit time entry')); ?>">
                                                    <?php p($l->t('Edit')); ?>
                                                </button>
                                            <?php endif; ?>
                                            <?php
                                            $entryId = $rowActionsEntryId;
                                            $deleteEligibility = $deletionEligibility[$entryId] ?? null;
                                            $canDelete = $deleteEligibility !== null
                                                ? (bool)$deleteEligibility['canDelete']
                                                : $entry->canDelete($editWindowDays);
                                            $deleteBlockCode = is_array($deleteEligibility)
                                                ? ($deleteEligibility['blockCode'] ?? null)
                                                : null;
                                            $deleteBlockMessage = is_array($deleteEligibility)
                                                ? ($deleteEligibility['blockMessage'] ?? null)
                                                : null;
                                            $deleteWarnings = is_array($deleteEligibility)
                                                ? ($deleteEligibility['warnings'] ?? [])
                                                : [];
                                            if ($canDelete):
                                                $deleteWarningsJson = json_encode(
                                                    array_values(array_filter($deleteWarnings, static fn ($w) => is_string($w) && $w !== '')),
                                                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
                                                );
                                            ?>
                                                <button class="btn btn--sm btn--danger btn-delete btn-delete-entry"
                                                    data-entry-id="<?php p($entryId); ?>"
                                                    <?php if ($deleteWarningsJson !== false && $deleteWarningsJson !== '[]'): ?>
                                                    data-delete-warnings="<?php p($deleteWarningsJson); ?>"
                                                    <?php endif; ?>
                                                    title="<?php p($l->t('Delete this time entry permanently. This cannot be undone.')); ?>"
                                                    type="button"
                                                    aria-label="<?php p($l->t('Delete this time entry permanently')); ?>">
                                                    <?php p($l->t('Delete')); ?>
                                                </button>
                                            <?php
                                            elseif (
                                                is_string($deleteBlockMessage)
                                                && $deleteBlockMessage !== ''
                                                && $deleteBlockCode !== 'cancel_correction_first'
                                                && in_array($deleteBlockCode, ['manager_approved', 'session_active'], true)
                                            ):
                                            ?>
                                                <p class="azc-action-hint" role="note">
                                                    <?php p($deleteBlockMessage); ?>
                                                </p>
                                            <?php endif; ?>
                                            </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="azc-empty-state">
                                            <h3 class="azc-empty-state__title"><?php p($l->t('No time entries yet')); ?></h3>
                                            <p class="azc-empty-state__text">
                                                <?php if ($manualTimeEntryEnabled): ?>
                                                    <?php p($l->t('You haven\'t recorded any working time yet. Click the button below to add your first time entry, or use the clock in button on the dashboard to start tracking automatically.')); ?>
                                                <?php else: ?>
                                                    <?php p($l->t('You haven\'t recorded any working time yet. When your administrator allows it, you can add entries here or use clock in on the dashboard.')); ?>
                                                <?php endif; ?>
                                            </p>
                                            <?php if ($manualTimeEntryEnabled): ?>
                                            <button id="btn-add-first-entry"
                                                class="azc-btn azc-btn--primary"
                                                type="button"
                                                aria-label="<?php p($l->t('Add your first time entry')); ?>">
                                                <?php p($l->t('Add Your First Entry')); ?>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($entries) && count($entries) > 0): ?>
                    <nav class="time-entries-page__pagination" aria-label="<?php p($l->t('Time entries pagination')); ?>">
                        <button id="btn-prev-page" class="azc-btn azc-btn--secondary" type="button" disabled>
                            <?php p($l->t('Previous')); ?>
                        </button>
                        <span class="time-entries-page__pagination-info">
                            <span id="current-page">1</span> / <span id="total-pages">1</span>
                        </span>
                        <button id="btn-next-page" class="azc-btn azc-btn--secondary" type="button" disabled>
                            <?php p($l->t('Next')); ?>
                        </button>
                    </nav>
                <?php endif; ?>
                </div>
            </section>

            <?php /* Correction dialog — cloned into modal on first open (time-entry-correction.js). */ ?>
            <div id="time-entry-correction-source" class="visually-hidden" aria-hidden="true" data-datepicker-defer>
                <form id="time-entry-correction-form" class="correction-dialog__inner" action="#" method="post" novalidate>
                    <input type="hidden" id="correction-entry-id" name="entryId" value="">
                    <p id="correction-dialog-desc" class="correction-dialog__intro"><?php p($l->t('Your manager must approve this change before it is saved.')); ?></p>

                    <section class="correction-dialog__block correction-dialog__block--current" aria-labelledby="correction-current-heading">
                        <h3 id="correction-current-heading" class="correction-dialog__block-title"><?php p($l->t('Currently stored')); ?></h3>
                        <div class="correction-snapshot__wrap" role="region" aria-label="<?php p($l->t('Times currently saved for this entry')); ?>">
                            <table class="correction-snapshot__table">
                                    <caption class="sr-only"><?php p($l->t('Times currently saved for this entry')); ?></caption>
                                    <tbody id="correction-current-summary"></tbody>
                                </table>
                            </div>
                        </section>

                    <section class="correction-dialog__block correction-dialog__block--proposed" aria-labelledby="correction-proposed-heading">
                        <h3 id="correction-proposed-heading" class="correction-dialog__block-title"><?php p($l->t('Corrected times')); ?></h3>
                        <p class="correction-dialog__block-hint"><?php p($l->t('Enter the date and times as they should be recorded.')); ?></p>

                            <fieldset class="correction-fieldset">
                                <legend class="sr-only"><?php p($l->t('Corrected working day')); ?></legend>
                            <div class="time-entry-form__date correction-dialog__date">
                                <div class="form-group">
                                    <label for="correction-date" id="correction-date-label" class="form-label">
                                        <?php p($l->t('Date')); ?>
                                        <span class="form-required" aria-hidden="true">*</span>
                                        <span class="sr-only"><?php p($l->t('required')); ?></span>
                                    </label>
                                    <div class="form-input-wrapper form-input-wrapper--date">
                                        <input type="text"
                                            id="correction-date"
                                            name="date"
                                            class="form-input correction-date-input"
                                            data-datepicker-defer
                                            inputmode="numeric"
                                            autocomplete="off"
                                            pattern="\d{2}\.\d{2}\.\d{4}"
                                            placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
                                            aria-labelledby="correction-date-label"
                                            aria-describedby="correction-date-help"
                                            required>
                                        <button type="button"
                                            id="correction-date-today"
                                            class="btn btn--sm btn--secondary correction-date-today"
                                            aria-label="<?php p($l->t('Set date to today')); ?>">
                                            <?php p($l->t('Today')); ?>
                                        </button>
                                    </div>
                                    <p id="correction-date-help" class="form-help"><?php p($l->t('Format: dd.mm.yyyy')); ?></p>
                                </div>
                            </div>

                            <p class="time-pair-matrix__intro" id="correction-work-time-intro"><?php p($l->t('Working Hours')); ?></p>
                            <div class="time-pair-matrix" role="group" aria-labelledby="correction-work-time-intro">
                                <div class="time-pair-matrix__grid time-pair-matrix__grid--header">
                                    <span class="time-pair-matrix__colhead"><?php p($l->t('Start Time')); ?></span>
                                    <span class="time-pair-matrix__colhead"><?php p($l->t('End Time')); ?></span>
                                    <span class="time-pair-matrix__colhead time-pair-matrix__colhead--action" aria-hidden="true"></span>
                                </div>
                                <div class="time-pair-matrix__grid time-pair-matrix__grid--row">
                                    <div class="form-group">
                                        <label for="correction-start-hour" class="form-label"><?php p($l->t('Start')); ?></label>
                                        <div class="time-input-group" id="correction-start-time-group" role="group" aria-label="<?php p($l->t('Start Time')); ?>">
                                            <select id="correction-start-hour" class="form-input time-hour" required aria-label="<?php p($l->t('Start hour')); ?>"></select>
                                            <span class="time-separator" aria-hidden="true">:</span>
                                            <select id="correction-start-minute" class="form-input time-minute" required aria-label="<?php p($l->t('Start minute')); ?>"></select>
                                            <input type="hidden" id="correction-start-time" name="startTime" value="">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="correction-end-hour" class="form-label"><?php p($l->t('End')); ?></label>
                                        <div class="time-input-group" id="correction-end-time-group" role="group" aria-label="<?php p($l->t('End Time')); ?>">
                                            <select id="correction-end-hour" class="form-input time-hour" required aria-label="<?php p($l->t('End hour')); ?>"></select>
                                            <span class="time-separator" aria-hidden="true">:</span>
                                            <select id="correction-end-minute" class="form-input time-minute" required aria-label="<?php p($l->t('End minute')); ?>"></select>
                                            <input type="hidden" id="correction-end-time" name="endTime" value="">
                                        </div>
                                    </div>
                                    <div class="time-pair-matrix__action time-pair-matrix__action--spacer" aria-hidden="true"></div>
                                </div>
                            </div>
                            <p class="form-help correction-dialog__hint"><?php p($l->t('Night shift: if end is earlier than start (e.g. 22:00–06:00), end counts as the next day.')); ?></p>
                        </fieldset>
                    </section>

                    <fieldset class="correction-fieldset correction-fieldset--breaks" aria-labelledby="correction-breaks-heading">
                        <legend id="correction-breaks-heading" class="correction-dialog__section-title"><?php p($l->t('Breaks (optional)')); ?></legend>
                        <?php
                        try {
                            $azcCorrectionMinBreak = \OCP\Server::get(LaborLawProfileFactory::class)->getProfileForCurrentUser()->minBreakMinutes;
                        } catch (\Throwable) {
                            $azcCorrectionMinBreak = 15;
                        }
                        $azcCorrectionMinBreak = max(1, (int)$azcCorrectionMinBreak);
                        ?>
                        <p class="correction-dialog__block-hint"><?php p($l->t('Leave empty to keep current breaks. Each break must be at least %d minutes and within working hours.', [$azcCorrectionMinBreak])); ?></p>
                        <p id="correction-breaks-empty" class="correction-breaks-empty"><?php p($l->t('No breaks added — current breaks will be kept.')); ?></p>
                            <p class="time-pair-matrix__intro time-pair-matrix__intro--breaks" id="correction-breaks-intro"><?php p($l->t('Break times')); ?></p>
                            <div class="time-pair-matrix time-pair-matrix--breaks" role="group" aria-labelledby="correction-breaks-intro">
                                <div class="time-pair-matrix__grid time-pair-matrix__grid--header">
                                    <span class="time-pair-matrix__colhead"><?php p($l->t('Start Time')); ?></span>
                                    <span class="time-pair-matrix__colhead"><?php p($l->t('End Time')); ?></span>
                                    <span class="time-pair-matrix__colhead time-pair-matrix__colhead--action"><?php p($l->t('Actions')); ?></span>
                                </div>
                                <div id="correction-breaks-container"></div>
                            </div>
                        <button type="button" class="btn btn--secondary btn--sm" id="correction-add-break"><?php p($l->t('Add break')); ?></button>
                    </fieldset>

                    <fieldset class="correction-fieldset correction-fieldset--reason" aria-labelledby="correction-reason-heading">
                        <legend id="correction-reason-heading" class="correction-dialog__section-title">
                            <?php p($l->t('Reason for correction')); ?>
                            <span class="form-required" aria-hidden="true">*</span>
                        </legend>
                        <p class="correction-dialog__block-hint"><?php p($l->t('Required for the audit trail (at least 10 characters).')); ?></p>
                        <div class="form-group">
                            <label for="correction-justification" class="form-label">
                                <?php p($l->t('Explanation')); ?>
                                <span class="sr-only"><?php p($l->t('required')); ?></span>
                            </label>
                            <textarea id="correction-justification"
                                name="justification"
                                class="form-textarea"
                                rows="4"
                                required
                                minlength="10"
                                maxlength="2000"
                                aria-required="true"
                                aria-describedby="correction-justification-help correction-justification-count"></textarea>
                            <p id="correction-justification-help" class="form-help"><?php p($l->t('Describe what was wrong and why the times should change.')); ?></p>
                            <p id="correction-justification-count" class="correction-dialog__char-count" aria-live="polite"></p>
                        </div>
                    </fieldset>

                    <div id="correction-dialog-status" class="correction-dialog__status" aria-live="polite"></div>

                    <div class="modal-footer correction-dialog__footer">
                        <button type="button" class="btn btn--secondary" id="correction-dialog-cancel"><?php p($l->t('Cancel')); ?></button>
                        <button type="submit" class="btn btn--primary" id="correction-wizard-submit" form="time-entry-correction-form"><?php p($l->t('Submit correction request')); ?></button>
                    </div>
                </form>
            </div>

        <?php endif; ?>

<?php include __DIR__ . '/common/main-ui-l10n.php'; ?>
<?php include __DIR__ . '/common/time-entry-correction-l10n.php'; ?>
<?php include __DIR__ . '/common/time-entries-page-bootstrap.php'; ?>
<?php if ($mode === 'create' || $mode === 'edit'): ?>
<?php include __DIR__ . '/common/time-entry-form-l10n.php'; ?>
<?php include __DIR__ . '/common/time-entry-form-config.php'; ?>
<?php endif; ?>
</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
