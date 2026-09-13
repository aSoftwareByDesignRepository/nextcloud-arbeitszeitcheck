<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Service\IconCatalog;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;

/**
 * Dashboard template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCP\Util;

/** @var array $_ */
/** @var \OCP\IL10N $l */

// Add page-specific styles handled by PageController::registerFrontEndAssets

$status = $_['status'] ?? [];
$overtime = $_['overtime'] ?? [];
$weekOvertime = is_array($_['weekOvertime'] ?? null) ? $_['weekOvertime'] : [];
$overtimeBank = is_array($_['overtimeBank'] ?? null) ? $_['overtimeBank'] : ['enabled' => false];
$overtimeTrafficLight = is_array($_['overtimeTrafficLight'] ?? null) ? $_['overtimeTrafficLight'] : ['enabled' => false, 'state' => 'green'];
try {
	$azcMaxDefault = (string)\OCP\Server::get(LaborLawProfileFactory::class)->getProfileForCurrentUser()->dailyMaxHoursDefault;
} catch (\Throwable) {
	$azcMaxDefault = '10';
}
$maxDailyHours = (float)\OCP\Server::get(\OCP\IConfig::class)->getAppValue('arbeitszeitcheck', 'max_daily_hours', $azcMaxDefault);
$recentEntries = $_['recentEntries'] ?? [];
$dashboardError = isset($_['error']) && is_string($_['error']) ? trim($_['error']) : '';
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$dashStats = $_['stats'] ?? [];
$timeCapture = is_array($_['timeCapture'] ?? null) ? $_['timeCapture'] : [];
$clockStampingEnabled = (bool)($timeCapture['clockStampingEnabled'] ?? true);
$manualTimeEntryEnabled = (bool)($timeCapture['manualTimeEntryEnabled'] ?? true);

// Single source of truth for storage TZ, user display TZ and the server-clock
// anchor the JS timer pins itself to. Defines $arbeitszeitCheckStorageTimeZone,
// $arbeitszeitCheckUserDisplayTz, $arbeitszeitCheckServerNowIso and emits the
// `window.ArbeitszeitCheck.tz` / `window.ArbeitszeitCheck.serverNow` bootstrap.
/** @var \DateTimeZone $arbeitszeitCheckStorageTimeZone */
/** @var \DateTimeZone $arbeitszeitCheckUserDisplayTz */
/** @var string $arbeitszeitCheckServerNowIso */
require __DIR__ . '/common/user-display-timezone.php';
$appTimezone = $arbeitszeitCheckStorageTimeZone->getName();

// Format a duration in seconds as HH:MM:SS for the static initial render.
// The live JS counter takes over the moment `js/common/time.js` loads, so
// this value is only ever visible for one frame before being replaced.
$arbeitszeitCheckFormatDuration = static function (int $seconds): string {
    $seconds = max(0, $seconds);
    return sprintf(
        '%02d:%02d:%02d',
        intdiv($seconds, 3600),
        intdiv($seconds % 3600, 60),
        $seconds % 60
    );
};

// Current session duration calculation for display. The status payload from
// `TimeTrackingService::getStatus()` already pre-computed this against the
// server clock, so the initial paint matches what the live timer will show
// once `ArbeitszeitCheckTime.syncFromServer()` resumes from `server_now`.
$currentSessionDuration = (int)round((float)($status['current_session_duration'] ?? 0));
$durationFormatted = $arbeitszeitCheckFormatDuration($currentSessionDuration);

// Break duration calculation for display (if on break). Use the server clock
// the status response was anchored at (`server_now`) so the initial paint is
// consistent with the live counter, instead of the PHP-template render time
// (which can be milliseconds ahead of the API instant).
$breakDurationFormatted = '00:00:00';
$breakStartTime = null;
if (($status['status'] ?? 'clocked_out') === 'break' && !empty($status['current_entry']['breakStartTime'])) {
    try {
        $breakStartTime = new \DateTime($status['current_entry']['breakStartTime']);
        $serverNow = new \DateTime($arbeitszeitCheckServerNowIso);
        $breakDuration = $serverNow->getTimestamp() - $breakStartTime->getTimestamp();
        $breakDurationFormatted = $arbeitszeitCheckFormatDuration((int)$breakDuration);
    } catch (\Throwable $e) {
        $breakStartTime = null;
    }
}

$arbeitszeitCheckFormatHours = static function (float $hours): string {
    return number_format($hours, 2, '.', '');
};
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <div class="azc-dashboard-alerts">
        <?php if ($dashboardError !== ''): ?>
            <?php
            $calloutVariant = 'danger';
            $calloutRole = 'alert';
            $calloutAriaLive = 'assertive';
            $calloutBanner = false;
            $calloutIcon = 'alert-triangle';
            $calloutTitle = $l->t('Some dashboard data could not be loaded.');
            $calloutText = $dashboardError;
            include __DIR__ . '/common/alert-callout.php';
            ?>
        <?php endif; ?>

        <?php if (!empty($_['workingTimeModelMissing'])): ?>
            <?php
            $calloutVariant = 'warning';
            $calloutRole = 'status';
            $calloutBanner = false;
            $calloutTitleId = 'dashboard-wtm-missing-title';
            $calloutIcon = 'alert-triangle';
            $calloutTitle = $l->t('Working time model missing');
            $calloutText = $l->t('No working time model is assigned to your account. Ask your administrator to assign one in employee settings. Until then, break rules and compliance checks may use default values only.');
            include __DIR__ . '/common/alert-callout.php';
            ?>
        <?php endif; ?>

        <?php
        $pendingCorrectionCount = (int)($_['pendingCorrectionCount'] ?? 0);
        if ($pendingCorrectionCount > 0):
            $timeEntriesUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries');
            $calloutVariant = 'warning';
            $calloutRole = 'status';
            $calloutElement = 'div';
            $calloutBanner = true;
            $calloutTitleId = 'dashboard-pending-correction-title';
            $calloutIcon = 'user-check';
            $calloutTitle = $l->n(
                '%n of your time entries is waiting for manager approval.',
                '%n of your time entries are waiting for manager approval.',
                $pendingCorrectionCount
            );
            $calloutText = $l->t('Open your time entries to see proposed times or withdraw the request.');
            $calloutActions = [
                [
                    'href' => $timeEntriesUrl,
                    'label' => $l->t('View time entries'),
                    'class' => 'azc-btn azc-btn--secondary azc-btn--sm',
                ],
            ];
            include __DIR__ . '/common/alert-callout.php';
        endif;
        ?>
        </div>

        <?php if (($_['isFirstTimeUser'] ?? false) === true): ?>
            <article class="azc-card azc-dashboard-welcome" role="region" aria-labelledby="welcome-title">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="welcome-title" class="azc-card__title"><?php p($l->t('Welcome to Time Tracking!')); ?></h2>
                        <p class="azc-card__lead"><?php p($l->t('This app helps you record your work time and follow the configured labour law. Here\'s how to get started:')); ?></p>
                    </div>
                </header>
                <div class="azc-card__body">
                    <ol class="azc-dashboard-welcome__steps">
                        <?php if ($clockStampingEnabled): ?>
                        <li><?php p($l->t('Click the "Clock In" button below when you start work')); ?></li>
                        <li><?php p($l->t('Click "Clock Out" when you finish work')); ?></li>
                        <li><?php p($l->t('The system will automatically track your hours and remind you to take breaks')); ?></li>
                        <?php endif; ?>
                        <?php if ($manualTimeEntryEnabled): ?>
                        <li><?php p($l->t('You can add time entries manually under Time entries when you need to record hours by hand')); ?></li>
                        <?php endif; ?>
                        <li><?php p($l->t('Request vacation and other absences in the Absences section')); ?></li>
                    </ol>
                    <div class="azc-dashboard-welcome__actions">
                        <?php if ($manualTimeEntryEnabled): ?>
                        <a href="<?php print_unescaped($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
                            class="azc-btn azc-btn--primary"
                            aria-label="<?php p($l->t('Go to time entries to see how to add entries manually')); ?>">
                            <?php p($l->t('Learn More About Time Entries')); ?>
                        </a>
                        <?php endif; ?>
                        <button type="button" class="azc-btn azc-btn--secondary" id="dismiss-welcome"
                            aria-label="<?php p($l->t('Dismiss this welcome message')); ?>">
                            <?php p($l->t('Got it, thanks!')); ?>
                        </button>
                    </div>
                </div>
            </article>
        <?php endif; ?>

        <section class="azc-dashboard-overview dashboard-key-metrics" aria-labelledby="dashboard-overview-title">
            <header class="azc-dashboard-overview__intro">
                <h2 id="dashboard-overview-title" class="azc-dashboard-overview__title"><?php p($l->t('At a glance')); ?></h2>
                <p class="azc-dashboard-overview__lead"><?php
                    if ($clockStampingEnabled && $manualTimeEntryEnabled) {
                        p($l->t('Clock in and out here, then check your hours, overtime, and vacation below.'));
                    } elseif ($clockStampingEnabled) {
                        p($l->t('Clock in and out here, then review your hours, overtime, and vacation below.'));
                    } elseif ($manualTimeEntryEnabled) {
                        p($l->t('Your hours are recorded under Time entries. Review overtime and vacation below.'));
                    } else {
                        p($l->t('Review your recorded hours, overtime, and vacation below.'));
                    }
                ?></p>
            </header>

            <div class="azc-dashboard-overview__grid">
                <!-- Status / punch clock -->
                <?php
			$statusKey = $status['status'] ?? 'clocked_out';
			$statusKeySafe = in_array($statusKey, ['active', 'break', 'clocked_out', 'paused'], true) ? $statusKey : 'clocked_out';
			$pausedEntryId = ($statusKeySafe === 'paused') ? ($status['current_entry']['id'] ?? null) : null;
			$showResumeButton = $clockStampingEnabled && $statusKeySafe === 'paused';
			$showClockInButton = $clockStampingEnabled && $statusKeySafe === 'clocked_out';
			$showCompletePausedButton = $statusKeySafe === 'paused' && $pausedEntryId !== null;
			$showSessionStampActions = in_array($statusKeySafe, ['active', 'break'], true);
			$showPunchActionGroup = $showClockInButton || $showResumeButton || $showCompletePausedButton || $showSessionStampActions;
			$statusLabel = match ($statusKeySafe) {
				'active' => $l->t('Clocked In'),
				'break' => $l->t('On Break'),
				'paused' => $l->t('Paused'),
				default => $l->t('Clocked Out'),
			};
			$statusIconName = match ($statusKeySafe) {
				'active' => 'clock',
				'break' => 'coffee',
				'paused' => 'pause',
				default => 'circle',
			};
			$statusSubtitle = match ($statusKeySafe) {
				'active' => $l->t('Your working time is being recorded.'),
				'break' => $l->t('You are on a break. End the break or clock out when you are done.'),
				'paused' => $clockStampingEnabled
					? $l->t('Your session is paused. Resume work or complete the session.')
					: $l->t('Your session is paused. Use “Complete session” to finish recording this day.'),
				default => $clockStampingEnabled
					? $l->t('You are not clocked in. Press the button below when you start work.')
					: ($manualTimeEntryEnabled
						? $l->t('Clock in/out is not enabled for your account. Record your hours under Time entries instead.')
						: $l->t('Clock in/out is not enabled for your account. Contact your administrator if you need to record time.')),
			};
			$showNextHint = in_array($statusKeySafe, ['active', 'break'], true);
                $startedAt = null;
                $isOvernightSession = false;
                if (!empty($status['current_entry']['startTime'])) {
                    try {
                        $startTime = new \DateTime($status['current_entry']['startTime']);
                        $startTime->setTimezone($arbeitszeitCheckUserDisplayTz);
                        $startedAt = $startTime->format('H:i');
                        $sessionStartDay = $startTime->format('Y-m-d');
                        $todayInStorage = (new \DateTime('now', $arbeitszeitCheckStorageTimeZone))->format('Y-m-d');
                        $isOvernightSession = in_array($statusKeySafe, ['active', 'break'], true)
                            && $sessionStartDay !== $todayInStorage;
                    } catch (\Throwable $e) {
                        $startedAt = null;
                    }
                }
                ?>
                <article class="azc-card azc-dashboard-punch azc-dashboard-punch--<?php p($statusKeySafe); ?> azc-dashboard-status dashboard-status-card dashboard-status-card--<?php p($statusKeySafe); ?>"
                    role="region"
                    aria-labelledby="dashboard-status-heading"
                    aria-describedby="dashboard-status-subtitle">
                    <div class="azc-dashboard-punch__inner">
                        <div class="azc-dashboard-punch__hero">
                            <div class="azc-dashboard-punch__signal" aria-hidden="true">
                                <span class="azc-dashboard-punch__signal-icon"><?php print_unescaped(IconCatalog::render($statusIconName, 'azc-dashboard-punch__icon-svg')); ?></span>
                            </div>
                            <div class="azc-dashboard-punch__headline">
                                <h3 id="dashboard-status-heading" class="azc-dashboard-punch__title"><?php p($statusLabel); ?></h3>
                                <p id="dashboard-status-subtitle" class="azc-dashboard-punch__subtitle"><?php p($statusSubtitle); ?></p>
                            </div>
                        </div>

                        <?php if ($statusKeySafe !== 'clocked_out'): ?>
                        <div class="azc-dashboard-punch__timers">
                            <?php if ($statusKeySafe === 'break'): ?>
                                <div class="break-timer azc-dashboard-punch__timer dashboard-status-card__timer" data-break-start-time="<?php p($status['current_entry']['breakStartTime'] ?? ''); ?>" role="status" aria-live="polite">
                                    <span class="timer-label"><?php p($l->t('Break timer')); ?></span>
                                    <span class="timer-value" id="break-timer-value"><?php p($breakDurationFormatted); ?></span>
                                    <?php if ($breakStartTime !== null): ?>
                                        <p class="azc-dashboard-punch__meta dashboard-status-card__meta">
                                            <?php
                                            try {
                                                $breakStartTime->setTimezone($arbeitszeitCheckUserDisplayTz);
                                                p($l->t('Break started at')); ?> <?php p($breakStartTime->format('H:i'));
                                            } catch (\Throwable $e) {
                                                p($l->t('Break started at')); ?> <?php p($breakStartTime->format('H:i'));
                                            }
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div class="session-timer azc-dashboard-punch__timer azc-dashboard-punch__timer--secondary dashboard-status-card__timer dashboard-status-card__timer--paused dashboard-status-card__timer--secondary" data-start-time="<?php p($status['current_entry']['startTime'] ?? ''); ?>" role="status" aria-live="polite">
                                    <span class="timer-label"><?php p($l->t('Worked so far today')); ?></span>
                                    <span class="timer-value" id="session-timer-value"><?php p($durationFormatted); ?></span>
                                    <?php if ($startedAt !== null): ?>
                                        <p class="azc-dashboard-punch__meta dashboard-status-card__meta"><?php p($l->t('Started at')); ?> <?php p($startedAt); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="session-timer azc-dashboard-punch__timer dashboard-status-card__timer" data-start-time="<?php p($status['current_entry']['startTime'] ?? ''); ?>" role="status" aria-live="polite">
                                    <span class="timer-label"><?php p($l->t('Current session')); ?></span>
                                    <span class="timer-value" id="session-timer-value"><?php p($durationFormatted); ?></span>
                                    <?php if ($startedAt !== null): ?>
                                        <p class="azc-dashboard-punch__meta dashboard-status-card__meta"><?php p($l->t('Started at')); ?> <?php p($startedAt); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($isOvernightSession): ?>
                            <div class="azc-callout azc-callout--info azc-dashboard-overnight" role="status">
                                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('clock', 'azc-callout__icon-svg')); ?></span>
                                <div class="azc-callout__body">
                                    <p class="azc-callout__title"><?php p($l->t('Night shift across midnight')); ?></p>
                                    <p class="azc-callout__text"><?php p($l->t('Your session continues from yesterday. “Worked today” counts only the hours since midnight on the current calendar day (labour-law daily limit). The session timer shows your total working time since clock-in.')); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($showNextHint): ?>
                        <p class="azc-dashboard-punch__hint" id="dashboard-next-action-hint">
                            <?php p(match ($statusKeySafe) {
                                'active' => $l->t('Take a break when required, or clock out when you finish.'),
                                'break' => $l->t('End your break to continue working, or clock out for the day.'),
                                default => '',
                            }); ?>
                        </p>
                        <?php endif; ?>

                        <?php
                        $projectCheckEnabled = !empty($_['projectCheckEnabled']);
                        $projectCheckAvailable = !empty($_['projectCheckAvailable']);
                        $projectCheckProjects = is_array($_['projectCheckProjects'] ?? null) ? $_['projectCheckProjects'] : [];
                        $hasProjectCheckProjects = !empty($projectCheckProjects);
                        $showClockInProjectPicker = $projectCheckEnabled
                            && $hasProjectCheckProjects
                            && $showClockInButton;
                        $showProjectCheckEmptyHint = $projectCheckEnabled
                            && !$hasProjectCheckProjects
                            && $showClockInButton;
                        $showProjectCheckLinkingOffHint = $projectCheckAvailable
                            && !$projectCheckEnabled
                            && $showClockInButton;
                        ?>
                        <?php if ($showProjectCheckLinkingOffHint): ?>
                            <?php $azcProjectCheckCalloutContext = 'dashboard'; include __DIR__ . '/partials/projectcheck-linking-disabled-callout.php'; ?>
                        <?php endif; ?>
                        <?php if ($showProjectCheckEmptyHint): ?>
                        <div class="azc-callout azc-callout--info azc-dashboard-project-picker-empty" role="status">
                            <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('briefcase', 'azc-callout__icon-svg')); ?></span>
                            <div class="azc-callout__body">
                                <p class="azc-callout__title"><?php p($l->t('No projects available for you')); ?></p>
                                <p class="azc-callout__text"><?php p($l->t('ProjectCheck is enabled, but you have no customer projects to choose from. Ask your project manager to add you to a project team, or clock in without a project.')); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($showClockInProjectPicker): ?>
                        <div class="azc-dashboard-punch__project azc-dashboard-project-picker" role="group" aria-labelledby="dashboard-clock-in-project-label">
                            <label for="dashboard-clock-in-project" id="dashboard-clock-in-project-label" class="azc-dashboard-project-picker__label">
                                <span class="azc-dashboard-project-picker__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('briefcase', 'azc-dashboard-project-picker__icon-svg')); ?></span>
                                <span class="azc-dashboard-project-picker__label-text"><?php p($l->t('Project for this session')); ?></span>
                                <span class="azc-dashboard-project-picker__optional"><?php p($l->t('optional')); ?></span>
                            </label>
                            <select id="dashboard-clock-in-project"
                                class="form-select azc-dashboard-project-picker__select"
                                aria-describedby="dashboard-clock-in-project-help">
                                <?php
                                $azcPickerProjects = $projectCheckProjects;
                                $azcPickerSelectedId = '';
                                include __DIR__ . '/common/projectcheck-picker-options.php';
                                ?>
                            </select>
                            <p id="dashboard-clock-in-project-help" class="form-help azc-dashboard-project-picker__help"><?php p($l->t('Optional: link this stamp session to one ProjectCheck customer project for billing. ArbeitszeitCheck tracks attendance (one active session). To split the day across several projects, keep stamping here and log project hours in ProjectCheck — or ask your administrator. Leave “No project” to track time only. Projects with per-person pricing only appear if you are on the project team.')); ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if (!$clockStampingEnabled && in_array($statusKeySafe, ['clocked_out', 'paused'], true) && !$showSessionStampActions): ?>
                            <div class="azc-callout azc-callout--info azc-dashboard-capture-disabled" role="status">
                                <span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('info', 'azc-callout__icon-svg')); ?></span>
                                <div class="azc-callout__body">
                                    <p class="azc-callout__title"><?php p($l->t('Clock in/out is turned off for you')); ?></p>
                                    <p class="azc-callout__text"><?php
                                        if ($statusKeySafe === 'paused') {
                                            p($l->t('You cannot start a new stamp session. Finish the paused session with “Complete session” below, or contact your administrator.'));
                                        } elseif ($manualTimeEntryEnabled) {
                                            p($l->t('Your administrator disabled stamping. Add your working hours manually under Time entries.'));
                                        } else {
                                            p($l->t('Your administrator disabled stamping. Contact HR if you need to record time.'));
                                        }
                                    ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($showPunchActionGroup): ?>
                        <div class="azc-dashboard-punch__actions azc-dashboard-status__actions<?php echo ($statusKeySafe === 'clocked_out' && $showClockInButton && !$showCompletePausedButton) ? ' azc-dashboard-punch__actions--solo' : ''; ?>" role="group" aria-label="<?php p($l->t('Time tracking actions')); ?>">
                            <?php if ($showClockInButton): ?>
                                <button id="btn-clock-in"
                                    class="azc-btn azc-btn--primary azc-dashboard-punch__cta"
                                    type="button"
                                    aria-label="<?php p($l->t('Clock in to start tracking your working time')); ?>"
                                    title="<?php p($l->t('Click to clock in and start tracking your working time')); ?>">
                                    <?php p($l->t('Clock In')); ?>
                                </button>
                            <?php endif; ?>
                            <?php if ($showResumeButton): ?>
                                <button id="btn-clock-in"
                                    class="azc-btn azc-btn--primary azc-dashboard-punch__cta"
                                    type="button"
                                    aria-label="<?php p($l->t('Resume working – continues your paused time entry')); ?>"
                                    title="<?php p($l->t('Resume working – continues your paused time entry')); ?>">
                                    <?php p($l->t('Resume after break')); ?>
                                </button>
                            <?php endif; ?>
                            <?php if ($showCompletePausedButton): ?>
                                    <button class="azc-btn azc-btn--secondary btn-complete-entry"
                                        type="button"
                                        data-entry-id="<?php p((string)$pausedEntryId); ?>"
                                        aria-label="<?php p($l->t('Complete the paused session and record the time you stopped working')); ?>"
                                        title="<?php p($l->t('Already done for today? Complete this session – the end time is the moment it was paused, required breaks are added automatically.')); ?>">
                                        <span class="btn__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('check', 'btn__icon-svg')); ?></span>
                                        <?php p($l->t('Complete session')); ?>
                                    </button>
                            <?php endif; ?>
                            <?php if ($statusKeySafe === 'active'): ?>
                                <button id="btn-start-break"
                                    class="azc-btn azc-btn--secondary"
                                    type="button"
                                    aria-label="<?php p($l->t('Start a break from work')); ?>"
                                    title="<?php p($l->t('Click to start a break. You must take breaks according to the configured labour law.')); ?>">
                                    <?php p($l->t('Start Break')); ?>
                                </button>
                                <button class="azc-btn azc-btn--danger btn-clock-out"
                                    type="button"
                                    aria-label="<?php p($l->t('Clock out to end your working day')); ?>"
                                    title="<?php p($l->t('Click to clock out and end your working time for today')); ?>">
                                    <?php p($l->t('Clock Out')); ?>
                                </button>
                            <?php elseif ($statusKeySafe === 'break'): ?>
                                <button id="btn-end-break"
                                    class="azc-btn azc-btn--primary"
                                    type="button"
                                    aria-label="<?php p($l->t('End your break and return to work')); ?>"
                                    title="<?php p($l->t('Click to end your break and continue working')); ?>">
                                    <?php p($l->t('End Break')); ?>
                                </button>
                                <button class="azc-btn azc-btn--danger btn-clock-out"
                                    type="button"
                                    aria-label="<?php p($l->t('Clock out to end your working day')); ?>"
                                    title="<?php p($l->t('Click to clock out and end your working time for today')); ?>">
                                    <?php p($l->t('Clock Out')); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <p class="azc-dashboard-punch__tz"
                           aria-label="<?php p($l->t('Times are shown in your timezone (%s).', [$arbeitszeitCheckUserDisplayTz->getName()])); ?>">
                            <?php p($l->t('All times in %s', [$arbeitszeitCheckUserDisplayTz->getName()])); ?>
                        </p>
                    </div>
                </article>

                <?php
                $metricWorkedToday = round((float)($status['working_today_hours'] ?? 0), 2);
                $metricWeekHours = round((float)($weekOvertime['total_hours_worked'] ?? 0), 2);
                $metricOvertimeBalance = round((float)($_['overtimeYtdBalance'] ?? $overtimeTrafficLight['balance'] ?? 0), 2);
                $weekPeriodLabel = '';
                if (!empty($weekOvertime['period_start']) && !empty($weekOvertime['period_end'])) {
                    $weekPeriodLabel = (string)$weekOvertime['period_start'] . ' – ' . (string)$weekOvertime['period_end'];
                }
                $metricDailyNorm = isset($weekOvertime['implied_daily_hours']) || isset($overtime['implied_daily_hours'])
                    ? round((float)($weekOvertime['implied_daily_hours'] ?? $overtime['implied_daily_hours'] ?? 0), 2)
                    : null;
                $bankEnabled = ($overtimeBank['enabled'] ?? false) === true;
                $displayBalancePreview = $bankEnabled
                    ? (float)($overtimeBank['effective_balance'] ?? 0)
                    : (float)($_['overtimeYtdBalance'] ?? $metricOvertimeBalance);
                $trafficEnabledPreview = ($overtimeTrafficLight['enabled'] ?? false) === true;
                // Bachus: always show the overtime card (never hide when balance ≈ 0).
                // Keeps Saldo discoverable; metrics panel no longer duplicates the balance.
                $showOvertimeSection = true;
                ?>
                <article class="azc-card azc-dashboard-metrics-panel" aria-labelledby="dashboard-metrics-heading">
                    <header class="azc-card__header">
                        <div class="azc-card__header-text">
                            <h3 id="dashboard-metrics-heading" class="azc-card__title"><?php p($l->t('Hours at a glance')); ?></h3>
                        </div>
                    </header>
                    <div class="azc-card__body">
                    <div class="azc-dashboard-metrics" role="list" aria-label="<?php p($l->t('Hours at a glance')); ?>">
                    <div class="azc-dashboard-metric" role="listitem">
                        <span class="azc-dashboard-metric__label"><?php p($l->t('Worked today')); ?></span>
                        <span class="azc-dashboard-metric__value"><?php p($arbeitszeitCheckFormatHours($metricWorkedToday)); ?> <span class="azc-dashboard-metric__unit"><?php p($l->t('hours')); ?></span></span>
                    </div>
                    <div class="azc-dashboard-metric" role="listitem">
                        <span class="azc-dashboard-metric__label" id="dashboard-metric-week-label"><?php p($l->t('This week')); ?></span>
                        <?php if ($weekPeriodLabel !== '') { ?>
                        <span class="azc-dashboard-metric__period" id="dashboard-metric-week-period"><?php p($weekPeriodLabel); ?></span>
                        <?php } ?>
                        <span class="azc-dashboard-metric__value" aria-labelledby="dashboard-metric-week-label<?php echo $weekPeriodLabel !== '' ? ' dashboard-metric-week-period' : ''; ?>"><?php p($arbeitszeitCheckFormatHours($metricWeekHours)); ?> <span class="azc-dashboard-metric__unit"><?php p($l->t('hours')); ?></span></span>
                    </div>
                    <?php if ($metricDailyNorm !== null && $metricDailyNorm > 0): ?>
                    <div class="azc-dashboard-metric" role="listitem">
                        <span class="azc-dashboard-metric__label"><?php p($l->t('Daily target (contract)')); ?></span>
                        <span class="azc-dashboard-metric__value"><?php p($arbeitszeitCheckFormatHours($metricDailyNorm)); ?> <span class="azc-dashboard-metric__unit"><?php p($l->t('hours')); ?></span></span>
                    </div>
                    <?php endif; ?>
                    </div>
                    </div>
                </article>

                <?php
                    $displayBalance = $displayBalancePreview;
                    $balanceSignPrefix = $displayBalance > 0.0001 ? '+' : '';
                    $lightState = (string)($overtimeTrafficLight['state'] ?? 'green');
                    $lightBadge = 'success';
                    $lightText = $l->t('Green — balance in target range');
                    $semaphoreClass = 'green';
                    if ($lightState === 'yellow_over') {
                        $lightBadge = 'warning';
                        $lightText = $l->t('Yellow — overtime threshold reached');
                        $semaphoreClass = 'yellow';
                    } elseif ($lightState === 'red_over') {
                        $lightBadge = 'error';
                        $lightText = $l->t('Red — high overtime');
                        $semaphoreClass = 'red';
                    } elseif ($lightState === 'yellow_under') {
                        $lightBadge = 'warning';
                        $lightText = $l->t('Yellow — undertime threshold reached');
                        $semaphoreClass = 'yellow';
                    } elseif ($lightState === 'red_under') {
                        $lightBadge = 'error';
                        $lightText = $l->t('Red — high undertime');
                        $semaphoreClass = 'red';
                    }
                    $thresholds = is_array($overtimeTrafficLight['thresholds'] ?? null) ? $overtimeTrafficLight['thresholds'] : [];
                    $trafficEnabled = $trafficEnabledPreview;
                ?>
                <?php if ($showOvertimeSection): ?>
                <article class="azc-card azc-dashboard-overtime dashboard-overtime-card" aria-labelledby="dashboard-overtime-heading">
                    <header class="azc-card__header">
                        <div class="azc-card__header-text">
                            <h3 id="dashboard-overtime-heading" class="azc-card__title"><?php p($l->t('Your overtime balance')); ?></h3>
                            <p id="dashboard-overtime-formula" class="azc-card__lead dashboard-overtime-card__formula">
                                <?php p($l->t('Worked hours minus your contract target (this year). This is an hour balance — not pay.')); ?>
                            </p>
                        </div>
                    </header>
                    <div class="azc-card__body">
                            <div class="dashboard-overtime-card__balance" role="group" aria-label="<?php p($l->t('Year-to-date overtime balance')); ?>">
                                <span class="dashboard-overtime-card__balance-label"><?php p($bankEnabled ? $l->t('Balance (after payouts)') : $l->t('Balance (year to date)')); ?></span>
                                <span class="dashboard-overtime-card__balance-value <?php echo $displayBalance >= 0 ? 'positive' : 'negative'; ?>"
                                    id="dashboard-overtime-balance-value"
                                    aria-describedby="dashboard-overtime-formula"
                                    aria-label="<?php p($l->t('Balance: %s hours', [$balanceSignPrefix . number_format($displayBalance, 2)])); ?>">
                                    <?php p($balanceSignPrefix . number_format($displayBalance, 2)); ?> <?php p($l->t('h')); ?>
                                </span>
                            </div>
                            <?php
                            $overtimeBalancePdfUrl = (string)($_['overtimeBalancePdfUrl'] ?? '');
                            if ($overtimeBalancePdfUrl !== ''):
                            ?>
                            <div class="dashboard-overtime-card__actions">
                                <a id="dashboard-overtime-balance-pdf"
                                   class="azc-btn azc-btn--secondary azc-btn--sm"
                                   href="<?php p($overtimeBalancePdfUrl); ?>"
                                   download
                                   aria-describedby="dashboard-overtime-formula">
                                    <?php p($l->t('Download balance PDF')); ?>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php
                            $premiumSummary = is_array($_['premiumSummary'] ?? null) ? $_['premiumSummary'] : [];
                            $premiumOn = !empty($premiumSummary['enabled']);
                            $premiumBuckets = is_array($premiumSummary['buckets'] ?? null) ? $premiumSummary['buckets'] : [];
                            if ($premiumOn):
                            ?>
                            <section class="dashboard-overtime-card__premiums" aria-labelledby="dashboard-premium-heading">
                                <h4 id="dashboard-premium-heading" class="dashboard-overtime-card__bank-title"><?php p($l->t('Hour premiums (this month)')); ?></h4>
                                <p class="form-help" id="dashboard-premium-help"><?php p($l->t('Hours with a percentage for reporting — not pay, and not your Saldo above.')); ?></p>
                                <?php if ($premiumBuckets === []): ?>
                                <p class="form-help" role="status"><?php p($l->t('No premium hours in this period yet.')); ?></p>
                                <?php else: ?>
                                <ul class="dashboard-premium-list" aria-describedby="dashboard-premium-help">
                                    <?php foreach ($premiumBuckets as $bucket): ?>
                                    <li>
                                        <span class="dashboard-premium-list__label"><?php p((string)($bucket['label'] ?? $bucket['id'] ?? '')); ?></span>
                                        <span class="dashboard-premium-list__value">
                                            <?php p(number_format((float)($bucket['hours'] ?? 0), 2)); ?> <?php p($l->t('h')); ?>
                                            @ <?php p((string)(int)round(((float)($bucket['rate'] ?? 0)) * 100)); ?>%
                                        </span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                            </section>
                            <?php endif; ?>

                            <?php if ($trafficEnabled): ?>
                            <div class="dashboard-overtime-card__traffic" role="status" aria-live="polite" aria-labelledby="dashboard-ot-traffic-label">
                                <span id="dashboard-ot-traffic-label" class="dashboard-overtime-card__traffic-label"><?php p($l->t('Balance alert')); ?></span>
                                <div class="ot-semaphore ot-semaphore--<?php p($semaphoreClass); ?>" aria-hidden="true">
                                    <span class="ot-semaphore__lamp ot-semaphore__lamp--red"></span>
                                    <span class="ot-semaphore__lamp ot-semaphore__lamp--yellow"></span>
                                    <span class="ot-semaphore__lamp ot-semaphore__lamp--green"></span>
                                </div>
                                <span class="badge badge--<?php p($lightBadge); ?>"><?php p($lightText); ?></span>
                                <?php if ($thresholds !== []): ?>
                                <p class="dashboard-overtime-card__thresholds form-help">
                                    <?php p($l->t('Overtime: yellow from %1$s h, red from %2$s h. Undertime: yellow from %3$s h, red from %4$s h.', [
                                        number_format((float)($thresholds['yellow_over'] ?? 5), 1),
                                        number_format((float)($thresholds['red_over'] ?? 15), 1),
                                        number_format((float)($thresholds['yellow_under'] ?? 5), 1),
                                        number_format((float)($thresholds['red_under'] ?? 15), 1),
                                    ])); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <p class="dashboard-overtime-card__traffic-disabled form-help" role="status">
                                <?php p($l->t('Overtime balance alerts are disabled by your administrator. Your balance above is still updated.')); ?>
                            </p>
                            <?php endif; ?>

                            <?php if ($bankEnabled): ?>
                            <?php
                                $bankMax = (float)($overtimeBank['bank_max_hours'] ?? 100);
                                $banked = (float)($overtimeBank['banked_hours'] ?? 0);
                                $bankFill = (float)($overtimeBank['bank_fill_percent'] ?? 0);
                                $payoutEligible = (float)($overtimeBank['payout_eligible_hours'] ?? 0);
                                $bankState = (string)($overtimeBank['bank_state'] ?? 'bank_green');
                                $bankBadge = 'success';
                                $bankStatusText = $l->t('Room in your overtime bank');
                                if ($bankState === 'bank_yellow') {
                                    $bankBadge = 'warning';
                                    $bankStatusText = $l->t('Overtime bank nearly full');
                                } elseif ($bankState === 'bank_red' || $bankState === 'payout_eligible') {
                                    $bankBadge = 'error';
                                    $bankStatusText = $bankState === 'payout_eligible'
                                        ? $l->t('Hours above the bank cap — eligible for payout at month end')
                                        : $l->t('Overtime bank almost at maximum');
                                } elseif ($bankState === 'undertime') {
                                    $bankBadge = 'warning';
                                    $bankStatusText = $l->t('Undertime — bank not in use');
                                }
                            ?>
                            <details class="dashboard-overtime-card__bank-details">
                                <summary class="dashboard-overtime-card__bank-summary">
                                    <?php p($l->t('Overtime bank and payouts')); ?>
                                    <?php if ($payoutEligible >= 0.01): ?>
                                    <span class="badge badge--error"><?php p($l->t('Eligible for payout')); ?>: <?php p(number_format($payoutEligible, 2)); ?> <?php p($l->t('h')); ?></span>
                                    <?php endif; ?>
                                </summary>
                            <div class="dashboard-overtime-card__bank" role="region" aria-labelledby="dashboard-overtime-bank-subheading">
                                <h4 id="dashboard-overtime-bank-subheading" class="dashboard-overtime-card__bank-title"><?php p($l->t('Overtime bank')); ?></h4>
                                <p class="form-help"><?php p($l->t('Save up to %s hours; payroll can pay out anything above the cap at month end.', [number_format($bankMax, 0)])); ?></p>
                                <div class="dashboard-overtime-bank-card__meter-wrap">
                                    <div
                                        class="dashboard-overtime-bank-card__meter"
                                        role="progressbar"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        aria-valuenow="<?php p((string)(int)min(100, max(0, round($bankFill)))); ?>"
                                        aria-valuetext="<?php p($l->t('%1$s of %2$s hours banked', [number_format($banked, 2), number_format($bankMax, 0)])); ?>">
                                        <div class="dashboard-overtime-bank-card__meter-fill dashboard-overtime-bank-card__meter-fill--<?php p($bankBadge); ?>" style="width: <?php p((string)min(100, max(0, $bankFill))); ?>%;"></div>
                                    </div>
                                    <p class="dashboard-overtime-bank-card__meter-value">
                                        <span><?php p(number_format($banked, 2)); ?></span>
                                        <span aria-hidden="true">/</span>
                                        <span><?php p(number_format($bankMax, 0)); ?> <?php p($l->t('h')); ?></span>
                                    </p>
                                </div>
                                <p class="dashboard-overtime-bank-card__status" role="status">
                                    <span class="badge badge--<?php p($bankBadge); ?>"><?php p($bankStatusText); ?></span>
                                </p>
                                <?php if ($payoutEligible >= 0.01): ?>
                                <p class="dashboard-overtime-card__payout-note" role="note">
                                    <strong><?php p($l->t('Eligible for payout')); ?>:</strong>
                                    <?php p(number_format($payoutEligible, 2)); ?> <?php p($l->t('h')); ?>
                                </p>
                                <?php endif; ?>
                                <?php if ((float)($overtimeBank['total_payouts_ytd'] ?? 0) > 0): ?>
                                <p class="form-help"><?php p($l->t('Already paid out this year: %s h', [number_format((float)$overtimeBank['total_payouts_ytd'], 2)])); ?></p>
                                <?php endif; ?>
                                <?php
                                $payoutHistory = is_array($_['overtimePayoutHistory'] ?? null) ? $_['overtimePayoutHistory'] : [];
                                $payoutItems = is_array($payoutHistory['items'] ?? null) ? $payoutHistory['items'] : [];
                                if ($payoutItems !== []):
                                ?>
                                <div class="dashboard-overtime-payout-history" role="region" aria-labelledby="dashboard-payout-history-heading">
                                    <h4 id="dashboard-payout-history-heading" class="dashboard-overtime-card__bank-title"><?php p($l->t('Payout history')); ?></h4>
                                    <div class="table-container">
                                        <table class="table table--hover azc-table--responsive report-table dashboard-overtime-payout-history__table">
                                            <caption class="visually-hidden"><?php p($l->t('Recorded overtime payouts')); ?></caption>
                                            <thead>
                                                <tr>
                                                    <th scope="col"><?php p($l->t('Month')); ?></th>
                                                    <th scope="col"><?php p($l->t('Hours paid')); ?></th>
                                                    <th scope="col"><?php p($l->t('Balance after')); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($payoutItems as $payoutRow): ?>
                                                <tr>
                                                    <td data-label="<?php p($l->t('Month')); ?>"><?php p(sprintf('%04d-%02d', (int)($payoutRow['calendar_year'] ?? 0), (int)($payoutRow['calendar_month'] ?? 0))); ?></td>
                                                    <td data-label="<?php p($l->t('Hours paid')); ?>"><?php p(number_format((float)($payoutRow['hours_paid'] ?? 0), 2)); ?> <?php p($l->t('h')); ?></td>
                                                    <td data-label="<?php p($l->t('Balance after')); ?>"><?php p(number_format((float)($payoutRow['effective_balance_after'] ?? 0), 2)); ?> <?php p($l->t('h')); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            </details>
                            <?php endif; ?>
                    </div>
                </article>
                <?php endif; ?>

                <!-- Vacation summary -->
                <?php
                $dashVacationUnit = (string)($dashStats['vacation_unit'] ?? 'days');
                $dashIsVacationHours = $dashVacationUnit === 'hours';
                $dashUnitWord = $dashIsVacationHours ? $l->t('hours') : $l->t('days');
                $dashRemainingLabel = $dashIsVacationHours
                    ? $l->t('Remaining vacation hours')
                    : $l->t('Remaining vacation days');
                ?>
                <article class="azc-card azc-dashboard-vacation dashboard-vacation-card" aria-labelledby="dashboard-vacation-heading" data-vacation-unit="<?php p($dashVacationUnit); ?>">
                    <header class="azc-card__header">
                        <div class="azc-card__header-text">
                            <h3 id="dashboard-vacation-heading" class="azc-card__title"><?php p($l->t('Vacation')); ?> <?php
                                $vyLabel = (string)($dashStats['vacation_year_label'] ?? ($dashStats['vacation_year'] ?? date('Y')));
                                p($vyLabel);
                            ?></h3>
                        </div>
                    </header>
                    <div class="azc-card__body">
                        <?php if (!empty($dashStats['vacation_year_error'])) { ?>
                        <p class="azc-callout azc-callout--warning" role="status" id="dashboard-vacation-year-error">
                            <?php p($l->t('Your vacation year uses hire anniversary, but no employment start date is set. Ask your admin to set it under Employees.')); ?>
                        </p>
                        <?php } ?>
                        <div class="dashboard-vacation-card__row dashboard-vacation-card__row--hero">
                            <span class="dashboard-vacation-card__label" id="dashboard-vacation-remaining-label"><?php p($dashRemainingLabel); ?></span>
                            <span class="dashboard-vacation-card__value dashboard-vacation-card__value--hero"
                                  id="dashboard-vacation-remaining-value"
                                  aria-labelledby="dashboard-vacation-remaining-label"><?php p((string)round((float)($dashStats['vacation_days_remaining'] ?? 0), 1)); ?></span>
                            <span class="dashboard-vacation-card__unit"><?php p($dashUnitWord); ?></span>
                        </div>
                        <dl class="dashboard-vacation-card__breakdown" aria-label="<?php p($l->t('Vacation breakdown')); ?>">
                            <div class="dashboard-vacation-card__breakdown-row">
                                <dt><?php p($l->t('Annual leave left (bookable)')); ?></dt>
                                <dd><?php p((string)round((float)($dashStats['vacation_annual_remaining'] ?? 0), 1) . ' ' . $dashUnitWord); ?></dd>
                            </div>
                            <div class="dashboard-vacation-card__breakdown-row">
                                <dt><?php p($l->t('Carryover pool left')); ?></dt>
                                <dd><?php p((string)round((float)($dashStats['vacation_carryover_remaining'] ?? 0), 1) . ' ' . $dashUnitWord); ?></dd>
                            </div>
                            <?php if (isset($dashStats['vacation_carryover_max_cap']) && $dashStats['vacation_carryover_max_cap'] !== null && $dashStats['vacation_carryover_max_cap'] !== '') { ?>
                            <div class="dashboard-vacation-card__breakdown-row">
                                <dt><?php p($l->t('Max. carryover (admin cap)')); ?></dt>
                                <dd><?php p((string)round((float)$dashStats['vacation_carryover_max_cap'], 1) . ' ' . $dashUnitWord); ?></dd>
                            </div>
                            <?php } ?>
                        </dl>
                        <?php
                        $vcExp = $dashStats['vacation_carryover_expires_on'] ?? null;
                        $vcUsable = (float)($dashStats['vacation_carryover_usable'] ?? 0);
                        $vcCoDays = (float)($dashStats['vacation_carryover_days'] ?? 0);
                        $vcLocked = !empty($dashStats['vacation_carryover_locked_after_deadline']);
                        $vcExpFmt = '';
                        if ($vcExp) {
                            try {
                                $vcExpFmt = (new \DateTimeImmutable((string)$vcExp))->format('d.m.Y');
                            } catch (\Throwable $e) {
                                $vcExpFmt = (string)$vcExp;
                            }
                        }
                        if ($vcExpFmt !== '' && $vcCoDays > 0.0001) {
                            ?>
                        <p class="form-help dashboard-vacation-card__hint<?php echo $vcLocked ? ' dashboard-vacation-card__hint--locked' : ''; ?>"
                            id="dashboard-vacation-carryover-hint"
                            role="status">
                            <?php if ($vcLocked) {
                                echo $dashIsVacationHours
                                    ? $l->t('Carryover deadline has passed (%1$s). New requests can no longer use last year’s remaining hours. The opening balance above is your HR record; approved vacation already reduced it.', [$vcExpFmt])
                                    : $l->t('Carryover deadline has passed (%1$s). New requests can no longer use last year’s remaining days. The opening balance above is your HR record; approved vacation already reduced it.', [$vcExpFmt]);
                            } else {
                                p($l->t('Carryover from last year: use by %1$s (%2$s %3$s still usable for new requests).', [$vcExpFmt, (string)round($vcUsable, 1), $dashUnitWord]));
                            } ?>
                        </p>
                        <?php } ?>
                        <div class="dashboard-vacation-card__actions">
                            <a href="<?php print_unescaped($urlGenerator->linkToRoute('arbeitszeitcheck.page.absences')); ?>"
                               class="azc-btn azc-btn--secondary"><?php p($l->t('Open absences')); ?></a>
                        </div>
                    </div>
                </article>
            </div><!-- /.azc-dashboard-overview__grid -->
        </section>

        <section class="azc-card azc-dashboard-recent" aria-labelledby="recent-entries-heading">
            <header class="azc-card__header">
                <div class="azc-card__header-text">
                    <h2 id="recent-entries-heading" class="azc-card__title"><?php p($l->t('Recent Entries')); ?></h2>
                    <p class="azc-card__lead"><?php p($l->t('Your last recorded working days. Open the full list to edit or correct entries.')); ?></p>
                </div>
                <div class="azc-card__header-actions">
                    <a href="<?php print_unescaped($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
                        class="azc-btn azc-btn--secondary">
                        <?php p($l->t('View All')); ?>
                    </a>
                </div>
            </header>

            <div class="azc-card__body azc-dashboard-recent__body">
            <div class="azc-table-wrap azc-dashboard-recent__table-wrap" role="region" aria-label="<?php p($l->t('Recent time entries')); ?>">
                <table class="table table--hover azc-table--responsive azc-dashboard-recent__table" role="table" aria-label="<?php p($l->t('Recent time entries')); ?>">
                    <caption class="sr-only"><?php p($l->t('Recent time entries with date, start, end, duration, break, status and actions')); ?></caption>
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
                            <?php foreach ($recentEntries as $entry):
                                // Convert times to user timezone (see templates/common/user-display-timezone.php)
                                $startTime = clone $entry->getStartTime();
                                $startTime->setTimezone($arbeitszeitCheckUserDisplayTz);
                                $endTime = $entry->getEndTime() ? clone $entry->getEndTime() : null;
                                if ($endTime) {
                                    $endTime->setTimezone($arbeitszeitCheckUserDisplayTz);
                                }
                            ?>
                                <tr>
                                    <td data-label="<?php p($l->t('Date')); ?>"><?php p($startTime->format('d.m.Y')); ?></td>
                                    <td data-label="<?php p($l->t('Start')); ?>"><?php p($startTime->format('H:i')); ?></td>
                                    <td data-label="<?php p($l->t('End')); ?>"><?php
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
                                    <td data-label="<?php p($l->t('Duration')); ?>">
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
                                                        $breakStart->setTimezone($arbeitszeitCheckUserDisplayTz);
                                                        $breakEnd = new \DateTime($break['end']);
                                                        $breakEnd->setTimezone($arbeitszeitCheckUserDisplayTz);
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
                                            $breakStart->setTimezone($arbeitszeitCheckUserDisplayTz);
                                            $breakEnd = clone $entry->getBreakEndTime();
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
                                    <td data-label="<?php p($l->t('Status')); ?>">
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
                                    <td data-label="<?php p($l->t('Actions')); ?>">
                                        <?php
                                        $canEdit = $entry->canEdit(\OCA\ArbeitszeitCheck\Constants::EDIT_WINDOW_DAYS);
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
                                <td colspan="7">
                                    <div class="azc-empty-state azc-dashboard-recent__empty">
                                        <p class="azc-empty-state__title"><?php p($l->t('No recent entries found')); ?></p>
                                        <p class="azc-empty-state__lead">
                                            <?php p($l->t('Your recent time entries will appear here. Start by clocking in to track your working time.')); ?>
                                        </p>
                                        <a href="<?php print_unescaped($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
                                            class="azc-btn azc-btn--primary">
                                            <?php p($l->t('View All Time Entries')); ?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </section>

<?php include __DIR__ . '/common/main-ui-l10n.php'; ?>
<?php include __DIR__ . '/common/hours-display-bootstrap.php'; ?>

<!-- Initialize JavaScript -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    // Pass essential data to JS
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.status = <?php echo json_encode($status, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.overtime = <?php echo json_encode($overtime, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.maxDailyHours = <?php echo json_encode($maxDailyHours, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.hoursDisplay = <?php echo json_encode($azcHoursDisplayMode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
        status: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_tracking.getStatus'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        onboardingComplete: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.settings.setOnboardingCompleted'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        projectcheckAssignable: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiAssignableProjectcheckProjects'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };

    // Handle welcome message dismissal
    const dismissWelcomeBtn = document.getElementById('dismiss-welcome');
    if (dismissWelcomeBtn) {
        dismissWelcomeBtn.addEventListener('click', function() {
            const welcomeCard = this.closest('.azc-dashboard-welcome');
            if (welcomeCard) {
                welcomeCard.style.display = 'none';
                const completeUrl = (window.ArbeitszeitCheck.apiUrl && window.ArbeitszeitCheck.apiUrl.onboardingComplete)
                    ? window.ArbeitszeitCheck.apiUrl.onboardingComplete
                    : null;
                if (completeUrl && window.ArbeitszeitCheckUtils && typeof window.ArbeitszeitCheckUtils.ajax === 'function') {
                    window.ArbeitszeitCheckUtils.ajax(completeUrl, {
                        method: 'POST',
                        data: { completed: true }
                    }).catch(function (err) {
                        const fallback = <?php echo json_encode($l->t('Could not save onboarding status. If this continues, ask an administrator to run the server upgrade.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                        const msg = (err && err.message) ? String(err.message) : fallback;
                        const region = document.getElementById('azc-alert-region');
                        if (region) {
                            region.textContent = msg;
                        }
                    });
                }
            }
        });
    }
</script>
        </div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>