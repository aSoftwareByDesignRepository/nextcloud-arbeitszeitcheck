/**
 * Admin business-trip rules — guided rule builder (spec §7.4).
 *
 * The UI never exposes raw JSON: guided fields compose the schema-
 * validated config document client-side and the server re-validates it.
 * Active rule sets are frozen — edits always produce a new draft
 * version; activation is an explicit, irreversible step.
 */
(function () {
	'use strict';

	const root = document.getElementById('bta-root');
	if (!root) return;

	const t = (text) => (typeof window.t === 'function' ? window.t('arbeitszeitcheck', text) : text);
	const api = window.AzcApi;
	const $ = (id) => document.getElementById(id);

	const DAY_BASES = [
		['trip_presence', t('Total absence on that day')],
		['travel_duration', t('Travel time only')],
		['actual_work', t('Actual work done')],
		['active_plus_work', t('Driving time plus work done')],
		['classified_total', t('Legally classified time')],
	];

	const els = {
		setList: $('bta-set-list'),
		setsStatus: $('bta-sets-status'),
		setsEmpty: $('bta-sets-empty'),
		newSet: $('bta-new-set'),
		builder: $('bta-builder'),
		builderForm: $('bta-builder-form'),
		builderTitle: $('bta-builder-title'),
		builderError: $('bta-builder-error'),
		builderErrorText: $('bta-builder-error-text'),
		builderClose: $('bta-builder-close'),
		setId: $('bta-set-id'),
		code: $('bta-code'),
		name: $('bta-name'),
		jurisdiction: $('bta-jurisdiction'),
		validFrom: $('bta-valid-from'),
		validTo: $('bta-valid-to'),
		dayBasis: $('bta-day-basis'),
		kindFirst: $('bta-kind-first'),
		kindMiddle: $('bta-kind-middle'),
		kindLast: $('bta-kind-last'),
		kindSingle: $('bta-kind-single'),
		thresholdRows: $('bta-threshold-rows'),
		thresholdError: $('bta-threshold-error'),
		addThreshold: $('bta-add-threshold'),
		operation: $('bta-operation'),
		operationHint: $('bta-operation-hint'),
		cap: $('bta-cap'),
		fWork: $('bta-f-work'),
		fActive: $('bta-f-active'),
		fPassive: $('bta-f-passive'),
		fDuring: $('bta-f-during'),
		roundMode: $('bta-round-mode'),
		roundInc: $('bta-round-inc'),
		reqClassification: $('bta-req-classification'),
		activeReleased: $('bta-active-released'),
		passiveReleased: $('bta-passive-released'),
		evalTz: $('bta-eval-tz'),
		previewBasis: $('bta-preview-basis'),
		previewStatus: $('bta-preview-status'),
		previewRows: $('bta-preview-rows'),
		save: $('bta-save'),
		activate: $('bta-activate'),
		newAssignment: $('bta-new-assignment'),
		assignmentForm: $('bta-assignment-form'),
		assignmentCancel: $('bta-assignment-cancel'),
		aRuleSet: $('bta-a-ruleset'),
		aScope: $('bta-a-scope'),
		aTarget: $('bta-a-target'),
		aUserWrap: $('bta-a-user-wrap'),
		aUser: $('bta-a-user'),
		aFrom: $('bta-a-from'),
		aTo: $('bta-a-to'),
		aPriority: $('bta-a-priority'),
		assignError: $('bta-assign-error'),
		assignErrorText: $('bta-assign-error-text'),
		assignStatus: $('bta-assign-status'),
		assignmentList: $('bta-assignment-list'),
		assignEmpty: $('bta-assign-empty'),
		profileForm: $('bta-profile-form'),
		profileError: $('bta-profile-error'),
		profileErrorText: $('bta-profile-error-text'),
		profileStatus: $('bta-profile-status'),
		pUser: $('bta-p-user'),
		pMinutes: $('bta-p-minutes'),
		pFrom: $('bta-p-from'),
		rolloutStatus: $('bta-rollout-status'),
		rolloutChecks: $('bta-rollout-checks'),
		enable: $('bta-enable'),
		disableLegacy: $('bta-disable-legacy'),
		presets: $('bta-presets'),
	};

	// The rollout card renders even while the feature is off — readiness is
	// exactly what an admin needs before enabling or cutting over.
	initRollout();
	if (root.dataset.enabled !== '1') return;

	let ruleSets = [];
	let assignments = [];
	let scopeData = { models: [], teams: [], cohorts: [] };
	let editingSet = null; // rule set being edited (draft) or null for new

	function tpl(name, id) {
		return root.dataset[name].replace('__ID__', encodeURIComponent(String(id)));
	}

	async function call(url, options) {
		const res = await api.fetch(url, options);
		if (!res.ok || !api.isApiSuccess(res)) {
			const err = new Error(res.error || t('The request could not be completed.'));
			err.status = res.status;
			err.fields = res.data && res.data.fields ? res.data.fields : {};
			err.data = res.data || {};
			throw err;
		}
		return res.data;
	}

	async function confirmStep(options) {
		const c = window.AzcComponents || window.ArbeitszeitCheckComponents;
		if (!c || typeof c.confirmDialog !== 'function') {
			els.rolloutStatus && (els.rolloutStatus.textContent = t('Could not open the confirmation. Please reload the page and try again.'));
			return false;
		}
		const result = await c.confirmDialog(options);
		return result === true || (result && result.confirmed === true);
	}

	function hm(minutes) {
		const m = Number(minutes) || 0;
		return Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0');
	}

	function parseHm(value) {
		const m = /^(\d{1,3}):([0-5]?\d)$/.exec(String(value || '').trim());
		if (!m) return null;
		const total = parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
		return total <= 1440 ? total : null;
	}

	function showError(el, textEl, message) {
		textEl.textContent = message;
		el.hidden = false;
	}

	// ---------- rule set list ----------

	function basisLabel(key) {
		const row = DAY_BASES.find(([k]) => k === key);
		return row ? row[1] : key;
	}

	function summarize(config) {
		try {
			const c = typeof config === 'string' ? JSON.parse(config) : config;
			const tiers = (c.thresholds || []).map((th) => hm(th.fromMinutes) + '→' + hm(th.targetMinutes)).join(', ');
			return basisLabel(c.dayBasis) + ' · ' + (c.operation || '') + ' · ' + tiers;
		} catch (e) {
			return '';
		}
	}

	function renderSets() {
		els.setList.replaceChildren();
		els.setsEmpty.hidden = ruleSets.length !== 0;
		ruleSets.forEach((set) => {
			const li = document.createElement('li');
			li.className = 'azc-list__item bt-trip-item';
			const main = document.createElement('div');
			main.className = 'bt-trip-item__main';
			const title = document.createElement('span');
			title.className = 'bt-trip-item__title';
			title.textContent = set.name + ' (' + set.code + ' v' + set.version + ')';
			const sub = document.createElement('span');
			sub.className = 'bt-trip-item__sub';
			sub.textContent = summarize(set.configJson);
			main.append(title, sub);
			if (set.sourceTitle) {
				const src = document.createElement('span');
				src.className = 'bt-trip-item__sub';
				src.textContent = set.sourceTitle;
				main.appendChild(src);
			}
			const chip = document.createElement('span');
			chip.className = 'azc-status-chip';
			chip.dataset.status = set.status === 'active' ? 'approved' : 'draft';
			chip.textContent = set.status === 'active' ? t('Active') : (set.status === 'retired' ? t('Retired') : t('Draft'));
			const actions = document.createElement('span');
			actions.className = 'bt-trip-item__actions';
			// Legal review gate (spec §10.5): drafts show review state and a
			// "mark reviewed" action; unreviewed drafts cannot be activated.
			if (set.status === 'draft') {
				const review = document.createElement('span');
				review.className = 'azc-status-chip';
				review.dataset.status = set.reviewedAt ? 'approved' : 'pending';
				review.textContent = set.reviewedAt ? t('Reviewed') : t('Review pending');
				actions.appendChild(review);
				if (!set.reviewedAt) {
					const mark = document.createElement('button');
					mark.type = 'button';
					mark.className = 'azc-btn azc-btn--secondary';
					mark.textContent = t('Mark legally reviewed');
					mark.addEventListener('click', async () => {
						const reviewed = await confirmStep({
							title: t('Confirm legal review'),
							message: t('Confirm that this exact configuration has completed the documented legal review. Editing the draft afterwards requires a new review.'),
							confirmLabel: t('Mark reviewed'),
							cancelLabel: t('Cancel'),
							variant: 'info',
						});
						if (!reviewed) {
							return;
						}
						mark.disabled = true;
						try {
							await call(tpl('markReviewedTemplate', set.id), {
								method: 'POST',
								json: { configHash: set.configHash || '' },
							});
							await loadSets();
						} catch (e) {
							els.setsStatus.textContent = e.message;
							mark.disabled = false;
						}
					});
					actions.appendChild(mark);
				}
			}
			const edit = document.createElement('button');
			edit.type = 'button';
			edit.className = 'azc-btn azc-btn--secondary';
			edit.textContent = set.status === 'active' ? t('New version') : t('Edit');
			edit.addEventListener('click', () => openBuilder(set));
			actions.appendChild(edit);
			li.append(main, chip, actions);
			els.setList.appendChild(li);
		});
	}

	async function loadSets() {
		els.setsStatus.textContent = t('Loading…');
		try {
			const body = await call(root.dataset.ruleSetsUrl);
			ruleSets = body.items || [];
			renderSets();
			els.setsStatus.textContent = '';
		} catch (e) {
			els.setsStatus.textContent = e.message;
		}
	}

	// ---------- guided builder ----------

	function fillKindSelects() {
		[els.kindFirst, els.kindMiddle, els.kindLast, els.kindSingle].forEach((sel) => {
			while (sel.options.length > 1) sel.remove(1);
			DAY_BASES.forEach(([value, label]) => {
				const opt = document.createElement('option');
				opt.value = value;
				opt.textContent = label;
				sel.appendChild(opt);
			});
		});
	}

	function thresholdRow(from, target) {
		const tr = document.createElement('tr');
		const tdFrom = document.createElement('td');
		const tdTarget = document.createElement('td');
		const tdActions = document.createElement('td');
		const inFrom = document.createElement('input');
		inFrom.type = 'text';
		inFrom.className = 'bta-hm';
		inFrom.inputMode = 'numeric';
		inFrom.placeholder = '10:00';
		inFrom.value = hm(from);
		inFrom.setAttribute('aria-label', t('From (h:mm)'));
		const inTarget = document.createElement('input');
		inTarget.type = 'text';
		inTarget.className = 'bta-hm';
		inTarget.inputMode = 'numeric';
		inTarget.placeholder = '9:00';
		inTarget.value = hm(target);
		inTarget.setAttribute('aria-label', t('Credits (h:mm)'));
		const del = document.createElement('button');
		del.type = 'button';
		del.className = 'azc-btn azc-btn--icon';
		del.textContent = t('Remove');
		del.addEventListener('click', () => { tr.remove(); runPreview(); });
		inFrom.addEventListener('change', runPreview);
		inTarget.addEventListener('change', runPreview);
		tdFrom.appendChild(inFrom);
		tdTarget.appendChild(inTarget);
		tdActions.appendChild(del);
		tr.append(tdFrom, tdTarget, tdActions);
		return tr;
	}

	function readConfig() {
		const thresholds = [];
		let error = null;
		els.thresholdRows.querySelectorAll('tr').forEach((tr) => {
			const [inFrom, inTarget] = tr.querySelectorAll('input.bta-hm');
			const f = parseHm(inFrom.value);
			const g = parseHm(inTarget.value);
			if (f === null || g === null) {
				error = t('Every tier needs a “from” and a “credits” value as h:mm.');
				return;
			}
			thresholds.push({ fromMinutes: f, targetMinutes: g });
		});
		if (error) return { error };
		thresholds.sort((a, b) => a.fromMinutes - b.fromMinutes);
		if (thresholds.length === 0) {
			return { error: t('Add at least one tier.') };
		}
		if (thresholds[0].fromMinutes !== 0) {
			return { error: t('The first tier must start at 0:00.') };
		}
		for (let i = 1; i < thresholds.length; i++) {
			if (thresholds[i].fromMinutes <= thresholds[i - 1].fromMinutes) {
				return { error: t('Tiers must be strictly increasing.') };
			}
		}
		const dayKinds = {};
		[['first', els.kindFirst], ['middle', els.kindMiddle], ['last', els.kindLast], ['single', els.kindSingle]].forEach(([k, sel]) => {
			if (sel.value) dayKinds[k] = sel.value;
		});
		const cap = els.cap.value === '' ? null : parseInt(els.cap.value, 10);
		const config = {
			schemaVersion: 1,
			evaluationTimezone: els.evalTz.value,
			dayBasis: els.dayBasis.value,
			thresholds,
			operation: els.operation.value,
			accountTreatment: {
				actualWorkFactorBps: Math.round((parseFloat(els.fWork.value) || 0) * 100),
				activeTravelFactorBps: Math.round((parseFloat(els.fActive.value) || 0) * 100),
				passiveTravelFactorBps: Math.round((parseFloat(els.fPassive.value) || 0) * 100),
				workDuringTravelFactorBps: Math.round((parseFloat(els.fDuring.value) || 0) * 100),
			},
			rounding: {
				mode: els.roundMode.value,
				resultIncrement: parseInt(els.roundInc.value, 10),
			},
			requiresClassification: els.reqClassification.checked,
			activeTravelLegallyReleased: els.activeReleased.checked,
			passiveTravelLegallyReleased: els.passiveReleased.checked,
		};
		if (Object.keys(dayKinds).length > 0) config.dayKinds = dayKinds;
		if (cap !== null && !Number.isNaN(cap)) config.dailyTargetCapMinutes = cap;
		return { config };
	}

	function fillBuilder(set) {
		editingSet = set;
		els.setId.value = set ? set.id : '';
		els.code.value = set ? set.code : '';
		els.code.disabled = !!set; // immutable after creation
		els.name.value = set ? set.name : '';
		els.jurisdiction.value = set ? (set.jurisdiction || 'generic') : 'generic';
		els.validFrom.value = set && set.validFrom ? set.validFrom : '';
		els.validTo.value = set && set.validTo ? set.validTo : '';

		let config = null;
		if (set) {
			try { config = JSON.parse(set.configJson); } catch (e) { config = null; }
		}
		els.dayBasis.value = config?.dayBasis || 'trip_presence';
		[els.kindFirst, els.kindMiddle, els.kindLast, els.kindSingle].forEach((sel) => { sel.value = ''; });
		if (config?.dayKinds) {
			els.kindFirst.value = config.dayKinds.first || '';
			els.kindMiddle.value = config.dayKinds.middle || '';
			els.kindLast.value = config.dayKinds.last || '';
			els.kindSingle.value = config.dayKinds.single || '';
		}
		els.thresholdRows.replaceChildren();
		const tiers = config?.thresholds || [{ fromMinutes: 0, targetMinutes: 0 }, { fromMinutes: 600, targetMinutes: 540 }];
		tiers.forEach((th) => els.thresholdRows.appendChild(thresholdRow(th.fromMinutes, th.targetMinutes)));
		els.operation.value = config?.operation || 'minimum';
		updateOperationHint();
		els.cap.value = config?.dailyTargetCapMinutes != null ? String(config.dailyTargetCapMinutes) : '';
		const at = config?.accountTreatment || {};
		els.fWork.value = at.actualWorkFactorBps != null ? String(at.actualWorkFactorBps / 100) : '100';
		els.fActive.value = at.activeTravelFactorBps != null ? String(at.activeTravelFactorBps / 100) : '100';
		els.fPassive.value = at.passiveTravelFactorBps != null ? String(at.passiveTravelFactorBps / 100) : '0';
		els.fDuring.value = at.workDuringTravelFactorBps != null ? String(at.workDuringTravelFactorBps / 100) : '100';
		els.roundMode.value = config?.rounding?.mode || 'nearest';
		els.roundInc.value = String(config?.rounding?.resultIncrement || 1);
		els.reqClassification.checked = !!config?.requiresClassification;
		els.activeReleased.checked = !!config?.activeTravelLegallyReleased;
		els.passiveReleased.checked = !!config?.passiveTravelLegallyReleased;
		els.evalTz.value = config?.evaluationTimezone || 'organization';
		els.activate.hidden = !(set && set.status === 'draft');
		// Activation stays disabled until the legal review is recorded —
		// the server enforces the same gate (RULE_REVIEW_REQUIRED, 409).
		els.activate.disabled = !(set && set.reviewedAt);
		els.activate.title = els.activate.disabled ? t('Record the legal review first.') : '';
		runPreview();
	}

	function updateOperationHint() {
		const hints = {
			minimum: t('Example: tier 9:00 with 7:00 actual work books 9:00 — a 2:00 top-up.'),
			fixed: t('Example: tier 9:00 always books 9:00, no matter the actual work.'),
			maximum: t('Example: tier 9:00 with 10:00 actual work books only 9:00.'),
			additive: t('Example: tier 2:00 plus 8:00 actual work books 10:00.'),
		};
		els.operationHint.textContent = hints[els.operation.value] || '';
	}

	function openBuilder(set) {
		fillBuilder(set);
		els.builder.hidden = false;
		els.builderTitle.textContent = set ? set.name : t('New rule set');
		els.builder.scrollIntoView({ block: 'start' });
	}

	els.newSet.addEventListener('click', () => openBuilder(null));
	els.builderClose.addEventListener('click', () => { els.builder.hidden = true; });
	els.addThreshold.addEventListener('click', () => {
		els.thresholdRows.appendChild(thresholdRow(0, 0));
	});
	els.operation.addEventListener('change', updateOperationHint);
	els.previewBasis.addEventListener('change', runPreview);

	let previewTimer = null;
	function runPreview() {
		if (previewTimer) clearTimeout(previewTimer);
		previewTimer = setTimeout(async () => {
			els.previewRows.replaceChildren();
			const basis = parseInt(els.previewBasis.value, 10);
			const { config, error } = readConfig();
			if (error) {
				els.previewStatus.textContent = error;
				return;
			}
			if (!Number.isInteger(basis) || basis < 0 || basis > 1440) {
				els.previewStatus.textContent = t('Enter absence minutes between 0 and 1440.');
				return;
			}
			els.previewStatus.textContent = t('Calculating preview…');
			try {
				const body = await call(root.dataset.previewUrl, {
					method: 'POST',
					json: { configJson: JSON.stringify(config), basisMinutes: basis },
				});
				els.previewRows.replaceChildren();
				(body.rows || []).forEach((row) => {
					const li = document.createElement('li');
					li.className = 'azc-list__item';
					li.textContent = t('At %s absence the account gets %s')
						.replace('%s', hm(row.basis) + ' h')
						.replace('%s', hm(row.target) + ' h');
					els.previewRows.appendChild(li);
				});
				els.previewStatus.textContent = '';
			} catch (e) {
				els.previewStatus.textContent = e.message;
			}
		}, 350);
	}

	els.builderForm.addEventListener('submit', async (ev) => {
		ev.preventDefault();
		els.builderError.hidden = true;
		const { config, error } = readConfig();
		if (error) {
			showError(els.builderError, els.builderErrorText, error);
			return;
		}
		if (els.name.value.trim() === '') {
			showError(els.builderError, els.builderErrorText, t('Please enter a name.'));
			return;
		}
		if (!editingSet && els.code.value.trim() === '') {
			showError(els.builderError, els.builderErrorText, t('Please enter a code.'));
			return;
		}
		try {
			if (editingSet && editingSet.status === 'draft') {
				await call(tpl('ruleSetUpdateTemplate', editingSet.id), {
					method: 'PUT',
					json: { configJson: JSON.stringify(config), name: els.name.value.trim() },
				});
			} else {
				await call(root.dataset.ruleSetCreateUrl, {
					method: 'POST',
					json: {
						code: els.code.value.trim(),
						name: els.name.value.trim(),
						jurisdiction: els.jurisdiction.value,
						configJson: JSON.stringify(config),
						validFrom: els.validFrom.value || null,
						validTo: els.validTo.value || null,
					},
				});
			}
			els.builder.hidden = true;
			await loadSets();
		} catch (e) {
			showError(els.builderError, els.builderErrorText, e.message);
		}
	});

	els.activate.addEventListener('click', async () => {
		if (!editingSet) return;
		els.activate.disabled = true;
		try {
			await call(tpl('ruleSetActivateTemplate', editingSet.id), { method: 'POST' });
			els.builder.hidden = true;
			await loadSets();
		} catch (e) {
			showError(els.builderError, els.builderErrorText, e.message);
		} finally {
			els.activate.disabled = false;
		}
	});

	// ---------- assignments ----------

	function scopeLabel(scope) {
		return {
			org: t('Whole organisation'),
			model: t('Working-time model'),
			team: t('Team'),
			policy_cohort: t('Entitlement group'),
			user: t('Single person'),
		}[scope] || scope;
	}

	function renderAssignments() {
		els.assignmentList.replaceChildren();
		els.assignEmpty.hidden = assignments.length !== 0;
		assignments.forEach((a) => {
			const set = ruleSets.find((s) => s.id === a.ruleSetId);
			const li = document.createElement('li');
			li.className = 'azc-list__item bt-trip-item';
			const main = document.createElement('div');
			main.className = 'bt-trip-item__main';
			const title = document.createElement('span');
			title.className = 'bt-trip-item__title';
			title.textContent = (set ? set.name : 'Set #' + a.ruleSetId) + ' → ' + scopeLabel(a.scopeType) + (a.scopeType !== 'org' ? ' ' + a.scopeId : '');
			const sub = document.createElement('span');
			sub.className = 'bt-trip-item__sub';
			sub.textContent = a.effectiveFrom + (a.effectiveTo ? ' – ' + a.effectiveTo : ' – ' + t('open-ended')) + (a.priority ? ' · P' + a.priority : '');
			main.append(title, sub);
			li.appendChild(main);
			if (!a.effectiveTo || a.effectiveTo >= new Date().toISOString().slice(0, 10)) {
				const end = document.createElement('button');
				end.type = 'button';
				end.className = 'azc-btn azc-btn--secondary';
				end.textContent = t('End');
				end.addEventListener('click', async () => {
					end.disabled = true;
					try {
						await call(tpl('assignmentEndTemplate', a.id), {
							method: 'POST',
							json: { revision: a.revision, effectiveTo: new Date().toISOString().slice(0, 10) },
						});
						await loadAll();
					} catch (e) {
						showError(els.assignError, els.assignErrorText, e.message);
						end.disabled = false;
					}
				});
				li.appendChild(end);
			}
			els.assignmentList.appendChild(li);
		});
	}

	function populateTargets() {
		const scope = els.aScope.value;
		els.aTarget.replaceChildren();
		els.aUserWrap.hidden = scope !== 'user';
		els.aTarget.hidden = scope === 'user' || scope === 'org';
		if (scope === 'model') {
			scopeData.models.forEach((m) => {
				const opt = document.createElement('option');
				opt.value = String(m.id ?? m.name);
				opt.textContent = m.name || String(m.id);
				els.aTarget.appendChild(opt);
			});
		} else if (scope === 'team') {
			scopeData.teams.forEach((team) => {
				const opt = document.createElement('option');
				opt.value = String(team.id ?? team.name);
				opt.textContent = team.name || String(team.id);
				els.aTarget.appendChild(opt);
			});
		} else if (scope === 'policy_cohort') {
			scopeData.cohorts.forEach((c) => {
				const opt = document.createElement('option');
				opt.value = String(c.id);
				opt.textContent = c.name || String(c.id);
				els.aTarget.appendChild(opt);
			});
		}
	}

	els.aScope.addEventListener('change', populateTargets);
	els.newAssignment.addEventListener('click', () => {
		els.assignmentForm.hidden = false;
		els.aRuleSet.replaceChildren();
		ruleSets.filter((s) => s.status === 'active').forEach((s) => {
			const opt = document.createElement('option');
			opt.value = String(s.id);
			opt.textContent = s.name + ' v' + s.version;
			els.aRuleSet.appendChild(opt);
		});
		if (els.aRuleSet.options.length === 0) {
			showError(els.assignError, els.assignErrorText, t('No active rule set exists — activate one first.'));
			els.assignmentForm.hidden = true;
			return;
		}
		populateTargets();
	});
	els.assignmentCancel.addEventListener('click', () => { els.assignmentForm.hidden = true; });

	els.assignmentForm.addEventListener('submit', async (ev) => {
		ev.preventDefault();
		els.assignError.hidden = true;
		const scope = els.aScope.value;
		const scopeId = scope === 'org' ? 'org' : (scope === 'user' ? $('bta-a-user-id').value.trim() : els.aTarget.value);
		if (scope !== 'org' && scopeId === '') {
			showError(els.assignError, els.assignErrorText, t('Please pick a target.'));
			return;
		}
		if (els.aFrom.value === '') {
			showError(els.assignError, els.assignErrorText, t('Please pick a start date.'));
			return;
		}
		try {
			await call(root.dataset.assignmentCreateUrl, {
				method: 'POST',
				json: {
					ruleSetId: parseInt(els.aRuleSet.value, 10),
					scopeType: scope,
					scopeId: scopeId,
					priority: parseInt(els.aPriority.value || '0', 10),
					effectiveFrom: els.aFrom.value,
					effectiveTo: els.aTo.value || null,
				},
			});
			els.assignmentForm.hidden = true;
			await loadAll();
		} catch (e) {
			showError(els.assignError, els.assignErrorText, e.message);
		}
	});

	// ---------- profiles ----------

	els.profileForm.addEventListener('submit', async (ev) => {
		ev.preventDefault();
		els.profileError.hidden = true;
		els.profileStatus.textContent = '';
		const uid = $('bta-p-user-id').value.trim();
		if (uid === '') {
			showError(els.profileError, els.profileErrorText, t('Please pick a person.'));
			return;
		}
		const minutes = els.pMinutes.value === '' ? null : parseInt(els.pMinutes.value, 10);
		if (minutes !== null && (Number.isNaN(minutes) || minutes < 0 || minutes > 1440)) {
			showError(els.profileError, els.profileErrorText, t('Minutes must be between 0 and 1440.'));
			return;
		}
		try {
			await call(root.dataset.profileUrl, {
				method: 'POST',
				json: {
					userId: uid,
					normalCommuteMinutes: minutes,
					effectiveFrom: els.pFrom.value || null,
				},
			});
			els.profileStatus.textContent = t('Commute saved.');
			els.pUser.value = '';
			$('bta-p-user-id').value = '';
			els.pMinutes.value = '';
			els.pFrom.value = '';
		} catch (e) {
			showError(els.profileError, els.profileErrorText, e.message);
		}
	});

	// ---------- data ----------

	async function loadScopeData() {
		try {
			const [models, teams, cohorts] = await Promise.all([
				call(root.dataset.modelsUrl),
				call(root.dataset.teamsUrl),
				call(root.dataset.cohortsUrl).catch(() => ({ items: [] })),
			]);
			scopeData.models = models.items || models.models || [];
			scopeData.teams = teams.items || teams.teams || [];
			scopeData.cohorts = (cohorts.items || []).map((c) => ({ id: c.id, name: c.name }));
		} catch (e) {
			// Scope lists are advisory — the server still validates ids.
		}
	}

	async function loadAll() {
		try {
			const body = await call(root.dataset.assignmentsUrl);
			assignments = body.items || [];
		} catch (e) {
			assignments = [];
		}
		await loadSets();
		renderAssignments();
	}

	// User pickers (shared module, same selectors convention as other admin pages).
	const pickerL10n = {
		typeToSearch: t('Type at least 2 characters to search.'),
		noResults: t('No matching people.'),
		loading: t('Searching…'),
	};
	window.ArbeitszeitCheck?.initAdminUserPicker?.({
		wrapSelector: '#bta-a-user-wrap',
		hiddenSelector: '#bta-a-user-id',
		searchSelector: '#bta-a-user',
		listSelector: '#bta-a-user-list',
		statusSelector: '#bta-a-user-status',
		searchUrl: root.dataset.usersUrl,
		l10n: pickerL10n,
	});
	window.ArbeitszeitCheck?.initAdminUserPicker?.({
		wrapSelector: '#bta-p-user-wrap',
		hiddenSelector: '#bta-p-user-id',
		searchSelector: '#bta-p-user',
		listSelector: '#bta-p-user-list',
		statusSelector: '#bta-p-user-status',
		searchUrl: root.dataset.usersUrl,
		l10n: pickerL10n,
	});

	// ---------- rollout (Phase 6) ----------

	function rolloutCheck(label, ok) {
		const li = document.createElement('li');
		li.className = 'azc-list__item bta-rollout-check';
		const chip = document.createElement('span');
		chip.className = 'azc-status-chip';
		chip.dataset.status = ok ? 'approved' : 'pending';
		chip.textContent = ok ? t('OK') : t('Open');
		const text = document.createElement('span');
		text.textContent = label;
		li.append(chip, text);
		return li;
	}

	function rolloutInfo(label) {
		const li = document.createElement('li');
		li.className = 'azc-list__item bta-rollout-check';
		const chip = document.createElement('span');
		chip.className = 'azc-status-chip';
		chip.dataset.status = 'draft';
		chip.textContent = t('Info');
		const text = document.createElement('span');
		text.textContent = label;
		li.append(chip, text);
		return li;
	}

	function renderRollout(body) {
		const p = body.preflight || {};
		const flags = body.flags || {};
		els.rolloutChecks.replaceChildren();
		els.rolloutChecks.append(
			rolloutCheck(t('No pending legacy business-trip requests'), p.pendingLegacyTripAbsences === 0),
			rolloutCheck(t('A legally reviewed rule set is active'), (p.activeRuleSets || 0) > 0),
			rolloutCheck(t('At least one rule assignment is in effect'), (p.effectiveAssignments || 0) > 0),
			rolloutCheck(t('Business trips feature is on'), flags.business_trips_enabled === true),
			rolloutInfo(t('Submitted trips awaiting a decision: %s').replace('%s', String(p.submittedTripsUndecided || 0))),
			rolloutInfo(t('Organisation-wide assignment: %s').replace('%s', p.orgScopeCoverage === true ? t('yes') : t('no'))),
		);
		if (els.enable) {
			els.enable.hidden = flags.business_trips_enabled === true;
		}
		const canCut = p.ready === true && flags.business_trips_enabled === true
			&& flags.legacy_trip_absence_create_enabled === true;
		els.disableLegacy.disabled = !canCut;
		els.rolloutStatus.textContent = flags.legacy_trip_absence_create_enabled === false
			? t('Legacy business-trip requests are closed for new submissions.')
			: (p.ready === true ? t('All checks passed — the cutover is safe.') : t('Some checks are still open.'));
	}

	async function loadRollout() {
		try {
			renderRollout(await call(root.dataset.rolloutUrl));
		} catch (e) {
			els.rolloutStatus.textContent = e.message;
		}
	}

	async function loadPresets() {
		if (!els.presets) return;
		try {
			const body = await call(root.dataset.presetsUrl);
			(body.items || []).forEach((preset) => {
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'azc-btn azc-btn--secondary';
				btn.textContent = t('New %s draft').replace('%s', preset.name);
				btn.title = preset.provenance && preset.provenance.sourceTitle ? preset.provenance.sourceTitle : '';
				btn.addEventListener('click', async () => {
					btn.disabled = true;
					try {
						await call(tpl('presetCreateTemplate', preset.jurisdiction), { method: 'POST' });
						await loadSets();
						await loadRollout();
					} catch (e) {
						els.setsStatus.textContent = e.message;
						btn.disabled = false;
					}
				});
				els.presets.appendChild(btn);
			});
		} catch (e) {
			// Presets are an accelerator, not a blocker — note and move on.
			els.setsStatus.textContent = e.message;
		}
	}

	function initRollout() {
		if (!els.rolloutStatus) return;
		if (els.enable) {
			els.enable.addEventListener('click', async () => {
				const enableOk = await confirmStep({
					title: t('Enable business trips?'),
					message: t('Enable the business-trip workflow? Once trips and rule sets exist, downgrading to a build without this feature cannot write them. You can keep the feature enabled and simply not use it.'),
					confirmLabel: t('Enable'),
					cancelLabel: t('Cancel'),
					variant: 'info',
				});
				if (!enableOk) {
					return;
				}
				els.enable.disabled = true;
				try {
					await call(root.dataset.enableUrl, {
						method: 'POST',
						json: { acknowledgeRollbackWarning: true },
					});
					window.location.reload();
				} catch (e) {
					els.rolloutStatus.textContent = e.message;
					els.enable.disabled = false;
				}
			});
		}
		els.disableLegacy.addEventListener('click', async () => {
			const cutoverOk = await confirmStep({
				title: t('Close the legacy trip absence type?'),
				message: t('This closes the legacy "business trip" absence type for new requests. Existing requests stay readable and decidable; new trips use the business-trip workflow. Rollback: re-enable the legacy create flag in the app configuration.'),
				confirmLabel: t('Close legacy type'),
				cancelLabel: t('Cancel'),
				variant: 'destructive',
				requireCheckbox: true,
				checkboxLabel: t('I understand the effect and the rollback path.'),
			});
			if (!cutoverOk) {
				return;
			}
			els.disableLegacy.disabled = true;
			try {
				const body = await call(root.dataset.disableLegacyUrl, { method: 'POST' });
				renderRollout({ preflight: body.preflight, flags: { legacy_trip_absence_create_enabled: body.legacy_trip_absence_create_enabled, business_trips_enabled: true } });
			} catch (e) {
				if (e.data && e.data.preflight) {
					renderRollout({ preflight: e.data.preflight, flags: { legacy_trip_absence_create_enabled: true, business_trips_enabled: true } });
				}
				els.rolloutStatus.textContent = e.message;
				els.disableLegacy.disabled = false;
			}
		});
		loadRollout();
	}

	fillKindSelects();
	updateOperationHint();
	loadScopeData();
	loadPresets();
	loadAll();
})();
