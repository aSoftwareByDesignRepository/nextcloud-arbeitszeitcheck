/**
 * Admin page: Sonderfreistellungen (special leave entitlements).
 * One page, four decisions (name, amount, recipients, from-date) plus a
 * server-side impact preview that gates the primary CTA (spec §7.2).
 *
 * Layout mirrors admin-absence-types: hidden editor + detail panels render
 * above the list card, the list itself is a design-system table
 * (.table.table--hover.azc-table--responsive with data-label cells and a
 * fade-in .azc-table-actions column).
 */
(function () {
	'use strict';

	const root = document.getElementById('se-admin-root');
	if (!root) return;
	const t = (text) => (typeof window.t === 'function' ? window.t('arbeitszeitcheck', text) : text);
	const token = document.querySelector('head')?.dataset.requesttoken || window.OC?.requestToken || '';

	const enabled = root.dataset.enabled === '1';
	const listEl = document.getElementById('se-type-tbody');
	const listStatus = document.getElementById('se-list-status');
	const searchEl = document.getElementById('se-search');
	const filterEl = document.getElementById('se-filter-active');
	const editor = document.getElementById('se-editor');
	const detail = document.getElementById('se-detail');
	const form = document.getElementById('se-create-form');
	const previewText = document.getElementById('se-preview-text');
	const previewWarn = document.getElementById('se-preview-warn');
	const activateBtn = document.getElementById('se-activate');
	const opGroup = document.getElementById('se-operation-group');
	const conflictBox = document.getElementById('se-conflict');
	const newTypeBtn = document.getElementById('se-new-type');
	const detailAddPolicyBtn = document.getElementById('se-detail-add-policy');
	const formErrorBox = document.getElementById('se-form-error');

	const TYPE_TABLE_COLUMNS = 7;

	let types = [];
	let policies = [];
	let cohorts = [];
	let currentType = null;
	let previewToken = 0;
	let previewOk = false;
	// Sequence guards: only the latest async loader may write its result. Two
	// admins (or a fast scope switch) otherwise let a stale response overwrite
	// the newer state ("model" options landing in a "team" select).
	let typesToken = 0;
	let policiesToken = 0;
	let cohortsToken = 0;
	let targetsToken = 0;
	// 'create' = new entitlement type, 'policy' = extra rule for currentType.
	let editorMode = null;
	// WCAG 2.4.3 — panels hide their trigger; remember it so focus returns.
	let editorReturnFocus = null;
	let detailReturnFocus = null;

	async function request(url, options = {}) {
		const response = await fetch(url, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: token, ...(options.headers || {}) },
			...options,
		});
		let body = null;
		try { body = await response.json(); } catch (_) { body = null; }
		if (!response.ok || !body || body.success !== true) {
			const error = new Error(body?.error || t('The request could not be completed.'));
			error.fields = body?.fields || {};
			error.status = response.status;
			error.code = body?.error_code || body?.error || '';
			throw error;
		}
		return body;
	}

	function tpl(name, id) {
		return root.dataset[name].replace('__ID__', encodeURIComponent(String(id)));
	}

	function setBusy(el, busy) {
		el.disabled = busy;
		if (busy) el.setAttribute('aria-busy', 'true');
		else el.removeAttribute('aria-busy');
	}

	function announce(text) {
		listStatus.textContent = text;
	}

	function prefersReducedMotion() {
		return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	function scrollToPanel(el) {
		if (!el || typeof el.scrollIntoView !== 'function') return;
		el.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
	}

	function restoreFocus(el) {
		if (el && typeof el.focus === 'function' && el.isConnected) {
			el.focus();
		}
	}

	function clearFieldErrors(scope) {
		scope.querySelectorAll('[aria-invalid="true"]').forEach((f) => f.removeAttribute('aria-invalid'));
		scope.querySelectorAll('.azc-field-error').forEach((e) => { e.hidden = true; e.textContent = ''; });
	}

	// Server field names are the request names (publicLabel, scopeType, …); the
	// error <p> ids follow the input id. Anything unlisted falls back to the
	// `se-{name}-error` / `se-cohort-{name}-error` convention inside the scope.
	const FIELD_ERROR_IDS = {
		publicLabel: 'se-label-error',
	};

	function showFieldErrors(scopeEl, fields) {
		let first = null;
		let shown = false;
		// Matched against element properties, not selector strings — server
		// field names are data, never trusted as selector syntax.
		const errorEls = Array.from(scopeEl.querySelectorAll('.azc-field-error'));
		const named = Array.from(scopeEl.querySelectorAll('[name]'));
		Object.entries(fields || {}).forEach(([name, message]) => {
			const errId = FIELD_ERROR_IDS[name] || `se-${name}-error`;
			const err = errorEls.find((el) => el.id === errId || el.id === `se-cohort-${name}-error`);
			const field = named.find((el) => el.name === name);
			if (err) {
				err.textContent = String(message);
				err.hidden = false;
				shown = true;
				if (field) field.setAttribute('aria-invalid', 'true');
				if (!first) first = field || err;
			}
		});
		if (first && typeof first.focus === 'function') first.focus();
		return shown;
	}

	function escapeText(v) { return String(v ?? ''); }

	function chip(text, variant) {
		const c = document.createElement('span');
		c.className = `badge badge--${variant}`;
		c.textContent = text;
		return c;
	}

	function operationChip(operation) {
		return {
			add: { label: t('Additionally'), variant: 'success' },
			replace: { label: t('Replaces'), variant: 'warning' },
			disable: { label: t('Disabled'), variant: 'error' },
			inherit: { label: t('Inherits'), variant: 'neutral' },
		}[operation] || { label: operation, variant: 'neutral' };
	}

	function glyph(name) {
		return (window.AzcCatalog && typeof window.AzcCatalog.render === 'function')
			? window.AzcCatalog.render(name)
			: '';
	}

	function iconBtn(icon, label) {
		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'azc-btn azc-btn--secondary azc-icon-btn';
		btn.setAttribute('aria-label', label);
		btn.title = label;
		const svg = glyph(icon);
		if (svg) {
			btn.innerHTML = svg;
		} else {
			btn.textContent = label;
		}
		return btn;
	}

	function fmtAmount(v, unit) {
		const n = Number(v);
		if (!Number.isFinite(n)) return '—';
		return `${n.toFixed(2).replace(/\.00$/, '')} ${unit === 'hours' ? t('hours') : t('days')}`;
	}

	function periodLabel(periodType) {
		return {
			calendar_year: t('per calendar year'),
			service_year: t('per employment year'),
			one_time: t('one-time'),
		}[periodType] || periodType;
	}

	/* ---------- type table ---------- */

	function scopeHuman(scopeType) {
		return {
			org: t('Everyone'),
			model: t('Working-time model'),
			policy_group: t('Entitlement group'),
			team: t('Team'),
			user: t('Single person'),
		}[scopeType] || scopeType;
	}

	function cell(label, className) {
		const td = document.createElement('td');
		td.setAttribute('data-label', label);
		if (className) td.className = className;
		return td;
	}

	function emptyRow(title) {
		const tr = document.createElement('tr');
		const td = document.createElement('td');
		td.colSpan = TYPE_TABLE_COLUMNS;
		td.className = 'azc-table-empty';
		const state = document.createElement('div');
		state.className = 'azc-empty-state';
		const h = document.createElement('h3');
		h.className = 'azc-empty-state__title';
		h.textContent = title;
		state.append(h);
		td.append(state);
		tr.append(td);
		return tr;
	}

	function loadingRow() {
		const tr = document.createElement('tr');
		const td = document.createElement('td');
		td.colSpan = TYPE_TABLE_COLUMNS;
		td.className = 'azc-table-loading';
		const state = document.createElement('div');
		state.className = 'azc-loading';
		state.setAttribute('aria-busy', 'true');
		state.setAttribute('aria-live', 'polite');
		state.textContent = t('Loading…');
		td.append(state);
		tr.append(td);
		return tr;
	}

	async function toggleTypeActive(item, btn) {
		setBusy(btn, true);
		try {
			await request(tpl('typeActiveTemplate', item.id), {
				method: 'POST',
				body: JSON.stringify({ active: item.active ? '0' : '1', version: item.version }),
			});
			await loadTypes();
		} catch (e) {
			announce(e.message);
		} finally {
			setBusy(btn, false);
		}
	}

	function renderTypes() {
		const q = (searchEl.value || '').toLowerCase();
		const filter = filterEl.value;
		const visible = types.filter((item) => {
			if (filter === 'active' && !item.active) return false;
			if (filter === 'inactive' && item.active) return false;
			if (q && !`${item.publicLabel} ${item.code}`.toLowerCase().includes(q)) return false;
			return true;
		});
		listEl.replaceChildren();
		if (visible.length === 0) {
			listEl.append(emptyRow(types.length === 0
				? t('No additional leave accounts exist.')
				: t('No entitlements match the current filters.')));
		}
		visible.forEach((item) => {
			const tr = document.createElement('tr');
			tr.dataset.typeId = String(item.id);

			const nameTd = cell(t('Name'));
			const name = document.createElement('span');
			name.className = 'se-type-name';
			name.textContent = item.publicLabel;
			const code = document.createElement('span');
			code.className = 'se-type-code';
			code.textContent = item.code;
			code.title = t('Technical identifier');
			nameTd.append(name, code);
			if (item.confidentialLabel) {
				const conf = document.createElement('p');
				conf.className = 'form-help se-type-confidential';
				conf.textContent = `${t('Confidential:')} ${item.confidentialLabel}`;
				nameTd.append(conf);
			}

			// "Betrag" + "Für wen" come from the type's active rules — one
			// line per rule so multi-rule types stay readable.
			const rules = Array.isArray(item.policies) ? item.policies : [];
			const amountTd = cell(t('Amount'));
			const scopeTd = cell(t('For whom?'));
			if (rules.length === 0) {
				amountTd.textContent = '—';
				scopeTd.textContent = '—';
			}
			rules.forEach((p) => {
				const amt = document.createElement('span');
				amt.className = 'se-type-rule';
				amt.textContent = p.operation === 'disable'
					? t('Disabled')
					: fmtAmount(p.amount, item.unit);
				amountTd.append(amt);

				const scp = document.createElement('span');
				scp.className = 'se-type-rule';
				scp.textContent = p.scopeType === 'org'
					? scopeHuman('org')
					: `${scopeHuman(p.scopeType)}: ${p.scopeLabel || `#${escapeText(p.scopeId)}`}`;
				scopeTd.append(scp);
			});

			const unitTd = cell(t('Unit'));
			unitTd.textContent = item.unit === 'hours' ? t('Hours') : t('Days');

			const periodTd = cell(t('Period'));
			periodTd.textContent = periodLabel(item.periodType);

			const statusTd = cell(t('Status'));
			const badge = document.createElement('span');
			badge.className = `badge ${item.active ? 'badge--success' : 'badge--neutral'}`;
			badge.textContent = item.active ? t('Active') : t('Inactive');
			statusTd.append(badge);

			const actTd = cell(t('Actions'), 'actions-cell');
			const group = document.createElement('div');
			group.className = 'azc-table-actions';
			group.setAttribute('role', 'group');
			group.setAttribute('aria-label', `${t('Actions')}: ${item.publicLabel}`);
			const manageBtn = iconBtn('edit', `${t('Manage rules')}: ${item.publicLabel}`);
			manageBtn.addEventListener('click', () => openDetail(item, manageBtn));
			const toggleBtn = iconBtn(item.active ? 'pause' : 'check',
				`${item.active ? t('Deactivate') : t('Activate')}: ${item.publicLabel}`);
			toggleBtn.addEventListener('click', () => toggleTypeActive(item, toggleBtn));
			group.append(manageBtn, toggleBtn);
			actTd.append(group);

			tr.append(nameTd, amountTd, scopeTd, unitTd, periodTd, statusTd, actTd);
			listEl.append(tr);
		});
		announce(visible.length === 0 ? '' : (typeof window.n === 'function'
			? window.n('arbeitszeitcheck', '1 entitlement', '%n entitlements', visible.length)
			: `${visible.length} ${visible.length === 1 ? t('entitlement') : t('entitlements')}`));
	}

	async function loadTypes() {
		const my = ++typesToken;
		listStatus.textContent = t('Loading…');
		if (types.length === 0 && listEl.children.length === 0) {
			listEl.append(loadingRow());
		}
		try {
			const body = await request(root.dataset.typesUrl);
			if (my !== typesToken) return;
			types = body.items || [];
			renderTypes();
		} catch (e) {
			if (my !== typesToken) return;
			listStatus.textContent = e.message;
			listEl.replaceChildren(emptyRow(e.message));
		}
	}

	/* ---------- scope targets ---------- */

	const scopeSel = document.getElementById('se-scope');
	const targetGroup = document.getElementById('se-target-group');
	const targetSel = document.getElementById('se-target');
	const targetEmpty = document.getElementById('se-target-empty');
	const userWrap = document.getElementById('se-user-wrap');
	const userHidden = document.getElementById('se-user-id');

	// The "Explain calculation" surface is commented out in the template;
	// keep the wiring guarded so re-enabling needs no JS change.
	const _traceUserPicker = document.getElementById('se-trace-user-wrap')
		? window.ArbeitszeitCheck?.initAdminUserPicker({
		wrapSelector: '#se-trace-user-wrap',
		hiddenSelector: '#se-trace-uid',
		searchSelector: '#se-trace-user-search',
		listSelector: '#se-trace-user-list',
		statusSelector: '#se-trace-user-status',
		searchUrl: root.dataset.usersUrl,
		l10n: {
			typeToSearch: t('Type to search for people'),
			noResults: t('No results'),
			loading: t('Loading…'),
		},
	})
		: null;

	const _userPicker = window.ArbeitszeitCheck?.initAdminUserPicker({
		wrapSelector: '#se-user-wrap',
		hiddenSelector: '#se-user-id',
		searchSelector: '#se-user-search',
		listSelector: '#se-user-list',
		statusSelector: '#se-user-status',
		searchUrl: root.dataset.usersUrl,
		l10n: {
			typeToSearch: t('Type at least 2 characters to search.'),
			noResults: t('No matching people.'),
			loading: t('Searching…'),
		},
		onChange: schedulePreview,
	});

	async function loadTargets() {
		const my = ++targetsToken;
		const kind = scopeSel.value;
		const needsTarget = kind !== 'org';
		targetGroup.hidden = !needsTarget;
		targetSel.hidden = true;
		userWrap.hidden = true;
		targetSel.replaceChildren();
		targetEmpty.textContent = '';
		if (!needsTarget) { schedulePreview(); return; }
		if (kind === 'user') {
			userWrap.hidden = false;
			schedulePreview();
			return;
		}
		targetSel.hidden = false;
		try {
			let rows = [];
			if (kind === 'policy_group') {
				rows = cohorts.map((c) => ({ id: c.id, label: c.publicLabel }));
			} else {
				const url = kind === 'model' ? root.dataset.modelsUrl : root.dataset.teamsUrl;
				const body = await request(url);
				if (my !== targetsToken) return;
				rows = (kind === 'model' ? body.models : body.teams) || [];
			}
			rows.forEach((node) => {
				const option = document.createElement('option');
				option.value = String(node.id);
				option.textContent = node.name || node.label || String(node.id);
				targetSel.append(option);
			});
			if (rows.length === 0) {
				targetEmpty.textContent = kind === 'policy_group'
					? t('No protected group exists yet. Create one below first.')
					: t('No targets are set up yet.');
			}
		} catch (e) {
			if (my === targetsToken) {
				targetEmpty.textContent = e.message;
			}
		}
		if (my === targetsToken) schedulePreview();
	}

	function selectedScopeId() {
		const kind = scopeSel.value;
		if (kind === 'org') return 'org';
		if (kind === 'user') return userHidden.value;
		return targetSel.value;
	}

	/* ---------- impact preview ---------- */

	function previewParams() {
		const amount = document.getElementById('se-amount').value;
		const from = document.getElementById('se-from').value;
		const scopeId = selectedScopeId();
		if (!amount || !from || !scopeId || (currentType === null && !document.getElementById('se-label').value)) return null;
		const operation = form.querySelector('[name="operation"]:checked')?.value || 'add';
		const params = new URLSearchParams({
			scopeType: scopeSel.value,
			scopeId,
			operation,
			amount,
			effectiveFrom: from,
			priority: document.getElementById('se-priority').value || '0',
		});
		if (currentType) params.set('entitlementTypeId', String(currentType.id));
		const to = document.getElementById('se-to').value;
		if (to) params.set('effectiveTo', to);
		return params;
	}

	let previewTimer = null;
	function schedulePreview() {
		previewOk = false;
		activateBtn.disabled = true;
		if (previewTimer) window.clearTimeout(previewTimer);
		previewTimer = window.setTimeout(runPreview, 350);
	}

	async function runPreview() {
		const params = previewParams();
		if (!params) {
			previewText.textContent = t('The preview appears once name, amount, recipients, and start date are set.');
			previewWarn.hidden = true;
			return;
		}
		if (!params.get('entitlementTypeId')) {
			// New type: preview the count from scope only (no resolution yet).
			previewText.textContent = t('Preview runs after the definition is saved — recipients are shown before activation.');
			// For create flow the CTA still needs a server-side preview; use a
			// two-step: save definition first, then preview+activate policy.
			previewOk = true;
			activateBtn.disabled = false;
			updateCta();
			return;
		}
		const my = ++previewToken;
		previewText.textContent = t('Calculating preview…');
		try {
			const body = await request(`${root.dataset.previewUrl}?${params}`);
			if (my !== previewToken) return;
			const unit = document.getElementById('se-unit').value;
			const parts = [
				t('Applies from'), params.get('effectiveFrom'),
				`${t('for')} ${body.recipientCount}${body.recipientCountTruncated ? '+' : ''} ${t('people')}:`,
				fmtAmount(body.summary.amount, unit),
			];
			previewText.textContent = parts.join(' ');
			const warns = (body.warnings || []).map((w) => ({
				no_recipients: t('Warning: this rule currently matches nobody.'),
				recipient_sample_truncated: t('Large group: the count is approximate.'),
				disables_entitlement: t('This rule disables the entitlement for the selection.'),
				shrinks_existing_entitlement: t('This rule lowers the entitlement for at least one person.'),
			}[w] || w)).join(' ');
			previewWarn.textContent = warns;
			previewWarn.hidden = warns === '';
			previewOk = true;
			activateBtn.disabled = false;
			updateCta();
			// Show the add/replace/disable choice when a lower rule applies.
			const lowerExists = (body.samples || []).some((s) => s.enabledBefore || Number(s.before) > 0);
			opGroup.hidden = !lowerExists;
		} catch (e) {
			if (e.status === 404) {
				previewText.textContent = t('The chosen recipient does not exist.');
			} else {
				previewText.textContent = e.message;
			}
			previewOk = false;
			activateBtn.disabled = true;
		}
	}

	function updateCta() {
		if (!previewOk) return;
		const amount = document.getElementById('se-amount').value;
		const from = document.getElementById('se-from').value;
		const unit = document.getElementById('se-unit').value;
		activateBtn.textContent = currentType
			? `${fmtAmount(amount, unit)} ${t('from')} ${from} ${t('activate')}`
			: t('Save and activate');
	}

	/* ---------- create / add-rule editor ---------- */

	const DEFINITION_FIELD_IDS = ['se-label', 'se-unit', 'se-period', 'se-confidential', 'se-absence-type'];

	function setDefinitionFieldsEnabled(on) {
		DEFINITION_FIELD_IDS.forEach((id) => {
			const el = document.getElementById(id);
			if (el) el.disabled = !on;
		});
	}

	function tomorrowYmd() {
		const tomorrow = new Date(Date.now() + 86400000);
		return window.ArbeitszeitCheckTime ? window.ArbeitszeitCheckTime.ymd(tomorrow) : tomorrow.toLocaleDateString('sv-SE');
	}

	function openCreateEditor(invoker) {
		currentType = null;
		editorMode = 'create';
		editorReturnFocus = invoker || newTypeBtn;
		form.reset();
		setDefinitionFieldsEnabled(true);
		document.getElementById('se-type-version').value = '';
		document.getElementById('se-editor-title').textContent = t('New special leave entitlement');
		document.getElementById('se-from').value = tomorrowYmd();
		formErrorBox.hidden = true;
		conflictBox.hidden = true;
		editor.hidden = false;
		detail.hidden = true;
		cohortEditor.hidden = true;
		loadTargets();
		scrollToPanel(editor);
		document.getElementById('se-label').focus();
	}

	function openPolicyEditor(invoker) {
		if (!currentType) return;
		editorMode = 'policy';
		editorReturnFocus = invoker || detailAddPolicyBtn;
		document.getElementById('se-editor-title').textContent = `${t('New rule for')} ${currentType.publicLabel}`;
		form.reset();
		document.getElementById('se-from').value = tomorrowYmd();
		// Definition fields are read-only for an existing type.
		setDefinitionFieldsEnabled(false);
		document.getElementById('se-label').value = currentType.publicLabel;
		document.getElementById('se-unit').value = currentType.unit;
		document.getElementById('se-period').value = currentType.periodType;
		formErrorBox.hidden = true;
		editor.hidden = false;
		cohortEditor.hidden = true;
		loadTargets();
		schedulePreview();
		scrollToPanel(editor);
		document.getElementById('se-amount').focus();
	}

	function closeEditor() {
		editor.hidden = true;
		editorMode = null;
		// Keep currentType: the detail panel may still be open underneath.
		restoreFocus(editorReturnFocus);
		editorReturnFocus = null;
	}

	form.addEventListener('input', (ev) => {
		if (ev.target?.name) {
			ev.target.removeAttribute('aria-invalid');
			schedulePreview();
		}
	});
	scopeSel.addEventListener('change', loadTargets);
	targetSel.addEventListener('change', schedulePreview);
	form.querySelectorAll('[name="operation"]').forEach((r) => r.addEventListener('change', schedulePreview));

	newTypeBtn.addEventListener('click', () => openCreateEditor(newTypeBtn));

	document.getElementById('se-cancel').addEventListener('click', closeEditor);

	form.addEventListener('submit', async (ev) => {
		ev.preventDefault();
		clearFieldErrors(editor);
		formErrorBox.hidden = true;
		if (!form.reportValidity()) return;
		const savedMode = editorMode;
		setBusy(activateBtn, true);
		try {
			let typeId = currentType?.id;
			if (!typeId) {
				// Step 1: create the definition (+ linked absence type).
				const def = {
					publicLabel: document.getElementById('se-label').value,
					confidentialLabel: document.getElementById('se-confidential').value || undefined,
					absenceTypeCode: document.getElementById('se-absence-type').value || undefined,
					unit: document.getElementById('se-unit').value,
					periodType: document.getElementById('se-period').value,
				};
				const created = await request(root.dataset.typeCreateUrl, { method: 'POST', body: JSON.stringify(def) });
				typeId = created.item.id;
				await request(tpl('typeActiveTemplate', typeId), {
					method: 'POST',
					body: JSON.stringify({ active: '1', version: created.item.version }),
				});
			}
			// Step 2: the first policy.
			const pol = {
				entitlementTypeId: typeId,
				scopeType: scopeSel.value,
				scopeId: selectedScopeId(),
				operation: form.querySelector('[name="operation"]:checked')?.value || 'add',
				amount: document.getElementById('se-amount').value,
				priority: document.getElementById('se-priority').value || '0',
				effectiveFrom: document.getElementById('se-from').value,
				effectiveTo: document.getElementById('se-to').value || undefined,
				reason: document.getElementById('se-reason').value || undefined,
			};
			await request(root.dataset.policyCreateUrl, { method: 'POST', body: JSON.stringify(pol) });
			editor.hidden = true;
			conflictBox.hidden = true;
			await loadTypes();
			announce(t('Special leave entitlement saved.'));
			if (savedMode === 'policy' && currentType) {
				// The detail panel stays open — refresh its rules so the new
				// row is visible without a manual reopen.
				await loadPolicies(currentType.id);
				restoreFocus(detailAddPolicyBtn);
			} else {
				currentType = null;
				const row = Array.from(listEl.querySelectorAll('tr'))
					.find((tr) => tr.dataset.typeId === String(typeId));
				restoreFocus(row?.querySelector('.azc-table-actions .azc-icon-btn') || newTypeBtn);
			}
			editorMode = null;
			editorReturnFocus = null;
		} catch (e) {
			if (!showFieldErrors(editor, e.fields)) {
				if (e.status === 409) {
					conflictBox.hidden = false;
					document.getElementById('se-conflict-text').textContent = e.message;
					// The conflict callout lives in the (possibly hidden) detail
					// panel; surface it in the editor as well.
					if (detail.hidden) {
						document.getElementById('se-form-error-text').textContent = e.message;
						formErrorBox.hidden = false;
					}
				} else {
					document.getElementById('se-form-error-text').textContent = e.message;
					formErrorBox.hidden = false;
				}
			}
		} finally {
			setBusy(activateBtn, false);
		}
	});

	document.getElementById('se-conflict-reload').addEventListener('click', async () => {
		conflictBox.hidden = true;
		await loadTypes();
		if (currentType) await openDetail(currentType);
	});

	/* ---------- detail: policies + trace ---------- */

	async function openDetail(item, invoker) {
		currentType = item;
		detailReturnFocus = invoker || null;
		// Single-flight: the editor and the detail panel never coexist.
		editor.hidden = true;
		editorMode = null;
		conflictBox.hidden = true;
		cohortEditor.hidden = true;
		detail.hidden = false;
		document.getElementById('se-detail-title').textContent = item.publicLabel;
		const addRuleBtn = document.getElementById('se-add-rule');
		if (addRuleBtn) {
			addRuleBtn.textContent = t('Add rule to %1$s').replace('%1$s', item.publicLabel);
		}
		
		await loadPolicies(item.id);
		scrollToPanel(detail);
	}

	function untilChip(p) {
		const c = chip(p.effectiveTo ? `${t('Valid until')} ${p.effectiveTo}` : t('open-ended'), 'neutral');
		if (!p.effectiveTo) return c;
		c.classList.add('se-policy-chip--removable');
		const x = document.createElement('button');
		x.type = 'button';
		x.className = 'se-policy-chip__remove';
		x.setAttribute('aria-label', `${t('Remove end date')}: ${scopeHuman(p.scopeType)}${p.scopeType !== 'org' ? ` ${p.scopeLabel || `#${escapeText(p.scopeId)}`}` : ''}`);
		x.textContent = '×';
		x.addEventListener('click', () => { void updatePolicyEnd(p, null, x); });
		c.append(x);
		return c;
	}

	async function updatePolicyEnd(p, effectiveTo, busyEl) {
		setBusy(busyEl, true);
		try {
			await request(tpl('policyUpdateTemplate', p.id), {
				method: 'PUT',
				body: JSON.stringify({ revision: p.version, effectiveTo }),
			});
			await loadPolicies(currentType.id);
			await loadTypes();
		} catch (e) {
			if (e.status === 409) {
				conflictBox.hidden = false;
				document.getElementById('se-conflict-text').textContent = e.message;
			} else {
				document.getElementById('se-policy-status').textContent = e.message;
			}
		} finally { setBusy(busyEl, false); }
	}

	function renderPolicies() {
		const wrap = document.getElementById('se-policy-groups');
		wrap.replaceChildren();
		const groups = {
			org: { title: t('Everyone'), rows: [] },
			groups: { title: t('Groups and models'), rows: [] },
			user: { title: t('Single people'), rows: [] },
		};
		policies.forEach((p) => {
			const key = p.scopeType === 'org' ? 'org' : (p.scopeType === 'user' ? 'user' : 'groups');
			groups[key].rows.push(p);
		});
		Object.values(groups).forEach((g) => {
			if (g.rows.length === 0) return;
			const h = document.createElement('h4');
			h.textContent = g.title;
			const ul = document.createElement('ul');
			ul.className = 'azc-list';
			g.rows.forEach((p) => {
				const li = document.createElement('li');
				li.className = 'azc-list__item se-policy-item';
				const text = document.createElement('div');
				text.className = 'se-policy-text';
				const title = document.createElement('strong');
				title.textContent = `${scopeHuman(p.scopeType)}${p.scopeType !== 'org' ? ` ${p.scopeLabel || `#${escapeText(p.scopeId)}`}` : ''} → ${fmtAmount(p.amount, currentType?.unit)}`;
				const chips = document.createElement('span');
				chips.className = 'se-policy-chips';
				const op = operationChip(p.operation);
				chips.append(
					chip(op.label, op.variant),
					chip(`${t('From')} ${p.effectiveFrom}`, 'neutral'),
					untilChip(p),
				);
				if (Number(p.priority) !== 0) {
					chips.append(chip(`${t('Priority')} ${p.priority}`, 'neutral'));
				}
				text.append(title, chips);
				if (p.reason) {
					const reason = document.createElement('p');
					reason.className = 'form-help';
					reason.textContent = p.reason;
					text.append(reason);
				}
				li.append(text);
				// The end-date control only appears while the rule is
				// open-ended; once an end date exists, the removable
				// "Valid until" chip takes over that role.
				if (!p.effectiveTo) {
					const actions = document.createElement('span');
					actions.className = 'azc-list__actions se-policy-end';
					const endLabel = document.createElement('span');
					endLabel.className = 'se-policy-end-label';
					endLabel.textContent = `${t('End on')}:`;
					const endInput = document.createElement('input');
					endInput.type = 'date';
					endInput.className = 'form-input se-policy-end-date';
					endInput.setAttribute('aria-label', `${t('End on')}: ${title.textContent}`);
					const endBtn = document.createElement('button');
					endBtn.type = 'button';
					endBtn.className = 'azc-btn azc-btn--secondary';
					endBtn.textContent = t('Set end date');
					endBtn.addEventListener('click', async () => {
						if (!endInput.reportValidity() || endInput.value === '') { endInput.focus(); return; }
						await updatePolicyEnd(p, endInput.value, endBtn);
					});
					actions.append(endLabel, endInput, endBtn);
					li.append(actions);
				}
				ul.append(li);
			});
			wrap.append(h, ul);
		});
		document.getElementById('se-policy-status').textContent = policies.length === 0 ? t('No rules yet.') : '';
	}

	async function loadPolicies(typeId) {
		const my = ++policiesToken;
		try {
			const body = await request(tpl('policiesTemplate', typeId));
			if (my !== policiesToken) return;
			policies = body.items || [];
			renderPolicies();
		} catch (e) {
			if (my === policiesToken) {
				document.getElementById('se-policy-status').textContent = e.message;
			}
		}
	}

	document.getElementById('se-detail-close').addEventListener('click', () => {
		detail.hidden = true;
		currentType = null;
		conflictBox.hidden = true;
		restoreFocus(detailReturnFocus);
		detailReturnFocus = null;
	});

	detailAddPolicyBtn.addEventListener('click', () => openPolicyEditor(detailAddPolicyBtn));

	const traceRunEl = document.getElementById('se-trace-run');
	if (traceRunEl) traceRunEl.addEventListener('click', async () => {
		if (!currentType) return;
		const out = document.getElementById('se-trace-out');
		const uid = document.getElementById('se-trace-uid').value.trim();
		const date = document.getElementById('se-trace-date').value || (window.ArbeitszeitCheckTime ? window.ArbeitszeitCheckTime.todayYmd() : new Date().toLocaleDateString('sv-SE'));
		if (!uid) { out.textContent = t('Choose a person first.'); return; }
		out.textContent = t('Resolving…');
		try {
			const q = new URLSearchParams({ userId: uid, asOf: date });
			const body = await request(`${tpl('traceTemplate', currentType.id)}?${q}`);
			out.textContent = JSON.stringify(body, null, 2);
		} catch (e) {
			out.textContent = e.message;
		}
	});

	/* ---------- cohorts ---------- */

	const COHORT_TABLE_COLUMNS = 5;
	const cohortList = document.getElementById('se-cohort-list');
	const cohortStatus = document.getElementById('se-cohort-status');
	const cohortEditor = document.getElementById('se-cohort-editor');
	const selectedCohortMembers = [];
	const cohortChipsEl = document.getElementById('se-cohort-member-chips');
	let openCohortId = null;
	let memberPicker = null;
	let cohortReturnFocus = null;

	function renderCohortMemberChips() {
		if (!cohortChipsEl) return;
		cohortChipsEl.replaceChildren();
		selectedCohortMembers.forEach((m, idx) => {
			const c = document.createElement('span');
			c.className = 'badge badge--neutral se-member-chip';
			c.textContent = m.displayName || m.userId;
			const x = document.createElement('button');
			x.type = 'button';
			x.className = 'se-policy-chip__remove';
			x.setAttribute('aria-label', t('Remove %s', 'Remove %s').replace('%s', m.displayName || m.userId));
			x.textContent = '×';
			x.addEventListener('click', () => { selectedCohortMembers.splice(idx, 1); renderCohortMemberChips(); });
			c.append(x);
			cohortChipsEl.append(c);
		});
	}

	const _cohortCreatePicker = document.getElementById('se-cohort-members-wrap')
		? window.ArbeitszeitCheck?.initAdminUserPicker({
			wrapSelector: '#se-cohort-members-wrap',
			hiddenSelector: '#se-cohort-member-uid',
			searchSelector: '#se-cohort-member-search',
			listSelector: '#se-cohort-member-list',
			statusSelector: '#se-cohort-member-status',
			searchUrl: root.dataset.usersUrl,
			l10n: {
				typeToSearch: t('Type to search for people'),
				noResults: t('No results'),
				loading: t('Loading…'),
			},
			onChange: (id, meta) => {
				if (!id) return;
				if (!selectedCohortMembers.some((m) => m.userId === id)) {
					selectedCohortMembers.push({ userId: id, displayName: meta.displayName || meta.label || id });
					renderCohortMemberChips();
				}
				// Free the picker for the next person — the chip list owns the selection.
				_cohortCreatePicker?.clear();
			},
		})
		: null;

	function openCohortEditor() {
		editor.hidden = true;
		editorMode = null;
		detail.hidden = true;
		conflictBox.hidden = true;
		cohortReturnFocus = document.activeElement;
		cohortEditor.hidden = false;
		scrollToPanel(cohortEditor);
		document.getElementById('se-cohort-label').focus();
	}

	function hideCohortEditor() {
		cohortEditor.hidden = true;
		cohortReturnFocus?.focus?.();
		cohortReturnFocus = null;
	}

	document.getElementById('se-new-cohort')?.addEventListener('click', () => {
		if (cohortEditor.hidden) { openCohortEditor(); } else { hideCohortEditor(); }
	});
	cohortEditor?.querySelector('[data-close-panel]')?.addEventListener('click', hideCohortEditor);

	function renderCohorts() {
		cohortList.replaceChildren();
		cohorts.forEach((c) => {
			const tr = document.createElement('tr');
			const nameTd = cell(t('Name'));
			const nameEl = document.createElement('span');
			nameEl.className = 'se-type-name';
			nameEl.textContent = c.publicLabel;
			const code = document.createElement('span');
			code.className = 'se-type-code';
			code.title = t('Technical identifier');
			code.textContent = c.code;
			nameTd.append(nameEl, code);
			const confTd = cell(t('Confidential label'));
			confTd.textContent = c.confidentialLabel || '—';
			const memberText = typeof window.n === 'function'
				? window.n('arbeitszeitcheck', '1 member', '%n members', c.memberCount)
				: `${c.memberCount} ${c.memberCount === 1 ? t('member') : t('members')}`;
			const membersTd = cell(t('Members'));
			membersTd.textContent = memberText;
			const statusTd = cell(t('Status'));
			const badge = document.createElement('span');
			badge.className = `badge badge--${c.active ? 'success' : 'neutral'}`;
			badge.textContent = c.active ? t('Active') : t('Inactive');
			statusTd.append(badge);
			const actTd = cell(t('Actions'));
			actTd.className = 'actions-cell';
			const group = document.createElement('span');
			group.className = 'azc-table-actions';
			group.setAttribute('role', 'group');
			group.setAttribute('aria-label', `${t('Actions')}: ${c.publicLabel}`);
			const manageBtn = document.createElement('button');
			manageBtn.type = 'button';
			manageBtn.className = 'azc-btn azc-btn--secondary';
			manageBtn.dataset.cohortId = String(c.id);
			manageBtn.textContent = t('Manage members');
			manageBtn.setAttribute('aria-expanded', openCohortId === c.id ? 'true' : 'false');
			manageBtn.addEventListener('click', () => {
				openCohortId = openCohortId === c.id ? null : c.id;
				renderCohorts();
			});
			const toggleBtn = iconBtn(c.active ? 'pause' : 'check',
				`${c.active ? t('Deactivate') : t('Activate')}: ${c.publicLabel}`);
			toggleBtn.addEventListener('click', async () => {
				setBusy(toggleBtn, true);
				try {
					await request(tpl('cohortUpdateTemplate', c.id), {
						method: 'PUT',
						body: JSON.stringify({ active: c.active ? '0' : '1', revision: c.revision }),
					});
					cohortStatus.textContent = '';
				} catch (e) {
					cohortStatus.textContent = e.message;
				}
				await loadCohorts();
			});
			group.append(manageBtn, toggleBtn);
			actTd.append(group);
			tr.append(nameTd, confTd, membersTd, statusTd, actTd);
			cohortList.append(tr);
			if (openCohortId === c.id) {
				const pr = document.createElement('tr');
				pr.className = 'se-cohort-panel';
				const td = document.createElement('td');
				td.colSpan = COHORT_TABLE_COLUMNS;
				td.append(buildCohortPanel(c));
				pr.append(td);
				cohortList.append(pr);
			}
		});
		if (cohorts.length === 0) {
			cohortList.append(emptyRow(COHORT_TABLE_COLUMNS, t('No protected groups yet.')));
		}
		cohortStatus.textContent = '';
	}

	function buildCohortPanel(cohort) {
		if (memberPicker) { memberPicker.destroy(); memberPicker = null; }

		const panel = document.createElement('div');
		panel.className = 'se-cohort-panel';
		const prefix = `se-member-${cohort.id}`;

		const header = document.createElement('div');
		header.className = 'se-cohort-panel__header';
		const headerTitle = document.createElement('strong');
		headerTitle.textContent = `${t('Members')}: ${cohort.publicLabel}`;
		const closeBtn = iconBtn('x', `${t('Close')}: ${cohort.publicLabel}`);
		closeBtn.addEventListener('click', () => {
			openCohortId = null;
			renderCohorts();
			cohortList.querySelector(`[data-cohort-id="${cohort.id}"]`)?.focus();
		});
		header.append(headerTitle, closeBtn);
		panel.append(header);

		const membersList = document.createElement('ul');
		membersList.className = 'azc-list';
		membersList.setAttribute('aria-label', `${cohort.publicLabel} ${t('members')}`);
		const memberStatus = document.createElement('p');
		memberStatus.className = 'form-help';
		memberStatus.setAttribute('role', 'status');

		const addForm = document.createElement('form');
		addForm.className = 'form';
		addForm.noValidate = true;
		const grid = document.createElement('div');
		grid.className = 'form-grid';
		const pickerWrap = document.createElement('div');
		pickerWrap.className = 'user-picker';
		pickerWrap.id = `${prefix}-wrap`;
		const hidden = document.createElement('input');
		hidden.type = 'hidden';
		hidden.id = `${prefix}-uid`;
		const searchLabel = document.createElement('label');
		searchLabel.className = 'form-label';
		searchLabel.htmlFor = `${prefix}-search`;
		searchLabel.textContent = t('Add a person');
		const search = document.createElement('input');
		search.id = `${prefix}-search`;
		search.className = 'user-picker__input form-input';
		search.autocomplete = 'off';
		search.setAttribute('aria-autocomplete', 'list');
		const list = document.createElement('div');
		list.id = `${prefix}-list`;
		list.className = 'user-picker__list';
		list.hidden = true;
		const pickStatus = document.createElement('p');
		pickStatus.id = `${prefix}-status`;
		pickStatus.className = 'form-help';
		pickStatus.setAttribute('role', 'status');
		pickStatus.setAttribute('aria-live', 'polite');
		pickerWrap.append(hidden, searchLabel, search, list, pickStatus);
		grid.append(pickerWrap);

		const fromWrap = document.createElement('div');
		fromWrap.className = 'form-group';
		const fromLabel = document.createElement('label');
		fromLabel.className = 'form-label';
		fromLabel.htmlFor = `${prefix}-from`;
		fromLabel.textContent = t('Member from');
		const fromInput = document.createElement('input');
		fromInput.id = `${prefix}-from`;
		fromInput.type = 'date';
		fromInput.required = true;
		fromInput.value = (window.ArbeitszeitCheckTime ? window.ArbeitszeitCheckTime.todayYmd() : new Date().toLocaleDateString('sv-SE'));
		fromWrap.append(fromLabel, fromInput);

		const toWrap = document.createElement('div');
		toWrap.className = 'form-group';
		const toLabel = document.createElement('label');
		toLabel.className = 'form-label';
		toLabel.htmlFor = `${prefix}-to`;
		toLabel.textContent = t('Member until (optional)');
		const toInput = document.createElement('input');
		toInput.id = `${prefix}-to`;
		toInput.type = 'date';
		toWrap.append(toLabel, toInput);
		grid.append(fromWrap, toWrap);

		const submit = document.createElement('button');
		submit.type = 'submit';
		submit.className = 'azc-btn azc-btn--primary';
		submit.textContent = t('Add member');
		addForm.append(grid, submit);

		panel.append(membersList, memberStatus, addForm);

		// initAdminUserPicker resolves its elements via document.querySelector —
		// the panel is still detached at this point, so defer the init until the
		// row has been connected to the DOM (after renderCohorts finishes).
		queueMicrotask(() => {
			if (!panel.isConnected) return;
			memberPicker = window.ArbeitszeitCheck?.initAdminUserPicker({
				wrapSelector: `#${prefix}-wrap`,
				hiddenSelector: `#${prefix}-uid`,
				searchSelector: `#${prefix}-search`,
				listSelector: `#${prefix}-list`,
				statusSelector: `#${prefix}-status`,
				searchUrl: root.dataset.usersUrl,
				l10n: {
					typeToSearch: t('Type at least 2 characters to search.'),
					noResults: t('No matching people.'),
					loading: t('Searching…'),
				},
			});
		});

		async function loadMembers() {
			try {
				const body = await request(tpl('cohortMembersTemplate', cohort.id));
				const rows = body.items || [];
				membersList.replaceChildren();
				rows.forEach((m) => {
					const mli = document.createElement('li');
					mli.className = 'azc-list__item';
					const mText = document.createElement('div');
					const mTitle = document.createElement('strong');
					mTitle.textContent = m.displayName || m.userId;
					const mMeta = document.createElement('p');
					mMeta.className = 'form-help';
					mMeta.textContent = `${m.effectiveFrom} – ${m.effectiveTo || t('open')}`;
					mText.append(mTitle, mMeta);
					const mActions = document.createElement('span');
					mActions.className = 'azc-list__actions';
					if (m.effectiveTo === null || m.effectiveTo === undefined || m.effectiveTo === '') {
						const endInput = document.createElement('input');
						endInput.type = 'date';
						endInput.value = (window.ArbeitszeitCheckTime ? window.ArbeitszeitCheckTime.todayYmd() : new Date().toLocaleDateString('sv-SE'));
						endInput.setAttribute('aria-label', `${t('End membership for')} ${m.displayName || m.userId}`);
						const endBtn = document.createElement('button');
						endBtn.type = 'button';
						endBtn.className = 'azc-btn azc-btn--secondary';
						endBtn.textContent = t('End membership');
						endBtn.addEventListener('click', async () => {
							if (!endInput.reportValidity() || endInput.value === '') { endInput.focus(); return; }
							setBusy(endBtn, true);
							try {
								await request(tpl('cohortMembershipTemplate', m.id), {
									method: 'PUT',
									body: JSON.stringify({ revision: m.revision, effectiveTo: endInput.value }),
								});
								await loadMembers();
								await loadCohorts();
							} catch (e) {
								memberStatus.textContent = e.message;
							} finally { setBusy(endBtn, false); }
						});
						mActions.append(endInput, endBtn);
					}
					mli.append(mText, mActions);
					membersList.append(mli);
				});
				memberStatus.textContent = rows.length === 0 ? t('No members yet.') : '';
			} catch (e) {
				memberStatus.textContent = e.message;
			}
		}

		addForm.addEventListener('submit', async (ev) => {
			ev.preventDefault();
			if (!addForm.reportValidity()) return;
			const uid = hidden.value.trim();
			if (!uid) { search.focus(); pickStatus.textContent = t('Choose a person first.'); return; }
			setBusy(submit, true);
			try {
				await request(tpl('cohortMembersTemplate', cohort.id), {
					method: 'POST',
					body: JSON.stringify({
						userId: uid,
						effectiveFrom: fromInput.value,
						effectiveTo: toInput.value || undefined,
					}),
				});
				memberPicker?.clear();
				fromInput.value = (window.ArbeitszeitCheckTime ? window.ArbeitszeitCheckTime.todayYmd() : new Date().toLocaleDateString('sv-SE'));
				toInput.value = '';
				memberStatus.textContent = t('Member added.');
				await loadMembers();
				await loadCohorts();
			} catch (e) {
				memberStatus.textContent = e.message;
			} finally { setBusy(submit, false); }
		});

		loadMembers();
		return panel;
	}

	async function loadCohorts() {
		if (!enabled) return;
		const my = ++cohortsToken;
		try {
			const body = await request(root.dataset.cohortsUrl);
			if (my !== cohortsToken) return;
			cohorts = body.items || [];
			renderCohorts();
		} catch (e) {
			if (my === cohortsToken) {
				cohortStatus.textContent = e.message;
			}
		}
	}

	document.getElementById('se-cohort-form').addEventListener('submit', async (ev) => {
		ev.preventDefault();
		const formEl = ev.currentTarget;
		clearFieldErrors(formEl);
		if (!formEl.reportValidity()) return;
		const submit = formEl.querySelector('[type="submit"]');
		setBusy(submit, true);
		try {
			const payload = Object.fromEntries(new FormData(formEl));
			payload.members = selectedCohortMembers.map((m) => m.userId);
			await request(root.dataset.cohortCreateUrl, {
				method: 'POST',
				body: JSON.stringify(payload),
			});
			formEl.reset();
			selectedCohortMembers.length = 0;
			renderCohortMemberChips();
			cohortStatus.textContent = t('Protected group created.');
			hideCohortEditor();
			await loadCohorts();
			await loadTargets();
		} catch (e) {
			if (!showFieldErrors(formEl, e.fields)) cohortStatus.textContent = e.message;
		} finally {
			setBusy(submit, false);
		}
	});

	searchEl.addEventListener('input', renderTypes);
	filterEl.addEventListener('change', renderTypes);

	const enableBtn = document.getElementById('se-enable');
	if (enableBtn && !enabled) {
		enableBtn.addEventListener('click', async () => {
			const c = window.AzcComponents || window.ArbeitszeitCheckComponents;
			const statusEl = document.getElementById('se-enable-status');
			const ok = c && typeof c.confirmDialog === 'function'
				? await c.confirmDialog({
					title: t('Enable special leave entitlements?'),
					message: t('Enable special leave entitlements and protected groups? Once definitions and bookings exist, downgrading to a build without this feature cannot write them. You can keep the feature enabled and simply not use it.'),
					confirmLabel: t('Enable'),
					cancelLabel: t('Cancel'),
					variant: 'info',
				})
				: false;
			const confirmed = ok === true || (ok && ok.confirmed === true);
			if (!confirmed) {
				return;
			}
			enableBtn.disabled = true;
			try {
				await request(root.dataset.enableUrl, {
					method: 'POST',
					body: JSON.stringify({ acknowledgeRollbackWarning: true }),
				});
				window.location.reload();
			} catch (e) {
				if (statusEl) statusEl.textContent = e.message;
				enableBtn.disabled = false;
			}
		});
	}

	if (enabled) {
		Promise.all([loadTypes(), loadCohorts()]).then(loadTargets);
	}

	// Test hooks (vitest stages the production DOM). Read-only surface —
	// mutations in tests go through the same DOM events users trigger.
	window.__ArbeitszeitCheckSeAdminTestables = {
		renderTypes,
		renderCohorts,
		loadTypes,
		loadTargets,
		loadCohorts,
		openDetail,
		openCreateEditor,
		openPolicyEditor,
		closeEditor,
		showFieldErrors,
		emptyRow,
		loadingRow,
		get types() { return types; },
		set types(v) { types = Array.isArray(v) ? v : []; },
		get currentType() { return currentType; },
		get editorMode() { return editorMode; },
	};
})();
