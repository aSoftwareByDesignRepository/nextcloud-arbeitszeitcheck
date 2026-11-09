<?php

declare(strict_types=1);

/**
 * In-page topic switcher for admin policy settings (Vacation / Overtime / Alerts).
 *
 * Hierarchy (Bachus / granny test — same pattern as Global settings):
 *   Sidebar → one “Policy settings” item
 *   Page → level-1 group tabs (uppercase, muted) → level-2 topic chips
 *          of the active group only
 *   H1 → current topic
 *
 * No nested sidebar children and no “On this page” jump nav.
 *
 * Expects: $l, $policyPages from AdminPolicyPagesCatalog::chipBarPayload()
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
/** @var array{
 *   current?: string,
 *   labels?: array<string, string>,
 *   urls?: array<string, string>,
 *   groups?: list<array{id?: string, label?: string, sections?: list<string>}>
 * } $policyPages */

$policyPages = is_array($policyPages ?? null) ? $policyPages : [];
$labels = is_array($policyPages['labels'] ?? null) ? $policyPages['labels'] : [];
$urls = is_array($policyPages['urls'] ?? null) ? $policyPages['urls'] : [];
$groups = is_array($policyPages['groups'] ?? null) ? $policyPages['groups'] : [];
$current = (string)($policyPages['current'] ?? '');
if ($labels === []) {
	return;
}

if ($groups === []) {
	$groups = [[
		'id' => 'all',
		'label' => '',
		'sections' => array_keys($labels),
	]];
}

// Two-level mode only when a real section is current; index contexts keep
// the grouped index layout so every topic stays reachable.
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
<nav class="azc-settings-nav azc-settings-nav--grouped" id="azc-policy-pages" aria-label="<?php p($l->t('Policy topics')); ?>">
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
		<p class="azc-settings-nav__title" id="azc-policy-topics-title"><?php p($l->t('Choose a topic')); ?></p>
		<?php foreach ($groups as $group):
			$groupId = (string)($group['id'] ?? '');
			$groupLabel = (string)($group['label'] ?? '');
			$sectionIds = is_array($group['sections'] ?? null) ? $group['sections'] : [];
			if ($sectionIds === []) {
				continue;
			}
			$groupTitleId = $groupId !== '' ? 'azc-policy-group-' . $groupId : '';
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
