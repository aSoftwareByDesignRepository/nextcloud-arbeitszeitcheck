<?php

declare(strict_types=1);

/**
 * Admin dashboard template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$overtimeOnboarding = $_['overtime_onboarding'] ?? [];
$showOvertimeBanner = !empty($overtimeOnboarding['show_banner']);
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$usersAdminUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.admin.users');
$violationsUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.compliance.violations');
$overtimePolicy = $_['overtime_policy'] ?? [];
$notificationsUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.admin.notifications');
$payoutsUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.overtime_payout.index');
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <div class="admin-dashboard__stack">
            <?php if ($showOvertimeBanner): ?>
            <?php
            $calloutVariant = 'warning';
            $calloutRole = 'region';
            $calloutId = 'admin-overtime-onboarding-banner';
            $calloutTitleId = 'admin-overtime-onboarding-title';
            $calloutTitle = $l->t('Configure overtime balances');
            $calloutText = $l->t('%s of %s employees have no overtime tracking start date (Stichtag). Without it, year-to-date balances are calculated from 1 January and may show large undertime until configured.', [
                (string)($overtimeOnboarding['without_tracking'] ?? 0),
                (string)($overtimeOnboarding['total_users'] ?? 0),
            ]);
            $calloutExtraClass = 'admin-overtime-onboarding';
            $calloutActions = [[
                'id' => 'admin-overtime-onboarding-link',
                'href' => $usersAdminUrl,
                'label' => $l->t('Open user administration'),
                'class' => 'azc-btn azc-btn--secondary azc-btn--sm',
            ]];
            include __DIR__ . '/common/alert-callout.php';
            ?>
            <?php endif; ?>

            <?php if (!empty($overtimePolicy['bank_enabled']) || !empty($overtimePolicy['traffic_light_enabled'])): ?>
            <section class="azc-card admin-overtime-policy" aria-labelledby="admin-overtime-policy-title">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="admin-overtime-policy-title" class="azc-card__title"><?php p($l->t('Overtime policy (active)')); ?></h2>
                        <p class="azc-card__lead"><?php p($l->t('Summary of current overtime settings. Change values under Notifications & overtime.')); ?></p>
                    </div>
                </header>
                <div class="azc-card__body admin-overtime-policy__grid">
                    <dl>
                        <dt><?php p($l->t('Balance alerts')); ?></dt>
                        <dd><?php p(!empty($overtimePolicy['traffic_light_enabled']) ? $l->t('On') : $l->t('Off')); ?></dd>
                        <?php if (!empty($overtimePolicy['traffic_light_enabled'])): ?>
                        <dt><?php p($l->t('Alert thresholds (h)')); ?></dt>
                        <dd><?php p($l->t('Over: yellow %s / red %s · Under: yellow %s / red %s', [
                            (string)($overtimePolicy['threshold_yellow_over'] ?? ''),
                            (string)($overtimePolicy['threshold_red_over'] ?? ''),
                            (string)($overtimePolicy['threshold_yellow_under'] ?? ''),
                            (string)($overtimePolicy['threshold_red_under'] ?? ''),
                        ])); ?></dd>
                        <?php endif; ?>
                    </dl>
                    <dl>
                        <dt><?php p($l->t('Overtime bank')); ?></dt>
                        <dd><?php p(!empty($overtimePolicy['bank_enabled']) ? $l->t('On') : $l->t('Off')); ?></dd>
                        <?php if (!empty($overtimePolicy['bank_enabled'])): ?>
                        <dt><?php p($l->t('Bank cap')); ?></dt>
                        <dd><?php p($l->t('%s h (yellow %s%% · red %s%%)', [
                            number_format((float)($overtimePolicy['bank_max_hours'] ?? 100), 0),
                            (string)($overtimePolicy['bank_yellow_percent'] ?? 80),
                            (string)($overtimePolicy['bank_red_percent'] ?? 95),
                        ])); ?></dd>
                        <dt><?php p($l->t('Block month closure until payout')); ?></dt>
                        <dd><?php p(!empty($overtimePolicy['block_month_closure_pending_payout']) ? $l->t('Yes') : $l->t('No')); ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
                <div class="admin-overtime-policy__actions">
                    <a class="azc-btn azc-btn--secondary azc-btn--sm" href="<?php p($notificationsUrl); ?>"><?php p($l->t('Notification settings')); ?></a>
                    <?php if (!empty($overtimePolicy['bank_enabled'])): ?>
                    <a class="azc-btn azc-btn--secondary azc-btn--sm" href="<?php p($payoutsUrl); ?>"><?php p($l->t('Overtime payouts')); ?></a>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php $unresolvedViolations = (int)($_['statistics']['unresolved_violations'] ?? 0); ?>
            <section class="azc-stat-strip admin-dashboard__stats" aria-label="<?php p($l->t('Statistics')); ?>">
                <button type="button"
                        class="azc-stat-tile"
                        data-stat="total_users"
                        data-drilldown-filter="all"
                        aria-expanded="false"
                        aria-label="<?php p($l->t('Total employees: %s. Show employee list.', [$_['statistics']['total_users'] ?? 0])); ?>">
                    <span class="azc-stat-tile__label"><?php p($l->t('Total employees')); ?></span>
                    <span class="azc-stat-tile__value stat-number"><?php p($_['statistics']['total_users'] ?? 0); ?></span>
                    <span class="azc-stat-tile__meta"><?php p($l->t('Click to show the employee list')); ?></span>
                </button>
                <button type="button"
                        class="azc-stat-tile azc-stat-tile--primary"
                        data-stat="active_users_today"
                        data-drilldown-filter="active_today"
                        aria-expanded="false"
                        aria-label="<?php p($l->t('Employees active today: %s. Show list.', [$_['statistics']['active_users_today'] ?? 0])); ?>">
                    <span class="azc-stat-tile__label"><?php p($l->t('Active today')); ?></span>
                    <span class="azc-stat-tile__value stat-number"><?php p($_['statistics']['active_users_today'] ?? 0); ?></span>
                    <span class="azc-stat-tile__meta"><?php p($l->t('Employees with time entries today')); ?></span>
                </button>
                <a class="azc-stat-tile <?php echo $unresolvedViolations > 0 ? 'azc-stat-tile--danger' : 'azc-stat-tile--success'; ?>"
                   data-stat="unresolved_violations"
                   data-drilldown-filter="violations"
                   data-href="<?php p($violationsUrl); ?>"
                   href="<?php p($violationsUrl); ?>"
                   title="<?php p($l->t('Number of open working-time compliance violations')); ?>"
                   aria-label="<?php p($l->t('Unresolved violations: %s. Open compliance violations.', [$unresolvedViolations])); ?>">
                    <span class="azc-stat-tile__label"><?php p($l->t('Open issues')); ?></span>
                    <span class="azc-stat-tile__value stat-number"><?php p((string)$unresolvedViolations); ?></span>
                    <span class="azc-stat-tile__meta"><?php p($l->t('Open compliance violations')); ?></span>
                </a>
            </section>

            <section class="azc-card admin-dashboard__issues" aria-labelledby="admin-dashboard-issues-title">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="admin-dashboard-issues-title" class="azc-card__title"><?php p($l->t('Current issues')); ?></h2>
                        <p class="azc-card__lead"><?php p($l->t('Working-time compliance violations that require your attention')); ?></p>
                    </div>
                    <div class="azc-card__header-actions">
                        <a href="<?php p($violationsUrl); ?>"
                           class="azc-btn azc-btn--secondary azc-btn--sm"
                           aria-label="<?php p($l->t('View all compliance violations')); ?>">
                            <?php p($l->t('View All Violations')); ?>
                        </a>
                    </div>
                </header>
                <div class="azc-card__body">
                    <?php if (empty($_['recent_violations'])): ?>
                        <div class="azc-empty-state">
                            <p class="azc-empty-state__title"><?php p($l->t('No problems found')); ?></p>
                            <p class="azc-empty-state__lead"><?php p($l->t('Great! All employees are following the working time rules correctly.')); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="table-container" tabindex="0" role="region" aria-label="<?php p($l->t('Recent compliance violations')); ?>">
                            <table class="table table--hover azc-table--responsive" aria-label="<?php p($l->t('Recent compliance violations')); ?>">
                                <thead>
                                    <tr>
                                        <th scope="col"><?php p($l->t('Employee')); ?></th>
                                        <th scope="col"><?php p($l->t('Problem Type')); ?></th>
                                        <th scope="col"><?php p($l->t('How Serious')); ?></th>
                                        <th scope="col"><?php p($l->t('Date')); ?></th>
                                        <th scope="col"><?php p($l->t('Fixed?')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($_['recent_violations'] ?? []) as $violation): ?>
                                        <?php
                                        $typeKey = $violation['type'] ?? '';
                                        $typeLabel = match ($typeKey) {
                                            'missing_break' => $l->t('Missing break'),
                                            'excessive_working_hours' => $l->t('Excessive working hours'),
                                            'insufficient_rest_period' => $l->t('Insufficient rest period'),
                                            'daily_hours_limit_exceeded' => $l->t('Daily hours limit exceeded'),
                                            'weekly_hours_limit_exceeded' => $l->t('Weekly hours limit exceeded'),
                                            'weekly_absolute_hours_exceeded' => $l->t('Absolute weekly hours maximum exceeded'),
                                            'night_work' => $l->t('Night work'),
                                            'sunday_work' => $l->t('Sunday work'),
                                            'holiday_work' => $l->t('Holiday work'),
                                            default => $typeKey,
                                        };
                                        $severityKey = $violation['severity'] ?? '';
                                        $severityLabel = match ($severityKey) {
                                            'error' => $l->t('High'),
                                            'warning' => $l->t('Medium'),
                                            'info' => $l->t('Low'),
                                            default => $severityKey,
                                        };
                                        $severityBadge = match ($severityKey) {
                                            'error' => 'error',
                                            'warning' => 'warning',
                                            default => 'primary',
                                        };
                                        ?>
                                        <tr>
                                            <td data-label="<?php p($l->t('Employee')); ?>"><?php p($violation['userDisplayName'] ?? $violation['userId']); ?></td>
                                            <td data-label="<?php p($l->t('Problem Type')); ?>"><?php p($typeLabel); ?></td>
                                            <td data-label="<?php p($l->t('How Serious')); ?>">
                                                <span class="badge badge--<?php p($severityBadge); ?>">
                                                    <?php p($severityLabel); ?>
                                                </span>
                                            </td>
                                            <td data-label="<?php p($l->t('Date')); ?>"><?php p($violation['date'] ?? '-'); ?></td>
                                            <td data-label="<?php p($l->t('Fixed?')); ?>">
                                                <?php if ($violation['resolved']): ?>
                                                    <span class="badge badge--success"><?php p($l->t('Resolved')); ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge--error"><?php p($l->t('Unresolved')); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
<?php
$adminDashboardL10n = [
	'activeTodayDrilldownTitle' => $l->t('Active today'),
	'totalEmployeesDrilldownTitle' => $l->t('All employees'),
	'drilldownHelp' => $l->t('Search by name or login. Export downloads the full filtered list.'),
	'Search employees' => $l->t('Search employees'),
	'Search employees…' => $l->t('Search employees…'),
	'Export CSV' => $l->t('Export CSV'),
	'Loading…' => $l->t('Loading…'),
	'Name' => $l->t('Name'),
	'User ID' => $l->t('User ID'),
	'Active today' => $l->t('Active today'),
	'Overtime tracking set' => $l->t('Overtime tracking set'),
	'No employees found.' => $l->t('No employees found.'),
	'Yes' => $l->t('Yes'),
	'No' => $l->t('No'),
	'drilldownLoadError' => $l->t('Could not load employee list.'),
	'drilldownTruncatedNotice' => $l->t('Showing the first results. Use search to narrow down the list.'),
	'statisticsRefreshError' => $l->t('Could not refresh statistics.'),
];
?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
Object.assign(window.ArbeitszeitCheck.l10n, <?php echo json_encode($adminDashboardL10n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>);
</script>

</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
