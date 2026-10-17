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
	const api = window.AzcApi;
	const $ = (id) => document.getElementById(id);

	const els = {
		queueCard: $('mbt-queue-card'),
		queue: $('mbt-queue'),
		queueStatus: $('mbt-queue-status'),
		empty: $('mbt-empty'),
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
		rejectGroup: $('mbt-reject-group'),
		rejectReason: $('mbt-reject-reason'),
		rejectError: $('mbt-reject-error'),
		rejectToggle: $('mbt-reject-toggle'),
		rejectConfirm: $('mbt-reject-confirm'),
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

	function tpl(name, id) {
		return root.dataset[name].replace('__ID__', encodeURIComponent(String(id)));
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

	async function loadQueue() {
		els.queueStatus.textContent = t('Loading…');
		try {
			const body = await call(root.dataset.queueUrl);
			const items = body.items || [];
			els.queue.replaceChildren();
			els.empty.hidden = items.length !== 0;
			items.forEach((item) => {
				const li = document.createElement('li');
				li.className = 'azc-list__item bt-trip-item';
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'bt-trip-item__link bt-trip-item__button';
				const title = document.createElement('span');
				title.className = 'bt-trip-item__title';
				title.textContent = (item.userId || '') + ' · ' + fmtDateTime(item.startsAtUtc) + ' – ' + fmtDateTime(item.endsAtUtc);
				const sub = document.createElement('span');
				sub.className = 'bt-trip-item__sub';
				sub.textContent = item.purpose || '';
				btn.append(title, sub);
				btn.addEventListener('click', () => openDetail(item.id));
				li.appendChild(btn);
				els.queue.appendChild(li);
			});
			els.queueStatus.textContent = '';
		} catch (e) {
			els.queueStatus.textContent = e.message;
		}
	}

	function renderDetail(body) {
		const trip = body.trip;
		els.detailSubtitle.textContent = (trip.userId || '') + ' · ' + fmtDateTime(trip.startsAtUtc) + ' – ' + fmtDateTime(trip.endsAtUtc) + (trip.purpose ? ' · ' + trip.purpose : '');
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
		els.linkedSummary.textContent = linked.length === 1
			? t('One working-time entry is linked.')
			: t('%n working-time entries are linked.').replace('%n', String(linked.length));

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
		els.approve.disabled = !body.snapshotToken;
		current = { trip, snapshotToken: body.snapshotToken };
	}

	async function openDetail(id) {
		els.detail.hidden = false;
		els.error.hidden = true;
		els.conflict.hidden = true;
		els.rejectGroup.hidden = true;
		els.rejectConfirm.hidden = true;
		els.detailStatusMsg.textContent = t('Loading…');
		try {
			const body = await call(tpl('detailTemplate', id));
			renderDetail(body);
			els.detailStatusMsg.textContent = '';
		} catch (e) {
			els.detailStatusMsg.textContent = '';
			showError(e.message);
		}
	}

	els.approve.addEventListener('click', async () => {
		if (!current) return;
		els.approve.disabled = true;
		try {
			const body = await call(tpl('approveTemplate', current.trip.id), {
				method: 'POST',
				json: { revision: current.trip.revision, snapshotToken: current.snapshotToken },
			});
			renderDetail({ ...body, segments: [], linkedTimeEntryIds: [], days: [], snapshotToken: null, trip: body.trip });
			els.detailStatusMsg.textContent = t('Approved — the top-up was booked to the time account.');
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
		els.rejectGroup.hidden = false;
		els.rejectConfirm.hidden = false;
		els.rejectReason.focus();
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
			const body = await call(tpl('rejectTemplate', current.trip.id), {
				method: 'POST',
				json: { revision: current.trip.revision, reason },
			});
			renderDetail({ ...body, segments: [], linkedTimeEntryIds: [], days: [], snapshotToken: null, trip: body.trip });
			els.detailStatusMsg.textContent = t('Rejected — the employee was informed.');
		} catch (e) {
			if (e.status === 409) {
				els.conflict.hidden = false;
				await openDetail(current.trip.id);
			} else {
				showError(e.message);
			}
		} finally {
			els.rejectConfirm.disabled = false;
		}
	});

	els.back.addEventListener('click', () => {
		els.detail.hidden = true;
		current = null;
		loadQueue();
	});

	loadQueue();
})();
