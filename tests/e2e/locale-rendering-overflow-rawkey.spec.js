/* global process */
// @ts-check
/**
 * Locale rendering + overflow + raw-key proof for ArbeitszeitCheck web.
 *
 * Proves every supported locale can render key employee/admin surfaces without
 * raw translation placeholders (%n, %s, %d, %1$...) or horizontal overflow, and
 * that the <html lang> attribute tracks the active user language.
 *
 * Run with --workers=1 because occ user-language mutations are not safe to
 * parallelise.
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'
import {
	SUPPORTED_LOCALES,
	setUserLanguage,
	resetUserLanguage,
	getCurrentUserId,
} from './helpers/locale.js'

test.describe.configure({ mode: 'serial' })

const employeeRoutes = [
	{ id: 'dashboard', path: '/apps/arbeitszeitcheck/dashboard', ready: '#azc-main-content' },
	{ id: 'time-entries', path: '/apps/arbeitszeitcheck/time-entries', ready: '#azc-main-content' },
	{ id: 'settings-breaks', path: '/apps/arbeitszeitcheck/settings/breaks', ready: '#azc-employee-settings-pages' },
]

const adminRoutes = [
	{ id: 'admin-notifications', path: '/apps/arbeitszeitcheck/admin/notifications', ready: '#admin-notifications-form' },
	{ id: 'admin-overtime', path: '/apps/arbeitszeitcheck/admin/overtime-settings', ready: '#admin-overtime-settings-form' },
]

const viewports = [
	{ width: 1280, height: 800, label: 'desktop' },
	{ width: 375, height: 812, label: 'mobile' },
]

const rawKeyPattern = /%[nsd]|%\d+\$[nsd]/gi

/**
 * @param {import('@playwright/test').Page} page
 */
async function expectNoRawKeys(page) {
	const matches = await page.evaluate((patternSource) => {
		const pattern = new RegExp(patternSource, 'gi')
		const found = new Set()
		const container = document.querySelector('#content') || document.body
		for (const el of container.querySelectorAll('*')) {
			const tag = el.tagName.toLowerCase()
			if (tag === 'script' || tag === 'style' || tag === 'noscript') {
				continue
			}
			const text = el.innerText ?? ''
			for (const match of text.match(pattern) || []) {
				found.add(match)
			}
		}
		return Array.from(found)
	}, rawKeyPattern.source)
	expect(matches, `raw translation placeholders in visible page text: ${JSON.stringify(matches.slice(0, 20))}`).toEqual([])
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function expectNoHorizontalOverflow(page, label) {
	const overflow = await page.evaluate(() => {
		const doc = document.documentElement
		const app = document.querySelector('#app-content.azc-app')
		const main = document.getElementById('azc-main-content')
		return {
			doc: doc.scrollWidth - doc.clientWidth,
			app: app ? app.scrollWidth - app.clientWidth : 0,
			main: main ? main.scrollWidth - main.clientWidth : 0,
		}
	})
	expect(overflow.doc, `document horizontal overflow at ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.app, `#app-content overflow at ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.main, `#azc-main-content overflow at ${label}`).toBeLessThanOrEqual(1)
}

/**
 * @param {string} locale
 */
function expectedHtmlLangPrefix(locale) {
	// Nextcloud normalises pt_BR to pt-BR in the <html lang> attribute.
	return locale === 'pt_BR' ? 'pt-br' : locale.toLowerCase()
}

function resetRoleLanguage(role) {
	// The env credential IS the uid — no browser round-trip needed, and a bare
	// context in a hook trips Playwright's trace-chunk ENOENT on close.
	const u = process.env[`NC_${role}_USER`]
	if (u) resetUserLanguage(u)
}

test.beforeAll(() => {
	// Start from a known-language baseline so other specs are not affected.
	resetRoleLanguage('EMPLOYEE')
	resetRoleLanguage('ADMIN')
})

test.afterAll(() => {
	resetRoleLanguage('EMPLOYEE')
	resetRoleLanguage('ADMIN')
})

test.describe('ArbeitszeitCheck locale matrix (employee surfaces)', () => {
	test.setTimeout(300_000)
	test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')

	for (const locale of SUPPORTED_LOCALES) {
		test(`${locale}: employee pages render without raw keys or overflow`, async ({ page }) => {
			await login(page, credsFromEnv('EMPLOYEE'))
			const userId = await getCurrentUserId(page)
			// try/finally: a failed assertion or test timeout must not leak the
			// mutated locale into the next test / sibling specs (hard interrupts
			// are covered by playwright globalTeardown in playwright.config.js).
			try {
				setUserLanguage(userId, locale)

				for (const route of employeeRoutes) {
					await page.goto(route.path, { waitUntil: 'domcontentloaded' })
					await assertArbeitszeitcheckLoaded(page)
					await expect(page.locator(route.ready)).toBeVisible({ timeout: 30_000 })

					const lang = await page.evaluate(() => document.documentElement.lang || '')
					expect(lang.toLowerCase(), `html lang for ${locale}`).toContain(expectedHtmlLangPrefix(locale))

					for (const viewport of viewports) {
						await page.setViewportSize(viewport)
						await expectNoHorizontalOverflow(page, `${locale}/${route.id}@${viewport.label}`)
					}
					await expectNoRawKeys(page)
				}
			} finally {
				resetUserLanguage(userId)
			}
		})
	}

	test('reset employee language', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		const userId = await getCurrentUserId(page)
		resetUserLanguage(userId)
	})
})

test.describe('ArbeitszeitCheck locale matrix (admin policy surfaces)', () => {
	test.setTimeout(300_000)
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	for (const locale of SUPPORTED_LOCALES) {
		test(`${locale}: admin pages render without raw keys or overflow`, async ({ page }) => {
			await login(page, credsFromEnv('ADMIN'))
			const userId = await getCurrentUserId(page)
			// Same leak guard as the employee matrix (see above).
			try {
				setUserLanguage(userId, locale)

				for (const route of adminRoutes) {
					await page.goto(route.path, { waitUntil: 'domcontentloaded' })
					await assertArbeitszeitcheckLoaded(page)
					await expect(page.locator(route.ready)).toBeVisible({ timeout: 30_000 })

					const lang = await page.evaluate(() => document.documentElement.lang || '')
					expect(lang.toLowerCase(), `html lang for ${locale}`).toContain(expectedHtmlLangPrefix(locale))

					for (const viewport of viewports) {
						await page.setViewportSize(viewport)
						await expectNoHorizontalOverflow(page, `${locale}/${route.id}@${viewport.label}`)
					}
					await expectNoRawKeys(page)
				}
			} finally {
				resetUserLanguage(userId)
			}
		})
	}

	test('reset admin language', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		const userId = await getCurrentUserId(page)
		resetUserLanguage(userId)
	})
})
