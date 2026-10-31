/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

const ABOUT_HTML = `<!DOCTYPE html><html><head><title>About - ArbeitszeitCheck</title></head><body>
<h1 id="azc-page-title">About</h1>
<span class="azc-breadcrumb__current">About</span>
<div class="settings-container">
  <nav id="azc-employee-settings-pages">
    <a class="azc-settings-nav__link" href="/apps/arbeitszeitcheck/settings/breaks">Breaks</a>
    <a class="azc-settings-nav__link is-active" aria-current="page" href="/apps/arbeitszeitcheck/settings/about">About</a>
  </nav>
  <section id="panel">about body</section>
</div>
</body></html>`;

function stageDom() {
  document.body.innerHTML = `
    <h1 id="azc-page-title">Breaks</h1>
    <span class="azc-breadcrumb__current">Breaks</span>
    <div class="settings-container">
      <nav id="azc-employee-settings-pages">
        <a class="azc-settings-nav__link is-active" aria-current="page" href="/apps/arbeitszeitcheck/settings/breaks">Breaks</a>
        <a class="azc-settings-nav__link" href="/apps/arbeitszeitcheck/settings/about">About</a>
      </nav>
      <section id="panel">breaks body</section>
    </div>`;
}

beforeEach(() => {
  stageDom();
  globalThis.fetch = vi.fn((url) => {
    if (String(url).includes('/settings/about')) {
      return Promise.resolve({ ok: true, text: () => Promise.resolve(ABOUT_HTML) });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, settings: {} }) });
  });
});

describe('settings topic switch', () => {
  it('replaces the panel and keeps the topic row', async () => {
    await import('./settings.js');
    const nav = document.getElementById('azc-employee-settings-pages');
    window.ArbeitszeitCheckSettings.setupSectionSwitch();
    document.querySelector('a[href="/apps/arbeitszeitcheck/settings/about"]').click();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    expect(document.getElementById('azc-employee-settings-pages')).toBe(nav);
    expect(document.getElementById('panel').textContent).toBe('about body');
    expect(document.getElementById('azc-page-title').textContent).toBe('About');
    expect(document.querySelector('.azc-breadcrumb__current').textContent).toBe('About');
    expect(document.querySelector('a[aria-current="page"]').textContent).toBe('About');
    expect(document.title).toBe('About - ArbeitszeitCheck');
  });
});
