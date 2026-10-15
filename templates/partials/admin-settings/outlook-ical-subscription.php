<?php
declare(strict_types=1);

/**
 * Admin settings section for calendar (iCal) subscriptions.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionLanguageCatalog;
use OCA\ArbeitszeitCheck\Service\IconCatalog;

/** @var \OCP\IL10N $l */
$urlGen = $urlGenerator ?? $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$outlookApiUrl = static function (string $route, string $fallbackPath) use ($urlGen): string {
	$linked = $urlGen->linkToRoute($route);
	if ($linked !== '') {
		return $linked;
	}

	return $urlGen->getAbsoluteURL($fallbackPath);
};
$useAppTeams = !empty($useAppTeams) || !empty($_['useAppTeams']);
$azcSettingsShowCardChrome = !empty($azcSettingsShowCardChrome) || !empty($renderAll);
$outlookIntroDescId = !empty($azcSettingsShowCardChrome) ? 'outlook-ical-subscription-intro' : 'azc-page-help';
$outlookTeamsUrl = $outlookApiUrl(
	'arbeitszeitcheck.outlook_ical_subscription.adminTeams',
	'/index.php/apps/arbeitszeitcheck/api/admin/outlook-ical/teams',
);
$outlookRotateUrl = $outlookApiUrl(
	'arbeitszeitcheck.outlook_ical_subscription.adminRotateToken',
	'/index.php/apps/arbeitszeitcheck/api/admin/outlook-ical/rotate',
);
$outlookCreateUrl = $outlookApiUrl(
	'arbeitszeitcheck.outlook_ical_subscription.adminCreateToken',
	'/index.php/apps/arbeitszeitcheck/api/admin/outlook-ical/create',
);
$outlookActiveSubscriptionsUrl = $outlookApiUrl(
	'arbeitszeitcheck.outlook_ical_subscription.adminActiveSubscriptions',
	'/index.php/apps/arbeitszeitcheck/api/admin/outlook-ical/active-subscriptions',
);
$outlookWebcalLocalAccessUrl = $outlookApiUrl(
	'arbeitszeitcheck.outlook_ical_subscription.adminWebcalLocalAccess',
	'/index.php/apps/arbeitszeitcheck/api/admin/outlook-ical/webcal-local-access',
);
$outlookFeedLanguageOptions = OutlookIcalSubscriptionLanguageCatalog::optionsForUi();
$outlookDefaultFeedLanguage = OutlookIcalSubscriptionLanguageCatalog::resolveDefault($l->getLanguageCode());
?>
<section id="outlook-ical-subscription"
	class="azc-card admin-settings-section admin-settings-section--outlook-subscription"
	aria-labelledby="section-outlook-subscription-heading"
	data-outlook-teams-url="<?php p($outlookTeamsUrl); ?>"
	data-outlook-rotate-url="<?php p($outlookRotateUrl); ?>"
	data-outlook-create-url="<?php p($outlookCreateUrl); ?>"
	data-outlook-active-subscriptions-url="<?php p($outlookActiveSubscriptionsUrl); ?>"
	data-outlook-webcal-local-access-url="<?php p($outlookWebcalLocalAccessUrl); ?>"
	data-use-app-teams="<?php echo $useAppTeams ? '1' : '0'; ?>"
	data-org-wide-available="1"
	data-default-feed-language="<?php p($outlookDefaultFeedLanguage); ?>">
	<header class="azc-card__header<?php echo empty($azcSettingsShowCardChrome) ? ' azc-card__header--page-title-only' : ''; ?>">
		<div class="azc-card__header-text">
			<?php if (!empty($azcSettingsShowCardChrome)): ?>
			<h2 id="section-outlook-subscription-heading" class="azc-card__title"><?php p($l->t('Calendar subscription (Per team, privacy-safe)')); ?></h2>
			<p class="azc-card__lead" id="outlook-ical-subscription-intro">
				<?php p($l->t('Share approved absences with Thunderbird, Nextcloud Calendar, or Outlook — one subscription link per scope and calendar language.')); ?>
			</p>
			<?php else: ?>
			<h2 id="section-outlook-subscription-heading" class="azc-card__title visually-hidden"><?php p($l->t('Calendar subscription (Per team, privacy-safe)')); ?></h2>
			<?php endif; ?>
		</div>
	</header>
	<div class="azc-card__body" aria-describedby="<?php p($outlookIntroDescId); ?> outlook-ical-subscription-help">
		<?php if (!$useAppTeams): ?>
		<div class="azc-callout azc-callout--info outlook-ical-callout" role="status">
			<span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('users', 'azc-callout__icon-svg')); ?></span>
			<div class="azc-callout__body">
				<p class="azc-callout__text"><?php p($l->t('No app teams configured? Choose “All employees” below — ideal for small companies without departments.')); ?></p>
			</div>
		</div>
		<?php endif; ?>

		<div class="outlook-ical-quickstart" aria-labelledby="outlook-ical-quickstart-heading">
			<h3 id="outlook-ical-quickstart-heading" class="admin-settings-subsection__title"><?php p($l->t('How it works')); ?></h3>
			<ol class="outlook-ical-quickstart__steps">
				<li><?php p($l->t('Choose who is included and the calendar language.')); ?></li>
				<li><?php p($l->t('Generate a subscription link (secret like a password).')); ?></li>
				<li><?php p($l->t('Copy the link into your calendar app under “Subscribe from URL”.')); ?></li>
			</ol>
			<p class="form-help form-help--note outlook-ical-quickstart__note">
				<?php p($l->t('Each scope and calendar language has its own link. Links are stored encrypted on this server so you can copy them again later. Rotating a link invalidates the previous URL immediately.')); ?>
			</p>
		</div>

		<section class="azc-settings-subsection outlook-ical-subscription-table-section" id="outlookIcalActiveSubscriptionsSection" aria-labelledby="outlook-ical-active-heading">
			<h3 id="outlook-ical-active-heading" class="admin-settings-subsection__title"><?php p($l->t('Your subscription links')); ?></h3>
			<p class="form-help outlook-ical-subscription-table__intro">
				<?php p($l->t('Copy a link into your calendar app under “Subscribe from URL”. Each row is one scope and calendar language.')); ?>
			</p>
			<div id="outlookIcalSubscriptionsLoading"
				class="azc-loading outlook-ical-subscription-table__loading"
				role="status"
				aria-live="polite"
				aria-busy="true"
				aria-labelledby="outlook-ical-active-heading">
				<?php p($l->t('Loading subscription links…')); ?>
			</div>
			<div class="table-container outlook-ical-subscription-table__wrap" role="region" aria-labelledby="outlook-ical-active-heading" hidden>
				<table class="table table--hover azc-table--responsive outlook-ical-subscription-table" id="outlookIcalSubscriptionTable" hidden>
					<caption class="visually-hidden"><?php p($l->t('Active calendar subscription links')); ?></caption>
					<thead>
						<tr>
							<th scope="col" data-col="scope"><?php p($l->t('Scope')); ?></th>
							<th scope="col" data-col="language"><?php p($l->t('Calendar language')); ?></th>
							<th scope="col" data-col="absences"><?php p($l->t('Approved absences')); ?></th>
							<th scope="col" data-col="window"><?php p($l->t('Rolling window')); ?></th>
							<th scope="col" data-col="url"><?php p($l->t('Subscription URL')); ?></th>
							<th scope="col" class="azc-table-actions-col" data-col="actions"><?php p($l->t('Actions')); ?></th>
						</tr>
					</thead>
					<tbody id="outlookIcalSubscriptionTableBody"></tbody>
				</table>
			</div>
			<p id="outlookIcalActiveSubscriptionsEmpty"
				class="form-help outlook-ical-subscription-table__empty"
				hidden
				data-empty-text="<?php p($l->t('No subscription links yet — create one below.')); ?>">
				<?php p($l->t('No subscription links yet — create one below.')); ?>
			</p>
		</section>

		<section class="azc-settings-subsection outlook-ical-create" aria-labelledby="outlook-ical-create-heading">
			<h3 id="outlook-ical-create-heading" class="admin-settings-subsection__title"><?php p($l->t('Create a new link')); ?></h3>
			<div class="form-row form-row--2">
				<div class="form-group">
					<label for="outlookIcalTeamSearch" class="form-label"><?php p($l->t('Who is included?')); ?></label>
					<input type="hidden" id="outlookIcalTeamId" value="">
					<div class="user-picker" id="outlook-ical-team-picker">
						<div class="user-picker__control">
							<input type="search"
								id="outlookIcalTeamSearch"
								class="form-input user-picker__search"
								autocomplete="off"
								spellcheck="false"
								placeholder="<?php p($l->t('Search scopes…')); ?>"
								role="combobox"
								aria-autocomplete="list"
								aria-expanded="false"
								aria-controls="outlookIcalTeamListbox"
								aria-describedby="outlookIcalTeamHelp outlookIcalTeamStatus">
							<button type="button"
								class="user-picker__clear"
								id="outlookIcalTeamClear"
								hidden
								aria-label="<?php p($l->t('Clear selected scope')); ?>">
								<span aria-hidden="true">&times;</span>
							</button>
						</div>
						<div id="outlookIcalTeamListbox"
							class="user-picker__list"
							role="listbox"
							hidden
							aria-label="<?php p($l->t('Matching scopes')); ?>"></div>
						<p id="outlookIcalTeamStatus" class="azc-sr-only" role="status" aria-live="polite" aria-atomic="true"></p>
					</div>
					<p id="outlookIcalTeamHelp" class="form-help">
						<?php p($l->t('“All employees” for the whole company, or one team (child teams included automatically).')); ?>
					</p>
				</div>

				<div class="form-group">
					<label for="outlookIcalFeedLanguage" class="form-label"><?php p($l->t('Calendar language')); ?></label>
					<select id="outlookIcalFeedLanguage"
						class="form-select"
						aria-describedby="outlookIcalFeedLanguageHelp">
						<?php foreach ($outlookFeedLanguageOptions as $option): ?>
							<option value="<?php p($option['code']); ?>"<?php if ($option['code'] === $outlookDefaultFeedLanguage) { echo ' selected'; } ?>>
								<?php p($option['label']); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p id="outlookIcalFeedLanguageHelp" class="form-help">
						<?php p($l->t('Absence labels in the feed (for example “Vacation” or “Urlaub”). Employee names stay as in Nextcloud.')); ?>
					</p>
				</div>
			</div>
			<p id="outlookIcalWindowHelp" class="form-help form-help--note">
				<?php p($l->t('Each refresh covers approved absences from the last 3 months through the next 12 months. Subscribe once — the window moves forward automatically.')); ?>
			</p>
			<div id="outlookIcalCreateExistsNotice" class="azc-callout azc-callout--info outlook-ical-callout outlook-ical-create-exists" hidden role="status">
				<span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('info', 'azc-callout__icon-svg')); ?></span>
				<div class="azc-callout__body">
					<p id="outlookIcalCreateExistsNoticeText" class="azc-callout__text"></p>
				</div>
			</div>
			<div class="card-actions card-actions--inline outlook-ical-create__buttons">
				<button type="button" id="outlookIcalCreateBtn" class="azc-btn azc-btn--primary azc-btn--touch" disabled>
					<?php p($l->t('Create subscription link')); ?>
				</button>
			</div>
			<p class="form-help"><?php p($l->t('Use “Rotate link” on an existing row to replace its URL. Calendar apps with the old link stop updating immediately.')); ?></p>
		</section>

		<div id="outlookIcalWebcalLocalAccess" class="azc-callout azc-callout--info outlook-ical-callout outlook-ical-callout--local-access" hidden role="status" aria-live="polite">
			<span class="azc-callout__icon azc-notif-icon-well" aria-hidden="true"><?php print_unescaped(IconCatalog::render('calendar', 'azc-callout__icon-svg')); ?></span>
			<div class="azc-callout__body">
				<p id="outlookIcalWebcalLocalAccessText" class="azc-callout__text"></p>
				<div class="azc-callout__actions">
					<button type="button"
						id="outlookIcalEnableWebcalLocalBtn"
						class="azc-btn azc-btn--secondary azc-btn--touch"
						hidden>
						<?php p($l->t('Allow Nextcloud Calendar subscriptions on this server')); ?>
					</button>
				</div>
			</div>
		</div>

		<details class="azc-settings-more" id="outlook-ical-client-help">
			<summary><?php p($l->t('Help for Thunderbird & Nextcloud Calendar')); ?></summary>
			<ul class="outlook-ical-client-help__list">
				<li><?php p($l->t('Nextcloud Calendar: left sidebar → + → “New subscription” — paste the URL. Do not use “Add calendar account” (that is CalDAV, not this feed).')); ?></li>
				<li><?php p($l->t('Thunderbird: Calendar → New calendar → On the network → paste the webcal link. Delete an old subscription before adding a changed link.')); ?></li>
				<li><?php p($l->t('Event titles look like “Alex (Vacation)”. Sick leave appears as a generic “Absence” — no free-text reasons are ever included.')); ?></li>
			</ul>
		</details>

		<div id="outlookIcalLive" class="form-help" role="status" aria-live="polite" aria-atomic="true"></div>
		<p id="outlook-ical-subscription-help" class="form-help form-help--note">
			<?php p($l->t('Security: links are encrypted at rest on this server (app admins can view them here). Anyone with the full URL can read that scoped feed until you rotate the link.')); ?>
		</p>
	</div>
</section>
