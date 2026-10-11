<?php

declare(strict_types=1);

/**
 * Admin tariff rule sets CRUD page for the arbeitszeitcheck app.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$tariffL10n = [
	'createTitle' => $l->t('Create tariff rule set'),
	'editTitle' => $l->t('Edit tariff rule set'),
	'viewTitle' => $l->t('View tariff rule set'),
	'readOnlyHelp' => $l->t('This rule set is locked for audit. Create a new draft version to change calculation rules.'),
	'tariffCode' => $l->t('Tariff code'),
	'tariffCodeHelp' => $l->t('Short code identifying this collective agreement (for example "TVoeD-VKA").'),
	'version' => $l->t('Version'),
	'versionHelp' => $l->t('Specific version of the agreement, for example "2024.1".'),
	'jurisdiction' => $l->t('Jurisdiction'),
	'jurisdictionHelp' => $l->t('Optional region or labor jurisdiction this set applies to.'),
	'validFrom' => $l->t('Valid from'),
	'validTo' => $l->t('Valid to'),
	'validToHelp' => $l->t('Optional. Leave empty if the rule set is open-ended.'),
	'activationMode' => $l->t('Activation mode'),
	'activationImmediate' => $l->t('Immediately'),
	'activationNextMonth' => $l->t('Start of next month'),
	'activationNextYear' => $l->t('Start of next year'),
	'activationModeHelp' => $l->t('When activated, the rule set will take effect at the chosen point in time.'),
	'modules' => $l->t('Calculation modules'),
	'modulesHelp' => $l->t('Configure how vacation entitlement is calculated. The base formula module is required; additional modules adjust the result.'),
	'addModule' => $l->t('Add module'),
	'moduleType' => $l->t('Module type'),
	'moduleBaseFormula' => $l->t('Base formula (reference days)'),
	'moduleAdditional' => $l->t('Additional entitlements (extra days)'),
	'moduleDeductions' => $l->t('Deductions (subtract days)'),
	'moduleRounding' => $l->t('Rounding rule'),
	'moduleProRata' => $l->t('Pro-rata rule'),
	'referenceDays' => $l->t('Reference days'),
	'referenceWeekDays' => $l->t('Reference working days per week'),
	'workDaysPerWeek' => $l->t('Working days per week'),
	'days' => $l->t('Days'),
	'roundingMode' => $l->t('Rounding mode'),
	'roundingCommercial' => $l->t('Commercial (round half away from zero)'),
	'roundingHalfDay' => $l->t('Nearest half day'),
	'roundingCeil' => $l->t('Always round up'),
	'roundingFloor' => $l->t('Always round down'),
	'proRataMode' => $l->t('Pro-rata calculation'),
	'proRataNone' => $l->t('None (full entitlement)'),
	'proRataMonth' => $l->t('Per month worked'),
	'proRataDay' => $l->t('Per day worked'),
	'remove' => $l->t('Remove'),
	'save' => $l->t('Save'),
	'cancel' => $l->t('Cancel'),
	'close' => $l->t('Close'),
	'edit' => $l->t('Edit'),
	'view' => $l->t('View'),
	'delete' => $l->t('Delete'),
	'activate' => $l->t('Activate'),
	'retire' => $l->t('Retire'),
	'created' => $l->t('Tariff rule set created.'),
	'updated' => $l->t('Tariff rule set updated.'),
	'deleted' => $l->t('Tariff rule set deleted.'),
	'activated' => $l->t('Tariff rule set activated.'),
	'retired' => $l->t('Tariff rule set retired.'),
	'confirmDeleteTitle' => $l->t('Delete tariff rule set?'),
	'confirmDeleteMessage' => $l->t('This permanently removes the draft tariff rule set. Active or retired sets cannot be deleted.'),
	'confirmActivateTitle' => $l->t('Activate tariff rule set?'),
	'confirmActivateMessage' => $l->t('Activating this rule set retires any currently active set with the same tariff code. Continue?'),
	'confirmRetireTitle' => $l->t('Retire tariff rule set?'),
	'confirmRetireMessage' => $l->t('Retired sets stay visible for audit purposes but no longer apply to new vacation calculations.'),
	'statusDraft' => $l->t('Draft'),
	'statusActive' => $l->t('Active'),
	'statusRetired' => $l->t('Retired'),
	'status' => $l->t('Status'),
	'modulesCol' => $l->t('Modules'),
	'actions' => $l->t('Actions'),
	'loading' => $l->t('Loading…'),
	'loadingError' => $l->t('Could not load tariff rule sets. Please try again.'),
	'createError' => $l->t('Could not create tariff rule set.'),
	'updateError' => $l->t('Could not update tariff rule set.'),
	'deleteError' => $l->t('Could not delete tariff rule set.'),
	'activateError' => $l->t('Could not activate tariff rule set.'),
	'retireError' => $l->t('Could not retire tariff rule set.'),
	'tariffCodeRequired' => $l->t('Please enter a tariff code.'),
	'versionRequired' => $l->t('Please enter a version.'),
	'validFromRequired' => $l->t('Please choose a valid-from date.'),
	'validityInvalid' => $l->t('"Valid to" must be after "Valid from".'),
	'moduleTypeRequired' => $l->t('Please choose a module type.'),
	'baseFormulaRequired' => $l->t('The base formula needs reference days and reference working days per week.'),
	'modulesRequired' => $l->t('Add at least one calculation module, including the base formula.'),
	'cannotRemoveBaseModule' => $l->t('At least one base formula module is required.'),
	'noRuleSets' => $l->t('No tariff rule sets yet'),
	'noRuleSetsHelp' => $l->t('Create the first tariff rule set to enable tariff-based vacation entitlement.'),
	'workflowHelp' => $l->t('Draft → Activate when ready → Retire when superseded. Only drafts can be edited or deleted.'),
	'sectionIdentity' => $l->t('Identify this rule set'),
	'sectionValidity' => $l->t('Validity and activation'),
	'sectionModules' => $l->t('Calculation modules'),
	'moduleLegend' => $l->t('Module %1$s: %2$s', ['%1$s', '%2$s']),
	'duplicateConflictDraft' => $l->t('A draft already exists for tariff code "%1$s" and version "%2$s". Open that draft to continue, or change the code or version below.', ['%1$s', '%2$s']),
	'duplicateConflictLocked' => $l->t('Tariff code "%1$s" with version "%2$s" already exists (status: %3$s). Pick a new version label to create another rule set.', ['%1$s', '%2$s', '%3$s']),
	'openExistingDraft' => $l->t('Open existing draft'),
	'viewExistingRuleSet' => $l->t('View existing rule set'),
	'showInList' => $l->t('Show in list'),
	'duplicateFieldHint' => $l->t('This combination is already in use.'),
	'versionSuggestion' => $l->t('Suggested new version label: %1$s', ['%1$s']),
	'useSuggestedVersion' => $l->t('Use suggested version'),
	'incompleteDraft' => $l->t('Incomplete'),
	'incompleteDraftHelp' => $l->t('This draft is missing required calculation modules. Edit it and add a base formula before activating.'),
	'activateIncompleteHint' => $l->t('Complete the calculation modules before activating this draft.'),
];
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

        <div class="azc-page-stack">
        <div id="admin-tariff-rules-feedback" class="azc-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>

        <div class="admin-tariff-rules">
            <?php
            $calloutVariant = 'info';
            $calloutRole = 'note';
            $calloutBanner = false;
            $calloutExtraClass = 'admin-tariff-rules__intro';
            $calloutText = $l->t('Tariff rule sets drive vacation entitlement when employees use “tariff rule” mode. Changes are audited and active sets cannot be edited.');
            $calloutHint = $tariffL10n['workflowHelp'];
            include __DIR__ . '/common/alert-callout.php';
            ?>

            <section class="azc-card admin-tariff-rules__list-card" aria-labelledby="admin-tariff-rules-list-heading">
                <header class="azc-card__header">
                    <div class="azc-card__header-text">
                        <h2 id="admin-tariff-rules-list-heading" class="azc-card__title">
                            <?php p($l->t('Tariff rule sets')); ?>
                        </h2>
                    </div>
                    <div class="azc-card__header-actions admin-tariff-rules__toolbar">
                        <button type="button"
                            id="tariff-rules-create"
                            class="azc-btn azc-btn--primary"
                            aria-label="<?php p($l->t('Create a new tariff rule set')); ?>">
                            <?php p($l->t('New tariff rule set')); ?>
                        </button>
                        <button type="button"
                            id="tariff-rules-refresh"
                            class="azc-btn azc-btn--secondary"
                            aria-label="<?php p($l->t('Refresh the tariff rule sets list')); ?>">
                            <?php p($l->t('Refresh')); ?>
                        </button>
                    </div>
                </header>
                <div class="azc-card__body">
                    <div class="table-container admin-tariff-rules__table-wrap" tabindex="0" role="region" aria-labelledby="admin-tariff-rules-list-heading">
                        <table class="table table--hover azc-table--responsive grid-table admin-tariff-rules__table" id="tariff-rules-table">
                            <caption class="sr-only"><?php p($l->t('Tariff rule sets')); ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php p($l->t('Tariff code')); ?></th>
                                    <th scope="col"><?php p($l->t('Version')); ?></th>
                                    <th scope="col"><?php p($l->t('Jurisdiction')); ?></th>
                                    <th scope="col"><?php p($l->t('Status')); ?></th>
                                    <th scope="col"><?php p($l->t('Valid from')); ?></th>
                                    <th scope="col"><?php p($l->t('Valid to')); ?></th>
                                    <th scope="col"><?php p($l->t('Modules')); ?></th>
                                    <th scope="col"><span class="sr-only"><?php p($l->t('Actions')); ?></span></th>
                                </tr>
                            </thead>
                            <tbody id="tariff-rules-tbody">
                                <tr>
                                    <td colspan="8" class="admin-tariff-rules__empty"><?php p($l->t('Loading…')); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.tariffRulesL10n = <?php echo json_encode($tariffL10n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>

</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
