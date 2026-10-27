// @ts-check
/**
 * ATLAS_BTN_VARIANT_DIALOG — learned-regression guard for
 * button_variant_specificity_collapse
 * (azc-vis-web-dialog-primary-variant-collapse).
 *
 * AzcComponents.createModal()/showConfirmDialog() append .azc-dialog /
 * .azc-modal-backdrop to document.body — outside #app-content(.azc-app) —
 * where NC core `button:not(.button-vue)` (0-1-1) repaints every <button>
 * with the pale element-light fill. Unscoped variant rules lose, so dialog
 * primary/danger <button>s render identical to secondary. Anchors are never
 * repainted by core — the in-page <a> keeps the true variant paint and is the
 * oracle this defect hid behind on the sealed crafts.
 *
 * Stateful: flips the shared EMPLOYEE user's theme via the theming OCS API
 * (real persistence — never a JS-painted data-theme attribute), so this spec
 * must stay registered in STATEFUL_SPECS.
 */
import { test, expect } from '@playwright/test';
import { loginAs, gotoApp } from './helpers/auth.js';
import { setUserTheme, resetUserTheme } from './helpers/theming.js';

/**
 * Computed paint of one element — the falsifiable record of the bug class.
 * `bg`/`border`/`ink` must be identical between the dialog <button> and the
 * in-page <a> oracle for the same variant.
 * @param {import('@playwright/test').Page} page
 * @param {string} selector
 */
async function paintOf(page, selector) {
	return page.locator(selector).first().evaluate((el) => {
		const cs = getComputedStyle(el);
		return {
			tag: el.tagName,
			bg: cs.backgroundColor,
			border: cs.borderTopColor,
			ink: cs.color,
		};
	});
}

/**
 * Inject in-page anchor oracles (primary + secondary + danger) plus a ghost
 * <button> oracle — anchors and in-app buttons are never repainted by the
 * core body-scoped dialog rule, so they carry each variant's true paint.
 */
async function injectOracles(page) {
	await page.evaluate(() => {
		document.querySelectorAll('.azc-variant-oracle').forEach((el) => el.remove());
		const host = document.getElementById('azc-main-content');
		for (const variant of ['primary', 'secondary', 'danger']) {
			const a = document.createElement('a');
			a.className = `azc-btn azc-btn--${variant} azc-variant-oracle`;
			a.href = '#';
			host.appendChild(a);
		}
		const ghost = document.createElement('button');
		ghost.className = 'azc-btn azc-btn--ghost azc-variant-oracle';
		host.appendChild(ghost);
	});
}

test.describe('Dialog button-variant paint (button_variant_specificity_collapse)', () => {
	test('ATLAS_BTN_VARIANT_DIALOG body-appended dialog <button> variants paint like in-page oracles (light + dark)', async ({ page }) => {
		test.setTimeout(180_000);
		await loginAs(page, 'EMPLOYEE');
		await resetUserTheme(page).catch(() => {});

		try {
			for (const theme of ['light', 'dark']) {
				await setUserTheme(page, theme);
				await gotoApp(page, '/apps/arbeitszeitcheck/dashboard');
				await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
				await page.waitForFunction(
					() => typeof window.AzcComponents?.showConfirmDialog === 'function',
					null,
					{ timeout: 15_000 },
				);
				await injectOracles(page);

				// Park the pointer far from the centered dialog — a leftover
				// :hover (last click position) repaints the sampled <button>
				// with the variant hover fill and breaks oracle equality.
				await page.mouse.move(4, 4);

				// Neutral confirm → primary confirm + secondary cancel on <button>.
				await page.evaluate(() => {
					window.AzcComponents.showConfirmDialog({
						title: 'paint probe', message: 'paint probe',
						variant: 'info', confirmLabel: 'probe', cancelLabel: 'probe',
					});
				});
				const primaryBtn = '.azc-modal-backdrop .confirm-dialog__confirm';
				await page.waitForSelector(primaryBtn, { state: 'visible', timeout: 10_000 });
				// display:none→flex + the 50ms focus move run the .btn 0.15s
				// transition — computed paint samples mid-flight without a settle.
				await page.waitForTimeout(400);

				for (const [btnSel, variant] of [[primaryBtn, 'primary'], ['.azc-modal-backdrop .confirm-dialog__cancel', 'secondary']]) {
					const btn = await paintOf(page, btnSel);
					const oracle = await paintOf(page, `a.azc-btn--${variant}.azc-variant-oracle`);
					expect(btn.tag, `${theme} ${variant}: probe must be a real <button>`).toBe('BUTTON');
					expect(btn.bg, `${theme} ${variant}: dialog <button> fill must equal the in-page <a> oracle`).toBe(oracle.bg);
					expect(btn.border, `${theme} ${variant}: dialog <button> border must equal the oracle`).toBe(oracle.border);
					expect(btn.ink, `${theme} ${variant}: dialog <button> ink must equal the oracle`).toBe(oracle.ink);
				}
				await page.locator('.azc-modal-backdrop .confirm-dialog__cancel').click();
				await page.waitForSelector('.azc-modal-backdrop', { state: 'detached', timeout: 10_000 });

				// Destructive confirm → btn--danger on the confirm <button>.
				await page.mouse.move(4, 4);
				await page.evaluate(() => {
					window.AzcComponents.showConfirmDialog({
						title: 'paint probe', message: 'paint probe',
						variant: 'danger', confirmLabel: 'probe', cancelLabel: 'probe',
					});
				});
				const dangerBtn = '.azc-modal-backdrop .confirm-dialog__confirm';
				await page.waitForSelector(dangerBtn, { state: 'visible', timeout: 10_000 });
				await page.waitForTimeout(400);
				const dBtn = await paintOf(page, dangerBtn);
				const dOracle = await paintOf(page, 'a.azc-btn--danger.azc-variant-oracle');
				expect(dBtn.tag, `${theme} danger: probe must be a real <button>`).toBe('BUTTON');
				expect(dBtn.bg, `${theme} danger: destructive confirm must keep the danger fill`).toBe(dOracle.bg);
				expect(dBtn.border, `${theme} danger: destructive confirm must keep the danger border`).toBe(dOracle.border);
				expect(dBtn.ink, `${theme} danger: destructive confirm must keep the danger ink`).toBe(dOracle.ink);
				await page.locator('.azc-modal-backdrop .confirm-dialog__cancel').click();
				await page.waitForSelector('.azc-modal-backdrop', { state: 'detached', timeout: 10_000 });

				// createModal path → .azc-dialog on body; ghost <button> stays
				// fill-less (admin-tariff-rules modal-close emits this shape).
				await page.mouse.move(4, 4);
				await page.evaluate(() => {
					window.AzcComponents.openDialog({
						id: 'azc-variant-probe-dialog',
						title: 'paint probe',
						content: '<button type="button" class="azc-btn azc-btn--ghost azc-variant-probe-ghost">probe</button>',
					});
				});
				await page.waitForSelector('#azc-variant-probe-dialog .azc-variant-probe-ghost', { state: 'visible', timeout: 10_000 });
				await page.waitForTimeout(400);
				const gBtn = await paintOf(page, '#azc-variant-probe-dialog .azc-variant-probe-ghost');
				const gOracle = await paintOf(page, 'button.azc-btn--ghost.azc-variant-oracle');
				expect(gBtn.tag, `${theme} ghost: probe must be a real <button>`).toBe('BUTTON');
				expect(gBtn.bg, `${theme} ghost: dialog ghost must stay transparent like the in-page oracle`).toBe(gOracle.bg);
				expect(gBtn.border, `${theme} ghost: dialog ghost border must equal the oracle`).toBe(gOracle.border);
				expect(gBtn.bg, `${theme} ghost: ghost must not carry a fill`).toBe('rgba(0, 0, 0, 0)');
				await page.evaluate(() => window.AzcComponents.closeModal('azc-variant-probe-dialog'));
				await page.waitForSelector('.azc-modal-backdrop', { state: 'detached', timeout: 10_000 });
			}
		} finally {
			await resetUserTheme(page).catch(() => {});
		}
	});
});
