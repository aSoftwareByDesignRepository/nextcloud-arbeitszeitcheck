/**
 * Admin Users JavaScript for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Components = window.ArbeitszeitCheckComponents || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};

    function parseLocalizedDecimal(value) {
        if (value === null || value === undefined || value === '') {
            return undefined;
        }
        const normalized = String(value).trim().replace(/\s+/g, '').replace(',', '.');
        if (!/^-?\d+(\.\d+)?$/.test(normalized)) {
            return undefined;
        }
        const parsed = Number(normalized);
        return Number.isFinite(parsed) ? parsed : undefined;
    }

    function localizedEntitlementSourceLabel(source, t) {
        if (source === 'manual') return t('sourceManual', 'Manual');
        if (source === 'manual_exception') return t('sourceManualException', 'Manual exception');
        if (source === 'simple_model') return t('sourceSimpleModel', 'Model based');
        if (source === 'tariff') return t('sourceTariff', 'Tariff');
        return source || t('notAvailable', 'Not available');
    }

    function formatPreviewDays(days) {
        const parsed = Number(days);
        if (!Number.isFinite(parsed)) {
            return '0';
        }
        return Number.isInteger(parsed) ? String(parsed) : String(Math.round(parsed * 100) / 100);
    }

    function humanEntitlementLayerLabel(code, t) {
        const key = String(code || '');
        const labels = {
            L0: t('organisationDefault', 'Organisation default'),
            L1: t('workingTimeModelDefault', 'Working time model default'),
            L2: t('teamPolicy', 'Team policy'),
            L3: t('individualRule', 'Individual rule'),
            legacy: t('legacyFallback', 'Legacy fallback (25 d.)'),
            inherit: t('vacationModeInherit', 'Inherit from team / model / organisation'),
        };
        return labels[key] || key;
    }

    /**
     * Plain-language summary for HR (never raw JSON).
     *
     * @param {object|string|null|undefined} trace
     * @param {function(string, string): string} t
     * @returns {string}
     */
    function buildEntitlementPreviewSummary(trace, t) {
        if (!trace) {
            return '';
        }
        if (typeof trace === 'string') {
            return trace;
        }
        if (typeof trace !== 'object') {
            return '';
        }
        if (trace.formula && trace.inputs) {
            const workDays = trace.inputs.work_days_per_week;
            const referenceDays = trace.inputs.reference_days;
            const referenceWeekDays = trace.inputs.reference_week_days;
            if (workDays && referenceDays && referenceWeekDays) {
                return `${trace.formula} (${referenceDays}, ${workDays}/${referenceWeekDays})`;
            }
            return String(trace.formula);
        }
        const matched = trace.matched_layer || trace.winner?.layer;
        if (matched) {
            const layerLabel = humanEntitlementLayerLabel(matched, t);
            const template = t(
                'previewResolvedByLayer',
                'Determined by: {layer}.'
            );
            return template.replace('{layer}', layerLabel);
        }
        const layers = Array.isArray(trace.layers_evaluated) ? trace.layers_evaluated : [];
        const hit = layers.find((row) => row && row.matched === true);
        if (hit && hit.layer) {
            const layerLabel = humanEntitlementLayerLabel(hit.layer, t);
            const days = hit.days != null ? formatPreviewDays(hit.days) : '';
            const mode = hit.mode || hit.reason || '';
            const parts = [t('previewResolvedByLayer', 'Determined by: {layer}.').replace('{layer}', layerLabel)];
            if (days) {
                parts.push(`${days} ${t('vacationDays', 'vacation days')}`);
            }
            if (mode) {
                parts.push(String(mode));
            }
            return parts.join(' ');
        }
        if (trace.degraded) {
            return t(
                'previewDegradedHint',
                'Resolution ran in a degraded state — open technical details or check layered vacation settings.'
            );
        }
        return '';
    }

    /**
     * Audit-oriented JSON for the optional &lt;details&gt; block only.
     *
     * @param {object|null|undefined} trace
     * @returns {string}
     */
    function buildEntitlementPreviewTechnical(trace) {
        if (!trace || typeof trace !== 'object') {
            return '';
        }
        try {
            const json = JSON.stringify(trace, null, 2);
            return json && json !== '{}' ? json : '';
        } catch (e) {
            return '';
        }
    }

    /**
     * @param {number|string} days
     * @param {string} sourceLabel
     * @param {string} summaryText
     * @param {object|string|null|undefined} traceObject
     * @param {function(string, string): string} t
     * @param {object|null|undefined} prorationPreview
     */
    function paintEntitlementPreview(previewEl, days, sourceLabel, summaryText, traceObject, t, prorationPreview) {
        if (!previewEl) {
            return;
        }
        const value = previewEl.querySelector('.entitlement-preview__value');
        const meta = previewEl.querySelector('.entitlement-preview__meta');
        const summary = previewEl.querySelector('.entitlement-preview__summary');
        const details = previewEl.querySelector('.entitlement-preview__details');
        const technical = previewEl.querySelector('.entitlement-preview__technical code');

        if (value) {
            value.textContent = `${formatPreviewDays(days)} ${t('vacationDays', 'vacation days')}`;
        }
        if (meta) {
            meta.textContent = sourceLabel || '';
        }

        const summaryLine = summaryText
            || buildEntitlementPreviewSummary(traceObject, t);
        if (summary) {
            summary.textContent = summaryLine;
            summary.hidden = !summaryLine;
        }

        const technicalJson = buildEntitlementPreviewTechnical(
            traceObject && typeof traceObject === 'object' ? traceObject : null
        );
        if (details) {
            details.hidden = !technicalJson;
        }
        if (technical) {
            technical.textContent = technicalJson;
        }

        paintProrationNote(previewEl, prorationPreview, t);
    }

    /**
     * Create, update, or remove the proration note under the entitlement preview.
     *
     * @param {HTMLElement|null} previewEl
     * @param {object|null|undefined} prorationPreview
     * @param {function(string, string): string} t
     */
    function paintProrationNote(previewEl, prorationPreview, t) {
        if (!previewEl) {
            return;
        }
        let prorationEl = previewEl.querySelector('.entitlement-preview__proration');
        const noteHtml = buildProrationNoteHtml(prorationPreview, t);
        if (!noteHtml) {
            if (prorationEl) {
                prorationEl.remove();
            }
            return;
        }
        if (!prorationEl) {
            prorationEl = document.createElement('p');
            prorationEl.className = 'entitlement-preview__proration';
            const anchor = previewEl.querySelector('.entitlement-preview__meta')
                || previewEl.querySelector('.entitlement-preview__value');
            if (anchor && anchor.parentNode) {
                anchor.insertAdjacentElement('afterend', prorationEl);
            } else {
                previewEl.appendChild(prorationEl);
            }
        }
        prorationEl.outerHTML = noteHtml;
    }

    /**
     * Map simulate/profile API fields to the preview proration shape.
     *
     * @param {object|null|undefined} resp
     * @returns {object|null}
     */
    function buildProrationPreviewFromResponse(resp) {
        if (!resp || !resp.prorated) {
            return null;
        }
        return {
            days: resp.proratedEntitlementDays,
            fullYearDays: resp.fullYearEntitlementDays != null
                ? resp.fullYearEntitlementDays
                : resp.effectiveEntitlementDays,
            prorated: true,
            prorationMethod: resp.prorationMethod,
            monthsCovered: resp.monthsCovered,
            employedInYear: resp.employedInYear,
        };
    }

    /**
     * Headline days for the preview: prorated when applicable.
     *
     * @param {object|null|undefined} resp
     * @returns {number}
     */
    function previewDaysFromResponse(resp) {
        if (!resp) {
            return 0;
        }
        if (resp.prorated && resp.proratedEntitlementDays != null) {
            return Number(resp.proratedEntitlementDays);
        }
        return Number(resp.effectiveEntitlementDays || 0);
    }

    function buildEntitlementPreviewHtml(entitlementPreview, t) {
        const days = entitlementPreview ? formatPreviewDays(entitlementPreview.days) : '';
        const valueText = entitlementPreview
            ? `${days} ${t('vacationDays', 'vacation days')}`
            : t('notAvailable', 'Not available');
        const metaText = entitlementPreview
            ? localizedEntitlementSourceLabel(entitlementPreview.source, t)
            : '';
        const trace = entitlementPreview?.calculationTrace || null;
        const summaryText = buildEntitlementPreviewSummary(trace, t);
        const technicalJson = buildEntitlementPreviewTechnical(trace);
        const detailsBlock = technicalJson
            ? `<details class="entitlement-preview__details">
                    <summary>${Utils.escapeHtml(t('previewTechnicalDetails', 'Technical details (audit)'))}</summary>
                    <pre class="entitlement-preview__technical"><code>${Utils.escapeHtml(technicalJson)}</code></pre>
               </details>`
            : '';

        return `<p class="entitlement-preview__value">${Utils.escapeHtml(valueText)}</p>
            <p class="entitlement-preview__meta">${Utils.escapeHtml(metaText)}</p>
            ${buildProrationNoteHtml(entitlementPreview, t)}
            <p class="entitlement-preview__summary"${summaryText ? '' : ' hidden'}>${Utils.escapeHtml(summaryText)}</p>
            ${detailsBlock}`;
    }

    /**
     * Human-readable note explaining a pro-rata reduction for a partial
     * employment year, e.g. "Prorated for partial year: 20 of 30 days
     * (employed 8 of 12 months)". Returns an empty string when the full annual
     * entitlement applies, so the preview stays uncluttered for the common case.
     */
    function buildProrationNoteHtml(entitlementPreview, t) {
        if (!entitlementPreview || !entitlementPreview.prorated) {
            return '';
        }
        const full = formatPreviewDays(entitlementPreview.fullYearDays);
        const prorated = formatPreviewDays(entitlementPreview.days);
        let note;
        if (entitlementPreview.employedInYear === false) {
            note = t('entitlementNotEmployedThisYear', 'No entitlement this year: the employment period does not cover this calendar year.');
        } else if (entitlementPreview.prorationMethod === 'daily') {
            note = (t('entitlementProratedDaily', 'Prorated for partial year: {prorated} of {full} days (daily method).'))
                .replace('{prorated}', prorated)
                .replace('{full}', full);
        } else {
            const months = String(entitlementPreview.monthsCovered != null ? entitlementPreview.monthsCovered : '');
            note = (t('entitlementProratedTwelfths', 'Prorated for partial year: {prorated} of {full} days (employed {months} of 12 months).'))
                .replace('{prorated}', prorated)
                .replace('{full}', full)
                .replace('{months}', months);
        }
        return `<p class="entitlement-preview__proration">${Utils.escapeHtml(note)}</p>`;
    }

    /** Prefer server-injected l10n; window.t may be unavailable. */
    function auMsg(key, englishFallback) {
        const v = window.ArbeitszeitCheck?.l10n?.[key];
        if (v !== undefined && v !== '') {
            return v;
        }
        if (typeof window.t === 'function' && englishFallback) {
            return window.t('arbeitszeitcheck', englishFallback);
        }
        return englishFallback || '';
    }

    let searchTimeout = null;
    const USERS_TABLE_COLS = 9;
    const FILTER_APP_ACCESS = 'app_access';
    const FILTER_ALL = 'all';
    const cfg = window.ArbeitszeitCheck && window.ArbeitszeitCheck.adminUsersConfig
        ? window.ArbeitszeitCheck.adminUsersConfig
        : {};
    const USERS_PAGE_SIZE = Number(cfg.pageSize) > 0 ? Number(cfg.pageSize) : 50;
    const USERS_MIN_SEARCH = Number(cfg.minSearchLength) >= 0 ? Number(cfg.minSearchLength) : 2;
    let listOffset = Number(cfg.initialOffset) >= 0 ? Number(cfg.initialOffset) : 0;
    let listSearch = '';
    let listTotal = Number(cfg.initialTotal) >= 0 ? Number(cfg.initialTotal) : 0;
    let lastShownCount = Number(cfg.initialShown) >= 0 ? Number(cfg.initialShown) : 0;
    let listAccessFilter = String(cfg.accessFilter || FILTER_ALL);
    let hiddenCount = Number(cfg.hiddenCount) >= 0 ? Number(cfg.hiddenCount) : 0;
    let isAccessRestricted = !!cfg.isAccessRestricted;
    let listTruncated = !!cfg.truncated;
    const accessSettingsUrl = String(cfg.accessSettingsUrl || '/apps/arbeitszeitcheck/admin/settings/access');

    function buildApiUrl(path) {
        if (Utils && typeof Utils.resolveUrl === 'function') {
            return Utils.resolveUrl(path);
        }
        const oc = (typeof window !== 'undefined' && window.OC) || (typeof OC !== 'undefined' ? OC : null);
        if (oc && typeof oc.generateUrl === 'function') {
            return oc.generateUrl(path);
        }
        return path;
    }


    function userDetailUrl(userId) {
        const encodedId = encodeURIComponent(String(userId || ''));
        const tpl = window.ArbeitszeitCheck && window.ArbeitszeitCheck.adminUsersConfig
            && window.ArbeitszeitCheck.adminUsersConfig.userDetailUrlTemplate;
        if (tpl) {
            return buildApiUrl(String(tpl).split('__USER_ID__').join(encodedId));
        }
        return buildApiUrl('/apps/arbeitszeitcheck/admin/users/' + encodedId);
    }


    /**
     * Initialize users page
     */
    function init() {
        bindEvents();
        const initialShown = Number(cfg.initialShown) >= 0 ? Number(cfg.initialShown) : 0;
        const initialTotal = Number(cfg.initialTotal) >= 0 ? Number(cfg.initialTotal) : initialShown;
        listTotal = initialTotal;
        lastShownCount = initialShown;
        updateExportLink();
        updateBanners();
        updateUsersPagination(initialShown, initialTotal, {});
    }

    /**
     * Bind event listeners
     */
    function bindEvents() {
        const searchInput = Utils.$('#user-search');
        if (searchInput) {
            Utils.on(searchInput, 'input', handleSearch);
        }

        const refreshBtn = Utils.$('#refresh-users');
        if (refreshBtn) {
            Utils.on(refreshBtn, 'click', function() {
                clearSearchAndReload();
            });
        }

        const prevBtn = Utils.$('#users-page-prev');
        const nextBtn = Utils.$('#users-page-next');
        if (prevBtn) {
            Utils.on(prevBtn, 'click', function() {
                if (listOffset <= 0 || listSearch !== '') {
                    return;
                }
                listOffset = Math.max(0, listOffset - USERS_PAGE_SIZE);
                loadUsers();
            });
        }
        if (nextBtn) {
            Utils.on(nextBtn, 'click', function() {
                if (listSearch !== '' || listOffset + USERS_PAGE_SIZE >= listTotal) {
                    return;
                }
                listOffset += USERS_PAGE_SIZE;
                loadUsers();
            });
        }

        document.querySelectorAll('input[name="employee-list-access-filter"]').forEach(function(radio) {
            Utils.on(radio, 'change', handleAccessFilterChange);
        });

        const showAllBtn = Utils.$('#employee-list-show-all');
        if (showAllBtn) {
            Utils.on(showAllBtn, 'click', handleShowAllAccounts);
        }

        const tbody = Utils.$('#users-tbody');
        if (tbody) {
            Utils.on(tbody, 'click', function(ev) {
                const target = ev.target && ev.target.closest
                    ? ev.target.closest('[data-action="show-all-accounts"], [data-action="clear-employee-search"]')
                    : null;
                if (!target) {
                    return;
                }
                ev.preventDefault();
                if (target.getAttribute('data-action') === 'show-all-accounts') {
                    handleShowAllAccounts();
                    return;
                }
                if (target.getAttribute('data-action') === 'clear-employee-search') {
                    clearSearchAndReload();
                }
            });
        }

        const exportLink = Utils.$('#export-users-csv');
        if (exportLink) {
            Utils.on(exportLink, 'click', function() {
                const status = Utils.$('#export-status');
                if (status) {
                    status.textContent = auMsg('exportStarted', 'Export started — your download should begin shortly.');
                }
            });
        }

        // Edit / History are plain links to the employee detail page (progressive enhancement).
    }

    function clearSearchAndReload() {
        const searchInput = Utils.$('#user-search');
        if (searchInput) {
            searchInput.value = '';
            searchInput.focus();
        }
        listSearch = '';
        listOffset = 0;
        loadUsers();
    }

    function handleAccessFilterChange(e) {
        if (!e.target || !e.target.checked) {
            return;
        }
        listAccessFilter = String(e.target.value || FILTER_ALL);
        listOffset = 0;
        loadUsers();
    }

    function handleShowAllAccounts() {
        const allRadio = Utils.$('#employee-list-filter-all');
        if (allRadio) {
            allRadio.checked = true;
            listAccessFilter = FILTER_ALL;
            listOffset = 0;
            loadUsers();
        }
    }

    function updateExportLink() {
        const link = Utils.$('#export-users-csv');
        if (!link) {
            return;
        }
        const base = link.getAttribute('data-export-base') || link.getAttribute('href') || '';
        if (!base) {
            return;
        }
        try {
            const url = new URL(base, window.location.origin);
            url.searchParams.set('filter', listAccessFilter);
            link.setAttribute('href', url.pathname + url.search);
        } catch (err) {
            const sep = base.indexOf('?') >= 0 ? '&' : '?';
            link.setAttribute('href', base + sep + 'filter=' + encodeURIComponent(listAccessFilter));
        }
    }

    function updateBanners() {
        const hiddenBanner = Utils.$('#employee-list-hidden-banner');
        const hiddenText = hiddenBanner && hiddenBanner.querySelector('.admin-users-hidden-banner__text');
        const truncatedBanner = Utils.$('#employee-list-truncated-banner');

        if (hiddenBanner) {
            const showHidden = hiddenCount > 0 && listAccessFilter === FILTER_APP_ACCESS;
            hiddenBanner.hidden = !showHidden;
            if (showHidden && hiddenText) {
                const template = auMsg(
                    'hiddenAccountsBanner',
                    '{count} Nextcloud accounts without app access are hidden.'
                );
                const countNode = hiddenText.querySelector('.admin-users-hidden-banner__count');
                if (countNode) {
                    countNode.textContent = String(hiddenCount);
                } else {
                    const prefix = hiddenText.querySelector('.admin-users-hidden-banner__prefix');
                    if (prefix) {
                        prefix.textContent = template.replace('{count}', String(hiddenCount));
                    }
                }
            }
        }

        if (truncatedBanner) {
            truncatedBanner.hidden = !listTruncated;
        }
    }

    /**
     * Handle search input
     */
    function handleSearch(e) {
        const query = e.target.value.trim();
        listSearch = query;
        listOffset = 0;

        clearTimeout(searchTimeout);
        if (query.length > 0 && query.length < USERS_MIN_SEARCH) {
            updateUsersPagination(lastShownCount, listTotal, { searchPending: true });
            return;
        }
        searchTimeout = setTimeout(function() {
            loadUsers();
        }, 300);
    }

    /**
     * Load users from API (paginated browse or search).
     */
    function setTableBusy(busy) {
        const region = document.getElementById('users-table-region');
        const card = document.getElementById('admin-users-list-card');
        const status = document.getElementById('users-list-status');
        if (region) {
            region.setAttribute('aria-busy', busy ? 'true' : 'false');
        }
        if (card) {
            card.setAttribute('aria-busy', busy ? 'true' : 'false');
        }
        if (status) {
            status.textContent = busy
                ? auMsg('loadingEllipsis', 'Loading…')
                : '';
        }
    }

    function loadUsers() {
        const tbody = Utils.$('#users-tbody');
        if (!tbody) {
            return;
        }

        const search = listSearch.trim();
        if (search.length > 0 && search.length < USERS_MIN_SEARCH) {
            updateUsersPagination(lastShownCount, listTotal, { searchPending: true });
            return;
        }

        setTableBusy(true);
        tbody.innerHTML = '<tr><td colspan="' + USERS_TABLE_COLS + '" class="text-center admin-users-loading-cell">'
            + '<span class="admin-users-loading-label">' + Utils.escapeHtml(auMsg('loadingEllipsis', 'Loading…')) + '</span>'
            + '</td></tr>';

        const params = new URLSearchParams({
            limit: String(USERS_PAGE_SIZE),
            filter: listAccessFilter,
        });
        if (search !== '') {
            params.set('search', search);
        } else {
            params.set('offset', String(listOffset));
        }
        const url = buildApiUrl('/apps/arbeitszeitcheck/api/admin/users') + '?' + params.toString();

        Utils.ajax(url, {
            method: 'GET',
            onSuccess: function(data) {
                setTableBusy(false);
                if (data.success && data.users) {
                    listTotal = Number.isFinite(data.total) ? Number(data.total) : data.users.length;
                    listTruncated = !!data.truncated;
                    if (typeof data.hiddenCount === 'number') {
                        hiddenCount = data.hiddenCount;
                    }
                    if (typeof data.filter === 'string' && data.filter) {
                        listAccessFilter = data.filter;
                        document.querySelectorAll('input[name="employee-list-access-filter"]').forEach(function(radio) {
                            radio.checked = radio.value === listAccessFilter;
                        });
                    }
                    if (typeof data.offset === 'number' && search === '') {
                        listOffset = data.offset;
                    }
                    updateExportLink();
                    updateBanners();
                    renderUsers(data.users, listTotal);
                } else {
                    tbody.innerHTML = '<tr><td colspan="' + USERS_TABLE_COLS + '" class="text-center admin-users-empty-cell">'
                        + '<p class="admin-users-empty-message">' + Utils.escapeHtml(auMsg('errorLoadingUsers', 'Error loading users')) + '</p>'
                        + '<button type="button" class="azc-btn azc-btn--secondary" data-action="clear-employee-search">'
                        + Utils.escapeHtml(auMsg('tryAgainReset', 'Reset and try again'))
                        + '</button></td></tr>';
                    updateUsersPagination(0, 0, {});
                }
            },
            onError: function() {
                setTableBusy(false);
                tbody.innerHTML = '<tr><td colspan="' + USERS_TABLE_COLS + '" class="text-center admin-users-empty-cell">'
                    + '<p class="admin-users-empty-message">' + Utils.escapeHtml(auMsg('errorLoadingUsers', 'Error loading users')) + '</p>'
                    + '<button type="button" class="azc-btn azc-btn--secondary" data-action="clear-employee-search">'
                    + Utils.escapeHtml(auMsg('tryAgainReset', 'Reset and try again'))
                    + '</button></td></tr>';
                if (Messaging && Messaging.showError) {
                    Messaging.showError(auMsg('failedToLoadUsersRetry', 'Failed to load users. Please try again.'));
                }
                updateUsersPagination(0, 0, {});
            },
        });
    }

    function formatPaginationText(shown, total, options) {
        const opts = options || {};
        if (opts.searchPending) {
            return auMsg('searchMinLength', 'Type at least 2 characters to search.');
        }
        const count = Number.isFinite(shown) ? shown : 0;
        const totalCount = Number.isFinite(total) ? total : count;
        if (listSearch.trim() !== '') {
            let text = auMsg('searchMatches', '{count} employees match your search')
                .replace('{count}', String(count));
            if (listTruncated || count >= USERS_PAGE_SIZE) {
                text += ' ' + auMsg('searchRefineHint', 'More than {count} matches — refine your search to find a specific person.')
                    .replace('{count}', String(count));
            }
            return text;
        }
        if (totalCount <= 0 || count <= 0) {
            return auMsg('noUsersFound', 'No users found');
        }
        const from = listOffset + 1;
        const to = listOffset + count;
        let text = auMsg('showingEmployeesRange', 'Showing employees {from}–{to} of {total}')
            .replace('{from}', String(from))
            .replace('{to}', String(to))
            .replace('{total}', String(totalCount));
        if (listAccessFilter === FILTER_APP_ACCESS && isAccessRestricted) {
            text += ' ' + auMsg('paginationAppAccessOnly', '(with app access only)');
        } else if (listAccessFilter === FILTER_ALL && isAccessRestricted) {
            text += ' ' + auMsg('paginationAllAccounts', '(all Nextcloud accounts)');
        }
        return text;
    }

    function updateUsersPagination(shown, total, options) {
        const meta = document.getElementById('users-pagination');
        const textEl = document.getElementById('users-pagination-text');
        const pager = document.getElementById('users-pager');
        const prevBtn = Utils.$('#users-page-prev');
        const nextBtn = Utils.$('#users-page-next');
        const text = formatPaginationText(shown, total, options);
        if (textEl) {
            textEl.textContent = text;
        } else if (meta) {
            meta.textContent = text;
        }

        const browseMode = listSearch.trim() === '' && !(options && options.searchPending);
        const showPager = browseMode && total > USERS_PAGE_SIZE;
        if (pager) {
            pager.hidden = !showPager;
        }
        if (prevBtn) {
            prevBtn.disabled = !showPager || listOffset <= 0;
        }
        if (nextBtn) {
            nextBtn.disabled = !showPager || listOffset + USERS_PAGE_SIZE >= total;
        }
    }

    /**
     * Render users table
     */
    function buildEmptyStateHtml() {
        if (isAccessRestricted && listAccessFilter === FILTER_APP_ACCESS) {
            const message = auMsg(
                'noAppAccessEmployees',
                'No one with app access yet. Add people under Access control, or show all Nextcloud accounts.'
            );
            const cta = auMsg('openAccessControl', 'Open access control');
            const showAll = auMsg('showAllAccounts', 'Show all accounts');
            const settingsUrl = Utils.escapeHtml(accessSettingsUrl);
            return '<tr><td colspan="' + USERS_TABLE_COLS + '" class="text-center admin-users-empty-cell">'
                + '<p class="admin-users-empty-message">' + Utils.escapeHtml(message) + '</p>'
                + '<div class="admin-users-empty-actions">'
                + '<a class="azc-btn azc-btn--primary" href="' + settingsUrl + '">' + Utils.escapeHtml(cta) + '</a>'
                + '<button type="button" class="azc-btn azc-btn--secondary" data-action="show-all-accounts">'
                + Utils.escapeHtml(showAll) + '</button>'
                + '</div></td></tr>';
        }
        if (listSearch.trim() !== '') {
            return '<tr><td colspan="' + USERS_TABLE_COLS + '" class="text-center admin-users-empty-cell">'
                + '<p class="admin-users-empty-message">' + Utils.escapeHtml(auMsg('noUsersFound', 'No users found')) + '</p>'
                + '<button type="button" class="azc-btn azc-btn--primary" data-action="clear-employee-search">'
                + Utils.escapeHtml(auMsg('clearSearch', 'Clear search'))
                + '</button></td></tr>';
        }
        return '<tr><td colspan="' + USERS_TABLE_COLS + '" class="text-center admin-users-empty-cell">'
            + '<p class="admin-users-empty-message">' + Utils.escapeHtml(auMsg('noUsersFound', 'No users found')) + '</p>'
            + '</td></tr>';
    }

    function renderUsers(users, total) {
        const tbody = Utils.$('#users-tbody');
        if (!tbody) return;

        const totalCount = Number.isFinite(total) ? total : users.length;
        lastShownCount = users.length;
        updateUsersPagination(users.length, totalCount, {});

        if (users.length === 0) {
            tbody.innerHTML = buildEmptyStateHtml();
            return;
        }

        const formatDate = (iso) => {
            if (!iso) return '-';
            const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            return m ? m[3] + '.' + m[2] + '.' + m[1] : iso;
        };
        const ongoingLabel = auMsg('ongoing', 'ongoing');

        tbody.innerHTML = users.map(user => {
            const vacation = user.vacationDaysPerYear != null ? String(user.vacationDaysPerYear) : '-';
            const start = user.workingTimeModelStartDate || null;
            const end = user.workingTimeModelEndDate || null;
            const validity = start ? (formatDate(start) + (end ? ' – ' + formatDate(end) : ' – ' + ongoingLabel)) : '-';
            const stichtag = user.overtimeTrackingFrom || '';
            const stichtagCell = stichtag
                ? `<span class="badge badge--info">${Utils.escapeHtml(formatDate(stichtag))}</span>`
                : `<span class="badge badge--warning">${Utils.escapeHtml(auMsg('notSet', 'Not set'))}</span>`;
            const td = (label, html, cls = '') => Utils.responsiveTd
                ? Utils.responsiveTd(label, html, cls)
                : `<td${cls ? ` class="${cls}"` : ''}>${html}</td>`;
            return `
            <tr data-user-id="${Utils.escapeHtml(user.userId)}">
                ${td(auMsg('select', 'Select'), `<input type="checkbox" class="admin-users-row-select" value="${Utils.escapeHtml(user.userId)}" aria-label="${Utils.escapeHtml(auMsg('selectPerson', 'Select') + ': ' + (user.displayName || user.userId))}">`, 'admin-users-select-col')}
                ${td(auMsg('colName', 'Name'), Utils.escapeHtml(user.displayName))}
                ${td(auMsg('colEmail', 'Email'), Utils.escapeHtml(user.email || '-'))}
                ${td(auMsg('workingTimeModel', 'Working Time Model'), user.workingTimeModel
                    ? Utils.escapeHtml(user.workingTimeModel.name)
                    : `<span class="text-muted">${auMsg('notAssigned', 'Not assigned')}</span>`)}
                ${td(auMsg('vacationDaysCol', 'Vacation days'), Utils.escapeHtml(vacation))}
                ${td(auMsg('colValidFromTo', 'Valid from / to'), Utils.escapeHtml(validity))}
                ${td(auMsg('colOvertimeStichtag', 'Overtime Stichtag'), stichtagCell)}
                ${td(auMsg('status', 'Status'), `<span class="badge badge--${user.enabled ? 'success' : 'error'}">
                        ${user.enabled
                        ? auMsg('enabled', 'Enabled')
                        : auMsg('disabled', 'Disabled')}
                    </span>`)}
                ${td(auMsg('actions', 'Actions'), (() => {
                    const detailUrl = userDetailUrl(user.userId);
                    const name = user.displayName || user.userId;
                    return `<div class="user-actions azc-table-actions" role="group" aria-label="${Utils.escapeHtml(auMsg('actions', 'Actions') + ': ' + name)}">
                        <a class="azc-btn azc-btn--sm azc-btn--ghost"
                            href="${Utils.escapeHtml(detailUrl)}#assignment-history"
                            data-action="history-user"
                            data-user-id="${Utils.escapeHtml(user.userId)}"
                            aria-label="${Utils.escapeHtml(auMsg('viewHistory', 'View history') + ': ' + name)}">
                            ${Utils.escapeHtml(auMsg('history', 'History'))}
                        </a>
                        <a class="azc-btn azc-btn--sm azc-btn--secondary"
                            href="${Utils.escapeHtml(detailUrl)}"
                            data-action="edit-user"
                            data-user-id="${Utils.escapeHtml(user.userId)}"
                            aria-label="${Utils.escapeHtml(auMsg('editEmployee', 'Edit employee') + ': ' + name)}">
                            ${Utils.escapeHtml(auMsg('edit', 'Edit'))}
                        </a>
                    </div>`;
                })(), 'actions-cell')}
            </tr>
        `;
        }).join('');

        bindBulkSelection();
    }

    const selectedEmployees = new Set();

    function syncBulkBar() {
        const bar = document.getElementById('admin-users-bulk-bar');
        const countEl = document.getElementById('admin-users-bulk-count');
        if (!bar || !countEl) return;
        const n = selectedEmployees.size;
        if (n === 0) {
            bar.hidden = true;
            countEl.textContent = '';
            return;
        }
        bar.hidden = false;
        countEl.textContent = auMsg('nSelectedApply', '%n selected — Apply…').replace('%n', String(n));
    }

    function bindBulkSelection() {
        document.querySelectorAll('.admin-users-row-select').forEach(function(cb) {
            cb.checked = selectedEmployees.has(cb.value);
            cb.addEventListener('change', function() {
                if (cb.checked) {
                    selectedEmployees.add(cb.value);
                } else {
                    selectedEmployees.delete(cb.value);
                }
                syncBulkBar();
            });
        });
        const all = document.getElementById('users-select-all');
        if (all) {
            all.onchange = function() {
                document.querySelectorAll('.admin-users-row-select').forEach(function(cb) {
                    cb.checked = all.checked;
                    if (all.checked) {
                        selectedEmployees.add(cb.value);
                    } else {
                        selectedEmployees.delete(cb.value);
                    }
                });
                syncBulkBar();
            };
        }
    }

    function openBulkApplyDialog() {
        const userIds = Array.from(selectedEmployees);
        if (!userIds.length) {
            Messaging && Messaging.showError && Messaging.showError(auMsg('selectPeopleFirst', 'Select at least one person.'));
            return;
        }
        const teamsUrl = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.adminUsersConfig && window.ArbeitszeitCheck.adminUsersConfig.adminTeamsUrl)
            || '/apps/arbeitszeitcheck/admin/teams';
        const esc = Utils.escapeHtml ? Utils.escapeHtml.bind(Utils) : function(s) { return String(s); };
        const today = new Date().toISOString().slice(0, 10);

        Utils.ajax('/apps/arbeitszeitcheck/api/admin/working-time-models', {
            method: 'GET',
            onSuccess: function(modelsData) {
                const models = (modelsData && modelsData.models) ? modelsData.models : [];
                let modelOpts = '<option value="">' + esc(auMsg('noChange', '— no change —')) + '</option>';
                models.forEach(function(m) {
                    modelOpts += '<option value="' + esc(String(m.id)) + '">' + esc(m.name || String(m.id)) + '</option>';
                });

                const content = '<form id="admin-users-bulk-form" class="form" novalidate>'
                    + '<p class="form-help">' + esc(auMsg('bulkApplyHelp', 'Apply the same work schedule and/or holiday region to the selected people.')) + '</p>'
                    + '<div class="form-group"><label for="bulk-model-id" class="form-label">' + esc(auMsg('workingTimeModel', 'Working Time Model')) + '</label>'
                    + '<select id="bulk-model-id" class="form-select">' + modelOpts + '</select></div>'
                    + '<div class="form-group"><label for="bulk-start-date" class="form-label">' + esc(auMsg('startDate', 'Start date')) + '</label>'
                    + '<input type="date" id="bulk-start-date" class="form-input" value="' + esc(today) + '"></div>'
                    + '<div class="form-group"><label for="bulk-region" class="form-label">' + esc(auMsg('holidayRegion', 'Holiday region')) + '</label>'
                    + '<select id="bulk-region" class="form-select">'
                    + '<option value="__unchanged__">' + esc(auMsg('noChange', '— no change —')) + '</option>'
                    + '<option value="">' + esc(auMsg('useOrgDefault', 'Use organisation default')) + '</option>'
                    + '</select></div>'
                    + '<div class="azc-callout azc-callout--info">'
                    + '<p><strong>' + esc(auMsg('vacationRecommended', 'Recommended for vacation')) + ':</strong> '
                    + esc(auMsg('vacationL2Hint', 'Put people on a team and use team vacation rules (L2).'))
                    + ' <a href="' + esc(teamsUrl) + '">' + esc(auMsg('openTeams', 'Open teams')) + '</a></p>'
                    + '<details><summary>' + esc(auMsg('vacationAdvanced', 'Advanced: same personal vacation rule (L3)')) + '</summary>'
                    + '<p class="form-help">' + esc(auMsg('vacationL3Hint', 'Only for exceptions that are not modeled as a team.')) + '</p>'
                    + '<label class="form-label" for="bulk-l3-days">' + esc(auMsg('vacationDaysCol', 'Vacation days')) + '</label>'
                    + '<input type="number" id="bulk-l3-days" class="form-input" min="0" max="366" step="0.5" placeholder="25">'
                    + '</details></div>'
                    + '<div id="bulk-preview" class="azc-callout" role="status" aria-live="polite" hidden></div>'
                    + '<div class="form-actions">'
                    + '<button type="button" class="btn btn--secondary" data-action="close-modal">' + esc(auMsg('cancel', 'Cancel')) + '</button>'
                    + '<button type="button" id="bulk-preview-btn" class="btn btn--secondary">' + esc(auMsg('preview', 'Preview')) + '</button>'
                    + '<button type="submit" class="btn btn--primary">' + esc(auMsg('confirmApply', 'Confirm apply')) + '</button>'
                    + '</div></form>';

                const modal = Components.createModal({
                    id: 'modal-admin-users-bulk',
                    title: auMsg('applyToSelected', 'Apply to selected…'),
                    content: content,
                    size: 'md',
                    closable: true,
                });
                Components.openModal('modal-admin-users-bulk');

                function buildFields() {
                    const fields = {};
                    const modelId = (document.getElementById('bulk-model-id') || {}).value;
                    const startDate = (document.getElementById('bulk-start-date') || {}).value || today;
                    if (modelId) {
                        fields.workingTimeModel = { modelId: parseInt(modelId, 10), startDate: startDate };
                    }
                    const region = (document.getElementById('bulk-region') || {}).value;
                    if (region !== '__unchanged__') {
                        fields.holidayRegion = { germanState: region };
                    }
                    return fields;
                }

                const previewBtn = document.getElementById('bulk-preview-btn');
                if (previewBtn) {
                    previewBtn.addEventListener('click', function() {
                        const fields = buildFields();
                        if (!fields.workingTimeModel && !fields.holidayRegion) {
                            Messaging && Messaging.showError && Messaging.showError(auMsg('chooseField', 'Choose a work schedule and/or holiday region.'));
                            return;
                        }
                        Utils.ajax('/apps/arbeitszeitcheck/api/admin/users/batch-profile', {
                            method: 'POST',
                            data: { userIds: userIds, fields: fields, dryRun: true },
                            onSuccess: function(res) {
                                const el = document.getElementById('bulk-preview');
                                if (!el) return;
                                el.hidden = false;
                                const s = res.summary || {};
                                el.textContent = auMsg('previewSummary', 'Would update %1$d people (%2$d failed checks).')
                                    .replace('%1$d', String(s.applied ?? 0))
                                    .replace('%2$d', String(s.failed ?? 0));
                            },
                            onError: function(err) {
                                Messaging && Messaging.showError && Messaging.showError((err && (err.message || err.error)) || auMsg('previewFailed', 'Preview failed'));
                            }
                        });
                    });
                }

                const form = document.getElementById('admin-users-bulk-form');
                if (form) {
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                        const fields = buildFields();
                        const l3Days = (document.getElementById('bulk-l3-days') || {}).value;
                        const hasL3 = l3Days !== '' && l3Days != null;
                        if (!fields.workingTimeModel && !fields.holidayRegion && !hasL3) {
                            Messaging && Messaging.showError && Messaging.showError(auMsg('chooseField', 'Choose a work schedule and/or holiday region.'));
                            return;
                        }
                        if (!window.confirm(auMsg('confirmBulk', 'Apply these settings to %n people?').replace('%n', String(userIds.length)))) {
                            return;
                        }

                        function finish(msg) {
                            const resultEl = document.getElementById('admin-users-bulk-result');
                            if (resultEl) {
                                resultEl.hidden = false;
                                resultEl.textContent = msg;
                            }
                            Messaging && Messaging.showSuccess && Messaging.showSuccess(msg);
                            Components.closeModal(document.getElementById('modal-admin-users-bulk'));
                            loadUsers();
                        }

                        function applyProfile(done) {
                            if (!fields.workingTimeModel && !fields.holidayRegion) {
                                done(null);
                                return;
                            }
                            Utils.ajax('/apps/arbeitszeitcheck/api/admin/users/batch-profile', {
                                method: 'POST',
                                data: { userIds: userIds, fields: fields, dryRun: false },
                                onSuccess: function(res) {
                                    const s = res.summary || {};
                                    done(auMsg('bulkDone', 'Updated %1$d, failed %2$d.')
                                        .replace('%1$d', String(s.applied ?? 0))
                                        .replace('%2$d', String(s.failed ?? 0)));
                                },
                                onError: function(err) {
                                    Messaging && Messaging.showError && Messaging.showError((err && (err.message || err.error)) || auMsg('bulkFailed', 'Bulk update failed'));
                                }
                            });
                        }

                        function applyL3(prevMsg) {
                            if (!hasL3) {
                                finish(prevMsg || auMsg('done', 'Done'));
                                return;
                            }
                            Utils.ajax('/apps/arbeitszeitcheck/api/admin/users/batch-vacation-policy', {
                                method: 'POST',
                                data: {
                                    userIds: userIds,
                                    vacationPolicy: {
                                        vacationMode: 'manual_fixed',
                                        manualDays: parseFloat(String(l3Days).replace(',', '.')),
                                        inheritLowerLayers: false,
                                        effectiveFrom: today,
                                    },
                                    dryRun: false,
                                },
                                onSuccess: function(res) {
                                    const s = res.summary || {};
                                    const l3Msg = auMsg('l3Done', 'L3 vacation: updated %1$d, failed %2$d.')
                                        .replace('%1$d', String(s.applied ?? 0))
                                        .replace('%2$d', String(s.failed ?? 0));
                                    finish(prevMsg ? (prevMsg + ' ' + l3Msg) : l3Msg);
                                },
                                onError: function(err) {
                                    Messaging && Messaging.showError && Messaging.showError((err && (err.message || err.error)) || auMsg('bulkFailed', 'Bulk update failed'));
                                }
                            });
                        }

                        applyProfile(function(msg) { applyL3(msg); });
                    });
                }
            },
            onError: function() {
                Messaging && Messaging.showError && Messaging.showError(auMsg('errorLoadingModels', 'Could not load working time models'));
            }
        });
    }

    function initBulkBar() {
        const applyBtn = document.getElementById('admin-users-bulk-apply');
        const clearBtn = document.getElementById('admin-users-bulk-clear');
        if (applyBtn) {
            applyBtn.addEventListener('click', openBulkApplyDialog);
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                selectedEmployees.clear();
                document.querySelectorAll('.admin-users-row-select').forEach(function(cb) { cb.checked = false; });
                const all = document.getElementById('users-select-all');
                if (all) all.checked = false;
                syncBulkBar();
            });
        }
        bindBulkSelection();
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            init();
            initBulkBar();
        });
    } else {
        init();
        initBulkBar();
    }
})();
