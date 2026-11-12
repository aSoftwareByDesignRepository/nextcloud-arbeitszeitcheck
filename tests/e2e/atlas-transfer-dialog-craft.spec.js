// @ts-check
/**
 * Atlas craft: native <dialog> render proof for the config-transfer pack —
 * the donor margin:auto re-sync (native_dialog_margin_reset_uncentered).
 *
 * Drives the REAL import-preview path: a live /export container is fed to
 * the import form (file input + passphrase), the debounced preview call
 * runs, and the report <dialog> opens via showModal(). Captures:
 *   1280px — horizontally + vertically centered over the dimmed backdrop
 *    375px — <=480px media query re-asserts margin:0 (full-bleed sheet)
 * Bounding-box asserts pin the geometry so a missing margin:auto hunk
 * (dialog anchored top-left) fails the leg, not just the screenshot.
 *
 * Ported from deskcheck tests/e2e/atlas-transfer-dialog-craft.spec.js
 * (same synced config-transfer pack).
 */
import { test, expect } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, credsFromEnv } from './helpers/auth.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const OUT = path.resolve(
	__dirname,
	'../../../../../.cursor/atlas-farm-v3/artifacts/arbeitszeitcheck/craft/transfer',
)
const PASS = 'Atlas-Dialog-Craft-2026-11-12!'

async function requestToken(page) {
	return page.evaluate(() => window.OC?.requestToken
		|| document.querySelector('head')?.getAttribute('data-requesttoken') || '')
}

async function shot(page, rel) {
	const file = path.join(OUT, rel)
	await page.screenshot({ path: file })
	const st = fs.statSync(file)
	expect(st.size, `${rel} too small`).toBeGreaterThan(8_000)
	// eslint-disable-next-line no-console
	console.log('craft', file, st.size)
}

test.describe('Atlas transfer dialog craft (margin:auto re-sync)', () => {
	test('import-preview dialog — centered @1280, full-bleed @375', async ({ page }) => {
		test.setTimeout(120_000)
		await login(page, credsFromEnv('ADMIN'))
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.goto('/apps/arbeitszeitcheck/admin/settings/transfer', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#azc-transfer-export-card')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#azc-transfer-file')).toBeAttached({ timeout: 15_000 })

		// Real container from the live export endpoint.
		const origin = new URL(page.url()).origin
		const res = await page.request.post(`${origin}/apps/arbeitszeitcheck/api/admin/transfer/export`, {
			headers: { requesttoken: await requestToken(page) },
			data: { passphrase: PASS },
		})
		expect(res.status()).toBe(200)
		const bytes = await res.body()
		expect(bytes.length).toBeGreaterThan(200)

		await page.setInputFiles('#azc-transfer-file', {
			name: 'dialog-craft.nctransfer',
			mimeType: 'application/octet-stream',
			buffer: bytes,
		})
		await page.locator('#azc-transfer-import-passphrase').fill(PASS)

		const dlg = page.locator('dialog#azc-transfer-report')
		await expect(dlg).toBeVisible({ timeout: 60_000 }) // Argon2id decrypt + preview
		await page.waitForTimeout(400) // top-layer settle

		// Geometry asserts: margin:auto centers the dialog in both axes.
		const vp = page.viewportSize()
		const box = await dlg.boundingBox()
		expect(box, 'dialog has no box').toBeTruthy()
		expect(Math.abs(box.x + box.width / 2 - vp.width / 2), 'dialog not horizontally centered').toBeLessThan(4)
		expect(Math.abs(box.y + box.height / 2 - vp.height / 2), 'dialog not vertically centered').toBeLessThan(24)
		expect(box.y, 'dialog anchored to viewport top (margin:auto missing)').toBeGreaterThan(40)
		await shot(page, 'azc-transfer-dialog-report-1280px.png')

		// <=480px: media query re-asserts margin:0 — full-bleed sheet.
		await page.setViewportSize({ width: 375, height: 720 })
		await page.waitForTimeout(400)
		const box375 = await dlg.boundingBox()
		expect(box375, 'dialog has no box @375').toBeTruthy()
		expect(box375.x, 'dialog not full-bleed left @375').toBeLessThan(8)
		expect(box375.width, 'dialog not full-bleed width @375').toBeGreaterThan(360)
		await shot(page, 'azc-transfer-dialog-report-375px.png')

		// Leave the surface clean for sibling lanes.
		await page.locator('#azc-transfer-cancel').click().catch(() => {})
	})
})
