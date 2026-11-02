import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const srcPath = join(dirname(fileURLToPath(import.meta.url)), 'team-bulk-selection.js')
const src = readFileSync(srcPath, 'utf8')

function load(source) {
	const sandbox = {}
	const fn = new Function('window', 'globalThis', `${source}\nreturn window.ArbeitszeitCheckTeamBulkSelection`)
	return fn(sandbox, sandbox)
}

function create() {
	return load(src).createBulkPeopleSelection()
}

describe('team bulk people selection', () => {
	it('ignores blank ids and trims real ones', () => {
		const selection = create()
		expect(selection.toggle('  ', 'Nobody', true)).toBe(false)
		expect(selection.toggle(null, 'Nobody', true)).toBe(false)
		expect(selection.size()).toBe(0)
		expect(selection.toggle('  root  ', 'root (root)', true)).toBe(true)
		expect(selection.ids()).toEqual(['root'])
		expect(selection.label('root')).toBe('root (root)')
	})

	it('keeps the first label when the same person is ticked again', () => {
		const selection = create()
		selection.toggle('root', 'root (root)', true)
		selection.toggle('root', 'other label', true)
		expect(selection.label('root')).toBe('root (root)')
	})

	it('removes a person on uncheck and ignores an unknown remove', () => {
		const selection = create()
		selection.toggle('root', 'root (root)', true)
		expect(selection.toggle('root', 'root (root)', false)).toBe(true)
		expect(selection.has('root')).toBe(false)
		expect(selection.remove('root')).toBe(false)
		expect(selection.remove('   ')).toBe(false)
	})

	it('keeps people who are not in the current result list', () => {
		const selection = create()
		selection.toggle('root', 'root (root)', true)
		selection.toggle('ada', 'Ada Lovelace (ada)', true)
		selection.syncVisible([{ uid: 'ada', checked: true, label: 'Ada Lovelace (ada)' }])
		expect(selection.ids()).toEqual(['root', 'ada'])
		selection.syncVisible([{ uid: 'ada', checked: false, label: 'Ada Lovelace (ada)' }])
		expect(selection.ids()).toEqual(['root'])
	})

	it('adopts a checked box that the change handler missed', () => {
		const selection = create()
		selection.syncVisible([{ uid: 'ada', checked: true, label: 'Ada (ada)' }])
		expect(selection.label('ada')).toBe('Ada (ada)')
		selection.syncVisible([{ uid: '  ', checked: true, label: 'blank' }])
		expect(selection.size()).toBe(1)
	})

	it('drops a stale search and accepts only the latest token', () => {
		const selection = create()
		const first = selection.beginSearch()
		const second = selection.beginSearch()
		expect(selection.isCurrentSearch(first)).toBe(false)
		expect(selection.isCurrentSearch(second)).toBe(true)
		expect(selection.isCurrentSearch(second + 1)).toBe(false)
	})

	it('refuses a second submit until the first one finishes', () => {
		const selection = create()
		expect(selection.tryBeginSubmit()).toBe(false)
		selection.toggle('root', 'root (root)', true)
		expect(selection.tryBeginSubmit()).toBe(true)
		expect(selection.tryBeginSubmit()).toBe(false)
		expect(selection.toggle('ada', 'Ada', true)).toBe(false)
		expect(selection.remove('root')).toBe(false)
		selection.syncVisible([{ uid: 'root', checked: false }])
		expect(selection.has('root')).toBe(true)
		expect(selection.isBusy()).toBe(true)
		selection.endSubmit()
		expect(selection.isBusy()).toBe(false)
		expect(selection.remove('root')).toBe(true)
		expect(selection.size()).toBe(0)
	})

	it('clear drops every selected person', () => {
		const selection = create()
		selection.toggle('root', 'root', true)
		selection.toggle('ada', 'ada', true)
		selection.clear()
		expect(selection.entries()).toEqual([])
	})
})

describe('team bulk selection mutations', () => {
	const mutants = [
		{
			name: 'blank ids are stored',
			from: 'if (!id) {\n\t\t\t\t\treturn false;\n\t\t\t\t}',
			to: 'if (id) {\n\t\t\t\t\treturn false;\n\t\t\t\t}',
		},
		{
			name: 'uncheck keeps the person',
			from: 'selected.delete(id);\n\t\t\t\t}\n\t\t\t\treturn true;',
			to: 'selected.set(id, normalizeLabel(label, id));\n\t\t\t\t}\n\t\t\t\treturn true;',
		},
		{
			name: 'a newer search is treated as stale',
			from: 'return token === searchGen;',
			to: 'return token !== searchGen;',
		},
		{
			name: 'empty selection can submit',
			from: 'if (busy || selected.size === 0) {',
			to: 'if (busy && selected.size === 0) {',
		},
		{
			name: 'sync drops people who are not on screen',
			from: '\t\t\t\t(boxes || []).forEach(function (box) {',
			to: '\t\t\t\tselected.clear();\n\t\t\t\t(boxes || []).forEach(function (box) {',
		},
	]

	it.each(mutants)('kills the mutant: $name', ({ from, to }) => {
		expect(src.includes(from), `anchor missing for mutant`).toBe(true)
		const broken = src.replace(from, to)
		expect(broken).not.toBe(src)
		const selection = load(broken).createBulkPeopleSelection()
		let killed = false
		try {
			expect(selection.toggle('  ', 'Nobody', true)).toBe(false)
			expect(selection.toggle('root', 'root (root)', true)).toBe(true)
			expect(selection.toggle('root', 'root (root)', false)).toBe(true)
			expect(selection.has('root')).toBe(false)
			selection.toggle('root', 'root (root)', true)
			selection.toggle('ada', 'Ada', true)
			selection.syncVisible([{ uid: 'ada', checked: true, label: 'Ada' }])
			expect(selection.ids()).toEqual(['root', 'ada'])
			const first = selection.beginSearch()
			const second = selection.beginSearch()
			expect(selection.isCurrentSearch(second)).toBe(true)
			expect(selection.isCurrentSearch(first)).toBe(false)
			selection.clear()
			expect(selection.tryBeginSubmit()).toBe(false)
			selection.toggle('root', 'root', true)
			expect(selection.tryBeginSubmit()).toBe(true)
			expect(selection.tryBeginSubmit()).toBe(false)
		} catch {
			killed = true
		}
		expect(killed, 'mutant survived the suite').toBe(true)
	})
})
