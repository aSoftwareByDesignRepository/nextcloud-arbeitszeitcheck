import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Break-tier editor ("Pausenregeln (Verschärfung)"): rows of
 * afterHours → breakMinutes plus min_break_minutes. Prefill is tolerant
 * (malformed stored rows are dropped with a hint so a save self-heals);
 * the payload writer sets/clears keys explicitly.
 */

const T = () => window.__ArbeitszeitCheckWorkingTimeModelsTestables

function mountPanel(mode, model) {
  document.body.innerHTML = '<section id="wtm-editor-panel" hidden></section>'
  T().openEditorPanel(mode, model)
  return document.getElementById('wtm-editor-panel')
}

beforeEach(async () => {
  vi.resetModules()
  window.ArbeitszeitCheckUtils = {
    escapeHtml: (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
    $: (sel) => document.querySelector(sel),
    $$: (sel) => Array.from(document.querySelectorAll(sel)),
    on: (el, ev, fn) => el.addEventListener(ev, fn),
    ajax: vi.fn(),
  }
  window.ArbeitszeitCheckWeekdaySchedule = {
    sectionHtml: () => '<fieldset class="wtm-weekday-schedule"></fieldset>',
    bind: () => {},
    collect: () => null,
  }
  window.ArbeitszeitCheckComponents = {}
  window.ArbeitszeitCheckMessaging = { showError: vi.fn() }
  window.ArbeitszeitCheck = {
    page: 'admin-working-time-models',
    l10n: {},
  }
  await import('./working-time-models.js')
})

describe('break-tier editor prefill', () => {
  it('renders stored valid tiers as rows and the floor value', () => {
    const host = mountPanel('edit', {
      id: 7,
      name: '35h',
      type: 'full_time',
      breakRules: {
        tiers: [
          { afterHours: 9, breakMinutes: 180 },
          { afterHours: 6, breakMinutes: 30 },
        ],
        min_break_minutes: 20,
      },
    })
    const rows = host.querySelectorAll('[data-break-tier-row]')
    expect(rows).toHaveLength(2)
    expect(rows[0].querySelector('.wtm-tier-after').value).toBe('9')
    expect(rows[0].querySelector('.wtm-tier-minutes').value).toBe('180')
    expect(host.querySelector('.wtm-min-break-minutes').value).toBe('20')
    expect(host.querySelector('.wtm-tier-dropped')).toBeNull()
  })

  it('drops malformed stored tiers and shows the cleanup hint', () => {
    const host = mountPanel('edit', {
      id: 8,
      name: 'broken',
      type: 'full_time',
      breakRules: {
        tiers: [
          { afterHours: 'abc', breakMinutes: 180 },
          { afterHours: 9, breakMinutes: 180 },
          'not-a-tier',
        ],
      },
    })
    const rows = host.querySelectorAll('[data-break-tier-row]')
    expect(rows).toHaveLength(1)
    expect(rows[0].querySelector('.wtm-tier-after').value).toBe('9')
    const hint = host.querySelector('.wtm-tier-dropped')
    expect(hint).toBeTruthy()
    expect(hint.textContent).toMatch(/invalid|ungültig/i)
  })

  it('shows the empty state for a fresh model', () => {
    const host = mountPanel('create', null)
    expect(host.querySelectorAll('[data-break-tier-row]')).toHaveLength(0)
    const empty = host.querySelector('.wtm-tier-empty')
    expect(empty.hidden).toBe(false)
  })
})

describe('buildBreakRulesPayload tiers', () => {
  it('includes collected tiers and floor in the payload', () => {
    const host = mountPanel('create', null)
    const form = host.querySelector('#wtm-model-form')
    // Add two rows via the add button.
    const fieldset = form.querySelector('.wtm-break-tiers')
    const addBtn = fieldset.querySelector('[data-action="add-break-tier"]')
    addBtn.click()
    addBtn.click()
    const rows = fieldset.querySelectorAll('[data-break-tier-row]')
    rows[0].querySelector('.wtm-tier-after').value = '6'
    rows[0].querySelector('.wtm-tier-minutes').value = '30'
    rows[1].querySelector('.wtm-tier-after').value = '9'
    rows[1].querySelector('.wtm-tier-minutes').value = '180'
    fieldset.querySelector('.wtm-min-break-minutes').value = '20'

    const payload = T().buildBreakRulesPayload(form, null)
    expect(payload.error).toBeUndefined()
    expect(payload.tiers).toEqual([
      { afterHours: 6, breakMinutes: 30 },
      { afterHours: 9, breakMinutes: 180 },
    ])
    expect(payload.min_break_minutes).toBe(20)
  })

  it('returns an error for a half-filled row', () => {
    const host = mountPanel('create', null)
    const form = host.querySelector('#wtm-model-form')
    const fieldset = form.querySelector('.wtm-break-tiers')
    fieldset.querySelector('[data-action="add-break-tier"]').click()
    fieldset.querySelector('.wtm-tier-after').value = '9'
    const payload = T().buildBreakRulesPayload(form, null)
    expect(payload.field).toBe('breakTiers')
    expect(payload.error).toBeTruthy()
  })

  it('clears tiers/floor keys from an existing doc when the editor is emptied', () => {
    const host = mountPanel('edit', {
      id: 9,
      name: 'has tiers',
      type: 'full_time',
      breakRules: {
        tiers: [{ afterHours: 9, breakMinutes: 180 }],
        min_break_minutes: 20,
        ue_label: 'keep-me',
      },
    })
    const form = host.querySelector('#wtm-model-form')
    // Remove the prefilled row and clear the floor.
    form.querySelector('[data-action="remove-break-tier"]').click()
    form.querySelector('.wtm-min-break-minutes').value = ''

    const payload = T().buildBreakRulesPayload(form, {
      tiers: [{ afterHours: 9, breakMinutes: 180 }],
      min_break_minutes: 20,
      ue_label: 'keep-me',
    })
    expect(payload.tiers).toBeUndefined()
    expect(payload.min_break_minutes).toBeUndefined()
    expect(payload.ue_label).toBe('keep-me')
  })

  it('preserves unknown stored keys via passthrough', () => {
    const host = mountPanel('create', null)
    const form = host.querySelector('#wtm-model-form')
    const payload = T().buildBreakRulesPayload(form, { future_key: { a: 1 } })
    expect(payload.future_key).toEqual({ a: 1 })
  })
})
