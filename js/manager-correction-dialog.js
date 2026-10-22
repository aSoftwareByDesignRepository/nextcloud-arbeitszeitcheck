/**
 * Manager direct time-entry correction dialog (date + HH:mm matrix).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const Utils = window.ArbeitszeitCheckUtils || {};
	const Messaging = window.ArbeitszeitCheckMessaging || {};
	const Components = window.ArbeitszeitCheckComponents || {};
	const ClockForm = window.ArbeitszeitCheckClockForm;

	function t(key, fallback) {
		const bundle = window.ArbeitszeitCheck?.l10n || {};
		const value = bundle[key];
		return value !== undefined && value !== '' ? value : (fallback || key);
	}

	function labels() {
		return {
			intro: t('managerCorrectionIntro', 'Changes are applied immediately and the employee is notified. A reason is required for the audit log.'),
			workingDayLegend: t('correctionWorkingDayLegend', 'Corrected working day'),
			date: t('Date', 'Date'),
			required: t('required', 'required'),
			datePlaceholder: t('dd.mm.yyyy', 'dd.mm.yyyy'),
			today: t('Today', 'Today'),
			dateHelp: t('correctionDateHelp', 'Format: dd.mm.yyyy'),
			workingHours: t('Working Hours', 'Working Hours'),
			startTime: t('Start Time', 'Start Time'),
			endTime: t('End Time', 'End Time'),
			start: t('Start', 'Start'),
			end: t('End', 'End'),
			nightShiftHint: t('correctionNightShiftHint', 'Night shift: if end is earlier than start (e.g. 22:00–06:00), end counts as the next day.'),
			breaksOptional: t('correctionBreaksOptional', 'Breaks (optional)'),
			breaksHelp: t('managerCorrectionBreaksHelp', 'Adjust breaks if needed. Each break must be at least 15 minutes and within working hours.'),
			breaksEmpty: t('correctionBreaksEmpty', 'No breaks added.'),
			actions: t('Actions', 'Actions'),
			addBreak: t('correctionAddBreak', 'Add break'),
			reason: t('Reason (min. 10 characters)', 'Reason (min. 10 characters)'),
			reasonHelp: t('correctionReasonHelp', 'Required for the audit trail (at least 10 characters).'),
			workLocationLegend: t('workLocationLabel', 'Work location'),
			workLocationUnspecified: t('workLocationKeepCurrent', 'Keep current location'),
			workLocationClearOption: t('workLocationClearMark', 'Not specified (remove mark)'),
			workLocationOffice: t('workLocationOffice', 'Office'),
			workLocationHome: t('workLocationHome', 'Home office'),
			workLocationExternal: t('workLocationExternal', 'External appointment'),
		};
	}

	function parseEntrySummary(raw) {
		const parsed = Utils.parseAttributeJson
			? Utils.parseAttributeJson(raw)
			: null;
		if (Utils.isTimeEntryClockSummary && Utils.isTimeEntryClockSummary(parsed)) {
			return parsed;
		}
		return null;
	}

	function correctionLoadErrorMessage() {
		return t(
			'correctionErrorLoadEntry',
			'Could not load the stored times for this entry. Please reload the page and try again.'
		);
	}

	function formatIsoDisplay(iso, mode) {
		if (!iso) {
			return '–';
		}
		const api = window.ArbeitszeitCheckTime;
		if (api) {
			if (mode === 'date') {
				return api.formatDate(iso) || '–';
			}
			return api.formatTime(iso) || '–';
		}
		return String(iso).slice(0, 16).replace('T', ' ');
	}

	function formatBreaksList(breaks) {
		if (!Array.isArray(breaks) || breaks.length === 0) {
			return '–';
		}
		return breaks.map((b) => {
			const start = b.start || b.startTime || '';
			const end = b.end || b.endTime || '';
			if (typeof start === 'string' && /^\d{2}:\d{2}$/.test(start)) {
				return start + '–' + end;
			}
			const s = formatIsoDisplay(start, 'time');
			const e = formatIsoDisplay(end, 'time');
			return s + '–' + e;
		}).join(', ');
	}

	function formatWorkLocationLabel(raw) {
		const s = typeof raw === 'string' ? raw : '';
		if (s === 'home') {
			return t('workLocationHome', 'Home office');
		}
		if (s === 'external') {
			return t('workLocationExternal', 'External appointment');
		}
		if (s === 'office') {
			return t('workLocationOffice', 'Office');
		}
		return t('workLocationUnspecified', 'Not specified');
	}

	function buildSnapshotHtml(summary) {
		const esc = (value) => (Utils.escapeHtml ? Utils.escapeHtml(String(value ?? '')) : String(value ?? ''));
		const rows = [
			[t('correctionLabelDate', 'Date'), formatIsoDisplay(summary.startTime, 'date')],
			[t('correctionLabelStart', 'Start'), formatIsoDisplay(summary.startTime, 'time')],
			[t('correctionLabelEnd', 'End'), formatIsoDisplay(summary.endTime, 'time')],
			[t('correctionLabelBreaks', 'Breaks'), formatBreaksList(summary.breaks)],
			[t('workLocationLabel', 'Work location'), formatWorkLocationLabel(summary.workLocation)],
		];
		const rowHtml = rows.map(([label, val]) => {
			return '<tr><th scope="row">' + esc(label) + '</th><td>' + esc(val) + '</td></tr>';
		}).join('');
		const currentHeading = t('correctionCurrentHeading', 'Currently stored');
		const currentAria = t('correctionCurrentAria', 'Times currently saved for this entry');
		const proposedHeading = t('correctionProposedHeading', 'Corrected times');
		const proposedHint = t('correctionProposedHint', 'Enter the date and times as they should be recorded.');
		return [
			'<section class="correction-dialog__block correction-dialog__block--current" aria-labelledby="mgr-correct-current-heading">',
			'<h3 id="mgr-correct-current-heading" class="correction-dialog__block-title">' + esc(currentHeading) + '</h3>',
			'<div class="correction-snapshot__wrap" role="region" aria-label="' + esc(currentAria) + '">',
			'<table class="correction-snapshot__table">',
			'<caption class="sr-only">' + esc(currentAria) + '</caption>',
			'<tbody>',
			rowHtml,
			'</tbody>',
			'</table>',
			'</div>',
			'</section>',
			'<section class="correction-dialog__block correction-dialog__block--proposed" aria-labelledby="mgr-correct-proposed-heading">',
			'<h3 id="mgr-correct-proposed-heading" class="correction-dialog__block-title">' + esc(proposedHeading) + '</h3>',
			'<p class="correction-dialog__block-hint">' + esc(proposedHint) + '</p>',
		].join('');
	}

	function open(entryId, updatedAt, summary) {
		if (!ClockForm || !Components.createModal) {
			Messaging.showError(t('Could not open correction dialog.', 'Could not open correction dialog.'));
			return;
		}
		if (!Utils.isTimeEntryClockSummary || !Utils.isTimeEntryClockSummary(summary)) {
			Messaging.showError(correctionLoadErrorMessage());
			return;
		}

		const modalId = 'manager-correct-entry-' + entryId;
		const existing = document.getElementById(modalId);
		if (existing) {
			existing.remove();
		}

		const idPrefix = 'mgr-correct-' + entryId;
		const wlCatalog = window.ArbeitszeitCheckWorkLocationCatalog;
		const wlOptions = wlCatalog ? wlCatalog.options() : null;
		const formHtml = buildSnapshotHtml(summary) + ClockForm.buildFormHtml(idPrefix, labels(), { workLocations: wlOptions }) + '</section>';
		const footerHtml = [
			'<div class="reject-modal-actions modal-footer">',
			'<button type="button" class="btn btn--secondary btn-mgr-correct-cancel">' + t('Cancel', 'Cancel') + '</button>',
			'<button type="button" class="btn btn--primary btn-mgr-correct-save">' + t('Apply correction', 'Apply correction') + '</button>',
			'</div>',
		].join('');

		const modal = Components.createModal({
			id: modalId,
			title: t('Correct time entry', 'Correct time entry'),
			content: '<div class="manager-create-dialog__meta manager-correct-dialog__meta"></div>' + formHtml + footerHtml,
			size: 'xl',
		});
		modal.classList.add('azc-manager-correction-modal');

		const saveBtn = modal.querySelector('.btn-mgr-correct-save');
		const cancelBtn = modal.querySelector('.btn-mgr-correct-cancel');

		const meta = modal.querySelector('.manager-correct-dialog__meta');
		let projectSelect = null;
		let projectOptionsReady = !(window.ArbeitszeitCheck?.projectCheckEnabled && summary.userId);
		if (window.ArbeitszeitCheck?.projectCheckEnabled && summary.userId) {
			const wrap = document.createElement('div');
			wrap.className = 'azc-filter-field';
			const pid = 'mgr-correct-project-' + entryId;
			wrap.innerHTML = [
				`<label for="${pid}" class="azc-filter-field__label">${t('Project (optional)', 'Project (optional)')}</label>`,
				'<div class="azc-filter-field__control">',
				`<select id="${pid}" class="form-select" disabled></select>`,
				'</div>',
			].join('');
			projectSelect = wrap.querySelector('select');
			meta.appendChild(wrap);
			const url = OC.generateUrl(
				'/apps/arbeitszeitcheck/api/manager/employees/{employeeId}/projectcheck-assignable-projects',
				{ employeeId: summary.userId }
			);
			fetch(url, { headers: { requesttoken: OC.requestToken } })
				.then((r) => r.json())
				.then((data) => {
					if (!projectSelect) {
						return;
					}
					projectSelect.innerHTML = '';
					const none = document.createElement('option');
					none.value = '';
					none.textContent = t('No project link', 'No project link');
					projectSelect.appendChild(none);
					if (data.success && Array.isArray(data.projects)) {
						data.projects.forEach((p) => {
							const opt = document.createElement('option');
							opt.value = String(p.id);
							opt.textContent = p.displayName || p.name || String(p.id);
							if (String(summary.projectCheckProjectId || '') === opt.value) {
								opt.selected = true;
							}
							projectSelect.appendChild(opt);
						});
					}
				})
				.catch(() => {
					Messaging.showError(t('Could not load projects.', 'Could not load projects.'));
					if (!projectSelect) {
						return;
					}
					projectSelect.innerHTML = '';
					const none = document.createElement('option');
					none.value = '';
					none.textContent = t('No project link', 'No project link');
					projectSelect.appendChild(none);
					if (summary.projectCheckProjectId) {
						const keep = document.createElement('option');
						keep.value = String(summary.projectCheckProjectId);
						keep.textContent = t('Keep current project link', 'Keep current project link');
						keep.selected = true;
						projectSelect.appendChild(keep);
					}
				})
				.finally(() => {
					projectOptionsReady = true;
					if (projectSelect) {
						projectSelect.disabled = false;
					}
					if (saveBtn) {
						saveBtn.disabled = false;
						saveBtn.removeAttribute('aria-busy');
					}
				});
		}

		const initial = {
			startTime: summary.startTime,
			endTime: summary.endTime,
			breaks: summary.breaks || [],
			minBreakMinutes: summary.minBreakMinutes,
			workLocation: summary.workLocation,
		};
		const formApi = ClockForm.bindForm(modal, idPrefix, initial, t, {
			minBreakMinutes: summary.minBreakMinutes,
			workLocations: wlOptions,
		});

		if (projectSelect && !projectOptionsReady && saveBtn) {
			saveBtn.disabled = true;
			saveBtn.setAttribute('aria-busy', 'true');
		}

		cancelBtn?.addEventListener('click', () => Components.closeModal(modal));

		saveBtn?.addEventListener('click', () => {
			if (projectSelect && !projectOptionsReady) {
				formApi.setStatus(t('correctionLoadingProjects', 'Loading projects…'), false);
				return;
			}
			const result = formApi.validateAndCollect();
			if (!result.ok) {
				formApi.setStatus(result.error, true);
				return;
			}
			formApi.setStatus('', false);

			const payload = {
				reason: result.payload.reason,
				expectedUpdatedAt: updatedAt || undefined,
				date: result.payload.date,
				startTime: result.payload.startTime,
				endTime: result.payload.endTime,
			};
			if (result.payload.breaks && result.payload.breaks.length > 0) {
				payload.breaks = result.payload.breaks;
			}
			// '__clear' proposes removing the mark entirely (sent as null —
			// TimeEntryController treats a present-but-null field as a clear);
			// '' means "keep current" and is never sent.
			const currentWorkLocation = typeof summary.workLocation === 'string' && summary.workLocation !== ''
				? summary.workLocation
				: null;
			const proposedLocation = result.payload.workLocation;
			if (proposedLocation === '__clear') {
				if (currentWorkLocation !== null) {
					payload.workLocation = null;
				}
			} else if (proposedLocation !== '' && proposedLocation !== currentWorkLocation) {
				payload.workLocation = proposedLocation;
			}
			if (projectSelect && projectOptionsReady) {
				payload.projectCheckProjectId = projectSelect.value || '';
			}

			saveBtn.disabled = true;
			saveBtn.setAttribute('aria-busy', 'true');

			Utils.ajax('/apps/arbeitszeitcheck/api/manager/time-entries/' + entryId + '/correct', {
				method: 'POST',
				data: payload,
				onSuccess: (data) => {
					if (data.success) {
						Components.closeModal(modal);
						Messaging.showSuccess(data.message || t('Time entry corrected successfully.', 'Time entry corrected successfully.'));
						if (data.billing_warning && Messaging.showWarning) {
							Messaging.showWarning(data.billing_warning);
						}
						document.dispatchEvent(new CustomEvent('arbeitszeitcheck:manager-entry-corrected'));
					} else {
						Messaging.showError(data.error || t('Correction failed.', 'Correction failed.'));
					}
				},
				onError: (err) => {
					const code = err?.data?.error_code;
					if (code === 'entry_modified') {
						Messaging.showError(err.error || t('Entry was modified. Reloading…', 'Entry was modified. Reloading…'));
						Components.closeModal(modal);
						document.dispatchEvent(new CustomEvent('arbeitszeitcheck:manager-entry-corrected'));
					} else {
						Messaging.showError(err?.error || t('Correction failed.', 'Correction failed.'));
					}
				},
			}).finally(() => {
				if (document.body.contains(modal)) {
					saveBtn.disabled = false;
					saveBtn.removeAttribute('aria-busy');
				}
			});
		});

		Components.openModal(modalId);
	}

	window.ArbeitszeitCheckManagerCorrection = {
		open,
		parseEntrySummary,
	};
})();
