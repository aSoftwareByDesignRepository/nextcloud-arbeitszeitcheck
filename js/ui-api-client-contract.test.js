/**
 * @vitest-environment node
 * Assert the UI↔API contract registry stays wired to real needles.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { execFileSync } from 'node:child_process'

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const workspaceRoot = path.resolve(appRoot, '../../..')
const registryPath = path.join(appRoot, 'tests/contracts/ui-api-client.json')

describe('ui-api-client contract registry', () => {
	it('registers manual create justification across server/web/mobile', () => {
		const data = JSON.parse(readFileSync(registryPath, 'utf8'))
		const contract = data.contracts.find((c) => c.id === 'manual-time-entry-justification')
		expect(contract).toBeTruthy()
		expect(contract.param).toBe('justification')
		expect(contract.web.some((s) => s.file.includes('time-entries.php'))).toBe(true)
		expect(contract.web.some((s) => s.file.includes('time-entry-form.js'))).toBe(true)
		expect(contract.mobile.some((s) => s.file.includes('CreateTimeEntryScreen'))).toBe(true)
	})

	it('workspace checker passes with heuristic for this app', () => {
		const script = path.join(workspaceRoot, 'scripts/check-ui-api-client-contracts.py')
		if (!existsSync(script)) {
			return
		}
		const out = execFileSync('python3', [script, '--app', 'arbeitszeitcheck', '--heuristic'], {
			encoding: 'utf8',
		})
		expect(out).toContain('OK')
	})
})
