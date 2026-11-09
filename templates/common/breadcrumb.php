<?php
/**
 * ArbeitszeitCheck breadcrumb trail (page chrome).
 *
 * Expected params:
 *   pageTitle          current-page label (aria-current, never linked)
 *   urls.dashboard     app root URL for the first crumb
 *   breadcrumbParents  ordered ancestor list [{label, url?}] — an item
 *                      without a non-empty url renders as plain text
 *   breadcrumbParent   legacy single ancestor {label, url} (fallback)
 *   breadcrumbSection  legacy plain-text ancestor label (last fallback)
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$azcCrumbTitle = (string)($_['pageTitle'] ?? '');
$azcCrumbUrls = $_['urls'] ?? [];
$azcCrumbHome = is_array($azcCrumbUrls) ? (string)($azcCrumbUrls['dashboard'] ?? '#') : '#';
$azcCrumbSection = (string)($_['breadcrumbSection'] ?? '');
$azcCrumbParent = is_array($_['breadcrumbParent'] ?? null) ? $_['breadcrumbParent'] : null;

$azcCrumbParents = [];
foreach ((is_array($_['breadcrumbParents'] ?? null) ? $_['breadcrumbParents'] : []) as $azcCrumb) {
	if (!is_array($azcCrumb) || (string)($azcCrumb['label'] ?? '') === '') {
		continue;
	}
	$azcCrumbParents[] = [
		'label' => (string)$azcCrumb['label'],
		'url' => (string)($azcCrumb['url'] ?? ''),
	];
}
if ($azcCrumbParents === [] && is_array($azcCrumbParent) && (string)($azcCrumbParent['label'] ?? '') !== '') {
	$azcCrumbParents[] = [
		'label' => (string)$azcCrumbParent['label'],
		'url' => (string)($azcCrumbParent['url'] ?? ''),
	];
} elseif ($azcCrumbParents === [] && $azcCrumbSection !== '') {
	$azcCrumbParents[] = ['label' => $azcCrumbSection, 'url' => ''];
}
?>
<nav class="azc-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
	<ol class="azc-breadcrumb__list">
		<li class="azc-breadcrumb__item">
			<a class="azc-breadcrumb__link" href="<?php p($azcCrumbHome); ?>">
				<?php p($l->t('ArbeitszeitCheck')); ?>
			</a>
		</li>
		<?php foreach ($azcCrumbParents as $azcCrumb): ?>
			<li class="azc-breadcrumb__item">
				<?php if ($azcCrumb['url'] !== ''): ?>
					<a class="azc-breadcrumb__link" href="<?php p($azcCrumb['url']); ?>">
						<?php p($azcCrumb['label']); ?>
					</a>
				<?php else: ?>
					<span class="azc-breadcrumb__text"><?php p($azcCrumb['label']); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
		<li class="azc-breadcrumb__item azc-breadcrumb__item--current" aria-current="page">
			<span class="azc-breadcrumb__current"><?php p($azcCrumbTitle); ?></span>
		</li>
	</ol>
</nav>
