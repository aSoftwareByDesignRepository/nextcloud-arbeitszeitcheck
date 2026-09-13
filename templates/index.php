<?php
declare(strict_types=1);

/**
 * Legacy multi-view template for arbeitszeitcheck.
 *
 * NOTE: This template is no longer rendered by any controller; `PageController`
 * renders `dashboard.php`, `time-entries.php`, `absences.php`, etc. directly.
 * It is kept here only to satisfy `AccessibilityTest` and to provide a
 * documented safety net in case a future route accidentally falls back to
 * the `index` template name. Times below are converted to the user's display
 * timezone via `templates/common/user-display-timezone.php`, which also
 * publishes the JS bootstrap (`window.ArbeitszeitCheck.tz` / `serverNow`).
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\ArbeitszeitCheck\Support\BadgeVariant;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;

/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

// Resolves the storage TZ, the user display TZ and emits the JS bootstrap.
/** @var \DateTimeZone $arbeitszeitCheckStorageTimeZone */
/** @var \DateTimeZone $arbeitszeitCheckUserDisplayTz */
/** @var string $arbeitszeitCheckServerNowIso */
require __DIR__ . '/common/user-display-timezone.php';

$status = $_['status'] ?? [];
$overtime = $_['overtime'] ?? [];
$recentEntries = $_['recentEntries'] ?? [];
try {
	$azcMaxDefault = (string)\OCP\Server::get(LaborLawProfileFactory::class)->getProfileForCurrentUser()->dailyMaxHoursDefault;
} catch (\Throwable) {
	$azcMaxDefault = '10';
}
$maxDailyHours = (float)\OCP\Server::get(\OCP\IConfig::class)->getAppValue('arbeitszeitcheck', 'max_daily_hours', $azcMaxDefault);

/**
 * Returns a display copy of the given DateTime converted into the user's
 * display timezone. Never mutates the original entity object.
 */
$arbeitszeitCheckToDisplayTz = static function (?\DateTimeInterface $dt) use ($arbeitszeitCheckUserDisplayTz): ?\DateTime {
    if (!$dt instanceof \DateTimeInterface) {
        return null;
    }
    $copy = $dt instanceof \DateTime ? clone $dt : \DateTime::createFromInterface($dt);
    $copy->setTimezone($arbeitszeitCheckUserDisplayTz);
    return $copy;
};

// Current session duration calculation for display
$currentSessionDuration = $status['current_session_duration'] ?? 0;
$hours = floor($currentSessionDuration / 3600);
$minutes = floor(($currentSessionDuration % 3600) / 60);
$seconds = $currentSessionDuration % 60;
$durationFormatted = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);

// Set page title based on view
$pageTitle = match($_['view'] ?? 'dashboard') {
    'dashboard' => $l->t('Dashboard'),
    'time-entries' => $l->t('Time Entries'),
    'absences' => $l->t('Absences'),
    'settings' => $l->t('Settings'),
    default => $l->t('ArbeitszeitCheck')
};

$content = '';
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<main id="app-content" role="main" aria-label="<?php p($l->t('Dashboard content')); ?>">
    <div id="app-content-wrapper">
        <div class="arbeitszeitcheck-content section">
            <?php if ($_['view'] === 'dashboard'): ?>
                <div class="arbeitszeitcheck-dashboard__header">
                    <h2><?php p($l->t('Dashboard')); ?></h2>
                </div>

                <div class="arbeitszeitcheck-dashboard__grid">
                    <!-- Status Card -->
                    <div class="arbeitszeitcheck-card status-card">
                        <div class="arbeitszeitcheck-card__title"><?php p($l->t('Current Status')); ?></div>
                        <div class="arbeitszeitcheck-card__content">
                            <div class="badge badge--<?php 
                                echo BadgeVariant::forClockStatus((string)($status['status'] ?? ''));
                            ?>">
                                <?php 
                                $statusLabel = match($status['status']) {
                                    'active' => $l->t('Clocked In'),
                                    'break' => $l->t('On Break'),
                                    'clocked_out' => $l->t('Clocked Out'),
                                    default => $status['status']
                                };
                                p($statusLabel);
                                ?>
                            </div>
                            
                            <?php if ($status['status'] !== 'clocked_out'): ?>
                                <div class="session-timer" data-start-time="<?php p($status['current_entry']['startTime'] ?? ''); ?>">
                                    <span class="timer-label"><?php p($l->t('Current Session:')); ?></span>
                                    <span class="timer-value"><?php p($durationFormatted); ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="card-actions" role="group" aria-label="<?php p($l->t('Time tracking actions')); ?>">
                                <?php if ($status['status'] === 'clocked_out'): ?>
                                    <button id="btn-clock-in" class="btn btn--primary" type="button"><?php p($l->t('Clock In')); ?></button>
                                <?php elseif ($status['status'] === 'active'): ?>
                                    <button id="btn-start-break" class="btn btn--secondary" type="button"><?php p($l->t('Start Break')); ?></button>
                                    <button class="btn btn--danger btn-clock-out" type="button"><?php p($l->t('Clock Out')); ?></button>
                                <?php elseif ($status['status'] === 'break'): ?>
                                    <button id="btn-end-break" class="btn btn--primary" type="button"><?php p($l->t('End Break')); ?></button>
                                    <button class="btn btn--danger btn-clock-out" type="button"><?php p($l->t('Clock Out')); ?></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Stats Card -->
                    <div class="arbeitszeitcheck-card stats-card">
                        <div class="arbeitszeitcheck-card__title"><?php p($l->t('Today\'s Stats')); ?></div>
                        <div class="arbeitszeitcheck-card__content">
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
                        </div>
                    </div>
                </div>

                <!-- Recent Entries -->
                <div class="arbeitszeitcheck-section recent-entries-section">
                    <h3><?php p($l->t('Recent Entries')); ?></h3>
                    <div class="table-container">
                        <table class="table table--hover azc-table--responsive arbeitszeitcheck-table" role="table" aria-label="<?php p($l->t('Recent time entries')); ?>">
                            <thead>
                                <tr>
                                    <th scope="col"><?php p($l->t('Date')); ?></th>
                                    <th scope="col"><?php p($l->t('Start')); ?></th>
                                    <th scope="col"><?php p($l->t('End')); ?></th>
                                    <th scope="col"><?php p($l->t('Duration')); ?></th>
                                    <th scope="col"><?php p($l->t('Status')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($_['recentEntries'] ?? [] as $entry):
                                    $rowStart = $arbeitszeitCheckToDisplayTz($entry->getStartTime());
                                    $rowEnd = $arbeitszeitCheckToDisplayTz($entry->getEndTime());
                                ?>
                                    <tr>
                                        <td data-label="<?php p($l->t('Date')); ?>"><?php p($rowStart ? $rowStart->format('d.m.Y') : '-'); ?></td>
                                        <td data-label="<?php p($l->t('Start')); ?>"><?php p($rowStart ? $rowStart->format('H:i') : '-'); ?></td>
                                        <td data-label="<?php p($l->t('End')); ?>"><?php
                                            if ($rowEnd && $rowStart) {
                                                $startDate = $rowStart->format('Y-m-d');
                                                $endDate = $rowEnd->format('Y-m-d');
                                                // Show date if end time is on a different day
                                                if ($startDate !== $endDate) {
                                                    p($rowEnd->format('d.m.Y H:i'));
                                                } else {
                                                    p($rowEnd->format('H:i'));
                                                }
                                            } else {
                                                p('-');
                                            }
                                        ?></td>
                                        <td data-label="<?php p($l->t('Duration')); ?>"><?php p(round($entry->getWorkingDurationHours() ?? 0, 2)); ?> h</td>
                                        <td data-label="<?php p($l->t('Status')); ?>"><span class="badge badge--<?php 
                                            p(BadgeVariant::forTimeEntryStatus((string)$entry->getStatus()));
                                        ?>"><?php
                                            $entryStatusKey = $entry->getStatus();
                                            p(match($entryStatusKey) {
                                                'completed' => $l->t('Completed'),
                                                'active' => $l->t('Active'),
                                                'pending_approval' => $l->t('Pending Approval'),
                                                'break' => $l->t('Break'),
                                                'paused' => $l->t('Paused'),
                                                'rejected' => $l->t('Rejected'),
                                                default => $entryStatusKey
                                            });
                                        ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($_['recentEntries'])): ?>
                                    <tr>
                                        <td colspan="5" class="empty-state"><?php p($l->t('No recent entries found')); ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($_['view'] === 'time-entries'): ?>
                <div class="arbeitszeitcheck-header">
                    <h2><?php p($l->t('Time Entries')); ?></h2>
                </div>
                <div class="table-container">
                    <table class="table table--hover azc-table--responsive arbeitszeitcheck-table" role="table" aria-label="<?php p($l->t('Time entries list')); ?>">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Date')); ?></th>
                                <th scope="col"><?php p($l->t('Start')); ?></th>
                                <th scope="col"><?php p($l->t('End')); ?></th>
                                <th scope="col"><?php p($l->t('Duration')); ?></th>
                                <th scope="col"><?php p($l->t('Status')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($_['entries'] ?? [])): ?>
                                <tr>
                                    <td colspan="5" class="empty-state"><?php p($l->t('No time entries found')); ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($_['entries'] as $entry):
                                    $rowStart = $arbeitszeitCheckToDisplayTz($entry->getStartTime());
                                    $rowEnd = $arbeitszeitCheckToDisplayTz($entry->getEndTime());
                                ?>
                                    <tr>
                                        <td data-label="<?php p($l->t('Date')); ?>"><?php p($rowStart ? $rowStart->format('d.m.Y') : '-'); ?></td>
                                        <td data-label="<?php p($l->t('Start')); ?>"><?php p($rowStart ? $rowStart->format('H:i') : '-'); ?></td>
                                        <td data-label="<?php p($l->t('End')); ?>"><?php p($rowEnd ? $rowEnd->format('H:i') : '-'); ?></td>
                                        <td data-label="<?php p($l->t('Duration')); ?>"><?php p(round($entry->getWorkingDurationHours() ?? 0, 2)); ?> h</td>
                                        <td data-label="<?php p($l->t('Status')); ?>"><span class="badge badge--<?php
                                            p(BadgeVariant::forTimeEntryStatus((string)$entry->getStatus()));
                                        ?>"><?php
                                            $statusKey = $entry->getStatus();
                                            $statusLabel = match($statusKey) {
                                                'completed' => $l->t('Completed'),
                                                'active' => $l->t('Active'),
                                                'pending_approval' => $l->t('Pending Approval'),
                                                default => $statusKey
                                            };
                                            p($statusLabel);
                                        ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($_['view'] === 'absences'): ?>
                <div class="arbeitszeitcheck-header">
                    <h2><?php p($l->t('Absences')); ?></h2>
                </div>
                <div class="table-container">
                    <table class="table table--hover azc-table--responsive arbeitszeitcheck-table" role="table" aria-label="<?php p($l->t('Absence requests list')); ?>">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Type')); ?></th>
                                <th scope="col"><?php p($l->t('Start Date')); ?></th>
                                <th scope="col"><?php p($l->t('End Date')); ?></th>
                                <th scope="col"><?php p($l->t('Status')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($_['absences'] ?? [] as $absence): ?>
                                <tr>
                                    <td data-label="<?php p($l->t('Type')); ?>"><?php
                                        $typeKey = $absence->getType();
                                        $typeLabel = match($typeKey) {
                                            'vacation' => $l->t('Vacation'),
                                            'sick', 'sick_leave' => $l->t('Sick Leave'),
                                            'personal_leave' => $l->t('Personal Leave'),
                                            'parental_leave' => $l->t('Parental Leave'),
                                            'special_leave' => $l->t('Special Leave'),
                                            'unpaid_leave' => $l->t('Unpaid Leave'),
                                            'home_office' => $l->t('Home Office'),
                                            'business_trip' => $l->t('Business Trip'),
                                            default => $l->t('Absence')
                                        };
                                        p($typeLabel);
                                    ?></td>
                                    <td data-label="<?php p($l->t('Start Date')); ?>"><?php p($absence->getStartDate()->format('d.m.Y')); ?></td>
                                    <td data-label="<?php p($l->t('End Date')); ?>"><?php p($absence->getEndDate()->format('d.m.Y')); ?></td>
                                    <td data-label="<?php p($l->t('Status')); ?>"><span class="badge"><?php
                                        $statusKey = $absence->getStatus();
                                        p(match($statusKey) {
                                            'approved' => $l->t('Approved'),
                                            'pending' => $l->t('Pending'),
                                            'rejected' => $l->t('Rejected'),
                                            default => $l->t(ucfirst($statusKey))
                                        });
                                    ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($_['absences'])): ?>
                                <tr>
                                    <td colspan="4" class="empty-state"><?php p($l->t('No absences found')); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($_['view'] === 'settings'): ?>
                <div class="arbeitszeitcheck-header">
                    <h2><?php p($l->t('Settings')); ?></h2>
                </div>
                <div class="arbeitszeitcheck-card">
                    <p>
                        <?php p($l->t('ArbeitszeitCheck version:')); ?>
                        <?php p(\OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion('arbeitszeitcheck')); ?>
                    </p>
                    <p><?php p($l->t('User:')); ?> <?php $user = \OCP\Server::get(\OCP\IUserSession::class)->getUser(); p($user ? $user->getDisplayName() : ''); ?> (<?php p($user ? $user->getUID() : ''); ?>)</p>
                </div>

            <?php else: ?>
                <div class="arbeitszeitcheck-header">
                    <h2><?php p(ucfirst($_['view'])); ?></h2>
                    <p class="empty-state"><?php p($l->t('This view is currently being migrated.')); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
</div><!-- /#arbeitszeitcheck-app -->

<!-- Initialize JavaScript -->
<?php include __DIR__ . '/common/hours-display-bootstrap.php'; ?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    // Pass essential data to JS
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.status = <?php echo json_encode($status, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.maxDailyHours = <?php echo json_encode($maxDailyHours, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.hoursDisplay = <?php echo json_encode($azcHoursDisplayMode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
    window.ArbeitszeitCheck.l10n.clockIn = <?php echo json_encode($l->t('Clock In'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.clockOut = <?php echo json_encode($l->t('Clock Out'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.apiUrl = <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.index'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>.replace('/time-entries', '/api');
</script>
