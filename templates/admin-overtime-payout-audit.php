<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Util\TemplateL10n;

/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
include __DIR__ . '/common/admin-overtime-payout-l10n.php';

$bankEnabled = (bool)($_['bankEnabled'] ?? false);
$notificationsUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.admin.overtimeSettings') . '#overtime-bank-heading';
$payoutUrl = $_['payoutProcessUrl'] ?? $urlGenerator->linkToRoute('arbeitszeitcheck.overtime_payout.index');
$defaultYear = (int)($_['defaultYear'] ?? (int)date('Y'));
$monthLabels = is_array($_['monthLabels'] ?? null) ? $_['monthLabels'] : [];
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <?php
        $policyPages = is_array($_['policyPages'] ?? null) ? $_['policyPages'] : [];
        include __DIR__ . '/common/azc-policy-pages-nav.php';
        ?>

        <div class="admin-ot-payout-audit">
            <p class="azc-admin-policy-layout__lead admin-ot-audit__actions-lead">
                <a href="<?php p($payoutUrl); ?>" class="azc-btn azc-btn--primary azc-btn--touch">
                    <?php p($l->t('Process payouts')); ?>
                </a>
            </p>
            <?php if (!$bankEnabled): ?>
            <?php
            $calloutVariant = 'warning';
            $calloutRole = 'alert';
            $calloutTitleId = 'admin-ot-audit-bank-off-title';
            $calloutTitle = $l->t('Overtime bank is off');
            $calloutText = $l->t('The overtime bank is disabled. Historical payout records may still appear below.');
            $calloutExtraClass = 'admin-ot-audit__bank-off';
            $calloutActions = [[
                'href' => $notificationsUrl,
                'label' => $l->t('Open overtime bank settings'),
                'class' => 'azc-btn azc-btn--primary',
            ]];
            include __DIR__ . '/common/alert-callout.php';
            ?>
            <?php endif; ?>

            <section class="azc-card admin-ot-audit-filters" aria-labelledby="admin-ot-audit-filter-heading">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="admin-ot-audit-filter-heading" class="azc-card__title">
                            <?php p($l->t('Search payouts')); ?>
                        </h2>
                        <p id="admin-ot-audit-filter-help" class="azc-card__lead">
                            <?php p($l->t('Pick a year, optionally a month and one employee, then apply. Leave employee empty to see everyone.')); ?>
                        </p>
                    </div>
                </header>
                <div class="azc-card__body">
                    <form id="ot-audit-filter-form" class="admin-ot-audit-filters__form" novalidate>
                        <div class="admin-ot-audit-filters__grid" role="group" aria-label="<?php p($l->t('Filter options')); ?>">
                            <div class="form-group admin-ot-audit-filters__field--year">
                                <label for="ot-audit-year" class="form-label"><?php p($l->t('Year')); ?></label>
                                <input type="number" class="form-input" id="ot-audit-year" name="year"
                                    min="2000" max="2100" step="1" required
                                    value="<?php p((string)$defaultYear); ?>"
                                    aria-describedby="admin-ot-audit-filter-help">
                            </div>
                            <div class="form-group admin-ot-audit-filters__field--month">
                                <label for="ot-audit-month" class="form-label"><?php p($l->t('Month')); ?></label>
                                <select class="form-input" id="ot-audit-month" name="month" aria-describedby="admin-ot-audit-filter-help">
                                    <option value=""><?php p($l->t('All months')); ?></option>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php p((string)$m); ?>"><?php p($monthLabels[$m] ?? (string)$m); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="form-group admin-ot-audit-filters__field--employee">
                                <label for="ot-audit-employee-search" class="form-label">
                                    <?php p($l->t('Employee')); ?>
                                    <span class="admin-ot-audit-filters__optional"><?php p($l->t('(optional)')); ?></span>
                                </label>
                                <input type="hidden" id="ot-audit-user-id" name="userId" value="">
                                <div class="user-picker admin-ot-audit-filters__picker" id="ot-audit-employee-picker">
                                    <div class="user-picker__control">
                                        <input type="search"
                                            id="ot-audit-employee-search"
                                            class="form-input user-picker__search"
                                            autocomplete="off"
                                            autocapitalize="none"
                                            spellcheck="false"
                                            placeholder="<?php p($l->t('Search by name or login…')); ?>"
                                            role="combobox"
                                            aria-autocomplete="list"
                                            aria-expanded="false"
                                            aria-controls="ot-audit-employee-listbox"
                                            aria-describedby="admin-ot-audit-filter-help ot-audit-employee-status">
                                        <button type="button"
                                            class="user-picker__clear admin-ot-audit-filters__clear"
                                            id="ot-audit-clear-employee"
                                            hidden
                                            aria-label="<?php p($l->t('Clear employee')); ?>">
                                            <span aria-hidden="true">&times;</span>
                                        </button>
                                    </div>
                                    <div id="ot-audit-employee-listbox"
                                        class="user-picker__list"
                                        role="listbox"
                                        hidden
                                        aria-label="<?php p($l->t('Matching employees')); ?>"></div>
                                    <p id="ot-audit-employee-status" class="admin-ot-audit-filters__picker-status azc-sr-only" role="status" aria-live="polite" aria-atomic="true"></p>
                                </div>
                            </div>
                            <div class="admin-ot-audit-filters__actions">
                                <button type="submit" class="azc-btn azc-btn--primary" id="ot-audit-apply">
                                    <?php p($l->t('Apply filters')); ?>
                                </button>
                                <button type="button" class="azc-btn azc-btn--ghost" id="ot-audit-reset">
                                    <?php p($l->t('Reset filters')); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>

            <div id="ot-audit-summary"
                class="azc-callout azc-callout--info admin-ot-audit__summary"
                role="status"
                aria-live="polite"
                aria-atomic="true"
                hidden></div>

            <section id="ot-audit-gaps-section" class="azc-card azc-callout azc-callout--warning admin-ot-audit-gaps" hidden aria-labelledby="ot-audit-gaps-heading">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="ot-audit-gaps-heading" class="azc-card__title"><?php p($l->t('Compliance gaps')); ?></h2>
                        <p class="azc-card__lead">
                            <?php p($l->t('These employees finalized a month with overtime above the bank cap, but no payout was recorded. Payroll should process the payout or document an exception.')); ?>
                        </p>
                    </div>
                </header>
                <div class="azc-card__body">
                    <p id="ot-audit-gaps-count" class="admin-ot-audit-gaps__count" role="status" aria-live="polite"></p>
                    <ul id="ot-audit-gaps-list" class="admin-ot-audit-gaps__list"></ul>
                </div>
            </section>

            <section class="azc-card admin-ot-audit__table" aria-labelledby="ot-audit-table-heading">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="ot-audit-table-heading" class="azc-card__title"><?php p($l->t('Recorded payouts')); ?></h2>
                    </div>
                </header>
                <div class="azc-card__body">
                    <div class="table-container" tabindex="0" role="region" aria-labelledby="ot-audit-table-heading">
                        <table class="table table--hover azc-table--responsive grid-table admin-ot-audit__grid" id="ot-audit-table">
                            <caption class="sr-only"><?php p($l->t('Recorded overtime payouts matching the current filters')); ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php p($l->t('Period')); ?></th>
                                    <th scope="col"><?php p($l->t('Employee')); ?></th>
                                    <th scope="col" class="admin-ot-audit__num"><?php p($l->t('Hours paid')); ?></th>
                                    <th scope="col"><?php p($l->t('Processed')); ?></th>
                                    <th scope="col"><span class="sr-only"><?php p($l->t('Actions')); ?></span></th>
                                </tr>
                            </thead>
                            <tbody id="ot-audit-tbody">
                                <tr><td colspan="5"><?php p($l->t('Loading…')); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div id="ot-audit-live" class="admin-ot-audit__live" role="alert" aria-live="assertive" aria-atomic="true" hidden></div>
        </div>

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ARBEITSZEITCHECK_OT_PAYOUT_AUDIT = {
	apiUrl: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.overtime_payout.listAudit'), TemplateL10n::JSON_ENCODE_FLAGS); ?>,
	payoutProcessUrl: <?php echo json_encode($payoutUrl, TemplateL10n::JSON_ENCODE_FLAGS); ?>,
	adminUserSearchUrl: <?php echo json_encode($_['adminUserSearchUrl'] ?? $urlGenerator->linkToRoute('arbeitszeitcheck.admin.getUsers'), TemplateL10n::JSON_ENCODE_FLAGS); ?>,
	defaultYear: <?php echo (int)$defaultYear; ?>,
	i18n: <?php echo json_encode($otPayoutAuditI18n, TemplateL10n::JSON_ENCODE_FLAGS); ?>
};
</script>

</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
