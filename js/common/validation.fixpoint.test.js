import { beforeEach, describe, expect, it } from 'vitest'

import './validation.js'

/**
 * Mirror of BreakPolicyResolver::minimalConsistentBreakSeconds —
 * spec §9.4 worked examples + edge cases. DE tiers {6h→30, 9h→45}.
 */
describe('ArbeitszeitCheckValidation minimalConsistentBreakMinutes', () => {
  const V = () => window.ArbeitszeitCheckValidation
  const H = 60

  beforeEach(() => {
    window.ArbeitszeitCheck = {
      complianceParams: {
        breakTiers: [
          { afterHours: 6, breakMinutes: 30 },
          { afterHours: 9, breakMinutes: 45 },
        ],
        minBreakMinutes: 15,
        deductionMode: 'strict',
      },
    }
  })

  describe('strict fixpoint — spec §9.4 matrix', () => {
    const cases = [
      ['5h59m', 5 * H + 59, 0],
      ['6h00m', 6 * H, 0],
      ['6h00m01s→≈6h00m', 6 * H + 1 / 60, 30],
      ['6h10m', 6 * H + 10, 30],
      ['6h45m', 6 * H + 45, 30],
      ['9h00m', 9 * H, 30],
      ['9h05m (customer bug)', 9 * H + 5, 30],
      ['9h20m', 9 * H + 20, 30],
      ['9h31m', 9 * H + 31, 45],
      ['9h40m', 9 * H + 40, 45],
      ['9h50m', 9 * H + 50, 45],
      ['10h00m', 10 * H, 45],
    ]
    it.each(cases)('%s → %d min', (label, presence, expected) => {
      const r = V().minimalConsistentBreakMinutes(presence, 0, null, 'strict')
      expect(r.targetMinutes).toBe(expected)
      expect(r.insertMinutes).toBe(expected)
      expect(r.capped).toBe(false)
    })
  })

  it('strict: existing break suppresses the insert', () => {
    const r = V().minimalConsistentBreakMinutes(9 * H + 10, 30, null, 'strict')
    expect(r.targetMinutes).toBe(30)
    expect(r.insertMinutes).toBe(0)
  })

  it('strict: partial existing break inserts only the delta', () => {
    const r = V().minimalConsistentBreakMinutes(9 * H + 40, 30, null, 'strict')
    expect(r.targetMinutes).toBe(45)
    expect(r.insertMinutes).toBe(15)
  })

  it('strict: excess existing break never inserts', () => {
    const r = V().minimalConsistentBreakMinutes(9 * H + 10, 60, null, 'strict')
    expect(r.insertMinutes).toBe(0)
  })

  describe('cap_overhang — spec §9.4 / A7–A10', () => {
    const cases = [
      ['6h10m → 10', 6 * H + 10, 10],
      ['9h31m → 31', 9 * H + 31, 31],
      ['9h40m → 40', 9 * H + 40, 40],
      ['10h00m → 45', 10 * H, 45],
      ['6h45m → 30', 6 * H + 45, 30],
      ['9h05m → 30', 9 * H + 5, 30],
      ['9h20m → 30', 9 * H + 20, 30],
    ]
    it.each(cases)('%s', (label, presence, expected) => {
      const r = V().minimalConsistentBreakMinutes(presence, 0, null, 'cap_overhang')
      expect(r.targetMinutes).toBe(expected)
      expect(r.insertMinutes).toBe(expected)
    })

    it('keeps the recorded books consistent: required(P−d) ≤ d', () => {
      for (let p = 5 * H; p <= 11 * H; p += 7) {
        for (const mode of ['strict', 'cap_overhang']) {
          const r = V().minimalConsistentBreakMinutes(p, 0, null, mode)
          const net = Math.max(0, p - r.targetMinutes)
          const required = V().calculateRequiredBreakMinutes(net / 60)
          expect(required).toBeLessThanOrEqual(r.targetMinutes)
        }
      }
    })
  })

  it('cap_overhang honours an existing partial break', () => {
    const r = V().minimalConsistentBreakMinutes(6 * H + 10, 5, null, 'cap_overhang')
    expect(r.targetMinutes).toBe(10)
    expect(r.insertMinutes).toBe(5)
  })

  it('custom org tier lattice (EC-12): 4h→20m extra tier lands on 20', () => {
    const tiers = [
      { afterHours: 4, breakMinutes: 20 },
      { afterHours: 6, breakMinutes: 30 },
      { afterHours: 9, breakMinutes: 45 },
    ]
    const r = V().minimalConsistentBreakMinutes(6 * H + 5, 0, tiers, 'cap_overhang')
    expect(r.targetMinutes).toBe(20)
  })

  it('fractional CH thresholds use minute precision', () => {
    const tiers = [
      { afterHours: 5.5, breakMinutes: 15 },
      { afterHours: 7, breakMinutes: 30 },
      { afterHours: 9, breakMinutes: 60 },
    ]
    // 5h30m presence: strictly at threshold → 0.
    expect(V().minimalConsistentBreakMinutes(5 * H + 30, 0, tiers, 'strict').targetMinutes).toBe(0)
    // 5h31m: overhang 1 < tier 15 → strict needs 15.
    expect(V().minimalConsistentBreakMinutes(5 * H + 31, 0, tiers, 'strict').targetMinutes).toBe(15)
  })

  it('getDeductionMode falls back to strict on missing/unknown values', () => {
    expect(V().getDeductionMode()).toBe('strict')
    window.ArbeitszeitCheck.complianceParams.deductionMode = 'cap_overhang'
    expect(V().getDeductionMode()).toBe('cap_overhang')
    window.ArbeitszeitCheck.complianceParams.deductionMode = 'bogus'
    expect(V().getDeductionMode()).toBe('strict')
    delete window.ArbeitszeitCheck.complianceParams
    expect(V().getDeductionMode()).toBe('strict')
    delete window.ArbeitszeitCheck
    expect(V().getDeductionMode()).toBe('strict')
  })

  it('unknown mode argument behaves as strict (fail-safe)', () => {
    const r = V().minimalConsistentBreakMinutes(6 * H + 10, 0, null, 'nonsense')
    expect(r.targetMinutes).toBe(30)
    expect(r.capped).toBe(false)
  })

  it('degenerate inputs return the empty plan', () => {
    expect(V().minimalConsistentBreakMinutes(0, 0, null, 'strict')).toEqual({
      targetMinutes: 0, insertMinutes: 0, capped: false,
    })
    expect(V().minimalConsistentBreakMinutes(-5, 0, null, 'strict').insertMinutes).toBe(0)
  })
})
