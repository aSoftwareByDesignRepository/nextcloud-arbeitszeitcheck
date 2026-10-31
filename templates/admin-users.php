<?php

declare(strict_types=1);

/**
 * Admin users template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


use OCA\ArbeitszeitCheck\Service\AdminEmployeeDirectoryService;
use OCA\ArbeitszeitCheck\Service\IconCatalog;
use OCA\ArbeitszeitCheck\Util\TemplateL10n;

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$users = $_['users'] ?? [];
$total = (int)($_['total'] ?? 0);
$accessFilter = (string)($_['accessFilter'] ?? AdminEmployeeDirectoryService::FILTER_ALL);
$defaultFilter = (string)($_['defaultFilter'] ?? $accessFilter);
$hiddenCount = (int)($_['hiddenCount'] ?? 0);
$isAccessRestricted = (bool)($_['isAccessRestricted'] ?? false);
$listTruncated = (bool)($_['truncated'] ?? false);
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$accessSettingsUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.admin.settingsSection', ['section' => \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog::SECTION_ACCESS]);
$exportBaseUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.admin.exportUsers', ['format' => 'csv']);
$workingTimeModels = is_array($_['workingTimeModels'] ?? null) ? $_['workingTimeModels'] : [];
$searchValue = (string)($_['search'] ?? '');
$modelFilter = (string)($_['modelFilter'] ?? '');
$statusFilter = (string)($_['statusFilter'] ?? '');
$filterError = (string)($_['filterError'] ?? '');
$exportQuery = ['filter' => $accessFilter];
if ($modelFilter !== '') {
	$exportQuery['model'] = $modelFilter;
}
if ($statusFilter !== '') {
	$exportQuery['status'] = $statusFilter;
}
if ($searchValue !== '') {
	$exportQuery['search'] = $searchValue;
}
$exportHref = $exportBaseUrl . (str_contains($exportBaseUrl, '?') ? '&' : '?') . http_build_query($exportQuery);
$azcListFiltered = $searchValue !== ''
	|| $modelFilter !== ''
	|| $statusFilter !== ''
	|| $accessFilter !== $defaultFilter
	|| $filterError !== '';
ob_start();
?>
            <button id="btn-filter"
                    class="azc-btn azc-btn--secondary"
                    type="button"
                    aria-expanded="<?php p($azcListFiltered ? 'true' : 'false'); ?>"
                    aria-controls="filter-section"
                    aria-label="<?php p($l->t('Filter employees')); ?>">
                <?php p($l->t('Filter')); ?>
            </button>
<?php
$captured = ob_get_clean();
$compact = preg_replace('/>\s+</', '><', (string)$captured);
$pageHeaderActions = is_string($compact) ? $compact : (string)$captured;
?>

<?php include __DIR__ . '/common/page-start.php'; ?>


        <div class="azc-page-stack">

        <section id="filter-section" class="azc-card azc-filter-panel admin-users-filter-panel<?php if ($azcListFiltered): ?> is-open<?php endif; ?>"<?php if (!$azcListFiltered): ?> style="display: none;"<?php endif; ?> aria-labelledby="employee-list-filter-title">
            <header class="azc-filter-panel__head">
                <h2 id="employee-list-filter-title"><?php p($l->t('Filter')); ?></h2>
            </header>
            <div class="azc-filter-panel__body">
                <form id="employee-list-filter-form" class="azc-filter-panel__form admin-users-filter-form" method="get" role="search" aria-labelledby="employee-list-filter-title" novalidate>
                    <div class="azc-filter-grid admin-users-filter-grid" role="group" aria-label="<?php p($l->t('Filter options')); ?>">
                        <div class="azc-filter-field admin-users-toolbar__search">
                            <label for="user-search" class="azc-filter-field__label"><?php p($l->t('Name')); ?></label>
                            <div class="azc-filter-field__control admin-users-search-control">
                                <input type="search"
                                    id="user-search"
                                    class="form-input admin-users-search-input"
                                    autocomplete="off"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    enterkeyhint="search"
                                    name="search"
                                    value="<?php p($searchValue); ?>"
                                    placeholder="<?php p($l->t('Type to search')); ?>"
                                    aria-describedby="user-search-help">
                                <p id="user-search-help" class="azc-sr-only">
                                    <?php p($l->t('Type at least 2 characters. Leave empty to browse page by page.')); ?>
                                </p>
                                <p id="user-search-error" class="azc-field-error" hidden></p>
                            </div>
                        </div>
                        <div class="azc-filter-field">
                            <label for="employee-list-access-filter" class="azc-filter-field__label"><?php p($l->t('Who to show')); ?></label>
                            <div class="azc-filter-field__control">
                                <select id="employee-list-access-filter" class="form-input form-select" name="filter">
                                    <option value="<?php p(AdminEmployeeDirectoryService::FILTER_APP_ACCESS); ?>"<?php if ($accessFilter === AdminEmployeeDirectoryService::FILTER_APP_ACCESS) { ?> selected<?php } ?>>
                                        <?php p($l->t('Can open ArbeitszeitCheck')); ?>
                                    </option>
                                    <option value="<?php p(AdminEmployeeDirectoryService::FILTER_ALL); ?>"<?php if ($accessFilter === AdminEmployeeDirectoryService::FILTER_ALL) { ?> selected<?php } ?>>
                                        <?php p($l->t('All Nextcloud accounts')); ?>
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="azc-filter-field">
                            <label for="employee-list-model-filter" class="azc-filter-field__label"><?php p($l->t('Working Time Model')); ?></label>
                            <div class="azc-filter-field__control">
                                <select id="employee-list-model-filter" class="form-input form-select" name="model">
                                    <option value=""><?php p($l->t('All working time models')); ?></option>
                                    <option value="<?php p(AdminEmployeeDirectoryService::MODEL_NONE); ?>"<?php if ($modelFilter === AdminEmployeeDirectoryService::MODEL_NONE) { ?> selected<?php } ?>><?php p($l->t('Not assigned')); ?></option>
                                    <option value="<?php p(AdminEmployeeDirectoryService::MODEL_PERSONAL); ?>"<?php if ($modelFilter === AdminEmployeeDirectoryService::MODEL_PERSONAL) { ?> selected<?php } ?>><?php p($l->t('Personal schedule')); ?></option>
                                    <?php foreach ($workingTimeModels as $modelOption): ?>
                                        <?php
                                        $modelId = (int)($modelOption['id'] ?? 0);
                                        if ($modelId < 1) {
                                        	continue;
                                        }
                                        ?>
                                        <option value="<?php p((string)$modelId); ?>"<?php if ($modelFilter === (string)$modelId) { ?> selected<?php } ?>><?php p((string)($modelOption['name'] ?? '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="azc-filter-field">
                            <label for="employee-list-status-filter" class="azc-filter-field__label"><?php p($l->t('Status')); ?></label>
                            <div class="azc-filter-field__control">
                                <select id="employee-list-status-filter" class="form-input form-select" name="status">
                                    <option value=""><?php p($l->t('All statuses')); ?></option>
                                    <option value="<?php p(AdminEmployeeDirectoryService::STATUS_ENABLED); ?>"<?php if ($statusFilter === AdminEmployeeDirectoryService::STATUS_ENABLED) { ?> selected<?php } ?>><?php p($l->t('Enabled')); ?></option>
                                    <option value="<?php p(AdminEmployeeDirectoryService::STATUS_DISABLED); ?>"<?php if ($statusFilter === AdminEmployeeDirectoryService::STATUS_DISABLED) { ?> selected<?php } ?>><?php p($l->t('Disabled')); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="azc-filter-field azc-filter-field--actions">
                            <span class="azc-filter-field__label" aria-hidden="true">&nbsp;</span>
                            <div class="azc-filter-field__control azc-filter-field__control--actions admin-users-filter-actions">
                                <button type="submit" id="employee-list-filter-apply" class="azc-btn azc-btn--primary">
                                    <?php p($l->t('Apply')); ?>
                                </button>
                                <button type="button"
                                    id="employee-list-filter-clear"
                                    class="azc-btn azc-btn--secondary admin-users-filter-reset"
                                    title="<?php p($l->t('Clear')); ?>"
                                    aria-label="<?php p($l->t('Clear')); ?>">
                                    <?php print_unescaped(IconCatalog::render('rotate', 'azc-btn__icon')); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
                <?php if ($filterError !== ''): ?>
                    <p class="azc-field-error" role="alert"><?php p($filterError); ?></p>
                <?php endif; ?>
            </div>
        </section>

        <div id="employee-list-hidden-banner"
            class="azc-inline-hint admin-users-hidden-banner"
            role="status"
            aria-live="polite"
            <?php if ($hiddenCount <= 0 || $accessFilter !== AdminEmployeeDirectoryService::FILTER_APP_ACCESS) {
            	p('hidden');
            } ?>>
            <p class="admin-users-hidden-banner__text">
                <span class="admin-users-hidden-banner__prefix"><?php p($l->n('1 Nextcloud account without app access is hidden.', '%n Nextcloud accounts without app access are hidden.', $hiddenCount)); ?></span>
                <button type="button" id="employee-list-show-all" class="azc-btn azc-btn--primary azc-btn--sm">
                    <?php p($l->t('Show all accounts')); ?>
                </button>
            </p>
        </div>

        <div id="employee-list-truncated-banner"
            class="azc-inline-hint admin-users-truncated-banner"
            role="status"
            aria-live="polite"
            <?php if (!$listTruncated) {
            	p('hidden');
            } ?>>
            <p><?php p($l->t('The list was shortened — more than 10,000 accounts exist. Refine your search to find a specific person.')); ?></p>
        </div>

        <section class="azc-card admin-users-card" id="admin-users-list-card" aria-labelledby="admin-users-list-heading">
            <header class="azc-card__header">
                <div class="azc-card__header-text">
                    <h2 id="admin-users-list-heading" class="azc-card__title"><?php p($l->t('Employee list')); ?></h2>
                    <p class="azc-card__lead"><?php p($l->t('Open a person to change their work schedule, vacation, or overtime.')); ?></p>
                </div>
                <div class="azc-card__header-actions admin-users-header-actions">
                    <a href="<?php p($exportHref); ?>"
                        id="export-users-csv"
                        class="azc-btn azc-btn--secondary"
                        data-export-base="<?php p($exportBaseUrl); ?>"
                        aria-label="<?php p($l->t('Export employee list as CSV')); ?>">
                        <?php p($l->t('Export CSV')); ?>
                    </a>
                </div>
            </header>
            <div class="azc-card__body">
                <p id="users-list-status" class="sr-only" aria-live="polite" aria-atomic="true"></p>
                <div class="table-container" tabindex="0" role="region" aria-label="<?php p($l->t('Employee list')); ?>" id="users-table-region">
                    <table class="table table--hover azc-table--responsive" id="users-table" role="table" aria-label="<?php p($l->t('Employee list')); ?>">
                        <thead>
                            <tr>
                                <th scope="col" class="admin-users-select-col">
                                    <label class="azc-sr-only" for="users-select-all"><?php p($l->t('Select all on this page')); ?></label>
                                    <input type="checkbox" id="users-select-all" aria-label="<?php p($l->t('Select all on this page')); ?>">
                                </th>
                                <th scope="col"><?php p($l->t('Name')); ?></th>
                                <th scope="col"><?php p($l->t('Email')); ?></th>
                                <th scope="col"><?php p($l->t('Working Time Model')); ?></th>
                                <th scope="col"><?php p($l->t('Vacation days')); ?></th>
                                <th scope="col"><?php p($l->t('Valid from / to')); ?></th>
                                <th scope="col"><?php p($l->t('Overtime Stichtag')); ?></th>
                                <th scope="col"><?php p($l->t('Status')); ?></th>
                                <th scope="col" class="azc-table-actions-col"><?php p($l->t('Actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="users-tbody">
                            <?php if (empty($users)): ?>
                                <tr id="users-empty-row">
                                    <td colspan="9" class="text-center admin-users-empty-cell">
                                        <?php if ($isAccessRestricted && $accessFilter === AdminEmployeeDirectoryService::FILTER_APP_ACCESS): ?>
                                            <p class="admin-users-empty-message"><?php p($l->t('No one with app access yet. Add people under Access control, or show all Nextcloud accounts.')); ?></p>
                                            <div class="admin-users-empty-actions">
                                                <a class="azc-btn azc-btn--primary" href="<?php p($accessSettingsUrl); ?>">
                                                    <?php p($l->t('Open access control')); ?>
                                                </a>
                                                <button type="button" class="azc-btn azc-btn--secondary" id="employee-list-empty-show-all" data-action="show-all-accounts">
                                                    <?php p($l->t('Show all accounts')); ?>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <p class="admin-users-empty-message"><?php p($l->t('No users found')); ?></p>
                                            <?php if ($searchValue !== '' || $modelFilter !== '' || $statusFilter !== ''): ?>
                                            <div class="admin-users-empty-actions">
                                                <button type="button" class="azc-btn azc-btn--primary" data-action="clear-employee-search">
                                                    <?php p($l->t('Clear search')); ?>
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $formatDate = function ($iso) use ($l) {
                                    if (empty($iso)) {
                                        return '-';
                                    }
                                    $d = \DateTime::createFromFormat('Y-m-d', $iso);
                                    return $d ? $d->format('d.m.Y') : $iso;
                                };
                                ?>
                                <?php foreach (($users ?? []) as $user): ?>
                                    <?php
                                    $vacation = $user['vacationDaysPerYear'] ?? null;
                                    $start = $user['workingTimeModelStartDate'] ?? null;
                                    $end = $user['workingTimeModelEndDate'] ?? null;
                                    $validity = $start ? ($formatDate($start) . ($end ? ' – ' . $formatDate($end) : ' – ' . $l->t('ongoing'))) : '-';
                                    $stichtag = $user['overtimeTrackingFrom'] ?? null;
                                    ?>
                                    <tr data-user-id="<?php p($user['userId']); ?>">
                                        <td data-label="<?php p($l->t('Select')); ?>" class="admin-users-select-col">
                                            <input type="checkbox" class="admin-users-row-select" value="<?php p($user['userId']); ?>"
                                                aria-label="<?php p($l->t('Select %s', [$user['displayName'] ?? $user['userId']])); ?>">
                                        </td>
                                        <td data-label="<?php p($l->t('Name')); ?>"><?php p($user['displayName']); ?></td>
                                        <td data-label="<?php p($l->t('Email')); ?>"><?php p($user['email'] ?? '-'); ?></td>
                                        <td data-label="<?php p($l->t('Working Time Model')); ?>">
                                            <?php if ($user['workingTimeModel']): ?>
                                                <?php p($user['workingTimeModel']['name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted"><?php p($l->t('Not assigned')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="<?php p($l->t('Vacation days')); ?>"><?php p($vacation !== null ? (string)$vacation : '-'); ?></td>
                                        <td data-label="<?php p($l->t('Valid from / to')); ?>"><?php p($validity); ?></td>
                                        <td data-label="<?php p($l->t('Overtime Stichtag')); ?>">
                                            <?php if ($stichtag): ?>
                                                <span class="badge badge--info"><?php p($formatDate($stichtag)); ?></span>
                                            <?php else: ?>
                                                <span class="badge badge--warning" title="<?php p($l->t('Year-to-date balance uses 1 January until configured')); ?>"><?php p($l->t('Not set')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="<?php p($l->t('Status')); ?>">
                                            <?php if ($user['enabled']): ?>
                                                <span class="badge badge--success"><?php p($l->t('Enabled')); ?></span>
                                            <?php else: ?>
                                                <span class="badge badge--error"><?php p($l->t('Disabled')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions-cell" data-label="<?php p($l->t('Actions')); ?>">
                                            <div class="user-actions azc-table-actions" role="group" aria-label="<?php p($l->t('Actions for %s', [$user['displayName']])); ?>">
                                                <a class="azc-btn azc-btn--ghost azc-icon-btn"
                                                        href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.userDetail', ['userId' => $user['userId']])); ?>#assignment-history"
                                                        data-action="history-user"
                                                        data-user-id="<?php p($user['userId']); ?>"
                                                        aria-label="<?php p($l->t('View assignment history for %s', [$user['displayName']])); ?>"
                                                        title="<?php p($l->t('View model assignment history')); ?>">
                                                    <?php print_unescaped(IconCatalog::render('history')); ?>
                                                </a>
                                                <a class="azc-btn azc-btn--secondary azc-icon-btn"
                                                        href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.userDetail', ['userId' => $user['userId']])); ?>"
                                                        data-action="edit-user"
                                                        data-user-id="<?php p($user['userId']); ?>"
                                                        aria-label="<?php p($l->t('Edit this employee\'s work schedule')); ?>"
                                                        title="<?php p($l->t('Click to change this employee\'s work schedule or other settings')); ?>">
                                                    <?php print_unescaped(IconCatalog::render('edit')); ?>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <nav class="admin-users-pager" id="users-pager" aria-label="<?php p($l->t('Employee list pages')); ?>" hidden>
                    <button type="button" id="users-page-prev" class="azc-btn azc-btn--secondary" disabled>
                        <?php p($l->t('Previous page')); ?>
                    </button>
                    <button type="button" id="users-page-next" class="azc-btn azc-btn--secondary" disabled>
                        <?php p($l->t('Next page')); ?>
                    </button>
                </nav>
                <div class="azc-table-meta pagination-info admin-users-meta" id="users-pagination" aria-live="polite" aria-atomic="true"<?php if ($total <= 0) { ?> hidden<?php } ?>>
                    <p id="users-pagination-text"><?php
                        $shown = count($users);
                        $from = $shown > 0 ? 1 : 0;
                        $to = $shown;
                        $paginationText = str_replace(
                            ['{from}', '{to}', '{total}'],
                            [(string) $from, (string) $to, (string) $total],
                            $l->t('Showing employees {from}–{to} of {total}')
                        );
                        if ($isAccessRestricted && $accessFilter === AdminEmployeeDirectoryService::FILTER_APP_ACCESS) {
                        	$paginationText .= ' ' . $l->t('(with app access only)');
                        } elseif ($isAccessRestricted && $accessFilter === AdminEmployeeDirectoryService::FILTER_ALL) {
                        	$paginationText .= ' ' . $l->t('(all Nextcloud accounts)');
                        }
                        p($paginationText);
                    ?></p>
                </div>
                <p id="export-status" class="sr-only" aria-live="polite"></p>
            </div><!-- /.azc-card__body -->
        </section><!-- /.azc-card -->

		<div id="admin-users-bulk-bar" class="admin-users-bulk-bar" hidden role="region" aria-label="<?php p($l->t('Bulk actions for selected employees')); ?>">
			<p class="admin-users-bulk-bar__count" id="admin-users-bulk-count" aria-live="polite"></p>
			<button type="button" id="admin-users-bulk-apply" class="azc-btn azc-btn--primary">
				<?php p($l->t('Apply to selected…')); ?>
			</button>
			<button type="button" id="admin-users-bulk-clear" class="azc-btn azc-btn--secondary">
				<?php p($l->t('Clear selection')); ?>
			</button>
			<div id="admin-users-bulk-result" class="azc-callout azc-callout--info" role="status" aria-live="polite" hidden></div>
		</div>
<?php $urlGenerator = $_['urlGenerator'] ?? $urlGenerator ?? null; ?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
<?php include __DIR__ . '/partials/admin-user-edit-l10n.php'; ?>
    window.ArbeitszeitCheck.adminUsersConfig = <?php echo json_encode([
        'pageSize' => 50,
        'minSearchLength' => 2,
        'initialOffset' => 0,
        'initialTotal' => (int) $total,
        'initialShown' => count($users),
        'accessFilter' => $accessFilter,
        'modelFilter' => $modelFilter,
        'statusFilter' => $statusFilter,
        'search' => $searchValue,
        'defaultFilter' => $defaultFilter,
        'hiddenCount' => $hiddenCount,
        'isAccessRestricted' => $isAccessRestricted,
        'truncated' => $listTruncated,
        'accessSettingsUrl' => $accessSettingsUrl,
        'organizationTimeCapture' => $_['organizationTimeCapture'] ?? ['clockStampingEnabled' => true, 'manualTimeEntryEnabled' => true],
        'userDetailUrlTemplate' => \OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for(
            $urlGenerator, 'arbeitszeitcheck.admin.userDetail', 'userId', '__USER_ID__'
        ),
        'adminTeamsUrl' => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.teams'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
