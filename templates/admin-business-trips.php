<?php

declare(strict_types=1);

/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
$enabled = !empty($_['businessTripsEnabled']);
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack azc-business-trips azc-bt-admin"
	id="bta-root"
	data-enabled="<?php p($enabled ? '1' : '0'); ?>"
	data-rule-sets-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip_admin.ruleSets')); ?>"
	data-rule-set-create-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip_admin.createRuleSet')); ?>"
	data-rule-set-update-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip_admin.updateRuleSet', 'id')); ?>"
	data-rule-set-activate-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip_admin.activateRuleSet', 'id')); ?>"
	data-preview-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip_admin.previewRuleConfig')); ?>"
	data-assignments-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip_admin.assignments')); ?>"
	data-assignment-create-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip_admin.createAssignment')); ?>"
	data-assignment-end-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip_admin.endAssignment', 'id')); ?>"
	data-profile-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip_admin.setProfile')); ?>"
	data-users-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.getUsers')); ?>"
	data-teams-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.getTeams')); ?>"
	data-models-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.getWorkingTimeModels')); ?>"
	data-cohorts-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.special_entitlement_admin.cohorts')); ?>"
	data-rollout-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip_admin.rollout')); ?>"
	data-enable-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip_admin.enable')); ?>"
	data-disable-legacy-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip_admin.disableLegacy')); ?>"
	data-presets-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip_admin.presets')); ?>"
	data-preset-create-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip_admin.createPreset', 'jurisdiction', '__ID__', 'DE')); ?>"
	data-credit-enable-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.business_trip_admin.enableCredit')); ?>"
	data-mark-reviewed-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.business_trip_admin.markReviewed', 'id')); ?>">

	<?php if (!$enabled): ?>
	<div class="azc-callout azc-callout--info" role="status">
		<div class="azc-callout__body">
			<p class="azc-callout__title"><?php p($l->t('Business trips are not enabled')); ?></p>
			<p class="azc-callout__text"><?php p($l->t('The feature was added by an upgrade and stays inactive until you enable it. Existing data is unchanged.')); ?></p>
		</div>
	</div>
	<?php endif; ?>

	<!-- Rollout & legacy cutover (Phase 6) -->
	<section class="azc-card" aria-labelledby="bta-rollout-title" id="bta-rollout">
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="bta-rollout-title" class="azc-card__title"><?php p($l->t('Rollout')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Check readiness before cutting over from legacy business-trip requests. The switch is only available once every check is green.')); ?></p>
			</div>
		</header>
		<div class="azc-card__body">
			<p id="bta-rollout-status" role="status" aria-live="polite" class="form-help"></p>
			<ul id="bta-rollout-checks" class="azc-list" aria-label="<?php p($l->t('Readiness checks')); ?>"></ul>
			<div class="form-actions">
				<button id="bta-enable" class="azc-btn azc-btn--primary" type="button" hidden>
					<?php p($l->t('Enable business trips')); ?>
				</button>
				<button id="bta-enable-credit" class="azc-btn azc-btn--primary" type="button" hidden>
					<?php p($l->t('Enable time-account credit postings')); ?>
				</button>
				<button id="bta-disable-legacy" class="azc-btn azc-btn--secondary" type="button" disabled>
					<?php p($l->t('Stop new legacy business-trip requests')); ?>
				</button>
				<p class="form-help"><?php p($l->t('Existing legacy requests stay readable and editable forever — this only stops creating new ones.')); ?></p>
			</div>
		</div>
	</section>

	<div id="bta-enabled" <?php echo $enabled ? '' : 'hidden'; ?>>

		<!-- Rule sets -->
		<section class="azc-card" aria-labelledby="bta-sets-title">
			<header class="azc-card__header azc-card__header--row">
				<div class="azc-card__header-text">
					<h2 id="bta-sets-title" class="azc-card__title"><?php p($l->t('Rule sets')); ?></h2>
					<p class="azc-card__lead"><?php p($l->t('Each rule set decides how much of a business trip counts for the time account. Active versions are frozen — changes create a new draft version.')); ?></p>
				</div>
				<div class="azc-card__header-actions">
					<div id="bta-presets" class="bta-presets" role="group" aria-label="<?php p($l->t('Country presets')); ?>"></div>
					<button id="bta-new-set" class="azc-btn azc-btn--primary" type="button"><?php p($l->t('New rule set')); ?></button>
				</div>
			</header>
			<div class="azc-card__body">
				<p id="bta-sets-status" role="status" aria-live="polite" class="form-help"></p>
				<ul id="bta-set-list" class="azc-list" aria-label="<?php p($l->t('Rule sets')); ?>"></ul>
				<p id="bta-sets-empty" class="azc-empty-state" hidden><?php p($l->t('No rule sets yet. Create the first one to make business trips calculable.')); ?></p>
			</div>
		</section>

		<!-- Guided rule builder -->
		<section class="azc-card" id="bta-builder" aria-labelledby="bta-builder-title" hidden>
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="bta-builder-title" class="azc-card__title"><?php p($l->t('Rule set')); ?></h2>
					<p class="azc-card__lead"><?php p($l->t('Answer the questions — the rule is built for you. No technical format needed.')); ?></p>
				</div>
			</header>
			<div class="azc-card__body">
				<div class="azc-callout azc-callout--danger" role="alert" id="bta-builder-error" hidden>
					<p id="bta-builder-error-text" class="azc-callout__text"></p>
				</div>
				<form id="bta-builder-form" class="form" novalidate>
					<input type="hidden" id="bta-set-id" value="">

					<fieldset class="absence-form-fieldset">
						<legend class="absence-form-fieldset__legend"><?php p($l->t('Name and validity')); ?></legend>
						<div class="form-grid">
							<div class="form-group">
								<label for="bta-code" class="form-label"><?php p($l->t('Code')); ?> <span class="form-required" aria-hidden="true">*</span></label>
								<input id="bta-code" maxlength="63" pattern="[a-z][a-z0-9_-]*" required aria-describedby="bta-code-help bta-code-error">
								<p id="bta-code-help" class="form-help"><?php p($l->t('Technical key, e.g. default or sales-team. Once created it cannot change.')); ?></p>
								<p id="bta-code-error" class="azc-field-error" hidden></p>
							</div>
							<div class="form-group">
								<label for="bta-name" class="form-label"><?php p($l->t('Name')); ?> <span class="form-required" aria-hidden="true">*</span></label>
								<input id="bta-name" maxlength="120" required aria-describedby="bta-name-error">
								<p id="bta-name-error" class="azc-field-error" hidden></p>
							</div>
						</div>
						<div class="form-grid">
							<div class="form-group">
								<label for="bta-jurisdiction" class="form-label"><?php p($l->t('Country profile')); ?> <span class="form-required" aria-hidden="true">*</span></label>
								<select id="bta-jurisdiction" required>
									<option value="generic"><?php p($l->t('Generic (no country law applied)')); ?></option>
									<option value="DE"><?php p($l->t('Germany')); ?></option>
									<option value="AT"><?php p($l->t('Austria')); ?></option>
									<option value="CH"><?php p($l->t('Switzerland')); ?></option>
								</select>
							</div>
							<div class="form-group">
								<label for="bta-valid-from" class="form-label"><?php p($l->t('Valid from')); ?></label>
								<input id="bta-valid-from" type="date">
							</div>
							<div class="form-group">
								<label for="bta-valid-to" class="form-label"><?php p($l->t('Valid until (optional)')); ?></label>
								<input id="bta-valid-to" type="date">
							</div>
						</div>
					</fieldset>

					<fieldset class="absence-form-fieldset">
						<legend class="absence-form-fieldset__legend"><?php p($l->t('What counts per day')); ?></legend>
						<div class="form-group">
							<label for="bta-day-basis" class="form-label"><?php p($l->t('Each trip day is measured by')); ?></label>
							<select id="bta-day-basis">
								<option value="trip_presence"><?php p($l->t('Total absence on that day')); ?></option>
								<option value="travel_duration"><?php p($l->t('Travel time only')); ?></option>
								<option value="actual_work"><?php p($l->t('Actual work done')); ?></option>
								<option value="active_plus_work"><?php p($l->t('Driving time plus work done')); ?></option>
								<option value="classified_total"><?php p($l->t('Legally classified time')); ?></option>
							</select>
						</div>
						<details class="bt-advanced">
							<summary><?php p($l->t('Different rules for travel days (advanced)')); ?></summary>
							<div class="form-grid">
								<div class="form-group">
									<label for="bta-kind-first" class="form-label"><?php p($l->t('First day')); ?></label>
									<select id="bta-kind-first" class="bta-kind"><option value=""><?php p($l->t('Same as every day')); ?></option></select>
								</div>
								<div class="form-group">
									<label for="bta-kind-middle" class="form-label"><?php p($l->t('Middle days')); ?></label>
									<select id="bta-kind-middle" class="bta-kind"><option value=""><?php p($l->t('Same as every day')); ?></option></select>
								</div>
								<div class="form-group">
									<label for="bta-kind-last" class="form-label"><?php p($l->t('Last day')); ?></label>
									<select id="bta-kind-last" class="bta-kind"><option value=""><?php p($l->t('Same as every day')); ?></option></select>
								</div>
								<div class="form-group">
									<label for="bta-kind-single" class="form-label"><?php p($l->t('Single-day trip')); ?></label>
									<select id="bta-kind-single" class="bta-kind"><option value=""><?php p($l->t('Same as every day')); ?></option></select>
								</div>
							</div>
						</details>
					</fieldset>

					<fieldset class="absence-form-fieldset">
						<legend class="absence-form-fieldset__legend"><?php p($l->t('Credit tiers')); ?></legend>
						<p class="form-help"><?php p($l->t('“At least X hours absence credits at least Y hours.” Rows are checked in order — the last matching row wins.')); ?></p>
						<table class="table table--hover bta-threshold-table" id="bta-threshold-table">
							<thead>
								<tr>
									<th scope="col"><?php p($l->t('From (h:mm)')); ?></th>
									<th scope="col"><?php p($l->t('Credits (h:mm)')); ?></th>
									<th scope="col"><span class="visually-hidden"><?php p($l->t('Actions')); ?></span></th>
								</tr>
							</thead>
							<tbody id="bta-threshold-rows"></tbody>
						</table>
						<p id="bta-threshold-error" class="azc-field-error" role="alert" hidden></p>
						<button type="button" class="azc-btn azc-btn--secondary" id="bta-add-threshold"><?php p($l->t('Add tier')); ?></button>
					</fieldset>

					<fieldset class="absence-form-fieldset">
						<legend class="absence-form-fieldset__legend"><?php p($l->t('How the credit applies')); ?></legend>
						<div class="form-group">
							<label for="bta-operation" class="form-label"><?php p($l->t('The tier value is')); ?></label>
							<select id="bta-operation">
								<option value="minimum"><?php p($l->t('A minimum — actual work counts fully, shortfalls are topped up')); ?></option>
								<option value="fixed"><?php p($l->t('Fixed — always exactly this amount')); ?></option>
								<option value="maximum"><?php p($l->t('A maximum — actual work counts, capped at this amount')); ?></option>
								<option value="additive"><?php p($l->t('Added on top of actual work')); ?></option>
							</select>
							<p id="bta-operation-hint" class="form-help"></p>
						</div>
						<div class="form-group">
							<label for="bta-cap" class="form-label"><?php p($l->t('Daily cap in minutes (optional)')); ?></label>
							<input id="bta-cap" type="number" min="0" max="1440" step="1" inputmode="numeric" aria-describedby="bta-cap-help">
							<p id="bta-cap-help" class="form-help"><?php p($l->t('Never credit more than this per day, regardless of the tiers.')); ?></p>
						</div>
					</fieldset>

					<fieldset class="absence-form-fieldset">
						<legend class="absence-form-fieldset__legend"><?php p($l->t('How each time kind counts')); ?></legend>
						<p class="form-help"><?php p($l->t('Percentage of each time kind that counts towards the time account.')); ?></p>
						<div class="form-grid form-grid--4">
							<div class="form-group">
								<label for="bta-f-work" class="form-label"><?php p($l->t('Work done')); ?></label>
								<div class="azc-input-suffix"><input id="bta-f-work" type="number" min="0" max="100" step="1" value="100" inputmode="numeric"><span class="azc-input-suffix__unit" aria-hidden="true">%</span></div>
							</div>
							<div class="form-group">
								<label for="bta-f-active" class="form-label"><?php p($l->t('Driving')); ?></label>
								<div class="azc-input-suffix"><input id="bta-f-active" type="number" min="0" max="100" step="1" value="100" inputmode="numeric"><span class="azc-input-suffix__unit" aria-hidden="true">%</span></div>
							</div>
							<div class="form-group">
								<label for="bta-f-passive" class="form-label"><?php p($l->t('Passenger time')); ?></label>
								<div class="azc-input-suffix"><input id="bta-f-passive" type="number" min="0" max="100" step="1" value="0" inputmode="numeric"><span class="azc-input-suffix__unit" aria-hidden="true">%</span></div>
							</div>
							<div class="form-group">
								<label for="bta-f-during" class="form-label"><?php p($l->t('Work while travelling')); ?></label>
								<div class="azc-input-suffix"><input id="bta-f-during" type="number" min="0" max="100" step="1" value="100" inputmode="numeric"><span class="azc-input-suffix__unit" aria-hidden="true">%</span></div>
							</div>
						</div>
					</fieldset>

					<fieldset class="absence-form-fieldset">
						<legend class="absence-form-fieldset__legend"><?php p($l->t('Rounding')); ?></legend>
						<div class="form-grid">
							<div class="form-group">
								<label for="bta-round-mode" class="form-label"><?php p($l->t('Round')); ?></label>
								<select id="bta-round-mode">
									<option value="nearest"><?php p($l->t('To the nearest')); ?></option>
									<option value="floor"><?php p($l->t('Down to')); ?></option>
									<option value="ceil"><?php p($l->t('Up to')); ?></option>
								</select>
							</div>
							<div class="form-group">
								<label for="bta-round-inc" class="form-label"><?php p($l->t('Minute step')); ?></label>
								<select id="bta-round-inc">
									<option value="1" selected>1</option>
									<option value="5">5</option>
									<option value="6">6</option>
									<option value="10">10</option>
									<option value="15">15</option>
									<option value="30">30</option>
									<option value="60">60</option>
								</select>
							</div>
						</div>
					</fieldset>

					<fieldset class="absence-form-fieldset">
						<legend class="absence-form-fieldset__legend"><?php p($l->t('Classification and legal notes')); ?></legend>
						<div class="form-group form-group--checkbox">
							<input id="bta-req-classification" type="checkbox">
							<label for="bta-req-classification"><?php p($l->t('Unclassified travel time blocks submission')); ?></label>
							<p class="form-help"><?php p($l->t('When on, employees must classify every passive travel block before the trip can be submitted.')); ?></p>
						</div>
						<div class="form-group form-group--checkbox">
							<input id="bta-active-released" type="checkbox">
							<label for="bta-active-released"><?php p($l->t('Driving time is legally free (not working time by law)')); ?></label>
						</div>
						<div class="form-group form-group--checkbox">
							<input id="bta-passive-released" type="checkbox">
							<label for="bta-passive-released"><?php p($l->t('Passenger time is legally free')); ?></label>
						</div>
						<div class="form-group">
							<label for="bta-eval-tz" class="form-label"><?php p($l->t('Days are evaluated in the time zone of')); ?></label>
							<select id="bta-eval-tz">
								<option value="organization"><?php p($l->t('The organisation')); ?></option>
								<option value="origin"><?php p($l->t('The departure')); ?></option>
								<option value="destination"><?php p($l->t('The destination')); ?></option>
							</select>
						</div>
					</fieldset>

					<!-- Live boundary preview -->
					<section class="bt-step" aria-labelledby="bta-preview-title">
						<h3 id="bta-preview-title" class="bt-step__title"><?php p($l->t('Boundary check')); ?></h3>
						<div class="form-grid">
							<div class="form-group">
								<label for="bta-preview-basis" class="form-label"><?php p($l->t('Absence minutes per day')); ?></label>
								<input id="bta-preview-basis" type="number" min="0" max="1440" step="1" value="600" inputmode="numeric">
							</div>
						</div>
						<p class="form-help" id="bta-preview-status" role="status" aria-live="polite"></p>
						<ul id="bta-preview-rows" class="azc-list"></ul>
					</section>

					<div class="form-actions">
						<button type="submit" class="azc-btn azc-btn--primary" id="bta-save"><?php p($l->t('Save draft')); ?></button>
						<button type="button" class="azc-btn azc-btn--primary" id="bta-activate" hidden><?php p($l->t('Activate')); ?></button>
						<button type="button" class="azc-btn azc-btn--secondary" id="bta-builder-close"><?php p($l->t('Close')); ?></button>
					</div>
				</form>
			</div>
		</section>

		<!-- Assignments -->
		<section class="azc-card" aria-labelledby="bta-assign-title">
			<header class="azc-card__header azc-card__header--row">
				<div class="azc-card__header-text">
					<h2 id="bta-assign-title" class="azc-card__title"><?php p($l->t('Assignments')); ?></h2>
					<p class="azc-card__lead"><?php p($l->t('Which rule set applies to whom — organisation, working-time model, team, or single person. The most specific assignment wins.')); ?></p>
				</div>
				<button id="bta-new-assignment" class="azc-btn azc-btn--primary" type="button"><?php p($l->t('New assignment')); ?></button>
			</header>
			<div class="azc-card__body">
				<div class="azc-callout azc-callout--danger" role="alert" id="bta-assign-error" hidden>
					<p id="bta-assign-error-text" class="azc-callout__text"></p>
				</div>
				<form id="bta-assignment-form" class="form" hidden novalidate>
					<div class="form-grid">
						<div class="form-group">
							<label for="bta-a-ruleset" class="form-label"><?php p($l->t('Rule set')); ?></label>
							<select id="bta-a-ruleset" required></select>
						</div>
						<div class="form-group">
							<label for="bta-a-scope" class="form-label"><?php p($l->t('Applies to')); ?></label>
							<select id="bta-a-scope" required>
								<option value="org"><?php p($l->t('Whole organisation')); ?></option>
								<option value="model"><?php p($l->t('Working-time model')); ?></option>
								<option value="team"><?php p($l->t('Team')); ?></option>
								<option value="policy_cohort"><?php p($l->t('Entitlement group')); ?></option>
								<option value="user"><?php p($l->t('Single person')); ?></option>
							</select>
						</div>
						<div class="form-group" id="bta-a-target-group">
							<label for="bta-a-target" class="form-label"><?php p($l->t('Selection')); ?></label>
							<select id="bta-a-target"></select>
							<div id="bta-a-user-wrap" class="user-picker" hidden>
								<input type="hidden" id="bta-a-user-id">
								<input id="bta-a-user" class="user-picker__input" type="text" autocomplete="off" aria-autocomplete="list" placeholder="<?php p($l->t('Search people')); ?>" aria-label="<?php p($l->t('Search people')); ?>">
								<div id="bta-a-user-list" class="user-picker__list" hidden></div>
								<p id="bta-a-user-status" class="form-help" role="status"></p>
							</div>
						</div>
						<div class="form-group">
							<label for="bta-a-from" class="form-label"><?php p($l->t('Applies from')); ?> <span class="form-required" aria-hidden="true">*</span></label>
							<input id="bta-a-from" type="date" required aria-describedby="bta-a-from-error">
							<p id="bta-a-from-error" class="azc-field-error" hidden></p>
						</div>
						<div class="form-group">
							<label for="bta-a-to" class="form-label"><?php p($l->t('Applies until (optional)')); ?></label>
							<input id="bta-a-to" type="date">
						</div>
						<div class="form-group">
							<label for="bta-a-priority" class="form-label"><?php p($l->t('Priority')); ?></label>
							<input id="bta-a-priority" type="number" min="-1000" max="1000" step="1" value="0" inputmode="numeric" aria-describedby="bta-a-priority-help">
							<p id="bta-a-priority-help" class="form-help"><?php p($l->t('Higher wins when two assignments of the same specificity overlap.')); ?></p>
						</div>
					</div>
					<div class="form-actions">
						<button type="submit" class="azc-btn azc-btn--primary"><?php p($l->t('Create assignment')); ?></button>
						<button type="button" class="azc-btn azc-btn--secondary" id="bta-assignment-cancel"><?php p($l->t('Cancel')); ?></button>
					</div>
				</form>
				<p id="bta-assign-status" role="status" aria-live="polite" class="form-help"></p>
				<ul id="bta-assignment-list" class="azc-list" aria-label="<?php p($l->t('Assignments')); ?>"></ul>
				<p id="bta-assign-empty" class="azc-empty-state" hidden><?php p($l->t('No assignments yet. Without an assignment no rule applies to anyone.')); ?></p>
			</div>
		</section>

		<!-- Commute profiles -->
		<section class="azc-card" aria-labelledby="bta-profiles-title">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="bta-profiles-title" class="azc-card__title"><?php p($l->t('Normal commute')); ?></h2>
					<p class="azc-card__lead"><?php p($l->t('The usual commute minutes per person. Austrian rules subtract it from travel time. No addresses are stored — only minutes.')); ?></p>
				</div>
			</header>
			<div class="azc-card__body">
				<div class="azc-callout azc-callout--danger" role="alert" id="bta-profile-error" hidden>
					<p id="bta-profile-error-text" class="azc-callout__text"></p>
				</div>
				<form id="bta-profile-form" class="form" novalidate>
					<div class="form-grid">
						<div class="form-group">
							<label for="bta-p-user" class="form-label"><?php p($l->t('Person')); ?> <span class="form-required" aria-hidden="true">*</span></label>
							<div id="bta-p-user-wrap" class="user-picker">
								<input type="hidden" id="bta-p-user-id">
								<input id="bta-p-user" class="user-picker__input" type="text" autocomplete="off" aria-autocomplete="list" required placeholder="<?php p($l->t('Search people')); ?>">
								<div id="bta-p-user-list" class="user-picker__list" hidden></div>
								<p id="bta-p-user-status" class="form-help" role="status"></p>
							</div>
						</div>
						<div class="form-group">
							<label for="bta-p-minutes" class="form-label"><?php p($l->t('Commute minutes per day')); ?></label>
							<input id="bta-p-minutes" type="number" min="0" max="1440" step="1" inputmode="numeric" aria-describedby="bta-p-minutes-error">
							<p id="bta-p-minutes-error" class="azc-field-error" hidden></p>
						</div>
						<div class="form-group">
							<label for="bta-p-from" class="form-label"><?php p($l->t('Applies from')); ?></label>
							<input id="bta-p-from" type="date">
						</div>
					</div>
					<div class="form-actions">
						<button type="submit" class="azc-btn azc-btn--primary"><?php p($l->t('Save commute')); ?></button>
					</div>
				</form>
				<p id="bta-profile-status" role="status" aria-live="polite" class="form-help"></p>
			</div>
		</section>
	</div>
</div>
