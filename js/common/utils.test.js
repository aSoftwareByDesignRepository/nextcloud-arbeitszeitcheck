import { describe, expect, it, vi } from 'vitest'

// utils.js attaches itself to window.ArbeitszeitCheckUtils
import './utils.js'

describe('ArbeitszeitCheckUtils', () => {
  it('escapeHtml escapes unsafe characters', () => {
    const u = window.ArbeitszeitCheckUtils
    expect(u.escapeHtml('<script>alert("x")</script>')).toBe('&lt;script&gt;alert("x")&lt;/script&gt;')
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
})

