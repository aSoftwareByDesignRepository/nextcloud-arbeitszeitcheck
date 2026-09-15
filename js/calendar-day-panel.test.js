import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const snapshot = {}

function buildCalendarDom() {
  document.body.innerHTML = `
    <section class="calendar-section">
      <div id="calendar-month-view">
        <div class="calendar-day" data-date="2026-05-01" tabindex="0"></div>
        <div class="calendar-day" data-date="2026-05-02" tabindex="0"></div>
      </div>
      <div id="day-details-panel" class="day-details-panel" hidden role="dialog">
        <div class="panel-header">
          <h3 id="selected-date-label" tabindex="-1"></h3>
          <button id="btn-close-panel" type="button">Close</button>
        </div>
        <div id="day-details-content"></div>
      </div>
    </section>`
}

beforeEach(async () => {
  snapshot.ArbeitszeitCheck = globalThis.window.ArbeitszeitCheck
  snapshot.ArbeitszeitCheckComponents = globalThis.window.ArbeitszeitCheckComponents
  snapshot.ArbeitszeitCheckUtils = globalThis.window.ArbeitszeitCheckUtils
  vi.resetModules()
  globalThis.window.ArbeitszeitCheck = {
    page: 'none',
    l10n: { weekdays: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] },
    apiUrl: { absenceCreate: '/apps/arbeitszeitcheck/absences/create' },
  }
  globalThis.window.ArbeitszeitCheckComponents = {
    _bindFocusTrap: vi.fn(),
    _unbindFocusTrap: vi.fn(),
  }
  globalThis.window.ArbeitszeitCheckUtils = {
    syncAzcOverlayMetrics: vi.fn(() => 50),
  }
  await import('./arbeitszeitcheck-main.js')
  buildCalendarDom()
})

afterEach(() => {
  globalThis.window.ArbeitszeitCheck = snapshot.ArbeitszeitCheck
  globalThis.window.ArbeitszeitCheckComponents = snapshot.ArbeitszeitCheckComponents
  globalThis.window.ArbeitszeitCheckUtils = snapshot.ArbeitszeitCheckUtils
  document.body.innerHTML = ''
  document.body.classList.remove('azc-day-panel-open')
})

describe('calendar day details panel', () => {
  it('mounts panel on document.body below the header (no full-screen backdrop)', () => {
    const app = globalThis.window.ArbeitszeitCheckApp
    app.calendarData = {
      timeEntries: [],
      absences: [],
      holidays: [],
      currentDate: new Date('2026-05-01'),
      currentView: 'month',
    }
    const day = document.querySelector('.calendar-day[data-date="2026-05-01"]')
    app.showDayDetails('2026-05-01', day)

    const panel = document.getElementById('day-details-panel')
    expect(panel.parentNode).toBe(document.body)
    expect(document.getElementById('day-details-backdrop')).toBeNull()
    expect(panel.hidden).toBe(false)
    expect(panel.style.display).not.toBe('block')
    expect(globalThis.window.ArbeitszeitCheckUtils.syncAzcOverlayMetrics).toHaveBeenCalled()
    expect(document.body.classList.contains('azc-day-panel-open')).toBe(true)
    expect(document.querySelector('.calendar-section').classList.contains('calendar-section--day-panel-open')).toBe(true)
  })

  it('closes on close button click', () => {
    const app = globalThis.window.ArbeitszeitCheckApp
    app.loadCalendarData = vi.fn()
    app.initCalendar()
    app.calendarData = {
      timeEntries: [],
      absences: [],
      holidays: [],
      currentDate: new Date('2026-05-01'),
      currentView: 'month',
    }
    const day = document.querySelector('.calendar-day[data-date="2026-05-01"]')
    app.showDayDetails('2026-05-01', day)
    document.getElementById('btn-close-panel').click()
    expect(app.isDayDetailsPanelOpen()).toBe(false)
  })

  it('closes on outside click and restores focus to the triggering day', () => {
    const app = globalThis.window.ArbeitszeitCheckApp
    app.loadCalendarData = vi.fn()
    app.initCalendar()
    app.calendarData = {
      timeEntries: [],
      absences: [],
      holidays: [],
      currentDate: new Date('2026-05-01'),
      currentView: 'month',
    }
    const day = document.querySelector('.calendar-day[data-date="2026-05-01"]')
    day.focus()
    app.showDayDetails('2026-05-01', day)
    document.getElementById('btn-close-panel').focus()

    document.querySelector('.calendar-section').dispatchEvent(new MouseEvent('click', { bubbles: true }))

    expect(app.isDayDetailsPanelOpen()).toBe(false)
  })

  it('switches to another day while the panel stays open', () => {
    const app = globalThis.window.ArbeitszeitCheckApp
    app.calendarData = {
      timeEntries: [],
      absences: [],
      holidays: [],
      currentDate: new Date('2026-05-01'),
      currentView: 'month',
    }
    const day1 = document.querySelector('.calendar-day[data-date="2026-05-01"]')
    const day2 = document.querySelector('.calendar-day[data-date="2026-05-02"]')
    app.showDayDetails('2026-05-01', day1)
    app.showDayDetails('2026-05-02', day2)

    expect(app.isDayDetailsPanelOpen()).toBe(true)
    expect(app.calendarData.openDayDate).toBe('2026-05-02')
    expect(day2.classList.contains('calendar-day--panel-selected')).toBe(true)
    expect(day1.classList.contains('calendar-day--panel-selected')).toBe(false)
  })

  it('closes when navigating to another calendar period', () => {
    const app = globalThis.window.ArbeitszeitCheckApp
    app.calendarData = {
      timeEntries: [],
      absences: [],
      holidays: [],
      currentDate: new Date('2026-05-01'),
      currentView: 'month',
    }
    app.showDayDetails('2026-05-01', document.querySelector('.calendar-day[data-date="2026-05-01"]'))
    app.loadCalendarData = vi.fn()
    app.navigateCalendar(1)

    expect(app.isDayDetailsPanelOpen()).toBe(false)
    expect(app.loadCalendarData).toHaveBeenCalled()
  })

  it('offers working time and absence actions in the day panel', () => {
    const app = globalThis.window.ArbeitszeitCheckApp
    app.config.timeCapture = { manualTimeEntryEnabled: true, clockStampingEnabled: true }
    app.config.apiUrl = {
      absenceCreate: '/apps/arbeitszeitcheck/absences/create',
      timeEntryCreate: '/apps/arbeitszeitcheck/time-entries/create',
    }
    app.config.l10n = {
      ...(app.config.l10n || {}),
      dayPanelShortHelp: 'Pick one: add hours or request absence for this day.',
      addWorkingTimeThisDay: 'Add working time',
    }
    app.calendarData = {
      timeEntries: [],
      absences: [],
      holidays: [],
      currentDate: new Date('2026-05-01'),
      currentView: 'month',
    }
    app.showDayDetails('2026-05-01', document.querySelector('.calendar-day[data-date="2026-05-01"]'))
    const html = document.getElementById('day-details-content').innerHTML
    expect(html).toContain('time-entries/create?date=2026-05-01')
    expect(html).toContain('absences/create?')
    expect(html).toContain('azc-btn--primary')
    expect(html).toContain('azc-btn--secondary')
    expect(html).toContain('Pick one:')
    expect(html).not.toContain('Past dates are allowed for migration')
    expect(html).toContain('day-details-actions__link--primary')
  })
})
