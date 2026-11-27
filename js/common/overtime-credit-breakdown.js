/**
 * Overtime absence-credit drill-down — the "where does this number come
 * from" dialog behind the dashboard's credited/reduced lines. Native
 * <dialog>, fetch on open, all dynamic content via textContent (no HTML
 * injection surface for custom type labels).
 */
(function () {
	'use strict';

	const l10n = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n
		&& window.ArbeitszeitCheck.l10n.breakdown) || {};

	const txt = (id, vars) => {
		let s = typeof l10n[id] === 'string' ? l10n[id] : id;
		(vars || []).forEach((v, i) => { s = s.split('%' + (i + 1) + '$s').join(v); });
		return s;
	};
	const n = (singularId, pluralId, count) => txt(count === 1 ? singularId : pluralId, [String(count)]);

	const nf = new Intl.NumberFormat(document.documentElement.lang || undefined, {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	});
	const df = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
		weekday: 'short',
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	});
	const dfShort = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	});

	const fmtHours = (v) => nf.format(typeof v === 'number' ? v : 0);
	const fmtDate = (ymd) => {
		const d = new Date(ymd + 'T00:00:00');
		return Number.isNaN(d.getTime()) ? ymd : df.format(d);
	};
	const fmtDateShort = (ymd) => {
		const d = new Date(ymd + 'T00:00:00');
		return Number.isNaN(d.getTime()) ? ymd : dfShort.format(d);
	};

	const REASON_MSGIDS = {
		not_approved: 'Not approved — pending, declined or cancelled absences never affect the balance.',
		effect_none: 'This type counts as productive or neutral time (e.g. business trips, home office) and does not credit hours.',
		type_not_paid: 'This absence type is not marked as paid leave, so it does not credit hours.',
		gate_disabled: 'Would credit hours, but this effect is disabled in the organization settings.',
		no_planned_hours: 'No working time is planned on these days (e.g. weekend or public holiday).',
		fully_worked: 'Already covered — the tracked working time reaches the planned hours.',
		overlap: 'Overlaps another absence that credited these days.',
	};

	// A-R5: gate-disabled items carry the config key of the setting an admin
	// can open — name it so the user knows which switch to ask for.
	const GATE_MSGIDS = {
		paid_absence_planned_hours_credit: 'Would credit hours, but crediting planned hours for paid absences is disabled in the organization settings.',
		leave_soll_effects: 'Would credit hours, but leave effects on planned hours are disabled in the organization settings.',
	};

	function reasonText(item) {
		if (item.reason === 'gate_disabled' && typeof item.gate === 'string' && GATE_MSGIDS[item.gate]) {
			return txt(GATE_MSGIDS[item.gate]);
		}
		return txt(REASON_MSGIDS[item.reason] || item.reason);
	}

	function buildDialog() {
		const dlg = document.createElement('dialog');
		dlg.className = 'azc-dialog azc-credit-breakdown modal--lg';
		dlg.setAttribute('aria-labelledby', 'azc-credit-breakdown-title');
		const header = document.createElement('div');
		header.className = 'modal-header';
		const title = document.createElement('h2');
		title.id = 'azc-credit-breakdown-title';
		title.className = 'modal-title';
		title.textContent = txt('Absence credit breakdown');
		const dismiss = document.createElement('button');
		dismiss.type = 'button';
		dismiss.className = 'modal-close';
		dismiss.setAttribute('aria-label', txt('Close'));
		dismiss.textContent = '×';
		dismiss.addEventListener('click', () => dlg.close());
		// NC's notifications app preventDefault()s the global Escape keydown,
		// which suppresses the native <dialog> `cancel` event — without an
		// explicit handler the modal ignores Escape entirely.
		dlg.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') {
				e.preventDefault();
				dlg.close();
			}
		});
		header.append(title, dismiss);
		const bodyWrap = document.createElement('div');
		bodyWrap.className = 'modal-body';
		const status = document.createElement('p');
		status.className = 'azc-credit-breakdown__status form-help';
		status.setAttribute('role', 'status');
		status.setAttribute('aria-live', 'polite');
		const body = document.createElement('div');
		body.className = 'azc-credit-breakdown__body';
		bodyWrap.append(status, body);
		const footer = document.createElement('div');
		footer.className = 'modal-footer';
		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'azc-btn azc-btn--secondary';
		close.textContent = txt('Close');
		close.addEventListener('click', () => dlg.close());
		footer.appendChild(close);
		dlg.append(header, bodyWrap, footer);
		return { dlg, status, body };
	}

	function itemHeader(item, hours, suffix) {
		const head = document.createElement('div');
		head.className = 'azc-credit-breakdown__item-head';
		const label = document.createElement('span');
		label.className = 'azc-credit-breakdown__item-label';
		const range = item.start === item.end
			? fmtDateShort(item.start)
			: fmtDateShort(item.start) + ' – ' + fmtDateShort(item.end);
		label.textContent = (item.label || item.type) + ' · ' + range;
		if (suffix !== '') {
			const val = document.createElement('span');
			val.className = 'azc-credit-breakdown__item-hours';
			val.textContent = (suffix === '+' ? '+' : '−') + fmtHours(hours) + ' h';
			head.append(label, val);
		} else {
			head.append(label);
		}
		return head;
	}

	function footNotes(item) {
		const notes = [];
		if (item.overlap_days) {
			notes.push(n(
				'One day overlapped another absence and is not listed',
				'%1$s days overlapped another absence and are not listed',
				item.overlap_days,
			));
		}
		if (item.unplanned_days) {
			notes.push(n(
				'One day had no planned working time (e.g. weekend or public holiday)',
				'%1$s days had no planned working time (e.g. weekend or public holiday)',
				item.unplanned_days,
			));
		}
		return notes.map((s) => {
			const p = document.createElement('p');
			p.className = 'form-help azc-credit-breakdown__overlap';
			p.textContent = s;
			return p;
		});
	}

	function renderCounted(item) {
		const block = document.createElement('li');
		block.className = 'azc-credit-breakdown__item';
		block.appendChild(itemHeader(item, item.credit_hours, '+'));
		const list = document.createElement('ul');
		list.className = 'azc-credit-breakdown__days';
		(item.days || []).forEach((d) => {
			const li = document.createElement('li');
			li.textContent = txt(
				'%1$s — planned %2$s h, absent %3$s h, worked %4$s h, credited %5$s h',
				[fmtDate(d.date), fmtHours(d.planned), fmtHours(d.absent), fmtHours(d.worked), fmtHours(d.credited)],
			);
			list.appendChild(li);
		});
		block.appendChild(list);
		footNotes(item).forEach((note) => block.appendChild(note));
		return block;
	}

	function renderReduced(item) {
		const block = document.createElement('li');
		block.className = 'azc-credit-breakdown__item';
		block.appendChild(itemHeader(item, item.reduced_hours, '−'));
		const list = document.createElement('ul');
		list.className = 'azc-credit-breakdown__days';
		(item.days || []).forEach((d) => {
			const li = document.createElement('li');
			li.textContent = txt(
				'%1$s — planned %2$s h, absent %3$s h, target reduced by %4$s h',
				[fmtDate(d.date), fmtHours(d.planned), fmtHours(d.absent), fmtHours(d.reduced)],
			);
			list.appendChild(li);
		});
		block.appendChild(list);
		footNotes(item).forEach((note) => block.appendChild(note));
		return block;
	}

	function renderNotCounted(item) {
		const block = document.createElement('li');
		block.className = 'azc-credit-breakdown__item azc-credit-breakdown__item--muted';
		block.appendChild(itemHeader(item, 0, ''));
		const reason = document.createElement('p');
		reason.className = 'form-help azc-credit-breakdown__reason';
		reason.textContent = reasonText(item);
		block.appendChild(reason);
		footNotes(item).forEach((note) => block.appendChild(note));
		return block;
	}

	function section(parent, titleText, items, renderItem) {
		if (!items || items.length === 0) {
			return;
		}
		const h = document.createElement('h3');
		h.className = 'azc-credit-breakdown__section-title';
		h.textContent = titleText;
		const ul = document.createElement('ul');
		ul.className = 'azc-credit-breakdown__list';
		items.forEach((item) => ul.appendChild(renderItem(item)));
		parent.append(h, ul);
	}

	function render(data, body, status) {
		body.textContent = '';
		const period = data.period || {};
		status.textContent = txt(
			'Period %1$s to %2$s — %3$s h credited in total.',
			[fmtDateShort(period.start || ''), fmtDateShort(period.end || ''), fmtHours(data.credit_hours)],
		);

		const isEmpty = !(data.credited || []).length
			&& !(data.reduced || []).length
			&& !(data.not_counted || []).length;
		if (isEmpty) {
			const p = document.createElement('p');
			p.className = 'form-help';
			p.textContent = txt('No absences in this period.');
			body.appendChild(p);
		} else {
			section(
				body,
				txt('Counted towards the balance'),
				data.credited,
				renderCounted,
			);
			section(
				body,
				txt('Reduced required hours'),
				data.reduced,
				renderReduced,
			);
			section(
				body,
				txt('Not counted'),
				data.not_counted,
				renderNotCounted,
			);
		}

		const est = data.comp_estimate;
		if (est && typeof est.days === 'number' && est.daily_hours > 0) {
			const p = document.createElement('p');
			p.className = 'form-help azc-credit-breakdown__estimate';
			p.textContent = txt(
				'%1$s — based on a balance of %2$s h ÷ %3$s h per planned day.',
				[
					n('≈ %1$s compensatory day available', '≈ %1$s compensatory days available', est.days),
					fmtHours(est.balance_hours),
					fmtHours(est.daily_hours),
				],
			);
			body.appendChild(p);
		}
	}

	async function load(url, refs, button) {
		const api = window.AzcApi;
		refs.status.textContent = txt('Loading…');
		refs.body.textContent = '';
		try {
			if (!api || typeof api.fetch !== 'function') {
				throw new Error('api-missing');
			}
			const res = await api.fetch(url, { method: 'GET', silent: true });
			if (!res.ok || !res.data || res.data.success !== true) {
				throw new Error((res.data && res.data.message) || 'load-failed');
			}
			render(res.data, refs.body, refs.status);
		} catch (e) {
			refs.status.textContent = txt('The breakdown could not be loaded.');
			const retry = document.createElement('button');
			retry.type = 'button';
			retry.className = 'azc-btn azc-btn--secondary azc-btn--sm';
			retry.textContent = txt('Retry');
			retry.addEventListener('click', () => load(url, refs, button));
			refs.body.textContent = '';
			refs.body.appendChild(retry);
		}
	}

	function open(trigger) {
		const url = trigger.getAttribute('data-breakdown-url');
		if (!url) {
			return;
		}
		const refs = buildDialog();
		document.body.appendChild(refs.dlg);
		refs.dlg.addEventListener('close', () => setTimeout(() => refs.dlg.remove(), 300));
		refs.dlg.showModal();
		load(url, refs, trigger);
	}

	document.addEventListener('click', (ev) => {
		const trigger = ev.target.closest && ev.target.closest('[data-overtime-breakdown]');
		if (trigger) {
			ev.preventDefault();
			open(trigger);
		}
	});
})();
