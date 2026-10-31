<?php

declare(strict_types=1);

/**
 * In-page topic switcher for employee My settings multipage.
 *
 * One row of topic links. The active topic carries the underline.
 * Sidebar stays a single “My settings” item; the page H1 is the current topic.
 *
 * Expects: $l, $settingsPages from EmployeeSettingsSectionCatalog::chipBarPayload()
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
/** @var array{
 *   current?: string,
 *   labels?: array<string, string>,
 *   urls?: array<string, string>,
 *   navAriaLabel?: string
 * } $settingsPages */

$settingsPages = is_array($settingsPages ?? null) ? $settingsPages : [];
$labels = is_array($settingsPages['labels'] ?? null) ? $settingsPages['labels'] : [];
$urls = is_array($settingsPages['urls'] ?? null) ? $settingsPages['urls'] : [];
$current = (string)($settingsPages['current'] ?? '');
$navAriaLabel = (string)($settingsPages['navAriaLabel'] ?? '');
if ($labels === []) {
	return;
}
if ($navAriaLabel === '') {
	$navAriaLabel = $l->t('My settings topics');
}
?>
<nav class="azc-settings-nav" id="azc-employee-settings-pages" aria-label="<?php p($navAriaLabel); ?>">
	<div class="azc-settings-nav__chips">
		<?php foreach ($labels as $sectionId => $sectionLabel):
			$sectionId = (string)$sectionId;
			$href = (string)($urls[$sectionId] ?? '');
			$sectionLabel = (string)$sectionLabel;
			if ($href === '' || $href === '#' || $sectionLabel === '') {
				continue;
			}
			$active = $current !== '' && $current === $sectionId;
			?>
			<a class="azc-settings-nav__link<?php echo $active ? ' is-active' : ''; ?>"
				href="<?php p($href); ?>"
				<?php if ($active): ?>aria-current="page"<?php endif; ?>>
				<?php p($sectionLabel); ?>
			</a>
		<?php endforeach; ?>
	</div>
</nav>
