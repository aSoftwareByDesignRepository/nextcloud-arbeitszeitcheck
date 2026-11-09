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
	const grid = document.getElementById('se-accounts-grid');
	const status = document.getElementById('se-accounts-status');

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
		return `${fmtNum(v)} ${unit === 'hours' ? t('hours') : t('days')}`;
	}

	function fmtNum(v) {
		const n = Number(v);
		if (!Number.isFinite(n)) return '—';
		return n % 1 === 0 ? String(Math.round(n)) : n.toFixed(2);
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
			grid.replaceChildren();
			visible.forEach((item, index) => {
				const card = document.createElement('div');
				card.className = 'stat-card stat-card--se-account';
				const labelId = `se-stat-${index}-label`;
				const title = document.createElement('span');
				title.className = 'stat-label';
				title.id = labelId;
				title.textContent = item.label;
				const value = document.createElement('span');
				value.className = 'stat-value stat-value--secondary';
				value.setAttribute('aria-labelledby', labelId);
				value.textContent = fmtNum(item.available);
				const sublabel = document.createElement('span');
				sublabel.className = 'stat-sublabel';
				sublabel.textContent = item.unit === 'hours' ? t('hours') : t('days');
				const facts = document.createElement('ul');
				facts.className = 'stat-card__facts';
				[
					`${t('Total')}: ${fmt(item.total, item.unit)}`,
					`${t('Reserved')}: ${fmt(item.reserved, item.unit)}`,
					item.periodEnd ? `${t('until')} ${item.periodEnd}` : '',
				].filter(Boolean).forEach((text) => {
					const li = document.createElement('li');
					li.textContent = text;
					facts.append(li);
				});
				card.append(title, value, sublabel, facts);
				grid.append(card);
			});
			status.textContent = '';
		} catch (e) {
			if (e.status === 404) return; // feature disabled or unavailable — stay hidden
			status.textContent = e.message;
			root.hidden = false;
		}
	}

	load();
})();
