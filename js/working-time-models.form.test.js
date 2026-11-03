import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Editor panel structure: the four scalar model fields sit in the 2×2
 * scan grid, and the description is a plain labelled textarea — never a
 * click-to-open disclosure (see working-time-models-inline-editor.spec.js
 * for the live variant).
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
  }
  window.ArbeitszeitCheckWeekdaySchedule = {
    sectionHtml: () => '<fieldset class="wtm-weekday-schedule"></fieldset>',
    bind: () => {},
  }
  window.ArbeitszeitCheckComponents = {}
  window.ArbeitszeitCheckMessaging = { showError: vi.fn() }
  window.ArbeitszeitCheck = {
    page: 'admin-working-time-models',
    l10n: {
      createModel: 'Create Working Time Model',
      create: 'Create',
      cancel: 'Cancel',
      name: 'Name',
      description: 'Description',
      descriptionOptional: 'Description (optional)',
      type: 'Type',
      weeklyHours: 'Weekly Hours',
      dailyHours: 'Daily Hours',
      workDaysPerWeek: 'Work days per week',
      isDefault: 'Set as Default',
    },
  }
  await import('./working-time-models.js')
})

describe('editor panel field grid', () => {
  it('groups type, weekly, daily and work-days in one .wtm-field-grid in that order', () => {
    const host = mountPanel('create', null)
    const grid = host.querySelector('.wtm-field-grid')

    expect(grid).toBeTruthy()
    const ids = Array.from(grid.querySelectorAll('.form-group input, .form-group select')).map((el) => el.id)
    expect(ids).toEqual([
      'wtm-model-type',
      'wtm-model-weekly-hours',
      'wtm-model-daily-hours',
      'wtm-model-work-days-per-week',
    ])
  })

  it('keeps name, checkboxes and the schedule outside the grid', () => {
    const host = mountPanel('create', null)
    const grid = host.querySelector('.wtm-field-grid')

    expect(grid.querySelector('#wtm-model-name')).toBeNull()
    expect(grid.querySelector('#wtm-model-is-default')).toBeNull()
    expect(grid.querySelector('.wtm-weekday-schedule')).toBeNull()
    expect(host.querySelector('#wtm-model-name')).toBeTruthy()
    expect(host.querySelector('.wtm-weekday-schedule')).toBeTruthy()
  })
})

describe('description field', () => {
  it('renders a visible labelled textarea — no details/summary disclosure', () => {
    const host = mountPanel('create', null)

    expect(host.querySelector('details')).toBeNull()
    expect(host.querySelector('.wtm-editor-desc')).toBeNull()
    expect(host.querySelector('summary')).toBeNull()

    const textarea = host.querySelector('#wtm-model-description')
    expect(textarea).toBeTruthy()
    expect(textarea.tagName).toBe('TEXTAREA')
    expect(textarea.hidden).toBe(false)

    const label = host.querySelector('label[for="wtm-model-description"]')
    expect(label).toBeTruthy()
    expect(label.classList.contains('visually-hidden')).toBe(false)
    expect(label.textContent.trim()).toBe('Description (optional)')
  })

  it('shows existing description text directly in edit mode', () => {
    const host = mountPanel('edit', {
      id: 7,
      name: 'Teilzeit',
      description: 'Mittwoch frei',
      type: 'part_time',
      weeklyHours: 30,
      dailyHours: 6,
      workDaysPerWeek: 5,
    })

    const textarea = host.querySelector('#wtm-model-description')
    expect(textarea.value).toBe('Mittwoch frei')
    expect(textarea.closest('.form-group').querySelector('details')).toBeNull()
  })
})
