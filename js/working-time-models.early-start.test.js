import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Earliest counted start (spec B): optional per-model clamp of the counted
 * work start. The editor collects earliest_counted_start + early_start_mode
 * + early_start_anchor into break_rules, sends explicit nulls on empty
 * input (absent keys are preserved server-side — B-R8, so a missing key can
 * never clear), validates HH:MM client-side, and toggles the mode group +
 * DST hint.
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

describe('early-start editor render', () => {
  it('prefills stored values and shows the mode group + DST hint', () => {
    const host = mountPanel('edit', {
      id: 7,
      name: 'shift',
      type: 'shift_work',
      breakRules: {
        earliest_counted_start: '08:00',
        early_start_mode: 'clamp_unless_approved',
        early_start_anchor: 'calendar_day',
      },
    })
    expect(host.querySelector('#wtm-model-earliest-counted-start').value).toBe('08:00')
    expect(host.querySelector('#wtm-model-early-start-mode').value).toBe('clamp_unless_approved')
    expect(host.querySelector('#wtm-model-early-start-mode-group').hidden).toBe(false)
    expect(host.querySelector('#wtm-model-early-start-dst-warning').hidden).toBe(false)
  })

  it('hides the mode group + DST hint when nothing is configured', () => {
    const host = mountPanel('create', null)
    expect(host.querySelector('#wtm-model-earliest-counted-start').value).toBe('')
    expect(host.querySelector('#wtm-model-early-start-mode-group').hidden).toBe(true)
    expect(host.querySelector('#wtm-model-early-start-dst-warning').hidden).toBe(true)
  })
})

describe('buildBreakRulesPayload early-start keys', () => {
  it('writes all three keys when a start is configured', () => {
    const host = mountPanel('create', null)
    const form = host.querySelector('#wtm-model-form')
    form.querySelector('#wtm-model-earliest-counted-start').value = '07:30'
    form.querySelector('#wtm-model-early-start-mode').value = 'clamp_unless_approved'

    const payload = T().buildBreakRulesPayload(form, null)
    expect(payload.error).toBeUndefined()
    expect(payload.earliest_counted_start).toBe('07:30')
    expect(payload.early_start_mode).toBe('clamp_unless_approved')
    expect(payload.early_start_anchor).toBe('calendar_day')
  })

  it('sends explicit nulls on empty input (explicit clear — absent keys are preserved server-side)', () => {
    const host = mountPanel('edit', {
      id: 7,
      name: 'shift',
      type: 'shift_work',
      breakRules: {
        earliest_counted_start: '08:00',
        early_start_mode: 'clamp',
        early_start_anchor: 'calendar_day',
      },
    })
    const form = host.querySelector('#wtm-model-form')
    form.querySelector('#wtm-model-earliest-counted-start').value = ''

    const payload = T().buildBreakRulesPayload(form, {
      earliest_counted_start: '08:00',
      early_start_mode: 'clamp',
      early_start_anchor: 'calendar_day',
    })
    expect(payload.error).toBeUndefined()
    // Server contract (B-R8): keys absent from breakRules keep their stored
    // value — an explicit clear must send null/'' so the write boundary
    // treats it as a deliberate removal, not an omission.
    expect(payload.earliest_counted_start).toBeNull()
    expect(payload.early_start_mode).toBeNull()
    expect(payload.early_start_anchor).toBeNull()
  })

  it('defaults early_start_mode to clamp when the select is untouched', () => {
    const host = mountPanel('create', null)
    const form = host.querySelector('#wtm-model-form')
    form.querySelector('#wtm-model-earliest-counted-start').value = '06:00'

    const payload = T().buildBreakRulesPayload(form, null)
    expect(payload.error).toBeUndefined()
    expect(payload.earliest_counted_start).toBe('06:00')
    expect(payload.early_start_mode).toBe('clamp')
    expect(payload.early_start_anchor).toBe('calendar_day')
  })
})
