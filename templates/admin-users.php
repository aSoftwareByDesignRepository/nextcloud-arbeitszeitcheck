<?php

declare(strict_types=1);

/**
 * Admin users template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


use OCA\ArbeitszeitCheck\Service\AdminEmployeeDirectoryService;
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
/** @var \OCP\IURLGenerator|null $urlGenerator */
$urlGenerator = $_['urlGenerator'] ?? null;
$accessSettingsUrl = $urlGenerator
	? $urlGenerator->linkToRoute('arbeitszeitcheck.admin.settingsAccess')
	: '/apps/arbeitszeitcheck/admin/settings/access';
$exportBaseUrl = $urlGenerator
	? $urlGenerator->linkToRoute('arbeitszeitcheck.admin.exportUsers', ['format' => 'csv'])
	: '/apps/arbeitszeitcheck/api/admin/users/export?format=csv';
$exportHref = $exportBaseUrl . (str_contains($exportBaseUrl, '?') ? '&' : '?') . 'filter=' . rawurlencode($accessFilter);
$filterIntro = $isAccessRestricted
	? $l->t('Search by name. Choose who appears: people who can open the app, or every Nextcloud account.')
	: $l->t('Search by name or login. Everyone with a Nextcloud account is listed.');
?>

<?php include __DIR__ . '/common/page-start.php'; ?>


        <div class="azc-page-stack">

        <section class="azc-card azc-filter-panel admin-users-filter-panel" aria-labelledby="employee-list-filter-title">
            <header class="azc-filter-panel__head">
                <h2 id="employee-list-filter-title"><?php p($l->t('Find employees')); ?></h2>
                <p class="azc-filter-panel__intro"><?php p($filterIntro); ?></p>
            </header>
            <div class="azc-filter-panel__body">
                <form class="azc-filter-panel__form admin-users-filter-form" role="search" aria-labelledby="employee-list-filter-title" novalidate>
                    <div class="azc-filter-grid azc-filter-grid--search admin-users-filter-grid">
                        <div class="azc-filter-field admin-users-toolbar__search">
                            <label for="user-search" class="azc-filter-field__label form-label visually-hidden"><?php p($l->t('Find an employee')); ?></label>
                            <div class="azc-filter-field__control admin-users-search-control">
                                <input type="search"
                                    id="user-search"
                                    class="form-input admin-users-search-input"
                                    autocomplete="off"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    enterkeyhint="search"
                                    placeholder="<?php p($l->t('Search by name or login…')); ?>"
                                    aria-describedby="user-search-help users-pagination">
                            </div>
                            <p id="user-search-help" class="form-help">
                                <?php p($l->t('Type at least 2 characters. Leave empty to browse page by page.')); ?>
                            </p>
                        </div>
                        <fieldset class="azc-filter-field admin-users-access-filter" aria-labelledby="employee-access-filter-legend">
                            <legend id="employee-access-filter-legend" class="azc-filter-field__label form-label"><?php p($l->t('Who to show')); ?></legend>
                            <div class="admin-users-access-filter__options" role="presentation">
                                <label class="admin-users-access-filter__option">
                                    <input type="radio"
                                        name="employee-list-access-filter"
                                        id="employee-list-filter-app-access"
                                        value="<?php p(AdminEmployeeDirectoryService::FILTER_APP_ACCESS); ?>"
                                        <?php if ($accessFilter === AdminEmployeeDirectoryService::FILTER_APP_ACCESS) {
                                        	p('checked');
                                        } ?>>
                                    <span><?php p($l->t('Can open ArbeitszeitCheck')); ?></span>
                                </label>
                                <label class="admin-users-access-filter__option">
                                    <input type="radio"
                                        name="employee-list-access-filter"
                                        id="employee-list-filter-all"
                                        value="<?php p(AdminEmployeeDirectoryService::FILTER_ALL); ?>"
                                        <?php if ($accessFilter === AdminEmployeeDirectoryService::FILTER_ALL) {
                                        	p('checked');
                                        } ?>>
                                    <span><?php p($l->t('All Nextcloud accounts')); ?></span>
                                </label>
                            </div>
                        </fieldset>
                    </div>
                </form>
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
                <span class="admin-users-hidden-banner__prefix"><?php p(str_replace('{count}', (string)$hiddenCount, $l->t('{count} Nextcloud accounts without app access are hidden.'))); ?></span>
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
                    <button type="button" id="refresh-users" class="azc-btn azc-btn--secondary" title="<?php p($l->t('Clear search and reload the list')); ?>">
                        <?php p($l->t('Reset')); ?>
                    </button>
                </div>
            </header>
            <div class="azc-card__body">
                <p id="users-list-status" class="sr-only" aria-live="polite" aria-atomic="true"></p>
                <div class="table-container" role="region" aria-label="<?php p($l->t('Employee list')); ?>" id="users-table-region">
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
                                                <a class="azc-btn azc-btn--sm azc-btn--ghost"
                                                        href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.userDetail', ['userId' => $user['userId']])); ?>#assignment-history"
                                                        data-action="history-user"
                                                        data-user-id="<?php p($user['userId']); ?>"
                                                        aria-label="<?php p($l->t('View assignment history for %s', [$user['displayName']])); ?>"
                                                        title="<?php p($l->t('View model assignment history')); ?>">
                                                    <?php p($l->t('History')); ?>
                                                </a>
                                                <a class="azc-btn azc-btn--sm azc-btn--secondary"
                                                        href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.userDetail', ['userId' => $user['userId']])); ?>"
                                                        data-action="edit-user"
                                                        data-user-id="<?php p($user['userId']); ?>"
                                                        aria-label="<?php p($l->t('Edit this employee\'s work schedule')); ?>"
                                                        title="<?php p($l->t('Click to change this employee\'s work schedule or other settings')); ?>">
                                                    <?php p($l->t('Edit')); ?>
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
                <div class="azc-table-meta pagination-info admin-users-meta" id="users-pagination" aria-live="polite" aria-atomic="true">
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
        'defaultFilter' => $defaultFilter,
        'hiddenCount' => $hiddenCount,
        'isAccessRestricted' => $isAccessRestricted,
        'truncated' => $listTruncated,
        'accessSettingsUrl' => $accessSettingsUrl,
        'organizationTimeCapture' => $_['organizationTimeCapture'] ?? ['clockStampingEnabled' => true, 'manualTimeEntryEnabled' => true],
        'userDetailUrlTemplate' => ($urlGenerator
            ? $urlGenerator->linkToRoute('arbeitszeitcheck.admin.userDetail', ['userId' => '__USER_ID__'])
            : '/apps/arbeitszeitcheck/admin/users/__USER_ID__'),
        'adminTeamsUrl' => ($urlGenerator
            ? $urlGenerator->linkToRoute('arbeitszeitcheck.admin.teams')
            : '/apps/arbeitszeitcheck/admin/teams'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
