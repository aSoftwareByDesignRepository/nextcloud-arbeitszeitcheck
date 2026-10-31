<?php
declare(strict_types=1);

/**
 * Employee settings — GDPR export / delete.
 *
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 * @var array $urls
 * @var bool $azcSettingsShowCardChrome
 */

$urlGenerator = $urlGenerator ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$urls = is_array($urls ?? null) ? $urls : [];
$showChrome = !empty($azcSettingsShowCardChrome);
?>
<section class="settings-section azc-card" id="settings-data-privacy" aria-labelledby="settings-privacy-heading">
	<header class="azc-card__header<?php echo $showChrome ? '' : ' azc-card__header--page-title-only'; ?>">
		<div class="azc-card__header-text">
			<?php if ($showChrome): ?>
				<h2 id="settings-privacy-heading" class="azc-card__title"><?php p($l->t('Data and privacy')); ?></h2>
				<p class="azc-card__lead"><?php p($l->t('Export or permanently delete your personal ArbeitszeitCheck data in accordance with GDPR.')); ?></p>
			<?php else: ?>
				<h2 id="settings-privacy-heading" class="azc-card__title visually-hidden"><?php p($l->t('Data and privacy')); ?></h2>
			<?php endif; ?>
		</div>
	</header>
	<div class="azc-card__body">
		<?php include __DIR__ . '/_section-intro.php'; ?>
		<div class="settings-privacy-actions">
			<a href="<?php print_unescaped((string)($urls['gdprExport'] ?? $urlGenerator->linkToRoute('arbeitszeitcheck.gdpr.export'))); ?>"
				class="azc-btn azc-btn--secondary"
				download>
				<?php p($l->t('Export My Data')); ?>
			</a>
			<button type="button"
				id="btn-gdpr-delete"
				class="azc-btn azc-btn--danger"
				data-delete-url="<?php p((string)($urls['gdprDelete'] ?? $urlGenerator->linkToRoute('arbeitszeitcheck.gdpr.delete'))); ?>"
				aria-describedby="gdpr-delete-help">
				<?php p($l->t('Delete my ArbeitszeitCheck data')); ?>
			</button>
		</div>
		<p class="form-help" id="gdpr-delete-help">
			<?php p($l->t('Deletes eligible time entries older than the configured retention period and clears your personal settings. Recent time entries, absences, audit logs, and compliance records are kept where the law requires it. This cannot be undone.')); ?>
		</p>
	</div>
</section>
