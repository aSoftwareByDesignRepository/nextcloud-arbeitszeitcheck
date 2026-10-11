import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * B-6 UE (teaching unit) helper coverage.
 *
 * Admins enter "27 UE à 45 min"; the helper converts to weekly hours on a
 * 0.25h grid, stamps a display-only label into break_rules.ue_label, and
 * drops that label the moment weekly hours are edited by hand.
 */

const T = () => window.__ArbeitszeitCheckWorkingTimeModelsTestables

function mountForm(existingLabel) {
  document.body.innerHTML = `
    <form id="wtm-form">
      <input type="text" id="wtm-model-weekly-hours" name="weeklyHours">
      ${T().ueHelperSectionHtml('wtm-model', existingLabel)}
    </form>`
  const form = document.getElementById('wtm-form')
  T().bindUeHelper(form.querySelector('.wtm-ue-helper'), form)
  return form
}

function apply(form, count, minutes) {
  form.querySelector('.wtm-ue-count').value = count
  form.querySelector('.wtm-ue-minutes').value = minutes
  form.querySelector('[data-action="apply-ue"]').click()
}

beforeEach(async () => {
  vi.resetModules()
  // Utils is captured at module top — must exist before import.
  window.ArbeitszeitCheckUtils = {
    escapeHtml: (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
    $: (sel) => document.querySelector(sel),
    $$: (sel) => Array.from(document.querySelectorAll(sel)),
    on: (el, ev, fn) => el.addEventListener(ev, fn),
  }
  window.ArbeitszeitCheck = { page: 'none' }
  document.body.innerHTML = ''
  await import('./working-time-models.js')
})

describe('computeUeConversion', () => {
  it('converts 27 x 45 min to 20.25 h exactly', () => {
    const conv = T().computeUeConversion('27', '45')
    expect(conv.hours).toBe(20.25)
    expect(conv.rounded).toBe(20.25)
    expect(conv.wasRounded).toBe(false)
    expect(conv.label).toBe('27 UE à 45 min')
  })

  it('accepts comma decimals and rounds to the 0.25 h grid', () => {
    // 27,5 x 40 = 1100 min = 18.3333h -> 18.25 with rounding notice
    const conv = T().computeUeConversion('27,5', '40')
    expect(conv.rounded).toBe(18.25)
    expect(conv.wasRounded).toBe(true)
  })

  it('rounds down/up correctly at grid boundaries', () => {
    expect(T().computeUeConversion('1', '8').rounded).toBe(0.25)   // 0.1333 -> 0.25
    expect(T().computeUeConversion('3', '15').rounded).toBe(0.75)  // 0.75 exact
    expect(T().computeUeConversion('3', '16').rounded).toBe(0.75)  // 0.8 -> 0.75
  })

  it.each([
    ['', '45'],
    ['abc', '45'],
    ['27', ''],
    ['0', '45'],
    ['-2', '45'],
    ['27', '0'],
    ['27', '-45'],
    ['27', '481'],   // minutes hard cap
  ])('rejects invalid input %j x %j', (count, minutes) => {
    expect(T().computeUeConversion(count, minutes)).toBeNull()
  })

  it('accepts the maximum sane minutes value', () => {
    expect(T().computeUeConversion('1', '480').rounded).toBe(8)
  })
})

describe('UE helper DOM flow', () => {
  it('fills weekly hours and stamps the label on apply', () => {
    const form = mountForm(null)
    apply(form, '27', '45')
    expect(form.querySelector('#wtm-model-weekly-hours').value).toBe('20.25')
    expect(form.dataset.ueLabel).toBe('27 UE à 45 min')
    expect(form.querySelector('.wtm-ue-result').textContent).toContain('20.25')
  })

  it('shows a rounding notice for crooked inputs', () => {
    const form = mountForm(null)
    apply(form, '27', '43')
    expect(form.querySelector('.wtm-ue-result').textContent).toContain('Rounded to the quarter-hour')
  })

  it('warns when the result exceeds the 80 h weekly cap', () => {
    const form = mountForm(null)
    apply(form, '200', '45')  // 150 h
    expect(form.querySelector('.wtm-ue-result').textContent).toContain('80')
  })

  it('rejects invalid input without touching weekly hours or the label', () => {
    const form = mountForm(null)
    form.querySelector('#wtm-model-weekly-hours').value = '38.5'
    apply(form, '', '45')
    expect(form.dataset.ueLabel).toBeUndefined()
    expect(form.querySelector('#wtm-model-weekly-hours').value).toBe('38.5')
    expect(form.querySelector('.wtm-ue-result').textContent).not.toBe('')
  })

  it('manual edit of weekly hours clears the label and marks ueCleared', () => {
    const form = mountForm(null)
    apply(form, '27', '45')
    const weekly = form.querySelector('#wtm-model-weekly-hours')
    weekly.value = '36'
    weekly.dispatchEvent(new Event('input', { bubbles: true }))
    expect(form.dataset.ueLabel).toBeUndefined()
    expect(form.dataset.ueCleared).toBe('1')
    expect(form.querySelector('.wtm-ue-result').textContent).toBe('')
  })

  it('existing stored label is removed when weekly hours are edited', () => {
    const form = mountForm('26 UE à 45 min')
    expect(form.querySelector('.wtm-ue-existing')).not.toBeNull()
    const weekly = form.querySelector('#wtm-model-weekly-hours')
    weekly.value = '30'
    weekly.dispatchEvent(new Event('input', { bubbles: true }))
    expect(form.dataset.ueCleared).toBe('1')
    expect(form.querySelector('.wtm-ue-existing')).toBeNull()
  })

  it('applying a new conversion over a stored label keeps the new label', () => {
    const form = mountForm('26 UE à 45 min')
    apply(form, '30', '45')
    // The apply-dispatched input event may have set ueCleared, but the fresh
    // label must win on save (collectFormData checks ueLabel first).
    expect(form.dataset.ueLabel).toBe('30 UE à 45 min')
  })
})
