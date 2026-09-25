<?php

declare(strict_types=1);

/**
 * Support & Us — admin settings surface (family standard).
 *
 * Expected variables (set by the including settings template):
 * @var \OCP\IL10N $l
 * @var \OCA\ArbeitszeitCheck\Support\SupportUsLinks $supportUsLinks
 * @var string $supportUsCssPrefix CSS BEM prefix for support-us + element ids (e.g. azc, bc, dkc)
 * @var string $supportUsShellPrefix optional card/section design-system prefix (defaults to css prefix)
 * @var string $supportUsBtnPrimaryClass
 * @var string $supportUsBtnSecondaryClass
 * @var string|null $supportUsLanguageCode optional override; defaults to $l->getLanguageCode()
 * @var string|null $supportUsPresentation 'page' for dedicated Support & us screens
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

if (!isset($supportUsLinks) || !$supportUsLinks instanceof \OCA\ArbeitszeitCheck\Support\SupportUsLinks) {
	return;
}

$l = $l ?? (\OCP\Util::getL10N('arbeitszeitcheck'));
$lang = isset($supportUsLanguageCode) && is_string($supportUsLanguageCode) && $supportUsLanguageCode !== ''
	? $supportUsLanguageCode
	: (method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en');
$links = $supportUsLinks->forLocale($lang);
$prefix = isset($supportUsCssPrefix) && is_string($supportUsCssPrefix) && $supportUsCssPrefix !== ''
	? preg_replace('/[^a-z0-9\-]/i', '', $supportUsCssPrefix)
	: 'azc';
$shell = isset($supportUsShellPrefix) && is_string($supportUsShellPrefix) && $supportUsShellPrefix !== ''
	? preg_replace('/[^a-z0-9\-]/i', '', $supportUsShellPrefix)
	: $prefix;
$btnPrimary = isset($supportUsBtnPrimaryClass) && is_string($supportUsBtnPrimaryClass) && $supportUsBtnPrimaryClass !== ''
	? $supportUsBtnPrimaryClass
	: 'button primary';
$btnSecondary = isset($supportUsBtnSecondaryClass) && is_string($supportUsBtnSecondaryClass) && $supportUsBtnSecondaryClass !== ''
	? $supportUsBtnSecondaryClass
	: 'button';
$isPage = isset($supportUsPresentation) && $supportUsPresentation === 'page';
$appName = (string)$links['appDisplayName'];
$sectionId = $prefix . '-support-us';
$titleId = $prefix . '-support-us-title';
$introId = $prefix . '-support-us-intro';
$partnerTitleId = $prefix . '-support-us-partner-title';
$secondaryLabelId = $prefix . '-support-us-secondary-label';
$hasMobile = !empty($links['hasOfficialMobileLicenses']) && !empty($links['licensePageUrl']);
$sectionClass = $prefix . '-support-us';
if ($isPage) {
	$sectionClass .= ' ' . $prefix . '-support-us--page';
} else {
	$sectionClass = $shell . '-card ' . $shell . '-section ' . $sectionClass;
}
$newTab = $l->t('(opens in a new tab)');
?>
<section
	class="<?php p($sectionClass); ?>"
	id="<?php p($sectionId); ?>"
	aria-labelledby="<?php p($titleId); ?>"
	aria-describedby="<?php p($introId); ?>"
	data-support-us="1"
	data-support-us-presentation="<?php p($isPage ? 'page' : 'embed'); ?>"
>
	<header class="<?php p($shell); ?>-section__header <?php p($prefix); ?>-support-us__header<?php p($isPage ? ' ' . $prefix . '-support-us__header--page' : ''); ?>">
		<div>
			<h2 id="<?php p($titleId); ?>" class="<?php p($shell); ?>-card__title <?php p($prefix); ?>-support-us__title">
				<?php p($l->t('Support & us')); ?>
			</h2>
			<p id="<?php p($introId); ?>" class="<?php p($shell); ?>-section__sub <?php p($prefix); ?>-support-us__intro">
				<?php
				// Match Block A to Block E: never mention mobile when the license CTA is hidden.
				$introKey = $hasMobile
					? '%s stays free (AGPL) on your Nextcloud. Bug reports and ideas on GitHub stay welcome — that is free open-source care. If your organisation needs bookable help on an invoice — or official mobile licenses — choose an option below:'
					: '%s stays free (AGPL) on your Nextcloud. Bug reports and ideas on GitHub stay welcome — that is free open-source care. If your organisation needs bookable help on an invoice, choose an option below:';
				p($l->t($introKey, [$appName]));
				?>
			</p>
		</div>
	</header>

	<div class="<?php p($prefix); ?>-support-us__body">
		<div
			class="<?php p($prefix); ?>-support-us__primary"
			aria-labelledby="<?php p($partnerTitleId); ?>"
		>
			<div class="<?php p($prefix); ?>-support-us__primary-copy">
				<h3 id="<?php p($partnerTitleId); ?>" class="<?php p($prefix); ?>-support-us__offer-title">
					<?php p($l->t('Check Partner')); ?>
				</h3>
				<p class="<?php p($prefix); ?>-support-us__benefit">
					<?php p($l->t('Annual hour packs — Small, Standard, or Premium — with priority email for your organisation. This is invoiceable service — not a donation. See packages on our support page.')); ?>
				</p>
				<p class="<?php p($prefix); ?>-support-us__coverage">
					<?php p($l->t('List prices on our site apply to published Check apps. For this app, ask for an individual partner offer — we invoice only after you accept a quote.')); ?>
				</p>
			</div>
			<div class="<?php p($prefix); ?>-support-us__primary-actions">
				<a
					class="<?php p($btnPrimary); ?> <?php p($prefix); ?>-support-us__cta <?php p($prefix); ?>-support-us__cta--primary"
					href="<?php p($links['partnerMailto']); ?>"
				>
					<?php p($l->t('Ask for a partner offer')); ?>
				</a>
				<p class="<?php p($prefix); ?>-support-us__hint">
					<?php p($l->t('Packages and terms:')); ?>
					<a
						href="<?php p($links['supportPageUrl']); ?>"
						target="_blank"
						rel="noopener noreferrer"
					><?php p($l->t('Open support page')); ?><span class="<?php p($prefix); ?>-support-us__new-tab"><?php p($newTab); ?></span></a>
				</p>
			</div>
		</div>

		<div class="<?php p($prefix); ?>-support-us__secondary" role="group" aria-labelledby="<?php p($secondaryLabelId); ?>">
			<h3 id="<?php p($secondaryLabelId); ?>" class="<?php p($prefix); ?>-support-us__secondary-title">
				<?php p($l->t('Additional invoiceable options')); ?>
			</h3>
			<div class="<?php p($prefix); ?>-support-us__options">
				<div class="<?php p($prefix); ?>-support-us__option">
					<h4 class="<?php p($prefix); ?>-support-us__option-title">
						<?php p($l->t('Setup & training')); ?>
					</h4>
					<p class="<?php p($prefix); ?>-support-us__option-hint">
						<?php p($l->t('Remote onboarding or a workshop so your team can roll out cleanly — billed as a service.')); ?>
					</p>
					<a
						class="<?php p($btnSecondary); ?> <?php p($prefix); ?>-support-us__cta <?php p($prefix); ?>-support-us__cta--secondary"
						href="<?php p($links['onboardingMailto']); ?>"
					>
						<?php p($l->t('Ask about setup or training')); ?>
					</a>
				</div>
				<div class="<?php p($prefix); ?>-support-us__option">
					<h4 class="<?php p($prefix); ?>-support-us__option-title">
						<?php p($l->t('Commissioned feature')); ?>
					</h4>
					<p class="<?php p($prefix); ?>-support-us__option-hint">
						<?php p($l->t('A scoped change with acceptance criteria and a delivery date — billed as project work.')); ?>
					</p>
					<a
						class="<?php p($btnSecondary); ?> <?php p($prefix); ?>-support-us__cta <?php p($prefix); ?>-support-us__cta--secondary"
						href="<?php p($links['featureMailto']); ?>"
					>
						<?php p($l->t('Request a commissioned feature')); ?>
					</a>
				</div>
				<?php if ($hasMobile): ?>
					<div class="<?php p($prefix); ?>-support-us__option">
						<h4 class="<?php p($prefix); ?>-support-us__option-title">
							<?php p($l->t('Mobile & terminal')); ?>
						</h4>
						<p class="<?php p($prefix); ?>-support-us__option-hint">
							<?php p($l->t('Named seats for the official apps — a software licence on invoice.')); ?>
						</p>
						<a
							class="<?php p($btnSecondary); ?> <?php p($prefix); ?>-support-us__cta <?php p($prefix); ?>-support-us__cta--secondary"
							href="<?php p($links['licensePageUrl']); ?>"
						>
							<?php p($l->t('Official mobile & terminal licenses')); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="<?php p($prefix); ?>-support-us__tertiary">
			<p class="<?php p($prefix); ?>-support-us__more">
				<a
					href="<?php p($links['appsPageUrl']); ?>"
					target="_blank"
					rel="noopener noreferrer"
				><?php p($l->t('More Check apps')); ?><span class="<?php p($prefix); ?>-support-us__new-tab"><?php p($newTab); ?></span></a>
				<span aria-hidden="true"> · </span>
				<a
					href="<?php p($links['sponsorsUrl']); ?>"
					target="_blank"
					rel="noopener noreferrer"
				><?php p($l->t('GitHub Sponsors (voluntary, no invoice SLA)')); ?><span class="<?php p($prefix); ?>-support-us__new-tab"><?php p($newTab); ?></span></a>
			</p>
			<p class="<?php p($prefix); ?>-support-us__contact">
				<a href="<?php p($links['contactMailto']); ?>"><?php p($links['contactEmail']); ?></a>
				<span aria-hidden="true"> · </span>
				<span><?php p($links['vendorName']); ?></span>
			</p>
		</div>
	</div>
</section>
