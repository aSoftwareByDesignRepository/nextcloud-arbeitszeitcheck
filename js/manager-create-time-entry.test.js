import { beforeEach, describe, expect, it, vi } from 'vitest'

import './common/time.js'
import './common/time-entry-clock-form.js'

function flush() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

function installGlobals({ projectCheckEnabled = true } = {}) {
  window.OC = {
    generateUrl: (tpl, params) => tpl.replace('{employeeId}', params.employeeId),
    requestToken: 'token',
  }
  window.ArbeitszeitCheckUtils = {
    ajax: vi.fn(() => ({ finally: (cb) => { cb(); return Promise.resolve() } })),
    buildAppUrl: (path) => path,
  }
  window.ArbeitszeitCheckMessaging = {
    showError: vi.fn(),
    showSuccess: vi.fn(),
    showWarning: vi.fn(),
  }
  window.ArbeitszeitCheckComponents = {
    createModal: ({ id, title, content }) => {
      const modal = document.createElement('div')
      modal.id = id
      modal.className = 'modal azc-dialog'
      modal.innerHTML = `<div class="modal-header"><h2>${title}</h2></div><div class="modal-body">${content}</div>`
      document.body.appendChild(modal)
      return modal
    },
    openModal: vi.fn(),
    closeModal: vi.fn((m) => m.remove()),
  }
  window.ArbeitszeitCheck = {
    projectCheckEnabled,
    l10n: {},
    tz: { display: 'Europe/Berlin', storage: 'Europe/Berlin' },
    initManagerScopedEmployeePicker: vi.fn((opts) => {
      window.__pickerOpts = opts
      return {
        getUserId: () => document.getElementById('mgr-create-employee-id').value,
        setSelection: (id) => { document.getElementById('mgr-create-employee-id').value = id },
      }
    }),
  }
  window.ArbeitszeitCheckTimePicker = {
    create: () => document.createElement('div'),
    initInRoot: (root) => root.querySelectorAll('[data-azc-timepicker]').forEach((h) => h.setAttribute('data-ready', '1')),
    setValue: (host, value) => { const h = host.querySelector('input[type="hidden"]'); if (h) h.value = value },
    setInvalid: () => {},
  }
}

function openDialog() {
  window.ArbeitszeitCheckManagerCreate.open()
  return document.getElementById('manager-create-time-entry-modal')
}

describe('manager-create-time-entry dialog', () => {
  beforeEach(async () => {
    document.body.innerHTML = `
      <button id="manager-open-create-time-entry"></button>
      <input type="hidden" id="employee-filter-id" value="">
      <input id="employee-filter-search" value="">
    `
    installGlobals()
    // The module is an IIFE binding window.* helpers at eval time — re-import
    // it after the mocks exist so it picks them up.
    vi.resetModules()
    await import('./manager-create-time-entry.js')
  })

  it('opens with correction chrome, form-group meta fields and a transparent form-actions footer', () => {
    const modal = openDialog()

    expect(modal).not.toBeNull()
    expect(modal.classList.contains('azc-manager-correction-modal')).toBe(true)
    expect(modal.querySelector('.modal-footer')).toBeNull()
    expect(modal.querySelector('.form-actions .btn-mgr-create-save')).not.toBeNull()

    const project = modal.querySelector('#mgr-create-project')
    expect(project.classList.contains('form-select')).toBe(true)
    expect(project.closest('.form-group')).not.toBeNull()
    expect(project.disabled).toBe(true)
    expect(project.options[0].textContent).toBe('No project link')

    expect(modal.querySelector('.manager-create-dialog__employee.form-group')).not.toBeNull()
    expect(modal.querySelectorAll('.manager-create-dialog__meta [data-azc-timepicker]').length).toBe(0)
    // work start/end + always-visible empty break row
    expect(modal.querySelectorAll('[data-azc-timepicker]').length).toBe(4)
    expect(modal.querySelector('#mgr-create-summary')).not.toBeNull()
  })

  it('keeps the select-variant fallback when the timepicker component is absent', () => {
    delete window.ArbeitszeitCheckTimePicker
    const modal = openDialog()

    expect(modal.querySelectorAll('[data-azc-timepicker]').length).toBe(0)
    expect(modal.querySelector('#mgr-create-start-hour')).not.toBeNull()
    expect(modal.querySelector('#mgr-create-summary')).toBeNull()
  })

  it('ignores a stale project-options response after the employee changed (race guard)', async () => {
    const deferred = []
    window.fetch = vi.fn(() => new Promise((resolve) => deferred.push(resolve)))

    const modal = openDialog()
    const sel = modal.querySelector('#mgr-create-project')

    window.__pickerOpts.onChange('u1', {})
    window.__pickerOpts.onChange('u2', {})
    expect(window.fetch).toHaveBeenCalledTimes(2)
    expect(sel.disabled).toBe(true)
    expect(sel.options[0].textContent).toBe('Loading…')

    // Stale response for u1 resolves last — must be dropped.
    deferred[1]({ json: () => Promise.resolve({ success: true, projects: [{ id: 7, displayName: 'U2 project' }] }) })
    await flush()
    deferred[0]({ json: () => Promise.resolve({ success: true, projects: [{ id: 9, displayName: 'STALE u1 project' }] }) })
    await flush()
    await flush()

    const labels = Array.from(sel.options).map((o) => o.textContent)
    expect(labels).toContain('U2 project')
    expect(labels).not.toContain('STALE u1 project')
    expect(sel.disabled).toBe(false)
  })

  it('posts the same payload contract on save', async () => {
    const modal = openDialog()

    document.getElementById('mgr-create-employee-id').value = 'u42'
    modal.querySelector('#mgr-create-date').value = '09.11.2026'
    modal.querySelector('#mgr-create-start-time').value = '09:00'
    modal.querySelector('#mgr-create-end-time').value = '17:00'
    modal.querySelector('#mgr-create-reason').value = 'approved late shift entry'

    modal.querySelector('.btn-mgr-create-save').click()

    const ajax = window.ArbeitszeitCheckUtils.ajax
    expect(ajax).toHaveBeenCalledTimes(1)
    const [url, cfg] = ajax.mock.calls[0]
    expect(url).toBe('/apps/arbeitszeitcheck/api/manager/employee-time-entries')
    expect(cfg.method).toBe('POST')
    expect(cfg.data).toEqual({
      userId: 'u42',
      reason: 'approved late shift entry',
      date: '09.11.2026',
      startTime: '09:00',
      endTime: '17:00',
    })
  })

  it('blocks save without an employee and focuses the picker', () => {
    const modal = openDialog()
    modal.querySelector('#mgr-create-date').value = '09.11.2026'
    modal.querySelector('#mgr-create-start-time').value = '09:00'
    modal.querySelector('#mgr-create-end-time').value = '17:00'
    modal.querySelector('#mgr-create-reason').value = 'approved late shift entry'

    modal.querySelector('.btn-mgr-create-save').click()

    expect(window.ArbeitszeitCheckUtils.ajax).not.toHaveBeenCalled()
    expect(modal.querySelector('.mgr-create-status').textContent).toBe('Select employee')
  })
})
