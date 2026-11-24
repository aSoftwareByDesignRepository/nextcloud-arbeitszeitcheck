/**
 * Year-end reset wizard (SPEC optional-workforce §9.5):
 * preview → per-user review → confirm (single-use handle) → live status.
 */
(function () {
	'use strict';

	const t = (s) => (typeof window.t === 'function' ? window.t('arbeitszeitcheck', s) : s);
	const root = document.getElementById('azc-year-end');
	if (!root) return;

	const apiBase = root.dataset.apiBase;
	let policyRevision = parseInt(root.dataset.policyRevision || '0', 10);
	const live = document.getElementById('azc-ye-live');
	const announce = (msg) => { if (live) live.textContent = msg; };

	const api = (url, json) => window.AzcApi.fetch(url, { method: 'POST', json })
		.then((res) => {
			if (!res.ok || !(res.data && res.data.success !== false)) {
				const e = new Error((res.data && res.data.error) || t('The request could not be completed.'));
				e.code = res.data && res.data.code;
				throw e;
			}
			return res.data;
		});

	const fmtHours = (centi) => {
		if (centi === null || centi === undefined) return '—';
		const h = centi / 100;
		return (h > 0 ? '+' : '') + h.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' h';
	};
	const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

	// ---- user list ----
	const usersData = JSON.parse(document.getElementById('azc-ye-users-data').textContent || '[]');
	const usersSel = document.getElementById('azc-ye-users');
	usersData.forEach((u) => {
		const opt = document.createElement('option');
		opt.value = u.id;
		opt.textContent = u.name && u.name !== u.id ? u.name + ' (' + u.id + ')' : u.id;
		usersSel.appendChild(opt);
	});
	const userName = (uid) => {
		const hit = usersData.find((u) => u.id === uid);
		return hit && hit.name ? hit.name : uid;
	};

	// ---- preview ----
	const form = document.getElementById('azc-ye-preview-form');
	const previewCard = document.getElementById('azc-ye-preview-card');
	const previewBody = document.querySelector('#azc-ye-preview-table tbody');
	let previewId = null;

	const err = (id, show) => { const el = document.getElementById(id); if (el) el.hidden = !show; };

	form.addEventListener('submit', async (ev) => {
		ev.preventDefault();
		const year = parseInt(document.getElementById('azc-ye-year').value, 10);
		const rp = document.getElementById('azc-ye-positive').checked;
		const wn = document.getElementById('azc-ye-negative').checked;
		const reason = document.getElementById('azc-ye-reason').value.trim();
		const userIds = Array.from(usersSel.selectedOptions).map((o) => o.value);
		err('azc-ye-signs-error', !(rp || wn));
		err('azc-ye-users-error', userIds.length === 0);
		err('azc-ye-reason-error', reason.length < 10);
		if (!(rp || wn) || userIds.length === 0 || reason.length < 10) return;

		const btn = document.getElementById('azc-ye-preview-btn');
		btn.disabled = true;
		try {
			const data = await api(apiBase + '/preview', {
				closingYear: year, userIds, resetPositive: rp, waiveNegative: wn,
				reason, expectedPolicyRevision: policyRevision,
			});
			previewId = data.preview_id;
			policyRevision = data.policy_revision;
			renderPreview(data.targets);
			announce(t('Preview ready — review every row before confirming.'));
		} catch (e) {
			announce(e.message);
			if (window.OC && OC.Notification) OC.Notification.showTemporary(e.message);
		} finally {
			btn.disabled = false;
		}
	});

	function renderPreview(targets) {
		previewBody.innerHTML = '';
		targets.forEach((r) => {
			const tr = document.createElement('tr');
			const status = r.blocked
				? '<span class="azc-status-chip" data-status="blocked">' + esc(r.blocked) + '</span>'
				: (r.delta_centi_hours === 0
					? '<span class="azc-status-chip" data-status="no_op">' + esc(t('No change')) + '</span>'
					: '<span class="azc-status-chip" data-status="pending">' + esc(t('Will reset')) + '</span>');
			tr.innerHTML =
				'<td data-cell="' + esc(t('Employee')) + '">' + esc(userName(r.user_id)) + '</td>' +
				'<td data-cell="' + esc(t('Balance now')) + '">' + esc(fmtHours(r.before_centi_hours)) + '</td>' +
				'<td data-cell="' + esc(t('Change')) + '">' + esc(fmtHours(r.delta_centi_hours)) + '</td>' +
				'<td data-cell="' + esc(t('Result')) + '">' + status + '</td>';
			previewBody.appendChild(tr);
		});
		previewCard.hidden = false;
		previewCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	document.getElementById('azc-ye-discard-btn').addEventListener('click', () => {
		previewCard.hidden = true;
		previewId = null;
	});

	// ---- execute + poll ----
	const runCard = document.getElementById('azc-ye-run-card');
	const runBody = document.querySelector('#azc-ye-run-table tbody');
	const runSummary = document.getElementById('azc-ye-run-summary');
	let pollTimer = null;

	document.getElementById('azc-ye-confirm-btn').addEventListener('click', async () => {
		if (!previewId) return;
		const btn = document.getElementById('azc-ye-confirm-btn');
		btn.disabled = true;
		try {
			const data = await api(apiBase, {
				previewId,
				operationId: crypto.randomUUID(),
				expectedPolicyRevision: policyRevision,
			});
			previewCard.hidden = true;
			previewId = null;
			renderRun(data.run);
			startPoll(data.run.id);
			announce(t('Reset run started.'));
		} catch (e) {
			announce(e.message);
			if (window.OC && OC.Notification) OC.Notification.showTemporary(e.message);
			btn.disabled = false;
		}
	});

	function renderRun(run) {
		runCard.hidden = false;
		const c = run.counts || {};
		runSummary.textContent = t('Run') + ' #' + run.id + ' — ' + run.state +
			' (' + (c.done || 0) + ' ' + t('done') + ', ' + (c.failed || 0) + ' ' + t('failed') +
			', ' + (c.skipped || 0) + ' ' + t('skipped') + ')';
		runBody.innerHTML = '';
		(run.items || []).forEach((r) => {
			const tr = document.createElement('tr');
			const review = r.review_state === 'needs_review' ? ' · ' + esc(t('needs review')) : '';
			tr.innerHTML =
				'<td data-cell="' + esc(t('Employee')) + '">' + esc(userName(r.user_id)) + '</td>' +
				'<td data-cell="' + esc(t('State')) + '"><span class="azc-status-chip" data-status="' + esc(r.state) + '">' + esc(r.state) + review + '</span></td>' +
				'<td data-cell="' + esc(t('Detail')) + '">' + esc(r.error_code || fmtHours(r.delta_centi_hours)) + '</td>';
			runBody.appendChild(tr);
		});
		runCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	function startPoll(runId) {
		if (pollTimer) clearInterval(pollTimer);
		pollTimer = setInterval(async () => {
			try {
				const data = await window.AzcApi.fetch(apiBase + '/' + runId, { method: 'GET' })
					.then((res) => res.data);
				if (data && data.run) {
					renderRun(data.run);
					if (data.run.state !== 'executing') {
						clearInterval(pollTimer);
						pollTimer = null;
						loadHistory();
					}
				}
			} catch (e) { /* transient poll failures are ignored — next tick retries */ }
		}, 3000);
	}

	// ---- history ----
	const histBody = document.querySelector('#azc-ye-history-table tbody');
	async function loadHistory() {
		try {
			const data = await window.AzcApi.fetch(apiBase + '?limit=25', { method: 'GET' }).then((res) => res.data);
			histBody.innerHTML = '';
			(data && data.items ? data.items : []).forEach((r) => {
				const tr = document.createElement('tr');
				tr.innerHTML =
					'<td data-cell="' + esc(t('Run')) + '">#' + esc(String(r.id)) + '</td>' +
					'<td data-cell="' + esc(t('Closing year')) + '">' + esc(String(r.closing_year)) + '</td>' +
					'<td data-cell="' + esc(t('Origin')) + '">' + esc(r.origin) + '</td>' +
					'<td data-cell="' + esc(t('State')) + '"><span class="azc-status-chip" data-status="' + esc(r.state) + '">' + esc(r.state) + '</span></td>' +
					'<td data-cell="' + esc(t('Started')) + '">' + esc(r.created_at || '—') + '</td>';
				histBody.appendChild(tr);
			});
		} catch (e) { /* history is best-effort */ }
	}
	loadHistory();
})();
