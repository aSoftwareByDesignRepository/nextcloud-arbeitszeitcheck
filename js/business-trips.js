/**
 * Employee business trips — list, guided create, and detail editor.
 *
 * All mutations carry the current `revision` (CAS). A 409 response shows
 * the conflict callout and keeps the entered values — never an auto-merge.
 * Time-entry links toggle via POST/DELETE on the links collection; only
 * draft trips can be edited, submitted/approved trips are read-only
 * with export links.
 */
(function () {
	'use strict';

	const root = document.getElementById('bt-root');
	if (!root || root.dataset.enabled !== '1') {
		return;
	}

	const t = (text) => (typeof window.t === 'function' ? window.t('arbeitszeitcheck', text) : text);
	const api = window.AzcApi;
	const $ = (id) => document.getElementById(id);

	const els = {
		listView: $('bt-list-view'),
		createView: $('bt-create-view'),
		detailView: $('bt-detail-view'),
		listStatus: $('bt-list-status'),
		tripList: $('bt-trip-list'),
		empty: $('bt-empty'),
		newTrip: $('bt-new-trip'),
		emptyCreate: $('bt-empty-create'),
		createForm: $('bt-create-form'),
		createTitle: $('bt-create-title'),
		createLead: $('bt-create-lead'),
		createSubmit: $('bt-create-submit'),
		createError: $('bt-create-error'),
		createErrorText: $('bt-create-error-text'),
		createCancel: $('bt-create-cancel'),
		start: $('bt-start'),
		end: $('bt-end'),
		originTz: $('bt-origin-tz'),
		destTz: $('bt-destination-tz'),
		purpose: $('bt-purpose'),
		detailTitle: $('bt-detail-title'),
		detailSubtitle: $('bt-detail-subtitle'),
		detailStatus: $('bt-detail-status'),
		detailStatusMsg: $('bt-detail-status-msg'),
		detailError: $('bt-detail-error'),
		detailErrorText: $('bt-detail-error-text'),
		conflict: $('bt-conflict'),
		conflictReload: $('bt-conflict-reload'),
		segmentList: $('bt-segment-list'),
		segmentForm: $('bt-segment-form'),
		segStart: $('bt-seg-start'),
		segEnd: $('bt-seg-end'),
		segKind: $('bt-seg-kind'),
		segMode: $('bt-seg-mode'),
		segError: $('bt-seg-error'),
		entrySuggestions: $('bt-entry-suggestions'),
		linksStatus: $('bt-links-status'),
		linksStep: $('bt-links-step'),
		sumRecorded: $('bt-sum-recorded'),
		sumWork: $('bt-sum-work'),
		sumTopup: $('bt-sum-topup'),
		dayDetails: $('bt-day-details'),
		dayRows: $('bt-day-rows'),
		warnings: $('bt-warnings'),
		back: $('bt-back'),
		editDraft: $('bt-edit-draft'),
		refreshPreview: $('bt-refresh-preview'),
		exportCsv: $('bt-export-csv'),
		exportPdf: $('bt-export-pdf'),
		cancelTrip: $('bt-cancel-trip'),
		deleteDraft: $('bt-delete-draft'),
		submit: $('bt-submit'),
	};

	const STATUS_LABELS = {
		draft: t('Draft — not yet submitted'),
		submitted: t('Submitted'),
		approved: t('Approved'),
		rejected: t('Rejected'),
		cancelled: t('Withdrawn'),
	};

	let trip = null; // current detail trip {id,status,revision,...}
	let segments = []; // working copy of the segment list
	let linkedIds = new Set();
	let requestKey = null;
	let editing = false; // create form acts as draft-header editor

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

	function setFieldError(input, errorEl, message) {
		if (!input || !errorEl) return;
		if (message) {
			input.setAttribute('aria-invalid', 'true');
			errorEl.textContent = message;
			errorEl.hidden = false;
		} else {
			input.removeAttribute('aria-invalid');
			errorEl.textContent = '';
			errorEl.hidden = true;
		}
	}

	function clearCreateErrors() {
		els.createError.hidden = true;
		['start', 'end', 'originTz', 'destTz', 'purpose'].forEach((k) => {
			setFieldError(els[k], $(els[k].id + '-error'), null);
		});
	}

	function showCreateError(message, fields) {
		clearCreateErrors();
		if (fields && typeof fields === 'object') {
			const map = { startsAtUtc: 'start', endsAtUtc: 'end', originTimezone: 'originTz', destinationTimezone: 'destTz', purpose: 'purpose' };
			Object.entries(fields).forEach(([name, code]) => {
				const key = map[name];
				if (key) {
					setFieldError(els[key], $(els[key].id + '-error'), t('Please check this field.'));
				}
			});
		}
		els.createErrorText.textContent = message;
		els.createError.hidden = false;
	}

	function showDetailError(message, fields) {
		els.detailErrorText.textContent = message;
		els.detailError.hidden = false;
		if (fields && typeof fields === 'object') {
			Object.values(fields).forEach((code) => {
				if (code === 'stale' || code === 'required') {
					els.conflict.hidden = false;
				}
			});
		}
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

	function toLocalInput(iso) {
		const d = new Date(iso);
		if (Number.isNaN(d.getTime())) return '';
		const pad = (n) => String(n).padStart(2, '0');
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}

	function show(view) {
		els.listView.hidden = view !== 'list';
		els.createView.hidden = view !== 'create';
		els.detailView.hidden = view !== 'detail';
		if (view === 'list') {
			els.newTrip.focus();
		}
	}

	// ---------- time zones ----------

	function fillTimeZones() {
		let zones = [];
		try {
			zones = Intl.supportedValuesOf('timeZone');
		} catch (e) {
			zones = ['Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich', 'UTC'];
		}
		const local = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
		[els.originTz, els.destTz].forEach((sel) => {
			zones.forEach((z) => {
				const opt = document.createElement('option');
				opt.value = z;
				opt.textContent = z.replace(/_/g, ' ');
				sel.appendChild(opt);
			});
			sel.value = local;
		});
	}

	// ---------- list ----------

	async function loadList() {
		els.listStatus.textContent = t('Loading…');
		try {
			const body = await call(root.dataset.listUrl);
			const items = body.items || [];
			els.tripList.replaceChildren();
			els.empty.hidden = items.length !== 0;
			items.forEach((item) => {
				const li = document.createElement('li');
				li.className = 'azc-list__item bt-trip-item';
				const link = document.createElement('a');
				link.className = 'bt-trip-item__link';
				link.href = tpl('detailPageTemplate', item.id);
				const title = document.createElement('span');
				title.className = 'bt-trip-item__title';
				title.textContent = fmtDateTime(item.startsAtUtc) + ' – ' + fmtDateTime(item.endsAtUtc);
				const sub = document.createElement('span');
				sub.className = 'bt-trip-item__sub';
				sub.textContent = item.purpose || '';
				const chip = document.createElement('span');
				chip.className = 'azc-status-chip';
				chip.dataset.status = item.status;
				chip.textContent = STATUS_LABELS[item.status] || item.status;
				link.append(title, sub);
				li.append(link, chip);
				els.tripList.appendChild(li);
			});
			els.listStatus.textContent = items.length === 0 ? '' : '';
		} catch (e) {
			els.listStatus.textContent = e.message;
		}
	}

	// ---------- create ----------

	function isoFromLocal(value) {
		if (!value) return null;
		const d = new Date(value);
		return Number.isNaN(d.getTime()) ? null : d.toISOString();
	}

	function openCreateForm() {
		editing = false;
		requestKey = null;
		clearCreateErrors();
		els.createForm.reset();
		els.start.value = '';
		els.end.value = '';
		els.purpose.value = '';
		const local = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
		els.originTz.value = local;
		els.destTz.value = local;
		els.createTitle.textContent = t('Record business trip');
		els.createLead.textContent = t('When does the trip start and end? You add travel blocks and working times on the next step.');
		els.createSubmit.textContent = t('Continue');
		show('create');
		els.start.focus();
	}

	function openEditForm() {
		if (!trip || trip.status !== 'draft') return;
		editing = true;
		clearCreateErrors();
		els.start.value = toLocalInput(trip.startsAtUtc);
		els.end.value = toLocalInput(trip.endsAtUtc);
		// A stored zone may be absent from Intl.supportedValuesOf — add it so
		// the select serializes the real value instead of silently resetting.
		[[els.originTz, trip.originTimezone], [els.destTz, trip.destinationTimezone]].forEach(([sel, tz]) => {
			if (tz && !Array.from(sel.options).some((o) => o.value === tz)) {
				const opt = document.createElement('option');
				opt.value = tz;
				opt.textContent = tz.replace(/_/g, ' ');
				sel.appendChild(opt);
			}
			if (tz) sel.value = tz;
		});
		els.purpose.value = trip.purpose || '';
		els.createTitle.textContent = t('Edit trip details');
		els.createLead.textContent = t('Change the dates, time zones or purpose of this draft. Travel blocks and linked working times stay as they are.');
		els.createSubmit.textContent = t('Save changes');
		show('create');
		els.start.focus();
	}

	els.createForm.addEventListener('submit', async (ev) => {
		ev.preventDefault();
		clearCreateErrors();
		const startsAt = isoFromLocal(els.start.value);
		const endsAt = isoFromLocal(els.end.value);
		let valid = true;
		if (!startsAt) { setFieldError(els.start, $('bt-start-error'), t('Please pick a start.')); valid = false; }
		if (!endsAt) { setFieldError(els.end, $('bt-end-error'), t('Please pick an end.')); valid = false; }
		if (startsAt && endsAt && endsAt <= startsAt) {
			setFieldError(els.end, $('bt-end-error'), t('The end must be after the start.'));
			valid = false;
		}
		if (els.purpose.value.length > 200) {
			setFieldError(els.purpose, $('bt-purpose-error'), t('Maximum 200 characters.'));
			valid = false;
		}
		if (!valid) return;

		const payload = {
			startsAtUtc: startsAt,
			endsAtUtc: endsAt,
			originTimezone: els.originTz.value,
			destinationTimezone: els.destTz.value,
			// Edits keep the stored evaluation zone — it is policy-resolved at
			// creation and has no UI control.
			evaluationTimezone: editing && trip && trip.evaluationTimezone ? trip.evaluationTimezone : els.originTz.value,
			purpose: els.purpose.value.trim() || null,
		};

		if (editing) {
			if (!trip || trip.status !== 'draft') return;
			payload.revision = trip.revision;
			els.createSubmit.disabled = true;
			try {
				const body = await call(tpl('updateTemplate', trip.id), { method: 'PUT', json: payload });
				editing = false;
				await openDetail(body.trip.id);
			} catch (e) {
				if (e.status === 409) {
					els.conflict.hidden = false;
				}
				showCreateError(e.message, e.fields);
			} finally {
				els.createSubmit.disabled = false;
			}
			return;
		}

		if (!requestKey) {
			requestKey = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'bt-' + Date.now() + '-' + Math.random().toString(36).slice(2);
		}
		payload.requestKey = requestKey;
		try {
			const body = await call(root.dataset.createUrl, {
				method: 'POST',
				json: payload,
			});
			requestKey = null;
			history.pushState({}, '', tpl('detailPageTemplate', body.trip.id));
			await openDetail(body.trip.id);
		} catch (e) {
			showCreateError(e.message, e.fields);
		}
	});

	els.newTrip.addEventListener('click', openCreateForm);
	els.emptyCreate.addEventListener('click', openCreateForm);
	els.createCancel.addEventListener('click', () => {
		if (editing && trip) {
			editing = false;
			show('detail');
			return;
		}
		show('list');
	});
	els.editDraft.addEventListener('click', openEditForm);

	// ---------- detail ----------

	function renderSegments() {
		els.segmentList.replaceChildren();
		const kindLabels = {
			active_travel: t('Self-driven travel'),
			passive_travel: t('Passenger / transported'),
			work_during_travel: t('Worked while travelling'),
			rest_personal: t('Rest / private'),
			unclassified: t('Not yet classified'),
		};
		segments
			.slice()
			.sort((a, b) => new Date(a.startsAtUtc) - new Date(b.startsAtUtc))
			.forEach((seg, idx) => {
				const li = document.createElement('li');
				li.className = 'azc-list__item bt-segment';
				const text = document.createElement('span');
				text.className = 'bt-segment__text';
				text.textContent = fmtDateTime(seg.startsAtUtc) + ' – ' + fmtDateTime(seg.endsAtUtc) + ' · ' + (kindLabels[seg.segmentType] || seg.segmentType);
				li.appendChild(text);
				if (trip && trip.status === 'draft') {
					const remove = document.createElement('button');
					remove.type = 'button';
					remove.className = 'azc-btn azc-btn--icon';
					remove.textContent = t('Remove');
					remove.addEventListener('click', () => {
						segments.splice(segments.indexOf(seg), 1);
						saveSegments();
					});
					li.appendChild(remove);
				}
				els.segmentList.appendChild(li);
			});
		if (segments.length === 0) {
			const li = document.createElement('li');
			li.className = 'azc-list__item';
			li.textContent = t('No travel blocks yet.');
			els.segmentList.appendChild(li);
		}
	}

	async function saveSegments() {
		if (!trip) return;
		try {
			const body = await call(tpl('segmentsTemplate', trip.id), {
				method: 'PUT',
				json: { revision: trip.revision, segments },
			});
			trip = body.trip;
			renderSegments();
			await refreshPreview();
		} catch (e) {
			if (e.status === 409) {
				els.conflict.hidden = false;
			}
			els.segError.textContent = e.message;
			els.segError.hidden = false;
		}
	}

	els.segmentForm.addEventListener('submit', (ev) => {
		ev.preventDefault();
		els.segError.hidden = true;
		const s = isoFromLocal(els.segStart.value);
		const e2 = isoFromLocal(els.segEnd.value);
		if (!s || !e2 || e2 <= s) {
			els.segError.textContent = t('Please enter a valid start and end.');
			els.segError.hidden = false;
			return;
		}
		// Local overlap check before hitting the server.
		const overlap = segments.some((seg) => new Date(seg.startsAtUtc) < new Date(e2) && new Date(seg.endsAtUtc) > new Date(s));
		if (overlap) {
			els.segError.textContent = t('This block overlaps another travel block.');
			els.segError.hidden = false;
			return;
		}
		segments.push({
			startsAtUtc: s,
			endsAtUtc: e2,
			segmentType: els.segKind.value,
			transportMode: els.segMode.value || null,
		});
		els.segStart.value = '';
		els.segEnd.value = '';
		saveSegments();
	});

	async function loadSuggestions() {
		if (!trip) return;
		els.linksStatus.textContent = t('Loading…');
		els.entrySuggestions.replaceChildren();
		try {
			const start = trip.startsAtUtc.slice(0, 10);
			const end = trip.endsAtUtc.slice(0, 10);
			const url = root.dataset.timeEntriesUrl + '?start_date=' + encodeURIComponent(start) + '&end_date=' + encodeURIComponent(end) + '&limit=200';
			const body = await call(url);
			const entries = (body.entries || []).filter((en) => {
				if (!en.startTime || !en.endTime) return false;
				return new Date(en.startTime) < new Date(trip.endsAtUtc) && new Date(en.endTime) > new Date(trip.startsAtUtc);
			});
			if (entries.length === 0) {
				els.linksStatus.textContent = t('No working times found in the trip window.');
				return;
			}
			const editable = trip.status === 'draft';
			entries.forEach((en) => {
				const li = document.createElement('li');
				li.className = 'azc-list__item bt-suggestion';
				const label = document.createElement('label');
				label.className = 'bt-suggestion__label';
				const cb = document.createElement('input');
				cb.type = 'checkbox';
				cb.checked = linkedIds.has(en.id);
				cb.disabled = !editable;
				const span = document.createElement('span');
				const hours = en.workingDurationHours != null ? ' · ' + Number(en.workingDurationHours).toFixed(2) + ' h' : '';
				span.textContent = fmtDateTime(en.startTime) + ' – ' + fmtDateTime(en.endTime) + hours + (en.description ? ' · ' + en.description : '');
				cb.addEventListener('change', async () => {
					cb.disabled = true;
					try {
						if (cb.checked) {
							await call(tpl('linkTemplate', trip.id), { method: 'POST', json: { timeEntryId: en.id } });
							linkedIds.add(en.id);
						} else {
							await call(tpl('linkTemplate', trip.id) + '/' + en.id, { method: 'DELETE' });
							linkedIds.delete(en.id);
						}
						await refreshPreview();
					} catch (e) {
						cb.checked = !cb.checked;
						if (e.status === 409 || (e.fields && e.fields.timeEntryId === 'already_linked')) {
							els.linksStatus.textContent = e.message;
							await openDetail(trip.id);
						} else {
							els.linksStatus.textContent = e.message;
						}
					} finally {
						cb.disabled = !editable;
					}
				});
				label.append(cb, span);
				li.appendChild(label);
				els.entrySuggestions.appendChild(li);
			});
			els.linksStatus.textContent = '';
		} catch (e) {
			els.linksStatus.textContent = e.message;
		}
	}

	function renderPreview(result) {
		if (!result) {
			els.sumRecorded.textContent = '—';
			els.sumWork.textContent = '—';
			els.sumTopup.textContent = '—';
			return;
		}
		const days = result.days || [];
		const totalPresence = days.reduce((acc, d) => acc + (d.presenceMinutes || 0), 0);
		const totalWork = days.reduce((acc, d) => acc + (d.actualWorkMinutes || 0), 0);
		const totalTopup = days.reduce((acc, d) => acc + (d.accountTopUpMinutes || 0), 0);
		els.sumRecorded.textContent = fmtDuration(totalPresence);
		els.sumWork.textContent = fmtDuration(totalWork);
		els.sumTopup.textContent = fmtDuration(totalTopup);

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
		els.dayDetails.hidden = days.length === 0;

		els.warnings.replaceChildren();
		(result.warnings || []).forEach((w) => {
			const li = document.createElement('li');
			li.className = 'azc-list__item bt-warning';
			li.textContent = w === 'unclassified_time_present'
				? t('Some travel time is not classified yet — please describe what it was.')
				: w;
			els.warnings.appendChild(li);
		});
	}

	async function refreshPreview() {
		if (!trip || trip.status !== 'draft') {
			return;
		}
		try {
			const body = await call(tpl('previewTemplate', trip.id), { method: 'POST' });
			renderPreview(body);
			els.submit.disabled = false;
		} catch (e) {
			els.submit.disabled = true;
			if (e.status === 409) {
				els.conflict.hidden = false;
			}
			els.detailErrorText.textContent = e.message;
			els.detailError.hidden = false;
		}
	}

	function applyStatus() {
		if (!trip) return;
		const chip = els.detailStatus;
		chip.dataset.status = trip.status;
		chip.textContent = STATUS_LABELS[trip.status] || trip.status;
		const editable = trip.status === 'draft';
		els.segmentForm.hidden = !editable;
		els.submit.hidden = !editable;
		els.deleteDraft.hidden = !editable;
		els.editDraft.hidden = !editable;
		els.cancelTrip.hidden = !(trip.status === 'draft' || trip.status === 'submitted' || trip.status === 'approved');
		const exportable = trip.status === 'submitted' || trip.status === 'approved';
		els.exportCsv.hidden = !exportable;
		els.exportPdf.hidden = !exportable;
		if (exportable) {
			els.exportCsv.href = tpl('exportCsvTemplate', trip.id);
			els.exportPdf.href = tpl('exportPdfTemplate', trip.id);
		}
		els.refreshPreview.hidden = !editable;
		if (trip.status === 'rejected') {
			els.detailStatusMsg.textContent = t('This trip was rejected. Withdraw it and record a new one if needed.');
		}
	}

	async function openDetail(id) {
		editing = false;
		show('detail');
		els.detailError.hidden = true;
		els.conflict.hidden = true;
		els.detailStatusMsg.textContent = t('Loading…');
		try {
			const body = await call(tpl('tripTemplate', id));
			trip = body.trip;
			segments = body.segments || [];
			linkedIds = new Set((body.linkedTimeEntryIds || []).map(Number));
			els.detailSubtitle.textContent = fmtDateTime(trip.startsAtUtc) + ' – ' + fmtDateTime(trip.endsAtUtc) + (trip.purpose ? ' · ' + trip.purpose : '');
			els.detailStatusMsg.textContent = '';
			renderSegments();
			applyStatus();
			renderPreview({ days: body.days || [], warnings: [] });
			await Promise.all([loadSuggestions(), refreshPreview()]);
		} catch (e) {
			els.detailStatusMsg.textContent = '';
			showDetailError(e.message, e.fields);
		}
	}

	els.refreshPreview.addEventListener('click', refreshPreview);
	els.conflictReload.addEventListener('click', () => { if (trip) openDetail(trip.id); });
	els.back.addEventListener('click', () => {
		history.pushState({}, '', root.dataset.listPageUrl);
		trip = null;
		show('list');
		loadList();
	});

	els.submit.addEventListener('click', async () => {
		if (!trip) return;
		els.submit.disabled = true;
		try {
			const body = await call(tpl('submitTemplate', trip.id), { method: 'POST', json: { revision: trip.revision } });
			trip = body.trip;
			applyStatus();
			els.detailStatusMsg.textContent = t('Submitted — your manager was asked to review the trip.');
		} catch (e) {
			els.submit.disabled = false;
			showDetailError(e.message, e.fields);
		}
	});

	els.cancelTrip.addEventListener('click', async () => {
		if (!trip) return;
		try {
			const body = await call(tpl('cancelTemplate', trip.id), { method: 'POST', json: { revision: trip.revision } });
			trip = body.trip;
			applyStatus();
		} catch (e) {
			showDetailError(e.message, e.fields);
		}
	});

	els.deleteDraft.addEventListener('click', async () => {
		if (!trip) return;
		try {
			await call(tpl('deleteTemplate', trip.id) + '?revision=' + trip.revision, { method: 'DELETE' });
			history.pushState({}, '', root.dataset.listPageUrl);
			trip = null;
			show('list');
			loadList();
		} catch (e) {
			showDetailError(e.message, e.fields);
		}
	});

	// ---------- boot ----------

	fillTimeZones();
	const initialId = parseInt(root.dataset.initialTripId || '0', 10);
	if (initialId > 0) {
		loadList().then(() => openDetail(initialId));
	} else {
		loadList();
	}
})();
