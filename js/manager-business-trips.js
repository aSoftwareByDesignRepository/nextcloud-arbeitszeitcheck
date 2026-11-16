/**
 * Manager business-trip review — pending queue + three-card detail.
 *
 * Approve requires the snapshot token the detail endpoint served plus
 * the current revision: the decision binds to exactly the reviewed fact
 * set. A 409 reloads the trip — decisions are never retried blindly.
 */
(function () {
	'use strict';

	const root = document.getElementById('mbt-root');
	if (!root) return;

	const t = (text) => (typeof window.t === 'function' ? window.t('arbeitszeitcheck', text) : text);

	/** Plural-aware translate (Nextcloud window.n replaces %n). */
	function n(singular, plural, count) {
		const c = Number.isFinite(Number(count)) ? Number(count) : 0;
		if (typeof window.n === 'function') {
			return window.n('arbeitszeitcheck', singular, plural, c);
		}
		return String(c === 1 ? singular : plural).replace('%n', String(c));
	}
	const api = window.AzcApi;
	const $ = (id) => document.getElementById(id);

	const els = {
		queueCard: $('mbt-queue-card'),
		queueBody: $('mbt-queue-body'),
		emptyRow: $('mbt-empty-row'),
		queueStatus: $('mbt-queue-status'),
		detail: $('mbt-detail'),
		detailSubtitle: $('mbt-detail-subtitle'),
		detailStatus: $('mbt-detail-status'),
		detailStatusMsg: $('mbt-detail-status-msg'),
		error: $('mbt-error'),
		errorText: $('mbt-error-text'),
		conflict: $('mbt-conflict'),
		segments: $('mbt-segments'),
		linkedSummary: $('mbt-linked-summary'),
		dayRows: $('mbt-day-rows'),
		sumRecorded: $('mbt-sum-recorded'),
		sumWork: $('mbt-sum-work'),
		sumTopup: $('mbt-sum-topup'),
		rejectDialog: $('mbt-reject-dialog'),
		rejectReason: $('mbt-reject-reason'),
		rejectError: $('mbt-reject-error'),
		rejectToggle: $('mbt-reject-toggle'),
		rejectConfirm: $('mbt-reject-confirm'),
		rejectCancel: $('mbt-reject-cancel'),
		rejectClose: $('mbt-reject-close'),
		detailClose: $('mbt-detail-close'),
		filterEmployee: $('mbt-filter-employee'),
		filterStatus: $('mbt-filter-status'),
		filterDateFrom: $('mbt-filter-date-from'),
		filterDateTo: $('mbt-filter-date-to'),
		filterReset: $('mbt-filter-reset'),
		emptyTitle: $('mbt-empty-title'),
		emptyText: $('mbt-empty-text'),
		approve: $('mbt-approve'),
		back: $('mbt-back'),
	};

	const STATUS_LABELS = {
		draft: t('Draft — not yet submitted'),
		submitted: t('Submitted'),
		approved: t('Approved'),
		rejected: t('Rejected'),
		cancelled: t('Withdrawn'),
	};

	let current = null; // {trip, snapshotToken}
	let queueSeq = 0; // last-write-wins guard for overlapping queue fetches
	let detailSeq = 0; // last-click-wins guard — a slow fetch must not overwrite a newer open

	function tpl(name, id) {
		return root.dataset[name].replace('__ID__', encodeURIComponent(String(id)));
	}

	// Dialogs reparent to <body> and open modally in the top layer — inside
	// #app-content-wrapper the shared h1–h6 reset and app positioning rules
	// would break their styling and centering.
	function openDialog(dlg) {
		if (dlg.parentElement !== document.body) {
			document.body.appendChild(dlg);
		}
		if (dlg.open) {
			return;
		}
		let opened = false;
		if (typeof dlg.showModal === 'function') {
			try {
				dlg.showModal();
				opened = dlg.open === true;
			} catch (e) {
				opened = false;
			}
		}
		if (!opened) {
			dlg.setAttribute('open', '');
		}
	}

	function closeDialog(dlg) {
		if (typeof dlg.close === 'function' && dlg.open) {
			dlg.close();
		}
		dlg.removeAttribute('open');
	}

	async function call(url, options) {
		const res = await api.fetch(url, options);
		if (!res.ok || !api.isApiSuccess(res)) {
			const err = new Error(res.error || t('The request could not be completed.'));
			err.status = res.status;
			err.fields = res.data && res.data.fields ? res.data.fields : {};
			throw err;
		}
		return res.data;
	}

	function fmtDuration(minutes) {
		const m = Number(minutes) || 0;
		const h = Math.floor(Math.abs(m) / 60);
		const rest = Math.abs(m) % 60;
		return (m < 0 ? '−' : '') + h + ':' + String(rest).padStart(2, '0') + ' h';
	}

	function fmtDateTime(iso) {
		if (!iso) return '—';
		try {
			return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
		} catch (e) {
			return iso;
		}
	}

	function showError(message) {
		els.errorText.textContent = message;
		els.error.hidden = false;
	}

	function icon(name) {
		return (window.AzcCatalog && window.AzcCatalog.render) ? window.AzcCatalog.render(name) : '';
	}

	function cell(label) {
		const td = document.createElement('td');
		td.dataset.label = label;
		return td;
	}

	function employeeCell(item) {
		const td = cell(t('Employee'));
		const wrap = document.createElement('span');
		wrap.className = 'mbt-emp';
		const name = document.createElement('span');
		name.className = 'mbt-emp__name';
		name.textContent = item.displayName || item.userId || '';
		wrap.appendChild(name);
		if (item.userId && item.userId !== name.textContent) {
			const uid = document.createElement('span');
			uid.className = 'mbt-emp__uid';
			uid.textContent = item.userId;
			wrap.appendChild(uid);
		}
		td.appendChild(wrap);
		return td;
	}

	function queueRow(item) {
		const who = item.displayName || item.userId || '';
		const range = fmtDateTime(item.startsAtUtc) + ' – ' + fmtDateTime(item.endsAtUtc);
		const tr = document.createElement('tr');
		tr.setAttribute('data-azc-row', '');

		const tdTrip = cell(t('Business trip'));
		// Stacked like .mbt-emp — a single-line range is the widest cell and
		// pushes the 6-column table into horizontal scroll at desktop.
		const tripWrap = document.createElement('span');
		tripWrap.className = 'mbt-trip';
		const tripFrom = document.createElement('span');
		tripFrom.className = 'mbt-trip__line';
		tripFrom.textContent = fmtDateTime(item.startsAtUtc);
		const tripTo = document.createElement('span');
		tripTo.className = 'mbt-trip__line';
		tripTo.textContent = '– ' + fmtDateTime(item.endsAtUtc);
		tripWrap.append(tripFrom, tripTo);
		tdTrip.appendChild(tripWrap);
		const tdPurpose = cell(t('Purpose'));
		tdPurpose.textContent = item.purpose || '—';
		const tdStatus = cell(t('Status'));
		const chip = document.createElement('span');
		chip.className = 'azc-status-chip';
		chip.dataset.status = item.status || 'submitted';
		chip.textContent = STATUS_LABELS[item.status] || item.status || '—';
		tdStatus.appendChild(chip);
		const tdUpdated = cell(t('Updated'));
		tdUpdated.textContent = fmtDateTime(item.updatedAt);

		const tdActions = cell(t('Actions'));
		tdActions.className = 'actions-cell';
		const group = document.createElement('div');
		group.className = 'azc-table-actions';
		group.setAttribute('role', 'group');
		group.setAttribute('aria-label', t('Actions'));
		const review = document.createElement('button');
		review.type = 'button';
		review.className = 'azc-btn azc-btn--secondary azc-icon-btn';
		review.setAttribute('aria-label', t('Review business trip') + ': ' + who + ', ' + range);
		review.title = t('Review');
		review.innerHTML = icon('info');
		review.addEventListener('click', () => openDetail(item.id));
		group.appendChild(review);
		tdActions.appendChild(group);

		tr.append(employeeCell(item), tdTrip, tdPurpose, tdStatus, tdUpdated, tdActions);
		return tr;
	}

	let queueItems = [];

	/* Strict dd.mm.yyyy → ISO date. Returns null for empty input and for
	   anything that is not a real calendar day — a half-typed or invalid
	   value must fail visibly, never silently filter out rows. */
	function parseFilterDate(value) {
		const m = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(value.trim());
		if (!m) {
			return null;
		}
		const iso = m[3] + '-' + m[2] + '-' + m[1];
		const d = new Date(iso + 'T00:00:00Z');
		return (!Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === iso) ? iso : null;
	}

	/* Invalidity display is owned by the shared datepicker (aria-invalid +
	   form-input--error + inline field-error on commit) — duplicating it here
	   produced two desynced error states that Reset could not clear. The
	   filter only interprets values: an unparsable bound is ignored. */
	function tripMatchesFilters(item) {
		const q = els.filterEmployee.value.trim().toLowerCase();
		if (q !== '' && !((item.displayName || '') + ' ' + (item.userId || '')).toLowerCase().includes(q)) {
			return false;
		}
		const status = els.filterStatus.value;
		if (status !== '' && item.status !== status) {
			return false;
		}
		// Date range = overlap: the trip's [start..end] must intersect the
		// filter's [from..to]. Open bounds are allowed; unparsable input is
		// flagged by applyFilters and ignored here.
		const from = parseFilterDate(els.filterDateFrom.value);
		const to = parseFilterDate(els.filterDateTo.value);
		const start = (item.startsAtUtc || '').slice(0, 10);
		const end = (item.endsAtUtc || item.startsAtUtc || '').slice(0, 10);
		if (from !== null && end < from) {
			return false;
		}
		if (to !== null && start > to) {
			return false;
		}
		return true;
	}

	function renderQueueRows() {
		const items = queueItems.filter(tripMatchesFilters);
		els.queueBody.replaceChildren(els.emptyRow, ...items.map(queueRow));
		els.emptyRow.hidden = items.length !== 0;
		// The empty row doubles as the "no matches" state — swap the copy so
		// an active filter never pretends the team has no trips.
		if (queueItems.length === 0) {
			els.emptyTitle.textContent = t('No business trips in your team yet.');
			els.emptyText.textContent = t('Trips your team members submit for approval will show up here.');
		} else {
			els.emptyTitle.textContent = t('No business trips match your filters.');
			els.emptyText.textContent = t('Adjust or reset the filters to see more trips.');
		}
	}

	function applyFilters() {
		renderQueueRows();
	}

	async function loadQueue() {
		const seq = ++queueSeq;
		els.queueStatus.textContent = t('Loading…');
		try {
			const body = await call(root.dataset.queueUrl);
			if (seq !== queueSeq) {
				return; // a newer fetch supersedes this response
			}
			queueItems = body.items || [];
			renderQueueRows();
			els.queueStatus.textContent = '';
		} catch (e) {
			if (seq !== queueSeq) {
				return;
			}
			els.queueStatus.textContent = e.message;
		}
	}

	function renderDetail(body) {
		const trip = body.trip;
		els.detailSubtitle.textContent = (trip.displayName || trip.userId || '') + ' · ' + fmtDateTime(trip.startsAtUtc) + ' – ' + fmtDateTime(trip.endsAtUtc) + (trip.purpose ? ' · ' + trip.purpose : '');
		els.detailStatus.dataset.status = trip.status;
		els.detailStatus.textContent = STATUS_LABELS[trip.status] || trip.status;

		const kindLabels = {
			active_travel: t('Self-driven travel'),
			passive_travel: t('Passenger / transported'),
			work_during_travel: t('Worked while travelling'),
			rest_personal: t('Rest / private'),
			unclassified: t('Not yet classified'),
		};
		els.segments.replaceChildren();
		(body.segments || []).forEach((seg) => {
			const li = document.createElement('li');
			li.className = 'azc-list__item bt-segment';
			const span = document.createElement('span');
			span.className = 'bt-segment__text';
			span.textContent = fmtDateTime(seg.startsAtUtc) + ' – ' + fmtDateTime(seg.endsAtUtc) + ' · ' + (kindLabels[seg.segmentType] || seg.segmentType);
			li.appendChild(span);
			els.segments.appendChild(li);
		});
		if ((body.segments || []).length === 0) {
			const li = document.createElement('li');
			li.className = 'azc-list__item';
			li.textContent = t('No travel blocks recorded.');
			els.segments.appendChild(li);
		}
		const linked = body.linkedTimeEntryIds || [];
		els.linkedSummary.textContent = n('One working-time entry is linked.', '%n working-time entries are linked.', linked.length);

		const days = body.days || [];
		els.dayRows.replaceChildren();
		days.forEach((d) => {
			const tr = document.createElement('tr');
			[d.localDate, fmtDuration(d.activeTravelMinutes), fmtDuration(d.passiveTravelMinutes), fmtDuration(d.actualWorkMinutes), fmtDuration(d.accountTargetMinutes), fmtDuration(d.accountTopUpMinutes)]
				.forEach((v) => {
					const td = document.createElement('td');
					td.textContent = v;
					tr.appendChild(td);
				});
			els.dayRows.appendChild(tr);
		});
		els.sumRecorded.textContent = fmtDuration(days.reduce((a, d) => a + (d.presenceMinutes || 0), 0));
		els.sumWork.textContent = fmtDuration(days.reduce((a, d) => a + (d.actualWorkMinutes || 0), 0));
		els.sumTopup.textContent = fmtDuration(days.reduce((a, d) => a + (d.accountTopUpMinutes || 0), 0));

		const decidable = trip.status === 'submitted';
		els.approve.hidden = !decidable;
		els.rejectToggle.hidden = !decidable;
		if (!decidable) {
			resetRejectUi();
		}
		els.rejectToggle.disabled = false;
		els.approve.disabled = !body.snapshotToken;
		current = { trip, snapshotToken: body.snapshotToken };
	}

	function resetRejectUi() {
		closeDialog(els.rejectDialog);
		els.rejectReason.value = '';
		els.rejectReason.removeAttribute('aria-invalid');
		els.rejectError.textContent = '';
		els.rejectError.hidden = true;
	}

	async function openDetail(id) {
		const seq = ++detailSeq;
		openDialog(els.detail);
		els.error.hidden = true;
		els.conflict.hidden = true;
		resetRejectUi();
		// Decision buttons stay inert while the detail is loading — a click
		// before `current` is set would silently no-op.
		els.approve.disabled = true;
		els.rejectToggle.disabled = true;
		els.detailStatusMsg.textContent = t('Loading…');
		try {
			const body = await call(tpl('detailTemplate', id));
			if (seq !== detailSeq || !els.detail.open) {
				return; // a newer open (or a close) supersedes this response
			}
			renderDetail(body);
			els.detailStatusMsg.textContent = '';
		} catch (e) {
			if (seq !== detailSeq || !els.detail.open) {
				return;
			}
			els.detailStatusMsg.textContent = '';
			showError(e.message);
		}
	}

	function notifySuccess(message) {
		if (window.ArbeitszeitCheckMessaging && typeof window.ArbeitszeitCheckMessaging.showSuccess === 'function') {
			window.ArbeitszeitCheckMessaging.showSuccess(message);
		} else {
			els.queueStatus.textContent = message;
		}
	}

	els.approve.addEventListener('click', async () => {
		if (!current) return;
		els.approve.disabled = true;
		try {
			await call(tpl('approveTemplate', current.trip.id), {
				method: 'POST',
				json: { revision: current.trip.revision, snapshotToken: current.snapshotToken },
			});
			// Decision made — close the overlay, toast the outcome, and let the
			// queue reload move the row to the decided section.
			closeDetail();
			notifySuccess(t('Approved — the top-up was booked to the time account.'));
		} catch (e) {
			if (e.status === 409) {
				els.conflict.hidden = false;
				await openDetail(current.trip.id);
			} else {
				showError(e.message);
				els.approve.disabled = false;
			}
		}
	});

	els.rejectToggle.addEventListener('click', () => {
		els.rejectReason.value = '';
		els.rejectReason.removeAttribute('aria-invalid');
		els.rejectError.textContent = '';
		els.rejectError.hidden = true;
		openDialog(els.rejectDialog);
		els.rejectReason.focus();
	});

	els.rejectCancel.addEventListener('click', () => {
		resetRejectUi();
	});

	els.rejectClose.addEventListener('click', () => {
		resetRejectUi();
	});

	els.rejectConfirm.addEventListener('click', async () => {
		if (!current) return;
		const reason = els.rejectReason.value.trim();
		if (reason === '') {
			els.rejectReason.setAttribute('aria-invalid', 'true');
			els.rejectError.textContent = t('Please give a reason — the employee sees it.');
			els.rejectError.hidden = false;
			return;
		}
		els.rejectConfirm.disabled = true;
		try {
			await call(tpl('rejectTemplate', current.trip.id), {
				method: 'POST',
				json: { revision: current.trip.revision, reason },
			});
			resetRejectUi();
			closeDetail();
			notifySuccess(t('Rejected — the employee was informed.'));
		} catch (e) {
			if (e.status === 409) {
				els.conflict.hidden = false;
				await openDetail(current.trip.id);
			} else if (e.status === 400 && e.fields && e.fields.reason) {
				// Validation error on the reason itself — keep the dialog open,
				// keep the typed text, flag the field.
				els.rejectReason.setAttribute('aria-invalid', 'true');
				els.rejectError.textContent = e.message;
				els.rejectError.hidden = false;
			} else {
				resetRejectUi();
				showError(e.message);
			}
		} finally {
			els.rejectConfirm.disabled = false;
		}
	});

	function closeDetail() {
		detailSeq++; // in-flight detail fetches must not re-render a closed dialog
		closeDialog(els.detail);
		current = null;
		loadQueue();
	}

	els.back.addEventListener('click', closeDetail);
	els.detailClose.addEventListener('click', closeDetail);

	/* NC core preventDefaults Escape in a global keydown handler, which
	   suppresses the native dialog 'cancel' — close explicitly instead.
	   preventDefault is required: if the keydown stays un-canceled, the
	   browser's own ESC-to-close default action fires after dispatch and —
	   with the inner dialog already closed — shuts the outer one too.
	   stopPropagation keeps ESC inside the modal from toggling app chrome. */
	els.detail.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') {
			e.preventDefault();
			e.stopPropagation();
			closeDetail();
		}
	});
	els.rejectDialog.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') {
			e.preventDefault();
			e.stopPropagation();
			resetRejectUi();
		}
	});

	// Live filtering — the queue payload is already client-side, so there is
	// no Show/apply round-trip. 'input' covers typing and the datepicker's
	// programmatic set; 'change' covers select + committed text edits.
	[els.filterEmployee, els.filterDateFrom, els.filterDateTo].forEach((el) => {
		el.addEventListener('input', applyFilters);
		el.addEventListener('change', applyFilters);
	});
	els.filterStatus.addEventListener('change', applyFilters);
	els.filterReset.addEventListener('click', () => {
		els.filterEmployee.value = '';
		els.filterStatus.value = '';
		// The datepicker component owns its error state and only observes
		// events — a programmatic clear must notify it or stale
		// aria-invalid/field-error survives the reset.
		for (const el of [els.filterDateFrom, els.filterDateTo]) {
			el.value = '';
			el.dispatchEvent(new Event('input', { bubbles: true }));
		}
		applyFilters();
		els.filterEmployee.focus();
	});

	loadQueue();
})();
