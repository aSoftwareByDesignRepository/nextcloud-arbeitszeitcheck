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
if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
	$pageUrl = $appFeedbackLinks->sanitizePageUrl((string)$_SERVER['REQUEST_URI']);
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

$sbdFeedbackIcon = static function (string $iconPrefix, string $inner): string {
	$class = htmlspecialchars($iconPrefix . '-icon', ENT_QUOTES, 'UTF-8');

	return sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="%s" aria-hidden="true" focusable="false">%s</svg>',
		$class,
		$inner
	);
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
				print_unescaped($sbdFeedbackIcon($prefix, '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><circle cx="12" cy="8" r="1" fill="currentColor" stroke="none"/>'));
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
						print_unescaped($sbdFeedbackIcon($prefix, '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><circle cx="12" cy="16" r="1" fill="currentColor" stroke="none"/>'));
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
						print_unescaped($sbdFeedbackIcon($prefix, '<path d="M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/>'));
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
						print_unescaped($sbdFeedbackIcon($prefix, '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M16 13H8M16 17H8M10 9H8"/>'));
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
