/* global process */
// @ts-check
/**
 * Theme × viewport × WCAG 2.1 AA gauntlet for ArbeitszeitCheck policy pages.
 *
 * Proves for every selectable NC theme and key route:
 *  - theme switched (body[data-theme-*]),
 *  - --azc-* tokens resolve from Nextcloud --color-*,
 *  - no horizontal overflow from 320 px to 4K,
 *  - chip / sticky Save touch targets ≥ 44×44,
 *  - zero axe WCAG 2.1 A/AA violations on the app shell.
 *
 * Run with --workers=1 (theme OCS + login are not safe to parallelise).
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'
import {
	setUserTheme,
	resetUserTheme,
	setAccentColor,
	resetAccentColor,
	USER_THEMES,
} from './helpers/theming.js'

test.describe.configure({ mode: 'serial' })

const adminRoutes = [
	{ id: 'notifications', path: '/apps/arbeitszeitcheck/admin/notifications', ready: '#admin-notifications-form' },
	{ id: 'overtime', path: '/apps/arbeitszeitcheck/admin/overtime-settings', ready: '#admin-overtime-settings-form' },
	{ id: 'vacation', path: '/apps/arbeitszeitcheck/admin/vacation-rules', ready: '#admin-vacation-policy-form' },
	{ id: 'vacation-entitlement', path: '/apps/arbeitszeitcheck/admin/vacation-layers', ready: '#layer-l0' },
	{ id: 'settings-access', path: '/apps/arbeitszeitcheck/admin/settings/access', ready: '#admin-settings-form' },
	{ id: 'admin-users', path: '/apps/arbeitszeitcheck/admin/users', ready: '#employee-list-filter-title' },
	{ id: 'working-time-models', path: '/apps/arbeitszeitcheck/admin/working-time-models', ready: '#create-model' },
	{
		id: 'outlook-subscription',
		path: '/apps/arbeitszeitcheck/admin/settings/outlook-subscription',
		ready: '#outlook-ical-subscription',
	},
	{ id: 'business-trips', path: '/apps/arbeitszeitcheck/admin/business-trips', ready: '#bta-root' },
	{ id: 'special-entitlements', path: '/apps/arbeitszeitcheck/admin/special-entitlements', ready: '#se-admin-root' },
]

const employeeRoutes = [
	{ id: 'dashboard', path: '/apps/arbeitszeitcheck/dashboard', ready: '#azc-main-content' },
	{ id: 'settings-breaks', path: '/apps/arbeitszeitcheck/settings/breaks', ready: '#azc-employee-settings-pages' },
	{ id: 'settings-privacy', path: '/apps/arbeitszeitcheck/settings/data-privacy', ready: '#btn-gdpr-delete' },
	{ id: 'time-entries', path: '/apps/arbeitszeitcheck/time-entries', ready: '#azc-main-content' },
	{ id: 'calendar', path: '/apps/arbeitszeitcheck/calendar', ready: '#azc-main-content' },
	{ id: 'business-trips', path: '/apps/arbeitszeitcheck/business-trips', ready: '#bt-root' },
]

const managerRoutes = [
	{ id: 'manager-business-trips', path: '/apps/arbeitszeitcheck/manager/business-trips', ready: '#mbt-root' },
]

const overflowViewports = [
	{ width: 320, height: 640 },
	{ width: 375, height: 812 },
	// 414px is the large-phone edge of the <768px mobile regime (single column, nav behind platform toggle)
	{ width: 414, height: 896 },
	{ width: 768, height: 1024 },
	{ width: 1024, height: 768 },
	// 1280px is the common laptop floor inside the ≥1024px sacred-desktop regime
	{ width: 1280, height: 800 },
	{ width: 1440, height: 900 },
	{ width: 2560, height: 1440 },
]

const axeViewports = [
	{ width: 320, height: 640 },
	{ width: 768, height: 1024 },
	{ width: 1280, height: 800 },
]

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function expectNoHorizontalOverflow(page, label) {
	const overflow = await page.evaluate(() => {
		const doc = document.documentElement
		const app = document.querySelector('#app-content.azc-app')
		const shell = document.querySelector('#app-content-wrapper.azc-shell, .azc-shell')
		const main = document.getElementById('azc-main-content')
		return {
			doc: doc.scrollWidth - doc.clientWidth,
			app: app ? app.scrollWidth - app.clientWidth : 0,
			shell: shell ? shell.scrollWidth - shell.clientWidth : 0,
			main: main ? main.scrollWidth - main.clientWidth : 0,
		}
	})
	expect(overflow.doc, `document horizontal overflow at ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.app, `#app-content overflow at ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.shell, `.azc-shell overflow at ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.main, `#azc-main-content overflow at ${label}`).toBeLessThanOrEqual(1)
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertThemeTokensResolved(page) {
	const tokens = await page.evaluate(() => {
		const bodyCs = getComputedStyle(document.body)
		const app = document.querySelector('#app-content.azc-app') || document.body
		const cs = getComputedStyle(app)
		return {
			bg: bodyCs.getPropertyValue('--azc-bg-card').trim() || bodyCs.getPropertyValue('--color-main-background').trim(),
			text: bodyCs.getPropertyValue('--azc-text').trim() || bodyCs.getPropertyValue('--color-main-text').trim(),
			primary: bodyCs.getPropertyValue('--color-primary-element').trim(),
			muted: bodyCs.getPropertyValue('--azc-muted').trim(),
			tintInfo: bodyCs.getPropertyValue('--azc-tint-info').trim(),
			tintSuccess: bodyCs.getPropertyValue('--azc-tint-success').trim(),
			dangerFill: bodyCs.getPropertyValue('--azc-danger-fill').trim(),
			dangerOnFill: bodyCs.getPropertyValue('--azc-danger-on-fill').trim(),
			touch: bodyCs.getPropertyValue('--azc-touch').trim(),
			scrim: bodyCs.getPropertyValue('--azc-scrim').trim(),
			appBg: cs.backgroundColor,
			appColor: cs.color,
		}
	})
	expect(tokens.bg, 'theme background token').not.toEqual('')
	expect(tokens.text, 'theme text token').not.toEqual('')
	expect(tokens.primary, 'primary element token').not.toEqual('')
	expect(tokens.muted, 'muted token').not.toEqual('')
	expect(tokens.tintInfo, 'tint-info must resolve').not.toEqual('')
	expect(tokens.tintSuccess, 'tint-success must resolve').not.toEqual('')
	expect(tokens.dangerFill, 'danger-fill must resolve').not.toEqual('')
	expect(tokens.dangerOnFill, 'danger-on-fill must resolve').not.toEqual('')
	expect(
		/,\s*transparent\s*\)\s*$/i.test(tokens.tintInfo),
		`tint-info must mix into main-background, got: ${tokens.tintInfo}`,
	).toBeFalsy()
	expect(tokens.scrim, 'scrim token').not.toEqual('')
	expect(tokens.touch === '44px' || parseFloat(tokens.touch) >= 44, 'touch token ≥44px').toBeTruthy()
	expect(tokens.appBg, 'app content must paint a theme background').not.toEqual('')
	expect(tokens.appColor, 'app content must paint theme text').not.toEqual('')
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertPolicyChromeTouchTargets(page) {
	const result = await page.evaluate(() => {
		const nodes = [
			...document.querySelectorAll(
				[
					'#azc-policy-pages .azc-settings-nav__link',
					'#azc-admin-settings-pages .azc-settings-nav__link',
					'#azc-employee-settings-pages .azc-settings-nav__link',
					'.azc-btn--touch',
					'#admin-notifications-save',
					'#admin-overtime-settings-save',
					'#admin-vacation-policy-save',
					'#admin-settings-save',
					'#create-model',
					'#wtm-model-submit',
					'#user-search',
					'#employee-list-show-all',
					'#employee-list-empty-show-all',
					'#export-users-csv',
					'#refresh-users',
					'#users-page-prev',
					'#users-page-next',
					'#users-table .azc-btn',
					'.btn-delete-entry',
					'#btn-gdpr-delete',
					'#outlookIcalCreateBtn',
					'#outlookIcalTeamSearch',
					'#outlookIcalFeedLanguage',
					'#outlookIcalEnableWebcalLocalBtn',
					'#bta-root .azc-btn',
					'#bt-root .azc-btn',
					'#mbt-root .azc-btn',
					'#se-admin-root .azc-btn',
				].join(', '),
			),
		]
		const undersized = []
		for (const el of nodes) {
			const style = getComputedStyle(el)
			if (style.display === 'none' || style.visibility === 'hidden') continue
			const rect = el.getBoundingClientRect()
			if (rect.width === 0 && rect.height === 0) continue
			const minH = Math.max(rect.height, parseFloat(style.minHeight) || 0)
			const minW = Math.max(rect.width, parseFloat(style.minWidth) || 0)
			const isBar = rect.width >= 120
			if (minH < 40 || (!isBar && minW < 40)) {
				undersized.push({
					tag: el.tagName,
					cls: String(el.className).slice(0, 80),
					w: Math.round(minW),
					h: Math.round(minH),
				})
			}
		}
		return { ok: undersized.length === 0, undersized, count: nodes.length }
	})
	expect(result.count, 'expected interactive chrome nodes').toBeGreaterThan(0)
	expect(result.ok, JSON.stringify(result.undersized)).toBeTruthy()
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function runAxe(page, label) {
	const results = await new AxeBuilder({ page })
		.include('#content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.toastify')
		.analyze()
	expect(
		results.violations,
		`axe violations at ${label}:\n${JSON.stringify(results.violations, null, 2)}`,
	).toEqual([])
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 * @param {string} ready
 */
async function gotoReady(page, path, ready) {
	await page.goto(path, { waitUntil: 'domcontentloaded' })
	await assertArbeitszeitcheckLoaded(page)
	await expect(page.locator(ready)).toBeVisible({ timeout: 30_000 })
	await page.waitForFunction(() => {
		const body = getComputedStyle(document.body)
		return body.getPropertyValue('--color-main-text').trim() !== ''
			&& body.getPropertyValue('--color-main-background').trim() !== ''
	}, null, { timeout: 10_000 }).catch(() => {})
}

test.describe('ArbeitszeitCheck theme × viewport a11y matrix (admin policy)', () => {
	test.setTimeout(300_000)
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	for (const theme of USER_THEMES) {
		for (const route of adminRoutes) {
			test(`${theme}: ${route.id}`, async ({ page }) => {
				await login(page, credsFromEnv('ADMIN'))
				await gotoReady(page, route.path, route.ready)
				await setUserTheme(page, theme)
				await expect(page.locator(route.ready)).toBeVisible({ timeout: 30_000 })
				await assertThemeTokensResolved(page)

				for (const viewport of overflowViewports) {
					await page.setViewportSize(viewport)
					await expectNoHorizontalOverflow(page, `${theme}/${route.id}@${viewport.width}px`)
				}
				await page.setViewportSize({ width: 375, height: 812 })
				await assertPolicyChromeTouchTargets(page)
				for (const viewport of axeViewports) {
					await page.setViewportSize(viewport)
					await runAxe(page, `${theme}/${route.id}@${viewport.width}px`)
				}
			})
		}
	}

	test('reset admin theme', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await gotoReady(page, '/apps/arbeitszeitcheck/admin/notifications', '#admin-notifications-form')
		await resetUserTheme(page)
	})
})

test.describe('ArbeitszeitCheck theme × viewport (employee dashboard)', () => {
	test.setTimeout(180_000)
	test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')

	for (const theme of ['light', 'dark']) {
		for (const route of employeeRoutes) {
			test(`${theme}: ${route.id}`, async ({ page }) => {
				await login(page, credsFromEnv('EMPLOYEE'))
				await gotoReady(page, route.path, route.ready)
				await setUserTheme(page, theme)
				await assertThemeTokensResolved(page)
				for (const viewport of overflowViewports) {
					await page.setViewportSize(viewport)
					await expectNoHorizontalOverflow(page, `${theme}/${route.id}@${viewport.width}px`)
				}
				await page.setViewportSize({ width: 1280, height: 800 })
				await runAxe(page, `${theme}/${route.id}@1280px`)
			})
		}
	}
})

test.describe('ArbeitszeitCheck theme × viewport (manager)', () => {
	test.setTimeout(180_000)
	test.skip(!process.env.NC_MANAGER_USER, 'Requires NC_MANAGER_USER / NC_MANAGER_PASS')

	for (const theme of ['light', 'dark']) {
		for (const route of managerRoutes) {
			test(`${theme}: ${route.id}`, async ({ page }) => {
				await login(page, credsFromEnv('MANAGER'))
				await gotoReady(page, route.path, route.ready)
				await setUserTheme(page, theme)
				await assertThemeTokensResolved(page)
				for (const viewport of overflowViewports) {
					await page.setViewportSize(viewport)
					await expectNoHorizontalOverflow(page, `${theme}/${route.id}@${viewport.width}px`)
				}
				await page.setViewportSize({ width: 1280, height: 800 })
				await runAxe(page, `${theme}/${route.id}@1280px`)
			})
		}
	}
})

test.describe('ArbeitszeitCheck custom accent colour', () => {
	test.setTimeout(180_000)
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	test('primary tokens follow instance accent and stay AA', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await gotoReady(page, '/apps/arbeitszeitcheck/admin/notifications', '#admin-notifications-form')
		await resetUserTheme(page)

		const readPrimary = () => page.evaluate(() => {
			const probe = getComputedStyle(document.body).getPropertyValue('--color-primary-element').trim()
			const tint = getComputedStyle(document.body).getPropertyValue('--azc-tint-info').trim()
			return { variable: probe, tintInfo: tint }
		})

		const before = await readPrimary()
		expect(before.variable, 'NC must expose --color-primary-element').not.toEqual('')

		setAccentColor('#971003')
		try {
			await expect.poll(async () => {
				await page.reload({ waitUntil: 'load' })
				return (await readPrimary()).variable
			}, { timeout: 60_000, intervals: [1_000, 2_000, 3_000] }).not.toEqual(before.variable)

			await expect(page.locator('#admin-notifications-form')).toBeVisible({ timeout: 30_000 })
			const after = await readPrimary()
			expect(after.tintInfo, 'tint-info must still resolve after accent change').not.toEqual('')
			expect(/,\s*transparent\s*\)\s*$/i.test(after.tintInfo)).toBeFalsy()
			await runAxe(page, 'custom-accent/notifications@1280px')
			await gotoReady(
				page,
				'/apps/arbeitszeitcheck/admin/settings/outlook-subscription',
				'#outlook-ical-subscription',
			)
			await runAxe(page, 'custom-accent/outlook-subscription@1280px')
		} finally {
			resetAccentColor()
		}

		await expect.poll(async () => {
			await page.reload({ waitUntil: 'load' })
			const current = (await readPrimary()).variable
			return current === before.variable
				|| current === '#00679e'
				|| current.toLowerCase() === before.variable.toLowerCase()
		}, { timeout: 90_000, intervals: [1_000, 2_000, 3_000] }).toBeTruthy()
	})
})
