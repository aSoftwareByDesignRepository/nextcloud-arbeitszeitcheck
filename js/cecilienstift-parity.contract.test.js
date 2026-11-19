import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * Contract tests for the cecilienstift payroll parity pack (2026-11):
 * export type/status filters, personalnummer column, comp-day estimate,
 * capability advertisement. These guard the wiring — removing a needle
 * breaks the payroll handoff the customer pays for.
 */
const dir = dirname(fileURLToPath(import.meta.url))
const read = (rel) => readFileSync(join(dir, rel), 'utf8')

const reportsJs = read('reports.js')
const reportsTpl = read('../templates/reports.php')
const dashboardTpl = read('../templates/dashboard.php')
const exportCtrl = read('../lib/Controller/ExportController.php')
const pageCtrl = read('../lib/Controller/PageController.php')
const capabilities = read('../lib/Capabilities.php')

describe('absence export type/status filters', () => {
	it('renders the chip group with multi-select pill semantics', () => {
		expect(reportsTpl).toContain('id="report-absence-filters-group"')
		expect(reportsTpl).toContain('id="report-absence-type-chips"')
		expect(reportsTpl).toContain('id="report-absence-status-chips"')
		expect(reportsTpl).toContain('data-absence-type=')
		expect(reportsTpl).toContain('data-absence-status=')
		expect(reportsTpl).toContain('aria-pressed="false"')
	})

	it('shows the group only for the absence report type', () => {
		expect(reportsJs).toContain("reportType === 'absence'")
		expect(reportsJs).toContain('absenceFiltersGroup.style.display')
	})

	it('sends types/statuses CSV params on the absence download URL', () => {
		expect(reportsJs).toContain("searchParams.set('types', types)")
		expect(reportsJs).toContain("searchParams.set('statuses', statuses)")
		expect(reportsJs).toContain('data-absence-type')
		expect(reportsJs).toContain('data-absence-status')
		// Grouped sections stay on — reason-separated output is the ask.
		expect(reportsJs).toContain("searchParams.set('sort', 'type')")
	})

	it('server parses and validates the plural filter params', () => {
		expect(exportCtrl).toContain('?string $types')
		expect(exportCtrl).toContain('?string $statuses')
		expect(exportCtrl).toContain('AMBIGUOUS_TYPE_FILTER')
		expect(exportCtrl).toContain('AMBIGUOUS_STATUS_FILTER')
		expect(exportCtrl).toContain('parseExportCsvList')
	})
})

describe('personalnummer payroll column', () => {
	it('lands in scoped absence exports after display_name', () => {
		expect(exportCtrl).toContain("'personalnummer' => $personalnummerByUid")
		expect(exportCtrl).toContain('Personalnummer::mapForUsers')
	})
})

describe('compensatory-day estimate + deep-link', () => {
	it('dashboard glance exposes the balance tile with comp-day delta', () => {
		expect(dashboardTpl).toContain('azc-dashboard-metric--balance')
		expect(dashboardTpl).toContain('compDaysEstimate')
		expect(dashboardTpl).toContain('dashboard-overtime-comp-request')
		// t() silently drops the count (L10NString defaults count=1): the
		// tile must call n() or it prints "≈ 1" for every balance — this is
		// the exact call shape, not just feature presence.
		expect(dashboardTpl).toContain("$l->n('≈ %n compensatory day available'")
	})

	it('controller gates the CTA on overtime_comp creatability', () => {
		expect(pageCtrl).toContain('absenceCreateCompUrl')
		expect(pageCtrl).toContain('TYPE_OVERTIME_COMP')
		expect(pageCtrl).toContain('isCreatable')
		expect(pageCtrl).toContain('compDaysEstimateService')
	})
})

describe('capability advertisement (no 404-probing)', () => {
	it('advertises absenceTypes and workLocationCatalog under mobile', () => {
		expect(capabilities).toContain("'absenceTypes' => [")
		expect(capabilities).toContain("'workLocationCatalog' => [")
		expect(capabilities).toContain('/api/absences/types')
		expect(capabilities).toContain('/api/work-locations')
	})
})
