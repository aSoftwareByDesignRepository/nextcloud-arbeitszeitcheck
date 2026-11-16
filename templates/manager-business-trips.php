<?php

declare(strict_types=1);

/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack azc-business-trips azc-manager-trips"
	id="mbt-root"
	data-queue-url="<?php p($_['tripQueueUrl']); ?>"
	data-detail-template="<?php p($_['tripDetailTemplate']); ?>"
	data-approve-template="<?php p($_['tripApproveTemplate']); ?>"
	data-reject-template="<?php p($_['tripRejectTemplate']); ?>">

	<section class="azc-card" id="mbt-queue-card" aria-labelledby="mbt-queue-title">
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="mbt-queue-title" class="azc-card__title"><?php p($l->t('Business trips')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Business trips in your team — pending reviews first, decided trips below. Open a row to see the details.')); ?></p>
			</div>
		</header>
		<div class="azc-card__body">
			<div class="azc-filter-grid azc-filter-grid--trips" role="group" aria-label="<?php p($l->t('Filter options')); ?>">
				<div class="azc-filter-field">
					<label for="mbt-filter-employee" class="azc-filter-field__label"><?php p($l->t('Employee')); ?></label>
					<div class="azc-filter-field__control">
						<input type="search" id="mbt-filter-employee" class="form-input" autocomplete="off"
							placeholder="<?php p($l->t('Name or user ID')); ?>">
					</div>
				</div>
				<div class="azc-filter-field">
					<label for="mbt-filter-status" class="azc-filter-field__label"><?php p($l->t('Status')); ?></label>
					<div class="azc-filter-field__control">
						<select id="mbt-filter-status" class="form-select">
							<option value=""><?php p($l->t('All')); ?></option>
							<option value="submitted"><?php p($l->t('Submitted')); ?></option>
							<option value="approved"><?php p($l->t('Approved')); ?></option>
							<option value="rejected"><?php p($l->t('Rejected')); ?></option>
							<option value="cancelled"><?php p($l->t('Withdrawn')); ?></option>
						</select>
					</div>
				</div>
				<div class="azc-filter-field azc-filter-field--dates" role="group" aria-labelledby="mbt-filter-dates-label">
					<span id="mbt-filter-dates-label" class="azc-filter-field__label"><?php p($l->t('Date range')); ?></span>
					<div class="azc-filter-field__control">
						<div class="azc-date-range">
							<div class="azc-date-range__part">
								<label for="mbt-filter-date-from" class="azc-date-range__sublabel visually-hidden"><?php p($l->t('From')); ?></label>
								<input id="mbt-filter-date-from" type="text" class="form-input datepicker-input" maxlength="10" autocomplete="off"
									placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" aria-label="<?php p($l->t('From')); ?>">
							</div>
							<span class="azc-date-range__sep" aria-hidden="true"><?php p($l->t('to')); ?></span>
							<div class="azc-date-range__part">
								<label for="mbt-filter-date-to" class="azc-date-range__sublabel visually-hidden"><?php p($l->t('To')); ?></label>
								<input id="mbt-filter-date-to" type="text" class="form-input datepicker-input" maxlength="10" autocomplete="off"
									placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" aria-label="<?php p($l->t('To')); ?>">
							</div>
						</div>
					</div>
				</div>
				<div class="azc-filter-actions">
					<button type="button" class="azc-btn azc-btn--secondary" id="mbt-filter-reset"><?php p($l->t('Reset filters')); ?></button>
				</div>
			</div>
			<p id="mbt-queue-status" role="status" aria-live="polite" class="form-help"></p>
			<div class="table-container">
				<table class="table table--hover azc-table--responsive" id="mbt-queue-table" aria-label="<?php p($l->t('Business trips')); ?>">
					<thead>
						<tr>
							<th scope="col"><?php p($l->t('Employee')); ?></th>
							<th scope="col"><?php p($l->t('Business trip')); ?></th>
							<th scope="col"><?php p($l->t('Purpose')); ?></th>
							<th scope="col"><?php p($l->t('Status')); ?></th>
							<th scope="col"><?php p($l->t('Updated')); ?></th>
							<th scope="col" class="azc-table-actions-col"><?php p($l->t('Actions')); ?></th>
						</tr>
					</thead>
					<tbody id="mbt-queue-body">
						<tr id="mbt-empty-row" hidden>
							<td colspan="6" class="azc-table-empty">
								<div class="azc-empty-state">
									<h3 class="azc-empty-state__title" id="mbt-empty-title"><?php p($l->t('No business trips in your team yet.')); ?></h3>
									<p class="azc-empty-state__text" id="mbt-empty-text"><?php p($l->t('Trips your team members submit for approval will show up here.')); ?></p>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<?php
			$azcTablePager = [
				'id' => 'mbt-queue-pager',
				'tableId' => 'mbt-queue-table',
				'label' => $l->t('Business trips pagination'),
				'base' => '',
				'filters' => [],
				'allow' => [],
				'state' => \OCA\ArbeitszeitCheck\Support\TablePager::state(0, null, null),
				'pageParam' => 'bt_page',
				'sizeParam' => 'bt_per_page',
				'mode' => 'client',
			];
			include __DIR__ . '/partials/table-pager.php';
			?>
		</div>
	</section>

	<!-- Trip review lives in a wide modal over the queue — not a second card
	     below the table. Reparented to <body> on open (top layer). -->
	<dialog class="azc-dialog modal--xl" id="mbt-detail" aria-modal="true" aria-labelledby="mbt-detail-title">
		<header class="modal-header mbt-detail-header">
			<div class="mbt-detail-heading">
				<h3 id="mbt-detail-title" class="modal-title"><?php p($l->t('Trip review')); ?></h3>
				<p class="mbt-detail-subtitle" id="mbt-detail-subtitle"></p>
			</div>
			<span id="mbt-detail-status" class="azc-status-chip" data-status="submitted"></span>
			<button type="button" class="modal-close" id="mbt-detail-close" aria-label="<?php p($l->t('Close')); ?>"><span aria-hidden="true">&times;</span></button>
		</header>
		<div class="modal-body">
			<p id="mbt-detail-status-msg" role="status" aria-live="polite" class="form-help"></p>
			<div class="azc-callout azc-callout--danger" role="alert" id="mbt-error" hidden>
				<p id="mbt-error-text" class="azc-callout__text"></p>
			</div>
			<div class="azc-callout azc-callout--warning" role="status" id="mbt-conflict" hidden>
				<div class="azc-callout__body">
					<p class="azc-callout__title"><?php p($l->t('This trip changed')); ?></p>
					<p class="azc-callout__text"><?php p($l->t('The trip was updated since you opened it. Reload to review the current state — your decision was not applied.')); ?></p>
				</div>
			</div>

			<div class="mbt-cards">
				<section class="mbt-card" aria-labelledby="mbt-facts-title">
					<h3 id="mbt-facts-title"><?php p($l->t('What was recorded')); ?></h3>
					<ul id="mbt-segments" class="azc-list bt-segment-list" aria-label="<?php p($l->t('Travel blocks')); ?>"></ul>
					<p class="form-help" id="mbt-linked-summary"></p>
				</section>
				<section class="mbt-card" aria-labelledby="mbt-rule-title">
					<h3 id="mbt-rule-title"><?php p($l->t('What the rule computed')); ?></h3>
					<div class="table-container">
						<table class="table table--hover azc-table--responsive" id="mbt-day-table" role="table">
							<thead>
								<tr>
									<th scope="col"><?php p($l->t('Day')); ?></th>
									<th scope="col"><?php p($l->t('Travel (driving)')); ?></th>
									<th scope="col"><?php p($l->t('Travel (passenger)')); ?></th>
									<th scope="col"><?php p($l->t('Work')); ?></th>
									<th scope="col"><?php p($l->t('Target')); ?></th>
									<th scope="col"><?php p($l->t('Top-up')); ?></th>
								</tr>
							</thead>
							<tbody id="mbt-day-rows"></tbody>
						</table>
					</div>
				</section>
				<section class="mbt-card" aria-labelledby="mbt-booking-title">
					<h3 id="mbt-booking-title"><?php p($l->t('What will be booked')); ?></h3>
					<dl class="bt-summary">
						<div class="bt-summary__item"><dt><?php p($l->t('Recorded')); ?></dt><dd id="mbt-sum-recorded">—</dd></div>
						<div class="bt-summary__item"><dt><?php p($l->t('Counts as work')); ?></dt><dd id="mbt-sum-work">—</dd></div>
						<div class="bt-summary__item"><dt><?php p($l->t('Extra credit (top-up)')); ?></dt><dd id="mbt-sum-topup">—</dd></div>
					</dl>
					<p class="form-help"><?php p($l->t('The top-up is credited separately to the time account — it never changes the recorded working time.')); ?></p>
				</section>
			</div>

		</div>
		<footer class="modal-footer">
			<button type="button" class="azc-btn azc-btn--secondary" id="mbt-back"><?php p($l->t('Close')); ?></button>
			<button type="button" class="azc-btn azc-btn--danger" id="mbt-reject-toggle"><?php p($l->t('Reject')); ?></button>
			<button type="button" class="azc-btn azc-btn--primary" id="mbt-approve"><?php p($l->t('Approve')); ?></button>
		</footer>
	</dialog>

	<!-- Reject reason is asked in a modal so the detail view stays clean. -->
	<dialog id="mbt-reject-dialog" class="azc-dialog modal--md" aria-modal="true" aria-labelledby="mbt-reject-dialog-title">
		<header class="modal-header">
			<h3 id="mbt-reject-dialog-title" class="modal-title"><?php p($l->t('Confirm rejection')); ?></h3>
			<button type="button" class="modal-close" id="mbt-reject-close" aria-label="<?php p($l->t('Close')); ?>"><span aria-hidden="true">&times;</span></button>
		</header>
		<div class="modal-body">
			<div class="form-group">
				<label for="mbt-reject-reason" class="form-label"><?php p($l->t('Reason for rejection')); ?> <span class="form-required" aria-hidden="true">*</span></label>
				<textarea id="mbt-reject-reason" rows="3" maxlength="500" aria-describedby="mbt-reject-error"></textarea>
				<p id="mbt-reject-error" class="azc-field-error" hidden></p>
			</div>
		</div>
		<footer class="modal-footer">
			<button type="button" class="azc-btn azc-btn--secondary" id="mbt-reject-cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="azc-btn azc-btn--danger" id="mbt-reject-confirm"><?php p($l->t('Confirm rejection')); ?></button>
		</footer>
	</dialog>
</div>
