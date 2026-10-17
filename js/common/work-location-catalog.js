/**
 * Work-location catalog bridge (Workforce Policy Suite, Phase 3).
 *
 * Loads the server-advertised catalog once per page (GET /api/work-locations)
 * and hands it to selects/labels. When the feature flag or the endpoint is
 * absent (404 on upgraded installs, older servers) every consumer falls back
 * to the built-in enum — the catalog never becomes a hard dependency.
 *
 * Unknown codes stored on historical entries still render: consumers must
 * surface them via labelFor() which falls back to the raw code.
 */
(function () {
	'use strict';

	/** @type {Promise<Array<{code:string,label:string,category:string}>|null>|null} */
	let pending = null;
	/** @type {Array<{code:string,label:string,category:string}>|null|undefined} */
	let resolved;

	const BUILTIN_FALLBACK = [
		{ code: 'office', label: null, category: 'office' },
		{ code: 'home', label: null, category: 'home' },
		{ code: 'external', label: null, category: 'external' },
	];

	function endpoint() {
		if (window.OC && typeof window.OC.generateUrl === 'function') {
			return window.OC.generateUrl('/apps/arbeitszeitcheck/api/work-locations');
		}
		return null;
	}

	/**
	 * @returns {Promise<{items:Array, effectiveDefault:{code:string,source:string,revision:number|null}|null}|null>}
	 *          `null` when the endpoint is unavailable or the flag is off.
	 */
	function load() {
		if (resolved !== undefined) {
			return Promise.resolve(resolved);
		}
		if (pending) {
			return pending;
		}
		const url = endpoint();
		if (url === null) {
			resolved = null;
			return Promise.resolve(null);
		}
		pending = fetch(url, {
			method: 'GET',
			headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
			credentials: 'same-origin',
		})
			.then((res) => (res.status === 404 ? null : res.json()))
			.then((body) => {
				if (!body || body.success !== true || !Array.isArray(body.items)) {
					resolved = null;
					return null;
				}
				const items = body.items
					.filter((it) => it && typeof it.code === 'string' && it.code !== '')
					.map((it) => ({
						code: it.code,
						label: typeof it.label === 'string' && it.label !== '' ? it.label : it.code,
						category: typeof it.category === 'string' ? it.category : 'other',
					}));
				const def = body.default && typeof body.default === 'object' ? body.default : null;
				resolved = {
					items,
					effectiveDefault: def && typeof def.code === 'string'
						? {
							code: def.code,
							source: typeof def.source === 'string' ? def.source : 'fallback',
							revision: typeof def.revision === 'number' ? def.revision : null,
						}
						: null,
				};
				return resolved;
			})
			.catch(() => {
				resolved = null;
				return null;
			})
			.finally(() => {
				pending = null;
			});
		return pending;
	}

	/** Options for a select; built-in fallback while catalog data is absent. */
	function options() {
		return resolved && Array.isArray(resolved.items) && resolved.items.length > 0
			? resolved.items
			: BUILTIN_FALLBACK.map((it) => ({ ...it }));
	}

	/**
	 * Display label for a stored code. Catalog label wins; built-in localized
	 * labels come second (supplied by the caller since this module cannot
	 * translate); the raw code is the last resort — never blank.
	 */
	function labelFor(code, builtinLabel) {
		if (typeof code !== 'string' || code === '') {
			return '';
		}
		if (resolved && Array.isArray(resolved.items)) {
			const hit = resolved.items.find((it) => it.code === code);
			if (hit && typeof hit.label === 'string' && hit.label !== '') {
				return hit.label;
			}
		}
		return typeof builtinLabel === 'string' && builtinLabel !== '' ? builtinLabel : code;
	}

	/** Kick the fetch early; safe to call repeatedly. */
	function prefetch() {
		load();
	}

	window.ArbeitszeitCheckWorkLocationCatalog = { load, options, labelFor, prefetch };
})();
