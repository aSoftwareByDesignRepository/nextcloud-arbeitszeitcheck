// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — asserts the *rendered* truth of each app
 * page surface: content list markers are not reset to none by shell CSS
 * leaks, selects are vertically centred (not sunken/clipped), icons and SVGs
 * have non-zero boxes, and form controls/labels are not text-centered by an
 * inherited shell alignment. DOM-level specs pass on pages that are visually
 * broken; this is the pixel-adjacent invariant class.
 */
import { test } from '@playwright/test';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { loginAs, gotoApp } from './helpers/auth.js';

const require = createRequire(import.meta.url);
const { assertAtlasRenderedSurface } = require(
	join(dirname(fileURLToPath(import.meta.url)), '../../../_shared/e2e/atlas-rendered-surface-contract.js'),
);

/**
 * Every authenticated page surface with lists/forms, split by role so each
 * runs under the caller it's designed for. Detail pages are covered by
 * per-journey specs (they need seeded entities); this contract sweeps the
 * list/form surfaces that break under shell CSS leaks.
 */
const SURFACES = [
	['/apps/arbeitszeitcheck/dashboard', 'dashboard', 'EMPLOYEE'],
	['/apps/arbeitszeitcheck/time-entries', 'time entries list', 'EMPLOYEE'],
	['/apps/arbeitszeitcheck/absences', 'absences list', 'EMPLOYEE'],
	['/apps/arbeitszeitcheck/absences/create', 'absence create form', 'EMPLOYEE'],
	['/apps/arbeitszeitcheck/reports', 'reports', 'EMPLOYEE'],
	['/apps/arbeitszeitcheck/calendar', 'calendar', 'EMPLOYEE'],
	['/apps/arbeitszeitcheck/settings/breaks', 'settings breaks', 'EMPLOYEE'],
	['/apps/arbeitszeitcheck/settings/notifications', 'settings notifications', 'EMPLOYEE'],
	['/apps/arbeitszeitcheck/admin/users', 'admin users', 'ADMIN'],
	['/apps/arbeitszeitcheck/admin/settings/access', 'admin settings access', 'ADMIN'],
	['/apps/arbeitszeitcheck/admin/settings/regional', 'admin settings regional', 'ADMIN'],
	['/apps/arbeitszeitcheck/admin/vacation-rules', 'admin vacation rules', 'ADMIN'],
	['/apps/arbeitszeitcheck/admin/overtime-settings', 'admin overtime settings', 'ADMIN'],
];

/**
 * Intentionally marker-less lists: chip/tag rows, status-badge rows, and the
 * desklet card grid use custom row chrome (icons + pills), not bullet lists.
 */
const LIST_ALLOW = [
	'.azc-chips',
	'.azc-chip-row',
	'.azc-badge-list',
	'.azc-status-list',
	'.azc-timeline',
	'.dz-grid',
	'.azc-page-stack',
].join(', ');

test.describe('Rendered-surface contract', () => {
	for (const [path, name, role] of SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name} (${path})`, async ({ page }) => {
			await loginAs(page, role);
			await gotoApp(page, path);
			await page.waitForSelector('#azc-main-content', { timeout: 30000 });
			await assertAtlasRenderedSurface(page, {
				content: '#azc-main-content',
				navExclude: '#app-navigation, nav, .azc-nav',
				listAllow: LIST_ALLOW,
			});
		});
	}
});
