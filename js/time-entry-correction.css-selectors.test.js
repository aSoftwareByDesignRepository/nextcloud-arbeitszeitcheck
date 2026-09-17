import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * Guards the regression where `#time-entry-correction-modal` was comma-paired
 * with `.azc-manager-correction-modal .descendant` selectors. That made
 * descendant rules apply to the employee modal *root*, turning it into a
 * horizontal flex wrap and shoving the form off the right edge (customer
 * report 2026-09-17).
 */
const cssPath = join(dirname(fileURLToPath(import.meta.url)), '../css/time-entry-correction.css')
const css = readFileSync(cssPath, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')

/** @param {string} text */
function ruleBlocks(text) {
	const blocks = []
	let i = 0
	while (i < text.length) {
		const open = text.indexOf('{', i)
		if (open < 0) {
			break
		}
		const before = text.slice(i, open)
		const trimmed = before.trim()
		if (!trimmed) {
			i = open + 1
			continue
		}
		if (trimmed.startsWith('@')) {
			// Enter at-rule; collect until matching close, then parse inner separately.
			let depth = 1
			let k = open + 1
			while (k < text.length && depth > 0) {
				if (text[k] === '{') {
					depth += 1
				} else if (text[k] === '}') {
					depth -= 1
				}
				k += 1
			}
			const inner = text.slice(open + 1, k - 1)
			blocks.push(...ruleBlocks(inner))
			i = k
			continue
		}
		let depth = 1
		let k = open + 1
		while (k < text.length && depth > 0) {
			if (text[k] === '{') {
				depth += 1
			} else if (text[k] === '}') {
				depth -= 1
			}
			k += 1
		}
		const selectors = trimmed
			.split(',')
			.map((s) => s.trim())
			.filter(Boolean)
		const body = text.slice(open + 1, k - 1)
		blocks.push({ selectors, body })
		i = k
	}
	return blocks
}

describe('time-entry-correction.css selector pairing', () => {
	const blocks = ruleBlocks(css)

	it('never pairs bare #time-entry-correction-modal with a manager descendant', () => {
		const offenders = []
		for (const block of blocks) {
			const hasBareEmployee = block.selectors.includes('#time-entry-correction-modal')
			const managerDescendants = block.selectors.filter(
				(s) => s.startsWith('.azc-manager-correction-modal ') || s.startsWith('.azc-manager-correction-modal>'),
			)
			if (hasBareEmployee && managerDescendants.length > 0) {
				offenders.push({
					bare: '#time-entry-correction-modal',
					with: managerDescendants,
				})
			}
		}
		expect(offenders).toEqual([])
	})

	it('scopes modal-body / date-flex / snapshot th to descendants of the employee modal', () => {
		const required = [
			'#time-entry-correction-modal .modal-body',
			'#time-entry-correction-modal .form-input-wrapper--date',
			'#time-entry-correction-modal .correction-snapshot__table tbody th',
			'#time-entry-correction-modal .correction-dialog__inner',
		]
		for (const sel of required) {
			const found = blocks.some((b) => b.selectors.includes(sel))
			expect(found, `missing selector ${sel}`).toBe(true)
		}
	})

	it('only root column-flex rule targets bare #time-entry-correction-modal', () => {
		const bareBlocks = blocks.filter((b) => b.selectors.includes('#time-entry-correction-modal'))
		expect(bareBlocks.length).toBe(1)
		expect(bareBlocks[0].body).toMatch(/flex-direction:\s*column/)
		expect(bareBlocks[0].body).not.toMatch(/flex-wrap/)
		expect(bareBlocks[0].selectors).toContain('.azc-manager-correction-modal')
	})

	it('does not apply row flex-wrap from date wrapper to the modal root', () => {
		const dateWrap = blocks.find((b) =>
			b.selectors.includes('#time-entry-correction-modal .form-input-wrapper--date'),
		)
		expect(dateWrap).toBeTruthy()
		expect(dateWrap.body).toMatch(/display:\s*flex/)
		expect(dateWrap.body).toMatch(/flex-wrap:\s*wrap/)
		// Critical: that flex-wrap must NOT also target the bare modal id.
		expect(dateWrap.selectors).not.toContain('#time-entry-correction-modal')
	})
})
