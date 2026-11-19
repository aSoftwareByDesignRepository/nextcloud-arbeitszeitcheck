// @ts-check
/**
 * Overtime absence-credit drill-down dialog (dashboard) — 1.12.1 delta.
 *
 * Structural selectors only (locale-safe gate). Covers:
 *  - trigger -> native <dialog> opens and the body loads real content
 *    (item rows, an honest "no absences" empty state, or an error + Retry —
 *    anything that is not the bare loading status)
 *  - Escape closes the modal — regression guard for the NC-notifications
 *    Escape-suppression class (host_app_escape_preventdefault): without the
 *    dialog-level keydown handler, NC's global preventDefault swallows the
 *    native cancel event and the dialog stays open.
 *  - footer Close closes it too.
 *
 * Read-only: the endpoint is a GET, nothing is persisted.
 */
import { test, expect } from '@playwright/test';
import { loginAs, gotoApp } from './helpers/auth.js';

test.describe('overtime breakdown dialog', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, 'EMPLOYEE');
		await gotoApp(page, '/apps/arbeitszeitcheck');
	});

	test('opens, loads content, Escape closes', async ({ page }) => {
		const trigger = page.locator('[data-overtime-breakdown]').first();
		await expect(trigger).toBeVisible({ timeout: 30_000 });
		await trigger.click();

		const dlg = page.locator('dialog.azc-credit-breakdown');
		await expect(dlg).toBeVisible({ timeout: 10_000 });

		// Body must reach a terminal state: item rows, an honest empty
		// notice, or an error status with a Retry button — never stuck on
		// the loading line.
		const terminal = dlg.locator(
			'.azc-credit-breakdown__item, .azc-credit-breakdown__body .form-help, .azc-credit-breakdown__status button',
		);
		await expect(terminal.first()).toBeVisible({ timeout: 30_000 });

		// Falsifiable Escape leg: pre-fix this leaves the dialog open
		// because NC notifications preventDefault()s the cancel event.
		await page.keyboard.press('Escape');
		await expect(dlg).toBeHidden({ timeout: 10_000 });
	});

	test('footer Close closes the dialog', async ({ page }) => {
		const trigger = page.locator('[data-overtime-breakdown]').first();
		await expect(trigger).toBeVisible({ timeout: 30_000 });
		await trigger.click();

		const dlg = page.locator('dialog.azc-credit-breakdown');
		await expect(dlg).toBeVisible({ timeout: 10_000 });

		await dlg.locator('.modal-footer .azc-btn').first().click();
		await expect(dlg).toBeHidden({ timeout: 10_000 });
	});
});
