/**
 * Admin tariff rule sets CRUD page for the ArbeitszeitCheck app.
 *
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const Utils = window.ArbeitszeitCheckUtils || {};
	const Messaging = window.ArbeitszeitCheckMessaging || {};
	const Components = window.AzcComponents || window.ArbeitszeitCheckComponents || {};

	const API_LIST = generateUrl('/apps/arbeitszeitcheck/api/admin/tariff-rule-sets');

	/** @type {Array<{id:number|string,tariffCode?:string,version?:string,status?:string}>} */
	let cachedRuleSets = [];

	const MODULE_DEFINITIONS = [
		{
			type: 'base_formula',
			labelKey: 'moduleBaseFormula',
			fields: [
				{ name: 'reference_days', labelKey: 'referenceDays', type: 'number', step: '0.5', min: 0, max: 366, defaultValue: 30 },
				{ name: 'reference_week_days', labelKey: 'referenceWeekDays', type: 'number', step: '0.5', min: 1, max: 7, defaultValue: 5 },
				{ name: 'work_days_per_week', labelKey: 'workDaysPerWeek', type: 'number', step: '0.5', min: 1, max: 7, defaultValue: 5 },
			],
		},
		{
			type: 'additional_entitlements',
			labelKey: 'moduleAdditional',
			fields: [
				{ name: 'days', labelKey: 'days', type: 'number', step: '0.5', min: 0, max: 366, defaultValue: 0 },
			],
		},
		{
			type: 'deductions',
			labelKey: 'moduleDeductions',
			fields: [
				{ name: 'days', labelKey: 'days', type: 'number', step: '0.5', min: 0, max: 366, defaultValue: 0 },
			],
		},
		{
			type: 'rounding_rule',
			labelKey: 'moduleRounding',
			fields: [
				{
					name: 'mode',
					labelKey: 'roundingMode',
					type: 'select',
					defaultValue: 'commercial',
					options: [
						{ value: 'commercial', labelKey: 'roundingCommercial' },
						{ value: 'half_day', labelKey: 'roundingHalfDay' },
						{ value: 'ceil', labelKey: 'roundingCeil' },
						{ value: 'floor', labelKey: 'roundingFloor' },
					],
				},
			],
		},
		{
			type: 'pro_rata_rule',
			labelKey: 'moduleProRata',
			fields: [
				{
					name: 'mode',
					labelKey: 'proRataMode',
					type: 'select',
					defaultValue: 'none',
					options: [
						{ value: 'none', labelKey: 'proRataNone' },
						{ value: 'monthly', labelKey: 'proRataMonth' },
						{ value: 'daily', labelKey: 'proRataDay' },
					],
				},
			],
		},
	];

	function l10n(key) {
		const map = window.ArbeitszeitCheck && window.ArbeitszeitCheck.tariffRulesL10n;
		if (map && Object.prototype.hasOwnProperty.call(map, key)) {
			return String(map[key]);
		}
		return key;
	}

	function l10nParams(key, ...params) {
		let text = l10n(key);
		params.forEach((val, index) => {
			const n = index + 1;
			text = text.split(`%${n}$s`).join(String(val));
			text = text.split(`%${n}$d`).join(String(val));
		});
		return text;
	}

	function escapeHtml(value) {
		if (Utils.escapeHtml) {
			return Utils.escapeHtml(value == null ? '' : String(value));
		}
		const div = document.createElement('div');
		div.textContent = value == null ? '' : String(value);
		return div.innerHTML;
	}

	function generateUrl(path) {
		if (Utils.resolveUrl) {
			return Utils.resolveUrl(path);
		}
		if (window.OC && typeof window.OC.generateUrl === 'function') {
			return window.OC.generateUrl(path);
		}
		return path;
	}

	function requestToken() {
		if (Utils.getRequestToken) {
			return Utils.getRequestToken();
		}
		return (typeof OC !== 'undefined' && OC.requestToken) ? OC.requestToken : '';
	}

	function announce(message) {
		const region = document.getElementById('admin-tariff-rules-feedback');
		if (region) {
			region.textContent = String(message || '');
		}
	}

	async function apiFetch(url, options) {
		const opts = Object.assign({ method: 'GET', headers: {}, credentials: 'same-origin' }, options || {});
		opts.headers = Object.assign({}, opts.headers, {
			Accept: 'application/json',
			requesttoken: requestToken(),
			'OCS-APIRequest': 'true',
		});
		if (opts.json !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(opts.json);
			delete opts.json;
		}
		const normalized = Utils.normalizeMutatingFetchInit
			? Utils.normalizeMutatingFetchInit(opts)
			: opts;
		const response = await fetch(generateUrl(url), normalized);
		let payload = null;
		try {
			payload = await response.json();
		} catch (_) {
			payload = null;
		}
		return { ok: response.ok, status: response.status, payload };
	}

	function statusLabel(status) {
		switch (status) {
			case 'draft': return l10n('statusDraft');
			case 'active': return l10n('statusActive');
			case 'retired': return l10n('statusRetired');
			default: return status;
		}
	}

	function statusBadgeClass(status) {
		const variant = Utils.badgeVariantForTariffRuleSetStatus
			? Utils.badgeVariantForTariffRuleSetStatus(status)
			: 'secondary';
		return `badge badge--${variant}`;
	}

	function canActivateRuleSet(ruleSet) {
		if (!ruleSet || ruleSet.status !== 'draft') {
			return false;
		}
		if (typeof ruleSet.canActivate === 'boolean') {
			return ruleSet.canActivate;
		}
		if (typeof ruleSet.isComplete === 'boolean') {
			return ruleSet.isComplete;
		}
		return typeof ruleSet.modulesCount === 'number' && ruleSet.modulesCount > 0;
	}

	function modulesCellHtml(ruleSet) {
		const count = typeof ruleSet.modulesCount === 'number' ? ruleSet.modulesCount : null;
		const countText = count === null ? '—' : String(count);
		if (ruleSet.status === 'draft' && !canActivateRuleSet(ruleSet)) {
			return `
				<span class="admin-tariff-rules__modules-count">${escapeHtml(countText)}</span>
				<span class="badge badge--warning admin-tariff-rules__incomplete-badge">${escapeHtml(l10n('incompleteDraft'))}</span>
				<span class="sr-only">${escapeHtml(l10n('incompleteDraftHelp'))}</span>`;
		}
		return escapeHtml(countText);
	}

	function renderTable(ruleSets) {
		const tbody = document.getElementById('tariff-rules-tbody');
		if (!tbody) {
			return;
		}

		if (!ruleSets.length) {
			tbody.innerHTML = `
				<tr>
					<td colspan="8">
						<div class="azc-empty-state admin-tariff-rules__empty">
							<p class="azc-empty-state__title">${escapeHtml(l10n('noRuleSets'))}</p>
							<p class="azc-empty-state__lead">${escapeHtml(l10n('noRuleSetsHelp'))}</p>
						</div>
					</td>
				</tr>`;
			return;
		}

		tbody.innerHTML = ruleSets.map((rs) => {
			const id = String(rs.id);
			const actions = [];
			if (rs.status === 'draft') {
				actions.push(`<button type="button" class="azc-btn azc-btn--secondary azc-btn--sm" data-action="edit" data-id="${escapeHtml(id)}">${escapeHtml(l10n('edit'))}</button>`);
				if (canActivateRuleSet(rs)) {
					actions.push(`<button type="button" class="azc-btn azc-btn--primary azc-btn--sm" data-action="activate" data-id="${escapeHtml(id)}">${escapeHtml(l10n('activate'))}</button>`);
				} else {
					actions.push(`<button type="button" class="azc-btn azc-btn--primary azc-btn--sm" data-action="activate" data-id="${escapeHtml(id)}" disabled aria-disabled="true" aria-label="${escapeHtml(l10n('activateIncompleteHint'))}">${escapeHtml(l10n('activate'))}</button>`);
				}
				actions.push(`<button type="button" class="azc-btn azc-btn--danger azc-btn--sm" data-action="delete" data-id="${escapeHtml(id)}">${escapeHtml(l10n('delete'))}</button>`);
			} else if (rs.status === 'active') {
				actions.push(`<button type="button" class="azc-btn azc-btn--secondary azc-btn--sm" data-action="view" data-id="${escapeHtml(id)}">${escapeHtml(l10n('view'))}</button>`);
				actions.push(`<button type="button" class="azc-btn azc-btn--danger azc-btn--sm" data-action="retire" data-id="${escapeHtml(id)}">${escapeHtml(l10n('retire'))}</button>`);
			} else {
				actions.push(`<button type="button" class="azc-btn azc-btn--secondary azc-btn--sm" data-action="view" data-id="${escapeHtml(id)}">${escapeHtml(l10n('view'))}</button>`);
			}
			const td = (label, html, cls) => Utils.responsiveTd
				? Utils.responsiveTd(label, html, cls)
				: `<td${cls ? ` class="${cls}"` : ''}>${html}</td>`;
			return `
				<tr data-rule-set-id="${escapeHtml(id)}">
					${td(l10n('tariffCode'), escapeHtml(rs.tariffCode || ''))}
					${td(l10n('version'), escapeHtml(rs.version || ''))}
					${td(l10n('jurisdiction'), escapeHtml(rs.jurisdiction || '—'))}
					${td(l10n('status'), `<span class="${statusBadgeClass(rs.status)}">${escapeHtml(statusLabel(rs.status))}</span>`)}
					${td(l10n('validFrom'), escapeHtml(rs.validFrom || '—'))}
					${td(l10n('validTo'), escapeHtml(rs.validTo || '—'))}
					${td(l10n('modulesCol'), modulesCellHtml(rs))}
					${td(l10n('actions'), `<div class="azc-table-actions" role="group">${actions.join(' ')}</div>`, 'admin-tariff-rules__row-actions actions-cell')}
				</tr>`;
		}).join('');

		tbody.querySelectorAll('button[data-action]').forEach((btn) => {
			btn.addEventListener('click', () => {
				const action = btn.getAttribute('data-action');
				const id = parseInt(btn.getAttribute('data-id') || '0', 10);
				handleRowAction(action, id);
			});
		});
	}

	async function loadRuleSets() {
		const tbody = document.getElementById('tariff-rules-tbody');
		if (tbody) {
			tbody.innerHTML = `<tr><td colspan="8" class="admin-tariff-rules__empty">${escapeHtml(l10n('loading'))}</td></tr>`;
		}
		const { ok, payload } = await apiFetch(API_LIST, { method: 'GET' });
		if (!ok || !payload || !payload.success) {
			const msg = (payload && payload.error) || l10n('loadingError');
			if (tbody) {
				tbody.innerHTML = `<tr><td colspan="8" class="admin-tariff-rules__empty">${escapeHtml(msg)}</td></tr>`;
			}
			if (Messaging.showError) {
				Messaging.showError(msg);
			}
			return;
		}
		cachedRuleSets = Array.isArray(payload.ruleSets) ? payload.ruleSets : [];
		renderTable(cachedRuleSets);
	}

	function normalizeIdentity(value) {
		return String(value || '').trim().replace(/\s+/g, ' ');
	}

	function normalizeProRataMode(mode) {
		const m = String(mode || '');
		if (m === 'month') {
			return 'monthly';
		}
		if (m === 'day') {
			return 'daily';
		}
		return m;
	}

	function findDuplicateRuleSet(tariffCode, version) {
		const code = normalizeIdentity(tariffCode);
		const ver = normalizeIdentity(version);
		if (!code || !ver) {
			return null;
		}
		return cachedRuleSets.find((rs) => (
			normalizeIdentity(rs.tariffCode) === code
			&& normalizeIdentity(rs.version) === ver
		)) || null;
	}

	function isDuplicateConflictResponse(response) {
		if (!response || response.status !== 409) {
			return false;
		}
		const payload = response.payload;
		if (!payload) {
			return false;
		}
		return payload.code === 'duplicate_code_version'
			|| (payload.existing && payload.existing.id != null);
	}

	function suggestNextVersion(version) {
		const ver = normalizeIdentity(version);
		if (!ver) {
			return '';
		}
		const dotted = ver.match(/^(.+)\.(\d+)$/);
		if (dotted) {
			return `${dotted[1]}.${parseInt(dotted[2], 10) + 1}`;
		}
		const trailingYear = ver.match(/^(.+?)(\d{4})$/);
		if (trailingYear) {
			return `${trailingYear[1]}${parseInt(trailingYear[2], 10) + 1}`;
		}
		return `${ver}-2`;
	}

	function updateSubmitDisabled(modal, disabled) {
		const submitBtn = modal.querySelector('#tariff-rules-submit');
		if (submitBtn) {
			submitBtn.disabled = !!disabled;
			submitBtn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
		}
	}

	function highlightRowInTable(id) {
		const tbody = document.getElementById('tariff-rules-tbody');
		if (!tbody) {
			return;
		}
		tbody.querySelectorAll('tr[data-rule-set-id]').forEach((row) => {
			row.classList.remove('admin-tariff-rules__row--highlight');
		});
		const row = tbody.querySelector(`tr[data-rule-set-id="${String(id)}"]`);
		if (!row) {
			return;
		}
		row.classList.add('admin-tariff-rules__row--highlight');
		row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		setTimeout(() => row.classList.remove('admin-tariff-rules__row--highlight'), 6000);
	}

	async function handleRowAction(action, id) {
		if (!id) {
			return;
		}
		if (action === 'edit') {
			openEditModal(id);
		} else if (action === 'view') {
			openViewModal(id);
		} else if (action === 'delete') {
			await confirmAndCall(
				l10n('confirmDeleteTitle'),
				l10n('confirmDeleteMessage'),
				l10n('delete'),
				'danger',
				async () => {
					const r = await apiFetch(`${API_LIST}/${encodeURIComponent(id)}`, { method: 'DELETE' });
					if (r.ok && r.payload && r.payload.success) {
						Messaging.showSuccess && Messaging.showSuccess(l10n('deleted'));
						announce(l10n('deleted'));
						loadRuleSets();
					} else {
						Messaging.showError && Messaging.showError((r.payload && r.payload.error) || l10n('deleteError'));
					}
				},
			);
		} else if (action === 'activate') {
			const row = cachedRuleSets.find((rs) => rs.id === id);
			if (row && !canActivateRuleSet(row)) {
				Messaging.showError && Messaging.showError(l10n('activateIncompleteHint'));
				announce(l10n('activateIncompleteHint'));
				openEditModal(id);
				return;
			}
			await confirmAndCall(
				l10n('confirmActivateTitle'),
				l10n('confirmActivateMessage'),
				l10n('activate'),
				'warning',
				async () => {
					const r = await apiFetch(`${API_LIST}/${encodeURIComponent(id)}/activate`, { method: 'POST', json: {} });
					if (r.ok && r.payload && r.payload.success) {
						Messaging.showSuccess && Messaging.showSuccess(l10n('activated'));
						announce(l10n('activated'));
						loadRuleSets();
					} else {
						const errMsg = (r.payload && r.payload.error) || l10n('activateError');
						Messaging.showError && Messaging.showError(errMsg);
						announce(errMsg);
						if (r.payload && r.payload.errors && r.payload.errors.modules) {
							openEditModal(id);
						}
					}
				},
			);
		} else if (action === 'retire') {
			await confirmAndCall(
				l10n('confirmRetireTitle'),
				l10n('confirmRetireMessage'),
				l10n('retire'),
				'danger',
				async () => {
					const r = await apiFetch(`${API_LIST}/${encodeURIComponent(id)}/retire`, { method: 'POST', json: {} });
					if (r.ok && r.payload && r.payload.success) {
						Messaging.showSuccess && Messaging.showSuccess(l10n('retired'));
						announce(l10n('retired'));
						loadRuleSets();
					} else {
						Messaging.showError && Messaging.showError((r.payload && r.payload.error) || l10n('retireError'));
					}
				},
			);
		}
	}

	async function confirmAndCall(title, message, confirmLabel, variant, fn) {
		const confirmed = Utils.confirmDestructiveAction
			? await Utils.confirmDestructiveAction({ title, message, confirmLabel, variant })
			: null;
		if (!confirmed) {
			return;
		}
		try {
			await fn();
		} catch (_) {
			Messaging.showError && Messaging.showError(l10n('updateError'));
		}
	}

	function findModuleDefinition(type) {
		return MODULE_DEFINITIONS.find((d) => d.type === type) || null;
	}

	function moduleTypeOptionsHtml(selectedType) {
		return MODULE_DEFINITIONS.map((d) => {
			const sel = d.type === selectedType ? ' selected' : '';
			return `<option value="${escapeHtml(d.type)}"${sel}>${escapeHtml(l10n(d.labelKey))}</option>`;
		}).join('');
	}

	function moduleFieldsHtml(moduleIndex, def, config) {
		return def.fields.map((field) => {
			const id = `module-${moduleIndex}-${field.name}`;
			const current = (config && Object.prototype.hasOwnProperty.call(config, field.name))
				? config[field.name]
				: field.defaultValue;
			if (field.type === 'select') {
				const optsHtml = field.options.map((opt) => {
					const sel = String(current) === String(opt.value) ? ' selected' : '';
					return `<option value="${escapeHtml(opt.value)}"${sel}>${escapeHtml(l10n(opt.labelKey))}</option>`;
				}).join('');
				return `
					<div class="form-group admin-tariff-rules__module-field">
						<label for="${id}" class="form-label">${escapeHtml(l10n(field.labelKey))}</label>
						<select id="${id}" class="form-input" data-field-name="${escapeHtml(field.name)}">${optsHtml}</select>
					</div>`;
			}
			const minAttr = field.min !== undefined ? ` min="${escapeHtml(field.min)}"` : '';
			const maxAttr = field.max !== undefined ? ` max="${escapeHtml(field.max)}"` : '';
			const stepAttr = field.step !== undefined ? ` step="${escapeHtml(field.step)}"` : '';
			return `
				<div class="form-group admin-tariff-rules__module-field">
					<label for="${id}" class="form-label">${escapeHtml(l10n(field.labelKey))}</label>
					<input type="number" id="${id}" class="form-input" value="${escapeHtml(current ?? '')}"
						data-field-name="${escapeHtml(field.name)}"${minAttr}${maxAttr}${stepAttr}>
				</div>`;
		}).join('');
	}

	function moduleLegendLabel(moduleIndex, type) {
		const def = findModuleDefinition(type) || MODULE_DEFINITIONS[0];
		return l10nParams('moduleLegend', moduleIndex + 1, l10n(def.labelKey));
	}

	function moduleRowHtml(moduleIndex, type, config, readOnly) {
		const def = findModuleDefinition(type) || MODULE_DEFINITIONS[0];
		const normalizedConfig = Object.assign({}, config || {});
		if (def.type === 'pro_rata_rule' && normalizedConfig.mode) {
			normalizedConfig.mode = normalizeProRataMode(normalizedConfig.mode);
		}
		const removeBtn = readOnly ? '' : `<button type="button" class="azc-btn azc-btn--danger azc-btn--sm admin-tariff-rules__remove-module">${escapeHtml(l10n('remove'))}</button>`;
		const typeDisabled = readOnly ? ' disabled' : '';
		return `
			<fieldset class="admin-tariff-rules__module" data-module-index="${moduleIndex}">
				<legend>${escapeHtml(moduleLegendLabel(moduleIndex, def.type))}</legend>
				<div class="form-group admin-tariff-rules__module-type-row">
					<label for="module-type-${moduleIndex}" class="form-label">${escapeHtml(l10n('moduleType'))}</label>
					<select id="module-type-${moduleIndex}" class="form-input admin-tariff-rules__module-type"${typeDisabled}>
						${moduleTypeOptionsHtml(def.type)}
					</select>
				</div>
				<div class="admin-tariff-rules__module-fields">
					${moduleFieldsHtml(moduleIndex, def, normalizedConfig)}
				</div>
				${removeBtn}
			</fieldset>`;
	}

	function reindexModules(modulesHost) {
		modulesHost.querySelectorAll('.admin-tariff-rules__module').forEach((fs, idx) => {
			fs.setAttribute('data-module-index', String(idx));
			const typeSelect = fs.querySelector('.admin-tariff-rules__module-type');
			const moduleType = typeSelect ? typeSelect.value : 'base_formula';
			if (typeSelect) {
				typeSelect.id = `module-type-${idx}`;
			}
			const legend = fs.querySelector('legend');
			if (legend) {
				legend.textContent = moduleLegendLabel(idx, moduleType);
			}
			fs.querySelectorAll('[data-field-name]').forEach((el) => {
				const name = el.getAttribute('data-field-name');
				if (name) {
					el.id = `module-${idx}-${name}`;
				}
			});
		});
	}

	function buildModalHtml(ruleSet, isEdit, readOnly) {
		const titleText = readOnly
			? l10n('viewTitle')
			: (isEdit ? l10n('editTitle') : l10n('createTitle'));
		const code = ruleSet ? ruleSet.tariffCode || '' : '';
		const version = ruleSet ? ruleSet.version || '' : '';
		const jurisdiction = ruleSet ? ruleSet.jurisdiction || '' : '';
		const validFrom = ruleSet ? ruleSet.validFrom || new Date().toISOString().slice(0, 10) : new Date().toISOString().slice(0, 10);
		const validTo = ruleSet ? ruleSet.validTo || '' : '';
		const activationMode = ruleSet ? ruleSet.activationMode || 'immediate' : 'immediate';
		const modules = (ruleSet && Array.isArray(ruleSet.modules) && ruleSet.modules.length)
			? ruleSet.modules
			: [{ moduleType: 'base_formula', config: {} }];
		const lockAttr = (isEdit || readOnly) ? ' disabled' : '';
		const moduleRowsHtml = modules.map((m, idx) => moduleRowHtml(idx, m.moduleType, m.config || {}, readOnly)).join('');
		const readOnlyAttr = readOnly ? ' aria-readonly="true"' : '';
		const readOnlyNote = readOnly
			? `<aside class="azc-callout azc-callout--info" role="note"><p class="azc-callout__text">${escapeHtml(l10n('readOnlyHelp'))}</p></aside>`
			: '';

		return `
			<div class="modal modal--lg admin-tariff-rules__modal" id="tariff-rules-modal" role="dialog" aria-modal="true" aria-labelledby="tariff-rules-modal-title"${readOnlyAttr}>
				<div class="modal-header">
					<h2 class="modal-title" id="tariff-rules-modal-title">${escapeHtml(titleText)}</h2>
					<button type="button" class="modal-close azc-btn azc-btn--ghost" data-dismiss="modal" aria-label="${escapeHtml(readOnly ? l10n('close') : l10n('cancel'))}">×</button>
				</div>
				<form id="tariff-rules-form" class="modal-body" novalidate>
					${readOnlyNote}
					<section class="admin-tariff-rules__form-section" aria-labelledby="tariff-rules-section-identity">
						<h3 id="tariff-rules-section-identity" class="admin-tariff-rules__form-section-title">${escapeHtml(l10n('sectionIdentity'))}</h3>
						<div class="form-grid admin-tariff-rules__form-grid">
							<div class="form-group">
								<label for="tariff-rules-code" class="form-label">${escapeHtml(l10n('tariffCode'))}</label>
								<input type="text" id="tariff-rules-code" class="form-input" required maxlength="64" value="${escapeHtml(code)}"${lockAttr} aria-describedby="tariff-rules-code-help tariff-rules-code-error" autocomplete="off">
								<p id="tariff-rules-code-help" class="form-help">${escapeHtml(l10n('tariffCodeHelp'))}</p>
								<p id="tariff-rules-code-error" class="form-help form-help--error admin-tariff-rules__field-error" role="alert" hidden></p>
							</div>
							<div class="form-group">
								<label for="tariff-rules-version" class="form-label">${escapeHtml(l10n('version'))}</label>
								<input type="text" id="tariff-rules-version" class="form-input" required maxlength="32" value="${escapeHtml(version)}"${lockAttr} aria-describedby="tariff-rules-version-help tariff-rules-version-error" autocomplete="off">
								<p id="tariff-rules-version-help" class="form-help">${escapeHtml(l10n('versionHelp'))}</p>
								<p id="tariff-rules-version-error" class="form-help form-help--error admin-tariff-rules__field-error" role="alert" hidden></p>
							</div>
							<div class="form-group admin-tariff-rules__form-grid--full">
								<label for="tariff-rules-jurisdiction" class="form-label">${escapeHtml(l10n('jurisdiction'))}</label>
								<input type="text" id="tariff-rules-jurisdiction" class="form-input" maxlength="64" value="${escapeHtml(jurisdiction)}"${readOnly ? ' disabled' : ''} aria-describedby="tariff-rules-jurisdiction-help">
								<p id="tariff-rules-jurisdiction-help" class="form-help">${escapeHtml(l10n('jurisdictionHelp'))}</p>
							</div>
						</div>
					</section>
					<section class="admin-tariff-rules__form-section" aria-labelledby="tariff-rules-section-validity">
						<h3 id="tariff-rules-section-validity" class="admin-tariff-rules__form-section-title">${escapeHtml(l10n('sectionValidity'))}</h3>
						<div class="form-grid admin-tariff-rules__form-grid">
							<div class="form-group">
								<label for="tariff-rules-valid-from" class="form-label">${escapeHtml(l10n('validFrom'))}</label>
								<input type="date" id="tariff-rules-valid-from" class="form-input" required value="${escapeHtml(validFrom)}"${readOnly ? ' disabled' : ''}>
							</div>
							<div class="form-group">
								<label for="tariff-rules-valid-to" class="form-label">${escapeHtml(l10n('validTo'))}</label>
								<input type="date" id="tariff-rules-valid-to" class="form-input" value="${escapeHtml(validTo)}"${readOnly ? ' disabled' : ''} aria-describedby="tariff-rules-valid-to-help">
								<p id="tariff-rules-valid-to-help" class="form-help">${escapeHtml(l10n('validToHelp'))}</p>
							</div>
							<div class="form-group admin-tariff-rules__form-grid--full">
								<label for="tariff-rules-activation" class="form-label">${escapeHtml(l10n('activationMode'))}</label>
								<select id="tariff-rules-activation" class="form-input"${readOnly ? ' disabled' : ''} aria-describedby="tariff-rules-activation-help">
									<option value="immediate"${activationMode === 'immediate' ? ' selected' : ''}>${escapeHtml(l10n('activationImmediate'))}</option>
									<option value="next_month"${activationMode === 'next_month' ? ' selected' : ''}>${escapeHtml(l10n('activationNextMonth'))}</option>
									<option value="next_year"${activationMode === 'next_year' ? ' selected' : ''}>${escapeHtml(l10n('activationNextYear'))}</option>
								</select>
								<p id="tariff-rules-activation-help" class="form-help">${escapeHtml(l10n('activationModeHelp'))}</p>
							</div>
						</div>
					</section>
					<section class="admin-tariff-rules__form-section" aria-labelledby="tariff-rules-section-modules">
						<h3 id="tariff-rules-section-modules" class="admin-tariff-rules__section-title admin-tariff-rules__form-section-title">${escapeHtml(l10n('sectionModules'))}</h3>
						<p class="form-help admin-tariff-rules__section-lead">${escapeHtml(l10n('modulesHelp'))}</p>
						<div id="tariff-rules-modules">${moduleRowsHtml}</div>
						${readOnly ? '' : `<button type="button" id="tariff-rules-add-module" class="azc-btn azc-btn--secondary azc-btn--sm admin-tariff-rules__add-module">${escapeHtml(l10n('addModule'))}</button>`}
					</section>
					<div id="tariff-rules-form-error" class="azc-callout azc-callout--danger admin-tariff-rules__form-error" role="alert" tabindex="-1" hidden></div>
				</form>
				<div class="modal-footer">
					<button type="button" class="azc-btn azc-btn--secondary" data-dismiss="modal">${escapeHtml(readOnly ? l10n('close') : l10n('cancel'))}</button>
					${readOnly ? '' : `<button type="submit" class="azc-btn azc-btn--primary" id="tariff-rules-submit">${escapeHtml(l10n('save'))}</button>`}
				</div>
			</div>`;
	}

	function bindModuleHandlers(container, readOnly) {
		if (readOnly) {
			return;
		}
		const modulesHost = container.querySelector('#tariff-rules-modules') || document.getElementById('tariff-rules-modules');

		container.querySelectorAll('.admin-tariff-rules__module-type').forEach((select) => {
			select.addEventListener('change', (e) => {
				const newType = e.target.value;
				const fieldset = e.target.closest('.admin-tariff-rules__module');
				const idx = fieldset.getAttribute('data-module-index');
				const def = findModuleDefinition(newType);
				if (!def) {
					return;
				}
				const fieldsHost = fieldset.querySelector('.admin-tariff-rules__module-fields');
				if (fieldsHost) {
					fieldsHost.innerHTML = moduleFieldsHtml(idx, def, {});
				}
				const legend = fieldset.querySelector('legend');
				if (legend) {
					legend.textContent = moduleLegendLabel(parseInt(idx, 10), newType);
				}
			});
		});

		container.querySelectorAll('.admin-tariff-rules__remove-module').forEach((btn) => {
			btn.addEventListener('click', (e) => {
				const fieldset = e.target.closest('.admin-tariff-rules__module');
				const type = fieldset.querySelector('.admin-tariff-rules__module-type')?.value;
				if (type === 'base_formula') {
					const baseCount = modulesHost.querySelectorAll('.admin-tariff-rules__module-type').length
						? [...modulesHost.querySelectorAll('.admin-tariff-rules__module-type')].filter((s) => s.value === 'base_formula').length
						: 0;
					if (baseCount <= 1) {
						showFormError(container.closest('#tariff-rules-modal') || container, l10n('cannotRemoveBaseModule'));
						return;
					}
				}
				fieldset.remove();
				reindexModules(modulesHost);
				const modalRoot = container.closest('#tariff-rules-modal');
				if (modalRoot) {
					showFormError(modalRoot, null);
				}
			});
		});
	}

	function readModuleValues(modulesHost) {
		const result = [];
		modulesHost.querySelectorAll('.admin-tariff-rules__module').forEach((fs) => {
			const typeSelect = fs.querySelector('.admin-tariff-rules__module-type');
			if (!typeSelect) {
				return;
			}
			const moduleType = typeSelect.value;
			const def = findModuleDefinition(moduleType);
			if (!def) {
				return;
			}
			const config = {};
			def.fields.forEach((field) => {
				const el = fs.querySelector(`[data-field-name="${field.name}"]`);
				if (!el) {
					return;
				}
				if (field.type === 'number') {
					const v = parseFloat(el.value);
					config[field.name] = Number.isFinite(v) ? v : field.defaultValue;
				} else {
					let value = String(el.value || field.defaultValue);
					if (moduleType === 'pro_rata_rule' && field.name === 'mode') {
						value = normalizeProRataMode(value);
					}
					config[field.name] = value;
				}
			});
			result.push({ moduleType, config });
		});
		return result;
	}

	function clearFormErrors(modal) {
		const errBox = modal.querySelector('#tariff-rules-form-error');
		if (errBox) {
			errBox.replaceChildren();
			errBox.hidden = true;
		}
		modal.querySelectorAll('.admin-tariff-rules__field-error').forEach((el) => {
			el.textContent = '';
			el.hidden = true;
		});
		modal.querySelectorAll('#tariff-rules-code, #tariff-rules-version').forEach((input) => {
			input.removeAttribute('aria-invalid');
		});
	}

	function setIdentityFieldErrors(modal, message) {
		const hint = message || l10n('duplicateFieldHint');
		['tariff-rules-code', 'tariff-rules-version'].forEach((inputId) => {
			const input = modal.querySelector(`#${inputId}`);
			const errEl = modal.querySelector(`#${inputId}-error`);
			if (input) {
				input.setAttribute('aria-invalid', 'true');
			}
			if (errEl) {
				errEl.textContent = hint;
				errEl.hidden = false;
			}
		});
		const first = modal.querySelector('#tariff-rules-code');
		if (first && !first.disabled) {
			first.focus();
		}
	}

	function duplicateConflictMessage(existing) {
		if (!existing) {
			return l10n('createError');
		}
		const code = existing.tariffCode || '';
		const version = existing.version || '';
		if (existing.status === 'draft') {
			return l10nParams('duplicateConflictDraft', code, version);
		}
		return l10nParams(
			'duplicateConflictLocked',
			code,
			version,
			existing.statusLabel || statusLabel(existing.status),
		);
	}

	function showFormError(modal, message, options) {
		const errBox = modal.querySelector('#tariff-rules-form-error');
		if (!errBox) {
			return;
		}
		const opts = options || {};
		clearFormErrors(modal);
		if (!message) {
			return;
		}
		if (opts.fieldErrors) {
			setIdentityFieldErrors(modal, opts.fieldErrors);
		}
		const text = document.createElement('p');
		text.className = 'azc-callout__text';
		text.textContent = message;
		errBox.appendChild(text);
		if (opts.versionSuggestion && typeof opts.onApplyVersionSuggestion === 'function') {
			const hint = document.createElement('p');
			hint.className = 'azc-callout__hint admin-tariff-rules__version-suggestion';
			hint.textContent = l10nParams('versionSuggestion', opts.versionSuggestion);
			errBox.appendChild(hint);
			const suggestBtn = document.createElement('button');
			suggestBtn.type = 'button';
			suggestBtn.className = 'azc-btn azc-btn--secondary azc-btn--sm';
			suggestBtn.textContent = l10n('useSuggestedVersion');
			suggestBtn.addEventListener('click', () => opts.onApplyVersionSuggestion());
			const suggestWrap = document.createElement('div');
			suggestWrap.className = 'admin-tariff-rules__conflict-actions';
			suggestWrap.appendChild(suggestBtn);
			errBox.appendChild(suggestWrap);
		}
		if (opts.existing && opts.existing.id) {
			const actions = document.createElement('div');
			actions.className = 'admin-tariff-rules__conflict-actions';
			const existingId = parseInt(String(opts.existing.id), 10);
			if (opts.existing.status === 'draft' && typeof opts.onOpenExisting === 'function') {
				const openBtn = document.createElement('button');
				openBtn.type = 'button';
				openBtn.className = 'azc-btn azc-btn--primary azc-btn--sm';
				openBtn.textContent = l10n('openExistingDraft');
				openBtn.addEventListener('click', () => opts.onOpenExisting(existingId));
				actions.appendChild(openBtn);
			} else if (typeof opts.onViewExisting === 'function') {
				const viewBtn = document.createElement('button');
				viewBtn.type = 'button';
				viewBtn.className = 'azc-btn azc-btn--secondary azc-btn--sm';
				viewBtn.textContent = l10n('viewExistingRuleSet');
				viewBtn.addEventListener('click', () => opts.onViewExisting(existingId));
				actions.appendChild(viewBtn);
			}
			const listBtn = document.createElement('button');
			listBtn.type = 'button';
			listBtn.className = 'azc-btn azc-btn--ghost azc-btn--sm';
			listBtn.textContent = l10n('showInList');
			listBtn.addEventListener('click', () => {
				highlightRowInTable(existingId);
			});
			actions.appendChild(listBtn);
			errBox.appendChild(actions);
		}
		errBox.hidden = false;
		errBox.focus();
		errBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
	}

	function showDuplicatePreview(modal, existing) {
		if (!existing) {
			clearFormErrors(modal);
			updateSubmitDisabled(modal, false);
			return;
		}
		setIdentityFieldErrors(modal, l10n('duplicateFieldHint'));
		updateSubmitDisabled(modal, true);
	}

	function handleDuplicateConflict(modal, existing, backdrop, closeModal) {
		const message = duplicateConflictMessage(existing);
		const suggestion = existing ? suggestNextVersion(existing.version) : '';
		showFormError(modal, message, {
			fieldErrors: l10n('duplicateFieldHint'),
			existing,
			versionSuggestion: suggestion,
			onApplyVersionSuggestion: suggestion
				? () => {
					const versionInput = modal.querySelector('#tariff-rules-version');
					if (versionInput && !versionInput.disabled) {
						versionInput.value = suggestion;
						versionInput.dispatchEvent(new Event('input', { bubbles: true }));
					}
				}
				: null,
			onOpenExisting: async (existingId) => {
				closeModal();
				await openEditModal(existingId);
				highlightRowInTable(existingId);
			},
			onViewExisting: async (existingId) => {
				closeModal();
				await openViewModal(existingId);
				highlightRowInTable(existingId);
			},
		});
		updateSubmitDisabled(modal, true);
	}

	function validateForm(values) {
		if (!values.tariffCode) {
			return l10n('tariffCodeRequired');
		}
		if (!values.version) {
			return l10n('versionRequired');
		}
		if (!values.validFrom) {
			return l10n('validFromRequired');
		}
		if (values.validTo && values.validTo < values.validFrom) {
			return l10n('validityInvalid');
		}
		const modules = values.modules || [];
		if (!modules.length) {
			return l10n('modulesRequired');
		}
		const base = modules.find((m) => m.moduleType === 'base_formula');
		if (!base) {
			return l10n('baseFormulaRequired');
		}
		const c = base.config || {};
		if (!Number.isFinite(c.reference_days) || c.reference_days < 0 || c.reference_days > 366) {
			return l10n('baseFormulaRequired');
		}
		if (!Number.isFinite(c.reference_week_days) || c.reference_week_days < 1 || c.reference_week_days > 7) {
			return l10n('baseFormulaRequired');
		}
		if (Number.isFinite(c.work_days_per_week)
			&& (c.work_days_per_week < 1 || c.work_days_per_week > 7)) {
			return l10n('baseFormulaRequired');
		}
		return null;
	}

	function collectFormValues(modal, isEdit, existing) {
		const tariffCode = isEdit
			? normalizeIdentity(existing.tariffCode)
			: normalizeIdentity(modal.querySelector('#tariff-rules-code').value);
		const version = isEdit
			? normalizeIdentity(existing.version)
			: normalizeIdentity(modal.querySelector('#tariff-rules-version').value);
		const jurisdiction = modal.querySelector('#tariff-rules-jurisdiction').value.trim();
		const validFrom = modal.querySelector('#tariff-rules-valid-from').value;
		const validTo = modal.querySelector('#tariff-rules-valid-to').value;
		const activationMode = modal.querySelector('#tariff-rules-activation').value;
		const modules = readModuleValues(modal.querySelector('#tariff-rules-modules'));
		return { tariffCode, version, jurisdiction, validFrom, validTo, activationMode, modules };
	}

	function openModal(ruleSet, isEdit, readOnly) {
		const backdrop = document.createElement('div');
		backdrop.className = 'modal-backdrop azc-modal-backdrop';
		backdrop.setAttribute('role', 'presentation');
		backdrop.innerHTML = buildModalHtml(ruleSet, isEdit, readOnly);
		document.body.appendChild(backdrop);

		const modal = backdrop.querySelector('#tariff-rules-modal');
		if (Components.openModal) {
			Components.openModal(modal);
		} else {
			backdrop.style.display = 'flex';
			modal.style.display = 'flex';
			document.body.style.overflow = 'hidden';
		}

		const closeModal = () => {
			if (Components.closeModal) {
				Components.closeModal(modal);
			} else {
				backdrop.remove();
				document.body.style.overflow = '';
			}
		};

		modal.querySelectorAll('[data-dismiss="modal"]').forEach((btn) => {
			btn.addEventListener('click', closeModal);
		});
		backdrop.addEventListener('click', (e) => {
			if (e.target === backdrop) {
				closeModal();
			}
		});
		const escListener = (e) => {
			if (e.key === 'Escape') {
				document.removeEventListener('keydown', escListener);
				closeModal();
			}
		};
		document.addEventListener('keydown', escListener);

		const modulesHost = modal.querySelector('#tariff-rules-modules');
		bindModuleHandlers(modal, readOnly);

		if (!readOnly) {
			const addBtn = modal.querySelector('#tariff-rules-add-module');
			if (addBtn) {
				addBtn.addEventListener('click', () => {
					const nextIndex = modulesHost.children.length;
					const wrapper = document.createElement('div');
					wrapper.innerHTML = moduleRowHtml(nextIndex, 'additional_entitlements', {}, false);
					const fieldset = wrapper.firstElementChild;
					modulesHost.appendChild(fieldset);
					bindModuleHandlers(fieldset, false);
				});
			}

			const submitBtn = modal.querySelector('#tariff-rules-submit');
			const form = modal.querySelector('#tariff-rules-form');
			const runSubmit = async () => {
				if (!submitBtn || submitBtn.disabled) {
					return;
				}
				showFormError(modal, null);
				const values = collectFormValues(modal, isEdit, ruleSet || {});
				const err = validateForm(values);
				if (err) {
					showFormError(modal, err);
					return;
				}
				if (!isEdit) {
					const localDup = findDuplicateRuleSet(values.tariffCode, values.version);
					if (localDup) {
						handleDuplicateConflict(modal, localDup, backdrop, closeModal);
						return;
					}
				}
				submitBtn.setAttribute('aria-busy', 'true');
				submitBtn.disabled = true;
				let keepSubmitDisabled = false;
				try {
					let r;
					if (isEdit) {
						r = await apiFetch(`${API_LIST}/${encodeURIComponent(ruleSet.id)}`, { method: 'PUT', json: values });
					} else {
						r = await apiFetch(API_LIST, { method: 'POST', json: values });
					}
					if (r.ok && r.payload && r.payload.success) {
						const msg = isEdit ? l10n('updated') : l10n('created');
						Messaging.showSuccess && Messaging.showSuccess(msg);
						announce(msg);
						closeModal();
						loadRuleSets();
					} else if (!isEdit && isDuplicateConflictResponse(r)) {
						const existing = (r.payload && r.payload.existing)
							|| findDuplicateRuleSet(values.tariffCode, values.version);
						if (existing && !existing.statusLabel && existing.status) {
							existing.statusLabel = statusLabel(existing.status);
						}
						handleDuplicateConflict(modal, existing, backdrop, closeModal);
						keepSubmitDisabled = true;
						await loadRuleSets();
					} else {
						let errMsg = (r.payload && r.payload.error) || (isEdit ? l10n('updateError') : l10n('createError'));
						const fieldErr = r.payload && r.payload.errors && r.payload.errors.tariffCode;
						if (r.payload && r.payload.errors && typeof r.payload.errors === 'object') {
							if (r.payload.errors.tariffCode || r.payload.errors.version) {
								setIdentityFieldErrors(modal, fieldErr || l10n('duplicateFieldHint'));
								keepSubmitDisabled = !!(r.payload.errors.tariffCode || r.payload.errors.version);
							}
							const parts = Object.values(r.payload.errors).filter(Boolean);
							if (parts.length && !r.payload.error) {
								errMsg = parts.join(' ');
							}
						}
						showFormError(modal, errMsg);
					}
				} catch (_) {
					showFormError(modal, isEdit ? l10n('updateError') : l10n('createError'));
				} finally {
					submitBtn.removeAttribute('aria-busy');
					if (!keepSubmitDisabled) {
						submitBtn.disabled = false;
						submitBtn.setAttribute('aria-disabled', 'false');
					}
				}
			};
			if (submitBtn) {
				submitBtn.addEventListener('click', (e) => {
					e.preventDefault();
					runSubmit();
				});
			}
			if (form) {
				form.addEventListener('submit', (e) => {
					e.preventDefault();
					runSubmit();
				});
			}

			if (!isEdit && !readOnly) {
				['tariff-rules-code', 'tariff-rules-version'].forEach((inputId) => {
					const input = modal.querySelector(`#${inputId}`);
					if (!input) {
						return;
					}
					input.addEventListener('input', () => {
						const values = collectFormValues(modal, false, {});
						const dup = findDuplicateRuleSet(values.tariffCode, values.version);
						if (dup) {
							showDuplicatePreview(modal, dup);
						} else {
							clearFormErrors(modal);
							updateSubmitDisabled(modal, false);
						}
					});
				});
			}
		}

		setTimeout(() => {
			const first = modal.querySelector('input:not([disabled]), select:not([disabled]), button[data-dismiss="modal"]');
			if (first) {
				first.focus();
			}
		}, 50);
	}

	async function openEditModal(id) {
		const { ok, payload } = await apiFetch(`${API_LIST}/${encodeURIComponent(id)}`, { method: 'GET' });
		if (!ok || !payload || !payload.success) {
			Messaging.showError && Messaging.showError((payload && payload.error) || l10n('loadingError'));
			return;
		}
		if (payload.ruleSet && payload.ruleSet.status !== 'draft') {
			openModal(payload.ruleSet, false, true);
			return;
		}
		openModal(payload.ruleSet, true, false);
	}

	async function openViewModal(id) {
		const { ok, payload } = await apiFetch(`${API_LIST}/${encodeURIComponent(id)}`, { method: 'GET' });
		if (!ok || !payload || !payload.success) {
			Messaging.showError && Messaging.showError((payload && payload.error) || l10n('loadingError'));
			return;
		}
		openModal(payload.ruleSet, false, true);
	}

	function init() {
		const createBtn = document.getElementById('tariff-rules-create');
		if (createBtn) {
			createBtn.addEventListener('click', async () => {
				createBtn.setAttribute('aria-busy', 'true');
				createBtn.disabled = true;
				try {
					await loadRuleSets();
					openModal(null, false, false);
				} finally {
					createBtn.removeAttribute('aria-busy');
					createBtn.disabled = false;
				}
			});
		}
		const refreshBtn = document.getElementById('tariff-rules-refresh');
		if (refreshBtn) {
			refreshBtn.addEventListener('click', () => loadRuleSets());
		}
		if (Components.relocatePageActions) {
			Components.relocatePageActions();
		}
		loadRuleSets();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	if (typeof window !== 'undefined') {
		window.__ArbeitszeitCheckTariffRulesTestables = {
			confirmAndCall: confirmAndCall,
		};
	}
})();
