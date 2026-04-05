<?php
declare(strict_types=1);

/**
 * In-app settings template for arbeitszeitcheck
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCP\Util;

/** @var array $_ */
/** @var \OCP\IL10N $l */

Util::addTranslations('arbeitszeitcheck');
Util::addStyle('arbeitszeitcheck', 'common/colors');
Util::addStyle('arbeitszeitcheck', 'common/typography');
Util::addStyle('arbeitszeitcheck', 'common/base');
Util::addStyle('arbeitszeitcheck', 'common/components');
Util::addStyle('arbeitszeitcheck', 'common/layout');
Util::addStyle('arbeitszeitcheck', 'common/app-layout');
Util::addStyle('arbeitszeitcheck', 'common/utilities');
Util::addStyle('arbeitszeitcheck', 'common/responsive');
Util::addStyle('arbeitszeitcheck', 'common/accessibility');
Util::addStyle('arbeitszeitcheck', 'navigation');
Util::addStyle('arbeitszeitcheck', 'settings');
Util::addScript('arbeitszeitcheck', 'common/utils');
Util::addScript('arbeitszeitcheck', 'settings');

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$embedded = false;
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
	<div id="app-content-wrapper">
		<div class="breadcrumb-container">
			<nav class="breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol>
					<li><a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"><?php p($l->t('Dashboard')); ?></a></li>
					<li aria-current="page"><?php p($l->t('Settings')); ?></li>
				</ol>
			</nav>
		</div>

		<header class="section page-header-section" aria-labelledby="settings-page-title">
			<div class="header-content">
				<div class="header-text">
					<h2 id="settings-page-title"><?php p($l->t('Settings')); ?></h2>
					<p><?php p($l->t('Manage your personal preferences and notification settings')); ?></p>
				</div>
			</div>
		</header>

		<section class="section">
			<div class="settings-container azc-user-settings" role="region" aria-label="<?php p($l->t('Settings options')); ?>">
				<?php include __DIR__ . '/parts/user-settings-forms.php'; ?>

				<section class="settings-section azc-user-settings__section" aria-labelledby="azc-compliance-heading">
					<h3 id="azc-compliance-heading" class="section-title"><?php p($l->t('Compliance Information')); ?></h3>
					<div class="info-box">
						<h4><?php p($l->t('German Labor Law (Arbeitszeitgesetz - ArbZG)')); ?></h4>
						<ul>
							<li><?php p($l->t('Maximum working time: 8 hours per day (can be extended to 10 hours)')); ?></li>
							<li><?php p($l->t('Minimum rest period: 11 hours between working days')); ?></li>
							<li><?php p($l->t('Mandatory breaks: 30 min after 6 hours, 45 min after 9 hours')); ?></li>
							<li><?php p($l->t('Sunday work is generally prohibited with exceptions')); ?></li>
						</ul>
					</div>
				</section>

				<section class="settings-section azc-user-settings__section" aria-labelledby="azc-version-heading">
					<h3 id="azc-version-heading" class="section-title"><?php p($l->t('Version Information')); ?></h3>
					<div class="info-box">
						<p>
							<strong><?php p($l->t('ArbeitszeitCheck')); ?></strong>
							<?php p($l->t('Version:')); ?> <?php p(\OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion('arbeitszeitcheck')); ?>
						</p>
						<p><?php p($l->t('German labor law compliant time tracking for Nextcloud')); ?></p>
					</div>
				</section>
			</div>
		</section>
	</div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.page = 'settings';
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
window.ArbeitszeitCheck.l10n.settingsSaved = <?php echo json_encode($l->t('Settings saved successfully'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.apiUrl = window.ArbeitszeitCheck.apiUrl || {};
window.ArbeitszeitCheck.apiUrl.updateSettings = <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.settings.update'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
