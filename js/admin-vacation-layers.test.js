/**
 * @vitest-environment jsdom
 *
 * Regression coverage for the admin vacation-entitlement-layers page.
 *
 * The IIFE in `admin-vacation-layers.js` bails out without side-effects when
 * `#vacation-layers-bootstrap` is missing, so these tests stage a minimal
 * shell of the production template (bootstrap JSON + the IDs the script
 * reaches for) and then verify the pure validators it exposes via
 * `window.__ArbeitszeitCheckVacationLayersTestables`, plus the surface
 * behaviour of the WAI-ARIA combobox on the simulator search input.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const BOOTSTRAP_JSON = JSON.stringify({
  urls: {
    overview: '/overview',
    org: '/org',
    orgDelete: '/org/0',
    model: '/model',
    modelDelete: '/model/0',
    team: '/team',
    teamDelete: '/team/0',
    simulate: '/simulate',
    userSearch: '/users',
    impact: '/impact',
  },
  layeredEnabled: true,
});

function stageDom() {
  document.body.innerHTML = `
    <h1 id="azc-vacation-layers-title">Vacation entitlement</h1>
    <div id="l0-active"></div>
    <span id="l0-count"></span>
    <table><tbody id="l0-history-rows"></tbody></table>
    <span id="l1-count"></span>
    <p id="l1-prereq" hidden></p>
    <tbody id="l1-rows"></tbody>
    <span id="l2-count"></span>
    <p id="l2-prereq" hidden></p>
    <tbody id="l2-rows"></tbody>
    <button type="button" data-action="add-model">Add model default</button>
    <button type="button" data-action="add-team">Add team policy</button>
    <select id="sim-hypothetical-teams" multiple></select>
    <button type="button" id="sim-hypothetical-clear">Clear</button>

    <dialog id="layer-dialog" class="layer-dialog azc-native-dialog" aria-modal="true">
      <form id="layer-dialog-form">
        <h2 id="layer-dialog-title"></h2>
        <p id="layer-dialog-intro"></p>
        <div id="layer-dialog-body"></div>
        <div id="layer-dialog-impact" hidden>
          <span id="layer-dialog-impact-text"></span>
        </div>
        <p id="layer-dialog-feedback"></p>
        <button type="button" id="layer-dialog-cancel">Cancel</button>
        <button type="submit" id="layer-dialog-save">Save</button>
      </form>
    </dialog>

    <form id="sim-form">
      <input id="sim-user" type="text" />
      <ul id="sim-user-suggest" role="listbox" hidden></ul>
      <input id="sim-date" type="date" />
      <button type="submit">Run simulation</button>
      <button type="reset" id="sim-reset">Reset</button>
    </form>
    <div id="sim-result"></div>
    <div id="vacation-layers-status" role="status" aria-live="polite"></div>

    <script id="vacation-layers-bootstrap" type="application/json">${BOOTSTRAP_JSON}</script>
  `;

  // jsdom does not implement HTMLDialogElement showModal/close — polyfill
  // the parts we use so the script doesn't throw.
  const dialog = document.getElementById('layer-dialog');
  if (dialog && typeof dialog.showModal !== 'function') {
    dialog.showModal = function () { this.setAttribute('open', 'open'); this.open = true; };
    dialog.close = function () { this.removeAttribute('open'); this.open = false; };
  }
}

function ajaxStub({ overview = { success: true, org: { active: null, history: [] }, model: { defaults: [], availableModels: [] }, team: { policies: [], availableTeams: [] }, ruleSets: [] }, users = [] } = {}) {
  return vi.fn((url, opts) => {
    if (String(url).startsWith('/overview')) {
      Promise.resolve().then(() => opts && opts.onSuccess && opts.onSuccess(overview));
      return Promise.resolve(overview);
    }
    if (String(url).startsWith('/users')) {
      Promise.resolve().then(() => opts && opts.onSuccess && opts.onSuccess({ success: true, users }));
      return Promise.resolve({ success: true, users });
    }
    if (opts && opts.method === 'DELETE') {
      Promise.resolve().then(() => opts.onSuccess && opts.onSuccess({ success: true }));
      return Promise.resolve({ success: true });
    }
    return Promise.resolve(null);
  });
}

async function importVacationLayersStack(ajaxMock) {
  if (typeof window.__ArbeitszeitCheckVacationLayersTeardown === 'function') {
    window.__ArbeitszeitCheckVacationLayersTeardown();
  }
  await import('./common/utils.js');
  window.ArbeitszeitCheckUtils.ajax = ajaxMock;
  await import('./common/components.js');
  window.ArbeitszeitCheckMessaging = { showError: vi.fn(), showSuccess: vi.fn(), announceAssertive: vi.fn() };
  window.t = (_app, s) => s;
  await import('./admin-vacation-layers.js');
  await Promise.resolve();
  await Promise.resolve();
}

let ajaxSpy;

beforeEach(async () => {
  vi.resetModules();
  stageDom();
  ajaxSpy = ajaxStub();
  await importVacationLayersStack(ajaxSpy);
});

afterEach(() => {
  if (typeof window.__ArbeitszeitCheckVacationLayersTeardown === 'function') {
    window.__ArbeitszeitCheckVacationLayersTeardown();
  }
  delete window.__ArbeitszeitCheckVacationLayersTestables;
  delete window.__ArbeitszeitCheckVacationLayersTeardown;
  delete window.ArbeitszeitCheckUtils;
  delete window.ArbeitszeitCheckMessaging;
  delete window.ArbeitszeitCheckComponents;
  delete window.AzcComponents;
  document.body.innerHTML = '';
});

describe('parseManualDaysInput', () => {
  function t() { return window.__ArbeitszeitCheckVacationLayersTestables; }

  it('accepts plain integers', () => {
    expect(t().parseManualDaysInput('25')).toEqual({ ok: true, value: 25, normalized: '25' });
  });

  it('accepts dot decimals up to 2 places', () => {
    expect(t().parseManualDaysInput('25.5')).toEqual({ ok: true, value: 25.5, normalized: '25.5' });
    expect(t().parseManualDaysInput('27.75')).toEqual({ ok: true, value: 27.75, normalized: '27.75' });
  });

  it('accepts the German comma form and normalises to dot', () => {
    expect(t().parseManualDaysInput('31,2')).toEqual({ ok: true, value: 31.2, normalized: '31.2' });
  });

  it('strips surrounding whitespace', () => {
    expect(t().parseManualDaysInput('  20.5  ')).toEqual({ ok: true, value: 20.5, normalized: '20.5' });
  });

  it('rejects empty / null input', () => {
    expect(t().parseManualDaysInput('')).toEqual({ ok: false, reason: 'empty' });
    expect(t().parseManualDaysInput('   ')).toEqual({ ok: false, reason: 'empty' });
    expect(t().parseManualDaysInput(null)).toEqual({ ok: false, reason: 'empty' });
    expect(t().parseManualDaysInput(undefined)).toEqual({ ok: false, reason: 'empty' });
  });

  it('rejects malformed payloads', () => {
    expect(t().parseManualDaysInput('-5')).toEqual({ ok: false, reason: 'format' });
    expect(t().parseManualDaysInput('1e3')).toEqual({ ok: false, reason: 'format' });
    expect(t().parseManualDaysInput('NaN')).toEqual({ ok: false, reason: 'format' });
    expect(t().parseManualDaysInput('Infinity')).toEqual({ ok: false, reason: 'format' });
    expect(t().parseManualDaysInput('25,5,5')).toEqual({ ok: false, reason: 'format' });
    expect(t().parseManualDaysInput('25.123')).toEqual({ ok: false, reason: 'format' });
  });

  it('rejects out-of-range values', () => {
    expect(t().parseManualDaysInput('367')).toEqual({ ok: false, reason: 'range' });
    expect(t().parseManualDaysInput('366.01')).toEqual({ ok: false, reason: 'range' });
  });

  it('accepts the inclusive boundary values', () => {
    expect(t().parseManualDaysInput('0')).toEqual({ ok: true, value: 0, normalized: '0' });
    expect(t().parseManualDaysInput('366')).toEqual({ ok: true, value: 366, normalized: '366' });
  });
});

describe('parseDateRange', () => {
  function t() { return window.__ArbeitszeitCheckVacationLayersTestables; }

  it('accepts a single from with no to', () => {
    expect(t().parseDateRange('2026-01-01', '')).toEqual({ ok: true, from: '2026-01-01', to: null });
  });

  it('accepts a closed range where to >= from', () => {
    expect(t().parseDateRange('2026-01-01', '2026-12-31')).toEqual({ ok: true, from: '2026-01-01', to: '2026-12-31' });
    expect(t().parseDateRange('2026-01-01', '2026-01-01')).toEqual({ ok: true, from: '2026-01-01', to: '2026-01-01' });
  });

  it('rejects an empty from', () => {
    expect(t().parseDateRange('', '2026-01-01')).toEqual({ ok: false, reason: 'from-empty' });
    expect(t().parseDateRange(null, '2026-01-01')).toEqual({ ok: false, reason: 'from-empty' });
  });

  it('rejects malformed dates', () => {
    expect(t().parseDateRange('01.01.2026', '')).toEqual({ ok: false, reason: 'from-format' });
    expect(t().parseDateRange('2026-01-01', '31.12.2026')).toEqual({ ok: false, reason: 'to-format' });
  });

  it('rejects to < from', () => {
    expect(t().parseDateRange('2026-06-01', '2026-01-01')).toEqual({ ok: false, reason: 'to-before-from' });
  });
});

describe('manualDaysErrorMessage', () => {
  function t() { return window.__ArbeitszeitCheckVacationLayersTestables; }

  it('returns a non-empty message for every documented reason', () => {
    ['empty', 'format', 'range', 'not-finite', 'unknown'].forEach((r) => {
      expect(typeof t().manualDaysErrorMessage(r)).toBe('string');
      expect(t().manualDaysErrorMessage(r).length).toBeGreaterThan(8);
    });
  });
});

describe('dateRangeErrorMessage', () => {
  function t() { return window.__ArbeitszeitCheckVacationLayersTestables; }

  it('returns a localisable message for every documented reason', () => {
    ['from-empty', 'from-format', 'to-format', 'to-before-from', 'unknown'].forEach((r) => {
      const msg = t().dateRangeErrorMessage(r);
      expect(typeof msg).toBe('string');
      expect(msg.length).toBeGreaterThan(8);
    });
  });
});

describe('simulator combobox keyboard interactions', () => {
  beforeEach(async () => {
    // Re-mount with users to populate the listbox.
    vi.resetModules();
    document.body.innerHTML = '';
    stageDom();
    ajaxSpy = ajaxStub({
      overview: { success: true, org: { active: null, history: [] }, model: { defaults: [], availableModels: [] }, team: { policies: [], availableTeams: [] }, ruleSets: [] },
      users: [
        { userId: 'alice', displayName: 'Alice Anderson' },
        { userId: 'bob', displayName: 'Bob Builder' },
      ],
    });
    await importVacationLayersStack(ajaxSpy);
  });

  it('attaches combobox semantics to the user search input', () => {
    const input = document.getElementById('sim-user');
    expect(input.getAttribute('role')).toBe('combobox');
    expect(input.getAttribute('aria-autocomplete')).toBe('list');
    expect(input.getAttribute('aria-controls')).toBe('sim-user-suggest');
    expect(input.getAttribute('aria-expanded')).toBe('false');
    expect(input.getAttribute('aria-haspopup')).toBe('listbox');
  });

  it('opens the listbox after typing 2+ characters and a debounce window', async () => {
    vi.useFakeTimers();
    const input = document.getElementById('sim-user');
    const suggest = document.getElementById('sim-user-suggest');
    input.value = 'al';
    input.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(220);
    await Promise.resolve();
    expect(suggest.hidden).toBe(false);
    expect(suggest.querySelectorAll('li').length).toBeGreaterThan(0);
    expect(input.getAttribute('aria-expanded')).toBe('true');
    vi.useRealTimers();
  });

  it('moves the active descendant with ArrowDown/ArrowUp and selects with Enter', async () => {
    vi.useFakeTimers();
    const input = document.getElementById('sim-user');
    const suggest = document.getElementById('sim-user-suggest');
    input.value = 'a';
    input.dispatchEvent(new Event('input'));
    // 2 char trigger - this should not open
    expect(suggest.hidden).toBe(true);
    input.value = 'al';
    input.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(220);
    await Promise.resolve();
    expect(suggest.hidden).toBe(false);
    const before = input.getAttribute('aria-activedescendant');
    expect(before).toBeNull();
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
    expect(input.getAttribute('aria-activedescendant')).toBe('sim-user-suggest-opt-0');
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
    expect(input.getAttribute('aria-activedescendant')).toBe('sim-user-suggest-opt-1');
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true, cancelable: true }));
    expect(input.getAttribute('aria-activedescendant')).toBe('sim-user-suggest-opt-0');
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    expect(input.value).toBe('Alice Anderson');
    expect(suggest.hidden).toBe(true);
    expect(input.getAttribute('aria-expanded')).toBe('false');
    vi.useRealTimers();
  });

  it('Escape closes the listbox without selecting', async () => {
    vi.useFakeTimers();
    const input = document.getElementById('sim-user');
    const suggest = document.getElementById('sim-user-suggest');
    input.value = 'al';
    input.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(220);
    await Promise.resolve();
    expect(suggest.hidden).toBe(false);
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    expect(suggest.hidden).toBe(true);
    expect(input.value).toBe('al');
    vi.useRealTimers();
  });

  it('surfaces an explicit "no matches" status entry', async () => {
    vi.useFakeTimers();
    window.ArbeitszeitCheckUtils.ajax = vi.fn((url, opts) => {
      if (String(url).startsWith('/overview')) {
        Promise.resolve().then(() => opts.onSuccess && opts.onSuccess({ success: true, org: { active: null, history: [] }, model: { defaults: [], availableModels: [] }, team: { policies: [], availableTeams: [] }, ruleSets: [] }));
        return Promise.resolve(null);
      }
      if (String(url).startsWith('/users')) {
        Promise.resolve().then(() => opts.onSuccess && opts.onSuccess({ success: true, users: [] }));
        return Promise.resolve(null);
      }
      return Promise.resolve(null);
    });
    const input = document.getElementById('sim-user');
    const suggest = document.getElementById('sim-user-suggest');
    input.value = 'zz';
    input.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(220);
    await Promise.resolve();
    expect(suggest.hidden).toBe(false);
    expect(suggest.querySelector('.form-suggest__empty')).not.toBeNull();
    vi.useRealTimers();
  });

  it('rejects typed free-text UIDs and only posts a picked suggestion', async () => {
    const simulateCalls = [];
    window.ArbeitszeitCheckUtils.ajax = vi.fn((url, opts) => {
      const u = String(url);
      if (u.startsWith('/overview')) {
        Promise.resolve().then(() => opts.onSuccess && opts.onSuccess({
          success: true,
          org: { active: null, history: [] },
          model: { defaults: [], availableModels: [] },
          team: { policies: [], availableTeams: [] },
          ruleSets: [],
        }));
        return Promise.resolve(null);
      }
      if (u.startsWith('/users')) {
        Promise.resolve().then(() => opts.onSuccess && opts.onSuccess({
          success: true,
          users: [
            { userId: 'alice', displayName: 'Alice Anderson' },
            { userId: 'bob', displayName: 'Bob Builder' },
          ],
        }));
        return Promise.resolve(null);
      }
      if (u.includes('simulate') || (opts && opts.method === 'POST')) {
        simulateCalls.push({ url: u, data: opts && opts.data });
        Promise.resolve().then(() => opts.onSuccess && opts.onSuccess({ success: true, layers: [] }));
        return Promise.resolve(null);
      }
      return Promise.resolve(null);
    });

    const form = document.getElementById('sim-form');
    const input = document.getElementById('sim-user');
    const result = document.getElementById('sim-result');

    // Typing a UID without picking must not POST.
    input.value = 'alice';
    input.dispatchEvent(new Event('input'));
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await Promise.resolve();
    expect(simulateCalls.length).toBe(0);
    expect(result.textContent).toMatch(/pick an employee/i);
    expect(input.getAttribute('aria-invalid')).toBe('true');

    // Pick from suggestions, then submit must POST that uid only.
    vi.useFakeTimers();
    input.value = 'al';
    input.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(220);
    await Promise.resolve();
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    vi.useRealTimers();
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await Promise.resolve();
    await Promise.resolve();
    expect(simulateCalls.length).toBe(1);
    expect(simulateCalls[0].data.userId).toBe('alice');
  });
});

describe('Layer dialog lifecycle', () => {
  it('stays closed on initial page load (no stray open attribute)', () => {
    const dlg = document.getElementById('layer-dialog');
    expect(dlg).not.toBeNull();
    expect(dlg.hasAttribute('open')).toBe(false);
    expect(dlg.open).toBeFalsy();
    expect(document.getElementById('layer-dialog-title').textContent).toBe('');
  });

  it('closes via Cancel and clears the open state', () => {
    const dlg = document.getElementById('layer-dialog');
    dlg.showModal();
    expect(dlg.hasAttribute('open')).toBe(true);
    document.getElementById('layer-dialog-cancel').click();
    expect(dlg.hasAttribute('open')).toBe(false);
    expect(dlg.open).toBeFalsy();
  });
});

describe('Add buttons disable when prerequisites are missing', () => {
  it('disables "Add model default" when no working time models exist', async () => {
    // Default overview stub already has empty availableModels[].
    const addModel = document.querySelector('[data-action="add-model"]');
    expect(addModel.disabled).toBe(true);
    expect(addModel.getAttribute('aria-disabled')).toBe('true');
  });

  it('disables "Add team policy" when no teams exist', () => {
    const addTeam = document.querySelector('[data-action="add-team"]');
    expect(addTeam.disabled).toBe(true);
    expect(addTeam.getAttribute('aria-disabled')).toBe('true');
  });

  it('renders inline prereq hints when models / teams are missing', () => {
    const hintModel = document.getElementById('l1-prereq');
    const hintTeam = document.getElementById('l2-prereq');
    expect(hintModel.hidden).toBe(false);
    expect(hintModel.textContent.length).toBeGreaterThan(8);
    expect(hintTeam.hidden).toBe(false);
    expect(hintTeam.textContent.length).toBeGreaterThan(8);
  });
});

describe('Add buttons enable when prerequisites are met', () => {
  beforeEach(async () => {
    vi.resetModules();
    document.body.innerHTML = '';
    stageDom();
    ajaxSpy = ajaxStub({
      overview: {
        success: true,
        org: { active: null, history: [] },
        model: { defaults: [], availableModels: [{ id: 1, name: 'Full-time' }] },
        team: { policies: [], availableTeams: [{ id: 1, name: 'Team Alpha' }] },
        ruleSets: [],
      },
    });
    await importVacationLayersStack(ajaxSpy);
  });

  it('enables both Add buttons when prerequisites are available', () => {
    const addModel = document.querySelector('[data-action="add-model"]');
    const addTeam = document.querySelector('[data-action="add-team"]');
    expect(addModel.disabled).toBe(false);
    expect(addTeam.disabled).toBe(false);
  });

  it('hides the inline prerequisite hints', () => {
    const hintModel = document.getElementById('l1-prereq');
    const hintTeam = document.getElementById('l2-prereq');
    expect(hintModel.hidden).toBe(true);
    expect(hintTeam.hidden).toBe(true);
  });
});

describe('parseDeleteId', () => {
  function t() { return window.__ArbeitszeitCheckVacationLayersTestables; }

  it('accepts positive integer ids', () => {
    expect(t().parseDeleteId('7')).toBe(7);
    expect(t().parseDeleteId(12)).toBe(12);
  });

  it('rejects invalid ids', () => {
    expect(t().parseDeleteId('0')).toBeNull();
    expect(t().parseDeleteId('-1')).toBeNull();
    expect(t().parseDeleteId('abc')).toBeNull();
    expect(t().parseDeleteId('')).toBeNull();
    expect(t().parseDeleteId(null)).toBeNull();
  });
});

describe('history delete workflow', () => {
  const orgRow = {
    id: 7,
    vacationMode: 'manual_fixed',
    manualDays: 27,
    tariffRuleSetId: null,
    description: '',
    effectiveFrom: '2026-01-01',
    effectiveTo: null,
  };

  beforeEach(async () => {
    vi.resetModules();
    document.body.innerHTML = '';
    stageDom();
    ajaxSpy = ajaxStub({
      overview: {
        success: true,
        org: { active: orgRow, history: [orgRow] },
        model: { defaults: [], availableModels: [] },
        team: { policies: [], availableTeams: [] },
        ruleSets: [],
      },
    });
    await importVacationLayersStack(ajaxSpy);
  });

  it('opens an accessible confirm dialog when delete is clicked', async () => {
    const deleteBtn = document.querySelector('[data-delete-org="7"]');
    expect(deleteBtn).toBeTruthy();
    deleteBtn.click();

    await vi.waitFor(() => {
      expect(document.querySelector('.confirm-dialog')).toBeTruthy();
    });
    const confirm = document.querySelector('.confirm-dialog__confirm');
    expect(confirm).toBeTruthy();
    expect(confirm.disabled).toBe(false);
    expect(document.querySelector('.confirm-dialog__cancel')).toBeTruthy();
    document.querySelector('.confirm-dialog__cancel').click();
    await vi.waitFor(() => {
      expect(document.querySelector('.confirm-dialog')).toBeFalsy();
    });
  });

  it('issues DELETE when destructive dialog is confirmed (real confirm click)', async () => {
    const deleteBtn = document.querySelector('[data-delete-org="7"]');
    deleteBtn.click();
    await vi.waitFor(() => {
      expect(document.querySelector('.confirm-dialog__confirm')).toBeTruthy();
    });
    document.querySelector('.confirm-dialog__confirm').click();
    await vi.waitFor(() => {
      expect(ajaxSpy.mock.calls.some((call) => call[1] && call[1].method === 'DELETE')).toBe(true);
    });

    const deleteCall = ajaxSpy.mock.calls.find((call) => call[1] && call[1].method === 'DELETE');
    expect(String(deleteCall[0])).toBe('/org/7');
    expect(window.ArbeitszeitCheckMessaging.showSuccess).toHaveBeenCalled();
  });

  it('does not delete when destructive dialog is cancelled (real cancel click)', async () => {
    const deleteBtn = document.querySelector('[data-delete-org="7"]');
    deleteBtn.click();
    await vi.waitFor(() => {
      expect(document.querySelector('.confirm-dialog__cancel')).toBeTruthy();
    });
    document.querySelector('.confirm-dialog__cancel').click();
    await Promise.resolve();
    await Promise.resolve();

    const deleteCall = ajaxSpy.mock.calls.find((call) => call[1] && call[1].method === 'DELETE');
    expect(deleteCall).toBeFalsy();
  });

  it('blocks concurrent delete requests while one is in flight', async () => {
    let finishDelete;
    const blocked = new Promise((resolve) => { finishDelete = resolve; });
    const baseAjax = ajaxSpy;
    const trackingAjax = vi.fn((url, opts) => {
      if (opts && opts.method === 'DELETE') {
        blocked.then(() => {
          if (opts.onSuccess) opts.onSuccess({ success: true });
        });
        return Promise.resolve(null);
      }
      return baseAjax(url, opts);
    });
    window.ArbeitszeitCheckUtils.ajax = trackingAjax;

    const deleteBtn = document.querySelector('[data-delete-org="7"]');
    deleteBtn.click();
    await vi.waitFor(() => {
      expect(document.querySelector('.confirm-dialog__confirm')).toBeTruthy();
    });
    document.querySelector('.confirm-dialog__confirm').click();
    await vi.waitFor(() => {
      expect(trackingAjax.mock.calls.filter((call) => call[1] && call[1].method === 'DELETE').length).toBe(1);
    });

    deleteBtn.click();
    await Promise.resolve();

    const deleteCalls = trackingAjax.mock.calls.filter((call) => call[1] && call[1].method === 'DELETE');
    expect(deleteCalls.length).toBe(1);

    finishDelete();
    await Promise.resolve();
  });
});
