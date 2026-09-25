/* global process */
import { test, expect } from '@playwright/test'
import { execFileSync } from 'child_process'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

const nextcloudRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')

function occ(args) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args,
	], { cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 })
}

async function wireOutlookMocks(page) {
	const sampleIcs = [
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//ArbeitszeitCheck//Outlook iCal//EN',
		'CALSCALE:GREGORIAN',
		'BEGIN:VEVENT',
		'UID:test-1@example.com',
		'DSTART;VALUE=DATE:20260101',
		'DEND;VALUE=DATE:20260102',
		'SUMMARY:Test: Absence',
		'DESCRIPTION:Test',
		'END:VEVENT',
		'END:VCALENDAR',
	].join('\n')

	await page.route('**/feed-*.ics', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'text/calendar; charset=utf-8',
			body: sampleIcs,
		})
	})

	await page.route('**/api/admin/outlook-ical/**', async (route) => {
		const url = route.request().url()
		if (url.includes('/teams/') && url.includes('/managers')) {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					useAppTeams: true,
					managers: [
						{ userId: 'boss', displayName: 'Boss User' },
					],
				}),
			})
			return
		}
		if (url.includes('/teams')) {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					useAppTeams: true,
					teams: [
						{ id: 17, name: 'Support', path: 'HQ / Support' },
					],
				}),
			})
			return
		}
		if (url.includes('/rotate')) {
			await route.continue()
			return
		}
		await route.continue()
	})

	await page.route('**/apps/arbeitszeitcheck/api/outlook-ical/tokenized*', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'text/calendar; charset=utf-8',
			body: [
				'BEGIN:VCALENDAR',
				'BEGIN:VEVENT',
				'SUMMARY:Support Vacation',
				'END:VEVENT',
				'END:VCALENDAR',
			].join('\r\n'),
		})
	})
}

test.describe('Admin calendar subscription UI', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER')

	let previousUseAppTeams = null

	test.beforeAll(() => {
		// `occ` stdout can carry PHP startup noise (e.g. JIT/opcache warnings).
		// The config value is always the last non-empty line.
		previousUseAppTeams = occ(['config:app:get', 'arbeitszeitcheck', 'use_app_teams'])
			.split('\n')
			.map((l) => l.trim())
			.filter((l) => l !== '')
			.pop() ?? ''
	})

	test.beforeEach(() => {
		occ(['config:app:set', 'arbeitszeitcheck', 'use_app_teams', '--value=1'])
	})

	test.afterAll(() => {
		if (previousUseAppTeams === null || previousUseAppTeams === '') {
			occ(['config:app:delete', 'arbeitszeitcheck', 'use_app_teams'])
			return
		}
		occ(['config:app:set', 'arbeitszeitcheck', 'use_app_teams', '--value=' + previousUseAppTeams])
	})

	test('does not show spurious user-search error on single-topic page load', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/settings/outlook-subscription')
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#outlook-ical-subscription')).toBeVisible()
		await expect(page.getByText('User search failed')).toHaveCount(0)
		await expect(page.locator('.toast-message')).toHaveCount(0)
	})

	test('create, copy, and rotate tokenized subscription link', async ({ page }) => {
		// Capture pristine fetch before Nextcloud core patches window.fetch (core-main.js).
		// Playwright page.route only settles reliably against the native implementation.
		await page.addInitScript(() => {
			const nativeFetch = window.fetch.bind(window)
			window.__azcNativeFetch = nativeFetch
			// Keep Playwright-interceptable fetch even if Nextcloud core replaces window.fetch.
			Object.defineProperty(window, 'fetch', {
				configurable: true,
				get() {
					return window.__azcNativeFetch || nativeFetch
				},
				set(_fn) {
					// Ignore core patch — route fulfill needs the pristine implementation.
				},
			})
			Object.defineProperty(navigator, 'clipboard', {
				value: { writeText: async () => {} },
				configurable: true,
			})
		})

		await login(page, credsFromEnv('ADMIN'))
		await wireOutlookMocks(page)

		let createCalls = 0
		let rotateCalls = 0
		let activeSubscriptions = []

		await page.route('**/api/admin/outlook-ical/teams**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					useAppTeams: true,
					teams: [{ id: 17, name: 'Support', path: 'HQ / Support' }],
				}),
			})
		})

		await page.route('**/api/admin/outlook-ical/active-subscriptions**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, subscriptions: activeSubscriptions }),
			})
		})

		await page.route('**/api/admin/outlook-ical/create**', async (route) => {
			createCalls++
			const body = route.request().postDataJSON()
			expect(body.teamId).toBe(17)
			expect(body.languageCode).toBe('de')
			expect(body.managerUserId).toBeUndefined()
			const subscription = {
				id: 101,
				teamId: 17,
				scopeLabel: 'Support',
				scopePath: 'HQ / Support',
				feedLanguageCode: 'de',
				urlAvailable: true,
				eventCount: 4,
				windowStart: '2026-05-19',
				windowEnd: '2027-08-19',
				feedUrl: `/apps/arbeitszeitcheck/api/outlook-ical/tokenized?token=test-create-${createCalls}&teamId=17`,
				feedWebcalUrl: `webcal://localhost:8081/apps/arbeitszeitcheck/api/outlook-ical/tokenized?token=test-create-${createCalls}&teamId=17`,
			}
			activeSubscriptions = [subscription]
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					eventCount: 4,
					subscriptionId: 101,
					feedUrl: subscription.feedUrl,
					feedWebcalUrl: subscription.feedWebcalUrl,
				}),
			})
		})

		await page.route('**/api/admin/outlook-ical/rotate**', async (route) => {
			rotateCalls++
			const body = route.request().postDataJSON()
			expect(body.teamId).toBe(17)
			expect(body.languageCode).toBe('de')
			activeSubscriptions = [{
				...activeSubscriptions[0],
				feedUrl: `/apps/arbeitszeitcheck/api/outlook-ical/tokenized?token=test-rotate-${rotateCalls}&teamId=17`,
				feedWebcalUrl: `webcal://localhost:8081/apps/arbeitszeitcheck/api/outlook-ical/tokenized?token=test-rotate-${rotateCalls}&teamId=17`,
			}]
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					eventCount: 4,
					subscriptionId: 101,
					feedUrl: activeSubscriptions[0].feedUrl,
					feedWebcalUrl: activeSubscriptions[0].feedWebcalUrl,
				}),
			})
		})

		await page.goto('/apps/arbeitszeitcheck/admin/settings/outlook-subscription')
		await assertArbeitszeitcheckLoaded(page)
		await page.evaluate(() => {
			window.ArbeitszeitCheckUtils = window.ArbeitszeitCheckUtils || {}
			window.ArbeitszeitCheckUtils.confirmDestructiveAction = async () => true
		})
		await expect(page.locator('#outlook-ical-subscription')).toBeVisible()
		await expect(page.locator('text=Internal Server Error')).toHaveCount(0)

		await page.locator('#outlookIcalTeamSearch').click()
		await page.locator('#outlookIcalTeamSearch').fill('sup')
		const teamItem = page.locator('#outlookIcalTeamListbox [data-team-index]').first()
		await expect(teamItem).toBeVisible({ timeout: 15000 })
		await teamItem.click()
		await expect(page.locator('#outlookIcalTeamId')).toHaveValue('17')

		await page.locator('#outlookIcalFeedLanguage').selectOption('de')
		await expect(page.locator('#outlookIcalCreateBtn')).toBeEnabled({ timeout: 10000 })
		await page.locator('#outlookIcalCreateBtn').click()

		await expect(page.locator('#outlookIcalSubscriptionsLoading')).toBeHidden({ timeout: 15000 })
		await expect(page.locator('#outlookIcalSubscriptionTable tbody tr')).toBeVisible()
		await expect(page.locator('#outlookIcalFeedUrl-101')).toHaveValue(/token=test-create-1/)
		expect(createCalls).toBe(1)

		await page.locator('.outlook-ical-subscription-table__copy').click()
		await expect(page.locator('#outlookIcalLive')).toContainText(/copied/i)

		const feedUrl1 = await page.locator('#outlookIcalFeedUrl-101').inputValue()
		expect(feedUrl1).toMatch(/token=test-create-1/)

		await page.evaluate(() => {
			window.ArbeitszeitCheckUtils = window.ArbeitszeitCheckUtils || {}
			window.ArbeitszeitCheckUtils.confirmDestructiveAction = async () => true
		})
		await page.locator('.outlook-ical-subscription-table__rotate').click()
		await expect(page.locator('#outlookIcalFeedUrl-101')).toHaveValue(/token=test-rotate-1/)
		expect(rotateCalls).toBe(1)

		const feedUrl2 = await page.locator('#outlookIcalFeedUrl-101').inputValue()
		expect(feedUrl2).toMatch(/token=test-rotate-1/)
		expect(feedUrl2).not.toBe(feedUrl1)
	})

	test('responsive layout stays usable across core breakpoints', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await wireOutlookMocks(page)

		const viewports = [
			{ width: 320, height: 900, columns: '1', buttonDirection: 'column' },
			{ width: 375, height: 900, columns: '1', buttonDirection: 'column' },
			{ width: 414, height: 900, columns: '1', buttonDirection: 'column' },
			{ width: 768, height: 1024, columns: '2', buttonDirection: 'row' },
			{ width: 1024, height: 900, columns: '2', buttonDirection: 'row' },
			{ width: 1280, height: 900, columns: '2', buttonDirection: 'row' },
		]

		for (const viewport of viewports) {
			await page.setViewportSize({ width: viewport.width, height: viewport.height })
			await page.goto('/apps/arbeitszeitcheck/admin/settings/outlook-subscription')
			await assertArbeitszeitcheckLoaded(page)

			await page.locator('#outlookIcalTeamSearch').fill('sup')
			if (viewport.width < 1024) {
				await page.locator('#outlookIcalTeamListbox .user-picker__item').first().click({ timeout: 10000 }).catch(() => {})
				await page.locator('#outlookIcalTeamClear').evaluate((el) => {
					el.hidden = false
				})
				const clearBox = await page.locator('#outlookIcalTeamClear').boundingBox()
				expect(clearBox?.width ?? 0).toBeGreaterThanOrEqual(44)
				expect(clearBox?.height ?? 0).toBeGreaterThanOrEqual(44)
			}

			const overflow = await page.evaluate(() => ({
				doc: document.documentElement.scrollWidth,
				win: window.innerWidth,
			}))
			expect(overflow.doc).toBeLessThanOrEqual(overflow.win)

			const layout = await page.locator('#outlook-ical-subscription').evaluate((section) => {
				const row = section.querySelector('.form-row--2')
				const actions = section.querySelector('.outlook-ical-create__buttons')
				const generate = section.querySelector('#outlookIcalCreateBtn')
				const input = section.querySelector('#outlookIcalTeamSearch')
				const rowColumns = row ? getComputedStyle(row).gridTemplateColumns.split(' ').length : 0
				return {
					rowColumns,
					actionDirection: actions ? getComputedStyle(actions).flexDirection : '',
					generateWidth: generate ? generate.getBoundingClientRect().width : 0,
					inputWidth: input ? input.getBoundingClientRect().width : 0,
				}
			})

			expect(String(layout.rowColumns)).toBe(viewport.columns)
			expect(layout.actionDirection).toBe(viewport.buttonDirection)
			expect(layout.inputWidth).toBeGreaterThan(0)
			if (viewport.width < 768) {
				expect(layout.generateWidth).toBeGreaterThanOrEqual(layout.inputWidth - 4)
			}
		}
	})
})
