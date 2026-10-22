/**
 * Mutation-style contracts for picker search params + teams bulk l10n.
 */
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')

describe('admin-teams bulk people search', () => {
	const src = readFileSync(join(root, 'js/admin-teams.js'), 'utf8')

	it('uses search query param for picker API', () => {
		expect(src).toContain('search: q')
		expect(src).toContain("picker: '1'")
		expect(src).not.toContain("'?q=' + encodeURIComponent(q) + '&picker=1")
	})

	it('normalizes user id aliases when rendering hits', () => {
		expect(src).toContain('u.userId || u.uid || u.id')
	})

	it('reads team name from #team-detail-name for bulk confirm copy', () => {
		expect(src).toContain("getElementById('team-detail-name')")
		expect(src).not.toContain("getElementById('admin-team-detail-name')")
	})

	it('syncs bulk selection from DOM data-user-id checkboxes', () => {
		expect(src).toContain('syncSelectionFromDom')
		expect(src).toContain("data-user-id")
	})

	it('wires bulk Cancel to Components.closeModal', () => {
		expect(src).toContain("querySelector('[data-action=\"close-modal\"]')")
		expect(src).toContain('Components.closeModal')
	})
})

describe('in-modal user picker CSS', () => {
	const css = readFileSync(join(root, 'css/common/user-picker.css'), 'utf8')

	it('keeps modal suggestion list in document flow', () => {
		expect(css).toMatch(/user-picker--in-modal[\s\S]*position:\s*static/)
	})
})

describe('admin-user-picker response shape', () => {
	const src = readFileSync(join(root, 'js/common/admin-user-picker.js'), 'utf8')

	it('accepts userId, uid, and id', () => {
		expect(src).toContain('u.userId || u.uid || u.id')
	})

	it('always sends search= for directory queries', () => {
		expect(src).toContain("params.set('search', q)")
	})
})

describe('teams-l10n bulk keys', () => {
	const src = readFileSync(join(root, 'templates/common/teams-l10n.php'), 'utf8')

	it('includes Kraft multi-add strings in TemplateL10n map', () => {
		expect(src).toContain('TemplateL10n::mapFromMessageIds')
		expect(src).toContain("'Find people'")
		expect(src).toContain("'Find a person'")
		expect(src).toContain("'Add several people…'")
		expect(src).toContain("'Add selected (%s)'")
	})
})
