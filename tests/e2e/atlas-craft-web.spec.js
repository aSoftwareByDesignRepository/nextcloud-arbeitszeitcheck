// @ts-check
/**
 * Atlas craft: capture ArbeitszeitCheck web surfaces into
 * .cursor/atlas-farm-v3/artifacts/arbeitszeitcheck/craft/web/ — ds_chrome lane.
 *
 * Honesty rules:
 *  - Real theme persistence via the theming OCS API (light / dark /
 *    light-highcontrast) — never a JS-painted data-theme attribute.
 *  - Locale-safe structural selectors only (ids/classes) — the i18n lint gate
 *    rejects EN|DE name/text regexes.
 *  - Serial, stateful: theme flips + seeded entries touch shared fixture
 *    accounts, so this spec is listed in STATEFUL_SPECS.
 *  - Filenames are descriptive: azc-<view>[-<state>][-dark|-hc].png
 */
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { login, credsFromEnv, hasCreds, gotoApp } from './helpers/auth.js';
import { setUserTheme, resetUserTheme } from './helpers/theming.js';
import { apiAllowFailure } from './helpers/api.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.resolve(
	__dirname,
	'../../../../../.cursor/atlas-farm-v3/artifacts/arbeitszeitcheck/craft/web',
);
const MANIFEST = [];

function note(id, rel, bytes) {
	MANIFEST.push({ id, path: `craft/web/${rel}`, bytes });
	// eslint-disable-next-line no-console
	console.log('craft', path.join(OUT, rel), bytes);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} rel filename relative to OUT
 * @param {{ waitFor?: string, scroll?: string, settleMs?: number, fullPage?: boolean }} [opts]
 */
async function shot(page, rel, opts = {}) {
	if (opts.waitFor) {
		await expect(page.locator(opts.waitFor).first()).toBeVisible({ timeout: 45_000 });
	}
	if (opts.scroll) {
		const el = page.locator(opts.scroll).first();
		await el.scrollIntoViewIfNeeded().catch(() => {});
	}
	if (opts.settleMs) {
		await page.waitForTimeout(opts.settleMs);
	}
	const file = path.join(OUT, rel);
	await page.screenshot({ path: file, fullPage: !!opts.fullPage });
	const st = fs.statSync(file);
	expect(st.size, `${rel} too small`).toBeGreaterThan(8_000);
	note(rel.replace(/\.png$/, ''), rel, st.size);
}

/** Dismiss transient toast chrome so headers/cards stay unobscured. */
async function dismissToasts(page) {
	await page.evaluate(() => {
		for (const sel of ['#azc-toast-region', '.azc-toast-region', '.toastify', '.toast']) {
			document.querySelectorAll(sel).forEach((n) => n.remove());
		}
	}).catch(() => {});
}

/** True when the selector resolves to a visible element. */
async function visible(page, sel, timeout = 5_000) {
	return page.locator(sel).first().isVisible({ timeout }).catch(() => false);
}

/* ------------------------------------------------------------------ */
/* Surface inventory — every primary web view, keyed by role           */
/* ------------------------------------------------------------------ */

const EMPLOYEE_VIEWS = [
	['dashboard', '/apps/arbeitszeitcheck/dashboard', '#azc-main-content'],
	['time-entries', '/apps/arbeitszeitcheck/time-entries', '#azc-main-content'],
	['time-entries-create', '/apps/arbeitszeitcheck/time-entries/create', '#time-entry-form, #azc-main-content'],
	['absences', '/apps/arbeitszeitcheck/absences', '#azc-main-content'],
	['absences-create', '/apps/arbeitszeitcheck/absences/create', '#azc-main-content'],
	['reports', '/apps/arbeitszeitcheck/reports', '#azc-main-content'],
	['calendar', '/apps/arbeitszeitcheck/calendar', '#calendar-container, #azc-main-content'],
	['timeline', '/apps/arbeitszeitcheck/timeline', '#timeline-container, #azc-main-content'],
	['settings-breaks', '/apps/arbeitszeitcheck/settings/breaks', '#azc-main-content'],
	['settings-notifications', '/apps/arbeitszeitcheck/settings/notifications', '#azc-main-content'],
	['settings-data-privacy', '/apps/arbeitszeitcheck/settings/data-privacy', '#azc-main-content'],
	['settings-about', '/apps/arbeitszeitcheck/settings/about', '#azc-main-content'],
	['get-the-app', '/apps/arbeitszeitcheck/get-the-app', '#azc-main-content'],
	['substitution-requests', '/apps/arbeitszeitcheck/substitution-requests', '#azc-main-content'],
	['compliance', '/apps/arbeitszeitcheck/compliance', '#azc-main-content'],
	['compliance-violations', '/apps/arbeitszeitcheck/compliance/violations', '#azc-main-content'],
	['compliance-reports', '/apps/arbeitszeitcheck/compliance/reports', '#azc-main-content'],
];

const MANAGER_VIEWS = [
	['manager', '/apps/arbeitszeitcheck/manager', '#azc-main-content'],
	['manager-time-entries', '/apps/arbeitszeitcheck/manager/time-entries', '#azc-main-content'],
	['manager-absences', '/apps/arbeitszeitcheck/manager/absences', '#azc-main-content'],
	['manager-month-closures', '/apps/arbeitszeitcheck/manager/month-closures', '#azc-main-content'],
];

const ADMIN_SETTINGS_SECTIONS = [
	'access', 'compliance', 'time-recording', 'time-approvals', 'exports',
	'outlook-subscription', 'month-closure', 'hours', 'regional', 'retention',
	'projectcheck',
];

const ADMIN_VIEWS = [
	['admin-dashboard', '/apps/arbeitszeitcheck/admin', '#azc-main-content'],
	['admin-users', '/apps/arbeitszeitcheck/admin/users', '#azc-main-content'],
	['admin-support-us', '/apps/arbeitszeitcheck/admin/support-us', '#azc-main-content'],
	['admin-license', '/apps/arbeitszeitcheck/admin/license', '#azc-main-content'],
	['admin-kiosk', '/apps/arbeitszeitcheck/admin/kiosk', '#azc-kiosk-page, #azc-main-content'],
	['admin-notifications', '/apps/arbeitszeitcheck/admin/notifications', '#admin-notifications-form, #azc-main-content'],
	['admin-overtime-settings', '/apps/arbeitszeitcheck/admin/overtime-settings', '#azc-main-content'],
	['admin-overtime-payouts', '/apps/arbeitszeitcheck/admin/overtime-payouts', '#azc-main-content'],
	['admin-overtime-payout-audit', '/apps/arbeitszeitcheck/admin/overtime-payout-audit', '#azc-main-content'],
	['admin-holidays', '/apps/arbeitszeitcheck/admin/holidays', '#azc-main-content'],
	['admin-working-time-models', '/apps/arbeitszeitcheck/admin/working-time-models', '#models-table, #azc-main-content'],
	['admin-audit-log', '/apps/arbeitszeitcheck/admin/audit-log', '#azc-main-content'],
	['admin-tariff-rules', '/apps/arbeitszeitcheck/admin/tariff-rules', '#tariff-rules-table, #azc-main-content'],
	['admin-vacation-rules', '/apps/arbeitszeitcheck/admin/vacation-rules', '#admin-vacation-policy-form, #azc-main-content'],
	['admin-vacation-layers', '/apps/arbeitszeitcheck/admin/vacation-layers', '#layer-l0, #azc-main-content'],
	['admin-teams', '/apps/arbeitszeitcheck/admin/teams', '#azc-main-content'],
];

/** Dark + HC re-capture subset (primary views). */
const EMPLOYEE_THEME_VIEWS = [
	['dashboard', '/apps/arbeitszeitcheck/dashboard', '#azc-main-content'],
	['time-entries', '/apps/arbeitszeitcheck/time-entries', '#azc-main-content'],
	['absences', '/apps/arbeitszeitcheck/absences', '#azc-main-content'],
	['calendar', '/apps/arbeitszeitcheck/calendar', '#azc-main-content'],
	['settings-breaks', '/apps/arbeitszeitcheck/settings/breaks', '#azc-main-content'],
];

const ADMIN_THEME_VIEWS = [
	['admin-dashboard', '/apps/arbeitszeitcheck/admin', '#azc-main-content'],
	['admin-users', '/apps/arbeitszeitcheck/admin/users', '#azc-main-content'],
	['admin-settings-access', '/apps/arbeitszeitcheck/admin/settings/access', '#azc-main-content'],
];

const MANAGER_THEME_VIEWS = [
	['manager', '/apps/arbeitszeitcheck/manager', '#azc-main-content'],
];

test.describe.configure({ mode: 'serial' });

test.describe('Atlas web craft — ArbeitszeitCheck', () => {
	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 });
	});

	test('craft employee surfaces (light)', async ({ page }) => {
		test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_* creds');
		test.setTimeout(420_000);
		fs.mkdirSync(OUT, { recursive: true });

		await login(page, credsFromEnv('EMPLOYEE'));
		await resetUserTheme(page).catch(() => {});
		await setUserTheme(page, 'light');

		for (const [id, url, ready] of EMPLOYEE_VIEWS) {
			await gotoApp(page, url);
			await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
			await dismissToasts(page);
			await shot(page, `azc-${id}.png`, { waitFor: ready, settleMs: 600 });
		}
	});

	test('craft employee dialogs, forms and error states', async ({ page }) => {
		test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_* creds');
		test.setTimeout(420_000);

		await login(page, credsFromEnv('EMPLOYEE'));
		await resetUserTheme(page).catch(() => {});
		await setUserTheme(page, 'light');

		/* --- entitlement explainer dialog (absences) --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/absences');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		const explainBtn = page.locator('#entitlement-explain');
		if (await explainBtn.count()) {
			await explainBtn.click();
			const dlg = page.locator('#entitlement-explain-dialog');
			await expect(dlg).toBeVisible({ timeout: 15_000 });
			await shot(page, 'azc-absences-dialog-entitlement.png', { settleMs: 300 });
			// cancel path
			const closeBtn = dlg.locator('button').first();
			await closeBtn.click().catch(() => page.keyboard.press('Escape'));
			await expect(dlg).toBeHidden({ timeout: 10_000 }).catch(async () => {
				await page.keyboard.press('Escape');
			});
		}

		/* --- absence create form: field-level error state --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/absences/create');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		{
			const submitBtn = page.locator(
				'#absence-form button[type="submit"], form button[type="submit"].azc-btn--primary, #azc-main-content form button[type="submit"]',
			).first();
			if (await submitBtn.count()) {
				await submitBtn.click();
				await page.waitForTimeout(800);
				await shot(page, 'azc-absences-create-errors.png', { settleMs: 300 });
			}
		}

		/* --- time entry create form + invalid submit errors --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/time-entries/create');
		await page.waitForSelector('#time-entry-form, #azc-main-content', { timeout: 30_000 });
		{
			const submitBtn = page.locator(
				'#time-entry-form button[type="submit"], #time-entry-form .time-entry-form__actions .azc-btn--primary',
			).first();
			if (await submitBtn.count()) {
				await submitBtn.click();
				await page.waitForTimeout(800);
				await shot(page, 'azc-time-entries-create-errors.png', { settleMs: 300 });
			}
		}

		/* --- correction dialog: needs a completed entry older than the edit
		   window; seed via the session API then click Request correction --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/time-entries');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		let requestBtn = page.locator('.btn-request-correction').first();
		if ((await requestBtn.count()) === 0) {
			for (let age = 20; age <= 70; age += 1) {
				const d = new Date();
				d.setDate(d.getDate() - age);
				while (d.getDay() === 0 || d.getDay() === 6) {
					d.setDate(d.getDate() - 1);
				}
				const pad = (n) => String(n).padStart(2, '0');
				const date = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
				const res = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
					data: { date, hours: 1.25, description: `craft seed ${date}` },
				});
				if (res.ok && res.json?.success) {
					break;
				}
			}
			await page.reload({ waitUntil: 'domcontentloaded' });
			await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
			requestBtn = page.locator('.btn-request-correction').first();
		}
		if (await requestBtn.count()) {
			await requestBtn.click();
			const corrDlg = page.locator(
				'.correction-dialog[role="dialog"], #time-entry-correction-form',
			).first();
			await expect(corrDlg).toBeVisible({ timeout: 15_000 });
			await shot(page, 'azc-time-entries-dialog-correction.png', { settleMs: 300 });
			const cancel = page.locator('#correction-dialog-cancel, .correction-dialog .azc-btn--secondary').first();
			await cancel.click().catch(() => page.keyboard.press('Escape'));
		}

		/* --- honest empty state: filter time-entries to an empty range ---
		   The date inputs are readonly datepicker fields — drive them through
		   the same value+change events the picker sets, then Apply. */
		const filterToggle = page.locator('#btn-filter').first();
		if (await filterToggle.count()) {
			await filterToggle.click().catch(() => {});
			await page.waitForTimeout(400);
		}
		const startDate = page.locator('#filter-start-date');
		const endDate = page.locator('#filter-end-date');
		const applyBtn = page.locator('#btn-apply-filter').first();
		if ((await startDate.count()) && (await endDate.count()) && (await applyBtn.count())) {
			// Range far in the past → real empty result set (no DOM injection).
			await page.evaluate(() => {
				for (const [id, v] of [['filter-start-date', '01.01.2020'], ['filter-end-date', '02.01.2020']]) {
					const el = document.getElementById(id);
					if (el instanceof HTMLInputElement) {
						el.removeAttribute('readonly');
						el.value = v;
						el.dispatchEvent(new Event('input', { bubbles: true }));
						el.dispatchEvent(new Event('change', { bubbles: true }));
					}
				}
			});
			// Apply navigates (GET with query params) — wait for the reload so
			// the shot captures the *filtered* page, then frame the list region
			// (the empty state lives below the fold on a 900px viewport).
			await Promise.all([
				page.waitForURL(/[?&]start_date=/, { timeout: 20_000 }).catch(() => {}),
				applyBtn.click(),
			]);
			await page.waitForLoadState('domcontentloaded');
			await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
			await page.waitForTimeout(800);
			await shot(page, 'azc-time-entries-empty.png', {
				waitFor: '.azc-empty-state, #time-entries-table',
				scroll: '.azc-empty-state, #time-entries-table',
				settleMs: 300,
			});
		}
	});

	test('craft employee themes (dark + light-highcontrast)', async ({ page }) => {
		test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_* creds');
		test.setTimeout(420_000);

		await login(page, credsFromEnv('EMPLOYEE'));
		for (const [theme, suffix] of [['dark', 'dark'], ['light-highcontrast', 'hc']]) {
			await setUserTheme(page, theme);
			for (const [id, url, ready] of EMPLOYEE_THEME_VIEWS) {
				await gotoApp(page, url);
				await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
				await dismissToasts(page);
				await shot(page, `azc-${id}-${suffix}.png`, { waitFor: ready, settleMs: 500 });
			}
		}
		await resetUserTheme(page).catch(() => {});
	});

	test('craft manager surfaces + dialogs', async ({ page }) => {
		test.skip(!hasCreds('MANAGER'), 'Requires NC_MANAGER_* creds');
		test.setTimeout(420_000);

		await login(page, credsFromEnv('MANAGER'));
		await resetUserTheme(page).catch(() => {});
		await setUserTheme(page, 'light');

		for (const [id, url, ready] of MANAGER_VIEWS) {
			await gotoApp(page, url);
			await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
			await dismissToasts(page);
			await shot(page, `azc-${id}.png`, { waitFor: ready, settleMs: 800 });
		}

		/* --- manager create-time-entry dialog (open + cancel) --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/manager/time-entries');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		const openCreate = page.locator('#manager-open-create-time-entry');
		if (await openCreate.count()) {
			await openCreate.click();
			const modal = page.locator('.manager-create-dialog[role="dialog"], [id^="manager-create"][role="dialog"], .modal-backdrop [role="dialog"]').first();
			if (await modal.isVisible({ timeout: 10_000 }).catch(() => false)) {
				await shot(page, 'azc-manager-dialog-create-entry.png', { settleMs: 300 });
				await modal.locator('.btn-mgr-create-cancel, .azc-btn--secondary').first().click()
					.catch(() => page.keyboard.press('Escape'));
			}
		}

		/* --- dark + HC on manager dashboard --- */
		for (const [theme, suffix] of [['dark', 'dark'], ['light-highcontrast', 'hc']]) {
			await setUserTheme(page, theme);
			for (const [id, url, ready] of MANAGER_THEME_VIEWS) {
				await gotoApp(page, url);
				await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
				await dismissToasts(page);
				await shot(page, `azc-${id}-${suffix}.png`, { waitFor: ready, settleMs: 600 });
			}
		}
		await resetUserTheme(page).catch(() => {});
	});

	test('craft admin surfaces (light)', async ({ page }) => {
		test.skip(!hasCreds('ADMIN'), 'Requires NC_ADMIN_* creds');
		test.setTimeout(600_000);

		await login(page, credsFromEnv('ADMIN'));
		await resetUserTheme(page).catch(() => {});
		await setUserTheme(page, 'light');

		for (const [id, url, ready] of ADMIN_VIEWS) {
			await gotoApp(page, url);
			await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
			await dismissToasts(page);
			await shot(page, `azc-${id}.png`, { waitFor: ready, settleMs: 700 });
		}

		for (const section of ADMIN_SETTINGS_SECTIONS) {
			await gotoApp(page, `/apps/arbeitszeitcheck/admin/settings/${section}`);
			await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
			await dismissToasts(page);
			await shot(page, `azc-admin-settings-${section}.png`, { settleMs: 500 });
		}

		/* --- user detail (first linked employee row) --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		const userLink = page.locator('#azc-main-content a[href*="/apps/arbeitszeitcheck/admin/users/"]').first();
		if (await userLink.count()) {
			const href = await userLink.getAttribute('href');
			if (href) {
				await gotoApp(page, href);
				await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
				await shot(page, 'azc-admin-user-detail.png', { settleMs: 600 });
			}
		}
	});

	test('craft admin dialogs + themes', async ({ page }) => {
		test.skip(!hasCreds('ADMIN'), 'Requires NC_ADMIN_* creds');
		test.setTimeout(420_000);

		await login(page, credsFromEnv('ADMIN'));
		await resetUserTheme(page).catch(() => {});
		await setUserTheme(page, 'light');

		/* --- kiosk: create-terminal modal (open + cancel) --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/kiosk');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		const kioskCreate = page.locator('#azc-kiosk-open-create');
		if (await kioskCreate.count()) {
			await kioskCreate.click();
			const modal = page.locator('#azc-kiosk-create-modal');
			if (await modal.isVisible({ timeout: 10_000 }).catch(() => false)) {
				await shot(page, 'azc-admin-kiosk-dialog-create.png', { settleMs: 300 });
				await page.locator('#azc-kiosk-create-close, [data-azc-modal-close]').first().click()
					.catch(() => page.keyboard.press('Escape'));
			}
		}

		/* --- license: clear-license confirm modal (only when licensed) --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/license');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		const clearBtn = page.locator('#azc-license-clear');
		if (await clearBtn.count()) {
			await clearBtn.click();
			const modal = page.locator('#azc-license-clear-modal');
			if (await modal.isVisible({ timeout: 10_000 }).catch(() => false)) {
				await shot(page, 'azc-admin-license-dialog-clear.png', { settleMs: 300 });
				await page.locator('#azc-license-clear-cancel').click()
					.catch(() => page.keyboard.press('Escape'));
			}
		}

		/* --- vacation layers: layer dialog (edit trigger on a row, or add) --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/vacation-layers');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		const layerTrigger = page.locator(
			'[data-action="add-org"], [data-action="add-model"], [data-action="add-team"]',
		).first();
		if (await layerTrigger.count()) {
			await layerTrigger.click();
			const dlg = page.locator('#layer-dialog');
			if (await dlg.isVisible({ timeout: 10_000 }).catch(() => false)) {
				await shot(page, 'azc-admin-vacation-layers-dialog.png', { settleMs: 300 });
				await page.locator('#layer-dialog-cancel').click()
					.catch(() => page.keyboard.press('Escape'));
			}
		}

		/* --- dark + HC on primary admin surfaces --- */
		for (const [theme, suffix] of [['dark', 'dark'], ['light-highcontrast', 'hc']]) {
			await setUserTheme(page, theme);
			for (const [id, url, ready] of ADMIN_THEME_VIEWS) {
				await gotoApp(page, url);
				await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
				await dismissToasts(page);
				await shot(page, `azc-${id}-${suffix}.png`, { waitFor: ready, settleMs: 600 });
			}
		}
		await resetUserTheme(page).catch(() => {});
	});

	test.afterAll(async () => {
		fs.mkdirSync(OUT, { recursive: true });
		fs.writeFileSync(
			path.join(OUT, '_manifest.json'),
			JSON.stringify({ app: 'arbeitszeitcheck', captured_at: new Date().toISOString(), files: MANIFEST }, null, 1),
		);
	});
});
