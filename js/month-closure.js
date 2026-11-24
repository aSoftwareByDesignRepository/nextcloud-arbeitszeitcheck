/**
 * Revision-safe month closure UI (time entries page).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

(function () {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};

    function $(id) {
        return document.getElementById(id);
    }

    /**
     * Prefer server-injected l10n (matches user locale); then window.t; then English.
     */
    function mcT(key, fallback) {
        const l = window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n;
        if (l && typeof l[key] === 'string' && l[key] !== '') {
            return l[key];
        }
        if (typeof window.t === 'function') {
            const r = window.t('arbeitszeitcheck', fallback);
            if (r && r !== fallback) {
                return r;
            }
        }
        return fallback;
    }

    function localeTagForDates() {
        if (typeof OC !== 'undefined' && typeof OC.getLocale === 'function') {
            const loc = OC.getLocale();
            if (loc) {
                return loc.replace(/_/g, '-');
            }
        }
        if (typeof OC !== 'undefined' && typeof OC.getLanguage === 'function') {
            const lang = OC.getLanguage();
            if (lang) {
                return lang.replace(/_/g, '-');
            }
        }
        return undefined;
    }

    function setPeriodSelectBusy(periodSel, busy) {
        if (!periodSel) {
            return;
        }
        periodSel.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    /**
     * Visible status line (WCAG: not screen-reader-only; complements the badge).
     */
    function setFeedback(msg, isError) {
        const el = $('month-closure-feedback');
        if (!el) {
            return;
        }
        el.textContent = msg || '';
        el.classList.toggle('month-closure-feedback--error', Boolean(isError && msg));
        el.classList.toggle('month-closure-feedback--success', Boolean(!isError && msg));
    }

    function announce(msg, isError) {
        setFeedback(msg, isError);
    }

    function setPdfLink(year, month, visible) {
        const a = $('month-closure-pdf');
        if (!a) {
            return;
        }
        if (!visible) {
            a.style.display = 'none';
            a.setAttribute('href', '#');
            a.setAttribute('tabindex', '-1');
            a.removeAttribute('aria-label');
            return;
        }
        a.href = Utils.buildAppUrl
            ? Utils.buildAppUrl('/apps/arbeitszeitcheck/api/month-closure/pdf?year=' + encodeURIComponent(String(year)) + '&month=' + encodeURIComponent(String(month)))
            : (Utils.resolveUrl
                ? Utils.resolveUrl('/apps/arbeitszeitcheck/api/month-closure/pdf?year=' + encodeURIComponent(String(year)) + '&month=' + encodeURIComponent(String(month)))
                : '#');
        a.style.display = 'inline-block';
        a.removeAttribute('tabindex');
        const l = window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n;
        const tpl = (l && typeof l.monthClosurePdfDownloadAria === 'string') ? l.monthClosurePdfDownloadAria : '';
        const period = formatCalendarMonthLabel(year, month);
        if (tpl && period) {
            a.setAttribute('aria-label', tpl.replace(/\{period\}/g, period));
        } else {
            a.removeAttribute('aria-label');
        }
    }

    function formatYmdLocal(ymd) {
        if (!ymd || typeof ymd !== 'string') {
            return '';
        }
        const api = window.ArbeitszeitCheckTime;
        const d = api ? api.parseYmd(ymd) : new Date(ymd + 'T12:00:00');
        if (Number.isNaN(d.getTime())) {
            return ymd;
        }
        return d.toLocaleDateString(localeTagForDates(), { year: 'numeric', month: 'long', day: 'numeric' });
    }

    /**
     * Localized "April 2026" for period dropdown options.
     */
    function formatCalendarMonthLabel(year, month) {
        const d = new Date(year, month - 1, 1);
        return new Intl.DateTimeFormat(localeTagForDates(), { month: 'long', year: 'numeric' }).format(d);
    }

    function parsePeriodValue(value) {
        if (!value || typeof value !== 'string') {
            return { y: NaN, m: NaN };
        }
        const parts = value.split('-');
        const y = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10);
        return { y: y, m: m };
    }

    function showPeriodsLoading(periodSel) {
        periodSel.innerHTML = '';
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = mcT('monthClosureLoadingPeriods', 'Loading months…');
        opt.disabled = true;
        periodSel.appendChild(opt);
        periodSel.disabled = true;
        setPeriodSelectBusy(periodSel, true);
    }

    function setMonthClosureStatus(text, statusKey) {
        const statusEl = $('month-closure-status');
        if (!statusEl) {
            return;
        }
        statusEl.textContent = text || '';
        if (Utils.applyMonthClosureBadgeVariant) {
            Utils.applyMonthClosureBadgeVariant(statusEl, statusKey || 'neutral');
        }
    }

    function refreshStatus() {
        const periodEl = $('month-closure-period');
        if (!periodEl) {
            return;
        }
        const { y, m } = parsePeriodValue(periodEl.value);
        const statusEl = $('month-closure-status');
        if (!statusEl) {
            return;
        }
        if (!Number.isInteger(y) || !Number.isInteger(m) || m < 1 || m > 12) {
            setMonthClosureStatus('', 'neutral');
            return;
        }
        const btn = $('month-closure-finalize');
        const deadlineEl = $('month-closure-deadline');
        const blockedEl = $('month-closure-blocked');
        const reviewEl = $('month-closure-review-warning');
        setMonthClosureStatus(mcT('monthClosureLoading', '…'), 'neutral');
        if (deadlineEl) {
            deadlineEl.hidden = true;
            deadlineEl.textContent = '';
        }
        if (blockedEl) {
            blockedEl.hidden = true;
            blockedEl.textContent = '';
        }
        if (reviewEl) {
            reviewEl.hidden = true;
            reviewEl.textContent = '';
        }
        setFeedback('', false);

        Utils.ajax('/apps/arbeitszeitcheck/api/month-closure/status?year=' + encodeURIComponent(String(y)) + '&month=' + encodeURIComponent(String(m)), {
            method: 'GET',
            onSuccess: function (data) {
                if (!data || !data.success) {
                    setMonthClosureStatus('', 'neutral');
                    if (btn) {
                        btn.disabled = true;
                    }
                    announce(mcT('monthClosureStatusError', 'Could not load status. Try again.'), true);
                    return;
                }
                if (!data.featureEnabled) {
                    setMonthClosureStatus('', 'neutral');
                    if (btn) {
                        btn.disabled = true;
                    }
                    if (blockedEl && data.finalizeBlockedMessage) {
                        blockedEl.textContent = data.finalizeBlockedMessage;
                        blockedEl.hidden = false;
                    }
                    setPdfLink(y, m, false);
                    return;
                }

                const grace = typeof data.graceDaysAfterEom === 'number' ? data.graceDaysAfterEom : parseInt(String(data.graceDaysAfterEom || 0), 10);
                const deadline = data.manualFinalizeDeadline;
                const isFinalized = data.status === 'finalized';
                const canFinalize = Boolean(data.canFinalize);

                if (blockedEl && !isFinalized && data.finalizeBlockedMessage) {
                    blockedEl.textContent = data.finalizeBlockedMessage;
                    blockedEl.hidden = false;
                }

                // §9.6 closure rule: review-state split entries stay
                // closable — shown as a warning, never a blocker. The
                // affected dates are listed so HR can find and correct
                // the entries before finalizing.
                if (reviewEl && !isFinalized && data.reviewWarning) {
                    var reviewDates = Array.isArray(data.reviewEntries)
                        ? data.reviewEntries
                            .map(function (e) { return e && e.date ? formatYmdLocal(e.date) : null; })
                            .filter(function (d) { return d; })
                        : [];
                    reviewEl.textContent = reviewDates.length > 0
                        ? data.reviewWarning + ' ' + reviewDates.join(', ')
                        : data.reviewWarning;
                    reviewEl.hidden = false;
                }

                if (deadlineEl && grace > 0 && deadline && !isFinalized && canFinalize) {
                    const formatted = formatYmdLocal(deadline);
                    const tmpl = mcT(
                        'monthClosureDeadline',
                        'Please finalize this month by {date} (end of the configured grace period). After that, it may be sealed automatically if it is still open.'
                    );
                    deadlineEl.textContent = tmpl.replace(/\{date\}/g, formatted);
                    deadlineEl.hidden = false;
                }

                const lockNotice = $('month-closure-finalized-notice');
                const divergedEl = $('month-closure-contract-diverged');
                if (isFinalized) {
                    if (data.autoFinalized) {
                        setMonthClosureStatus(mcT('monthClosureStatusFinalizedAuto', 'Finalized automatically'), 'finalized');
                    } else {
                        setMonthClosureStatus(mcT('monthClosureStatusFinalized', 'Finalized'), 'finalized');
                    }
                    if (btn) {
                        btn.disabled = true;
                    }
                    if (lockNotice) {
                        lockNotice.hidden = false;
                    }
                    if (divergedEl) {
                        divergedEl.hidden = data.contractDiverged !== true;
                    }
                    if (blockedEl) {
                        blockedEl.hidden = true;
                    }
                    setPdfLink(y, m, true);
                } else {
                    if (lockNotice) {
                        lockNotice.hidden = true;
                    }
                    if (divergedEl) {
                        divergedEl.hidden = true;
                    }
                    setMonthClosureStatus(mcT('monthClosureStatusOpen', 'Open (month status)'), 'open');
                    if (btn) {
                        btn.disabled = !canFinalize;
                    }
                    setPdfLink(y, m, false);
                }
            },
            onError: function () {
                setMonthClosureStatus('', 'neutral');
                if (deadlineEl) {
                    deadlineEl.hidden = true;
                }
                if (blockedEl) {
                    blockedEl.hidden = true;
                }
                if (reviewEl) {
                    reviewEl.hidden = true;
                }
                if (btn) {
                    btn.disabled = true;
                }
                announce(mcT('monthClosureStatusError', 'Could not load status. Try again.'), true);
            }
        });
    }

    function wireFinalizeButton(periodSel) {
        const fin = $('month-closure-finalize');
        if (!fin || fin.getAttribute('data-mc-bound') === '1') {
            return;
        }
        fin.setAttribute('data-mc-bound', '1');
        fin.addEventListener('click', async function () {
            if (fin.disabled) {
                return;
            }
            const msg = fin.getAttribute('data-confirm-finalize') || '';
            if (msg) {
                const confirmed = await Utils.confirmDestructiveAction({
                    title: mcT('finalizeConfirmTitle', 'Finalize month'),
                    message: msg,
                    confirmLabel: mcT('finalizeConfirmAction', 'Finalize'),
                    variant: 'danger',
                });
                if (!confirmed) {
                    return;
                }
            }
            const { y, m } = parsePeriodValue(periodSel.value);
            if (!Number.isInteger(y) || !Number.isInteger(m) || m < 1 || m > 12) {
                return;
            }
            fin.disabled = true;
            setFeedback('', false);
            Utils.ajax('/apps/arbeitszeitcheck/api/month-closure/finalize', {
                method: 'POST',
                data: { year: y, month: m },
                onSuccess: function (data) {
                    if (data && data.success) {
                        announce(mcT('monthClosureFinalizedSuccess', 'Month finalized.'), false);
                        refreshStatus();
                    } else {
                        announce((data && data.error) ? data.error : '', true);
                        refreshStatus();
                    }
                    fin.disabled = false;
                },
                onError: function (err) {
                    announce(err && err.error ? err.error : mcT('monthClosureError', 'Error'), true);
                    refreshStatus();
                    fin.disabled = false;
                }
            });
        });
    }

    function init() {
        const periodSel = $('month-closure-period');
        if (!periodSel) {
            return;
        }

        showPeriodsLoading(periodSel);
        const btn = $('month-closure-finalize');
        if (btn) {
            btn.disabled = true;
        }

        Utils.ajax('/apps/arbeitszeitcheck/api/month-closure/periods', {
            method: 'GET',
            onSuccess: function (data) {
                periodSel.innerHTML = '';
                if (!data || !data.success) {
                    periodSel.disabled = true;
                    setPeriodSelectBusy(periodSel, false);
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = mcT('monthClosurePeriodsLoadError', 'Could not load months.');
                    opt.disabled = true;
                    periodSel.appendChild(opt);
                    if (btn) {
                        btn.disabled = true;
                    }
                    wireFinalizeButton(periodSel);
                    return;
                }
                if (!data.featureEnabled) {
                    periodSel.disabled = true;
                    setPeriodSelectBusy(periodSel, false);
                    wireFinalizeButton(periodSel);
                    return;
                }
                const periods = data.periods || [];
                const blockedEl = $('month-closure-blocked');
                if (periods.length === 0) {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = mcT('monthClosureNoPeriods', 'No completed months with time entries yet.');
                    opt.disabled = true;
                    opt.selected = true;
                    periodSel.appendChild(opt);
                    periodSel.disabled = true;
                    setPeriodSelectBusy(periodSel, false);
                    if (blockedEl) {
                        blockedEl.textContent = mcT(
                            'monthClosureNoPeriodsHint',
                            'Record working time in a month first. After that calendar month has ended, you can seal it here.'
                        );
                        blockedEl.hidden = false;
                    }
                    if (btn) {
                        btn.disabled = true;
                    }
                    const statusEl = $('month-closure-status');
                    if (statusEl) {
                        statusEl.textContent = '';
                    }
                    setPdfLink(0, 0, false);
                    wireFinalizeButton(periodSel);
                    return;
                }

                periodSel.disabled = false;
                setPeriodSelectBusy(periodSel, false);
                periods.forEach(function (p, idx) {
                    const y = p.year;
                    const mo = p.month;
                    const opt = document.createElement('option');
                    opt.value = y + '-' + String(mo).padStart(2, '0');
                    opt.textContent = formatCalendarMonthLabel(y, mo);
                    if (idx === 0) {
                        opt.selected = true;
                    }
                    periodSel.appendChild(opt);
                });

                periodSel.addEventListener('change', refreshStatus);
                wireFinalizeButton(periodSel);
                refreshStatus();
            },
            onError: function () {
                periodSel.innerHTML = '';
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = mcT('monthClosurePeriodsLoadError', 'Could not load months.');
                opt.disabled = true;
                periodSel.appendChild(opt);
                periodSel.disabled = true;
                setPeriodSelectBusy(periodSel, false);
                if (btn) {
                    btn.disabled = true;
                }
                wireFinalizeButton(periodSel);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
