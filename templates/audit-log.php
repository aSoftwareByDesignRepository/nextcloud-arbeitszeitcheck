<?php

declare(strict_types=1);

/**
 * Audit log template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\ArbeitszeitCheck\Constants;

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$logs = $_['logs'] ?? [];
$total = (int)($_['total'] ?? 0);
$limit = (int)($_['limit'] ?? 50);
$offset = (int)($_['offset'] ?? 0);
$startDate = $_['startDate'] ?? '';
$endDate = $_['endDate'] ?? '';
$actionCategoryOptions = is_array($_['actionCategoryOptions'] ?? null) ? $_['actionCategoryOptions'] : [];
$entityTypeOptions = is_array($_['entityTypeOptions'] ?? null) ? $_['entityTypeOptions'] : [];
$maxDateRangeDays = (int)($_['maxDateRangeDays'] ?? Constants::MAX_EXPORT_DATE_RANGE_DAYS);
$shownCount = count($logs);
$rangeStart = min($total, $offset + 1);
$rangeEnd = min($total, $offset + $shownCount);
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack">
	<div class="audit-log-page">

		<section class="azc-card audit-log-page__filters" aria-labelledby="audit-filters-heading">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="audit-filters-heading" class="azc-card__title"><?php p($l->t('Search activity')); ?></h2>
					<p id="audit-log-filter-help" class="azc-card__lead">
						<?php p($l->t('Choose a date range and optional filters, then click Show. Export downloads everything that matches your filters.')); ?>
					</p>
				</div>
			</header>
			<div class="azc-card__body">
				<div class="audit-log-page__toolbar" role="search" aria-label="<?php p($l->t('Filter activity log')); ?>">
					<form
						id="audit-log-filter-form"
						class="audit-log-page__filter-form"
						novalidate
						aria-describedby="audit-log-filter-help audit-log-filter-footnote audit-log-filter-error"
					>
						<?php /* Dates first (required), then optional filters. IDs must stay stable for audit-log-viewer.js. */ ?>
						<div class="audit-log-page__filter-grid" role="group" aria-label="<?php p($l->t('Filter options')); ?>">
							<div class="audit-log-filter__field audit-log-filter__field--from">
								<label for="start-date" class="audit-log-filter__label"><?php p($l->t('From')); ?></label>
								<div class="audit-log-filter__control">
									<input
										type="text"
										id="start-date"
										name="start_date"
										class="form-input datepicker-input"
										placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
										pattern="\d{2}\.\d{2}\.\d{4}"
										maxlength="10"
										readonly
										required
										autocomplete="off"
										aria-required="true"
										value="<?php p($startDate); ?>"
										aria-describedby="audit-log-filter-footnote"
									/>
								</div>
							</div>

							<div class="audit-log-filter__field audit-log-filter__field--to">
								<label for="end-date" class="audit-log-filter__label"><?php p($l->t('To')); ?></label>
								<div class="audit-log-filter__control">
									<input
										type="text"
										id="end-date"
										name="end_date"
										class="form-input datepicker-input"
										placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
										pattern="\d{2}\.\d{2}\.\d{4}"
										maxlength="10"
										readonly
										required
										autocomplete="off"
										aria-required="true"
										value="<?php p($endDate); ?>"
										aria-describedby="audit-log-filter-footnote"
									/>
								</div>
							</div>

							<div class="audit-log-filter__field audit-log-filter__field--actions">
								<span class="audit-log-filter__label audit-log-filter__label--spacer" aria-hidden="true">&nbsp;</span>
								<div class="audit-log-filter__control audit-log-filter__actions" role="group" aria-label="<?php p($l->t('Actions')); ?>">
									<button type="submit" id="apply-filters" class="azc-btn azc-btn--primary">
										<?php p($l->t('Show')); ?>
									</button>
									<button type="button" id="reset-filters" class="azc-btn azc-btn--secondary">
										<?php p($l->t('Reset')); ?>
									</button>
									<button type="button" id="export-logs" class="azc-btn azc-btn--secondary">
										<?php p($l->t('Export CSV')); ?>
									</button>
								</div>
							</div>

							<div class="audit-log-filter__field audit-log-filter__field--user">
								<label for="audit-user-search" class="audit-log-filter__label"><?php p($l->t('User account')); ?></label>
								<div class="audit-log-filter__control">
									<input type="hidden" id="user-filter" name="user_id" value="">
									<div class="user-picker audit-log-filter__picker" id="audit-user-picker">
										<div class="user-picker__control">
											<input
												type="search"
												id="audit-user-search"
												class="form-input user-picker__search"
												placeholder="<?php p($l->t('Search by name or login…')); ?>"
												autocomplete="off"
												autocapitalize="none"
												spellcheck="false"
												role="combobox"
												aria-autocomplete="list"
												aria-expanded="false"
												aria-controls="audit-user-listbox"
												aria-describedby="audit-log-filter-footnote audit-user-status"
											/>
											<button type="button"
												class="user-picker__clear"
												id="audit-user-clear"
												hidden
												aria-label="<?php p($l->t('Clear user filter')); ?>">
												<span aria-hidden="true">&times;</span>
											</button>
										</div>
										<div id="audit-user-listbox"
											class="user-picker__list"
											role="listbox"
											hidden
											aria-label="<?php p($l->t('Matching accounts')); ?>"></div>
										<p id="audit-user-status" class="azc-sr-only" role="status" aria-live="polite" aria-atomic="true"></p>
									</div>
								</div>
							</div>

							<div class="audit-log-filter__field audit-log-filter__field--action">
								<label for="action-category-filter" class="audit-log-filter__label"><?php p($l->t('Action')); ?></label>
								<div class="audit-log-filter__control">
									<select id="action-category-filter" name="action_category" class="form-select">
										<?php foreach ($actionCategoryOptions as $value => $label): ?>
											<option value="<?php p((string)$value); ?>"><?php p($label); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="audit-log-filter__field audit-log-filter__field--entity">
								<label for="entity-type-filter" class="audit-log-filter__label"><?php p($l->t('What changed')); ?></label>
								<div class="audit-log-filter__control">
									<select id="entity-type-filter" name="entity_type" class="form-select">
										<?php foreach ($entityTypeOptions as $value => $label): ?>
											<option value="<?php p((string)$value); ?>"><?php p($label); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="audit-log-filter__field audit-log-filter__field--offline">
								<span class="audit-log-filter__label" id="offline-sync-filter-label"><?php p($l->t('Offline sync')); ?></span>
								<div class="audit-log-filter__control">
									<label class="audit-log-filter__checkbox">
										<input type="checkbox" id="offline-sync-filter" name="offlineSync" value="1"
											aria-describedby="offline-sync-filter-hint">
										<span><?php p($l->t('Only offline-synced punches')); ?></span>
									</label>
									<p id="offline-sync-filter-hint" class="form-help"><?php p($l->t('Show stamps that were recorded offline and synced later.')); ?></p>
								</div>
							</div>
						</div>

						<div id="audit-log-filter-error" class="azc-callout azc-callout--danger audit-log-page__toolbar-feedback" role="alert" hidden>
							<span class="azc-callout__text"></span>
						</div>
					</form>
					<p id="audit-log-filter-footnote" class="audit-log-page__toolbar-footnote" role="note">
						<?php p($l->t('Optional: search and pick a user account. Never type a raw user id. Maximum date range: %d days. Times use your personal timezone.', [$maxDateRangeDays])); ?>
					</p>
				</div>
			</div>
		</section>

		<section class="azc-card audit-log-page__results" aria-labelledby="audit-table-heading" aria-busy="false">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="audit-table-heading" class="azc-card__title"><?php p($l->t('Activity log')); ?></h2>
					<p class="azc-card__lead"><?php p($l->t('Each row is one recorded change. Newest entries appear first.')); ?></p>
				</div>
				<p id="audit-log-count" class="azc-badge azc-badge--neutral" role="status" aria-live="polite">
					<?php if ($total > 0): ?>
						<?php p($l->t('%1$d–%2$d of %3$d entries', [$rangeStart, $rangeEnd, $total])); ?>
					<?php else: ?>
						<?php p($l->t('0 entries')); ?>
					<?php endif; ?>
				</p>
			</header>
			<div class="azc-card__body">
				<div class="table-container audit-log-page__table-wrap" role="region" aria-label="<?php p($l->t('Activity log')); ?>">
					<table class="table table--hover azc-table--responsive audit-log-table" id="audit-log-table" aria-label="<?php p($l->t('Activity log')); ?>">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('Date and time')); ?></th>
								<th scope="col"><?php p($l->t('Employee')); ?></th>
								<th scope="col"><?php p($l->t('Action')); ?></th>
								<th scope="col"><?php p($l->t('What was changed')); ?></th>
								<th scope="col"><?php p($l->t('Who did it')); ?></th>
							</tr>
						</thead>
						<tbody id="audit-log-tbody">
							<?php if (empty($logs)): ?>
								<tr>
									<td colspan="5" class="text-center audit-log-empty">
										<div class="empty-state">
											<h3 class="empty-state__title"><?php p($l->t('No activities found')); ?></h3>
											<p class="empty-state__description">
												<?php p($l->t('No activities were logged for the selected period.')); ?>
											</p>
										</div>
									</td>
								</tr>
							<?php else: ?>
								<?php foreach ($logs as $log): ?>
								<tr>
									<td data-label="<?php p($l->t('Date and time')); ?>">
										<?php p($log['createdAt'] ?? '-'); ?>
										<?php if (!empty($log['isOfflineSync'])): ?>
											<span class="azc-badge azc-badge--info audit-log-offline-badge"><?php p($l->t('Offline sync')); ?></span>
											<?php if (!empty($log['clientOccurredAtIso'])): ?>
												<span class="audit-log-offline-occurred"><?php p($l->t('Occurred: %s', [$log['clientOccurredAtIso']])); ?></span>
											<?php endif; ?>
										<?php endif; ?>
									</td>
									<td data-label="<?php p($l->t('Employee')); ?>"><?php p($log['userDisplayName'] ?? $log['userId']); ?></td>
									<td data-label="<?php p($l->t('Action')); ?>"><?php p($log['action']); ?></td>
									<td data-label="<?php p($l->t('What was changed')); ?>"><?php p($log['entityType']); ?></td>
									<td data-label="<?php p($l->t('Who did it')); ?>"><?php p($log['performedByDisplayName'] ?? $log['performedBy'] ?? '-'); ?></td>
								</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<nav id="audit-log-pagination" class="audit-log-page__pagination" aria-label="<?php p($l->t('Activity log pages')); ?>" <?php if ($total <= $limit): ?>hidden<?php endif; ?>>
					<button type="button" id="audit-log-prev" class="azc-btn azc-btn--secondary" <?php if ($offset <= 0): ?>disabled aria-disabled="true"<?php endif; ?>>
						<?php p($l->t('Previous')); ?>
					</button>
					<p id="audit-log-pagination-text" class="audit-log-page__pagination-text" aria-live="polite">
						<?php p($l->t('Page %1$d of %2$d', [max(1, (int)floor($offset / max(1, $limit)) + 1), max(1, (int)ceil($total / max(1, $limit)))])); ?>
					</p>
					<button type="button" id="audit-log-next" class="azc-btn azc-btn--secondary" <?php if ($offset + $shownCount >= $total): ?>disabled aria-disabled="true"<?php endif; ?>>
						<?php p($l->t('Next')); ?>
					</button>
				</nav>
			</div>
		</section>

	</div>
</div>

<?php
// Placeholder strings (%d etc.) must go through TemplateL10n: a bare $l->t() without
// arguments crashes vsprintf on PHP 8, and the JS substitutes the placeholders itself.
$auditLogViewerL10n = \OCA\ArbeitszeitCheck\Util\TemplateL10n::mapFromMessageIds($l, [
	'Loading…',
	'Error loading audit logs',
	'Failed to load audit logs. Please try again.',
	'No audit log entries found',
	'Date and time',
	'Employee',
	'Action',
	'What was changed',
	'Who did it',
	'0 entries',
	'%1$d–%2$d of %3$d entries',
	'Page %1$d of %2$d',
	'Previous',
	'Next',
	'Start date must be before or equal to end date',
	'Please enter valid dates in dd.mm.yyyy format.',
	'Date range must not exceed %d days. Please narrow the range.',
	'User filter is too long.',
	'Offline sync',
	'Occurred: %s',
	'Synced: %s',
]);
?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.auditLogViewerL10n = <?php echo json_encode($auditLogViewerL10n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
window.ArbeitszeitCheck.auditLogViewerConfig = <?php echo json_encode([
	'limit' => $limit,
	'offset' => $offset,
	'total' => $total,
	'maxDateRangeDays' => $maxDateRangeDays,
	'defaultStartDate' => $startDate,
	'defaultEndDate' => $endDate,
	'exportUrl' => \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.admin.exportAuditLogs'),
	'adminUserSearchUrl' => \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.admin.getUsers'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>

<?php include __DIR__ . '/common/page-end.php'; ?>
