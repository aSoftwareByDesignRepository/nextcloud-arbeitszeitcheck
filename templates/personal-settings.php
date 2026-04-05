<?php

declare(strict_types=1);

/**
 * Personal settings embedded in Nextcloud → Personal settings (no full app chrome).
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */

$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$embedded = (bool)($_['embedded'] ?? true);
$showAppSettingsLink = (bool)($_['showAppSettingsLink'] ?? true);
$fullSettingsUrl = $urlGenerator->linkToRoute('arbeitszeitcheck.page.settings');
?>

<div id="arbeitszeitcheck-personal-settings" class="section azc-settings-embedded">
	<header class="azc-settings-embedded__header">
		<h2 class="azc-settings-embedded__title"><?php p($l->t('ArbeitszeitCheck')); ?></h2>
		<p class="azc-settings-embedded__lede" id="azc-personal-desc">
			<?php p($l->t('Working time, notifications, calendar sync, and data export. For labor-law details and version info, open settings inside the app.')); ?>
		</p>
		<?php if ($showAppSettingsLink) { ?>
			<p class="azc-settings-embedded__more">
				<a href="<?php p($fullSettingsUrl); ?>"
					class="azc-settings-embedded__applink"
					aria-describedby="azc-personal-desc">
					<?php p($l->t('Open full settings in the app')); ?>
				</a>
			</p>
		<?php } ?>
	</header>

	<div class="settings-container azc-user-settings" role="region" aria-label="<?php p($l->t('Settings options')); ?>">
		<?php include __DIR__ . '/parts/user-settings-forms.php'; ?>
	</div>
</div>

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.page = 'settings';
window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
window.ArbeitszeitCheck.l10n.settingsSaved = <?php echo json_encode($l->t('Settings saved successfully'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
