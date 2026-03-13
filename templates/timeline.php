<?php
declare(strict_types=1);

/**
 * Timeline template for arbeitszeitcheck app
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
Util::addStyle('arbeitszeitcheck', 'timeline');
Util::addScript('arbeitszeitcheck', 'common/utils', 'core');
Util::addScript('arbeitszeitcheck', 'arbeitszeitcheck-main', 'core');

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb-container">
            <nav class="breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
                <ol>
                    <li><a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"><?php p($l->t('Dashboard')); ?></a></li>
                    <li aria-current="page"><?php p($l->t('Timeline')); ?></li>
                </ol>
            </nav>
        </div>

        <!-- Page Header -->
        <header class="section page-header-section" aria-labelledby="timeline-page-title">
            <div class="header-content">
                <div class="header-text">
                    <h2 id="timeline-page-title"><?php p($l->t('Timeline')); ?></h2>
                    <p><?php p($l->t('View your working time history in chronological order')); ?></p>
                </div>
                <div class="header-actions">
                    <button id="btn-refresh-timeline" class="btn btn--secondary" type="button" aria-label="<?php p($l->t('Refresh timeline data')); ?>">
                        <?php p($l->t('Refresh')); ?>
                    </button>
                </div>
            </div>
        </header>

        <!-- Timeline Content -->
        <section class="section" aria-label="<?php p($l->t('Timeline of working time')); ?>">
            <div id="timeline-filters" class="timeline-filters" role="group" aria-labelledby="timeline-filters-heading">
                <h3 id="timeline-filters-heading" class="timeline-filters__title"><?php p($l->t('Show in timeline')); ?></h3>
                <div class="timeline-filters__options">
                    <label class="timeline-filters__option">
                        <input type="checkbox" id="timeline-filter-time-entries" class="timeline-filters__checkbox" checked>
                        <span class="timeline-filters__label"><?php p($l->t('Time Entries')); ?></span>
                    </label>
                    <label class="timeline-filters__option">
                        <input type="checkbox" id="timeline-filter-absences" class="timeline-filters__checkbox" checked>
                        <span class="timeline-filters__label"><?php p($l->t('Absences')); ?></span>
                    </label>
                    <label class="timeline-filters__option">
                        <input type="checkbox" id="timeline-filter-holidays" class="timeline-filters__checkbox" checked>
                        <span class="timeline-filters__label"><?php p($l->t('Holidays')); ?></span>
                    </label>
                </div>
            </div>
            <div id="timeline-container" class="timeline-container">
                <div class="timeline-loading">
                    <div class="loading-spinner"></div>
                    <p><?php p($l->t('Loading timeline...')); ?></p>
                </div>
            </div>
        </section>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<!-- Initialize JavaScript -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.page = 'timeline';
    
    window.ArbeitszeitCheck.apiUrl = {
        timeEntries: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiIndex'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        absences: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.absence.index'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        holidays: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.holiday.index'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };
    
    // L10n strings
    window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
    window.ArbeitszeitCheck.l10n.loadingTimeline = <?php echo json_encode($l->t('Loading timeline...'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.showInTimeline = <?php echo json_encode($l->t('Show in timeline'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.timeEntries = <?php echo json_encode($l->t('Time Entries'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.absences = <?php echo json_encode($l->t('Absences'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.holidays = <?php echo json_encode($l->t('Holidays'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.selectAtLeastOneFilter = <?php echo json_encode($l->t('Select at least one type to display in the timeline.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.noTimelineData = <?php echo json_encode($l->t('No timeline data available'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.months = <?php echo json_encode([
        $l->t('January'), $l->t('February'), $l->t('March'), $l->t('April'),
        $l->t('May'), $l->t('June'), $l->t('July'), $l->t('August'),
        $l->t('September'), $l->t('October'), $l->t('November'), $l->t('December')
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.weekdays = <?php echo json_encode([
        $l->t('Sunday'), $l->t('Monday'), $l->t('Tuesday'), $l->t('Wednesday'),
        $l->t('Thursday'), $l->t('Friday'), $l->t('Saturday')
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // Time entry / absence status labels for timeline (fully translated on the server)
    window.ArbeitszeitCheck.l10n.statusCompleted = <?php echo json_encode($l->t('Completed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.statusActive = <?php echo json_encode($l->t('Active'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.statusPaused = <?php echo json_encode($l->t('Paused'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.statusPending = <?php echo json_encode($l->t('Pending'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.statusApproved = <?php echo json_encode($l->t('Approved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.statusRejected = <?php echo json_encode($l->t('Rejected'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.statusSubstitutePending = <?php echo json_encode($l->t('Awaiting substitute approval'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.statusSubstituteDeclined = <?php echo json_encode($l->t('Declined by substitute'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // Shared labels
    window.ArbeitszeitCheck.l10n.breakTime = <?php echo json_encode($l->t('Break Time'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.publicHoliday = <?php echo json_encode($l->t('Public holiday'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.companyHoliday = <?php echo json_encode($l->t('Company holiday'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.customHoliday = <?php echo json_encode($l->t('Custom holiday'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    window.ArbeitszeitCheck.l10n.coveringFor = <?php echo json_encode($l->t('Covering for %1$s', ['%1$s']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    // Absence type labels used in the timeline (and reused by calendar)
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
</script>
