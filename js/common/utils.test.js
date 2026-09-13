import { describe, expect, it, vi } from 'vitest'

// utils.js attaches itself to window.ArbeitszeitCheckUtils
import './utils.js'

describe('ArbeitszeitCheckUtils', () => {
  it('escapeHtml escapes unsafe characters', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.escapeHtml('<script>alert("x")</script>')).toBe('&lt;script&gt;alert("x")&lt;/script&gt;')
  })

  it('dataLabelAttr and responsiveTd emit escaped data-label attributes', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.dataLabelAttr('Name')).toBe(' data-label="Name"')
    expect(u.dataLabelAttr('A & B')).toBe(' data-label="A &amp; B"')
    expect(u.responsiveTd('Actions', '<span>ok</span>', 'actions-cell')).toBe(
      '<td data-label="Actions" class="actions-cell"><span>ok</span></td>',
    )
  })

  it('encodeAttributeJson uses HTML entities so attributes round-trip through JSON.parse', () => {
    const u = window.ArbeitszeitCheckUtils
    const payload = { startTime: '2026-05-20T08:00:00+02:00', endTime: '2026-05-20T17:00:00+02:00' }
    const encoded = u.encodeAttributeJson(payload)
    expect(encoded).not.toContain('"')
    expect(encoded).toContain('&quot;startTime&quot;')
    expect(encoded).toContain('2026-05-20T08:00:00+02:00')

    document.body.innerHTML = '<button id="attr-json-test" data-entry-summary="' + encoded + '"></button>'
    const raw = document.getElementById('attr-json-test').getAttribute('data-entry-summary')
    expect(u.parseAttributeJson(raw)).toEqual(payload)
  })

  it('parseAttributeJson decodes legacy unicode-escaped attribute JSON', () => {
    const u = window.ArbeitszeitCheckUtils
    const legacy = '{\\u0022startTime\\u0022:\\u00222026-05-20T08:00:00+02:00\\u0022}'
    expect(u.parseAttributeJson(legacy)).toEqual({ startTime: '2026-05-20T08:00:00+02:00' })
  })

  it('parseAttributeJson rejects non-object payloads', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.parseAttributeJson('null')).toBeNull()
    expect(u.parseAttributeJson('[]')).toBeNull()
    expect(u.parseAttributeJson('"x"')).toBeNull()
  })

  it('isTimeEntryClockSummary requires ISO clock instants', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.isTimeEntryClockSummary({
      startTime: '2026-05-20T08:00:00+02:00',
      endTime: '2026-05-20T17:00:00+02:00',
    })).toBe(true)
    expect(u.isTimeEntryClockSummary({ startTime: '2026-05-20T08:00:00+02:00' })).toBe(false)
    expect(u.isTimeEntryClockSummary(null)).toBe(false)
  })

  it('createElement sets className and textContent and avoids implicit html', () => {
    const u = window.ArbeitszeitCheckUtils
    const el = u.createElement('div', { className: 'x', textContent: '<b>hi</b>' })
    expect(el.className).toBe('x')
    expect(el.textContent).toBe('<b>hi</b>')
    expect(el.innerHTML).toBe('&lt;b&gt;hi&lt;/b&gt;')
  })

  it('formatTime returns 24h time and handles invalid dates', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.formatTime('invalid')).toBe('00:00')
    expect(u.formatTime('2024-01-01T09:05:07Z')).toMatch(/^\d{2}:\d{2}$/)
    expect(u.formatTime('2024-01-01T09:05:07Z', true)).toMatch(/^\d{2}:\d{2}:\d{2}$/)
  })

  it('debounce delays invocation until wait elapsed', async () => {
    vi.useFakeTimers()
    const u = window.ArbeitszeitCheckUtils
    const fn = vi.fn()
    const debounced = u.debounce(fn, 100)

    debounced(1)
    debounced(2)
    expect(fn).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(99)
    expect(fn).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)
    expect(fn).toHaveBeenCalledTimes(1)
    expect(fn).toHaveBeenCalledWith(2)
    vi.useRealTimers()
  })

  it('resolveUrl normalizes app paths through OC.generateUrl', () => {
    const u = window.ArbeitszeitCheckUtils
    const originalGenerateUrl = window.OC.generateUrl
    const spy = vi.fn((path) => '/index.php' + path)
    window.OC.generateUrl = spy

    expect(u.resolveUrl('/apps/arbeitszeitcheck/api/admin/users')).toBe('/index.php/apps/arbeitszeitcheck/api/admin/users')
    expect(spy).toHaveBeenCalledWith('/apps/arbeitszeitcheck/api/admin/users')

    // Non-app absolute path must pass through unchanged.
    expect(u.resolveUrl('/ocs/v2.php/apps/notifications/api/v2/notifications')).toBe('/ocs/v2.php/apps/notifications/api/v2/notifications')

    window.OC.generateUrl = originalGenerateUrl
  })

  it('toSameOriginPath strips same-origin absolute URLs to root-relative paths', () => {
    const u = window.ArbeitszeitCheckUtils
    const origin = window.location.origin
    expect(u.toSameOriginPath(origin + '/index.php/apps/arbeitszeitcheck/api/x')).toBe('/index.php/apps/arbeitszeitcheck/api/x')
    expect(u.toSameOriginPath('/apps/arbeitszeitcheck/api/x')).toBe('/apps/arbeitszeitcheck/api/x')
    expect(u.toSameOriginPath('https://example.org/ping')).toBe('https://example.org/ping')
  })

  it('resolveUrl preserves already normalized /index.php app paths', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.resolveUrl('/index.php/apps/arbeitszeitcheck/api/admin/teams')).toBe('/index.php/apps/arbeitszeitcheck/api/admin/teams')
  })

  it('resolveUrl falls back to /index.php prefix when OC is unavailable', () => {
    const u = window.ArbeitszeitCheckUtils
    const previousWindowOc = window.OC
    const previousGlobalOc = globalThis.OC

    // Simulate page context with /index.php routing and no OC helpers.
    Object.defineProperty(window, 'location', {
      value: { origin: 'https://example.test', protocol: 'https:', pathname: '/index.php/apps/arbeitszeitcheck/admin/teams' },
      configurable: true,
    })
    window.OC = undefined
    globalThis.OC = undefined

    expect(u.resolveUrl('/apps/arbeitszeitcheck/api/admin/teams')).toBe('/index.php/apps/arbeitszeitcheck/api/admin/teams')

    window.OC = previousWindowOc
    globalThis.OC = previousGlobalOc
  })

  it('resolveUrl falls back to subdirectory webroot when OC is unavailable', () => {
    const u = window.ArbeitszeitCheckUtils
    const previousWindowOc = window.OC
    const previousGlobalOc = globalThis.OC

    Object.defineProperty(window, 'location', {
      value: { origin: 'https://example.test', protocol: 'https:', pathname: '/nextcloud/apps/arbeitszeitcheck/admin/dashboard' },
      configurable: true,
    })
    window.OC = undefined
    globalThis.OC = undefined

    expect(u.resolveUrl('/apps/arbeitszeitcheck/api/admin/dashboard-employees?filter=all&format=csv'))
      .toBe('/nextcloud/apps/arbeitszeitcheck/api/admin/dashboard-employees?filter=all&format=csv')

    window.OC = previousWindowOc
    globalThis.OC = previousGlobalOc
  })

  it('buildAppUrl delegates to resolveUrl', () => {
    const u = window.ArbeitszeitCheckUtils
    const originalGenerateUrl = window.OC.generateUrl
    window.OC.generateUrl = (path) => '/nextcloud' + path

    expect(u.buildAppUrl('/apps/arbeitszeitcheck/api/admin/users'))
      .toBe('/nextcloud/apps/arbeitszeitcheck/api/admin/users')

    window.OC.generateUrl = originalGenerateUrl
  })

  it('triggerDownload blocks external URLs', () => {
    const u = window.ArbeitszeitCheckUtils
    const assignMock = vi.fn()
    const previousLocation = window.location
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...previousLocation, assign: assignMock },
    })

    expect(u.triggerDownload('https://evil.example/export.csv')).toBe(false)
    expect(assignMock).not.toHaveBeenCalled()

    Object.defineProperty(window, 'location', { configurable: true, value: previousLocation })
  })

  it('triggerDownload navigates for same-origin app exports', () => {
    const u = window.ArbeitszeitCheckUtils
    const originalGenerateUrl = window.OC.generateUrl
    window.OC.generateUrl = (path) => '/nextcloud' + path
    const assignMock = vi.fn()
    const previousLocation = window.location
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...previousLocation, assign: assignMock },
    })

    expect(u.triggerDownload('/apps/arbeitszeitcheck/api/admin/dashboard-employees?format=csv')).toBe(true)
    expect(assignMock).toHaveBeenCalledWith('/nextcloud/apps/arbeitszeitcheck/api/admin/dashboard-employees?format=csv')

    window.OC.generateUrl = originalGenerateUrl
    Object.defineProperty(window, 'location', { configurable: true, value: previousLocation })
  })

  it('openDownload blocks external URLs', () => {
    const u = window.ArbeitszeitCheckUtils
    const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)

    expect(u.openDownload('https://evil.example/export.csv')).toBe(false)
    expect(openSpy).not.toHaveBeenCalled()

    openSpy.mockRestore()
  })

  it('isExternalUrl distinguishes same-origin from external origins', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.isExternalUrl('/apps/arbeitszeitcheck/api/admin/users')).toBe(false)
    expect(u.isExternalUrl(window.location.origin + '/apps/arbeitszeitcheck/api/admin/users')).toBe(false)
    expect(u.isExternalUrl('https://example.org/apps/arbeitszeitcheck/api/admin/users')).toBe(true)
  })

  it('normalizeMutatingFetchInit fills JSON {} on bare POST with Content-Type', () => {
    const u = window.ArbeitszeitCheckUtils
    const init = u.normalizeMutatingFetchInit({
      method: 'POST',
      headers: { 'Content-Type': 'application/json', requesttoken: 'tok' },
    })
    expect(init.body).toBe(JSON.stringify({}))
    expect(init.headers['Content-Type']).toBe('application/json')
  })

  it('normalizeMutatingFetchInit fills JSON {} on bare DELETE without Content-Type', () => {
    const u = window.ArbeitszeitCheckUtils
    const init = u.normalizeMutatingFetchInit({
      method: 'DELETE',
      headers: { requesttoken: 'tok' },
    })
    expect(init.body).toBe(JSON.stringify({}))
    expect(init.headers['Content-Type']).toBe('application/json')
  })

  it('normalizeMutatingFetchInit preserves explicit JSON bodies', () => {
    const u = window.ArbeitszeitCheckUtils
    const body = JSON.stringify({ licenseKey: 'AZC2' })
    const init = u.normalizeMutatingFetchInit({
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body,
    })
    expect(init.body).toBe(body)
  })

  it('normalizeMutatingFetchInit leaves GET untouched', () => {
    const u = window.ArbeitszeitCheckUtils
    const init = u.normalizeMutatingFetchInit({
      method: 'GET',
      headers: { Accept: 'application/json' },
    })
    expect(init.body).toBeUndefined()
  })

  it('ajax sends JSON {} body on bare mutating POST', async () => {
    const u = window.ArbeitszeitCheckUtils
    window.OC = { ...(window.OC || {}), requestToken: 'tok-ajax', generateUrl: (p) => p }
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ success: true }),
    })

    await u.ajax('/apps/arbeitszeitcheck/api/dashboard-widget/clock/out', { method: 'POST' })
    expect(fetchSpy).toHaveBeenCalledTimes(1)
    const init = fetchSpy.mock.calls[0][1]
    expect(init.method).toBe('POST')
    expect(init.body).toBe(JSON.stringify({}))
    expect(init.headers['Content-Type']).toBe('application/json')

    fetchSpy.mockRestore()
  })

  it('ajax preserves explicit JSON payloads on POST', async () => {
    const u = window.ArbeitszeitCheckUtils
    window.OC = { ...(window.OC || {}), requestToken: 'tok-ajax', generateUrl: (p) => p }
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ success: true }),
    })

    await u.ajax('/apps/arbeitszeitcheck/api/dashboard-widget/clock/in', {
      method: 'POST',
      data: { projectCheckProjectId: '9' },
    })
    const init = fetchSpy.mock.calls[0][1]
    expect(JSON.parse(init.body)).toEqual({ projectCheckProjectId: '9' })

    fetchSpy.mockRestore()
  })

  it('ajax blocks external URLs by default', async () => {
    const u = window.ArbeitszeitCheckUtils
    const fetchSpy = vi.spyOn(globalThis, 'fetch')

    await expect(u.ajax('https://example.org/ping')).rejects.toThrow('External URL blocked')
    expect(fetchSpy).not.toHaveBeenCalled()

    fetchSpy.mockRestore()
  })

  it('ajax allows external URLs when explicitly opted in', async () => {
    const u = window.ArbeitszeitCheckUtils
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      json: async () => ({ success: true })
    })

    const data = await u.ajax('https://example.org/ping', { allowExternal: true })
    expect(data).toEqual({ success: true })
    expect(fetchSpy).toHaveBeenCalledTimes(1)

    fetchSpy.mockRestore()
  })

  it('ajax surfaces session expiry on 412 with a stable message', async () => {
    const u = window.ArbeitszeitCheckUtils
    const showError = vi.fn()
    window.ArbeitszeitCheckMessaging = { showError }
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      status: 412,
      json: async () => ({ error: 'CSRF check failed' })
    })

    const onError = vi.fn()
    await u.ajax('/apps/arbeitszeitcheck/api/clock/status', { onError })
    expect(onError).toHaveBeenCalledTimes(1)
    expect(onError.mock.calls[0][0].status).toBe(412)
    expect(onError.mock.calls[0][0].error).toContain('session expired')
    expect(showError).toHaveBeenCalledTimes(1)

    delete window.ArbeitszeitCheckMessaging
    fetchSpy.mockRestore()
  })

  it('isConfirmAccepted accepts boolean true and confirmed objects', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.isConfirmAccepted(true)).toBe(true)
    expect(u.isConfirmAccepted({ confirmed: true, reason: 'ok' })).toBe(true)
    expect(u.isConfirmAccepted(false)).toBe(false)
    expect(u.isConfirmAccepted({ confirmed: false })).toBe(false)
  })

  it('confirmDialogReason returns trimmed reason or fallback', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.confirmDialogReason({ confirmed: true, reason: '  audit  ' })).toBe('audit')
    expect(u.confirmDialogReason(false, 'user_request')).toBe('user_request')
  })

  it('isApiSuccess respects HTTP ok and JSON success flag', async () => {
    await import('./api.js')
    const api = window.AzcApi
    expect(api.isApiSuccess({ ok: true, data: { success: true } })).toBe(true)
    expect(api.isApiSuccess({ ok: true, data: {} })).toBe(true)
    expect(api.isApiSuccess({ ok: false, data: { success: true } })).toBe(false)
    expect(api.isApiSuccess({ ok: true, data: { success: false } })).toBe(false)
    expect(api.isApiSuccess({ ok: true, data: { ok: false } })).toBe(false)
  })

  it('confirmDestructiveAction fails closed when dialog API is missing', async () => {
    const u = window.ArbeitszeitCheckUtils
    const showError = vi.fn()
    window.ArbeitszeitCheckMessaging = { showError, announceAssertive: vi.fn() }
    delete window.AzcComponents
    delete window.ArbeitszeitCheckComponents

    const result = await u.confirmDestructiveAction({ title: 'T', message: 'M' })
    expect(result).toBeNull()
    expect(showError).toHaveBeenCalledTimes(1)

    delete window.ArbeitszeitCheckMessaging
  })

  it('confirmDestructiveAction keeps confirmDialog this binding', async () => {
    const u = window.ArbeitszeitCheckUtils
    const showConfirmDialog = vi.fn().mockResolvedValue(true)
    window.AzcComponents = {
      confirmDialog(options) {
        return this.showConfirmDialog(options)
      },
      showConfirmDialog,
    }

    const result = await u.confirmDestructiveAction({ title: 'Delete', message: 'Sure?' })
    expect(showConfirmDialog).toHaveBeenCalledWith({ title: 'Delete', message: 'Sure?' })
    expect(result).toEqual({ confirmed: true, reason: '' })

    delete window.AzcComponents
  })

  it('confirmDestructiveAction resolves after real destructive dialog confirmation', async () => {
    await import('./components.js')
    const u = window.ArbeitszeitCheckUtils
    document.body.innerHTML = `
      <header id="header"></header>
      <nav id="app-navigation"></nav>
      <main id="azc-main-content"></main>`

    const pending = u.confirmDestructiveAction({
      title: 'Delete organisation default?',
      message: 'This permanently removes this rule from history.',
      variant: 'destructive',
      confirmLabel: 'Delete',
    })

    await vi.waitFor(() => document.querySelector('.confirm-dialog__confirm'))
    const confirm = document.querySelector('.confirm-dialog__confirm')
    expect(confirm.disabled).toBe(false)
    confirm.click()

    await expect(pending).resolves.toEqual({ confirmed: true, reason: '' })
  })

  it('syncAzcOverlayMetrics measures #header and sets CSS variables on body', async () => {
    document.body.innerHTML = '<header id="header" style="height:60px"></header>'
    Object.defineProperty(document.getElementById('header'), 'getBoundingClientRect', {
      value: () => ({ bottom: 60, height: 60, top: 0, left: 0, right: 100, width: 100 }),
    })
    const u = window.ArbeitszeitCheckUtils
    const top = u.syncAzcOverlayMetrics()
    expect(top).toBe(60)
    expect(document.body.style.getPropertyValue('--azc-overlay-top')).toBe('60px')
    expect(document.body.style.getPropertyValue('--azc-overlay-height')).toBe('calc(100dvh - 60px)')
  })

  it('badgeVariantForTimeEntryStatus maps workflow states consistently', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.badgeVariantForTimeEntryStatus('completed')).toBe('success')
    expect(u.badgeVariantForTimeEntryStatus('active')).toBe('primary')
    expect(u.badgeVariantForTimeEntryStatus('break')).toBe('warning')
    expect(u.badgeVariantForTimeEntryStatus('paused')).toBe('warning')
    expect(u.badgeVariantForTimeEntryStatus('pending_approval')).toBe('warning')
    expect(u.badgeVariantForTimeEntryStatus('rejected')).toBe('error')
    expect(u.badgeVariantForTimeEntryStatus('unknown')).toBe('secondary')
  })

  it('badgeVariantForAbsenceStatus maps workflow states consistently', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.badgeVariantForAbsenceStatus('approved')).toBe('success')
    expect(u.badgeVariantForAbsenceStatus('pending')).toBe('warning')
    expect(u.badgeVariantForAbsenceStatus('substitute_pending')).toBe('warning')
    expect(u.badgeVariantForAbsenceStatus('rejected')).toBe('error')
    expect(u.badgeVariantForAbsenceStatus('cancelled')).toBe('secondary')
  })

  it('renderBadgeHtml escapes label and applies variant class', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.renderBadgeHtml('Active', 'primary')).toBe('<span class="badge badge--primary">Active</span>')
    expect(u.renderBadgeHtml('<bad>', 'error')).toBe('<span class="badge badge--error">&lt;bad&gt;</span>')
  })

  it('applyMonthClosureBadgeVariant toggles semantic classes', () => {
    const u = window.ArbeitszeitCheckUtils
    const el = document.createElement('span')
    el.className = 'month-closure-badge'
    u.applyMonthClosureBadgeVariant(el, 'finalized')
    expect(el.classList.contains('month-closure-badge--success')).toBe(true)
    u.applyMonthClosureBadgeVariant(el, 'open')
    expect(el.classList.contains('month-closure-badge--warning')).toBe(true)
    expect(el.classList.contains('month-closure-badge--success')).toBe(false)
  })

  it('isAssignableTariffRuleSet prefers assignable flag and keeps legacy selection', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.isAssignableTariffRuleSet({ id: 1, status: 'active', assignable: true })).toBe(true)
    expect(u.isAssignableTariffRuleSet({ id: 2, status: 'draft', assignable: false })).toBe(false)
    expect(u.isAssignableTariffRuleSet({ id: 3, status: 'retired' }, { keepId: 3 })).toBe(true)
    expect(u.isAssignableTariffRuleSet({ id: 3, status: 'retired' })).toBe(false)
  })

  it('formatHours defaults to legacy decimal and supports hours_minutes (#36)', () => {
    const u = window.ArbeitszeitCheckUtils
    const prev = window.ArbeitszeitCheck
    window.ArbeitszeitCheck = { ...(prev || {}), hoursDisplay: 'decimal' }
    expect(u.formatHours(5.5)).toBe('5.5')
    expect(u.formatHours(8)).toBe('8')
    expect(u.formatHours(-1)).toBe('0')
    expect(u.formatHours(Number.NaN)).toBe('0')

    window.ArbeitszeitCheck.hoursDisplay = 'hours_minutes'
    expect(u.formatHours(5.5)).toBe('5h 30')
    expect(u.formatHours(8)).toBe('8h')
    expect(u.formatHours(1 + 1 / 60)).toBe('1h 01')
    expect(u.formatHours(0)).toBe('0h')

    window.ArbeitszeitCheck = prev
  })
})

