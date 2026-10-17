/**
 * Employee special-entitlement accounts on the absences page.
 * Renders public labels + balances only; confidential data never reaches
 * this endpoint (server-side serialization boundary).
 */
(function () {
	'use strict';

	const root = document.getElementById('se-accounts');
	if (!root) return;
	const t = (text) => (typeof window.t === 'function' ? window.t('arbeitszeitcheck', text) : text);
	const token = document.querySelector('head')?.dataset.requesttoken || window.OC?.requestToken || '';
	const list = document.getElementById('se-accounts-list');
	const status = document.getElementById('se-accounts-status');
	const history = document.getElementById('se-account-history');

	async function request(url) {
		const response = await fetch(url, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json', requesttoken: token },
		});
		let body = null;
		try { body = await response.json(); } catch (_) { body = null; }
		if (!response.ok || !body || body.success !== true) {
			const error = new Error(body?.error || t('The request could not be completed.'));
			error.status = response.status;
			throw error;
		}
		return body;
	}

	function fmt(v, unit) {
		const n = Number(v);
		if (!Number.isFinite(n)) return '—';
		return `${n % 1 === 0 ? String(Math.round(n)) : n.toFixed(2)} ${unit === 'hours' ? t('hours') : t('days')}`;
	}

	async function loadHistory(typeCode, titleEl, listEl) {
		listEl.replaceChildren();
		try {
			const url = root.dataset.historyTemplate.replace('__ID__', encodeURIComponent(typeCode));
			const body = await request(url);
			const periods = body.periods || [];
			periods.forEach((p) => {
				(p.entries || []).forEach((e) => {
					const li = document.createElement('li');
					li.className = 'azc-list__item';
					const text = document.createElement('span');
					const kind = { debit: t('Debited'), credit: t('Credited'), correction: t('Correction') }[e.kind] || e.kind;
					text.textContent = `${kind} ${fmt(e.amount, body.unit)} · ${p.periodStart} – ${p.periodEnd}`;
					li.append(text);
					listEl.append(li);
				});
			});
			if (listEl.childElementCount === 0) {
				const li = document.createElement('li');
				li.className = 'azc-list__item';
				li.textContent = t('No ledger entries.');
				listEl.append(li);
			}
		} catch (e) {
			const li = document.createElement('li');
			li.className = 'azc-list__item';
			li.textContent = e.message;
			listEl.append(li);
		}
	}

	async function load() {
		try {
			const body = await request(root.dataset.url);
			const items = (body.items || []);
			// Only surface accounts the employee actually has (enabled or used).
			const visible = items.filter((i) => i.enabled || Number(i.total) > 0 || Number(i.used) > 0 || Number(i.reserved) > 0);
			if (visible.length === 0) {
				return; // keep the whole card hidden — spec: no misleading empty state for employees
			}
			root.hidden = false;
			list.replaceChildren();
			visible.forEach((item) => {
				const li = document.createElement('li');
				li.className = 'azc-list__item se-account-card';
				const main = document.createElement('div');
				const title = document.createElement('strong');
				title.textContent = item.label;
				const meta = document.createElement('p');
				meta.className = 'form-help';
				meta.textContent = [
					`${t('Available')}: ${fmt(item.available, item.unit)}`,
					`${t('Total')}: ${fmt(item.total, item.unit)}`,
					`${t('Reserved')}: ${fmt(item.reserved, item.unit)}`,
					`${t('Used')}: ${fmt(item.used, item.unit)}`,
					item.periodEnd ? `${t('until')} ${item.periodEnd}` : '',
				].filter(Boolean).join(' · ');
				main.append(title, meta);
				li.append(main);
				const actions = document.createElement('span');
				actions.className = 'azc-list__actions';
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'azc-btn azc-btn--secondary';
				btn.textContent = t('History');
				btn.setAttribute('aria-label', `${t('History for')} ${item.label}`);
				btn.addEventListener('click', async () => {
					history.hidden = false;
					document.getElementById('se-account-history-title').textContent = `${item.label}`;
					await loadHistory(item.typeCode, null, document.getElementById('se-account-history-list'));
					history.scrollIntoView({ block: 'nearest' });
				});
				actions.append(btn);
				li.append(actions);
				list.append(li);
			});
			status.textContent = '';
		} catch (e) {
			if (e.status === 404) return; // feature disabled or unavailable — stay hidden
			status.textContent = e.message;
			root.hidden = false;
		}
	}

	document.getElementById('se-account-history-close').addEventListener('click', () => {
		history.hidden = true;
	});

	load();
})();
