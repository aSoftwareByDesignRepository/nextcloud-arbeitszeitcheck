/**
 * Admin employee detail page — profile form + assignment history
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
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
     * Unsaved-leave confirm shared by sidebar + back-link guards.
     * @param {{message?: string}=} opts
     * @returns {Promise<*>}
     */
    async function promptUnsavedLeave(opts) {
        const message = (opts && opts.message)
            || auMsg('unsavedChanges', 'You have unsaved changes. Leave this page anyway?');
        if (!Utils.confirmDestructiveAction) {
            return null;
        }
        return Utils.confirmDestructiveAction({
            title: auMsg('unsavedChangesTitle', 'Unsaved changes'),
            message: message,
            confirmLabel: auMsg('leaveWithoutSaving', 'Leave without saving'),
            cancelLabel: auMsg('stayOnPage', 'Stay on page'),
            variant: 'warning',
        });
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

    function fetchTariffRuleSets() {
        return new Promise((resolve) => {
            Utils.ajax(buildApiUrl('/apps/arbeitszeitcheck/api/admin/tariff-rule-sets'), {
                method: 'GET',
                onSuccess: function(data) {
                    if (data && data.success && Array.isArray(data.ruleSets)) {
                        resolve(data.ruleSets);
                        return;
                    }
                    resolve([]);
                },
                onError: function() {
                    resolve([]);
                }
            });
        });
    }

    const detailCfg = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.adminUserDetailConfig) || {};
    let formDirty = false;
    let saveInFlight = false;
    let loadedUserSnapshot = null;

    function setStatus(message, isError) {
        const el = document.getElementById('admin-user-detail-status');
        if (!el) {
            return;
        }
        el.hidden = !message;
        el.classList.toggle('admin-user-detail__status--error', !!isError);
        const text = el.querySelector('.admin-user-detail__status-text');
        if (text) {
            text.textContent = message || '';
        } else {
            el.textContent = message || '';
        }
    }

    function markDirty() {
        formDirty = true;
    }

    function clearDirty() {
        formDirty = false;
    }

    function bindDirtyTracking(form) {
        if (!form) {
            return;
        }
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
    }

    function bindUnloadGuard() {
        window.addEventListener('beforeunload', function(e) {
            if (!formDirty || saveInFlight) {
                return;
            }
            e.preventDefault();
            e.returnValue = '';
        });
    }

    /**
     * Initialize employee detail page
     */
    function init() {
        const userId = String(detailCfg.userId || '').trim();
        if (!userId) {
            setStatus(auMsg('invalidUserData', 'Invalid user data'), true);
            return;
        }
        bindUnloadGuard();
        setStatus(auMsg('loadingEllipsis', 'Loading…'), false);
        loadUserDetail(userId);
    }

    function loadUserDetail(userId) {
        Utils.ajax(buildApiUrl('/apps/arbeitszeitcheck/api/admin/users/' + encodeURIComponent(userId)), {
            method: 'GET',
            onSuccess: function(data) {
                if (data.success && data.user) {
                    loadedUserSnapshot = data.user;
                    mountEditForm(data.user);
                } else {
                    setStatus(auMsg('failedToLoadUserDetails', 'Failed to load user details'), true);
                    Messaging.showError(auMsg('failedToLoadUserDetails', 'Failed to load user details'));
                }
            },
            onError: function() {
                setStatus(auMsg('failedToLoadUserDetails', 'Failed to load user details'), true);
                Messaging.showError(auMsg('failedToLoadUserDetails', 'Failed to load user details'));
            }
        });
    }

    function mountEditForm(user) {
        if (!user || !user.userId) {
            setStatus(auMsg('invalidUserData', 'Invalid user data'), true);
            return;
        }
        const models = Array.isArray(user.availableWorkingTimeModels) ? user.availableWorkingTimeModels : [];
        fetchTariffRuleSets().then((ruleSets) => {
            renderEditForm(user, models, ruleSets);
        });
    }

    function renderEditForm(user, models, ruleSets) {
        const t = (key, english) => auMsg(key, english);
        const modelLabel = t('workingTimeModel', 'Working Time Model');
        const vacationDaysLabel = t('vacationDaysPerYear', 'Vacation Days Per Year');
        const policyModeLabel = t('vacationModeSimpleLabel', 'How should annual vacation be calculated?');
        const carryoverLabel = t('vacationCarryoverLabel', 'Vacation carryover (opening balance)');
        const carryoverYearLabel = t('vacationCarryoverYearLabel', 'Year for carryover balance');
        const startDateLabel = t('startDate', 'Start Date');
        const endDateLabel = t('endDateOptional', 'End Date (Optional)');
        const noModelLabel = t('noModel', 'No Model Assigned');
        const germanStateLabel = t('germanStateLabel', 'Region for public holidays');
        const germanStateHelp = t('germanStateHelp', 'Select the region whose holiday calendar applies to this person. If not set, the instance default region is used.');
        const germanStateDefault = t('germanStateDefault', 'Instance default (currently: %s)');
        const datePlaceholder = Utils.escapeHtml(t('ddmmYYYY', 'dd.mm.yyyy'));

        const DEFAULT_VACATION_DAYS = (typeof window.ArbeitszeitCheck !== 'undefined'
            && Number.isFinite(Number(window.ArbeitszeitCheck.vacationDaysSuggestion))
            && Number(window.ArbeitszeitCheck.vacationDaysSuggestion) > 0)
            ? Math.round(Number(window.ArbeitszeitCheck.vacationDaysSuggestion))
            : 25; // Profile suggestion (DE/AT 25, CH 20); must match LaborLawProfileFactory
        const vacation = user.vacationDaysPerYear ?? user.userWorkingTimeModel?.vacationDaysPerYear ?? DEFAULT_VACATION_DAYS;
        const carryover = user.vacationCarryoverDays != null ? String(user.vacationCarryoverDays) : '0';
        const carryYear = user.vacationCarryoverYear != null ? String(user.vacationCarryoverYear) : String(new Date().getFullYear());
        const overtimeTrackingFrom = user.overtimeTrackingFrom || '';
        const overtimeTrackingVal = (overtimeTrackingFrom && convertISOToEuropean(overtimeTrackingFrom)) || '';
        const overtimeOpening = user.overtimeOpeningBalanceHours != null ? String(user.overtimeOpeningBalanceHours) : '0';
        const overtimeOpeningYear = user.overtimeOpeningBalanceYear != null ? String(user.overtimeOpeningBalanceYear) : String(new Date().getFullYear());
        const startIso = user.workingTimeModelStartDate ?? user.userWorkingTimeModel?.startDate ?? null;
        const endIso = user.workingTimeModelEndDate ?? user.userWorkingTimeModel?.endDate ?? null;
        const startVal = (startIso && convertISOToEuropean(startIso)) || '';
        const endVal = (endIso && convertISOToEuropean(endIso)) || '';
        const employmentStartIso = user.employmentStart ?? null;
        const employmentEndIso = user.employmentEnd ?? null;
        const employmentStartVal = (employmentStartIso && convertISOToEuropean(employmentStartIso)) || '';
        const employmentEndVal = (employmentEndIso && convertISOToEuropean(employmentEndIso)) || '';
        const currentState = user.germanState || '';
        const policy = user.vacationPolicy || {};
        const inheritLowerLayers = !!policy.inheritLowerLayers;
        // A user without an explicit L3 policy already resolves entitlement by
        // falling through to the lower layers (team → model → organisation →
        // legacy default). Reflect that reality by defaulting the dropdown to
        // "inherit" instead of "manual_fixed": the latter would require a
        // manual-days value the operator never entered and would make a
        // no-op "open and save" fail server-side validation (the historic
        // "Benutzer konnte nicht aktualisiert werden" bug).
        const hasExplicitPolicy = !!(user.vacationPolicy && user.vacationPolicy.vacationMode);
        const rawMode = hasExplicitPolicy ? (policy.vacationMode || 'manual_fixed') : 'inherit';
        // REQ-WF-04 — surface the "inherit" sentinel as a first-class option in
        // the dropdown so HR can flip an individual into "follow team/model/org"
        // without having to know that empty fields + manual_fixed magically mean
        // anything. The controller already accepts either representation
        // (sentinel mode or the boolean column) and persists both in sync.
        const currentMode = (inheritLowerLayers || rawMode === 'inherit') ? 'inherit' : rawMode;
        const manualDays = policy.manualDays != null ? String(policy.manualDays) : '';
        const ruleSetId = policy.tariffRuleSetId != null ? String(policy.tariffRuleSetId) : '';
        const overrideReason = policy.overrideReason || '';
        const entitlementPreview = user.entitlementPreview || null;
        const timeCapture = user.timeCapture || {};
        const orgCapture = getOrganizationTimeCapture(user);
        const preferences = timeCapture.preferences || timeCapture;
        const clockStampingEnabled = preferences.clockStampingEnabled !== false;
        const manualTimeEntryEnabled = preferences.manualTimeEntryEnabled !== false;
        const orgClockDisabled = !orgCapture.clockStampingEnabled;
        const orgManualDisabled = !orgCapture.manualTimeEntryEnabled;

        let modelOptions = `<option value="">${noModelLabel}</option>`;
        models.forEach(model => {
            const selected = user.workingTimeModel && user.workingTimeModel.id === model.id ? 'selected' : '';
            modelOptions += `<option value="${Utils.escapeHtml(String(model.id))}" ${selected}>${Utils.escapeHtml(model.name)}</option>`;
        });

        // Regions grouped per country (cross-border commuters may pick a region
        // of another supported country). Working-time law can be overridden per user (E-9).
        const regionGroups = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.regionGroups) || null;
        let stateOptions = `<option value="">${Utils.escapeHtml(germanStateDefault)}</option>`;
        if (regionGroups && regionGroups.length > 0) {
            regionGroups.forEach(group => {
                stateOptions += `<optgroup label="${Utils.escapeHtml(group.countryLabel)}">`;
                (group.regions || []).forEach(region => {
                    const selected = currentState === region.code ? 'selected' : '';
                    stateOptions += `<option value="${Utils.escapeHtml(region.code)}" ${selected}>${Utils.escapeHtml(region.label)}</option>`;
                });
                stateOptions += '</optgroup>';
            });
        } else {
            const states = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.states) || [];
            states.forEach(state => {
                const selected = currentState === state.code ? 'selected' : '';
                stateOptions += `<option value="${Utils.escapeHtml(state.code)}" ${selected}>${Utils.escapeHtml(state.label)}</option>`;
            });
        }

        const instanceCountry = user.instanceCountry || (window.ArbeitszeitCheck && window.ArbeitszeitCheck.holidayRegionContext && window.ArbeitszeitCheck.holidayRegionContext.country) || 'DE';
        const currentLaborLaw = user.laborLawCountry || '';
        const countryLabels = {
            DE: t('countryGermany', 'Germany'),
            AT: t('countryAustria', 'Austria'),
            CH: t('countrySwitzerland', 'Switzerland'),
        };
        const laborLawDefault = t('laborLawCountryDefault', 'Same as organisation (%s)')
            .replace('%s', countryLabels[instanceCountry] || instanceCountry);
        let laborLawOptions = `<option value="">${Utils.escapeHtml(laborLawDefault)}</option>`;
        ['DE', 'AT', 'CH'].forEach(code => {
            const selected = currentLaborLaw === code ? 'selected' : '';
            laborLawOptions += `<option value="${code}" ${selected}>${Utils.escapeHtml(countryLabels[code] || code)}</option>`;
        });

        let tariffRuleSetOptions = `<option value="">${Utils.escapeHtml(t('notAvailable', 'Not available'))}</option>`;
        (Array.isArray(ruleSets) ? ruleSets : []).forEach(ruleSet => {
            const id = String(ruleSet.id || '');
            if (!id || !Utils.isAssignableTariffRuleSet(ruleSet, { keepId: ruleSetId })) {
                return;
            }
            const selected = ruleSetId === id ? 'selected' : '';
            const st = ruleSet.statusLabel || ruleSet.status || '';
            const status = st ? ` (${String(st)})` : '';
            const label = String(ruleSet.displayName || `${ruleSet.tariffCode || ''} ${ruleSet.version || ''}`) + status;
            tariffRuleSetOptions += `<option value="${Utils.escapeHtml(id)}" ${selected}>${Utils.escapeHtml(label)}</option>`;
        });

        const policyId = policy.id != null ? String(policy.id) : '';
        const policyEffectiveFromIso = policy.effectiveFrom || '';

        const listUrl = detailCfg.employeesListUrl || buildApiUrl('/apps/arbeitszeitcheck/admin/users');
        const formContent = `
            <form id="edit-user-form" class="form admin-user-detail__form" novalidate>
                <input type="hidden" id="user-id" name="userId" value="${Utils.escapeHtml(user.userId)}">
                <input type="hidden" id="user-vacation-policy-id" name="vacationPolicyId" value="${Utils.escapeHtml(policyId)}">
                <input type="hidden" id="user-loaded-wtm-start" name="loadedWtmStart" value="${Utils.escapeHtml(startIso || '')}">
                <input type="hidden" id="user-policy-effective-from" name="policyEffectiveFrom" value="${Utils.escapeHtml(policyEffectiveFromIso)}">
                <details class="user-edit-section" id="user-edit-assignment" open>
                    <summary class="user-edit-section__summary" id="user-edit-assignment-heading">
                        <span class="user-edit-section__heading">${Utils.escapeHtml(t('workSchedule', 'Work schedule'))}</span>
                    </summary>
                    <div class="user-edit-section__body">
                    <p class="user-edit-section__guide form-help form-help--block">${Utils.escapeHtml(t('sectionGuideWorkSchedule', 'Pick the work schedule and the region used for public holidays.'))}</p>
                <div class="form-group">
                    <label for="user-model" class="form-label">${modelLabel}</label>
                    <select id="user-model" name="workingTimeModelId" class="form-select" aria-describedby="user-model-help">
                        ${modelOptions}
                    </select>
                    <p id="user-model-help" class="form-help">${t('selectWorkScheduleHelp', 'Select a work schedule to assign to this employee')}</p>
                </div>
                <div class="form-group">
                    <label for="user-german-state" class="form-label">${germanStateLabel}</label>
                    <select id="user-german-state" name="germanState" class="form-select" aria-describedby="user-german-state-help user-german-state-crossborder">
                        ${stateOptions}
                    </select>
                    <p id="user-german-state-help" class="form-help">${germanStateHelp}</p>
                    <p id="user-german-state-crossborder" class="form-help form-help--note" role="status" aria-live="polite" hidden>${Utils.escapeHtml(auMsg('regionCrossBorderNote', 'Public holidays follow this region. Working time rules follow the labour-law country below (or the organisation default when that is empty).'))}</p>
                </div>
                <div class="form-group">
                    <label for="user-labor-law-country" class="form-label">${Utils.escapeHtml(t('laborLawCountryLabel', 'Working time law country'))}</label>
                    <select id="user-labor-law-country" name="laborLawCountry" class="form-select" aria-describedby="user-labor-law-country-help">
                        ${laborLawOptions}
                    </select>
                    <p id="user-labor-law-country-help" class="form-help">${Utils.escapeHtml(t('laborLawCountryHelp', 'Optional. Use this for cross-border employees whose working time rules should differ from the organisation country. Leave empty to follow the organisation setting.'))}</p>
                </div>
                    </div>
                </details>
                <details class="user-edit-section user-edit-section--capture" id="user-edit-capture" open>
                    <summary class="user-edit-section__summary" id="user-edit-capture-heading">
                        <span class="user-edit-section__heading">${Utils.escapeHtml(t('timeRecordingMethods', 'Time recording'))}</span>
                    </summary>
                    <div class="user-edit-section__body">
                    <p class="user-edit-section__guide form-help form-help--block">${Utils.escapeHtml(t('sectionGuideTimeRecording', 'Choose whether this person may clock in/out and/or enter time manually. At least one method must stay on.'))}</p>
                    <p id="user-edit-capture-intro" class="form-help user-edit-capture__intro">${Utils.escapeHtml(t('timeRecordingMethodsIntro', 'Choose how this employee may record working time. At least one method must stay enabled.'))}</p>
                    ${(orgClockDisabled || orgManualDisabled) ? `<p id="user-edit-capture-org-note" class="form-help form-help--note user-edit-capture__org-note">${Utils.escapeHtml(t('timeRecordingOrgRestrictionNote', 'Greyed-out options are disabled organisation-wide in Global settings. You can only restrict this person further.'))}</p>` : ''}
                    <div class="user-edit-capture__grid" role="group" aria-labelledby="user-edit-capture-heading" aria-describedby="user-edit-capture-intro user-edit-capture-error${orgClockDisabled || orgManualDisabled ? ' user-edit-capture-org-note' : ''}">
                        <label class="user-edit-capture__card${orgClockDisabled ? ' user-edit-capture__card--locked' : ''}">
                            <input type="checkbox" id="user-clock-stamping" name="clockStampingEnabled" value="1" class="user-edit-capture__checkbox"${clockStampingEnabled ? ' checked' : ''}${orgClockDisabled ? ' disabled aria-disabled="true"' : ''} data-user-preference="${clockStampingEnabled ? '1' : '0'}">
                            <span class="user-edit-capture__card-body">
                                <span class="user-edit-capture__card-title">${Utils.escapeHtml(t('clockStampingLabel', 'Clock in / out (stamping)'))}</span>
                                <span class="user-edit-capture__card-text">${Utils.escapeHtml(t('clockStampingHelp', 'Live punch clock on the dashboard and in the mobile app.'))}</span>
                            </span>
                        </label>
                        <label class="user-edit-capture__card${orgManualDisabled ? ' user-edit-capture__card--locked' : ''}">
                            <input type="checkbox" id="user-manual-entry" name="manualTimeEntryEnabled" value="1" class="user-edit-capture__checkbox"${manualTimeEntryEnabled ? ' checked' : ''}${orgManualDisabled ? ' disabled aria-disabled="true"' : ''} data-user-preference="${manualTimeEntryEnabled ? '1' : '0'}">
                            <span class="user-edit-capture__card-body">
                                <span class="user-edit-capture__card-title">${Utils.escapeHtml(t('manualTimeEntryLabel', 'Manual time entries'))}</span>
                                <span class="user-edit-capture__card-text">${Utils.escapeHtml(t('manualTimeEntryHelp', 'Add completed work blocks by date and time in the web app.'))}</span>
                            </span>
                        </label>
                    </div>
                    <p id="user-edit-capture-error" class="form-error user-edit-capture__error" role="alert" hidden></p>
                    </div>
                </details>
                <details class="user-edit-section" id="user-edit-vacation" open>
                    <summary class="user-edit-section__summary" id="user-edit-vacation-heading">
                        <span class="user-edit-section__heading">${Utils.escapeHtml(t('vacationDays', 'Vacation days'))}</span>
                    </summary>
                    <div class="user-edit-section__body">
                    <p class="user-edit-section__guide form-help form-help--block">${Utils.escapeHtml(t('sectionGuideVacation', 'Set how annual vacation is calculated, then check the preview before saving.'))}</p>
                <div class="form-group">
                    <label for="user-vacation-days" class="form-label">${vacationDaysLabel}</label>
                    <input type="number" id="user-vacation-days" name="vacationDaysPerYear" class="form-input" min="0" max="365" value="${vacation}" aria-describedby="user-vacation-help">
                    <p id="user-vacation-help" class="form-help">${t('vacationDaysHelp', 'Number of vacation days per year (standard in Germany: 25 days)')}</p>
                </div>
                <div class="form-group">
                    <label for="user-vacation-mode" class="form-label">${policyModeLabel}</label>
                    <select id="user-vacation-mode" name="vacationMode" class="form-select" aria-describedby="user-vacation-mode-help">
                        <option value="inherit" ${currentMode === 'inherit' ? 'selected' : ''}>${t('vacationModeInherit', 'Inherit from team / model / organisation')}</option>
                        <option value="manual_fixed" ${currentMode === 'manual_fixed' ? 'selected' : ''}>${t('manualFixedSimple', 'Fixed value per person')}</option>
                        <option value="model_based_simple" ${currentMode === 'model_based_simple' ? 'selected' : ''}>${t('modelBasedSimple', 'Automatic from work schedule')}</option>
                        <option value="tariff_rule_based" ${currentMode === 'tariff_rule_based' ? 'selected' : ''}>${t('tariffRuleBased', 'Tariff rule')}</option>
                        <option value="manual_exception" ${currentMode === 'manual_exception' ? 'selected' : ''}>${t('manualExceptionSimple', 'Manual exception (with reason)')}</option>
                    </select>
                    <p id="user-vacation-mode-help" class="form-help">${t('vacationModeHelpSimpleInherit', 'Inherit follows the deepest team policy, then the work-schedule default, then the organisation default. Fixed/automatic/tariff/exception set an individual rule for this employee.')}</p>
                </div>
                <div class="form-group">
                    <label for="user-manual-days" class="form-label">${t('manualDays', 'Manual annual days')}</label>
                    <input type="text" id="user-manual-days" name="manualDays" class="form-input" inputmode="decimal" pattern="^[0-9]+([\\.,][0-9]{1,2})?$" value="${Utils.escapeHtml(manualDays)}">
                    <p class="form-help">${t('manualDaysHelp', 'Example: 30 or 24.5 days per year')}</p>
                </div>
                <div class="form-group">
                    <label for="user-tariff-rule-set-id" class="form-label">${t('tariffRuleSetLabel', 'Tariff rule set')}</label>
                    <select id="user-tariff-rule-set-id" name="tariffRuleSetId" class="form-select">
                        ${tariffRuleSetOptions}
                    </select>
                    <p class="form-help">${t('tariffRuleSetHelp', 'Choose the active tariff rule set that should apply to this person.')}</p>
                </div>
                <div class="form-group">
                    <label for="user-override-reason" class="form-label">${t('overrideReason', 'Override reason')}</label>
                    <textarea id="user-override-reason" name="overrideReason" class="form-textarea" rows="2">${Utils.escapeHtml(overrideReason)}</textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">${t('effectiveEntitlement', 'Effective entitlement preview')}</label>
                    <div id="user-entitlement-preview" class="entitlement-preview" aria-live="polite">
                        ${buildEntitlementPreviewHtml(entitlementPreview, t)}
                    </div>
                </div>
                <div class="form-group">
                    <label for="user-vacation-carryover" class="form-label">${carryoverLabel}</label>
                    <input type="text" id="user-vacation-carryover" name="vacationCarryoverDays" class="form-input"
                           inputmode="decimal" pattern="^[0-9]+([\\.,][0-9]{1,2})?$" autocomplete="off"
                           value="${Utils.escapeHtml(carryover)}" aria-describedby="user-carryover-help">
                    <p id="user-carryover-help" class="form-help">${t('vacationCarryoverHelp', 'Opening balance of carryover days for the selected calendar year (Resturlaub), e.g. from HR or migration. This is not the annual vacation entitlement from the working time model. The last day carryover can be used is set globally in Admin settings.')} ${t('vacationCarryoverHelpDecimals', 'Up to two decimal places are allowed, e.g. 1.5 or 4.25 — comma or dot both work.')}</p>
                </div>
                <div class="form-group">
                    <label for="user-vacation-carryover-year" class="form-label">${carryoverYearLabel}</label>
                    <input type="text" id="user-vacation-carryover-year" name="vacationCarryoverYear" class="form-input" inputmode="numeric" pattern="\\d{4}" maxlength="4" autocomplete="off" value="${carryYear}" aria-describedby="user-carryover-year-help">
                    <p id="user-carryover-year-help" class="form-help">${t('vacationCarryoverYearHelp', 'The calendar year this opening balance applies to (same year as in employees’ vacation statistics—usually the current year). When a new year starts or after migrating from another system, set the Resturlaub opening balance for that year here or use the CSV import command; the app does not roll balances forward automatically.')}</p>
                </div>
                    </div>
                </details>
                <details class="user-edit-section" id="user-edit-overtime" open>
                    <summary class="user-edit-section__summary" id="user-edit-overtime-heading">
                        <span class="user-edit-section__heading">${Utils.escapeHtml(t('overtimeSettings', 'Overtime balance'))}</span>
                    </summary>
                    <div class="user-edit-section__body">
                    <p class="user-edit-section__guide form-help form-help--block">${Utils.escapeHtml(t('sectionGuideOvertime', 'Optional: set the overtime start date (Stichtag) and an opening balance for a calendar year.'))}</p>
                <div class="form-group">
                    <label for="user-overtime-tracking-from" class="form-label">${Utils.escapeHtml(t('overtimeTrackingFrom', 'Overtime tracking from (Stichtag)'))}</label>
                    <input type="text" id="user-overtime-tracking-from" name="overtimeTrackingFrom" class="form-input datepicker-input" placeholder="${datePlaceholder}" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" maxlength="10" value="${Utils.escapeHtml(overtimeTrackingVal)}" autocomplete="off" aria-describedby="user-overtime-tracking-from-help">
                    <p id="user-overtime-tracking-from-help" class="form-help">${Utils.escapeHtml(t('overtimeTrackingFromHelp', 'Leave empty for legacy calculation from 1 January. When set, year-to-date overtime counts only from this date.'))} ${Utils.escapeHtml(t('formatDdmmyyyy', 'Format: dd.mm.yyyy'))}</p>
                </div>
                <div class="form-group">
                    <label for="user-overtime-opening" class="form-label">${Utils.escapeHtml(t('overtimeOpeningBalance', 'Opening overtime balance (hours)'))}</label>
                    <input type="text" id="user-overtime-opening" name="overtimeOpeningBalanceHours" class="form-input" inputmode="decimal" value="${Utils.escapeHtml(overtimeOpening)}" aria-describedby="user-overtime-opening-help">
                    <p id="user-overtime-opening-help" class="form-help">${Utils.escapeHtml(t('overtimeOpeningBalanceHelp', 'Eröffnungssaldo in hours for the selected year (can be negative).'))}</p>
                </div>
                <div class="form-group">
                    <label for="user-overtime-opening-year" class="form-label">${Utils.escapeHtml(t('overtimeOpeningBalanceYear', 'Year for opening balance'))}</label>
                    <input type="text" id="user-overtime-opening-year" name="overtimeOpeningBalanceYear" class="form-input" inputmode="numeric" pattern="\\d{4}" maxlength="4" autocomplete="off" value="${Utils.escapeHtml(overtimeOpeningYear)}" aria-describedby="user-overtime-opening-year-help">
                    <p id="user-overtime-opening-year-help" class="form-help">${Utils.escapeHtml(t('yearFourDigitsHelp', 'Enter a four-digit year (e.g. 2026).'))}</p>
                </div>
                    </div>
                </details>
                <details class="user-edit-section" id="user-edit-validity" open>
                    <summary class="user-edit-section__summary" id="user-edit-validity-heading">
                        <span class="user-edit-section__heading">${Utils.escapeHtml(t('validFrom', 'Valid from'))}</span>
                    </summary>
                    <div class="user-edit-section__body">
                    <p class="user-edit-section__guide form-help form-help--block">${Utils.escapeHtml(t('sectionGuideValidity', 'When this work-schedule assignment starts and (optionally) ends.'))}</p>
                <div class="form-group">
                    <label for="user-start-date" class="form-label">${startDateLabel}</label>
                    <input type="text" id="user-start-date" name="startDate" class="form-input datepicker-input" placeholder="${datePlaceholder}" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" maxlength="10" value="${startVal}" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="user-end-date" class="form-label">${endDateLabel}</label>
                    <input type="text" id="user-end-date" name="endDate" class="form-input datepicker-input" placeholder="${datePlaceholder}" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" maxlength="10" value="${endVal}" autocomplete="off">
                    <p class="form-help">${t('endDateHelp', 'Leave empty if the assignment has no end date')}</p>
                </div>
                    </div>
                </details>
                <details class="user-edit-section" id="user-edit-employment" open>
                    <summary class="user-edit-section__summary" id="user-edit-employment-heading">
                        <span class="user-edit-section__heading">${Utils.escapeHtml(t('employmentPeriod', 'Employment period (for pro-rata vacation)'))}</span>
                    </summary>
                    <div class="user-edit-section__body">
                    <p id="user-edit-employment-intro" class="user-edit-section__guide form-help form-help--block">${Utils.escapeHtml(t('sectionGuideEmployment', 'Hire and leaving dates used to reduce vacation in partial years. Leave empty for a full-year entitlement.'))}</p>
                    <p class="form-help form-help--block">${Utils.escapeHtml(t('employmentPeriodIntro', 'Set the hire date (and leaving date, if any). When the employment does not cover the whole calendar year, the annual vacation entitlement is reduced proportionally. Leave both empty for the full annual entitlement.'))}</p>
                <div class="form-group">
                    <label for="user-employment-start" class="form-label">${Utils.escapeHtml(t('employmentStart', 'Employment start date (Eintrittsdatum)'))}</label>
                    <input type="text" id="user-employment-start" name="employmentStart" class="form-input datepicker-input" placeholder="${datePlaceholder}" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" maxlength="10" value="${employmentStartVal}" autocomplete="off" aria-describedby="user-employment-start-help">
                    <p id="user-employment-start-help" class="form-help">${Utils.escapeHtml(t('employmentStartHelp', 'First day of employment. Vacation for the year of hire is prorated from this date.'))} ${Utils.escapeHtml(t('formatDdmmyyyy', 'Format: dd.mm.yyyy'))}</p>
                </div>
                    <div class="form-group">
                    <label for="user-employment-end" class="form-label">${Utils.escapeHtml(t('employmentEnd', 'Employment end date (Austrittsdatum)'))}</label>
                    <input type="text" id="user-employment-end" name="employmentEnd" class="form-input datepicker-input" placeholder="${datePlaceholder}" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" maxlength="10" value="${employmentEndVal}" autocomplete="off" aria-describedby="user-employment-end-help">
                    <p id="user-employment-end-help" class="form-help">${Utils.escapeHtml(t('employmentEndHelp', 'Last day of employment. Leave empty for ongoing employment. Vacation for the year of leaving is prorated up to this date.'))}</p>
                </div>
                    </div>
                </details>
                <details class="user-edit-section" id="user-edit-datev" open>
                    <summary class="user-edit-section__summary" id="user-edit-datev-heading">
                        <span class="user-edit-section__heading">${Utils.escapeHtml(t('datevPersonal', 'DATEV Personalnummer'))}</span>
                    </summary>
                    <div class="user-edit-section__body">
                    <p id="user-edit-datev-intro" class="user-edit-section__guide form-help form-help--block">${Utils.escapeHtml(t('sectionGuideDatev', 'Payroll employee number for DATEV export. Leave empty if this person is not exported to DATEV.'))}</p>
                <div class="form-group">
                    <label for="user-datev-personalnummer" class="form-label">${Utils.escapeHtml(t('datevPersonalnummer', 'DATEV Personalnummer'))}</label>
                    <input type="text" id="user-datev-personalnummer" name="datevPersonalnummer" class="form-input" inputmode="numeric" pattern="[0-9]{0,8}" maxlength="8" value="${Utils.escapeHtml(String(user.datevPersonalnummer || ''))}" autocomplete="off" aria-describedby="user-datev-personalnummer-help user-edit-datev-intro">
                    <p id="user-datev-personalnummer-help" class="form-help">${Utils.escapeHtml(t('datevPersonalnummerHelp', 'Up to 8 digits. Required for DATEV export of this employee.'))}</p>
                </div>
                    </div>
                </details>

                <details class="user-edit-section user-edit-section--history" id="assignment-history">
                    <summary class="user-edit-section__summary" id="user-edit-history-heading">
                        <span class="user-edit-section__heading">${Utils.escapeHtml(t('historySectionTitle', 'Work schedule history'))}</span>
                    </summary>
                    <div class="user-edit-section__body">
                        <p class="user-edit-section__guide form-help form-help--block">${Utils.escapeHtml(t('sectionGuideHistory', 'Read-only list of past and current work-schedule assignments.'))}</p>
                        <div id="assignment-history-content" class="history-panel" aria-live="polite">
                            <p class="history-panel__loading">${Utils.escapeHtml(auMsg('loadingEllipsis', 'Loading…'))}</p>
                        </div>
                    </div>
                </details>

                <div class="form-actions admin-user-detail__actions" data-ime-chrome="bottom" role="group" aria-label="${Utils.escapeHtml(t('saveChanges', 'Save changes'))}">
                    <a class="btn btn--secondary" href="${Utils.escapeHtml(listUrl)}" data-action="back-to-list">${Utils.escapeHtml(t('discardAndBack', 'Back without saving'))}</a>
                    <button type="submit" class="btn btn--primary">${Utils.escapeHtml(t('saveChanges', 'Save changes'))}</button>
                </div>
            </form>
        `;

        const root = document.getElementById('admin-user-detail-root');
        const statusEl = document.getElementById('admin-user-detail-status');
        if (!root) {
            setStatus(auMsg('error', 'An error occurred'), true);
            return;
        }
        root.innerHTML = formContent;
        root.hidden = false;
        if (statusEl) {
            statusEl.hidden = true;
        }

        const dp = window.ArbeitszeitCheckDatepicker;
        if (dp && dp.initializeDatepicker) {
            ['user-start-date', 'user-end-date', 'user-overtime-tracking-from', 'user-employment-start', 'user-employment-end'].forEach((id) => {
                const el = document.getElementById(id);
                if (el) {
                    dp.initializeDatepicker(el, {});
                }
            });
        }

        const form = document.getElementById('edit-user-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                handleUpdateUser(form, user.userId);
            });
            form.querySelectorAll('a[data-action="back-to-list"]').forEach((link) => {
                link.addEventListener('click', async function(e) {
                    if (!formDirty || saveInFlight) {
                        return;
                    }
                    e.preventDefault();
                    const href = link.getAttribute('href');
                    if (!href) {
                        return;
                    }
                    const confirmed = await promptUnsavedLeave({
                        message: auMsg(
                            'discardUnsavedConfirm',
                            'You have unsaved changes. Leave this page without saving?',
                        ),
                    });
                    if (confirmed) {
                        window.location.href = href;
                    }
                });
            });
        }

        // Cross-border note: visible when holiday region country differs from the
        // effective labour-law country (instance default OR per-user override).
        const regionSelectEl = document.getElementById('user-german-state');
        const laborLawSelectEl = document.getElementById('user-labor-law-country');
        const crossBorderNoteEl = document.getElementById('user-german-state-crossborder');
        if (regionSelectEl && crossBorderNoteEl) {
            const instanceCountry = (window.ArbeitszeitCheck
                && window.ArbeitszeitCheck.holidayRegionContext
                && window.ArbeitszeitCheck.holidayRegionContext.country) || 'DE';
            const countryOfRegion = (code) => {
                const idx = String(code).indexOf('-');
                return idx === -1 ? 'DE' : String(code).slice(0, idx);
            };
            const syncCrossBorderNote = () => {
                const code = String(regionSelectEl.value || '');
                const lawCountry = String((laborLawSelectEl && laborLawSelectEl.value) || '').toUpperCase() || instanceCountry;
                crossBorderNoteEl.hidden = code === '' || countryOfRegion(code) === lawCountry;
            };
            regionSelectEl.addEventListener('change', syncCrossBorderNote);
            if (laborLawSelectEl) {
                laborLawSelectEl.addEventListener('change', syncCrossBorderNote);
            }
            syncCrossBorderNote();
        }

        const vacationModeEl = document.getElementById('user-vacation-mode');
        const manualDaysEl = document.getElementById('user-manual-days');
        const tariffRuleSetEl = document.getElementById('user-tariff-rule-set-id');
        const overrideReasonEl = document.getElementById('user-override-reason');
        const modelEl = document.getElementById('user-model');
        const previewEl = document.getElementById('user-entitlement-preview');
        const startDateEl = document.getElementById('user-start-date');
        const employmentStartEl = document.getElementById('user-employment-start');
        const employmentEndEl = document.getElementById('user-employment-end');
        let previewTimer = null;
        let localPreviewSeq = 0;
        const previewToISO = resolveToISO();

        const readEmploymentDraftFromForm = function() {
            const startRaw = String(employmentStartEl?.value || '').trim();
            const endRaw = String(employmentEndEl?.value || '').trim();
            return {
                start: startRaw ? (previewToISO(startRaw) || '') : '',
                end: endRaw ? (previewToISO(endRaw) || '') : '',
            };
        };

        const renderEntitlementPreview = function(days, sourceLabel, summaryOrTrace, traceObject, prorationPreview) {
            const summaryText = typeof summaryOrTrace === 'string' ? summaryOrTrace : '';
            const trace = traceObject != null
                ? traceObject
                : (typeof summaryOrTrace === 'object' ? summaryOrTrace : null);
            paintEntitlementPreview(previewEl, days, sourceLabel, summaryText, trace, t, prorationPreview || null);
        };

        const computeLocalPreview = function() {
            const mode = String(vacationModeEl?.value || 'manual_fixed');
            if (mode === 'tariff_rule_based' && !(tariffRuleSetEl?.value)) {
                renderEntitlementPreview(
                    0,
                    t('sourceTariff', 'Tariff'),
                    t('previewSelectTariffRuleSet', 'Select a tariff rule set to see the preview.'),
                    null,
                    null
                );
                return;
            }

            const payload = {
                userId: user.userId,
                asOfDate: (startDateEl?.value && previewToISO(startDateEl.value))
                    || (window.ArbeitszeitCheckTime ? window.ArbeitszeitCheckTime.todayYmd() : new Date().toISOString().slice(0, 10)),
                employment: readEmploymentDraftFromForm(),
                draftPolicy: {
                    vacationMode: mode,
                    inheritLowerLayers: mode === 'inherit',
                    manualDays: mode === 'inherit' ? null : parseLocalizedDecimal(manualDaysEl?.value),
                    tariffRuleSetId: mode === 'inherit' ? null : (tariffRuleSetEl?.value ? parseInt(String(tariffRuleSetEl.value), 10) : null),
                    overrideReason: (overrideReasonEl?.value || '').toString()
                }
            };

            const seq = ++localPreviewSeq;
            Utils.ajax(buildApiUrl('/apps/arbeitszeitcheck/api/admin/vacation-policy/simulate'), {
                method: 'POST',
                data: payload,
                onSuccess: function(resp) {
                    if (seq !== localPreviewSeq) {
                        return;
                    }
                    if (!resp || !resp.success) {
                        renderEntitlementPreview(
                            0,
                            t('notAvailable', 'Not available'),
                            resp?.error || t('previewTraceError', 'Preview unavailable.'),
                            null,
                            null
                        );
                        return;
                    }
                    const src = localizedEntitlementSourceLabel(resp.source, t);
                    const trace = resp.calculationTrace || null;
                    renderEntitlementPreview(
                        previewDaysFromResponse(resp),
                        src,
                        buildEntitlementPreviewSummary(trace, t),
                        trace,
                        buildProrationPreviewFromResponse(resp)
                    );
                },
                onError: function() {
                    if (seq !== localPreviewSeq) {
                        return;
                    }
                    renderEntitlementPreview(
                        0,
                        t('notAvailable', 'Not available'),
                        t('previewTraceError', 'Preview unavailable.'),
                        null,
                        null
                    );
                }
            });
        };

        const triggerPreview = function() {
            if (previewTimer) {
                clearTimeout(previewTimer);
            }
            previewTimer = setTimeout(computeLocalPreview, 220);
        };

        const toggleVacationModeFields = function() {
            const mode = String(vacationModeEl?.value || 'manual_fixed');
            const isInherit = mode === 'inherit';
            const isManual = !isInherit && (mode === 'manual_fixed' || mode === 'manual_exception');
            const isTariff = !isInherit && mode === 'tariff_rule_based';
            if (manualDaysEl) {
                manualDaysEl.disabled = !isManual;
                manualDaysEl.closest('.form-group')?.classList.toggle('is-disabled', !isManual);
            }
            if (tariffRuleSetEl) {
                tariffRuleSetEl.disabled = !isTariff;
                tariffRuleSetEl.closest('.form-group')?.classList.toggle('is-disabled', !isTariff);
            }
            if (overrideReasonEl) {
                const needsReason = !isInherit && mode === 'manual_exception';
                overrideReasonEl.disabled = !needsReason;
                overrideReasonEl.required = needsReason;
                overrideReasonEl.closest('.form-group')?.classList.toggle('is-disabled', !needsReason);
            }
            triggerPreview();
        };
        if (vacationModeEl) {
            vacationModeEl.addEventListener('change', toggleVacationModeFields);
            toggleVacationModeFields();
        }
        [manualDaysEl, tariffRuleSetEl, overrideReasonEl, modelEl, startDateEl, employmentStartEl, employmentEndEl].forEach((el) => {
            if (!el) {
                return;
            }
            el.addEventListener('input', triggerPreview);
            el.addEventListener('change', triggerPreview);
        });
        triggerPreview();

        bindTimeCaptureValidation(form, orgCapture);

        bindDirtyTracking(form);
        clearDirty();
        loadAssignmentHistory(user.userId);

        const backLink = root.querySelector('[data-action="back-to-list"]');
        if (backLink) {
            backLink.addEventListener('click', async function(e) {
                if (!formDirty) {
                    return;
                }
                e.preventDefault();
                const href = backLink.getAttribute('href');
                if (!href) {
                    return;
                }
                const confirmed = await promptUnsavedLeave();
                if (confirmed) {
                    window.location.href = href;
                }
            });
        }

        if (window.location.hash === '#assignment-history') {
            const hist = document.getElementById('assignment-history');
            if (hist) {
                hist.open = true;
                hist.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    function loadAssignmentHistory(userId) {
        const contentEl = document.getElementById('assignment-history-content');
        if (!contentEl) {
            return;
        }
        const t = (key, english) => auMsg(key, english);
        Utils.ajax(buildApiUrl('/apps/arbeitszeitcheck/api/admin/users/' + encodeURIComponent(userId) + '/working-time-model/history'), {
            method: 'GET',
            onSuccess: function(data) {
                if (data.success && Array.isArray(data.history) && data.history.length > 0) {
                    const formatDate = (iso) => {
                        if (!iso) return '–';
                        const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})$/);
                        return m ? m[3] + '.' + m[2] + '.' + m[1] : iso;
                    };
                    const workScheduleHdr = Utils.escapeHtml(t('workSchedule', 'Work schedule'));
                    const vacationDaysHdr = Utils.escapeHtml(t('vacationDaysCol', 'Vacation days'));
                    const validFromHdr = Utils.escapeHtml(t('validFrom', 'Valid from'));
                    const validToHdr = Utils.escapeHtml(t('validTo', 'Valid to'));
                    const statusHdr = Utils.escapeHtml(t('status', 'Status'));
                    const ongoingVal = Utils.escapeHtml(t('ongoing', 'ongoing'));
                    const activeVal = Utils.escapeHtml(t('active', 'Active'));
                    const endedVal = Utils.escapeHtml(t('ended', 'Ended'));
                    const rows = data.history.map(item => {
                        const model = Utils.escapeHtml(item.modelName);
                        const vacation = String(item.vacationDaysPerYear);
                        const from = formatDate(item.startDate);
                        const to = formatDate(item.endDate) || ongoingVal;
                        const status = item.isActive
                            ? '<span class="badge badge--success">' + activeVal + '</span>'
                            : '<span class="badge badge--secondary">' + endedVal + '</span>';
                        const td = (label, html) => Utils.responsiveTd
                            ? Utils.responsiveTd(label, html)
                            : '<td>' + html + '</td>';
                        return '<tr>'
                            + td(workScheduleHdr, model)
                            + td(vacationDaysHdr, vacation)
                            + td(validFromHdr, from)
                            + td(validToHdr, to)
                            + td(statusHdr, status)
                            + '</tr>';
                    }).join('');
                    contentEl.innerHTML = '<div class="table-container" role="region" aria-label="' + Utils.escapeHtml(t('assignmentHistory', 'Assignment history')) + '">' +
                        '<table class="table table--hover azc-table--responsive history-panel__table" role="table" aria-label="' + Utils.escapeHtml(t('assignmentHistory', 'Assignment history')) + '">' +
                        '<thead><tr>' +
                        '<th scope="col">' + workScheduleHdr + '</th>' +
                        '<th scope="col">' + vacationDaysHdr + '</th>' +
                        '<th scope="col">' + validFromHdr + '</th>' +
                        '<th scope="col">' + validToHdr + '</th>' +
                        '<th scope="col">' + statusHdr + '</th>' +
                        '</tr></thead><tbody>' + rows + '</tbody></table></div>';
                } else {
                    contentEl.innerHTML = '<p class="history-panel__empty">' + Utils.escapeHtml(t('noAssignmentHistory', 'No assignment history')) + '</p>';
                }
            },
            onError: function() {
                contentEl.innerHTML = '<p class="history-panel__empty">' + Utils.escapeHtml(auMsg('errorLoadingHistory', 'Error loading assignment history')) + '</p>';
            }
        });
    }

    function getOrganizationTimeCapture(user) {
        const org = (user && user.organizationTimeCapture)
            || (window.ArbeitszeitCheck && window.ArbeitszeitCheck.adminUsersConfig
                && window.ArbeitszeitCheck.adminUsersConfig.organizationTimeCapture)
            || { clockStampingEnabled: true, manualTimeEntryEnabled: true };
        return {
            clockStampingEnabled: org.clockStampingEnabled !== false,
            manualTimeEntryEnabled: org.manualTimeEntryEnabled !== false,
        };
    }

    function bindTimeCaptureValidation(form, orgCapture) {
        const org = orgCapture || getOrganizationTimeCapture(null);
        const clockEl = form.querySelector('#user-clock-stamping');
        const manualEl = form.querySelector('#user-manual-entry');
        const errorEl = form.querySelector('#user-edit-capture-error');
        if (!clockEl || !manualEl || !errorEl) {
            return;
        }
        const validate = () => {
            const clockEffective = org.clockStampingEnabled && clockEl.checked;
            const manualEffective = org.manualTimeEntryEnabled && manualEl.checked;
            const ok = clockEffective || manualEffective;
            errorEl.hidden = ok;
            if (!ok) {
                errorEl.textContent = auMsg(
                    'timeCaptureAtLeastOne',
                    'Enable clock in/out or manual time entries — at least one method is required.'
                );
            }
            return ok;
        };
        clockEl.addEventListener('change', validate);
        manualEl.addEventListener('change', validate);
        form.addEventListener('submit', (event) => {
            if (!validate()) {
                event.preventDefault();
                errorEl.focus();
            }
        });
    }

    function readTimeCapturePayload(form) {
        const clockEl = form.querySelector('#user-clock-stamping');
        const manualEl = form.querySelector('#user-manual-entry');
        const readPreference = (el) => {
            if (!el) {
                return false;
            }
            if (el.disabled) {
                return el.getAttribute('data-user-preference') === '1';
            }
            el.setAttribute('data-user-preference', el.checked ? '1' : '0');
            return el.checked;
        };
        return {
            clockStampingEnabled: readPreference(clockEl),
            manualTimeEntryEnabled: readPreference(manualEl),
        };
    }

    /**
     * Remove every inline field error previously rendered by {@link setFieldError}
     * and reset the related ARIA wiring so re-validation starts from a clean slate.
     */
    function clearFieldErrors(form) {
        if (!form) {
            return;
        }
        form.querySelectorAll('.field-error[data-field-error]').forEach((el) => {
            const ownerId = el.getAttribute('data-field-error');
            const owner = ownerId ? form.querySelector('#' + escapeSelector(ownerId)) : null;
            if (owner) {
                owner.removeAttribute('aria-invalid');
                owner.classList.remove('form-input--error');
                const tokens = (owner.getAttribute('aria-describedby') || '')
                    .split(/\s+/)
                    .filter((token) => token && token !== el.id);
                if (tokens.length) {
                    owner.setAttribute('aria-describedby', tokens.join(' '));
                } else {
                    owner.removeAttribute('aria-describedby');
                }
            }
            el.remove();
        });
    }

    /** Minimal CSS.escape fallback so we can target generated ids safely. */
    function escapeSelector(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
    }

    /**
     * Render an accessible, screen-reader-announced error directly beneath the
     * offending field and link it via aria-describedby. Returns the field so
     * callers can track the first invalid control for focus management.
     */
    function setFieldError(field, message) {
        if (!field) {
            return null;
        }
        const group = field.closest('.form-group') || field.parentNode;
        if (!group) {
            return field;
        }
        const fieldId = field.id || ('azc-field-' + Math.random().toString(36).slice(2));
        if (!field.id) {
            field.id = fieldId;
        }
        const errorId = fieldId + '-field-error';
        let errorEl = group.querySelector('.field-error[data-field-error="' + fieldId + '"]');
        if (!errorEl) {
            errorEl = document.createElement('p');
            errorEl.className = 'field-error';
            errorEl.id = errorId;
            errorEl.setAttribute('role', 'alert');
            errorEl.setAttribute('data-field-error', fieldId);
            group.appendChild(errorEl);
        }
        errorEl.textContent = message;
        field.setAttribute('aria-invalid', 'true');
        field.classList.add('form-input--error');
        const tokens = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (!tokens.includes(errorId)) {
            tokens.push(errorId);
            field.setAttribute('aria-describedby', tokens.join(' '));
        }
        return field;
    }

    /** Map server-side validation keys to form controls for inline feedback. */
    const SERVER_FIELD_SELECTORS = {
        manualDays: '#user-manual-days',
        tariffRuleSetId: '#user-tariff-rule-set-id',
        overrideReason: '#user-override-reason',
        vacationMode: '#user-vacation-mode',
        effectiveFrom: '#user-start-date',
        effectiveTo: '#user-end-date',
        startDate: '#user-start-date',
        endDate: '#user-end-date',
        trackingFrom: '#user-overtime-tracking-from',
        openingBalanceYear: '#user-overtime-opening-year',
        openingBalanceHours: '#user-overtime-opening',
        employmentStart: '#user-employment-start',
        employmentEnd: '#user-employment-end',
        datevPersonalnummer: '#user-datev-personalnummer',
    };

    /**
     * Paint field-level errors returned by PUT …/profile ({ errors: { field: msg } }).
     *
     * @returns {HTMLElement|null} first field marked invalid
     */
    function applyServerFieldErrors(form, errors) {
        if (!form || !errors || typeof errors !== 'object') {
            return null;
        }
        let firstInvalid = null;
        Object.keys(errors).forEach((key) => {
            const selector = SERVER_FIELD_SELECTORS[key];
            const field = selector ? form.querySelector(selector) : null;
            const raw = errors[key];
            const message = Array.isArray(raw) ? String(raw[0] || '') : String(raw || '');
            if (!message) {
                return;
            }
            const marked = setFieldError(field, message);
            if (!firstInvalid && marked) {
                firstInvalid = marked;
            }
        });
        return firstInvalid;
    }

    /**
     * Validate the whole edit-user form on the client BEFORE any request is sent.
     *
     * The save uses a single atomic profile endpoint; client validation still
     * prevents round-trips and surfaces specific, localized, accessible messages.
     *
     * @returns {HTMLElement|null} the first invalid field, or null when valid.
     */
    function validateUserEditForm(form) {
        clearFieldErrors(form);
        let firstInvalid = null;
        const markInvalid = (field, message) => {
            const marked = setFieldError(field, message);
            if (!firstInvalid && marked) {
                firstInvalid = marked;
            }
        };

        // 1. Time recording: at least one method must remain enabled. Reuse the
        // dedicated capture error region so live and submit feedback stay aligned.
        const clockEl = form.querySelector('#user-clock-stamping');
        const manualEl = form.querySelector('#user-manual-entry');
        const captureErrorEl = form.querySelector('#user-edit-capture-error');
        if (clockEl && manualEl && !clockEl.checked && !manualEl.checked) {
            if (captureErrorEl) {
                captureErrorEl.hidden = false;
                captureErrorEl.textContent = auMsg('timeCaptureAtLeastOne', 'Enable clock in/out or manual time entries — at least one method is required.');
            }
            if (!firstInvalid) {
                firstInvalid = clockEl;
            }
        } else if (captureErrorEl) {
            captureErrorEl.hidden = true;
        }

        // 2. Vacation calculation mode cross-field requirements (mirror the server).
        const modeEl = form.querySelector('#user-vacation-mode');
        const mode = String(modeEl?.value || 'inherit');
        const manualDaysEl = form.querySelector('#user-manual-days');
        const tariffEl = form.querySelector('#user-tariff-rule-set-id');
        const reasonEl = form.querySelector('#user-override-reason');
        if (mode === 'manual_fixed' || mode === 'manual_exception') {
            const days = parseLocalizedDecimal(manualDaysEl?.value);
            if (days === undefined) {
                markInvalid(manualDaysEl, auMsg('manualDaysRequired', 'Enter the annual vacation days (e.g. 30 or 24.5).'));
            } else if (days < 0 || days > 366) {
                markInvalid(manualDaysEl, auMsg('manualDaysRange', 'Vacation days must be between 0 and 366.'));
            }
        }
        if (mode === 'manual_exception' && !(reasonEl?.value || '').trim()) {
            markInvalid(reasonEl, auMsg('overrideReasonRequired', 'A reason is required for a manual exception.'));
        }
        if (mode === 'tariff_rule_based' && !(tariffEl?.value || '')) {
            markInvalid(tariffEl, auMsg('tariffRuleSetRequired', 'Select a tariff rule set.'));
        }

        // 3. Legacy "vacation days per year" assignment field (0–365, integer).
        const vacationDaysEl = form.querySelector('#user-vacation-days');
        if (vacationDaysEl && String(vacationDaysEl.value || '').trim() !== '') {
            const n = Number(vacationDaysEl.value);
            if (!Number.isFinite(n) || n < 0 || n > 365) {
                markInvalid(vacationDaysEl, auMsg('vacationDaysRange', 'Vacation days per year must be between 0 and 365.'));
            }
        }

        // 4. Vacation carryover (Resturlaub) days + year.
        const carryoverEl = form.querySelector('#user-vacation-carryover');
        if (carryoverEl && String(carryoverEl.value || '').trim() !== '') {
            const carry = parseLocalizedDecimal(carryoverEl.value);
            if (carry === undefined || carry < 0 || carry > 366) {
                markInvalid(carryoverEl, auMsg('carryoverRange', 'Carryover must be a number between 0 and 366.'));
            }
        }
        const carryoverYearEl = form.querySelector('#user-vacation-carryover-year');
        if (carryoverYearEl && String(carryoverYearEl.value || '').trim() !== '') {
            if (!/^\d{4}$/.test(String(carryoverYearEl.value).trim())) {
                markInvalid(carryoverYearEl, auMsg('yearFourDigitsHelp', 'Enter a four-digit year (e.g. 2026).'));
            } else {
                const y = parseInt(carryoverYearEl.value, 10);
                if (y < 2000 || y > 2100) {
                    markInvalid(carryoverYearEl, auMsg('yearRange2000', 'Year must be between 2000 and 2100.'));
                }
            }
        }

        // 5. Overtime opening balance year (required, four digits, 2000–2100) + hours.
        const otYearEl = form.querySelector('#user-overtime-opening-year');
        const otYearRaw = String(otYearEl?.value || '').trim();
        if (!/^\d{4}$/.test(otYearRaw)) {
            markInvalid(otYearEl, auMsg('yearFourDigitsHelp', 'Enter a four-digit year (e.g. 2026).'));
        } else {
            const y = parseInt(otYearRaw, 10);
            if (y < 2000 || y > 2100) {
                markInvalid(otYearEl, auMsg('openingBalanceYearRange', 'Opening balance year must be between 2000 and 2100.'));
            }
        }
        const otHoursEl = form.querySelector('#user-overtime-opening');
        if (otHoursEl && String(otHoursEl.value || '').trim() !== '') {
            const hours = parseLocalizedDecimal(otHoursEl.value);
            if (hours === undefined || hours < -9999 || hours > 9999) {
                markInvalid(otHoursEl, auMsg('openingBalanceHoursRange', 'Opening balance hours must be a number between -9999 and 9999.'));
            }
        }

        // 6. Assignment validity window: strict ISO after dd.mm.yyyy conversion (never
        // send unconverted German dates to the API — see issue #15 / CHANGELOG 1.3.13).
        const startEl = form.querySelector('#user-start-date');
        const endEl = form.querySelector('#user-end-date');
        const toISO = resolveToISO();
        const isIso = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s);
        const assertValidOptionalDate = (field, raw) => {
            const trimmed = String(raw || '').trim();
            if (!trimmed) {
                return;
            }
            if (!isIso(toISO(trimmed))) {
                markInvalid(
                    field,
                    auMsg('invalidDateDdmmyyyy', 'Please enter a valid date (dd.mm.yyyy).')
                );
            }
        };
        assertValidOptionalDate(startEl, startEl?.value);
        assertValidOptionalDate(endEl, endEl?.value);
        const trackingEl = form.querySelector('#user-overtime-tracking-from');
        assertValidOptionalDate(trackingEl, trackingEl?.value);
        const startIso = toISO(String(startEl?.value || '').trim());
        const endIso = toISO(String(endEl?.value || '').trim());
        if (isIso(startIso) && isIso(endIso) && endIso < startIso) {
            markInvalid(endEl, auMsg('endDateAfterStart', 'The end date must be on or after the start date.'));
        }

        // 7. Employment period: valid optional dates and start <= end.
        const empStartEl = form.querySelector('#user-employment-start');
        const empEndEl = form.querySelector('#user-employment-end');
        assertValidOptionalDate(empStartEl, empStartEl?.value);
        assertValidOptionalDate(empEndEl, empEndEl?.value);
        const empStartIso = toISO(String(empStartEl?.value || '').trim());
        const empEndIso = toISO(String(empEndEl?.value || '').trim());
        if (isIso(empStartIso) && isIso(empEndIso) && empEndIso < empStartIso) {
            markInvalid(empEndEl, auMsg('employmentEndAfterStart', 'The employment end date must be on or after the employment start date.'));
        }

        return firstInvalid;
    }

    /**
     * Handle update user form submission
     */
    function convertISOToEuropean(s) {
        if (!s || !/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
        const p = s.split('-');
        return p[2] + '.' + p[1] + '.' + p[0];
    }

    /**
     * Resolve a European→ISO date converter. Prefer the shared datepicker module
     * but fall back to a local implementation so the save never sends an
     * unconverted `dd.mm.yyyy` value to the strict server-side parser (the
     * historic cause of the "Benutzer konnte nicht aktualisiert werden" 400 when
     * the datepicker asset failed to load).
     */
    function resolveToISO() {
        const dp = window.ArbeitszeitCheckDatepicker;
        if (dp && typeof dp.convertEuropeanToISO === 'function') {
            return dp.convertEuropeanToISO;
        }
        return convertEuropeanToISOLocal;
    }

    function convertEuropeanToISOLocal(value) {
        const s = String(value == null ? '' : value).trim();
        if (!s) return '';
        // Already ISO — leave untouched.
        if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
        const m = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
        if (!m) return s;
        const day = m[1].padStart(2, '0');
        const month = m[2].padStart(2, '0');
        const year = m[3];
        return year + '-' + month + '-' + day;
    }

    function todayYmd() {
        if (window.ArbeitszeitCheckTime && typeof window.ArbeitszeitCheckTime.todayYmd === 'function') {
            return window.ArbeitszeitCheckTime.todayYmd();
        }
        return new Date().toISOString().slice(0, 10);
    }

    /**
     * Build the four independent payloads from the validated form. Field-level
     * validity is assumed (see {@link validateUserEditForm}); this function only
     * shapes the data for the respective endpoints.
     */
    function buildUpdatePayloads(form) {
        const formData = new FormData(form);
        const toISO = resolveToISO();

        const workingTimeModel = {
            workingTimeModelId: formData.get('workingTimeModelId') ? parseInt(formData.get('workingTimeModelId'), 10) : null,
            vacationDaysPerYear: formData.get('vacationDaysPerYear') ? parseInt(formData.get('vacationDaysPerYear'), 10) : null,
            vacationCarryoverDays: formData.get('vacationCarryoverDays') !== null && formData.get('vacationCarryoverDays') !== ''
                ? parseLocalizedDecimal(formData.get('vacationCarryoverDays'))
                : undefined,
            vacationCarryoverYear: formData.get('vacationCarryoverYear') ? parseInt(String(formData.get('vacationCarryoverYear')), 10) : undefined,
            startDate: toISO(formData.get('startDate') || '') || null,
            endDate: toISO(formData.get('endDate') || '') || null,
            germanState: (formData.get('germanState') || '').toString(),
            laborLawCountry: (formData.get('laborLawCountry') || '').toString()
        };

        const mode = (formData.get('vacationMode') || 'inherit').toString();
        const isInherit = mode === 'inherit';
        const isManual = mode === 'manual_fixed' || mode === 'manual_exception';
        const policyIdRaw = formData.get('vacationPolicyId');
        const policyId = policyIdRaw && String(policyIdRaw).trim() !== ''
            ? parseInt(String(policyIdRaw), 10)
            : null;
        const loadedWtmStart = String(formData.get('loadedWtmStart') || '').trim();
        const policyEffectiveFrom = String(formData.get('policyEffectiveFrom') || '').trim();
        const newStartIso = workingTimeModel.startDate || '';
        // Keep the existing policy row when the assignment start date did not change.
        // Using only the work-schedule start date would spawn duplicate policy rows on
        // every no-op save (effective_from drift vs. the row being edited).
        let vacationEffectiveFrom = newStartIso || todayYmd();
        if (policyId && policyEffectiveFrom && loadedWtmStart && newStartIso === loadedWtmStart) {
            vacationEffectiveFrom = policyEffectiveFrom;
        }
        const vacationPolicy = {
            policyId: policyId,
            vacationMode: mode,
            inheritLowerLayers: isInherit,
            manualDays: (!isInherit && isManual) ? (parseLocalizedDecimal(formData.get('manualDays')) ?? null) : null,
            tariffRuleSetId: (!isInherit && mode === 'tariff_rule_based' && formData.get('tariffRuleSetId'))
                ? parseInt(String(formData.get('tariffRuleSetId')), 10)
                : null,
            overrideReason: (!isInherit && mode === 'manual_exception') ? (formData.get('overrideReason') || '').toString() : '',
            effectiveFrom: vacationEffectiveFrom,
            effectiveTo: workingTimeModel.endDate || null
        };

        const timeCapture = readTimeCapturePayload(form);

        const trackingRaw = String(form.querySelector('#user-overtime-tracking-from')?.value || '').trim();
        const trackingIso = trackingRaw ? (toISO(trackingRaw) || null) : null;
        const overtime = {
            trackingFrom: trackingIso,
            openingBalance: {
                year: parseInt(String(form.querySelector('#user-overtime-opening-year')?.value || '').trim(), 10),
                hours: form.querySelector('#user-overtime-opening')?.value || '0'
            }
        };

        // Employment period (Eintritts-/Austrittsdatum) drives pro-rata vacation
        // entitlement in partial years. Empty strings clear the stored value.
        const employmentStartRaw = String(form.querySelector('#user-employment-start')?.value || '').trim();
        const employmentEndRaw = String(form.querySelector('#user-employment-end')?.value || '').trim();
        const employment = {
            start: employmentStartRaw ? (toISO(employmentStartRaw) || '') : '',
            end: employmentEndRaw ? (toISO(employmentEndRaw) || '') : ''
        };

        const datev = {
            personalnummer: String(form.querySelector('#user-datev-personalnummer')?.value || '').trim()
        };

        return { workingTimeModel, vacationPolicy, timeCapture, overtime, employment, datev };
    }

    /**
     * Issue a PUT and normalise both transport-level (HTTP) and application-level
     * ({success:false}) failures into a thrown error carrying a user-facing
     * `.error` message, so the orchestration can surface a specific reason.
     */
    async function apiPut(path, data) {
        const response = await Utils.ajax(buildApiUrl(path), { method: 'PUT', data: data });
        if (!response || response.success === false) {
            const err = new Error((response && response.error) || auMsg('failedToUpdateUser', 'Failed to update user'));
            err.error = (response && response.error) || err.message;
            throw err;
        }
        return response;
    }

    async function handleUpdateUser(form, userId) {
        if (saveInFlight) {
            return;
        }
        const firstInvalid = validateUserEditForm(form);
        if (firstInvalid) {
            if (typeof firstInvalid.focus === 'function') {
                firstInvalid.focus();
            }
            Messaging.showError(auMsg('formHasErrors', 'Please correct the highlighted fields and try again.'));
            return;
        }

        const payloads = buildUpdatePayloads(form);
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalLabel = submitBtn ? submitBtn.textContent : '';
        saveInFlight = true;
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
            submitBtn.textContent = auMsg('saving', 'Saving…');
        }

        try {
            await apiPut('/apps/arbeitszeitcheck/api/admin/users/' + encodeURIComponent(userId) + '/profile', payloads);

            clearDirty();
            Messaging.showSuccess(auMsg('userUpdated', 'User updated successfully'));
            loadUserDetail(userId);
        } catch (error) {
            const serverErrors = error && error.data && error.data.errors;
            const serverField = applyServerFieldErrors(form, serverErrors);
            if (serverField && typeof serverField.focus === 'function') {
                serverField.focus();
            }
            const message = (error && error.error) ? error.error : auMsg('failedToUpdateUser', 'Failed to update user');
            Messaging.showError(
                serverField
                    ? auMsg('formHasErrors', 'Please correct the highlighted fields and try again.')
                    : message
            );
        } finally {
            saveInFlight = false;
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.removeAttribute('aria-busy');
                submitBtn.textContent = originalLabel;
            }
        }
    }

    // Initialize on DOM ready
    if (typeof window !== 'undefined') {
        window.__ArbeitszeitCheckAdminUserDetailTestables = {
            promptUnsavedLeave: promptUnsavedLeave,
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
