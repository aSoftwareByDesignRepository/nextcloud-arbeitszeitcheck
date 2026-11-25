import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * Contract: admin absence-types surface must not hide guaranteed-failing
 * actions and must not mask server error reasons.
 *
 * Regression guard for the Cecilienstift report (2026-11): deleting the
 * entitlement-linked "ZU Jubiläum" type showed the generic
 * "Der Abwesenheitstyp konnte nicht gelöscht werden" toast although the
 * server answered 409 with the real reason. Two layers failed:
 *   1. the list rendered a delete button for types the server always
 *      refuses (entitlement-linked) — a guaranteed dead end;
 *   2. every Utils.ajax onError discarded `error.error`, so even the
 *      helpful 409 message never reached the admin.
 */
const dir = dirname(fileURLToPath(import.meta.url))
const read = (rel) => readFileSync(join(dir, rel), 'utf8')

const adminJs = read('admin-absence-types.js')
const tpl = read('../templates/admin-absence-types.php')
const service = read('../lib/Service/CustomAbsenceTypeService.php')

describe('entitlement-linked type visibility', () => {
	it('service flags linked types in list and detail summaries', () => {
		expect(service).toContain("'entitlementLinked'")
		expect(service).toContain('specialEntitlementTypes?->findAll()')
		// The link lookup is wrapped by isEntitlementLinked() (missing-table
		// tolerant) — pin both the guard and the mapper call it wraps.
		expect(service).toContain('isEntitlementLinked(')
		expect(service).toContain('specialEntitlementTypes->findByAbsenceTypeCode(')
	})

	it('list hides toggle/delete for linked types and links the entitlement admin', () => {
		expect(tpl).toContain("!empty($type['entitlementLinked'])")
		expect(tpl).toContain('special_entitlement_admin.page')
	})

	it('editor disables the availability checkbox for linked types', () => {
		expect(adminJs).toContain('data.entitlementLinked')
	})
})

describe('server error messages are not masked', () => {
	it('ajax error handlers prefer the server-provided error text', () => {
		// Every mutation handler must surface `error.error` before the
		// generic fallback (catalog-busy 409, validation 400, linked 409).
		const passes = adminJs.match(/error && error\.error/g) || []
		expect(passes.length).toBeGreaterThanOrEqual(4)
	})

	it('save path renders field-level errors from 400 bodies', () => {
		expect(adminJs).toContain('error.data.fields')
		expect(adminJs).toContain('showFieldErrors(host, error.data.fields')
	})
})
