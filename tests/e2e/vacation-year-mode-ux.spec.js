// @ts-check
/**
 * Phase B Bachus: vacation year mode + Q2 anniversary carryover expiry UX + WCAG.
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const fixtureUrl = 'file://' + path.resolve(__dirname, 'fixtures/vacation-year-mode-config.html');

test.describe('Phase B: vacation year mode fixture', () => {
	test('J-V1: anniversary radio selectable and saveable', async ({ page }) => {
		await page.goto(fixtureUrl);
		await expect(page.locator('#vacation-year-title')).toBeVisible();
		await page.locator('#vacationYearMode-anniversary').check();
		await page.locator('#vacationYearMissingHireAcknowledged').check();
		await page.locator('button[type="submit"]').click();
		await expect(page.locator('#save-status')).toHaveText(/anniversary/);
	});

	test('J-V2: axe clean on vacation year config', async ({ page }) => {
		await page.goto(fixtureUrl);
		const results = await new AxeBuilder({ page })
			.include('#vacation-year-config')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});

	test('J-V2b: recompute note is linked from year-mode fieldset', async ({ page }) => {
		await page.goto(fixtureUrl);
		await expect(page.locator('#vacation-year-mode-recompute-note')).toBeVisible();
		await expect(page.locator('fieldset.wtm-vacation-year-mode')).toHaveAttribute(
			'aria-describedby',
			/vacation-year-mode-recompute-note/,
		);
		await expect(page.locator('fieldset.wtm-vacation-year-mode')).toHaveAttribute(
			'aria-describedby',
			/vacation-year-mode-rollover-note/,
		);
	});

	test('J-V-B1: Bachus choice cards + advanced help collapsed', async ({ page }) => {
		await page.goto(fixtureUrl);
		await expect(page.locator('.azc-choice-card')).toHaveCount(2);
		await expect(page.locator('#vacationYearMode-calendar')).toBeVisible();
		await expect(page.locator('#vacation-year-mode-more')).not.toHaveAttribute('open', '');
		await expect(page.locator('#vacation-carryover-how-more')).not.toHaveAttribute('open', '');
		await expect(page.locator('#vacation-year-mode-intro')).toBeVisible();
		const intro = await page.locator('#vacation-year-mode-intro').innerText();
		expect(intro.length).toBeLessThan(160);
	});

	test('J-V-B2: opening details reveals advanced copy without breaking axe', async ({ page }) => {
		await page.goto(fixtureUrl);
		await page.locator('#vacation-year-mode-more summary').click();
		await expect(page.locator('#vacation-year-mode-help')).toBeVisible();
		const results = await new AxeBuilder({ page })
			.include('#vacation-year-config')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});

	test('J-V3: anniversary mode switches carryover to months-after and hides day', async ({ page }) => {
		await page.goto(fixtureUrl);
		await expect(page.locator('#vacationCarryoverExpiryDay-wrap')).toBeVisible();
		await page.locator('#vacationYearMode-anniversary').check();
		await expect(page.locator('#vacationCarryoverExpiryMonth-label')).toHaveText(/Months after anniversary/i);
		await expect(page.locator('#vacationCarryoverExpiryDay-wrap')).toBeHidden();
		await expect(page.locator('#vacation-carryover-expiry-intro-anniversary')).toBeVisible();
		await expect(page.locator('#vacation-carryover-expiry-intro')).toBeHidden();
		await page.locator('#vacationYearMissingHireAcknowledged').check();
		await page.locator('button[type="submit"]').click();
		await expect(page.locator('#save-status')).toHaveText(/anniversary.*months=3/);
	});

	test('J-V4: axe clean after switching to anniversary carryover UI', async ({ page }) => {
		await page.goto(fixtureUrl);
		await page.locator('#vacationYearMode-anniversary').check();
		const results = await new AxeBuilder({ page })
			.include('#vacation-year-config')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});

	test('J-V5: anniversary shows missing-hire warning', async ({ page }) => {
		await page.goto(fixtureUrl);
		await expect(page.locator('#vacation-year-missing-hire')).toBeHidden();
		await page.locator('#vacationYearMode-anniversary').check();
		await expect(page.locator('#vacation-year-missing-hire')).toBeVisible();
		await expect(page.locator('#vacation-year-missing-hire')).toContainText(/missing a hire date/i);
	});

	test('J-V6: save blocked without missing-hire acknowledgement', async ({ page }) => {
		await page.goto(fixtureUrl);
		await page.locator('#vacationYearMode-anniversary').check();
		await expect(page.locator('#vacationYearMissingHireAcknowledged')).toBeVisible();
		await page.locator('button[type="submit"]').click();
		await expect(page.locator('#save-status')).toHaveText(/Blocked: missing hire ack/i);
		await page.locator('#vacationYearMissingHireAcknowledged').check();
		await page.locator('button[type="submit"]').click();
		await expect(page.locator('#save-status')).toHaveText(/Saved: anniversary/);
	});
});

const absencesCarryoverUrl = 'file://' + path.resolve(__dirname, 'fixtures/absences-carryover-q2.html');

test.describe('Phase B: absences carryover Q2 fixture', () => {
	test('J-A1: hero remaining shows locked carryover copy', async ({ page }) => {
		await page.goto(absencesCarryoverUrl);
		await expect(page.locator('#stat-carryover-usable')).toBeVisible();
		await expect(page.locator('#stat-carryover-usable')).toContainText(/Carryover ended/i);
		await expect(page.locator('#stats-carryover-deadline-notice')).toBeVisible();
		await expect(page.locator('#vacation-stats-details')).toHaveCount(0);
		await expect(page.locator('#stat-carryover-pool-label')).toHaveText('Resturlaub');
	});

	test('J-A2: axe clean on simplified absences stats', async ({ page }) => {
		await page.goto(absencesCarryoverUrl);
		const results = await new AxeBuilder({ page })
			.include('#absences-carryover-fixture')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});
});
