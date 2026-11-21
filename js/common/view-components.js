/*
 * Check family — view components (canonical, copied verbatim).
 * window.CheckViewComponents — behaviour for the recipe surfaces in
 * view-components.css. Prefix-free: behaviour hangs off data attributes
 * and class hooks the caller passes in.
 *
 *   bindListFilter(form, onChange)
 *     Recipe-A filter panel (DESIGN-SYSTEM §3.6b): calls
 *     onChange(valuesObject) on submit, on change (selects/checkboxes),
 *     and debounced on text input; a button with type="reset" or
 *     [data-filter-clear] resets the form and re-applies. Returns the
 *     current values getter.
 *
 *   pillGroup(root, onSelect)
 *     Recipe-B quick filters (§3.6c): single-select pill bar. Children are
 *     <button type="button" class="{p}-filter" data-value="…">. Manages
 *     aria-pressed, ArrowLeft/Right + Home/End keyboard travel, and calls
 *     onSelect(value, button). Returns { value(), select(v) }.
 *   listFetch(host, opts)
 *     Six-state async surface (loading → populated | empty | error+retry).
 *     Renders a spinner while fetching, hands data to opts.render on success,
 *     and on failure shows an inline error panel (role="alert") with a retry
 *     button that re-runs the same fetch — a failed load never leaves a blank
 *     surface. Host carries aria-busy during the request; opts.emptyEl is
 *     hidden/shown alongside. Classes + strings are caller-provided so the
 *     helper stays prefix-free:
 *       opts.fetch()          → Promise<data>
 *       opts.render(data,host)→ paint rows into host
 *       opts.emptyEl          → element hidden while rows/error show
 *       opts.onError(err)     → e.g. toast/field-error handler (optional)
 *       opts.classes          → {loading, errorBox, errorTitle, errorText, retryBtn}
 *       opts.strings          → {loading, errorTitle, errorText, retry}
 */
(function () {
	'use strict';

	function formValues(form) {
		var out = {};
		var fd = new FormData(form);
		fd.forEach(function (v, k) { out[k] = typeof v === 'string' ? v : ''; });
		return out;
	}

	function bindListFilter(form, onChange) {
		if (!form) { return function () { return {}; }; }
		var deb = null;
		var fire = function () { onChange(formValues(form)); };
		form.addEventListener('submit', function (e) { e.preventDefault(); fire(); });
		form.addEventListener('input', function (e) {
			if (e.target && (e.target.type === 'text' || e.target.type === 'search' || e.target.tagName === 'TEXTAREA')) {
				clearTimeout(deb);
				deb = setTimeout(fire, 200);
				return;
			}
			fire();
		});
		form.addEventListener('change', fire);
		form.addEventListener('reset', function () {
			// let the browser reset the controls first, then re-apply
			setTimeout(fire, 0);
		});
		return function () { return formValues(form); };
	}

	function pillGroup(root, onSelect) {
		if (!root) { return { value: function () { return ''; }, select: function () {} }; }
		var buttons = function () {
			return Array.prototype.slice.call(root.querySelectorAll('button[data-value]'));
		};
		if (!root.hasAttribute('role')) { root.setAttribute('role', 'radiogroup'); }
		function activate(btn, focus) {
			buttons().forEach(function (b) {
				b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
				b.tabIndex = b === btn ? 0 : -1;
			});
			if (focus) { btn.focus(); }
			onSelect(btn.dataset.value, btn);
		}
		root.addEventListener('click', function (e) {
			var b = e.target.closest('button[data-value]');
			if (b && root.contains(b)) { activate(b, false); }
		});
		root.addEventListener('keydown', function (e) {
			var list = buttons();
			var cur = list.indexOf(document.activeElement);
			if (cur < 0) { return; }
			var next = null;
			if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { next = list[(cur + 1) % list.length]; }
			else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { next = list[(cur - 1 + list.length) % list.length]; }
			else if (e.key === 'Home') { next = list[0]; }
			else if (e.key === 'End') { next = list[list.length - 1]; }
			if (next) { e.preventDefault(); activate(next, true); }
		});
		var initial = buttons().filter(function (b) { return b.getAttribute('aria-pressed') === 'true'; })[0] || buttons()[0];
		if (initial) { activate(initial, false); }
		return {
			value: function () {
				var b = buttons().filter(function (x) { return x.getAttribute('aria-pressed') === 'true'; })[0];
				return b ? b.dataset.value : '';
			},
			select: function (v) {
				var b = buttons().filter(function (x) { return x.dataset.value === v; })[0];
				if (b) { activate(b, false); }
			},
		};
	}


	/* el: minimal builder — text/attrs only, mirrors the pack's DOM style. */
	function mkEl(tag, attrs, children) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'class') { n.className = attrs[k]; }
			else if (k === 'text') { n.textContent = attrs[k]; }
			else { n.setAttribute(k, attrs[k]); }
		});
		(children || []).forEach(function (c) { if (c) { n.appendChild(c); } });
		return n;
	}

	function listFetch(host, opts) {
		if (!host) { return function () { return Promise.resolve(); }; }
		var o = opts || {};
		var cls = o.classes || {};
		var str = o.strings || {};
		function run() {
			host.setAttribute('aria-busy', 'true');
			host.innerHTML = '';
			var ld = mkEl('div', { 'class': cls.loading || '', role: 'status', 'aria-busy': 'true' });
			ld.textContent = str.loading || 'Loading…';
			host.appendChild(ld);
			return o.fetch().then(function (data) {
				host.removeAttribute('aria-busy');
				host.innerHTML = '';
				o.render(data, host);
				if (o.emptyEl) { o.emptyEl.hidden = host.children.length > 0; }
			}, function (err) {
				host.removeAttribute('aria-busy');
				host.innerHTML = '';
				var retry = mkEl('button', { type: 'button', 'class': cls.retryBtn || '' });
				retry.textContent = str.retry || 'Retry';
				retry.addEventListener('click', run);
				host.appendChild(mkEl('div', { 'class': cls.errorBox || '' }, [
					mkEl('p', { 'class': cls.errorTitle || '', role: 'alert', text: str.errorTitle || 'Could not load this list.' }),
					mkEl('p', { 'class': cls.errorText || '', text: str.errorText || 'Check your connection and try again.' }),
					retry,
				]));
				if (o.emptyEl) { o.emptyEl.hidden = true; }
				if (o.onError) { o.onError(err); }
			});
		}
		return run;
	}

	window.CheckViewComponents = { bindListFilter: bindListFilter, pillGroup: pillGroup, listFetch: listFetch };
}());
