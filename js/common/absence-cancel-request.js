/**
 * FA-2c: owner cancel-request + withdraw for running approved absences.
 * Delegated handlers on [data-cancel-request] / [data-cancel-request-withdraw];
 * one native <dialog> with a single required reason field (U-3).
 */
(function () {
	'use strict';

	const t = (text) => (typeof window.t === 'function' ? window.t('arbeitszeitcheck', text) : text);

	function call(url, json) {
		const api = window.AzcApi;
		if (!api || typeof api.fetch !== 'function') {
			return Promise.reject(new Error(t('The request could not be completed.')));
		}
		return api.fetch(url, { method: 'POST', json }).then((res) => {
			if (!res.ok || !(res.data && res.data.success !== false)) {
				const err = new Error((res.data && res.data.error) || res.error || t('The request could not be completed.'));
				err.code = res.data && res.data.error_code;
				throw err;
			}
			return res.data;
		});
	}

	function buildDialog() {
		const dlg = document.createElement('dialog');
		dlg.className = 'azc-dialog azc-cancel-request';
		dlg.setAttribute('aria-labelledby', 'azc-cancel-request-title');
		dlg.innerHTML = `
			<h2 id="azc-cancel-request-title" class="azc-dialog__title"></h2>
			<p class="form-help" id="azc-cancel-request-hint"></p>
			<form method="dialog" novalidate>
				<div class="form-group">
					<label class="form-label" for="azc-cancel-request-reason"></label>
					<textarea id="azc-cancel-request-reason" class="form-input" rows="3" maxlength="500" required></textarea>
					<p class="azc-field-error" id="azc-cancel-request-error" role="alert" hidden></p>
				</div>
				<div class="azc-dialog__actions">
					<button type="submit" class="azc-btn azc-btn--primary" id="azc-cancel-request-submit"></button>
					<button type="button" class="azc-btn azc-btn--secondary" id="azc-cancel-request-cancel">${t('Cancel')}</button>
				</div>
			</form>`;
		return dlg;
	}

	function openRequestDialog(ctx) {
		const dlg = buildDialog();
		dlg.querySelector('#azc-cancel-request-title').textContent = t('Request cancellation');
		dlg.querySelector('#azc-cancel-request-hint').textContent = t('Your request must be approved by your manager — until then the absence stays approved and counts fully.');
		dlg.querySelector('label[for="azc-cancel-request-reason"]').textContent = t('Reason');
		dlg.querySelector('#azc-cancel-request-submit').textContent = t('Send request');
		document.body.appendChild(dlg);
		const reason = dlg.querySelector('#azc-cancel-request-reason');
		const error = dlg.querySelector('#azc-cancel-request-error');
		const submit = dlg.querySelector('#azc-cancel-request-submit');
		dlg.querySelector('#azc-cancel-request-cancel').addEventListener('click', () => dlg.close());
		dlg.addEventListener('keydown', (e) => { if (e.key === 'Escape') dlg.close(); });
		dlg.addEventListener('close', () => setTimeout(() => dlg.remove(), 300));
		dlg.querySelector('form').addEventListener('submit', async (ev) => {
			ev.preventDefault();
			const value = reason.value.trim();
			if (value === '') {
				reason.setAttribute('aria-invalid', 'true');
				error.textContent = t('Please provide a reason.');
				error.hidden = false;
				reason.focus();
				return;
			}
			submit.disabled = true;
			try {
				await call(ctx.url, { expectedUpdatedAt: ctx.updatedAt, reason: value });
				window.location.reload();
			} catch (e) {
				error.textContent = e.message;
				error.hidden = false;
				submit.disabled = false;
				if (e.code === 'revision_stale') setTimeout(() => window.location.reload(), 1600);
			}
		});
		dlg.showModal();
		reason.focus();
	}

	async function withdraw(ctx, button) {
		button.disabled = true;
		try {
			await call(ctx.url, { expectedUpdatedAt: ctx.updatedAt });
			window.location.reload();
		} catch (e) {
			button.disabled = false;
			if (window.OC && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
				OC.Notification.showTemporary(e.message);
			}
			if (e.code === 'revision_stale' || e.code === 'no_cancel_request') {
				setTimeout(() => window.location.reload(), 1600);
			}
		}
	}

	document.addEventListener('click', (ev) => {
		const req = ev.target.closest && ev.target.closest('[data-cancel-request]');
		if (req) {
			ev.preventDefault();
			openRequestDialog({
				url: req.dataset.cancelRequestUrl,
				updatedAt: req.dataset.updatedAt || '',
			});
			return;
		}
		const wd = ev.target.closest && ev.target.closest('[data-cancel-request-withdraw]');
		if (wd) {
			ev.preventDefault();
			withdraw({ url: wd.dataset.withdrawUrl, updatedAt: wd.dataset.updatedAt || '' }, wd);
		}
	});
})();
