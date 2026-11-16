<?php

declare(strict_types=1);

/** @var \OCP\IL10N $l */
$azcSettingsShowCardChrome = !empty($azcSettingsShowCardChrome) || !empty($renderAll);
?>
<section class="azc-card admin-settings-section azc-work-location-admin" aria-labelledby="section-work-locations-heading"
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
	<header class="azc-card__header<?php echo empty($azcSettingsShowCardChrome) ? ' azc-card__header--page-title-only' : ''; ?>">
		<div class="azc-card__header-text">
			<?php if (!empty($azcSettingsShowCardChrome)): ?>
			<h2 id="section-work-locations-heading" class="azc-card__title"><?php p($l->t('Work locations')); ?></h2>
			<p class="azc-card__lead"><?php p($l->t('Manage the locations employees can select. Locations describe where work happened and never create working time.')); ?></p>
			<?php else: ?>
			<h2 id="section-work-locations-heading" class="azc-card__title visually-hidden"><?php p($l->t('Work locations')); ?></h2>
			<?php endif; ?>
		</div>
	</header>
	<div class="azc-card__body">
		<div id="work-location-rollout" class="azc-callout azc-callout--info">
			<div class="azc-callout__body">
				<p id="work-location-rollout-status" class="azc-callout__text" role="status" aria-live="polite"><?php p($l->t('Checking migration readiness…')); ?></p>
				<div class="form-checkbox">
					<input id="work-location-rollback-ack" type="checkbox">
					<label for="work-location-rollback-ack" class="form-label"><?php p($l->t('I understand that older app versions cannot write custom location codes.')); ?></label>
				</div>
				<div class="card-actions card-actions--inline">
					<button id="work-location-enable" class="azc-btn azc-btn--primary" type="button"><?php p($l->t('Enable work-location catalog')); ?></button>
					<button id="work-location-disable-legacy" class="azc-btn azc-btn--secondary" type="button"><?php p($l->t('Stop new home-office absence requests')); ?></button>
				</div>
			</div>
		</div>

		<h3 class="admin-settings-subsection__title"><?php p($l->t('Available work locations')); ?></h3>
		<p id="work-location-list-status" class="form-help" role="status" aria-live="polite"></p>
		<ul id="work-location-list" class="azc-list" aria-label="<?php p($l->t('Available work locations')); ?>"></ul>

		<fieldset class="form-fieldset" id="work-location-create-form" aria-labelledby="work-location-create-heading">
			<legend id="work-location-create-heading" class="form-legend"><?php p($l->t('Add a work location')); ?></legend>
			<div class="azc-wl-fields">
				<div class="form-group">
					<label for="work-location-label" class="form-label"><?php p($l->t('Label')); ?></label>
					<input id="work-location-label" class="form-input" type="text" data-field="label" maxlength="80" required aria-describedby="work-location-label-error">
					<p id="work-location-label-error" class="azc-field-error" hidden></p>
				</div>
				<div class="form-group">
					<label for="work-location-code" class="form-label"><?php p($l->t('Code')); ?></label>
					<input id="work-location-code" class="form-input" type="text" data-field="code" maxlength="57" pattern="[a-z0-9_]+" required aria-describedby="work-location-code-help work-location-code-error">
					<p id="work-location-code-help" class="form-help"><?php p($l->t('Lowercase letters, numbers, and underscores. The custom_ prefix is added automatically.')); ?></p>
					<p id="work-location-code-error" class="azc-field-error" hidden></p>
				</div>
				<div class="form-group">
					<label for="work-location-category" class="form-label"><?php p($l->t('Reporting category')); ?></label>
					<select id="work-location-category" class="form-select" data-field="category"><option value="office"><?php p($l->t('Office')); ?></option><option value="home"><?php p($l->t('Home office')); ?></option><option value="external"><?php p($l->t('External appointment')); ?></option><option value="other"><?php p($l->t('Other')); ?></option></select>
				</div>
			</div>
			<div class="card-actions card-actions--inline">
				<button class="azc-btn azc-btn--primary" type="button" data-submit><?php p($l->t('Add work location')); ?></button>
			</div>
		</fieldset>

		<h3 class="admin-settings-subsection__title"><?php p($l->t('Existing default policies')); ?></h3>
		<p id="work-location-policy-status" class="form-help" role="status" aria-live="polite"></p>
		<ul id="work-location-policy-list" class="azc-list" aria-label="<?php p($l->t('Existing default policies')); ?>"></ul>

		<fieldset class="form-fieldset" id="work-location-policy-form" aria-labelledby="work-location-policy-heading">
			<legend id="work-location-policy-heading" class="form-legend"><?php p($l->t('Add default policy')); ?></legend>
			<p class="form-help form-help--block"><?php p($l->t('A policy sets the default location for a scope. Exact day overrides and manual entry selections still win.')); ?></p>
			<div class="azc-wl-fields">
				<div class="form-group">
					<label for="work-location-policy-scope" class="form-label"><?php p($l->t('Applies to')); ?></label>
					<select id="work-location-policy-scope" class="form-select" data-field="scopeType" required aria-describedby="work-location-policy-scopeType-error">
						<option value="org"><?php p($l->t('Whole organization')); ?></option>
						<option value="model"><?php p($l->t('Working-time model')); ?></option>
						<option value="team"><?php p($l->t('Team')); ?></option>
						<option value="user"><?php p($l->t('Person')); ?></option>
					</select>
					<p id="work-location-policy-scopeType-error" class="azc-field-error" hidden></p>
				</div>
				<div class="form-group" id="work-location-policy-target-group" hidden>
					<label for="work-location-policy-target" class="form-label"><?php p($l->t('Policy target')); ?></label>
					<select id="work-location-policy-target" class="form-select" data-field="scopeId" aria-describedby="work-location-policy-scopeId-error"></select>
					<div id="work-location-policy-user-wrap" class="user-picker" hidden>
						<input type="hidden" id="work-location-policy-user-id">
						<label class="form-label" for="work-location-policy-user-search"><?php p($l->t('Choose a person')); ?></label>
						<div class="user-picker__control">
							<input id="work-location-policy-user-search" class="form-input user-picker__search" type="search" autocomplete="off" aria-autocomplete="list" role="combobox" aria-expanded="false" aria-controls="work-location-policy-user-list">
						</div>
						<div id="work-location-policy-user-list" class="user-picker__list" hidden></div>
						<p id="work-location-policy-user-status" class="form-help" role="status" aria-live="polite"></p>
					</div>
					<p id="work-location-policy-scopeId-error" class="azc-field-error" hidden></p>
				</div>
				<div class="form-group">
					<label for="work-location-policy-location" class="form-label"><?php p($l->t('Default work location')); ?></label>
					<select id="work-location-policy-location" class="form-select" data-field="locationCode" required aria-describedby="work-location-policy-locationCode-error"></select>
					<p id="work-location-policy-locationCode-error" class="azc-field-error" hidden></p>
				</div>
				<div class="form-group">
					<label for="work-location-policy-from" class="form-label"><?php p($l->t('Valid from')); ?></label>
					<input id="work-location-policy-from" class="form-input" data-field="effectiveFrom" type="date" required aria-describedby="work-location-policy-effectiveFrom-error">
					<p id="work-location-policy-effectiveFrom-error" class="azc-field-error" hidden></p>
				</div>
				<div class="form-group">
					<label for="work-location-policy-to" class="form-label"><?php p($l->t('Valid until')); ?></label>
					<input id="work-location-policy-to" class="form-input" data-field="effectiveTo" type="date" aria-describedby="work-location-policy-effectiveTo-error">
					<p class="form-help"><?php p($l->t('Leave empty for an open-ended policy.')); ?></p>
					<p id="work-location-policy-effectiveTo-error" class="azc-field-error" hidden></p>
				</div>
				<div class="form-group">
					<label for="work-location-policy-priority" class="form-label"><?php p($l->t('Priority')); ?></label>
					<input id="work-location-policy-priority" class="form-input" data-field="priority" type="number" min="-1000" max="1000" value="0" aria-describedby="work-location-policy-priority-error">
					<p id="work-location-policy-priority-error" class="azc-field-error" hidden></p>
				</div>
			</div>
			<div class="card-actions card-actions--inline">
				<button class="azc-btn azc-btn--primary" type="button" data-submit><?php p($l->t('Create policy')); ?></button>
			</div>
		</fieldset>
	</div>
</section>
