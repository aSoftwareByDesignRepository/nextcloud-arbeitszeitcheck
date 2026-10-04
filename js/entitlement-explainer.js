/**
 * Employee vacation entitlement explainer (Absences page).
 * Loads a redacted trace from AbsenceController::entitlementTrace (REQ-SEC-05).
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */
(function (window) {
  'use strict';

  const ROOT_ID = 'arbeitszeitcheck-app';
  const DIALOG_ID = 'entitlement-explain-dialog';
  const TRIGGER_ID = 'entitlement-explain';
  const BODY_ID = 'entitlement-explain-body';
  const CLOSE_ID = 'entitlement-explain-close';
  const DISMISS_ID = 'entitlement-explain-dismiss';
  const RETRY_ID = 'entitlement-explain-retry';
  const BOOTSTRAP_ID = 'entitlement-explainer-bootstrap';
  const PAGE_LOCK_CLASS = 'entitlement-explain-page-locked';
  const FETCH_TIMEOUT_MS = 15000;

  let loadGeneration = 0;
  let activeAbortController = null;
  let returnFocusEl = null;
  let fallbackBackdrop = null;
  let initialized = false;

  function readBootstrap() {
    const el = document.getElementById(BOOTSTRAP_ID);
    if (el && el.textContent) {
      try {
        const parsed = JSON.parse(el.textContent);
        if (parsed && typeof parsed === 'object') {
          return parsed;
        }
      } catch (e) { /* noop */ }
    }
  return (window.ArbeitszeitCheck && window.ArbeitszeitCheck.entitlementExplainer) || {};
  }

  function strings() {
    return readBootstrap();
  }

  function tt(id) {
    const S = strings();
    if (S[id]) {
      return S[id];
    }
    if (typeof window.t === 'function') {
      const r = window.t('arbeitszeitcheck', id);
      if (r && r !== id) {
        return r;
      }
    }
    return id;
  }

  function escapeHtml(value) {
    const Utils = window.ArbeitszeitCheckUtils;
    if (Utils && typeof Utils.escapeHtml === 'function') {
      return Utils.escapeHtml(value);
    }
    if (value === null || value === undefined) {
      return '';
    }
    return String(value).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function normalizeTraceUrl(url) {
    const Utils = window.ArbeitszeitCheckUtils;
    if (Utils && typeof Utils.toSameOriginPath === 'function') {
      return Utils.toSameOriginPath(url);
    }
    if (typeof url === 'string' && /^https?:\/\//.test(url)) {
      try {
        const parsed = new URL(url);
        return parsed.pathname + parsed.search;
      } catch (e) { /* noop */ }
    }
    return url;
  }

  function resolveTraceUrl() {
    const S = strings();
    const Utils = window.ArbeitszeitCheckUtils;
    if (S.traceUrl) {
      const normalized = normalizeTraceUrl(S.traceUrl);
      if (Utils && typeof Utils.resolveUrl === 'function') {
        return Utils.resolveUrl(normalized);
      }
      return normalized;
    }
    if (Utils && typeof Utils.resolveUrl === 'function') {
      return Utils.resolveUrl('/apps/arbeitszeitcheck/api/absences/entitlement-trace');
    }
    return '';
  }

  function layerHuman(label) {
    const layerHumanMap = {
      L3: tt('individualRule'),
      L2: tt('teamPolicy'),
      L1: tt('workingTimeModel'),
      L0: tt('organisationDefault'),
      legacy: tt('defaultFallback')
    };
    return layerHumanMap[label] || label;
  }

  function formatDays(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) {
      return '—';
    }
    const rounded = Math.round(n * 10) / 10;
    return (rounded % 1 === 0) ? String(Math.round(rounded)) : rounded.toFixed(1);
  }

  function buildExplainerHtml(payload) {
    const trace = (payload && payload.trace) ? payload.trace : {};
    const layers = Array.isArray(trace.layers_evaluated) ? trace.layers_evaluated : [];
    const matched = (payload && payload.matchedLayer)
      ? String(payload.matchedLayer)
      : ((trace && trace.matched_layer) ? String(trace.matched_layer) : '—');
    const matchedHuman = layerHuman(matched);
    const fullDays = (payload && payload.effectiveEntitlementDays != null)
      ? payload.effectiveEntitlementDays
      : trace.result_days;
    const isProrated = !!(payload && payload.prorated === true);
    // Headline shows what the employee actually gets this year (prorated when
    // a partial employment year applies), falling back to the full figure.
    const days = (isProrated && payload && payload.proratedEntitlementDays != null)
      ? payload.proratedEntitlementDays
      : fullDays;
    const asOf = (payload && payload.asOfDate) || trace.as_of_date || '';

    const steps = layers.map(function (layer) {
      const label = layer.layer || '—';
      const humanLabel = layerHuman(label);
      const matchedRow = layer.matched === true;
      const outcome = matchedRow ? tt('applied') : tt('skipped');
      const stateClass = matchedRow
        ? 'entitlement-explain-dialog__step--applied'
        : 'entitlement-explain-dialog__step--skipped';
      let extras = '';
       if (layer.partial_history) {
        extras = '<p class="entitlement-explain-dialog__step-note">'
          + escapeHtml(tt('partialHistoryHint'))
          + '</p>';
      }
      return ''
        + '<li class="entitlement-explain-dialog__step ' + stateClass + '">'
        + '<div class="entitlement-explain-dialog__step-head">'
        + '<span class="entitlement-explain-dialog__step-badge" aria-hidden="true"></span>'
        + '<span class="entitlement-explain-dialog__step-title">' + escapeHtml(humanLabel) + '</span>'
        + '<span class="entitlement-explain-dialog__step-outcome">'
        + '<span class="visually-hidden">' + escapeHtml(outcome) + '</span>'
        + '<span aria-hidden="true">' + escapeHtml(outcome) + '</span>'
        + '</span>'
        + '</div>'
        + extras
        + '</li>';
    }).join('');

    let bannerHtml = '';
    if (trace && trace.degraded) {
      bannerHtml += '<p class="entitlement-explain-dialog__banner entitlement-explain-dialog__banner--warn" role="alert">'
        + escapeHtml(tt('degradedBanner'))
        + '</p>';
    }
    if (trace && trace.clamped) {
      bannerHtml += '<p class="entitlement-explain-dialog__banner entitlement-explain-dialog__banner--info" role="status">'
        + escapeHtml(tt('clampedBanner'))
        + '</p>';
    }

    let prorationNote = '';
    if (isProrated) {
      let tpl;
      if (payload && payload.employedInYear === false) {
        tpl = tt('prorationNoteNone');
      } else if (payload && payload.prorationMethod === 'daily') {
        tpl = tt('prorationNoteDaily');
      } else {
        tpl = tt('prorationNoteTwelfths');
      }
      const noteText = String(tpl)
        .replace('{prorated}', formatDays(days))
        .replace('{full}', formatDays(fullDays))
        .replace('{months}', String((payload && payload.prorationMonthsCovered != null) ? payload.prorationMonthsCovered : ''));
      prorationNote = '<p class="entitlement-explain-dialog__proration" role="note">'
        + escapeHtml(noteText)
        + '</p>';
    }

    let asOfLine = '';
    if (asOf) {
      const asOfTpl = tt('asOfDate');
      const asOfText = asOfTpl.indexOf('%s') !== -1
        ? asOfTpl.replace('%s', String(asOf))
        : (asOfTpl + ' ' + asOf);
      asOfLine = '<p class="entitlement-explain-dialog__as-of">' + escapeHtml(asOfText) + '</p>';
    }

    return ''
      + bannerHtml
      + '<section class="entitlement-explain-dialog__summary" aria-labelledby="entitlement-explain-summary-title">'
      + '<h3 id="entitlement-explain-summary-title" class="entitlement-explain-dialog__summary-title">'
      + escapeHtml(tt('summaryTitle'))
      + '</h3>'
      + '<p class="entitlement-explain-dialog__summary-days">'
      + '<span class="entitlement-explain-dialog__summary-value">' + escapeHtml(formatDays(days)) + '</span>'
      + '<span class="entitlement-explain-dialog__summary-unit">' + escapeHtml(tt('daysPerYear')) + '</span>'
      + '</p>'
      + prorationNote
      + asOfLine
      + '<p class="entitlement-explain-dialog__lead">'
      + '<span class="entitlement-explain-dialog__lead-text">' + escapeHtml(tt('layerDeterminedLead')) + '</span> '
      + '<strong class="entitlement-explain-dialog__lead-layer">' + escapeHtml(matchedHuman) + '</strong>'
      + '</p>'
      + '</section>'
      + '<section class="entitlement-explain-dialog__chain" aria-labelledby="entitlement-explain-chain-title">'
      + '<h3 id="entitlement-explain-chain-title" class="entitlement-explain-dialog__chain-title">'
      + escapeHtml(tt('chainTitle'))
      + '</h3>'
      + '<ol class="entitlement-explain-dialog__steps">' + steps + '</ol>'
      + '</section>'
      + '<p class="entitlement-explain-dialog__hint">' + escapeHtml(tt('hintContactHr')) + '</p>';
  }

  function renderLoading(body) {
    if (!body) {
      return;
    }
    body.setAttribute('aria-busy', 'true');
    body.innerHTML = ''
      + '<div class="entitlement-explain-dialog__loading" role="status">'
      + '<span class="entitlement-explain-dialog__spinner" aria-hidden="true"></span>'
      + '<p class="entitlement-explain-dialog__loading-text">' + escapeHtml(tt('loading')) + '</p>'
      + '</div>';
  }

  function renderError(body, message) {
    if (!body) {
      return;
    }
    body.setAttribute('aria-busy', 'false');
    body.innerHTML = ''
      + '<p class="entitlement-explain-dialog__error" role="alert">' + escapeHtml(message || tt('loadError')) + '</p>';
    const retry = document.getElementById(RETRY_ID);
    if (retry) {
      retry.hidden = false;
    }
  }

  function renderSuccess(body, html) {
    if (!body) {
      return;
    }
    body.setAttribute('aria-busy', 'false');
    body.innerHTML = html;
    const retry = document.getElementById(RETRY_ID);
    if (retry) {
      retry.hidden = true;
    }
  }

  function abortActiveFetch() {
    if (activeAbortController) {
      try {
        activeAbortController.abort();
      } catch (e) { /* noop */ }
      activeAbortController = null;
    }
  }

  function fetchTrace(signal) {
    const url = resolveTraceUrl();
    const Utils = window.ArbeitszeitCheckUtils;
    const opts = { method: 'GET', signal: signal };

    if (Utils && typeof Utils.ajax === 'function') {
      return Utils.ajax(url, opts);
    }

    const token = (Utils && typeof Utils.getRequestToken === 'function')
      ? Utils.getRequestToken()
      : ((typeof OC !== 'undefined' && OC.requestToken) ? OC.requestToken : '');

    return fetch(url, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        requesttoken: token
      },
      credentials: 'same-origin',
      signal: signal
    }).then(function (response) {
      return response.json().catch(function () { return null; }).then(function (data) {
        if (!response.ok) {
          const err = new Error((data && data.error) || tt('loadError'));
          err.error = (data && data.error) || err.message;
          err.status = response.status;
          throw err;
        }
        return data;
      });
    });
  }

  function fetchTraceWithTimeout() {
    abortActiveFetch();
    const controller = new AbortController();
    activeAbortController = controller;

    const timeoutId = window.setTimeout(function () {
      try {
        controller.abort();
      } catch (e) { /* noop */ }
    }, FETCH_TIMEOUT_MS);

    return fetchTrace(controller.signal).finally(function () {
      window.clearTimeout(timeoutId);
      if (activeAbortController === controller) {
        activeAbortController = null;
      }
    });
  }

  function getAppRoot() {
    return document.getElementById(ROOT_ID);
  }

  function ensureFallbackBackdrop() {
    if (fallbackBackdrop && document.body.contains(fallbackBackdrop)) {
      return fallbackBackdrop;
    }
    fallbackBackdrop = document.createElement('div');
    fallbackBackdrop.className = 'entitlement-explain-dialog__fallback-backdrop';
    fallbackBackdrop.setAttribute('aria-hidden', 'true');
    fallbackBackdrop.addEventListener('click', function () {
      requestClose(document.getElementById(DIALOG_ID));
    });
    return fallbackBackdrop;
  }

  function setPageLocked(locked) {
    document.documentElement.classList.toggle(PAGE_LOCK_CLASS, locked);
    document.body.style.overflow = locked ? 'hidden' : '';

    const root = getAppRoot();
    if (!root) {
      return;
    }
    if (locked) {
      root.setAttribute('inert', '');
      root.setAttribute('aria-hidden', 'true');
    } else {
      root.removeAttribute('inert');
      root.removeAttribute('aria-hidden');
    }
  }

  function isDialogOpen(dlg) {
    return !!(dlg && (dlg.open === true || dlg.hasAttribute('open')));
  }

  function openDialog(dlg) {
    if (!dlg) {
      return;
    }
    returnFocusEl = (document.activeElement instanceof HTMLElement) ? document.activeElement : null;

    if (dlg.parentElement !== document.body) {
      document.body.appendChild(dlg);
    }

    let openedNative = false;
    if (typeof dlg.showModal === 'function') {
      try {
        if (dlg.open) {
          dlg.close();
        }
        dlg.showModal();
        openedNative = dlg.open === true;
      } catch (e) {
        openedNative = false;
      }
    }

    if (!openedNative) {
      dlg.setAttribute('open', 'open');
      const backdrop = ensureFallbackBackdrop();
      if (!backdrop.parentElement) {
        document.body.insertBefore(backdrop, dlg);
      }
      backdrop.hidden = false;
    }

    setPageLocked(true);

    const focusTarget = document.getElementById(DISMISS_ID)
      || document.getElementById(CLOSE_ID)
      || dlg.querySelector('.entitlement-explain-dialog__panel');
    if (focusTarget && typeof focusTarget.focus === 'function') {
      try {
        focusTarget.focus({ preventScroll: true });
      } catch (e) { /* noop */ }
    }
  }

  function cleanupDialogState(dlg) {
    if (fallbackBackdrop) {
      fallbackBackdrop.hidden = true;
      if (fallbackBackdrop.parentElement) {
        fallbackBackdrop.parentElement.removeChild(fallbackBackdrop);
      }
    }
    setPageLocked(false);
    if (dlg) {
      dlg.removeAttribute('open');
    }
  }

  function restoreFocus() {
    const target = returnFocusEl;
    returnFocusEl = null;
    if (target && document.body.contains(target) && typeof target.focus === 'function') {
      try {
        target.focus();
        return;
      } catch (e) { /* noop */ }
    }
    const trigger = document.getElementById(TRIGGER_ID);
    if (trigger && typeof trigger.focus === 'function') {
      try {
        trigger.focus();
      } catch (e) { /* noop */ }
    }
  }

  function requestClose(dlg) {
    if (!dlg) {
      return;
    }
    loadGeneration += 1;
    abortActiveFetch();

    if (typeof dlg.close === 'function') {
      try {
        dlg.close();
      } catch (e) {
        cleanupDialogState(dlg);
        restoreFocus();
      }
      return;
    }

    cleanupDialogState(dlg);
    restoreFocus();
  }

  function loadExplanation() {
    const dlg = document.getElementById(DIALOG_ID);
    const body = document.getElementById(BODY_ID);
    if (!dlg || !body) {
      return;
    }

    const generation = ++loadGeneration;
    renderLoading(body);

    fetchTraceWithTimeout()
      .then(function (data) {
        if (generation !== loadGeneration || !isDialogOpen(dlg)) {
          return;
        }
        if (!data || data.success !== true) {
          renderError(body, (data && data.error) ? String(data.error) : tt('loadError'));
          return;
        }
        renderSuccess(body, buildExplainerHtml(data));
      })
      .catch(function (err) {
        if (generation !== loadGeneration || !isDialogOpen(dlg)) {
          return;
        }
        if (err && err.name === 'AbortError') {
          return;
        }
        const msg = (err && (err.error || err.message)) ? String(err.error || err.message) : tt('loadError');
        renderError(body, msg);
      });
  }

  function handleTriggerClick(ev) {
    const trigger = ev.target.closest('#' + TRIGGER_ID);
    if (!trigger) {
      return;
    }
    ev.preventDefault();
    const dlg = document.getElementById(DIALOG_ID);
    if (!dlg) {
      return;
    }
    openDialog(dlg);
    loadExplanation();
  }

  function handleCloseClick(ev) {
    const dismiss = ev.target.closest('#' + DISMISS_ID);
    if (!dismiss) {
      return;
    }
    ev.preventDefault();
    requestClose(document.getElementById(DIALOG_ID));
  }

  function handleRetryClick(ev) {
    const retry = ev.target.closest('#' + RETRY_ID);
    if (!retry) {
      return;
    }
    ev.preventDefault();
    retry.hidden = true;
    loadExplanation();
  }

  function handleDialogClick(ev) {
    const dlg = document.getElementById(DIALOG_ID);
    if (!dlg || ev.target !== dlg) {
      return;
    }
    requestClose(dlg);
  }

  function handleDialogClose() {
    loadGeneration += 1;
    abortActiveFetch();
    cleanupDialogState(document.getElementById(DIALOG_ID));
    restoreFocus();
  }

  function init() {
    if (initialized) {
      return;
    }

    const dlg = document.getElementById(DIALOG_ID);
    const trigger = document.getElementById(TRIGGER_ID);
    if (!dlg || !trigger) {
      return;
    }

    initialized = true;

    // Nextcloud core sets `dialog { display: block }` — mount on <body> and
    // guarantee a closed initial state (same pattern as admin vacation layers).
    if (dlg.parentElement !== document.body) {
      document.body.appendChild(dlg);
    }
    if (dlg.open) {
      try {
        dlg.close();
      } catch (e) { /* noop */ }
    }
    dlg.removeAttribute('open');
    cleanupDialogState(dlg);

    document.addEventListener('click', handleTriggerClick);
    document.addEventListener('click', handleCloseClick);
    document.addEventListener('click', handleRetryClick);

    dlg.addEventListener('click', handleDialogClick);
    dlg.addEventListener('close', handleDialogClose);
    dlg.addEventListener('cancel', function () {
      loadGeneration += 1;
      abortActiveFetch();
    });
    // Host apps (e.g. core notifications) preventDefault() the Escape keydown
    // globally, suppressing the native `cancel` event — close explicitly.
    dlg.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        requestClose(dlg);
      }
    });
  }

  function boot() {
    init();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})(window);
