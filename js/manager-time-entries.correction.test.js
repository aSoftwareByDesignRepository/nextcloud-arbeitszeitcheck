import { beforeEach, describe, expect, it } from 'vitest'

import './common/utils.js'

describe('manager time entry correction summary round-trip', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
  })

  it('rendered Correct button data-entry-summary parses and validates for prefill', () => {
    const Utils = window.ArbeitszeitCheckUtils
    const payload = {
      userId: 'falk',
      projectCheckProjectId: '42',
      startTime: '2026-05-20T08:15:00+02:00',
      endTime: '2026-05-20T17:30:00+02:00',
      breaks: [{ start: '2026-05-20T12:00:00+02:00', end: '2026-05-20T12:30:00+02:00' }],
    }
    const encoded = Utils.encodeAttributeJson(payload)
    document.body.innerHTML = [
      '<button type="button" class="btn-manager-correct"',
      ' data-entry-id="99"',
      ' data-entry-updated="2026-05-20T18:00:00+02:00"',
      ' data-entry-summary="' + encoded + '">Correct</button>',
    ].join('')

    const btn = document.querySelector('.btn-manager-correct')
    const raw = btn.getAttribute('data-entry-summary')
    const parsed = Utils.parseAttributeJson(raw)

    expect(Utils.isTimeEntryClockSummary(parsed)).toBe(true)
    expect(parsed.startTime).toBe(payload.startTime)
    expect(parsed.endTime).toBe(payload.endTime)
    expect(parsed.userId).toBe('falk')
  })
})
