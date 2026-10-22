<?php
declare(strict_types=1);

/**
 * Reports template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */

use OCA\ArbeitszeitCheck\Service\IconCatalog;

// Assets registered by PageController::registerFrontEndAssets

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
// Do not reuse $isAdmin / $isManager — common/navigation.php overwrites $isAdmin for nav highlighting.
$reportsIsAdmin = !empty($_['isAdmin']);
$reportsIsManager = !empty($_['isManager']);
$canAccessReports = $reportsIsAdmin || $reportsIsManager;

// Whether app-owned teams are enabled (controls whether manager-specific team selection makes sense)
$config = \OCP\Server::get(\OCP\IConfig::class);
$useAppTeams = $config->getAppValue('arbeitszeitcheck', 'use_app_teams', '0') === '1';
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <div class="reports-page">
            <?php if (!$canAccessReports): ?>
                <section class="azc-card reports-page__denied" aria-labelledby="reports-denied-heading">
                    <div class="azc-empty-state">
                        <h2 id="reports-denied-heading" class="azc-empty-state__title"><?php p($l->t('Reports are only available for administrators and managers')); ?></h2>
                        <p class="azc-empty-state__lead">
                            <?php p($l->t('If you need to generate reports, please contact your administrator or manager.')); ?>
                        </p>
                        <p class="azc-empty-state__actions">
                            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"
                               class="azc-btn azc-btn--primary">
                                <?php p($l->t('Dashboard')); ?>
                            </a>
                        </p>
                    </div>
                </section>
            <?php else: ?>

            <ol class="azc-reports-stepper" aria-label="<?php p($l->t('Report creation steps')); ?>">
                <li class="azc-reports-stepper__item azc-reports-stepper__item--current">
                    <span class="azc-reports-stepper__badge" aria-hidden="true">1</span>
                    <span class="azc-reports-stepper__label"><?php p($l->t('Who is included')); ?></span>
                </li>
                <li class="azc-reports-stepper__item">
                    <span class="azc-reports-stepper__badge" aria-hidden="true">2</span>
                    <span class="azc-reports-stepper__label"><?php p($l->t('Export type')); ?></span>
                </li>
                <li class="azc-reports-stepper__item">
                    <span class="azc-reports-stepper__badge" aria-hidden="true">3</span>
                    <span class="azc-reports-stepper__label"><?php p($l->t('Period and download')); ?></span>
                </li>
            </ol>

            <!-- Step 1: scope -->
            <section class="azc-card reports-step-card report-scope-section" aria-labelledby="report-scope-heading">
                <h3 id="report-scope-heading" class="reports-section__title"><?php p($l->t('Who should be included in the report?')); ?></h3>
                <p class="reports-section__desc">
                    <?php
                    if ($reportsIsAdmin) {
                        p($l->t('Choose whether you want a report for the whole organization or for a specific team.'));
                    } else {
                        p($l->t('Choose whether you want a report for everyone you manage.'));
                    }
                    ?>
                </p>

                <form id="report-scope-form" class="report-scope-form" aria-label="<?php p($l->t('Select report scope')); ?>">
                    <fieldset class="form-fieldset" aria-labelledby="scope-legend">
                        <legend id="scope-legend" class="form-legend"><?php p($l->t('Report scope')); ?></legend>

                        <?php if ($reportsIsAdmin): ?>
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
                            <div class="form-group reports-scope-option-conditional" id="manager-specific-team-group">
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
            </section>

            <!-- Step 2: export type -->
            <section class="azc-card reports-step-card report-selection-section" aria-labelledby="report-type-heading">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="report-type-heading" class="azc-card__title"><?php p($l->t('Choose an export')); ?></h2>
                        <p class="azc-card__lead"><?php p($l->t('Choose one of these exports, then set the period and format below.')); ?></p>
                    </div>
                </header>
                <div class="azc-card__body">
                    <div
                        class="report-types-grid"
                        role="radiogroup"
                        aria-labelledby="report-type-heading"
                        aria-describedby="report-type-hint"
                    >
                        <p id="report-type-hint" class="visually-hidden">
                            <?php p($l->t('Use the arrow keys to move between exports, then press Enter or Space to select.')); ?>
                        </p>

                        <button
                            type="button"
                            class="report-type-card btn-select-report"
                            role="radio"
                            aria-checked="false"
                            data-report="monthly"
                            data-report-type="monthly"
                            data-label-select="<?php p($l->t('Select')); ?>"
                            data-label-selected="<?php p($l->t('Selected')); ?>"
                        >
                            <span class="report-type-card__icon" aria-hidden="true">
                                <?php print_unescaped(IconCatalog::render('clock', 'report-type-card__icon-svg', 2.5)); ?>
                            </span>
                            <span class="report-type-card__body">
                                <span class="report-type-card__title"><?php p($l->t('Working Time Export')); ?></span>
                                <span class="report-type-card__desc"><?php p($l->t('Export a clear overview of worked hours for the selected period.')); ?></span>
                            </span>
                            <span class="report-type-card__action" aria-hidden="true">
                                <span class="report-type-card__action-mark"></span>
                                <span class="report-type-card__action-label"><?php p($l->t('Select')); ?></span>
                            </span>
                        </button>

                        <button
                            type="button"
                            class="report-type-card btn-select-report"
                            role="radio"
                            aria-checked="false"
                            data-report="absence"
                            data-report-type="absence"
                            data-label-select="<?php p($l->t('Select')); ?>"
                            data-label-selected="<?php p($l->t('Selected')); ?>"
                        >
                            <span class="report-type-card__icon" aria-hidden="true">
                                <?php print_unescaped(IconCatalog::render('calendar-off', 'report-type-card__icon-svg', 2.5)); ?>
                            </span>
                            <span class="report-type-card__body">
                                <span class="report-type-card__title"><?php p($l->t('Absence Export')); ?></span>
                                <span class="report-type-card__desc"><?php p($l->t('Export vacation and absence data with totals and status.')); ?></span>
                            </span>
                            <span class="report-type-card__action" aria-hidden="true">
                                <span class="report-type-card__action-mark"></span>
                                <span class="report-type-card__action-label"><?php p($l->t('Select')); ?></span>
                            </span>
                        </button>

                        <button
                            type="button"
                            class="report-type-card btn-select-report"
                            role="radio"
                            aria-checked="false"
                            data-report="compliance"
                            data-report-type="compliance"
                            data-label-select="<?php p($l->t('Select')); ?>"
                            data-label-selected="<?php p($l->t('Selected')); ?>"
                        >
                            <span class="report-type-card__icon" aria-hidden="true">
                                <?php print_unescaped(IconCatalog::render('shield-check', 'report-type-card__icon-svg', 2.5)); ?>
                            </span>
                            <span class="report-type-card__body">
                                <span class="report-type-card__title"><?php p($l->t('Compliance Export')); ?></span>
                                <span class="report-type-card__desc"><?php p($l->t('Export labor law compliance violations and severity details.')); ?></span>
                            </span>
                            <span class="report-type-card__action" aria-hidden="true">
                                <span class="report-type-card__action-mark"></span>
                                <span class="report-type-card__action-label"><?php p($l->t('Select')); ?></span>
                            </span>
                        </button>

                        <?php if (!empty($_['premiumSurchargesEnabled'])): ?>
                        <button
                            type="button"
                            class="report-type-card btn-select-report"
                            role="radio"
                            aria-checked="false"
                            data-report="premium"
                            data-report-type="premium"
                            data-label-select="<?php p($l->t('Select')); ?>"
                            data-label-selected="<?php p($l->t('Selected')); ?>"
                        >
                            <span class="report-type-card__icon" aria-hidden="true">
                                <?php print_unescaped(IconCatalog::render('coins', 'report-type-card__icon-svg', 2.5)); ?>
                            </span>
                            <span class="report-type-card__body">
                                <span class="report-type-card__title"><?php p($l->t('Hour premiums (Zuschläge)')); ?></span>
                                <span class="report-type-card__desc"><?php p($l->t('Hours with a percentage for payroll handoff — not Saldo and not pay.')); ?></span>
                            </span>
                            <span class="report-type-card__action" aria-hidden="true">
                                <span class="report-type-card__action-mark"></span>
                                <span class="report-type-card__action-label"><?php p($l->t('Select')); ?></span>
                            </span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Step 3: period, format, export options -->
            <section id="report-parameters" class="azc-card reports-step-card report-parameters-section" hidden aria-labelledby="report-parameters-heading">
                <h3 id="report-parameters-heading" class="reports-section__title"><?php p($l->t('Set time period and format')); ?></h3>
                <p class="reports-section__desc">
                    <?php p($l->t('Pick the time period for your report. The selected range applies to the export and preview.')); ?>
                </p>
                <p class="reports-section__desc reports-section__desc--secondary" id="report-preview-download-hint">
                    <?php p($l->t('Preview loads the report in the browser. Generate and download saves a file using the options below.')); ?>
                </p>
                <form id="report-form" class="report-form" aria-label="<?php p($l->t('Report parameters')); ?>">
                    <input type="hidden" id="report-type" name="report_type" value="">
                    <input type="hidden" id="report-scope" name="report_scope" value="">
                    <input type="hidden" id="report-team-users" name="report_team_users" value="">

                    <div class="report-form__date-row">
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
                    </div>

                    <div class="form-group" id="format-group">
                        <label for="format" class="form-label">
                            <?php p($l->t('File Format')); ?>
                        </label>
                        <select id="format" 
                                name="format" 
                                class="form-select"
                                aria-describedby="<?php echo $reportsIsAdmin ? 'format-help format-help-datev' : 'format-help'; ?>">
                            <option value="csv"><?php p($l->t('CSV (for Excel or other programs)')); ?></option>
                            <option value="json"><?php p($l->t('JSON (for computer programs)')); ?></option>
                        </select>
                        <p id="format-help" class="form-help">
                            <?php p($l->t('Choose how you want to save the report. CSV works well with spreadsheet programs. JSON is best if another system needs to process the data.')); ?>
                        </p>
                        <?php if ($reportsIsAdmin): ?>
                        <p id="format-help-datev" class="form-help">
                            <?php p($l->t('CSV and JSON downloads use the buttons below. For payroll DATEV files, use the DATEV section under this form — configure Beraternummer and Mandantennummer in Administration → Global settings first.')); ?>
                        </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group" id="report-team-variant-group" style="display: none;" aria-labelledby="report-team-variant-label">
                        <label id="report-team-variant-label" for="report-team-variant" class="form-label">
                            <?php p($l->t('Team download content')); ?>
                        </label>
                        <select id="report-team-variant"
                                name="report_team_variant"
                                class="form-select"
                                disabled
                                aria-describedby="report-team-variant-help">
                            <option value="time_entries" selected><?php p($l->t('Detailed time entries')); ?></option>
                            <option value="summary"><?php p($l->t('Summary (one row per person)')); ?></option>
                        </select>
                        <p id="report-team-variant-help" class="form-help">
                            <?php p($l->t('Summary matches the on-screen team totals. Detailed time entries lists each booking line (respects midnight split in admin settings).')); ?>
                        </p>
                    </div>

                    <div class="form-group" id="report-export-layout-group" style="display: none;" aria-labelledby="report-export-layout-label">
                        <label id="report-export-layout-label" for="report-export-layout" class="form-label">
                            <?php p($l->t('Time entries layout')); ?>
                        </label>
                        <select id="report-export-layout"
                                name="report_export_layout"
                                class="form-select"
                                disabled
                                aria-describedby="report-export-layout-help">
                            <option value="long"><?php p($l->t('One row per segment (long)')); ?></option>
                            <option value="wide"><?php p($l->t('Daily sheet: date, weekday, From–To pairs (wide)')); ?></option>
                        </select>
                        <p id="report-export-layout-help" class="form-help">
                            <?php p($l->t('Long layout: one row per time segment with start/end times and duration/working hours columns (use this to see overnight shifts split at midnight when your administrator enables it). Wide layout: date, weekday, and From–To clock times only — no duration or working hours columns.')); ?>
                        </p>
                    </div>
                    
                    <div class="reports-form__actions">
                        <button type="button"
                                id="btn-preview-report"
                                class="azc-btn azc-btn--secondary"
                                aria-label="<?php p($l->t('Preview the report before downloading')); ?>">
                            <?php p($l->t('Preview')); ?>
                        </button>
                        <button type="submit"
                                id="btn-generate-report"
                                class="azc-btn azc-btn--primary"
                                aria-label="<?php p($l->t('Generate and download the report')); ?>">
                            <?php p($l->t('Generate and download')); ?>
                        </button>
                        <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"
                           class="azc-btn azc-btn--secondary"
                           aria-label="<?php p($l->t('Cancel and go back')); ?>">
                            <?php p($l->t('Cancel')); ?>
                        </a>
                    </div>
                </form>

                <?php if ($reportsIsAdmin): ?>
                <aside id="datev-export-panel"
                       class="datev-export-panel"
                       aria-labelledby="datev-export-heading"
                       data-datev-panel="1">
                    <h3 id="datev-export-heading" class="datev-export-panel__title">
                        <?php print_unescaped(IconCatalog::render('download', 'datev-export-panel__icon', 1.25)); ?>
                        <?php p($l->t('DATEV payroll download')); ?>
                    </h3>
                    <p id="datev-export-lead" class="datev-export-panel__lead">
                        <?php p($l->t('Download a DATEV text file for the same start and end dates as above. Hour premiums appear as extra Lohnart lines only when mapped in Global settings.')); ?>
                    </p>
                    <p id="datev-export-status"
                       class="datev-export-panel__status"
                       role="status"
                       aria-live="polite">
                        <?php p($l->t('Checking DATEV setup…')); ?>
                    </p>
                    <p id="datev-export-error" class="datev-export-panel__error" role="alert" hidden></p>
                    <div class="datev-export-panel__actions">
                        <button type="button"
                                id="btn-datev-self"
                                class="azc-btn azc-btn--primary"
                                disabled
                                aria-describedby="datev-export-lead datev-export-status">
                            <?php p($l->t('Download my DATEV file')); ?>
                        </button>
                        <button type="button"
                                id="btn-datev-org"
                                class="azc-btn azc-btn--secondary"
                                disabled
                                aria-describedby="datev-export-lead datev-export-status">
                            <?php p($l->t('Download organisation DATEV file')); ?>
                        </button>
                    </div>
                    <p class="datev-export-panel__help form-help">
                        <?php p($l->t('Organisation download includes every enabled user who has a DATEV Personalnummer. Set Personalnummer on each employee profile.')); ?>
                    </p>
                </aside>
                <?php endif; ?>
            </section>

            <!-- Step 4: preview (shown after preview or generate) -->
            <section id="report-preview" class="azc-card reports-step-card report-preview-section" hidden aria-labelledby="report-preview-heading">
                <h3 id="report-preview-heading" class="reports-section__title" tabindex="-1"><?php p($l->t('Report Preview')); ?></h3>
                <div id="report-preview-live" class="visually-hidden" aria-live="polite" aria-atomic="true"></div>
                <div id="report-preview-content" class="report-preview-content">
                    <!-- Preview will be loaded here dynamically -->
                </div>
            </section>
            <?php endif; ?>
        </div>

<?php include __DIR__ . '/common/main-ui-l10n.php'; ?>

<!-- Initialize JavaScript: keep configuration inline, move behaviour into bundled reports.js -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.page = 'reports';
    window.ArbeitszeitCheck.canAccessReports = <?php echo json_encode($canAccessReports, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.isAdmin = <?php echo json_encode($reportsIsAdmin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.isManager = <?php echo json_encode($reportsIsManager, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    
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
    window.ArbeitszeitCheck.l10n.workLocationDays = <?php echo json_encode($l->t('Work location days'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.workLocationDaysHome = <?php echo json_encode($l->t('Home office days'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.workLocationDaysExternal = <?php echo json_encode($l->t('External appointment days'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.workLocationDaysHint = <?php echo json_encode($l->t('A day counts toward a location when at least one entry that day carries it — mixed days appear under both.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.violationTypes = <?php echo json_encode($l->t('Violation types'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.severities = <?php echo json_encode($l->t('Severities'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.severity = <?php echo json_encode($l->t('Severity'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.type = <?php echo json_encode($l->t('Type'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.count = <?php echo json_encode($l->t('Count'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.absences = <?php echo json_encode($l->t('Absences'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.totalDays = <?php echo json_encode($l->t('Total days'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.absencesByType = <?php echo json_encode($l->t('Absences by type'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.absencesByStatus = <?php echo json_encode($l->t('Absences by status'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.details = <?php echo json_encode($l->t('Details'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.startDateCol = <?php echo json_encode($l->t('Start'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.endDateCol = <?php echo json_encode($l->t('End'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.days = <?php echo json_encode($l->t('Days'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.status = <?php echo json_encode($l->t('Status'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.scopeRequired = <?php echo json_encode($l->t('Please choose who should be included in the report.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.teamRequired = <?php echo json_encode($l->t('Please select a team.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.dateRangeInvalid = <?php echo json_encode($l->t('Start date must be before or equal to end date.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.exportScopeNotice = <?php echo json_encode($l->t('The download contains one row per team member for the selected period.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.exportScopeNoticeTimeEntries = <?php echo json_encode($l->t('The download lists each time entry line for your team (several rows per person if there are multiple entries; overnight shifts may appear as two rows when midnight split is enabled).'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.exportOrganizationScopeNotice = <?php echo json_encode($l->t('For organization scope, download is available for working time export after preview.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.exportOrganizationEmpty = <?php echo json_encode($l->t('No organization members had time entries in the selected period; nothing to download.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.teamDownloadWorkingTimeOnly = <?php echo json_encode($l->t('Team download is only available for the working time export. Switch to personal scope to download absence or compliance data.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.teamPreviewWorkingTimeOnly = <?php echo json_encode($l->t('With team scope, this preview shows the team working time summary, not absence or compliance.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.teamDownloadOnlyWorkingTimeExport = <?php echo json_encode($l->t('Team file download is only available for the working time export.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.reportParamsRequired = <?php echo json_encode($l->t('Please fill in report type, start date and end date.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.invalidReportType = <?php echo json_encode($l->t('Invalid report type.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.errorTryAgain = <?php echo json_encode($l->t('An error occurred. Please try again.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    
    window.ArbeitszeitCheck.l10n.premiumsTitle = <?php echo json_encode($l->t('Hour premiums'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.premiumBucket = <?php echo json_encode($l->t('Category'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.premiumRate = <?php echo json_encode($l->t('Rate'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.premiumValued = <?php echo json_encode($l->t('Valued hours'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.premiumHint = <?php echo json_encode($l->t('Hours with a percentage for reporting — not pay, and not your overtime Saldo.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.premiumEmpty = <?php echo json_encode($l->t('No premium hours in this period.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.teamDownloadPremiumOk = <?php echo json_encode($l->t('Team download for hour premiums includes one row per person and category.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.monthClosureSealed = <?php echo json_encode($l->t('This month is sealed. Figures below match the closed-month snapshot (including frozen hour premiums).'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.frozenPremiumsTitle = <?php echo json_encode($l->t('Frozen hour premiums'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.policyVersion = <?php echo json_encode($l->t('Policy version'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.datevChecking = <?php echo json_encode($l->t('Checking DATEV setup…'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.datevReadySelf = <?php echo json_encode($l->t('DATEV organisation numbers are set. You can download your own file if your Personalnummer is set.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.datevReadyOrg = <?php echo json_encode($l->t('DATEV organisation numbers are set. Organisation download skips people without a Personalnummer.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.datevNeedConfig = <?php echo json_encode($l->t('Set Beraternummer and Mandantennummer in Administration → Global settings before downloading DATEV files.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.datevNeedPersonal = <?php echo json_encode($l->t('Organisation numbers are set, but your DATEV Personalnummer is missing. Add it on your employee profile to download your own file.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.datevNeedDates = <?php echo json_encode($l->t('Choose a start and end date above before downloading DATEV.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.datevDownloadStarted = <?php echo json_encode($l->t('DATEV download started.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.datevLoadFailed = <?php echo json_encode($l->t('Could not load DATEV status. Refresh the page or check your permissions.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    window.ArbeitszeitCheck.apiUrl = {
        daily: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.daily'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        weekly: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.weekly'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        monthly: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.monthly'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        overtime: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.overtime'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        premium: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.premium'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        absence: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.absence'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        compliance: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.compliance.getReport'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        team: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.report.team'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };
    // Export endpoints that trigger real file downloads
    window.ArbeitszeitCheck.exportUrl = {
        timeEntries: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.export.timeEntries'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        absences: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.export.absences'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        compliance: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.export.compliance'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        datev: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.export.datev'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        datevConfig: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.export.datevConfig'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };
</script>
</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>

