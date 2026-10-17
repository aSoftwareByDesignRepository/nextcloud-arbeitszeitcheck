<?php

declare(strict_types=1);

/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
$enabled = !empty($_['businessTripsEnabled']);
$initialTripId = (int)($_['initialTripId'] ?? 0);
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack azc-business-trips"
	id="bt-root"
	data-enabled="<?php p($enabled ? '1' : '0'); ?>"
	data-initial-trip-id="<?php p($initialTripId); ?>"
	data-list-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip.index')); ?>"
	data-trip-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip.show', 'id')); ?>"
	data-create-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip.create')); ?>"
	data-update-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip.update', 'id')); ?>"
	data-delete-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip.delete', 'id')); ?>"
	data-segments-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip.replaceSegments', 'id')); ?>"
	data-link-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip.linkTimeEntry', 'id')); ?>"
	data-preview-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip.preview', 'id')); ?>"
	data-submit-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip.submit', 'id')); ?>"
	data-cancel-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip.cancel', 'id')); ?>"
	data-export-csv-template="<?php p(preg_replace('#/0(?=/)#', '/__ID__', $urlGenerator->linkToRoute('arbeitszeitcheck.business_trip.export', ['id' => 0, 'format' => 'csv']), 1)); ?>"
	data-export-pdf-template="<?php p(preg_replace('#/0(?=/)#', '/__ID__', $urlGenerator->linkToRoute('arbeitszeitcheck.business_trip.export', ['id' => 0, 'format' => 'pdf']), 1)); ?>"
	data-time-entries-url="<?php p($_['timeEntriesApi']); ?>"
	data-detail-page-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.page.businessTripDetail', 'id')); ?>"
	data-list-page-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.businessTrips')); ?>">

	<?php if (!$enabled): ?>
	<div class="azc-callout azc-callout--info" role="status">
		<div class="azc-callout__body">
			<p class="azc-callout__title"><?php p($l->t('Business trips are not enabled')); ?></p>
			<p class="azc-callout__text"><?php p($l->t('Your organisation has not activated business-trip recording. Existing absences of type business trip stay unchanged.')); ?></p>
		</div>
	</div>
	<?php endif; ?>

	<div id="bt-enabled" <?php echo $enabled ? '' : 'hidden'; ?>>

		<!-- List view -->
		<section class="azc-card" id="bt-list-view" aria-labelledby="bt-list-title">
			<header class="azc-card__header azc-card__header--row">
				<div class="azc-card__header-text">
					<h2 id="bt-list-title" class="azc-card__title"><?php p($l->t('Business trips')); ?></h2>
					<p class="azc-card__lead"><?php p($l->t('Record a business trip, link the working time that belongs to it, and submit it for review.')); ?></p>
				</div>
				<button id="bt-new-trip" class="azc-btn azc-btn--primary" type="button"><?php p($l->t('Record business trip')); ?></button>
			</header>
			<div class="azc-card__body">
				<p id="bt-list-status" role="status" aria-live="polite" class="form-help"></p>
				<ul id="bt-trip-list" class="azc-list bt-trip-list" aria-label="<?php p($l->t('Business trips')); ?>"></ul>
				<div id="bt-empty" class="azc-empty-state" hidden>
					<p class="azc-empty-state__text"><?php p($l->t('No business trips yet.')); ?></p>
					<button id="bt-empty-create" class="azc-btn azc-btn--primary" type="button"><?php p($l->t('Record your first business trip')); ?></button>
				</div>
			</div>
		</section>

		<!-- Create view -->
		<section class="azc-card" id="bt-create-view" aria-labelledby="bt-create-title" hidden>
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="bt-create-title" class="azc-card__title"><?php p($l->t('Record business trip')); ?></h2>
					<p class="azc-card__lead" id="bt-create-lead"><?php p($l->t('When does the trip start and end? You add travel blocks and working times on the next step.')); ?></p>
				</div>
			</header>
			<div class="azc-card__body">
				<div class="azc-callout azc-callout--danger" role="alert" id="bt-create-error" hidden>
					<p id="bt-create-error-text" class="azc-callout__text"></p>
				</div>
				<form id="bt-create-form" class="form" novalidate>
					<div class="form-grid">
						<div class="form-group">
							<label for="bt-start" class="form-label"><?php p($l->t('Start')); ?> <span class="form-required" aria-hidden="true">*</span></label>
							<input id="bt-start" name="startsAt" type="datetime-local" required aria-describedby="bt-start-error">
							<p id="bt-start-error" class="azc-field-error" hidden></p>
						</div>
						<div class="form-group">
							<label for="bt-end" class="form-label"><?php p($l->t('End')); ?> <span class="form-required" aria-hidden="true">*</span></label>
							<input id="bt-end" name="endsAt" type="datetime-local" required aria-describedby="bt-end-error">
							<p id="bt-end-error" class="azc-field-error" hidden></p>
						</div>
					</div>
					<div class="form-grid">
						<div class="form-group">
							<label for="bt-origin-tz" class="form-label"><?php p($l->t('Departure time zone')); ?> <span class="form-required" aria-hidden="true">*</span></label>
							<select id="bt-origin-tz" name="originTimezone" required aria-describedby="bt-origin-tz-error"></select>
							<p id="bt-origin-tz-error" class="azc-field-error" hidden></p>
						</div>
						<div class="form-group">
							<label for="bt-destination-tz" class="form-label"><?php p($l->t('Destination time zone')); ?> <span class="form-required" aria-hidden="true">*</span></label>
							<select id="bt-destination-tz" name="destinationTimezone" required aria-describedby="bt-destination-tz-error"></select>
							<p id="bt-destination-tz-error" class="azc-field-error" hidden></p>
						</div>
					</div>
					<div class="form-group">
						<label for="bt-purpose" class="form-label"><?php p($l->t('Purpose (optional)')); ?></label>
						<input id="bt-purpose" name="purpose" maxlength="200" aria-describedby="bt-purpose-help bt-purpose-error">
						<p id="bt-purpose-help" class="form-help"><?php p($l->t('Short label only — no customer names needed. Maximum 200 characters.')); ?></p>
						<p id="bt-purpose-error" class="azc-field-error" hidden></p>
					</div>
					<div class="form-actions">
						<button type="submit" class="azc-btn azc-btn--primary" id="bt-create-submit"><?php p($l->t('Continue')); ?></button>
						<button type="button" class="azc-btn azc-btn--secondary" id="bt-create-cancel"><?php p($l->t('Cancel')); ?></button>
					</div>
				</form>
			</div>
		</section>

		<!-- Detail view -->
		<section class="azc-card" id="bt-detail-view" aria-labelledby="bt-detail-title" hidden>
			<header class="azc-card__header azc-card__header--row">
				<div class="azc-card__header-text">
					<h2 id="bt-detail-title" class="azc-card__title"><?php p($l->t('Business trip')); ?></h2>
					<p class="azc-card__lead" id="bt-detail-subtitle"></p>
				</div>
				<span id="bt-detail-status" class="azc-status-chip" data-status="draft"></span>
			</header>
			<div class="azc-card__body">
				<p id="bt-detail-status-msg" role="status" aria-live="polite" class="form-help"></p>
				<div class="azc-callout azc-callout--danger" role="alert" id="bt-detail-error" hidden>
					<p id="bt-detail-error-text" class="azc-callout__text"></p>
				</div>
				<div class="azc-callout azc-callout--warning" role="status" id="bt-conflict" hidden>
					<div class="azc-callout__body">
						<p class="azc-callout__title"><?php p($l->t('This trip changed')); ?></p>
						<p class="azc-callout__text"><?php p($l->t('The trip was changed in another window. Your entries are kept — reload to see the current state.')); ?></p>
						<button type="button" class="azc-btn azc-btn--secondary" id="bt-conflict-reload"><?php p($l->t('Reload')); ?></button>
					</div>
				</div>

				<!-- Step 1: travel blocks -->
				<section class="bt-step" aria-labelledby="bt-segments-title">
					<h3 id="bt-segments-title" class="bt-step__title"><?php p($l->t('Travel blocks')); ?></h3>
					<p class="form-help"><?php p($l->t('Describe how you travelled. Add a block for each part — for example outbound drive, flight, return trip.')); ?></p>
					<ul id="bt-segment-list" class="azc-list bt-segment-list" aria-label="<?php p($l->t('Travel blocks')); ?>"></ul>
					<form id="bt-segment-form" class="form bt-segment-form" novalidate>
						<div class="form-grid form-grid--4">
							<div class="form-group">
								<label for="bt-seg-start" class="form-label"><?php p($l->t('Start')); ?></label>
								<input id="bt-seg-start" type="datetime-local" required>
							</div>
							<div class="form-group">
								<label for="bt-seg-end" class="form-label"><?php p($l->t('End')); ?></label>
								<input id="bt-seg-end" type="datetime-local" required>
							</div>
							<div class="form-group">
								<label for="bt-seg-kind" class="form-label"><?php p($l->t('What is this time?')); ?></label>
								<select id="bt-seg-kind" required>
									<option value="active_travel"><?php p($l->t('I drove myself')); ?></option>
									<option value="passive_travel"><?php p($l->t('I was a passenger / was transported')); ?></option>
									<option value="work_during_travel"><?php p($l->t('I worked during the trip')); ?></option>
									<option value="rest_personal"><?php p($l->t('Rest / private time')); ?></option>
								</select>
							</div>
							<div class="form-group">
								<label for="bt-seg-mode" class="form-label"><?php p($l->t('Transport (optional)')); ?></label>
								<select id="bt-seg-mode">
									<option value=""><?php p($l->t('Not specified')); ?></option>
									<option value="car_driver"><?php p($l->t('Car (driver)')); ?></option>
									<option value="car_passenger"><?php p($l->t('Car (passenger)')); ?></option>
									<option value="train"><?php p($l->t('Train')); ?></option>
									<option value="plane"><?php p($l->t('Plane')); ?></option>
									<option value="public_transport"><?php p($l->t('Public transport')); ?></option>
									<option value="bicycle"><?php p($l->t('Bicycle')); ?></option>
									<option value="walking"><?php p($l->t('Walking')); ?></option>
									<option value="other"><?php p($l->t('Other')); ?></option>
								</select>
							</div>
						</div>
						<p id="bt-seg-error" class="azc-field-error" role="alert" hidden></p>
						<div class="form-actions">
							<button type="submit" class="azc-btn azc-btn--secondary" id="bt-seg-add"><?php p($l->t('Add travel block')); ?></button>
						</div>
					</form>
				</section>

				<!-- Step 2: suggested time entries -->
				<section class="bt-step" aria-labelledby="bt-links-title" id="bt-links-step">
					<h3 id="bt-links-title" class="bt-step__title"><?php p($l->t('Working times during the trip')); ?></h3>
					<p class="form-help"><?php p($l->t('Tick every working-time entry that belongs to this trip. Only linked entries count as work done on the trip.')); ?></p>
					<ul id="bt-entry-suggestions" class="azc-list bt-suggestion-list" aria-label="<?php p($l->t('Working times during the trip window')); ?>"></ul>
					<p id="bt-links-status" role="status" aria-live="polite" class="form-help"></p>
				</section>

				<!-- Step 3: summary + preview -->
				<section class="bt-step" aria-labelledby="bt-summary-title">
					<h3 id="bt-summary-title" class="bt-step__title"><?php p($l->t('Summary')); ?></h3>
					<dl class="bt-summary" id="bt-summary-grid">
						<div class="bt-summary__item"><dt><?php p($l->t('Recorded')); ?></dt><dd id="bt-sum-recorded">—</dd></div>
						<div class="bt-summary__item"><dt><?php p($l->t('Counts as work')); ?></dt><dd id="bt-sum-work">—</dd></div>
						<div class="bt-summary__item"><dt><?php p($l->t('Extra credit (top-up)')); ?></dt><dd id="bt-sum-topup">—</dd></div>
					</dl>
					<details class="bt-day-details" id="bt-day-details" hidden>
						<summary><?php p($l->t('Per-day details')); ?></summary>
						<div class="table-container">
							<table class="table table--hover azc-table--responsive" id="bt-day-table" role="table">
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
								<tbody id="bt-day-rows"></tbody>
							</table>
						</div>
					</details>
					<ul id="bt-warnings" class="azc-list bt-warning-list" role="status"></ul>
				</section>

				<div class="form-actions bt-detail-actions">
					<button type="button" class="azc-btn azc-btn--secondary" id="bt-back"><?php p($l->t('Back to list')); ?></button>
					<button type="button" class="azc-btn azc-btn--secondary" id="bt-edit-draft" hidden><?php p($l->t('Edit trip details')); ?></button>
					<button type="button" class="azc-btn azc-btn--secondary" id="bt-refresh-preview"><?php p($l->t('Recalculate')); ?></button>
					<a class="azc-btn azc-btn--secondary" id="bt-export-csv" href="#" hidden><?php p($l->t('Export CSV')); ?></a>
					<a class="azc-btn azc-btn--secondary" id="bt-export-pdf" href="#" hidden><?php p($l->t('Export PDF')); ?></a>
					<button type="button" class="azc-btn azc-btn--danger" id="bt-cancel-trip" hidden><?php p($l->t('Withdraw')); ?></button>
					<button type="button" class="azc-btn azc-btn--danger" id="bt-delete-draft" hidden><?php p($l->t('Delete draft')); ?></button>
					<button type="button" class="azc-btn azc-btn--primary" id="bt-submit" hidden><?php p($l->t('Submit for review')); ?></button>
				</div>
			</div>
		</section>
	</div>
</div>
