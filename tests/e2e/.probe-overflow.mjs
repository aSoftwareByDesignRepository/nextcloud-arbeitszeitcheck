import { chromium } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

const browser = await chromium.launch()
const page = await browser.newPage({ baseURL: process.env.NC_BASE_URL || 'http://127.0.0.1:8081', viewport: { width: 768, height: 1024 } })
await login(page, credsFromEnv('ADMIN'))
await page.goto('/apps/arbeitszeitcheck/admin/business-trips')
await assertArbeitszeitcheckLoaded(page)
await page.waitForSelector('#bta-root', { timeout: 30000 })
await page.waitForTimeout(800)
const wide = await page.evaluate(() => {
	const vw = document.documentElement.clientWidth
	const out = []
	document.querySelectorAll('#bta-root *').forEach((el) => {
		const r = el.getBoundingClientRect()
		if (r.right > vw + 1) {
			out.push(`${el.tagName}.${String(el.className).slice(0, 60)} right=${Math.round(r.right)} w=${Math.round(r.width)}`)
		}
	})
	return { vw, hits: out.slice(0, 20) }
})
console.log(JSON.stringify(wide, null, 1))
await browser.close()
