/**
 * Reports page logic for ArbeitszeitCheck.
 *
 * This file contains the report builder, preview and download logic that
 * previously lived in an inline <script> block in reports.php. It now
 * reads configuration from window.ArbeitszeitCheck, which is bootstrapped
 * in the PHP template, and attaches all required DOM behaviour here.
 */

(function () {
	document.addEventListener('DOMContentLoaded', () => {
		const A = window.ArbeitszeitCheck || {};

		// Only run on the reports page and only when the user can access reports
		if (A.page !== 'reports' || !A.canAccessReports) {
			return;
		}

		const reportCards = document.querySelectorAll('.report-type-card.btn-select-report');
		const reportButtons = reportCards;
		const reportTypeGroup = document.querySelector('.report-types-grid[role="radiogroup"]');
		const reportParameters = document.getElementById('report-parameters');
		const reportForm = document.getElementById('report-form');
		const reportTypeInput = document.getElementById('report-type');
		const reportScopeInput = document.getElementById('report-scope');
		const _reportTeamUsersInput = document.getElementById('report-team-users');
		const startDateInput = document.getElementById('start-date');
		const endDateInput = document.getElementById('end-date');
		const formatSelect = document.getElementById('format');
		const teamVariantGroup = document.getElementById('report-team-variant-group');
		const teamVariantSelect = document.getElementById('report-team-variant');
		const exportLayoutGroup = document.getElementById('report-export-layout-group');
		const exportLayoutSelect = document.getElementById('report-export-layout');
		const previewBtn = document.getElementById('btn-preview-report');
		const generateBtn = document.getElementById('btn-generate-report');
		const scopeForm = document.getElementById('report-scope-form');
		const stepperItems = document.querySelectorAll('.azc-reports-stepper__item');
		let reportFetchSeq = 0;

		function setSectionVisible(section, visible) {
			if (!section) {
				return;
			}
			section.hidden = !visible;
		}

		function updateReportsStepper(activeStep) {
			if (!stepperItems.length) {
				return;
			}
			stepperItems.forEach((item, index) => {
				const stepNum = index + 1;
				item.classList.toggle('azc-reports-stepper__item--current', stepNum === activeStep);
				item.classList.toggle('azc-reports-stepper__item--done', stepNum < activeStep);
			});
		}

		// Helper: get request token safely
		function getRequestToken() {
			if (window.ArbeitszeitCheckUtils && typeof window.ArbeitszeitCheckUtils.getRequestToken === 'function') {
				return window.ArbeitszeitCheckUtils.getRequestToken();
			}
			if (typeof OC !== 'undefined' && OC.requestToken) {
				return OC.requestToken;
			}
			const head = document.querySelector('head');
			return head ? head.getAttribute('data-requesttoken') || '' : '';
		}

		/** Build app URL when OC is not yet loaded (same pattern as arbeitszeitcheck-main.js). */
		function generateAppUrl(path) {
			if (window.ArbeitszeitCheckUtils && typeof window.ArbeitszeitCheckUtils.resolveUrl === 'function') {
				return window.ArbeitszeitCheckUtils.resolveUrl(path);
			}
			if (typeof OC !== 'undefined' && typeof OC.generateUrl === 'function') {
				return OC.generateUrl(path);
			}
			return path.startsWith('/') ? path : '/' + path;
		}

		/** Normalize export/download href (server route or app-relative path). */
		function toDownloadHref(urlOrPath) {
			const resolved = generateAppUrl(urlOrPath);
			try {
				return new URL(resolved, window.location.origin).toString();
			} catch (e) {
				return resolved;
			}
		}

		// Helper: announce status to screen reader
		function announceToScreenReader(message) {
			const live = document.getElementById('report-preview-live');
			if (!live) {
				return;
			}
			live.textContent = '';
			live.setAttribute('aria-live', 'polite');
			setTimeout(() => {
				live.textContent = message;
			}, 100);
		}

		// Helper: show a visible inline error in preview area and announce it.
		function showDownloadError(message) {
			const previewSection = document.getElementById('report-preview');
			const previewContent = document.getElementById('report-preview-content');
			if (previewSection && previewContent) {
				previewContent.innerHTML = `<p class="report-error" role="alert">${esc(message)}</p>`;
				setSectionVisible(previewSection, true);
				previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			}
			announceToScreenReader(message);
		}

		// Helper: escape HTML
		function esc(s) {
			if (s == null) {
				return '';
			}
			return String(s)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;');
		}

		// Scope handling: keep a clean, explicit state
		const scopeState = {
			scope: '',
			adminTeamId: '',
			managerTeamId: '',
			teamUserIds: [],
		};

		function updateScopeFromForm() {
			if (!scopeForm) {
				return;
			}
			const formData = new FormData(scopeForm);
			const scope = formData.get('report_scope') || '';
			scopeState.scope = scope;

			if (A.isAdmin) {
				scopeState.adminTeamId = (formData.get('admin_team_id') || '').trim();
			} else if (A.isManager) {
				scopeState.managerTeamId = (formData.get('manager_team_id') || '').trim();
			}

			if (reportScopeInput) {
				reportScopeInput.value = scope || '';
			}
		}

		// Load admin teams for selection (only once)
		function loadAdminTeamsIfNeeded() {
			if (!A.isAdmin) {
				return;
			}
			const select = document.getElementById('admin-team-select');
			if (!select || select.dataset.loaded === 'true') {
				return;
			}

			const url = generateAppUrl('/apps/arbeitszeitcheck/api/admin/teams');
			fetch(url, {
				method: 'GET',
				headers: { requesttoken: getRequestToken() },
			})
				.then((res) => (res.ok ? res.json() : null))
				.then((data) => {
					if (!data || !data.success || !Array.isArray(data.teams)) {
						return;
					}
					// Mark that we tried loading; even if empty we avoid repeated fetches
					select.dataset.loaded = 'true';
					data.teams.forEach((team) => {
						const opt = document.createElement('option');
						opt.value = String(team.id);
						opt.textContent = team.name || `#${team.id}`;
						select.appendChild(opt);
					});
				})
				.catch(() => {
					// Fail silently – scope selection still works with organization-wide reports
				});
		}

		// Load manager-managed teams when app-owned teams are enabled
		function loadManagerTeamsIfNeeded() {
			if (!A.isManager) {
				return;
			}
			const select = document.getElementById('manager-team-select');
			const scopeRadio = document.getElementById('scope-manager-single-team');
			if (!select || select.dataset.loaded === 'true') {
				return;
			}

			const url = generateAppUrl('/apps/arbeitszeitcheck/api/manager/teams');
			fetch(url, {
				method: 'GET',
				headers: { requesttoken: getRequestToken() },
			})
				.then((res) => (res.ok ? res.json() : null))
				.then((data) => {
					if (!data || !data.success || !Array.isArray(data.teams)) {
						return;
					}
					select.dataset.loaded = 'true';
					if (data.teams.length === 0) {
						return; /* Keep hidden – user only sees "Everyone I manage" */
					}
					const group = scopeRadio && scopeRadio.closest('.form-group');
					if (group) {
						group.classList.add('reports-scope-option-visible');
					}
					if (scopeRadio) scopeRadio.disabled = false;
					data.teams.forEach((team) => {
						const opt = document.createElement('option');
						opt.value = String(team.id);
						opt.textContent = team.name || `#${team.id}`;
						select.appendChild(opt);
					});
				})
				.catch(() => {
					// Fail silently – manager can still use aggregated team scope
				});
		}

		// React to changes in scope radios and team selects
		// Use both 'change' and 'input' so scope updates reliably (e.g. keyboard, click, assistive tech)
		function applyReportTypeRestrictionsForScope(_scope) {
			reportCards.forEach((card) => {
				card.disabled = false;
				card.setAttribute('aria-disabled', 'false');
			});
		}

		function setReportTypeSelection(selectedButton) {
			reportCards.forEach((card) => {
				const selected = card === selectedButton;
				card.classList.toggle('is-selected', selected);
				card.setAttribute('aria-checked', selected ? 'true' : 'false');
				card.tabIndex = selected ? 0 : -1;
				const actionLabel = card.querySelector('.report-type-card__action-label')
					|| card.querySelector('.report-type-card__action');
				if (actionLabel) {
					const selectLabel = card.getAttribute('data-label-select') || 'Select';
					const selectedLabel = card.getAttribute('data-label-selected') || 'Selected';
					actionLabel.textContent = selected ? selectedLabel : selectLabel;
				}
			});
			if (reportCards.length && !Array.from(reportCards).some((c) => c.tabIndex === 0)) {
				reportCards[0].tabIndex = 0;
			}
		}

		/** Show team variant + export layout controls when they apply (working time export, team scope, formats). */
		function updateExportOptionVisibility() {
			const reportType = reportTypeInput ? reportTypeInput.value : '';
			const scope = reportScopeInput ? reportScopeInput.value : '';
			const teamScopes = ['admin_team', 'manager_team', 'manager_single_team'];
			const isTeam = teamScopes.includes(scope);
			const isMonthlyWorkingTime = reportType === 'monthly';
			const fmt = formatSelect ? formatSelect.value : 'csv';

			if (teamVariantGroup) {
				const showTeamVariant = isTeam && isMonthlyWorkingTime;
				teamVariantGroup.style.display = showTeamVariant ? '' : 'none';
				if (teamVariantSelect) {
					teamVariantSelect.disabled = !showTeamVariant;
				}
			}

			if (exportLayoutGroup) {
				const teamVariant = teamVariantSelect ? teamVariantSelect.value : 'summary';
				const showLayout =
					isMonthlyWorkingTime &&
					(fmt === 'csv' || fmt === 'json') &&
					(!isTeam || teamVariant === 'time_entries');
				exportLayoutGroup.style.display = showLayout ? '' : 'none';
				if (exportLayoutSelect) {
					exportLayoutSelect.disabled = !showLayout;
				}
			}
		}

		function handleScopeChange() {
				// Enable/disable team selects based on active scope
				const scopeAdminTeam = document.getElementById('scope-admin-team');
				const scopeManagerSingleTeam = document.getElementById('scope-manager-single-team');
				const adminTeamSelect = document.getElementById('admin-team-select');
				const managerTeamSelect = document.getElementById('manager-team-select');

				// Admin: toggle team select
				if (scopeAdminTeam && adminTeamSelect) {
					const adminTeamActive = scopeAdminTeam.checked;
					adminTeamSelect.disabled = !adminTeamActive;
					if (adminTeamActive) {
						loadAdminTeamsIfNeeded();
					}
				}

				// Manager: toggle team select
				if (scopeManagerSingleTeam && managerTeamSelect) {
					const managerSingleActive = scopeManagerSingleTeam.checked;
					managerTeamSelect.disabled = !managerSingleActive;
					if (managerSingleActive) {
						loadManagerTeamsIfNeeded();
					}
				}

				updateScopeFromForm();
				applyReportTypeRestrictionsForScope(reportScopeInput ? reportScopeInput.value : '');
				updateExportOptionVisibility();
				updateReportsStepper(2);
		}

			if (scopeForm) {
			scopeForm.addEventListener('change', handleScopeChange);
			scopeForm.addEventListener('input', handleScopeChange);

			// Initialize scope state once
			updateScopeFromForm();
			applyReportTypeRestrictionsForScope(reportScopeInput ? reportScopeInput.value : '');
			if (A.isAdmin) {
				loadAdminTeamsIfNeeded();
			} else if (A.isManager) {
				loadManagerTeamsIfNeeded();
			}
			updateExportOptionVisibility();
			updateReportsStepper(1);
		}

		// Report type chooser: each option is a single button (role=radio).
		function selectReportType(button) {
			if (!(button instanceof HTMLElement) || button.disabled) {
				return;
			}
			const reportType = button.dataset.report;
			if (!reportType || !reportTypeInput) {
				return;
			}

			setReportTypeSelection(button);
			reportTypeInput.value = reportType;

			if (teamVariantSelect) {
				teamVariantSelect.value = 'time_entries';
			}
			if (exportLayoutSelect) {
				exportLayoutSelect.value = 'long';
			}

			updateScopeFromForm();

			if (reportParameters) {
				setSectionVisible(reportParameters, true);
				updateReportsStepper(3);
				reportParameters.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			}

			const timeApi = window.ArbeitszeitCheckTime;
			const utils = window.ArbeitszeitCheckUtils;
			const toDDMMYYYY = (d) => {
				if (utils && typeof utils.formatDate === 'function') {
					return utils.formatDate(d, 'DD.MM.YYYY');
				}
				const day = String(d.getDate()).padStart(2, '0');
				const month = String(d.getMonth() + 1).padStart(2, '0');
				const year = d.getFullYear();
				return `${day}.${month}.${year}`;
			};
			const todayAnchor = (timeApi && timeApi.parseYmd(timeApi.todayYmd()))
				? timeApi.parseYmd(timeApi.todayYmd())
				: new Date();
			const thirtyDaysAgo = new Date(todayAnchor);
			thirtyDaysAgo.setDate(todayAnchor.getDate() - 30);

			let defaultStart = thirtyDaysAgo;
			let defaultEnd = todayAnchor;

			if (reportType === 'daily') {
				defaultStart = todayAnchor;
				defaultEnd = todayAnchor;
			} else if (reportType === 'weekly') {
				const jsDay = todayAnchor.getDay();
				defaultStart = new Date(todayAnchor);
				defaultStart.setDate(todayAnchor.getDate() - jsDay);
				defaultEnd = new Date(defaultStart);
				defaultEnd.setDate(defaultStart.getDate() + 6);
			} else if (reportType === 'monthly') {
				defaultStart = thirtyDaysAgo;
				defaultEnd = todayAnchor;
			} else {
				defaultStart = thirtyDaysAgo;
				defaultEnd = todayAnchor;
			}

			if (startDateInput) startDateInput.value = toDDMMYYYY(defaultStart);
			if (endDateInput) endDateInput.value = toDDMMYYYY(defaultEnd);
			updateExportOptionVisibility();
			button.focus();
		}

		reportButtons.forEach((button) => {
			button.tabIndex = -1;
			button.addEventListener('click', (e) => {
				e.preventDefault();
				selectReportType(button);
			});
		});
		if (reportButtons.length) {
			reportButtons[0].tabIndex = 0;
		}

		if (reportTypeGroup) {
			reportTypeGroup.addEventListener('keydown', (e) => {
				const keys = ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'];
				if (!keys.includes(e.key)) {
					return;
				}
				const options = Array.from(reportButtons).filter((btn) => !btn.disabled);
				if (!options.length) {
					return;
				}
				const currentIndex = options.findIndex((btn) => btn === document.activeElement || btn.getAttribute('aria-checked') === 'true');
				let nextIndex = currentIndex >= 0 ? currentIndex : 0;
				if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
					nextIndex = (nextIndex + 1) % options.length;
				} else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
					nextIndex = (nextIndex - 1 + options.length) % options.length;
				} else if (e.key === 'Home') {
					nextIndex = 0;
				} else if (e.key === 'End') {
					nextIndex = options.length - 1;
				}
				e.preventDefault();
				const next = options[nextIndex];
				options.forEach((btn) => {
					btn.tabIndex = btn === next ? 0 : -1;
				});
				next.focus();
			});
		}

		if (formatSelect) {
			formatSelect.addEventListener('change', updateExportOptionVisibility);
		}
		if (teamVariantSelect) {
			teamVariantSelect.addEventListener('change', updateExportOptionVisibility);
		}

		// Build report URL with correct params per type (API expects specific param names)
		function buildReportUrl(apiUrl, reportType, startDate, endDate) {
			const url = new URL(apiUrl, window.location.origin);
			if (reportType === 'daily') {
				url.searchParams.set('date', startDate);
			} else if (reportType === 'weekly') {
				url.searchParams.set('weekStart', startDate);
			} else if (reportType === 'monthly') {
				if (startDate.length >= 7) {
					url.searchParams.set('month', startDate.substring(0, 7));
				}
				url.searchParams.set('startDate', startDate);
				url.searchParams.set('endDate', endDate);
			} else {
				url.searchParams.set('startDate', startDate);
				url.searchParams.set('endDate', endDate);
			}
			return url.toString();
		}

		// Format period for display (API returns object { start, end } or string)
		function formatPeriod(period) {
			if (period == null) return '';
			if (typeof period === 'string') return period;
			if (typeof period === 'object' && period.start != null && period.end != null) {
				return `${period.start} – ${period.end}`;
			}
			if (typeof period === 'object' && (period.start != null || period.end != null)) {
				return (period.start || '') + (period.end ? ` – ${period.end}` : '');
			}
			return '';
		}

		// Render report data as HTML (never show raw JSON). Handles daily, weekly, monthly, overtime, absence, team, compliance.
		function renderPremiumBucketTable(buckets, L, reportTd, title) {
			let html = '';
			html += `<h4 class="report-subhead">${esc(title || L.premiumsTitle || 'Hour premiums')}</h4>`;
			html += `<div class="table-container" role="region"><table class="table table--hover azc-table--responsive report-table"><thead><tr>`
				+ `<th>${esc(L.premiumBucket || 'Category')}</th>`
				+ `<th>${esc(L.hours || 'Hours')}</th>`
				+ `<th>${esc(L.premiumRate || 'Rate')}</th>`
				+ `<th>${esc(L.premiumValued || 'Valued hours')}</th>`
				+ `</tr></thead><tbody>`;
			if (!buckets.length) {
				html += `<tr>`
					+ reportTd(L.premiumBucket || 'Category', '—')
					+ reportTd(L.hours || 'Hours', '0')
					+ reportTd(L.premiumRate || 'Rate', '—')
					+ reportTd(L.premiumValued || 'Valued hours', '0')
					+ `</tr>`;
			} else {
				buckets.forEach((b) => {
					const pct = b.rate_percent != null ? `${b.rate_percent}%` : (b.rate != null ? `${b.rate}%` : '—');
					html += `<tr>`
						+ reportTd(L.premiumBucket || 'Category', esc(b.label || b.id || '-'))
						+ reportTd(L.hours || 'Hours', esc(b.hours != null ? b.hours : '0'))
						+ reportTd(L.premiumRate || 'Rate', esc(pct))
						+ reportTd(L.premiumValued || 'Valued hours', esc(b.valued_hours != null ? b.valued_hours : '0'))
						+ `</tr>`;
				});
			}
			html += '</tbody></table></div>';
			return html;
		}

		function renderReportHtml(report) {
			if (!report) return '';
			const L = A.l10n || {};
			const reportTd = (label, content) => `<td data-label="${esc(label)}">${content}</td>`;
			let html = '<div class="report-result">';
			if (report.from_month_closure_snapshot) {
				html += `<p class="report-meta report-meta--sealed" role="status">${esc(L.monthClosureSealed || 'This month is sealed. Figures below match the closed-month snapshot (including frozen hour premiums).')}</p>`;
			}
			if (report.date) html += `<p class="report-meta"><strong>${L.date || 'Date'}:</strong> ${esc(report.date)}</p>`;
			const periodStr = formatPeriod(report.period);
			if (periodStr) html += `<p class="report-meta"><strong>${L.period || 'Period'}:</strong> ${esc(periodStr)}</p>`;
			if (report.total_hours != null) html += `<p class="report-meta"><strong>${L.totalHours || 'Total hours'}:</strong> ${esc(report.total_hours)}</p>`;
			if (report.totalHours != null && report.total_hours === undefined) html += `<p class="report-meta"><strong>${L.totalHours || 'Total hours'}:</strong> ${esc(report.totalHours)}</p>`;
			if (report.total_violations != null) html += `<p class="report-meta"><strong>${L.violations || 'Violations'}:</strong> ${esc(report.total_violations)}</p>`;
			if (report.violations_count != null) html += `<p class="report-meta"><strong>${L.violations || 'Violations'}:</strong> ${esc(report.violations_count)}</p>`;
			if (report.total_overtime != null) html += `<p class="report-meta"><strong>${L.overtime || 'Overtime'}:</strong> ${esc(report.total_overtime)} h</p>`;
			if (report.work_location_days && typeof report.work_location_days === 'object') {
				const wld = report.work_location_days;
				html += `<p class="report-meta"><strong>${L.workLocationDays || 'Work location days'}:</strong> `
					+ esc(L.workLocationDaysHome || 'Home office') + ` ${esc(wld.home ?? 0)}, `
					+ esc(L.workLocationDaysExternal || 'External appointments') + ` ${esc(wld.external ?? 0)}</p>`;
				html += `<p class="report-meta report-meta--hint">${esc(L.workLocationDaysHint || 'A day counts toward a location when at least one entry that day carries it — mixed days appear under both.')}</p>`;
			}
			if (report.workLocation && report.workLocation.byCode && typeof report.workLocation.byCode === 'object') {
				const wlCodes = Object.keys(report.workLocation.byCode);
				if (wlCodes.length) {
					html += `<div class="table-container" role="region"><table class="table report-table report-table--work-location"><thead><tr>`
						+ `<th>${esc(L.workLocation || 'Work location')}</th>`
						+ `<th>${esc(L.workLocationCategory || 'Category')}</th>`
						+ `<th>${esc(L.days || 'Days')}</th>`
						+ `<th>${esc(L.workLocationNetHours || 'Net hours')}</th>`
						+ `</tr></thead><tbody>`;
					wlCodes.forEach((wlCode) => {
						const wlRow = report.workLocation.byCode[wlCode] || {};
						const wlMinutes = typeof wlRow.netMinutes === 'number' ? wlRow.netMinutes : 0;
						html += `<tr><td data-label="${esc(L.workLocation || 'Work location')}">${esc(wlRow.label || wlCode)}</td>`
							+ `<td data-label="${esc(L.workLocationCategory || 'Category')}">${esc(wlRow.category || '-')}</td>`
							+ `<td data-label="${esc(L.days || 'Days')}">${esc(wlRow.days != null ? wlRow.days : 0)}</td>`
							+ `<td data-label="${esc(L.workLocationNetHours || 'Net hours')}">${esc((wlMinutes / 60).toFixed(2))}</td></tr>`;
					});
					html += `</tbody></table></div>`;
					if (report.workLocation.mixedDays != null && report.workLocation.mixedDays > 0) {
						html += `<p class="report-meta report-meta--hint">${esc((L.workLocationMixedDays || 'Days with entries at more than one location: {n}').replace('{n}', report.workLocation.mixedDays))}</p>`;
					}
				}
			}
			if (report.total_undertime != null) html += `<p class="report-meta"><strong>${L.undertime || 'Undertime'}:</strong> ${esc(report.total_undertime)} h</p>`;
			if (report.bank_enabled) html += `<p class="report-meta"><strong>${L.overtimeBank || 'Overtime bank'}:</strong> ${esc(L.enabled || 'Enabled')}</p>`;
			if (report.entries && report.entries.length) {
				html += `<div class="table-container" role="region"><table class="table table--hover azc-table--responsive report-table"><thead><tr><th>${L.date || 'Date'}</th><th>${L.hours || 'Hours'}</th></tr></thead><tbody>`;
				report.entries.forEach((entry) => {
					html += `<tr><td data-label="${esc(L.date || 'Date')}">${esc(entry.date || entry.start || '-')}</td><td data-label="${esc(L.hours || 'Hours')}">${esc(entry.hours != null ? entry.hours : (entry.duration || '-'))}</td></tr>`;
				});
				html += '</tbody></table></div>';
			}
			const isAbsenceReport = report.type === 'absence' || report.absences_by_type || report.absences_by_status;
			const isComplianceReport = report.violations_by_type || report.violations_by_severity;

			if (isAbsenceReport) {
				html += `<p class="report-meta"><strong>${L.absences || 'Absences'}:</strong> ${esc(report.total_absences != null ? report.total_absences : '')}</p>`;
				html += `<p class="report-meta"><strong>${L.totalDays || 'Total days'}:</strong> ${esc(report.total_days != null ? report.total_days : '')}</p>`;

				if (report.absences_by_type && typeof report.absences_by_type === 'object') {
					const rows = Object.entries(report.absences_by_type)
						.sort((a, b) => (b[1] ?? 0) - (a[1] ?? 0));
					html += `<h4 class="report-subhead">${esc(L.absencesByType || 'Absences by type')}</h4>`;
					html += `<div class="table-container" role="region"><table class="table table--hover azc-table--responsive report-table"><thead><tr><th>${esc(L.type || 'Type')}</th><th>${esc(L.count || 'Count')}</th></tr></thead><tbody>`;
					rows.forEach(([k, v]) => {
						html += `<tr>${reportTd(L.type || 'Type', esc(k))}${reportTd(L.count || 'Count', esc(v))}</tr>`;
					});
					html += '</tbody></table></div>';
				}

				if (report.absences_by_status && typeof report.absences_by_status === 'object') {
					const rows = Object.entries(report.absences_by_status)
						.sort((a, b) => (b[1] ?? 0) - (a[1] ?? 0));
					html += `<h4 class="report-subhead">${esc(L.absencesByStatus || 'Absences by status')}</h4>`;
					html += `<div class="table-container" role="region"><table class="table table--hover azc-table--responsive report-table"><thead><tr><th>${esc(L.status || 'Status')}</th><th>${esc(L.count || 'Count')}</th></tr></thead><tbody>`;
					rows.forEach(([k, v]) => {
						html += `<tr>${reportTd(L.status || 'Status', esc(k))}${reportTd(L.count || 'Count', esc(v))}</tr>`;
					});
					html += '</tbody></table></div>';
				}

				// Flatten per-user absences for a simple, predictable preview.
				if (report.users && report.users.length) {
					const allAbsences = [];
					report.users.forEach((u) => {
						(u.absences || []).forEach((a) => {
							allAbsences.push({
								user_name: u.display_name || u.user_id || '-',
								type: a.type || '-',
								start: a.start_date || '',
								end: a.end_date || '',
								days: a.days != null ? a.days : '',
								status: a.status || '-',
							});
						});
					});

					allAbsences.sort((a, b) => {
						const t = String(a.type).localeCompare(String(b.type));
						if (t !== 0) return t;
						return String(a.start).localeCompare(String(b.start));
					});

					if (allAbsences.length) {
						html += `<h4 class="report-subhead">${esc(L.details || 'Details')}</h4>`;
						html += `<div class="table-container" role="region"><table class="table table--hover azc-table--responsive report-table"><thead><tr><th>${esc(L.name || 'Name')}</th><th>${esc(L.type || 'Type')}</th><th>${esc(L.startDateCol || L.startDate || 'Start')}</th><th>${esc(L.endDateCol || L.endDate || 'End')}</th><th>${esc(L.days || 'Days')}</th><th>${esc(L.status || 'Status')}</th></tr></thead><tbody>`;
						allAbsences.forEach((a) => {
							html += `<tr>${reportTd(L.name || 'Name', esc(a.user_name))}${reportTd(L.type || 'Type', esc(a.type))}${reportTd(L.startDateCol || L.startDate || 'Start', esc(a.start))}${reportTd(L.endDateCol || L.endDate || 'End', esc(a.end))}${reportTd(L.days || 'Days', esc(a.days))}${reportTd(L.status || 'Status', esc(a.status))}</tr>`;
						});
						html += '</tbody></table></div>';
					}
				}
			} else if (isComplianceReport) {
				// Compliance report: show totals and grouped breakdowns.
				if (report.violations_by_type && typeof report.violations_by_type === 'object') {
					const rows = Object.entries(report.violations_by_type)
						.sort((a, b) => (b[1] ?? 0) - (a[1] ?? 0))
						.slice(0, 10);
					html += `<h4 class="report-subhead">${esc(L.violationTypes || 'Violation types')}</h4>`;
					html += `<div class="table-container" role="region"><table class="table table--hover azc-table--responsive report-table"><thead><tr><th>${esc(L.type || 'Type')}</th><th>${esc(L.count || 'Count')}</th></tr></thead><tbody>`;
					rows.forEach(([k, v]) => {
						html += `<tr>${reportTd(L.type || 'Type', esc(k))}${reportTd(L.count || 'Count', esc(v))}</tr>`;
					});
					html += '</tbody></table></div>';
				}

				if (report.violations_by_severity && typeof report.violations_by_severity === 'object') {
					const rows = Object.entries(report.violations_by_severity)
						.sort((a, b) => (b[1] ?? 0) - (a[1] ?? 0))
						.slice(0, 10);
					html += `<h4 class="report-subhead">${esc(L.severities || 'Severities')}</h4>`;
					html += `<div class="table-container" role="region"><table class="table table--hover azc-table--responsive report-table"><thead><tr><th>${esc(L.severity || 'Severity')}</th><th>${esc(L.count || 'Count')}</th></tr></thead><tbody>`;
					rows.forEach(([k, v]) => {
						html += `<tr>${reportTd(L.severity || 'Severity', esc(k))}${reportTd(L.count || 'Count', esc(v))}</tr>`;
					});
					html += '</tbody></table></div>';
				}
			} else if (report.type === 'premium') {
				html += `<p class="report-meta form-help">${esc(L.premiumHint || 'Hours with a percentage for reporting — not pay, and not your overtime Saldo.')}</p>`;
				if (report.policy_version != null) {
					html += `<p class="report-meta"><strong>${esc(L.policyVersion || 'Policy version')}:</strong> ${esc(report.policy_version)}</p>`;
				}
				const users = Array.isArray(report.users) ? report.users : [];
				if (!users.length) {
					html += `<p class="report-meta" role="status">${esc(L.premiumEmpty || 'No premium hours in this period.')}</p>`;
				} else {
					html += `<h4 class="report-subhead">${esc(L.premiumsTitle || 'Hour premiums')}</h4>`;
					html += `<div class="table-container" role="region"><table class="table table--hover azc-table--responsive report-table"><thead><tr>`
						+ `<th>${esc(L.name || 'Name')}</th>`
						+ `<th>${esc(L.premiumBucket || 'Category')}</th>`
						+ `<th>${esc(L.hours || 'Hours')}</th>`
						+ `<th>${esc(L.premiumRate || 'Rate')}</th>`
						+ `<th>${esc(L.premiumValued || 'Valued hours')}</th>`
						+ `</tr></thead><tbody>`;
					users.forEach((u) => {
						const buckets = Array.isArray(u.buckets) ? u.buckets : [];
						if (!buckets.length) {
							html += `<tr>`
								+ reportTd(L.name || 'Name', esc(u.display_name || u.user_id || '-'))
								+ reportTd(L.premiumBucket || 'Category', '—')
								+ reportTd(L.hours || 'Hours', '0')
								+ reportTd(L.premiumRate || 'Rate', '—')
								+ reportTd(L.premiumValued || 'Valued hours', '0')
								+ `</tr>`;
							return;
						}
						buckets.forEach((b) => {
							const pct = b.rate != null ? Math.round(Number(b.rate) * 100) + '%' : '—';
							html += `<tr>`
								+ reportTd(L.name || 'Name', esc(u.display_name || u.user_id || '-'))
								+ reportTd(L.premiumBucket || 'Category', esc(b.label || b.id || '-'))
								+ reportTd(L.hours || 'Hours', esc(b.hours != null ? b.hours : '0'))
								+ reportTd(L.premiumRate || 'Rate', esc(pct))
								+ reportTd(L.premiumValued || 'Valued hours', esc(b.valued_hours != null ? b.valued_hours : '0'))
								+ `</tr>`;
						});
					});
					html += '</tbody></table></div>';
				}
			} else if (report.type === 'overtime' && report.users && report.users.length) {
				const bankOn = report.bank_enabled === true;
				html += `<h4 class="report-subhead">${esc(L.users || 'Users')}</h4>`;
				html += `<div class="table-container" role="region"><table class="table table--hover azc-table--responsive report-table"><thead><tr>`
					+ `<th>${esc(L.name || 'Name')}</th>`
					+ `<th>${esc(L.workedHours || L.hours || 'Worked')}</th>`
					+ `<th>${esc(L.requiredHours || 'Required')}</th>`
					+ `<th>${esc(L.periodOvertime || 'Period +')}</th>`
					+ `<th>${esc(L.periodUndertime || 'Period −')}</th>`
					+ `<th>${esc(bankOn ? (L.effectiveBalance || 'Effective balance') : (L.balance || 'Balance'))}</th>`
					+ (bankOn ? `<th>${esc(L.payoutEligible || 'Payout eligible')}</th>` : '')
					+ `</tr></thead><tbody>`;
				report.users.forEach((u) => {
					html += `<tr>`
						+ reportTd(L.name || 'Name', esc(u.display_name || u.user_id || '-'))
						+ reportTd(L.workedHours || L.hours || 'Worked', esc(u.total_hours_worked != null ? u.total_hours_worked : '-'))
						+ reportTd(L.requiredHours || 'Required', esc(u.required_hours != null ? u.required_hours : '-'))
						+ reportTd(L.periodOvertime || 'Period +', esc(u.period_overtime_hours != null ? u.period_overtime_hours : (u.overtime_hours > 0 ? u.overtime_hours : '0')))
						+ reportTd(L.periodUndertime || 'Period −', esc(u.period_undertime_hours != null ? u.period_undertime_hours : '-'))
						+ reportTd(bankOn ? (L.effectiveBalance || 'Effective balance') : (L.balance || 'Balance'), esc(u.effective_balance != null ? u.effective_balance : (u.cumulative_balance != null ? u.cumulative_balance : '-')))
						+ (bankOn ? reportTd(L.payoutEligible || 'Payout eligible', esc(u.payout_eligible_hours != null ? u.payout_eligible_hours : '0')) : '')
						+ `</tr>`;
				});
				html += '</tbody></table></div>';
			} else if (report.members && report.members.length) {
				// Team report (aggregated members)
				const showWl = report.members.some((m) => m.home_office_days != null || m.external_appointment_days != null);
				html += `<h4 class="report-subhead">${L.users || 'Users'}</h4><div class="table-container" role="region"><table class="table table--hover azc-table--responsive report-table"><thead><tr><th>${L.name || 'Name'}</th><th>${L.hours || 'Hours'}</th><th>${L.overtime || 'Overtime'}</th>`
					+ (showWl ? `<th>${esc(L.workLocationDaysHome || 'Home office')}</th><th>${esc(L.workLocationDaysExternal || 'External appointments')}</th>` : '')
					+ `</tr></thead><tbody>`;
				report.members.forEach((m) => {
					html += `<tr>${reportTd(L.name || 'Name', esc(m.display_name || m.user_id || '-'))}${reportTd(L.hours || 'Hours', esc(m.total_hours != null ? m.total_hours : '-'))}${reportTd(L.overtime || 'Overtime', esc(m.overtime_hours != null ? m.overtime_hours : '-'))}`
						+ (showWl ? reportTd(L.workLocationDaysHome || 'Home office', esc(m.home_office_days != null ? m.home_office_days : '0')) + reportTd(L.workLocationDaysExternal || 'External appointments', esc(m.external_appointment_days != null ? m.external_appointment_days : '0')) : '')
						+ `</tr>`;
				});
				html += '</tbody></table></div>';
			} else if (report.users && report.users.length) {
				const showWl = report.users.some((u) => u.work_location_days && typeof u.work_location_days === 'object');
				html += `<h4 class="report-subhead">${L.users || 'Users'}</h4><div class="table-container" role="region"><table class="table table--hover azc-table--responsive report-table"><thead><tr><th>${L.name || 'Name'}</th><th>${L.hours || 'Hours'}</th><th>${L.overtime || 'Overtime'}</th>`
					+ (showWl ? `<th>${esc(L.workLocationDaysHome || 'Home office')}</th><th>${esc(L.workLocationDaysExternal || 'External appointments')}</th>` : '')
					+ `</tr></thead><tbody>`;
				report.users.forEach((u) => {
					const wld = (u.work_location_days && typeof u.work_location_days === 'object') ? u.work_location_days : null;
					html += `<tr>${reportTd(L.name || 'Name', esc(u.display_name || u.user_id || '-'))}${reportTd(L.hours || 'Hours', esc(u.total_hours != null ? u.total_hours : (u.total_hours_worked != null ? u.total_hours_worked : '-')))}${reportTd(L.overtime || 'Overtime', esc(u.overtime_hours != null ? u.overtime_hours : '-'))}`
						+ (showWl ? reportTd(L.workLocationDaysHome || 'Home office', esc(wld ? wld.home : '0')) + reportTd(L.workLocationDaysExternal || 'External appointments', esc(wld ? wld.external : '0')) : '')
						+ `</tr>`;
				});
				html += '</tbody></table></div>';
			}
			if (report.daily_breakdown && Object.keys(report.daily_breakdown).length) {
				html += `<h4 class="report-subhead">${L.dailyBreakdown || 'Daily breakdown'}</h4><div class="table-container" role="region"><table class="table table--hover azc-table--responsive report-table"><thead><tr><th>${L.date || 'Date'}</th><th>${L.hours || 'Hours'}</th></tr></thead><tbody>`;
				Object.keys(report.daily_breakdown)
					.sort()
					.forEach((d) => {
						const day = report.daily_breakdown[d];
						html += `<tr>${reportTd(L.date || 'Date', esc(day.date || d))}${reportTd(L.hours || 'Hours', esc(day.total_hours != null ? day.total_hours : '-'))}</tr>`;
					});
				html += '</tbody></table></div>';
			}
			// Sealed monthly report: frozen premiums from month-closure snapshot (NN-06).
			if (report.type !== 'premium' && report.premium && typeof report.premium === 'object' && report.premium.enabled) {
				const frozen = report.premium;
				html += `<p class="report-meta form-help">${esc(L.premiumHint || 'Hours with a percentage for reporting — not pay, and not your overtime Saldo.')}</p>`;
				if (frozen.policy_version != null) {
					html += `<p class="report-meta"><strong>${esc(L.policyVersion || 'Policy version')}:</strong> ${esc(frozen.policy_version)}</p>`;
				}
				const summary = frozen.summary && typeof frozen.summary === 'object' ? frozen.summary : {};
				const buckets = Array.isArray(summary.buckets) ? summary.buckets : [];
				if (!buckets.length) {
					html += `<h4 class="report-subhead">${esc(L.frozenPremiumsTitle || L.premiumsTitle || 'Frozen hour premiums')}</h4>`;
					html += `<p class="report-meta" role="status">${esc(L.premiumEmpty || 'No premium hours in this period.')}</p>`;
				} else {
					html += renderPremiumBucketTable(
						buckets,
						L,
						reportTd,
						L.frozenPremiumsTitle || L.premiumsTitle || 'Frozen hour premiums'
					);
				}
			}
			if (report.summary) {
				const readyMsg = (A.l10n && A.l10n.reportReady) ? String(A.l10n.reportReady).trim() : '';
				const sum = String(report.summary).trim();
				if (!readyMsg || sum !== readyMsg) {
					html += `<p class="report-summary">${esc(report.summary)}</p>`;
				}
			}
			html += '</div>';
			return html;
		}

		// Build params for team report (organization, admin team, manager team) – returns { apiUrl, isTeam, queryParams }
		function resolveScopeAndApi(reportType, startDate, endDate) {
			const apiMap = A.apiUrl || {};
			const scope = reportScopeInput ? reportScopeInput.value : '';
			const adminTeamSelect = document.getElementById('admin-team-select');
			const managerTeamSelect = document.getElementById('manager-team-select');

			// Hour premiums: always use dedicated premium endpoint (supports teamId / org).
			if (reportType === 'premium') {
				if (A.isAdmin && scope === 'organization') {
					return {
						apiUrl: apiMap.premium || null,
						isTeam: false,
						queryParams: { userId: '' },
					};
				}
				if (A.isAdmin && scope === 'admin_team') {
					const teamId = adminTeamSelect && adminTeamSelect.value ? adminTeamSelect.value.trim() : '';
					return {
						apiUrl: apiMap.premium || null,
						isTeam: true,
						queryParams: { teamId, startDate, endDate },
					};
				}
				if (A.isManager && scope === 'manager_team') {
					return {
						apiUrl: apiMap.premium || null,
						isTeam: true,
						queryParams: { managerScope: '1', startDate, endDate },
					};
				}
				if (A.isManager && scope === 'manager_single_team') {
					const teamId = managerTeamSelect && managerTeamSelect.value ? managerTeamSelect.value.trim() : '';
					return {
						apiUrl: apiMap.premium || null,
						isTeam: true,
						queryParams: { teamId, startDate, endDate },
					};
				}
				return {
					apiUrl: apiMap.premium || null,
					isTeam: false,
					queryParams: {},
				};
			}

			// Default: per-user reports (viewer or target user)
			if (!scope || scope === '') {
				return {
					apiUrl: apiMap[reportType] || null,
					isTeam: false,
					queryParams: {},
				};
			}

			// Admin scopes
			if (A.isAdmin) {
				if (scope === 'organization') {
					return {
						apiUrl: apiMap[reportType] || null,
						isTeam: false,
						queryParams: { userId: '' },
					};
				}

				if (scope === 'admin_team') {
					const teamId = adminTeamSelect && adminTeamSelect.value ? adminTeamSelect.value.trim() : '';
					return {
						apiUrl: apiMap.team || null,
						isTeam: true,
						queryParams: {
							teamId,
							startDate,
							endDate,
						},
					};
				}
			}

			// Manager scopes
			if (A.isManager) {
				if (scope === 'manager_team') {
					// "Everyone I manage" – backend resolves via TeamResolverService when userIds and teamId empty
					return {
						apiUrl: apiMap.team || null,
						isTeam: true,
						queryParams: {
							startDate,
							endDate,
						},
					};
				}

				if (scope === 'manager_single_team') {
					const teamId = managerTeamSelect && managerTeamSelect.value ? managerTeamSelect.value.trim() : '';
					return {
						apiUrl: apiMap.team || null,
						isTeam: true,
						queryParams: {
							teamId,
							startDate,
							endDate,
						},
					};
				}
			}

			return {
				apiUrl: apiMap[reportType] || null,
				isTeam: false,
				queryParams: {},
			};
		}

		// Shared: fetch report and show in preview (or show error in preview). Both Preview and Generate use this.
		function fetchAndShowReport() {
			const reportType = reportTypeInput ? reportTypeInput.value : '';
			const dp = window.ArbeitszeitCheckDatepicker;
			const toISO = dp && typeof dp.convertEuropeanToISO === 'function'
				? dp.convertEuropeanToISO
				: (s) => s;
			const startDate = toISO(startDateInput ? startDateInput.value : '');
			const endDate = toISO(endDateInput ? endDateInput.value : '');
			const previewSection = document.getElementById('report-preview');
			const previewContent = document.getElementById('report-preview-content');
			if (!previewSection || !previewContent) {
				return Promise.resolve({ success: false });
			}
			if (!reportType || !startDate || !endDate) {
				const errMsg =
					(A.l10n && A.l10n.reportParamsRequired) ||
					(A.l10n && A.l10n.error) ||
					'Please fill in report type, start date and end date.';
				announceToScreenReader(errMsg);
				previewContent.innerHTML = `<p class="report-error" role="alert">${esc(errMsg)}</p>`;
				setSectionVisible(previewSection, true);
				previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				const h = document.getElementById('report-preview-heading');
				if (h) h.focus();
				return Promise.resolve({ success: false });
			}

			// Validate date range: start must be <= end
			if (startDate > endDate) {
				const dateMsg =
					(A.l10n && A.l10n.dateRangeInvalid) ||
					'Start date must be before or equal to end date.';
				announceToScreenReader(dateMsg);
				previewContent.innerHTML = `<p class="report-error" role="alert">${esc(dateMsg)}</p>`;
				setSectionVisible(previewSection, true);
				previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				return Promise.resolve({ success: false });
			}

			// Validate scope before building URL
			updateScopeFromForm();
			if (!reportScopeInput || !reportScopeInput.value) {
				const scopeMsg =
					(A.l10n && A.l10n.scopeRequired) ||
					'Please choose who should be included in the report.';
				announceToScreenReader(scopeMsg);
				previewContent.innerHTML = `<p class="report-error" role="alert">${esc(scopeMsg)}</p>`;
				setSectionVisible(previewSection, true);
				previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				return Promise.resolve({ success: false });
			}

			const scopeResolution = resolveScopeAndApi(reportType, startDate, endDate);
			if (!scopeResolution.apiUrl) {
				const typeMsg =
					(A.l10n && A.l10n.invalidReportType) ||
					(A.l10n && A.l10n.error) ||
					'Invalid report type.';
				previewContent.innerHTML = `<p class="report-error" role="alert">${esc(typeMsg)}</p>`;
				setSectionVisible(previewSection, true);
				previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				return Promise.resolve({ success: false });
			}

			// If a team-specific scope is selected, ensure a team is chosen
			const adminTeamSelect = document.getElementById('admin-team-select');
			const managerTeamSelect = document.getElementById('manager-team-select');
			if (reportScopeInput.value === 'admin_team') {
				const teamId = adminTeamSelect && adminTeamSelect.value ? adminTeamSelect.value.trim() : '';
				if (!teamId) {
					const teamMsg = (A.l10n && A.l10n.teamRequired) || 'Please select a team.';
					announceToScreenReader(teamMsg);
					previewContent.innerHTML = `<p class="report-error" role="alert">${esc(teamMsg)}</p>`;
					setSectionVisible(previewSection, true);
					previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					return Promise.resolve({ success: false });
				}
			}
			if (reportScopeInput.value === 'manager_single_team') {
				const teamId = managerTeamSelect && managerTeamSelect.value ? managerTeamSelect.value.trim() : '';
				if (!teamId) {
					const teamMsg = (A.l10n && A.l10n.teamRequired) || 'Please select a team.';
					announceToScreenReader(teamMsg);
					previewContent.innerHTML = `<p class="report-error" role="alert">${esc(teamMsg)}</p>`;
					setSectionVisible(previewSection, true);
					previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					return Promise.resolve({ success: false });
				}
			}

			let url;
			if (scopeResolution.isTeam) {
				// Team reports: use team endpoint and its own query params
				const urlObj = new URL(scopeResolution.apiUrl, window.location.origin);
				Object.keys(scopeResolution.queryParams || {}).forEach((key) => {
					const val = scopeResolution.queryParams[key];
					if (val != null && val !== '') {
						urlObj.searchParams.set(key, val);
					}
				});
				url = urlObj.toString();
			} else {
				// Per-user and organization-wide reports: use classic per-type endpoints
				url = buildReportUrl(scopeResolution.apiUrl, reportType, startDate, endDate);
				// If we explicitly want organization-wide and the API supports userId, include it
				if (scopeResolution.queryParams && typeof scopeResolution.queryParams.userId !== 'undefined') {
					const u = new URL(url);
					// Important: organization scope uses an empty string to mean "all users".
					u.searchParams.set('userId', String(scopeResolution.queryParams.userId));
					url = u.toString();
				}
				// Payroll-friendly absence preview: same type-then-date order as CSV export.
				if (reportType === 'absence') {
					const u = new URL(url, window.location.origin);
					u.searchParams.set('sort', 'type');
					url = u.toString();
				}
			}
			const requestToken = getRequestToken();
			const originalPreviewText = previewBtn ? previewBtn.textContent : '';
			const originalGenerateText = generateBtn ? generateBtn.textContent : '';
			const fetchSeq = ++reportFetchSeq;
			if (previewBtn) {
				previewBtn.disabled = true;
				previewBtn.textContent = (A.l10n && A.l10n.generating) || 'Generating...';
			}
			if (generateBtn) {
				generateBtn.disabled = true;
				generateBtn.textContent = (A.l10n && A.l10n.generating) || 'Generating...';
			}
			previewContent.innerHTML = `<p class="report-loading" aria-busy="true">${esc(
				(A.l10n && A.l10n.generating) || 'Generating report...',
			)}</p>`;
			setSectionVisible(previewSection, true);
			announceToScreenReader((A.l10n && A.l10n.generating) || 'Generating report...');
			return fetch(url, { method: 'GET', headers: { requesttoken: requestToken } })
				.then((res) =>
					res.text().then((text) => ({
						ok: res.ok,
						status: res.status,
						text,
					})),
				)
				.then((result) => {
					if (fetchSeq !== reportFetchSeq) {
						return { success: false, stale: true };
					}
					let data = null;
					try {
						data = result.text ? JSON.parse(result.text) : null;
					} catch (err) {
						data = null;
					}
					if (previewBtn) {
						previewBtn.disabled = false;
						previewBtn.textContent = originalPreviewText;
					}
					if (generateBtn) {
						generateBtn.disabled = false;
						generateBtn.textContent = originalGenerateText;
					}
					if (result.ok && data && data.success && data.report) {
						const teamScopes = ['admin_team', 'manager_team', 'manager_single_team'];
						const reportType = reportTypeInput ? reportTypeInput.value : '';
						const scope = reportScopeInput ? reportScopeInput.value : '';
						const isTeamScope = teamScopes.includes(scope) || reportType === 'team';
						const isOrganizationScope = scope === 'organization';
						const orgUserIds =
							isOrganizationScope && data.report && Array.isArray(data.report.users)
								? data.report.users
										.map((u) => (u && u.user_id ? String(u.user_id).trim() : ''))
										.filter((uid) => uid !== '')
								: [];
						if (_reportTeamUsersInput) {
							_reportTeamUsersInput.value = orgUserIds.join(',');
						}
						let html = `<p class="report-success">${esc(
							(A.l10n && A.l10n.reportReady) || 'Report generated successfully.',
						)}</p>`;
						if (isTeamScope && (reportType === 'absence' || reportType === 'compliance')) {
							html += `<p class="report-info" role="status">${esc(
								(A.l10n && A.l10n.teamPreviewWorkingTimeOnly) ||
								'With team scope, this preview shows the team working time summary, not absence or compliance.',
							)}</p>`;
						}
						if (isTeamScope) {
							if (reportType === 'monthly') {
								const teamVar = teamVariantSelect ? teamVariantSelect.value : 'summary';
								const scopeNotice =
									teamVar === 'time_entries' && A.l10n && A.l10n.exportScopeNoticeTimeEntries
										? A.l10n.exportScopeNoticeTimeEntries
										: (A.l10n && A.l10n.exportScopeNotice) ||
											'The download will contain one row per team member matching this preview.';
								html += `<p class="report-info" role="status">${esc(scopeNotice)}</p>`;
							} else if (reportType === 'premium') {
								html += `<p class="report-info" role="status">${esc(
									(A.l10n && A.l10n.teamDownloadPremiumOk) ||
									'Team download for hour premiums includes one row per person and category.',
								)}</p>`;
							} else {
								html += `<p class="report-info" role="status">${esc(
									(A.l10n && A.l10n.teamDownloadOnlyWorkingTimeExport) ||
									'Team file download is only available for the working time export.',
								)}</p>`;
							}
						} else if (isOrganizationScope) {
							html += `<p class="report-info" role="status">${esc(
								(A.l10n && A.l10n.exportOrganizationScopeNotice) ||
								'For organization scope, download is available for working time export.',
							)}</p>`;
						}
						html += renderReportHtml(data.report);
						previewContent.innerHTML = html;
						announceToScreenReader(
							(A.l10n && A.l10n.reportReady) || 'Report generated successfully.',
						);
						previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
						const heading = document.getElementById('report-preview-heading');
						if (heading) heading.focus();
						return { success: true, report: data.report };
					} else {
						let msg = (data && data.error) || '';
						if (!msg) {
							if (result.status === 403 || result.status === 401) {
								msg =
									(A.l10n && A.l10n.sessionExpired) ||
									'Your session may have expired. Please refresh the page and try again.';
							} else {
								msg = (A.l10n && A.l10n.error) || 'An error occurred';
							}
						}
						previewContent.innerHTML = `<p class="report-error" role="alert">${esc(msg)}</p>`;
						announceToScreenReader(msg);
						previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
						return { success: false };
					}
				})
				.catch(() => {
					if (fetchSeq !== reportFetchSeq) {
						return { success: false, stale: true };
					}
					if (previewBtn) {
						previewBtn.disabled = false;
						previewBtn.textContent = originalPreviewText;
					}
					if (generateBtn) {
						generateBtn.disabled = false;
						generateBtn.textContent = originalGenerateText;
					}
					const msg =
						(A.l10n && A.l10n.sessionExpired) ||
						(A.l10n && A.l10n.error) ||
						'An error occurred. Please try again.';
					previewContent.innerHTML = `<p class="report-error" role="alert">${esc(msg)}</p>`;
					announceToScreenReader(msg);
					return { success: false };
				});
		}

		function downloadBlob(content, mime, filename) {
			const blob = new Blob([content], { type: mime });
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = filename;
			a.style.display = 'none';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
		}

		function downloadOvertimeExport(previewPayload, startIso, endIso, format) {
			const report = previewPayload && previewPayload.report ? previewPayload.report : null;
			if (!report || !Array.isArray(report.users)) {
				return;
			}
			const bankOn = report.bank_enabled === true;
			if (format === 'json') {
				downloadBlob(JSON.stringify(report, null, 2), 'application/json', 'overtime-report-' + startIso + '_' + endIso + '.json');
				return;
			}
			// Formula-injection guard is mandatory for client-built CSV
			// (user-controlled cells: user_id, display_name). Fail closed.
			const csvCell = window.ArbeitszeitCheckCsv && typeof window.ArbeitszeitCheckCsv.cell === 'function'
				? window.ArbeitszeitCheckCsv.cell
				: null;
			if (!csvCell) {
				showDownloadError((A.l10n && A.l10n.error) || 'An error occurred. Please try again.');
				return;
			}
			const headers = [
				'user_id',
				'display_name',
				'worked_h',
				'required_h',
				'period_overtime_h',
				'period_undertime_h',
				'effective_balance_h',
			];
			if (bankOn) {
				headers.push('banked_h', 'payout_eligible_h');
			}
			const rows = [headers.join(';')];
			report.users.forEach((u) => {
				const cols = [
					csvCell(u.user_id),
					csvCell(u.display_name),
					csvCell(u.total_hours_worked),
					csvCell(u.required_hours),
					csvCell(u.period_overtime_hours),
					csvCell(u.period_undertime_hours),
					csvCell(u.effective_balance),
				];
				if (bankOn) {
					cols.push(csvCell(u.banked_hours), csvCell(u.payout_eligible_hours));
				}
				rows.push(cols.join(';'));
			});
			const csv = '\uFEFF' + rows.join('\n');
			downloadBlob(csv, 'text/csv;charset=utf-8', 'overtime-report-' + startIso + '_' + endIso + '.csv');
		}

		// Trigger a real file download using the export endpoints
		function downloadReport(previewPayload) {
			const reportType = reportTypeInput ? reportTypeInput.value : '';
			if (!reportType || !startDateInput || !endDateInput) {
				return;
			}
			const dp = window.ArbeitszeitCheckDatepicker;
			const toISO = dp && typeof dp.convertEuropeanToISO === 'function'
				? dp.convertEuropeanToISO
				: (s) => s;
			const startIso = toISO(startDateInput.value || '');
			const endIso = toISO(endDateInput.value || '');
			if (!startIso || !endIso) {
				return;
			}
			const format = formatSelect ? formatSelect.value : 'csv';
			const scope = reportScopeInput ? reportScopeInput.value : '';
			const teamScopes = ['admin_team', 'manager_team', 'manager_single_team'];

			// Hour premiums: server CSV/JSON via dedicated endpoint (personal, org, team).
			if (reportType === 'premium') {
				const apiMap = A.apiUrl || {};
				const premiumApi = apiMap.premium;
				if (!premiumApi) {
					return;
				}
				try {
					const urlObj = new URL(toDownloadHref(premiumApi));
					urlObj.searchParams.set('startDate', startIso);
					urlObj.searchParams.set('endDate', endIso);
					urlObj.searchParams.set('download', '1');
					if (format) {
						urlObj.searchParams.set('format', format);
					}
					if (scope === 'organization') {
						urlObj.searchParams.set('userId', '');
					} else if (scope === 'admin_team') {
						const adminTeamSelect = document.getElementById('admin-team-select');
						const teamId = adminTeamSelect && adminTeamSelect.value ? adminTeamSelect.value.trim() : '';
						if (!teamId) {
							showDownloadError((A.l10n && A.l10n.teamRequired) || 'Please select a team.');
							return;
						}
						urlObj.searchParams.set('teamId', teamId);
					} else if (scope === 'manager_team') {
						urlObj.searchParams.set('managerScope', '1');
					} else if (scope === 'manager_single_team') {
						const managerTeamSelect = document.getElementById('manager-team-select');
						const teamId = managerTeamSelect && managerTeamSelect.value ? managerTeamSelect.value.trim() : '';
						if (!teamId) {
							showDownloadError((A.l10n && A.l10n.teamRequired) || 'Please select a team.');
							return;
						}
						urlObj.searchParams.set('teamId', teamId);
					}
					const a = document.createElement('a');
					a.href = urlObj.toString();
					a.style.display = 'none';
					a.setAttribute('download', '');
					document.body.appendChild(a);
					a.click();
					document.body.removeChild(a);
				} catch (e) {
					// no-op
				}
				return;
			}

			// Organization-wide download is supported for working-time exports via team endpoint.
			if (scope === 'organization') {
				if (reportType !== 'monthly') {
					const msg =
						(A.l10n && A.l10n.teamDownloadWorkingTimeOnly) ||
						'Organization download is only available for the working time export.';
					showDownloadError(msg);
					return;
				}
				const report = previewPayload && previewPayload.report ? previewPayload.report : null;
				const userIds = report && Array.isArray(report.users)
					? report.users
							.map((u) => (u && u.user_id ? String(u.user_id).trim() : ''))
							.filter((uid) => uid !== '')
					: ((_reportTeamUsersInput && _reportTeamUsersInput.value)
						? _reportTeamUsersInput.value.split(',').map((s) => s.trim()).filter((s) => s !== '')
						: []);
				if (userIds.length === 0) {
					const msg =
						(A.l10n && A.l10n.exportOrganizationEmpty) ||
						(A.l10n && A.l10n.exportOrganizationScopeNotice) ||
						'No organization members had time entries in the selected period; nothing to download.';
					showDownloadError(msg);
					return;
				}
				const apiMap = A.apiUrl || {};
				const teamApi = apiMap.team;
				if (!teamApi) {
					return;
				}
				try {
					const urlObj = new URL(toDownloadHref(teamApi));
					urlObj.searchParams.set('startDate', startIso);
					urlObj.searchParams.set('endDate', endIso);
					urlObj.searchParams.set('download', '1');
					urlObj.searchParams.set('userIds', userIds.join(','));
					if (format) {
						urlObj.searchParams.set('format', format);
					}
					const teamVar = teamVariantSelect ? teamVariantSelect.value : 'summary';
					if (teamVar) {
						urlObj.searchParams.set('variant', teamVar);
					}
					const layoutVal = exportLayoutSelect ? exportLayoutSelect.value : 'long';
					if (
						teamVar === 'time_entries' &&
						layoutVal &&
						(format === 'csv' || format === 'json')
					) {
						urlObj.searchParams.set('layout', layoutVal);
					}
					const a = document.createElement('a');
					a.href = urlObj.toString();
					a.style.display = 'none';
					a.setAttribute('download', '');
					document.body.appendChild(a);
					a.click();
					document.body.removeChild(a);
				} catch (e) {
					// no-op
				}
				return;
			}

			// Team downloads only support working time (team report / time entries). Other exports are per-user only.
			if (teamScopes.includes(scope) && reportType !== 'monthly') {
				const msg =
					(A.l10n && A.l10n.teamDownloadWorkingTimeOnly) ||
					'Team download is only available for the working time export. Switch to personal scope to download absence or compliance data.';
				showDownloadError(msg);
				return;
			}

			// Team and manager scopes: export aggregated team report via team API
			if (teamScopes.includes(scope)) {
				const apiMap = A.apiUrl || {};
				const teamApi = apiMap.team;
				if (!teamApi) {
					return;
				}
				const adminTeamSelect = document.getElementById('admin-team-select');
				const managerTeamSelect = document.getElementById('manager-team-select');
				try {
					const urlObj = new URL(toDownloadHref(teamApi));
					// Use the same date range that was used for the preview
					urlObj.searchParams.set('startDate', startIso);
					urlObj.searchParams.set('endDate', endIso);
					urlObj.searchParams.set('download', '1');
					if (format) {
						urlObj.searchParams.set('format', format);
					}
					const teamVar =
						reportType === 'monthly' && teamVariantSelect ? teamVariantSelect.value : 'summary';
					if (teamVar) {
						urlObj.searchParams.set('variant', teamVar);
					}
					const layoutVal = exportLayoutSelect ? exportLayoutSelect.value : 'long';
					if (
						reportType === 'monthly' &&
						teamVar === 'time_entries' &&
						layoutVal &&
						(format === 'csv' || format === 'json')
					) {
						urlObj.searchParams.set('layout', layoutVal);
					}
					if (scope === 'admin_team' && adminTeamSelect && adminTeamSelect.value) {
						urlObj.searchParams.set('teamId', adminTeamSelect.value.trim());
					}
					if (scope === 'manager_single_team' && managerTeamSelect && managerTeamSelect.value) {
						urlObj.searchParams.set('teamId', managerTeamSelect.value.trim());
					}

					const a = document.createElement('a');
					a.href = urlObj.toString();
					a.style.display = 'none';
					a.setAttribute('download', '');
					document.body.appendChild(a);
					a.click();
					document.body.removeChild(a);
				} catch (e) {
					// If URL construction fails, silently skip download to avoid breaking preview
				}
				return;
			}

			if (reportType === 'overtime') {
				downloadOvertimeExport(previewPayload, startIso, endIso, format);
				return;
			}

			// Per-user / organization-wide exports use the dedicated export endpoints
			let exportKey = 'timeEntries';
			if (reportType === 'absence') {
				exportKey = 'absences';
			} else if (reportType === 'compliance') {
				exportKey = 'compliance';
			}
			const exportBase =
				A.exportUrl && Object.prototype.hasOwnProperty.call(A.exportUrl, exportKey)
					? A.exportUrl[exportKey]
					: null;
			if (!exportBase) {
				return;
			}
			try {
				const urlObj = new URL(toDownloadHref(exportBase));
				if (startIso) urlObj.searchParams.set('startDate', startIso);
				if (endIso) urlObj.searchParams.set('endDate', endIso);
				if (format) urlObj.searchParams.set('format', format);
				if (reportType === 'absence') {
					urlObj.searchParams.set('sort', 'type');
				}
				const layoutVal = exportLayoutSelect ? exportLayoutSelect.value : 'long';
				if (
					reportType === 'monthly' &&
					layoutVal &&
					(format === 'csv' || format === 'json')
				) {
					urlObj.searchParams.set('layout', layoutVal);
				}
				const a = document.createElement('a');
				a.href = urlObj.toString();
				a.style.display = 'none';
				a.setAttribute('download', '');
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
			} catch (e) {
				// If URL construction fails, silently skip download to avoid breaking preview
			}
		}

		if (reportForm) {
			reportForm.addEventListener('submit', async (e) => {
				e.preventDefault();
				const previewPayload = await fetchAndShowReport();
				if (previewPayload && previewPayload.success) {
					downloadReport(previewPayload);
				}
			});
		}
		if (previewBtn) {
			previewBtn.addEventListener('click', (e) => {
				e.preventDefault();
				fetchAndShowReport();
			});
		}

		// Admin DATEV payroll downloads (same date range as the report form).
		(function initDatevExportPanel() {
			if (!A.isAdmin) {
				return;
			}
			const panel = document.getElementById('datev-export-panel');
			const statusEl = document.getElementById('datev-export-status');
			const errorEl = document.getElementById('datev-export-error');
			const btnSelf = document.getElementById('btn-datev-self');
			const btnOrg = document.getElementById('btn-datev-org');
			if (!panel || !statusEl || !btnSelf || !btnOrg) {
				return;
			}
			const L = A.l10n || {};
			const exportUrls = A.exportUrl || {};
			let orgConfigured = false;
			let selfReady = false;

			function setError(msg) {
				if (!errorEl) {
					return;
				}
				if (!msg) {
					errorEl.hidden = true;
					errorEl.textContent = '';
					return;
				}
				errorEl.hidden = false;
				errorEl.textContent = msg;
			}

			function refreshButtons() {
				btnSelf.disabled = !selfReady;
				btnOrg.disabled = !orgConfigured;
			}

			function readDates() {
				const start = startDateInput && startDateInput.value ? startDateInput.value.trim() : '';
				const end = endDateInput && endDateInput.value ? endDateInput.value.trim() : '';
				return { start, end };
			}

			function triggerDatevDownload(scope) {
				setError('');
				const { start, end } = readDates();
				if (!start || !end) {
					setError(L.datevNeedDates || 'Choose a start and end date above before downloading DATEV.');
					return;
				}
				if (start > end) {
					setError(L.dateRangeInvalid || 'Start date must be before or equal to end date.');
					return;
				}
				const base = exportUrls.datev;
				if (!base) {
					setError(L.datevLoadFailed || 'Could not load DATEV status. Refresh the page or check your permissions.');
					return;
				}
				try {
					const urlObj = new URL(toDownloadHref(base));
					urlObj.searchParams.set('startDate', start);
					urlObj.searchParams.set('endDate', end);
					if (scope === 'organization') {
						urlObj.searchParams.set('scope', 'organization');
					}
					const a = document.createElement('a');
					a.href = urlObj.toString();
					a.style.display = 'none';
					a.setAttribute('download', '');
					document.body.appendChild(a);
					a.click();
					document.body.removeChild(a);
					announceToScreenReader(L.datevDownloadStarted || 'DATEV download started.');
				} catch (e) {
					setError(L.errorTryAgain || 'An error occurred. Please try again.');
				}
			}

			btnSelf.addEventListener('click', (e) => {
				e.preventDefault();
				triggerDatevDownload('self');
			});
			btnOrg.addEventListener('click', (e) => {
				e.preventDefault();
				triggerDatevDownload('organization');
			});

			const configUrl = exportUrls.datevConfig;
			if (!configUrl) {
				statusEl.textContent = L.datevLoadFailed || 'Could not load DATEV status. Refresh the page or check your permissions.';
				refreshButtons();
				return;
			}

			fetch(toDownloadHref(configUrl), {
				credentials: 'same-origin',
				headers: {
					requesttoken: getRequestToken(),
					Accept: 'application/json',
				},
			})
				.then((r) => r.json().then((body) => ({ ok: r.ok, status: r.status, body })))
				.then(({ ok, body }) => {
					if (!ok || !body || !body.success || !body.config) {
						statusEl.textContent = L.datevLoadFailed || 'Could not load DATEV status. Refresh the page or check your permissions.';
						refreshButtons();
						return;
					}
					const cfg = body.config;
					orgConfigured = !!cfg.configured;
					selfReady = !!cfg.ready_for_self_export;
					if (!orgConfigured) {
						statusEl.textContent = L.datevNeedConfig
							|| 'Set Beraternummer and Mandantennummer in Administration → Global settings before downloading DATEV files.';
					} else if (!selfReady) {
						statusEl.textContent = L.datevNeedPersonal
							|| 'Organisation numbers are set, but your DATEV Personalnummer is missing. Add it on your employee profile to download your own file.';
					} else {
						statusEl.textContent = L.datevReadySelf
							|| 'DATEV organisation numbers are set. You can download your own file if your Personalnummer is set.';
					}
					if (orgConfigured) {
						statusEl.textContent += ' '
							+ (L.datevReadyOrg
								|| 'Organisation download skips people without a Personalnummer.');
					}
					refreshButtons();
				})
				.catch(() => {
					statusEl.textContent = L.datevLoadFailed || 'Could not load DATEV status. Refresh the page or check your permissions.';
					refreshButtons();
				});
		})();
	});
})();

