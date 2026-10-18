/**
 * Admin page: Sonderfreistellungen (special leave entitlements).
 * One page, four decisions (name, amount, recipients, from-date) plus a
 * server-side impact preview that gates the primary CTA (spec §7.2).
 */
(function () {
	'use strict';

	const root = document.getElementById('se-admin-root');
	if (!root) return;
	const t = (text) => (typeof window.t === 'function' ? window.t('arbeitszeitcheck', text) : text);
	const token = document.querySelector('head')?.dataset.requesttoken || window.OC?.requestToken || '';

	const enabled = root.dataset.enabled === '1';
	const listEl = document.getElementById('se-type-list');
	const listStatus = document.getElementById('se-list-status');
	const emptyEl = document.getElementById('se-empty');
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

	let types = [];
	let policies = [];
	let cohorts = [];
	let currentType = null;
	let previewToken = 0;
	let previewOk = false;

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

	function clearFieldErrors(scope) {
		scope.querySelectorAll('[aria-invalid="true"]').forEach((f) => f.removeAttribute('aria-invalid'));
		scope.querySelectorAll('.azc-field-error').forEach((e) => { e.hidden = true; e.textContent = ''; });
	}

	function showFieldErrors(fields) {
		let first = null;
		Object.entries(fields || {}).forEach(([name, message]) => {
			const err = document.getElementById(`se-${name}-error`) || document.getElementById(`se-cohort-${name}-error`);
			const field = form.elements.namedItem(name) || document.querySelector(`[name="${name}"]`);
			if (err) {
				err.textContent = String(message);
				err.hidden = false;
				if (field) field.setAttribute('aria-invalid', 'true');
				if (!first) first = field || err;
			}
		});
		if (first && typeof first.focus === 'function') first.focus();
		return Object.keys(fields || {}).length > 0;
	}

	function escapeText(v) { return String(v ?? ''); }

	function fmtAmount(v, unit) {
		const n = Number(v);
		if (!Number.isFinite(n)) return '—';
		return `${n.toFixed(2).replace(/\.00$/, '')} ${unit === 'hours' ? t('hours') : t('days')}`;
	}

	/* ---------- type list ---------- */

	function scopeHuman(scopeType) {
		return {
			org: t('Everyone'),
			model: t('Working-time model'),
			policy_group: t('Entitlement group'),
			team: t('Team'),
			user: t('Single person'),
		}[scopeType] || scopeType;
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
		visible.forEach((item) => {
			const li = document.createElement('li');
			li.className = 'azc-list__item se-type-card';
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'se-type-card__btn';
			const title = document.createElement('strong');
			title.textContent = item.publicLabel;
			const meta = document.createElement('span');
			meta.className = 'form-help';
			const parts = [
				item.unit === 'hours' ? t('Hours') : t('Days'),
				{ calendar_year: t('per calendar year'), service_year: t('per employment year'), one_time: t('one-time') }[item.periodType] || item.periodType,
				item.active ? t('Active') : t('Inactive'),
			];
			if (item.confidentialLabel) parts.push(`${t('Confidential:')} ${item.confidentialLabel}`);
			meta.textContent = parts.join(' · ');
			btn.append(title, meta);
			btn.addEventListener('click', () => openDetail(item));
			li.append(btn);
			const actions = document.createElement('span');
			actions.className = 'azc-list__actions';
			const toggle = document.createElement('button');
			toggle.type = 'button';
			toggle.className = 'azc-btn azc-btn--secondary';
			toggle.textContent = item.active ? t('Deactivate') : t('Activate');
			toggle.addEventListener('click', async () => {
				setBusy(toggle, true);
				try {
					await request(tpl('typeActiveTemplate', item.id), {
						method: 'POST',
						body: JSON.stringify({ active: item.active ? '0' : '1', version: item.version }),
					});
					await loadTypes();
				} catch (e) { announce(e.message); }
				finally { setBusy(toggle, false); }
			});
			actions.append(toggle);
			li.append(actions);
			listEl.append(li);
		});
		emptyEl.hidden = visible.length > 0;
		announce(visible.length === 0 ? '' : (typeof window.n === 'function'
			? window.n('arbeitszeitcheck', '1 entitlement', '%n entitlements', visible.length)
			: `${visible.length} ${visible.length === 1 ? t('entitlement') : t('entitlements')}`));
	}

	async function loadTypes() {
		listStatus.textContent = t('Loading…');
		try {
			const body = await request(root.dataset.typesUrl);
			types = body.items || [];
			renderTypes();
		} catch (e) {
			listStatus.textContent = e.message;
		}
	}

	/* ---------- scope targets ---------- */

	const scopeSel = document.getElementById('se-scope');
	const targetGroup = document.getElementById('se-target-group');
	const targetSel = document.getElementById('se-target');
	const targetEmpty = document.getElementById('se-target-empty');
	const userWrap = document.getElementById('se-user-wrap');
	const userHidden = document.getElementById('se-user-id');

	const userPicker = window.ArbeitszeitCheck?.initAdminUserPicker({
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
			targetEmpty.textContent = e.message;
		}
		schedulePreview();
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

	/* ---------- create ---------- */

	form.addEventListener('input', (ev) => {
		if (ev.target?.name) {
			ev.target.removeAttribute('aria-invalid');
			schedulePreview();
		}
	});
	scopeSel.addEventListener('change', loadTargets);
	targetSel.addEventListener('change', schedulePreview);
	form.querySelectorAll('[name="operation"]').forEach((r) => r.addEventListener('change', schedulePreview));

	document.getElementById('se-new-type').addEventListener('click', () => {
		currentType = null;
		form.reset();
		document.getElementById('se-type-version').value = '';
		document.getElementById('se-editor-title').textContent = t('New special leave entitlement');
		const tomorrow = new Date(Date.now() + 86400000);
		document.getElementById('se-from').value = (window.ArbeitszeitCheckTime ? window.ArbeitszeitCheckTime.ymd(tomorrow) : tomorrow.toLocaleDateString('sv-SE'));
		editor.hidden = false;
		detail.hidden = true;
		loadTargets();
		document.getElementById('se-label').focus();
	});

	document.getElementById('se-cancel').addEventListener('click', () => {
		editor.hidden = true;
		currentType = null;
	});

	form.addEventListener('submit', async (ev) => {
		ev.preventDefault();
		clearFieldErrors(document.getElementById('se-editor'));
		if (!form.reportValidity()) return;
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
			announce(t('Special leave entitlement saved.'));
			await loadTypes();
		} catch (e) {
			if (!showFieldErrors(e.fields)) {
				if (e.status === 409) {
					conflictBox.hidden = false;
					document.getElementById('se-conflict-text').textContent = e.message;
				} else {
					document.getElementById('se-form-error-text').textContent = e.message;
					document.getElementById('se-form-error').hidden = false;
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

	async function openDetail(item) {
		currentType = item;
		detail.hidden = false;
		document.getElementById('se-detail-title').textContent = item.publicLabel;
		document.getElementById('se-detail-meta').textContent = `${item.code} · ${item.unit} · ${item.periodType} · ${item.active ? t('Active') : t('Inactive')}`;
		await loadPolicies(item.id);
		detail.scrollIntoView({ block: 'start' });
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
				li.className = 'azc-list__item';
				const text = document.createElement('div');
				const title = document.createElement('strong');
				title.textContent = `${scopeHuman(p.scopeType)} ${p.scopeId !== 'org' ? `#${escapeText(p.scopeId)}` : ''} → ${fmtAmount(p.amount, currentType?.unit)}`;
				const meta = document.createElement('p');
				meta.className = 'form-help';
				meta.textContent = `${p.operation} · ${p.effectiveFrom} – ${p.effectiveTo || t('open')} · ${t('Priority')} ${p.priority}`;
				text.append(title, meta);
				const actions = document.createElement('span');
				actions.className = 'azc-list__actions';
				const endInput = document.createElement('input');
				endInput.type = 'date';
				endInput.setAttribute('aria-label', t('Last valid date'));
				const endBtn = document.createElement('button');
				endBtn.type = 'button';
				endBtn.className = 'azc-btn azc-btn--secondary';
				endBtn.textContent = t('End rule');
				endBtn.addEventListener('click', async () => {
					if (!endInput.reportValidity() || endInput.value === '') { endInput.focus(); return; }
					setBusy(endBtn, true);
					try {
						await request(tpl('policyUpdateTemplate', p.id), {
							method: 'PUT',
							body: JSON.stringify({ revision: p.version, effectiveTo: endInput.value }),
						});
						await loadPolicies(currentType.id);
					} catch (e) {
						if (e.status === 409) {
							conflictBox.hidden = false;
							document.getElementById('se-conflict-text').textContent = e.message;
						} else {
							document.getElementById('se-policy-status').textContent = e.message;
						}
					} finally { setBusy(endBtn, false); }
				});
				actions.append(endInput, endBtn);
				li.append(text, actions);
				ul.append(li);
			});
			wrap.append(h, ul);
		});
		document.getElementById('se-policy-status').textContent = policies.length === 0 ? t('No rules yet.') : '';
	}

	async function loadPolicies(typeId) {
		try {
			const body = await request(tpl('policiesTemplate', typeId));
			policies = body.items || [];
			renderPolicies();
		} catch (e) {
			document.getElementById('se-policy-status').textContent = e.message;
		}
	}

	document.getElementById('se-detail-close').addEventListener('click', () => {
		detail.hidden = true;
		currentType = null;
	});

	document.getElementById('se-detail-add-policy').addEventListener('click', () => {
		if (!currentType) return;
		document.getElementById('se-editor-title').textContent = `${t('New rule for')} ${currentType.publicLabel}`;
		form.reset();
		const tomorrow = new Date(Date.now() + 86400000);
		document.getElementById('se-from').value = (window.ArbeitszeitCheckTime ? window.ArbeitszeitCheckTime.ymd(tomorrow) : tomorrow.toLocaleDateString('sv-SE'));
		// Definition fields are read-only for an existing type.
		['se-label', 'se-unit', 'se-period', 'se-confidential', 'se-absence-type'].forEach((id) => {
			const el = document.getElementById(id);
			if (!el) return;
			el.disabled = true;
		});
		document.getElementById('se-label').value = currentType.publicLabel;
		document.getElementById('se-unit').value = currentType.unit;
		document.getElementById('se-period').value = currentType.periodType;
		editor.hidden = false;
		loadTargets();
		schedulePreview();
		document.getElementById('se-amount').focus();
	});

	// Re-enable definition fields when creating a new type.
	document.getElementById('se-new-type').addEventListener('click', () => {
		['se-label', 'se-unit', 'se-period', 'se-confidential', 'se-absence-type'].forEach((id) => {
			const el = document.getElementById(id);
			if (el) el.disabled = false;
		});
	});

	document.getElementById('se-trace-run').addEventListener('click', async () => {
		if (!currentType) return;
		const out = document.getElementById('se-trace-out');
		const uid = document.getElementById('se-trace-user').value.trim();
		const date = document.getElementById('se-trace-date').value || (window.ArbeitszeitCheckTime ? window.ArbeitszeitCheckTime.todayYmd() : new Date().toLocaleDateString('sv-SE'));
		if (!uid) { out.textContent = t('Enter a user id.'); return; }
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

	const cohortList = document.getElementById('se-cohort-list');
	const cohortStatus = document.getElementById('se-cohort-status');

	let openCohortId = null;
	let memberPicker = null;

	function renderCohorts() {
		cohortList.replaceChildren();
		cohorts.forEach((c) => {
			const li = document.createElement('li');
			li.className = 'azc-list__item se-cohort-item';
			const text = document.createElement('div');
			const title = document.createElement('strong');
			title.textContent = c.publicLabel;
			const meta = document.createElement('p');
			meta.className = 'form-help';
			const memberText = typeof window.n === 'function'
				? window.n('arbeitszeitcheck', '1 member', '%n members', c.memberCount)
				: `${c.memberCount} ${c.memberCount === 1 ? t('member') : t('members')}`;
			meta.textContent = `${c.code} · ${memberText} · ${c.active ? t('Active') : t('Inactive')}`;
			text.append(title, meta);
			const actions = document.createElement('span');
			actions.className = 'azc-list__actions';
			const manageBtn = document.createElement('button');
			manageBtn.type = 'button';
			manageBtn.className = 'azc-btn azc-btn--secondary';
			manageBtn.textContent = t('Manage members');
			manageBtn.setAttribute('aria-expanded', openCohortId === c.id ? 'true' : 'false');
			manageBtn.addEventListener('click', () => {
				openCohortId = openCohortId === c.id ? null : c.id;
				renderCohorts();
			});
			actions.append(manageBtn);
			li.append(text, actions);
			cohortList.append(li);
			if (openCohortId === c.id) {
				const panelLi = document.createElement('li');
				panelLi.className = 'azc-list__item se-cohort-panel-wrap';
				panelLi.append(buildCohortPanel(c));
				cohortList.append(panelLi);
			}
		});
		cohortStatus.textContent = cohorts.length === 0 ? t('No protected groups yet.') : '';
	}

	function buildCohortPanel(cohort) {
		if (memberPicker) { memberPicker.destroy(); memberPicker = null; }

		const panel = document.createElement('div');
		panel.className = 'se-cohort-panel';
		const prefix = `se-member-${cohort.id}`;

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
		try {
			const body = await request(root.dataset.cohortsUrl);
			cohorts = body.items || [];
			renderCohorts();
		} catch (e) {
			cohortStatus.textContent = e.message;
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
			await request(root.dataset.cohortCreateUrl, {
				method: 'POST',
				body: JSON.stringify(Object.fromEntries(new FormData(formEl))),
			});
			formEl.reset();
			cohortStatus.textContent = t('Protected group created.');
			await loadCohorts();
			await loadTargets();
		} catch (e) {
			if (!showFieldErrors(e.fields)) cohortStatus.textContent = e.message;
		} finally {
			setBusy(submit, false);
		}
	});

	searchEl.addEventListener('input', renderTypes);
	filterEl.addEventListener('change', renderTypes);

	if (enabled) {
		Promise.all([loadTypes(), loadCohorts()]).then(loadTargets);
	}
})();
