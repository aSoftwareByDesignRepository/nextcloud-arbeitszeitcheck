// @ts-check
// L10N-08: exception messages serialized into API error payloads must
// honor the caller's user language (cataloged → localized, uncataloged →
// verbatim pass-through). Proves the wire boundary end-to-end against the
// live instance; catalog values are read from l10n/*.json so the spec
// never hardcodes a translation.
import { test, expect } from '@playwright/test'
import { readFileSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { login } from './helpers/auth.js'
import { apiAllowFailure } from './helpers/api.js'
import { getCurrentUserId, setUserLanguage, resetUserLanguage } from './helpers/locale.js'

const APP = '/apps/arbeitszeitcheck'
const EN_SOURCE = 'Month must be between 1 and 12.'
const PROBE_URL = `${APP}/api/admin/overtime-payouts?year=2026&month=13`

function catalogValue(locale, msgid) {
	const catalogPath = resolve(
		dirname(fileURLToPath(import.meta.url)),
		`../../l10n/${locale}.json`,
	)
	const catalog = JSON.parse(readFileSync(catalogPath, 'utf8'))
	return catalog.translations?.[msgid] ?? null
}

test.describe('API error l10n wire boundary', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	test('cataloged exception message arrives in the user language', async ({ page }) => {
		const german = catalogValue('de', EN_SOURCE)
		test.skip(
			!german || german === EN_SOURCE,
			'DE catalog lacks a distinct translation for the probe message',
		)

		await login(page, {
			username: process.env.NC_ADMIN_USER,
			password: process.env.NC_ADMIN_PASS,
		})
		const userId = await getCurrentUserId(page)

		try {
			setUserLanguage(userId, 'de')
			const deRes = await apiAllowFailure(page, 'GET', PROBE_URL)
			expect(deRes.status).toBe(400)
			expect(deRes.json?.success).toBe(false)
			expect(deRes.json?.error).toBe(german)

			setUserLanguage(userId, 'en')
			const enRes = await apiAllowFailure(page, 'GET', PROBE_URL)
			expect(enRes.status).toBe(400)
			expect(enRes.json?.success).toBe(false)
			expect(enRes.json?.error).toBe(EN_SOURCE)
		} finally {
			resetUserLanguage(userId)
		}
	})
})
