<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Constants;

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
$maxManagerListDateRangeDays = (int)($_['maxManagerListDateRangeDays'] ?? Constants::MAX_EXPORT_DATE_RANGE_DAYS);
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack">
	<div class="manager-scope-page manager-scope-page--time-entries">

		<section class="azc-card manager-scope-page__create" aria-labelledby="manager-create-time-entry-title">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="manager-create-time-entry-title" class="azc-card__title"><?php p($l->t('Record time for an employee')); ?></h2>
					<p class="azc-card__lead"><?php p($l->t('Create a completed manual entry on behalf of someone you manage. The employee is notified and the change is logged for audit.')); ?></p>
				</div>
				<div class="azc-card__header-actions">
					<button type="button" id="manager-open-create-time-entry" class="azc-btn azc-btn--primary">
						<?php p($l->t('Add time entry')); ?>
					</button>
				</div>
			</header>
			<?php if (!empty($_['projectCheckEnabled'])): ?>
				<p class="manager-scope-page__create-hint azc-callout azc-callout--neutral" role="note">
					<?php p($l->t('When ProjectCheck is enabled, you can optionally link billing hours on a project the employee is allowed to use.')); ?>
				</p>
			<?php endif; ?>
		</section>

		<section class="azc-card manager-scope-page__results" aria-labelledby="employee-time-entries-results-title" aria-busy="false">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="employee-time-entries-results-title" class="azc-card__title"><?php p($l->t('Time Entries')); ?></h2>
					<p class="azc-card__lead">
						<?php p($l->t('Choose who and which period to view, then click Show. The table below lists matching entries.')); ?>
					</p>
				</div>
				<p id="employee-time-entries-count" class="azc-badge azc-badge--neutral" role="status" aria-live="polite"></p>
			</header>
			<div class="azc-card__body">
				<div class="manager-scope-page__toolbar" role="search" aria-label="<?php p($l->t('Filter employee time entries')); ?>">
					<form
						id="employee-time-entries-filter-form"
						class="manager-scope-page__filter-form"
						novalidate
						aria-describedby="employee-time-entries-filter-error"
					>
						<div class="manager-scope-filter" role="group" aria-label="<?php p($l->t('Filter options')); ?>">
							<div class="azc-filter-field azc-filter-field--employee">
								<label for="employee-filter-search" class="azc-filter-field__label"><?php p($l->t('Employee')); ?></label>
								<div class="azc-filter-field__control">
									<?php
									$scopePickerId = 'employee-filter';
									$scopePickerName = 'employee_id';
									$scopePickerAllowAll = true;
									$scopePickerRequired = false;
									$scopePickerCompact = true;
									include __DIR__ . '/common/scoped-employee-picker-field.php';
									?>
								</div>
							</div>

							<div class="azc-filter-field azc-filter-field--status">
								<label for="status-filter" class="azc-filter-field__label"><?php p($l->t('Status')); ?></label>
								<div class="azc-filter-field__control">
									<select id="status-filter" name="status" class="form-select" aria-label="<?php p($l->t('Status')); ?>">
										<option value=""><?php p($l->t('All statuses')); ?></option>
										<option value="active"><?php p($l->t('Clocked In')); ?></option>
										<option value="break"><?php p($l->t('On Break')); ?></option>
										<option value="paused"><?php p($l->t('Paused')); ?></option>
										<option value="completed"><?php p($l->t('Completed')); ?></option>
										<option value="pending_approval"><?php p($l->t('Pending Approval')); ?></option>
										<option value="rejected"><?php p($l->t('Rejected')); ?></option>
									</select>
								</div>
							</div>

							<div
								class="azc-filter-field azc-filter-field--dates"
								role="group"
								aria-labelledby="employee-time-entries-date-range-label"
							>
								<span id="employee-time-entries-date-range-label" class="azc-filter-field__label"><?php p($l->t('Date range')); ?></span>
								<div class="azc-filter-field__control">
									<div class="azc-date-range">
										<div class="azc-date-range__part">
											<label for="start-date-filter" class="azc-date-range__sublabel visually-hidden"><?php p($l->t('From')); ?></label>
											<input
												id="start-date-filter"
												name="start_date"
												type="text"
												class="form-input datepicker-input"
												placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
												pattern="\d{2}\.\d{2}\.\d{4}"
												maxlength="10"
												required
												autocomplete="off"
												aria-required="true"
												aria-label="<?php p($l->t('From')); ?>"
											/>
										</div>
										<span class="azc-date-range__sep" aria-hidden="true"><?php p($l->t('to')); ?></span>
										<div class="azc-date-range__part">
											<label for="end-date-filter" class="azc-date-range__sublabel visually-hidden"><?php p($l->t('To')); ?></label>
											<input
												id="end-date-filter"
												name="end_date"
												type="text"
												class="form-input datepicker-input"
												placeholder="<?php p($l->t('dd.mm.yyyy')); ?>"
												pattern="\d{2}\.\d{2}\.\d{4}"
												maxlength="10"
												required
												autocomplete="off"
												aria-required="true"
												aria-label="<?php p($l->t('To')); ?>"
											/>
										</div>
									</div>
								</div>
							</div>

							<div class="azc-filter-field azc-filter-field--actions">
								<span class="azc-filter-field__label visually-hidden"><?php p($l->t('Actions')); ?></span>
								<div class="azc-filter-field__control azc-filter-actions">
									<button type="submit" class="azc-btn azc-btn--primary" id="employee-time-entries-submit">
										<?php p($l->t('Show')); ?>
									</button>
									<button type="button" id="employee-time-entries-clear" class="azc-btn azc-btn--secondary">
										<?php p($l->t('Reset')); ?>
									</button>
								</div>
							</div>
						</div>

						<p id="employee-time-entries-filter-error" class="azc-callout azc-callout--danger manager-scope-page__toolbar-feedback" role="alert" hidden>
							<span class="azc-callout__text"></span>
						</p>
					</form>
					<p id="employee-time-entries-filter-help" class="manager-scope-page__toolbar-footnote" role="note">
						<?php p($l->t('Pick a date range, then Show. Leave employee empty for your whole team, or search and select one person. Maximum range: %d days.', [$maxManagerListDateRangeDays])); ?>
					</p>
				</div>

				<div id="employee-time-entries-empty" class="azc-empty-state">
					<h3 class="azc-empty-state__title"><?php p($l->t('Select filters first')); ?></h3>
					<p class="azc-empty-state__text"><?php p($l->t('Choose a date range to load entries.')); ?></p>
				</div>

				<div id="employee-time-entries-table-wrap" class="table-container visually-hidden" aria-live="polite">
					<table class="table table--hover azc-table--responsive manager-scope-page__results-table" aria-label="<?php p($l->t('Employee time entries')); ?>">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('Name')); ?></th>
								<th scope="col"><?php p($l->t('Date')); ?></th>
								<th scope="col"><?php p($l->t('Start')); ?></th>
								<th scope="col"><?php p($l->t('End')); ?></th>
								<th scope="col"><?php p($l->t('Working Hours')); ?></th>
								<th scope="col"><?php p($l->t('Break')); ?></th>
								<th scope="col"><?php p($l->t('Status')); ?></th>
								<th scope="col"><?php p($l->t('Location')); ?></th>
								<th scope="col"><?php p($l->t('Description')); ?></th>
								<th scope="col" class="azc-table-actions-col"><?php p($l->t('Actions')); ?></th>
							</tr>
						</thead>
						<tbody id="employee-time-entries-body"></tbody>
					</table>
				</div>

				<nav class="manager-scope-page__pagination" aria-label="<?php p($l->t('Results pagination')); ?>">
					<button type="button" id="employee-time-entries-prev" class="azc-btn azc-btn--secondary" disabled><?php p($l->t('Previous')); ?></button>
					<span id="employee-time-entries-page-indicator" class="manager-scope-page__pagination-info"></span>
					<button type="button" id="employee-time-entries-next" class="azc-btn azc-btn--secondary" disabled><?php p($l->t('Next')); ?></button>
				</nav>
			</div>
		</section>

	</div>
</div>

<?php include __DIR__ . '/common/manager-correction-l10n.php'; ?>

<?php include __DIR__ . '/common/manager-employee-list-l10n.php'; ?>

<?php include __DIR__ . '/common/page-end.php'; ?>
