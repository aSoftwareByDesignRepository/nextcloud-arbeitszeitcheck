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

		const reportCards = document.querySelectorAll('.report-type-card');
		const reportButtons = document.querySelectorAll('.btn-select-report');
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

		// Helper: get request token safely
		function getRequestToken() {
			if (typeof OC !== 'undefined' && OC.requestToken) {
				return OC.requestToken;
			}
			const head = document.querySelector('head');
			return head ? head.getAttribute('data-requesttoken') || '' : '';
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

			const url = OC.generateUrl('/apps/arbeitszeitcheck/api/admin/teams');
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

			const url = OC.generateUrl('/apps/arbeitszeitcheck/api/manager/teams');
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
				const btn = card.querySelector('.btn-select-report');
				if (!btn) return;
				// Keep report types selectable for all scopes.
				btn.disabled = false;
				btn.setAttribute('aria-disabled', 'false');
			});
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
		}

		// Handle report card clicks
		reportCards.forEach((card) => {
			card.addEventListener('click', (e) => {
				// Don't trigger if clicking the button
				if (e.target instanceof HTMLElement && e.target.classList.contains('btn-select-report')) {
					return;
				}
				const button = card.querySelector('.btn-select-report');
				if (button instanceof HTMLElement) {
					button.click();
				}
			});
		});

		// Handle report button clicks
		reportButtons.forEach((button) => {
			button.addEventListener('click', (e) => {
				e.stopPropagation();
				const reportType = button.dataset.report;
				if (reportType && reportTypeInput) {
					reportCards.forEach((card) => card.classList.remove('is-selected'));
					const selectedCard = button.closest('.report-type-card');
					if (selectedCard) {
						selectedCard.classList.add('is-selected');
					}
					reportTypeInput.value = reportType;

					if (teamVariantSelect) {
						teamVariantSelect.value = 'time_entries';
					}
					if (exportLayoutSelect) {
						exportLayoutSelect.value = 'long';
					}

					// Ensure scope is up to date before showing parameters
					updateScopeFromForm();

					// For daily and weekly reports we only really need one anchor date; we keep both fields visible
					// for consistency but will adjust the API parameters later.

					// Show parameters section
					if (reportParameters) {
						reportParameters.style.display = 'block';
						reportParameters.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					}

					// Set default dates (last 30 days) in dd.mm.yyyy format
					const today = new Date();
					const thirtyDaysAgo = new Date(today);
					thirtyDaysAgo.setDate(today.getDate() - 30);
					const toDDMMYYYY = (d) => {
						const day = String(d.getDate()).padStart(2, '0');
						const month = String(d.getMonth() + 1).padStart(2, '0');
						const year = d.getFullYear();
						return `${day}.${month}.${year}`;
					};

					// Defaults per report type so preview and export ranges match user expectations.
					let defaultStart = thirtyDaysAgo;
					let defaultEnd = today;

					if (reportType === 'daily') {
						defaultStart = today;
						defaultEnd = today;
					} else if (reportType === 'weekly') {
						// Backend aligns week start by subtracting JS weekday (w=0 is Sunday).
						const jsDay = today.getDay(); // 0=Sun, 6=Sat
						defaultStart = new Date(today);
						defaultStart.setDate(today.getDate() - jsDay);
						defaultEnd = new Date(defaultStart);
						defaultEnd.setDate(defaultStart.getDate() + 6);
					} else if (reportType === 'monthly') {
						// Rolling window so team/time-entry exports include recent bookings (e.g. overnight
						// shifts last month) instead of only the current calendar month.
						defaultStart = thirtyDaysAgo;
						defaultEnd = today;
					} else {
						// overtime, absence, compliance: keep last-30-days default
						defaultStart = thirtyDaysAgo;
						defaultEnd = today;
					}

					if (startDateInput) startDateInput.value = toDDMMYYYY(defaultStart);
					if (endDateInput) endDateInput.value = toDDMMYYYY(defaultEnd);
					updateExportOptionVisibility();
				}
			});
		});

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
		function renderReportHtml(report) {
			if (!report) return '';
			const L = A.l10n || {};
			let html = '<div class="report-result">';
			if (report.date) html += `<p class="report-meta"><strong>${L.date || 'Date'}:</strong> ${esc(report.date)}</p>`;
			const periodStr = formatPeriod(report.period);
			if (periodStr) html += `<p class="report-meta"><strong>${L.period || 'Period'}:</strong> ${esc(periodStr)}</p>`;
			if (report.total_hours != null) html += `<p class="report-meta"><strong>${L.totalHours || 'Total hours'}:</strong> ${esc(report.total_hours)}</p>`;
			if (report.totalHours != null && report.total_hours === undefined) html += `<p class="report-meta"><strong>${L.totalHours || 'Total hours'}:</strong> ${esc(report.totalHours)}</p>`;
			if (report.total_violations != null) html += `<p class="report-meta"><strong>${L.violations || 'Violations'}:</strong> ${esc(report.total_violations)}</p>`;
			if (report.violations_count != null) html += `<p class="report-meta"><strong>${L.violations || 'Violations'}:</strong> ${esc(report.violations_count)}</p>`;
			if (report.total_overtime != null) html += `<p class="report-meta"><strong>${L.overtime || 'Overtime'}:</strong> ${esc(report.total_overtime)} h</p>`;
			if (report.entries && report.entries.length) {
				html += `<table class="report-table"><thead><tr><th>${L.date || 'Date'}</th><th>${L.hours || 'Hours'}</th></tr></thead><tbody>`;
				report.entries.forEach((entry) => {
					html += `<tr><td>${esc(entry.date || entry.start || '-')}</td><td>${esc(entry.hours != null ? entry.hours : (entry.duration || '-'))}</td></tr>`;
				});
				html += '</tbody></table>';
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
					html += `<table class="report-table"><thead><tr><th>${esc(L.type || 'Type')}</th><th>${esc(L.count || 'Count')}</th></tr></thead><tbody>`;
					rows.forEach(([k, v]) => {
						html += `<tr><td>${esc(k)}</td><td>${esc(v)}</td></tr>`;
					});
					html += '</tbody></table>';
				}

				if (report.absences_by_status && typeof report.absences_by_status === 'object') {
					const rows = Object.entries(report.absences_by_status)
						.sort((a, b) => (b[1] ?? 0) - (a[1] ?? 0));
					html += `<h4 class="report-subhead">${esc(L.absencesByStatus || 'Absences by status')}</h4>`;
					html += `<table class="report-table"><thead><tr><th>${esc(L.status || 'Status')}</th><th>${esc(L.count || 'Count')}</th></tr></thead><tbody>`;
					rows.forEach(([k, v]) => {
						html += `<tr><td>${esc(k)}</td><td>${esc(v)}</td></tr>`;
					});
					html += '</tbody></table>';
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

					if (allAbsences.length) {
						html += `<h4 class="report-subhead">${esc(L.details || 'Details')}</h4>`;
						html += `<table class="report-table"><thead><tr><th>${esc(L.name || 'Name')}</th><th>${esc(L.type || 'Type')}</th><th>${esc(L.startDateCol || L.startDate || 'Start')}</th><th>${esc(L.endDateCol || L.endDate || 'End')}</th><th>${esc(L.days || 'Days')}</th><th>${esc(L.status || 'Status')}</th></tr></thead><tbody>`;
						allAbsences.forEach((a) => {
							html += `<tr><td>${esc(a.user_name)}</td><td>${esc(a.type)}</td><td>${esc(a.start)}</td><td>${esc(a.end)}</td><td>${esc(a.days)}</td><td>${esc(a.status)}</td></tr>`;
						});
						html += '</tbody></table>';
					}
				}
			} else if (isComplianceReport) {
				// Compliance report: show totals and grouped breakdowns.
				if (report.violations_by_type && typeof report.violations_by_type === 'object') {
					const rows = Object.entries(report.violations_by_type)
						.sort((a, b) => (b[1] ?? 0) - (a[1] ?? 0))
						.slice(0, 10);
					html += `<h4 class="report-subhead">${esc(L.violationTypes || 'Violation types')}</h4>`;
					html += `<table class="report-table"><thead><tr><th>${esc(L.type || 'Type')}</th><th>${esc(L.count || 'Count')}</th></tr></thead><tbody>`;
					rows.forEach(([k, v]) => {
						html += `<tr><td>${esc(k)}</td><td>${esc(v)}</td></tr>`;
					});
					html += '</tbody></table>';
				}

				if (report.violations_by_severity && typeof report.violations_by_severity === 'object') {
					const rows = Object.entries(report.violations_by_severity)
						.sort((a, b) => (b[1] ?? 0) - (a[1] ?? 0))
						.slice(0, 10);
					html += `<h4 class="report-subhead">${esc(L.severities || 'Severities')}</h4>`;
					html += `<table class="report-table"><thead><tr><th>${esc(L.severity || 'Severity')}</th><th>${esc(L.count || 'Count')}</th></tr></thead><tbody>`;
					rows.forEach(([k, v]) => {
						html += `<tr><td>${esc(k)}</td><td>${esc(v)}</td></tr>`;
					});
					html += '</tbody></table>';
				}
			} else if (report.members && report.members.length) {
				// Team report (aggregated members)
				html += `<h4 class="report-subhead">${L.users || 'Users'}</h4><table class="report-table"><thead><tr><th>${L.name || 'Name'}</th><th>${L.hours || 'Hours'}</th><th>${L.overtime || 'Overtime'}</th></tr></thead><tbody>`;
				report.members.forEach((m) => {
					html += `<tr><td>${esc(m.display_name || m.user_id || '-')}</td><td>${esc(m.total_hours != null ? m.total_hours : '-')}</td><td>${esc(m.overtime_hours != null ? m.overtime_hours : '-')}</td></tr>`;
				});
				html += '</tbody></table>';
			} else if (report.users && report.users.length) {
				html += `<h4 class="report-subhead">${L.users || 'Users'}</h4><table class="report-table"><thead><tr><th>${L.name || 'Name'}</th><th>${L.hours || 'Hours'}</th><th>${L.overtime || 'Overtime'}</th></tr></thead><tbody>`;
				report.users.forEach((u) => {
					html += `<tr><td>${esc(u.display_name || u.user_id || '-')}</td><td>${esc(u.total_hours != null ? u.total_hours : (u.total_hours_worked != null ? u.total_hours_worked : '-'))}</td><td>${esc(u.overtime_hours != null ? u.overtime_hours : '-')}</td></tr>`;
				});
				html += '</tbody></table>';
			}
			if (report.daily_breakdown && Object.keys(report.daily_breakdown).length) {
				html += `<h4 class="report-subhead">${L.dailyBreakdown || 'Daily breakdown'}</h4><table class="report-table"><thead><tr><th>${L.date || 'Date'}</th><th>${L.hours || 'Hours'}</th></tr></thead><tbody>`;
				Object.keys(report.daily_breakdown)
					.sort()
					.forEach((d) => {
						const day = report.daily_breakdown[d];
						html += `<tr><td>${esc(day.date || d)}</td><td>${esc(day.total_hours != null ? day.total_hours : '-')}</td></tr>`;
					});
				html += '</tbody></table>';
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
			if (!previewSection || !previewContent) return;
			if (!reportType || !startDate || !endDate) {
				const errMsg =
					(A.l10n && A.l10n.reportParamsRequired) ||
					(A.l10n && A.l10n.error) ||
					'Please fill in report type, start date and end date.';
				announceToScreenReader(errMsg);
				previewContent.innerHTML = `<p class="report-error" role="alert">${esc(errMsg)}</p>`;
				previewSection.style.display = 'block';
				previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				const h = document.getElementById('report-preview-heading');
				if (h) h.focus();
				return;
			}

			// Validate date range: start must be <= end
			if (startDate > endDate) {
				const dateMsg =
					(A.l10n && A.l10n.dateRangeInvalid) ||
					'Start date must be before or equal to end date.';
				announceToScreenReader(dateMsg);
				previewContent.innerHTML = `<p class="report-error" role="alert">${esc(dateMsg)}</p>`;
				previewSection.style.display = 'block';
				previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				return;
			}

			// Validate scope before building URL
			updateScopeFromForm();
			if (!reportScopeInput || !reportScopeInput.value) {
				const scopeMsg =
					(A.l10n && A.l10n.scopeRequired) ||
					'Please choose who should be included in the report.';
				announceToScreenReader(scopeMsg);
				previewContent.innerHTML = `<p class="report-error" role="alert">${esc(scopeMsg)}</p>`;
				previewSection.style.display = 'block';
				previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				return;
			}

			const scopeResolution = resolveScopeAndApi(reportType, startDate, endDate);
			if (!scopeResolution.apiUrl) {
				const typeMsg =
					(A.l10n && A.l10n.invalidReportType) ||
					(A.l10n && A.l10n.error) ||
					'Invalid report type.';
				previewContent.innerHTML = `<p class="report-error" role="alert">${esc(typeMsg)}</p>`;
				previewSection.style.display = 'block';
				previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				return;
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
					previewSection.style.display = 'block';
					previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					return;
				}
			}
			if (reportScopeInput.value === 'manager_single_team') {
				const teamId = managerTeamSelect && managerTeamSelect.value ? managerTeamSelect.value.trim() : '';
				if (!teamId) {
					const teamMsg = (A.l10n && A.l10n.teamRequired) || 'Please select a team.';
					announceToScreenReader(teamMsg);
					previewContent.innerHTML = `<p class="report-error" role="alert">${esc(teamMsg)}</p>`;
					previewSection.style.display = 'block';
					previewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					return;
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
			}
			const requestToken = getRequestToken();
			const originalPreviewText = previewBtn ? previewBtn.textContent : '';
			const originalGenerateText = generateBtn ? generateBtn.textContent : '';
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
			previewSection.style.display = 'block';
			announceToScreenReader((A.l10n && A.l10n.generating) || 'Generating report...');
			fetch(url, { method: 'GET', headers: { requesttoken: requestToken } })
				.then((res) =>
					res.text().then((text) => ({
						ok: res.ok,
						status: res.status,
						text,
					})),
				)
				.then((result) => {
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
							} else {
								html += `<p class="report-info" role="status">${esc(
									(A.l10n && A.l10n.teamDownloadOnlyWorkingTimeExport) ||
									'Team file download is only available for the working time export.',
								)}</p>`;
							}
						} else if (isOrganizationScope) {
							html += `<p class="report-info" role="status">${esc(
								(A.l10n && A.l10n.exportOrganizationScopeNotice) ||
								'Export for organization scope is not yet available. Use Preview to view the report.',
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
					}
				})
				.catch(() => {
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
				});
		}

		// Trigger a real file download using the export endpoints
		function downloadReport() {
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

			// Organization-wide download is not implemented (export endpoints are per-user today).
			// To avoid silently downloading the wrong data, block the download and instruct the user.
			if (scope === 'organization') {
				const msg =
					(A.l10n && A.l10n.exportOrganizationScopeNotice) ||
					'Export for organization scope is not yet available. Use Preview to view the report.';
				announceToScreenReader(msg);
				return;
			}

			// Team downloads only support working time (team report / time entries). Other exports are per-user only.
			if (teamScopes.includes(scope) && reportType !== 'monthly') {
				const msg =
					(A.l10n && A.l10n.teamDownloadWorkingTimeOnly) ||
					'Team download is only available for the working time export. Switch to personal scope to download absence or compliance data.';
				announceToScreenReader(msg);
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
					const urlObj = new URL(teamApi, window.location.origin);
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
				const urlObj = new URL(exportBase, window.location.origin);
				if (startIso) urlObj.searchParams.set('startDate', startIso);
				if (endIso) urlObj.searchParams.set('endDate', endIso);
				if (format) urlObj.searchParams.set('format', format);
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
			reportForm.addEventListener('submit', (e) => {
				e.preventDefault();
				fetchAndShowReport();
				downloadReport();
			});
		}
		if (previewBtn) {
			previewBtn.addEventListener('click', (e) => {
				e.preventDefault();
				fetchAndShowReport();
			});
		}
	});
})();

