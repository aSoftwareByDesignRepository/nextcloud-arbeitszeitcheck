<?php

declare(strict_types=1);

/**
 * Dashboard template for arbeitszeitcheck app
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
Util::addStyle('arbeitszeitcheck', 'dashboard');
Util::addScript('arbeitszeitcheck', 'common/utils');
Util::addScript('arbeitszeitcheck', 'arbeitszeitcheck-main');

$status = $_['status'] ?? [];
$overtime = $_['overtime'] ?? [];
$recentEntries = $_['recentEntries'] ?? [];
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);

// Current session duration calculation for display
$currentSessionDuration = $status['current_session_duration'] ?? 0;
$hours = floor($currentSessionDuration / 3600);
$minutes = floor(($currentSessionDuration % 3600) / 60);
$seconds = $currentSessionDuration % 60;
$durationFormatted = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);

// Break duration calculation for display (if on break)
$breakDurationFormatted = '00:00:00';
$breakStartTime = null;
if (($status['status'] ?? 'clocked_out') === 'break' && !empty($status['current_entry']['breakStartTime'])) {
    try {
        $breakStartTime = new \DateTime($status['current_entry']['breakStartTime']);
        $now = new \DateTime();
        $breakDuration = $now->getTimestamp() - $breakStartTime->getTimestamp();
        $breakHours = floor($breakDuration / 3600);
        $breakMinutes = floor(($breakDuration % 3600) / 60);
        $breakSeconds = $breakDuration % 60;
        $breakDurationFormatted = sprintf('%02d:%02d:%02d', $breakHours, $breakMinutes, $breakSeconds);
    } catch (\Throwable $e) {
        $breakStartTime = null;
    }
}
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb-container">
            <nav class="breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
                <ol>
                    <li aria-current="page"><?php p($l->t('Dashboard')); ?></li>
                </ol>
            </nav>
        </div>

        <!-- Page Header -->
        <div class="section page-header-section">
            <div class="header-content">
                <div class="header-text">
                    <h2><?php p($l->t('Dashboard')); ?></h2>
                    <p><?php p($l->t('See your current work status, today\'s hours, and recent time entries')); ?></p>
                </div>
            </div>
        </div>

        <!-- Welcome Message for First-Time Users -->
        <?php if (($_['isFirstTimeUser'] ?? false) === true): ?>
            <div class="section">
                <div class="card alert alert--info" role="dialog" aria-modal="true" aria-labelledby="welcome-title">
                    <div class="card-header">
                        <h3 id="welcome-title" class="card-title">
                            <span class="alert-icon" aria-hidden="true">👋</span>
                            <?php p($l->t('Welcome to Time Tracking!')); ?>
                        </h3>
                    </div>
                    <div class="card-body">
                        <p class="alert-message">
                            <?php p($l->t('This app helps you record your work time and follow German labor law. Here\'s how to get started:')); ?>
                        </p>
                        <ol class="welcome-steps">
                            <li>
                                <?php p($l->t('Click the "Clock In" button below when you start work')); ?>
                            </li>
                            <li>
                                <?php p($l->t('Click "Clock Out" when you finish work')); ?>
                            </li>
                            <li>
                                <?php p($l->t('The system will automatically track your hours and remind you to take breaks')); ?>
                            </li>
                            <li>
                                <?php p($l->t('You can also add time entries manually or request vacation days in the "Absences" section')); ?>
                            </li>
                        </ol>
                        <div class="card-actions">
                            <a href="<?php print_unescaped($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
                                class="btn btn--primary"
                                aria-label="<?php p($l->t('Go to time entries to see how to add entries manually')); ?>"
                                title="<?php p($l->t('Click to learn more about adding time entries manually')); ?>">
                                <?php p($l->t('Learn More About Time Entries')); ?>
                            </a>
                            <button type="button"
                                class="btn btn--secondary"
                                id="dismiss-welcome"
                                aria-label="<?php p($l->t('Dismiss this welcome message')); ?>"
                                title="<?php p($l->t('Click to hide this welcome message')); ?>">
                                <?php p($l->t('Got it, thanks!')); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Dashboard Grid -->
        <section class="section" aria-labelledby="dashboard-status-heading" aria-label="<?php p($l->t('Current status and today\'s stats')); ?>">
            <div class="arbeitszeitcheck-dashboard__grid">
                <!-- Status Card -->
                <?php
                $statusKey = $status['status'] ?? 'clocked_out';
                $statusKeySafe = in_array($statusKey, ['active', 'break', 'clocked_out'], true) ? $statusKey : 'clocked_out';
                $statusBadgeVariant = match ($statusKeySafe) {
                    'active' => 'success',
                    'break' => 'warning',
                    default => 'secondary',
                };
                $statusLabel = match ($statusKeySafe) {
                    'active' => $l->t('Clocked In'),
                    'break' => $l->t('On Break'),
                    default => $l->t('Clocked Out'),
                };
                $statusIcon = match ($statusKeySafe) {
                    'active' => '⏱',
                    'break' => '☕',
                    default => '⏸',
                };
                $startedAt = null;
                if (!empty($status['current_entry']['startTime'])) {
                    try {
                        // Get user timezone
                        $userTimezone = \OCP\Server::get(\OCP\IDateTimeZone::class)->getTimeZone();
                        $startTime = new \DateTime($status['current_entry']['startTime']);
                        $startTime->setTimezone($userTimezone);
                        $startedAt = $startTime->format('H:i');
                    } catch (\Throwable $e) {
                        $startedAt = null;
                    }
                }
                ?>
                <div class="card dashboard-status-card dashboard-status-card--<?php p($statusKeySafe); ?>">
                    <div class="dashboard-status-card__header">
                        <div class="dashboard-status-card__title">
                            <span class="dashboard-status-card__icon" aria-hidden="true"><?php p($statusIcon); ?></span>
                            <h3 id="dashboard-status-heading" class="card-title"><?php p($l->t('Current Status')); ?></h3>
                        </div>
                        <div class="badge badge--<?php p($statusBadgeVariant); ?>">
                            <?php p($statusLabel); ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (($status['status'] ?? 'clocked_out') !== 'clocked_out'): ?>
                            <?php if (($status['status'] ?? 'clocked_out') === 'break'): ?>
                                <!-- Break Timer (shown when on break) -->
                                <div class="break-timer dashboard-status-card__timer" data-break-start-time="<?php p($status['current_entry']['breakStartTime'] ?? ''); ?>">
                                    <span class="timer-label"><?php p($l->t('Break Time:')); ?></span>
                                    <span class="timer-value" id="break-timer-value"><?php p($breakDurationFormatted); ?></span>
                                    <?php if ($breakStartTime !== null): ?>
                                        <div class="dashboard-status-card__meta">
                                            <?php
                                            try {
                                                // Get user timezone
                                                $userTimezone = \OCP\Server::get(\OCP\IDateTimeZone::class)->getTimeZone();
                                                $breakStartTime->setTimezone($userTimezone);
                                                p($l->t('Break started at')); ?> <?php p($breakStartTime->format('H:i'));
                                                                                } catch (\Throwable $e) {
                                                                                    p($l->t('Break started at')); ?> <?php p($breakStartTime->format('H:i'));
                                                                                }
                                                                                    ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <!-- Working Time (shown when on break, but paused) -->
                                <div class="session-timer dashboard-status-card__timer dashboard-status-card__timer--paused" data-start-time="<?php p($status['current_entry']['startTime'] ?? ''); ?>" style="opacity: 0.6; margin-top: 1rem;">
                                    <span class="timer-label"><?php p($l->t('Working Time:')); ?></span>
                                    <span class="timer-value" id="session-timer-value"><?php p($durationFormatted); ?></span>
                                </div>
                            <?php else: ?>
                                <!-- Working Timer (shown when active) -->
                                <div class="session-timer dashboard-status-card__timer" data-start-time="<?php p($status['current_entry']['startTime'] ?? ''); ?>">
                                    <span class="timer-label"><?php p($l->t('Current Session:')); ?></span>
                                    <span class="timer-value" id="session-timer-value"><?php p($durationFormatted); ?></span>
                                    <?php if ($startedAt !== null): ?>
                                        <div class="dashboard-status-card__meta">
                                            <?php p($l->t('Started at')); ?> <?php p($startedAt); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="card-actions" role="group" aria-label="<?php p($l->t('Time tracking actions')); ?>">
                            <?php if (($status['status'] ?? 'clocked_out') === 'clocked_out' || ($status['status'] ?? 'clocked_out') === 'paused'): ?>
                                <button id="btn-clock-in"
                                    class="btn btn--primary"
                                    type="button"
                                    aria-label="<?php p($l->t('Clock in to start tracking your working time')); ?>"
                                    title="<?php p($l->t('Click to clock in and start tracking your working time')); ?>">
                                    <?php p($l->t('Clock In')); ?>
                                </button>
                            <?php elseif (($status['status'] ?? 'clocked_out') === 'active'): ?>
                                <button id="btn-start-break"
                                    class="btn btn--secondary"
                                    type="button"
                                    aria-label="<?php p($l->t('Start a break from work')); ?>"
                                    title="<?php p($l->t('Click to start a break. You must take breaks according to German labor law.')); ?>">
                                    <?php p($l->t('Start Break')); ?>
                                </button>
                                <button class="btn btn--danger btn-clock-out"
                                    type="button"
                                    aria-label="<?php p($l->t('Clock out to end your working day')); ?>"
                                    title="<?php p($l->t('Click to clock out and end your working time for today')); ?>">
                                    <?php p($l->t('Clock Out')); ?>
                                </button>
                            <?php elseif (($status['status'] ?? 'clocked_out') === 'break'): ?>
                                <button id="btn-end-break"
                                    class="btn btn--primary"
                                    type="button"
                                    aria-label="<?php p($l->t('End your break and return to work')); ?>"
                                    title="<?php p($l->t('Click to end your break and continue working')); ?>">
                                    <?php p($l->t('End Break')); ?>
                                </button>
                                <button class="btn btn--danger btn-clock-out"
                                    type="button"
                                    aria-label="<?php p($l->t('Clock out to end your working day')); ?>"
                                    title="<?php p($l->t('Click to clock out and end your working time for today')); ?>">
                                    <?php p($l->t('Clock Out')); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Stats Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><?php p($l->t('Today\'s Stats')); ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="stat-item">
                            <span class="stat-label"><?php p($l->t('Worked Today:')); ?></span>
                            <span class="stat-value"><?php p(round($status['working_today_hours'] ?? 0, 2)); ?> <?php p($l->t('hours')); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label"><?php p($l->t('Overtime Balance:')); ?></span>
                            <span class="stat-value <?php echo ($overtime['cumulative_balance'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                                <?php p(round($overtime['cumulative_balance'] ?? 0, 2)); ?> <?php p($l->t('hours')); ?>
                            </span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label"><?php p($l->t('This Week:')); ?></span>
                            <span class="stat-value"><?php p(round($overtime['total_hours_worked'] ?? 0, 2)); ?> <?php p($l->t('hours')); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Recent Entries Section -->
        <section class="section" aria-labelledby="recent-entries-heading">
            <div class="section-header">
                <h3 id="recent-entries-heading"><?php p($l->t('Recent Entries')); ?></h3>
                <a href="<?php print_unescaped($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
                    class="btn btn--secondary">
                    <?php p($l->t('View All')); ?>
                </a>
            </div>

            <div class="table-container" role="region" aria-label="<?php p($l->t('Recent time entries')); ?>">
                <table class="table table--hover" role="table" aria-label="<?php p($l->t('Recent time entries')); ?>">
                    <thead>
                        <tr>
                            <th scope="col"><?php p($l->t('Date')); ?></th>
                            <th scope="col"><?php p($l->t('Start')); ?></th>
                            <th scope="col"><?php p($l->t('End')); ?></th>
                            <th scope="col"><?php p($l->t('Duration')); ?></th>
                            <th scope="col"><?php p($l->t('Break')); ?></th>
                            <th scope="col"><?php p($l->t('Status')); ?></th>
                            <th scope="col"><?php p($l->t('Actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentEntries)): ?>
                            <?php
                            // Get user timezone once for all entries
                            try {
                                $userTimezone = \OCP\Server::get(\OCP\IDateTimeZone::class)->getTimeZone();
                            } catch (\Throwable $e) {
                                $userTimezone = new \DateTimeZone(date_default_timezone_get());
                            }
                            foreach ($recentEntries as $entry):
                                // Convert times to user timezone
                                $startTime = clone $entry->getStartTime();
                                $startTime->setTimezone($userTimezone);
                                $endTime = $entry->getEndTime() ? clone $entry->getEndTime() : null;
                                if ($endTime) {
                                    $endTime->setTimezone($userTimezone);
                                }
                            ?>
                                <tr>
                                    <td><?php p($startTime->format('d.m.Y')); ?></td>
                                    <td><?php p($startTime->format('H:i')); ?></td>
                                    <td><?php
                                        if ($endTime) {
                                            $startDate = $startTime->format('Y-m-d');
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
                                                        $breakStart->setTimezone($userTimezone);
                                                        $breakEnd = new \DateTime($break['end']);
                                                        $breakEnd->setTimezone($userTimezone);
                                                        $breakTimes[] = $breakStart->format('H:i') . ' - ' . $breakEnd->format('H:i');
                                                    } catch (\Exception $e) {
                                                        // Skip invalid break times
                                                    }
                                                }
                                            }
                                        }

                                        // Check for single break (breakStartTime/breakEndTime)
                                        if ($entry->getBreakStartTime() !== null && $entry->getBreakEndTime() !== null) {
                                            $breakStart = clone $entry->getBreakStartTime();
                                            $breakStart->setTimezone($userTimezone);
                                            $breakEnd = clone $entry->getBreakEndTime();
                                            $breakEnd->setTimezone($userTimezone);

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
                                    <td>
                                        <?php
                                        // Show edit button only if entry can be edited
                                        // Same logic as in time-entries.php:
                                        // 1. Manual entries (not approved)
                                        // 2. Entries with pending approval
                                        // 3. Completed automatic entries (not yet approved)
                                        // 4. Only entries from the last 2 weeks (14 days) - for data integrity and compliance
                                        // Do NOT show if entry is already approved or older than 2 weeks
                                        $isApproved = $entry->getApprovedBy() !== null;
                                        $entryDate = $entry->getStartTime();
                                        $editCutoff = new \DateTime();
                                        $editCutoff->modify('-' . \OCA\ArbeitszeitCheck\Constants::EDIT_WINDOW_DAYS . ' days');
                                        $editCutoff->setTime(0, 0, 0);
                                        $isWithinEditWindow = $entryDate && $entryDate >= $editCutoff;

                                        $canEdit = !$isApproved && $isWithinEditWindow && (
                                            $entry->getIsManualEntry()
                                            || $entry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL
                                            || ($entry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED && !$entry->getIsManualEntry())
                                        );
                                        if ($canEdit):
                                        ?>
                                            <button class="btn btn--sm btn--secondary"
                                                data-entry-id="<?php p($entry->getId()); ?>"
                                                type="button"
                                                aria-label="<?php p($l->t('Edit this time entry')); ?>"
                                                title="<?php p($l->t('Click to edit this time entry')); ?>">
                                                <?php p($l->t('Edit')); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="empty-state">
                                    <div class="empty-state">
                                        <p><?php p($l->t('No recent entries found')); ?></p>
                                        <p class="empty-state-description">
                                            <?php p($l->t('Your recent time entries will appear here. Start by clocking in to track your working time.')); ?>
                                        </p>
                                        <a href="<?php print_unescaped($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
                                            class="btn btn--primary">
                                            <?php p($l->t('View All Time Entries')); ?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<!-- Initialize JavaScript -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    // Pass essential data to JS
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.status = <?php echo json_encode($status, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.overtime = <?php echo json_encode($overtime, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.page = 'dashboard';

    // L10n strings
    window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
    window.ArbeitszeitCheck.l10n.clockIn = <?php echo json_encode($l->t('Clock In'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.clockOut = <?php echo json_encode($l->t('Clock Out'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.startBreak = <?php echo json_encode($l->t('Start Break'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.endBreak = <?php echo json_encode($l->t('End Break'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // API URLs
    window.ArbeitszeitCheck.apiUrl = {
        clockIn: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_tracking.clockIn'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        clockOut: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_tracking.clockOut'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        startBreak: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_tracking.startBreak'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        endBreak: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_tracking.endBreak'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        status: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_tracking.getStatus'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };

    // Handle welcome message dismissal
    const dismissWelcomeBtn = document.getElementById('dismiss-welcome');
    if (dismissWelcomeBtn) {
        dismissWelcomeBtn.addEventListener('click', function() {
            const welcomeCard = this.closest('.card');
            if (welcomeCard) {
                welcomeCard.style.display = 'none';
                // Store dismissal in localStorage
                try {
                    localStorage.setItem('arbeitszeitcheck-welcome-dismissed', 'true');
                } catch (e) {
                    // Ignore localStorage errors
                }
            }
        });
    }
</script>