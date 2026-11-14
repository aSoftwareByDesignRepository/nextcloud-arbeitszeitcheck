/**
 * License-button variant armor — computed-style proof.
 *
 * The shared license pack pre-armor painted every
 * .azc-license-btn--{primary,secondary,danger} to the identical NC pale fill
 * (NC core inputs.scss at (0,1,1) out-specifies the pack's lone variant rules).
 * The healed donor (a5af61e4aa) ships !important scope-list armor. This probe
 * asserts the three variants resolve to three different fills live.
 */
import { test, expect } from '@playwright/test'
import { loginAs, gotoApp } from './helpers/auth.js'

const APP = '/apps/arbeitszeitcheck'

test.use({ trace: 'off' })

test('license button variants render distinct fills (armor active)', async ({ page }) => {
	await loginAs(page, 'ADMIN')
	await gotoApp(page, `${APP}/admin/license`)
	await page.waitForSelector('.azc-license-btn', { timeout: 15000 })

	const fills = await page.evaluate(() => {
		const read = (sel) => {
			const el = document.querySelector(sel)
			if (!el) return null
			const cs = getComputedStyle(el)
			return { bg: cs.backgroundColor, color: cs.color }
		}
		return {
			primary: read('.azc-license-btn--primary'),
			secondary: read('.azc-license-btn--secondary'),
			danger: read('.azc-license-btn--danger'),
		}
	})
	expect(fills.primary, 'need a primary button on the page').toBeTruthy()
	expect(fills.secondary, 'need a secondary button on the page').toBeTruthy()
	expect(fills.danger, 'need a danger button on the page').toBeTruthy()

	// Armor active ⇒ three distinct fills (pre-armor all collapsed to the
	// same pale --color-primary-element-light rgb(229,239,245)).
	const set = new Set([fills.primary.bg, fills.secondary.bg, fills.danger.bg])
	expect(set.size, `variant fills must differ: ${JSON.stringify(fills)}`).toBe(3)
})
