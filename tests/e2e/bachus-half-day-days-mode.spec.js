/* global process */
// @ts-check
/**
 * Bachus: days-mode half-day vacation UX (fixture + live create when credentials present).
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import { login, credsFromEnv } from './helpers/auth.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const fixtureUrl = 'file://' + path.resolve(__dirname, 'fixtures/absences-days-half-create.html');

test.describe('Bachus: half-day days-mode fixture', () => {
	test('UX-AC / J-H: Length visible, preview, progressive help, half-today, axe, target size', async ({ page }) => {
		await page.goto(fixtureUrl);
		const group = page.locator('#absence-day-fraction-group');
		await expect(group).toBeVisible();
		await expect(page.locator('#absence-day-fraction-legend')).toHaveText(/Day length|Tageslänge/i);
		await expect(page.locator('#absence-day-fraction-help')).toContainText(/Full day or half day|Ganzer oder halber/i);
		await expect(page.locator('#absence-multi-day-half-tip')).toBeHidden();

		const halfFace = page.locator('#absence-day-fraction-half + .absence-day-fraction__face');
		const box = await halfFace.boundingBox();
		expect(box).not.toBeNull();
		expect(box.height).toBeGreaterThanOrEqual(44);
		expect(box.width).toBeGreaterThanOrEqual(44);

		await page.locator('#btn-half-day-today').click();
		await expect(page.locator('#absence-day-fraction-half')).toBeChecked();
		await expect(page.locator('#absence-day-fraction-preview')).toContainText(/0\.5|0,5/);

		await page.locator('#absence-end-date').fill('14.08.2026');
		await page.locator('#absence-end-date').dispatchEvent('change');
		await expect(group).toBeHidden();
		await expect(page.locator('#absence-multi-day-half-tip')).toBeVisible();
		await expect(page.locator('#absence-day-fraction-live')).toContainText(/single day|einzelnen Tag/i);

		await page.locator('#absence-end-date').fill('12.08.2026');
		await page.locator('#absence-end-date').dispatchEvent('change');
		await expect(group).toBeVisible();
		await expect(page.locator('#absence-multi-day-half-tip')).toBeHidden();

		await page.locator('#absence-type').selectOption('sick_leave');
		await expect(group).toBeHidden();

		const results = await new AxeBuilder({ page })
			.include('#absences-days-half-create')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});

	test('responsive stacking adapts without overflow', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 812 });
		await page.goto(fixtureUrl);
		await page.locator('#absence-day-fraction-half + .absence-day-fraction__face').click();

		const mobileLayout = await page.evaluate(() => {
			const segments = Array.from(document.querySelectorAll('.absence-day-fraction__segment'));
			const boxes = segments.map((node) => node.getBoundingClientRect());
			return {
				scrollWidth: document.documentElement.scrollWidth,
				innerWidth: window.innerWidth,
				firstTop: boxes[0]?.top ?? 0,
				secondTop: boxes[1]?.top ?? 0,
			};
		});
		expect(mobileLayout.scrollWidth).toBeLessThanOrEqual(mobileLayout.innerWidth);
		expect(mobileLayout.secondTop).toBeGreaterThan(mobileLayout.firstTop);

		await page.setViewportSize({ width: 1280, height: 900 });
		const desktopLayout = await page.evaluate(() => {
			const segments = Array.from(document.querySelectorAll('.absence-day-fraction__segment'));
			const boxes = segments.map((node) => node.getBoundingClientRect());
			return {
				scrollWidth: document.documentElement.scrollWidth,
				innerWidth: window.innerWidth,
				firstTop: boxes[0]?.top ?? 0,
				secondTop: boxes[1]?.top ?? 0,
			};
		});
		expect(desktopLayout.scrollWidth).toBeLessThanOrEqual(desktopLayout.innerWidth);
		expect(Math.abs(desktopLayout.secondTop - desktopLayout.firstTop)).toBeLessThan(2);
	});
});

test.describe('Bachus: half-day days-mode live create', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS');
		await login(page, credsFromEnv('EMPLOYEE'));
	});

	test('J-H1: create page exposes Length in days mode or hours presets in hours mode', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/absences/create');
		await page.waitForSelector('#absence-form, #azc-main-content', { timeout: 30000 });

		const hoursGroup = page.locator('#absence-duration-hours-group');
		const dayGroup = page.locator('#absence-day-fraction-group');
		const hoursMode = await hoursGroup.getAttribute('data-hours-mode');

		if (hoursMode === '1') {
			await expect(hoursGroup).toBeVisible();
			await expect(dayGroup).toBeHidden();
		} else {
			await expect(page.locator('#absence-type')).toHaveValue('vacation');
			await expect(dayGroup).toBeVisible();
			await expect(page.locator('#absence-day-fraction-help')).toContainText(/Full day or half day|Ganzer oder halber/i);
		}
	});

	test('J-H2: half=1 deep link preselects half day', async ({ page }) => {
		const today = new Date();
		const ymd = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
		await page.goto(`/apps/arbeitszeitcheck/absences/create?start=${ymd}&end=${ymd}&half=1`);
		await page.waitForSelector('#absence-form', { timeout: 30000 });
		const hoursMode = await page.locator('#absence-duration-hours-group').getAttribute('data-hours-mode');
		test.skip(hoursMode === '1', 'Hours mode uses presets instead of day_fraction');
		await expect(page.locator('#absence-day-fraction-group')).toBeVisible();
		await expect(page.locator('#absence-day-fraction-half')).toBeChecked();
	});

	test('J-H3: live create adapts to Nextcloud theme variables when days mode is active', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/absences/create');
		await page.waitForSelector('#absence-form', { timeout: 30000 });
		const hoursMode = await page.locator('#absence-duration-hours-group').getAttribute('data-hours-mode');
		test.skip(hoursMode === '1', 'Hours mode hides the day-fraction cards.');

		await page.locator('#absence-day-fraction-half + .absence-day-fraction__face').click();
		// The face has a 150ms border-color transition — computed style would read a
		// mid-blend interpolation and flake. Freeze transitions for this token check.
		await page.addStyleTag({ content: '.absence-day-fraction__face { transition: none !important; }' });
		const readFace = () => page.locator('#absence-day-fraction-half + .absence-day-fraction__face').evaluate((node) => {
			const style = getComputedStyle(node);
			return {
				border: style.borderColor,
				color: style.color,
			};
		});

		await page.evaluate(() => {
			document.body.style.setProperty('--color-main-background', '#ffffff');
			document.body.style.setProperty('--color-main-text', '#1b1b1b');
			document.body.style.setProperty('--color-primary-element', '#005ea8');
			document.body.style.setProperty('--color-border', '#8a8a8a');
		});
		const light = await readFace();

		await page.evaluate(() => {
			document.body.style.setProperty('--color-main-background', '#171717');
			document.body.style.setProperty('--color-main-text', '#f5f5f5');
			document.body.style.setProperty('--color-primary-element', '#78b8ff');
			document.body.style.setProperty('--color-border', '#d0d0d0');
		});
		const dark = await readFace();

		expect(dark.border).not.toBe(light.border);
		expect(dark.color).not.toBe(light.color);
	});
});
