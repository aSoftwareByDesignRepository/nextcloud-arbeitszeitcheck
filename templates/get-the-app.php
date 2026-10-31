<?php

declare(strict_types=1);

/**
 * Get the App — ArbeitszeitCheck Mobile + Terminal (Google Play and the App Store).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\ArbeitszeitCheck\Service\IconCatalog;
use OCA\ArbeitszeitCheck\Support\MobileAppLinks;
use OCA\ArbeitszeitCheck\Support\SupportUsLinks;

$urls = is_array($_['urls'] ?? null) ? $_['urls'] : [];
$sitePrefix = SupportUsLinks::SITE_ORIGIN . '/';
$playStore = MobileAppLinks::publicHttpsUrl(
	(string)($urls['playStore'] ?? ''),
	'https://play.google.com/',
	MobileAppLinks::PLAY_STORE_URL,
);
$kioskPlay = MobileAppLinks::publicHttpsUrl(
	(string)($urls['kioskPlayStore'] ?? ''),
	'https://play.google.com/',
	MobileAppLinks::KIOSK_PLAY_STORE_URL,
);
$appStore = MobileAppLinks::publicHttpsUrl(
	(string)($urls['appStore'] ?? ''),
	'https://apps.apple.com/',
	MobileAppLinks::APP_STORE_URL,
);
$kioskAppStore = MobileAppLinks::publicHttpsUrl(
	(string)($urls['kioskAppStore'] ?? ''),
	'https://apps.apple.com/',
	MobileAppLinks::KIOSK_APP_STORE_URL,
);
$productPage = MobileAppLinks::publicHttpsUrl((string)($urls['mobileProductPage'] ?? ''), $sitePrefix, '');
$privacyPage = MobileAppLinks::publicHttpsUrl((string)($urls['mobilePrivacyPage'] ?? ''), $sitePrefix, '');
$kioskPrivacy = MobileAppLinks::publicHttpsUrl((string)($urls['kioskPrivacyPage'] ?? ''), $sitePrefix, '');
$vendorApps = MobileAppLinks::publicHttpsUrl((string)($urls['vendorApps'] ?? ''), $sitePrefix, '');

$features = [
	[
		'icon' => 'clock',
		'title' => $l->t('Clock in from your phone'),
		'hint' => $l->t('Start and stop work, and see today’s hours, without opening a laptop.'),
	],
	[
		'icon' => 'calendar-off',
		'title' => $l->t('Request leave on the go'),
		'hint' => $l->t('Submit absences and follow their status from the official mobile app.'),
	],
	[
		'icon' => 'tablet',
		'title' => $l->t('Foyer terminal for shared tablets'),
		'hint' => $l->t('The Terminal app is for reception or shop-floor tablets. IT licenses each device.'),
	],
	[
		'icon' => 'lock',
		'title' => $l->t('Sign in safely'),
		'hint' => $l->t('Uses Nextcloud Login Flow — your main password is never stored in the app.'),
	],
];

include __DIR__ . '/common/page-start.php';
?>
<div class="azc-get-app-page azc-page-stack">
	<section class="azc-get-app__hero" aria-labelledby="azc-get-app-intro-title">
		<p class="azc-get-app__eyebrow"><?php p($l->t('Official apps for Android and iOS')); ?></p>
		<h2 id="azc-get-app-intro-title" class="azc-get-app__title"><?php p($l->t('Phone and foyer terminal')); ?></h2>
		<p class="azc-get-app__lead">
			<?php p($l->t('Clock in, request leave, and see your hours on Android or iOS. Foyer tablets use the Terminal app, also on Android and iOS. Both connect to this Nextcloud. Your organisation licenses seats — the web app stays free.')); ?>
		</p>
		<div class="azc-get-app__stores">
			<section class="azc-get-app__store" aria-labelledby="azc-get-app-play-title">
				<h3 id="azc-get-app-play-title" class="azc-get-app__store-title"><?php p($l->t('On Google Play')); ?></h3>
				<a class="azc-btn azc-btn--primary azc-get-app__store-link azc-get-app__store-link--primary azc-get-app__play" href="<?php p($playStore); ?>" target="_blank" rel="noopener noreferrer">
					<span class="azc-get-app__play-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('smartphone')); ?></span>
					<span class="azc-get-app__play-label"><?php p($l->t('ArbeitszeitCheck Mobile on Google Play')); ?></span>
					<span class="visually-hidden"><?php p($l->t('(opens in a new tab)')); ?></span>
				</a>
				<a class="azc-btn azc-btn--secondary azc-get-app__store-link azc-get-app__play" href="<?php p($kioskPlay); ?>" target="_blank" rel="noopener noreferrer">
					<span class="azc-get-app__play-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('tablet')); ?></span>
					<span class="azc-get-app__play-label"><?php p($l->t('Terminal app on Google Play')); ?></span>
					<span class="visually-hidden"><?php p($l->t('(opens in a new tab)')); ?></span>
				</a>
			</section>
			<section class="azc-get-app__store" aria-labelledby="azc-get-app-apple-title">
				<h3 id="azc-get-app-apple-title" class="azc-get-app__store-title"><?php p($l->t('On the App Store')); ?></h3>
				<a class="azc-btn azc-btn--primary azc-get-app__store-link azc-get-app__store-link--primary" href="<?php p($appStore); ?>" target="_blank" rel="noopener noreferrer">
					<span class="azc-get-app__play-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('smartphone')); ?></span>
					<span class="azc-get-app__play-label"><?php p($l->t('ArbeitszeitCheck Mobile on the App Store')); ?></span>
					<span class="visually-hidden"><?php p($l->t('(opens in a new tab)')); ?></span>
				</a>
				<a class="azc-btn azc-btn--secondary azc-get-app__store-link" href="<?php p($kioskAppStore); ?>" target="_blank" rel="noopener noreferrer">
					<span class="azc-get-app__play-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('tablet')); ?></span>
					<span class="azc-get-app__play-label"><?php p($l->t('Terminal app on the App Store')); ?></span>
					<span class="visually-hidden"><?php p($l->t('(opens in a new tab)')); ?></span>
				</a>
			</section>
		</div>
		<p class="azc-get-app__price-hint">
			<?php p($l->t('Free to download. Your IT assigns official mobile and terminal seats on the License page.')); ?>
		</p>
	</section>

	<section class="azc-get-app__features-block" aria-labelledby="azc-get-app-features-title">
		<h2 id="azc-get-app-features-title" class="azc-get-app__section-title"><?php p($l->t('Benefits of the mobile apps')); ?></h2>
		<ul class="azc-get-app__features">
			<?php foreach ($features as $feature): ?>
				<li class="azc-get-app__feature">
					<span class="azc-get-app__icon-well azc-get-app__icon-well--feature" aria-hidden="true">
						<?php print_unescaped(IconCatalog::render((string)$feature['icon'])); ?>
					</span>
					<div class="azc-get-app__feature-copy">
						<span class="azc-get-app__feature-title"><?php p((string)$feature['title']); ?></span>
						<span class="azc-get-app__feature-hint"><?php p((string)$feature['hint']); ?></span>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<?php
	$actionRows = [];
	if ($productPage !== '') {
		$actionRows[] = ['href' => $productPage, 'label' => $l->t('Product page'), 'primary' => true];
	}
	if ($privacyPage !== '') {
		$actionRows[] = ['href' => $privacyPage, 'label' => $l->t('Privacy policy for the mobile app'), 'primary' => false];
	}
	if ($kioskPrivacy !== '') {
		$actionRows[] = ['href' => $kioskPrivacy, 'label' => $l->t('Privacy policy for the terminal app'), 'primary' => false];
	}
	if ($vendorApps !== '') {
		$actionRows[] = ['href' => $vendorApps, 'label' => $l->t('All Check apps'), 'primary' => false];
	}
	?>
	<section class="azc-get-app__vendor" aria-labelledby="azc-get-app-vendor-title">
		<p class="azc-get-app__vendor-kicker">Software by Design</p>
		<h2 id="azc-get-app-vendor-title" class="azc-get-app__vendor-title"><?php p($l->t('Built by Software by Design')); ?></h2>
		<p class="azc-get-app__vendor-lead">
			<?php p($l->t('Software by Design makes ArbeitszeitCheck and the other Check apps for Nextcloud. The web app on this server stays free. Your organisation licenses the official phone and terminal apps. The product page and the privacy policies are below.')); ?>
		</p>
		<?php if ($actionRows !== []): ?>
			<nav class="azc-get-app__actions" aria-label="<?php p($l->t('More information')); ?>">
				<?php foreach ($actionRows as $row): ?>
					<?php $actionClass = !empty($row['primary'])
						? 'azc-btn azc-btn--primary azc-get-app__action azc-get-app__action--primary'
						: 'azc-btn azc-btn--secondary azc-get-app__action'; ?>
					<a class="<?php p($actionClass); ?>" href="<?php p((string)$row['href']); ?>" target="_blank" rel="noopener noreferrer">
						<span class="azc-get-app__action-label"><?php p((string)$row['label']); ?></span>
						<span class="visually-hidden"><?php p($l->t('(opens in a new tab)')); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
