<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Util\TemplateL10n;

/**
 * Guided year-end overtime reset (SPEC optional-workforce §9.5).
 * Preview → per-user review → confirm → live status. No silent resets.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$yearEndEnabled = !empty($_['yearEndEnabled']);
$defaults = is_array($_['yearEndDefaults'] ?? null) ? $_['yearEndDefaults'] : [];
$policyRevision = (int)($_['policyRevision'] ?? 0);
$workforceSettingsUrl = (string)($_['workforceSettingsUrl'] ?? '#');
$apiBase = (string)($_['apiBase'] ?? '');
$users = is_array($_['users'] ?? null) ? $_['users'] : [];
$currentYear = (int)date('Y');
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <?php
        $policyPages = is_array($_['policyPages'] ?? null) ? $_['policyPages'] : [];
        include __DIR__ . '/common/azc-policy-pages-nav.php';
        ?>

        <?php if (!$yearEndEnabled): ?>
            <section class="azc-card" aria-labelledby="ye-disabled-heading">
                <div class="azc-card__body">
                    <?php
                    $calloutVariant = 'info';
                    $calloutRole = 'status';
                    $calloutTitle = $l->t('Year-end reset is disabled');
                    $calloutText = $l->t('Enable the guided year-end reset in the workforce policy settings first.');
                    $calloutActions = [[
                        'href' => $workforceSettingsUrl,
                        'label' => $l->t('Open workforce settings'),
                        'class' => 'azc-btn azc-btn--primary',
                    ]];
                    include __DIR__ . '/common/alert-callout.php';
                    ?>
                </div>
            </section>
        <?php else: ?>
        <div id="azc-year-end" class="azc-year-end"
             data-api-base="<?php p($apiBase); ?>"
             data-policy-revision="<?php p((string)$policyRevision); ?>">
            <div id="azc-ye-live" class="azc-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>

            <section class="azc-card" aria-labelledby="ye-wizard-heading">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="ye-wizard-heading" class="azc-card__title"><?php p($l->t('Start a reset run')); ?></h2>
                        <p class="azc-card__lead"><?php p($l->t('Step 1 — choose who and what. Step 2 — review the preview. Nothing is written before you confirm.')); ?></p>
                    </div>
                </header>
                <div class="azc-card__body">
                    <form id="azc-ye-preview-form" novalidate>
                        <div class="form-group">
                            <label for="azc-ye-year" class="form-label"><?php p($l->t('Closing year')); ?></label>
                            <select id="azc-ye-year" name="closingYear" class="form-select" required>
                                <?php for ($y = $currentYear - 1; $y >= $currentYear - 5 && $y >= 2000; $y--): ?>
                                    <option value="<?php p((string)$y); ?>"><?php p((string)$y); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <fieldset class="form-fieldset" aria-labelledby="azc-ye-signs-heading">
                            <legend id="azc-ye-signs-heading" class="form-legend"><?php p($l->t('What happens to balances')); ?></legend>
                            <div class="form-checkbox">
                                <input type="checkbox" id="azc-ye-positive" name="resetPositive" value="1"
                                       <?php echo !empty($defaults['resetPositive']) ? 'checked' : ''; ?>>
                                <label for="azc-ye-positive" class="form-label"><?php p($l->t('Reset positive balances (overtime)')); ?></label>
                            </div>
                            <div class="form-checkbox">
                                <input type="checkbox" id="azc-ye-negative" name="waiveNegative" value="1"
                                       <?php echo !empty($defaults['waiveNegative']) ? 'checked' : ''; ?>>
                                <label for="azc-ye-negative" class="form-label"><?php p($l->t('Waive negative balances (undertime)')); ?></label>
                            </div>
                            <p class="azc-field-error" id="azc-ye-signs-error" role="alert" hidden><?php p($l->t('Choose at least one option.')); ?></p>
                        </fieldset>

                        <div class="form-group">
                            <label for="azc-ye-users" class="form-label"><?php p($l->t('Employees')); ?>
                                <span class="form-required" aria-hidden="true">*</span></label>
                            <p class="form-help" id="azc-ye-users-help"><?php p($l->t('Hold Ctrl/Cmd to select several. Maximum 200 per run.')); ?></p>
                            <select id="azc-ye-users" name="userIds" class="form-select azc-ye-users" multiple size="8"
                                    required aria-describedby="azc-ye-users-help"></select>
                            <p class="azc-field-error" id="azc-ye-users-error" role="alert" hidden><?php p($l->t('Select at least one employee.')); ?></p>
                            <script type="application/json" id="azc-ye-users-data"><?php echo json_encode($users, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
                        </div>

                        <div class="form-group">
                            <label for="azc-ye-reason" class="form-label"><?php p($l->t('Reason (shown in the audit log)')); ?>
                                <span class="form-required" aria-hidden="true">*</span></label>
                            <textarea id="azc-ye-reason" name="reason" class="form-input" rows="2"
                                      minlength="10" maxlength="500" required
                                      placeholder="<?php p($l->t('e.g. Collective agreement 2026 — annual overtime nullification')); ?>"></textarea>
                            <p class="form-help"><?php p($l->t('10 to 500 characters.')); ?></p>
                            <p class="azc-field-error" id="azc-ye-reason-error" role="alert" hidden><?php p($l->t('Please give a reason of at least 10 characters.')); ?></p>
                        </div>

                        <div class="azc-dialog__actions">
                            <button type="submit" class="azc-btn azc-btn--primary" id="azc-ye-preview-btn">
                                <?php p($l->t('Preview the reset')); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="azc-card" id="azc-ye-preview-card" aria-labelledby="ye-preview-heading" hidden>
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="ye-preview-heading" class="azc-card__title"><?php p($l->t('Step 2 — review the preview')); ?></h2>
                        <p class="azc-card__lead"><?php p($l->t('Check every row. Blocked employees are never reset. The preview expires after 15 minutes.')); ?></p>
                    </div>
                </header>
                <div class="azc-card__body">
                    <table class="azc-table table--hover azc-table--cards" id="azc-ye-preview-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Employee')); ?></th>
                                <th scope="col"><?php p($l->t('Balance now')); ?></th>
                                <th scope="col"><?php p($l->t('Change')); ?></th>
                                <th scope="col"><?php p($l->t('Result')); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <div class="azc-dialog__actions">
                        <button type="button" class="azc-btn azc-btn--primary" id="azc-ye-confirm-btn">
                            <?php p($l->t('Confirm and run the reset')); ?>
                        </button>
                        <button type="button" class="azc-btn azc-btn--secondary" id="azc-ye-discard-btn">
                            <?php p($l->t('Discard preview')); ?>
                        </button>
                    </div>
                </div>
            </section>

            <section class="azc-card" id="azc-ye-run-card" aria-labelledby="ye-run-heading" hidden>
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="ye-run-heading" class="azc-card__title"><?php p($l->t('Run status')); ?></h2>
                    </div>
                </header>
                <div class="azc-card__body">
                    <p id="azc-ye-run-summary" class="form-help" role="status"></p>
                    <table class="azc-table table--hover azc-table--cards" id="azc-ye-run-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Employee')); ?></th>
                                <th scope="col"><?php p($l->t('State')); ?></th>
                                <th scope="col"><?php p($l->t('Detail')); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </section>

            <section class="azc-card" aria-labelledby="ye-history-heading">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="ye-history-heading" class="azc-card__title"><?php p($l->t('Past runs')); ?></h2>
                    </div>
                </header>
                <div class="azc-card__body">
                    <table class="azc-table table--hover azc-table--cards" id="azc-ye-history-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Run')); ?></th>
                                <th scope="col"><?php p($l->t('Closing year')); ?></th>
                                <th scope="col"><?php p($l->t('Origin')); ?></th>
                                <th scope="col"><?php p($l->t('State')); ?></th>
                                <th scope="col"><?php p($l->t('Started')); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </section>
        </div>
        <?php endif; ?>
        </div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
