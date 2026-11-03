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
$auditUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.overtime_payout.auditIndex');
$bankMax = (float)($_['bankMaxHours'] ?? 100);
$defaultYear = (int)($_['defaultYear'] ?? (int)date('Y'));
$defaultMonth = (int)($_['defaultMonth'] ?? (int)date('n'));

$monthLabels = [];
$fmt = new \IntlDateFormatter(
	$l->getLanguageCode(),
	\IntlDateFormatter::NONE,
	\IntlDateFormatter::NONE,
	null,
	null,
	'MMMM'
);
for ($m = 1; $m <= 12; $m++) {
	$dt = \DateTime::createFromFormat('!Y-n-j', sprintf('%d-%d-1', $defaultYear, $m));
	$monthLabels[$m] = $dt !== false ? (string)$fmt->format($dt) : (string)$m;
}
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <?php
        $policyPages = is_array($_['policyPages'] ?? null) ? $_['policyPages'] : [];
        include __DIR__ . '/common/azc-policy-pages-nav.php';
        ?>

        <div class="admin-ot-payouts">
            <?php
            $calloutVariant = 'info';
            $calloutRole = 'status';
            $calloutId = 'admin-ot-payout-saldo-hint';
            $calloutTitleId = 'admin-ot-payout-saldo-hint-title';
            $calloutTitle = $l->t('Payouts are not the overtime balance');
            $calloutText = $l->t('Payouts only record hours above the overtime bank cap. Each employee’s running hour balance (worked minus contract target) is on their dashboard.');
            $calloutExtraClass = 'admin-ot-payouts__saldo-hint';
            $calloutActions = [[
                'href' => $auditUrl,
                'label' => $l->t('Open payout audit'),
                'class' => 'azc-btn azc-btn--secondary',
            ]];
            include __DIR__ . '/common/alert-callout.php';
            ?>

            <?php if (!$bankEnabled): ?>
            <?php
            $calloutVariant = 'warning';
            $calloutRole = 'alert';
            $calloutId = 'admin-ot-payout-bank-off';
            $calloutTitleId = 'admin-ot-payout-bank-off-title';
            $calloutTitle = $l->t('Overtime bank is off');
            $calloutText = $l->t('The overtime bank is disabled. Payouts cannot be processed until you enable it.');
            $calloutExtraClass = 'admin-ot-payouts__bank-off';
            $calloutActions = [[
                'href' => $notificationsUrl,
                'label' => $l->t('Open overtime bank settings'),
                'class' => 'azc-btn azc-btn--primary',
            ]];
            include __DIR__ . '/common/alert-callout.php';
            ?>
            <?php endif; ?>

            <section class="azc-card admin-ot-payouts__guide" aria-labelledby="admin-ot-payout-guide-title">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="admin-ot-payout-guide-title" class="azc-card__title">
                            <?php p($l->t('How payout works')); ?>
                        </h2>
                    </div>
                </header>
                <div class="azc-card__body">
                    <ol class="admin-ot-payouts__steps">
                        <li><?php p($l->t('Select the completed calendar month.')); ?></li>
                        <li><?php p($l->t('Review employees with hours above the %s h bank cap.', [number_format($bankMax, 0)])); ?></li>
                        <li><?php p($l->t('Confirm payout — the employee is notified and the record cannot be deleted.')); ?></li>
                    </ol>
                </div>
            </section>

            <section class="azc-card admin-ot-payouts__filters" aria-labelledby="admin-ot-payout-filter-heading">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="admin-ot-payout-filter-heading" class="azc-card__title">
                            <?php p($l->t('Select month')); ?>
                        </h2>
                        <p id="admin-ot-payout-bank-hint" class="azc-card__lead">
                            <?php if ($bankEnabled): ?>
                                <?php p($l->t('Bank cap: %s hours. Payout is only possible after the month has ended.', [number_format($bankMax, 0)])); ?>
                            <?php else: ?>
                                <?php p($l->t('Enable the overtime bank to process payouts.')); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </header>
                <div class="azc-card__body">
                    <div class="admin-ot-payouts__period form-row form-row--inline">
                        <div class="form-group">
                            <label for="ot-payout-year" class="form-label"><?php p($l->t('Year')); ?></label>
                            <input type="number" id="ot-payout-year" class="form-input" min="2000" max="2100" step="1" required
                                value="<?php p((string)$defaultYear); ?>"
                                aria-describedby="admin-ot-payout-bank-hint">
                        </div>
                        <div class="form-group">
                            <label for="ot-payout-month" class="form-label"><?php p($l->t('Month')); ?></label>
                            <select id="ot-payout-month" class="form-input" required aria-describedby="admin-ot-payout-bank-hint">
                                <?php foreach ($monthLabels as $m => $label): ?>
                                <option value="<?php p((string)$m); ?>"<?php echo $m === $defaultMonth ? ' selected' : ''; ?>>
                                    <?php p($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="admin-ot-payouts__toolbar" role="group" aria-label="<?php p($l->t('Actions')); ?>">
                        <button type="button" class="azc-btn azc-btn--secondary" id="ot-payout-refresh">
                            <?php p($l->t('Load list')); ?>
                        </button>
                        <button type="button" class="azc-btn azc-btn--secondary" id="ot-payout-export"<?php echo $bankEnabled ? '' : ' disabled'; ?>>
                            <?php p($l->t('Export CSV for payroll')); ?>
                        </button>
                        <button type="button" class="azc-btn azc-btn--primary" id="ot-payout-bulk"<?php echo $bankEnabled ? '' : ' disabled'; ?>>
                            <?php p($l->t('Pay out all pending')); ?>
                        </button>
                    </div>
                </div>
            </section>

            <div id="ot-payout-summary"
                class="azc-callout azc-callout--info admin-ot-payouts__summary"
                role="status"
                aria-live="polite"
                aria-atomic="true"
                hidden></div>

            <section class="azc-card admin-ot-payouts__table" aria-labelledby="admin-ot-payout-table-heading">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="admin-ot-payout-table-heading" class="azc-card__title">
                            <?php p($l->t('Employees')); ?>
                        </h2>
                        <p class="azc-card__lead">
                            <?php p($l->t('Pending rows can be paid out individually. Paid rows are locked for audit.')); ?>
                        </p>
                    </div>
                </header>
                <div class="azc-card__body">
                    <div class="table-container">
                        <table class="table table--hover azc-table--responsive grid-table admin-ot-payouts__grid" id="ot-payout-table">
                            <caption class="sr-only"><?php p($l->t('Employees with overtime eligible for payout')); ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php p($l->t('Employee')); ?></th>
                                    <th scope="col"><?php p($l->t('Status')); ?></th>
                                    <th scope="col"><?php p($l->t('Eligible (h)')); ?></th>
                                    <th scope="col"><?php p($l->t('Paid (h)')); ?></th>
                                    <th scope="col"><span class="sr-only"><?php p($l->t('Actions')); ?></span></th>
                                </tr>
                            </thead>
                            <tbody id="ot-payout-tbody">
                                <tr><td colspan="5" class="azc-table-empty"><div class="azc-empty-state"><h3 class="azc-empty-state__title"><?php p($l->t('Choose a month and click Load list.')); ?></h3></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div id="ot-payout-live" class="admin-ot-payouts__live" role="status" aria-live="polite" aria-atomic="true"></div>
        </div>

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ARBEITSZEITCHECK_OT_PAYOUT = {
	bankEnabled: <?php echo $bankEnabled ? 'true' : 'false'; ?>,
	apiList: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.overtime_payout.listMonth'), TemplateL10n::JSON_ENCODE_FLAGS); ?>,
	apiProcess: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.overtime_payout.processOne'), TemplateL10n::JSON_ENCODE_FLAGS); ?>,
	apiBulk: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.overtime_payout.processBulk'), TemplateL10n::JSON_ENCODE_FLAGS); ?>,
	apiExport: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.overtime_payout.exportCsv'), TemplateL10n::JSON_ENCODE_FLAGS); ?>,
	i18n: <?php echo json_encode($otPayoutI18n, TemplateL10n::JSON_ENCODE_FLAGS); ?>
};
</script>

</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
