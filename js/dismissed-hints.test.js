import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * B-2 regression coverage for dismissed-hint persistence.
 *
 * The server stores the WHOLE dismissed set (replace semantics). The page
 * only renders its own hints, so a naive "rebuild from DOM" write would
 * silently drop dismissals of hints living on other pages. The fix merges
 * the server-rendered set (#azc-dismissed-hints-data) with the live DOM
 * state of page-local hints.
 */

const snapshot = {}

beforeEach(async () => {
  snapshot.ArbeitszeitCheck = globalThis.window.ArbeitszeitCheck
  snapshot.ArbeitszeitCheckApp = globalThis.window.ArbeitszeitCheckApp
  vi.resetModules()
  globalThis.window.ArbeitszeitCheck = { page: 'none' }
  document.body.innerHTML = ''
  await import('./arbeitszeitcheck-main.js')
})

afterEach(() => {
  globalThis.window.ArbeitszeitCheck = snapshot.ArbeitszeitCheck
  globalThis.window.ArbeitszeitCheckApp = snapshot.ArbeitszeitCheckApp
  vi.restoreAllMocks()
})

function lastPostedBody() {
  const call = globalThis.fetch.mock.calls[0]
  return JSON.parse(call[1].body)
}

describe('persistDismissedHints', () => {
  it('keeps dismissals of hints that are not rendered on this page', async () => {
    document.body.innerHTML = `
      <script type="application/json" id="azc-dismissed-hints-data">["other-page-hint"]</script>
      <div class="azc-hint" data-hint="time-entries-export-hint">
        <p class="azc-hint__body" hidden></p>
      </div>`
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true }) }))

    const ok = await window.ArbeitszeitCheckApp.persistDismissedHints('time-entries-export-hint', true)

    expect(ok).toBe(true)
    expect(lastPostedBody().dismissed_hints).toEqual(
      expect.arrayContaining(['other-page-hint', 'time-entries-export-hint'])
    )
  })

  it('drops a locally re-shown hint while keeping foreign dismissals', async () => {
    document.body.innerHTML = `
      <script type="application/json" id="azc-dismissed-hints-data">["other-page-hint","time-entries-export-hint"]</script>
      <div class="azc-hint" data-hint="time-entries-export-hint">
        <p class="azc-hint__body"></p>
      </div>`
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true }) }))

    await window.ArbeitszeitCheckApp.persistDismissedHints('time-entries-export-hint', false)

    expect(lastPostedBody().dismissed_hints).toEqual(['other-page-hint'])
  })

  it('falls back to DOM state when the data tag is absent', async () => {
    document.body.innerHTML = `
      <div class="azc-hint" data-hint="only-hint"><p class="azc-hint__body" hidden></p></div>`
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true }) }))

    await window.ArbeitszeitCheckApp.persistDismissedHints('only-hint', true)

    expect(lastPostedBody().dismissed_hints).toEqual(['only-hint'])
  })

  it('falls back to DOM state when the data tag is malformed', async () => {
    document.body.innerHTML = `
      <script type="application/json" id="azc-dismissed-hints-data">{broken</script>
      <div class="azc-hint" data-hint="only-hint"><p class="azc-hint__body" hidden></p></div>`
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true }) }))

    await window.ArbeitszeitCheckApp.persistDismissedHints('only-hint', true)

    expect(lastPostedBody().dismissed_hints).toEqual(['only-hint'])
  })

  it('resolves false when the settings write fails', async () => {
    document.body.innerHTML = '<div class="azc-hint" data-hint="h"><p class="azc-hint__body" hidden></p></div>'
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: false, json: () => Promise.resolve({}) }))

    const ok = await window.ArbeitszeitCheckApp.persistDismissedHints('h', true)

    expect(ok).toBe(false)
  })
})
