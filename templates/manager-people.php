<?php

declare(strict_types=1);

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
$isAdminScope = !empty($_['isAdminScope']);
$currentYear = (int)(new \DateTime())->format('Y');
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack">
	<div class="manager-scope-page manager-scope-page--people">

		<?php if (!empty($_['error'])): ?>
		<div class="azc-callout azc-callout--danger" role="alert">
			<span class="azc-callout__text"><?php p($_['error']); ?></span>
		</div>
		<?php endif; ?>

		<section class="azc-card manager-scope-page__results" aria-labelledby="manager-people-results-title" aria-busy="false">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="manager-people-results-title" class="azc-card__title"><?php p($l->t('People overview')); ?></h2>
					<p class="azc-card__lead">
						<?php if ($isAdminScope) {
							p($l->t('Vacation balances, overtime and open compliance issues for every employee in the organization.'));
						} else {
							p($l->t('Vacation balances, overtime and open compliance issues for the employees in your team.'));
						} ?>
					</p>
				</div>
				<p id="manager-people-count" class="azc-badge azc-badge--neutral" role="status" aria-live="polite"></p>
			</header>
			<div class="azc-card__body">
				<div class="manager-scope-page__toolbar" role="search" aria-label="<?php p($l->t('Filter people overview')); ?>">
					<form
						id="manager-people-filter-form"
						class="manager-scope-page__filter-form"
						novalidate
						aria-describedby="manager-people-filter-error"
					>
						<div class="manager-scope-filter" role="group" aria-label="<?php p($l->t('Filter options')); ?>">
							<div class="azc-filter-field azc-filter-field--employee">
								<label for="manager-people-employee-filter-search" class="azc-filter-field__label"><?php p($l->t('Employee')); ?></label>
								<div class="azc-filter-field__control">
									<?php
									$scopePickerId = 'manager-people-employee-filter';
									$scopePickerName = 'employee_id';
									$scopePickerAllowAll = true;
									$scopePickerRequired = false;
									$scopePickerCompact = true;
									include __DIR__ . '/common/scoped-employee-picker-field.php';
									?>
								</div>
							</div>

							<div class="azc-filter-field azc-filter-field--year">
								<label for="manager-people-year" class="azc-filter-field__label"><?php p($l->t('Vacation year')); ?></label>
								<div class="azc-filter-field__control">
									<input
										id="manager-people-year"
										name="year"
										type="number"
										class="form-input"
										min="1970"
										max="2100"
										step="1"
										inputmode="numeric"
										value="<?php p($currentYear); ?>"
										aria-label="<?php p($l->t('Vacation year')); ?>"
									/>
								</div>
								<p class="form-help"><?php p($l->t('The year the balances are calculated for.')); ?></p>
							</div>

							<div class="azc-filter-field azc-filter-field--actions">
								<span class="azc-filter-field__label visually-hidden"><?php p($l->t('Actions')); ?></span>
								<div class="azc-filter-field__control azc-filter-actions">
									<button type="submit" class="azc-btn azc-btn--primary" id="manager-people-submit">
										<?php p($l->t('Show')); ?>
									</button>
									<button type="button" id="manager-people-clear" class="azc-btn azc-btn--secondary">
										<?php p($l->t('Reset')); ?>
									</button>
								</div>
							</div>
						</div>

						<p id="manager-people-filter-error" class="azc-callout azc-callout--danger manager-scope-page__toolbar-feedback" role="alert" hidden>
							<span class="azc-callout__text"></span>
						</p>
					</form>
					<p id="manager-people-filter-help" class="manager-scope-page__toolbar-footnote" role="note">
						<?php p($l->t('Leave employee empty to see everyone in your scope, or search and select one person.')); ?>
					</p>
				</div>

				<div id="manager-people-empty" class="azc-empty-state">
					<h3 class="azc-empty-state__title"><?php p($l->t('Loading…')); ?></h3>
					<p class="azc-empty-state__text"><?php p($l->t('Please wait while the people overview loads.')); ?></p>
				</div>

				<div id="manager-people-table-wrap" class="table-container visually-hidden" aria-live="polite">
					<table class="table table--hover azc-table--responsive manager-scope-page__results-table" aria-label="<?php p($l->t('People overview')); ?>">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('Employee')); ?></th>
								<th scope="col"><?php p($l->t('Vacation remaining')); ?></th>
								<th scope="col"><?php p($l->t('Approved upcoming')); ?></th>
								<th scope="col"><?php p($l->t('Overtime')); ?></th>
								<th scope="col"><?php p($l->t('Open issues')); ?></th>
								<th scope="col"><?php p($l->t('Pending requests')); ?></th>
								<th scope="col" class="actions-cell"><?php p($l->t('Details')); ?></th>
							</tr>
						</thead>
						<tbody id="manager-people-body"></tbody>
					</table>
				</div>

				<nav class="manager-scope-page__pagination" aria-label="<?php p($l->t('Results pagination')); ?>">
					<button type="button" id="manager-people-prev" class="azc-btn azc-btn--secondary" disabled><?php p($l->t('Previous')); ?></button>
					<span id="manager-people-page-indicator" class="manager-scope-page__pagination-info"></span>
					<button type="button" id="manager-people-next" class="azc-btn azc-btn--secondary" disabled><?php p($l->t('Next')); ?></button>
				</nav>
			</div>
		</section>

	</div>
</div>

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.peopleOverview = {
	apiUrl: <?php echo json_encode((string)($_['peopleOverviewUrl'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
	employeeAbsencesUrl: <?php echo json_encode((string)($_['employeeAbsencesUrl'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
	violationsUrl: <?php echo json_encode((string)($_['violationsUrl'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
	adminUserUrl: <?php echo json_encode((string)($_['adminUserUrl'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
	isAdminScope: <?php echo $isAdminScope ? 'true' : 'false'; ?>,
	vacationUnit: <?php echo json_encode((string)($_['vacationUnit'] ?? 'days'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
	vacationHoursPerDay: <?php echo json_encode((float)($_['vacationHoursPerDay'] ?? 8.0)); ?>,
};
</script>

<?php include __DIR__ . '/common/manager-people-l10n.php'; ?>
<?php include __DIR__ . '/common/page-end.php'; ?>
