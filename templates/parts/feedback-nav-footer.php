<?php

declare(strict_types=1);

/**
 * Nav footer: single "Help" button that opens a dropdown with
 * Report a problem / Suggest an improvement / GitHub Issues.
 * (Commercial "Support & us" is a separate nav page — do not reuse that label here.)
 *
 * Expected variables (set by the including template):
 * @var \OCP\IL10N $l
 * @var \OCA\ArbeitszeitCheck\Support\AppFeedbackLinks $appFeedbackLinks optional; constructed when omitted
 * @var string $appFeedbackCssPrefix CSS BEM prefix (e.g. azc, dc, crm)
 * @var string|null $appFeedbackLanguageCode
 * @var string|null $appFeedbackVersion
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

use OCA\ArbeitszeitCheck\Support\AppFeedbackLinks;

$l = $l ?? (\OCP\Util::getL10N('arbeitszeitcheck'));
$prefix = isset($appFeedbackCssPrefix) && is_string($appFeedbackCssPrefix) && $appFeedbackCssPrefix !== ''
	? preg_replace('/[^a-z0-9\-]/i', '', $appFeedbackCssPrefix)
	: 'azc';
$lang = isset($appFeedbackLanguageCode) && is_string($appFeedbackLanguageCode) && $appFeedbackLanguageCode !== ''
	? $appFeedbackLanguageCode
	: (method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en');
$version = isset($appFeedbackVersion) && is_string($appFeedbackVersion) ? $appFeedbackVersion : '';
if ($version === '' && class_exists(\OCP\Server::class)) {
	try {
		$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
		$resolved = trim((string)$appManager->getAppVersion('arbeitszeitcheck'));
		if ($resolved !== '') {
			$version = $resolved;
		}
	} catch (\Throwable) {
		$version = '';
	}
}
if (!isset($appFeedbackLinks) || !$appFeedbackLinks instanceof AppFeedbackLinks) {
	$appFeedbackLinks = new AppFeedbackLinks('arbeitszeitcheck', 'ArbeitszeitCheck', $version);
}
$pageUrl = '';
if (isset($appFeedbackPageUrl) && is_string($appFeedbackPageUrl)) {
	// Callers that already resolved the current request path may inject it.
	$pageUrl = $appFeedbackLinks->sanitizePageUrl($appFeedbackPageUrl);
} elseif (class_exists(\OCP\Server::class)) {
	try {
		$feedbackRequest = \OCP\Server::get(\OCP\IRequest::class);
		$pageUrl = $appFeedbackLinks->sanitizePageUrl($feedbackRequest->getRequestUri());
	} catch (\Throwable) {
		$pageUrl = '';
	}
}
$ncVersion = '';
if (class_exists(\OCP\Server::class)) {
	try {
		$config = \OCP\Server::get(\OCP\IConfig::class);
		$ncVersion = (string)$config->getSystemValue('version', '');
	} catch (\Throwable) {
		$ncVersion = '';
	}
}
$ctx = [
	'pageUrl' => $pageUrl,
	'locale' => $lang,
	'ncVersion' => $ncVersion,
];
$links = $appFeedbackLinks->forLocale($lang, $ctx);
$github = (string)($links['githubIssuesUrl'] ?? '');
$footerId = $prefix . '-nav-footer';
$menuId = $prefix . '-feedback-menu';
$newTab = $l->t('(opens in a new tab)');

// Decorative nav-footer glyphs come from the app's IconCatalog (no inline SVG
// in templates — the catalog is the single icon source). Candidate lists keep
// the partial working across family apps whose catalogs diverged; when no
// catalog/icon resolves, the item still renders its text label (aria-hidden
// decoration is never required for comprehension).
$sbdFeedbackIcon = static function (string $iconPrefix, array $candidates): string {
	$catalog = '\\' . 'OCA\ArbeitszeitCheck' . '\\Service\\IconCatalog';
	if (!class_exists($catalog) || !method_exists($catalog, 'render')) {
		return '';
	}
	foreach ($candidates as $name) {
		try {
			if (method_exists($catalog, 'has') && !$catalog::has($name)) {
				continue;
			}
			$html = $catalog::render($name, $iconPrefix . '-icon');
			if (is_string($html) && $html !== '') {
				return $html;
			}
		} catch (\Throwable) {
			// try the next candidate
		}
	}
	return '';
};
?>
<nav
	class="<?php p($prefix); ?>-nav-footer"
	id="<?php p($footerId); ?>"
	aria-label="<?php p($l->t('Help')); ?>"
	data-app-feedback="1"
	data-app-feedback-app="<?php p((string)$links['appId']); ?>"
>
	<div class="<?php p($prefix); ?>-nav-footer__popover">
		<button
			type="button"
			class="<?php p($prefix); ?>-nav-footer__trigger"
			aria-expanded="false"
			aria-controls="<?php p($menuId); ?>"
			aria-haspopup="true"
		>
			<span class="<?php p($prefix); ?>-nav-footer__trigger-icon" aria-hidden="true"><?php
				print_unescaped($sbdFeedbackIcon($prefix, ['help-circle', 'circle-help', 'help', 'info']));
			?></span>
			<span class="<?php p($prefix); ?>-nav-footer__trigger-label"><?php p($l->t('Help')); ?></span>
		</button>
		<ul
			class="<?php p($prefix); ?>-nav-footer__menu"
			id="<?php p($menuId); ?>"
			role="menu"
			hidden
		>
			<li role="none">
				<a
					class="<?php p($prefix); ?>-nav-footer__menu-item"
					role="menuitem"
					id="<?php p($prefix); ?>-feedback-problem"
					href="<?php p((string)$links['problemMailto']); ?>"
					data-app-feedback-kind="problem"
				>
					<span class="<?php p($prefix); ?>-nav-footer__menu-icon" aria-hidden="true"><?php
						print_unescaped($sbdFeedbackIcon($prefix, ['alert-circle', 'circle-alert', 'alert-triangle', 'triangle-alert', 'info']));
					?></span>
					<?php p($l->t('Report a problem')); ?>
				</a>
			</li>
			<li role="none">
				<a
					class="<?php p($prefix); ?>-nav-footer__menu-item"
					role="menuitem"
					id="<?php p($prefix); ?>-feedback-idea"
					href="<?php p((string)$links['ideaMailto']); ?>"
					data-app-feedback-kind="idea"
				>
					<span class="<?php p($prefix); ?>-nav-footer__menu-icon" aria-hidden="true"><?php
						print_unescaped($sbdFeedbackIcon($prefix, ['square-pen', 'edit', 'pencil']));
					?></span>
					<?php p($l->t('Suggest an improvement')); ?>
				</a>
			</li>
			<?php if ($github !== ''): ?>
			<li role="none">
				<a
					class="<?php p($prefix); ?>-nav-footer__menu-item"
					role="menuitem"
					id="<?php p($prefix); ?>-feedback-github"
					href="<?php p($github); ?>"
					target="_blank"
					rel="noopener noreferrer"
				>
					<span class="<?php p($prefix); ?>-nav-footer__menu-icon" aria-hidden="true"><?php
						print_unescaped($sbdFeedbackIcon($prefix, ['file-text', 'file', 'book', 'info']));
					?></span>
					<?php p($l->t('Open GitHub Issues')); ?>
					<span class="<?php p($prefix); ?>-nav-footer__new-tab"><?php p($newTab); ?></span>
				</a>
			</li>
			<?php endif; ?>
		</ul>
		<p class="<?php p($prefix); ?>-nav-footer__note">
			<?php p($l->t('Email is best-effort — no reply SLA. Need booked help? Use Support & us.')); ?>
		</p>
	</div>
	<script type="application/json" id="<?php p($prefix); ?>-app-feedback-config"><?php
		print_unescaped(json_encode([
			'appId' => $links['appId'],
			'appDisplayName' => $links['appDisplayName'],
			'appVersion' => $links['appVersion'],
			'feedbackEmail' => $links['feedbackEmail'],
			'githubIssuesUrl' => $github,
			'problemMailto' => $links['problemMailto'],
			'ideaMailto' => $links['ideaMailto'],
			'cssPrefix' => $prefix,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP));
	?></script>
</nav>
