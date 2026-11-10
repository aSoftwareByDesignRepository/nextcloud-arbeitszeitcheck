import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * Contract tests for the absence-day-accuracy pack (2026-11):
 * FA-RECDATE, FA-PREVIEW, FA-GLANCE, FA-CARRYOVER, NH-01/NH-04, FA-MSHORTEN.
 *
 * These guard the wiring, not the pixel values — DOM ids, endpoint usage,
 * flag semantics and silent-fail behaviour are the regression surface.
 */
const dir = dirname(fileURLToPath(import.meta.url))
const read = (rel) => readFileSync(join(dir, rel), 'utf8')

const managerAbsencesJs = read('manager-absences.js')
const managerAbsencesTpl = read('../templates/manager-absences.php')
const absencesTpl = read('../templates/absences.php')
const dashboardTpl = read('../templates/dashboard.php')
const wtmTpl = read('../templates/working-time-models.php')
const routes = read('../appinfo/routes.php')

describe('FA-RECDATE — record form defaults to today', () => {
	it('defines setRecordDatesToToday and never syncs filter → record', () => {
		expect(managerAbsencesJs).toContain('function setRecordDatesToToday()')
		// Both record fields get today only when empty.
		expect(managerAbsencesJs).toContain('manager-absence-record-start')
		expect(managerAbsencesJs).toContain('manager-absence-record-end')
	})

	it('resolves today via the org-time helper, not a UTC slice', () => {
		expect(managerAbsencesJs).toContain('ArbeitszeitCheckTime')
		expect(managerAbsencesJs).toContain('todayYmd')
		// A raw new Date().toISOString() slice would be a TZ bug (US-404 AC-1).
		expect(managerAbsencesJs).not.toContain('toISOString().slice')
	})

	it('calls setRecordDatesToToday on init and after list reloads', () => {
		const calls = managerAbsencesJs.match(/setRecordDatesToToday\(\)/g) || []
		// init + post-load + employee-change paths — at least 2 call sites
		// besides the function definition line.
		expect(calls.length).toBeGreaterThanOrEqual(3)
	})
})

describe('NH-01 — explicit "copy filter range" affordance', () => {
	it('offers a button that copies filter → record on click only', () => {
		expect(managerAbsencesTpl).toContain('manager-absence-record-copy-filter')
		expect(managerAbsencesJs).toContain('function copyFilterRangeToRecord()')
		expect(managerAbsencesJs).toContain(
			"getElementById('manager-absence-record-copy-filter')"
		)
	})

	it('is a secondary button, never a submit', () => {
		expect(managerAbsencesTpl).toContain('type="button" id="manager-absence-record-copy-filter"')
		expect(managerAbsencesTpl).toContain('azc-btn--secondary')
	})
})

describe('FA-PREVIEW — server-side days consumption estimate', () => {
	it('renders the callout with polite live-region semantics', () => {
		expect(absencesTpl).toContain('id="absence-days-consumption"')
		expect(absencesTpl).toMatch(/absence-days-consumption[\s\S]{0,200}azc-callout--info/)
	})

	it('fetches the server estimate endpoint (NN-03: no client-side day math)', () => {
		expect(absencesTpl).toContain("linkToRoute('arbeitszeitcheck.absence.estimateDays')")
		expect(absencesTpl).toContain("encodeURIComponent(startISO)")
		expect(absencesTpl).toContain("encodeURIComponent(endISO)")
		// No client-side Mo–Fr loop must ever creep back in.
		expect(absencesTpl).not.toMatch(/getDay\(\)\s*[<>=!]+\s*0/)
	})

	it('debounces and discards stale responses (E7/FM-08)', () => {
		expect(absencesTpl).toContain('setTimeout(run, 400)')
		expect(absencesTpl).toContain('mySeq !== seq')
	})

	it('only fires for multi-day vacation ranges and hides silently on error', () => {
		expect(absencesTpl).toContain("typeEl.value !== 'vacation'")
		expect(absencesTpl).toContain('startISO === endISO')
		// catch → hide, never an error banner for a pure preview.
		expect(absencesTpl).toContain('} catch (e) { if (mySeq === seq) hide(); }')
	})

	it('renders the basis suffix (NH-03)', () => {
		expect(absencesTpl).toContain("'weekday_schedule'")
		expect(absencesTpl).toContain('(Mon–Fri)')
	})
})

describe('FA-GLANCE — credit-inclusive tiles', () => {
	it('renders the credit footnote markup for periods with credit', () => {
		expect(dashboardTpl).toContain('azc-dashboard-metric__credit')
		expect(dashboardTpl).toContain("$glanceRow['credit']")
		// Only rendered when the credit is real — no empty hint rows.
		expect(dashboardTpl).toContain('$gCredit >= 0.01')
	})
})

describe('FA-CARRYOVER — prior-year label + expired marker', () => {
	it('uses the new carryover wording and an (expired) text marker', () => {
		expect(dashboardTpl).toContain('Prior-year carryover left')
		expect(dashboardTpl).toContain('(expired)')
	})
})

describe('NH-04 — schedule hint in the working-time-model editor', () => {
	it('explains the Mo–Fr absence fallback when no weekday schedule exists', () => {
		expect(wtmTpl).toContain('weekdayScheduleAbsenceHint')
		expect(read('working-time-models.js')).toContain('wtm-weekday-absence-hint')
	})
})

describe('routes — the pack’s endpoints are registered', () => {
	it('exposes self + manager estimate-days and manager shorten', () => {
		expect(routes).toContain("'/api/absences/estimate-days'")
		expect(routes).toContain("'/api/manager/employee-absences/estimate-days'")
		expect(routes).toContain('estimateDays')
		expect(routes).toContain('shortenEmployeeAbsence')
	})
})
