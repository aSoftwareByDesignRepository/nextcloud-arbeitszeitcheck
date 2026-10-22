<?php

declare(strict_types=1);

/**
 * Last row of a data table: total, page size, and a short page list.
 *
 * Set $azcTablePager before including:
 *   id, tableId, label, base, filters, allow, state, pageParam, sizeParam, mode, hash
 *
 * @var array $azcTablePager
 * @var \OCP\IL10N $l
 */

$pager = is_array($azcTablePager ?? null) ? $azcTablePager : [];
$state = is_array($pager['state'] ?? null) ? $pager['state'] : [];
$total = max(0, (int)($state['total'] ?? 0));
$page = max(1, (int)($state['page'] ?? 1));
$pages = max(1, (int)($state['pages'] ?? 1));
$perPage = (int)($state['perPage'] ?? \OCA\ArbeitszeitCheck\Support\TablePager::DEFAULT_SIZE);
$items = is_array($state['items'] ?? null) ? $state['items'] : [1];
$filters = is_array($pager['filters'] ?? null) ? $pager['filters'] : [];
$allow = is_array($pager['allow'] ?? null) ? $pager['allow'] : ['start_date', 'end_date', 'type', 'status'];
$pageKey = \OCA\ArbeitszeitCheck\Support\TablePager::paramName((string)($pager['pageParam'] ?? 'page'), 'page');
$sizeKey = \OCA\ArbeitszeitCheck\Support\TablePager::paramName((string)($pager['sizeParam'] ?? 'per_page'), 'per_page');
$base = (string)($pager['base'] ?? '');
$id = (string)($pager['id'] ?? '');
$tableId = (string)($pager['tableId'] ?? '');
$label = (string)($pager['label'] ?? $l->t('Results pagination'));
$mode = ($pager['mode'] ?? '') === 'client' ? 'client' : 'navigate';
$hash = (string)($pager['hash'] ?? '');
if (preg_match('/^[A-Za-z][\w:-]{0,80}$/', $hash) !== 1) {
	$hash = '';
}
$rows = (string)($pager['rows'] ?? 'tbody tr[data-azc-row]');
if (preg_match('/^[a-z0-9_\[\]\-\s.#:=>]+$/i', $rows) !== 1) {
	$rows = 'tbody tr[data-azc-row]';
}
$href = static function (int $target) use ($filters, $perPage, $base, $allow, $pageKey, $sizeKey, $hash): string {
	$query = \OCA\ArbeitszeitCheck\Support\TablePager::query($filters, $target, $perPage, $allow, $pageKey, $sizeKey);
	$url = $base . ($query !== '' ? '?' . $query : '');
	return $hash !== '' ? $url . '#' . $hash : $url;
};
?>
<div class="azc-table-pager" id="<?php p($id); ?>"
	data-table="<?php p($tableId); ?>"
	data-base="<?php p($base); ?>"
	data-filters="<?php p(\OCA\ArbeitszeitCheck\Support\TablePager::query($filters, 1, \OCA\ArbeitszeitCheck\Support\TablePager::DEFAULT_SIZE, $allow, $pageKey, $sizeKey)); ?>"
	data-page="<?php p((string)$page); ?>"
	data-per-page="<?php p((string)$perPage); ?>"
	data-page-param="<?php p($pageKey); ?>"
	data-size-param="<?php p($sizeKey); ?>"
	data-mode="<?php p($mode); ?>"
	<?php if ($hash !== ''): ?>data-hash="<?php p($hash); ?>"<?php endif; ?>
	data-rows="<?php p($rows); ?>"
	data-total-template="<?php p($l->t('Total %s', ['%s'])); ?>"
	data-page-of-template="<?php p($l->t('Page %1$s of %2$s', ['%1$s', '%2$s'])); ?>"
	data-page-label="<?php p($l->t('Page %s', ['%s'])); ?>"
	data-prev-label="<?php p($l->t('Previous')); ?>"
	data-next-label="<?php p($l->t('Next')); ?>"
	<?php if ($total <= 0): ?>hidden<?php endif; ?>>
	<form class="azc-table-pager__meta" method="get" action="<?php p($base !== '' ? $base : ''); ?>">
		<?php foreach ($allow as $filterKey): ?>
			<?php if (!is_string($filterKey)): ?>
				<?php continue; ?>
			<?php endif; ?>
			<?php $filterValue = $filters[$filterKey] ?? null; ?>
			<?php if (is_string($filterValue) && $filterValue !== ''): ?>
				<input type="hidden" name="<?php p($filterKey); ?>" value="<?php p($filterValue); ?>">
			<?php endif; ?>
		<?php endforeach; ?>
		<p class="azc-table-pager__total" data-pager-total><?php p($l->t('Total %s', [(string)$total])); ?></p>
		<label class="azc-table-pager__size">
			<span class="visually-hidden"><?php p($l->t('Entries per page')); ?></span>
			<select name="<?php p($sizeKey); ?>">
				<?php foreach (\OCA\ArbeitszeitCheck\Support\TablePager::SIZES as $size): ?>
					<option value="<?php p((string)$size); ?>"<?php echo $size === $perPage ? ' selected' : ''; ?>><?php p($l->t('%s/page', [(string)$size])); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
	</form>
	<nav class="azc-table-pager__pages" data-pager-pages aria-label="<?php p($label); ?>">
		<?php if ($page > 1): ?>
			<a class="azc-table-pager__step" href="<?php p($href($page - 1)); ?>" data-page="<?php p((string)($page - 1)); ?>" rel="prev">
				<span aria-hidden="true">‹</span>
				<span class="visually-hidden"><?php p($l->t('Previous')); ?></span>
			</a>
		<?php else: ?>
			<span class="azc-table-pager__step" aria-disabled="true">
				<span aria-hidden="true">‹</span>
				<span class="visually-hidden"><?php p($l->t('Previous')); ?></span>
			</span>
		<?php endif; ?>
		<?php foreach ($items as $pagerItem): ?>
			<?php if ($pagerItem === \OCA\ArbeitszeitCheck\Support\TablePager::ELLIPSIS): ?>
				<span class="azc-table-pager__ellipsis" aria-hidden="true">…</span>
			<?php elseif ((int)$pagerItem === $page): ?>
				<span class="azc-table-pager__page is-current" aria-current="page"><?php p((string)$pagerItem); ?></span>
			<?php else: ?>
				<a class="azc-table-pager__page" href="<?php p($href((int)$pagerItem)); ?>" data-page="<?php p((string)$pagerItem); ?>" aria-label="<?php p($l->t('Page %s', [(string)$pagerItem])); ?>"><?php p((string)$pagerItem); ?></a>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php if ($page < $pages): ?>
			<a class="azc-table-pager__step" href="<?php p($href($page + 1)); ?>" data-page="<?php p((string)($page + 1)); ?>" rel="next">
				<span aria-hidden="true">›</span>
				<span class="visually-hidden"><?php p($l->t('Next')); ?></span>
			</a>
		<?php else: ?>
			<span class="azc-table-pager__step" aria-disabled="true">
				<span aria-hidden="true">›</span>
				<span class="visually-hidden"><?php p($l->t('Next')); ?></span>
			</span>
		<?php endif; ?>
	</nav>
	<p class="visually-hidden" data-pager-live aria-live="polite"><?php p($l->t('Page %1$s of %2$s', [(string)$page, (string)$pages])); ?></p>
</div>
