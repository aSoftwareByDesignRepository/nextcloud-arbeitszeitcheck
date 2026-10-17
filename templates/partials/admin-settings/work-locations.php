<?php

declare(strict_types=1);

/** @var \OCP\IL10N $l */
?>
<section id="section-work-locations-heading" class="settings-card azc-work-location-admin" aria-labelledby="work-locations-title"
	data-catalog-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.work_location_admin.index')); ?>"
	data-create-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.work_location_admin.create')); ?>"
	data-migration-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.work_location_admin.migration')); ?>"
	data-enable-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.work_location_admin.enableCatalog')); ?>"
	data-disable-legacy-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.work_location_admin.disableLegacyHomeAbsence')); ?>"
	data-policies-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.work_location_admin.policies')); ?>"
	data-policy-create-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.work_location_admin.createPolicy')); ?>"
	data-policy-end-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.work_location_admin.endPolicy', 'id')); ?>"
	data-users-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.getUsers')); ?>"
	data-teams-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.getTeams')); ?>"
	data-models-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.getWorkingTimeModels')); ?>">
	<h2 id="work-locations-title"><?php p($l->t('Work locations')); ?></h2>
	<p class="settings-card__description"><?php p($l->t('Manage the locations employees can select. Locations describe where work happened and never create working time.')); ?></p>

	<div id="work-location-rollout" class="azc-callout azc-callout--info" role="status" aria-live="polite">
		<p id="work-location-rollout-status"><?php p($l->t('Checking migration readiness…')); ?></p>
		<label><input id="work-location-rollback-ack" type="checkbox"> <?php p($l->t('I understand that older app versions cannot write custom location codes.')); ?></label>
		<div class="button-row">
			<button id="work-location-enable" class="azc-btn azc-btn--primary" type="button"><?php p($l->t('Enable work-location catalog')); ?></button>
			<button id="work-location-disable-legacy" class="azc-btn azc-btn--secondary" type="button"><?php p($l->t('Stop new home-office absence requests')); ?></button>
		</div>
	</div>

	<div id="work-location-create-form" class="form" role="group" aria-labelledby="work-location-create-heading">
		<h3 id="work-location-create-heading"><?php p($l->t('Add a work location')); ?></h3>
		<div class="form-group">
			<label for="work-location-label"><?php p($l->t('Label')); ?></label>
			<input id="work-location-label" data-field="label" maxlength="80" required aria-describedby="work-location-label-error">
			<p id="work-location-label-error" class="azc-field-error" hidden></p>
		</div>
		<div class="form-group">
			<label for="work-location-code"><?php p($l->t('Code')); ?></label>
			<input id="work-location-code" data-field="code" maxlength="57" pattern="[a-z0-9_]+" required aria-describedby="work-location-code-help work-location-code-error">
			<p id="work-location-code-help" class="form-help"><?php p($l->t('Lowercase letters, numbers, and underscores. The custom_ prefix is added automatically.')); ?></p>
			<p id="work-location-code-error" class="azc-field-error" hidden></p>
		</div>
		<div class="form-group">
			<label for="work-location-category"><?php p($l->t('Reporting category')); ?></label>
			<select id="work-location-category" data-field="category"><option value="office"><?php p($l->t('Office')); ?></option><option value="home"><?php p($l->t('Home office')); ?></option><option value="external"><?php p($l->t('External appointment')); ?></option><option value="other"><?php p($l->t('Other')); ?></option></select>
		</div>
		<button class="azc-btn azc-btn--primary" type="button" data-submit><?php p($l->t('Add work location')); ?></button>
	</div>

	<h3><?php p($l->t('Available work locations')); ?></h3>
	<p id="work-location-list-status" role="status" aria-live="polite"></p>
	<ul id="work-location-list" class="azc-list" aria-label="<?php p($l->t('Available work locations')); ?>"></ul>

	<div id="work-location-policy-form" class="form" role="group" aria-labelledby="work-location-policy-heading">
		<h3 id="work-location-policy-heading"><?php p($l->t('Effective default policy')); ?></h3>
		<p class="form-help"><?php p($l->t('A policy sets the default location for a scope. Exact day overrides and manual entry selections still win.')); ?></p>
		<div class="form-group">
			<label for="work-location-policy-scope"><?php p($l->t('Applies to')); ?></label>
			<select id="work-location-policy-scope" data-field="scopeType" required aria-describedby="work-location-policy-scopeType-error">
				<option value="org"><?php p($l->t('Whole organization')); ?></option>
				<option value="model"><?php p($l->t('Working-time model')); ?></option>
				<option value="team"><?php p($l->t('Team')); ?></option>
				<option value="user"><?php p($l->t('Person')); ?></option>
			</select>
			<p id="work-location-policy-scopeType-error" class="azc-field-error" hidden></p>
		</div>
		<div class="form-group" id="work-location-policy-target-group" hidden>
			<label for="work-location-policy-target"><?php p($l->t('Policy target')); ?></label>
			<select id="work-location-policy-target" data-field="scopeId" aria-describedby="work-location-policy-scopeId-error"></select>
			<div id="work-location-policy-user-wrap" class="user-picker" hidden>
				<input type="hidden" id="work-location-policy-user-id">
				<label class="form-label" for="work-location-policy-user-search"><?php p($l->t('Choose a person')); ?></label>
				<input id="work-location-policy-user-search" class="user-picker__input" autocomplete="off" aria-autocomplete="list">
				<div id="work-location-policy-user-list" class="user-picker__list" hidden></div>
				<p id="work-location-policy-user-status" class="form-help" role="status" aria-live="polite"></p>
			</div>
			<p id="work-location-policy-scopeId-error" class="azc-field-error" hidden></p>
		</div>
		<div class="form-group">
			<label for="work-location-policy-location"><?php p($l->t('Default work location')); ?></label>
			<select id="work-location-policy-location" data-field="locationCode" required aria-describedby="work-location-policy-locationCode-error"></select>
			<p id="work-location-policy-locationCode-error" class="azc-field-error" hidden></p>
		</div>
		<div class="form-group">
			<label for="work-location-policy-from"><?php p($l->t('Valid from')); ?></label>
			<input id="work-location-policy-from" data-field="effectiveFrom" type="date" required aria-describedby="work-location-policy-effectiveFrom-error">
			<p id="work-location-policy-effectiveFrom-error" class="azc-field-error" hidden></p>
		</div>
		<div class="form-group">
			<label for="work-location-policy-to"><?php p($l->t('Valid until')); ?></label>
			<input id="work-location-policy-to" data-field="effectiveTo" type="date" aria-describedby="work-location-policy-effectiveTo-error">
			<p class="form-help"><?php p($l->t('Leave empty for an open-ended policy.')); ?></p>
			<p id="work-location-policy-effectiveTo-error" class="azc-field-error" hidden></p>
		</div>
		<div class="form-group">
			<label for="work-location-policy-priority"><?php p($l->t('Priority')); ?></label>
			<input id="work-location-policy-priority" data-field="priority" type="number" min="-1000" max="1000" value="0" aria-describedby="work-location-policy-priority-error">
			<p id="work-location-policy-priority-error" class="azc-field-error" hidden></p>
		</div>
		<button class="azc-btn azc-btn--primary" type="button" data-submit><?php p($l->t('Create policy')); ?></button>
	</div>

	<h3><?php p($l->t('Existing default policies')); ?></h3>
	<p id="work-location-policy-status" role="status" aria-live="polite"></p>
	<ul id="work-location-policy-list" class="azc-list" aria-label="<?php p($l->t('Existing default policies')); ?>"></ul>
</section>
