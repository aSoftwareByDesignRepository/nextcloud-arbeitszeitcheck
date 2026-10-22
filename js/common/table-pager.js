/**
 * Table footer: total, page size, and a short numbered page list.
 * The first paint comes from PHP. This keeps page changes on the rows
 * already in the table, and keeps the total honest after a row is removed.
 * Each footer names its own page and size query keys, so two tables on
 * one address do not share a page.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function () {
	'use strict';

	var SIZES = [10, 25, 50, 100];
	var DEFAULT_SIZE = 25;
	var ELLIPSIS = 'ellipsis';

	function perPage(raw) {
		var value = typeof raw === 'number' ? String(raw) : String(raw == null ? '' : raw);
		return SIZES.indexOf(Number(value)) !== -1 && /^(10|25|50|100)$/.test(value) ? Number(value) : DEFAULT_SIZE;
	}

	function paramName(raw, fallback) {
		return /^[a-zA-Z][a-zA-Z0-9_]{0,40}$/.test(String(raw || '')) ? String(raw) : fallback;
	}

	function pageKeyOf(nav) {
		return paramName(nav.dataset.pageParam, 'page');
	}

	function sizeKeyOf(nav) {
		return paramName(nav.dataset.sizeParam, 'per_page');
	}

	function items(page, pages) {
		pages = Math.max(1, pages);
		page = Math.min(Math.max(1, page), pages);
		if (pages <= 7) {
			var all = [];
			var n;
			for (n = 1; n <= pages; n += 1) {
				all.push(n);
			}
			return all;
		}
		if (page <= 4) {
			return [1, 2, 3, 4, ELLIPSIS, pages];
		}
		if (page >= pages - 3) {
			return [1, ELLIPSIS, pages - 3, pages - 2, pages - 1, pages];
		}
		return [1, ELLIPSIS, page - 1, page, page + 1, ELLIPSIS, pages];
	}

	function fill(template, values) {
		var text = String(template || '');
		text = text.replace('%1$s', String(values[0]));
		text = text.replace('%2$s', String(values[1] == null ? '' : values[1]));
		return text.replace('%s', String(values[0]));
	}

	function hashFor(nav) {
		var own = nav.dataset.hash || '';
		if (/^[A-Za-z][\w:-]{0,80}$/.test(own)) {
			return '#' + own;
		}
		var current = window.location && window.location.hash ? window.location.hash : '';
		return /^#[A-Za-z][\w:-]{0,80}$/.test(current) ? current : '';
	}

	function hrefFor(nav, page, size) {
		var params = new URLSearchParams(window.location ? window.location.search : '');
		var filters = new URLSearchParams(nav.dataset.filters || '');
		filters.forEach(function (value, key) {
			params.set(key, value);
		});
		var pagers = Array.prototype.slice.call(document.querySelectorAll('.azc-table-pager'));
		if (pagers.indexOf(nav) === -1) {
			pagers.push(nav);
		}
		pagers.forEach(function (other) {
			var pKey = pageKeyOf(other);
			var sKey = sizeKeyOf(other);
			params.delete(pKey);
			params.delete(sKey);
			var targetPage = other === nav ? page : (Number(other.dataset.page) || 1);
			var targetSize = other === nav ? size : perPage(other.dataset.perPage);
			if (targetSize !== DEFAULT_SIZE) {
				params.set(sKey, String(targetSize));
			}
			if (targetPage > 1) {
				params.set(pKey, String(targetPage));
			}
		});
		var query = params.toString();
		var url = (nav.dataset.base || '') + (query ? '?' + query : '');
		return url + hashFor(nav);
	}

	function dataRows(nav) {
		var table = document.getElementById(nav.dataset.table || '');
		if (!table) {
			return [];
		}
		var selector = nav.dataset.rows || 'tbody tr[data-azc-row]';
		var rows = Array.prototype.slice.call(table.querySelectorAll(selector));
		if (rows.length === 0 && !nav.dataset.rows) {
			rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-absence-id]'));
		}
		return rows.filter(function (row) {
			return !row.classList.contains('azc-kiosk-empty-row')
				&& !row.classList.contains('azc-table-empty')
				&& !row.querySelector('.azc-empty-state, .empty-state');
		});
	}

	function stepControl(nav, kind, targetPage) {
		var enabled = targetPage > 0;
		var node = document.createElement(enabled ? 'a' : 'span');
		node.className = 'azc-table-pager__step';
		if (enabled) {
			node.href = hrefFor(nav, targetPage, perPage(nav.dataset.perPage));
			node.dataset.page = String(targetPage);
			node.rel = kind === 'prev' ? 'prev' : 'next';
		} else {
			node.setAttribute('aria-disabled', 'true');
		}
		var glyph = document.createElement('span');
		glyph.setAttribute('aria-hidden', 'true');
		glyph.textContent = kind === 'prev' ? '‹' : '›';
		var name = document.createElement('span');
		name.className = 'visually-hidden';
		name.textContent = kind === 'prev' ? (nav.dataset.prevLabel || 'Previous') : (nav.dataset.nextLabel || 'Next');
		node.append(glyph, name);
		return node;
	}

	function pageControl(nav, number, current) {
		if (number === current) {
			var currentNode = document.createElement('span');
			currentNode.className = 'azc-table-pager__page is-current';
			currentNode.setAttribute('aria-current', 'page');
			currentNode.textContent = String(number);
			return currentNode;
		}
		var link = document.createElement('a');
		link.className = 'azc-table-pager__page';
		link.href = hrefFor(nav, number, perPage(nav.dataset.perPage));
		link.dataset.page = String(number);
		link.setAttribute('aria-label', fill(nav.dataset.pageLabel || 'Page %s', [number]));
		link.textContent = String(number);
		return link;
	}

	function paint(nav, page, pages, total) {
		var totalEl = nav.querySelector('[data-pager-total]');
		if (totalEl) {
			totalEl.textContent = fill(nav.dataset.totalTemplate || 'Total %s', [total]);
		}
		var live = nav.querySelector('[data-pager-live]');
		if (live) {
			live.textContent = fill(nav.dataset.pageOfTemplate || 'Page %1$s of %2$s', [page, pages]);
		}
		var list = nav.querySelector('[data-pager-pages]');
		if (!list) {
			return;
		}
		var fragment = document.createDocumentFragment();
		fragment.append(stepControl(nav, 'prev', page > 1 ? page - 1 : 0));
		items(page, pages).forEach(function (item) {
			if (item === ELLIPSIS) {
				var dots = document.createElement('span');
				dots.className = 'azc-table-pager__ellipsis';
				dots.setAttribute('aria-hidden', 'true');
				dots.textContent = '…';
				fragment.append(dots);
				return;
			}
			fragment.append(pageControl(nav, item, page));
		});
		fragment.append(stepControl(nav, 'next', page < pages ? page + 1 : 0));
		list.replaceChildren(fragment);
	}

	function remember(nav, page, size, push) {
		nav.dataset.page = String(page);
		nav.dataset.perPage = String(size);
		if (!window.history || typeof window.history.replaceState !== 'function') {
			return;
		}
		var url = hrefFor(nav, page, size);
		if (push && typeof window.history.pushState === 'function') {
			window.history.pushState({ azcTablePager: true }, '', url);
		} else {
			window.history.replaceState({ azcTablePager: true }, '', url);
		}
	}

	function showPage(nav, page, push) {
		var size = perPage(nav.dataset.perPage);
		var rows = dataRows(nav);
		var total = rows.length;
		if (total === 0) {
			nav.hidden = true;
			return;
		}
		var pages = Math.max(1, Math.ceil(total / size));
		page = Math.min(Math.max(1, page), pages);
		var start = (page - 1) * size;
		rows.forEach(function (row, index) {
			row.hidden = index < start || index >= start + size;
		});
		nav.hidden = false;
		nav.dataset.page = String(page);
		nav.dataset.perPage = String(size);
		paint(nav, page, pages, total);
		remember(nav, page, size, push);
	}

	function applySize(nav, size) {
		size = perPage(size);
		if (nav.dataset.mode === 'client') {
			nav.dataset.perPage = String(size);
			var select = nav.querySelector('select');
			if (select) {
				select.value = String(size);
			}
			showPage(nav, 1, true);
			return;
		}
		window.location.assign(hrefFor(nav, 1, size));
	}

	function onPopState() {
		var params = new URLSearchParams(window.location.search);
		document.querySelectorAll('.azc-table-pager').forEach(function (nav) {
			var rawPage = params.get(pageKeyOf(nav));
			var page = rawPage && /^[1-9]\d*$/.test(rawPage) ? Number(rawPage) : 1;
			var rawSize = params.get(sizeKeyOf(nav));
			if (rawSize) {
				nav.dataset.perPage = String(perPage(rawSize));
				var select = nav.querySelector('select');
				if (select) {
					select.value = nav.dataset.perPage;
				}
			}
			showPage(nav, page, false);
		});
	}

	function bindPopState() {
		if (window.__azcTablePagerPop === true) {
			return;
		}
		window.__azcTablePagerPop = true;
		window.addEventListener('popstate', onPopState);
	}

	function watchRows(nav) {
		if (nav.dataset.rowWatch === '1' || typeof MutationObserver !== 'function') {
			return;
		}
		var table = document.getElementById(nav.dataset.table || '');
		var body = table && table.tBodies ? table.tBodies[0] : null;
		if (!body) {
			return;
		}
		nav.dataset.rowWatch = '1';
		var observer = new MutationObserver(function () {
			resync(nav);
		});
		observer.observe(body, { childList: true });
	}

	function attach(nav) {
		if (!nav || nav.dataset.pagerReady === '1') {
			return;
		}
		nav.dataset.pagerReady = '1';
		bindPopState();
		watchRows(nav);
		var form = nav.querySelector('form');
		var select = nav.querySelector('select');
		if (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				applySize(nav, select ? select.value : DEFAULT_SIZE);
			});
		}
		if (select) {
			select.addEventListener('change', function () {
				if (typeof form.requestSubmit === 'function') {
					form.requestSubmit();
				} else if (form) {
					form.dispatchEvent(new Event('submit', { cancelable: true }));
				}
			});
		}
		nav.addEventListener('click', function (event) {
			if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return;
			}
			var link = event.target.closest('a[data-page]');
			if (!link || !nav.contains(link)) {
				return;
			}
			event.preventDefault();
			showPage(nav, Number(link.dataset.page), true);
		});
	}

	function resync(nav) {
		if (!nav) {
			return;
		}
		showPage(nav, Number(nav.dataset.page) || 1, false);
	}

	function boot() {
		document.querySelectorAll('.azc-table-pager').forEach(attach);
	}

	var api = {
		SIZES: SIZES,
		DEFAULT_SIZE: DEFAULT_SIZE,
		ELLIPSIS: ELLIPSIS,
		perPage: perPage,
		items: items,
		fill: fill,
		hrefFor: hrefFor,
		attach: attach,
		resync: resync,
		showPage: showPage,
	};

	if (typeof window !== 'undefined') {
		window.AzcTablePager = api;
	}

	if (typeof document !== 'undefined') {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', boot);
		} else {
			boot();
		}
	}
})();
