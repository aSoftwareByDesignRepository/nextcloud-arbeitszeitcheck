<?php
declare(strict_types=1);

/**
 * Employee settings — Compliance summary + app version (read-only).
 *
 * @var \OCP\IL10N $l
 * @var array $complianceProfile
 * @var string $appVersion
 * @var bool $azcSettingsShowCardChrome
 */

$complianceProfile = is_array($complianceProfile ?? null) ? $complianceProfile : [];
$appVersion = (string)($appVersion ?? '');
$showChrome = !empty($azcSettingsShowCardChrome);
?>
<section class="settings-section azc-card" aria-labelledby="settings-compliance-heading">
	<header class="azc-card__header<?php echo $showChrome ? '' : ' azc-card__header--page-title-only'; ?>">
		<div class="azc-card__header-text">
			<?php if ($showChrome): ?>
				<h2 id="settings-compliance-heading" class="azc-card__title"><?php p($l->t('Compliance Information')); ?></h2>
				<p class="azc-card__lead"><?php p((string)($complianceProfile['lead'] ?? $l->t('Key rules from German working time law that this app helps you follow.'))); ?></p>
			<?php else: ?>
				<h2 id="settings-compliance-heading" class="azc-card__title visually-hidden"><?php p($l->t('About')); ?></h2>
			<?php endif; ?>
		</div>
	</header>
	<div class="azc-card__body">
		<?php include __DIR__ . '/_section-intro.php'; ?>
		<div class="azc-callout azc-callout--neutral" role="note">
			<p class="azc-callout__text"><strong><?php p((string)($complianceProfile['lawName'] ?? $l->t('German Labor Law (Arbeitszeitgesetz - ArbZG)'))); ?></strong></p>
			<ul class="settings-callout-list">
				<li><?php p($l->t('Maximum working time: %s hours per day', [(string)($complianceProfile['maxDailyHours'] ?? '10')])); ?></li>
				<li><?php p($l->t('Minimum rest period: %s hours between working days', [(string)($complianceProfile['minRestHours'] ?? '11')])); ?></li>
				<?php foreach (($complianceProfile['breakLines'] ?? []) as $breakLine): ?>
					<li><?php p((string)$breakLine); ?></li>
				<?php endforeach; ?>
				<?php if (empty($complianceProfile['breakLines'])): ?>
					<li><?php p($l->t('Mandatory breaks: 30 min after 6 hours, 45 min after 9 hours')); ?></li>
				<?php endif; ?>
				<li><?php p((string)($complianceProfile['sundayNote'] ?? $l->t('Sunday work is generally prohibited with exceptions'))); ?></li>
			</ul>
		</div>

		<div class="settings-about-version" aria-labelledby="settings-version-heading">
			<h3 id="settings-version-heading" class="settings-about-version__title"><?php p($l->t('Version Information')); ?></h3>
			<p class="settings-version-line">
				<strong><?php p($l->t('ArbeitszeitCheck')); ?></strong>
				<?php p($l->t('Version:')); ?> <?php p($appVersion); ?>
			</p>
			<p class="settings-version-line"><?php p((string)($complianceProfile['footerBlurb'] ?? $l->t('German labor law compliant time tracking for Nextcloud'))); ?></p>
		</div>
	</div>
</section>
