<?php

declare(strict_types=1);

/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
$enabled = !empty($_['specialEntitlementsEnabled']);
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack azc-se-admin"
	id="se-admin-root"
	data-enabled="<?php p($enabled ? '1' : '0'); ?>"
	data-types-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.special_entitlement_admin.types')); ?>"
	data-type-create-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.special_entitlement_admin.createType')); ?>"
	data-type-show-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.special_entitlement_admin.showType', 'typeId')); ?>"
	data-type-update-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.special_entitlement_admin.updateType', 'typeId')); ?>"
	data-type-active-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.special_entitlement_admin.setTypeActive', 'typeId')); ?>"
	data-policies-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.special_entitlement_admin.policies', 'typeId')); ?>"
	data-policy-create-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.special_entitlement_admin.createPolicyFlat')); ?>"
	data-policy-update-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.special_entitlement_admin.updatePolicy', 'policyId')); ?>"
	data-preview-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.special_entitlement_admin.previewPolicy')); ?>"
	data-trace-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.special_entitlement_admin.trace', 'typeId')); ?>"
	data-cohorts-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.special_entitlement_admin.cohorts')); ?>"
	data-cohort-create-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.special_entitlement_admin.createCohort')); ?>"
	data-cohort-members-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.special_entitlement_admin.cohortMembers', 'cohortId')); ?>"
	data-cohort-membership-template="<?php p(\OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($urlGenerator, 'arbeitszeitcheck.special_entitlement_admin.endCohortMember', 'membershipId')); ?>"
	data-users-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.getUsers')); ?>"
	data-teams-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.getTeams')); ?>"
	data-models-url="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.getWorkingTimeModels')); ?>">

	<?php if (!$enabled): ?>
	<div class="azc-callout azc-callout--info" role="status">
		<div class="azc-callout__body">
			<p class="azc-callout__title"><?php p($l->t('Special leave entitlements are not enabled')); ?></p>
			<p class="azc-callout__text"><?php p($l->t('The feature was added by an upgrade and stays inactive until an administrator enables it. Existing data is unchanged.')); ?></p>
		</div>
	</div>
	<?php endif; ?>

	<section class="azc-card" aria-labelledby="se-types-title" <?php echo $enabled ? '' : 'hidden'; ?>>
		<header class="azc-card__header azc-card__header--row">
			<div class="azc-card__header-text">
				<h2 id="se-types-title" class="azc-card__title"><?php p($l->t('Special leave entitlements')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Here you define additional leave entitlements and their quotas.')); ?></p>
			</div>
			<button id="se-new-type" class="azc-btn azc-btn--primary" type="button"><?php p($l->t('Create special leave entitlement')); ?></button>
		</header>
		<div class="azc-card__body">
			<div class="azc-filter-grid se-toolbar" role="search">
				<div class="azc-filter-field">
					<label for="se-search" class="azc-filter-field__label"><?php p($l->t('Search')); ?></label>
					<div class="azc-filter-field__control">
						<input type="search" id="se-search" class="form-input" autocomplete="off">
					</div>
				</div>
				<div class="azc-filter-field">
					<label for="se-filter-active" class="azc-filter-field__label"><?php p($l->t('Status')); ?></label>
					<div class="azc-filter-field__control">
						<select id="se-filter-active" class="form-select">
							<option value="all"><?php p($l->t('All')); ?></option>
							<option value="active" selected><?php p($l->t('Active')); ?></option>
							<option value="inactive"><?php p($l->t('Inactive')); ?></option>
						</select>
					</div>
				</div>
			</div>
			<p id="se-list-status" role="status" aria-live="polite" class="form-help"></p>
			<ul id="se-type-list" class="azc-list se-type-list" aria-label="<?php p($l->t('Special leave entitlements')); ?>"></ul>
			<p id="se-empty" class="azc-empty-state" hidden><?php p($l->t('No additional leave accounts exist.')); ?></p>
		</div>
	</section>

	<section class="azc-card" id="se-editor" aria-labelledby="se-editor-title" hidden>
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="se-editor-title" class="azc-card__title"><?php p($l->t('New special leave entitlement')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Four decisions: name, amount, recipients, start date. The linked absence type is created automatically.')); ?></p>
			</div>
		</header>
		<div class="azc-card__body">
			<div class="azc-callout azc-callout--danger" role="alert" id="se-form-error" hidden>
				<p id="se-form-error-text" class="azc-callout__text"></p>
			</div>
			<form id="se-create-form" class="form" novalidate>
				<input type="hidden" id="se-type-version" name="version" value="">
				<fieldset class="absence-form-fieldset">
					<legend class="absence-form-fieldset__legend"><?php p($l->t('Definition')); ?></legend>
					<div class="form-group">
						<label for="se-label" class="form-label"><?php p($l->t('Name')); ?> <span class="form-required" aria-hidden="true">*</span></label>
						<input id="se-label" name="publicLabel" maxlength="80" required aria-describedby="se-label-error">
						<p id="se-label-error" class="azc-field-error" hidden></p>
					</div>
					<div class="form-grid">
						<div class="form-group">
							<label for="se-amount" class="form-label"><?php p($l->t('Amount')); ?> <span class="form-required" aria-hidden="true">*</span></label>
							<input id="se-amount" name="amount" type="number" min="0.25" max="4000" step="0.25" required aria-describedby="se-amount-error">
							<p id="se-amount-error" class="azc-field-error" hidden></p>
						</div>
						<div class="form-group">
							<label for="se-unit" class="form-label"><?php p($l->t('Unit')); ?></label>
							<select id="se-unit" name="unit" aria-describedby="se-unit-error">
								<option value="days"><?php p($l->t('Days')); ?></option>
								<option value="hours"><?php p($l->t('Hours')); ?></option>
							</select>
							<p id="se-unit-error" class="azc-field-error" hidden></p>
						</div>
					</div>
					<div class="form-group">
						<label for="se-scope" class="form-label"><?php p($l->t('For whom?')); ?> <span class="form-required" aria-hidden="true">*</span></label>
						<select id="se-scope" name="scopeType" required aria-describedby="se-scopeType-error">
							<option value="org"><?php p($l->t('Everyone')); ?></option>
							<option value="policy_group"><?php p($l->t('Protected entitlement group')); ?></option>
							<option value="model"><?php p($l->t('Working-time model')); ?></option>
							<option value="team"><?php p($l->t('Team')); ?></option>
							<option value="user"><?php p($l->t('Single person')); ?></option>
						</select>
						<p id="se-scopeType-error" class="azc-field-error" hidden></p>
					</div>
					<div class="form-group" id="se-target-group" hidden>
						<label for="se-target" class="form-label"><?php p($l->t('Recipient')); ?></label>
						<select id="se-target" name="scopeId" aria-describedby="se-scopeId-error se-target-empty"></select>
						<p id="se-target-empty" class="azc-callout__text" role="status"></p>
						<div id="se-user-wrap" class="user-picker" hidden>
							<input type="hidden" id="se-user-id">
							<label class="form-label" for="se-user-search"><?php p($l->t('Choose a person')); ?></label>
							<input id="se-user-search" class="user-picker__input" autocomplete="off" aria-autocomplete="list">
							<div id="se-user-list" class="user-picker__list" hidden></div>
							<p id="se-user-status" class="form-help" role="status" aria-live="polite"></p>
						</div>
						<p id="se-scopeId-error" class="azc-field-error" hidden></p>
					</div>
					<div class="form-group" id="se-operation-group" hidden>
						<span class="form-label" id="se-operation-legend"><?php p($l->t('A lower-priority rule already applies. How should this rule apply?')); ?></span>
						<div role="radiogroup" aria-labelledby="se-operation-legend">
							<label><input type="radio" name="operation" value="add" checked> <?php p($l->t('Grant additionally')); ?></label>
							<label><input type="radio" name="operation" value="replace"> <?php p($l->t('Replace the previous value')); ?></label>
							<label><input type="radio" name="operation" value="disable"> <?php p($l->t('Disable entitlement for this selection')); ?></label>
						</div>
						<p id="se-operation-error" class="azc-field-error" hidden></p>
					</div>
					<div class="form-group">
						<label for="se-from" class="form-label"><?php p($l->t('Valid from')); ?> <span class="form-required" aria-hidden="true">*</span></label>
						<input id="se-from" name="effectiveFrom" type="date" required aria-describedby="se-from-help se-effectiveFrom-error">
						<p id="se-from-help" class="form-help"><?php p($l->t('Default is the next day that is not yet closed. Backdating requires an explicit choice.')); ?></p>
						<p id="se-effectiveFrom-error" class="azc-field-error" hidden></p>
					</div>
				</fieldset>

				<details id="se-advanced" class="se-advanced">
					<summary><?php p($l->t('More settings')); ?></summary>
					<div class="form-group">
						<label for="se-period" class="form-label"><?php p($l->t('Period')); ?></label>
						<select id="se-period" name="periodType" aria-describedby="se-periodType-error">
							<option value="calendar_year"><?php p($l->t('Calendar year')); ?></option>
							<option value="service_year"><?php p($l->t('Employment year')); ?></option>
							<option value="one_time"><?php p($l->t('One-time')); ?></option>
						</select>
						<p id="se-periodType-error" class="azc-field-error" hidden></p>
					</div>
					<div class="form-group">
						<label for="se-confidential" class="form-label"><?php p($l->t('Confidential label')); ?></label>
						<input id="se-confidential" name="confidentialLabel" maxlength="80" aria-describedby="se-confidential-help se-confidentialLabel-error">
						<p id="se-confidential-help" class="form-help"><?php p($l->t('Only app administrators see this label. Employees and managers always see the public name.')); ?></p>
						<p id="se-confidentialLabel-error" class="azc-field-error" hidden></p>
					</div>
					<div class="form-group">
						<label for="se-absence-type" class="form-label"><?php p($l->t('Linked absence type')); ?></label>
						<input id="se-absence-type" name="absenceTypeCode" maxlength="64" pattern="custom_[a-z0-9_]+" aria-describedby="se-absence-type-help se-absenceTypeCode-error">
						<p id="se-absence-type-help" class="form-help"><?php p($l->t('Leave empty to create a matching custom absence type automatically. Only existing active custom types can be linked.')); ?></p>
						<p id="se-absenceTypeCode-error" class="azc-field-error" hidden></p>
					</div>
					<div class="form-group">
						<label for="se-priority" class="form-label"><?php p($l->t('Priority')); ?></label>
						<input id="se-priority" name="priority" type="number" min="-1000" max="1000" value="0" aria-describedby="se-priority-error">
						<p id="se-priority-error" class="azc-field-error" hidden></p>
					</div>
					<div class="form-group">
						<label for="se-to" class="form-label"><?php p($l->t('Valid until')); ?></label>
						<input id="se-to" name="effectiveTo" type="date" aria-describedby="se-effectiveTo-error">
						<p id="se-effectiveTo-error" class="azc-field-error" hidden></p>
					</div>
					<div class="form-group">
						<label for="se-reason" class="form-label"><?php p($l->t('Legal or contract reference')); ?></label>
						<input id="se-reason" name="reason" maxlength="500" aria-describedby="se-reason-error">
						<p id="se-reason-error" class="azc-field-error" hidden></p>
					</div>
				</details>

				<div class="se-preview" id="se-preview" aria-live="polite">
					<p id="se-preview-text" class="se-preview__text"><?php p($l->t('The preview appears once name, amount, recipients, and start date are set.')); ?></p>
					<p id="se-preview-warn" class="azc-callout__text se-preview__warn" role="alert" hidden></p>
				</div>
				<div class="button-row">
					<button id="se-activate" class="azc-btn azc-btn--primary" type="submit" disabled><?php p($l->t('Activate rule')); ?></button>
					<button id="se-cancel" class="azc-btn azc-btn--secondary" type="button"><?php p($l->t('Cancel')); ?></button>
				</div>
			</form>
		</div>
	</section>

	<section class="azc-card" id="se-detail" aria-labelledby="se-detail-title" hidden>
		<header class="azc-card__header azc-card__header--row">
			<div class="azc-card__header-text">
				<h2 id="se-detail-title" class="azc-card__title"></h2>
				<p id="se-detail-meta" class="azc-card__lead"></p>
			</div>
			<div class="button-row">
				<button id="se-detail-add-policy" class="azc-btn azc-btn--primary" type="button"><?php p($l->t('Add rule')); ?></button>
				<button id="se-detail-close" class="azc-btn azc-btn--secondary" type="button"><?php p($l->t('Close')); ?></button>
			</div>
		</header>
		<div class="azc-card__body">
			<div class="azc-callout azc-callout--danger" id="se-conflict" role="alert" hidden>
				<div class="azc-callout__body">
					<p class="azc-callout__title"><?php p($l->t('Changed elsewhere')); ?></p>
					<p class="azc-callout__text" id="se-conflict-text"></p>
					<div class="button-row">
						<button id="se-conflict-reload" class="azc-btn azc-btn--secondary" type="button"><?php p($l->t('Load current data')); ?></button>
					</div>
				</div>
			</div>
			<h3><?php p($l->t('Rules')); ?></h3>
			<p id="se-policy-status" role="status" aria-live="polite" class="form-help"></p>
			<div id="se-policy-groups"></div>
			<details id="se-trace-wrap" class="se-advanced">
				<summary><?php p($l->t('Explain calculation')); ?></summary>
				<div class="form-grid">
					<div class="form-group">
						<label for="se-trace-user" class="form-label"><?php p($l->t('Person (user id)')); ?></label>
						<input id="se-trace-user" class="form-input" autocomplete="off">
					</div>
					<div class="form-group">
						<label for="se-trace-date" class="form-label"><?php p($l->t('As of date')); ?></label>
						<input id="se-trace-date" type="date" class="form-input">
					</div>
				</div>
				<button id="se-trace-run" class="azc-btn azc-btn--secondary" type="button"><?php p($l->t('Resolve')); ?></button>
				<pre id="se-trace-out" class="se-trace" tabindex="0" aria-live="polite"></pre>
			</details>
		</div>
	</section>

	<section class="azc-card" id="se-cohorts-card" aria-labelledby="se-cohorts-title" <?php echo $enabled ? '' : 'hidden'; ?>>
		<header class="azc-card__header">
			<div class="azc-card__header-text">
				<h2 id="se-cohorts-title" class="azc-card__title"><?php p($l->t('Protected entitlement groups')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Protected groups hold sensitive tariff cohorts. They are deliberately separate from operational teams and only visible here.')); ?></p>
			</div>
		</header>
		<div class="azc-card__body">
			<form id="se-cohort-form" class="form" novalidate>
				<div class="form-grid">
					<div class="form-group">
						<label for="se-cohort-label" class="form-label"><?php p($l->t('Group name')); ?> <span class="form-required" aria-hidden="true">*</span></label>
						<input id="se-cohort-label" name="label" maxlength="80" required aria-describedby="se-cohort-label-error">
						<p id="se-cohort-label-error" class="azc-field-error" hidden></p>
					</div>
					<div class="form-group">
						<label for="se-cohort-confidential" class="form-label"><?php p($l->t('Confidential label')); ?></label>
						<input id="se-cohort-confidential" name="confidentialLabel" maxlength="80">
					</div>
				</div>
				<button class="azc-btn azc-btn--primary" type="submit"><?php p($l->t('Create protected group')); ?></button>
			</form>
			<p id="se-cohort-status" role="status" aria-live="polite" class="form-help"></p>
			<ul id="se-cohort-list" class="azc-list" aria-label="<?php p($l->t('Protected entitlement groups')); ?>"></ul>
		</div>
	</section>
</div>

<?php include __DIR__ . '/common/page-end.php'; ?>
