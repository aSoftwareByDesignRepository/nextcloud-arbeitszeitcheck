<?php
declare(strict_types=1);

use OCA\ArbeitszeitCheck\Service\IconCatalog;

/**
 * Calendar template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */

// Assets registered by PageController::registerFrontEndAssets

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$currentMonth = $_['currentMonth'] ?? date('Y-m');
$timeCapture = is_array($_['timeCapture'] ?? null) ? $_['timeCapture'] : [
	'clockStampingEnabled' => true,
	'manualTimeEntryEnabled' => true,
];
$timeEntryCreateUrl = (string)($_['timeEntryCreateUrl'] ?? $urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.create'));
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <div class="header-actions">
            <div class="view-toggle" role="group" aria-label="<?php p($l->t('View')); ?>">
                <button id="btn-month-view" class="btn btn--secondary active" type="button" data-view="month" aria-pressed="true">
                    <?php p($l->t('Month')); ?>
                </button>
                <button id="btn-week-view" class="btn btn--secondary" type="button" data-view="week" aria-pressed="false">
                    <?php p($l->t('Week')); ?>
                </button>
            </div>
            <button id="btn-today" class="btn btn--secondary" type="button" aria-label="<?php p($l->t('Go to today')); ?>">
                <?php p($l->t('Today')); ?>
            </button>
        </div>

        <!-- Calendar Navigation -->
        <section class="section calendar-section" aria-labelledby="current-period-label" aria-label="<?php p($l->t('Calendar view')); ?>">
            <div class="calendar-nav">
                <button id="btn-prev-period" class="btn-nav" type="button" aria-label="<?php p($l->t('Previous month')); ?>" title="<?php p($l->t('Previous month')); ?>">
                    <span class="btn-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('chevron-left', 'btn-nav__icon-svg')); ?></span>
                    <span class="btn-nav__label"><?php p($l->t('Previous')); ?></span>
                </button>
                <h3 id="current-period-label" class="period-label">
                    <?php 
                    $monthNum = date('n', strtotime($currentMonth . '-01'));
                    $monthNames = [
                        1 => $l->t('January'), 2 => $l->t('February'), 3 => $l->t('March'), 4 => $l->t('April'),
                        5 => $l->t('May'), 6 => $l->t('June'), 7 => $l->t('July'), 8 => $l->t('August'),
                        9 => $l->t('September'), 10 => $l->t('October'), 11 => $l->t('November'), 12 => $l->t('December')
                    ];
                    p($monthNames[$monthNum] . ' ' . date('Y', strtotime($currentMonth . '-01')));
                    ?>
                </h3>
                <button id="btn-next-period" class="btn-nav" type="button" aria-label="<?php p($l->t('Next month')); ?>" title="<?php p($l->t('Next month')); ?>">
                    <span class="btn-nav__label"><?php p($l->t('Next')); ?></span>
                    <span class="btn-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('chevron-right', 'btn-nav__icon-svg')); ?></span>
                </button>
            </div>

            <!-- Calendar Grid -->
            <div id="calendar-container" class="calendar-container">
                <div class="calendar-grid" id="calendar-month-view">
                    <!-- Calendar will be rendered here by JavaScript -->
                    <div class="calendar-loading" role="status" aria-live="polite">
                        <div class="loading-spinner" aria-hidden="true"></div>
                        <p><?php p($l->t('Loading calendar...')); ?></p>
                    </div>
                </div>

                <div class="calendar-grid" id="calendar-week-view" style="display: none;">
                    <!-- Week view will be rendered here by JavaScript -->
                </div>
            </div>

            <!-- Calendar Legend -->
            <div class="calendar-legend" aria-labelledby="calendar-legend-heading">
                <h4 id="calendar-legend-heading"><?php p($l->t('Legend')); ?></h4>
                <div class="legend-items" role="list">
                    <div class="legend-item" role="listitem">
                        <span class="legend-color legend-color--entry" aria-hidden="true"></span>
                        <span class="legend-label"><?php p($l->t('Day with time entries')); ?></span>
                    </div>
                    <div class="legend-item" role="listitem">
                        <span class="legend-color legend-color--absence" aria-hidden="true"></span>
                        <span class="legend-label"><?php p($l->t('Day with absence (type and status shown on the day)')); ?></span>
                    </div>
                    <div class="legend-item" role="listitem">
                        <span class="legend-sample-chip" aria-hidden="true">
                            <span class="badge badge--success"><?php p($l->t('Approved')); ?></span>
                        </span>
                        <span class="legend-label"><?php p($l->t('Approved absence')); ?></span>
                    </div>
                    <div class="legend-item" role="listitem">
                        <span class="legend-sample-chip" aria-hidden="true">
                            <span class="badge badge--warning"><?php p($l->t('Pending')); ?></span>
                        </span>
                        <span class="legend-label"><?php p($l->t('Absence awaiting approval')); ?></span>
                    </div>
                    <div class="legend-item" role="listitem">
                        <span class="legend-color legend-color--coverage" aria-hidden="true"></span>
                        <span class="legend-label"><?php p($l->t('Covering for colleague')); ?></span>
                    </div>
                    <div class="legend-item" role="listitem">
                        <span class="legend-color legend-color--holiday" aria-hidden="true"></span>
                        <span class="legend-label">
                            <?php p($l->t('Public holiday')); ?>
                        </span>
                    </div>
                    <div class="legend-item" role="listitem">
                        <span class="legend-color legend-color--company-holiday" aria-hidden="true"></span>
                        <span class="legend-label">
                            <?php p($l->t('Company holiday')); ?>
                        </span>
                    </div>
                    <div class="legend-item" role="listitem">
                        <span class="legend-color legend-color--today" aria-hidden="true"></span>
                        <span class="legend-label"><?php p($l->t('Today')); ?></span>
                    </div>
                    <div class="legend-item" role="listitem">
                        <span class="legend-color legend-color--past-absence" aria-hidden="true"></span>
                        <span class="legend-label"><?php p($l->t('Historical absence (already ended)')); ?></span>
                    </div>
                </div>
            </div>

            <!-- Day details side drawer (moved to document.body when opened; no full-screen backdrop) -->
            <div id="day-details-panel" class="day-details-panel" hidden role="dialog" aria-modal="false" aria-labelledby="selected-date-label" aria-label="<?php p($l->t('Day details')); ?>">
                <div class="panel-header">
                    <h3 id="selected-date-label" tabindex="-1" aria-live="polite"></h3>
                    <button id="btn-close-panel" class="btn-close" type="button" aria-label="<?php p($l->t('Close')); ?>">
                        <span aria-hidden="true"><?php print_unescaped(IconCatalog::render('x', 'btn-close__icon')); ?></span>
                    </button>
                </div>
                <div class="panel-content" id="day-details-content">
                    <!-- Day details will be loaded here -->
                </div>
            </div>
        </section>

<?php include __DIR__ . '/common/main-ui-l10n.php'; ?>

<!-- Initialize JavaScript -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.page = 'calendar';
    window.ArbeitszeitCheck.currentView = 'month';
    window.ArbeitszeitCheck.currentMonth = <?php echo json_encode($currentMonth, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    
    window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
    window.ArbeitszeitCheck.l10n.months = <?php echo json_encode([
        $l->t('January'), $l->t('February'), $l->t('March'), $l->t('April'),
        $l->t('May'), $l->t('June'), $l->t('July'), $l->t('August'),
        $l->t('September'), $l->t('October'), $l->t('November'), $l->t('December')
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.weekdays = <?php echo json_encode([
        $l->t('Sunday'), $l->t('Monday'), $l->t('Tuesday'), $l->t('Wednesday'),
        $l->t('Thursday'), $l->t('Friday'), $l->t('Saturday')
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.weekdaysShort = <?php echo json_encode([
        $l->t('Sun'), $l->t('Mon'), $l->t('Tue'), $l->t('Wed'),
        $l->t('Thu'), $l->t('Fri'), $l->t('Sat')
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.loadingCalendar = <?php echo json_encode($l->t('Loading calendar...'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.noEntries = <?php echo json_encode($l->t('No entries for this day'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.today = <?php echo json_encode($l->t('Today'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.clickForDetails = <?php echo json_encode($l->t('Click for details'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.noEntriesThisMonth = <?php echo json_encode($l->t('No time entries or absences for this month.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.hours = <?php echo json_encode($l->t('hours'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.timeEntries = <?php echo json_encode($l->t('Time Entries'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.absences = <?php echo json_encode($l->t('Absences'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.totalHours = <?php echo json_encode($l->t('Total Hours'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.workingDays = <?php echo json_encode($l->t('Working Days'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.holiday = <?php echo json_encode($l->t('Public holiday'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.breakTime = <?php echo json_encode($l->t('Break Time'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.coveringFor = <?php echo json_encode($l->t('Covering for %1$s', ['%1$s']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.pastRecord = <?php echo json_encode($l->t('Past record'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.requestAbsenceThisDay = <?php echo json_encode($l->t('Request absence for this day'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.requestAbsenceThisDayHelp = <?php echo json_encode($l->t('Request absence (opens form with this day prefilled). Past dates are allowed for migration.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.addWorkingTimeThisDay = <?php echo json_encode($l->t('Add working time'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.addWorkingTimeThisDayHelp = <?php echo json_encode($l->t('Opens the time entry form with this day prefilled. Breaks and compliance checks stay the same as under Time entries.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.dayPanelShortHelp = <?php echo json_encode($l->t('Pick one: add hours or request absence for this day.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.dayPanelActions = <?php echo json_encode($l->t('Add working time or absence'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.historicalAbsenceLegend = <?php echo json_encode($l->t('Historical absence (already ended)'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.statusPending = <?php echo json_encode($l->t('Pending'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.statusApproved = <?php echo json_encode($l->t('Approved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.statusRejected = <?php echo json_encode($l->t('Rejected'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.statusSubstitutePending = <?php echo json_encode($l->t('Awaiting substitute approval'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.statusSubstituteDeclined = <?php echo json_encode($l->t('Declined by substitute'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.moreAbsencesOnDay = <?php echo json_encode($l->t('+{count} more'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.moreAbsencesOnDayTitle = <?php echo json_encode($l->t('Additional absences on this day'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.absenceTypes = {
        vacation: <?php echo json_encode($l->t('Vacation'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        holiday: <?php echo json_encode($l->t('Vacation'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        sick: <?php echo json_encode($l->t('Sick Leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        sick_leave: <?php echo json_encode($l->t('Sick Leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        personal_leave: <?php echo json_encode($l->t('Personal Leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        parental_leave: <?php echo json_encode($l->t('Parental Leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        special_leave: <?php echo json_encode($l->t('Special Leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        unpaid_leave: <?php echo json_encode($l->t('Unpaid Leave'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        home_office: <?php echo json_encode($l->t('Home Office'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        business_trip: <?php echo json_encode($l->t('Business Trip'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };

    window.ArbeitszeitCheck.apiUrl = {
        calendar: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiIndex'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        absences: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.index'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        holidays: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.holiday.index'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        absenceCreate: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.create'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        timeEntryCreate: <?php echo json_encode($timeEntryCreateUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };
    window.ArbeitszeitCheck.timeCapture = <?php echo json_encode([
        'clockStampingEnabled' => !empty($timeCapture['clockStampingEnabled']),
        'manualTimeEntryEnabled' => !empty($timeCapture['manualTimeEntryEnabled']),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
