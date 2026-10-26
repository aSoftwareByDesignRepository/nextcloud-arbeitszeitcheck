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
import { applyLabLicense } from './helpers/license.js';

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
	['business-trips', '/apps/arbeitszeitcheck/business-trips', '#bt-trip-list .bt-trip-item, #bt-empty:not([hidden]), #bt-root'],
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
	['manager-business-trips', '/apps/arbeitszeitcheck/manager/business-trips', '#mbt-root, #azc-main-content'],
	['manager-people', '/apps/arbeitszeitcheck/manager/people', '#manager-people-body tr'],
];

const ADMIN_SETTINGS_SECTIONS = [
	'access', 'compliance', 'time-recording', 'time-approvals', 'exports',
	'outlook-subscription', 'month-closure', 'hours', 'regional', 'retention',
	'projectcheck', 'work-locations',
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
	['admin-business-trips', '/apps/arbeitszeitcheck/admin/business-trips', '#bta-root, #azc-main-content'],
	['admin-special-entitlements', '/apps/arbeitszeitcheck/admin/special-entitlements', '#se-admin-root, #azc-main-content'],
	['admin-absence-types', '/apps/arbeitszeitcheck/admin/absence-types', '#cat-table, #cat-editor-panel, #azc-main-content'],
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
	['manager-people', '/apps/arbeitszeitcheck/manager/people', '#manager-people-body tr'],
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

		/* --- absence create form: field-level error state ---
		   Dates default to today and type defaults to Vacation, so a bare
		   submit would CREATE a real absence row and land on the list.
		   Clear both dates: the server rejects the POST and re-renders the
		   form with the error callout — honest error state, zero mutation. */
		await gotoApp(page, '/apps/arbeitszeitcheck/absences/create');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		{
			// Datepicker inputs — clear via value+events (same
			// pattern as the filter leg below), never fill().
			await page.evaluate(() => {
				for (const id of ['absence-start-date', 'absence-end-date']) {
					const el = document.getElementById(id);
					if (el instanceof HTMLInputElement) {
						el.removeAttribute('readonly');
						el.value = '';
						el.dispatchEvent(new Event('input', { bubbles: true }));
						el.dispatchEvent(new Event('change', { bubbles: true }));
					}
				}
			});
			const submitBtn = page.locator(
				'#absence-form button[type="submit"], form button[type="submit"].azc-btn--primary, #azc-main-content form button[type="submit"]',
			).first();
			if (await submitBtn.count()) {
				await submitBtn.click();
				await expect(page.locator('#absence-form-error')).toBeVisible({ timeout: 20_000 });
				await shot(page, 'azc-absences-create-errors.png', { settleMs: 300 });
			}
		}

		/* --- time entry create form + invalid submit errors ---
		   The form pre-fills today and current times — a bare submit creates
		   a real 0-duration entry. Clear the date so client-side validation
		   flags #entry-date-error (role=alert) and nothing is persisted. */
		await gotoApp(page, '/apps/arbeitszeitcheck/time-entries/create');
		await page.waitForSelector('#time-entry-form, #azc-main-content', { timeout: 30_000 });
		{
			await page.evaluate(() => {
				const el = document.getElementById('entry-date');
				if (el instanceof HTMLInputElement) {
					el.removeAttribute('readonly');
					el.value = '';
					el.dispatchEvent(new Event('input', { bubbles: true }));
					el.dispatchEvent(new Event('change', { bubbles: true }));
				}
			});
			const submitBtn = page.locator(
				'#time-entry-form button[type="submit"], #time-entry-form .time-entry-form__actions .azc-btn--primary',
			).first();
			if (await submitBtn.count()) {
				await submitBtn.click();
				await expect(page.locator('#entry-date-error .form-error')).toBeVisible({ timeout: 10_000 });
				await shot(page, 'azc-time-entries-create-errors.png', {
					scroll: '#entry-date-error, #entry-date',
					settleMs: 300,
				});
			}
		}

		/* --- correction dialog: needs a completed entry older than the edit
		   window; seed via the session API then click Request correction --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/time-entries');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		// The entries table paginates server-side (newest first): rows outside
		// the current page render with `hidden`. Correction-eligible entries
		// (> edit window) sit on the oldest pages — walk the pager backwards.
		const visibleRequestBtn = () => page.locator('.btn-request-correction:visible').first();
		const findAcrossPages = async () => {
			const visited = new Set();
			for (let hops = 0; hops < 15; hops += 1) {
				const btn = visibleRequestBtn();
				if ((await btn.count()) > 0) {
					return btn;
				}
				const current = await page.locator('#time-entries-table-pager .azc-table-pager__page.is-current').textContent().catch(() => '1');
				visited.add(Number(String(current).trim()));
				const nums = await page.locator('#time-entries-table-pager .azc-table-pager__pages a[data-page]').evaluateAll(
					(links) => links.map((a) => Number(a.getAttribute('data-page'))),
				);
				const nextPage = nums.filter((p) => !visited.has(p)).sort((a, b) => b - a)[0];
				if (nextPage == null) {
					return btn;
				}
				await page.locator(`#time-entries-table-pager a[data-page="${nextPage}"]`).click();
				await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
			}
			return visibleRequestBtn();
		};
		let requestBtn = await findAcrossPages();
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
			requestBtn = await findAcrossPages();
		}
		if (await requestBtn.count()) {
			// Row actions reveal on tr:hover at >=769px (zero-width sticky
			// actions-cell + pointer-events:none panel). Playwright's hit-test
			// never moves the OS cursor first, so hover the row explicitly —
			// same thing a real pointer user does.
			await requestBtn.locator('xpath=ancestor::tr[1]').hover();
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
		   The date inputs are datepicker fields — drive them through
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

		/* --- business trips: create view (open → cancel, zero mutation) and a
		   real detail surface via the first rendered trip link --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/business-trips');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		const newTripBtn = page.locator('#bt-new-trip, #bt-empty-create').first();
		if (await newTripBtn.count()) {
			await newTripBtn.click();
			await expect(page.locator('#bt-create-view')).toBeVisible({ timeout: 10_000 });
			await shot(page, 'azc-business-trips-create.png', { settleMs: 300 });
			await page.locator('#bt-create-cancel').click().catch(() => {});
		}
		const tripLink = page.locator('#bt-trip-list .bt-trip-item__link').first();
		if (await tripLink.count()) {
			const href = await tripLink.getAttribute('href');
			if (href) {
				await gotoApp(page, href);
				await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
				await shot(page, 'azc-business-trips-detail.png', { settleMs: 500 });
			}
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

		/* --- people overview: filter-error + mobile card craft, geometry probe ---
		   Permanent probe (azc-vis-people-* cluster): the Details column is the
		   rows' only control — at the 1280 critic-repro width it must render
		   inside the card, and the filter grid must compute display:grid
		   (manager-scope-filter.css whitelist covers --manager-people). */
		await gotoApp(page, '/apps/arbeitszeitcheck/manager/people');
		await page.waitForSelector('#manager-people-body tr', { timeout: 30_000 });
		const peopleGeo = await page.evaluate(() => {
			const wrap = document.querySelector('#manager-people-table-wrap');
			const filter = document.querySelector('.manager-scope-filter');
			const card = document.querySelector('.manager-scope-page__results .azc-card__body, .manager-scope-page__results')
				|| wrap;
			const actionsTd = document.querySelector('#manager-people-body td.actions-cell');
			const cardRect = card.getBoundingClientRect();
			const tdRect = actionsTd ? actionsTd.getBoundingClientRect() : null;
			const badge = document.querySelector('#manager-people-count');
			return {
				filterDisplay: filter ? getComputedStyle(filter).display : '',
				wrapClientW: wrap ? wrap.clientWidth : 0,
				wrapScrollW: wrap ? wrap.scrollWidth : 0,
				cardRight: cardRect.right,
				actionsRight: tdRect ? tdRect.right : -1,
				badgeHidden: badge ? badge.classList.contains('visually-hidden') : null,
				badgeText: badge ? badge.textContent : null,
			};
		});
		expect(peopleGeo.filterDisplay, 'people filter must compute grid (whitelist covers --manager-people)').toBe('grid');
		expect(
			peopleGeo.wrapScrollW,
			`table must fit card at 1280 (scrollW ${peopleGeo.wrapScrollW} > clientW ${peopleGeo.wrapClientW})`,
		).toBeLessThanOrEqual(peopleGeo.wrapClientW + 2);
		expect(
			peopleGeo.actionsRight,
			'Details/actions column must render inside the card',
		).toBeLessThanOrEqual(peopleGeo.cardRight + 2);
		expect(peopleGeo.badgeHidden, 'count badge must not render as empty dot').toBe(false);
		expect(peopleGeo.badgeText, 'populated table must show an employee count').toBeTruthy();
		await shot(page, 'azc-manager-people-desktop-1280.png', { settleMs: 400 });

		/* error state: invalid year → inline callout + honest empty state */
		await page.locator('#manager-people-year').fill('1800');
		await page.locator('#manager-people-submit').click();
		await expect(page.locator('#manager-people-filter-error')).toBeVisible({ timeout: 10_000 });
		await shot(page, 'azc-manager-people-filter-error.png', { settleMs: 300 });
		await page.locator('#manager-people-clear').click();
		await page.waitForSelector('#manager-people-body tr', { timeout: 30_000 });

		/* mobile cards: 375 viewport → responsive card reflow, no page h-scroll */
		await page.setViewportSize({ width: 375, height: 800 });
		await gotoApp(page, '/apps/arbeitszeitcheck/manager/people');
		await page.waitForSelector('#manager-people-body tr', { timeout: 30_000 });
		const mobileGeo = await page.evaluate(() => ({
			docW: document.documentElement.clientWidth,
			scrollW: document.documentElement.scrollWidth,
		}));
		expect(mobileGeo.scrollW, 'no horizontal page scroll at 375').toBeLessThanOrEqual(mobileGeo.docW + 1);
		await shot(page, 'azc-manager-people-mobile-cards.png', { settleMs: 400 });
		await page.setViewportSize({ width: 1280, height: 720 });

		/* --- manager create-time-entry dialog (open + cancel) --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/manager/time-entries');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		const openCreate = page.locator('#manager-open-create-time-entry');
		if (await openCreate.count()) {
			await openCreate.click();
			const modal = page.locator('.manager-create-dialog[role="dialog"], [id^="manager-create"][role="dialog"], .modal-backdrop [role="dialog"]').first();
			if (await modal.isVisible({ timeout: 10_000 }).catch(() => false)) {
				/* Permanent probe (azc-mgr-time-pair-matrix-unstyled): the shared
				   css/common/time-pair-matrix.css must be registered on this page —
				   without it the Start/End grid degrades to block layout and the
				   column headers collapse into contiguous text. */
				const matrixProbe = await page.evaluate(() => {
					const header = document.querySelector(
						'[role="dialog"] .time-pair-matrix__grid--header, .time-pair-matrix__grid--header',
					);
					if (!header) {
						return { found: false, display: '', minGap: -1 };
					}
					const rects = [...header.querySelectorAll('.time-pair-matrix__colhead')]
						.map((el) => el.getBoundingClientRect());
					let minGap = Infinity;
					for (let i = 1; i < rects.length; i++) {
						minGap = Math.min(minGap, rects[i].left - rects[i - 1].right);
					}
					return {
						found: true,
						display: getComputedStyle(header).display,
						minGap: rects.length > 1 ? minGap : 1,
					};
				});
				expect(matrixProbe.found, 'time-pair-matrix header grid must render in manager create dialog').toBe(true);
				expect(matrixProbe.display, 'time-pair-matrix grid must compute to display:grid (common/time-pair-matrix.css loaded)').toBe('grid');
				expect(matrixProbe.minGap, 'time-pair-matrix column headers must not render contiguous').toBeGreaterThan(0);
				await shot(page, 'azc-manager-dialog-create-entry.png', { settleMs: 300 });
				await modal.locator('.btn-mgr-create-cancel, .azc-btn--secondary').first().click()
					.catch(() => page.keyboard.press('Escape'));
			}
		}

		/* --- bulk decision: select pending cards → count toolbar → reject modal ---
		   Checkboxes render inside pending-approval cards after the ajax list
		   loads; the toolbar only unhides once ≥1 item is selected. Reject opens
		   a modal (open → cancel, never confirm — zero mutation). */
		await gotoApp(page, '/apps/arbeitszeitcheck/manager');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		const bulkCbs = page.locator('#pending-approvals-items .bulk-select-checkbox');
		if (await bulkCbs.first().isVisible({ timeout: 20_000 }).catch(() => false)) {
			await bulkCbs.nth(0).check();
			if ((await bulkCbs.count()) >= 2) {
				await bulkCbs.nth(1).check();
			}
			const toolbar = page.locator('#bulk-decision-toolbar');
			await expect(toolbar).not.toHaveClass(/visually-hidden/, { timeout: 10_000 });
			await shot(page, 'azc-manager-bulk-toolbar.png', { settleMs: 300 });
			await page.locator('#bulk-decision-reject').click();
			const rejectModal = page.locator('#bulk-reject-modal');
			if (await rejectModal.isVisible({ timeout: 10_000 }).catch(() => false)) {
				await shot(page, 'azc-manager-dialog-bulk-reject.png', { settleMs: 300 });
				await rejectModal.locator('.btn-reject-modal-cancel').first().click()
					.catch(() => page.keyboard.press('Escape'));
			}
			await page.locator('#bulk-decision-clear').click().catch(() => {});
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

		/* --- compliance section detail: the org-level paid-break cap field
		   sits below the fold — scroll it into frame for an honest capture. */
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/settings/compliance');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		await dismissToasts(page);
		if (await page.locator('#breakPolicyPaidCapMinutes').count()) {
			await shot(page, 'azc-admin-settings-compliance-paid-break.png', {
				scroll: '#breakPolicyPaidCapMinutes',
				settleMs: 300,
			});
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

	test('craft admin dialogs + filter toolbars (3.5.15 inventory)', async ({ page, browser }) => {
		test.skip(!hasCreds('ADMIN'), 'Requires NC_ADMIN_* creds');
		test.setTimeout(480_000);

		await login(page, credsFromEnv('ADMIN'));
		await resetUserTheme(page).catch(() => {});
		await setUserTheme(page, 'light');

		/* --- tariff-rules modal (role=dialog): create trigger + cancel --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/tariff-rules');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		await page.locator('#tariff-rules-create').click();
		const tariffModal = page.locator('#tariff-rules-modal[role="dialog"]');
		await expect(tariffModal).toBeVisible({ timeout: 15_000 });
		await shot(page, 'azc-admin-tariff-rules-modal.png', { settleMs: 300 });
		await tariffModal.locator('[data-dismiss="modal"]').first().click();
		await expect(tariffModal).toBeHidden({ timeout: 10_000 });

		/* --- working-time-model editor: paid-break controls + live impact
		   preview (TVöD §6), and the employee time-entries paid line.
		   Seed a dedicated model via API, assign the shared employee to it
		   (assignment restored in finally), let the employee record one
		   9h+1h-break day in 1993 so the allocator snapshots a paid portion,
		   then: (a) open the model editor, change the cap → the debounced
		   preview GET fires with REAL affected counts (the callout only
		   renders in edit mode when flag/cap differ from persisted state);
		   (b) capture the employee list cell's "Net: … · paid break: …" line. */
		let pbModelId = null;
		let pbEntryId = null;
		let pbOrigAssignment = null;
		const pbEmployeeUid = hasCreds('EMPLOYEE') ? credsFromEnv('EMPLOYEE').username : null;
		let empCtx = null;
		let empPage = null;
		try {
			const pbModel = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/admin/working-time-models', {
				data: {
					name: `AZC craft paid-break ${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
					description: 'craft capture fixture — deleted after shot',
					type: 'full_time',
					weeklyHours: 40,
					dailyHours: 8,
					workDaysPerWeek: 5,
					breakRules: { breaks_count_as_work: true, paid_break_cap_minutes: 45 },
				},
			});
			pbModelId = pbModel.json?.model?.id ?? null;
			expect(pbModelId, `paid-break model seed failed: ${JSON.stringify(pbModel.json)}`).toBeTruthy();

			if (pbEmployeeUid) {
				const userBefore = await apiAllowFailure(page, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${pbEmployeeUid}`);
				const origModel = userBefore.json?.user?.userWorkingTimeModel ?? null;
				if (origModel?.workingTimeModelId) {
					pbOrigAssignment = {
						workingTimeModelId: origModel.workingTimeModelId,
						startDate: userBefore.json?.user?.workingTimeModelStartDate ?? null,
						endDate: userBefore.json?.user?.workingTimeModelEndDate ?? null,
						vacationDaysPerYear: userBefore.json?.user?.vacationDaysPerYear ?? null,
						germanState: userBefore.json?.user?.germanState ?? null,
					};
				}
				await apiAllowFailure(page, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${pbEmployeeUid}/working-time-model`, {
					data: { workingTimeModelId: pbModelId, startDate: '1993-01-01' },
				});

				empCtx = await browser.newContext();
				empPage = await empCtx.newPage();
				await login(empPage, credsFromEnv('EMPLOYEE'));
				// Scan 1993 for a free day (same convention as paid-breaks.spec).
				const pbBase = new Date('1993-03-01T00:00:00Z');
				let pbWorkDate = null;
				for (let i = 0; i < 40 && !pbWorkDate; i++) {
					const d = new Date(pbBase);
					d.setUTCDate(d.getUTCDate() + i + Math.floor(Math.random() * 200));
					const date = d.toISOString().slice(0, 10);
					const res = await apiAllowFailure(empPage, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
						data: {
							date,
							startTime: '08:00',
							endTime: '17:00',
							breaks: [{ start: '12:00', end: '13:00' }],
							justification: 'craft paid-break capture entry.',
						},
					});
					if (res.ok && res.json?.success) {
						pbWorkDate = date;
						pbEntryId = res.json.entry?.id ?? null;
					}
				}
				expect(pbWorkDate, 'employee could not create the paid-break craft entry').toBeTruthy();

				// (a) Admin editor with the impact preview mid-fire.
				await gotoApp(page, '/apps/arbeitszeitcheck/admin/working-time-models');
				await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
				const pbRow = page.locator(`tr[data-model-id="${pbModelId}"]`);
				await pbRow.scrollIntoViewIfNeeded();
				// dispatchEvent: the actions-cell overlay reveals on row hover;
				// firing the handler directly avoids a pointer hit-test stall.
				await pbRow.locator('[data-action="edit-model"]').dispatchEvent('click');
				const pbPanel = page.locator('#wtm-editor-panel');
				await expect(pbPanel).toBeVisible({ timeout: 15_000 });
				await expect(pbPanel.locator('#wtm-model-breaks-count-as-work')).toBeChecked();
				const pbCap = pbPanel.locator('#wtm-model-paid-break-cap');
				await expect(pbCap).toHaveValue('45');
				const impactResp = page.waitForResponse(
					(r) => r.url().includes('/paid-break-impact') && r.status() === 200,
					{ timeout: 15_000 });
				await pbCap.fill('30');
				await impactResp;
				await expect(pbPanel.locator('#wtm-model-paid-break-impact'))
					.toBeVisible({ timeout: 10_000 });
				// Scroll the callout itself into frame — the editor is taller
				// than the viewport; a panel-top scroll strands the preview
				// (and the flag/cap block sits just above it).
				await shot(page, 'azc-admin-working-time-models-editor-paid-break.png', {
					scroll: '#wtm-model-paid-break-impact',
					settleMs: 300,
				});
				await pbPanel.locator('[data-action="close-editor"]').first().click();
				await expect(pbPanel.locator('#wtm-model-form')).toHaveCount(0, { timeout: 10_000 });

				// (b) Employee list cell: "Net: 8.00 h · paid break: 45 min".
				// Durable postcondition first — never capture a surface whose
				// paid snapshot silently failed (e.g. pending-approval path).
				const pbList = await apiAllowFailure(empPage, 'GET', `/apps/arbeitszeitcheck/api/time-entries?start_date=${pbWorkDate}&end_date=${pbWorkDate}`);
				const pbRowJson = (pbList.json?.entries || pbList.json?.data?.entries || [])
					.find((e) => Number(e.id) === Number(pbEntryId));
				expect(
					Number(pbRowJson?.paidBreakSeconds ?? 0),
					`paid snapshot missing on entry ${pbEntryId}: ${JSON.stringify(pbRowJson)}`,
				).toBeGreaterThan(0);

				await empPage.goto(`/apps/arbeitszeitcheck/time-entries?start_date=${pbWorkDate}&end_date=${pbWorkDate}`);
				await empPage.waitForSelector('#azc-main-content', { timeout: 30_000 });
				// Numeric needle, locale-safe: 8.00 net + 0.75h paid = "8.75".
				await expect(
					empPage.locator('#time-entries-table td[data-label]', { hasText: '8.75' }).first(),
				).toBeVisible({ timeout: 15_000 });
				await shot(empPage, 'azc-time-entries-paid-break.png', {
					waitFor: '#time-entries-table, .azc-empty-state',
					scroll: '#time-entries-table',
					settleMs: 300,
				});
			}
		} finally {
			if (empCtx) {
				if (pbEntryId && empPage) {
					// Reuse the logged-in page — a fresh blank page has no
					// OC.requestToken for the API helper.
					await apiAllowFailure(empPage, 'DELETE', `/apps/arbeitszeitcheck/api/time-entries/${pbEntryId}`).catch(() => {});
				}
				await empCtx.close().catch(() => {});
			}
			if (pbEmployeeUid) {
				const restore = pbOrigAssignment
					? { ...pbOrigAssignment }
					: { workingTimeModelId: '' };
				await apiAllowFailure(page, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${pbEmployeeUid}/working-time-model`, { data: restore });
			}
			if (pbModelId) {
				await apiAllowFailure(page, 'DELETE', `/apps/arbeitszeitcheck/api/admin/working-time-models/${pbModelId}`);
			}
		}

		/* --- holiday calendar filters: year select re-queries the list --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/holidays');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		await expect(page.locator('#holiday-calendar-filters')).toBeVisible();
		const yearSel = page.locator('#holiday-year-select');
		const curYear = await yearSel.inputValue();
		const altYear = String(parseInt(curYear, 10) + 1);
		const holResp = page.waitForResponse(
			(r) => r.url().includes('/api/admin/state-holidays')
				&& r.url().includes(`year=${altYear}`), { timeout: 20_000 });
		await yearSel.selectOption(altYear);
		await holResp;
		await expect(page.locator('#holiday-tbody tr').first())
			.toBeVisible({ timeout: 15_000 });
		// Region select drives the same loader — switch back+forth proves the control.
		const stateSel = page.locator('#holiday-state-select');
		if (await stateSel.count()) {
			const curState = await stateSel.inputValue();
			const otherState = await stateSel.locator('option')
				.evaluateAll((opts, cur) =>
					(opts.find((o) => o.value !== cur) || {}).value || cur, curState);
			const stResp = page.waitForResponse(
				(r) => r.url().includes('/api/admin/state-holidays')
					&& r.url().includes(`state=${encodeURIComponent(otherState)}`), { timeout: 20_000 });
			await stateSel.selectOption(otherState);
			await stResp;
		}

		/* --- compliance violations filters: severity drives the query --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/compliance/violations');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		await expect(page.locator('.compliance-violations__filters')).toBeVisible();
		await page.locator('#severity-filter').selectOption('error');
		const vResp = page.waitForResponse(
			(r) => r.url().includes('/api/compliance/violations')
				&& r.url().includes('severity=error'), { timeout: 20_000 });
		await page.locator('#apply-filters').click();
		await vResp;
		// Rendered severity badges (if any rows) must all match the filter value.
		const badges = page.locator('#violations-tbody .badge--error, #violations-tbody .badge--warning, #violations-tbody .badge--primary');
		const nBadges = await badges.count();
		for (let i = 0; i < nBadges; i++) {
			await expect(badges.nth(i)).toHaveClass(/badge--error/);
		}

		/* --- overtime payouts filter: month+year reload via refresh --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/overtime-payouts');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		await expect(page.locator('.admin-ot-payouts__filters')).toBeVisible();
		const monthEl = page.locator('#ot-payout-month');
		const curMonth = parseInt(await monthEl.inputValue(), 10);
		const altMonth = curMonth === 1 ? 2 : 1;
		const otResp = page.waitForResponse(
			(r) => r.url().includes('/api/admin/overtime-payouts')
				&& r.url().includes(`month=${altMonth}`), { timeout: 20_000 });
		await monthEl.selectOption(String(altMonth));
		await page.locator('#ot-payout-refresh').click();
		await otResp;

		/* --- overtime payout audit filter form: month select + submit --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/overtime-payout-audit');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		await expect(page.locator('#ot-audit-filter-form')).toBeVisible();
		await page.locator('#ot-audit-month').selectOption('1');
		const auResp = page.waitForResponse(
			(r) => r.url().includes('/api/admin/overtime-payout-audit')
				&& r.url().includes('month=1'), { timeout: 20_000 });
		await page.locator('#ot-audit-apply').click();
		await auResp;

		/* --- license clear modal: real confirm leg (destructive → restore) ---
		   open→confirm→cleared-state re-read, then re-apply the lab license
		   and re-seat the fixture users so shared test state is restored. */
		// Seed a license first — the clear trigger only renders when licensed,
		// and sibling lanes can clear it at any time.
		const seed = await applyLabLicense(page, apiAllowFailure);
		expect(seed.ok, `license seed failed: ${JSON.stringify(seed.res?.json)}`).toBe(true);
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/license');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		const clearOpen = page.locator('#azc-license-clear');
		await expect(clearOpen).toBeVisible({ timeout: 15_000 });
		await clearOpen.click();
		const clearModal = page.locator('#azc-license-clear-modal[role="dialog"]');
		await expect(clearModal).toBeVisible({ timeout: 10_000 });
		await shot(page, 'azc-admin-license-clear-confirm.png', { settleMs: 300 });
		await page.locator('#azc-license-clear-confirm').click();
		// cleared state re-read: reload shows the apply form, no clear trigger
		await page.waitForSelector('#azc-license-key-input', { timeout: 30_000 });
		await expect(page.locator('#azc-license-clear')).toHaveCount(0);
		const lic = await applyLabLicense(page, apiAllowFailure);
		expect(lic.ok, `license re-apply failed: ${JSON.stringify(lic.res?.json)}`).toBe(true);
		const seats = await apiAllowFailure(page, 'POST',
			'/apps/arbeitszeitcheck/api/admin/license/mobile-seats/batch',
			{ data: { userIds: ['e2e_employee', 'e2e_manager', 'e2e_substitute', 'azc_gdpr_probe'] } });
		expect(seats.ok, `seat restore failed: ${JSON.stringify(seats.json)}`).toBe(true);
		// restore re-read: the licensed surface renders the clear trigger again
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/license');
		await expect(page.locator('#azc-license-clear')).toBeVisible({ timeout: 15_000 });

		await resetUserTheme(page).catch(() => {});
	});

	test('craft employee timeline filters + calendar day panel (3.5.15 inventory)', async ({ page }) => {
		test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_* creds');
		test.setTimeout(300_000);

		await login(page, credsFromEnv('EMPLOYEE'));
		await page.evaluate(() => sessionStorage.removeItem('arbeitszeitcheck-timeline-filters'));
		await gotoApp(page, '/apps/arbeitszeitcheck/timeline');
		await page.waitForSelector('#timeline-container, #azc-main-content', { timeout: 30_000 });
		await expect(page.locator('#timeline-filters')).toBeVisible();

		// Unchecking a type must remove its rendered items (client-side filter
		// re-render from cached data + sessionStorage persistence).
		const absenceBox = page.locator('#timeline-filter-absences');
		const entriesBox = page.locator('#timeline-filter-time-entries');
		await absenceBox.uncheck();
		await expect(page.locator('.timeline-item--absence')).toHaveCount(0, { timeout: 10_000 });
		await absenceBox.check();
		await entriesBox.uncheck();
		await expect(page.locator('.timeline-item--time-entry')).toHaveCount(0, { timeout: 10_000 });
		// sessionStorage persistence leg
		const persisted = await page.evaluate(
			() => JSON.parse(sessionStorage.getItem('arbeitszeitcheck-timeline-filters') || '{}'));
		expect(persisted.timeEntries).toBe(false);
		await entriesBox.check();

		/* --- calendar day-details panel (role=dialog): open → close --- */
		await gotoApp(page, '/apps/arbeitszeitcheck/calendar');
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 });
		const dayCell = page.locator('.calendar-day[data-date]').first();
		await expect(dayCell).toBeVisible({ timeout: 30_000 });
		await dayCell.click();
		const dayPanel = page.locator('#day-details-panel[role="dialog"]');
		await expect(dayPanel).toBeVisible({ timeout: 15_000 });
		await shot(page, 'azc-calendar-day-details-panel.png', { settleMs: 300 });
		await page.locator('#btn-close-panel').click();
		await expect(dayPanel).toBeHidden({ timeout: 10_000 });
	});

	test.afterAll(async () => {
		fs.mkdirSync(OUT, { recursive: true });
		fs.writeFileSync(
			path.join(OUT, '_manifest.json'),
			JSON.stringify({ app: 'arbeitszeitcheck', captured_at: new Date().toISOString(), files: MANIFEST }, null, 1),
		);
	});
});
