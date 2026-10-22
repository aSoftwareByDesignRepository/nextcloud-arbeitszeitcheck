// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/arbeitszeitcheck/craft/web/. The companion
 * gate scripts/check-screenshot-matrix.py verifies completeness — a declared
 * matrix cell that produces no artifact fails the ready-gate craft stage.
 *
 * States:
 *   populated — seeded fixture data visible (manager scope lists, admin users)
 *   empty     — deterministic empty render: a search query that matches
 *               nothing (list can't rely on DB state)
 *   current   — state-invariant chrome (dashboard, settings, get-the-app) or
 *               surfaces whose population depends on fixture data we do not
 *               control here
 *
 * Roles: surfaces carry a `role` (EMPLOYEE/MANAGER/ADMIN) — the matrix logs in
 * once per role and captures that role's surfaces in one session.
 *
 * Run (opt-in — the env flag keeps the capture out of default suite runs):
 *   AZC_MATRIX=1 npx playwright test --project=screenshot-matrix
 */
import { test, expect } from '@playwright/test';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { login, credsFromEnv, hasCreds } from '../tests/e2e/helpers/auth.js';
import { apiAllowFailure } from '../tests/e2e/helpers/api.js';

const HERE = dirname(fileURLToPath(import.meta.url));
const MANIFEST = JSON.parse(readFileSync(join(HERE, 'screenshot-matrix.json'), 'utf8'));
const OUT = resolve(HERE, '../../../../.cursor/atlas-farm-v3/artifacts/arbeitszeitcheck', MANIFEST.outDir);
const APP = '/apps/arbeitszeitcheck';
const NO_MATCH = 'zz-nomatch-9x7q';

/**
 * Apply a user theme client-side (theme--* / data-theme-* / data-themes) —
 * the same mechanism Nextcloud emits for a user-pinned theme, so pixels are
 * identical but no occ writes hit the shared instance.
 */
async function applyTheme(page, theme) {
	await page.evaluate((id) => {
		const body = document.body;
		['theme--light', 'theme--dark', 'theme--dark-highcontrast', 'theme--light-highcontrast']
			.forEach((c) => body.classList.remove(c));
		['data-theme-default', 'data-theme-light', 'data-theme-dark', 'data-theme-dark-highcontrast', 'data-theme-light-highcontrast']
			.forEach((a) => body.removeAttribute(a));
		body.removeAttribute('data-themes');
		if (id === 'dark') {
			body.classList.add('theme--dark');
			body.setAttribute('data-theme-dark', 'true');
			body.setAttribute('data-themes', 'dark');
		} else {
			body.classList.add('theme--light');
			body.setAttribute('data-theme-light', 'true');
			body.setAttribute('data-themes', 'default');
		}
	}, theme);
}

/** Wait for a painted frame — the real "rendered" signal, not a blind settle. */
async function settle(page, path) {
	await page.goto(`${APP}${path}`, { waitUntil: 'domcontentloaded' });
	await page.waitForLoadState('networkidle').catch(() => {});
	await page.waitForSelector('#app-content', { timeout: 30_000 });
	// two painted frames before the shutter
	await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
}

/**
 * The scope-list pages gate their results behind a "Show" submit AND ambient
 * seed data ages out of the default range (see table-width-desktop.spec.js).
 * Populated producers therefore seed a row via the manager API first, then
 * drive the filter form — same as the sibling specs.
 */
const SEED_EMP = () => process.env.NC_EMPLOYEE_USER || 'e2e_employee';
const fmtDE = (d) => `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}.${d.getFullYear()}`;
const fmtISO = (d) => d.toISOString().slice(0, 10);

async function fillRangeAndShow(page, days) {
	const start = new Date(Date.now() - days * 86400000);
	const end = new Date(Date.now() + days * 86400000);
	await page.locator('#start-date-filter').fill(fmtDE(start));
	await page.locator('#end-date-filter').fill(fmtDE(end));
	await page.locator('[id$="-submit"].azc-btn--primary').first().click();
}

const STATE_PRODUCERS = {
	populated: {
		'manager-people': async (page) => {
			const search = page.locator('#manager-people-search');
			if (await search.count()) {
				await search.fill('');
				await page.locator('#manager-people-filter-form').evaluate((f) => f.requestSubmit());
			}
			await expect(page.locator('#manager-people-body tr').first()).toBeVisible({ timeout: 30_000 });
		},
		'manager-time-entries': async (page) => {
			const yesterday = new Date(Date.now() - 86400000);
			await apiAllowFailure(page, 'POST', `${APP}/api/manager/employee-time-entries`, {
				data: {
					userId: SEED_EMP(),
					date: fmtDE(yesterday),
					startTime: '08:00',
					endTime: '12:00',
					reason: 'matrix-populated seed',
				},
			});
			await fillRangeAndShow(page, 30);
			await expect(page.locator('.manager-scope-page__results-table tbody tr').first()).toBeVisible({ timeout: 30_000 });
		},
		'manager-absences': async (page) => {
			// Probe forward weeks for a free window — shared dev users
			// accumulate approved absences across runs (atlas-api-mutations).
			// Track the accepted window so the Show range actually covers it.
			let seededSd = null;
			for (let w = 0; w < 40 && !seededSd; w++) {
				const sd = new Date(Date.now() + (7 + w * 7) * 86400000);
				const ed = new Date(Date.now() + (8 + w * 7) * 86400000);
				const res = await apiAllowFailure(page, 'POST', `${APP}/api/manager/employee-absences`, {
					data: {
						userId: SEED_EMP(),
						type: 'special_leave',
						startDate: fmtISO(sd),
						endDate: fmtISO(ed),
						reason: 'matrix-populated seed',
					},
				});
				if (res.ok) { seededSd = sd; }
			}
			// Default to a wide window when no fresh seed landed — ambient rows
			// may still exist inside it.
			const start = seededSd ? new Date(seededSd.getTime() - 30 * 86400000) : new Date(Date.now() - 180 * 86400000);
			const end = new Date(start.getTime() + 360 * 86400000);
			await page.locator('#start-date-filter').fill(fmtDE(start));
			await page.locator('#end-date-filter').fill(fmtDE(end));
			await page.locator('[id$="-submit"].azc-btn--primary').first().click();
			await expect(page.locator('.manager-scope-page__results-table tbody tr').first()).toBeVisible({ timeout: 30_000 });
		},
		'admin-users': async (page) => {
			await expect(page.locator('tbody tr').first()).toBeVisible({ timeout: 30_000 });
		},
	},
	empty: {
		'manager-people': async (page) => {
			await page.locator('#manager-people-search').fill(NO_MATCH);
			await page.locator('#manager-people-filter-form').evaluate((f) => f.requestSubmit());
			await expect(page.locator('#manager-people-empty')).toBeVisible({ timeout: 30_000 });
		},
	},
};

function shotName(surface, state, vp, theme) {
	return `${MANIFEST.prefix}-${surface.id}-${state}-${vp.name}${theme === 'dark' ? '-dark' : ''}.png`;
}

function* matrixCells(theme, role) {
	for (const surface of MANIFEST.surfaces) {
		if ((surface.role || 'EMPLOYEE') !== role) { continue; }
		for (const state of surface.states) {
			for (const vp of MANIFEST.viewports) {
				if (theme === 'dark' && (!surface.dark || vp.name !== 'desktop' || state !== surface.states[0])) { continue; }
				yield { surface, state, vp };
			}
		}
	}
}

const ROLES = [...new Set(MANIFEST.surfaces.map((s) => s.role || 'EMPLOYEE'))];

test.describe('SCREENSHOT_MATRIX capture', () => {
	test.setTimeout(600_000);
	test.skip(!process.env.AZC_MATRIX, 'Opt-in capture — run with AZC_MATRIX=1 --project=screenshot-matrix');

	for (const role of ROLES) {
		test(`captures every manifest cell as ${role}`, async ({ page }) => {
			test.skip(!hasCreds(role), `Requires NC_${role}_USER / NC_${role}_PASS`);
			await login(page, credsFromEnv(role));
			mkdirSync(OUT, { recursive: true });
			const missing = [];

			for (const theme of ['light', 'dark']) {
				for (const { surface, state, vp } of matrixCells(theme, role)) {
					await page.setViewportSize({ width: vp.width, height: vp.height });
					await settle(page, surface.path);
					await applyTheme(page, theme);
					const produce = (STATE_PRODUCERS[state] || {})[surface.id];
					if (state === 'empty') {
						expect(produce, `no empty-state producer for ${surface.id}`).toBeTruthy();
					}
					if (produce) { await produce(page); }
					const file = join(OUT, shotName(surface, state, vp, theme));
					// #app-content scrolls internally — a viewport-height screenshot
					// would only capture the fold. Grow the viewport to the
					// scroller's full content height (capped for pathological pages).
					const contentH = await page.evaluate(() =>
						(document.querySelector('#app-content') || document.documentElement).scrollHeight);
					const shotH = Math.min(Math.max(contentH + 60, vp.height), 8192);
					if (shotH !== vp.height) {
						await page.setViewportSize({ width: vp.width, height: shotH });
						await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
					}
					await page.screenshot({ path: file });
					if (!existsSync(file)) { missing.push(file); }
				}
			}

			expect(missing, `matrix cells missing: ${missing.join(', ')}`).toEqual([]);
		});
	}
});
