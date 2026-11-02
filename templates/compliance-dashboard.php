<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Service\IconCatalog;

/**
 * Compliance page: status, personal report, and violations.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$complianceStatus = $_['complianceStatus'] ?? [];
$violations = $_['violations'] ?? [];
$violationTotal = (int)($_['violationTotal'] ?? count($violations));
$reportData = $_['reportData'] ?? [];
$error = $_['error'] ?? null;
$loadError = $complianceStatus['load_error'] ?? false;
$hasData = $complianceStatus['has_data'] ?? true;
$filtered = !empty($_['filterUserId']);
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$compliancePagerFilters = [];
if (is_string($_['filterUserId'] ?? null) && $_['filterUserId'] !== '') {
	$compliancePagerFilters['userId'] = (string)$_['filterUserId'];
}

$complianceTypeLabel = static function (string $typeKey) use ($l): string {
	return match ($typeKey) {
		'missing_break' => $l->t('Missing break'),
		'excessive_working_hours' => $l->t('Excessive working hours'),
		'insufficient_rest_period' => $l->t('Insufficient rest period'),
		'daily_hours_limit_exceeded' => $l->t('Daily hours limit exceeded'),
		'weekly_hours_limit_exceeded' => $l->t('Weekly hours limit exceeded'),
		'weekly_absolute_hours_exceeded' => $l->t('Absolute weekly hours maximum exceeded'),
		'night_work' => $l->t('Night work'),
		'sunday_work' => $l->t('Sunday work'),
		'holiday_work' => $l->t('Holiday work'),
		'missing_presence' => $l->t('Required presence not covered'),
		default => $typeKey,
	};
};
$complianceSeverityLabel = static function (string $severityKey) use ($l): string {
	return match ($severityKey) {
		'error' => $l->t('High'),
		'warning' => $l->t('Medium'),
		'info' => $l->t('Low'),
		default => $severityKey,
	};
};
$complianceSeverityBadge = static function (string $severityKey): string {
	return match ($severityKey) {
		'error' => 'error',
		'warning' => 'warning',
		default => 'primary',
	};
};
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <?php if ($filtered): ?>
            <?php
            $calloutVariant = 'info';
            $calloutRole = 'status';
            $calloutBanner = false;
            $calloutTitleId = 'compliance-person-title';
            $calloutIcon = 'info';
            $calloutTitle = $l->t('Showing violations for %s.', [$_['filterDisplayName'] ?? $_['filterUserId']]);
            $calloutText = '';
            $calloutHint = '';
            $calloutActions = [[
                'href' => $urlGenerator->linkToRoute('arbeitszeitcheck.compliance.dashboard'),
                'label' => $l->t('Show all my visible violations'),
            ]];
            include __DIR__ . '/common/alert-callout.php';
            $calloutActions = [];
            ?>
        <?php endif; ?>

        <section class="azc-card compliance-dashboard__status" id="compliance-status" aria-labelledby="compliance-status-heading">
            <header class="azc-card__header">
                <div class="azc-card__header-text">
                    <h2 id="compliance-status-heading" class="azc-card__title"><?php p($l->t('Compliance Status')); ?></h2>
                    <?php if (!empty($_['showComplianceRunCheck'])): ?>
                        <p id="compliance-run-check-help" class="azc-card__lead">
                            <?php p($l->t('Administrator only: runs a manual compliance scan for all users. This does not change your data automatically — review new violations afterward.')); ?>
                        </p>
                    <?php endif; ?>
                </div>
                <?php if (!empty($_['showComplianceRunCheck'])): ?>
                    <div class="azc-card__header-actions">
                        <button type="button"
                            id="btn-run-compliance-check"
                            class="azc-btn azc-btn--secondary azc-btn--sm"
                            data-run-check-url="<?php p((string)($_['complianceRunCheckUrl'] ?? '')); ?>"
                            data-violations-url="<?php p((string)($_['complianceViolationsUrl'] ?? '')); ?>"
                            aria-describedby="compliance-run-check-help">
                            <?php print_unescaped(IconCatalog::render('rotate', 'azc-btn__icon')); ?>
                            <span><?php p($l->t('Run compliance check now')); ?></span>
                        </button>
                    </div>
                <?php endif; ?>
            </header>
            <div class="azc-card__body">
                <?php if ($loadError): ?>
                    <?php
                    $calloutVariant = 'danger';
                    $calloutRole = 'alert';
                    $calloutBanner = false;
                    $calloutTitleId = 'compliance-status-load-error-title';
                    $calloutIcon = 'circle-alert';
                    $calloutTitle = $l->t('Could not load compliance status');
                    $calloutText = $l->t('Please refresh the page to try again.');
                    $calloutHint = !empty($error) ? (string)$error : '';
                    include __DIR__ . '/common/alert-callout.php';
                    ?>
                <?php elseif ($complianceStatus['compliant'] ?? false): ?>
                    <?php if (!$hasData): ?>
                        <?php
                        $calloutVariant = 'info';
                        $calloutRole = 'status';
                        $calloutBanner = false;
                        $calloutTitleId = 'compliance-status-no-data-title';
                        $calloutIcon = 'info';
                        $calloutTitle = $l->t('Not enough data yet');
                        $calloutText = $filtered
                            ? $l->t('Not enough recorded time yet to check the rules for this person.')
                            : $l->t('Create time entries to get your compliance status. Once you have recorded working hours, we can check them against the configured labour law.');
                        $calloutHint = '';
                        include __DIR__ . '/common/alert-callout.php';
                        ?>
                    <?php else: ?>
                        <?php
                        $calloutVariant = 'success';
                        $calloutRole = 'status';
                        $calloutBanner = false;
                        $calloutTitleId = 'compliance-status-ok-title';
                        $calloutIcon = 'check-circle';
                        $calloutTitle = $l->t('Everything looks good!');
                        $calloutText = $filtered
                            ? $l->t('This working time follows the configured labour-law rules.')
                            : $l->t('Your working time follows all configured labour-law rules. Keep up the good work!');
                        $calloutHint = '';
                        include __DIR__ . '/common/alert-callout.php';
                        ?>
                    <?php endif; ?>
                <?php else: ?>
                    <?php
                    $calloutVariant = 'warning';
                    $calloutRole = 'status';
                    $calloutBanner = false;
                    $calloutTitleId = 'compliance-status-warning-title';
                    $calloutIcon = 'alert-triangle';
                    $calloutTitle = $l->t('Some problems found');
                    $calloutText = $filtered
                        ? $l->t('There are issues with this working time that need attention.')
                        : $l->t('There are issues with your working time that need attention. Please check the list below and fix them.');
                    $calloutHint = '';
                    include __DIR__ . '/common/alert-callout.php';
                    ?>
                <?php endif; ?>
                <?php if (!$loadError): ?>
                    <p class="compliance-dashboard__score">
                        <strong><?php p($filtered ? $l->t('How well the rules are followed:') : $l->t('How well you follow the rules:')); ?></strong>
                        <?php if ($hasData): ?>
                            <?php p((string)($complianceStatus['score'] ?? 0)); ?>%
                        <?php else: ?>
                            — <?php p($l->t('(no data yet)')); ?>
                        <?php endif; ?>
                        <span class="form-help compliance-dashboard__score-hint">
                            <?php p($filtered
                                ? $l->t('This shows how well this working time follows the configured labour law. 100%% means everything is perfect.')
                                : $l->t('This shows how well your working time follows the configured labour law. 100%% means everything is perfect.')); ?>
                        </span>
                    </p>
                    <div class="compliance-status__reports">
                        <h3 id="compliance-reports-heading"><?php p($l->t('Reports')); ?></h3>
                        <dl class="compliance-status__figures">
                            <div class="compliance-status__figure">
                                <dt><?php p($l->t('Total Problems')); ?></dt>
                                <dd><?php p((string)($reportData['total_violations'] ?? 0)); ?></dd>
                            </div>
                            <div class="compliance-status__figure">
                                <dt><?php p($l->t('Need to Fix')); ?></dt>
                                <dd><?php p((string)($reportData['unresolved'] ?? 0)); ?></dd>
                            </div>
                        </dl>
                        <?php if (!empty($reportData['by_type'])): ?>
                            <div class="compliance-status__breakdown">
                                <h4 id="compliance-by-type"><?php p($l->t('Problems by Type')); ?></h4>
                                <p class="form-help"><?php p($l->t('See what kinds of working time problems occurred')); ?></p>
                                <?php
                                $typePager = is_array($_['typePager'] ?? null) ? $_['typePager'] : \OCA\ArbeitszeitCheck\Support\TablePager::state(count($reportData['by_type']), null, null);
                                $typePagerTotal = (int)($typePager['total'] ?? 0);
                                $typePagerPerPage = (int)($typePager['perPage'] ?? \OCA\ArbeitszeitCheck\Support\TablePager::DEFAULT_SIZE);
                                $typePagerOffset = (int)($typePager['offset'] ?? 0);
                                $typeRowIndex = 0;
                                ?>
                                <div class="table-container" role="region" aria-labelledby="compliance-by-type">
                                    <div class="azc-table-scroll" tabindex="0">
                                    <table class="table table--hover azc-table--responsive" id="compliance-by-type-table">
                                        <thead>
                                            <tr>
                                                <th scope="col"><?php p($l->t('Type')); ?></th>
                                                <th scope="col"><?php p($l->t('Count')); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reportData['by_type'] as $typeKey => $count): ?>
                                                <?php
                                                $hideTypeRow = $typePagerTotal > $typePagerPerPage
                                                	&& ($typeRowIndex < $typePagerOffset || $typeRowIndex >= $typePagerOffset + $typePagerPerPage);
                                                $typeRowIndex++;
                                                ?>
                                                <tr data-azc-row<?php echo $hideTypeRow ? ' hidden' : ''; ?>>
                                                    <td data-label="<?php p($l->t('Type')); ?>"><?php p($complianceTypeLabel((string)$typeKey)); ?></td>
                                                    <td data-label="<?php p($l->t('Count')); ?>"><?php p((string)$count); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    </div>
                                    <?php
                                    $azcTablePager = [
                                    	'id' => 'compliance-by-type-pager',
                                    	'tableId' => 'compliance-by-type-table',
                                    	'label' => $l->t('Problems by type pagination'),
                                    	'base' => $urlGenerator->linkToRoute('arbeitszeitcheck.compliance.dashboard'),
                                    	'filters' => $compliancePagerFilters,
                                    	'allow' => ['userId'],
                                    	'state' => $typePager,
                                    	'pageParam' => 't_page',
                                    	'sizeParam' => 't_per_page',
                                    	'mode' => 'client',
                                    	'hash' => 'compliance-status',
                                    ];
                                    include __DIR__ . '/partials/table-pager.php';
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($reportData['by_severity'])): ?>
                            <div class="compliance-status__breakdown">
                                <h4 id="compliance-by-severity"><?php p($l->t('Problems by How Serious')); ?></h4>
                                <p class="form-help"><?php p($l->t('See how serious the problems were')); ?></p>
                                <?php
                                $severityPager = is_array($_['severityPager'] ?? null) ? $_['severityPager'] : \OCA\ArbeitszeitCheck\Support\TablePager::state(count($reportData['by_severity']), null, null);
                                $severityPagerTotal = (int)($severityPager['total'] ?? 0);
                                $severityPagerPerPage = (int)($severityPager['perPage'] ?? \OCA\ArbeitszeitCheck\Support\TablePager::DEFAULT_SIZE);
                                $severityPagerOffset = (int)($severityPager['offset'] ?? 0);
                                $severityRowIndex = 0;
                                ?>
                                <div class="table-container" role="region" aria-labelledby="compliance-by-severity">
                                    <div class="azc-table-scroll" tabindex="0">
                                    <table class="table table--hover azc-table--responsive" id="compliance-by-severity-table">
                                        <thead>
                                            <tr>
                                                <th scope="col"><?php p($l->t('How Serious')); ?></th>
                                                <th scope="col"><?php p($l->t('Count')); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reportData['by_severity'] as $severityKey => $count): ?>
                                                <?php
                                                $hideSeverityRow = $severityPagerTotal > $severityPagerPerPage
                                                	&& ($severityRowIndex < $severityPagerOffset || $severityRowIndex >= $severityPagerOffset + $severityPagerPerPage);
                                                $severityRowIndex++;
                                                ?>
                                                <tr data-azc-row<?php echo $hideSeverityRow ? ' hidden' : ''; ?>>
                                                    <td data-label="<?php p($l->t('How Serious')); ?>">
                                                        <span class="badge badge--<?php p($complianceSeverityBadge((string)$severityKey)); ?>">
                                                            <?php p($complianceSeverityLabel((string)$severityKey)); ?>
                                                        </span>
                                                    </td>
                                                    <td data-label="<?php p($l->t('Count')); ?>"><?php p((string)$count); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    </div>
                                    <?php
                                    $azcTablePager = [
                                    	'id' => 'compliance-by-severity-pager',
                                    	'tableId' => 'compliance-by-severity-table',
                                    	'label' => $l->t('Problems by how serious pagination'),
                                    	'base' => $urlGenerator->linkToRoute('arbeitszeitcheck.compliance.dashboard'),
                                    	'filters' => $compliancePagerFilters,
                                    	'allow' => ['userId'],
                                    	'state' => $severityPager,
                                    	'pageParam' => 's_page',
                                    	'sizeParam' => 's_per_page',
                                    	'mode' => 'client',
                                    	'hash' => 'compliance-status',
                                    ];
                                    include __DIR__ . '/partials/table-pager.php';
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="azc-card compliance-dashboard__violations" id="compliance-violations" aria-labelledby="compliance-violations-heading">
            <header class="azc-card__header">
                <div class="azc-card__header-text">
                    <h2 id="compliance-violations-heading" class="azc-card__title"><?php p($l->t('Violations')); ?></h2>
                </div>
            </header>
            <div class="azc-card__body">
                <div class="compliance-violations__filters">
                    <div class="compliance-violations__filters-grid" role="group" aria-label="<?php p($l->t('Filter options')); ?>">
                        <div class="compliance-violations__filter-field">
                            <label for="start-date" class="compliance-violations__filter-label"><?php p($l->t('Start Date')); ?></label>
                            <input type="text" id="start-date" class="form-input datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" aria-label="<?php p($l->t('Start date for filter')); ?>">
                        </div>
                        <div class="compliance-violations__filter-field">
                            <label for="end-date" class="compliance-violations__filter-label"><?php p($l->t('End Date')); ?></label>
                            <input type="text" id="end-date" class="form-input datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" aria-label="<?php p($l->t('End date for filter')); ?>">
                        </div>
                        <div class="compliance-violations__filter-field">
                            <label for="severity-filter" class="compliance-violations__filter-label"><?php p($l->t('Severity')); ?></label>
                            <select id="severity-filter" class="form-select" aria-label="<?php p($l->t('Filter by severity')); ?>">
                                <option value=""><?php p($l->t('All Severities')); ?></option>
                                <option value="info"><?php p($l->t('Low')); ?></option>
                                <option value="warning"><?php p($l->t('Medium')); ?></option>
                                <option value="error"><?php p($l->t('High')); ?></option>
                            </select>
                        </div>
                        <div class="compliance-violations__filter-actions">
                            <button type="button" id="apply-filters" class="btn btn--primary" aria-label="<?php p($l->t('Apply filters and refresh list')); ?>">
                                <?php p($l->t('Apply Filters')); ?>
                            </button>
                        </div>
                    </div>
                </div>
                <?php if ($violationTotal > count($violations)): ?>
                    <p class="form-help compliance-dashboard__truncation"><?php p($l->t('Showing the latest 50 of %s problems. Filters narrow this list.', [(string)$violationTotal])); ?></p>
                <?php endif; ?>
                <?php
                $violationPager = is_array($_['violationPager'] ?? null) ? $_['violationPager'] : \OCA\ArbeitszeitCheck\Support\TablePager::state(count($violations), null, null);
                $violationPagerTotal = (int)($violationPager['total'] ?? 0);
                $violationPagerPerPage = (int)($violationPager['perPage'] ?? \OCA\ArbeitszeitCheck\Support\TablePager::DEFAULT_SIZE);
                $violationPagerOffset = (int)($violationPager['offset'] ?? 0);
                $violationRowIndex = 0;
                ?>
                <div class="table-container" role="region" aria-labelledby="compliance-violations-heading">
                    <div class="azc-table-scroll" tabindex="0">
                    <table class="table table--hover azc-table--responsive" id="violations-table" data-filter-user-id="<?php p((string)($_['filterUserId'] ?? '')); ?>">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Problem Type')); ?></th>
                                <th scope="col"><?php p($l->t('How Serious')); ?></th>
                                <th scope="col"><?php p($l->t('Date')); ?></th>
                                <th scope="col"><?php p($l->t('What Happened')); ?></th>
                                <th scope="col"><?php p($l->t('Fixed?')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="violations-tbody">
                            <?php if ($violations === []): ?>
                                <tr>
                                    <td colspan="5" class="empty-state">
                                        <p class="empty-state__title"><?php p($l->t('No problems found')); ?></p>
                                        <p class="empty-state__description">
                                            <?php p($l->t('Great! No working time rule violations were found for the selected time period.')); ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($violations as $violation): ?>
                                    <?php
                                    $typeKey = (string)($violation['type'] ?? '');
                                    $severityKey = (string)($violation['severity'] ?? '');
                                    $hideViolationRow = $violationPagerTotal > $violationPagerPerPage
                                    	&& ($violationRowIndex < $violationPagerOffset || $violationRowIndex >= $violationPagerOffset + $violationPagerPerPage);
                                    $violationRowIndex++;
                                    ?>
                                    <tr data-azc-row<?php echo $hideViolationRow ? ' hidden' : ''; ?>>
                                        <td data-label="<?php p($l->t('Problem Type')); ?>"><?php p($complianceTypeLabel($typeKey)); ?></td>
                                        <td data-label="<?php p($l->t('How Serious')); ?>">
                                            <span class="badge badge--<?php p($complianceSeverityBadge($severityKey)); ?>">
                                                <?php p($complianceSeverityLabel($severityKey)); ?>
                                            </span>
                                        </td>
                                        <td data-label="<?php p($l->t('Date')); ?>"><?php p((string)($violation['date'] ?? '-')); ?></td>
                                        <td data-label="<?php p($l->t('What Happened')); ?>"><?php p(!empty($violation['description']) ? $l->t((string)$violation['description']) : '-'); ?></td>
                                        <td data-label="<?php p($l->t('Fixed?')); ?>">
                                            <?php if (!empty($violation['resolved'])): ?>
                                                <span class="badge badge--success"><?php p($l->t('Resolved')); ?></span>
                                            <?php else: ?>
                                                <span class="badge badge--error"><?php p($l->t('Unresolved')); ?></span>
                                                <?php if (!empty($violation['can_resolve']) && (string)($violation['id'] ?? '') !== ''): ?>
                                                    <button type="button"
                                                        class="btn btn--sm btn--secondary azc-icon-btn"
                                                        id="btn-resolve-violation-<?php p((string)$violation['id']); ?>"
                                                        data-violation-id="<?php p((string)$violation['id']); ?>"
                                                        aria-label="<?php p($l->t('Mark as fixed')); ?>"
                                                        title="<?php p($l->t('Mark as fixed')); ?>">
                                                        <?php print_unescaped(IconCatalog::render('check')); ?>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php
                    $azcTablePager = [
                    	'id' => 'violations-table-pager',
                    	'tableId' => 'violations-table',
                    	'label' => $l->t('Violations pagination'),
                    	'base' => $urlGenerator->linkToRoute('arbeitszeitcheck.compliance.dashboard'),
                    	'filters' => $compliancePagerFilters,
                    	'allow' => ['userId'],
                    	'state' => $violationPager,
                    	'pageParam' => 'v_page',
                    	'sizeParam' => 'v_per_page',
                    	'mode' => 'client',
                    	'hash' => 'compliance-violations',
                    ];
                    include __DIR__ . '/partials/table-pager.php';
                    ?>
                </div>
            </div>
        </section>

</div><!-- /.azc-page-stack -->
<?php
$complianceViolationsL10n = [
	'Loading...' => $l->t('Loading...'),
	'Error loading violations' => $l->t('Error loading violations'),
	'Failed to load violations. Please try again.' => $l->t('Failed to load violations. Please try again.'),
	'No violations found' => $l->t('No violations found'),
	'Resolved' => $l->t('Resolved'),
	'Unresolved' => $l->t('Unresolved'),
	'Missing break' => $l->t('Missing break'),
	'Excessive working hours' => $l->t('Excessive working hours'),
	'Insufficient rest period' => $l->t('Insufficient rest period'),
	'Daily hours limit exceeded' => $l->t('Daily hours limit exceeded'),
	'Weekly hours limit exceeded' => $l->t('Weekly hours limit exceeded'),
	'Absolute weekly hours maximum exceeded' => $l->t('Absolute weekly hours maximum exceeded'),
	'Night work' => $l->t('Night work'),
	'Sunday work' => $l->t('Sunday work'),
	'Holiday work' => $l->t('Holiday work'),
	'Required presence not covered' => $l->t('Required presence not covered'),
	'High' => $l->t('High'),
	'Medium' => $l->t('Medium'),
	'Low' => $l->t('Low'),
	'Problem Type' => $l->t('Problem Type'),
	'How Serious' => $l->t('How Serious'),
	'Date' => $l->t('Date'),
	'What Happened' => $l->t('What Happened'),
	'Fixed?' => $l->t('Fixed?'),
	'Mark as fixed' => $l->t('Mark as fixed'),
];
?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.complianceViolationsL10n = <?php echo json_encode($complianceViolationsL10n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
window.ArbeitszeitCheck.complianceFilterUserId = <?php echo json_encode($_['filterUserId'] ?? null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>
<?php include __DIR__ . '/common/page-end.php'; ?>
