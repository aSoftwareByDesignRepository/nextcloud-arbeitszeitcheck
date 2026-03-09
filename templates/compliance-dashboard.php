<?php

declare(strict_types=1);

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
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <?php include __DIR__ . '/common/compliance-tabs.php'; ?>
        <div class="section">
            <div class="section-header">
                <h2><?php p($l->t('Compliance Dashboard')); ?></h2>
                <p><?php p($l->t('Check if your working time follows German labor law and see any problems that need fixing')); ?></p>
            </div>

            <!-- Compliance Status -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><?php p($l->t('Compliance Status')); ?></h3>
                </div>
                <div class="card-content">
                    <?php if ($loadError): ?>
                        <div class="alert alert--error">
                            <span class="alert-icon" aria-hidden="true">❌</span>
                            <div class="alert-content">
                                <strong class="alert-title"><?php p($l->t('Could not load compliance status')); ?></strong>
                                <p class="alert-message">
                                    <?php p($l->t('Please refresh the page to try again.')); ?>
                                </p>
                                <?php if (!empty($error)): ?>
                                    <p class="form-help">
                                        <?php p($error); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php elseif ($complianceStatus['compliant'] ?? false): ?>
                        <?php if (!$hasData): ?>
                            <div class="alert alert--info">
                                <span class="alert-icon" aria-hidden="true">ℹ️</span>
                                <div class="alert-content">
                                    <strong class="alert-title"><?php p($l->t('Not enough data yet')); ?></strong>
                                    <p class="alert-message">
                                        <?php p($l->t('Create time entries to get your compliance status. Once you have recorded working hours, we can check them against German labor law.')); ?>
                                    </p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert--success">
                                <span class="alert-icon" aria-hidden="true">✅</span>
                                <div class="alert-content">
                                    <strong class="alert-title"><?php p($l->t('Everything looks good!')); ?></strong>
                                    <p class="alert-message">
                                        <?php p($l->t('Your working time follows all German labor law rules. Keep up the good work!')); ?>
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert--warning">
                            <span class="alert-icon" aria-hidden="true">⚠️</span>
                            <div class="alert-content">
                                <strong class="alert-title"><?php p($l->t('Some problems found')); ?></strong>
                                <p class="alert-message">
                                    <?php p($l->t('There are issues with your working time that need attention. Please check the list below and fix them.')); ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!$loadError): ?>
                    <p>
                        <strong><?php p($l->t('How well you follow the rules:')); ?></strong>
                        <?php if ($hasData): ?>
                            <?php p($complianceStatus['score'] ?? 0); ?>%
                        <?php else: ?>
                            — <?php p($l->t('(no data yet)')); ?>
                        <?php endif; ?>
                        <span class="form-help" style="display: block; margin-top: var(--space-1);">
                            <?php p($l->t('This shows how well your working time follows German labor law. 100% means everything is perfect.')); ?>
                        </span>
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Violations -->
            <?php
            $urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
            ?>
            <div class="section">
                <div class="section-header section-header--with-actions">
                    <h3><?php p($l->t('Recent Violations')); ?></h3>
                    <div class="flex flex--gap">
                        <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.compliance.violations')); ?>"
                           class="btn btn--secondary btn--sm"
                           aria-label="<?php p($l->t('View all compliance violations')); ?>">
                            <?php p($l->t('View All Violations')); ?>
                        </a>
                        <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.compliance.reports')); ?>"
                           class="btn btn--secondary btn--sm"
                           aria-label="<?php p($l->t('View compliance reports')); ?>">
                            <?php p($l->t('Reports')); ?>
                        </a>
                    </div>
                </div>

                <?php if (empty($recentViolations)): ?>
                    <div class="empty-state">
                        <p><?php p($l->t('No recent violations')); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive" role="region" aria-label="<?php p($l->t('Recent violations')); ?>">
                        <table class="table" role="table" aria-label="<?php p($l->t('Recent violations')); ?>">
                            <thead>
                                <tr>
                                    <th scope="col"><?php p($l->t('Type')); ?></th>
                                    <th scope="col"><?php p($l->t('Severity')); ?></th>
                                    <th scope="col"><?php p($l->t('Date')); ?></th>
                                    <th scope="col"><?php p($l->t('Status')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (($recentViolations ?? []) as $violation): ?>
                                    <tr>
                                        <td><?php p($violation['type']); ?></td>
                                        <td>
                                            <span class="badge badge--<?php p($violation['severity'] === 'high' ? 'error' : ($violation['severity'] === 'medium' ? 'warning' : 'primary')); ?>">
                                                <?php p($violation['severity']); ?>
                                            </span>
                                        </td>
                                        <td><?php p($violation['date'] ?? '-'); ?></td>
                                        <td>
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
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->
