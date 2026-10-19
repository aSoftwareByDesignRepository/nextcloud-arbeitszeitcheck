import { describe, expect, it } from 'vitest'
import { readFileSync, readdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

function* jsFiles(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) {
      yield* jsFiles(full)
    } else if (entry.name.endsWith('.js') && !entry.name.endsWith('.test.js')) {
      yield full
    }
  }
}

describe('Bachus simplify source contracts', () => {
  it('no production JS uses native blocking dialogs (confirmDialog only, §7.3)', () => {
    const offenders = []
    for (const file of jsFiles(path.join(root, 'js'))) {
      const src = readFileSync(file, 'utf8')
      // Strip block + line comments so documentation mentions don't flag.
      const code = src
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .split('\n')
        .map((line) => line.replace(/\/\/.*$/, ''))
        .join('\n')
      const hits = code.match(/window\.(confirm|alert|prompt)\s*\(/g)
      if (hits) offenders.push(path.relative(root, file) + ': ' + hits.join(', '))
    }
    expect(offenders, 'native dialogs found — use AzcComponents.confirmDialog').toEqual([])
  })
  it('admin overtime Nullung exposes one primary reset; custom fields stay in details', () => {
    const src = readFileSync(path.join(root, 'js/admin-user-detail.js'), 'utf8')
    expect(src).toContain('btn-overtime-adj-reset')
    expect(src).toContain('user-overtime-adjust__more')
    expect(src).toMatch(/id="btn-overtime-adj-reset"[\s\S]*user-overtime-adjust__more/)
    expect(src).toMatch(/user-overtime-adjust__more[\s\S]*btn-overtime-adj-apply/)
    expect(src).toContain('overtimeAdjustHelpShort')
  })

  it('dashboard shows month and year glance tiles without a disclosure', () => {
    const src = readFileSync(path.join(root, 'templates/dashboard.php'), 'utf8')
    expect(src).not.toContain('azc-dashboard-metrics-more')
    expect(src).not.toContain('hoursGlancePrimary')
    expect(src).not.toContain('Worked / target')
    expect(src).not.toContain('hour balance — not pay')
    expect(src).not.toContain('Annual leave left (bookable)')
    expect(src).toContain("t('Annual leave left')")
    expect(src).toContain('foreach ($hoursGlance as $glanceRow)')
    expect(src).toContain('dashboard-manual-add-entry')
  })

  it('calendar day panel uses short help key', () => {
    const src = readFileSync(path.join(root, 'js/arbeitszeitcheck-main.js'), 'utf8')
    expect(src).toContain('dayPanelShortHelp')
    expect(src).toContain('day-details-actions__link--primary')
    expect(src).not.toMatch(/addTimeHelpPlain \+ ' ' \+ reqAbsHelpPlain/)
  })
})
