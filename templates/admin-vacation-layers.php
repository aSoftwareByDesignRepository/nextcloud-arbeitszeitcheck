<?php

declare(strict_types=1);

/**
 * Admin · Vacation entitlement layers (L0 / L1 / L2 + simulator) — one job.
 *
 * Org vacation year/unit/carryover rules live on /admin/vacation-rules.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule
 * @license AGPL-3.0-or-later
 */

/** @var array<string, mixed> $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$layeredEnabled = (bool)($_['layeredEnabled'] ?? true);
$vacationUnit = (string)($_['vacationUnit'] ?? 'days');
$isVacationHours = $vacationUnit === 'hours';
$vacationHoursPerDay = (float)($_['vacationHoursPerDay'] ?? 8);
$policyPages = is_array($_['policyPages'] ?? null) ? $_['policyPages'] : [];
$employeesAdminUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.admin.users');
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack">
	<script type="application/json" nonce="<?php p($_['cspNonce'] ?? ''); ?>" id="vacation-layers-bootstrap">
<?php
echo json_encode([
	'urls' => [
		'overview' => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.getVacationLayers'),
		'org' => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.saveOrgVacationDefault'),
		'orgDelete' => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.deleteOrgVacationDefault', ['id' => 0]),
		'model' => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.saveModelVacationDefault'),
		'modelDelete' => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.deleteModelVacationDefault', ['id' => 0]),
		'team' => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.saveTeamVacationPolicy'),
		'teamDelete' => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.deleteTeamVacationPolicy', ['id' => 0]),
		'simulate' => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.simulateVacationPolicy'),
		'userSearch' => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.getUsers'),
		'impact' => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.previewVacationLayerImpact'),
	],
	'layeredEnabled' => $layeredEnabled,
	'vacationUnit' => $isVacationHours ? 'hours' : 'days',
	'vacationHoursPerDay' => $vacationHoursPerDay,
	'amountMax' => 366,
	'amountUnitLabel' => $l->t('days'),
	'amountPerYearLabel' => $l->t('days per year'),
	'adminInputAlwaysDays' => true,
	'hoursModeHint' => $isVacationHours
		? $l->t('You always enter vacation in days (e.g. 25). We convert to hours automatically using %s hours per day.', [(string)$vacationHoursPerDay])
		: '',
], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
	</script>

	<div class="azc-admin-policy-layout azc-admin-vacation-policy-layout">
		<?php include __DIR__ . '/common/azc-policy-pages-nav.php'; ?>

	<div class="admin-vacation-layers">

		<section class="azc-card admin-vacation-layers__intro" aria-labelledby="vacation-layers-intro-title">
			<header class="azc-card__header azc-card__header--page-title-only">
				<div class="azc-card__header-text">
					<h2 id="vacation-layers-intro-title" class="azc-card__title visually-hidden"><?php p($l->t('How vacation entitlement is resolved')); ?></h2>
					<p class="azc-card__lead">
						<?php if ($isVacationHours): ?>
							<?php p($l->t('Enter annual vacation in days — the same numbers you already know (e.g. 25). Booking and remaining balances use hours; we convert using your hours-per-day factor. A higher layer always wins.')); ?>
						<?php else: ?>
							<?php p($l->t('Define how many vacation days people get. A higher layer always wins. Edit organisation, model, and team defaults here; simulate any person.')); ?>
						<?php endif; ?>
					</p>
					<p class="form-help form-help--block" id="vacation-layers-l3-help">
						<?php p($l->t('Individual (L3) overrides are set on each person under Employees.')); ?>
						<a href="<?php p($employeesAdminUrl); ?>"><?php p($l->t('Open Employees')); ?></a>
					</p>
				</div>
				<div class="azc-card__header-actions admin-vacation-layers__status">
					<?php if ($layeredEnabled): ?>
						<span class="azc-badge azc-badge--success" aria-label="<?php p($l->t('Layered resolution is currently active')); ?>"><?php p($l->t('Layered resolution active')); ?></span>
					<?php else: ?>
						<span class="azc-badge azc-badge--warning" aria-label="<?php p($l->t('Layered resolution is disabled — only individual L3 rules and the legacy fallback are evaluated')); ?>"><?php p($l->t('Layered resolution disabled')); ?></span>
					<?php endif; ?>
				</div>
			</header>
			<div class="azc-card__body">
				<details class="azc-settings-more" id="vacation-layers-precedence-more">
					<summary><?php p($l->t('Which layer wins?')); ?></summary>
					<nav class="vacation-layers__stepper" aria-label="<?php p($l->t('Layer precedence overview')); ?>">
						<ol class="stepper">
							<li class="stepper__item stepper__item--l3"><span class="stepper__rank">1</span><span class="stepper__label"><?php p($l->t('L3 · Individual')); ?></span></li>
							<li class="stepper__item stepper__item--l2"><span class="stepper__rank">2</span><span class="stepper__label"><?php p($l->t('L2 · Team / Cohort')); ?></span></li>
							<li class="stepper__item stepper__item--l1"><span class="stepper__rank">3</span><span class="stepper__label"><?php p($l->t('L1 · Working time model')); ?></span></li>
							<li class="stepper__item stepper__item--l0"><span class="stepper__rank">4</span><span class="stepper__label"><?php p($l->t('L0 · Organisation default')); ?></span></li>
							<li class="stepper__item stepper__item--legacy"><span class="stepper__rank">5</span><span class="stepper__label"><?php p($l->t('Legacy fallback (25 d.)')); ?></span></li>
						</ol>
					</nav>
				</details>
			</div>
		</section>

		<section class="azc-card layer-card layer-card--l0" id="layer-l0" aria-labelledby="layer-l0-heading">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="layer-l0-heading" class="azc-card__title">
						<span class="layer-card__chip" aria-hidden="true">L0</span>
						<?php p($l->t('Organisation default')); ?>
						<span class="layer-card__count" id="l0-count" data-count="0" aria-live="polite"></span>
					</h2>
					<p class="azc-card__lead">
						<?php p($l->t('Used when no team policy, model default, or individual rule applies. This is the safety net for new employees.')); ?>
					</p>
				</div>
				<div class="azc-card__header-actions">
					<button type="button" class="azc-btn azc-btn--primary" data-action="add-org"
						aria-label="<?php p($l->t('Add or supersede the organisation default')); ?>">
						<?php p($l->t('Add organisation default')); ?>
					</button>
				</div>
			</header>
			<div class="azc-card__body">
				<div id="l0-active" class="layer-card__active" aria-live="polite">
					<p class="layer-card__placeholder"><?php p($l->t('Loading…')); ?></p>
				</div>
				<details class="layer-card__history">
					<summary><?php p($l->t('Show full history')); ?></summary>
					<div class="table-container layer-card__history-scroll" tabindex="0" role="region" aria-label="<?php p($l->t('Organisation default history')); ?>">
						<table class="table table--hover azc-table--responsive layer-card__history-table">
							<thead>
								<tr>
									<th scope="col"><?php p($l->t('Effective')); ?></th>
									<th scope="col"><?php p($l->t('Mode')); ?></th>
									<th scope="col"><?php p($l->t('Days')); ?></th>
									<th scope="col"><?php p($l->t('Tariff rule set')); ?></th>
									<th scope="col"><?php p($l->t('Description')); ?></th>
									<th scope="col" class="layer-card__history-actions"><?php p($l->t('Actions')); ?></th>
								</tr>
							</thead>
							<tbody id="l0-history-rows">
								<tr><td colspan="6" class="layer-card__placeholder"><?php p($l->t('Loading…')); ?></td></tr>
							</tbody>
						</table>
					</div>
				</details>
			</div>
		</section>

		<section class="azc-card layer-card layer-card--l1" id="layer-l1" aria-labelledby="layer-l1-heading">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="layer-l1-heading" class="azc-card__title">
						<span class="layer-card__chip" aria-hidden="true">L1</span>
						<?php p($l->t('Working time model defaults')); ?>
						<span class="layer-card__count" id="l1-count" data-count="0" aria-live="polite"></span>
					</h2>
					<p class="azc-card__lead">
						<?php p($l->t('Defaults attached to a working time model (e.g. “30 hours / week”). Apply automatically to every employee with that model who has no individual or team rule.')); ?>
					</p>
					<p id="l1-prereq" class="layer-card__prereq azc-callout azc-callout--warning" role="status" hidden></p>
				</div>
				<div class="azc-card__header-actions">
					<button type="button" class="azc-btn azc-btn--primary" data-action="add-model"
						aria-label="<?php p($l->t('Add a default for a working time model')); ?>">
						<?php p($l->t('Add model default')); ?>
					</button>
				</div>
			</header>
			<div class="azc-card__body">
				<div class="table-container layer-card__history-scroll" tabindex="0" role="region" aria-label="<?php p($l->t('Working time model defaults')); ?>">
					<table class="table table--hover azc-table--responsive layer-card__history-table">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('Model')); ?></th>
								<th scope="col"><?php p($l->t('Effective')); ?></th>
								<th scope="col"><?php p($l->t('Mode')); ?></th>
								<th scope="col"><?php p($l->t('Days')); ?></th>
								<th scope="col"><?php p($l->t('Tariff rule set')); ?></th>
								<th scope="col"><?php p($l->t('Description')); ?></th>
								<th scope="col" class="layer-card__history-actions"><?php p($l->t('Actions')); ?></th>
							</tr>
						</thead>
						<tbody id="l1-rows">
							<tr><td colspan="7" class="layer-card__placeholder"><?php p($l->t('Loading…')); ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>
		</section>

		<section class="azc-card layer-card layer-card--l2" id="layer-l2" aria-labelledby="layer-l2-heading">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="layer-l2-heading" class="azc-card__title">
						<span class="layer-card__chip" aria-hidden="true">L2</span>
						<?php p($l->t('Team / cohort policies')); ?>
						<span class="layer-card__count" id="l2-count" data-count="0" aria-live="polite"></span>
					</h2>
					<p class="azc-card__lead">
						<?php p($l->t('Policies attached to a team. When an employee belongs to several teams, the policy attached to the deepest team in the hierarchy wins; ties are broken by the higher priority, then by the smallest team ID.')); ?>
					</p>
					<p id="l2-prereq" class="layer-card__prereq azc-callout azc-callout--warning" role="status" hidden></p>
				</div>
				<div class="azc-card__header-actions">
					<button type="button" class="azc-btn azc-btn--primary" data-action="add-team"
						aria-label="<?php p($l->t('Add a team vacation policy')); ?>">
						<?php p($l->t('Add team policy')); ?>
					</button>
				</div>
			</header>
			<div class="azc-card__body">
				<div class="table-container layer-card__history-scroll" tabindex="0" role="region" aria-label="<?php p($l->t('Team vacation policies')); ?>">
					<table class="table table--hover azc-table--responsive layer-card__history-table">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('Team')); ?></th>
								<th scope="col"><?php p($l->t('Effective')); ?></th>
								<th scope="col"><?php p($l->t('Mode')); ?></th>
								<th scope="col"><?php p($l->t('Days')); ?></th>
								<th scope="col"><?php p($l->t('Priority')); ?></th>
								<th scope="col"><?php p($l->t('Description')); ?></th>
								<th scope="col" class="layer-card__history-actions"><?php p($l->t('Actions')); ?></th>
							</tr>
						</thead>
						<tbody id="l2-rows">
							<tr><td colspan="7" class="layer-card__placeholder"><?php p($l->t('Loading…')); ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>
		</section>

		<section class="azc-card layer-card layer-card--sim" id="layer-sim" aria-labelledby="sim-heading">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="sim-heading" class="azc-card__title">
						<i data-lucide="flask-conical" class="lucide-icon" aria-hidden="true"></i>
						<?php p($l->t('Simulator')); ?>
					</h2>
					<p class="azc-card__lead">
						<?php if ($isVacationHours): ?>
							<?php p($l->t('Try out: how many vacation hours does an employee actually get on a given date? See exactly which layer was applied and why.')); ?>
						<?php else: ?>
							<?php p($l->t('Try out: how many vacation days does an employee actually get on a given date? See exactly which layer was applied and why.')); ?>
						<?php endif; ?>
					</p>
				</div>
			</header>
			<div class="azc-card__body">
				<form id="sim-form" class="layer-form admin-vacation-layers__sim-form" novalidate>
					<div class="layer-form__row">
						<label for="sim-user" class="azc-filter-field__label">
							<?php p($l->t('Employee')); ?>
							<span class="form-required" aria-hidden="true">*</span>
							<span class="visually-hidden">(<?php p($l->t('required')); ?>)</span>
						</label>
						<input type="search" id="sim-user" name="userId" class="form-input"
							placeholder="<?php p($l->t('Type to search for an employee')); ?>"
							autocomplete="off"
							role="combobox"
							aria-autocomplete="list"
							aria-controls="sim-user-suggest"
							aria-expanded="false"
							aria-haspopup="listbox"
							aria-required="true"
							aria-describedby="sim-user-help" required>
						<p id="sim-user-help" class="form-help"><?php p($l->t('Start typing the user name or login — suggestions will appear. Use the arrow keys to navigate, Enter to select.')); ?></p>
						<ul id="sim-user-suggest" class="form-suggest" role="listbox" aria-label="<?php p($l->t('Employee suggestions')); ?>" hidden></ul>
					</div>
					<div class="layer-form__row">
						<label for="sim-date" class="azc-filter-field__label"><?php p($l->t('As-of date')); ?></label>
						<input type="date" id="sim-date" name="asOfDate" class="form-input" value="<?php p(date('Y-m-d')); ?>">
						<p class="form-help"><?php p($l->t('Defaults to today. Pick a past or future date to see how the entitlement changes.')); ?></p>
					</div>
					<div class="layer-form__row layer-form__row--wide">
						<fieldset class="sim-hypothesis" aria-describedby="sim-hypothesis-help">
							<legend><?php p($l->t('What-if (optional)')); ?></legend>
							<p id="sim-hypothesis-help" class="form-help">
								<?php p($l->t('Pretend the employee is a member of one or more teams to see how their entitlement would change. Real team memberships are not modified.')); ?>
							</p>
							<label class="azc-filter-field__label" for="sim-hypothetical-teams"><?php p($l->t('Hypothetical team membership')); ?></label>
							<select id="sim-hypothetical-teams" name="hypotheticalTeamIds[]" multiple size="4" class="form-select form-select--multi" aria-describedby="sim-hypothetical-teams-help"></select>
							<p id="sim-hypothetical-teams-help" class="form-help">
								<?php p($l->t('Hold Ctrl/Cmd or Shift to pick several teams. Leave empty to use the employee’s real membership.')); ?>
							</p>
							<div class="sim-hypothesis__actions">
								<button type="button" class="azc-btn azc-btn--secondary azc-btn--sm" id="sim-hypothetical-clear">
									<?php p($l->t('Clear hypothetical selection')); ?>
								</button>
							</div>
						</fieldset>
					</div>
					<div class="layer-form__row layer-form__row--actions admin-vacation-layers__sim-actions">
						<button type="submit" class="azc-btn azc-btn--primary"><?php p($l->t('Run simulation')); ?></button>
						<button type="reset" id="sim-reset" class="azc-btn azc-btn--secondary"><?php p($l->t('Reset')); ?></button>
					</div>
				</form>
				<div id="sim-result" class="layer-sim__result" role="region" aria-live="polite" aria-labelledby="sim-heading"></div>
			</div>
		</section>

	</div>
	</div>
</div>

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.adminPolicyPages = <?php echo json_encode($policyPages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<dialog id="layer-dialog" class="layer-dialog azc-native-dialog"
	aria-modal="true"
	aria-labelledby="layer-dialog-title"
	aria-describedby="layer-dialog-intro">
	<form id="layer-dialog-form" class="layer-dialog__form" novalidate>
		<h2 id="layer-dialog-title" class="layer-dialog__title"></h2>
		<p id="layer-dialog-intro" class="layer-dialog__intro"></p>
		<div id="layer-dialog-body" class="layer-dialog__body"></div>
		<div id="layer-dialog-impact" class="layer-dialog__impact" role="status" aria-live="polite" hidden>
			<span class="layer-dialog__impact-icon" aria-hidden="true">
				<i data-lucide="users" class="lucide-icon"></i>
			</span>
			<span class="layer-dialog__impact-text" id="layer-dialog-impact-text"></span>
		</div>
		<p id="layer-dialog-feedback" class="layer-dialog__feedback" role="alert" aria-live="assertive"></p>
		<div class="layer-dialog__actions">
			<button type="button" id="layer-dialog-cancel" class="azc-btn azc-btn--secondary"><?php p($l->t('Cancel')); ?></button>
			<button type="submit" id="layer-dialog-save" class="azc-btn azc-btn--primary"><?php p($l->t('Save')); ?></button>
		</div>
	</form>
</dialog>

<div role="status" aria-live="polite" id="vacation-layers-status" class="visually-hidden"></div>

<?php include __DIR__ . '/common/page-end.php'; ?>
