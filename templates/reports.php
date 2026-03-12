<?php
declare(strict_types=1);

/**
 * Reports template for arbeitszeitcheck app
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
Util::addStyle('arbeitszeitcheck', 'reports');
Util::addScript('arbeitszeitcheck', 'common/utils');
Util::addScript('arbeitszeitcheck', 'common/datepicker');
Util::addScript('arbeitszeitcheck', 'arbeitszeitcheck-main');

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$isAdmin = $_['isAdmin'] ?? false;
$isManager = $_['isManager'] ?? false;
$canAccessReports = $isAdmin || $isManager;

// Whether app-owned teams are enabled (controls whether manager-specific team selection makes sense)
$config = \OCP\Server::get(\OCP\IConfig::class);
$useAppTeams = $config->getAppValue('arbeitszeitcheck', 'use_app_teams', '0') === '1';
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb-container">
            <nav class="breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
                <ol>
                    <li><a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"><?php p($l->t('Dashboard')); ?></a></li>
                    <li aria-current="page"><?php p($l->t('Reports')); ?></li>
                </ol>
            </nav>
        </div>

        <!-- Page Header -->
        <header class="section page-header-section" aria-labelledby="reports-page-title">
            <div class="header-content">
                <div class="header-text">
                    <h2 id="reports-page-title"><?php p($l->t('Reports')); ?></h2>
                    <p><?php p($l->t('Generate and export working time reports')); ?></p>
                </div>
            </div>
        </header>

        <!-- Step 1: Scope & Report Type Selection -->
        <section id="report-type-section" class="reports-section section" aria-labelledby="report-scope-heading" aria-label="<?php p($l->t('Select what you want to see')); ?>">
            <?php if (!$canAccessReports): ?>
                <div class="empty-state">
                    <h3 class="empty-state__title" id="report-type-heading"><?php p($l->t('Reports are only available for administrators and managers')); ?></h3>
                    <p class="empty-state__description">
                        <?php p($l->t('If you need to generate reports, please contact your administrator or manager.')); ?>
                    </p>
                </div>
            <?php else: ?>

            <!-- Scope selection: who should be included -->
            <div class="report-scope-section">
                <h3 id="report-scope-heading" class="reports-section__title"><?php p($l->t('Who should be included in the report?')); ?></h3>
                <p class="reports-section__desc">
                    <?php
                    if ($isAdmin) {
                        p($l->t('Choose whether you want a report for the whole organization or for a specific team.'));
                    } else {
                        p($l->t('Choose whether you want a report for everyone you manage.'));
                    }
                    ?>
                </p>

                <form id="report-scope-form" class="report-scope-form" aria-label="<?php p($l->t('Select report scope')); ?>">
                    <fieldset class="form-fieldset" aria-labelledby="scope-legend">
                        <legend id="scope-legend" class="form-legend"><?php p($l->t('Report scope')); ?></legend>

                        <?php if ($isAdmin): ?>
                            <div class="form-group">
                                <div class="form-radio">
                                    <input type="radio"
                                           id="scope-organization"
                                           name="report_scope"
                                           value="organization"
                                           checked
                                           aria-describedby="scope-organization-help">
                                    <label for="scope-organization" class="form-label">
                                        <?php p($l->t('Whole organization')); ?>
                                    </label>
                                </div>
                                <p id="scope-organization-help" class="form-help">
                                    <?php p($l->t('Includes all active employees in your Nextcloud instance.')); ?>
                                </p>
                            </div>

                            <div class="form-group">
                                <div class="form-radio">
                                    <input type="radio"
                                           id="scope-admin-team"
                                           name="report_scope"
                                           value="admin_team"
                                           aria-describedby="scope-admin-team-help">
                                    <label for="scope-admin-team" class="form-label">
                                        <?php p($l->t('Specific team')); ?>
                                    </label>
                                </div>
                                <p id="scope-admin-team-help" class="form-help">
                                    <?php p($l->t('Limit the report to one department or team that you configure in the administration area.')); ?>
                                </p>

                                <label for="admin-team-select" class="form-label visually-hidden">
                                    <?php p($l->t('Select team')); ?>
                                </label>
                                <select id="admin-team-select"
                                        name="admin_team_id"
                                        class="form-select"
                                        aria-describedby="admin-team-select-help"
                                        disabled>
                                    <option value=""><?php p($l->t('Select a team')); ?></option>
                                </select>
                                <p id="admin-team-select-help" class="form-help">
                                    <?php p($l->t('Only available if you have configured app-owned teams.')); ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="form-group">
                                <div class="form-radio">
                                    <input type="radio"
                                           id="scope-my-team"
                                           name="report_scope"
                                           value="manager_team"
                                           checked
                                           aria-describedby="scope-my-team-help">
                                    <label for="scope-my-team" class="form-label">
                                        <?php p($l->t('Everyone I manage')); ?>
                                    </label>
                                </div>
                                <p id="scope-my-team-help" class="form-help">
                                    <?php p($l->t('Includes all people you are responsible for as a manager.')); ?>
                                </p>
                            </div>

                            <?php if ($useAppTeams): ?>
                            <div class="form-group">
                                <div class="form-radio">
                                    <input type="radio"
                                           id="scope-manager-single-team"
                                           name="report_scope"
                                           value="manager_single_team"
                                           aria-describedby="scope-manager-single-team-help"
                                           disabled>
                                    <label for="scope-manager-single-team" class="form-label">
                                        <?php p($l->t('Specific managed team')); ?>
                                    </label>
                                </div>
                                <p id="scope-manager-single-team-help" class="form-help">
                                    <?php p($l->t('If you manage more than one team, you can choose one here.')); ?>
                                </p>

                                <label for="manager-team-select" class="form-label visually-hidden">
                                    <?php p($l->t('Select managed team')); ?>
                                </label>
                                <select id="manager-team-select"
                                        name="manager_team_id"
                                        class="form-select"
                                        aria-describedby="manager-team-select-help"
                                        disabled>
                                    <option value=""><?php p($l->t('Select a team you manage')); ?></option>
                                </select>
                                <p id="manager-team-select-help" class="form-help">
                                    <?php p($l->t('This list is filled automatically if app-owned teams are enabled.')); ?>
                                </p>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </fieldset>
                </form>
            </div>

            <div class="report-selection-section" aria-labelledby="report-type-heading">
                <h3 id="report-type-heading" class="reports-section__title"><?php p($l->t('What kind of report do you need?')); ?></h3>
                <p class="reports-section__desc"><?php p($l->t('First choose the scope above, then pick the type of report.')); ?></p>

                <div class="report-types-grid" role="list">
                    <div class="report-type-card" data-report-type="daily">
                        <div class="report-type-icon">📊</div>
                        <h4><?php p($l->t('Daily Report')); ?></h4>
                        <p><?php p($l->t('View working hours for a specific day')); ?></p>
                        <button class="btn-select-report" data-report="daily"><?php p($l->t('Generate')); ?></button>
                    </div>

                    <div class="report-type-card" data-report-type="weekly">
                        <div class="report-type-icon">📅</div>
                        <h4><?php p($l->t('Weekly Report')); ?></h4>
                        <p><?php p($l->t('Weekly summary of working time')); ?></p>
                        <button class="btn-select-report" data-report="weekly"><?php p($l->t('Generate')); ?></button>
                    </div>

                    <div class="report-type-card" data-report-type="monthly">
                        <div class="report-type-icon">📈</div>
                        <h4><?php p($l->t('Monthly Report')); ?></h4>
                        <p><?php p($l->t('Monthly working time overview')); ?></p>
                        <button class="btn-select-report" data-report="monthly"><?php p($l->t('Generate')); ?></button>
                    </div>

                    <div class="report-type-card" data-report-type="overtime">
                        <div class="report-type-icon">⏰</div>
                        <h4><?php p($l->t('Overtime Report')); ?></h4>
                        <p><?php p($l->t('Overtime balance and history')); ?></p>
                        <button class="btn-select-report" data-report="overtime"><?php p($l->t('Generate')); ?></button>
                    </div>

                    <div class="report-type-card" data-report-type="absence">
                        <div class="report-type-icon">🏖️</div>
                        <h4><?php p($l->t('Absence Report')); ?></h4>
                        <p><?php p($l->t('Vacation and absence overview')); ?></p>
                        <button class="btn-select-report" data-report="absence"><?php p($l->t('Generate')); ?></button>
                    </div>

                    <div class="report-type-card" data-report-type="compliance">
                        <div class="report-type-icon">✅</div>
                        <h4><?php p($l->t('Compliance Report')); ?></h4>
                        <p><?php p($l->t('German labor law compliance')); ?></p>
                        <button class="btn-select-report" data-report="compliance"><?php p($l->t('Generate')); ?></button>
                    </div>
                </div>
            </div>

            <!-- Step 2: Report Parameters -->
            <div id="report-parameters" class="reports-section report-parameters-section" style="display: none;" aria-labelledby="report-parameters-heading">
                <h3 id="report-parameters-heading" class="reports-section__title"><?php p($l->t('Set time period and format')); ?></h3>
                <p class="reports-section__desc">
                    <?php p($l->t('Pick the time period for your report. For daily or weekly reports, we will adjust the dates automatically.')); ?>
                </p>
                <form id="report-form" class="report-form" aria-label="<?php p($l->t('Report parameters')); ?>">
                    <input type="hidden" id="report-type" name="report_type" value="">
                    <input type="hidden" id="report-scope" name="report_scope" value="">
                    <input type="hidden" id="report-team-users" name="report_team_users" value="">
                    
                    <div class="form-group" id="start-date-group">
                        <label for="start-date" class="form-label">
                            <?php p($l->t('Start Date')); ?>
                            <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                        </label>
                        <input type="text"
                               id="start-date"
                               name="start_date"
                               class="form-input datepicker-input"
                               placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
                               pattern="\d{2}\.\d{2}\.\d{4}"
                               maxlength="10"
                               required
                               aria-describedby="start-date-help">
                        <p id="start-date-help" class="form-help">
                            <?php p($l->t('The first day to include in the report. Click the calendar icon to pick a date. Example: If you want a report for January, select January 1st.')); ?>
                        </p>
                    </div>
                    
                    <div class="form-group" id="end-date-group">
                        <label for="end-date" class="form-label">
                            <?php p($l->t('End Date')); ?>
                            <span class="form-required" aria-label="<?php p($l->t('required')); ?>">*</span>
                        </label>
                        <input type="text"
                               id="end-date"
                               name="end_date"
                               class="form-input datepicker-input"
                               placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
                               pattern="\d{2}\.\d{2}\.\d{4}"
                               maxlength="10"
                               required
                               aria-describedby="end-date-help">
                        <p id="end-date-help" class="form-help">
                            <?php p($l->t('The last day to include in the report. Click the calendar icon to pick a date. Example: If you want a report for January, select January 31st.')); ?>
                        </p>
                    </div>
                    
                    <div class="form-group">
                        <label for="format" class="form-label">
                            <?php p($l->t('File Format')); ?>
                        </label>
                        <select id="format" 
                                name="format" 
                                class="form-select"
                                aria-describedby="format-help">
                            <option value="csv"><?php p($l->t('CSV (for Excel or other programs)')); ?></option>
                            <option value="json"><?php p($l->t('JSON (for computer programs)')); ?></option>
                        </select>
                        <p id="format-help" class="form-help">
                            <?php p($l->t('Choose how you want to save the report. CSV works well with spreadsheet programs. JSON is best if another system needs to process the data.')); ?>
                        </p>
                    </div>
                    
                    <div class="card-actions">
                        <button type="button" 
                                id="btn-preview-report" 
                                class="btn btn--secondary"
                                aria-label="<?php p($l->t('Preview the report before downloading')); ?>"
                                title="<?php p($l->t('Click to see what the report will look like before downloading it')); ?>">
                            <?php p($l->t('Preview')); ?>
                        </button>
                        <button type="submit" 
                                id="btn-generate-report" 
                                class="btn btn--primary"
                                aria-label="<?php p($l->t('Generate and download the report')); ?>"
                                title="<?php p($l->t('Click to create the report and download it to your computer')); ?>">
                            <?php p($l->t('Generate & Download')); ?>
                        </button>
                        <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"
                           class="btn btn--secondary"
                           aria-label="<?php p($l->t('Cancel and go back')); ?>"
                           title="<?php p($l->t('Click to cancel and go back without generating a report')); ?>">
                            <?php p($l->t('Cancel')); ?>
                        </a>
                    </div>
                </form>
            </div>

            <!-- Report Preview -->
            <section id="report-preview" class="reports-section report-preview-section" style="display: none;" aria-labelledby="report-preview-heading">
                <h3 id="report-preview-heading" class="reports-section__title" tabindex="-1"><?php p($l->t('Report Preview')); ?></h3>
                <div id="report-preview-live" class="visually-hidden" aria-live="polite" aria-atomic="true"></div>
                <div id="report-preview-content" class="report-preview-content">
                    <!-- Preview will be loaded here dynamically -->
                </div>
            </section>
            <?php endif; ?>
        </section>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<!-- Initialize JavaScript -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.page = 'reports';
    window.ArbeitszeitCheck.canAccessReports = <?php echo json_encode($canAccessReports, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.isAdmin = <?php echo json_encode($isAdmin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.isManager = <?php echo json_encode($isManager, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    
    window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
    window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.generating = <?php echo json_encode($l->t('Generating report...'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.reportReady = <?php echo json_encode($l->t('Report generated successfully'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.sessionExpired = <?php echo json_encode($l->t('Your session may have expired. Please refresh the page and try again.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.period = <?php echo json_encode($l->t('Period'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.date = <?php echo json_encode($l->t('Date'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.totalHours = <?php echo json_encode($l->t('Total Hours'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.hours = <?php echo json_encode($l->t('Hours'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.violations = <?php echo json_encode($l->t('Violations'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.overtime = <?php echo json_encode($l->t('Overtime'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.users = <?php echo json_encode($l->t('Users'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.name = <?php echo json_encode($l->t('Name'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.dailyBreakdown = <?php echo json_encode($l->t('Daily breakdown'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.scopeRequired = <?php echo json_encode($l->t('Please choose who should be included in the report.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.teamRequired = <?php echo json_encode($l->t('Please select a team.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    
    window.ArbeitszeitCheck.apiUrl = {
        daily: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.daily'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        weekly: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.weekly'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        monthly: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.monthly'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        overtime: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.overtime'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        absence: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.absence'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        compliance: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.compliance.getReport'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        team: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.team'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };
    // Export endpoints that trigger real file downloads
    window.ArbeitszeitCheck.exportUrl = {
        timeEntries: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.export.timeEntries'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        absences: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.export.absences'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        compliance: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.export.compliance'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };
    
    <?php if ($canAccessReports): ?>
    // Initialize reports functionality
    document.addEventListener('DOMContentLoaded', function() {
        const reportCards = document.querySelectorAll('.report-type-card');
        const reportButtons = document.querySelectorAll('.btn-select-report');
        const reportParameters = document.getElementById('report-parameters');
        const reportForm = document.getElementById('report-form');
        const reportTypeInput = document.getElementById('report-type');
        const reportScopeInput = document.getElementById('report-scope');
        const reportTeamUsersInput = document.getElementById('report-team-users');
        const startDateInput = document.getElementById('start-date');
        const endDateInput = document.getElementById('end-date');
        const formatSelect = document.getElementById('format');
        const previewBtn = document.getElementById('btn-preview-report');
        const generateBtn = document.getElementById('btn-generate-report');
        const scopeForm = document.getElementById('report-scope-form');
        const startDateGroup = document.getElementById('start-date-group');
        const endDateGroup = document.getElementById('end-date-group');

        // Helper: get request token safely
        
        function getRequestToken() {
            return (typeof OC !== 'undefined' && OC.requestToken)
                ? OC.requestToken
                : (document.querySelector('head') && document.querySelector('head').getAttribute('data-requesttoken')) || '';
        }

        // Helper: announce status to screen reader
        function announceToScreenReader(message) {
            var live = document.getElementById('report-preview-live');
            if (live) {
                live.textContent = '';
                live.setAttribute('aria-live', 'polite');
                setTimeout(function() { live.textContent = message; }, 100);
            }
        }

        // Helper: escape HTML
        function esc(s) {
            if (s == null) return '';
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        // Scope handling: keep a clean, explicit state
        var scopeState = {
            scope: '',
            adminTeamId: '',
            managerTeamId: '',
            teamUserIds: []
        };

        function updateScopeFromForm() {
            if (!scopeForm) return;
            var formData = new FormData(scopeForm);
            var scope = formData.get('report_scope') || '';
            scopeState.scope = scope;

            if (window.ArbeitszeitCheck.isAdmin) {
                scopeState.adminTeamId = (formData.get('admin_team_id') || '').trim();
            } else if (window.ArbeitszeitCheck.isManager) {
                scopeState.managerTeamId = (formData.get('manager_team_id') || '').trim();
            }

            if (reportScopeInput) {
                reportScopeInput.value = scope || '';
            }
        }

        // Load admin teams for selection (only once)
        function loadAdminTeamsIfNeeded() {
            if (!window.ArbeitszeitCheck.isAdmin) return;
            var select = document.getElementById('admin-team-select');
            if (!select || select.dataset.loaded === 'true') return;

            var url = OC.generateUrl('/apps/arbeitszeitcheck/api/admin/teams');
            fetch(url, { method: 'GET', headers: { requesttoken: getRequestToken() } })
                .then(function (res) { return res.ok ? res.json() : null; })
                .then(function (data) {
                    if (!data || !data.success || !Array.isArray(data.teams)) {
                        return;
                    }
                    // Mark that we tried loading; even if empty we avoid repeated fetches
                    select.dataset.loaded = 'true';
                    data.teams.forEach(function (team) {
                        var opt = document.createElement('option');
                        opt.value = String(team.id);
                        opt.textContent = team.name || ('#' + team.id);
                        select.appendChild(opt);
                    });
                })
                .catch(function () {
                    // Fail silently – scope selection still works with organization-wide reports
                });
        }

        // Load manager-managed teams when app-owned teams are enabled
        function loadManagerTeamsIfNeeded() {
            if (!window.ArbeitszeitCheck.isManager) return;
            var select = document.getElementById('manager-team-select');
            var scopeRadio = document.getElementById('scope-manager-single-team');
            if (!select || select.dataset.loaded === 'true') return;

            var url = OC.generateUrl('/apps/arbeitszeitcheck/api/manager/teams');
            fetch(url, { method: 'GET', headers: { requesttoken: getRequestToken() } })
                .then(function (res) { return res.ok ? res.json() : null; })
                .then(function (data) {
                    if (!data || !data.success || !Array.isArray(data.teams)) {
                        return;
                    }
                    select.dataset.loaded = 'true';
                    if (data.teams.length === 0) {
                        // No app-owned teams: keep radio disabled, scope "everyone I manage" still works
                        return;
                    }
                    // Enable the single-team scope option only when at least one team is available
                    if (scopeRadio) {
                        scopeRadio.disabled = false;
                    }
                    data.teams.forEach(function (team) {
                        var opt = document.createElement('option');
                        opt.value = String(team.id);
                        opt.textContent = team.name || ('#' + team.id);
                        select.appendChild(opt);
                    });
                })
                .catch(function () {
                    // Fail silently – manager can still use aggregated team scope
                });
        }

        // React to changes in scope radios and team selects
        if (scopeForm) {
            scopeForm.addEventListener('change', function (e) {
                var target = e.target;
                if (!target) return;

                // Enable/disable team selects based on active scope
                var scopeOrg = document.getElementById('scope-organization');
                var scopeAdminTeam = document.getElementById('scope-admin-team');
                var scopeManagerTeam = document.getElementById('scope-my-team');
                var scopeManagerSingleTeam = document.getElementById('scope-manager-single-team');
                var adminTeamSelect = document.getElementById('admin-team-select');
                var managerTeamSelect = document.getElementById('manager-team-select');

                // Admin: toggle team select
                if (scopeAdminTeam && adminTeamSelect) {
                    var adminTeamActive = scopeAdminTeam.checked;
                    adminTeamSelect.disabled = !adminTeamActive;
                    if (adminTeamActive) {
                        loadAdminTeamsIfNeeded();
                    }
                }

                // Manager: toggle team select
                if (scopeManagerSingleTeam && managerTeamSelect) {
                    var managerSingleActive = scopeManagerSingleTeam.checked;
                    managerTeamSelect.disabled = !managerSingleActive;
                    if (managerSingleActive) {
                        loadManagerTeamsIfNeeded();
                    }
                }

                updateScopeFromForm();
            });

            // Initialize scope state once
            updateScopeFromForm();
            if (window.ArbeitszeitCheck.isAdmin) {
                loadAdminTeamsIfNeeded();
            } else if (window.ArbeitszeitCheck.isManager) {
                loadManagerTeamsIfNeeded();
            }
        }

        // Handle report card clicks
        reportCards.forEach(card => {
            card.addEventListener('click', function(e) {
                // Don't trigger if clicking the button
                if (e.target.classList.contains('btn-select-report')) {
                    return;
                }
                const button = card.querySelector('.btn-select-report');
                if (button) {
                    button.click();
                }
            });
        });
        
        // Handle report button clicks
        reportButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.stopPropagation();
                const reportType = this.dataset.report;
                if (reportType && reportTypeInput) {
                    reportTypeInput.value = reportType;
                    
                    // Ensure scope is up to date before showing parameters
                    updateScopeFromForm();

                    // For daily and weekly reports we only really need one anchor date; we keep both fields visible
                    // for consistency but will adjust the API parameters later.

                    // Show parameters section
                    if (reportParameters) {
                        reportParameters.style.display = 'block';
                        reportParameters.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                    
                    // Set default dates (last 30 days) in dd.mm.yyyy format
                    const today = new Date();
                    const thirtyDaysAgo = new Date();
                    thirtyDaysAgo.setDate(today.getDate() - 30);
                    function toDDMMYYYY(d) {
                        const day = String(d.getDate()).padStart(2,'0');
                        const month = String(d.getMonth()+1).padStart(2,'0');
                        const year = d.getFullYear();
                        return day + '.' + month + '.' + year;
                    }
                    if (startDateInput) startDateInput.value = toDDMMYYYY(thirtyDaysAgo);
                    if (endDateInput) endDateInput.value = toDDMMYYYY(today);
                }
            });
        });
        
        // Build report URL with correct params per type (API expects specific param names)
        function buildReportUrl(apiUrl, reportType, startDate, endDate) {
            const url = new URL(apiUrl, window.location.origin);
            if (reportType === 'daily') {
                url.searchParams.set('date', startDate);
            } else if (reportType === 'weekly') {
                url.searchParams.set('weekStart', startDate);
            } else if (reportType === 'monthly') {
                url.searchParams.set('month', startDate.substring(0, 7));
            } else {
                url.searchParams.set('startDate', startDate);
                url.searchParams.set('endDate', endDate);
            }
            return url.toString();
        }
        // Format period for display (API returns object { start, end } or string)
        function formatPeriod(period) {
            if (period == null) return '';
            if (typeof period === 'string') return period;
            if (typeof period === 'object' && period.start != null && period.end != null) {
                return (period.start + ' – ' + period.end);
            }
            if (typeof period === 'object' && (period.start != null || period.end != null)) {
                return (period.start || '') + (period.end ? ' – ' + period.end : '');
            }
            return '';
        }
        // Render report data as HTML (never show raw JSON). Handles daily, weekly, monthly, overtime, absence, team, compliance.
        function renderReportHtml(report) {
            if (!report) return '';
            var L = window.ArbeitszeitCheck.l10n || {};
            var html = '<div class="report-result">';
            if (report.date) html += '<p class="report-meta"><strong>' + (L.date || 'Date') + ':</strong> ' + esc(report.date) + '</p>';
            var periodStr = formatPeriod(report.period);
            if (periodStr) html += '<p class="report-meta"><strong>' + (L.period || 'Period') + ':</strong> ' + esc(periodStr) + '</p>';
            if (report.total_hours != null) html += '<p class="report-meta"><strong>' + (L.totalHours || 'Total hours') + ':</strong> ' + esc(report.total_hours) + '</p>';
            if (report.totalHours != null && report.total_hours === undefined) html += '<p class="report-meta"><strong>' + (L.totalHours || 'Total hours') + ':</strong> ' + esc(report.totalHours) + '</p>';
            if (report.total_violations != null) html += '<p class="report-meta"><strong>' + (L.violations || 'Violations') + ':</strong> ' + esc(report.total_violations) + '</p>';
            if (report.violations_count != null) html += '<p class="report-meta"><strong>' + (L.violations || 'Violations') + ':</strong> ' + esc(report.violations_count) + '</p>';
            if (report.total_overtime != null) html += '<p class="report-meta"><strong>' + (L.overtime || 'Overtime') + ':</strong> ' + esc(report.total_overtime) + ' h</p>';
            if (report.entries && report.entries.length) {
                html += '<table class="report-table"><thead><tr><th>' + (L.date || 'Date') + '</th><th>' + (L.hours || 'Hours') + '</th></tr></thead><tbody>';
                report.entries.forEach(function(entry) {
                    html += '<tr><td>' + esc(entry.date || entry.start || '-') + '</td><td>' + esc(entry.hours != null ? entry.hours : (entry.duration || '-')) + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            if (report.members && report.members.length) {
                // Team report (aggregated members)
                html += '<h4 class="report-subhead">' + (L.users || 'Users') + '</h4><table class="report-table"><thead><tr><th>' + (L.name || 'Name') + '</th><th>' + (L.hours || 'Hours') + '</th><th>' + (L.overtime || 'Overtime') + '</th></tr></thead><tbody>';
                report.members.forEach(function(m) {
                    html += '<tr><td>' + esc(m.display_name || m.user_id || '-') + '</td><td>' + esc(m.total_hours != null ? m.total_hours : '-') + '</td><td>' + esc(m.overtime_hours != null ? m.overtime_hours : '-') + '</td></tr>';
                });
                html += '</tbody></table>';
            } else if (report.users && report.users.length) {
                html += '<h4 class="report-subhead">' + (L.users || 'Users') + '</h4><table class="report-table"><thead><tr><th>' + (L.name || 'Name') + '</th><th>' + (L.hours || 'Hours') + '</th><th>' + (L.overtime || 'Overtime') + '</th></tr></thead><tbody>';
                report.users.forEach(function(u) {
                    html += '<tr><td>' + esc(u.display_name || u.user_id || '-') + '</td><td>' + esc(u.total_hours != null ? u.total_hours : (u.total_hours_worked != null ? u.total_hours_worked : '-')) + '</td><td>' + esc(u.overtime_hours != null ? u.overtime_hours : '-') + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            if (report.daily_breakdown && Object.keys(report.daily_breakdown).length) {
                html += '<h4 class="report-subhead">' + (L.dailyBreakdown || 'Daily breakdown') + '</h4><table class="report-table"><thead><tr><th>' + (L.date || 'Date') + '</th><th>' + (L.hours || 'Hours') + '</th></tr></thead><tbody>';
                Object.keys(report.daily_breakdown).sort().forEach(function(d) {
                    var day = report.daily_breakdown[d];
                    html += '<tr><td>' + esc(day.date || d) + '</td><td>' + esc(day.total_hours != null ? day.total_hours : '-') + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            if (report.summary) html += '<p class="report-summary">' + esc(report.summary) + '</p>';
            html += '</div>';
            return html;
        }
        // Build params for team report (organization, admin team, manager team) – returns { apiUrl, isTeam, extraParams }
        function resolveScopeAndApi(reportType, startDate, endDate) {
            var A = window.ArbeitszeitCheck || {};
            var apiMap = A.apiUrl || {};
            var scope = reportScopeInput ? reportScopeInput.value : '';

            // Default: per-user reports (viewer or target user)
            if (!scope || scope === '') {
                return {
                    apiUrl: apiMap[reportType] || null,
                    isTeam: false,
                    queryParams: {}
                };
            }

            // Admin scopes
            if (A.isAdmin) {
                if (scope === 'organization') {
                    // organization-wide: use existing per-period API with userId=null
                    return {
                        apiUrl: apiMap[reportType] || null,
                        isTeam: false,
                        queryParams: { userId: '' }
                    };
                }

                if (scope === 'admin_team') {
                    var teamUsers = (reportTeamUsersInput && reportTeamUsersInput.value) ? reportTeamUsersInput.value : '';
                    return {
                        apiUrl: apiMap.team || null,
                        isTeam: true,
                        queryParams: {
                            userIds: teamUsers,
                            startDate: startDate,
                            endDate: endDate
                        }
                    };
                }
            }

            // Manager scopes
            if (A.isManager) {
                if (scope === 'manager_team') {
                    // "Everyone I manage": reporting service already expects team userIds; we use team API endpoint,
                    // and let the backend resolve the exact team membership for this manager.
                    return {
                        apiUrl: apiMap.team || null,
                        isTeam: true,
                        queryParams: {
                            // empty userIds signals "use my full team"; backend still checks permissions
                            userIds: '',
                            startDate: startDate,
                            endDate: endDate
                        }
                    };
                }

                if (scope === 'manager_single_team') {
                    var managerTeamUsers = (reportTeamUsersInput && reportTeamUsersInput.value) ? reportTeamUsersInput.value : '';
                    return {
                        apiUrl: apiMap.team || null,
                        isTeam: true,
                        queryParams: {
                            userIds: managerTeamUsers,
                            startDate: startDate,
                            endDate: endDate
                        }
                    };
                }
            }

            // Fallback: per-user API
            return {
                apiUrl: apiMap[reportType] || null,
                isTeam: false,
                queryParams: {}
            };
        }

        // Shared: fetch report and show in preview (or show error in preview). Both Preview and Generate use this.
        function fetchAndShowReport() {
            var reportType = reportTypeInput ? reportTypeInput.value : '';
            var dp = window.ArbeitszeitCheckDatepicker;
            var toISO = dp ? dp.convertEuropeanToISO : function(s) { return s; };
            var startDate = toISO(startDateInput ? startDateInput.value : '');
            var endDate = toISO(endDateInput ? endDateInput.value : '');
            var previewSection = document.getElementById('report-preview');
            var previewContent = document.getElementById('report-preview-content');
            if (!previewSection || !previewContent) return;
            if (!reportType || !startDate || !endDate) {
                var errMsg = (window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.error) || 'Please fill in report type, start date and end date.';
                announceToScreenReader(errMsg);
                previewContent.innerHTML = '<p class="report-error" role="alert">' + esc(errMsg) + '</p>';
                previewSection.style.display = 'block';
                previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                var h = document.getElementById('report-preview-heading');
                if (h) h.focus();
                return;
            }

            // Validate scope before building URL
            updateScopeFromForm();
            if (!reportScopeInput || !reportScopeInput.value) {
                var scopeMsg = (window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.scopeRequired) || 'Please choose who should be included in the report.';
                announceToScreenReader(scopeMsg);
                previewContent.innerHTML = '<p class="report-error" role="alert">' + esc(scopeMsg) + '</p>';
                previewSection.style.display = 'block';
                previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }

            var scopeResolution = resolveScopeAndApi(reportType, startDate, endDate);
            if (!scopeResolution.apiUrl) {
                var typeMsg = (window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.error) || 'Invalid report type.';
                previewContent.innerHTML = '<p class="report-error" role="alert">' + esc(typeMsg) + '</p>';
                previewSection.style.display = 'block';
                previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }

            // If a team-specific scope is selected, ensure a team or user set is present
            if ((reportScopeInput.value === 'admin_team' || reportScopeInput.value === 'manager_single_team') &&
                (!reportTeamUsersInput || !reportTeamUsersInput.value)) {
                var teamMsg = (window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.teamRequired) || 'Please select a team.';
                announceToScreenReader(teamMsg);
                previewContent.innerHTML = '<p class="report-error" role="alert">' + esc(teamMsg) + '</p>';
                previewSection.style.display = 'block';
                previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }

            var url;
            if (scopeResolution.isTeam) {
                // Team reports: use team endpoint and its own query params
                var urlObj = new URL(scopeResolution.apiUrl, window.location.origin);
                Object.keys(scopeResolution.queryParams || {}).forEach(function (key) {
                    var val = scopeResolution.queryParams[key];
                    if (val != null && val !== '') {
                        urlObj.searchParams.set(key, val);
                    }
                });
                url = urlObj.toString();
            } else {
                // Per-user and organization-wide reports: use classic per-type endpoints
                url = buildReportUrl(scopeResolution.apiUrl, reportType, startDate, endDate);
                // If we explicitly want organization-wide and the API supports userId, include it
                if (scopeResolution.queryParams && typeof scopeResolution.queryParams.userId !== 'undefined') {
                    var u = new URL(url);
                    if (scopeResolution.queryParams.userId !== '') {
                        u.searchParams.set('userId', scopeResolution.queryParams.userId);
                    }
                    url = u.toString();
                }
            }
            var requestToken = getRequestToken();
            var originalPreviewText = previewBtn ? previewBtn.textContent : '';
            var originalGenerateText = generateBtn ? generateBtn.textContent : '';
            if (previewBtn) { previewBtn.disabled = true; previewBtn.textContent = (window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.generating) || 'Generating...'; }
            if (generateBtn) { generateBtn.disabled = true; generateBtn.textContent = (window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.generating) || 'Generating...'; }
            previewContent.innerHTML = '<p class="report-loading" aria-busy="true">' + esc((window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.generating) || 'Generating report...') + '</p>';
            previewSection.style.display = 'block';
            announceToScreenReader((window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.generating) || 'Generating report...');
            fetch(url, { method: 'GET', headers: { requesttoken: requestToken } })
                .then(function(res) { return res.text().then(function(text) { return { ok: res.ok, status: res.status, text: text }; }); })
                .then(function(result) {
                    var data = null;
                    try { data = result.text ? JSON.parse(result.text) : null; } catch (err) { }
                    if (previewBtn) { previewBtn.disabled = false; previewBtn.textContent = originalPreviewText; }
                    if (generateBtn) { generateBtn.disabled = false; generateBtn.textContent = originalGenerateText; }
                    if (result.ok && data && data.success && data.report) {
                        previewContent.innerHTML = '<p class="report-success">' + esc((window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.reportReady) || 'Report generated successfully.') + '</p>' + renderReportHtml(data.report);
                        announceToScreenReader((window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.reportReady) || 'Report generated successfully.');
                        previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        var heading = document.getElementById('report-preview-heading');
                        if (heading) heading.focus();
                    } else {
                        var msg = (data && data.error) ? data.error : (result.status === 403 || result.status === 401 ? (window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.sessionExpired) || 'Your session may have expired. Please refresh the page and try again.' : (window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.error) || 'An error occurred');
                        previewContent.innerHTML = '<p class="report-error" role="alert">' + esc(msg) + '</p>';
                        announceToScreenReader(msg);
                        previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                })
                .catch(function(err) {
                    if (previewBtn) { previewBtn.disabled = false; previewBtn.textContent = originalPreviewText; }
                    if (generateBtn) { generateBtn.disabled = false; generateBtn.textContent = originalGenerateText; }
                    var msg = (window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.sessionExpired) || (window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.error) || 'An error occurred. Please try again.';
                    previewContent.innerHTML = '<p class="report-error" role="alert">' + esc(msg) + '</p>';
                    announceToScreenReader(msg);
                });
        }
        // Trigger a real file download using the export endpoints
        function downloadReport() {
            var reportType = reportTypeInput ? reportTypeInput.value : '';
            if (!reportType || !startDateInput || !endDateInput) {
                return;
            }
            var dp = window.ArbeitszeitCheckDatepicker;
            var toISO = dp ? dp.convertEuropeanToISO : function(s) { return s; };
            var startIso = toISO(startDateInput.value || '');
            var endIso = toISO(endDateInput.value || '');
            if (!startIso || !endIso) {
                return;
            }
            var format = formatSelect ? formatSelect.value : 'csv';
            // Map report types to export endpoints (team/org exports reuse the same endpoints,
            // which are currently per-user and organization-safe – team/org downloads can
            // be added later via dedicated export endpoints if needed).
            var exportKey = 'timeEntries';
            if (reportType === 'absence') {
                exportKey = 'absences';
            } else if (reportType === 'compliance') {
                exportKey = 'compliance';
            }
            var exportBase = window.ArbeitszeitCheck && window.ArbeitszeitCheck.exportUrl
                ? window.ArbeitszeitCheck.exportUrl[exportKey]
                : null;
            if (!exportBase) {
                return;
            }
            try {
                var urlObj = new URL(exportBase, window.location.origin);
                if (startIso) urlObj.searchParams.set('startDate', startIso);
                if (endIso) urlObj.searchParams.set('endDate', endIso);
                if (format) urlObj.searchParams.set('format', format);
                var a = document.createElement('a');
                a.href = urlObj.toString();
                a.style.display = 'none';
                a.setAttribute('download', '');
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            } catch (e) {
                // If URL construction fails, silently skip download to avoid breaking preview
            }
        }
        if (reportForm) {
            reportForm.addEventListener('submit', function(e) {
                e.preventDefault();
                fetchAndShowReport();
                downloadReport();
            });
        }
        if (previewBtn) {
            previewBtn.addEventListener('click', function(e) {
                e.preventDefault();
                fetchAndShowReport();
            });
        }
    });
    <?php endif; ?>
</script>

