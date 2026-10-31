<?php
declare(strict_types=1);

/**
 * Employee My settings — SETTINGS-PAGES-STANDARD multipage shell.
 *
 * Dispatches to templates/partials/employee-settings/<section>.php via a literal
 * slug → file map (never concatenate the request into an include path).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$urls = $_['urls'] ?? [];
$appVersion = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion('arbeitszeitcheck');
$complianceProfile = is_array($_['complianceProfile'] ?? null) ? $_['complianceProfile'] : [];
$settingsSection = (string)($_['settingsSection'] ?? \OCA\ArbeitszeitCheck\Service\EmployeeSettingsSectionCatalog::DEFAULT_SECTION);
$settingsPages = is_array($_['settingsPages'] ?? null) ? $_['settingsPages'] : [];
$sectionFiles = \OCA\ArbeitszeitCheck\Service\EmployeeSettingsSectionCatalog::SECTION_FILES;
// Shell H1 stays the section name. The section sentence lives in the card so
// the header and topic row keep the same height on every tab.
$settingsSectionHelp = (new \OCA\ArbeitszeitCheck\Service\EmployeeSettingsSectionCatalog())
	->help($l, $settingsSection);
// Shell owns H1 — hide duplicate card titles (design-system §14).
$azcSettingsShowCardChrome = false;
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

<div class="azc-page-stack settings-container" aria-label="<?php p($l->t('Settings options')); ?>">
	<?php if ($settingsPages !== []): ?>
		<?php include __DIR__ . '/common/azc-employee-settings-nav.php'; ?>
	<?php endif; ?>

	<?php
	$partial = $sectionFiles[$settingsSection] ?? null;
	if (is_string($partial) && $partial !== '') {
		include __DIR__ . '/partials/employee-settings/' . $partial;
	}
	?>
</div>

<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
	window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
	window.ArbeitszeitCheck.page = 'settings';
	window.ArbeitszeitCheck.settingsSection = <?php echo json_encode($settingsSection, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	window.ArbeitszeitCheck.employeeSettingsPages = <?php echo json_encode($settingsPages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

	window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
	window.ArbeitszeitCheck.l10n.settingsSaved = <?php echo json_encode($l->t('Settings saved successfully'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	window.ArbeitszeitCheck.l10n.saving = <?php echo json_encode($l->t('Saving...'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	window.ArbeitszeitCheck.l10n.failedToSaveSettings = <?php echo json_encode($l->t('Failed to save settings'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

	window.ArbeitszeitCheck.apiUrl = {
		updateSettings: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.settings.update'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		gdprDelete: <?php echo json_encode((string)($urls['gdprDelete'] ?? $urlGenerator->linkToRoute('arbeitszeitcheck.gdpr.delete')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
	};
</script>
<?php include __DIR__ . '/common/page-end.php'; ?>
