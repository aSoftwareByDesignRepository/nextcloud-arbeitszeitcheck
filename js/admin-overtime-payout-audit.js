/**
 * Admin overtime payout audit registry (read-only)
 *
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const cfg = window.ARBEITSZEITCHECK_OT_PAYOUT_AUDIT || {};
	const i18n = cfg.i18n || {};
	const Utils = window.ArbeitszeitCheckUtils || {};

	function $(sel) {
		if (Utils.$) {
			return Utils.$(sel);
		}
		return document.querySelector(sel);
	}

	function escapeHtml(text) {
		if (Utils.escapeHtml) {
			return Utils.escapeHtml(text);
		}
		const d = document.createElement('div');
		d.textContent = String(text);
		return d.innerHTML;
	}

	function formatHours(value) {
		const n = Number(value);
		if (!Number.isFinite(n)) {
			return '—';
		}
		return n.toFixed(2);
	}

	/** Plural-aware translate (Nextcloud window.n replaces %n). */
	function plural(singular, pluralText, count) {
		const c = Number.isFinite(Number(count)) ? Number(count) : 0;
		if (typeof window.n === 'function') {
			return window.n('arbeitszeitcheck', singular, pluralText, c);
		}
		return String(c === 1 ? singular : pluralText).replace('%n', String(c));
	}

	function formatProcessedAt(value) {
		if (value == null || value === '' || value === '—') {
			return { display: '—', datetime: '' };
		}
		const s = String(value);
		const parsed = Date.parse(s);
		if (!Number.isFinite(parsed)) {
			return { display: s, datetime: '' };
		}
		const d = new Date(parsed);
		const display = d.toLocaleString(undefined, {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		});
		return { display, datetime: d.toISOString() };
	}

	function getFilters() {
		const year = parseInt($('#ot-audit-year')?.value || '0', 10);
		const monthRaw = $('#ot-audit-month')?.value || '';
		const month = monthRaw === '' ? '' : parseInt(monthRaw, 10);
		const userId = ($('#ot-audit-user-id')?.value || '').trim();
		return { year, month, userId };
	}

	function setLoading(loading) {
		const btn = $('#ot-audit-apply');
		if (btn) {
			btn.disabled = loading;
			btn.setAttribute('aria-busy', loading ? 'true' : 'false');
		}
	}

	function setLiveMessage(message) {
		const live = $('#ot-audit-live');
		if (!live) {
			return;
		}
		live.textContent = message || '';
		live.hidden = !message;
	}

	function buildPayoutProcessUrl(year, month) {
		const base = cfg.payoutProcessUrl || '';
		if (!base || !year) {
			return base;
		}
		try {
			const url = new URL(base, window.location.origin);
			url.searchParams.set('year', String(year));
			if (month >= 1 && month <= 12) {
				url.searchParams.set('month', String(month));
			}
			return url.pathname + url.search + url.hash;
		} catch (e) {
			return base;
		}
	}

	function renderGaps(gaps) {
		const gapsSection = $('#ot-audit-gaps-section');
		const gapsList = $('#ot-audit-gaps-list');
		const gapsCount = $('#ot-audit-gaps-count');
		if (!gapsSection || !gapsList) {
			return;
		}

		if (!gaps || gaps.length === 0) {
			gapsSection.hidden = true;
			gapsList.innerHTML = '';
			if (gapsCount) {
				gapsCount.textContent = '';
			}
			return;
		}

		gapsSection.hidden = false;
		if (gapsCount) {
			gapsCount.textContent = plural(
				'%n compliance gap found',
				'%n compliance gaps found',
				gaps.length
			);
		}

		gapsList.innerHTML = gaps.map(function (g) {
			const name = escapeHtml(g.display_name || g.user_id || '');
			const year = g.calendar_year;
			const month = g.calendar_month;
			const period = escapeHtml(String(year) + '-' + String(month).padStart(2, '0'));
			const hoursRaw = g.payout_eligible_hours ?? g.hours ?? '';
			const hoursLabel = (i18n.gapHours || '%s h unpaid').replace('%s', formatHours(hoursRaw));
			const processUrl = escapeHtml(buildPayoutProcessUrl(year, month));
			const processLabel = escapeHtml(i18n.gapProcess || 'Process payout');
			return '<li class="admin-ot-audit-gaps__item">'
				+ '<div class="admin-ot-audit-gaps__meta">'
				+ '<span class="admin-ot-audit-gaps__name">' + name + '</span>'
				+ '<span class="admin-ot-audit-gaps__period">' + period + '</span>'
				+ '</div>'
				+ '<span class="admin-ot-audit-gaps__hours">' + escapeHtml(hoursLabel) + '</span>'
				+ '<a href="' + processUrl + '" class="azc-btn azc-btn--secondary azc-btn--sm">' + processLabel + '</a>'
				+ '</li>';
		}).join('');
	}

	function renderSummary(total, totalHours, meta) {
		const summaryEl = $('#ot-audit-summary');
		if (!summaryEl) {
			return;
		}

		let text = plural(
			'%n payout, %s hours total',
			'%n payouts, %s hours total',
			total
		).replace('%s', formatHours(totalHours));

		if (meta && meta.truncated) {
			summaryEl.classList.add('azc-callout--warning');
			summaryEl.classList.remove('azc-callout--info');
			text += ' ' + (i18n.truncated || 'Showing %1$s of %2$s records.')
				.replace('%1$s', String(meta.shown ?? 0))
				.replace('%2$s', String(total));
		} else {
			summaryEl.classList.remove('azc-callout--warning');
			summaryEl.classList.add('azc-callout--info');
		}

		summaryEl.textContent = text;
		summaryEl.hidden = false;
	}

	async function loadAudit() {
		const tbody = $('#ot-audit-tbody');
		const summaryEl = $('#ot-audit-summary');

		setLiveMessage('');

		const { year, month, userId } = getFilters();
		if (!year || year < 2000 || year > 2100) {
			const msg = i18n.invalidYear || 'Enter a valid year (2000–2100).';
			setLiveMessage(msg);
			if (tbody) {
				tbody.innerHTML = '<tr><td colspan="5" class="admin-ot-audit__empty">'
					+ escapeHtml(msg) + '</td></tr>';
			}
			if (summaryEl) {
				summaryEl.hidden = true;
			}
			renderGaps([]);
			return;
		}

		if (tbody) {
			tbody.innerHTML = '<tr><td colspan="5" class="admin-ot-audit__empty">'
				+ escapeHtml(i18n.loading || 'Loading…') + '</td></tr>';
		}

		const params = new URLSearchParams({ year: String(year) });
		if (month !== '' && month >= 1 && month <= 12) {
			params.append('month', String(month));
		}
		if (userId) {
			params.append('userId', userId);
		}

		setLoading(true);

		try {
			const res = await fetch(cfg.apiUrl + '?' + params.toString(), {
				headers: { requesttoken: OC.requestToken },
				credentials: 'same-origin',
			});
			const data = await res.json();
			if (!data || !data.success) {
				throw new Error(data?.error || 'error');
			}

			const items = data.data?.items || [];
			const total = data.data?.total ?? items.length;
			const totalHours = data.data?.summary?.total_hours ?? 0;
			const meta = data.meta || {};

			renderSummary(total, totalHours, meta);

			if (tbody) {
				if (items.length === 0) {
					tbody.innerHTML = '<tr><td colspan="5" class="admin-ot-audit__empty">'
						+ escapeHtml(i18n.noRecords || '') + '</td></tr>';
				} else {
					tbody.innerHTML = items.map(function (row) {
						const period = row.period || (row.calendar_year + '-' + String(row.calendar_month).padStart(2, '0'));
						const name = row.display_name || row.user_id || '';
						const hours = formatHours(row.hours_paid);
						const processed = formatProcessedAt(row.created_at || row.processed_at);
						const links = [];
						const glyph = (icon) => (window.AzcCatalog && window.AzcCatalog.render) ? window.AzcCatalog.render(icon) : '';
						if (row.audit_log_url) {
							const auditLabel = i18n.auditLog || 'Activity log';
							links.push('<a href="' + escapeHtml(row.audit_log_url) + '" class="azc-btn azc-btn--secondary azc-icon-btn" aria-label="' + escapeHtml(auditLabel) + '" title="' + escapeHtml(auditLabel) + '">'
								+ glyph('scroll-text') + '</a>');
						}
						if (row.pdf_url) {
							const pdfLabel = i18n.monthPdf || 'Month-closure PDF';
							links.push('<a href="' + escapeHtml(row.pdf_url) + '" class="azc-btn azc-btn--secondary azc-icon-btn" target="_blank" rel="noopener noreferrer" aria-label="' + escapeHtml(pdfLabel) + '" title="' + escapeHtml(pdfLabel) + '">'
								+ glyph('file-down') + '</a>');
						}
						const actionsHtml = links.length > 0
							? '<div class="azc-table-actions" role="group" aria-label="' + escapeHtml(i18n.colActions || 'Actions') + '">' + links.join('') + '</div>'
							: '<span class="admin-ot-audit__muted">' + escapeHtml(i18n.noActions || '—') + '</span>';
						const timeCell = processed.datetime
							? '<time datetime="' + escapeHtml(processed.datetime) + '">' + escapeHtml(processed.display) + '</time>'
							: escapeHtml(processed.display);
						const td = (label, html, cls) => Utils.responsiveTd
							? Utils.responsiveTd(label, html, cls)
							: '<td' + (cls ? ' class="' + cls + '"' : '') + '>' + html + '</td>';
						return '<tr>'
							+ td(i18n.colPeriod || 'Period', escapeHtml(period))
							+ td(i18n.colEmployee || 'Employee', escapeHtml(name))
							+ td(i18n.colHoursPaid || 'Hours paid', escapeHtml(hours), 'admin-ot-audit__num')
							+ td(i18n.colProcessed || 'Processed', timeCell)
							+ td(i18n.colActions || 'Actions', actionsHtml, 'admin-ot-audit__actions')
							+ '</tr>';
					}).join('');
				}
			}

			renderGaps(data.compliance_gaps || []);
		} catch (e) {
			const errMsg = (e && e.message && e.message !== 'error') ? String(e.message) : (i18n.error || '');
			if (tbody) {
				tbody.innerHTML = '<tr><td colspan="5" class="admin-ot-audit__empty">'
					+ escapeHtml(errMsg) + '</td></tr>';
			}
			if (summaryEl) {
				summaryEl.hidden = true;
			}
			renderGaps([]);
			setLiveMessage(errMsg);
		} finally {
			setLoading(false);
		}
	}

	function createEmployeePicker() {
		if (typeof window.ArbeitszeitCheck?.initAdminUserPicker !== 'function') {
			return null;
		}
		return window.ArbeitszeitCheck.initAdminUserPicker({
			hiddenSelector: '#ot-audit-user-id',
			searchSelector: '#ot-audit-employee-search',
			listSelector: '#ot-audit-employee-listbox',
			wrapSelector: '#ot-audit-employee-picker',
			statusSelector: '#ot-audit-employee-status',
			searchUrl: cfg.adminUserSearchUrl || '',
			limit: 20,
			minQueryLength: 2,
			idPrefix: 'ot-audit-employee',
			l10n: i18n,
			onChange: function (userId) {
				const clearBtn = $('#ot-audit-clear-employee');
				if (clearBtn) {
					clearBtn.hidden = userId === '';
				}
			},
		});
	}

	function init() {
		const filterForm = $('#ot-audit-filter-form');
		const clearEmployeeBtn = $('#ot-audit-clear-employee');
		const resetBtn = $('#ot-audit-reset');
		const defaultYear = cfg.defaultYear || new Date().getFullYear();

		let employeePicker = createEmployeePicker();
		if (!employeePicker) {
			setLiveMessage(i18n.searchError || 'Employee search is unavailable. Reload the page.');
		}

		if (clearEmployeeBtn) {
			clearEmployeeBtn.addEventListener('click', function () {
				if (employeePicker) {
					employeePicker.clear();
				} else {
					const hidden = $('#ot-audit-user-id');
					const search = $('#ot-audit-employee-search');
					if (hidden) {
						hidden.value = '';
					}
					if (search) {
						search.value = '';
					}
				}
				clearEmployeeBtn.hidden = true;
			});
		}

		if (resetBtn) {
			resetBtn.addEventListener('click', function () {
				const yearEl = $('#ot-audit-year');
				const monthEl = $('#ot-audit-month');
				if (yearEl) {
					yearEl.value = String(defaultYear);
				}
				if (monthEl) {
					monthEl.value = '';
				}
				if (employeePicker) {
					employeePicker.clear();
				} else {
					const hidden = $('#ot-audit-user-id');
					const search = $('#ot-audit-employee-search');
					if (hidden) {
						hidden.value = '';
					}
					if (search) {
						search.value = '';
					}
				}
				if (clearEmployeeBtn) {
					clearEmployeeBtn.hidden = true;
				}
				loadAudit();
			});
		}

		if (filterForm) {
			filterForm.addEventListener('submit', function (event) {
				event.preventDefault();
				loadAudit();
			});
		}

		loadAudit();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
