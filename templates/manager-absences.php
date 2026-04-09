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
				<h1><?php p($l->t('Employee absences')); ?></h1>
				<p class="section__desc">
					<?php p($l->t('View your employees\' absences directly in the app. Start by selecting a date range and optionally one person.')); ?>
				</p>
			</header>

			<section class="section manager-time-entries-page__filters" aria-labelledby="employee-absences-filters-title">
				<h2 id="employee-absences-filters-title"><?php p($l->t('Filter')); ?></h2>
				<form id="employee-absences-filter-form" class="form-grid form-grid--3" novalidate>
					<div class="form-group">
						<label for="employee-absences-employee-filter"><?php p($l->t('Employee')); ?></label>
						<select id="employee-absences-employee-filter" name="employee_id">
							<option value=""><?php p($l->t('All in my scope')); ?></option>
						</select>
					</div>
					<div class="form-group">
						<label for="employee-absences-start-date-filter"><?php p($l->t('Start date')); ?></label>
						<input id="employee-absences-start-date-filter" name="start_date" type="text" class="datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly required />
					</div>
					<div class="form-group">
						<label for="employee-absences-end-date-filter"><?php p($l->t('End date')); ?></label>
						<input id="employee-absences-end-date-filter" name="end_date" type="text" class="datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly required />
					</div>
					<div class="form-group">
						<label for="employee-absences-type-filter"><?php p($l->t('Type')); ?></label>
						<select id="employee-absences-type-filter" name="type">
							<option value=""><?php p($l->t('All types')); ?></option>
							<option value="vacation"><?php p($l->t('Vacation')); ?></option>
							<option value="sick_leave"><?php p($l->t('Sick leave')); ?></option>
							<option value="personal_leave"><?php p($l->t('Personal leave')); ?></option>
							<option value="parental_leave"><?php p($l->t('Parental leave')); ?></option>
							<option value="special_leave"><?php p($l->t('Special leave')); ?></option>
							<option value="unpaid_leave"><?php p($l->t('Unpaid leave')); ?></option>
							<option value="home_office"><?php p($l->t('Home office')); ?></option>
							<option value="business_trip"><?php p($l->t('Business trip')); ?></option>
						</select>
					</div>
					<div class="form-group">
						<label for="employee-absences-status-filter"><?php p($l->t('Status')); ?></label>
						<select id="employee-absences-status-filter" name="status">
							<option value=""><?php p($l->t('Alle Status')); ?></option>
							<option value="pending"><?php p($l->t('Pending')); ?></option>
							<option value="substitute_pending"><?php p($l->t('Substitute pending')); ?></option>
							<option value="substitute_declined"><?php p($l->t('Substitute declined')); ?></option>
							<option value="approved"><?php p($l->t('Approved')); ?></option>
							<option value="rejected"><?php p($l->t('Rejected')); ?></option>
							<option value="cancelled"><?php p($l->t('Cancelled')); ?></option>
						</select>
					</div>
					<div class="form-group manager-time-entries-page__filter-actions">
						<button type="submit" class="btn btn--primary"><?php p($l->t('Show')); ?></button>
						<button type="button" id="employee-absences-clear" class="btn btn--secondary"><?php p($l->t('Reset')); ?></button>
					</div>
				</form>
				<p id="employee-absences-filter-help" class="form-help">
					<?php p($l->t('For security and performance, results are only loaded after both start and end date are set.')); ?>
				</p>
			</section>

			<section class="section manager-time-entries-page__results" aria-labelledby="employee-absences-results-title">
				<div class="section-header manager-time-entries-page__results-header">
					<h2 id="employee-absences-results-title"><?php p($l->t('Absences')); ?></h2>
					<p id="employee-absences-count" class="manager-time-entries-page__count" role="status" aria-live="polite"></p>
				</div>

				<div id="employee-absences-empty" class="empty-state">
					<h3 class="empty-state__title"><?php p($l->t('Select filters first')); ?></h3>
					<p class="empty-state__description"><?php p($l->t('Choose a date range to load absences.')); ?></p>
				</div>

				<div id="employee-absences-table-wrap" class="table-container visually-hidden" aria-live="polite">
					<table class="table table--hover" aria-label="<?php p($l->t('Employee absences')); ?>">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('Name')); ?></th>
								<th scope="col"><?php p($l->t('Type')); ?></th>
								<th scope="col"><?php p($l->t('Start date')); ?></th>
								<th scope="col"><?php p($l->t('End date')); ?></th>
								<th scope="col"><?php p($l->t('Days')); ?></th>
								<th scope="col"><?php p($l->t('Status')); ?></th>
								<th scope="col"><?php p($l->t('Reason')); ?></th>
							</tr>
						</thead>
						<tbody id="employee-absences-body"></tbody>
					</table>
				</div>

				<div class="pagination manager-time-entries-page__pagination">
					<button type="button" id="employee-absences-prev" class="btn btn--secondary" disabled><?php p($l->t('Previous')); ?></button>
					<span id="employee-absences-page-indicator" class="pagination-info"></span>
					<button type="button" id="employee-absences-next" class="btn btn--secondary" disabled><?php p($l->t('Next')); ?></button>
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
		"Could not load employee absences.": <?php echo json_encode($l->t('Could not load employee absences.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Page {page} of {pages}": <?php echo json_encode($l->t('Page {page} of {pages}'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"{count} entries": <?php echo json_encode($l->t('{count} entries'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"No reason": <?php echo json_encode($l->t('No reason'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"Choose a date range to load absences.": <?php echo json_encode($l->t('Choose a date range to load absences.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		"All in my scope": <?php echo json_encode($l->t('All in my scope'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
	});
</script>
