// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { loginAs, gotoApp } from './helpers/auth.js';

// role: who the page is meant for — the smoke logs in as that user so we
// scan the real rendered surface, not an access-denied shell.
const a11yRoutes = [
	{ path: '/apps/arbeitszeitcheck/dashboard', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/time-entries', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/time-entries/create', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/absences', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/absences/create', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/business-trips', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/reports', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/calendar', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/timeline', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/compliance', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/compliance/violations', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/compliance/reports', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/substitution-requests', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/get-the-app', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/settings', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/settings/breaks', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/settings/notifications', role: 'EMPLOYEE' },
	{ path: '/apps/arbeitszeitcheck/manager', role: 'MANAGER' },
	{ path: '/apps/arbeitszeitcheck/manager/time-entries', role: 'MANAGER' },
	{ path: '/apps/arbeitszeitcheck/manager/absences', role: 'MANAGER' },
	{ path: '/apps/arbeitszeitcheck/manager/month-closures', role: 'MANAGER' },
	{ path: '/apps/arbeitszeitcheck/manager/business-trips', role: 'MANAGER' },
	{ path: '/apps/arbeitszeitcheck/admin', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/users', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/teams', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/holidays', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/working-time-models', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/absence-types', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/tariff-rules', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/audit-log', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/kiosk', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/license', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/support-us', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/notifications', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/overtime-settings', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/vacation-rules', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/vacation-layers', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/overtime-payouts', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/overtime-payout-audit', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/settings', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/settings/access', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/settings/compliance', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/settings/regional', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/business-trips', role: 'ADMIN' },
	{ path: '/apps/arbeitszeitcheck/admin/special-entitlements', role: 'ADMIN' },
];

for (const route of a11yRoutes) {
	test(`a11y smoke: ${route.path}`, async ({ page }) => {
		await loginAs(page, route.role);
		await gotoApp(page, route.path);
		await page.waitForSelector('#azc-main-content', { timeout: 30000 });
		const results = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze();
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
	});
}
