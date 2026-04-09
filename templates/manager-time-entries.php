<?php

declare(strict_types=1);

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content" class="manager-time-entries-page">
	<div id="app-content-wrapper">
		<div class="section manager-time-entries-page__content">
			<header class="section-header manager-time-entries-page__header">
				<h1><?php p($l->t('Employee time entries')); ?></h1>
				<p class="section__desc">
					<?php p($l->t('View your employees\' time entries directly in the app. Start by selecting a date range and optionally one person.')); ?>
				</p>
			</header>

			<section class="section manager-time-entries-page__filters" aria-labelledby="employee-time-entries-filters-title">
				<h2 id="employee-time-entries-filters-title"><?php p($l->t('Filter')); ?></h2>
				<form id="employee-time-entries-filter-form" class="form-grid form-grid--3" novalidate>
					<div class="form-group">
						<label for="employee-filter"><?php p($l->t('Employee')); ?></label>
						<select id="employee-filter" name="employee_id">
							<option value=""><?php p($l->t('All in my scope')); ?></option>
						</select>
					</div>
					<div class="form-group">
						<label for="start-date-filter"><?php p($l->t('Start date')); ?></label>
						<input id="start-date-filter" name="start_date" type="text" class="datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly required />
					</div>
					<div class="form-group">
						<label for="end-date-filter"><?php p($l->t('End date')); ?></label>
						<input id="end-date-filter" name="end_date" type="text" class="datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly required />
					</div>
					<div class="form-group">
						<label for="status-filter"><?php p($l->t('Status')); ?></label>
						<select id="status-filter" name="status">
							<option value=""><?php p($l->t('Alle Status')); ?></option>
							<option value="active"><?php p($l->t('Clocked In')); ?></option>
							<option value="break"><?php p($l->t('On Break')); ?></option>
							<option value="paused"><?php p($l->t('Paused')); ?></option>
							<option value="completed"><?php p($l->t('Completed')); ?></option>
							<option value="pending_approval"><?php p($l->t('Pending Approval')); ?></option>
							<option value="rejected"><?php p($l->t('Rejected')); ?></option>
						</select>
					</div>
					<div class="form-group manager-time-entries-page__filter-actions">
						<button type="submit" class="btn btn--primary"><?php p($l->t('Show')); ?></button>
						<button type="button" id="employee-time-entries-clear" class="btn btn--secondary"><?php p($l->t('Reset')); ?></button>
					</div>
				</form>
				<p id="employee-time-entries-filter-help" class="form-help">
					<?php p($l->t('For security and performance, results are only loaded after both start and end date are set.')); ?>
				</p>
			</section>

			<section class="section manager-time-entries-page__results" aria-labelledby="employee-time-entries-results-title">
				<div class="section-header manager-time-entries-page__results-header">
					<h2 id="employee-time-entries-results-title"><?php p($l->t('Time Entries')); ?></h2>
					<p id="employee-time-entries-count" class="manager-time-entries-page__count" role="status" aria-live="polite"></p>
				</div>

				<div id="employee-time-entries-empty" class="empty-state">
					<h3 class="empty-state__title"><?php p($l->t('Select filters first')); ?></h3>
					<p class="empty-state__description"><?php p($l->t('Choose a date range to load entries.')); ?></p>
				</div>

				<div id="employee-time-entries-table-wrap" class="table-container visually-hidden" aria-live="polite">
					<table class="table table--hover" aria-label="<?php p($l->t('Employee time entries')); ?>">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('Name')); ?></th>
								<th scope="col"><?php p($l->t('Date')); ?></th>
								<th scope="col"><?php p($l->t('Start')); ?></th>
								<th scope="col"><?php p($l->t('End')); ?></th>
								<th scope="col"><?php p($l->t('Working Hours')); ?></th>
								<th scope="col"><?php p($l->t('Break')); ?></th>
								<th scope="col"><?php p($l->t('Status')); ?></th>
								<th scope="col"><?php p($l->t('Description')); ?></th>
							</tr>
						</thead>
						<tbody id="employee-time-entries-body"></tbody>
					</table>
				</div>

				<div class="pagination manager-time-entries-page__pagination">
					<button type="button" id="employee-time-entries-prev" class="btn btn--secondary" disabled><?php p($l->t('Previous')); ?></button>
					<span id="employee-time-entries-page-indicator" class="pagination-info"></span>
					<button type="button" id="employee-time-entries-next" class="btn btn--secondary" disabled><?php p($l->t('Next')); ?></button>
				</div>
			</section>
		</div>
	</div>
</div>
</div>

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
	window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
	window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
	window.ArbeitszeitCheck.dateLocale = <?php echo json_encode($l->getLanguageCode(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	Object.assign(window.ArbeitszeitCheck.l10n, {
		"Loading...": <?php echo json_encode($l->t('Loading...'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"No entries found for the selected filters.": <?php echo json_encode($l->t('No entries found for the selected filters.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Please select start and end date.": <?php echo json_encode($l->t('Please select start and end date.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Could not load employee time entries.": <?php echo json_encode($l->t('Could not load employee time entries.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Page {page} of {pages}": <?php echo json_encode($l->t('Page {page} of {pages}'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"{count} entries": <?php echo json_encode($l->t('{count} entries'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"No description": <?php echo json_encode($l->t('No description'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Choose a date range to load entries.": <?php echo json_encode($l->t('Choose a date range to load entries.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"All in my scope": <?php echo json_encode($l->t('All in my scope'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
	});
</script>
