<?php

declare(strict_types=1);

/**
 * Time Entries template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCP\Util;

/** @var array $_ */
/** @var \OCP\IL10N $l */

// Add common + page-specific styles and scripts
Util::addTranslations('arbeitszeitcheck');
Util::addStyle('arbeitszeitcheck', 'common/colors');
Util::addStyle('arbeitszeitcheck', 'common/typography');
Util::addStyle('arbeitszeitcheck', 'common/base');
Util::addStyle('arbeitszeitcheck', 'common/components');
Util::addStyle('arbeitszeitcheck', 'common/layout');
Util::addStyle('arbeitszeitcheck', 'common/app-layout');
Util::addStyle('arbeitszeitcheck', 'common/utilities');
Util::addStyle('arbeitszeitcheck', 'common/responsive');
Util::addStyle('arbeitszeitcheck', 'common/accessibility');
Util::addStyle('arbeitszeitcheck', 'navigation');
Util::addStyle('arbeitszeitcheck', 'time-entries');
Util::addStyle('arbeitszeitcheck', 'time-entry-form-accessibility');
Util::addScript('arbeitszeitcheck', 'common/utils');
Util::addScript('arbeitszeitcheck', 'common/datepicker');
Util::addScript('arbeitszeitcheck', 'common/validation');
Util::addScript('arbeitszeitcheck', 'time-entry-form-accessibility');
Util::addScript('arbeitszeitcheck', 'arbeitszeitcheck-main');

$entries = $_['entries'] ?? [];
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$stats = $_['stats'] ?? [];
$mode = $_['mode'] ?? 'list'; // 'list', 'create', 'edit'
$entry = $_['entry'] ?? null;
$error = $_['error'] ?? null;
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb-container">
            <nav class="breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
                <ol>
                    <li><a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"><?php p($l->t('Dashboard')); ?></a></li>
                    <li aria-current="page"><?php p($l->t('Time Entries')); ?></li>
                </ol>
            </nav>
        </div>

        <!-- Page Header -->
        <header class="section page-header-section" aria-labelledby="time-entries-page-title">
            <div class="header-content">
                <div class="header-text">
                    <h2 id="time-entries-page-title"><?php
                        if ($mode === 'create') {
                            p($l->t('Add Time Entry'));
                        } elseif ($mode === 'edit') {
                            p($l->t('Edit Time Entry'));
                        } else {
                            p($l->t('Time Entries'));
                        }
                        ?></h2>
                    <p><?php
                        if ($mode === 'create') {
                            p($l->t('Record when you worked by entering the start and end times, and any breaks you took.'));
                        } elseif ($mode === 'edit') {
                            p($l->t('Edit your time entry. You can edit manual entries, entries with pending approval, or completed automatic entries from the last 2 weeks.'));
                        } else {
                            p($l->t('Manage your working time records'));
                        }
                        ?></p>
                </div>
                <?php if ($mode === 'list'): ?>
                    <div class="header-actions">
                        <button id="btn-add-entry"
                            class="btn btn--primary"
                            type="button"
                            aria-label="<?php p($l->t('Add a new time entry to record when you worked')); ?>"
                            title="<?php p($l->t('Click to add a new time entry. You can record when you started and finished work, and any breaks you took.')); ?>">
                            <?php p($l->t('Add Time Entry')); ?>
                        </button>
                        <button id="btn-filter"
                            class="btn btn--secondary"
                            type="button"
                            aria-label="<?php p($l->t('Filter time entries by date or status')); ?>"
                            title="<?php p($l->t('Click to show options for filtering your time entries. You can filter by date range or status.')); ?>">
                            <?php p($l->t('Filter')); ?>
                        </button>
                        <button id="btn-export"
                            class="btn btn--secondary"
                            type="button"
                            aria-label="<?php p($l->t('Download your time entries as a file')); ?>"
                            title="<?php p($l->t('Click to download all your time entries as a file. You can choose PDF, Excel, or CSV format.')); ?>">
                            <?php p($l->t('Download')); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($mode === 'list' && !empty($stats)): ?>
                <div class="stats-grid">
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
            <?php endif; ?>
        </header>

        <?php if ($mode === 'create' || $mode === 'edit'): ?>
            <!-- Create/Edit Form -->
            <section class="section" aria-label="<?php p($l->t('Time entry form')); ?>">
                <?php if ($error): ?>
                    <div class="alert alert--error">
                        <p><?php p($error); ?></p>
                    </div>
                <?php endif; ?>

                <div class="card card--elevated">
                    <form id="time-entry-form" 
                          class="form" 
                          method="GET" 
                          action="#"
                          novalidate
                          role="form"
                          aria-label="<?php p($l->t('Time Entry Form')); ?>"
                          aria-describedby="form-description">
                        <p id="form-description" class="sr-only">
                            <?php p($mode === 'create'
                                ? $l->t('Use this form to record when you worked. Fill in the date, start time, end time, and optionally break times. The form will automatically check compliance with German labor law requirements.')
                                : $l->t('Use this form to edit your time entry. The form will automatically check compliance with German labor law requirements.')); ?>
                        </p>

                        <!-- Real-time Summary Section -->
                        <div id="time-summary" 
                             role="status" 
                             aria-live="polite" 
                             aria-atomic="true"
                             class="time-summary-card"
                             style="display: none;">
                            <h4 class="time-summary-title"><?php p($l->t('Summary')); ?></h4>
                            <div class="summary-row">
                                <span class="summary-label"><?php p($l->t('Working Hours')); ?>:</span>
                                <span id="summary-working-hours" class="summary-value">0.0</span> h
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><?php p($l->t('Break Time')); ?>:</span>
                                <span id="summary-break-time" class="summary-value">0.0</span> h
                            </div>
                            <div id="compliance-status" class="compliance-status" role="status" aria-live="polite"></div>
                        </div>

                        <!-- Date and Time Section -->
                        <div class="form-section" role="group" aria-labelledby="date-time-section-title">
                            <h3 id="date-time-section-title" class="form-section-title"><?php p($l->t('Date & Time')); ?></h3>
                            <div class="form-grid form-grid--2">
                                <div class="form-group">
                                    <label for="entry-date" id="entry-date-label" class="form-label">
                                        <span class="form-label-icon" aria-hidden="true">📅</span>
                                        <?php p($l->t('Date')); ?> 
                                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                                    </label>
                                    <div class="form-input-wrapper">
                                        <input type="text"
                                            id="entry-date"
                                            name="date"
                                            class="form-input datepicker-input"
                                            pattern="\d{2}\.\d{2}\.\d{4}"
                                            placeholder="dd.mm.yyyy"
                                            value="<?php p($entry ? $entry->getStartTime()->format('d.m.Y') : date('d.m.Y')); ?>"
                                            aria-labelledby="entry-date-label"
                                            aria-describedby="entry-date-help entry-date-error"
                                            aria-required="true"
                                            aria-invalid="false"
                                            required>
                                        <button type="button" 
                                                id="btn-today"
                                                class="btn btn--sm btn--secondary"
                                                aria-label="<?php p($l->t('Set date to today')); ?>"
                                                title="<?php p($l->t('Click to quickly set the date to today')); ?>">
                                            <?php p($l->t('Today')); ?>
                                        </button>
                                    </div>
                                    <p id="entry-date-help" class="form-help">
                                        <?php p($l->t('Select the day you worked (format: dd.mm.yyyy, e.g., 15.01.2024). You can also click "Today" to quickly set today\'s date.')); ?>
                                    </p>
                                    <div id="entry-date-error" role="alert" aria-live="polite" class="form-error-container" style="display: none;"></div>
                                </div>
                            </div>
                            <div class="form-grid form-grid--2">
                                <div class="form-group">
                                    <label for="entry-start-time" id="entry-start-time-label" class="form-label">
                                        <span class="form-label-icon" aria-hidden="true">🕐</span>
                                        <?php p($l->t('Start Time')); ?> 
                                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                                    </label>
                                    <?php 
                                    // Custom 24-hour time input - always shows 24h format regardless of browser locale
                                    $startTimeValue = $entry ? $entry->getStartTime()->format('H:i') : '09:00';
                                    $startTimeParts = explode(':', $startTimeValue);
                                    $startHour = $startTimeParts[0] ?? '09';
                                    $startMinute = $startTimeParts[1] ?? '00';
                                    ?>
                                    <div class="time-input-group" 
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
                                            <?php for ($m = 0; $m < 60; $m += 1): ?>
                                                <option value="<?php p(sprintf('%02d', $m)); ?>" <?php p(sprintf('%02d', $m) === $startMinute ? 'selected' : ''); ?>>
                                                    <?php p(sprintf('%02d', $m)); ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                        <input type="hidden" 
                                               id="entry-start-time" 
                                               name="startTime" 
                                               value="<?php p($startTimeValue); ?>" 
                                               required
                                               aria-invalid="false">
                                    </div>
                                    <p id="entry-start-time-help" class="form-help">
                                        <?php p($l->t('What time did you start working? (24-hour format, e.g., 09:00 for 9 AM or 17:30 for 5:30 PM)')); ?>
                                    </p>
                                    <div id="entry-start-time-error" role="alert" aria-live="polite" class="form-error-container" style="display: none;"></div>
                                </div>

                                <div class="form-group">
                                    <label for="entry-end-time" id="entry-end-time-label" class="form-label">
                                        <span class="form-label-icon" aria-hidden="true">🕐</span>
                                        <?php p($l->t('End Time')); ?> 
                                        <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                                    </label>
                                    <?php 
                                    // Custom 24-hour time input - always shows 24h format regardless of browser locale
                                    $endTimeValue = $entry && $entry->getEndTime() ? $entry->getEndTime()->format('H:i') : '17:00';
                                    $endTimeParts = explode(':', $endTimeValue);
                                    $endHour = $endTimeParts[0] ?? '17';
                                    $endMinute = $endTimeParts[1] ?? '00';
                                    ?>
                                    <div class="time-input-group" 
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
                                            <?php for ($m = 0; $m < 60; $m += 1): ?>
                                                <option value="<?php p(sprintf('%02d', $m)); ?>" <?php p(sprintf('%02d', $m) === $endMinute ? 'selected' : ''); ?>>
                                                    <?php p(sprintf('%02d', $m)); ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                        <input type="hidden" 
                                               id="entry-end-time" 
                                               name="endTime" 
                                               value="<?php p($endTimeValue); ?>" 
                                               required
                                               aria-invalid="false">
                                    </div>
                                    <p id="entry-end-time-help" class="form-help">
                                        <?php p($l->t('What time did you finish working? (24-hour format, e.g., 17:00 for 5 PM. For night shifts, enter the next day\'s time)')); ?>
                                    </p>
                                    <div id="entry-end-time-error" role="alert" aria-live="polite" class="form-error-container" style="display: none;"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Break Section -->
                        <div class="form-section" role="group" aria-labelledby="break-section-title">
                            <h3 id="break-section-title" class="form-section-title"><?php p($l->t('Break Information')); ?></h3>
                            <p id="break-section-description" class="form-section-description">
                                <?php p($l->t('Optional: Record your break times. German law requires breaks for work over 6 hours: 30 minutes for 6-9 hours, 45 minutes for 9+ hours. Shorter shifts have no break requirements. Automatic breaks are added when needed for compliance. You can add additional manual breaks.')); ?>
                            </p>

                            <div class="auto-break-toggle">
                                <label class="form-toggle">
                                    <input type="checkbox" id="auto-break-enabled" checked>
                                    <span class="toggle-slider"></span>
                                    <span class="toggle-label"><?php p($l->t('Automatically add required breaks')); ?></span>
                                </label>
                                <p class="form-help"><?php p($l->t('When enabled, breaks required by German law are automatically added. Disable to manage breaks manually.')); ?></p>
                            </div>
                            <div id="break-requirement-indicator" class="break-requirement-indicator" style="display: none;">
                                <small class="form-help">
                                    <span id="break-requirement-text"></span>
                                </small>
                            </div>

                            <div id="breaks-container">
                                <?php
                                // Load existing breaks from breaks JSON field
                                $existingBreaks = [];
                                if ($entry) {
                                    $breaksJson = $entry->getBreaks();
                                    if ($breaksJson !== null && $breaksJson !== '') {
                                        $breaks = json_decode($breaksJson, true) ?? [];
                                        foreach ($breaks as $break) {
                                            if (isset($break['start']) && isset($break['end'])) {
                                                try {
                                                    $breakStart = new \DateTime($break['start']);
                                                    $breakEnd = new \DateTime($break['end']);
                                                    $breakDurationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();
                                                    $minBreakDurationSeconds = 900; // 15 minutes

                                                    // Only include breaks that are at least 15 minutes (ArbZG §4)
                                                    if ($breakDurationSeconds >= $minBreakDurationSeconds) {
                                                        $existingBreaks[] = [
                                                            'start' => $breakStart->format('H:i'),
                                                            'end' => $breakEnd->format('H:i')
                                                        ];
                                                    }
                                                } catch (\Exception $e) {
                                                    // Skip invalid break times
                                                }
                                            }
                                        }
                                    }
                                    // Also check for single break (breakStartTime/breakEndTime) for backward compatibility
                                    if ($entry->getBreakStartTime() && $entry->getBreakEndTime()) {
                                        $breakStart = $entry->getBreakStartTime();
                                        $breakEnd = $entry->getBreakEndTime();
                                        $breakDurationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();
                                        $minBreakDurationSeconds = 900; // 15 minutes

                                        if ($breakDurationSeconds >= $minBreakDurationSeconds) {
                                            $existingBreaks[] = [
                                                'start' => $breakStart->format('H:i'),
                                                'end' => $breakEnd->format('H:i')
                                            ];
                                        }
                                    }
                                }

                                // If no breaks exist, show one empty break field
                                if (empty($existingBreaks)) {
                                    $existingBreaks = [['start' => '', 'end' => '']];
                                }

                                foreach ($existingBreaks as $index => $break):
                                ?>
                                    <div class="break-entry" data-break-index="<?php p((string)$index); ?>">
                                        <div class="form-grid form-grid--2">
                                            <div class="form-group">
                                                <label class="form-label">
                                                    <span class="form-label-icon">☕</span>
                                                    <?php p($l->t('Break Start Time')); ?>
                                                </label>
                                                <?php 
                                                // Custom 24-hour time input - always shows 24h format
                                                $breakStartValue = $break['start'] ?? '';
                                                $breakStartParts = $breakStartValue ? explode(':', $breakStartValue) : ['', ''];
                                                $breakStartHour = $breakStartParts[0] ?? '';
                                                $breakStartMinute = $breakStartParts[1] ?? '';
                                                ?>
                                                <div class="time-input-group" data-time-input="break-start-<?php p((string)$index); ?>">
                                                    <select class="form-input time-hour break-start-time-hour" data-break-index="<?php p((string)$index); ?>" aria-label="<?php p($l->t('Break start hour')); ?>">
                                                        <option value="">--</option>
                                                        <?php for ($h = 0; $h < 24; $h++): ?>
                                                            <option value="<?php p(sprintf('%02d', $h)); ?>" <?php p(sprintf('%02d', $h) === $breakStartHour ? 'selected' : ''); ?>>
                                                                <?php p(sprintf('%02d', $h)); ?>
                                                            </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                    <span class="time-separator">:</span>
                                                    <select class="form-input time-minute break-start-time-minute" data-break-index="<?php p((string)$index); ?>" aria-label="<?php p($l->t('Break start minute')); ?>">
                                                        <option value="">--</option>
                                                        <?php for ($m = 0; $m < 60; $m += 1): ?>
                                                            <option value="<?php p(sprintf('%02d', $m)); ?>" <?php p(sprintf('%02d', $m) === $breakStartMinute ? 'selected' : ''); ?>>
                                                                <?php p(sprintf('%02d', $m)); ?>
                                                            </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                    <input type="hidden" class="break-start-time" data-break-index="<?php p((string)$index); ?>" name="breaks[<?php p((string)$index); ?>][start]" value="<?php p($breakStartValue); ?>">
                                                </div>
                                                <p class="form-help"><?php p($l->t('Optional: When did your break start? Use automatic breaks for legal compliance.')); ?></p>
                                            </div>

                                            <div class="form-group">
                                                <label class="form-label">
                                                    <span class="form-label-icon">☕</span>
                                                    <?php p($l->t('Break End Time')); ?>
                                                </label>
                                                <div class="form-input-group">
                                                    <?php 
                                                    // Custom 24-hour time input - always shows 24h format
                                                    $breakEndValue = $break['end'] ?? '';
                                                    $breakEndParts = $breakEndValue ? explode(':', $breakEndValue) : ['', ''];
                                                    $breakEndHour = $breakEndParts[0] ?? '';
                                                    $breakEndMinute = $breakEndParts[1] ?? '';
                                                    ?>
                                                    <div class="time-input-group" data-time-input="break-end-<?php p((string)$index); ?>">
                                                        <select class="form-input time-hour break-end-time-hour" data-break-index="<?php p((string)$index); ?>" aria-label="<?php p($l->t('Break end hour')); ?>">
                                                            <option value="">--</option>
                                                            <?php for ($h = 0; $h < 24; $h++): ?>
                                                                <option value="<?php p(sprintf('%02d', $h)); ?>" <?php p(sprintf('%02d', $h) === $breakEndHour ? 'selected' : ''); ?>>
                                                                    <?php p(sprintf('%02d', $h)); ?>
                                                                </option>
                                                            <?php endfor; ?>
                                                        </select>
                                                        <span class="time-separator">:</span>
                                                        <select class="form-input time-minute break-end-time-minute" data-break-index="<?php p((string)$index); ?>" aria-label="<?php p($l->t('Break end minute')); ?>">
                                                            <option value="">--</option>
                                                            <?php for ($m = 0; $m < 60; $m += 1): ?>
<option value="<?php p(sprintf('%02d', $m)); ?>" <?php p(sprintf('%02d', $m) === $breakEndMinute ? 'selected' : ''); ?>>
                                                                <?php p(sprintf('%02d', $m)); ?>
                                                                </option>
                                                            <?php endfor; ?>
                                                        </select>
                                                        <input type="hidden" class="break-end-time" data-break-index="<?php p((string)$index); ?>" name="breaks[<?php p((string)$index); ?>][end]" value="<?php p($breakEndValue); ?>">
                                                    </div>
                                                    <?php if ($index > 0): ?>
                                                        <button type="button" class="btn btn--sm btn--danger btn-remove-break" data-break-index="<?php p((string)$index); ?>" title="<?php p($l->t('Remove break')); ?>">
                                                            <?php p($l->t('Remove')); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                                <p class="form-help"><?php p($l->t('Optional: When did your break end? Use automatic breaks for legal compliance.')); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="form-actions">
                                <button type="button" id="btn-add-break" class="btn btn--secondary btn--sm">
                                    <span class="form-label-icon">➕</span>
                                    <?php p($l->t('Add another break')); ?>
                                </button>
                            </div>
                        </div>

                        <!-- Description Section -->
                        <div class="form-section" role="group" aria-labelledby="description-section-title">
                            <h3 id="description-section-title" class="form-section-title"><?php p($l->t('Additional Information')); ?></h3>
                            <div class="form-group">
                                <label for="entry-description" id="entry-description-label" class="form-label">
                                    <span class="form-label-icon" aria-hidden="true">📝</span>
                                    <?php p($l->t('Description')); ?>
                                </label>
                                <textarea id="entry-description"
                                    name="description"
                                    class="form-textarea form-textarea--large"
                                    rows="6"
                                    aria-labelledby="entry-description-label"
                                    aria-describedby="entry-description-help"
                                    placeholder="<?php p($l->t('Optional: Add notes or details about this work period (e.g., project work, meetings, tasks)')); ?>"><?php p($entry ? ($entry->getDescription() ?? '') : ''); ?></textarea>
                                <p id="entry-description-help" class="form-help">
                                    <?php p($l->t('Optional: Add notes or details about this work period (e.g., project work, meetings, tasks)')); ?>
                                </p>
                            </div>
                        </div>

                        <div class="form-actions" role="group" aria-label="<?php p($l->t('Form actions')); ?>">
                            <button type="submit" 
                                    id="submit-button"
                                    class="btn btn--primary btn--lg"
                                    aria-describedby="submit-button-help">
                                <?php p($mode === 'create' ? $l->t('Create Entry') : $l->t('Update Entry')); ?>
                            </button>
                            <span id="submit-button-help" class="sr-only">
                                <?php p($mode === 'create'
                                    ? $l->t('Click to save your time entry. The form will be validated before saving.')
                                    : $l->t('Click to update your time entry. The form will be validated before saving.')); ?>
                            </span>
                            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>" 
                               class="btn btn--secondary btn--lg"
                               aria-label="<?php p($l->t('Cancel and return to time entries list')); ?>">
                                <?php p($l->t('Cancel')); ?>
                            </a>
                        </div>
                    </form>
                </div>
            </section>
        <?php else: ?>
            <section class="section" aria-label="<?php p($l->t('Time entries list')); ?>" role="region">
            <!-- Filter Section (initially hidden) -->
            <div id="filter-section" class="section filter-section" style="display: none;">
                <div class="form">
                    <div class="form-group">
                        <label for="filter-start-date" class="form-label"><?php p($l->t('Start Date')); ?></label>
                        <input type="text" id="filter-start-date" name="start_date" class="form-input datepicker-input" placeholder="dd.mm.yyyy" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly>
                    </div>
                    <div class="form-group">
                        <label for="filter-end-date" class="form-label"><?php p($l->t('End Date')); ?></label>
                        <input type="text" id="filter-end-date" name="end_date" class="form-input datepicker-input" placeholder="dd.mm.yyyy" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly>
                    </div>
                    <div class="form-group">
                        <label for="filter-status" class="form-label"><?php p($l->t('Status')); ?></label>
                        <select id="filter-status" name="status" class="form-select">
                            <option value=""><?php p($l->t('All')); ?></option>
                            <option value="active"><?php p($l->t('Active')); ?></option>
                            <option value="completed"><?php p($l->t('Completed')); ?></option>
                            <option value="pending_approval"><?php p($l->t('Pending Approval')); ?></option>
                        </select>
                    </div>
                    <div class="card-actions">
                        <button id="btn-apply-filter" class="btn btn--primary" type="button"><?php p($l->t('Apply')); ?></button>
                        <button id="btn-clear-filter" class="btn btn--secondary" type="button"><?php p($l->t('Clear')); ?></button>
                    </div>
                </div>
            </div>

            <!-- Time Entries Table -->
            <div class="section">
                <div class="table-container">
                    <table class="table table--hover" id="time-entries-table" role="table" aria-label="<?php p($l->t('Time entries list')); ?>">
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
                                <th scope="col"><?php p($l->t('Actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($entries)): ?>
                                <?php foreach ($entries as $entry): ?>
                                    <tr data-entry-id="<?php p($entry->getId()); ?>">
                                        <td><?php p($entry->getStartTime()->format('d.m.Y')); ?></td>
                                        <td><?php p($entry->getStartTime()->format('H:i')); ?></td>
                                        <td><?php
                                            if ($entry->getEndTime()) {
                                                $endTime = $entry->getEndTime();
                                                $startDate = $entry->getStartTime()->format('Y-m-d');
                                                $endDate = $endTime->format('Y-m-d');
                                                // Show date if end time is on a different day
                                                if ($startDate !== $endDate) {
                                                    p($endTime->format('d.m.Y H:i'));
                                                } else {
                                                    p($endTime->format('H:i'));
                                                }
                                            } else {
                                                p('-');
                                            }
                                            ?></td>
                                        <td><?php p(round($entry->getDurationHours() ?? 0, 2)); ?> h</td>
                                        <td>
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

                                                            // Only include breaks that are at least 15 minutes (ArbZG §4)
                                                            $breakDurationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();
                                                            $minBreakDurationSeconds = 900; // 15 minutes

                                                            if ($breakDurationSeconds >= $minBreakDurationSeconds) {
                                                                $breakTimes[] = $breakStart->format('H:i') . ' - ' . $breakEnd->format('H:i');
                                                            }
                                                        } catch (\Exception $e) {
                                                            // Skip invalid break times
                                                        }
                                                    }
                                                }
                                            }

                                            // Check for single break (breakStartTime/breakEndTime)
                                            if ($entry->getBreakStartTime() !== null && $entry->getBreakEndTime() !== null) {
                                                $breakStart = $entry->getBreakStartTime();
                                                $breakEnd = $entry->getBreakEndTime();

                                                // Only include breaks that are at least 15 minutes (ArbZG §4)
                                                $breakDurationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();
                                                $minBreakDurationSeconds = 900; // 15 minutes

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
                                        <td>
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
                                        <td class="description-cell">
                                            <?php p($entry->getDescription() ? substr($entry->getDescription(), 0, 50) : '-'); ?>
                                            <?php if ($entry->getDescription() && strlen($entry->getDescription()) > 50): ?>
                                                <span class="description-more">...</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge--<?php
                                                                        p(match ($entry->getStatus()) {
                                                                            'completed' => 'success',
                                                                            'active' => 'primary',
                                                                            'pending_approval' => 'warning',
                                                                            default => 'secondary'
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
                                        <td class="actions-cell">
                                            <?php
                                            // Show edit button for:
                                            // 1. Manual entries (not approved)
                                            // 2. Entries with pending approval
                                            // 3. Completed automatic entries (not yet approved)
                                            // 4. Only entries from the last 2 weeks (14 days) - for data integrity and compliance
                                            // Do NOT show if entry is already approved or older than 2 weeks
                                            $isApproved = $entry->getApprovedBy() !== null;
                                            $entryDate = $entry->getStartTime();
                                            $twoWeeksAgo = new \DateTime();
                                            $twoWeeksAgo->modify('-14 days');
                                            $twoWeeksAgo->setTime(0, 0, 0); // Start of day
                                            $isWithinTwoWeeks = $entryDate && $entryDate >= $twoWeeksAgo;

                                            $canEdit = !$isApproved && $isWithinTwoWeeks && (
                                                $entry->getIsManualEntry()
                                                || $entry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL
                                                || ($entry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED && !$entry->getIsManualEntry())
                                            );
                                            if ($canEdit):
                                            ?>
                                                <button class="btn btn--sm btn--secondary"
                                                    data-entry-id="<?php p($entry->getId()); ?>"
                                                    title="<?php p($l->t('Edit')); ?>"
                                                    type="button"
                                                    aria-label="<?php p($l->t('Edit time entry')); ?>">
                                                    <?php p($l->t('Edit')); ?>
                                                </button>
                                            <?php endif; ?>
                                            <?php
                                            // Only show delete button for manual entries
                                            $canDelete = $entry->getIsManualEntry();
                                            if ($canDelete):
                                            ?>
                                                <button class="btn btn--sm btn--danger btn-delete"
                                                    data-entry-id="<?php p($entry->getId()); ?>"
                                                    title="<?php p($l->t('Delete this time entry permanently. This cannot be undone.')); ?>"
                                                    type="button"
                                                    aria-label="<?php p($l->t('Delete this time entry permanently')); ?>">
                                                    <?php p($l->t('Delete')); ?>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="empty-state">
                                        <div class="empty-state">
                                            <h3 class="empty-state__title"><?php p($l->t('No time entries yet')); ?></h3>
                                            <p class="empty-state__description">
                                                <?php p($l->t('You haven\'t recorded any working time yet. Click the button below to add your first time entry, or use the clock in button on the dashboard to start tracking automatically.')); ?>
                                            </p>
                                            <button id="btn-add-first-entry"
                                                class="btn btn--primary"
                                                type="button"
                                                aria-label="<?php p($l->t('Add your first time entry')); ?>">
                                                <?php p($l->t('Add Your First Entry')); ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if (!empty($entries) && count($entries) > 0): ?>
                    <div class="pagination">
                        <button id="btn-prev-page" class="btn btn--secondary" type="button" disabled>
                            <?php p($l->t('Previous')); ?>
                        </button>
                        <span class="pagination-info">
                            <span id="current-page">1</span> / <span id="total-pages">1</span>
                        </span>
                        <button id="btn-next-page" class="btn btn--secondary" type="button" disabled>
                            <?php p($l->t('Next')); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            </section>
        <?php endif; ?>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<!-- Initialize JavaScript -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>" type="text/javascript">
    // Pass essential data to JS
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.page = 'time-entries';
    window.ArbeitszeitCheck.mode = <?php echo json_encode($mode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.entries = <?php echo json_encode(array_map(function ($entry) {
                                            return [
                                                'id' => $entry->getId(),
                                                'startTime' => $entry->getStartTime()->format('c'),
                                                'endTime' => $entry->getEndTime() ? $entry->getEndTime()->format('c') : null,
                                                'status' => $entry->getStatus(),
                                                'description' => $entry->getDescription()
                                            ];
                                        }, $entries), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // L10n strings
    window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
    window.ArbeitszeitCheck.l10n.confirmDelete = <?php echo json_encode($l->t('Are you sure you want to delete this time entry?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.confirmDeleteTimeEntry = <?php echo json_encode($l->t('Are you sure you want to delete this time entry?\n\nThis will permanently remove this record of your working time. This action cannot be undone.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.deleted = <?php echo json_encode($l->t('Time entry deleted successfully'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // API URLs
    window.ArbeitszeitCheck.apiUrl = {
        timeEntries: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiIndex'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        create: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiStore'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        update: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiUpdate', ['id' => '__ID__']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>.replace('__ID__', ''),
        delete: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiDelete', ['id' => '__ID__']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>.replace('__ID__', ''),
        export: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.export.timeEntries'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };

    // Escape HTML for safe injection (XSS prevention)
    const _escapeHtml = (typeof window.ArbeitszeitCheckUtils !== 'undefined' && window.ArbeitszeitCheckUtils.escapeHtml)
        ? window.ArbeitszeitCheckUtils.escapeHtml
        : function(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; };

    // Handle form submission for create/edit
    <?php if ($mode === 'create' || $mode === 'edit'): ?>

        /**
         * Time Entry Form Manager
         * Handles all functionality for the time entry form including:
         * - Time input synchronization
         * - Break management
         * - Time calculations
         * - Form validation
         * - Form submission
         */
        class TimeEntryFormManager {
            constructor() {
                this.form = document.getElementById('time-entry-form');
                this.startTimeHour = document.getElementById('entry-start-time-hour');
                this.startTimeMinute = document.getElementById('entry-start-time-minute');
                this.startTimeHidden = document.getElementById('entry-start-time');
                this.endTimeHour = document.getElementById('entry-end-time-hour');
                this.endTimeMinute = document.getElementById('entry-end-time-minute');
                this.endTimeHidden = document.getElementById('entry-end-time');
                this.breaksContainer = document.getElementById('breaks-container');
                this.btnAddBreak = document.getElementById('btn-add-break');
                this.dateInput = document.getElementById('entry-date');
                this.timeSummary = document.getElementById('time-summary');
                this.summaryWorkingHours = document.getElementById('summary-working-hours');
                this.summaryBreakTime = document.getElementById('summary-break-time');
                this.complianceStatus = document.getElementById('compliance-status');
                this.descriptionTextarea = document.getElementById('entry-description');
                this.breakRequirementIndicator = document.getElementById('break-requirement-indicator');
                this.breakRequirementText = document.getElementById('break-requirement-text');
                this.autoBreakToggle = document.getElementById('auto-break-enabled');

                this.breakIndex = <?php echo count($existingBreaks ?? []); ?>;
                this.formSubmitted = false;
                this.maxWorkingHours = <?php echo (float)($_['maxDailyHours'] ?? 10); ?>;
                this.maxBreaks = 10; // Prevent excessive break entries

                this.init();
            }

            init() {
                this.setupTimeInputs();
                this.setupBreakManagement();
                this.setupDateValidation();
                this.setupFormValidation();
                this.setupFormSubmission();

                // Initial summary update if form has values
                setTimeout(() => {
                    if (this.hasFormData()) {
                        this.updateTimeSummary();
                    }
                }, 100);
            }

            hasFormData() {
                return this.dateInput?.value &&
                       this.startTimeHidden?.value &&
                       this.endTimeHidden?.value;
            }

            setupTimeInputs() {
                // Main time inputs with auto-break calculation
                if (this.startTimeHour && this.startTimeMinute && this.startTimeHidden) {
                    this.bindTimeInputs(this.startTimeHour, this.startTimeMinute, this.startTimeHidden, true);
                }
                if (this.endTimeHour && this.endTimeMinute && this.endTimeHidden) {
                    this.bindTimeInputs(this.endTimeHour, this.endTimeMinute, this.endTimeHidden, true);
                }
            }

            bindTimeInputs(hourSelect, minuteSelect, hiddenInput, enableAutoBreak = false) {
                if (!hourSelect || !minuteSelect || !hiddenInput) return;

                // Prevent duplicate binding
                if (hourSelect.hasAttribute('data-bound')) return;
                hourSelect.setAttribute('data-bound', 'true');

                const updateValue = () => {
                    try {
                        // Validate inputs
                        const hour = hourSelect.value;
                        const minute = minuteSelect.value;

                        // Handle empty/invalid values
                        if (!hour || hour === '--' || !minute || minute === '--') {
                            hiddenInput.value = '';
                            return;
                        }

                        // Ensure valid hour (0-23) and minute (0-59)
                        const hourNum = parseInt(hour, 10);
                        const minuteNum = parseInt(minute, 10);

                        if (isNaN(hourNum) || hourNum < 0 || hourNum > 23 ||
                            isNaN(minuteNum) || minuteNum < 0 || minuteNum > 59) {
                            console.warn('Invalid time values:', hour, minute);
                            hiddenInput.value = '';
                            return;
                        }

                        hiddenInput.value = `${String(hourNum).padStart(2, '0')}:${String(minuteNum).padStart(2, '0')}`;

                        // Update summary and validate
                        this.updateTimeSummary();
                    } catch (error) {
                        console.error('Error updating time input:', error);
                        hiddenInput.value = '';
                    }
                };

                hourSelect.addEventListener('change', updateValue);
                minuteSelect.addEventListener('change', updateValue);

                // Initial update
                updateValue();
            }


                if (!this.hasFormData()) return;

                try {
                    const dateStr = this.convertDateFormat(this.dateInput.value);
                    if (!dateStr) return;

                    const startTime = this.startTimeHidden.value;
                    const endTime = this.endTimeHidden.value;
                    if (!startTime || !endTime || startTime === '00:00' || endTime === '00:00') return;

                    const startDateTime = new Date(dateStr + 'T' + startTime);
                    let endDateTime = new Date(dateStr + 'T' + endTime);

                    if (isNaN(startDateTime.getTime()) || isNaN(endDateTime.getTime())) return;

                    // Handle overnight work
                    if (endDateTime < startDateTime) {
                        endDateTime.setDate(endDateTime.getDate() + 1);
                    }

                    const workDurationMs = endDateTime - startDateTime;
                    const workDurationHours = workDurationMs / (1000 * 60 * 60);

                    // Calculate required break time based on German ArbZG
                    let requiredBreakMinutes = 0;
                    if (workDurationHours >= 9) {
                        requiredBreakMinutes = 45;
                    } else if (workDurationHours >= 6) {
                        requiredBreakMinutes = 30;
                    }
                    // Under 6 hours: no break required

                    if (requiredBreakMinutes === 0) {
                        // No breaks required - remove any auto-added breaks if work duration is short
                        this.removeAutoAddedBreaks();
                        return;
                    }

                    // Calculate existing break time
                    const existingBreakMinutes = this.calculateExistingBreakTime(dateStr);
                    const shortfallMinutes = Math.max(0, requiredBreakMinutes - existingBreakMinutes);

                    if (shortfallMinutes > 0) {
                        // Need to add automatic break
                        this.addAutomaticBreak(startDateTime, endDateTime, shortfallMinutes);
                    } else if (existingBreakMinutes > requiredBreakMinutes) {
                        // User has more breaks than required - this is fine, just update status
                        this.updateBreakRequirementIndicator(workDurationHours);
                    }

                } catch (error) {
                    console.warn('Error in auto-break calculation:', error);
                }
            }

                            }
                        } catch (error) {
                            console.warn('Error calculating existing break time:', error);
                        }
                    }
                });

                return totalBreakMinutes;
            }

                        // Add new auto-break
                        this.createAutoBreak(breakStartTime, breakMinutes);
                    }

                    // Show notification about auto-break addition
                    if (window.OC && OC.Notification) {
                        const breakText = breakMinutes === 45 ? '45 minutes' : '30 minutes';
                        OC.Notification.showTemporary(
                            `<?php echo addslashes($l->t('Automatic %s break added for legal compliance', ['%s'])); ?>`.replace('%s', breakText),
                            { type: 'info', timeout: 3000 }
                        );
                    }
                }
            }


                const breakEndTime = new Date(breakStartTime.getTime() + breakMinutes * 60 * 1000);
                if (breakEndTime > endDateTime) {
                    // If break would end after work, start it earlier
                    breakStartTime.setTime(endDateTime.getTime() - breakMinutes * 60 * 1000);
                }

                return breakStartTime;
            }

                }
                return null;
            }

                    endHourSelect.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }


                // Add a note below the auto-break
                const formGrid = breakEntry.querySelector('.form-grid');
                if (formGrid) {
                    const autoNote = document.createElement('p');
                    autoNote.className = 'form-help auto-break-note';
                    autoNote.innerHTML = '<small><?php echo addslashes($l->t('Automatically added for German labor law compliance (ArbZG §4)')); ?></small>';
                    formGrid.appendChild(autoNote);
                }

                this.breaksContainer.appendChild(breakEntry);

                // Update the break with calculated times
                this.updateAutoBreak(breakEntry, breakStartTime, breakMinutes);

                this.breakIndex++;
            }


                this.updateTimeSummary();
            }

            setupBreakManagement() {
                if (!this.btnAddBreak || !this.breaksContainer) return;

                this.btnAddBreak.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.addBreakEntry();
                });

                // Handle remove buttons
                this.breaksContainer.addEventListener('click', (e) => {
                    if (e.target.classList.contains('btn-remove-break')) {
                        e.preventDefault();
                        this.removeBreakEntry(e.target);
                    }
                });

                // Handle auto-break toggle
                if (this.autoBreakToggle) {
                    this.autoBreakToggle.addEventListener('change', () => {
                        if (this.autoBreakToggle.checked) {
                            // Re-enable auto-breaks - recalculate
                            this.handleAutoBreakCalculation();
                    } else {
                        // Disable auto-breaks - user can manually manage breaks
                        if (window.OC && OC.Notification) {
                            OC.Notification.showTemporary(
                                '<?php echo addslashes($l->t('Automatic break generation disabled')); ?>',
                                { type: 'info', timeout: 2000 }
                            );
                        }
                    }
                });
                }
            }

            addBreakEntry() {
                try {
                    // Prevent adding too many breaks (performance and usability)
                    const existingBreaks = this.breaksContainer ? this.breaksContainer.querySelectorAll('.break-entry') : [];
                    if (existingBreaks.length >= this.maxBreaks) {
                        if (window.OC && OC.Notification) {
                            OC.Notification.showTemporary(
                                '<?php echo addslashes($l->t('Maximum of %d breaks allowed', [$this->maxBreaks])); ?>'.replace('%d', this.maxBreaks),
                                { type: 'error', timeout: 3000 }
                            );
                        }
                        return;
                    }

                    const breakEntry = this.createBreakEntryElement(this.breakIndex);
                    this.breaksContainer.appendChild(breakEntry);

                    // Bind time inputs for the new break
                    this.bindBreakTimeInputs(breakEntry);

                    this.breakIndex++;
                    this.updateTimeSummary();
                } catch (error) {
                    console.error('Error adding break entry:', error);
                }
            }

            createBreakEntryElement(index) {
                const breakEntry = document.createElement('div');
                breakEntry.className = 'break-entry';
                breakEntry.setAttribute('data-break-index', index);

                const formGrid = document.createElement('div');
                formGrid.className = 'form-grid form-grid--2';

                // Start time group
                const startGroup = document.createElement('div');
                startGroup.className = 'form-group';

                const startLabel = document.createElement('label');
                startLabel.className = 'form-label';
                const startIcon = document.createElement('span');
                startIcon.className = 'form-label-icon';
                startIcon.textContent = '☕';
                startLabel.appendChild(startIcon);
                startLabel.appendChild(document.createTextNode('<?php p($l->t('Break Start Time')); ?>'));

                const startTimeGroup = document.createElement('div');
                startTimeGroup.className = 'time-input-group';

                const startHourSelect = document.createElement('select');
                startHourSelect.className = 'form-input time-hour break-start-time-hour';
                startHourSelect.setAttribute('data-break-index', index);
                startHourSelect.setAttribute('aria-label', '<?php p($l->t('Break start hour')); ?>');

                const startMinuteSelect = document.createElement('select');
                startMinuteSelect.className = 'form-input time-minute break-start-time-minute';
                startMinuteSelect.setAttribute('data-break-index', index);
                startMinuteSelect.setAttribute('aria-label', '<?php p($l->t('Break start minute')); ?>');

                const startHidden = document.createElement('input');
                startHidden.type = 'hidden';
                startHidden.className = 'break-start-time';
                startHidden.setAttribute('data-break-index', index);
                startHidden.name = `breaks[${index}][start]`;
                startHidden.value = '';

                // Add options
                this.addTimeOptions(startHourSelect, startMinuteSelect);

                const startSeparator = document.createElement('span');
                startSeparator.className = 'time-separator';
                startSeparator.textContent = ':';

                startTimeGroup.appendChild(startHourSelect);
                startTimeGroup.appendChild(startSeparator);
                startTimeGroup.appendChild(startMinuteSelect);
                startTimeGroup.appendChild(startHidden);

                const startHelp = document.createElement('p');
                startHelp.className = 'form-help';
                startHelp.textContent = '<?php p($l->t('Optional: When did your break start?')); ?>';

                startGroup.appendChild(startLabel);
                startGroup.appendChild(startTimeGroup);
                startGroup.appendChild(startHelp);

                // End time group
                const endGroup = document.createElement('div');
                endGroup.className = 'form-group';

                const endLabel = document.createElement('label');
                endLabel.className = 'form-label';
                const endIcon = document.createElement('span');
                endIcon.className = 'form-label-icon';
                endIcon.textContent = '☕';
                endLabel.appendChild(endIcon);
                endLabel.appendChild(document.createTextNode('<?php p($l->t('Break End Time')); ?>'));

                const endTimeGroup = document.createElement('div');
                endTimeGroup.className = 'time-input-group';

                const endHourSelect = document.createElement('select');
                endHourSelect.className = 'form-input time-hour break-end-time-hour';
                endHourSelect.setAttribute('data-break-index', index);
                endHourSelect.setAttribute('aria-label', '<?php p($l->t('Break end hour')); ?>');

                const endMinuteSelect = document.createElement('select');
                endMinuteSelect.className = 'form-input time-minute break-end-time-minute';
                endMinuteSelect.setAttribute('data-break-index', index);
                endMinuteSelect.setAttribute('aria-label', '<?php p($l->t('Break end minute')); ?>');

                const endHidden = document.createElement('input');
                endHidden.type = 'hidden';
                endHidden.className = 'break-end-time';
                endHidden.setAttribute('data-break-index', index);
                endHidden.name = `breaks[${index}][end]`;
                endHidden.value = '';

                // Add options
                this.addTimeOptions(endHourSelect, endMinuteSelect);

                const endSeparator = document.createElement('span');
                endSeparator.className = 'time-separator';
                endSeparator.textContent = ':';

                const endInputGroup = document.createElement('div');
                endInputGroup.className = 'form-input-group';

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn--sm btn--danger btn-remove-break';
                removeBtn.setAttribute('data-break-index', index);
                removeBtn.title = '<?php p($l->t('Remove break')); ?>';
                removeBtn.textContent = '<?php p($l->t('Remove')); ?>';

                endTimeGroup.appendChild(endHourSelect);
                endTimeGroup.appendChild(endSeparator);
                endTimeGroup.appendChild(endMinuteSelect);
                endTimeGroup.appendChild(endHidden);

                endInputGroup.appendChild(endTimeGroup);
                endInputGroup.appendChild(removeBtn);

                const endHelp = document.createElement('p');
                endHelp.className = 'form-help';
                endHelp.textContent = '<?php p($l->t('Optional: When did your break end?')); ?>';

                endGroup.appendChild(endLabel);
                endGroup.appendChild(endInputGroup);
                endGroup.appendChild(endHelp);

                formGrid.appendChild(startGroup);
                formGrid.appendChild(endGroup);
                breakEntry.appendChild(formGrid);

                return breakEntry;
            }

            addTimeOptions(hourSelect, minuteSelect) {
                // Empty option
                const emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = '--';

                hourSelect.appendChild(emptyOption.cloneNode(true));
                minuteSelect.appendChild(emptyOption.cloneNode(true));

                // Hours
                for (let h = 0; h < 24; h++) {
                    const option = document.createElement('option');
                    option.value = String(h).padStart(2, '0');
                    option.textContent = String(h).padStart(2, '0');
                    hourSelect.appendChild(option);
                }

                // Minutes
                for (let m = 0; m < 60; m++) {
                    const option = document.createElement('option');
                    option.value = String(m).padStart(2, '0');
                    option.textContent = String(m).padStart(2, '0');
                    minuteSelect.appendChild(option);
                }
            }

            bindBreakTimeInputs(breakEntry) {
                const index = breakEntry.getAttribute('data-break-index');
                if (!index) return;

                const startHour = breakEntry.querySelector('.break-start-time-hour');
                const startMinute = breakEntry.querySelector('.break-start-time-minute');
                const startHidden = breakEntry.querySelector(`input.break-start-time[data-break-index="${index}"]`);

                const endHour = breakEntry.querySelector('.break-end-time-hour');
                const endMinute = breakEntry.querySelector('.break-end-time-minute');
                const endHidden = breakEntry.querySelector(`input.break-end-time[data-break-index="${index}"]`);

                if (startHour && startMinute && startHidden) {
                    this.bindTimeInputs(startHour, startMinute, startHidden, false); // Don't trigger auto-break for break inputs
                }
                if (endHour && endMinute && endHidden) {
                    this.bindTimeInputs(endHour, endMinute, endHidden, false); // Don't trigger auto-break for break inputs
                }
            }

            removeBreakEntry(button) {
                const index = button.getAttribute('data-break-index');
                const breakEntry = this.breaksContainer.querySelector(`[data-break-index="${index}"]`);
                if (breakEntry) {
                    breakEntry.remove();
                    this.updateTimeSummary();
                    this.validateTimes();
                }
            }

            setupDateValidation() {
                if (!this.dateInput) return;

                this.dateInput.addEventListener('blur', () => {
                    this.validateDate();
                    this.updateTimeSummary();
                });
            }

            validateDate() {
                if (!this.dateInput) return true;

                const value = this.dateInput.value.trim();
                const errorContainer = document.getElementById('entry-date-error');

                // Clear previous error
                if (errorContainer) {
                    errorContainer.style.display = 'none';
                    errorContainer.textContent = '';
                }

                if (!value) {
                    this.showDateError('<?php echo addslashes($l->t('Date is required')); ?>');
                    return false;
                }

                const result = this.parseDate(value);
                if (!result.valid) {
                    const errorMsg = result.errors[0] || '<?php echo addslashes($l->t('Invalid date')); ?>';
                    this.showDateError(errorMsg);
                    return false;
                }

                // Valid date - try to fix format if needed
                if (result.date) {
                    // Prevent future dates (more than 1 day in the future to account for timezone differences)
                    const tomorrow = new Date();
                    tomorrow.setDate(tomorrow.getDate() + 1);

                    if (result.date > tomorrow) {
                        this.showDateError('<?php echo addslashes($l->t('Date cannot be in the future')); ?>');
                        return false;
                    }

                    // Prevent dates too far in the past (more than 1 year ago)
                    const oneYearAgo = new Date();
                    oneYearAgo.setFullYear(oneYearAgo.getFullYear() - 1);

                    if (result.date < oneYearAgo) {
                        this.showDateError('<?php echo addslashes($l->t('Date cannot be more than 1 year in the past')); ?>');
                        return false;
                    }

                    const day = String(result.date.getDate()).padStart(2, '0');
                    const month = String(result.date.getMonth() + 1).padStart(2, '0');
                    const year = result.date.getFullYear();
                    this.dateInput.value = `${day}.${month}.${year}`;
                }

                if (this.dateInput) {
                    this.dateInput.setAttribute('aria-invalid', 'false');
                    this.dateInput.classList.remove('form-input--error');
                }

                return true;
            }

            showDateError(message) {
                    const errorContainer = document.getElementById('entry-date-error');
                    if (errorContainer) {
                    errorContainer.style.display = 'block';
                    errorContainer.innerHTML = `<div class="form-error" role="alert"><span class="form-error__icon" aria-hidden="true">⚠️</span><div class="form-error__content"><strong>${_escapeHtml(String(message))}</strong></div></div>`;
                }
                if (this.dateInput) {
                    this.dateInput.setAttribute('aria-invalid', 'true');
                    this.dateInput.classList.add('form-input--error');
                    this.dateInput.setCustomValidity(message);
                }
            }

            parseDate(dateStr) {
                const result = { valid: false, date: null, errors: [] };

                if (!dateStr || typeof dateStr !== 'string') {
                    result.errors.push('Date is required');
                    return result;
                }

                // Try dd.mm.yyyy format
                const ddmmyyyyMatch = dateStr.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
                if (ddmmyyyyMatch) {
                    const day = parseInt(ddmmyyyyMatch[1], 10);
                    const month = parseInt(ddmmyyyyMatch[2], 10) - 1; // JS months are 0-based
                    const year = parseInt(ddmmyyyyMatch[3], 10);

                    const date = new Date(year, month, day);
                    if (date.getFullYear() === year && date.getMonth() === month && date.getDate() === day) {
                        result.valid = true;
                        result.date = date;
                        return result;
                    } else {
                        result.errors.push('Invalid date values');
                        return result;
                    }
                }

                // Try yyyy-mm-dd format
                const yyyymmddMatch = dateStr.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                if (yyyymmddMatch) {
                    const year = parseInt(yyyymmddMatch[1], 10);
                    const month = parseInt(yyyymmddMatch[2], 10) - 1;
                    const day = parseInt(yyyymmddMatch[3], 10);

                    const date = new Date(year, month, day);
                    if (date.getFullYear() === year && date.getMonth() === month && date.getDate() === day) {
                        result.valid = true;
                        result.date = date;
                        return result;
                        } else {
                        result.errors.push('Invalid date values');
                        return result;
                    }
                }

                result.errors.push('Date must be in format dd.mm.yyyy');
                return result;
            }

            updateTimeSummary() {
                if (!this.timeSummary || !this.hasFormData()) {
                    if (this.timeSummary) this.timeSummary.style.display = 'none';
                    return;
                }

                const dateStr = this.convertDateFormat(this.dateInput.value);
                if (!dateStr) {
                    if (this.timeSummary) this.timeSummary.style.display = 'none';
                    return;
                }

                const startTime = this.startTimeHidden.value;
                const endTime = this.endTimeHidden.value;
                if (!startTime || !endTime || startTime === '00:00' || endTime === '00:00') {
                    if (this.timeSummary) this.timeSummary.style.display = 'none';
                    return;
                }

                try {
                    const startDateTime = new Date(dateStr + 'T' + startTime);
                    let endDateTime = new Date(dateStr + 'T' + endTime);

                    // Validate Date objects
                    if (isNaN(startDateTime.getTime()) || isNaN(endDateTime.getTime())) {
                        console.warn('Invalid date/time values');
                        if (this.timeSummary) this.timeSummary.style.display = 'none';
                        return;
                    }

                    // Handle overnight work (end time next day)
                    if (endDateTime < startDateTime) {
                        endDateTime.setDate(endDateTime.getDate() + 1);
                    }

                    // Calculate breaks
                    const breaks = [];
                    const breakEntries = this.breaksContainer ? this.breaksContainer.querySelectorAll('.break-entry') : [];
                    const minBreakDurationMs = 15 * 60 * 1000;

                    breakEntries.forEach((breakEntry) => {
                        try {
                            const breakStartInput = breakEntry.querySelector('input.break-start-time');
                            const breakEndInput = breakEntry.querySelector('input.break-end-time');
                            if (breakStartInput && breakEndInput && breakStartInput.value && breakEndInput.value) {
                                const breakStart = new Date(dateStr + 'T' + breakStartInput.value);
                                let breakEnd = new Date(dateStr + 'T' + breakEndInput.value);

                                // Validate break Date objects
                                if (isNaN(breakStart.getTime()) || isNaN(breakEnd.getTime())) {
                                    return; // Skip invalid breaks
                                }

                                // Handle overnight breaks
                                if (breakEnd < breakStart) breakEnd.setDate(breakEnd.getDate() + 1);

                                const breakDurationMs = breakEnd - breakStart;

                                // Only include valid breaks (minimum 15 minutes)
                                if (breakDurationMs >= minBreakDurationMs && breakDurationMs > 0) {
                                    breaks.push({
                                        start: breakStart.toISOString(),
                                        end: breakEnd.toISOString()
                    });
                }
            }
                        } catch (breakError) {
                            console.warn('Error processing break:', breakError);
                            // Continue processing other breaks
                        }
                    });

                    // Calculate working duration
                    const totalDurationMs = endDateTime - startDateTime;

                    // Validate duration is positive
                    if (totalDurationMs <= 0) {
                        console.warn('Invalid work duration');
                        if (this.timeSummary) this.timeSummary.style.display = 'none';
                        return;
                    }

                    const totalDurationHours = totalDurationMs / (1000 * 60 * 60);

                    let breakDurationHours = 0;
                    breaks.forEach(breakTime => {
                        try {
                            const breakStart = new Date(breakTime.start);
                            const breakEnd = new Date(breakTime.end);

                            if (!isNaN(breakStart.getTime()) && !isNaN(breakEnd.getTime())) {
                                const breakMs = breakEnd - breakStart;
                                if (breakMs > 0) {
                                    breakDurationHours += breakMs / (1000 * 60 * 60);
                                }
                            }
                        } catch (breakCalcError) {
                            console.warn('Error calculating break duration:', breakCalcError);
                        }
                    });

                    // Ensure break time doesn't exceed work time
                    breakDurationHours = Math.min(breakDurationHours, totalDurationHours);
                    const workingDurationHours = Math.max(0, totalDurationHours - breakDurationHours);

                    // Update summary display
                    if (this.summaryWorkingHours) {
                        this.summaryWorkingHours.textContent = workingDurationHours.toFixed(2);
                    }
                    if (this.summaryBreakTime) {
                        this.summaryBreakTime.textContent = breakDurationHours.toFixed(2);
                    }
                    if (this.timeSummary) {
                        this.timeSummary.style.display = 'block';
                    }

                    // Update compliance status
                    this.updateComplianceStatus(workingDurationHours, breakDurationHours);

                    // Update break requirement indicator
                    this.updateBreakRequirementIndicator(workingDurationHours);

                } catch (error) {
                    console.warn('Error updating time summary:', error);
                    if (this.timeSummary) this.timeSummary.style.display = 'none';
                }
            }

            updateComplianceStatus(workingHours, breakHours) {
                if (!this.complianceStatus) return;

                const requiredBreakHours = workingHours > 9 ? 0.75 : (workingHours > 6 ? 0.5 : 0);
                const hasRequiredBreak = breakHours >= requiredBreakHours;

                let statusClass = 'compliant';
                let statusText = '';

                if (workingHours > this.maxWorkingHours) {
                    statusClass = 'violation';
                    statusText = '<?php echo addslashes($l->t('Working hours exceed legal maximum (ArbZG §3)')); ?>';
                } else if (workingHours >= 8 && workingHours <= this.maxWorkingHours) {
                    statusClass = 'warning';
                    statusText = '<?php echo addslashes($l->t('Approaching maximum working hours')); ?>';
                } else if (!hasRequiredBreak && requiredBreakHours > 0) {
                    // Check if we have auto-generated breaks that should fulfill requirements
                    const hasAutoBreak = this.breaksContainer ?
                        this.breaksContainer.querySelector('.break-entry[data-auto-break]') : false;

                    if (hasAutoBreak) {
                        // Auto-break exists but calculation shows insufficient breaks
                        // This shouldn't happen, but handle gracefully
                        statusClass = 'warning';
                        statusText = '<?php echo addslashes($l->t('Recalculating automatic break...')); ?>';
                        // Trigger recalculation
                        setTimeout(() => this.handleAutoBreakCalculation(), 100);
                    } else {
                        statusClass = 'warning';
                        statusText = '<?php echo addslashes($l->t('Break requirement not met (ArbZG §4)')); ?>';
                    }
                } else {
                    // Provide more specific compliance messages based on work duration
                    const hasAutoBreak = this.breaksContainer ?
                        this.breaksContainer.querySelector('.break-entry[data-auto-break]') : false;

                    if (workingHours < 6) {
                        statusText = '<?php echo addslashes($l->t('Short shift - no breaks required')); ?>';
                    } else if (workingHours >= 6 && workingHours < 9) {
                        statusText = hasAutoBreak
                            ? '<?php echo addslashes($l->t('Compliant - automatic 30 min break')); ?>'
                            : '<?php echo addslashes($l->t('Compliant - 30 min break provided')); ?>';
                    } else if (workingHours >= 9) {
                        statusText = hasAutoBreak
                            ? '<?php echo addslashes($l->t('Compliant - automatic 45 min break')); ?>'
                            : '<?php echo addslashes($l->t('Compliant - 45 min break provided')); ?>';
                    } else {
                        statusText = '<?php echo addslashes($l->t('Compliant with German labor law')); ?>';
                    }
                }

                this.complianceStatus.className = 'compliance-status ' + statusClass;
                this.complianceStatus.textContent = statusText;
                this.complianceStatus.setAttribute('aria-label', statusText);
            }

            convertDateFormat(dateStr) {
                if (!dateStr) return null;
                // Check if already in yyyy-mm-dd format
                if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
                    return dateStr;
                }
                // Convert dd.mm.yyyy to yyyy-mm-dd
                const parts = dateStr.split('.');
                if (parts.length === 3) {
                    const day = parts[0].padStart(2, '0');
                    const month = parts[1].padStart(2, '0');
                    const year = parts[2];
                    return `${year}-${month}-${day}`;
                }
                return dateStr;
            }

            setupFormValidation() {
                // No special setup needed - validation happens on change
            }

            validateTimes() {
                // Update break time hidden inputs before validation
                if (this.breaksContainer) {
                    const breakEntries = this.breaksContainer.querySelectorAll('.break-entry');
                        breakEntries.forEach((breakEntry) => {
                            const breakIndex = breakEntry.getAttribute('data-break-index');
                            if (breakIndex === null) return;
                            
                            const breakStartHour = breakEntry.querySelector('.break-start-time-hour');
                            const breakStartMinute = breakEntry.querySelector('.break-start-time-minute');
                            const breakStartHidden = breakEntry.querySelector('input.break-start-time[data-break-index="' + breakIndex + '"]');
                            
                            const breakEndHour = breakEntry.querySelector('.break-end-time-hour');
                            const breakEndMinute = breakEntry.querySelector('.break-end-time-minute');
                            const breakEndHidden = breakEntry.querySelector('input.break-end-time[data-break-index="' + breakIndex + '"]');
                            
                            if (breakStartHour && breakStartMinute && breakStartHidden) {
                                const startHour = breakStartHour.value || '';
                                const startMinute = breakStartMinute.value || '';
                                if (startHour && startMinute) {
                                    breakStartHidden.value = `${startHour}:${startMinute}`;
                                } else {
                                    breakStartHidden.value = '';
                                }
                            }
                            
                            if (breakEndHour && breakEndMinute && breakEndHidden) {
                                const endHour = breakEndHour.value || '';
                                const endMinute = breakEndMinute.value || '';
                                if (endHour && endMinute) {
                                    breakEndHidden.value = `${endHour}:${endMinute}`;
                                } else {
                                    breakEndHidden.value = '';
                                }
                            }
                        });
                }
            }

            setupFormSubmission() {
                if (!this.form) return;

                this.form.addEventListener('submit', (e) => {
                    e.preventDefault();

                    if (this.validateForm()) {
                        this.submitForm();
                    }
                });
            }

            validateForm() {
                // Prevent double submission
                if (this.formSubmitted) {
                    return false;
                }

                // If auto-breaks are enabled, ensure they're created before validation
                if (this.autoBreakToggle && this.autoBreakToggle.checked) {
                    // Ensure auto-breaks are in place
                    this.handleAutoBreakCalculation();
                    
                    // Clear any previous validation errors on break fields
                    const breakEntries = this.breaksContainer ? this.breaksContainer.querySelectorAll('.break-entry') : [];
                    breakEntries.forEach((breakEntry) => {
                        // Only clear validation on manual break entries (not auto-generated ones)
                        if (!breakEntry.hasAttribute('data-auto-break')) {
                            const startHour = breakEntry.querySelector('.break-start-time-hour');
                            const startMinute = breakEntry.querySelector('.break-start-time-minute');
                            const endHour = breakEntry.querySelector('.break-end-time-hour');
                            const endMinute = breakEntry.querySelector('.break-end-time-minute');
                            
                            [startHour, startMinute, endHour, endMinute].forEach((field) => {
                                if (field) {
                                    field.setCustomValidity('');
                                }
                            });
                        }
                    });
                }

                // Only validate the absolute minimum required fields
                let isValid = true;

                // Validate date
                if (!this.validateDate()) {
                    isValid = false;
                }

                // Validate start time (must have hour and minute)
                if (!this.startTimeHour?.value || !this.startTimeMinute?.value ||
                    this.startTimeHour.value === '--' || this.startTimeMinute.value === '--') {
                    if (this.startTimeHour) {
                        this.startTimeHour.setCustomValidity('<?php echo addslashes($l->t('Start time is required')); ?>');
                        this.startTimeHour.reportValidity();
                    }
                    isValid = false;
                }

                // Validate end time (must have hour and minute)
                if (!this.endTimeHour?.value || !this.endTimeMinute?.value ||
                    this.endTimeHour.value === '--' || this.endTimeMinute.value === '--') {
                    if (this.endTimeHour) {
                        this.endTimeHour.setCustomValidity('<?php echo addslashes($l->t('End time is required')); ?>');
                        this.endTimeHour.reportValidity();
                    }
                    isValid = false;
                }

                // That's it - no break validation required!
                // Auto-breaks will be calculated and added during submission if needed

                return isValid;
            }

            validateTimeInput(hiddenInput, hourSelect, minuteSelect, type) {
                if (!hiddenInput) {
                    console.warn(`Missing ${type} time hidden input`);
                    return false;
                }

                const value = hiddenInput.value;
                if (!value || value === '00:00') {
                    // Try to populate from select elements
                    if (hourSelect && minuteSelect &&
                        hourSelect.value && hourSelect.value !== '--' &&
                        minuteSelect.value && minuteSelect.value !== '--') {

                        const hour = hourSelect.value.padStart(2, '0');
                        const minute = minuteSelect.value.padStart(2, '0');
                        hiddenInput.value = `${hour}:${minute}`;
                        return true;
                            } else {
                        // Show validation error
                        const errorMsg = type === 'start'
                            ? '<?php echo addslashes($l->t('Start time is required')); ?>'
                            : '<?php echo addslashes($l->t('End time is required')); ?>';

                        if (hourSelect) {
                            hourSelect.setCustomValidity(errorMsg);
                            hourSelect.reportValidity();
                        }
                        return false;
                    }
                }

                return true;
            }


                    const breakIndex = breakEntry.getAttribute('data-break-index');
                    const startInput = breakEntry.querySelector(`input.break-start-time[data-break-index="${breakIndex}"]`);
                    const endInput = breakEntry.querySelector(`input.break-end-time[data-break-index="${breakIndex}"]`);

                    if (startInput && endInput) {
                        const startTime = startInput.value;
                        const endTime = endInput.value;

                        // If both times are provided, validate they form a valid break
                        if (startTime && endTime && startTime !== '00:00' && endTime !== '00:00') {
                            try {
                                const startDate = new Date('1970-01-01T' + startTime);
                                const endDate = new Date('1970-01-01T' + endTime);

                                if (endDate <= startDate) {
                                    // Handle overnight breaks
                                    endDate.setDate(endDate.getDate() + 1);
                                }

                                const durationMs = endDate - startDate;

                                // Check if breaks are required based on estimated work duration
                                // We need to estimate based on current form data
                                let workDurationHours = 0;
                                if (this.startTimeHidden?.value && this.endTimeHidden?.value) {
                                    try {
                                        const workStart = new Date('1970-01-01T' + this.startTimeHidden.value);
                                        let workEnd = new Date('1970-01-01T' + this.endTimeHidden.value);
                                        if (workEnd < workStart) workEnd.setDate(workEnd.getDate() + 1);
                                        workDurationHours = (workEnd - workStart) / (1000 * 60 * 60);
                                    } catch (e) {
                                        // If we can't calculate work duration, assume breaks might be required
                                        workDurationHours = 8; // Conservative estimate
                                    }
                                }

                                // Break requirements based on German labor law (ArbZG)
                                const breaksRequired = workDurationHours >= 6;
                                const minDurationMs = breaksRequired ? 15 * 60 * 1000 : 0; // 15 minutes if required, 0 if not

                                if (durationMs < minDurationMs) {
                                    const durationMinutes = Math.floor(durationMs / (1000 * 60));
                                    const startSelect = breakEntry.querySelector('.break-start-time-hour');
                                    if (startSelect) {
                                        if (breaksRequired) {
                                            startSelect.setCustomValidity('<?php echo addslashes($l->t('Break must be at least 15 minutes long')); ?>');
                        } else {
                                            startSelect.setCustomValidity('<?php echo addslashes($l->t('Breaks are not required for work periods under 6 hours')); ?>');
                                        }
                                        startSelect.reportValidity();
                                    }
                                    isValid = false;
                                }

                                // Additional validation: break should not be longer than work period
                                if (workDurationHours > 0 && durationMs > workDurationHours * 60 * 60 * 1000) {
                                    const startSelect = breakEntry.querySelector('.break-start-time-hour');
                                    if (startSelect) {
                                        startSelect.setCustomValidity('<?php echo addslashes($l->t('Break cannot be longer than work period')); ?>');
                                        startSelect.reportValidity();
                                    }
                                    isValid = false;
                                }

                            } catch (error) {
                                console.warn('Error validating break time:', error);
                                isValid = false;
                            }
                        }
                    }
                });

                return isValid;
            }


                    const workDurationMs = endDateTime - startDateTime;
                    const workDurationHours = workDurationMs / (1000 * 60 * 60);

                    if (workDurationMs <= 0) {
                        if (this.endTimeHour) {
                            this.endTimeHour.setCustomValidity('<?php echo addslashes($l->t('End time must be after start time')); ?>');
                            this.endTimeHour.reportValidity();
                        }
                        return false;
                    }

                    // Check for unreasonably short work periods (less than 15 minutes)
                    // This helps prevent accidental entries while still allowing legitimate short shifts
                    const minWorkDurationMs = 15 * 60 * 1000; // 15 minutes
                    if (workDurationMs < minWorkDurationMs) {
                        if (this.endTimeHour) {
                            this.endTimeHour.setCustomValidity('<?php echo addslashes($l->t('Work period must be at least 15 minutes')); ?>');
                            this.endTimeHour.reportValidity();
                        }
                        return false;
                    }

                    // Check for unreasonably long work periods (more than 16 hours)
                    const maxWorkDurationMs = 16 * 60 * 60 * 1000; // 16 hours
                    if (workDurationMs > maxWorkDurationMs) {
                        if (this.endTimeHour) {
                            this.endTimeHour.setCustomValidity('<?php echo addslashes($l->t('Work period cannot exceed 16 hours')); ?>');
                            this.endTimeHour.reportValidity();
                        }
                        return false;
                    }

                    // Check if breaks exceed work time
                    const breakEntries = this.breaksContainer ? this.breaksContainer.querySelectorAll('.break-entry') : [];
                    let totalBreakMs = 0;

                    breakEntries.forEach((breakEntry) => {
                        const breakStartInput = breakEntry.querySelector('input.break-start-time');
                        const breakEndInput = breakEntry.querySelector('input.break-end-time');

                        if (breakStartInput && breakEndInput && breakStartInput.value && breakEndInput.value) {
                            try {
                                const breakStart = new Date(dateStr + 'T' + breakStartInput.value);
                                let breakEnd = new Date(dateStr + 'T' + breakEndInput.value);
                                if (breakEnd < breakStart) breakEnd.setDate(breakEnd.getDate() + 1);
                                totalBreakMs += (breakEnd - breakStart);
                            } catch (error) {
                                // Skip invalid breaks
                            }
                        }
                    });

                    if (totalBreakMs >= workDurationMs) {
                        if (this.startTimeHour) {
                            this.startTimeHour.setCustomValidity('<?php echo addslashes($l->t('Total break time cannot exceed work time')); ?>');
                            this.startTimeHour.reportValidity();
                        }
                        return false;
                    }

                    // For part-time work (under 6 hours), warn if unnecessary breaks are added
                    if (workDurationHours < 6 && totalBreakMs > 0) {
                        console.info('Breaks added for short shift - this is allowed but not required');
                        // Don't fail validation, just log - breaks are optional for short shifts
                    }

                } catch (error) {
                    console.warn('Error validating work duration:', error);
                    return false;
                }

                return true;
            }

            updateBreakRequirementIndicator(workingHours) {
                if (!this.breakRequirementIndicator || !this.breakRequirementText) return;

                if (workingHours <= 0) {
                    this.breakRequirementIndicator.style.display = 'none';
                            return;
                        }

                let requirementText = '';
                if (workingHours < 6) {
                    requirementText = '<?php echo addslashes($l->t('No breaks required for shifts under 6 hours')); ?>';
                } else if (workingHours >= 6 && workingHours < 9) {
                    requirementText = '<?php echo addslashes($l->t('30 minutes break required (ArbZG §4)')); ?>';
                } else if (workingHours >= 9) {
                    requirementText = '<?php echo addslashes($l->t('45 minutes break required (ArbZG §4)')); ?>';
                }

                if (requirementText) {
                    this.breakRequirementText.textContent = requirementText;
                    this.breakRequirementIndicator.style.display = 'block';
                        } else {
                    this.breakRequirementIndicator.style.display = 'none';
                }
            }

                });

                // Clear validation on break inputs
                if (this.breaksContainer) {
                    const breakSelects = this.breaksContainer.querySelectorAll('select');
                    breakSelects.forEach(select => {
                        select.setCustomValidity('');
                    });
                }

                // Clear hidden input validation
                if (this.startTimeHidden) this.startTimeHidden.setCustomValidity('');
                if (this.endTimeHidden) this.endTimeHidden.setCustomValidity('');
            }

            async submitForm() {
                const submitBtn = this.form.querySelector('button[type="submit"]');
                const originalText = submitBtn ? submitBtn.textContent : '';

                try {
                    // First ensure all hidden inputs are up to date
                    this.updateAllHiddenInputs();

                    // Calculate and ensure auto-breaks are in place before submission
                    if (this.autoBreakToggle && this.autoBreakToggle.checked) {
                        this.ensureAutoBreaksForSubmission();
                    }

                    // Update hidden inputs again after auto-break calculation
                    this.updateAllHiddenInputs();

                    const formData = new FormData(this.form);
                    const dateInputValue = formData.get('date');
                    const startTimeValue = formData.get('startTime');
                    const endTimeValue = formData.get('endTime');

                    // Validate required fields exist
                    if (!dateInputValue || !startTimeValue || !endTimeValue) {
                        throw new Error('Missing required form fields');
                    }

                    // Validate date format
                    const date = this.convertDateFormat(dateInputValue.trim());
                    if (!date) {
                        throw new Error('Invalid date format');
                    }

                    // Validate time formats
                    const timeRegex = /^([01]\d|2[0-3]):([0-5]\d)$/;
                    if (!timeRegex.test(startTimeValue) || !timeRegex.test(endTimeValue)) {
                        throw new Error('Invalid time format');
                    }

                    // Prepare data for submission
                    const data = {
                        date: date,
                        startTime: startTimeValue,
                        endTime: endTimeValue,
                        description: (formData.get('description') || '').trim()
                    };

                    // Process breaks - include all valid breaks (both manual and auto-generated)
                    const breaks = [];
                    const breakEntries = this.breaksContainer ? this.breaksContainer.querySelectorAll('.break-entry') : [];

                    breakEntries.forEach((breakEntry) => {
                        const breakIndex = breakEntry.getAttribute('data-break-index');
                        const startValue = formData.get(`breaks[${breakIndex}][start]`);
                        const endValue = formData.get(`breaks[${breakIndex}][end]`);

                        // Include any break with valid start/end times
                        if (startValue && endValue &&
                            startValue !== '00:00' && endValue !== '00:00' &&
                            startValue !== '' && endValue !== '') {
                                    breaks.push({
                                start_time: startValue,
                                end_time: endValue
                                    });
                        }
                    });

                    if (breaks.length > 0) {
                        data.breaks = JSON.stringify(breaks);
                    }

                    // Set loading state
                    this.setSubmitLoadingState(submitBtn, true);

                    // Submit the form with timeout
                    <?php if ($mode === 'create'): ?>
                    const url = <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiStore'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    <?php else: ?>
                    const url = <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiUpdatePost', ['id' => $entry->getId()]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    <?php endif; ?>

                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 second timeout

                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'requesttoken': (typeof OC !== 'undefined' && OC.requestToken) || (document.querySelector('head') && document.querySelector('head').getAttribute('data-requesttoken')) || ''
                        },
                        body: JSON.stringify(data),
                        signal: controller.signal
                    });

                    clearTimeout(timeoutId);

                            if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }

                    const result = await response.json();

                    if (result.success) {
                        // Success - show message then redirect
                        const successMsg = result.message || '<?php echo addslashes($l->t('Time entry saved successfully')); ?>';
                        if (window.OC && OC.Notification) {
                            OC.Notification.showTemporary(successMsg, {
                                        type: 'success',
                                timeout: 3000
                            });
                        }

                        // Redirect after a short delay to show the success message
                        setTimeout(() => {
                            window.location.href = '<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>';
                        }, 1000);

                    } else {
                        // Server returned success=false
                        const errorMsg = result.message || '<?php echo addslashes($l->t('An error occurred while saving')); ?>';
                        this.showErrorNotification(errorMsg);
                        this.resetSubmitButton(submitBtn, originalText);
                    }

                } catch (error) {
                    console.error('Form submission error:', error);

                    let errorMsg;
                    if (error.name === 'AbortError') {
                        errorMsg = '<?php echo addslashes($l->t('Request timed out. Please try again.')); ?>';
                    } else if (error.message.includes('HTTP')) {
                        errorMsg = '<?php echo addslashes($l->t('Server error occurred. Please try again.')); ?>';
                    } else if (error.message.includes('required form fields')) {
                        errorMsg = '<?php echo addslashes($l->t('Please fill in all required fields (date, start time, end time)')); ?>';
                    } else if (error.message.includes('date format')) {
                        errorMsg = '<?php echo addslashes($l->t('Please enter a valid date')); ?>';
                    } else if (error.message.includes('time format')) {
                        errorMsg = '<?php echo addslashes($l->t('Please enter valid start and end times')); ?>';
                    } else {
                        errorMsg = '<?php echo addslashes($l->t('Network error occurred')); ?>';
                    }

                    this.showErrorNotification(errorMsg);
                    this.resetSubmitButton(submitBtn, originalText);
                }
            }

            updateAllHiddenInputs() {
                // Ensure all hidden time inputs are updated from their select elements
                if (this.startTimeHour && this.startTimeMinute && this.startTimeHidden) {
                    const startHour = this.startTimeHour.value || '00';
                    const startMinute = this.startTimeMinute.value || '00';
                    this.startTimeHidden.value = `${startHour}:${startMinute}`;
                }

                if (this.endTimeHour && this.endTimeMinute && this.endTimeHidden) {
                    const endHour = this.endTimeHour.value || '00';
                    const endMinute = this.endTimeMinute.value || '00';
                    this.endTimeHidden.value = `${endHour}:${endMinute}`;
                }

                // Update break hidden inputs
                if (this.breaksContainer) {
                    const breakEntries = this.breaksContainer.querySelectorAll('.break-entry');
                    breakEntries.forEach((breakEntry) => {
                        const breakIndex = breakEntry.getAttribute('data-break-index');
                        if (!breakIndex) return;

                        const startHour = breakEntry.querySelector('.break-start-time-hour');
                        const startMinute = breakEntry.querySelector('.break-start-time-minute');
                        const startHidden = breakEntry.querySelector(`input.break-start-time[data-break-index="${breakIndex}"]`);

                        const endHour = breakEntry.querySelector('.break-end-time-hour');
                        const endMinute = breakEntry.querySelector('.break-end-time-minute');
                        const endHidden = breakEntry.querySelector(`input.break-end-time[data-break-index="${breakIndex}"]`);

                        if (startHour && startMinute && startHidden) {
                            const hour = startHour.value || '00';
                            const minute = startMinute.value || '00';
                            startHidden.value = `${hour}:${minute}`;
                        }

                        if (endHour && endMinute && endHidden) {
                            const hour = endHour.value || '00';
                            const minute = endMinute.value || '00';
                            endHidden.value = `${hour}:${minute}`;
                        }
                    });
                }
            }

            ensureAutoBreaksForSubmission() {
                // Calculate required breaks and ensure they exist before submission
                if (!this.hasFormData()) return;

                const dateStr = this.convertDateFormat(this.dateInput.value);
                if (!dateStr) return;

                const startTime = this.startTimeHidden.value;
                const endTime = this.endTimeHidden.value;
                if (!startTime || !endTime || startTime === '00:00' || endTime === '00:00') return;

                try {
                    const startDateTime = new Date(dateStr + 'T' + startTime);
                    let endDateTime = new Date(dateStr + 'T' + endTime);

                    if (isNaN(startDateTime.getTime()) || isNaN(endDateTime.getTime())) return;

                    // Handle overnight work
                    if (endDateTime < startDateTime) {
                        endDateTime.setDate(endDateTime.getDate() + 1);
                    }

                    const workDurationMs = endDateTime - startDateTime;
                    const workDurationHours = workDurationMs / (1000 * 60 * 60);

                    // Calculate required break time
                    let requiredBreakMinutes = 0;
                    if (workDurationHours >= 9) {
                        requiredBreakMinutes = 45;
                    } else if (workDurationHours >= 6) {
                        requiredBreakMinutes = 30;
                    }

                    if (requiredBreakMinutes === 0) {
                        // No breaks required - remove any auto-generated breaks
                        this.removeAutoAddedBreaks();
                        return;
                    }

                    // Check if we already have sufficient breaks
                    const existingBreakMinutes = this.calculateExistingBreakTime(dateStr);
                    if (existingBreakMinutes >= requiredBreakMinutes) {
                        // Already have sufficient breaks
                        return;
                    }

                    // Need to add auto-break
                    const breakStartTime = this.calculateOptimalBreakTime(startDateTime, endDateTime, requiredBreakMinutes);
                    if (breakStartTime) {
                        this.createAutoBreakForSubmission(breakStartTime, requiredBreakMinutes);
                    }

                } catch (error) {
                    console.warn('Error ensuring auto-breaks for submission:', error);
                }
            }

            createAutoBreakForSubmission(breakStartTime, breakMinutes) {
                // Create a break entry specifically for submission (simplified version)
                const breakIndex = this.breakIndex;

                // Find first empty break entry to populate, or create new one
                let targetBreakEntry = null;
                if (this.breaksContainer) {
                    const breakEntries = this.breaksContainer.querySelectorAll('.break-entry');
                    for (const entry of breakEntries) {
                        const startHidden = entry.querySelector('input.break-start-time');
                        const endHidden = entry.querySelector('input.break-end-time');
                        if (startHidden && endHidden &&
                            (!startHidden.value || startHidden.value === '00:00' || startHidden.value === '')) {
                            // Found empty break entry
                            targetBreakEntry = entry;
                            break;
                        }
                    }
                }

                if (!targetBreakEntry) {
                    // Create new break entry
                    targetBreakEntry = this.createBreakEntryElement(breakIndex);
                    if (this.breaksContainer) {
                        this.breaksContainer.appendChild(targetBreakEntry);
                    }
                    this.breakIndex++;
                }

                // Populate with auto-break times
                const breakIndexAttr = targetBreakEntry.getAttribute('data-break-index');
                const startHidden = targetBreakEntry.querySelector(`input.break-start-time[data-break-index="${breakIndexAttr}"]`);
                const endHidden = targetBreakEntry.querySelector(`input.break-end-time[data-break-index="${breakIndexAttr}"]`);

                if (startHidden && endHidden) {
                    const startHour = String(breakStartTime.getHours()).padStart(2, '0');
                    const startMinute = String(breakStartTime.getMinutes()).padStart(2, '0');
                    startHidden.value = `${startHour}:${startMinute}`;

                    const endTime = new Date(breakStartTime.getTime() + breakMinutes * 60 * 1000);
                    const endHour = String(endTime.getHours()).padStart(2, '0');
                    const endMinute = String(endTime.getMinutes()).padStart(2, '0');
                    endHidden.value = `${endHour}:${endMinute}`;

                    // Mark as auto-generated
                    targetBreakEntry.setAttribute('data-auto-break', 'true');
                }
            }

            setSubmitLoadingState(submitBtn, loading) {
                if (!submitBtn) return;

                this.formSubmitted = loading;

                if (loading) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = '<?php echo addslashes($l->t('Submitting...')); ?>';
                    submitBtn.setAttribute('aria-busy', 'true');
                } else {
                                submitBtn.disabled = false;
                    submitBtn.textContent = '<?php echo addslashes($l->t('Submit')); ?>';
                                submitBtn.removeAttribute('aria-busy');
                }
            }

            resetSubmitButton(submitBtn, originalText) {
                this.setSubmitLoadingState(submitBtn, false);
                if (submitBtn && originalText) {
                                submitBtn.textContent = originalText;
                }
            }

            showErrorNotification(message) {
                if (window.OC && OC.Notification) {
                    OC.Notification.showTemporary(message, {
                        type: 'error',
                        timeout: 5000
                    });
                } else {
                    alert(message);
                }
            }
        }

        // Initialize the form manager when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Only initialize if the form exists (we're on the right page)
            if (document.getElementById('time-entry-form')) {
                try {
                    new TimeEntryFormManager();
                } catch (error) {
                    console.error('Failed to initialize TimeEntryFormManager:', error);
                    // Show user-friendly error
                    const form = document.getElementById('time-entry-form');
                    if (form) {
                        const errorMsg = document.createElement('div');
                        errorMsg.className = 'form-error';
                        errorMsg.innerHTML = '<strong><?php echo addslashes($l->t('Form initialization failed. Please refresh the page.')); ?></strong>';
                        form.insertBefore(errorMsg, form.firstChild);
                    }
                }
            }
        });

    <?php endif; ?>
</script>
