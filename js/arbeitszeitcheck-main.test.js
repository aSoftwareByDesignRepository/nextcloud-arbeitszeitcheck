import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Regression coverage for the destructive-confirmation wrapper used by the
 * time-entry delete, paused-completion and absence-cancel flows.
 *
 * In Nextcloud 31+ OC.dialogs.confirmDestructive() returns a Promise whose
 * resolution value is ALWAYS `undefined` (its internal `.then(() => { ... })`
 * has no `return` statement) — the user's actual choice is delivered via the
 * callback parameter only. A previous version of `confirmDestructiveMain`
 * trusted the Promise value, which caused every confirmation to resolve as
 * "cancelled" and silently swallowed the destructive action (e.g. clicking
 * "Yes" in the delete-time-entry dialog did nothing). These tests pin the
 * correct behaviour so the bug cannot reappear.
 */

const snapshot = {}

beforeEach(async () => {
  // The IIFE in arbeitszeitcheck-main.js captures `window.OC` by reference at
  // import time, so we mutate that same object instead of replacing it.
  snapshot.OCDialogs = globalThis.OC ? globalThis.OC.dialogs : undefined
  snapshot.AzcComponents = globalThis.window.AzcComponents
  snapshot.ArbeitszeitCheckComponents = globalThis.window.ArbeitszeitCheckComponents
  snapshot.ArbeitszeitCheck = globalThis.window.ArbeitszeitCheck
  snapshot.ArbeitszeitCheckApp = globalThis.window.ArbeitszeitCheckApp
  vi.resetModules()

  // Load the IIFE module which attaches `window.ArbeitszeitCheckApp`.
  // Set `page: 'none'` so init() does not try to wire up real DOM listeners.
  globalThis.window.ArbeitszeitCheck = { page: 'none' }
  delete globalThis.window.AzcComponents
  delete globalThis.window.ArbeitszeitCheckComponents
  await import('./arbeitszeitcheck-main.js')
})

afterEach(() => {
  if (globalThis.OC) {
    if (snapshot.OCDialogs === undefined) {
      delete globalThis.OC.dialogs
    } else {
      globalThis.OC.dialogs = snapshot.OCDialogs
    }
  }
  globalThis.window.ArbeitszeitCheck = snapshot.ArbeitszeitCheck
  globalThis.window.AzcComponents = snapshot.AzcComponents
  globalThis.window.ArbeitszeitCheckComponents = snapshot.ArbeitszeitCheckComponents
  globalThis.window.ArbeitszeitCheckApp = snapshot.ArbeitszeitCheckApp
})

/**
 * Mimic Nextcloud 31/32/33 `OC.dialogs.confirmDestructive`:
 *  - the button callback delivers the real user choice
 *  - the returned Promise always resolves to `undefined`
 *  - in YES_NO mode the post-show fallback ALSO calls the callback with `false`
 *    (because NC sets `callback._clicked` but checks `callback.clicked`).
 *
 * `simulatedClick` is the choice the simulated user makes:
 *   true  → clicks the "Yes" button
 *   false → clicks the "No" button
 *   null  → closes via ESC / backdrop (no button)
 */
function mockNcConfirmDestructive(simulatedClick) {
  return vi.fn((text, title, buttons, callback) => {
    return Promise.resolve().then(() => {
      // Button click happens BEFORE the dialog-close promise resolves.
      if (simulatedClick === true) {
        callback._clicked = true
        callback(true)
      } else if (simulatedClick === false) {
        callback._clicked = true
        callback(false)
      }
      // Post-show fallback (NC's `.then`): looks at `callback.clicked` not
      // `_clicked`, so it ALWAYS fires `callback(false)` for legacy buttons.
      if (!callback.clicked) {
        callback(false)
      }
      // The returned Promise resolves to undefined regardless of choice.
      return undefined
    })
  })
}

describe('ArbeitszeitCheck.confirmDestructiveMain', () => {
  it('resolves to true when the user clicks "Yes" (NC 31+ promise+callback)', async () => {
    const cd = mockNcConfirmDestructive(true)
    globalThis.OC.dialogs = { confirmDestructive: cd, YES_NO_BUTTONS: 70 }

    const app = globalThis.window.ArbeitszeitCheckApp
    const result = await app.confirmDestructiveMain('Delete?', 'Confirm', {
      type: 70,
      modal: true,
    })

    expect(result).toBe(true)
    expect(cd).toHaveBeenCalledTimes(1)
  })

  it('resolves to false when the user clicks "No"', async () => {
    const cd = mockNcConfirmDestructive(false)
    globalThis.OC.dialogs = { confirmDestructive: cd, YES_NO_BUTTONS: 70 }

    const app = globalThis.window.ArbeitszeitCheckApp
    const result = await app.confirmDestructiveMain('Delete?', 'Confirm', {
      type: 70,
      modal: true,
    })

    expect(result).toBe(false)
  })

  it('resolves to false when the dialog is dismissed without a button click', async () => {
    const cd = mockNcConfirmDestructive(null)
    globalThis.OC.dialogs = { confirmDestructive: cd, YES_NO_BUTTONS: 70 }

    const app = globalThis.window.ArbeitszeitCheckApp
    const result = await app.confirmDestructiveMain('Delete?', 'Confirm', {
      type: 70,
      modal: true,
    })

    expect(result).toBe(false)
  })

  it('uses AzcComponents.confirmDialog when available', async () => {
    const confirmDialog = vi.fn().mockResolvedValue(true)
    globalThis.window.AzcComponents = { confirmDialog }
    delete globalThis.OC.dialogs

    const app = globalThis.window.ArbeitszeitCheckApp
    const result = await app.confirmDestructiveMain('Delete?', 'Confirm', {})

    expect(confirmDialog).toHaveBeenCalledTimes(1)
    expect(confirmDialog).toHaveBeenCalledWith(expect.objectContaining({
      title: 'Confirm',
      message: 'Delete?',
      variant: 'danger',
    }))
    expect(result).toBe(true)
  })

  it('resolves to false when confirmDialog is unavailable and OC.dialogs is missing', async () => {
    delete globalThis.window.AzcComponents
    delete globalThis.window.ArbeitszeitCheckComponents
    delete globalThis.OC.dialogs

    const app = globalThis.window.ArbeitszeitCheckApp
    const result = await app.confirmDestructiveMain('Delete?', 'Confirm', {})

    expect(result).toBe(false)
  })

  it('resolves to false when OC.dialogs.confirmDestructive throws', async () => {
    delete globalThis.window.AzcComponents
    delete globalThis.window.ArbeitszeitCheckComponents
    globalThis.OC.dialogs = {
      confirmDestructive: () => {
        throw new Error('boom')
      },
      YES_NO_BUTTONS: 70,
    }

    const app = globalThis.window.ArbeitszeitCheckApp
    const result = await app.confirmDestructiveMain('Delete?', 'Confirm', {
      type: 70,
      modal: true,
    })

    expect(result).toBe(false)
  })
})

/**
 * Per-surface open → cancel (no mutate) → confirm (mutates) for the four
 * product dialogs that call confirmDestructiveMain. Shared wrapper unit tests
 * above are not enough under Atlas CRUD-DIALOGS.
 */
describe('main confirm call-sites open→cancel→confirm', () => {
  async function mountRealConfirmShell() {
    document.body.innerHTML = `
      <header id="header"></header>
      <nav id="app-navigation"></nav>
      <main id="azc-main-content" tabindex="-1"></main>`
    delete globalThis.window.AzcComponents
    delete globalThis.window.ArbeitszeitCheckComponents
    await import('./common/components.js')
    globalThis.window.AzcComponents = globalThis.window.ArbeitszeitCheckComponents
  }

  async function clickCancelThenConfirm(triggerClick) {
    triggerClick()
    await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__cancel')).toBeTruthy())
    document.querySelector('.confirm-dialog__cancel').click()
    await vi.waitFor(() => expect(document.querySelector('.confirm-dialog')).toBeFalsy())

    triggerClick()
    await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__confirm')).toBeTruthy())
    document.querySelector('.confirm-dialog__confirm').click()
    await vi.waitFor(() => expect(document.querySelector('.confirm-dialog')).toBeFalsy())
  }

  beforeEach(async () => {
    snapshot.OCDialogs = globalThis.OC ? globalThis.OC.dialogs : undefined
    snapshot.AzcComponents = globalThis.window.AzcComponents
    snapshot.ArbeitszeitCheckComponents = globalThis.window.ArbeitszeitCheckComponents
    snapshot.ArbeitszeitCheck = globalThis.window.ArbeitszeitCheck
    snapshot.ArbeitszeitCheckApp = globalThis.window.ArbeitszeitCheckApp
    vi.resetModules()
    globalThis.window.ArbeitszeitCheck = { page: 'none', mainUiStrings: {} }
    delete globalThis.OC.dialogs
    await mountRealConfirmShell()
    await import('./arbeitszeitcheck-main.js')
  })

  afterEach(() => {
    document.body.innerHTML = ''
    if (globalThis.OC) {
      if (snapshot.OCDialogs === undefined) {
        delete globalThis.OC.dialogs
      } else {
        globalThis.OC.dialogs = snapshot.OCDialogs
      }
    }
    globalThis.window.ArbeitszeitCheck = snapshot.ArbeitszeitCheck
    globalThis.window.AzcComponents = snapshot.AzcComponents
    globalThis.window.ArbeitszeitCheckComponents = snapshot.ArbeitszeitCheckComponents
    globalThis.window.ArbeitszeitCheckApp = snapshot.ArbeitszeitCheckApp
  })

  it('clock-out: cancel skips clockOut; confirm calls clockOut', async () => {
    const app = globalThis.window.ArbeitszeitCheckApp
    const clockOut = vi.fn()
    app.clockOut = clockOut
    document.body.insertAdjacentHTML('beforeend', '<button type="button" class="btn-clock-out">Clock out</button>')
    app.initClockButtons()

    await clickCancelThenConfirm(() => {
      document.querySelector('.btn-clock-out').click()
    })

    await vi.waitFor(() => expect(clockOut).toHaveBeenCalledTimes(1))
  })

  it('generic data-delete-endpoint: cancel skips DELETE; confirm calls API', async () => {
    const app = globalThis.window.ArbeitszeitCheckApp
    const callApi = vi.fn().mockResolvedValue({ success: true })
    app.callApi = callApi
    document.body.insertAdjacentHTML(
      'beforeend',
      '<button type="button" data-delete-endpoint="/apps/arbeitszeitcheck/api/x/1">Delete</button>',
    )
    app.initEventListeners()

    await clickCancelThenConfirm(() => {
      document.querySelector('[data-delete-endpoint]').click()
    })

    await vi.waitFor(() => expect(callApi).toHaveBeenCalledTimes(1))
    expect(callApi).toHaveBeenCalledWith('/apps/arbeitszeitcheck/api/x/1', 'DELETE')
  })

  it('complete paused: cancel skips POST; confirm completes', async () => {
    const app = globalThis.window.ArbeitszeitCheckApp
    const callApi = vi.fn().mockResolvedValue({ success: true, message: 'ok' })
    app.callApi = callApi
    app.showSuccess = vi.fn()
    app.resolveRequestUrl = (u) => u
    const reload = vi.fn()
    const locationDescriptor = Object.getOwnPropertyDescriptor(window, 'location')
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, reload },
    })

    document.body.insertAdjacentHTML(
      'beforeend',
      '<button type="button" class="btn-complete-entry" data-entry-id="42">Complete</button>',
    )
    app.attachCompletePausedHandlers()

    await clickCancelThenConfirm(() => {
      document.querySelector('.btn-complete-entry').click()
    })

    await vi.waitFor(() => expect(callApi).toHaveBeenCalledTimes(1))
    expect(callApi.mock.calls[0][0]).toContain('/api/time-entries/42/complete')
    expect(callApi.mock.calls[0][1]).toBe('POST')

    if (locationDescriptor) {
      Object.defineProperty(window, 'location', locationDescriptor)
    }
  })

  it('absence cancel: cancel skips DELETE; confirm deletes', async () => {
    const app = globalThis.window.ArbeitszeitCheckApp
    const callApi = vi.fn().mockResolvedValue({ success: true })
    app.callApi = callApi
    app.showSuccess = vi.fn()
    app.config = { l10n: {}, apiUrl: {} }
    globalThis.OC.generateUrl = (u) => u

    document.body.insertAdjacentHTML(
      'beforeend',
      `<table><tbody>
        <tr><td><button type="button" class="btn-icon--cancel" data-absence-id="9">Cancel</button></td></tr>
      </tbody></table>`,
    )
    app.initAbsences()

    await clickCancelThenConfirm(() => {
      document.querySelector('.btn-icon--cancel[data-absence-id]').click()
    })

    await vi.waitFor(() => expect(callApi).toHaveBeenCalledTimes(1))
    expect(String(callApi.mock.calls[0][0])).toContain('/api/absences/9')
    expect(callApi.mock.calls[0][1]).toBe('DELETE')
  })

  it('time-entry delete: cancel skips DELETE after impact; confirm deletes', async () => {
    const app = globalThis.window.ArbeitszeitCheckApp
    const callApi = vi.fn((url, method) => {
      if (method === 'GET' || String(url).includes('deletion-impact')) {
        return Promise.resolve({ impact: { canDelete: true, warnings: [] } })
      }
      return Promise.resolve({ success: true })
    })
    app.callApi = callApi
    app.showSuccess = vi.fn()
    app.resolveRequestUrl = (u) => u
    app.config = { l10n: {}, apiUrl: {} }
    globalThis.OC.generateUrl = (u) => u

    document.body.insertAdjacentHTML(
      'beforeend',
      `<table><tbody>
        <tr><td><button type="button" class="btn-delete" data-entry-id="77">Delete</button></td></tr>
      </tbody></table>`,
    )
    app.initTimeEntries()

    // First open → cancel: impact GET happens, DELETE must not.
    document.querySelector('.btn-delete').click()
    await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__cancel')).toBeTruthy())
    document.querySelector('.confirm-dialog__cancel').click()
    await vi.waitFor(() => expect(document.querySelector('.confirm-dialog')).toBeFalsy())
    expect(callApi.mock.calls.some((c) => c[1] === 'DELETE')).toBe(false)

    // Re-enable button (handler sets busy during impact load).
    const btn = document.querySelector('.btn-delete')
    btn.disabled = false
    btn.removeAttribute('aria-busy')

    document.querySelector('.btn-delete').click()
    await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__confirm')).toBeTruthy())
    document.querySelector('.confirm-dialog__confirm').click()
    await vi.waitFor(() => expect(callApi.mock.calls.some((c) => c[1] === 'DELETE')).toBe(true))
  })
})
