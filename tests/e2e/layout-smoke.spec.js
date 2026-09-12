// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const require = createRequire(import.meta.url);
const { assertAtlasMobileNav } = require(join(dirname(fileURLToPath(import.meta.url)), '../../../_shared/e2e/atlas-mobile-nav-contract.js'));

const routes = [
	{ path: '/apps/arbeitszeitcheck/dashboard', name: 'dashboard' },
	{ path: '/apps/arbeitszeitcheck/time-entries', name: 'time-entries' },
	{ path: '/apps/arbeitszeitcheck/settings/breaks', name: 'settings' },
	{ path: '/apps/arbeitszeitcheck/compliance', name: 'compliance' },
];

for (const { path, name } of routes) {
	test(`layout smoke: ${name} has shell and no horizontal overflow`, async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS');
		await page.setViewportSize({ width: 375, height: 800 });
		await login(page, credsFromEnv('EMPLOYEE'));
		await page.goto(path);
		await page.waitForSelector('#azc-main-content', { timeout: 30000 });
		await expect(page.locator('.azc-page-header').first()).toBeVisible();
		await expect(page.locator('#azc-main-content')).toBeVisible();
		const overflow = await page.evaluate(() => {
			const main = document.getElementById('azc-main-content');
			if (!main) {
				return false;
			}
			return main.scrollWidth <= main.clientWidth + 2;
		});
		expect(overflow).toBe(true);
	});
}

test('ATLAS_MOBILE_NAV_CONTRACT dashboard Menu opens drawer', async ({ page }) => {
	test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS');
	await page.setViewportSize({ width: 375, height: 812 });
	await login(page, credsFromEnv('EMPLOYEE'));
	await page.goto('/apps/arbeitszeitcheck/dashboard');
	await page.waitForSelector('#azc-nav-toggle', { timeout: 30000 });
	await assertAtlasMobileNav(page, {
		toggle: page.locator('#azc-nav-toggle'),
		nav: page.locator('#app-navigation'),
		openClass: /azc-nav--open/,
	});
});
