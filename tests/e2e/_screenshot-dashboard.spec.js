// @ts-check
/** One-off proof screenshot: dashboard loads + customize control present. */
import { test } from '@playwright/test';
import { login, credsFromEnv, hasCreds } from './helpers/auth.js';

test('proof screenshot — NC dashboard with customize control', async ({ page }) => {
	test.skip(!hasCreds('ADMIN'), 'Requires NC_ADMIN_* creds');
	await login(page, credsFromEnv('ADMIN'));
	await page.goto('/apps/dashboard/', { waitUntil: 'networkidle', timeout: 90000 });
	await page.locator('#app-dashboard .footer button').first().waitFor({ timeout: 30000 });
	await page.screenshot({
		path: '../../../documentation/arbeitszeitcheck/audits/screenshots/web/dashboard-customize.png',
	});
});
