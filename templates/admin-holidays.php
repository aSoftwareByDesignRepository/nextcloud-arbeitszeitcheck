<?php

declare(strict_types=1);

/**
 * Admin holidays template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2025
 * @license AGPL-3.0-or-later
 */

use OCA\ArbeitszeitCheck\Support\RegionRegistry;

/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);

$country = $_['country'] ?? RegionRegistry::COUNTRY_DE;
$defaultState = $_['defaultState'] ?? RegionRegistry::defaultRegionForCountry($country);
$statutoryAutoReseed = (bool)($_['statutoryAutoReseed'] ?? true);
$settingsUrl = $_['settingsUrl'] ?? '';
$calendarSubscriptionUrl = $_['calendarSubscriptionUrl'] ?? '';
$currentYear = (int)date('Y');

// Regions of the instance country (default-region select must not cross the
// border) and all regions grouped per country (calendar viewer may).
$instanceRegions = RegionRegistry::regionsForCountry($country);
$regionGroups = [];
foreach (RegionRegistry::supportedCountries() as $groupCountry) {
	$regionGroups[] = [
		'country' => $groupCountry,
		'countryLabel' => $l->t(RegionRegistry::countryLabels()[$groupCountry] ?? $groupCountry),
		'regions' => RegionRegistry::regionsForCountry($groupCountry),
	];
}

$countryCards = [
	RegionRegistry::COUNTRY_DE => [
		'title' => $l->t('Germany'),
		'text' => $l->t('Working time rules follow the German Working Time Act (ArbZG).'),
	],
	RegionRegistry::COUNTRY_AT => [
		'title' => $l->t('Austria'),
		'text' => $l->t('Working time rules follow the Austrian Working Time Act (AZG) and Rest Act (ARG).'),
	],
	RegionRegistry::COUNTRY_CH => [
		'title' => $l->t('Switzerland'),
		'text' => $l->t('Working time rules follow the Swiss Labour Act (ArG). Public holidays follow the selected canton.'),
	],
];

$regionData = ['defaultRegionByCountry' => [], 'regionsByCountry' => []];
foreach (RegionRegistry::supportedCountries() as $countryCode) {
	$regionData['defaultRegionByCountry'][$countryCode] =
		RegionRegistry::defaultRegionForCountry($countryCode);
	$regions = [];
	foreach (RegionRegistry::regionsForCountry($countryCode) as $regionCode => $regionMsgid) {
		$regions[] = ['code' => $regionCode, 'label' => $l->t($regionMsgid)];
	}
	$regionData['regionsByCountry'][$countryCode] = $regions;
}

$holidaysUiStrings = [
	'dd.mm.yyyy' => $l->t('dd.mm.yyyy'),
	'Full-day holiday' => $l->t('Full-day holiday'),
	'Half-day holiday' => $l->t('Half-day holiday'),
	'Company holiday' => $l->t('Company holiday'),
	'custom' => $l->t('custom'),
	'Statutory' => $l->t('Statutory'),
	'Save' => $l->t('Save'),
	'Remove' => $l->t('Remove'),
	'Technical error: Required fields for the holiday could not be found.' => $l->t('Technical error: Required fields for the holiday could not be found.'),
	'Please specify date and name of the holiday.' => $l->t('Please specify date and name of the holiday.'),
	'Holiday was saved.' => $l->t('Holiday was saved.'),
	'Holiday could not be saved.' => $l->t('Holiday could not be saved.'),
	'An error occurred while saving the holiday.' => $l->t('An error occurred while saving the holiday.'),
	'Holidays could not be loaded.' => $l->t('Holidays could not be loaded.'),
	'Remove holiday {name} on {date}' => $l->t('Remove holiday {name} on {date}'),
	'Remove holiday' => $l->t('Remove holiday'),
	'Do you really want to remove the holiday "{name}" on {date}?' => $l->t('Do you really want to remove the holiday "{name}" on {date}?'),
	'Removed statutory holidays are restored automatically while auto-restore is enabled in settings.' => $l->t('Removed statutory holidays are restored automatically while auto-restore is enabled in settings.'),
	'Statutory holiday removal is permanent because auto-restore is disabled in settings.' => $l->t('Statutory holiday removal is permanent because auto-restore is disabled in settings.'),
	'Auto-restore statutory holidays' => $l->t('Auto-restore statutory holidays'),
	'Cancel' => $l->t('Cancel'),
	'No holidays configured for this year.' => $l->t('No holidays configured for this year.'),
	'Holiday was removed.' => $l->t('Holiday was removed.'),
	'Statutory holiday removed. It will be added again automatically because auto-restore is enabled.' => $l->t('Statutory holiday removed. It will be added again automatically because auto-restore is enabled.'),
	'Holiday could not be removed.' => $l->t('Holiday could not be removed.'),
	'An error occurred while removing the holiday.' => $l->t('An error occurred while removing the holiday.'),
	'Default region was saved.' => $l->t('Default region was saved.'),
	'The default region could not be saved.' => $l->t('The default region could not be saved.'),
	'Country and region were saved.' => $l->t('Country and region were saved.'),
	'The country and region could not be saved.' => $l->t('The country and region could not be saved.'),
	'Add as company holiday' => $l->t('Add as company holiday'),
	'Already in the calendar' => $l->t('Already in the calendar'),
	'Add {name} ({date}) as a company holiday' => $l->t('Add {name} ({date}) as a company holiday'),
	'Holiday "{name}" was added as a company holiday.' => $l->t('Holiday "{name}" was added as a company holiday.'),
	'Show holidays of another country?' => $l->t('Show holidays of another country?'),
	'The statutory holidays of the selected region will be added to the calendar automatically.' => $l->t('The statutory holidays of the selected region will be added to the calendar automatically.'),
	'Working time rules are not affected — they follow the country configured for the whole organisation.' => $l->t('Working time rules are not affected — they follow the country configured for the whole organisation.'),
	'You can switch back to any other region at any time.' => $l->t('You can switch back to any other region at any time.'),
	'Show region' => $l->t('Show region'),
	'Change working time country?' => $l->t('Change working time country?'),
	'Change country' => $l->t('Change country'),
	'Working time rules will follow the newly selected country from now on.' => $l->t('Working time rules will follow the newly selected country from now on.'),
	'The default holiday region is reset when it does not belong to the new country. Existing holiday calendars of other countries stay in the database.' => $l->t('The default holiday region is reset when it does not belong to the new country. Existing holiday calendars of other countries stay in the database.'),
	'Daily hour and rest limits you already set are kept. You can switch back to another country later the same way.' => $l->t('Daily hour and rest limits you already set are kept. You can switch back to another country later the same way.'),
	'Could not show the country-change confirmation. Please reload the page and try again.' => $l->t('Could not show the country-change confirmation. Please reload the page and try again.'),
	'Region list updated. Default region: %s' => $l->t('Region list updated. Default region: %s', ['%s']),
	'The selected region does not belong to the selected country' => $l->t('The selected region does not belong to the selected country'),
];
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack">
	<script type="application/json" nonce="<?php p($_['cspNonce'] ?? ''); ?>" id="arbeitszeitcheck-admin-holidays-ui-strings">
<?php echo json_encode($holidaysUiStrings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
	</script>
	<script type="application/json" nonce="<?php p($_['cspNonce'] ?? ''); ?>" id="arbeitszeitcheck-admin-holidays-config">
<?php echo json_encode([
	'statutoryAutoReseed' => $statutoryAutoReseed,
	'settingsUrl' => $settingsUrl,
	'country' => $country,
	'defaultState' => $defaultState,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
	</script>
	<script type="application/json" nonce="<?php p($_['cspNonce'] ?? ''); ?>" id="azc-holidays-region-data">
<?php echo json_encode($regionData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
	</script>

	<div class="admin-holidays">

		<?php if ($calendarSubscriptionUrl !== ''): ?>
		<?php
		$calloutVariant = 'info';
		$calloutRole = 'region';
		$calloutId = 'holiday-calendar-subscription-link';
		$calloutTitleId = 'holiday-calendar-subscription-title';
		$calloutExtraClass = 'admin-holidays__subscription-link';
		$calloutTitle = $l->t('Calendar subscription');
		$calloutText = $l->t('Subscribe to approved team absences in Thunderbird, Nextcloud Calendar, Outlook, or other calendar apps. The feed refreshes automatically (last 3 months through next 12 months).');
		$calloutTextHtml = null;
		$calloutHint = $l->t('Requires app-owned teams. Configure links under Global settings → Calendar subscription.');
		$calloutActions = [[
			'href' => $calendarSubscriptionUrl,
			'label' => $l->t('Open calendar subscription settings'),
			'id' => 'holiday-calendar-subscription-settings-link',
			'class' => 'azc-btn azc-btn--primary azc-btn--sm azc-btn--touch',
		]];
		include __DIR__ . '/common/alert-callout.php';
		?>
		<?php endif; ?>

		<section class="azc-card" aria-labelledby="holiday-country-region-title" data-initial-country="<?php p($country); ?>">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="holiday-country-region-title" class="azc-card__title"><?php p($l->t('Country and region')); ?></h2>
					<p class="azc-card__lead">
						<?php p($l->t('First pick the country whose working time law applies, then the default region for public holidays.')); ?>
					</p>
				</div>
			</header>
			<div class="azc-card__body">
				<fieldset class="form-fieldset" aria-labelledby="holiday-country-legend" aria-describedby="holiday-country-help">
					<legend id="holiday-country-legend" class="form-legend"><?php p($l->t('In which country does your organisation work?')); ?></legend>
					<div class="azc-country-grid">
						<?php foreach ($countryCards as $countryCode => $card): ?>
						<label class="azc-country-card" for="holiday-country-<?php p(strtolower($countryCode)); ?>">
							<input type="radio"
								   id="holiday-country-<?php p(strtolower($countryCode)); ?>"
								   name="holidayCountry"
								   value="<?php p($countryCode); ?>"
								   class="azc-country-card__radio"
								   <?php echo ($country === $countryCode) ? 'checked' : ''; ?>
								   aria-describedby="holiday-country-help">
							<span class="azc-country-card__body">
								<span class="azc-country-card__title"><?php p($card['title']); ?></span>
								<span class="azc-country-card__text"><?php p($card['text']); ?></span>
							</span>
						</label>
						<?php endforeach; ?>
					</div>
					<p id="holiday-country-help" class="form-help">
						<?php p($l->t('Changing the country updates working time rules for the whole organisation. Daily hour and rest limits you already set are kept — review them under Settings after switching.')); ?>
					</p>
				</fieldset>
				<div id="holiday-country-region-live" class="form-help azc-country-region-live" role="status" aria-live="polite" aria-atomic="true"></div>
				<div class="azc-filter-field admin-holidays__default-state-field">
					<label for="holiday-default-state" class="azc-filter-field__label"><?php p($l->t('Default region for public holidays')); ?></label>
					<div class="azc-filter-field__control">
						<select id="holiday-default-state" name="holidayDefaultState" class="form-select" aria-describedby="holiday-default-state-help">
							<?php foreach ($instanceRegions as $code => $name): ?>
								<option value="<?php p($code); ?>"<?php if ($code === $defaultState) { echo ' selected'; } ?>><?php p($l->t($name)); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<p id="holiday-default-state-help" class="admin-holidays__help">
					<strong><?php p($l->t('Region changes are saved automatically. Country changes ask for confirmation first.')); ?></strong>
					<?php
					$usersUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.admin.users');
					print_unescaped($l->t(
						'The region for an employee is set by administrators or managers, for example in %1$sEmployee settings%2$s. If no own region is configured there, the default region configured here is used.',
						[
							'<a href="' . \OCP\Util::sanitizeHTML($usersUrl) . '">',
							'</a>',
						]
					));
					?>
				</p>
			</div>
		</section>

		<section class="azc-card" aria-labelledby="state-calendar-title">
			<header class="azc-card__header">
				<div class="azc-card__header-text">
					<h2 id="state-calendar-title" class="azc-card__title"><?php p($l->t('Manage calendars by region')); ?></h2>
					<p class="azc-card__lead">
						<?php p($l->t('Select region and year to view and edit statutory holidays as well as additional company or custom holidays.')); ?>
					</p>
				</div>
			</header>
			<div class="azc-card__body">
				<?php if (!$statutoryAutoReseed): ?>
				<?php
				$calloutVariant = 'warning';
				$calloutRole = 'status';
				$calloutAriaLive = 'polite';
				$calloutExtraClass = 'admin-holidays__auto-reseed-notice';
				$calloutTitle = '';
				if ($settingsUrl !== '') {
					$calloutTextHtml = $l->t(
						'Auto-restore is off. Deleted statutory holidays stay removed. You can change this under %1$sSettings%2$s → Auto-restore statutory holidays.',
						[
							'<a href="' . \OCP\Util::sanitizeHTML($settingsUrl) . '">',
							'</a>',
						]
					);
					$calloutText = '';
				} else {
					$calloutText = $l->t('Auto-restore is off. Deleted statutory holidays stay removed.');
					$calloutTextHtml = null;
				}
				$calloutActions = [];
				include __DIR__ . '/common/alert-callout.php';
				?>
				<?php endif; ?>
				<form class="admin-holidays__toolbar" id="holiday-calendar-filters" novalidate>
					<div class="azc-filter-grid admin-holidays__filter-grid" role="group" aria-label="<?php p($l->t('Calendar selection')); ?>">
						<div class="azc-filter-field">
							<label for="holiday-state-select" class="azc-filter-field__label"><?php p($l->t('Region')); ?></label>
							<div class="azc-filter-field__control">
								<select id="holiday-state-select" name="holidayState" class="form-select">
									<?php foreach ($regionGroups as $group): ?>
										<optgroup label="<?php p($group['countryLabel']); ?>">
											<?php foreach ($group['regions'] as $code => $name): ?>
												<option value="<?php p($code); ?>"<?php if ($code === $defaultState) { echo ' selected'; } ?>><?php p($l->t($name)); ?></option>
											<?php endforeach; ?>
										</optgroup>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="azc-filter-field">
							<label for="holiday-year-select" class="azc-filter-field__label"><?php p($l->t('Year')); ?></label>
							<div class="azc-filter-field__control">
								<select id="holiday-year-select" name="holidayYear" class="form-select">
									<?php for ($y = $currentYear - 1; $y <= $currentYear + 3; $y++): ?>
										<option value="<?php p($y); ?>"<?php if ($y === $currentYear) { echo ' selected'; } ?>><?php p($y); ?></option>
									<?php endfor; ?>
								</select>
							</div>
						</div>
						<div class="azc-filter-actions">
							<button type="button" id="holiday-add-entry" class="azc-btn azc-btn--primary" aria-label="<?php p($l->t('Create new holiday')); ?>">
								<?php p($l->t('Add new holiday')); ?>
							</button>
						</div>
					</div>
				</form>

				<div class="admin-holidays__results" id="holiday-results" aria-live="polite" aria-busy="false">
					<div class="table-container" tabindex="0" role="region" aria-label="<?php p($l->t('List of holidays for the selected region and year')); ?>">
						<table class="table table--hover azc-table--responsive" id="holiday-table" aria-label="<?php p($l->t('List of holidays for the selected region and year')); ?>">
							<caption class="visually-hidden"><?php p($l->t('List of holidays for the selected region and year, with date, name, type and actions')); ?></caption>
							<thead>
								<tr>
									<th scope="col"><?php p($l->t('Date')); ?></th>
									<th scope="col"><?php p($l->t('Holiday name')); ?></th>
									<th scope="col"><?php p($l->t('Type')); ?></th>
									<th scope="col"><?php p($l->t('Scope')); ?></th>
									<th scope="col" class="azc-table-actions-col"><?php p($l->t('Actions')); ?></th>
								</tr>
							</thead>
							<tbody id="holiday-tbody"></tbody>
						</table>
					</div>
				</div>

				<aside class="azc-callout azc-callout--info admin-holidays__legend" aria-label="<?php p($l->t('Column explanations')); ?>">
					<p class="azc-callout__text">
						<?php p($l->t('"Type" determines whether a day is treated as a full-day holiday (not counted as a working day) or as a half-day holiday (e.g., 0.5 vacation day).')); ?>
					</p>
					<p class="azc-callout__text">
						<?php p($l->t('"Scope" distinguishes between statutory holidays, organization-wide company holidays, and custom entries. Statutory holidays are usually full-day; in Switzerland some statutory days (e.g. Sechseläuten) are half-day and count as 0.5.')); ?>
					</p>
				</aside>

				<section class="admin-holidays__suggestions" id="holiday-suggestions-section" aria-labelledby="holiday-suggestions-title" hidden>
					<h3 id="holiday-suggestions-title" class="admin-holidays__suggestions-title"><?php p($l->t('Common additional holidays')); ?></h3>
					<p class="form-help form-help--block">
						<?php p($l->t('These days are customary in the selected region — for example through collective agreements — but they are not statutory holidays. Add a day as a company holiday if your organisation grants it.')); ?>
					</p>
					<p class="form-help form-help--block" id="holiday-good-friday-note" hidden>
						<?php p($l->t('Note: Good Friday has not been a statutory public holiday in Austria since 2019. It is therefore never added automatically — add it as a company holiday if your organisation grants it.')); ?>
					</p>
					<ul class="admin-holidays__suggestions-list" id="holiday-suggestions-list" aria-live="polite"></ul>
				</section>
			</div>
		</section>

	</div>
</div>

<?php include __DIR__ . '/common/page-end.php'; ?>
