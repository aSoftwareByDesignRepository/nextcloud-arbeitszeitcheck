/**
 * @vitest-environment node
 *
 * Source contracts: create form must collect + submit justification when
 * four-eyes manual approval is enabled (UI/backend parity).
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

describe('Time entry form justification (four-eyes create)', () => {
	const formJs = readFileSync(path.join(root, 'js/time-entry-form.js'), 'utf8')
	const template = readFileSync(path.join(root, 'templates/time-entries.php'), 'utf8')
	const formConfig = readFileSync(path.join(root, 'templates/common/time-entry-form-config.php'), 'utf8')
	const formL10n = readFileSync(path.join(root, 'templates/common/time-entry-form-l10n.php'), 'utf8')

	it('template renders a required justification field gated by approval flag', () => {
		expect(template).toContain('id="entry-justification"')
		expect(template).toContain('name="justification"')
		expect(template).toMatch(/\$mode === 'create' && \$manualTimeEntriesRequireApproval/)
		expect(template).toContain('minlength="10"')
		expect(template).toContain('maxlength="2000"')
		expect(template).toContain('aria-required="true"')
	})

	it('form config exports approval flag and min length to the client', () => {
		expect(formConfig).toContain("'manualTimeEntriesRequireApproval'")
		expect(formConfig).toContain("'minJustificationLength'")
		expect(formConfig).toContain('manualTimeEntriesRequireApproval')
	})

	it('JS validates and includes justification on submit', () => {
		expect(formJs).toContain('validateJustification')
		expect(formJs).toContain('setupJustificationField')
		expect(formJs).toContain('getJustificationValue')
		expect(formJs).toContain('data.justification = this.getJustificationValue()')
		expect(formJs).toContain('entry-justification')
		expect(formJs).toMatch(/if\s*\(\s*!this\.validateJustification\(\)\s*\)/)
	})

	it('l10n bundle includes justification feedback strings', () => {
		expect(formL10n).toContain("'justificationRequired'")
		expect(formL10n).toContain("'justificationReady'")
		expect(formL10n).toContain("'justificationRemaining'")
	})
})
