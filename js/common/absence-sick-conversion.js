/**
 * Shared "convert vacation to sick" dialog.
 *
 * Used by the employee absence detail (owner flow → pending sick request) and
 * the manager absences table (manager flow → approved sick record). Buttons
 * carry data attributes; the dialog builds a native <dialog>, previews via
 * the endpoint's dryRun mode, and posts the conversion atomically.
 *
 * spec: planning/features/arbeitszeitcheck/vacation-to-sick-conversion-2026-10
 */
(function () {
	'use strict';

	const api = window.AzcApi || window.ArbeitszeitCheckApi || {};
	const t = (msgid) => (window.t ? window.t('arbeitszeitcheck', msgid) : msgid);

	function fmt(iso) {
		if (!iso) return '';
		const dp = window.AzcDatepicker;
		if (dp && typeof dp.convertIsoToEuropean === 'function') {
			return dp.convertIsoToEuropean(iso);
		}
		const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso));
		return m ? `${m[3]}.${m[2]}.${m[1]}` : String(iso);
	}

	function toIso(display) {
		const v = String(display || '').trim();
		const dp = window.AzcDatepicker;
		if (dp && typeof dp.convertEuropeanToISO === 'function') {
			return dp.convertEuropeanToISO(v);
		}
		const m = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(v);
		return m ? `${m[3]}-${m[2]}-${m[1]}` : (/^\d{4}-\d{2}-\d{2}$/.test(v) ? v : null);
	}

	async function call(url, json) {
		const res = await api.fetch(url, { method: 'POST', json });
		const ok = res && res.ok && api.isApiSuccess(res);
		if (!ok) {
			const err = new Error(res?.error || t('The request could not be completed.'));
			err.code = res?.data?.error_code || res?.error_code || '';
			throw err;
		}
		return res.data;
	}

	function describePreview(data) {
		const v = data.vacation || {};
		const s = data.sick || {};
		const parts = [];
		if (v.action === 'cancel') {
			parts.push(t('The vacation is cancelled completely.'));
		} else if (v.action === 'shorten') {
			parts.push(t('The vacation ends on %s.').replace('%s', fmt(v.newEnd)));
		} else if (v.action === 'move_start') {
			parts.push(t('The vacation starts on %s instead.').replace('%s', fmt(v.newStart)));
		} else if (v.action === 'shorten_and_split') {
			parts.push(t('The vacation is shortened to %s and continues again from %s to %s.')
				.replace('%s', fmt(v.newEnd))
				.replace('%s', fmt(data.tail ? data.tail.start : ''))
				.replace('%s', fmt(data.tail ? data.tail.end : '')));
		}
		const statusLabel = s.status === 'approved' ? t('approved') : t('pending approval');
		parts.push(t('Sick leave from %s to %s (%s working days) is recorded as %s.')
			.replace('%s', fmt(s.start))
			.replace('%s', fmt(s.end))
			.replace('%s', String(s.days))
			.replace('%s', statusLabel));
		const conflicts = Array.isArray(data.conflicts) ? data.conflicts : [];
		if (conflicts.length) {
			parts.push(t('Warning: this range overlaps an existing absence; saving will be rejected.'));
		}
		return parts.join(' ');
	}

	function buildDialog() {
		const dlg = document.createElement('dialog');
		dlg.className = 'azc-dialog azc-sick-convert';
		dlg.setAttribute('aria-labelledby', 'sick-convert-title');
		dlg.innerHTML = `
			<form method="dialog" class="azc-dialog__form" id="sick-convert-form" novalidate>
				<h2 id="sick-convert-title" class="azc-dialog__title"></h2>
				<p class="form-help" id="sick-convert-sub"></p>
				<div class="form-group">
					<label for="sick-convert-start" class="form-label">${t('Sick from')}</label>
					<input type="text" id="sick-convert-start" class="form-input datepicker-input"
						   placeholder="dd.mm.yyyy" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" maxlength="10" required>
				</div>
				<div class="form-group">
					<label for="sick-convert-end" class="form-label">${t('Sick until')}</label>
					<input type="text" id="sick-convert-end" class="form-input datepicker-input"
						   placeholder="dd.mm.yyyy" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" maxlength="10" required>
				</div>
				<div class="form-group" id="sick-convert-reason-wrap" hidden>
					<label for="sick-convert-reason" class="form-label">${t('Reason (optional)')}</label>
					<textarea id="sick-convert-reason" class="form-input" rows="2" maxlength="500"></textarea>
				</div>
				<p class="azc-sick-convert__preview form-help" id="sick-convert-preview" role="status" aria-live="polite"></p>
				<p class="azc-field-error" id="sick-convert-error" role="alert" hidden></p>
				<div class="azc-dialog__actions form-actions">
					<button type="submit" id="sick-convert-submit" class="azc-btn azc-btn--primary">${t('Convert to sick leave')}</button>
					<button type="button" id="sick-convert-cancel" class="azc-btn azc-btn--secondary">${t('Cancel')}</button>
				</div>
			</form>`;
		return dlg;
	}

	function open(ctx) {
		const dlg = buildDialog();
		document.body.appendChild(dlg);
		const startEl = dlg.querySelector('#sick-convert-start');
		const endEl = dlg.querySelector('#sick-convert-end');
		const reasonEl = dlg.querySelector('#sick-convert-reason');
		const reasonWrap = dlg.querySelector('#sick-convert-reason-wrap');
		const previewEl = dlg.querySelector('#sick-convert-preview');
		const errorEl = dlg.querySelector('#sick-convert-error');
		const submitBtn = dlg.querySelector('#sick-convert-submit');
		const cancelBtn = dlg.querySelector('#sick-convert-cancel');
		const form = dlg.querySelector('#sick-convert-form');

		dlg.querySelector('#sick-convert-title').textContent = ctx.title;
		dlg.querySelector('#sick-convert-sub').textContent = ctx.subtitle;
		reasonWrap.hidden = !ctx.showReason;
		startEl.value = fmt(ctx.vacStart);
		endEl.value = fmt(ctx.vacEnd);
		if (startEl.dataset) {
			startEl.dataset.datepickerMin = fmt(ctx.vacStart);
			startEl.dataset.datepickerMax = fmt(ctx.vacEnd);
			endEl.dataset.datepickerMin = fmt(ctx.vacStart);
			endEl.dataset.datepickerMax = fmt(ctx.vacEnd);
		}

		let previewTimer = null;
		const showError = (msg) => {
			errorEl.textContent = msg || '';
			errorEl.hidden = !msg;
		};

		const preview = async () => {
			const ss = toIso(startEl.value);
			const se = toIso(endEl.value);
			previewEl.textContent = '';
			if (!ss || !se) return;
			try {
				const data = await call(ctx.url, {
					sickStart: ss, sickEnd: se,
					expectedUpdatedAt: ctx.updatedAt,
					reason: reasonEl.value.trim(),
					dryRun: '1',
				});
				previewEl.textContent = describePreview(data);
			} catch (e) {
				previewEl.textContent = '';
			}
		};
		const schedulePreview = () => {
			clearTimeout(previewTimer);
			previewTimer = setTimeout(preview, 400);
		};
		startEl.addEventListener('input', schedulePreview);
		endEl.addEventListener('input', schedulePreview);
		startEl.addEventListener('change', schedulePreview);
		endEl.addEventListener('change', schedulePreview);

		cancelBtn.addEventListener('click', () => dlg.close());
		// NC's notifications app preventDefault()s the global Escape keydown,
		// which suppresses the native <dialog> `cancel` event — without an
		// explicit handler the modal ignores Escape entirely.
		dlg.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') {
				e.preventDefault();
				dlg.close();
			}
		});
		dlg.addEventListener('close', () => { setTimeout(() => dlg.remove(), 300); });

		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			showError('');
			const ss = toIso(startEl.value);
			const se = toIso(endEl.value);
			if (!ss || !se) {
				showError(t('Please enter both dates as dd.mm.yyyy.'));
				return;
			}
			submitBtn.disabled = true;
			try {
				await call(ctx.url, {
					sickStart: ss, sickEnd: se,
					expectedUpdatedAt: ctx.updatedAt,
					reason: reasonEl.value.trim(),
				});
				window.location.reload();
			} catch (err) {
				if (err.code === 'revision_stale') {
					showError(t('This absence was changed in the meantime. Reloading to show the current state.'));
					setTimeout(() => window.location.reload(), 1600);
					return;
				}
				showError(err.message);
				submitBtn.disabled = false;
			}
		});

		if (typeof dlg.showModal === 'function') {
			dlg.showModal();
		}
		startEl.focus();
		schedulePreview();
	}

	document.addEventListener('click', (e) => {
		const btn = e.target.closest && e.target.closest('[data-sick-convert]');
		if (!btn || btn.disabled) return;
		e.preventDefault();
		const d = btn.dataset;
		const isManager = d.sickConvertFlow === 'manager';
		open({
			url: d.sickConvertUrl,
			vacStart: d.vacStart,
			vacEnd: d.vacEnd,
			updatedAt: d.updatedAt,
			showReason: isManager,
			title: t('Convert vacation to sick leave'),
			subtitle: isManager
				? t('Converts the approved vacation into sick leave. The employee is notified; vacation days are credited back.')
				: t('Were you sick during your vacation? Select the sick days. The vacation is shortened and the days are credited back.'),
		});
	});
})();
