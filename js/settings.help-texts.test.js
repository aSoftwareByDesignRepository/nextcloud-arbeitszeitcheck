/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

function stageDom() {
  document.body.innerHTML = `
    <script type="application/json" id="azc-help-text-ids">["absence-past-entry-hint","time-entries-export-hint"]</script>
    <script type="application/json" id="azc-dismissed-hints-data">["dashboard-projectcheck-linking-off"]</script>
    <button type="button" id="btn-hide-all-hints">Hide all help texts</button>
    <button type="button" id="btn-show-all-hints" disabled>Show all help texts again</button>
    <p id="show-all-hints-status" data-total="2" data-one="%n of %s help text is shown." data-many="%n of %s help texts are shown.">2 of 2 help texts are shown.</p>`;
}

beforeEach(() => {
  stageDom();
  globalThis.window.t = (_app, text) => text;
  globalThis.window.ArbeitszeitCheck = {
    apiUrl: { updateSettings: '/apps/arbeitszeitcheck/settings' },
  };
  globalThis.fetch = vi.fn(() => Promise.resolve({
    json: () => Promise.resolve({ success: true }),
  }));
});

describe('help text shortcuts', () => {
  it('hides every catalog id and keeps hints already dismissed', async () => {
    await import('./settings.js');
    window.ArbeitszeitCheckSettings.setupHelpTextShortcuts();
    document.getElementById('btn-hide-all-hints').click();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    const body = JSON.parse(globalThis.fetch.mock.calls[0][1].body);
    expect(body.dismissed_hints).toEqual([
      'absence-past-entry-hint',
      'time-entries-export-hint',
      'dashboard-projectcheck-linking-off',
    ]);
    expect(document.getElementById('btn-hide-all-hints').disabled).toBe(true);
    expect(document.getElementById('btn-show-all-hints').disabled).toBe(false);
    expect(document.getElementById('show-all-hints-status').textContent).toBe('0 of 2 help texts are shown.');
  });
});
