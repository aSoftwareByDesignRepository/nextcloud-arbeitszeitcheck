// @ts-check
/**
 * ATLAS web_api durable-CRUD sweep — Nextcloud web layer (POLICY 3.5.12/3.5.14).
 *
 * Every mutation is followed by a persisted-state re-read (GET list/show or a
 * second idempotent write that must answer `unchanged`). Resources use unique
 * per-run identifiers and are deleted again so parallel workers do not collide
 * on shared fixtures.
 *
 * Serialised via STATEFUL_SPECS in playwright.config.js — it mutates shared
 * admin entities (teams, models, holidays, kiosk credentials).
 */
import { test, expect } from '@playwright/test'
import { login, loginAs, credsFromEnv, gotoApp } from './helpers/auth.js'
import { api, apiAllowFailure, getRequestToken } from './helpers/api.js'
import { applyLabLicense } from './helpers/license.js'

test.describe.configure({ mode: 'serial' })

const APP = '/apps/arbeitszeitcheck'
const UNIQ = `azc${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
const EMPLOYEE_UID = process.env.NC_EMPLOYEE_USER || 'e2e_employee'
const MANAGER_UID = process.env.NC_MANAGER_USER || 'e2e_manager'

/** Far-future closed window so vacation-layer upserts never auto-trim real rows. */
const FUTURE_FROM = '2099-06-01'
const FUTURE_TO = '2099-06-30'

let shared = { teamId: 0, wtmId: 0 }

test.describe('ATLAS durable CRUD (web)', () => {
	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/teams`)

		const team = await api(page, 'POST', `${APP}/api/admin/teams`, {
			data: { name: `Atlas CRUD ${UNIQ}`, sortOrder: 0 },
		})
		expect(team.success).toBe(true)
		shared.teamId = Number(team.team?.id)
		expect(shared.teamId).toBeGreaterThan(0)

		const wtm = await api(page, 'POST', `${APP}/api/admin/working-time-models`, {
			data: {
				name: `Atlas WTM ${UNIQ}`,
				description: 'durable-CRUD probe',
				weeklyHours: 30,
				dailyHours: 6,
				workDaysPerWeek: 5,
				isDefault: false,
			},
		})
		expect(wtm.success).toBe(true)
		shared.wtmId = Number(wtm.model?.id)
		expect(shared.wtmId).toBeGreaterThan(0)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/teams`)
		if (shared.teamId) {
			await apiAllowFailure(page, 'DELETE', `${APP}/api/admin/teams/${shared.teamId}`)
		}
		if (shared.wtmId) {
			await apiAllowFailure(page, 'DELETE', `${APP}/api/admin/working-time-models/${shared.wtmId}`)
		}
		await page.close()
	})

	test('team: create -> list -> update -> members/managers add+remove -> delete -> gone', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/teams`)

		// fixture team already created in beforeAll — prove read_or_list durable
		const list = await api(page, 'GET', `${APP}/api/admin/teams`)
		const listed = (list.teams || list.data || []).find((t) => Number(t.id) === shared.teamId)
		expect(listed, 'created team must appear in GET teams').toBeTruthy()

		// update + re-read
		const renamed = `Atlas CRUD ${UNIQ} renamed`
		const upd = await api(page, 'PUT', `${APP}/api/admin/teams/${shared.teamId}`, {
			data: { name: renamed, sortOrder: 3 },
		})
		expect(upd.success).toBe(true)
		const list2 = await api(page, 'GET', `${APP}/api/admin/teams`)
		const after = (list2.teams || list2.data || []).find((t) => Number(t.id) === shared.teamId)
		expect(after?.name).toBe(renamed)

		// membership create/read/delete
		const addMember = await api(page, 'POST', `${APP}/api/admin/teams/${shared.teamId}/members`, {
			data: { userId: EMPLOYEE_UID },
		})
		expect(addMember.success).toBe(true)
		const members = await api(page, 'GET', `${APP}/api/admin/teams/${shared.teamId}/members`)
		expect(JSON.stringify(members)).toContain(EMPLOYEE_UID)
		await api(page, 'DELETE', `${APP}/api/admin/teams/${shared.teamId}/members/${encodeURIComponent(EMPLOYEE_UID)}`)
		const members2 = await api(page, 'GET', `${APP}/api/admin/teams/${shared.teamId}/members`)
		expect(JSON.stringify(members2)).not.toContain(`"${EMPLOYEE_UID}"`)

		const addManager = await api(page, 'POST', `${APP}/api/admin/teams/${shared.teamId}/managers`, {
			data: { userId: MANAGER_UID },
		})
		expect(addManager.success).toBe(true)
		const managers = await api(page, 'GET', `${APP}/api/admin/teams/${shared.teamId}/managers`)
		expect(JSON.stringify(managers)).toContain(MANAGER_UID)
		await api(page, 'DELETE', `${APP}/api/admin/teams/${shared.teamId}/managers/${encodeURIComponent(MANAGER_UID)}`)
		const managers2 = await api(page, 'GET', `${APP}/api/admin/teams/${shared.teamId}/managers`)
		expect(JSON.stringify(managers2)).not.toContain(`"${MANAGER_UID}"`)
	})

	test('team: delete persists (dedicated throwaway team)', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/teams`)
		const created = await api(page, 'POST', `${APP}/api/admin/teams`, {
			data: { name: `Atlas Delete ${UNIQ}`, sortOrder: 0 },
		})
		const id = Number(created.team?.id)
		expect(id).toBeGreaterThan(0)
		await api(page, 'DELETE', `${APP}/api/admin/teams/${id}`)
		const list = await api(page, 'GET', `${APP}/api/admin/teams`)
		expect((list.teams || list.data || []).find((t) => Number(t.id) === id)).toBeFalsy()
	})

	test('working-time-model: create -> list -> update -> delete -> gone', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/working-time-models`)

		// fixture WTM created in beforeAll — read
		const list = await api(page, 'GET', `${APP}/api/admin/working-time-models`)
		const found = (list.models || list.data || []).find((m) => Number(m.id) === shared.wtmId)
		expect(found, 'created WTM must appear in GET list').toBeTruthy()

		const upd = await api(page, 'PUT', `${APP}/api/admin/working-time-models/${shared.wtmId}`, {
			data: { description: `updated ${UNIQ}`, weeklyHours: 32, dailyHours: 6.4, workDaysPerWeek: 5 },
		})
		expect(upd.success).toBe(true)
		const show = await api(page, 'GET', `${APP}/api/admin/working-time-models/${shared.wtmId}`)
		expect(JSON.stringify(show)).toContain(`updated ${UNIQ}`)

		// delete on a dedicated row so the shared fixture survives for other tests
		const tmp = await api(page, 'POST', `${APP}/api/admin/working-time-models`, {
			data: { name: `Atlas WTM-del ${UNIQ}`, weeklyHours: 20, dailyHours: 4, workDaysPerWeek: 5, isDefault: false },
		})
		const tmpId = Number(tmp.model?.id)
		await api(page, 'DELETE', `${APP}/api/admin/working-time-models/${tmpId}`)
		const gone = await apiAllowFailure(page, 'GET', `${APP}/api/admin/working-time-models/${tmpId}`)
		expect(gone.ok === false || gone.json?.success === false).toBe(true)
	})

	test('holidays: company + state create -> list -> delete -> gone', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/holidays`)

		// company holiday (appConfig JSON list)
		const cDate = '2099-03-17'
		const cName = `Atlas Company ${UNIQ}`
		const c = await api(page, 'POST', `${APP}/api/admin/holidays`, {
			data: { date: cDate, name: cName, kind: 'full' },
		})
		expect(c.success).toBe(true)
		const cList = await api(page, 'GET', `${APP}/api/admin/holidays`)
		expect(JSON.stringify(cList)).toContain(cName)
		await api(page, 'DELETE', `${APP}/api/admin/holidays`, { data: { date: cDate } })
		const cList2 = await api(page, 'GET', `${APP}/api/admin/holidays`)
		expect(JSON.stringify(cList2)).not.toContain(cName)

		// state holiday (DB row)
		const sDate = '2099-03-18'
		const sName = `Atlas State ${UNIQ}`
		const s = await api(page, 'POST', `${APP}/api/admin/state-holidays`, {
			data: { state: 'NW', date: sDate, name: sName, kind: 'full', scope: 'company' },
		})
		expect(s.success).toBe(true)
		const sList = await api(page, 'GET', `${APP}/api/admin/state-holidays?state=NW&year=2099`)
		const row = (sList.holidays || sList.data || []).find((h) => h.name === sName)
		expect(row, 'created state holiday must be listed').toBeTruthy()
		await api(page, 'DELETE', `${APP}/api/admin/state-holidays/${row.id}`)
		const sList2 = await api(page, 'GET', `${APP}/api/admin/state-holidays?state=NW&year=2099`)
		expect((sList2.holidays || sList2.data || []).find((h) => h.name === sName)).toBeFalsy()
	})

	test('tariff-rule-set: create draft -> list -> update -> delete -> gone', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/tariff-rules`)

		const code = `ATLAS-${UNIQ}`.toUpperCase()
		const created = await api(page, 'POST', `${APP}/api/admin/tariff-rule-sets`, {
			data: {
				tariffCode: code,
				version: '1',
				jurisdiction: 'DE',
				validFrom: '2099-01-01',
				modules: [
					{
						moduleType: 'base_formula',
						config: { reference_days: 30, reference_week_days: 5, work_days_per_week: 5 },
					},
				],
			},
		})
		expect(created.success).toBe(true)
		const id = Number(created.ruleSetId)
		expect(id).toBeGreaterThan(0)
		try {
			const list = await api(page, 'GET', `${APP}/api/admin/tariff-rule-sets`)
			expect((list.ruleSets || list.data || []).find((r) => Number(r.id) === id)).toBeTruthy()

			const upd = await api(page, 'PUT', `${APP}/api/admin/tariff-rule-sets/${id}`, {
				data: { jurisdiction: 'AT', validTo: '2099-12-31' },
			})
			expect(upd.success).toBe(true)
			const show = await api(page, 'GET', `${APP}/api/admin/tariff-rule-sets/${id}`)
			expect(JSON.stringify(show)).toContain('AT')
		} finally {
			await apiAllowFailure(page, 'DELETE', `${APP}/api/admin/tariff-rule-sets/${id}`)
		}
		const gone = await apiAllowFailure(page, 'GET', `${APP}/api/admin/tariff-rule-sets/${id}`)
		expect(gone.ok === false || gone.json?.success === false).toBe(true)
	})

	test('vacation layers: org/model/team defaults + user assignment persist', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/vacation-layers`)

		const readLayers = async () => api(page, 'GET', `${APP}/api/admin/vacation-layers`)
		const layerRows = (l) => [
			...(l.org?.history || []),
			...(l.org?.active ? [l.org.active] : []),
			...(l.model?.defaults || []),
			...(l.team?.policies || []),
		]

		// L0 org default — far-future closed range, then delete again
		const org = await api(page, 'POST', `${APP}/api/admin/vacation-layers/org`, {
			data: { vacationMode: 'manual_fixed', manualDays: 25, effectiveFrom: FUTURE_FROM, effectiveTo: FUTURE_TO, description: UNIQ },
		})
		expect(org.success).toBe(true)
		const orgRows = layerRows(await readLayers())
		expect(orgRows.find((r) => r.description === UNIQ), 'org default must be listed after create').toBeTruthy()
		const orgId = Number(org.id ?? org.data?.id ?? org.default?.id ?? org.summary?.id ?? orgRows.find((r) => r.description === UNIQ)?.id)
		expect(orgId, `org layer id in ${JSON.stringify(org)}`).toBeGreaterThan(0)
		await api(page, 'DELETE', `${APP}/api/admin/vacation-layers/org/${orgId}`)

		// L1 model default on the fixture WTM
		const model = await api(page, 'POST', `${APP}/api/admin/vacation-layers/model`, {
			data: { workingTimeModelId: shared.wtmId, vacationMode: 'manual_fixed', manualDays: 26, effectiveFrom: FUTURE_FROM, effectiveTo: FUTURE_TO, description: UNIQ },
		})
		expect(model.success).toBe(true)
		const modelRows = layerRows(await readLayers())
		const modelId = Number(model.id ?? model.data?.id ?? model.default?.id ?? model.summary?.id ?? modelRows.find((r) => r.description === UNIQ)?.id)
		expect(modelId, `model layer id in ${JSON.stringify(model)}`).toBeGreaterThan(0)
		await api(page, 'DELETE', `${APP}/api/admin/vacation-layers/model/${modelId}`)

		// L2 team policy on the fixture team
		const team = await api(page, 'POST', `${APP}/api/admin/vacation-layers/team`, {
			data: { teamId: shared.teamId, vacationMode: 'manual_fixed', manualDays: 27, effectiveFrom: FUTURE_FROM, effectiveTo: FUTURE_TO, description: UNIQ },
		})
		expect(team.success).toBe(true)
		const teamRows = layerRows(await readLayers())
		const teamPolicyId = Number(team.id ?? team.data?.id ?? team.policy?.id ?? team.summary?.id ?? teamRows.find((r) => r.description === UNIQ)?.id)
		expect(teamPolicyId, `team layer id in ${JSON.stringify(team)}`).toBeGreaterThan(0)
		await api(page, 'DELETE', `${APP}/api/admin/vacation-layers/team/${teamPolicyId}`)

		const finalRows = layerRows(await readLayers())
		expect(finalRows.find((r) => r.description === UNIQ), 'all layer rows must be deleted').toBeFalsy()

		// user-level vacation policy assignment — idempotent second write must
		// answer `unchanged`, proving the first write persisted.
		const assign = await api(page, 'PUT', `${APP}/api/admin/users/${encodeURIComponent(EMPLOYEE_UID)}/vacation-policy`, {
			data: { vacationMode: 'manual_fixed', manualDays: 24, effectiveFrom: FUTURE_FROM, effectiveTo: FUTURE_TO, overrideReason: `atlas ${UNIQ}` },
		})
		expect(assign.success).toBe(true)
		const again = await api(page, 'PUT', `${APP}/api/admin/users/${encodeURIComponent(EMPLOYEE_UID)}/vacation-policy`, {
			data: { vacationMode: 'manual_fixed', manualDays: 24, effectiveFrom: FUTURE_FROM, effectiveTo: FUTURE_TO, overrideReason: `atlas ${UNIQ}` },
		})
		expect(again.success).toBe(true)
		expect(again.unchanged === true || again.policyId === assign.policyId).toBe(true)
		// close the future-dated window again so it never resolves for real dates
		await apiAllowFailure(page, 'PUT', `${APP}/api/admin/users/${encodeURIComponent(EMPLOYEE_UID)}/vacation-policy`, {
			data: { vacationMode: 'inherit', effectiveFrom: FUTURE_FROM, effectiveTo: FUTURE_TO, overrideReason: `atlas cleanup ${UNIQ}` },
		})
	})

	test('overtime-adjustment: create -> audited list re-read', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/users`)

		const created = await api(page, 'POST', `${APP}/api/admin/users/${encodeURIComponent(EMPLOYEE_UID)}/overtime-adjustments`, {
			data: { hoursDelta: 0.5, reasonCode: 'custom', note: `atlas ${UNIQ}` },
		})
		expect(created.success).toBe(true)
		const list = await api(page, 'GET', `${APP}/api/admin/users/${encodeURIComponent(EMPLOYEE_UID)}/overtime-adjustments?limit=20`)
		expect(JSON.stringify(list)).toContain(UNIQ)
		// append-only ledger: compensate the delta back to neutral
		const undo = await api(page, 'POST', `${APP}/api/admin/users/${encodeURIComponent(EMPLOYEE_UID)}/overtime-adjustments`, {
			data: { hoursDelta: -0.5, reasonCode: 'custom', note: `atlas undo ${UNIQ}` },
		})
		expect(undo.success).toBe(true)
	})

	test('kiosk terminal + credentials: create -> read -> revoke/delete', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/kiosk`)

		// Terminal creation requires a license with terminalDevices — apply the
		// lab AZC2 bundle (signed with the vendor key the dev container trusts)
		// only when no license is active, and restore that state afterwards.
		const licStatus = await apiAllowFailure(page, 'GET', `${APP}/api/admin/license/status`)
		const hadLicense = Boolean(licStatus.json?.data?.active ?? licStatus.json?.active ?? licStatus.json?.licensed ?? licStatus.json?.data?.licensed)
		if (!hadLicense) {
			const lic = await applyLabLicense(page, apiAllowFailure)
			expect(lic.ok, `license apply for kiosk CRUD failed: ${JSON.stringify(lic.res?.json)}`).toBe(true)
		}

		// Kiosk must be enabled globally and the employee must carry the
		// per-user kiosk-allowed flag — the API rejects otherwise with
		// KIOSK_USER_NOT_ALLOWED (assertUserKioskAllowed).
		const wasEnabled = await page.locator('#azc-kiosk-enabled').isChecked()
		if (!wasEnabled) {
			const en = await api(page, 'POST', `${APP}/api/admin/kiosk/enabled`, { data: { enabled: true } })
			expect(en.success).toBe(true)
		}
		const allow = await api(page, 'PUT', `${APP}/api/admin/kiosk/users/${encodeURIComponent(EMPLOYEE_UID)}/allowed`, {
			data: { kioskAllowed: true },
		})
		expect(allow.success).toBe(true)

		const label = `Atlas Terminal ${UNIQ}`
		const term = await api(page, 'POST', `${APP}/api/admin/kiosk/terminals`, {
			data: { label },
		})
		expect(term.success).toBe(true)
		const terminalId = term.data?.terminalId
		const pairingCode = term.data?.pairingCode
		expect(terminalId).toBeTruthy()
		expect(pairingCode).toBeTruthy()

		// New terminals start `pending`; only a paired (active) tablet may run
		// enrollments — exercise the real public pairing endpoint.
		const paired = await api(page, 'POST', `${APP}/api/kiosk/pair`, {
			data: { pairingCode, label: `atlas-paired ${UNIQ}` },
		})
		expect(paired.success).toBe(true)
		expect(paired.data?.terminalId).toBe(terminalId)

		const credIds = []
		try {
			// PIN credential for the fixture employee
			const pin = await api(page, 'POST', `${APP}/api/admin/kiosk/credentials/pin/generate`, {
				data: { userId: EMPLOYEE_UID },
			})
			expect(pin.success).toBe(true)
			const creds = await api(page, 'GET', `${APP}/api/admin/kiosk/credentials?userId=${encodeURIComponent(EMPLOYEE_UID)}`)
			const pinRow = (creds.data?.credentials || []).find((c) => c.hasPin)
			expect(pinRow, 'generated PIN must appear in credential list').toBeTruthy()
			credIds.push(pinRow.id)

			// RFID credential
			const rfid = await api(page, 'POST', `${APP}/api/admin/kiosk/credentials/rfid`, {
				data: { userId: EMPLOYEE_UID, rfidUid: `RFID-${UNIQ}`, label: `atlas ${UNIQ}` },
			})
			expect(rfid.success).toBe(true)
			const creds2 = await api(page, 'GET', `${APP}/api/admin/kiosk/credentials?userId=${encodeURIComponent(EMPLOYEE_UID)}`)
			const rfidRow = (creds2.data?.credentials || []).find((c) => c.hasRfid)
			expect(rfidRow, 'assigned RFID must appear in credential list').toBeTruthy()
			credIds.push(rfidRow.id)

			// enrollment start -> status -> cancel
			const enr = await api(page, 'POST', `${APP}/api/admin/kiosk/enrollment/start`, {
				data: { userId: EMPLOYEE_UID, terminalId },
			})
			expect(enr.success).toBe(true)
			const status = await api(page, 'GET', `${APP}/api/admin/kiosk/enrollment/status?terminalId=${encodeURIComponent(terminalId)}`)
			expect(JSON.stringify(status)).not.toBe('{}')
			const cancel = await api(page, 'POST', `${APP}/api/admin/kiosk/enrollment/cancel`, {
				data: { terminalId },
			})
			expect(cancel.success).toBe(true)
		} finally {
			for (const id of credIds) {
				const del = await api(page, 'DELETE', `${APP}/api/admin/kiosk/credentials/${id}`)
				expect(del.success).toBe(true)
			}
			const revoke = await api(page, 'POST', `${APP}/api/admin/kiosk/terminals/${encodeURIComponent(terminalId)}/revoke`)
			expect(revoke.success).toBe(true)
			// restore pre-test kiosk state
			await api(page, 'PUT', `${APP}/api/admin/kiosk/users/${encodeURIComponent(EMPLOYEE_UID)}/allowed`, {
				data: { kioskAllowed: false },
			})
			if (!wasEnabled) {
				await api(page, 'POST', `${APP}/api/admin/kiosk/enabled`, { data: { enabled: false } })
			}
			if (!hadLicense) {
				await apiAllowFailure(page, 'DELETE', `${APP}/api/admin/license`, { data: {} })
			}
		}
		const credsAfter = await api(page, 'GET', `${APP}/api/admin/kiosk/credentials?userId=${encodeURIComponent(EMPLOYEE_UID)}`)
		expect(JSON.stringify(credsAfter)).not.toContain(UNIQ)
	})

	test('outlook-ical subscription token: create -> list -> rotate persists new token', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/settings`)

		const created = await apiAllowFailure(page, 'POST', `${APP}/api/admin/outlook-ical/create`, {
			data: { teamId: 0, languageCode: 'de' },
		})
		let subId = created.json?.subscriptionId ?? created.json?.data?.subscriptionId
		let token1 = created.json?.token ?? created.json?.feedUrl
		if (!created.ok || !created.json?.success) {
			// repeat run: duplicate scope must be rejected — resolve the
			// existing org-wide subscription row instead (repeat/deny proof).
			expect(created.json?.success).toBe(false)
			const existing = await api(page, 'GET', `${APP}/api/admin/outlook-ical/active-subscriptions`)
			const row = (existing.subscriptions ?? existing.data?.subscriptions ?? [])
				.find((s) => s.orgWide && s.feedLanguageCode === 'de')
			expect(row, 'existing org-wide de subscription must be listable').toBeTruthy()
			subId = row.id
			token1 = row.feedUrl ?? null
		}
		expect(subId).toBeTruthy()

		const list = await api(page, 'GET', `${APP}/api/admin/outlook-ical/active-subscriptions`)
		expect(JSON.stringify(list)).toContain(String(subId))

		const rotated = await api(page, 'POST', `${APP}/api/admin/outlook-ical/rotate`, {
			data: { teamId: 0, languageCode: 'de' },
		})
		expect(rotated.success).toBe(true)
		// same subscription row, new token material
		expect(rotated.subscriptionId).toBe(subId)
		expect(rotated.token ?? rotated.feedUrl).not.toBe(token1)
	})

	test('employee time-entry: create -> list -> update -> delete -> gone (owner context)', async ({ page, browser }) => {
		// Owner edit/delete is only possible for non-approved, non-pending
		// entries inside the edit window (TimeEntry::canEdit/canDelete).
		// manual_time_entries_require_approval is ON in this environment, so a
		// manual create lands in pending_approval where both PUT and DELETE are
		// correctly blocked. Toggle the gate off for this test, restore after.
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/settings`)
		const settings = await api(page, 'GET', `${APP}/api/admin/settings`)
		const approvalWasOn = settings.settings?.manualTimeEntriesRequireApproval === true
		if (approvalWasOn) {
			const off = await api(page, 'POST', `${APP}/api/admin/settings`, {
				data: { manualTimeEntriesRequireApproval: false },
			})
			expect(off.success).toBe(true)
			const verify = await api(page, 'GET', `${APP}/api/admin/settings`)
			expect(
				verify.settings?.manualTimeEntriesRequireApproval === false,
				'approval gate must be off after settings POST'
			).toBe(true)
		}

		const emp = await browser.newPage()
		let entryId = null
		try {
			await login(emp, credsFromEnv('EMPLOYEE'))
			await gotoApp(emp, `${APP}/time-entries`)

			// find a free slot inside the 14-day edit window — overlap is
			// interval-based, so a late-evening slot on a recent day works even
			// when the employee already clocked that day
			const SLOTS = [['22:30', '23:15'], ['20:30', '21:15'], ['18:30', '19:15']]
			let created = null
			let createdDate = null
			for (let i = 0; i < 14 && !created; i++) {
				const d = new Date()
				d.setUTCDate(d.getUTCDate() - i)
				const date = d.toISOString().slice(0, 10)
				for (const [startTime, endTime] of SLOTS) {
					const res = await apiAllowFailure(emp, 'POST', `${APP}/api/time-entries`, {
						data: { date, startTime, endTime, description: `atlas crud ${UNIQ}`, justification: `atlas durable-crud ${UNIQ}` },
					})
					if (res.ok && res.json?.success) {
						created = res.json
						createdDate = date
						break
					}
					const err = String(res.json?.error || res.json?.message || '')
					if (!err.toLowerCase().includes('overlap') && !err.toLowerCase().includes('überschneid')) {
						throw new Error(`time-entry create failed: ${err || res.status}`)
					}
				}
			}
			expect(created?.success, 'no free calendar day for employee time entry').toBe(true)
			entryId = created.entry?.id ?? created.entryId
			expect(entryId).toBeTruthy()

			// the default list window is recent-only — scope it to the created date
			const list = await api(emp, 'GET', `${APP}/api/time-entries?start_date=${createdDate}&end_date=${createdDate}&limit=200`)
			expect(JSON.stringify(list)).toContain(String(entryId))

			const upd = await apiAllowFailure(emp, 'PUT', `${APP}/api/time-entries/${entryId}`, {
				data: { description: `atlas crud updated ${UNIQ}` },
			})
			expect(upd.ok === true || upd.json?.success === true, `update failed: ${JSON.stringify(upd.json)}`).toBe(true)
			const show = await api(emp, 'GET', `${APP}/api/time-entries/${entryId}`)
			expect(JSON.stringify(show)).toContain(`updated ${UNIQ}`)

			const del = await apiAllowFailure(emp, 'DELETE', `${APP}/api/time-entries/${entryId}`)
			expect(del.ok === true || del.json?.success === true, `delete failed: ${JSON.stringify(del.json)}`).toBe(true)
			const gone = await apiAllowFailure(emp, 'GET', `${APP}/api/time-entries/${entryId}`)
			expect(gone.ok === false || gone.json?.success === false).toBe(true)
			entryId = null
		} finally {
			if (entryId !== null) {
				await apiAllowFailure(emp, 'DELETE', `${APP}/api/time-entries/${entryId}`)
			}
			await emp.close()
			if (approvalWasOn) {
				const on = await api(page, 'POST', `${APP}/api/admin/settings`, {
					data: { manualTimeEntriesRequireApproval: true },
				})
				expect(on.success).toBe(true)
			}
		}
	})

	test('employee absence: create -> list -> update -> delete -> gone', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/absences`)

		let absence = null
		for (let i = 200; i < 240; i++) {
			const d = new Date()
			d.setUTCDate(d.getUTCDate() + i)
			// skip weekends
			if (d.getUTCDay() === 0 || d.getUTCDay() === 6) continue
			const date = d.toISOString().slice(0, 10)
			const res = await apiAllowFailure(page, 'POST', `${APP}/api/absences`, {
				data: { type: 'special_leave', start_date: date, end_date: date, reason: `atlas crud ${UNIQ}` },
			})
			if (res.ok && res.json?.success) {
				absence = res.json
				break
			}
			const err = String(res.json?.error || res.json?.message || '')
			if (!/overlap|working day|überschneid|arbeitstag/i.test(err)) {
				throw new Error(`absence create failed: ${err || res.status}`)
			}
		}
		expect(absence?.success, 'no free absence window').toBe(true)
		const absenceId = absence.absence?.id ?? absence.id
		expect(absenceId).toBeTruthy()

		try {
			const list = await api(page, 'GET', `${APP}/api/absences?limit=500`)
			expect(JSON.stringify(list)).toContain(String(absenceId))

			const upd = await apiAllowFailure(page, 'PUT', `${APP}/api/absences/${absenceId}`, {
				data: { reason: `atlas crud updated ${UNIQ}` },
			})
			expect(upd.ok === true || upd.json?.success === true, `absence update failed: ${JSON.stringify(upd.json)}`).toBe(true)
			const show = await api(page, 'GET', `${APP}/api/absences/${absenceId}`)
			expect(JSON.stringify(show)).toContain(`updated ${UNIQ}`)

			const del = await apiAllowFailure(page, 'DELETE', `${APP}/api/absences/${absenceId}`)
			expect(del.ok === true || del.json?.success === true).toBe(true)
			const gone = await apiAllowFailure(page, 'GET', `${APP}/api/absences/${absenceId}`)
			expect(gone.ok === false || gone.json?.success === false).toBe(true)
		} finally {
			await apiAllowFailure(page, 'DELETE', `${APP}/api/absences/${absenceId}`)
		}
	})

	test('correction request -> manager pending list -> approve -> list clean', async ({ page, browser }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/time-entries`)

		let created = null
		for (let i = 60; i < 120; i++) {
			const d = new Date()
			d.setUTCDate(d.getUTCDate() - i)
			const date = d.toISOString().slice(0, 10)
			const res = await apiAllowFailure(page, 'POST', `${APP}/api/time-entries`, {
				data: { date, hours: 1, description: `atlas corr ${UNIQ}`, justification: `atlas corr ${UNIQ}` },
			})
			if (res.ok && res.json?.success) {
				created = res.json
				break
			}
			const err = String(res.json?.error || '')
			const retryable = ['overlap', 'überschneid', 'finalisier', 'finalized']
			if (!retryable.some((k) => err.toLowerCase().includes(k))) {
				throw new Error(`seed time entry failed: ${err || res.status}`)
			}
		}
		expect(created?.success).toBe(true)
		const entryId = created.entry?.id ?? created.entryId

		const mgr = await browser.newPage()
		await login(mgr, credsFromEnv('MANAGER'))
		await gotoApp(mgr, `${APP}/manager`)

		if ((created.entry?.status || created.status) === 'pending_approval') {
			const appr = await apiAllowFailure(mgr, 'POST', `${APP}/api/manager/time-entries/${entryId}/approve-correction`, {
				data: { comment: `atlas seed approve ${UNIQ}` },
			})
			expect(appr.ok).toBe(true)
		}

		try {
			const req = await api(page, 'POST', `${APP}/api/time-entries/${entryId}/request-correction`, {
				data: { justification: `atlas corr reason ${UNIQ}`, newHours: 1.5 },
			})
			expect(req.success).toBe(true)

			const pending = await api(mgr, 'GET', `${APP}/api/manager/pending-time-entry-corrections`)
			expect(JSON.stringify(pending)).toContain(String(entryId))

			const approve = await api(mgr, 'POST', `${APP}/api/manager/time-entries/${entryId}/approve-correction`, {
				data: { comment: `atlas approved ${UNIQ}` },
			})
			expect(approve.success).toBe(true)

			const pendingAfter = await api(mgr, 'GET', `${APP}/api/manager/pending-time-entry-corrections`)
			expect(JSON.stringify(pendingAfter)).not.toContain(`"id":${entryId}`)
		} finally {
			await apiAllowFailure(page, 'POST', `${APP}/api/time-entries/${entryId}/cancel-correction`)
			await apiAllowFailure(page, 'DELETE', `${APP}/api/time-entries/${entryId}`)
			await mgr.close()
		}
	})

	test('audit-log: admin writes above are persisted and queryable', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/audit-log`)
		const logs = await api(page, 'GET', `${APP}/api/admin/audit-logs?entity_type=team&action=team_created&limit=100`)
		expect(JSON.stringify(logs)).toContain('team_created')
	})

	test('month-closure: employee finalize -> finalized list -> reopen -> list clean', async ({ page, browser }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/settings`)

		const before = await api(page, 'GET', `${APP}/api/admin/settings`)
		const wasEnabled = before.settings?.monthClosureEnabled === true
		const approvalWasOn = before.settings?.manualTimeEntriesRequireApproval === true
		const toggles = {}
		if (!wasEnabled) toggles.monthClosureEnabled = true
		// seeded entry must be finalized-deletable afterwards — pending/approved
		// rows are intentionally not owner-deletable, so drop the approval gate
		if (approvalWasOn) toggles.manualTimeEntriesRequireApproval = false
		if (Object.keys(toggles).length) {
			const on = await api(page, 'POST', `${APP}/api/admin/settings`, { data: toggles })
			expect(on.success).toBe(true)
		}

		const emp = await browser.newPage()
		await login(emp, credsFromEnv('EMPLOYEE'))
		await gotoApp(emp, `${APP}/time-entries`)

		// seed one entry in a fully-ended past month for the employee
		const target = new Date()
		target.setUTCMonth(target.getUTCMonth() - 2)
		const year = target.getUTCFullYear()
		const month = target.getUTCMonth() + 1

		// a leftover finalized month from a previous run blocks seeding —
		// reopen is admin-only and needs userId + reason
		await apiAllowFailure(page, 'POST', `${APP}/api/month-closure/reopen`, {
			data: { year, month, userId: EMPLOYEE_UID, reason: `atlas pre-clean ${UNIQ}` },
		})

		let entryId = null
		for (let day = 2; day <= 20 && !entryId; day++) {
			const d = new Date(Date.UTC(year, month - 1, day))
			if (d.getUTCDay() === 0 || d.getUTCDay() === 6) continue
			const date = d.toISOString().slice(0, 10)
			const res = await apiAllowFailure(emp, 'POST', `${APP}/api/time-entries`, {
				data: { date, hours: 1, description: `atlas mc ${UNIQ}`, justification: `atlas mc ${UNIQ}` },
			})
			if (res.ok && res.json?.success) {
				entryId = res.json.entry?.id ?? res.json.entryId
			}
		}
		expect(entryId, 'could not seed a past-month time entry').toBeTruthy()

		// pending four-eyes create must be approved before finalize is allowed
		const mgr = await browser.newPage()
		try {
			const show = await api(emp, 'GET', `${APP}/api/time-entries/${entryId}`)
			if ((show.entry?.status || show.status) === 'pending_approval') {
				await login(mgr, credsFromEnv('MANAGER'))
				await gotoApp(mgr, `${APP}/manager`)
				const appr = await apiAllowFailure(mgr, 'POST', `${APP}/api/manager/time-entries/${entryId}/approve-correction`, {
					data: { comment: `atlas mc approve ${UNIQ}` },
				})
				expect(appr.ok).toBe(true)
			}

			const fin = await apiAllowFailure(emp, 'POST', `${APP}/api/month-closure/finalize`, {
				data: { year, month },
			})
			expect(fin.ok, `finalize failed: ${JSON.stringify(fin.json)}`).toBe(true)
			expect(fin.json?.success).toBe(true)

			const months = await api(emp, 'GET', `${APP}/api/month-closure/finalized-months`)
			const rows = months.months ?? months.data?.months ?? []
			expect(
				rows.some((m) => m.year === year && m.month === month),
				`finalized month ${year}-${month} must be listed: ${JSON.stringify(months)}`
			).toBe(true)

			// reopen is admin-only and requires userId + reason
			const reopen = await apiAllowFailure(page, 'POST', `${APP}/api/month-closure/reopen`, {
				data: { year, month, userId: EMPLOYEE_UID, reason: `atlas reopen ${UNIQ}` },
			})
			expect(reopen.ok, `reopen failed: ${JSON.stringify(reopen.json)}`).toBe(true)
			expect(reopen.json?.success).toBe(true)

			const months2 = await api(emp, 'GET', `${APP}/api/month-closure/finalized-months`)
			const rows2 = months2.months ?? months2.data?.months ?? []
			expect(
				rows2.some((m) => m.year === year && m.month === month),
				`reopened month ${year}-${month} must no longer be listed`
			).toBe(false)
		} finally {
			await apiAllowFailure(page, 'POST', `${APP}/api/month-closure/reopen`, {
				data: { year, month, userId: EMPLOYEE_UID, reason: `atlas cleanup ${UNIQ}` },
			})
			await apiAllowFailure(emp, 'DELETE', `${APP}/api/time-entries/${entryId}`)
			await emp.close()
			await mgr.close()
			const restore = {}
			if (!wasEnabled) restore.monthClosureEnabled = false
			if (approvalWasOn) restore.manualTimeEntriesRequireApproval = true
			if (Object.keys(restore).length) {
				await apiAllowFailure(page, 'POST', `${APP}/api/admin/settings`, { data: restore })
			}
		}
	})

	test('gdpr export: admin can pull a user data export (read path)', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/users`)
		const res = await page.request.fetch(
			new URL(`${APP}/gdpr/export?userId=${encodeURIComponent(EMPLOYEE_UID)}`, page.url()).toString(),
			{ headers: { requesttoken: await getRequestToken(page) } }
		)
		expect(res.status(), `gdpr export failed: ${res.status()}`).toBe(200)
		const body = await res.body()
		expect(body.length, 'gdpr export payload must be non-empty').toBeGreaterThan(0)
	})
})
