// @ts-check
/**
 * Theme × viewport × WCAG 2.1 AA gauntlet for family surfaces:
 * Get the App, Support & us, and the Help nav footer.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'
import { setUserTheme, USER_THEMES } from './helpers/theming.js'

test.describe.configure({ mode: 'serial' })

const overflowViewports = [
	{ width: 320, height: 640 },
	{ width: 375, height: 812 },
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
		const main = document.getElementById('azc-main-content')
		return {
			doc: doc.scrollWidth - doc.clientWidth,
			app: app ? app.scrollWidth - app.clientWidth : 0,
			main: main ? main.scrollWidth - main.clientWidth : 0,
		}
	})
	expect(overflow.doc, `document overflow at ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.app, `#app-content overflow at ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.main, `#azc-main-content overflow at ${label}`).toBeLessThanOrEqual(1)
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertThemeTokensResolved(page) {
	const tokens = await page.evaluate(() => {
		const bodyCs = getComputedStyle(document.body)
		return {
			bg: bodyCs.getPropertyValue('--color-main-background').trim(),
			text: bodyCs.getPropertyValue('--color-main-text').trim(),
			primary: bodyCs.getPropertyValue('--color-primary-element').trim(),
			muted: bodyCs.getPropertyValue('--color-text-maxcontrast').trim(),
		}
	})
	expect(tokens.bg, 'theme background').not.toEqual('')
	expect(tokens.text, 'theme text').not.toEqual('')
	expect(tokens.primary, 'primary element').not.toEqual('')
	expect(tokens.muted, 'muted text').not.toEqual('')
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} selector
 */
async function assertTouchTargets(page, selector) {
	const result = await page.evaluate((sel) => {
		const nodes = [...document.querySelectorAll(sel)]
		const undersized = []
		for (const el of nodes) {
			const style = getComputedStyle(el)
			if (style.display === 'none' || style.visibility === 'hidden') continue
			const rect = el.getBoundingClientRect()
			if (rect.width === 0 && rect.height === 0) continue
			const minH = Math.max(rect.height, parseFloat(style.minHeight) || 0)
			const minW = Math.max(rect.width, parseFloat(style.minWidth) || 0)
			if (minH < 44 || minW < 44) {
				undersized.push({
					cls: String(el.className).slice(0, 80),
					w: Math.round(minW),
					h: Math.round(minH),
				})
			}
		}
		return { ok: undersized.length === 0, undersized, count: nodes.length }
	}, selector)
	expect(result.count, `expected nodes for ${selector}`).toBeGreaterThan(0)
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

test.describe('Get the App + Help footer theme × viewport a11y', () => {
	test.setTimeout(240_000)
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	for (const theme of USER_THEMES) {
		test(`${theme}: get-the-app + nav footer`, async ({ page }) => {
			await login(page, credsFromEnv('ADMIN'))
			await page.goto('/apps/arbeitszeitcheck/get-the-app', { waitUntil: 'domcontentloaded' })
			await assertArbeitszeitcheckLoaded(page)
			await expect(page.locator('.azc-get-app-page')).toBeVisible({ timeout: 30_000 })
			await setUserTheme(page, theme)
			await expect(page.locator('.azc-get-app-page')).toBeVisible({ timeout: 30_000 })
			await assertThemeTokensResolved(page)

			const footer = page.locator('#azc-nav-footer')
			await expect(footer).toBeVisible()
			await expect(footer).toHaveAttribute('data-app-feedback', '1')
			await page.locator('#azc-nav-footer .azc-nav-footer__trigger').click()
			await expect(page.locator('#azc-feedback-problem')).toBeVisible()
			await expect(page.locator('#azc-feedback-idea')).toBeVisible()

			const play = page.locator('a.azc-get-app__store-link--primary').first()
			const playColor = await play.evaluate((el) => {
				const s = getComputedStyle(el)
				return { bg: s.backgroundColor, color: s.color }
			})
			expect(playColor.bg).toMatch(/^rgb\(/)
			expect(playColor.bg).not.toMatch(/rgba\(0,\s*0,\s*0,\s*0\)/)

			for (const viewport of overflowViewports) {
				await page.setViewportSize(viewport)
				await expectNoHorizontalOverflow(page, `${theme}/get-the-app@${viewport.width}px`)
			}

			await page.setViewportSize({ width: 320, height: 640 })
			await assertTouchTargets(page, 'a.azc-get-app__store-link, a.azc-get-app__action, #azc-nav-footer .azc-nav-footer__menu-item, #azc-nav-footer .azc-nav-footer__trigger')
			await runAxe(page, `${theme}/get-the-app@320`)

			await page.setViewportSize({ width: 1280, height: 800 })
			await runAxe(page, `${theme}/get-the-app@1280`)
		})
	}
})

test.describe('Support & us theme × viewport a11y', () => {
	test.setTimeout(240_000)
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	for (const theme of USER_THEMES) {
		test(`${theme}: admin support-us`, async ({ page }) => {
			await login(page, credsFromEnv('ADMIN'))
			await page.goto('/apps/arbeitszeitcheck/admin/support-us', { waitUntil: 'domcontentloaded' })
			await assertArbeitszeitcheckLoaded(page)
			await expect(page.locator('#azc-support-us')).toBeVisible({ timeout: 30_000 })
			await setUserTheme(page, theme)
			await expect(page.locator('#azc-support-us')).toBeVisible({ timeout: 30_000 })
			await assertThemeTokensResolved(page)

			await expect(page.locator('#azc-support-us')).toHaveAttribute('data-support-us-presentation', 'page')
			await expect(page.locator('.azc-support-us__option-title')).toHaveCount(3)
			await expect(page.locator('#azc-support-us-secondary-label')).toBeVisible()

			const partner = page.locator('a.azc-support-us__cta--primary')
			await expect(partner).toBeVisible()
			const chrome = await partner.evaluate((el) => {
				const s = getComputedStyle(el)
				return {
					bg: s.backgroundColor,
					color: s.color,
					minHeight: parseFloat(s.minHeight),
					outline: s.outlineStyle,
				}
			})
			expect(chrome.bg).toMatch(/^rgb\(/)
			expect(chrome.minHeight).toBeGreaterThanOrEqual(44)

			await partner.focus()
			await expect(partner).toBeFocused()

			for (const viewport of overflowViewports) {
				await page.setViewportSize(viewport)
				await expectNoHorizontalOverflow(page, `${theme}/support-us@${viewport.width}px`)
			}

			await page.setViewportSize({ width: 320, height: 640 })
			await assertTouchTargets(
				page,
				'#azc-support-us a.azc-support-us__cta, #azc-nav-footer .azc-nav-footer__menu-item, #azc-nav-footer .azc-nav-footer__trigger',
			)
			await runAxe(page, `${theme}/support-us@320`)
		})
	}
})
