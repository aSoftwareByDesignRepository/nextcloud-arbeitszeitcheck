import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

describe('Bachus simplify source contracts', () => {
  it('admin overtime Nullung exposes one primary reset; custom fields stay in details', () => {
    const src = readFileSync(path.join(root, 'js/admin-user-detail.js'), 'utf8')
    expect(src).toContain('btn-overtime-adj-reset')
    expect(src).toContain('user-overtime-adjust__more')
    expect(src).toMatch(/id="btn-overtime-adj-reset"[\s\S]*user-overtime-adjust__more/)
    expect(src).toMatch(/user-overtime-adjust__more[\s\S]*btn-overtime-adj-apply/)
    expect(src).toContain('overtimeAdjustHelpShort')
  })

  it('dashboard progressive disclosure for month/year glance tiles', () => {
    const src = readFileSync(path.join(root, 'templates/dashboard.php'), 'utf8')
    expect(src).toContain('azc-dashboard-metrics-more')
    expect(src).toContain('hoursGlancePrimary')
    expect(src).toContain('dashboard-manual-add-entry')
    expect(src).toContain('Worked / target')
  })

  it('calendar day panel uses short help key', () => {
    const src = readFileSync(path.join(root, 'js/arbeitszeitcheck-main.js'), 'utf8')
    expect(src).toContain('dayPanelShortHelp')
    expect(src).toContain('day-details-actions__link--primary')
    expect(src).not.toMatch(/addTimeHelpPlain \+ ' ' \+ reqAbsHelpPlain/)
  })
})
