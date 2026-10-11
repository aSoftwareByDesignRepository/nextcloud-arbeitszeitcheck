<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Service\IconCatalog;

/**
 * Compliance dashboard template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$complianceStatus = $_['complianceStatus'] ?? [];
$recentViolations = $_['recentViolations'] ?? [];
$error = $_['error'] ?? null;
$loadError = $complianceStatus['load_error'] ?? false;
$hasData = $complianceStatus['has_data'] ?? true;
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <?php include __DIR__ . '/common/compliance-tabs.php'; ?>

        <section class="azc-card compliance-dashboard__status" aria-labelledby="compliance-status-heading">
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
                        $calloutText = $l->t('Create time entries to get your compliance status. Once you have recorded working hours, we can check them against the configured labour law.');
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
                        $calloutText = $l->t('Your working time follows all configured labour-law rules. Keep up the good work!');
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
                    $calloutText = $l->t('There are issues with your working time that need attention. Please check the list below and fix them.');
                    $calloutHint = '';
                    include __DIR__ . '/common/alert-callout.php';
                    ?>
                <?php endif; ?>
                <?php if (!$loadError): ?>
                    <p class="compliance-dashboard__score">
                        <strong><?php p($l->t('How well you follow the rules:')); ?></strong>
                        <?php if ($hasData): ?>
                            <?php p((string)($complianceStatus['score'] ?? 0)); ?>%
                        <?php else: ?>
                            — <?php p($l->t('(no data yet)')); ?>
                        <?php endif; ?>
                        <span class="form-help compliance-dashboard__score-hint">
                            <?php p($l->t('This shows how well your working time follows the configured labour law. 100%% means everything is perfect.')); ?>
                        </span>
                    </p>
                <?php endif; ?>
            </div>
        </section>

        <section class="azc-card compliance-dashboard__violations" aria-labelledby="compliance-violations-heading">
            <header class="azc-card__header">
                <div class="azc-card__header-text">
                    <h2 id="compliance-violations-heading" class="azc-card__title"><?php p($l->t('Recent Violations')); ?></h2>
                </div>
                <div class="azc-card__header-actions">
                    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.compliance.violations')); ?>"
                       class="azc-btn azc-btn--secondary azc-btn--sm"
                       aria-label="<?php p($l->t('View all compliance violations')); ?>">
                        <?php p($l->t('View All Violations')); ?>
                    </a>
                    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.compliance.reports')); ?>"
                       class="azc-btn azc-btn--secondary azc-btn--sm"
                       aria-label="<?php p($l->t('View compliance reports')); ?>">
                        <?php p($l->t('Reports')); ?>
                    </a>
                </div>
            </header>
            <div class="azc-card__body">
                <?php if (empty($recentViolations)): ?>
                    <div class="azc-empty-state">
                        <p class="azc-empty-state__title"><?php p($l->t('No recent violations')); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-container" tabindex="0" role="region" aria-label="<?php p($l->t('Recent violations')); ?>">
                        <table class="table table--hover azc-table--responsive" aria-label="<?php p($l->t('Recent violations')); ?>">
                            <thead>
                                <tr>
                                    <th scope="col"><?php p($l->t('Type')); ?></th>
                                    <th scope="col"><?php p($l->t('Severity')); ?></th>
                                    <th scope="col"><?php p($l->t('Date')); ?></th>
                                    <th scope="col"><?php p($l->t('Status')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentViolations as $violation): ?>
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
                                    'missing_presence' => $l->t('Required presence not covered'),
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
                                        <td data-label="<?php p($l->t('Type')); ?>"><?php p($typeLabel); ?></td>
                                        <td data-label="<?php p($l->t('Severity')); ?>">
                                            <span class="badge badge--<?php p($severityBadge); ?>">
                                                <?php p($severityLabel); ?>
                                            </span>
                                        </td>
                                        <td data-label="<?php p($l->t('Date')); ?>"><?php p($violation['date'] ?? '-'); ?></td>
                                        <td data-label="<?php p($l->t('Status')); ?>">
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

</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
