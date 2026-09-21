/**
 * @vitest-environment node
 *
 * Admin employee dialog: year-scoped Resturlaub + overtime opening balances
 * must reload on year change, and post-save reload must preserve those years.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

describe('Admin user detail — year-scoped balances', () => {
	const detailJs = readFileSync(path.join(root, 'js/admin-user-detail.js'), 'utf8')
	const l10n = readFileSync(path.join(root, 'templates/partials/admin-user-edit-l10n.php'), 'utf8')
	const service = readFileSync(
		path.join(root, 'lib/Service/AdminUserProfileUpdateService.php'),
		'utf8',
	)
	const controller = readFileSync(
		path.join(root, 'lib/Controller/AdminController.php'),
		'utf8',
	)

	it('binds carryover and overtime year reloads via shared helper', () => {
		expect(detailJs).toContain('function bindYearScopedBalanceReload')
		expect(detailJs).toContain('function bindCarryoverYearReload')
		expect(detailJs).toContain('function bindOvertimeOpeningYearReload')
		expect(detailJs).toContain('bindCarryoverYearReload(user.userId)')
		expect(detailJs).toContain('bindOvertimeOpeningYearReload(user.userId)')
		expect(detailJs).toContain("queryParam: 'carryoverYear'")
		expect(detailJs).toContain("queryParam: 'overtimeOpeningBalanceYear'")
	})

	it('preserves year fields on post-save reload', () => {
		expect(detailJs).toMatch(/loadUserDetail\(userId,\s*\{/)
		expect(detailJs).toContain('carryoverYear: payloads.workingTimeModel')
		expect(detailJs).toContain('overtimeOpeningBalanceYear: payloads.overtime')
		expect(detailJs).toContain("params.set('carryoverYear'")
		expect(detailJs).toContain("params.set('overtimeOpeningBalanceYear'")
	})

	it('l10n includes year-reload and failure strings', () => {
		expect(l10n).toContain('failedToLoadCarryoverForYear')
		expect(l10n).toContain('failedToLoadOvertimeOpeningForYear')
		expect(l10n).toContain('overtimeOpeningYearReloadHelp')
		expect(l10n).toContain('Changing the year loads the stored opening balance')
	})

	it('service skips only assignment update on match (no early return)', () => {
		const needle =
			'workingTimeModelAssignmentMatches($currentModel, $workingTimeModelId, $vacationDaysPerYear, $startDate, $endDate))'
		const pos = service.indexOf(needle)
		expect(pos).toBeGreaterThan(-1)
		const window = service.slice(pos, pos + 280)
		expect(window).toContain('$updated = $currentModel;')
		expect(window).not.toContain("'unchanged' => true")
		expect(window).not.toMatch(/\)\s*\{\s*return\s*\[/)
	})

	it('overtime apply returns the written balance year, not always current year', () => {
		expect(service).toContain('$balanceYear = $year;')
		expect(service).toContain("'overtimeOpeningBalanceYear' => $balanceYear")
		expect(service).not.toMatch(
			/getOpeningBalanceHours\(\$userId,\s*\$currentYear\).*overtimeOpeningBalanceYear.*\$currentYear/s,
		)
	})

	it('GET user resolves overtimeOpeningBalanceYear query param', () => {
		expect(controller).toContain("resolveAdminBalanceYearParam('overtimeOpeningBalanceYear')")
		expect(controller).toContain("resolveAdminBalanceYearParam('carryoverYear')")
	})
})
