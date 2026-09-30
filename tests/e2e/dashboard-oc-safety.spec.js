// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv, hasCreds } from './helpers/auth.js';

/**
 * Guards the NC home dashboard against two related regressions:
 *
 * 1. "OC is not defined" ReferenceErrors — thrown by any l10n or app
 *    script that runs before core-main.js creates window.OC/window.OCA.
 * 2. Script-order poisoning — a mid-request \OCP\Template render with a truthy
 *    renderAs (dashboard widget load()) consumes the request script registry,
 *    leaks app scripts ahead of core bundles, and leaves the Dashboard Vue app
 *    dead (missing "Customize" control). Asserted via document.scripts order
 *    and the presence of the customize button.
 */
test.describe('NC home dashboard console safety', () => {
	test('dashboard loads without OC errors, with correct script order and customize control', async ({ page }) => {
		test.setTimeout(120000);
		test.skip(!hasCreds('ADMIN'), 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

		/** @type {string[]} */
		const fatalConsole = [];
		page.on('pageerror', (err) => {
			const msg = String(err?.message || err);
			const stack = String(err?.stack || '');
			if (/OC is not defined|OCA is not defined|Cannot (read|set) properties of undefined/i.test(msg + ' ' + stack)) {
				fatalConsole.push(`${msg} @ ${stack.split('\n')[0] ?? ''}`);
			}
		});
		page.on('console', (msg) => {
			if (msg.type() !== 'error') {
				return;
			}
			const text = msg.text();
			const loc = msg.location()?.url || '';
			if (/OC is not defined|OCA is not defined/i.test(text + ' ' + loc)) {
				fatalConsole.push(text);
			}
		});

		await login(page, credsFromEnv('ADMIN'));
		await page.goto('/apps/dashboard/', { waitUntil: 'domcontentloaded', timeout: 90000 });
		await page.locator('#app-dashboard').waitFor({ state: 'attached', timeout: 60000 });
		// Deferred/module scripts resolve through real load conditions — the
		// customize control being attached proves the Dashboard Vue app booted.
		await page.waitForLoadState('networkidle');
		await page.locator('#app-dashboard .footer button').first()
			.waitFor({ state: 'attached', timeout: 30000 });

		expect(fatalConsole, JSON.stringify(fatalConsole, null, 2)).toEqual([]);

		// Every app/l10n script must come after the core bundles that create
		// window.OC/window.OCA. Deferred scripts and modules execute in document
		// order, so DOM position == execution order here.
		const order = await page.evaluate(() => {
			const srcs = [...document.scripts]
				.map((el) => el.getAttribute('src') || '')
				.filter((s) => s !== '');
			const coreIdx = srcs.findIndex((s) => /core-main\.(js|mjs)/.test(s));
			const earlyApp = srcs.slice(0, coreIdx === -1 ? srcs.length : coreIdx)
				.filter((s) => /l10n\/|custom_apps\/|apps\//.test(s));
			const azc = srcs.filter((s) => /arbeitszeitcheck\//.test(s));
			return {
				coreIdx,
				earlyApp,
				azc,
				hasOc: typeof window.OC !== 'undefined',
				hasOca: typeof window.OCA !== 'undefined',
			};
		});
		expect(order.coreIdx, 'core-main.js must be emitted').toBeGreaterThanOrEqual(0);
		expect(order.earlyApp, 'app/l10n scripts must not render before core-main.js').toEqual([]);
		expect(order.hasOc, 'window.OC must exist after dashboard boot').toBeTruthy();
		expect(order.hasOca, 'window.OCA must exist after dashboard boot').toBeTruthy();
		expect(order.azc.length, 'arbeitszeitcheck widget assets must load').toBeGreaterThan(0);

		// The Dashboard Vue app is dead when scripts race core init — its
		// customize button then never renders. Selector is locale-agnostic:
		// the label is translated ("Customize"/"Anpassen"/"Personalizar"/…).
		const customizeButton = page.locator('#app-dashboard .footer button').first();
		await expect(
			customizeButton,
			'Dashboard "Customize" control must be rendered',
		).toBeVisible();

		// Functional check: a live Vue app opens the customization modal.
		await customizeButton.click();
		await expect(
			page.getByRole('dialog'),
			'Dashboard customize modal must open',
		).toBeVisible({ timeout: 10000 });
		await page.keyboard.press('Escape');
	});
});
