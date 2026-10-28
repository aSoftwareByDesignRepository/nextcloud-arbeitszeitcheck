/**
 * Vacation-to-sick conversion E2E —
 * spec: planning/features/arbeitszeitcheck/vacation-to-sick-conversion-2026-10
 *
 * Manager API flow: seeds an ended approved vacation in a probed free
 * window (shared dev users accumulate absences across runs), converts
 * retroactively → approved sick + CAS guard (stale revision must 409).
 *
 * Owner dialog flow: seeds a RUNNING approved vacation for e2e_substitute
 * (e2e_employee's shared calendar is residue-saturated — a running vacation
 * must span today and would collide), then drives the shared conversion
 * dialog in a separate browser context per role.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'

const APP = '/apps/arbeitszeitcheck'

function ymd(offsetDays) {
	const d = new Date()
	d.setDate(d.getDate() + offsetDays)
	return d.toISOString().slice(0, 10)
}

function shiftYmd(base, days) {
	const d = new Date(base + 'T12:00:00Z')
	d.setUTCDate(d.getUTCDate() + days)
	return d.toISOString().slice(0, 10)
}

function dmy(offsetDays) {
	return ymd(offsetDays).split('-').reverse().join('.')
}

/**
 * Seed an approved vacation via the manager API, probing backwards for a
 * free window — the shared dev accounts accumulate absences across runs.
 */
async function seedApprovedVacation(page, userId, startOffset, endOffset) {
	for (let back = 0; back < 400; back += 7) {
		const res = await apiAllowFailure(page, 'POST', `${APP}/api/manager/employee-absences`, {
			data: {
				userId,
				type: 'vacation',
				startDate: ymd(startOffset - back),
				endDate: ymd(endOffset - back),
				reason: 'e2e sick-conversion seed',
			},
		})
		if (res.ok && res.json?.absence) {
			return res.json.absence
		}
	}
	throw new Error('no free vacation seed window found within 400 days')
}

test.describe('Vacation to sick conversion', () => {
	test.skip(
		!process.env.NC_MANAGER_USER || !process.env.NC_EMPLOYEE_USER,
		'Requires NC_MANAGER_USER/NC_MANAGER_PASS + NC_EMPLOYEE_USER/NC_EMPLOYEE_PASS',
	)

	test('manager API converts an ended vacation into approved sick leave', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto(APP + '/manager')
		const employee = process.env.NC_EMPLOYEE_USER

		const vacation = await seedApprovedVacation(page, employee, -10, -3)
		expect(vacation.status).toBe('approved')

		// Sick range ending on the last vacation day → tail shorten.
		const sickStart = shiftYmd(vacation.startDate, 2)
		const sickEnd = vacation.endDate
		const dry = await api(page, 'POST',
			`${APP}/api/manager/employee-absences/${vacation.id}/convert-to-sick`, {
				data: {
					sickStart, sickEnd,
					expectedUpdatedAt: vacation.updatedAt,
					reason: 'AU e2e',
					dryRun: '1',
				},
			})
		expect(dry.vacation.action).toBe('shorten')
		expect(dry.sick.status).toBe('approved')

		const res = await api(page, 'POST',
			`${APP}/api/manager/employee-absences/${vacation.id}/convert-to-sick`, {
				data: {
					sickStart, sickEnd,
					expectedUpdatedAt: vacation.updatedAt,
					reason: 'AU e2e',
				},
			})
		expect(res.vacation.endDate).toBe(shiftYmd(sickStart, -1))
		expect(res.sick.status).toBe('approved')
		expect(res.sick.type).toBe('sick_leave')

		// CAS: a stale revision must 409, not double-post.
		const stale = await apiAllowFailure(page, 'POST',
			`${APP}/api/manager/employee-absences/${vacation.id}/convert-to-sick`, {
				data: {
					sickStart, sickEnd,
					expectedUpdatedAt: vacation.updatedAt,
				},
			})
		expect(stale.status).toBe(409)
		expect(stale.json.error_code).toBe('revision_stale')
	})

	test('employee dialog converts a running vacation into pending sick leave', async ({ browser }) => {
		const ownerRole = process.env.NC_SUBSTITUTE_USER ? 'SUBSTITUTE' : 'EMPLOYEE'
		const ownerId = process.env[`NC_${ownerRole}_USER`]
		const mgr = await browser.newPage()
		const emp = await browser.newPage()

		// Reuse an existing running approved vacation when present — reruns
		// of this spec leave exactly that residue (a shortened but still
		// running row); re-seeding it would collide with itself.
		await login(mgr, credsFromEnv('MANAGER'))
		await mgr.goto(APP + '/manager')
		const today = ymd(0)
		const list = await apiAllowFailure(mgr, 'GET', `${APP}/api/manager/employee-absences?employeeId=${ownerId}&startDate=${ymd(-90)}&endDate=${ymd(90)}&limit=100`)
		const rows = list.json?.entries ?? []
		let vacation = rows.find(
			(a) => a.type === 'vacation' && a.status === 'approved'
				&& a.startDate <= today && a.endDate >= today && shiftYmd(a.startDate, 2) < a.endDate,
		)
		if (!vacation) {
			// Seed path A: the manager posts an approved vacation directly.
			const direct = await apiAllowFailure(mgr, 'POST', `${APP}/api/manager/employee-absences`, {
				data: {
					userId: ownerId,
					type: 'vacation',
					startDate: ymd(-2),
					endDate: ymd(5),
					reason: 'e2e sick-conversion seed',
				},
			})
			if (direct.ok && direct.json?.absence?.status === 'approved') {
				vacation = direct.json.absence
			} else {
				// Seed path B: the owner files a pending request, manager approves.
				await login(emp, credsFromEnv(ownerRole))
				const req = await apiAllowFailure(emp, 'POST', `${APP}/api/absences`, {
					data: {
						type: 'vacation',
						start_date: ymd(-2),
						end_date: ymd(5),
						reason: 'e2e sick-conversion seed',
					},
				})
				if (req.ok && (req.json.absence?.id ?? req.json.id)) {
					const pendingId = req.json.absence?.id ?? req.json.id
					const appr = await api(mgr, 'POST', `${APP}/api/manager/absences/${pendingId}/approve`, { data: {} })
					vacation = appr.absence ?? appr
				}
			}
		}
		test.skip(!vacation?.id, 'no usable running vacation and all seed paths blocked (ambient absence state)')

		// The owner opens the detail page and converts via the shared dialog.
		await login(emp, credsFromEnv(ownerRole))
		await emp.goto(`${APP}/absences/${vacation.id}`)
		const convertBtn = emp.locator('[data-sick-convert]')
		await expect(convertBtn).toBeVisible()
		await convertBtn.click()

		const dlg = emp.locator('dialog.azc-sick-convert')
		await expect(dlg).toBeVisible()
		await expect(dlg.locator('#sick-convert-title')).toBeVisible()

		// Sick range derived from the ACTUAL vacation dates (tail shorten):
		// the last three vacation days become the sick leave.
		const sickStart = shiftYmd(vacation.endDate, -2)
		const sickEnd = vacation.endDate
		const toDmy = (iso) => iso.split('-').reverse().join('.')

		// Server-side dryRun preview populates once both dates parse.
		await dlg.locator('#sick-convert-start').fill(toDmy(sickStart))
		await dlg.locator('#sick-convert-end').fill(toDmy(sickEnd))
		await expect(dlg.locator('#sick-convert-preview')).not.toBeEmpty({ timeout: 5000 })

		await dlg.locator('#sick-convert-submit').click()
		await emp.waitForLoadState('load')

		// Post-reload the vacation row shows the shortened end date.
		await expect(emp.locator('body')).toContainText(toDmy(shiftYmd(sickStart, -1)))
	})

	test('manager table row converts via shared dialog and passes axe', async ({ browser }) => {
		// Covers the manager-flow dialog path (approved sick, reason field)
		// plus a WCAG scan of the dialog while open.
		const ownerId = process.env.NC_SUBSTITUTE_USER || process.env.NC_EMPLOYEE_USER
		const ctx = await browser.newContext()
		const mgr = await ctx.newPage()
		await login(mgr, credsFromEnv('MANAGER'))
		await mgr.goto(APP + '/manager')
		const today = ymd(0)
		const list = await apiAllowFailure(mgr, 'GET', `${APP}/api/manager/employee-absences?employeeId=${ownerId}&startDate=${ymd(-90)}&endDate=${ymd(90)}&limit=100`)
		const entries = list.json?.entries ?? []
		// Manager flow also covers ended vacations — prefer a running one for
		// realism, fall back to any approved vacation on the account.
		let vacation = entries.find(
			(a) => a.type === 'vacation' && a.status === 'approved' && a.startDate <= today && a.endDate >= today,
		) ?? entries.find((a) => a.type === 'vacation' && a.status === 'approved')
		if (!vacation) {
			vacation = await seedApprovedVacation(mgr, ownerId, -10, -3).catch(() => null)
		}
		test.skip(!vacation?.id, 'no approved vacation available and seed paths blocked')

		await mgr.goto(`${APP}/manager/absences`)
		// The table only loads entries after a date range is chosen.
		await mgr.locator('#employee-absences-start-date-filter').fill(ymd(-90).split('-').reverse().join('.'))
		await mgr.locator('#employee-absences-end-date-filter').fill(ymd(90).split('-').reverse().join('.'))
		await mgr.locator('#employee-absences-submit').click()
		const btn = mgr.locator('[data-sick-convert][data-sick-convert-flow="manager"]').first()
		await expect(btn).toBeAttached({ timeout: 15000 })
		// Row actions reveal on hover (.azc-table-actions pattern) — the td
		// owns pointer events until the row is hovered, like a real user.
		// .azc-table-actions reveals on row hover/focus — the delegated click
		// handler is what is under test; force past the hover gate.
		await btn.dispatchEvent('click')

		const dlg = mgr.locator('dialog.azc-sick-convert')
		await expect(dlg).toBeVisible()
		// Manager flow shows the reason field.
		await expect(dlg.locator('#sick-convert-reason')).toBeVisible()

		// WCAG scan of the open dialog (aa/21aa rule sets) — per theme, since
		// Nextcloud resolves CSS variables from html[data-themes].
		for (const theme of ['', 'dark', 'dark highcontrast']) {
			await mgr.evaluate(
				(t) => document.documentElement.setAttribute('data-themes', t),
				theme,
			)
			const axe = await new AxeBuilder({ page: mgr })
				.include('dialog.azc-sick-convert')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.analyze()
			expect(
				axe.violations,
				`theme "${theme || 'default'}": ` + JSON.stringify(axe.violations, null, 2),
			).toEqual([])
		}

		// <768px: dialog must stay fully usable on a phone viewport — no
		// horizontal overflow, submit still reachable.
		await mgr.setViewportSize({ width: 375, height: 700 })
		const mobileAxe = await new AxeBuilder({ page: mgr })
			.include('dialog.azc-sick-convert')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(
			mobileAxe.violations,
			'375px viewport: ' + JSON.stringify(mobileAxe.violations, null, 2),
		).toEqual([])
		const overflows = await mgr.evaluate(() => {
			const d = document.querySelector('dialog.azc-sick-convert')
			return d ? d.scrollWidth > d.clientWidth + 1 : false
		})
		expect(overflows, 'dialog must not overflow horizontally at 375px').toBe(false)
		await mgr.setViewportSize({ width: 1280, height: 800 })

		const vacEnd = await btn.getAttribute('data-vac-end')
		const sickStart = shiftYmd(vacEnd, -2)
		await dlg.locator('#sick-convert-start').fill(sickStart.split('-').reverse().join('.'))
		await dlg.locator('#sick-convert-end').fill(vacEnd.split('-').reverse().join('.'))
		await dlg.locator('#sick-convert-reason').fill('AU eingegangen')
		await expect(dlg.locator('#sick-convert-preview')).not.toBeEmpty({ timeout: 5000 })
		await dlg.locator('#sick-convert-submit').click()
		await mgr.waitForLoadState('load')
	})
})
