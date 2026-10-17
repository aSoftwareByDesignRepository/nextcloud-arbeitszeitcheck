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
				<h2 id="mbt-queue-title" class="azc-card__title"><?php p($l->t('Submitted business trips')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Trips waiting for your review. Open one to see the recorded times, the rule result, and what will be booked.')); ?></p>
			</div>
		</header>
		<div class="azc-card__body">
			<p id="mbt-queue-status" role="status" aria-live="polite" class="form-help"></p>
			<ul id="mbt-queue" class="azc-list bt-trip-list" aria-label="<?php p($l->t('Submitted business trips')); ?>"></ul>
			<p id="mbt-empty" class="azc-empty-state" hidden><?php p($l->t('No business trips are waiting for review.')); ?></p>
		</div>
	</section>

	<section class="azc-card" id="mbt-detail" aria-labelledby="mbt-detail-title" hidden>
		<header class="azc-card__header azc-card__header--row">
			<div class="azc-card__header-text">
				<h2 id="mbt-detail-title" class="azc-card__title"><?php p($l->t('Trip review')); ?></h2>
				<p class="azc-card__lead" id="mbt-detail-subtitle"></p>
			</div>
			<span id="mbt-detail-status" class="azc-status-chip" data-status="submitted"></span>
		</header>
		<div class="azc-card__body">
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
				<section class="azc-card mbt-card" aria-labelledby="mbt-facts-title">
					<header class="azc-card__header"><h3 id="mbt-facts-title" class="azc-card__title"><?php p($l->t('What was recorded')); ?></h3></header>
					<div class="azc-card__body">
						<ul id="mbt-segments" class="azc-list bt-segment-list" aria-label="<?php p($l->t('Travel blocks')); ?>"></ul>
						<p class="form-help" id="mbt-linked-summary"></p>
					</div>
				</section>
				<section class="azc-card mbt-card" aria-labelledby="mbt-rule-title">
					<header class="azc-card__header"><h3 id="mbt-rule-title" class="azc-card__title"><?php p($l->t('What the rule computed')); ?></h3></header>
					<div class="azc-card__body">
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
					</div>
				</section>
				<section class="azc-card mbt-card" aria-labelledby="mbt-booking-title">
					<header class="azc-card__header"><h3 id="mbt-booking-title" class="azc-card__title"><?php p($l->t('What will be booked')); ?></h3></header>
					<div class="azc-card__body">
						<dl class="bt-summary">
							<div class="bt-summary__item"><dt><?php p($l->t('Recorded')); ?></dt><dd id="mbt-sum-recorded">—</dd></div>
							<div class="bt-summary__item"><dt><?php p($l->t('Counts as work')); ?></dt><dd id="mbt-sum-work">—</dd></div>
							<div class="bt-summary__item"><dt><?php p($l->t('Extra credit (top-up)')); ?></dt><dd id="mbt-sum-topup">—</dd></div>
						</dl>
						<p class="form-help"><?php p($l->t('The top-up is credited separately to the time account — it never changes the recorded working time.')); ?></p>
					</div>
				</section>
			</div>

			<div class="form-group mbt-reject-group" id="mbt-reject-group" hidden>
				<label for="mbt-reject-reason" class="form-label"><?php p($l->t('Reason for rejection')); ?> <span class="form-required" aria-hidden="true">*</span></label>
				<textarea id="mbt-reject-reason" rows="2" maxlength="500" aria-describedby="mbt-reject-error"></textarea>
				<p id="mbt-reject-error" class="azc-field-error" hidden></p>
			</div>

			<div class="form-actions">
				<button type="button" class="azc-btn azc-btn--secondary" id="mbt-back"><?php p($l->t('Back to list')); ?></button>
				<button type="button" class="azc-btn azc-btn--danger" id="mbt-reject-toggle"><?php p($l->t('Reject')); ?></button>
				<button type="button" class="azc-btn azc-btn--danger" id="mbt-reject-confirm" hidden><?php p($l->t('Confirm rejection')); ?></button>
				<button type="button" class="azc-btn azc-btn--primary" id="mbt-approve"><?php p($l->t('Approve')); ?></button>
			</div>
		</div>
	</section>
</div>
