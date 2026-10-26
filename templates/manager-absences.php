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
	<div class="manager-scope-page manager-scope-page--absences">

		<section class="azc-card manager-scope-page__results" aria-labelledby="employee-absences-results-title" aria-busy="false">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="employee-absences-results-title" class="azc-card__title"><?php p($l->t('Employee absences')); ?></h2>
					<p class="azc-card__lead">
						<?php p($l->t('Choose who and which period to view, then click Show. The table below lists matching requests.')); ?>
					</p>
				</div>
				<p id="employee-absences-count" class="azc-badge azc-badge--neutral" role="status" aria-live="polite"></p>
			</header>
			<div class="azc-card__body">
				<div class="manager-scope-page__toolbar" role="search" aria-label="<?php p($l->t('Filter employee absences')); ?>">
					<form
						id="employee-absences-filter-form"
						class="manager-scope-page__filter-form"
						novalidate
						aria-describedby="employee-absences-filter-error"
					>
						<div class="manager-scope-filter manager-scope-filter--extended" role="group" aria-label="<?php p($l->t('Filter options')); ?>">
							<div class="azc-filter-field azc-filter-field--employee">
								<label for="employee-absences-employee-filter-search" class="azc-filter-field__label"><?php p($l->t('Employee')); ?></label>
								<div class="azc-filter-field__control">
									<?php
									$scopePickerId = 'employee-absences-employee-filter';
									$scopePickerName = 'employee_id';
									$scopePickerAllowAll = true;
									$scopePickerRequired = false;
									$scopePickerCompact = true;
									include __DIR__ . '/common/scoped-employee-picker-field.php';
									?>
								</div>
							</div>

							<div class="azc-filter-field azc-filter-field--type">
								<label for="employee-absences-type-filter" class="azc-filter-field__label"><?php p($l->t('Type')); ?></label>
								<div class="azc-filter-field__control">
									<select id="employee-absences-type-filter" name="type" class="form-select" aria-label="<?php p($l->t('Type')); ?>">
										<option value=""><?php p($l->t('All types')); ?></option>
										<?php foreach (\OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog::labels($l) as $typeKey => $typeLabel): ?>
										<option value="<?php p($typeKey); ?>"><?php p($typeLabel); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="azc-filter-field azc-filter-field--status">
								<label for="employee-absences-status-filter" class="azc-filter-field__label"><?php p($l->t('Status')); ?></label>
								<div class="azc-filter-field__control">
									<select id="employee-absences-status-filter" name="status" class="form-select" aria-label="<?php p($l->t('Status')); ?>">
										<option value=""><?php p($l->t('All statuses')); ?></option>
										<option value="pending"><?php p($l->t('Pending')); ?></option>
										<option value="substitute_pending"><?php p($l->t('Substitute pending')); ?></option>
										<option value="substitute_declined"><?php p($l->t('Substitute declined')); ?></option>
										<option value="approved"><?php p($l->t('Approved')); ?></option>
										<option value="rejected"><?php p($l->t('Rejected')); ?></option>
										<option value="cancelled"><?php p($l->t('Cancelled')); ?></option>
									</select>
								</div>
							</div>

							<div
								class="azc-filter-field azc-filter-field--dates"
								role="group"
								aria-labelledby="employee-absences-date-range-label"
							>
								<span id="employee-absences-date-range-label" class="azc-filter-field__label"><?php p($l->t('Date range')); ?></span>
								<div class="azc-filter-field__control">
									<div class="azc-date-range">
										<div class="azc-date-range__part">
											<label for="employee-absences-start-date-filter" class="azc-date-range__sublabel visually-hidden"><?php p($l->t('From')); ?></label>
											<input
												id="employee-absences-start-date-filter"
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
											<label for="employee-absences-end-date-filter" class="azc-date-range__sublabel visually-hidden"><?php p($l->t('To')); ?></label>
											<input
												id="employee-absences-end-date-filter"
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
									<button type="submit" class="azc-btn azc-btn--primary" id="employee-absences-submit">
										<?php p($l->t('Show')); ?>
									</button>
									<button type="button" id="employee-absences-clear" class="azc-btn azc-btn--secondary">
										<?php p($l->t('Reset')); ?>
									</button>
								</div>
							</div>
						</div>

						<p id="employee-absences-filter-error" class="azc-callout azc-callout--danger manager-scope-page__toolbar-feedback" role="alert" hidden>
							<span class="azc-callout__text"></span>
						</p>
					</form>
					<p id="employee-absences-filter-help" class="manager-scope-page__toolbar-footnote" role="note">
						<?php p($l->t('Pick a date range, then Show. Leave employee empty for your whole team, or search and select one person. Type and status are optional. Maximum range: %d days.', [$maxManagerListDateRangeDays])); ?>
					</p>
				</div>

				<div id="employee-absences-empty" class="azc-empty-state">
					<h3 class="azc-empty-state__title"><?php p($l->t('Select filters first')); ?></h3>
					<p class="azc-empty-state__text"><?php p($l->t('Choose a date range to load absences.')); ?></p>
				</div>

				<div id="employee-absences-table-wrap" class="table-container visually-hidden" aria-live="polite">
					<table class="table table--hover azc-table--responsive manager-scope-page__results-table" aria-label="<?php p($l->t('Employee absences')); ?>">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('Name')); ?></th>
								<th scope="col"><?php p($l->t('Type')); ?></th>
								<th scope="col"><?php p($l->t('Start date')); ?></th>
								<th scope="col"><?php p($l->t('End date')); ?></th>
								<th scope="col" id="employee-absences-duration-heading"><?php p($l->t('Duration')); ?></th>
								<th scope="col"><?php p($l->t('Status')); ?></th>
								<th scope="col"><?php p($l->t('Reason')); ?></th>
							</tr>
						</thead>
						<tbody id="employee-absences-body"></tbody>
					</table>
				</div>

				<nav class="manager-scope-page__pagination" aria-label="<?php p($l->t('Results pagination')); ?>">
					<button type="button" id="employee-absences-prev" class="azc-btn azc-btn--secondary" disabled><?php p($l->t('Previous')); ?></button>
					<span id="employee-absences-page-indicator" class="manager-scope-page__pagination-info"></span>
					<button type="button" id="employee-absences-next" class="azc-btn azc-btn--secondary" disabled><?php p($l->t('Next')); ?></button>
				</nav>
			</div>
		</section>

		<section class="azc-card manager-scope-page__record" aria-labelledby="manager-absence-record-title">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="manager-absence-record-title" class="azc-card__title"><?php p($l->t('Record approved absence for an employee')); ?></h2>
					<p class="azc-card__lead">
						<?php p($l->t('Saves immediately as approved. For past or future dates; substitute rules do not apply. Finalized months stay blocked until an administrator reopens them.')); ?>
					</p>
				</div>
			</header>
			<div class="azc-card__body">
				<?php
				$calloutVariant = 'info';
				$calloutRole = 'status';
				$calloutId = 'manager-absence-record-historical-hint';
				$calloutTitle = $l->t('Historical entry – the dates you selected are in the past');
				$calloutText = $l->t('This will be saved immediately as approved and shown with the "Past record" badge in calendars, timelines and reports. Audit trail captures who recorded it.');
				$calloutIcon = 'clock';
				$calloutExtraClass = 'manager-scope-page__historical-hint';
				$calloutBanner = false;
				$calloutAriaLive = 'polite';
				$calloutHidden = true;
				$calloutActions = [];
				$calloutElement = 'div';
				include __DIR__ . '/common/alert-callout.php';
				?>
				<form id="manager-absence-record-form" class="azc-filter-panel__form" novalidate>
					<div class="azc-filter-grid azc-filter-grid--record" role="group" aria-label="<?php p($l->t('Record absence')); ?>">
						<div class="azc-filter-field">
							<label for="manager-absence-record-employee-search" class="azc-filter-field__label"><?php p($l->t('Employee (required)')); ?></label>
							<div class="azc-filter-field__control">
								<?php
								$scopePickerId = 'manager-absence-record-employee';
								$scopePickerName = 'record_employee_id';
								$scopePickerAllowAll = false;
								$scopePickerRequired = true;
								include __DIR__ . '/common/scoped-employee-picker-field.php';
								?>
							</div>
						</div>
						<div class="azc-filter-field">
							<label for="manager-absence-record-type" class="azc-filter-field__label"><?php p($l->t('Type')); ?></label>
							<div class="azc-filter-field__control">
								<select id="manager-absence-record-type" name="record_type" class="form-select" required>
									<?php foreach (\OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog::creatableLabels($l) as $typeKey => $typeLabel): ?>
									<option value="<?php p($typeKey); ?>"><?php p($typeLabel); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="azc-filter-field">
							<label for="manager-absence-record-start" class="azc-filter-field__label"><?php p($l->t('From')); ?></label>
							<div class="azc-filter-field__control">
								<input id="manager-absence-record-start" name="record_start_date" type="text" class="form-input datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" required autocomplete="off" aria-label="<?php p($l->t('From')); ?>" />
							</div>
						</div>
						<div class="azc-filter-field">
							<label for="manager-absence-record-end" class="azc-filter-field__label"><?php p($l->t('To')); ?></label>
							<div class="azc-filter-field__control">
								<input id="manager-absence-record-end" name="record_end_date" type="text" class="form-input datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" required autocomplete="off" aria-label="<?php p($l->t('To')); ?>" />
							</div>
						</div>
						<div class="azc-filter-field" id="manager-absence-record-day-fraction-field" hidden>
							<fieldset class="absence-day-fraction__fieldset">
								<legend id="manager-absence-day-fraction-legend" class="azc-filter-field__label"><?php p($l->t('Day length')); ?></legend>
								<div class="absence-day-fraction__segments"
									 role="radiogroup"
									 aria-labelledby="manager-absence-day-fraction-legend"
									 aria-describedby="manager-absence-day-fraction-help manager-absence-day-fraction-preview">
									<label class="absence-day-fraction__segment">
										<input type="radio" name="record_day_fraction" id="manager-absence-day-fraction-full" value="1" checked class="absence-day-fraction__input">
										<span class="absence-day-fraction__face"><?php p($l->t('Full day')); ?></span>
									</label>
									<label class="absence-day-fraction__segment">
										<input type="radio" name="record_day_fraction" id="manager-absence-day-fraction-half" value="0.5" class="absence-day-fraction__input">
										<span class="absence-day-fraction__face"><?php p($l->t('Half day')); ?></span>
									</label>
								</div>
							</fieldset>
							<p id="manager-absence-day-fraction-help" class="form-help"><?php p($l->t('Full day or half day for this date. Morning or afternoon is not tracked.')); ?></p>
							<p id="manager-absence-day-fraction-preview" class="azc-callout azc-callout--info" role="status" aria-live="polite"></p>
							<p id="manager-absence-day-fraction-live" class="sr-only" aria-live="polite"></p>
						</div>
						<div class="azc-filter-field" id="manager-absence-record-hours-field" hidden>
							<label for="manager-absence-record-hours" class="azc-filter-field__label"><?php p($l->t('Hours')); ?></label>
							<div class="azc-filter-field__control">
								<input id="manager-absence-record-hours" name="record_duration_hours" type="number" class="form-input" min="0.25" max="744" step="0.25" inputmode="decimal" placeholder="<?php p($l->t('e.g. 4')); ?>" aria-describedby="manager-absence-record-hours-help" />
							</div>
							<p id="manager-absence-record-hours-help" class="form-help"><?php p($l->t('Total vacation hours for the whole request (not per day). Full range uses the employee’s work model (schedule), not a fixed 8 hours.')); ?></p>
							<p id="manager-absence-record-hours-preview" class="azc-callout azc-callout--info" role="status" aria-live="polite" hidden></p>
							<div class="absence-hours-presets" role="group" aria-label="<?php p($l->t('Quick hours')); ?>">
								<button type="button" class="azc-btn azc-btn--secondary manager-absence-hours-preset" data-hours="4"><?php p($l->t('4 hours')); ?></button>
								<button type="button" class="azc-btn azc-btn--secondary manager-absence-hours-preset" data-hours-half="1"><?php p($l->t('Half day')); ?></button>
								<button type="button" class="azc-btn azc-btn--secondary manager-absence-hours-preset" data-hours-full="1"><?php p($l->t('1 full day')); ?></button>
								<button type="button" class="azc-btn azc-btn--primary manager-absence-hours-preset" data-hours-range="1"><?php p($l->t('Full range')); ?></button>
							</div>
						</div>
						<div class="azc-filter-actions">
							<button type="submit" class="azc-btn azc-btn--primary" id="manager-absence-record-submit"><?php p($l->t('Save as approved')); ?></button>
						</div>
					</div>
					<div class="manager-scope-page__reason-field">
						<label for="manager-absence-record-reason" class="azc-filter-field__label"><?php p($l->t('Reason')); ?></label>
						<textarea id="manager-absence-record-reason" name="record_reason" class="form-textarea form-input" rows="3" maxlength="8000" placeholder="<?php p($l->t('Optional reason or notes for your absence request')); ?>"></textarea>
					</div>
				</form>
			</div>
		</section>

	</div>
</div>

<?php include __DIR__ . '/common/manager-employee-list-l10n.php'; ?>
<script>
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.vacationUnit = <?php echo json_encode((string)($_['vacationUnit'] ?? 'days'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.vacationHoursPerDay = <?php echo json_encode((float)($_['vacationHoursPerDay'] ?? 8), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.estimateEmployeeVacationHoursUrl = <?php echo json_encode(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.manager.estimateEmployeeVacationHours'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<?php include __DIR__ . '/common/page-end.php'; ?>
