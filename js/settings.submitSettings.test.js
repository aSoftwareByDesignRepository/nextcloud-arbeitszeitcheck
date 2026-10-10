/**
 * @vitest-environment jsdom
 *
 * Regression coverage for the silent-drop class found by atlas-farm
 * flow-parity: the settings save response carries
 * `vacationYearModeFlip.allocationsFailedCount`. When the count is
 * non-zero the admin flipped vacation-year mode but some allocations
 * failed to refresh — the UI must warn, not claim plain success.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const snapshot = {};

function stageDom() {
  document.body.innerHTML = `
    <form id="notification-settings-form">
      <button type="submit">Save</button>
    </form>`;
}

function mockFetchResult(result) {
  globalThis.fetch = vi.fn(() =>
    Promise.resolve({ json: () => Promise.resolve(result) })
  );
}

async function flush() {
  // fetch -> .json() -> handler: three microtask turns
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
}

beforeEach(() => {
  snapshot.ArbeitszeitCheck = globalThis.window.ArbeitszeitCheck;
  snapshot.ArbeitszeitCheckMessaging = globalThis.window.ArbeitszeitCheckMessaging;
  snapshot.n = globalThis.window.n;
  snapshot.t = globalThis.window.t;

  globalThis.window.ArbeitszeitCheckMessaging = {
    showSuccess: vi.fn(),
    showWarning: vi.fn(),
    showError: vi.fn(),
  };
  globalThis.window.t = (app, text) => text;
  globalThis.window.n = (app, sing, plur, n) => (n === 1 ? sing : plur).replace('%n', String(n));

  stageDom();
});

afterEach(() => {
  globalThis.window.ArbeitszeitCheck = snapshot.ArbeitszeitCheck;
  globalThis.window.ArbeitszeitCheckMessaging = snapshot.ArbeitszeitCheckMessaging;
  globalThis.window.n = snapshot.n;
  globalThis.window.t = snapshot.t;
  document.body.innerHTML = '';
});

describe('submitSettings vacation-year flip feedback', () => {
  it('shows success when no allocation refresh failed', async () => {
    mockFetchResult({ success: true, message: 'Saved' });
    await import('./settings.js');

    window.ArbeitszeitCheckSettings.submitSettings({}, 'notification-settings-form');
    await flush();

    expect(window.ArbeitszeitCheckMessaging.showSuccess).toHaveBeenCalledWith('Saved');
    expect(window.ArbeitszeitCheckMessaging.showWarning).not.toHaveBeenCalled();
  });

  it('warns instead of claiming success when allocations failed to refresh', async () => {
    mockFetchResult({
      success: true,
      message: 'Saved',
      vacationYearModeFlip: { allocationsRefreshed: 8, allocationsFailedCount: 3 },
    });
    await import('./settings.js');

    window.ArbeitszeitCheckSettings.submitSettings({}, 'notification-settings-form');
    await flush();

    expect(window.ArbeitszeitCheckMessaging.showSuccess).not.toHaveBeenCalled();
    expect(window.ArbeitszeitCheckMessaging.showWarning).toHaveBeenCalledTimes(1);
    const msg = window.ArbeitszeitCheckMessaging.showWarning.mock.calls[0][0];
    expect(msg).toContain('3');
    expect(msg).toMatch(/allocations? could not be refreshed/);
  });

  it('uses the singular form for exactly one failed allocation', async () => {
    mockFetchResult({
      success: true,
      vacationYearModeFlip: { allocationsFailedCount: 1 },
    });
    await import('./settings.js');

    window.ArbeitszeitCheckSettings.submitSettings({}, 'notification-settings-form');
    await flush();

    const msg = window.ArbeitszeitCheckMessaging.showWarning.mock.calls[0][0];
    expect(msg).toContain('1 vacation allocation could not be refreshed');
  });

  it('still shows errors via showError', async () => {
    mockFetchResult({ success: false, error: 'nope' });
    await import('./settings.js');

    window.ArbeitszeitCheckSettings.submitSettings({}, 'notification-settings-form');
    await flush();

    expect(window.ArbeitszeitCheckMessaging.showError).toHaveBeenCalledTimes(1);
    expect(window.ArbeitszeitCheckMessaging.showWarning).not.toHaveBeenCalled();
  });
});
