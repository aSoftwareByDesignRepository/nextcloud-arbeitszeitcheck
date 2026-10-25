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

	test('localized access-denied classifies 403, not 500 (locale-stable type classification)', async ({ page }) => {
		const denyMsgid =
			'Access denied. You can only view reports for yourself or your team members.'
		const german = catalogValue('de', denyMsgid)
		test.skip(
			!german || german === denyMsgid,
			'DE catalog lacks a distinct translation for the deny message',
		)

		await login(page, {
			username: process.env.NC_ADMIN_USER,
			password: process.env.NC_ADMIN_PASS,
		})
		const userId = await getCurrentUserId(page)
		const ghost = `atlas-ghost-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`

		try {
			// Admin requests a report for a nonexistent/foreign uid — the
			// deny throw is typed (AccessDeniedException), so a de-locale
			// session must still get 403 + the German wire text, never the
			// 500 a message-substring classifier produced before.
			setUserLanguage(userId, 'de')
			const deRes = await apiAllowFailure(
				page,
				'GET',
				`${APP}/api/reports/daily?userId=${encodeURIComponent(ghost)}`,
			)
			expect(deRes.status).toBe(403)
			expect(deRes.json?.success).toBe(false)
			expect(deRes.json?.error).toBe(german)

			setUserLanguage(userId, 'en')
			const enRes = await apiAllowFailure(
				page,
				'GET',
				`${APP}/api/reports/daily?userId=${encodeURIComponent(ghost)}`,
			)
			expect(enRes.status).toBe(403)
			expect(enRes.json?.error).toBe(denyMsgid)
		} finally {
			resetUserLanguage(userId)
		}
	})

	test('localized validation throw classifies 400 + actionable text (locale-stable)', async ({ page }) => {
		const invalidMsgid = 'Invalid %s format. Use Y-m-d (e.g. 2024-01-15)'
		const germanTpl = catalogValue('de', invalidMsgid)
		test.skip(
			!germanTpl || germanTpl === invalidMsgid,
			'DE catalog lacks a distinct translation for the invalid-format message',
		)
		const german = germanTpl.replace('%s', 'date')

		await login(page, {
			username: process.env.NC_ADMIN_USER,
			password: process.env.NC_ADMIN_PASS,
		})
		const userId = await getCurrentUserId(page)

		try {
			setUserLanguage(userId, 'de')
			const deRes = await apiAllowFailure(
				page,
				'GET',
				`${APP}/api/reports/daily?date=not-a-date`,
			)
			expect(deRes.status).toBe(400)
			expect(deRes.json?.success).toBe(false)
			expect(deRes.json?.error).toBe(german)

			setUserLanguage(userId, 'en')
			const enRes = await apiAllowFailure(
				page,
				'GET',
				`${APP}/api/reports/daily?date=not-a-date`,
			)
			expect(enRes.status).toBe(400)
			expect(enRes.json?.error).toBe(invalidMsgid.replace('%s', 'date'))
		} finally {
			resetUserLanguage(userId)
		}
	})

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
