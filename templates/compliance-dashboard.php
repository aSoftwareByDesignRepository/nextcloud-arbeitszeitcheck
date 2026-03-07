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
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
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
                    <?php if ($complianceStatus['compliant'] ?? false): ?>
                        <div class="alert alert--success">
                            <span class="alert-icon" aria-hidden="true">✅</span>
                            <div class="alert-content">
                                <strong class="alert-title"><?php p($l->t('Everything looks good!')); ?></strong>
                                <p class="alert-message">
                                    <?php p($l->t('Your working time follows all German labor law rules. Keep up the good work!')); ?>
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert--warning">
                            <span class="alert-icon" aria-hidden="true">⚠️</span>
                            <div class="alert-content">
                                <strong class="alert-title"><?php p($l->t('Some problems found')); ?></strong>
                                <p class="alert-message">
                                    <?php p($l->t('There are some issues with your working time that need attention. Please check the list below and fix them.')); ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <p>
                        <strong><?php p($l->t('How well you follow the rules:')); ?></strong>
                        <?php p($complianceStatus['score'] ?? 0); ?>%
                        <span class="form-help" style="display: block; margin-top: var(--space-1);">
                            <?php p($l->t('This shows how well your working time follows German labor law. 100% means everything is perfect.')); ?>
                        </span>
                    </p>
                </div>
            </div>

            <!-- Recent Violations -->
            <div class="section">
                <div class="section-header">
                    <h3><?php p($l->t('Recent Violations')); ?></h3>
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
                                <?php foreach ($recentViolations as $violation): ?>
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
