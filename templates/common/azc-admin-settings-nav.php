<?php

declare(strict_types=1);

/**
 * In-page topic switcher for Global settings multipage.
 *
 * Hierarchy (Bachus / granny test):
 *   Sidebar → one “Global settings” item
 *   Page → level-1 group tabs (uppercase, muted) → level-2 topic chips
 *          of the active group only
 *   H1 → current topic
 *
 * When no section is current (Nextcloud Administration mega-form index),
 * the grouped index layout is kept so every topic stays visible.
 *
 * Avoids menu-in-menu: no nested sidebar children mirroring these chips.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
/** @var array{
 *   current?: string,
 *   labels?: array<string, string>,
 *   urls?: array<string, string>,
 *   groups?: list<array{id?: string, label?: string, sections?: list<string>}>,
 *   navAriaLabel?: string,
 *   navTitle?: string
 * } $settingsPages */

$settingsPages = is_array($settingsPages ?? null) ? $settingsPages : [];
$labels = is_array($settingsPages['labels'] ?? null) ? $settingsPages['labels'] : [];
$urls = is_array($settingsPages['urls'] ?? null) ? $settingsPages['urls'] : [];
$groups = is_array($settingsPages['groups'] ?? null) ? $settingsPages['groups'] : [];
$current = (string)($settingsPages['current'] ?? '');
$navAriaLabel = (string)($settingsPages['navAriaLabel'] ?? '');
$navTitle = (string)($settingsPages['navTitle'] ?? '');
if ($labels === []) {
	return;
}
if ($navAriaLabel === '') {
	$navAriaLabel = $l->t('Settings topics');
}
if ($navTitle === '') {
	$navTitle = $l->t('Choose a topic');
}

// Flat fallback when groups missing (NC / older payloads).
if ($groups === []) {
	$groups = [[
		'id' => 'all',
		'label' => '',
		'sections' => array_keys($labels),
	]];
}

// Two-level mode only when a real section is current: the owning group's
// chips render below the group tab row. Index contexts (current === '')
// keep the grouped index layout so every topic stays reachable.
$activeGroupSections = null;
if ($current !== '') {
	foreach ($groups as $azcNavGroup) {
		$azcNavGroupSections = is_array($azcNavGroup['sections'] ?? null) ? $azcNavGroup['sections'] : [];
		if (in_array($current, $azcNavGroupSections, true)) {
			$activeGroupSections = $azcNavGroupSections;
			break;
		}
	}
}

$azcRenderSectionLinks = static function (array $sectionIds) use ($urls, $labels, $current): void {
	foreach ($sectionIds as $sectionId):
		$sectionId = (string)$sectionId;
		$href = (string)($urls[$sectionId] ?? '');
		$sectionLabel = (string)($labels[$sectionId] ?? '');
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
		<?php
	endforeach;
};
?>
<nav class="azc-settings-nav azc-settings-nav--grouped" id="azc-admin-settings-pages" aria-label="<?php p($navAriaLabel); ?>">
	<?php if ($activeGroupSections !== null): ?>
		<div class="azc-settings-nav__tabs">
			<?php foreach ($groups as $group):
				$groupLabel = (string)($group['label'] ?? '');
				$sectionIds = is_array($group['sections'] ?? null) ? $group['sections'] : [];
				if ($groupLabel === '' || $sectionIds === []) {
					continue;
				}
				$groupHref = '';
				foreach ($sectionIds as $azcTabSection) {
					$azcTabSection = (string)$azcTabSection;
					$azcTabHref = (string)($urls[$azcTabSection] ?? '');
					if ($azcTabHref !== '' && $azcTabHref !== '#') {
						$groupHref = $azcTabHref;
						break;
					}
				}
				if ($groupHref === '') {
					continue;
				}
				$groupActive = in_array($current, $sectionIds, true);
				?>
				<a class="azc-settings-nav__tab<?php echo $groupActive ? ' is-active' : ''; ?>"
					href="<?php p($groupHref); ?>"
					<?php if ($groupActive): ?>aria-current="location"<?php endif; ?>>
					<?php p($groupLabel); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<div class="azc-settings-nav__chips">
			<?php $azcRenderSectionLinks($activeGroupSections); ?>
		</div>
	<?php else: ?>
		<p class="azc-settings-nav__title" id="azc-admin-settings-topics-title"><?php p($navTitle); ?></p>
		<?php foreach ($groups as $group):
			$groupId = (string)($group['id'] ?? '');
			$groupLabel = (string)($group['label'] ?? '');
			$sectionIds = is_array($group['sections'] ?? null) ? $group['sections'] : [];
			if ($sectionIds === []) {
				continue;
			}
			$groupTitleId = $groupId !== '' ? 'azc-settings-group-' . $groupId : '';
			?>
			<div class="azc-settings-nav__group"<?php if ($groupTitleId !== '' && $groupLabel !== ''): ?> role="group" aria-labelledby="<?php p($groupTitleId); ?>"<?php endif; ?>>
				<?php if ($groupLabel !== ''): ?>
					<p class="azc-settings-nav__group-label"<?php if ($groupTitleId !== ''): ?> id="<?php p($groupTitleId); ?>"<?php endif; ?>><?php p($groupLabel); ?></p>
				<?php endif; ?>
				<div class="azc-settings-nav__chips">
					<?php $azcRenderSectionLinks($sectionIds); ?>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</nav>
