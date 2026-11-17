import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Contract tests for the correction-dialog "Split day" section
 * (spec split-day §9.7 + §9.9 row for this file):
 *
 *  - `splitOverride` is emitted only when a split control was touched or
 *    worked portions were added
 *  - `worked` travels only with decisions that can produce a split
 *  - worked portions stay inside their interruption bounds
 *  - the local break-compliance pre-check defers to the server for
 *    context entries (never blocks a valid proposal on gross math)
 *  - entries without `accounting` never send `splitOverride`
 */

const snapshot = {}

const L10N = {
	correctionModalTitle: 'Request correction',
	correctionLabelDate: 'Date',
	correctionLabelStart: 'Start',
	correctionLabelEnd: 'End',
	correctionLabelBreaks: 'Breaks',
	correctionLabelWorkLocation: 'Work location',
	correctionLabelDescription: 'Description',
	correctionLabelSplit: 'Split day',
	correctionLabelPartialService: 'Partial service {number}',
	correctionSplitCurrentSplit: 'This day was split at the scheduled interruption.',
	correctionSplitHintNotAfterCutoff: 'Not split: clock-out was not after the cut-off time',
	correctionSplitHintClockInInterruption: 'Not split: clock-in during interruption',
	correctionSplitHintNoInterruption: 'Not split: no interruption today',
	correctionSplitHintCrossesMidnight: 'Not split: crosses midnight',
	correctionSplitHintDstReview: 'Needs review: time change (DST)',
	correctionInterruptionLabel: 'Interruption {start}–{end}',
	correctionWorkedMark: 'I worked part of this interruption',
	correctionWorkedPortion: 'Worked portion {number}',
	correctionWorkedAddAnother: 'Add another worked portion',
	correctionWorkedStartHour: 'Worked portion start hour',
	correctionWorkedStartMinute: 'Worked portion start minute',
	correctionWorkedEndHour: 'Worked portion end hour',
	correctionWorkedEndMinute: 'Worked portion end minute',
	correctionWorkedRemove: 'Remove worked portion',
	correctionErrorWorkedPartial: 'Each worked portion needs both a start and end time, or remove the row.',
	correctionErrorWorkedBounds: 'Worked portions must stay inside their interruption ({start}–{end}).',
	correctionErrorWorkedEndAfterStart: 'Each worked portion must end after it starts.',
	correctionErrorWorkedOverlap: 'Worked portions must not overlap.',
	correctionErrorWorkedTooMany: 'At most {max} worked portions.',
	correctionErrorNoChanges: 'Change at least one time or break before submitting.',
	correctionErrorValidDate: 'Please enter a valid date (dd.mm.yyyy).',
	correctionErrorStartEndRequired: 'Please enter both start and end time.',
	correctionErrorValidTimes: 'Please enter valid start and end times.',
	correctionErrorEndAfterStart: 'End time must be after start time.',
	correctionErrorJustificationMin: 'Please provide a reason of at least 10 characters.',
	correctionErrorPartialBreak: 'Each break must have both a start and end time, or leave the row empty.',
	correctionErrorBreakMinDuration: 'Each break must be at least 15 minutes.',
	correctionErrorBreakWithinWork: 'Breaks must fall within your working hours.',
	correctionErrorBreakOverlap: 'Break times must not overlap.',
	correctionErrorBreakSplitInvalid: 'Breaks must be one continuous block.',
	correctionSubmitting: 'Submitting…',
	correctionSubmitSuccess: 'Submitted',
	correctionSubmitError: 'Could not submit correction.',
	correctionJustificationReady: '{count} of 10+ characters — you can submit',
	workLocationUnspecified: 'Not specified',
	workLocationOffice: 'Office',
	workLocationHome: 'Home office',
	workLocationExternal: 'External appointment',
}

function buildDom() {
	document.body.innerHTML = `
		<div id="time-entry-correction-source" class="visually-hidden">
			<form id="time-entry-correction-form" action="#" method="post" novalidate>
				<input type="hidden" id="correction-entry-id" name="entryId" value="">
				<table><tbody id="correction-current-summary"></tbody></table>
				<input type="text" id="correction-date" value="">
				<button type="button" id="correction-date-today"></button>
				<select id="correction-start-hour"></select>
				<select id="correction-start-minute"></select>
				<input type="hidden" id="correction-start-time">
				<select id="correction-end-hour"></select>
				<select id="correction-end-minute"></select>
				<input type="hidden" id="correction-end-time">
				<div id="correction-breaks-container"></div>
				<p id="correction-breaks-empty"></p>
				<button type="button" id="correction-add-break">Add break</button>
				<fieldset id="correction-split-fieldset" hidden>
					<p id="correction-split-current"></p>
					<div role="radiogroup">
						<label><input type="radio" name="correctionSplitDecision" value="auto" checked></label>
						<label><input type="radio" name="correctionSplitDecision" value="force_split"></label>
						<label><input type="radio" name="correctionSplitDecision" value="force_continuous"></label>
					</div>
					<div id="correction-worked-region">
						<div id="correction-worked-container"></div>
					</div>
				</fieldset>
				<select id="correction-work-location"><option value=""></option></select>
				<textarea id="correction-justification"></textarea>
				<p id="correction-justification-count"></p>
				<div id="correction-dialog-status"></div>
				<div class="correction-dialog__footer">
					<button type="button" id="correction-dialog-cancel">Cancel</button>
					<button type="submit" id="correction-wizard-submit" form="time-entry-correction-form">Submit</button>
				</div>
			</form>
		</div>`
}

function summaryFixture(overrides = {}) {
	return {
		id: 7,
		startTime: '2026-11-16T06:00:00+01:00',
		endTime: '2026-11-16T16:30:00+01:00',
		breaks: [],
		description: '',
		workLocation: 'office',
		updatedAt: '2026-11-16T16:30:01+01:00',
		// Opaque CAS token issued by TimeEntry::getContentRevision() — the JS
		// must echo THIS (not updatedAt) as splitOverride.baseEntryRevision.
		revision: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
		...overrides,
	}
}

function accountingFixture(overrides = {}) {
	return {
		contractVersion: 2,
		state: 'applied',
		split: true,
		reason: 'clock_out_after_cutoff',
		segments: [
			{ no: 1, start: '2026-11-16T06:00:00+01:00', end: '2026-11-16T10:00:00+01:00', workSeconds: 14400 },
			{ no: 2, start: '2026-11-16T13:30:00+01:00', end: '2026-11-16T16:30:00+01:00', workSeconds: 10800 },
		],
		interruptions: [
			{ start: '2026-11-16T10:00:00+01:00', end: '2026-11-16T13:30:00+01:00', seconds: 12600 },
		],
		excludedSeconds: 12600,
		breakHandling: 'covered_by_interruption',
		netWorkSeconds: 25200,
		paidWorkSeconds: 25200,
		...overrides,
	}
}

function openCorrectionDialog(summary) {
	// Same path as the button: ensureCorrectionModal + wizardState.openWizard.
	const modal = window.__ArbeitszeitCheckTimeEntryCorrectionTestables.open(summary)
	expect(modal).toBeTruthy()
	return modal
}

function setHm(prefix, hm) {
	const [h, m] = hm.split(':')
	const hourSel = document.getElementById(prefix + '-hour')
	const minuteSel = document.getElementById(prefix + '-minute')
	hourSel.value = h
	minuteSel.value = m
	hourSel.dispatchEvent(new Event('change', { bubbles: true }))
	minuteSel.dispatchEvent(new Event('change', { bubbles: true }))
}

function setWorkedHm(row, prefix, hm) {
	const [h, m] = hm.split(':')
	const hourSel = row.querySelector(prefix + '-hour')
	const minuteSel = row.querySelector(prefix + '-minute')
	hourSel.value = h
	minuteSel.value = m
	hourSel.dispatchEvent(new Event('change', { bubbles: true }))
	minuteSel.dispatchEvent(new Event('change', { bubbles: true }))
}

function submitForm() {
	document.getElementById('correction-justification').value = 'a perfectly good reason'
	const form = document.getElementById('time-entry-correction-form')
	form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
}

let ajaxCalls
let ajaxMock

beforeEach(async () => {
	snapshot.ArbeitszeitCheck = globalThis.window.ArbeitszeitCheck
	snapshot.ArbeitszeitCheckComponents = globalThis.window.ArbeitszeitCheckComponents
	snapshot.ArbeitszeitCheckUtils = globalThis.window.ArbeitszeitCheckUtils
	snapshot.ArbeitszeitCheckMessaging = globalThis.window.ArbeitszeitCheckMessaging
	snapshot.ArbeitszeitCheckTime = globalThis.window.ArbeitszeitCheckTime
	snapshot.ArbeitszeitCheckValidation = globalThis.window.ArbeitszeitCheckValidation
	snapshot.testables = globalThis.window.__ArbeitszeitCheckTimeEntryCorrectionTestables
	vi.resetModules()
	ajaxCalls = []
	ajaxMock = vi.fn((url, opts) => {
		ajaxCalls.push({ url, data: opts && opts.data })
		if (opts && typeof opts.onSuccess === 'function') {
			opts.onSuccess({ success: true })
		}
	})

	globalThis.window.ArbeitszeitCheck = {
		l10n: { ...L10N },
		apiUrl: { requestCorrection: '/apps/arbeitszeitcheck/api/time-entries/__ID__/request-correction' },
	}
	globalThis.window.ArbeitszeitCheckMessaging = {
		showError: vi.fn(),
		showSuccess: vi.fn(),
	}
	globalThis.window.ArbeitszeitCheckTime = {
		parseInstant: (v) => (v ? new Date(v) : null),
		formatTime: (v) => String(v).slice(11, 16),
		formatDate: (v) => String(v).slice(0, 10),
		todayYmd: () => '2026-11-16',
	}
	globalThis.window.ArbeitszeitCheckUtils = {
		parseAttributeJson: (raw) => {
			try {
				return JSON.parse(raw)
			} catch (_e) {
				return null
			}
		},
		isTimeEntryClockSummary: (v) => !!v && typeof v.startTime === 'string'
			&& (typeof v.endTime === 'string' || v.endTime === null),
		escapeHtml: (s) => String(s),
		ajax: ajaxMock,
		confirmDestructiveAction: vi.fn(async () => true),
	}
	globalThis.window.ArbeitszeitCheckComponents = {
		createModal: (opts) => {
			const el = document.createElement('div')
			el.id = opts.id
			el.innerHTML = opts.content + '<button type="button" class="modal-close"></button>'
			document.body.appendChild(el)
			return el
		},
		openModal: vi.fn(),
		closeModal: vi.fn(),
	}
	if (!globalThis.window.requestAnimationFrame) {
		globalThis.window.requestAnimationFrame = (fn) => setTimeout(fn, 0)
	}
	buildDom()
	await import('./time-entry-correction.js')
})

afterEach(() => {
	globalThis.window.ArbeitszeitCheck = snapshot.ArbeitszeitCheck
	globalThis.window.ArbeitszeitCheckComponents = snapshot.ArbeitszeitCheckComponents
	globalThis.window.ArbeitszeitCheckUtils = snapshot.ArbeitszeitCheckUtils
	globalThis.window.ArbeitszeitCheckMessaging = snapshot.ArbeitszeitCheckMessaging
	globalThis.window.ArbeitszeitCheckTime = snapshot.ArbeitszeitCheckTime
	globalThis.window.ArbeitszeitCheckValidation = snapshot.ArbeitszeitCheckValidation
	globalThis.window.__ArbeitszeitCheckTimeEntryCorrectionTestables = snapshot.testables
	document.body.innerHTML = ''
})

describe('pure helpers (spec §9.7 payload contract)', () => {
	it('buildSplitOverride returns null when nothing was touched', () => {
		const t = window.__ArbeitszeitCheckTimeEntryCorrectionTestables
		expect(t.buildSplitOverride('auto', 'auto', [], null)).toBeNull()
		expect(t.buildSplitOverride('force_split', 'force_split', [], null)).toBeNull()
		expect(t.buildSplitOverride('auto', 'auto', [], 'rev1')).toBeNull()
	})

	it('emits the chosen decision when a control changed', () => {
		const t = window.__ArbeitszeitCheckTimeEntryCorrectionTestables
		expect(t.buildSplitOverride('auto', 'force_split', [], null)).toEqual({ decision: 'force_split' })
		expect(t.buildSplitOverride('auto', 'force_continuous', [], null)).toEqual({ decision: 'force_continuous' })
		expect(t.buildSplitOverride('force_split', 'auto', [], null)).toEqual({ decision: 'auto' })
	})

	it('attaches worked only to split-capable decisions, never to force_continuous', () => {
		const t = window.__ArbeitszeitCheckTimeEntryCorrectionTestables
		const worked = [['2026-11-16T10:00:00+01:00', '2026-11-16T11:00:00+01:00']]
		expect(t.buildSplitOverride('auto', 'force_split', worked, null)).toEqual({ decision: 'force_split', worked })
		expect(t.buildSplitOverride('auto', 'auto', worked, null)).toEqual({ decision: 'auto', worked })
		expect(t.buildSplitOverride('auto', 'force_continuous', worked, null)).toEqual({ decision: 'force_continuous' })
	})

	it('includes baseEntryRevision when the flow provides one', () => {
		const t = window.__ArbeitszeitCheckTimeEntryCorrectionTestables
		const rev = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2'
		const out = t.buildSplitOverride('auto', 'force_split', [], rev)
		expect(out.baseEntryRevision).toBe(rev)
	})

	it('initialSplitDecision maps engine reasons, defaulting to auto', () => {
		const t = window.__ArbeitszeitCheckTimeEntryCorrectionTestables
		expect(t.initialSplitDecision({ reason: 'override_split' })).toBe('force_split')
		expect(t.initialSplitDecision({ reason: 'override_continuous' })).toBe('force_continuous')
		expect(t.initialSplitDecision({ reason: 'clock_out_after_cutoff' })).toBe('auto')
		expect(t.initialSplitDecision({ reason: 'anything_else' })).toBe('auto')
		expect(t.initialSplitDecision(null)).toBe('auto')
	})

	it('splitAccountingOf accepts only object accounting blocks', () => {
		const t = window.__ArbeitszeitCheckTimeEntryCorrectionTestables
		expect(t.splitAccountingOf({ accounting: { split: true } })).toEqual({ split: true })
		expect(t.splitAccountingOf({})).toBeNull()
		expect(t.splitAccountingOf({ accounting: null })).toBeNull()
		expect(t.splitAccountingOf({ accounting: 'x' })).toBeNull()
		expect(t.splitAccountingOf({ accounting: [1] })).toBeNull()
		expect(t.splitAccountingOf(null)).toBeNull()
	})

	it('pickedMsInInterruption anchors picked times to the interruption start instant', () => {
		const t = window.__ArbeitszeitCheckTimeEntryCorrectionTestables
		const intStartMs = Date.parse('2026-11-16T10:00:00+01:00')
		expect(t.pickedMsInInterruption(intStartMs, '10:00', '10:30'))
			.toBe(intStartMs + 30 * 60000)
		// A picked time displayed before the start lands on the next day
		expect(t.pickedMsInInterruption(intStartMs, '10:00', '09:30'))
			.toBe(intStartMs + (24 * 60 - 30) * 60000)
		expect(Number.isNaN(t.pickedMsInInterruption(intStartMs, '10:00', 'bogus'))).toBe(true)
	})
})

describe('correction dialog split section (DOM)', () => {
	it('opens with the split section for context entries and hides it for legacy', async () => {
		await openCorrectionDialog(summaryFixture({ accounting: accountingFixture() }))
		expect(document.getElementById('correction-split-fieldset').hidden).toBe(false)
		expect(document.getElementById('correction-split-current').textContent)
			.toBe('This day was split at the scheduled interruption.')
	})

	it('does not send splitOverride when no split control was touched', async () => {
		await openCorrectionDialog(summaryFixture({ accounting: accountingFixture() }))
		document.getElementById('correction-date').value = '16.11.2026'
		setHm('correction-start', '06:15') // changed time → proposal is valid
		setHm('correction-end', '16:30')
		submitForm()
		expect(ajaxCalls.length).toBe(1)
		expect(ajaxCalls[0].data).not.toHaveProperty('splitOverride')
	})

	it('sends splitOverride with decision and baseEntryRevision when a control changed', async () => {
		await openCorrectionDialog(summaryFixture({ accounting: accountingFixture() }))
		document.getElementById('correction-date').value = '16.11.2026'
		setHm('correction-start', '06:00')
		setHm('correction-end', '16:30')
		document.querySelector('input[name="correctionSplitDecision"][value="force_continuous"]').checked = true
		submitForm()
		expect(ajaxCalls.length).toBe(1)
		expect(ajaxCalls[0].data.splitOverride).toEqual({
			decision: 'force_continuous',
			baseEntryRevision: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
		})
		expect(ajaxCalls[0].data.splitOverride).not.toHaveProperty('worked')
	})

	it('sends worked portions only inside interruption bounds as ISO instants', async () => {
		await openCorrectionDialog(summaryFixture({ accounting: accountingFixture() }))
		document.getElementById('correction-date').value = '16.11.2026'
		setHm('correction-start', '06:00')
		setHm('correction-end', '16:30')
		const checkbox = document.querySelector('.correction-worked-enable')
		checkbox.checked = true
		checkbox.dispatchEvent(new Event('change', { bubbles: true }))
		const row = document.querySelector('.correction-worked-row')
		expect(row).toBeTruthy()
		// Portion 10:30–11:15 inside interruption 10:00–13:30
		setWorkedHm(row, '.correction-worked-start', '10:30')
		setWorkedHm(row, '.correction-worked-end', '11:15')
		submitForm()
		expect(ajaxCalls.length).toBe(1)
		const so = ajaxCalls[0].data.splitOverride
		expect(so).toBeTruthy()
		expect(so.decision).toBe('auto')
		expect(so.worked).toHaveLength(1)
		const intStartMs = Date.parse('2026-11-16T10:00:00+01:00')
		expect(Date.parse(so.worked[0][0])).toBe(intStartMs + 30 * 60000)
		expect(Date.parse(so.worked[0][1])).toBe(intStartMs + 75 * 60000)
	})

	it('rejects a worked portion outside the interruption bounds', async () => {
		await openCorrectionDialog(summaryFixture({ accounting: accountingFixture() }))
		document.getElementById('correction-date').value = '16.11.2026'
		setHm('correction-start', '06:00')
		setHm('correction-end', '16:30')
		const checkbox = document.querySelector('.correction-worked-enable')
		checkbox.checked = true
		checkbox.dispatchEvent(new Event('change', { bubbles: true }))
		const row = document.querySelector('.correction-worked-row')
		// 13:45 is outside the 10:00–13:30 interruption bounds
		setWorkedHm(row, '.correction-worked-start', '10:30')
		setWorkedHm(row, '.correction-worked-end', '13:45')
		submitForm()
		expect(ajaxCalls.length).toBe(0)
		expect(document.getElementById('correction-dialog-status').textContent)
			.toContain('must stay inside their interruption')
	})

	it('hides the worked region under force_continuous', async () => {
		await openCorrectionDialog(summaryFixture({ accounting: accountingFixture() }))
		const region = document.getElementById('correction-worked-region')
		expect(region.hidden).toBe(false)
		const radio = document.querySelector('input[name="correctionSplitDecision"][value="force_continuous"]')
		radio.checked = true
		radio.dispatchEvent(new Event('change', { bubbles: true }))
		expect(region.hidden).toBe(true)
	})

	it('never sends splitOverride for entries without accounting', async () => {
		await openCorrectionDialog(summaryFixture())
		expect(document.getElementById('correction-split-fieldset').hidden).toBe(true)
		document.getElementById('correction-date').value = '16.11.2026'
		setHm('correction-start', '06:15')
		setHm('correction-end', '16:30')
		// Simulate a stray touched radio — the accounting guard must still win.
		document.querySelector('input[name="correctionSplitDecision"][value="force_split"]').checked = true
		submitForm()
		expect(ajaxCalls.length).toBe(1)
		expect(ajaxCalls[0].data).not.toHaveProperty('splitOverride')
	})

	it('defers break-compliance evaluation to the server for context entries (§9.9)', async () => {
		const evaluateBreakCompliance = vi.fn(() => ({ ok: false, message: 'blocked' }))
		globalThis.window.ArbeitszeitCheckValidation = { evaluateBreakCompliance }
		await openCorrectionDialog(summaryFixture({ accounting: accountingFixture() }))
		document.getElementById('correction-date').value = '16.11.2026'
		setHm('correction-start', '06:15') // change → valid proposal
		setHm('correction-end', '16:30')
		// Add a break so the compliance branch would run for legacy entries.
		document.getElementById('correction-add-break').click()
		const breakRow = document.querySelector('.break-entry')
		const bs = breakRow.querySelector('.correction-break-start-hour')
		const bsm = breakRow.querySelector('.correction-break-start-minute')
		const be = breakRow.querySelector('.correction-break-end-hour')
		const bem = breakRow.querySelector('.correction-break-end-minute')
		bs.value = '12'; bsm.value = '00'; be.value = '12'; bem.value = '30'
		bs.dispatchEvent(new Event('change', { bubbles: true }))
		bsm.dispatchEvent(new Event('change', { bubbles: true }))
		be.dispatchEvent(new Event('change', { bubbles: true }))
		bem.dispatchEvent(new Event('change', { bubbles: true }))
		submitForm()
		expect(evaluateBreakCompliance).not.toHaveBeenCalled()
		expect(ajaxCalls.length).toBe(1)
	})

	it('still runs the compliance pre-check for legacy entries', async () => {
		const evaluateBreakCompliance = vi.fn(() => ({ ok: false, message: 'blocked' }))
		globalThis.window.ArbeitszeitCheckValidation = { evaluateBreakCompliance }
		await openCorrectionDialog(summaryFixture())
		document.getElementById('correction-date').value = '16.11.2026'
		setHm('correction-start', '06:15')
		setHm('correction-end', '16:30')
		document.getElementById('correction-add-break').click()
		const breakRow = document.querySelector('.break-entry')
		const bs = breakRow.querySelector('.correction-break-start-hour')
		const bsm = breakRow.querySelector('.correction-break-start-minute')
		const be = breakRow.querySelector('.correction-break-end-hour')
		const bem = breakRow.querySelector('.correction-break-end-minute')
		bs.value = '12'; bsm.value = '00'; be.value = '12'; bem.value = '30'
		bs.dispatchEvent(new Event('change', { bubbles: true }))
		bsm.dispatchEvent(new Event('change', { bubbles: true }))
		be.dispatchEvent(new Event('change', { bubbles: true }))
		bem.dispatchEvent(new Event('change', { bubbles: true }))
		submitForm()
		expect(evaluateBreakCompliance).toHaveBeenCalled()
		expect(ajaxCalls.length).toBe(0)
	})

	it('locks the form and announces stale state on a 409 split-override conflict', async () => {
		await openCorrectionDialog(summaryFixture({ accounting: accountingFixture() }))
		document.getElementById('correction-date').value = '16.11.2026'
		setHm('correction-start', '06:15')
		setHm('correction-end', '16:30')
		ajaxMock.mockImplementation((url, opts) => {
			ajaxCalls.push({ url, data: opts && opts.data })
			const err = new Error('conflict')
			err.status = 409
			err.error = 'This time entry was changed in the meantime. Reload it and submit the correction again.'
			err.data = { success: false, error: err.error, error_code: 'SPLIT_OVERRIDE_STALE' }
			if (opts && typeof opts.onError === 'function') {
				opts.onError(err)
			}
		})
		submitForm()
		expect(ajaxCalls.length).toBe(1)
		// Stale basis is never silently retryable — the form locks and the
		// dialog status tells the user to reload instead of resubmitting.
		expect(document.getElementById('correction-wizard-submit').disabled).toBe(true)
		expect(document.getElementById('correction-dialog-status').textContent)
			.toContain('changed in the meantime')
	})
})
