(function () {
	'use strict';

	const root = document.querySelector('.azc-work-location-admin');
	if (!root) return;

	const list = document.getElementById('work-location-list');
	const listStatus = document.getElementById('work-location-list-status');
	const rolloutStatus = document.getElementById('work-location-rollout-status');
	const form = document.getElementById('work-location-create-form');
	const policyForm = document.getElementById('work-location-policy-form');
	const policyList = document.getElementById('work-location-policy-list');
	const policyStatus = document.getElementById('work-location-policy-status');
	const scopeType = document.getElementById('work-location-policy-scope');
	const targetGroup = document.getElementById('work-location-policy-target-group');
	const targetSelect = document.getElementById('work-location-policy-target');
	const userWrap = document.getElementById('work-location-policy-user-wrap');
	const userHidden = document.getElementById('work-location-policy-user-id');
	const locationSelect = document.getElementById('work-location-policy-location');
	const token = document.querySelector('head')?.dataset.requesttoken || window.OC?.requestToken || '';
	const t = (text) => typeof window.t === 'function' ? window.t('arbeitszeitcheck', text) : text;

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
			throw error;
		}
		return body;
	}

	function setBusy(element, busy) {
		element.disabled = busy;
		if (busy) element.setAttribute('aria-busy', 'true');
		else element.removeAttribute('aria-busy');
	}

	// The admin-settings shell wraps every section partial in the outer
	// #admin-settings-form. Nested <form> elements are dropped by the HTML
	// parser (the first inner form never reaches the DOM and its stray
	// </form> closes the outer form early), so the create/policy groups are
	// plain role="group" containers and submission is wired explicitly:
	// data-field drives the payload, Enter/click drive submit, per-control
	// checkValidity drives validation.
	function fieldValues(group) {
		const data = {};
		group.querySelectorAll('[data-field]').forEach((el) => { data[el.dataset.field] = el.value; });
		return data;
	}

	function validateGroup(group) {
		const bad = [...group.querySelectorAll('input, select, textarea')].find((el) => !el.checkValidity());
		if (bad) bad.reportValidity();
		return !bad;
	}

	function resetFields(group) {
		group.querySelectorAll('[data-field]').forEach((el) => {
			if (el instanceof HTMLSelectElement) {
				el.selectedIndex = Math.max(0, [...el.options].findIndex((o) => o.defaultSelected));
			} else if (el.type === 'checkbox' || el.type === 'radio') {
				el.checked = el.defaultChecked;
			} else {
				el.value = el.defaultValue;
			}
		});
	}

	function bindSubmits(group, handler) {
		let inFlight = false;
		const run = async () => {
			if (inFlight) return;
			inFlight = true;
			try { await handler(); } finally { inFlight = false; }
		};
		group.querySelector('[data-submit]').addEventListener('click', run);
		group.addEventListener('keydown', (event) => {
			if (event.key !== 'Enter' || event.defaultPrevented || event.isComposing
				|| !(event.target instanceof HTMLInputElement)
				|| event.target.closest('.user-picker')) {
				return;
			}
			event.preventDefault();
			run();
		});
	}

	function clearErrors(target = form) {
		target.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));
		target.closest('section').querySelectorAll('.azc-field-error').forEach((error) => { error.hidden = true; error.textContent = ''; });
	}

	function showFieldError(fieldName, message, target = form) {
		const field = [...target.querySelectorAll('[data-field]')].find((el) => el.dataset.field === fieldName);
		const error = document.getElementById(`work-location-${fieldName}-error`)
			|| document.getElementById(`work-location-policy-${fieldName}-error`);
		if (!field || !error) return false;
		field.setAttribute('aria-invalid', 'true');
		error.textContent = message;
		error.hidden = false;
		return true;
	}

	function render(items) {
		list.replaceChildren();
		locationSelect.replaceChildren();
		items.forEach((item) => {
			const row = document.createElement('li');
			row.className = 'azc-list__item';
			const text = document.createElement('div');
			const title = document.createElement('strong');
			title.textContent = item.label;
			const detail = document.createElement('p');
			detail.className = 'form-help';
			detail.textContent = `${item.code} · ${item.category} · ${item.active ? t('Active') : t('Inactive')} · ${item.usage?.entryCount || 0} ${t('entries')}`;
			text.append(title, detail);
			row.append(text);
			list.append(row);
			if (item.active === true) {
				const option = document.createElement('option');
				option.value = item.code;
				option.textContent = item.label;
				locationSelect.append(option);
			}
		});
		listStatus.textContent = items.length === 0 ? t('No work locations found.') : '';
	}

	function selectedScopeId() {
		if (scopeType.value === 'org') return 'org';
		if (scopeType.value === 'user') return userHidden.value;
		return targetSelect.value;
	}

	function renderPolicies(items) {
		policyList.replaceChildren();
		items.forEach((item) => {
			const row = document.createElement('li');
			row.className = 'azc-list__item';
			const text = document.createElement('div');
			const title = document.createElement('strong');
			title.textContent = `${item.scopeType}:${item.scopeId} → ${item.locationLabel}`;
			const detail = document.createElement('p');
			detail.className = 'form-help';
			detail.textContent = `${item.effectiveFrom} – ${item.effectiveTo || t('open')} · ${t('Priority')} ${item.priority} · ${t('Revision')} ${item.revision}`;
			text.append(title, detail);
			const endGroup = document.createElement('span');
			endGroup.className = 'azc-list__actions';
			const endDate = document.createElement('input');
			endDate.type = 'date';
			endDate.value = new Date().toISOString().slice(0, 10);
			endDate.setAttribute('aria-label', t('Last valid date'));
			const end = document.createElement('button');
			end.type = 'button';
			end.className = 'azc-btn azc-btn--secondary';
			end.textContent = t('End policy');
			end.addEventListener('click', async () => {
				if (!endDate.reportValidity() || endDate.value === '') { endDate.focus(); return; }
				setBusy(end, true);
				try {
					await request(root.dataset.policyEndTemplate.replace('__ID__', encodeURIComponent(String(item.id))), {
						method: 'POST', body: JSON.stringify({ effectiveTo: endDate.value }),
					});
					await loadPolicies();
				} catch (error) { policyStatus.textContent = error.message; }
				finally { setBusy(end, false); }
			});
			endGroup.append(endDate, end);
			row.append(text, endGroup);
			policyList.append(row);
		});
		policyStatus.textContent = items.length === 0 ? t('No policies found for this scope.') : '';
	}

	async function loadPolicies() {
		const scopeId = selectedScopeId();
		if (!scopeId) { policyList.replaceChildren(); policyStatus.textContent = ''; return; }
		policyStatus.textContent = t('Loading…');
		try {
			const query = new URLSearchParams({ scopeType: scopeType.value, scopeId });
			const body = await request(`${root.dataset.policiesUrl}?${query}`);
			renderPolicies(body.items || []);
		} catch (error) { policyStatus.textContent = error.message; }
	}

	async function loadScopeTargets() {
		const kind = scopeType.value;
		targetGroup.hidden = kind === 'org';
		targetSelect.hidden = kind !== 'model' && kind !== 'team';
		userWrap.hidden = kind !== 'user';
		targetSelect.replaceChildren();
		if (kind === 'org' || kind === 'user') {
			await loadPolicies();
			return;
		}
		try {
			const url = kind === 'model' ? root.dataset.modelsUrl : root.dataset.teamsUrl;
			const body = await request(url);
			const rows = kind === 'model' ? body.models || [] : body.teams || [];
			const append = (nodes) => nodes.forEach((node) => {
				const option = document.createElement('option');
				option.value = String(node.id);
				option.textContent = node.name || node.label || String(node.id);
				targetSelect.append(option);
				if (Array.isArray(node.children)) append(node.children);
			});
			append(rows);
		} catch (error) {
			policyStatus.textContent = error.message;
		}
		await loadPolicies();
	}

	async function loadCatalog() {
		listStatus.textContent = t('Loading…');
		try {
			const body = await request(root.dataset.catalogUrl);
			render(body.items || []);
		} catch (error) {
			listStatus.textContent = error.status === 404 ? t('Enable the work-location catalog to manage locations.') : error.message;
		}
	}

	async function loadMigration() {
		try {
			const body = await request(root.dataset.migrationUrl);
			const readiness = body.preflight || {};
			rolloutStatus.textContent = readiness.ready
				? t('Migration readiness checks passed.')
				: `${t('Migration is blocked:')} ${readiness.pendingLegacyRequests || 0} ${t('open requests')}, ${readiness.unmigratedUserDefaults || 0} ${t('unmigrated defaults')}.`;
			document.getElementById('work-location-enable').hidden = body.flags?.work_location_catalog_enabled === true;
			document.getElementById('work-location-disable-legacy').disabled = readiness.ready !== true || body.flags?.legacy_home_absence_create_enabled !== true;
		} catch (error) {
			rolloutStatus.textContent = error.message;
		}
	}

	form.addEventListener('input', (event) => {
		if (event.target?.dataset?.field) {
			event.target.removeAttribute('aria-invalid');
			const error = document.getElementById(`work-location-${event.target.dataset.field}-error`);
			if (error) { error.hidden = true; error.textContent = ''; }
		}
	});

	bindSubmits(form, async () => {
		clearErrors();
		if (!validateGroup(form)) return;
		const submit = form.querySelector('[data-submit]');
		setBusy(submit, true);
		try {
			await request(root.dataset.createUrl, { method: 'POST', body: JSON.stringify(fieldValues(form)) });
			resetFields(form);
			listStatus.textContent = t('Work location added.');
			await loadCatalog();
		} catch (error) {
			const entries = Object.entries(error.fields || {});
			const shown = entries.some(([name, message]) => showFieldError(name, String(message)));
			if (!shown) listStatus.textContent = error.message;
		} finally {
			setBusy(submit, false);
		}
	});

	policyForm.addEventListener('input', (event) => {
		if (event.target?.dataset?.field) event.target.removeAttribute('aria-invalid');
	});

	scopeType.addEventListener('change', loadScopeTargets);
	targetSelect.addEventListener('change', loadPolicies);

	const userPicker = window.ArbeitszeitCheck?.initAdminUserPicker?.({
		hiddenSelector: '#work-location-policy-user-id',
		searchSelector: '#work-location-policy-user-search',
		listSelector: '#work-location-policy-user-list',
		wrapSelector: '#work-location-policy-user-wrap',
		statusSelector: '#work-location-policy-user-status',
		searchUrl: root.dataset.usersUrl,
		onChange: loadPolicies,
	});

	bindSubmits(policyForm, async () => {
		clearErrors(policyForm);
		if (!validateGroup(policyForm)) return;
		const submit = policyForm.querySelector('[data-submit]');
		setBusy(submit, true);
		try {
			const data = fieldValues(policyForm);
			data.scopeId = selectedScopeId();
			await request(root.dataset.policyCreateUrl, { method: 'POST', body: JSON.stringify(data) });
			policyStatus.textContent = t('Default policy created.');
			await loadPolicies();
		} catch (error) {
			const entries = Object.entries(error.fields || {});
			const shown = entries.some(([name, message]) => showFieldError(name, String(message), policyForm));
			if (!shown) policyStatus.textContent = error.message;
		} finally {
			setBusy(submit, false);
		}
	});

	document.getElementById('work-location-enable').addEventListener('click', async (event) => {
		const button = event.currentTarget;
		const ack = document.getElementById('work-location-rollback-ack');
		if (!ack.checked) { ack.focus(); rolloutStatus.textContent = t('Confirm the rollback warning first.'); return; }
		setBusy(button, true);
		try {
			await request(root.dataset.enableUrl, { method: 'POST', body: JSON.stringify({ acknowledgeRollbackWarning: true }) });
			await Promise.all([loadMigration(), loadCatalog()]);
		} catch (error) { rolloutStatus.textContent = error.message; }
		finally { setBusy(button, false); }
	});

	document.getElementById('work-location-disable-legacy').addEventListener('click', async (event) => {
		const button = event.currentTarget;
		setBusy(button, true);
		try {
			await request(root.dataset.disableLegacyUrl, { method: 'POST', body: '{}' });
			await loadMigration();
		} catch (error) { rolloutStatus.textContent = error.message; }
		finally { setBusy(button, false); }
	});

	Promise.all([loadMigration(), loadCatalog(), loadScopeTargets()]);
})();
