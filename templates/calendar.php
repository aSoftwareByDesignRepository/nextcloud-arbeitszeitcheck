<?php
declare(strict_types=1);

/**
 * Calendar template for arbeitszeitcheck app
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
Util::addStyle('arbeitszeitcheck', 'calendar');
Util::addScript('arbeitszeitcheck', 'common/utils', 'core');
Util::addScript('arbeitszeitcheck', 'arbeitszeitcheck-main', 'core');

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$currentMonth = $_['currentMonth'] ?? date('Y-m');
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb-container">
            <nav class="breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
                <ol>
                    <li><a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"><?php p($l->t('Dashboard')); ?></a></li>
                    <li aria-current="page"><?php p($l->t('Calendar')); ?></li>
                </ol>
            </nav>
        </div>

        <!-- Page Header -->
        <header class="section page-header-section" aria-labelledby="calendar-page-title calendar-page-desc">
            <div class="header-content">
                <div class="header-text">
                    <h2 id="calendar-page-title"><?php p($l->t('Calendar')); ?></h2>
                    <p id="calendar-page-desc" class="page-description"><?php p($l->t('See your working hours and absences at a glance. Click a day for details.')); ?></p>
                </div>
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
            </div>
        </header>

        <!-- Calendar Navigation -->
        <section class="section calendar-section" aria-labelledby="current-period-label" aria-label="<?php p($l->t('Calendar view')); ?>">
            <div class="calendar-nav">
                <button id="btn-prev-period" class="btn-nav" type="button" aria-label="<?php p($l->t('Previous month')); ?>" title="<?php p($l->t('Previous month')); ?>">
                    ◀ <?php p($l->t('Previous')); ?>
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
                    <?php p($l->t('Next')); ?> ▶
                </button>
            </div>

            <!-- Calendar Grid -->
            <div id="calendar-container" class="calendar-container">
                <div class="calendar-grid" id="calendar-month-view">
                    <!-- Calendar will be rendered here by JavaScript -->
                    <div class="calendar-loading">
                        <div class="loading-spinner"></div>
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
                        <span class="legend-label"><?php p($l->t('Day with absence')); ?></span>
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
                </div>
            </div>

            <!-- Day Details Panel (Initially Hidden) -->
            <div id="day-details-panel" class="day-details-panel" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="selected-date-label" aria-label="<?php p($l->t('Day details')); ?>">
                <div class="panel-header">
                    <h3 id="selected-date-label"></h3>
                    <button id="btn-close-panel" class="btn-close" type="button" aria-label="<?php p($l->t('Close')); ?>">✕</button>
                </div>
                <div class="panel-content" id="day-details-content">
                    <!-- Day details will be loaded here -->
                </div>
            </div>
        </section>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

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
        holidays: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.holiday.index'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };
</script>
