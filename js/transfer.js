/**
 * Transfer settings page — export download + import dry-run/report/apply.
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */
(function () {
	'use strict';
	const API = window.ArbeitszeitCheckApi;
	const M = window.ArbeitszeitCheckMessaging;
	// window.t expands {name} tokens from a vars object only — positional
	// vsprintf placeholders (%d, %s, %1$s) come back literally. Catalog
	// strings keep their vsprintf form for the PHP side, so positional
	// params arrays are interpolated here after translation. Non-array
	// params keep the named-vars path untouched.
	const t = (key, params) => {
		const s = window.t ? window.t('arbeitszeitcheck', key, (params && !Array.isArray(params)) ? params : {}) : key;
		if (!Array.isArray(params) || !params.length) return s;
		let i = 0;
		return s.replace(/%%|%(?:\d+\$)?[sd]/g, (m) => {
			if (m === '%%') return '%';
			const idx = /^%(\d+)\$/.exec(m);
			const v = idx ? params[+idx[1] - 1] : params[i++];
			return (v === undefined || v === null) ? '' : String(v);
		});
	};
	const P = 'azc';
	const $ = (id) => document.getElementById(P + '-' + id);

	let pendingId = null;
	let pendingMode = 'merge';
	let pollTimer = null;
	let previewTimer = null;
	let previewSeq = 0;

	function live() { return $('transfer-result-live'); }
	function msg(text) {
		if (M && M.alert) { M.alert(text); return; }
		const el = live();
		if (el) el.textContent = text;
	}
	function polite(text) {
		if (M && M.polite) { M.polite(text); return; }
		msg(text);
	}
	function errText(e) {
		const code = (e && e.code) || 'SERVER_ERROR';
		const fallback = t('Something went wrong. Please try again.');
		const map = {
			TRANSFER_CANNOT_READ_FILE: t('Cannot read this file — wrong passphrase, wrong app, or a damaged file.'),
			TRANSFER_FILE_INVALID: t('Cannot read this file — wrong passphrase, wrong app, or a damaged file.'),
			TRANSFER_NEWER_VERSION: t('This file was exported from a newer app version. Update this app first.'),
			TRANSFER_IN_PROGRESS: t('A transfer started by %s is already running.', [(e.context && e.context.actor) || '?']),
			TRANSFER_MAINTENANCE_REQUIRED: t('Replace needs maintenance mode: occ maintenance:mode --on'),
			TRANSFER_CONFIRM_REQUIRED: t('Type REPLACE to confirm.'),
			TRANSFER_PENDING_EXPIRED: t('The preview expired — pick the file and enter the passphrase again.'),
			TRANSFER_PENDING_NOT_FOUND: t('The preview expired — pick the file and enter the passphrase again.'),
			TRANSFER_PREFLIGHT_FAILED: t('A pre-flight check failed — see the report for details.'),
			TRANSFER_ADMIN_REQUIRED: t('Replace is available to Nextcloud system administrators only.'),
			INSUFFICIENT_ROLE: t('You do not have the rights for this action.'),
			RATE_LIMITED: t('Too many attempts — wait a minute and try again.'),
			TRANSFER_THROTTLED: t('Too many attempts — wait a minute and try again.'),
			NOT_FOUND: t('You do not have the rights for this action.'),
			NETWORK_ERROR: t('Network problem — check your connection and try again.'),
			SERVER_ERROR: fallback,
		};
		// Unmapped codes still get the translated fallback — never render a
		// raw UPPER_SNAKE code to the user.
		return map[code] || fallback;
	}

	// ── export ──────────────────────────────────────────────────────────
	function strength(pass) {
		if (!pass) return '';
		if (pass.length < 12) return t('Too short — at least 12 characters.');
		if (pass.length < 16) return t('OK — a longer passphrase is safer.');
		return t('Strong passphrase.');
	}
	function genPass() {
		const buf = new Uint8Array(18);
		crypto.getRandomValues(buf);
		return btoa(String.fromCharCode.apply(null, buf)).replace(/[+/=]/g, '').slice(0, 24);
	}
	function initExport() {
		const pass = $('transfer-passphrase');
		const strEl = $('transfer-passphrase-strength');
		if (pass) {
			pass.addEventListener('input', () => { strEl.textContent = strength(pass.value); });
		}
		['transfer-passphrase-toggle', 'transfer-import-passphrase-toggle'].forEach((id) => {
			const btn = $(id);
			const input = id.indexOf('import') >= 0 ? $('transfer-import-passphrase') : pass;
			if (btn && input) btn.addEventListener('click', () => {
				const show = input.type === 'password';
				input.type = show ? 'text' : 'password';
				btn.textContent = show ? t('Hide') : t('Show');
				btn.setAttribute('aria-pressed', String(show));
			});
		});
		const gen = $('transfer-passphrase-generate');
		if (gen) gen.addEventListener('click', () => {
			pass.value = genPass();
			pass.dispatchEvent(new Event('input'));
			if (navigator.clipboard) {
				navigator.clipboard.writeText(pass.value).then(
					() => polite(t('Passphrase generated and copied.')),
					() => polite(t('Passphrase generated — copy it now.')),
				);
			} else {
				polite(t('Passphrase generated — copy it now.'));
			}
		});
		const form = $('transfer-export-form');
		if (form) form.addEventListener('submit', async (ev) => {
			ev.preventDefault();
			const btn = $('transfer-export-btn');
			if (!pass.value || pass.value.length < 12) { msg(t('Choose a passphrase of at least 12 characters.')); pass.focus(); return; }
			btn.disabled = true;
			try {
				const resp = await fetch(API.url('/api/admin/transfer/export'), {
					method: 'POST', credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'requesttoken': (window.OC && OC.requestToken) || '' },
					body: JSON.stringify({ passphrase: pass.value }),
				});
				if (!resp.ok) {
					const j = await resp.json().catch(() => null);
					throw { code: (j && j.error && (j.error.code || j.error)) || 'SERVER_ERROR', context: (j && j.context) || {} };
				}
				const blob = await resp.blob();
				const a = document.createElement('a');
				a.href = URL.createObjectURL(blob);
				a.download = resp.headers.get('Content-Disposition')
					? (resp.headers.get('Content-Disposition').match(/filename="?([^";]+)"?/) || [])[1] || 'arbeitszeitcheck-transfer.nctransfer'
					: 'arbeitszeitcheck-transfer.nctransfer';
				document.body.appendChild(a); a.click(); a.remove();
				setTimeout(() => URL.revokeObjectURL(a.href), 30000);
				polite(t('Transfer file downloaded — store it and the passphrase safely.'));
			} catch (e) {
				msg(errText(e));
			} finally {
				btn.disabled = false;
			}
		});
	}

	// ── import: preview → report → confirm → progress ────────────────────
	function selectedMode() {
		const r = document.querySelector('#' + P + '-transfer-import-form input[name="mode"]:checked');
		return r ? r.value : 'merge';
	}
	function importReady() {
		const f = $('transfer-file');
		const p = $('transfer-import-passphrase');
		return f && f.files && f.files.length > 0 && p && p.value.length > 0;
	}
	function schedulePreview() {
		// Debounce: the preview decrypts with Argon2id — one request per
		// keystroke would melt the server for no benefit.
		if (previewTimer) clearTimeout(previewTimer);
		previewTimer = setTimeout(runPreview, 500);
	}
	async function runPreview() {
		const statusEl = $('transfer-import-status');
		if (!importReady()) { statusEl.textContent = ''; pendingId = null; return; }
		const seq = ++previewSeq;
		statusEl.textContent = t('Reading the file and building the preview…');
		try {
			const data = await API.upload('/api/admin/transfer/preview', $('transfer-file').files[0], {
				passphrase: $('transfer-import-passphrase').value,
				mode: selectedMode(),
			});
			if (seq !== previewSeq) return; // a newer preview superseded this one
			pendingId = data.pendingId;
			pendingMode = selectedMode();
			statusEl.textContent = '';
			showReport(data.report);
		} catch (e) {
			if (seq !== previewSeq) return;
			pendingId = null;
			statusEl.textContent = '';
			msg(errText(e));
		}
	}

	function group(title, items, renderItem, cls) {
		const det = document.createElement('details');
		det.className = P + '-transfer-group ' + (cls || '');
		det.open = cls === 'is-security';
		const sum = document.createElement('summary');
		sum.textContent = title + ' (' + items.length + ')';
		det.appendChild(sum);
		const ul = document.createElement('ul');
		items.forEach((it) => {
			const li = document.createElement('li');
			li.textContent = renderItem(it);
			ul.appendChild(li);
		});
		det.appendChild(ul);
		return det;
	}

	function armEscape(dlg) {
		// NC notification handlers can preventDefault the global Escape
		// keydown, suppressing the native <dialog> cancel event — a dialog
		// opened via showModal() would then ignore Escape entirely. Bind a
		// dialog-level close ourselves (host_app_escape_preventdefault).
		if (dlg) dlg.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); dlg.close(); }
		});
	}

	function showReport(r) {
		const dlg = $('transfer-report');
		const verdict = $('transfer-report-verdict');
		const file = $('transfer-report-file');
		const groups = $('transfer-report-groups');
		groups.textContent = '';
		const c = r.counts || {};
		if (pendingMode === 'replace') {
			verdict.textContent = t('Warning: %d row(s) will be permanently deleted, then the file data is loaded.', [c.rowsDeleted || 0]);
			verdict.className = P + '-transfer-report__verdict is-danger';
		} else {
			verdict.textContent = t('This will update %d settings and %d rows — nothing is deleted.', [(c.configApplied || 0), ((c.rowsInserted || 0) + (c.rowsUpdated || 0))]);
			verdict.className = P + '-transfer-report__verdict';
		}
		file.textContent = t('Exported from %1$s at %2$s', [(r.file && r.file.source_instance) || '?', (r.file && r.file.exported_at) || '?']);
		if ((c.rowsSkipped || 0) > 0 || (c.refsUnresolved || 0) > 0) {
			const skipped = document.createElement('p');
			skipped.className = P + '-callout ' + P + '-callout--warning';
			skipped.textContent = t('%d row(s) skipped and %d reference(s) unresolved — see the groups below.', [c.rowsSkipped || 0, c.refsUnresolved || 0]);
			groups.appendChild(skipped);
		}

		(r.warnings || []).forEach((w) => {
			const p = document.createElement('p');
			p.className = P + '-callout ' + P + '-callout--warning';
			p.textContent = w.code === 'same_instance' ? t('This file was exported from this instance.') :
				w.code === 'stale_file' ? t('This file is older than the last import on this instance.') :
				w.code === 'maintenance_required' ? t('Replace needs maintenance mode: occ maintenance:mode --on') :
				t('Value "%s" still points at the source server URL.', [w.detail || '']);
			groups.appendChild(p);
		});
		if ((r.security || []).length) groups.appendChild(group(t('Security-relevant changes'), r.security, (s) => s.key + ' — ' + s.detail, 'is-security'));
		if ((r.unresolved || []).length) groups.appendChild(group(t('Unresolved users / groups'), r.unresolved, (u) =>
			u.value + ' (' + u.type + ') → ' + (u.action === 'keep' ? t('kept as-is') : u.action === 'filtered' ? t('removed from the list') : t('will be skipped')) + (u.table ? ' · ' + u.table : '')));
		if ((r.secrets || []).length) groups.appendChild(group(t('Secrets not transferred'), r.secrets, (s) => s.key + ' — ' + t('set it on this instance')));
		if ((r.envBound || []).length) groups.appendChild(group(t('Instance-bound columns left empty'), r.envBound, (e) => e.table + '.' + e.column + ' — ' + t('%d row(s) need a value on this instance', [e.rows || 0])));
		const tableRows = Object.keys(r.tables || {}).map((k) => [k, r.tables[k]]);
		if (tableRows.length) groups.appendChild(group(t('Data changes'), tableRows, ([k, v]) =>
			k + ': +' + (v.insert || 0) + ' ~' + (v.update || 0) + ' −' + (v.delete || 0) + ' ✕' + (v.skip || 0)
				+ (v.filtered ? ' ' + t('(%d filtered)', [v.filtered]) : '')
				+ (v.unresolved ? ' ' + t('(%d unresolved ref)', [v.unresolved]) : '')
				+ (v.envDropped ? ' ' + t('(%d env-bound value(s) emptied)', [v.envDropped]) : '')
				+ (v.secretsRedacted ? ' ' + t('(%d secret(s) redacted)', [v.secretsRedacted]) : '')
				+ (v.skipped ? ' (' + (v.skipReason || '') + ')' : '')));
		if ((r.tablesUnknown || []).length) groups.appendChild(group(t('Unknown tables skipped'), r.tablesUnknown, (x) => x));
		const cc = r.config || {};
		const cfgItems = []
			.concat((cc.added || []).map((k) => '+ ' + k))
			.concat((cc.changed || []).map((x) => '~ ' + x.key + ': ' + x.from + ' → ' + x.to))
			.concat((cc.skipped || []).map((x) => '✕ ' + x.key + ' (' + x.reason + ')'))
			.concat((cc.invalid || []).map((x) => '✕ ' + x.key + ' (' + x.code + ')'))
			.concat((cc.unknown || []).map((k) => '? ' + k + ' ' + t('(unknown — skipped)')));
		if (cfgItems.length) groups.appendChild(group(t('Config changes'), cfgItems, (x) => x));

		const replaceBox = $('transfer-replace-confirm');
		const confirmBtn = $('transfer-confirm');
		const token = $('transfer-replace-token');
		if (pendingMode === 'replace') {
			replaceBox.hidden = false;
			token.value = '';
			confirmBtn.disabled = true;
			token.oninput = () => { confirmBtn.disabled = token.value !== 'REPLACE'; };
		} else {
			replaceBox.hidden = true;
			confirmBtn.disabled = false;
		}
		if (typeof dlg.showModal === 'function') dlg.showModal();
	}

	function initImport() {
		['transfer-file', 'transfer-import-passphrase'].forEach((id) => {
			const el = $(id);
			if (el) el.addEventListener(id === 'transfer-file' ? 'change' : 'input', schedulePreview);
		});
		document.querySelectorAll('#' + P + '-transfer-import-form input[name="mode"]').forEach((r) => {
			r.addEventListener('change', () => { if (importReady()) schedulePreview(); });
		});
		const cancel = $('transfer-cancel');
		if (cancel) cancel.addEventListener('click', () => { $('transfer-report').close(); });
		const confirm = $('transfer-confirm');
		if (confirm) confirm.addEventListener('click', async () => {
			confirm.disabled = true;
			try {
				await API.post('/api/admin/transfer/apply', {
					pendingId: pendingId,
					confirmation: pendingMode === 'replace' ? ($('transfer-replace-token') || {}).value : undefined,
				});
				$('transfer-report').close();
				startProgress();
			} catch (e) {
				msg(errText(e));
				// Re-arm: merge always; replace only while the token still matches.
				confirm.disabled = pendingMode === 'replace'
					&& (($('transfer-replace-token') || {}).value !== 'REPLACE');
			}
		});
	}

	const PHASES = {
		queued: 'Queued', validating: 'Validating', preflight: 'Pre-flight checks',
		snapshot: 'Safety snapshot', config: 'Writing settings', tables: 'Writing data',
		verify: 'Verifying references', finished: 'Done', failed: 'Failed',
	};
	function startProgress() {
		const dlg = $('transfer-progress');
		const bar = $('transfer-progress-bar');
		const text = $('transfer-progress-text');
		if (typeof dlg.showModal === 'function') dlg.showModal();
		const tick = async () => {
			try {
				const s = await API.get('/api/admin/transfer/status');
				const job = s.job || {};
				bar.value = job.percent || 0;
				const phase = PHASES[job.phase] || job.phase || '';
				text.textContent = phase ? t('Phase: %s', [t(phase)]) : t('Waiting for the job…');
				if (job.status === 'done' || job.status === 'failed') {
					clearInterval(pollTimer); pollTimer = null;
					dlg.close();
					const last = s.lastReceipt || {};
					if (job.status === 'done') {
						polite(t('Import applied. %d settings and %d rows written.', [
							(last.counts && last.counts.configApplied) || 0,
							((last.counts && (last.counts.rowsInserted + last.counts.rowsUpdated)) || 0)]));
						location.reload();
					} else {
						msg(t('Import failed — nothing was changed.') + (job.errorCode ? ' (' + job.errorCode + ')' : ''));
					}
				}
			} catch (e) { /* keep polling */ }
		};
		pollTimer = setInterval(tick, 1500);
		tick();
	}

	document.addEventListener('DOMContentLoaded', () => {
		if (!$('transfer-export-card')) return;
		initExport();
		initImport();
		armEscape($('transfer-report'));
		armEscape($('transfer-progress'));
	});
})();
