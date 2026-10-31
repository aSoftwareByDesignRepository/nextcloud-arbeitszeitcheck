// @ts-check
/**
 * Bachus journeys: admin employee list access filter (J-AL-01–10).
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { loginAs, gotoApp } from './helpers/auth.js';
import { api } from './helpers/api.js';
import { openEmployeeListFilters, selectEmployeeListAccessFilter, switchEmployeeListAccessFilter } from './helpers/admin-users-filter.js';

test.describe.configure({ mode: 'serial' });

test.describe('Bachus: admin employee list access filter', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, 'ADMIN');
	});

	test('J-AL-01: filter panel and export are visible on employees page', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await page.waitForSelector('#btn-filter', { timeout: 30000 });

		await expect(page.locator('#btn-filter')).toBeVisible();
		await expect(page.locator('#filter-section')).toBeHidden();
		await page.locator('#btn-filter').click();
		await expect(page.locator('#employee-list-filter-title')).toBeVisible();
		await expect(page.locator('#user-search')).toBeVisible();
		await expect(page.locator('#employee-list-access-filter')).toBeVisible();
		await expect(page.locator('#employee-list-model-filter')).toBeVisible();
		await expect(page.locator('#employee-list-status-filter')).toBeVisible();
		await expect(page.locator('#employee-list-filter-apply')).toBeVisible();
		await expect(page.locator('#employee-list-filter-clear')).toBeVisible();
		await expect(page.locator('#export-users-csv')).toBeVisible();
		await expect(page.locator('#users-table')).toBeVisible();
		const exportHref = await page.locator('#export-users-csv').getAttribute('href');
		expect(exportHref).toMatch(/filter=(all|app_access)/);
	});

	test('J-AL-01b: search empty state offers clear recovery', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await openEmployeeListFilters(page);

		const responsePromise = page.waitForResponse(
			(res) =>
				res.url().includes('/api/admin/users')
				&& res.url().includes('search=zzznomatchxyz')
				&& res.request().method() === 'GET',
			{ timeout: 120_000 },
		);
		await page.locator('#user-search').fill('zzznomatchxyz');
		await page.locator('#employee-list-filter-apply').click();
		await responsePromise;

		await expect(page.locator('[data-action="clear-employee-search"]')).toBeVisible();
		await page.locator('[data-action="clear-employee-search"]').click();
		await expect(page.locator('#user-search')).toHaveValue('');
	});

	test('J-AL-02: switching to all accounts reloads list via API', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await page.waitForSelector('#users-tbody tr', { timeout: 30000 });

		await switchEmployeeListAccessFilter(page, 'all');
		await expect(page.locator('#employee-list-access-filter')).toHaveValue('all');
	});

	test('J-AL-03: API rejects invalid filter with stable code', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await page.waitForSelector('#azc-main-content', { timeout: 30000 });

		const res = await page.request.get(
			new URL('/apps/arbeitszeitcheck/api/admin/users?filter=evil', page.url()).toString(),
			{ headers: { requesttoken: await page.evaluate(() => window.OC?.requestToken || '') } },
		);
		expect(res.status()).toBe(400);
		const body = await res.json();
		expect(body.code).toBe('INVALID_EMPLOYEE_LIST_FILTER');
	});

	test('J-AL-03b: export rejects invalid filter with stable code', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await page.waitForSelector('#azc-main-content', { timeout: 30000 });

		const res = await page.request.get(
			new URL('/apps/arbeitszeitcheck/api/admin/users/export?format=csv&filter=evil', page.url()).toString(),
			{ headers: { requesttoken: await page.evaluate(() => window.OC?.requestToken || '') } },
		);
		expect(res.status()).toBe(400);
		const body = await res.json();
		expect(body.code).toBe('INVALID_EMPLOYEE_LIST_FILTER');
	});

	test('J-AL-04: export link carries current filter', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await page.waitForSelector('#export-users-csv', { timeout: 30000 });

		await switchEmployeeListAccessFilter(page, 'all');

		const href = await page.locator('#export-users-csv').getAttribute('href');
		expect(href).toMatch(/filter=all/);
	});

	test('J-AL-05: filter panel region is axe-clean', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await openEmployeeListFilters(page);

		const results = await new AxeBuilder({ page })
			.include('.admin-users-filter-panel')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();

		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});

	test('J-AL-06: picker mode ignores access filter param semantics', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await page.waitForSelector('#azc-main-content', { timeout: 30000 });

		const data = await api(
			page,
			'GET',
			'/apps/arbeitszeitcheck/api/admin/users?picker=1&search=admin&limit=20&filter=app_access',
		);
		expect(data.success).toBe(true);
		expect(data.picker).toBe(true);
	});

	test('J-AL-07: employee list card is axe-clean', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await page.waitForSelector('#admin-users-list-card', { timeout: 30000 });

		const results = await new AxeBuilder({ page })
			.include('#admin-users-list-card')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();

		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});

	test('J-AL-08: no horizontal overflow at 320px', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 640 });
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await page.waitForSelector('#azc-main-content', { timeout: 30000 });

		const overflow = await page.evaluate(() => {
			const app = document.getElementById('app-content');
			const main = document.getElementById('azc-main-content');
			return {
				app: app ? app.scrollWidth - app.clientWidth : 0,
				main: main ? main.scrollWidth - main.clientWidth : 0,
			};
		});
		expect(overflow.app).toBeLessThanOrEqual(1);
		expect(overflow.main).toBeLessThanOrEqual(1);
	});

	test('J-AL-09: keyboard can apply the access filter', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await openEmployeeListFilters(page);

		await page.locator('#employee-list-access-filter').selectOption('app_access');
		await page.locator('#employee-list-filter-apply').focus();

		const responsePromise = page.waitForResponse(
			(res) =>
				res.url().includes('/api/admin/users')
				&& res.url().includes('filter=all')
				&& res.request().method() === 'GET',
			{ timeout: 120_000 },
		);

		await page.locator('#employee-list-access-filter').selectOption('all');
		await page.keyboard.press('Enter');
		await responsePromise;

		await expect(page.locator('#employee-list-access-filter')).toHaveValue('all');
	});

	test('J-AL-11: model and status filters are sent with the list request', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await openEmployeeListFilters(page);

		await page.locator('#employee-list-model-filter').selectOption('none');
		await page.locator('#employee-list-status-filter').selectOption('enabled');
		const responsePromise = page.waitForResponse(
			(res) =>
				res.url().includes('/api/admin/users')
				&& res.url().includes('model=none')
				&& res.url().includes('status=enabled')
				&& res.request().method() === 'GET',
			{ timeout: 120_000 },
		);
		await page.locator('#employee-list-filter-apply').click();
		const response = await responsePromise;
		expect(response.status()).toBe(200);
		const body = await response.json();
		expect(body.model).toBe('none');
		expect(body.status).toBe('enabled');
		const href = await page.locator('#export-users-csv').getAttribute('href');
		expect(href).toMatch(/model=none/);
		expect(href).toMatch(/status=enabled/);
	});

	test('J-AL-10: show all accounts button switches filter', async ({ page }) => {
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await page.waitForSelector('#btn-filter', { timeout: 30000 });

		const banner = page.locator('#employee-list-hidden-banner');
		if (await banner.isVisible()) {
			const responsePromise = page.waitForResponse(
				(res) =>
					res.url().includes('/api/admin/users')
					&& res.url().includes('filter=all')
					&& res.request().method() === 'GET',
			);
			await page.locator('#employee-list-show-all').click();
			await responsePromise;
			await expect(page.locator('#employee-list-access-filter')).toHaveValue('all');
		} else {
			test.skip(true, 'hidden banner not shown in this fixture (no restricted install or no hidden users)');
		}
	});
});
