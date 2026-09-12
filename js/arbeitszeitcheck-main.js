/**
 * ArbeitszeitCheck - Vanilla JavaScript Application
 * Replaces the Vue.js implementation with a clean, simple JavaScript approach
 */
(function(window, OC) {
    'use strict';

    /** Escape string for safe use in HTML (prevents XSS when injecting API/user data into innerHTML) */
    function escapeHtml(text) {
        if (text == null) return '';
        const s = String(text);
        if (typeof window.ArbeitszeitCheckUtils !== 'undefined' && window.ArbeitszeitCheckUtils.escapeHtml) {
            return window.ArbeitszeitCheckUtils.escapeHtml(s);
        }
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    /** Prefer server-injected mainUiStrings; window.t is not always available. */
    function mainT(msg) {
        const map = window.ArbeitszeitCheck && window.ArbeitszeitCheck.mainUiStrings;
        if (map && Object.prototype.hasOwnProperty.call(map, msg) && map[msg] !== undefined && map[msg] !== '') {
            return map[msg];
        }
        return (typeof window.t === 'function' ? window.t('arbeitszeitcheck', msg) : msg);
    }

    /** @returns {typeof window.ArbeitszeitCheckTime|null} */
    function timeApi() {
        return window.ArbeitszeitCheckTime || null;
    }

    /** Parse API ISO-8601 instants (delegates to {@link ArbeitszeitCheckTime}). */
    function parseApiInstant(value) {
        const api = timeApi();
        if (api) {
            return api.parseInstant(value);
        }
        if (window.ArbeitszeitCheckUtils && typeof window.ArbeitszeitCheckUtils.parseApiInstant === 'function') {
            return window.ArbeitszeitCheckUtils.parseApiInstant(value);
        }
        return null;
    }

    /** Render clock time in the user's display TZ. */
    function formatDisplayTime(value, withSeconds) {
        const api = timeApi();
        if (api) {
            return api.formatTime(value, withSeconds ? { withSeconds: true } : undefined);
        }
        return '';
    }

    /**
     * Calendar-day key `YYYY-MM-DD` in the user's display TZ (never via
     * `toISOString()`, which would shift to UTC).
     */
    function formatLocalDateYmd(date) {
        const api = timeApi();
        if (api && date instanceof Date && !Number.isNaN(date.getTime())) {
            return api.ymd(date);
        }
        if (!(date instanceof Date)) {
            return '';
        }
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    /** Parse `YYYY-MM-DD` as a civil date (DST-safe noon anchor). */
    function parseYmdToLocalDate(ymd) {
        const api = timeApi();
        if (api) {
            return api.parseYmd(ymd);
        }
        if (!ymd || !/^\d{4}-\d{2}-\d{2}$/.test(ymd)) {
            return null;
        }
        const p = ymd.split('-').map(function (x) { return parseInt(x, 10); });
        const d = new Date(p[0], p[1] - 1, p[2], 12, 0, 0, 0);
        if (d.getFullYear() !== p[0] || d.getMonth() !== p[1] - 1 || d.getDate() !== p[2]) {
            return null;
        }
        return d;
    }

    function todayYmdLocal() {
        const api = timeApi();
        if (api) {
            return api.todayYmd();
        }
        return formatLocalDateYmd(new Date());
    }

    function azcIcon(name, extraClass) {
        if (typeof window.AzcCatalog !== 'undefined' && typeof window.AzcCatalog.render === 'function') {
            return window.AzcCatalog.render(name, extraClass || '');
        }
        return '';
    }

    // Main application object
    const ArbeitszeitCheck = {
        config: window.ArbeitszeitCheck || {},
        timers: {},
        initialized: false,
        /** Prevents double-submit on clock / break buttons before loading UI disables them. */
        clockActionInFlight: false,

        /**
         * Initialize the application
         */
        init: function() {
            if (this.initialized) {
                return;
            }
            this.initialized = true;
            // Refresh config from window (inline script may run after main script)
            this.config = (typeof window !== 'undefined' && window.ArbeitszeitCheck) ? window.ArbeitszeitCheck : (this.config || {});

            const initPage = () => {
                this.initTimer();
                this.initClockButtons();
                this.initEventListeners();
                
                // Initialize page-specific functionality
                if (this.config.page === 'timeline') {
                    this.initTimeline();
                } else if (this.config.page === 'calendar') {
                    this.initCalendar();
                } else if (this.config.page === 'time-entries') {
                    this.initTimeEntries();
                } else if (this.config.page === 'absences') {
                    this.initAbsences();
                } else if (this.config.page === 'reports') {
                    // Reports functionality is handled inline in the template
                }
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPage);
            } else {
                // DOM already loaded, initialize immediately
                initPage();
            }
        },

        /**
         * Initialize the real-time session timer
         * Timer pauses during breaks (only counts working time)
         * Also initializes break timer when on break
         */
        initTimer: function() {
            // Get initial status from config
            const status = this.config.status || {};
            const currentStatus = status.status || 'clocked_out';

            // Sync the drift-safe clock with the server `server_now` anchor on
            // every (re)initialisation so the very first tick uses the same
            // instant the server used to compute `current_session_duration`.
            // Without this, a client whose wall clock is off by N seconds would
            // observe the timer jumping by N the very first second.
            if (timeApi() && status && status.server_now) {
                timeApi().syncFromServer(status.server_now);
            }
            const nowMillis = () => {
                const api = timeApi();
                return (api && typeof api.serverNowMillis === 'function')
                    ? api.serverNowMillis()
                    : Date.now();
            };

            // Initialize working time timer
            const sessionTimerEl = document.querySelector('.session-timer');
            if (sessionTimerEl) {
                const timerEl = sessionTimerEl.querySelector('.timer-value');
                if (timerEl) {
                    const startTimeStr = sessionTimerEl.dataset.startTime;
                    // Only start timer if user is actually clocked in (active or on break)
                    // Don't start timer if clocked out or paused
                    if (startTimeStr && (currentStatus === 'active' || currentStatus === 'break')) {
                        // Use backend-calculated duration (already excludes breaks) as base
                        let baseWorkingSeconds = 0;
                        if (status.current_session_duration !== null && status.current_session_duration !== undefined) {
                            baseWorkingSeconds = Math.floor(status.current_session_duration);
                        } else {
                            // Fallback when the backend didn't pre-compute the duration:
                            // diff the start-time ISO instant against the drift-safe
                            // server clock. ArbeitszeitCheckTime.secondsSince clamps
                            // negatives (future instants) to zero so a client clock
                            // running ahead of the server never produces a "negative"
                            // timer.
                            if (timeApi() && typeof timeApi().secondsSince === 'function') {
                                baseWorkingSeconds = timeApi().secondsSince(startTimeStr);
                            } else {
                                const startDate = parseApiInstant(startTimeStr);
                                const startMs = startDate ? startDate.getTime() : NaN;
                                baseWorkingSeconds = Number.isFinite(startMs)
                                    ? Math.max(0, Math.floor((nowMillis() - startMs) / 1000))
                                    : 0;
                            }
                        }

                        // Track when timer was last updated (for incrementing)
                        let lastUpdateTime = nowMillis();
                        let isOnBreak = (currentStatus === 'break');
                        let isClockedIn = (currentStatus === 'active' || currentStatus === 'break');
                        let lastStatusCheck = nowMillis();
                        // Calendar-day total from server (midnight-clipped; ArbZG §3).
                        let workingTodayHours = 0.0;
                        if (status.working_today_hours !== null && status.working_today_hours !== undefined) {
                            workingTodayHours = parseFloat(status.working_today_hours) || 0.0;
                        }
                        let serverAtDailyMaximum = status.at_daily_maximum === true;
                        /* Portion of the live session that falls on today's calendar day only
                           (overnight shifts must not use full session length for daily totals). */
                        let sessionHoursOnCalendarToday = 0.0;
                        if (status.session_hours_on_calendar_today !== null
                                && status.session_hours_on_calendar_today !== undefined) {
                            sessionHoursOnCalendarToday = parseFloat(status.session_hours_on_calendar_today) || 0.0;
                        }
                        let lastWtSyncSessionSecondsOnToday = null;
                        if (status.current_session_duration !== null && status.current_session_duration !== undefined) {
                            const liveSessionSeconds = Math.floor(status.current_session_duration);
                            if (liveSessionSeconds > 0 && sessionHoursOnCalendarToday > 0) {
                                lastWtSyncSessionSecondsOnToday = Math.floor(
                                    sessionHoursOnCalendarToday * 3600
                                );
                            } else if (liveSessionSeconds > 0) {
                                const startIso = sessionTimerEl.dataset.startTime || '';
                                const startDay = startIso ? startIso.slice(0, 10) : '';
                                const todayKey = todayYmdLocal();
                                if (startDay === todayKey) {
                                    lastWtSyncSessionSecondsOnToday = liveSessionSeconds;
                                }
                            }
                        }
                        const STATUS_CHECK_INTERVAL = 5000; // Check status every 5 seconds
                        const configuredMaxDailyHours = (() => {
                            const raw = parseFloat(this.config.maxDailyHours);
                            return Number.isFinite(raw) ? Math.max(1, Math.min(24, raw)) : 10;
                        })();

                        // Clear any existing timer
                        if (this.timers.session) {
                            clearInterval(this.timers.session);
                        }

                        // Don't start timer if already clocked out or paused
                        if (!isClockedIn) {
                            return;
                        }

                        // Page Visibility API: when the tab is backgrounded and
                        // then brought back into focus, re-sync from the backend so
                        // that throttled setInterval ticks don't accumulate drift.
                        // Remove any previously registered handler to avoid duplicates
                        // on re-init (e.g. SPA-style page transitions).
                        if (this._visibilityHandler) {
                            document.removeEventListener('visibilitychange', this._visibilityHandler);
                        }
                        this._visibilityHandler = () => {
                            if (document.visibilityState === 'visible' && isClockedIn && !isOnBreak) {
                                // Force an immediate status sync to correct any drift
                                this.getStatus()
                                    .then(response => {
                                        if (response && response.success && response.status) {
                                            const newStatus = response.status.status || 'clocked_out';
                                            isClockedIn = (newStatus === 'active' || newStatus === 'break');
                                            isOnBreak   = (newStatus === 'break');
                                            // Re-pin the drift-safe clock to the fresh server anchor.
                                            if (timeApi() && response.status.server_now) {
                                                timeApi().syncFromServer(response.status.server_now);
                                            }
                                            if (response.status.working_today_hours !== null
                                                    && response.status.working_today_hours !== undefined) {
                                                workingTodayHours = parseFloat(response.status.working_today_hours) || 0.0;
                                            }
                                            serverAtDailyMaximum = response.status.at_daily_maximum === true;
                                            if (response.status.session_hours_on_calendar_today !== null
                                                    && response.status.session_hours_on_calendar_today !== undefined) {
                                                sessionHoursOnCalendarToday = parseFloat(response.status.session_hours_on_calendar_today) || 0.0;
                                            }
                                            if (response.status.current_session_duration !== null
                                                    && response.status.current_session_duration !== undefined) {
                                                baseWorkingSeconds = Math.floor(response.status.current_session_duration);
                                                const liveSessionSeconds = baseWorkingSeconds;
                                                if (sessionHoursOnCalendarToday > 0) {
                                                    lastWtSyncSessionSecondsOnToday = Math.floor(sessionHoursOnCalendarToday * 3600);
                                                } else if (liveSessionSeconds > 0) {
                                                    const startIso = sessionTimerEl.dataset.startTime || '';
                                                    const startDay = startIso ? startIso.slice(0, 10) : '';
                                                    lastWtSyncSessionSecondsOnToday = (startDay === todayYmdLocal())
                                                        ? liveSessionSeconds
                                                        : null;
                                                }
                                            }
                                            lastUpdateTime = nowMillis();
                                        }
                                    })
                                    .catch(() => {
                                        // Non-fatal: reset lastUpdateTime so elapsed calculation
                                        // starts fresh from the moment the tab becomes visible.
                                        lastUpdateTime = nowMillis();
                                    });
                            }
                        };
                        document.addEventListener('visibilitychange', this._visibilityHandler);

                        // Update timer every second
                        this.timers.session = setInterval(() => {
                            const now = nowMillis();

                            // Periodically check status from backend to update isOnBreak and isClockedIn
                            // This ensures the timer correctly pauses/resumes when break status changes
                            // and stops when user clocks out
                            if (now - lastStatusCheck >= STATUS_CHECK_INTERVAL) {
                                lastStatusCheck = now;
                                this.getStatus()
                                    .then(response => {
                                        if (response && response.success && response.status) {
                                            const newStatus = response.status.status || 'clocked_out';
                                            const wasOnBreak = isOnBreak;
                                            const wasClockedIn = isClockedIn;

                                            // Re-pin the drift-safe clock to the latest server anchor.
                                            if (timeApi() && response.status.server_now) {
                                                timeApi().syncFromServer(response.status.server_now);
                                            }

                                            isOnBreak = (newStatus === 'break');
                                            isClockedIn = (newStatus === 'active' || newStatus === 'break');
                                            
                                            // Update working today hours from backend (includes completed entries + current session)
                                            if (response.status.working_today_hours !== null && response.status.working_today_hours !== undefined) {
                                                workingTodayHours = parseFloat(response.status.working_today_hours) || 0.0;
                                            }
                                            serverAtDailyMaximum = response.status.at_daily_maximum === true;
                                            if (response.status.session_hours_on_calendar_today !== null
                                                    && response.status.session_hours_on_calendar_today !== undefined) {
                                                sessionHoursOnCalendarToday = parseFloat(response.status.session_hours_on_calendar_today) || 0.0;
                                            }
                                            if (response.status.current_session_duration !== null
                                                    && response.status.current_session_duration !== undefined) {
                                                const liveSessionSeconds = Math.floor(response.status.current_session_duration);
                                                if (sessionHoursOnCalendarToday > 0) {
                                                    lastWtSyncSessionSecondsOnToday = Math.floor(sessionHoursOnCalendarToday * 3600);
                                                } else if (liveSessionSeconds > 0) {
                                                    const startIso = sessionTimerEl.dataset.startTime || '';
                                                    const startDay = startIso ? startIso.slice(0, 10) : '';
                                                    if (startDay === todayYmdLocal()) {
                                                        lastWtSyncSessionSecondsOnToday = liveSessionSeconds;
                                                    } else {
                                                        lastWtSyncSessionSecondsOnToday = null;
                                                    }
                                                }
                                            }

                                            // CRITICAL: Stop timer if user clocked out or paused
                                            if (wasClockedIn && !isClockedIn) {
                                                // User clocked out or paused - stop the timer immediately
                                                if (this.timers.session) {
                                                    clearInterval(this.timers.session);
                                                    this.timers.session = null;
                                                }
                                                // Don't return here - we need to continue to update the display
                                                // The timer interval will be stopped, so no more updates will occur
                                            }
                                            
                                            // If break status changed, update lastUpdateTime to prevent time jumps
                                            if (wasOnBreak !== isOnBreak) {
                                                // If break just ended, reset lastUpdateTime to now
                                                if (wasOnBreak && !isOnBreak) {
                                                    lastUpdateTime = now;
                                                }
                                                // If break just started, update lastUpdateTime to prevent incrementing
                                                if (!wasOnBreak && isOnBreak) {
                                                    lastUpdateTime = now;
                                                }
                                                
                                                // Update baseWorkingSeconds from backend if available
                                                if (response.status.current_session_duration !== null && response.status.current_session_duration !== undefined) {
                                                    baseWorkingSeconds = Math.floor(response.status.current_session_duration);
                                                }
                                            }
                                        }
                                    })
                                    .catch(error => {
                                        // Silently fail - don't interrupt timer if status check fails
                                        console.debug('Status check failed (non-critical):', error);
                                    });
                            }
                            
                            // CRITICAL: Stop timer if user is no longer clocked in (double-check)
                            if (!isClockedIn) {
                                if (this.timers.session) {
                                    clearInterval(this.timers.session);
                                    this.timers.session = null;
                                }
                                return; // Exit early, timer is stopped
                            }
                            
                            let workingSeconds = baseWorkingSeconds;
                            
                            // Only increment timer if not on break
                            if (!isOnBreak) {
                                const elapsed = Math.floor((now - lastUpdateTime) / 1000);
                                workingSeconds = baseWorkingSeconds + elapsed;
                                // Update base for next iteration
                                baseWorkingSeconds = workingSeconds;
                                lastUpdateTime = now;
                            } else {
                                // If on break, update lastUpdateTime to prevent time accumulation when break ends
                                lastUpdateTime = now;
                            }
                            // If on break, timer is paused (workingSeconds stays at baseWorkingSeconds)

                            // Ensure non-negative
                            workingSeconds = Math.max(0, workingSeconds);

                            const maxWorkingHours = configuredMaxDailyHours;

                            /* Cap the session timer display when today's calendar-day allowance is exhausted
                               (overnight: only today's clipped portion counts toward ArbZG §3). */
                            if (workingTodayHours > 0 && lastWtSyncSessionSecondsOnToday !== null) {
                                const maxSessionSecondsAllowed = lastWtSyncSessionSecondsOnToday
                                    + Math.max(0, (maxWorkingHours - workingTodayHours) * 3600);
                                if (workingSeconds > maxSessionSecondsAllowed) {
                                    workingSeconds = Math.floor(maxSessionSecondsAllowed);
                                    baseWorkingSeconds = workingSeconds;
                                    lastUpdateTime = now;
                                }
                            }

                            const hours = Math.floor(workingSeconds / 3600);
                            const minutes = Math.floor((workingSeconds % 3600) / 60);
                            const seconds = workingSeconds % 60;

                            timerEl.textContent =
                                String(hours).padStart(2, '0') + ':' +
                                String(minutes).padStart(2, '0') + ':' +
                                String(seconds).padStart(2, '0');
                            
                            // Warning for maximum working hours (ArbZG §3: max 10 hours).
                            // We compare TOTAL daily working hours (previous entries + current session
                            // attributed to the calendar day), not just the running session length.

                            // Total hours on today's calendar day (never the full overnight session length).
                            let totalDailyHours = workingTodayHours;
                            if (lastWtSyncSessionSecondsOnToday !== null) {
                                const sessionSecondsOnToday = Math.min(
                                    workingSeconds,
                                    Math.max(lastWtSyncSessionSecondsOnToday, Math.floor(sessionHoursOnCalendarToday * 3600))
                                );
                                totalDailyHours = workingTodayHours
                                    + (sessionSecondsOnToday - lastWtSyncSessionSecondsOnToday) / 3600;
                            }
                            
                            // Remove previous warning classes
                            timerEl.classList.remove('timer-warning', 'timer-error');
                            
                            if (totalDailyHours >= maxWorkingHours) {
                                // Exceeded 10 hours TOTAL for the day - show error state
                                timerEl.classList.add('timer-error');
                                if (sessionTimerEl) {
                                    sessionTimerEl.classList.add('timer-exceeded');
                                }

                                // AUTOMATIC CLOCK-OUT: only when the server already reports the
                                // calendar-day maximum (prevents false positives on overnight shifts
                                // where the client extrapolation used to count the whole session).
                                // Auto clock-out only when the server explicitly reports the
                                // calendar-day maximum (never client extrapolation alone).
                                if (serverAtDailyMaximum === true && !timerEl.dataset.autoClockOutTriggered) {
                                    timerEl.dataset.autoClockOutTriggered = 'true';

                                    // Stop the live timer immediately to prevent any further
                                    // re-entry into this branch within this page-load.
                                    if (this.timers.session) {
                                        clearInterval(this.timers.session);
                                        this.timers.session = null;
                                    }

                                    this.triggerAutomaticDailyMaximumClockOut();
                                }
                            } else if (totalDailyHours >= Math.max(1, maxWorkingHours - 2)) {
                                // Approaching the configured daily maximum (max − 2 h).
                                // Country-aware copy is injected via mainUiStrings (legacy ArbZG keys remap).
                                timerEl.classList.add('timer-warning');
                                if (sessionTimerEl) {
                                    sessionTimerEl.classList.add('timer-warning');
                                }

                                if (!timerEl.dataset.infoShown) {
                                    timerEl.dataset.infoShown = 'true';

                                    if (window.OC && OC.Notification) {
                                        const infoMsg = mainT('Note: You are approaching the maximum working hours. Extended hours must be compensated within 6 months (ArbZG §3).');
                                        OC.Notification.showTemporary(infoMsg, {
                                            type: 'info',
                                            timeout: 10000
                                        });
                                    }
                                }
                            }
                        }, 1000);
                    }
                }
            }
            
            // Initialize break timer (only when on break)
            if (currentStatus === 'break') {
                const breakTimerEl = document.querySelector('.break-timer');
                if (breakTimerEl) {
                    const breakTimerValueEl = breakTimerEl.querySelector('.timer-value');
                    if (breakTimerValueEl) {
                        const breakStartTimeStr = breakTimerEl.dataset.breakStartTime;
                        if (breakStartTimeStr) {
                            // Parse the ISO instant via the single source of truth.
                            // Falling back to `new Date(s)` would re-introduce the
                            // exact UTC-vs-local interpretation pitfall the timer
                            // bug originally came from.
                            const breakStartDate = parseApiInstant(breakStartTimeStr);
                            const breakStartMs = breakStartDate && !Number.isNaN(breakStartDate.getTime())
                                ? breakStartDate.getTime()
                                : NaN;

                            // Clear any existing break timer
                            if (this.timers.break) {
                                clearInterval(this.timers.break);
                            }

                            if (Number.isFinite(breakStartMs)) {
                                // Update break timer every second, driven by the
                                // drift-safe server clock so a wrong local clock
                                // cannot make the break appear longer/shorter than
                                // the server records it.
                                this.timers.break = setInterval(() => {
                                    const breakSeconds = Math.max(0, Math.floor((nowMillis() - breakStartMs) / 1000));
                                    breakTimerValueEl.textContent = (timeApi() && typeof timeApi().formatDuration === 'function')
                                        ? timeApi().formatDuration(breakSeconds)
                                        : (
                                            String(Math.floor(breakSeconds / 3600)).padStart(2, '0') + ':' +
                                            String(Math.floor((breakSeconds % 3600) / 60)).padStart(2, '0') + ':' +
                                            String(breakSeconds % 60).padStart(2, '0')
                                        );
                                }, 1000);
                            }
                        }
                    }
                }
            }
        },

        /**
         * Initialize clock in/out and break buttons
         */
        initClockButtons: function() {
            /* Use class .btn-clock-out for all clock-out buttons (no duplicate IDs).
               Multiple clock-out buttons may exist when on break (End Break + Clock Out). */
            const buttons = {
                clockIn: document.getElementById('btn-clock-in'),
                clockOut: document.querySelector('.btn-clock-out'),  /* First match; all share same handler */
                startBreak: document.getElementById('btn-start-break'),
                endBreak: document.getElementById('btn-end-break')
            };

            /* Attach clock-out handler to all clock-out buttons (when on break there are two) */
            const clockOutButtons = document.querySelectorAll('.btn-clock-out');

            if (buttons.clockIn) {
                buttons.clockIn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const projectSelect = document.getElementById('dashboard-clock-in-project');
                    const projectId = projectSelect && projectSelect.value ? projectSelect.value : null;
                    this.clockIn(projectId);
                });
            }

            clockOutButtons.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const confirmMsg = mainT('Clock out and end your working day?') + '\n\n' +
                        mainT('Your time entry will be finalized. To pause and continue working, use "Start Break" instead.');
                    const confirmTitle = mainT('Clock out?');
                    const dialogOpts = (typeof OC !== 'undefined' && OC.dialogs)
                        ? { type: OC.dialogs.YES_NO_BUTTONS, modal: true }
                        : {};
                    this.confirmDestructiveMain(confirmMsg, confirmTitle, dialogOpts).then((confirmed) => {
                        if (confirmed) {
                            this.clockOut();
                        }
                    });
                }.bind(this));
            }.bind(this));

            if (buttons.startBreak) {
                buttons.startBreak.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.startBreak();
                });
            }

            if (buttons.endBreak) {
                buttons.endBreak.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.endBreak();
                });
            }
        },

        /**
         * Initialize general event listeners
         */
        initEventListeners: function() {
            // Handle form submissions with API calls
            const forms = document.querySelectorAll('form[data-api-endpoint]');
            forms.forEach(form => {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    const endpoint = form.dataset.apiEndpoint;
                    const method = form.method.toUpperCase() || 'POST';
                    const formData = new FormData(form);
                    const data = Object.fromEntries(formData);
                    this.callApi(endpoint, method, data);
                });
            });

            // Handle delete buttons
            const deleteButtons = document.querySelectorAll('[data-delete-endpoint]');
            deleteButtons.forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const confirmMsg = this.config.l10n?.confirmDelete || mainT('Are you sure you want to delete this item?');
                    const confirmTitle = mainT('Delete?');
                    this.confirmDestructiveMain(confirmMsg, confirmTitle, {}).then((confirmed) => {
                        if (confirmed) {
                            const endpoint = button.dataset.deleteEndpoint;
                            this.callApi(endpoint, 'DELETE');
                        }
                    });
                });
            });

            // Handle edit buttons in table rows (works on all pages including dashboard).
            // The selector must exclude `.btn-delete` AND `.btn-complete-entry` so we don't
            // accidentally redirect to the edit form when the user clicks "Complete".
            const editButtons = document.querySelectorAll('table tbody button[data-entry-id]:not(.btn-delete):not(.btn-complete-entry):not(.btn-request-correction):not(.btn-cancel-correction)');
            editButtons.forEach(button => {
                // Check if button already has a click handler by checking for a data attribute
                if (button.dataset.editHandlerAttached === 'true') {
                    return; // Skip if already attached
                }

                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const entryId = this.dataset.entryId;
                    if (entryId) {
                        // Redirect to edit page
                        const editUrl = OC.generateUrl('/apps/arbeitszeitcheck/time-entries/' + entryId + '/edit');
                        window.location.href = editUrl;
                    }
                });

                // Mark as attached to prevent duplicates
                button.dataset.editHandlerAttached = 'true';
            });

            // Handle "Complete" buttons for paused entries (table rows AND dashboard card).
            // One-click finalisation of an unfinished session: POSTs to /api/time-entries/{id}/complete
            // and reloads on success so the dashboard/list reflects the new state.
            this.attachCompletePausedHandlers();
        },

        /**
         * Attach a click handler to every `.btn-complete-entry` button currently in the DOM.
         *
         * Idempotent: each button is marked with `data-completeHandlerAttached` so a
         * re-call (e.g. after dynamic DOM updates) never registers the listener twice.
         * The endpoint, confirmation copy and reload behaviour are kept centralised
         * so the dashboard and time-entries list cannot drift out of sync.
         */
        attachCompletePausedHandlers: function() {
            const buttons = document.querySelectorAll('.btn-complete-entry[data-entry-id]');
            buttons.forEach(button => {
                if (button.dataset.completeHandlerAttached === 'true') {
                    return;
                }
                button.dataset.completeHandlerAttached = 'true';

                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const entryId = button.dataset.entryId;
                    if (!entryId) return;

                    const confirmMsg = (this.config.l10n && this.config.l10n.confirmCompletePaused)
                        || mainT('Complete this paused session? The end time will be set to when it was paused, and any required breaks will be added automatically. This finalises the entry; you can still edit it afterwards.');
                    const confirmTitle = (this.config.l10n && this.config.l10n.confirmCompletePausedTitle)
                        || mainT('Complete paused session');
                    const dialogOpts = (typeof OC !== 'undefined' && OC.dialogs)
                        ? { type: OC.dialogs.YES_NO_BUTTONS, modal: true }
                        : {};

                    this.confirmDestructiveMain(confirmMsg, confirmTitle, dialogOpts).then((confirmed) => {
                        if (!confirmed) {
                            return;
                        }

                        const completeUrl = this.resolveRequestUrl(
                            '/apps/arbeitszeitcheck/api/time-entries/' + encodeURIComponent(entryId) + '/complete'
                        );

                        button.disabled = true;
                        button.setAttribute('aria-busy', 'true');

                        this.callApi(completeUrl, 'POST', null, false)
                            .then((result) => {
                                const successMsg = (result && result.message)
                                    || (this.config.l10n && this.config.l10n.completedPaused)
                                    || mainT('Paused session was completed and recorded successfully.');
                                this.showSuccess(successMsg);
                                // Small delay so the toast is visible before the reload.
                                setTimeout(() => window.location.reload(), 400);
                            })
                            .catch((error) => {
                                button.disabled = false;
                                button.removeAttribute('aria-busy');
                                const msg = (error && error.message)
                                    || (this.config.l10n && this.config.l10n.error)
                                    || mainT('Could not complete the paused session. Please try again.');
                                this.showError(msg);
                            });
                    });
                });
            });
        },

        /**
         * Collapsible per-row action menus on narrow viewports.
         */
        initRowActionsMenus: function() {
            const toggles = document.querySelectorAll('.azc-row-actions__toggle');
            toggles.forEach((toggle) => {
                if (toggle.dataset.rowActionsAttached === 'true') {
                    return;
                }
                toggle.dataset.rowActionsAttached = 'true';
                toggle.addEventListener('click', (e) => {
                    e.preventDefault();
                    const wrap = toggle.closest('.azc-row-actions');
                    if (!wrap) {
                        return;
                    }
                    const isOpen = wrap.classList.toggle('is-open');
                    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });
            });
        },

        /**
         * Initialize time entries page functionality
         */
        initTimeEntries: function() {
            // Add Entry button
            const addEntryBtn = document.getElementById('btn-add-entry');
            const addFirstEntryBtn = document.getElementById('btn-add-first-entry');
            
            if (addEntryBtn || addFirstEntryBtn) {
                const handler = () => {
                    // Redirect to create form page
                    const createUrl = OC.generateUrl('/apps/arbeitszeitcheck/time-entries/create');
                    window.location.href = createUrl;
                };
                
                if (addEntryBtn) {
                    addEntryBtn.addEventListener('click', handler);
                }
                if (addFirstEntryBtn) {
                    addFirstEntryBtn.addEventListener('click', handler);
                }
            }

            // Filter button - toggle filter section
            const filterBtn = document.getElementById('btn-filter');
            const filterSection = document.getElementById('filter-section');
            
            if (filterBtn && filterSection) {
                filterBtn.addEventListener('click', () => {
                    const isVisible = filterSection.style.display !== 'none';
                    filterSection.style.display = isVisible ? 'none' : 'block';
                });
            }

            // Apply filter button
            const applyFilterBtn = document.getElementById('btn-apply-filter');
            if (applyFilterBtn) {
                applyFilterBtn.addEventListener('click', () => {
                    const dp = window.ArbeitszeitCheckDatepicker;
                    const toISO = dp ? dp.convertEuropeanToISO : function (s) { return s; };
                    const startDate = toISO(document.getElementById('filter-start-date')?.value || '');
                    const endDate = toISO(document.getElementById('filter-end-date')?.value || '');
                    const status = document.getElementById('filter-status')?.value;
                    
                    // Build query string (API expects yyyy-mm-dd)
                    const params = new URLSearchParams();
                    if (startDate) params.append('start_date', startDate);
                    if (endDate) params.append('end_date', endDate);
                    if (status) params.append('status', status);
                    
                    // Reload page with filters
                    const currentUrl = window.location.pathname;
                    const queryString = params.toString();
                    window.location.href = currentUrl + (queryString ? '?' + queryString : '');
                });
            }

            // Clear filter button
            const clearFilterBtn = document.getElementById('btn-clear-filter');
            if (clearFilterBtn) {
                clearFilterBtn.addEventListener('click', () => {
                    document.getElementById('filter-start-date').value = '';
                    document.getElementById('filter-end-date').value = '';
                    document.getElementById('filter-status').value = '';
                    // Reload without filters
                    window.location.href = window.location.pathname;
                });
            }

            // Export/Download button
            const exportBtn = document.getElementById('btn-export');
            if (exportBtn && this.config.apiUrl?.export) {
                exportBtn.addEventListener('click', () => {
                    const Utils = window.ArbeitszeitCheckUtils || {};
                    const exportUrl = Utils.buildAppUrl
                        ? Utils.buildAppUrl(this.config.apiUrl.export)
                        : (Utils.resolveUrl ? Utils.resolveUrl(this.config.apiUrl.export) : this.config.apiUrl.export);
                    if (Utils.openDownload && !Utils.openDownload(exportUrl)) {
                        this.showError?.(this.t?.('Export failed. Please try again.') || 'Export failed. Please try again.');
                        return;
                    }
                    if (!Utils.openDownload) {
                        window.open(exportUrl, '_blank', 'noopener,noreferrer');
                    }
                });
            }


            // Edit buttons in table rows (skip delete + paused-completion buttons).
            const editButtons = document.querySelectorAll('table tbody button[data-entry-id]:not(.btn-delete):not(.btn-complete-entry):not(.btn-request-correction):not(.btn-cancel-correction)');
            editButtons.forEach(button => {
                if (button.dataset.editHandlerAttached === 'true') {
                    return;
                }
                button.dataset.editHandlerAttached = 'true';
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const entryId = button.dataset.entryId;
                    if (entryId) {
                        // Redirect to edit page
                        const editUrl = OC.generateUrl('/apps/arbeitszeitcheck/time-entries/' + entryId + '/edit');
                        window.location.href = editUrl;
                    }
                });
            });

            // Complete buttons (paused entries) — re-run so buttons rendered inside
            // the time-entries table also get a handler if initEventListeners() ran
            // before the table existed (e.g. dynamic re-render).
            this.attachCompletePausedHandlers();

            this.initRowActionsMenus();

            // Delete buttons in table rows
            const deleteButtons = document.querySelectorAll('table tbody .btn-delete[data-entry-id]');
            deleteButtons.forEach(button => {
                if (button.dataset.deleteHandlerAttached === 'true') {
                    return;
                }
                button.dataset.deleteHandlerAttached = 'true';
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const entryId = button.dataset.entryId;
                    if (!entryId) {
                        return;
                    }

                    const baseConfirmMsg = mainT('Are you sure you want to delete this time entry?') || this.config.l10n?.confirmDeleteTimeEntry || this.config.l10n?.confirmDelete;
                    const confirmTitle = this.config.l10n?.confirmDeleteTimeEntryTitle ||
                                     mainT('Delete time entry');
                    const dialogOpts = (typeof OC !== 'undefined' && OC.dialogs)
                        ? { type: OC.dialogs.YES_NO_BUTTONS, modal: true }
                        : {};

                    console.log(this.config.l10n);

                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');

                    const resolveImpactUrl = () => {
                        let impactUrl = this.config.apiUrl?.deletionImpact || '';
                        if (impactUrl && impactUrl.includes('__ID__')) {
                            return impactUrl.replace('__ID__', entryId);
                        }
                        return this.resolveRequestUrl(
                            '/apps/arbeitszeitcheck/api/time-entries/' + encodeURIComponent(entryId) + '/deletion-impact'
                        );
                    };

                    const parseServerWarnings = () => {
                        const raw = button.getAttribute('data-delete-warnings');
                        if (!raw) {
                            return [];
                        }
                        try {
                            const parsed = JSON.parse(raw);
                            return Array.isArray(parsed) ? parsed.filter(Boolean) : [];
                        } catch (e) {
                            return [];
                        }
                    };

                    const buildConfirmMessage = (warnings) => {
                        const list = Array.isArray(warnings) ? warnings.filter(Boolean) : [];
                        if (list.length === 0) {
                            return baseConfirmMsg;
                        }
                        return baseConfirmMsg + '\n\n' + list.join('\n');
                    };

                    const loadDeletionConfirmMessage = () => {
                        return this.callApi(resolveImpactUrl(), 'GET', null, false)
                            .then((response) => {
                                const impact = response && response.impact ? response.impact : null;
                                if (!impact) {
                                    const fallbackWarnings = parseServerWarnings();
                                    if (fallbackWarnings.length === 0) {
                                        throw new Error(
                                            this.config.l10n?.deleteImpactCheckFailed
                                            || mainT('This page is out of date. Reload the page, then try again.')
                                        );
                                    }
                                    return buildConfirmMessage(fallbackWarnings);
                                }
                                if (impact.canDelete === false) {
                                    const blockMsg = impact.blockMessage
                                        || mainT('This time entry cannot be deleted.');
                                    const blocked = new Error(blockMsg);
                                    blocked.isDeleteBlocked = true;
                                    throw blocked;
                                }
                                const warnings = Array.isArray(impact.warnings)
                                    ? impact.warnings.filter(Boolean)
                                    : parseServerWarnings();
                                return buildConfirmMessage(warnings);
                            })
                            .catch((err) => {
                                if (err && err.isDeleteBlocked) {
                                    throw err;
                                }
                                if (err && err.message && err.message !== mainT('An error occurred')) {
                                    const blocked = new Error(err.message);
                                    blocked.isDeleteBlocked = true;
                                    throw blocked;
                                }
                                throw new Error(
                                    this.config.l10n?.deleteImpactCheckFailed
                                    || mainT('This page is out of date. Reload the page, then try again.')
                                );
                            });
                    };

                    loadDeletionConfirmMessage()
                        .then((confirmMsg) => this.confirmDestructiveMain(confirmMsg, confirmTitle, dialogOpts))
                        .then((confirmed) => {
                            if (!confirmed) {
                                return;
                            }
                            let deleteUrl = this.config.apiUrl?.delete || '';
                            if (deleteUrl && deleteUrl.includes('__ID__')) {
                                deleteUrl = deleteUrl.replace('__ID__', entryId);
                            } else if (!deleteUrl) {
                                deleteUrl = OC.generateUrl('/apps/arbeitszeitcheck/api/time-entries/' + entryId);
                            } else {
                                deleteUrl = deleteUrl.replace(/\/$/, '') + '/' + entryId;
                            }

                            return this.callApi(deleteUrl, 'DELETE', null, false)
                                .then(() => {
                                    const row = button.closest('tr');
                                    if (row) {
                                        row.remove();
                                    }
                                    const successMsg = this.config.l10n?.deleted ||
                                        mainT('Time entry deleted successfully');
                                    this.showSuccess(successMsg);
                                });
                        })
                        .catch((error) => {
                            console.error('Error deleting time entry:', error);
                            const msg = error && error.message
                                ? error.message
                                : (this.config.l10n?.error || mainT('An error occurred'));
                            const blockCode = error.response && error.response.error_code;
                            if (error.isDeleteBlocked || blockCode) {
                                return this.alertBlockingMain(
                                    msg,
                                    this.config.l10n?.cannotDeleteTimeEntryTitle
                                );
                            }
                            this.showError(msg);
                        })
                        .finally(() => {
                            button.disabled = false;
                            button.removeAttribute('aria-busy');
                        });
                });
            });

            // Pagination buttons (if implemented)
            const prevPageBtn = document.getElementById('btn-prev-page');
            const nextPageBtn = document.getElementById('btn-next-page');
            
            if (prevPageBtn) {
                prevPageBtn.addEventListener('click', () => {
                    const currentPage = parseInt(document.getElementById('current-page')?.textContent || '1');
                    if (currentPage > 1) {
                        const params = new URLSearchParams(window.location.search);
                        params.set('page', (currentPage - 1).toString());
                        window.location.href = window.location.pathname + '?' + params.toString();
                    }
                });
            }
            
            if (nextPageBtn) {
                nextPageBtn.addEventListener('click', () => {
                    const currentPage = parseInt(document.getElementById('current-page')?.textContent || '1');
                    const totalPages = parseInt(document.getElementById('total-pages')?.textContent || '1');
                    if (currentPage < totalPages) {
                        const params = new URLSearchParams(window.location.search);
                        params.set('page', (currentPage + 1).toString());
                        window.location.href = window.location.pathname + '?' + params.toString();
                    }
                });
            }
        },

        /**
         * Initialize absences page functionality
         */
        initAbsences: function() {
            // Show success/error toast when redirected with query params
            const params = new URLSearchParams(window.location.search);
            const created = params.get('created');
            const updated = params.get('updated');
            const cancelled = params.get('cancelled');
            const errorParam = params.get('error');
            const shortened = params.get('shortened');
            const shortenError = params.get('shorten_error');
            if (created === '1' || updated === '1') {
                const msg = created === '1'
                    ? (params.get('half') === '1'
                        ? mainT('Half-day vacation request submitted successfully')
                        : mainT('Absence request submitted successfully'))
                    : mainT('Absence request updated');
                if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                    window.OC.Notification.showTemporary(msg, { type: 'success' });
                }
                params.delete('created');
                params.delete('updated');
                params.delete('half');
            }
            if (shortened === '1') {
                const msg = mainT('Absence shortened successfully. Your actual last day of absence has been updated.');
                if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                    window.OC.Notification.showTemporary(msg, { type: 'success' });
                }
                params.delete('shortened');
            }
            if (shortenError) {
                if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                    window.OC.Notification.showTemporary(shortenError, { type: 'error', timeout: 6000 });
                }
                params.delete('shorten_error');
            }
            if (cancelled === '1') {
                const msg = mainT('Absence cancelled successfully.');
                if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                    window.OC.Notification.showTemporary(msg, { type: 'success' });
                }
                params.delete('cancelled');
            }
            if (errorParam) {
                if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                    window.OC.Notification.showTemporary(decodeURIComponent(errorParam), { type: 'error', timeout: 6000 });
                }
                params.delete('error');
            }
            if (created === '1' || updated === '1' || shortened === '1' || shortenError || cancelled === '1' || errorParam) {
                const qs = params.toString();
                const cleanUrl = window.location.pathname + (qs ? '?' + qs : '');
                window.history.replaceState({}, '', cleanUrl);
            }

            // Request Absence button
            const requestAbsenceBtn = document.getElementById('btn-request-absence');
            const requestFirstAbsenceBtn = document.getElementById('btn-request-first-absence');
            
            if (requestAbsenceBtn || requestFirstAbsenceBtn) {
                const handler = () => {
                    // Redirect to create absence page
                    const createUrl = OC.generateUrl('/apps/arbeitszeitcheck/absences/create');
                    window.location.href = createUrl;
                };
                
                if (requestAbsenceBtn) {
                    requestAbsenceBtn.addEventListener('click', handler);
                }
                if (requestFirstAbsenceBtn) {
                    requestFirstAbsenceBtn.addEventListener('click', handler);
                }
            }

            // Past absences are first-class records. The server still enforces
            // overlaps, entitlement, type rules and finalized-month protection.

            // Filter button - toggle filter section (only when both exist)
            const filterBtn = document.getElementById('btn-filter');
            const filterSection = document.getElementById('filter-section');
            if (filterBtn && filterSection) {
                // Ensure assistive technologies understand the toggle state
                filterBtn.setAttribute('aria-controls', 'filter-section');
                const applyFilterVisibility = (visible) => {
                    filterSection.style.display = visible ? 'block' : 'none';
                    filterSection.setAttribute('aria-hidden', visible ? 'false' : 'true');
                    filterBtn.setAttribute('aria-expanded', visible ? 'true' : 'false');
                };
                // Initialize ARIA state from current visibility
                const initiallyVisible = filterSection.style.display !== 'none';
                applyFilterVisibility(initiallyVisible);
                filterBtn.addEventListener('click', () => {
                    const isVisible = filterSection.style.display !== 'none';
                    applyFilterVisibility(!isVisible);
                });
            }

            // Apply / clear absences list filters (query: start_date, end_date, status)
            const applyAbsenceFilterBtn = document.getElementById('btn-apply-filter');
            if (applyAbsenceFilterBtn && filterSection) {
                applyAbsenceFilterBtn.addEventListener('click', () => {
                    const dp = window.ArbeitszeitCheckDatepicker;
                    const toISO = dp ? dp.convertEuropeanToISO : function (s) { return s; };
                    const startDate = toISO(document.getElementById('filter-start-date')?.value || '');
                    const endDate = toISO(document.getElementById('filter-end-date')?.value || '');
                    const status = document.getElementById('filter-status')?.value;
                    const params = new URLSearchParams();
                    if (startDate) params.append('start_date', startDate);
                    if (endDate) params.append('end_date', endDate);
                    if (status) params.append('status', status);
                    const queryString = params.toString();
                    window.location.href = window.location.pathname + (queryString ? '?' + queryString : '');
                });
            }
            const clearAbsenceFilterBtn = document.getElementById('btn-clear-filter');
            if (clearAbsenceFilterBtn && filterSection) {
                clearAbsenceFilterBtn.addEventListener('click', () => {
                    const startEl = document.getElementById('filter-start-date');
                    const endEl = document.getElementById('filter-end-date');
                    const statusEl = document.getElementById('filter-status');
                    if (startEl) startEl.value = '';
                    if (endEl) endEl.value = '';
                    if (statusEl) statusEl.value = '';
                    window.location.href = window.location.pathname;
                });
            }

            // Edit buttons in table rows (for pending absences)
            const editButtons = document.querySelectorAll('table tbody .btn-icon--edit[data-absence-id]');
            editButtons.forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const absenceId = button.dataset.absenceId;
                    if (absenceId) {
                        const editUrl = (this.config.apiUrl && this.config.apiUrl.edit)
                            ? this.config.apiUrl.edit.replace('__ID__', absenceId)
                            : OC.generateUrl('/apps/arbeitszeitcheck/absences/' + absenceId + '/edit');
                        window.location.href = editUrl;
                    }
                });
            });

            // Cancel buttons in table rows (for pending absences)
            const cancelButtons = document.querySelectorAll('table tbody .btn-icon--cancel[data-absence-id]');
            cancelButtons.forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const absenceId = button.dataset.absenceId;
                    if (!absenceId) return;
                    
                    const confirmMsg = this.config.l10n?.confirmCancel ||
                                     mainT('Are you sure you want to cancel this absence request?');
                    
                    const confirmTitle = this.config.l10n?.confirmCancelAbsenceTitle ||
                                     mainT('Cancel absence request');
                    const dialogOpts = (typeof OC !== 'undefined' && OC.dialogs)
                        ? { type: OC.dialogs.YES_NO_BUTTONS, modal: true }
                        : {};

                    this.confirmDestructiveMain(confirmMsg, confirmTitle, dialogOpts).then((confirmed) => {
                        if (!confirmed) {
                            return;
                        }
                        let deleteUrl = this.config.apiUrl?.delete || '';
                        if (deleteUrl && deleteUrl.includes('__ID__')) {
                            deleteUrl = deleteUrl.replace('__ID__', absenceId);
                        } else if (!deleteUrl) {
                            deleteUrl = OC.generateUrl('/apps/arbeitszeitcheck/api/absences/' + absenceId);
                        } else {
                            deleteUrl = deleteUrl.replace(/\/$/, '') + '/' + absenceId;
                        }

                        button.disabled = true;
                        button.setAttribute('aria-busy', 'true');
                        this.callApi(deleteUrl, 'DELETE', null, false)
                            .then(() => {
                                const row = button.closest('tr');
                                if (row) {
                                    row.remove();
                                }
                                const successMsg = this.config.l10n?.canceled ||
                                    mainT('Absence request cancelled successfully');
                                this.showSuccess(successMsg);
                            })
                            .catch((error) => {
                                console.error('Error canceling absence request:', error);
                                this.showError(error && error.message ? error.message : (this.config.l10n?.error || mainT('An error occurred')));
                            })
                            .finally(() => {
                                button.disabled = false;
                                button.removeAttribute('aria-busy');
                            });
                    });
                });
            });

            // View buttons in table rows (for approved/rejected absences)
            const viewButtons = document.querySelectorAll('table tbody .btn-icon--view[data-absence-id]');
            viewButtons.forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const absenceId = button.dataset.absenceId;
                    if (absenceId) {
                        const showUrl = (this.config.apiUrl && this.config.apiUrl.show)
                            ? this.config.apiUrl.show.replace('__ID__', absenceId)
                            : OC.generateUrl('/apps/arbeitszeitcheck/absences/' + absenceId);
                        window.location.href = showUrl;
                    }
                });
            });
        },

        /**
         * Clock in action
         */
        clockIn: function(projectCheckProjectId = null, description = null) {
            if (this.clockActionInFlight) {
                return;
            }
            this.clockActionInFlight = true;
            const data = {};
            if (projectCheckProjectId) {
                data.projectCheckProjectId = projectCheckProjectId;
            }
            if (description) data.description = description;

            this.callApi('/apps/arbeitszeitcheck/api/clock/in', 'POST', data)
                .catch((err) => {
                    this.showError(err && err.message ? err.message : (this.config.l10n?.error || 'An error occurred'));
                })
                .finally(() => {
                    this.clockActionInFlight = false;
                });
        },

        /**
         * Clock out action
         */
        clockOut: function() {
            if (this.clockActionInFlight) {
                return;
            }
            this.clockActionInFlight = true;
            // Stop timer immediately when clocking out
            if (this.timers.session) {
                clearInterval(this.timers.session);
                this.timers.session = null;
            }
            
            // Stop break timer if running
            if (this.timers.break) {
                clearInterval(this.timers.break);
                this.timers.break = null;
            }
            
            this.callApi('/apps/arbeitszeitcheck/api/clock/out', 'POST')
                .catch((err) => {
                    this.showError(err && err.message ? err.message : (this.config.l10n?.error || 'An error occurred'));
                })
                .finally(() => {
                    this.clockActionInFlight = false;
                });
        },

        /**
         * sessionStorage-based guard so the auto-clockout flow can never loop
         * across reloads inside the same browser session. Keyed per local day
         * so a fresh day naturally starts with a clean slate.
         *
         * Stored value: integer attempt counter as string. The flow tolerates
         * a small number of transient failures (e.g. flaky network) but stops
         * cold once the bound is reached, and surfaces a permanent error.
         */
        AUTO_CLOCKOUT_MAX_ATTEMPTS: 3,

        getAutoClockoutGuardKey: function() {
            return 'arbeitszeitcheck-auto-clockout-' + todayYmdLocal();
        },

        readAutoClockoutAttempts: function() {
            try {
                if (typeof window === 'undefined' || !window.sessionStorage) {
                    return 0;
                }
                const raw = window.sessionStorage.getItem(this.getAutoClockoutGuardKey());
                const n = parseInt(raw || '0', 10);
                return Number.isFinite(n) && n >= 0 ? n : 0;
            } catch (e) {
                return 0;
            }
        },

        bumpAutoClockoutAttempts: function() {
            try {
                if (typeof window === 'undefined' || !window.sessionStorage) {
                    return 1;
                }
                const next = this.readAutoClockoutAttempts() + 1;
                window.sessionStorage.setItem(this.getAutoClockoutGuardKey(), String(next));
                return next;
            } catch (e) {
                return 1;
            }
        },

        /**
         * Drive the ArbZG §3 automatic clock-out via the backend.
         *
         * Replaces the previous "show notification + window.location.reload()"
         * pattern that would loop forever whenever the backend hadn't actually
         * completed the entry yet. We now:
         *   - Bound the number of attempts via sessionStorage (no infinite loops).
         *   - Call the explicit /api/clock/enforce-daily-maximum endpoint so the
         *     backend transactionally completes the entry (ArbZG §3 audit reason).
         *   - Reload the page exactly ONCE on success to refresh the status card.
         *   - On failure: surface a clear, sticky error and stop. The user can
         *     still manually clock out via the button.
         */
        triggerAutomaticDailyMaximumClockOut: function() {
            const attempts = this.bumpAutoClockoutAttempts();

            const showCritical = (msg, opts) => {
                if (window.OC && OC.Notification && OC.Notification.showTemporary) {
                    OC.Notification.showTemporary(msg, Object.assign({ type: 'error', timeout: 20000 }, opts || {}));
                }
            };

            if (attempts > this.AUTO_CLOCKOUT_MAX_ATTEMPTS) {
                /* Hard stop: too many attempts in this session. Never reload again.
                   The user is informed and can clock out manually. */
                const giveUpMsg = mainT('Automatic clock-out (ArbZG §3) failed repeatedly. Please clock out manually or contact your administrator.');
                showCritical(giveUpMsg, { timeout: 0 });
                this.showError(giveUpMsg);
                return;
            }

            const criticalMsg = mainT('CRITICAL: Maximum daily working hours (10h) exceeded! Automatically clocking out to comply with German labor law (ArbZG §3).');
            showCritical(criticalMsg);

            this.callApi('/apps/arbeitszeitcheck/api/clock/enforce-daily-maximum', 'POST', null, false)
                .then((response) => {
                    if (response && response.success) {
                        const statusAfter = response.status && response.status.status ? String(response.status.status) : '';
                        const stillClockedIn = statusAfter === 'active' || statusAfter === 'break';
                        const enforced = response.enforced === true;
                        const dailyMaximumReached = response.daily_maximum_reached === true;

                        if (enforced || !stillClockedIn) {
                            const successMsg = mainT('Automatically clocked out to comply with German labor law (ArbZG §3).');
                            if (window.OC && OC.Notification && OC.Notification.showTemporary) {
                                OC.Notification.showTemporary(successMsg, { type: 'success', timeout: 10000 });
                            }
                            /* Refresh exactly once so the dashboard re-renders
                               with the clocked_out state. The sessionStorage guard
                               prevents this branch from being entered again on the
                               reloaded page even if the user happens to still see
                               workingTodayHours >= 10 (e.g. during the brief
                               interval before status data updates client-side). */
                            setTimeout(() => { window.location.reload(); }, 1500);
                        } else if (!dailyMaximumReached) {
                            /* Edge case: client-side extrapolation reached the threshold slightly
                               before the backend did. Do NOT treat this as a failure. Re-arm once. */
                            const timerValueEl = document.querySelector('.session-timer .timer-value');
                            if (timerValueEl && timerValueEl.dataset) {
                                delete timerValueEl.dataset.autoClockOutTriggered;
                            }
                            this.initTimer();
                        } else {
                            const errMsg = mainT('Automatic clock-out (ArbZG §3) could not be completed. Please clock out manually.');
                            showCritical(errMsg, { timeout: 0 });
                        }
                    } else {
                        const errMsg = (response && response.error)
                            ? String(response.error)
                            : mainT('Automatic clock-out (ArbZG §3) could not be completed. Please clock out manually.');
                        showCritical(errMsg, { timeout: 0 });
                    }
                })
                .catch((err) => {
                    const errMsg = (err && err.message)
                        ? String(err.message)
                        : mainT('Automatic clock-out (ArbZG §3) could not be completed. Please clock out manually.');
                    showCritical(errMsg, { timeout: 0 });
                });
        },

        /**
         * Start break action
         */
        startBreak: function() {
            if (this.clockActionInFlight) {
                return;
            }
            this.clockActionInFlight = true;
            this.callApi('/apps/arbeitszeitcheck/api/break/start', 'POST')
                .catch((err) => {
                    this.showError(err && err.message ? err.message : (this.config.l10n?.error || 'An error occurred'));
                })
                .finally(() => {
                    this.clockActionInFlight = false;
                });
        },

        /**
         * End break action
         */
        endBreak: function() {
            if (this.clockActionInFlight) {
                return;
            }
            this.clockActionInFlight = true;
            this.callApi('/apps/arbeitszeitcheck/api/break/end', 'POST')
                .catch((err) => {
                    this.showError(err && err.message ? err.message : (this.config.l10n?.error || 'An error occurred'));
                })
                .finally(() => {
                    this.clockActionInFlight = false;
                });
        },

        /**
         * Get current status
         */
        getStatus: function() {
            return this.callApi('/apps/arbeitszeitcheck/api/clock/status', 'GET', null, false).then((response) => {
                try {
                    const notice = response && response.status ? response.status.auto_clockout_notice : null;
                    if (notice && notice.message) {
                        const noticeKey = String(notice.at || notice.message);
                        if (this._lastAutoClockoutNoticeKey !== noticeKey) {
                            this._lastAutoClockoutNoticeKey = noticeKey;
                            this.showSuccess(String(notice.message));
                        }
                    }
                } catch (e) {
                    // keep status polling resilient
                }
                return response;
            });
        },

        /**
         * Get CSRF request token (from OC.requestToken or DOM fallback)
         * @returns {string} The request token for CSRF protection
         */
        getRequestToken: function() {
            if (typeof OC !== 'undefined' && OC.requestToken) {
                return OC.requestToken;
            }
            const head = document.querySelector('head');
            return (head && head.getAttribute('data-requesttoken')) || '';
        },

        /**
         * Resolve a URL for fetch(): paths from IURLGenerator::linkToRoute already include the web root
         * (and often /index.php/); passing those through OC.generateUrl again breaks routing (404),
         * so short-circuit when the path is already absolute under this instance.
         *
         * @param {string} endpoint
         * @returns {string}
         */
        resolveRequestUrl: function(endpoint) {
            if (!endpoint || typeof endpoint !== 'string') {
                return endpoint;
            }
            const Utils = window.ArbeitszeitCheckUtils;
            if (Utils && typeof Utils.resolveUrl === 'function') {
                return Utils.resolveUrl(endpoint);
            }
            if (endpoint.startsWith('http://') || endpoint.startsWith('https://')) {
                return endpoint;
            }
            if (endpoint.startsWith('/index.php/')) {
                return endpoint;
            }
            const root = (typeof OC !== 'undefined' && OC.getRootPath) ? String(OC.getRootPath() || '') : '';
            if (root !== '' && endpoint.startsWith(root + '/')) {
                return endpoint;
            }
            if (typeof OC !== 'undefined' && OC.generateUrl) {
                return OC.generateUrl(endpoint.startsWith('/') ? endpoint : ('/' + endpoint));
            }
            return endpoint.startsWith('/') ? endpoint : ('/' + endpoint);
        },

        /**
         * Destructive confirmation. Preference order:
         *   1. AzcComponents.confirmDialog — focus trap, aria-hidden on
         *      #app-content, return focus, parity with sibling check-apps.
         *   2. OC.dialogs.confirmDestructive — Nextcloud modal, theme-aware.
         *
         * @param {string} message
         * @param {string} title
         * @param {object} [options]
         * @returns {Promise<boolean>}
         */
        /**
         * Informational modal when deletion is blocked (not a toast).
         *
         * @param {string} message
         * @param {string} [title]
         * @returns {Promise<void>}
         */
        alertBlockingMain: function(message, title) {
            const alertTitle = title || this.config.l10n?.cannotDeleteTimeEntryTitle
                || mainT('Cannot delete time entry');
            const azc = (typeof window !== 'undefined') &&
                (window.AzcComponents || window.ArbeitszeitCheckComponents);
            if (azc && typeof azc.alertDialog === 'function') {
                return azc.alertDialog({
                    title: alertTitle,
                    message: message,
                });
            }
            if (typeof OC !== 'undefined' && OC.dialogs && typeof OC.dialogs.alert === 'function') {
                return new Promise((resolve) => {
                    OC.dialogs.alert(message, alertTitle, resolve, true);
                });
            }
            this.showError(message);
            return Promise.resolve();
        },

        confirmDestructiveMain: function(message, title, options) {
            const opts = options || {};
            const azc = (typeof window !== 'undefined') &&
                (window.AzcComponents || window.ArbeitszeitCheckComponents);
            if (azc && typeof azc.confirmDialog === 'function') {
                const confirmLabel = mainT('Confirm');
                const cancelLabel = mainT('Cancel');
                return azc.confirmDialog({
                    title: title || '',
                    message: message,
                    confirmLabel: confirmLabel,
                    cancelLabel: cancelLabel,
                    variant: 'danger',
                }).then((res) => {
                    // confirmDialog resolves true | false | { confirmed: true }
                    if (res && typeof res === 'object') {
                        return !!res.confirmed;
                    }
                    return !!res;
                });
            }
            return new Promise((resolve) => {
                let settled = false;
                const once = function(v) {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    resolve(!!v);
                };
                if (typeof OC !== 'undefined' && OC.dialogs && typeof OC.dialogs.confirmDestructive === 'function') {
                    // The button callback is the only authoritative source of the user's
                    // choice. In Nextcloud 31+ OC.dialogs.confirmDestructive() returns a
                    // Promise whose resolution value is ALWAYS `undefined` (its internal
                    // `.then(() => { callback.clicked || callback(false) })` has no
                    // return), so relying on the Promise value made every confirmation
                    // resolve as "cancelled". On top of that, the YES_NO_BUTTONS legacy
                    // path sets `callback._clicked` while the post-show check tests
                    // `callback.clicked`, so the callback fires twice — first with the
                    // real choice, then again with `false`. We therefore use the FIRST
                    // callback invocation only (guarded by the outer `once`) and treat
                    // the Promise solely as a "dialog closed" fallback.
                    let ret;
                    try {
                        ret = OC.dialogs.confirmDestructive(
                            message,
                            title || '',
                            opts,
                            function(confirmed) {
                                once(confirmed);
                            }
                        );
                    } catch (e) {
                        once(false);
                        return;
                    }
                    if (ret && typeof ret.then === 'function') {
                        ret.then(() => once(false)).catch(() => once(false));
                    }
                } else {
                    once(false);
                }
            });
        },

        /**
         * Generic API call helper
         * @param {string} endpoint - API endpoint URL
         * @param {string} method - HTTP method (GET, POST, PUT, DELETE)
         * @param {object|null} data - Data to send (null for GET/DELETE)
         * @param {boolean} reloadOnSuccess - Whether to reload page on success (default: true)
         */
		callApi: function(endpoint, method = 'POST', data = null, reloadOnSuccess = true) {
            const url = this.resolveRequestUrl(endpoint);

            // Build request options (requesttoken required for CSRF)
            const requestToken = this.getRequestToken();
            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'requesttoken': requestToken
                }
            };

            // Always send a JSON body for mutating methods. Empty POSTs without a
            // body (clock-out / break / enforce-daily-maximum) are rejected as opaque
            // HTTP 400 by some reverse proxies when Content-Type claims JSON.
            const upper = String(method || 'GET').toUpperCase();
            if (upper === 'POST' || upper === 'PUT' || upper === 'PATCH' || upper === 'DELETE') {
                const payload = (typeof data === 'object' && data !== null && !Array.isArray(data))
                    ? { ...data }
                    : {};
                if (requestToken) {
                    payload.requesttoken = requestToken;
                }
                options.body = JSON.stringify(payload);
            }

            // Show loading state
            this.setLoadingState(true);

            const resolveTransportError = (status, rawText) => {
                if (window.AzcApi && typeof window.AzcApi.mapApiError === 'function') {
                    return window.AzcApi.mapApiError(
                        rawText && String(rawText).trim() !== '' ? rawText : null,
                        status,
                    );
                }
                const code = Number.isFinite(status) && status > 0 ? String(status) : '';
                if (code !== '') {
                    return mainT(
                        'The server rejected this request (HTTP %s). Reload the page and try again. If it continues, contact your administrator.',
                    ).replace('%s', code);
                }
                return mainT('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
            };

            // Make the API call
            return fetch(url, options)
                .then(async response => {
                    // Check if response is JSON
                    const contentType = (response.headers.get('content-type') || '').toLowerCase();
                    let result;

                    if (contentType.includes('application/json')) {
                        result = await response.json();
                    } else {
                        const text = await response.text();
                        try {
                            result = JSON.parse(text);
                        } catch (e) {
                            const trimmed = (text || '').trim();
                            const unsafe = window.AzcApi
                                && typeof window.AzcApi.isUnsafeApiErrorText === 'function'
                                ? window.AzcApi.isUnsafeApiErrorText(trimmed)
                                : (trimmed === '' || trimmed.charAt(0) === '<' || /^\d{3}$/.test(trimmed));
                            if (!response.ok || unsafe) {
                                result = {
                                    success: false,
                                    error: resolveTransportError(response.status, trimmed),
                                };
                            } else {
                                result = { success: response.ok, error: trimmed || 'Unknown error' };
                            }
                        }
                    }

                    // Check if HTTP response indicates error
                    if (!response.ok) {
                        if (result && typeof result === 'object') {
                            const rawErr = typeof result.error === 'string' ? result.error
                                : (typeof result.message === 'string' ? result.message : '');
                            const unsafe = window.AzcApi
                                && typeof window.AzcApi.isUnsafeApiErrorText === 'function'
                                && window.AzcApi.isUnsafeApiErrorText(rawErr);
                            if (!rawErr || unsafe) {
                                result.error = resolveTransportError(response.status, rawErr);
                                if (typeof result.message === 'string'
                                    && window.AzcApi
                                    && typeof window.AzcApi.isUnsafeApiErrorText === 'function'
                                    && window.AzcApi.isUnsafeApiErrorText(result.message)) {
                                    result.message = result.error;
                                }
                            } else if (!result.error) {
                                result.error = result.message || resolveTransportError(response.status, '');
                            }
                            result.success = false;
                        } else {
                            result = {
                                success: false,
                                error: resolveTransportError(response.status, ''),
                            };
                        }
                    }

                    // Attach response to result for error handling
                    result._response = response;
                    return result;
                })
                .then(result => {
                    this.setLoadingState(false);

                    const res = result._response;
                    const okHttp = res && res.ok;
                    const hasSuccessKey = result && typeof result === 'object' && Object.prototype.hasOwnProperty.call(result, 'success');
                    const bodyOk = !hasSuccessKey || result.success === true;

                    if (okHttp && bodyOk) {
                        // Success
                        if (reloadOnSuccess) {
                            // Small delay to show success feedback
                            setTimeout(() => {
                                window.location.reload();
                            }, 300);
                        }
                        return result;
                    } else {
                        // Error - get error message from response
                        let errorMsg = result.error || result.message;
                        
                        // If no error message, use fallback
                        if (!errorMsg) {
                            errorMsg = this.config.l10n?.error || 'An error occurred';
                        }
                        
                        // Ensure error message is a plain string (not a translation key)
                        errorMsg = String(errorMsg);
                        
                        // Create error object with response data
                        const error = new Error(errorMsg);
                        error.response = result;
                        throw error;
                    }
                })
                .catch(error => {
                    this.setLoadingState(false);
                    
                    // If error already has response data, keep it
                    if (!error.response) {
                        // Try to extract error message from error object
                        let errorMsg = error.message;
                        
                        if (error.error) {
                            errorMsg = error.error;
                        }
                        
                        // If still no error message, use fallback
                        if (!errorMsg) {
                            errorMsg = this.config.l10n?.error || 'An error occurred';
                        }
                        
                        error.message = String(errorMsg);
                    }
                    
                    // Don't show error here - let the caller handle it
                    throw error;
                });
        },

        /**
         * Set loading state on buttons and forms (includes clock-in/out/break for double-submit guard)
         */
        setLoadingState: function(loading) {
            const buttons = document.querySelectorAll(
                'button[data-api-action], #btn-clock-in, #btn-start-break, #btn-end-break, .btn-clock-out'
            );
            buttons.forEach(button => {
                if (loading) {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                    button.dataset.originalText = button.textContent;
                    button.textContent = this.config.l10n?.loading || 'Loading...';
                } else {
                    button.disabled = false;
                    button.removeAttribute('aria-busy');
                    if (button.dataset.originalText) {
                        button.textContent = button.dataset.originalText;
                        delete button.dataset.originalText;
                    }
                }
            });
        },

        /**
         * Show error message to user
         */
        showError: function(message) {
            const errorMessage = typeof message === 'string' ? message : String(message || mainT('An error occurred'));
            if (window.AzcMessaging && typeof window.AzcMessaging.showError === 'function') {
                window.AzcMessaging.showError(errorMessage);
                return;
            }
            if (window.ArbeitszeitCheckMessaging && typeof window.ArbeitszeitCheckMessaging.showError === 'function') {
                window.ArbeitszeitCheckMessaging.showError(errorMessage);
                return;
            }
            if (window.OC && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                try {
                    OC.Notification.showTemporary(errorMessage);
                    return;
                } catch (e) {
                    console.warn('Failed to show notification:', e);
                }
            }
            const region = document.getElementById('azc-alert-region');
            if (region) {
                region.textContent = errorMessage;
            }
        },

        /**
         * Show success message to user
         */
        showSuccess: function(message) {
            const successMessage = typeof message === 'string' ? message : String(message || '');
            if (window.AzcMessaging && typeof window.AzcMessaging.showSuccess === 'function') {
                window.AzcMessaging.showSuccess(successMessage);
                return;
            }
            if (window.ArbeitszeitCheckMessaging && typeof window.ArbeitszeitCheckMessaging.showSuccess === 'function') {
                window.ArbeitszeitCheckMessaging.showSuccess(successMessage);
                return;
            }
            if (window.OC && OC.Notification && typeof OC.Notification.showTemporary === 'function') {
                OC.Notification.showTemporary(successMessage, { type: 'success' });
            }
        },

        /**
         * Format time duration (seconds to HH:MM:SS)
         */
        formatDuration: function(seconds) {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const secs = seconds % 60;
            return String(hours).padStart(2, '0') + ':' +
                   String(minutes).padStart(2, '0') + ':' +
                   String(secs).padStart(2, '0');
        },

        /**
         * Format date for display
         * Always uses 24-hour format for time (HH:MM)
         */
        formatDate: function(dateString, includeTime = false) {
            const utils = window.ArbeitszeitCheckUtils;
            if (utils && typeof utils.formatDate === 'function') {
                return utils.formatDate(dateString, includeTime ? 'DD.MM.YYYY HH:mm' : 'DD.MM.YYYY');
            }
            const api = timeApi();
            if (api) {
                return includeTime ? api.formatDateTime(dateString) : api.formatDate(dateString);
            }
            return String(dateString || '');
        },

        /** Cached timeline data for filter re-renders (set after successful load) */
        _timelineData: null,

        /** Session storage key for timeline filter preferences */
        _timelineFiltersKey: 'arbeitszeitcheck-timeline-filters',

        /**
         * Get current timeline filter state from checkboxes
         * @returns {{ timeEntries: boolean, absences: boolean, holidays: boolean }}
         */
        getTimelineFilterState: function() {
            const timeEntries = document.getElementById('timeline-filter-time-entries');
            const absences = document.getElementById('timeline-filter-absences');
            const holidays = document.getElementById('timeline-filter-holidays');
            return {
                timeEntries: timeEntries ? timeEntries.checked : true,
                absences: absences ? absences.checked : true,
                holidays: holidays ? holidays.checked : true
            };
        },

        /**
         * Restore timeline filter state from sessionStorage
         */
        restoreTimelineFilters: function() {
            try {
                const raw = sessionStorage.getItem(this._timelineFiltersKey);
                if (!raw) return;
                const saved = JSON.parse(raw);
                if (!saved || typeof saved !== 'object') return;
                const timeEntries = document.getElementById('timeline-filter-time-entries');
                const absences = document.getElementById('timeline-filter-absences');
                const holidays = document.getElementById('timeline-filter-holidays');
                if (timeEntries && saved.timeEntries !== undefined) timeEntries.checked = !!saved.timeEntries;
                if (absences && saved.absences !== undefined) absences.checked = !!saved.absences;
                if (holidays && saved.holidays !== undefined) holidays.checked = !!saved.holidays;
            } catch (e) {
                /* ignore parse/storage errors */
            }
        },

        /**
         * Persist timeline filter state to sessionStorage
         */
        persistTimelineFilters: function() {
            try {
                const state = this.getTimelineFilterState();
                sessionStorage.setItem(this._timelineFiltersKey, JSON.stringify(state));
            } catch (e) {
                /* ignore storage errors */
            }
        },

        /**
         * Apply filters and re-render timeline (uses cached data)
         */
        applyTimelineFilters: function() {
            const container = document.getElementById('timeline-container');
            if (!container || !this._timelineData) {
                return;
            }
            const filter = this.getTimelineFilterState();
            const allUnchecked = !filter.timeEntries && !filter.absences && !filter.holidays;
            if (allUnchecked) {
                const msg = this.config.l10n?.selectAtLeastOneFilter || 'Select at least one type to display in the timeline.';
                container.innerHTML = `
                    <div class="timeline-empty" role="status" aria-live="polite">
                        <p>${escapeHtml(msg)}</p>
                    </div>
                `;
                return;
            }
            const entries = filter.timeEntries ? this._timelineData.timeEntries : [];
            const absences = filter.absences ? this._timelineData.absences : [];
            const holidays = filter.holidays ? this._timelineData.holidays : [];
            this.renderTimeline(container, entries, absences, holidays);
        },

        /**
         * Initialize timeline page (with max retry to avoid infinite loop)
         */
        initTimeline: function(retryCount = 0) {
            const maxRetries = 20;
            const container = document.getElementById('timeline-container');
            if (!container) {
                if (retryCount >= maxRetries) {
                    console.warn('arbeitszeitcheck: timeline-container not found after ' + maxRetries + ' retries');
                    return;
                }
                setTimeout(() => {
                    this.initTimeline(retryCount + 1);
                }, 100);
                return;
            }

            this.restoreTimelineFilters();

            const refreshBtn = document.getElementById('btn-refresh-timeline');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', () => {
                    this.loadTimeline();
                });
            }

            const bindFilter = (id) => {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('change', () => {
                        this.persistTimelineFilters();
                        this.applyTimelineFilters();
                    });
                }
            };
            bindFilter('timeline-filter-time-entries');
            bindFilter('timeline-filter-absences');
            bindFilter('timeline-filter-holidays');

            this.loadTimeline();
        },

        /**
         * Load and render timeline data
         */
        loadTimeline: function() {
            const container = document.getElementById('timeline-container');
            if (!container) {
                return;
            }

            // Show loading state
            const loadingMsg = this.config.l10n?.loadingTimeline || 'Loading timeline...';
            container.innerHTML = `
                <div class="timeline-loading">
                    <div class="loading-spinner"></div>
                    <p>${escapeHtml(loadingMsg)}</p>
                </div>
            `;

            const timeEntriesUrl = this.config.apiUrl?.timeEntries || this._buildApiPath('/apps/arbeitszeitcheck/api/time-entries');
            const absencesUrl = this.config.apiUrl?.absences || this._buildApiPath('/apps/arbeitszeitcheck/api/absences');
            const holidaysUrl = this.config.apiUrl?.holidays || this._buildApiPath('/apps/arbeitszeitcheck/api/holidays');

            // Load both time entries and absences in parallel
            Promise.all([
                this.fetchTimelineData(timeEntriesUrl),
                this.fetchTimelineData(absencesUrl)
            ]).then(([timeEntries, absences]) => {
                // Ensure we have arrays
                const entries = Array.isArray(timeEntries) ? timeEntries : [];
                const abs = Array.isArray(absences) ? absences : [];

                // Derive a safe date range from existing items (if any)
                const allDates = [];
                entries.forEach(entry => {
                    const startTime = entry.start_time || entry.startTime;
                    if (startTime) {
                        const d = parseApiInstant(startTime);
                        if (d) {
                            allDates.push(d);
                        }
                    }
                });
                abs.forEach(absence => {
                    const startDate = absence.start_date || absence.startDate;
                    if (startDate) {
                        const d = parseYmdToLocalDate(String(startDate).slice(0, 10));
                        if (d) {
                            allDates.push(d);
                        }
                    }
                });

                if (allDates.length === 0) {
                    this._timelineData = { timeEntries: entries, absences: abs, holidays: [] };
                    this.applyTimelineFilters();
                    return;
                }

                const minTime = Math.min.apply(null, allDates.map(d => d.getTime()));
                const maxTime = Math.max.apply(null, allDates.map(d => d.getTime()));
                const start = new Date(minTime);
                const end = new Date(maxTime);

                const startDateYmd = formatLocalDateYmd(start);
                const endDateYmd = formatLocalDateYmd(end);

                this.fetchTimelineData(holidaysUrl, { start: startDateYmd, end: endDateYmd })
                    .then((holidaysResponse) => {
                        const holidays = Array.isArray(holidaysResponse && holidaysResponse.holidays) ? holidaysResponse.holidays : [];
                        this._timelineData = { timeEntries: entries, absences: abs, holidays };
                        this.applyTimelineFilters();
                    })
                    .catch(() => {
                        this._timelineData = { timeEntries: entries, absences: abs, holidays: [] };
                        this.applyTimelineFilters();
                    });
            }).catch((error) => {
                const errMsg = error && error.message ? escapeHtml(error.message) : (this.config.l10n?.error || 'An error occurred');
                container.innerHTML = `
                    <div class="timeline-error">
                        <p>${escapeHtml(this.config.l10n?.error || 'An error occurred')}: ${errMsg}</p>
                    </div>
                `;
            });
        },

        /**
         * Fetch timeline data from API
         * @param {string} url - Route path (e.g. from linkToRoute) or full URL if already containing protocol
         * @param {Record<string,string>} [queryParams] - Optional query params (e.g. { start_date: '2025-01-01', end_date: '2025-01-31', limit: '500' })
         */
        fetchTimelineData: function(url, queryParams) {
            // PHP linkToRoute() returns a path like /index.php/apps/...; use as-is. Only use OC.generateUrl for paths without leading slash.
            let fullUrl = (url.indexOf('http') === 0 || url.indexOf('//') === 0) ? url : (url.charAt(0) === '/' ? url : (typeof OC !== 'undefined' && OC.generateUrl ? OC.generateUrl(url) : url));
            if (queryParams && Object.keys(queryParams).length > 0) {
                const sep = fullUrl.indexOf('?') >= 0 ? '&' : '?';
                fullUrl += sep + new URLSearchParams(queryParams).toString();
            }
            return fetch(fullUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': this.getRequestToken()
                },
                credentials: 'same-origin'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                // Unwrap Nextcloud OCS envelope if present
                if (data && data.ocs && data.ocs.data) {
                    data = data.ocs.data;
                }

                // Handle different response formats
                if (Array.isArray(data)) {
                    return data;
                } else if (data && data.success && data.holidays && Array.isArray(data.holidays)) {
                    // Format: {success: true, state: 'NW', holidays: [...]} – return full object so calendar can use .holidays
                    return data;
                } else if (data && data.success && data.entries && Array.isArray(data.entries)) {
                    // Format: {success: true, entries: [...]}
                    return data.entries;
                } else if (data && data.success && data.absences && Array.isArray(data.absences)) {
                    // Format: {success: true, absences: [...]}
                    return data.absences;
                } else if (data && data.data && Array.isArray(data.data)) {
                    return data.data;
                } else if (data && data.timeEntries && Array.isArray(data.timeEntries)) {
                    return data.timeEntries;
                } else if (data && data.absences && Array.isArray(data.absences)) {
                    return data.absences;
                }
                return [];
            })
            .catch(error => {
                // Rethrow so calendar can show error; timeline and other callers may expect array, so only rethrow when used with queryParams (calendar usage)
                if (queryParams && Object.keys(queryParams).length > 0) {
                    throw error;
                }
                return [];
            });
        },

        /**
         * Render timeline with time entries, absences, and holidays
         */
        renderTimeline: function(container, timeEntries, absences, holidays) {
            // Combine and sort all items by date
            const items = [];
            
            // Add time entries
            timeEntries.forEach(entry => {
                const startTime = entry.start_time || entry.startTime;
                if (startTime) {
                    const entryDate = parseApiInstant(startTime);
                    if (!entryDate) {
                        return;
                    }
                    items.push({
                        type: 'time_entry',
                        date: entryDate,
                        data: entry
                    });
                }
            });

            // Add absences
            absences.forEach(absence => {
                const startDate = absence.start_date || absence.startDate;
                if (startDate) {
                    const absenceDate = parseYmdToLocalDate(String(startDate).slice(0, 10));
                    if (!absenceDate) {
                        return;
                    }
                    items.push({
                        type: 'absence',
                        date: absenceDate,
                        data: absence
                    });
                }
            });

            // Add holidays (statutory, company, custom) as separate, read-only items
            if (Array.isArray(holidays)) {
                holidays.forEach(holiday => {
                    if (!holiday || !holiday.date) {
                        return;
                    }
                    const dateObj = parseYmdToLocalDate(String(holiday.date).slice(0, 10));
                    if (!dateObj) {
                        return;
                    }
                    items.push({
                        type: 'holiday',
                        date: dateObj,
                        data: holiday
                    });
                });
            }

            // Sort by date (newest first)
            items.sort((a, b) => b.date - a.date);

            if (items.length === 0) {
                const emptyMsg = this.config.l10n?.noTimelineData || 'No timeline data available';
                container.innerHTML = `
                    <div class="timeline-empty" role="status" aria-live="polite">
                        <p>${escapeHtml(emptyMsg)}</p>
                    </div>
                `;
                return;
            }

            // Group items by date using local calendar date (not UTC) to avoid timezone drift
            const grouped = {};
            items.forEach(item => {
                const dateKey = formatLocalDateYmd(item.date);
                if (!grouped[dateKey]) {
                    grouped[dateKey] = [];
                }
                grouped[dateKey].push(item);
            });

            // Render timeline
            let html = '<div class="timeline">';
            const sortedDates = Object.keys(grouped).sort((a, b) => b.localeCompare(a));
            
            sortedDates.forEach(dateKey => {
                // Append T00:00:00 so the date string is parsed as local midnight, not UTC midnight.
                const date = parseYmdToLocalDate(dateKey) || new Date(dateKey + 'T12:00:00');
                // Format date using translated month and weekday names
                const months = this.config.l10n?.months || [
                mainT('January'),
                mainT('February'),
                mainT('March'),
                mainT('April'),
                mainT('May'),
                mainT('June'),
                mainT('July'),
                mainT('August'),
                mainT('September'),
                mainT('October'),
                mainT('November'),
                mainT('December')
            ];
                const weekdays = this.config.l10n?.weekdays || [
                    mainT('Sunday'),
                    mainT('Monday'),
                    mainT('Tuesday'),
                    mainT('Wednesday'),
                    mainT('Thursday'),
                    mainT('Friday'),
                    mainT('Saturday')
                ];
                const weekdayName = weekdays[date.getDay()];
                const monthName = months[date.getMonth()];
                // Use German date format: "Freitag, 2. Januar 2026"
                const day = date.getDate();
                const year = date.getFullYear();
                const dateStr = `${weekdayName}, ${day}. ${monthName} ${year}`;
                
                html += `
                    <div class="timeline-day">
                        <div class="timeline-day-header">
                            <h3>${dateStr}</h3>
                        </div>
                        <div class="timeline-day-items">
                `;

                grouped[dateKey].forEach(item => {
                    if (item.type === 'time_entry') {
                        html += this.renderTimeEntryItem(item.data);
                    } else if (item.type === 'absence') {
                        html += this.renderAbsenceItem(item.data);
                    } else if (item.type === 'holiday') {
                        html += this.renderHolidayItem(item.data);
                    }
                });

                html += `
                        </div>
                    </div>
                `;
            });

            html += '</div>';
            container.innerHTML = html;
        },

        /**
         * Render a time entry item for timeline
         */
        renderTimeEntryItem: function(entry) {
            const startTime = entry.start_time || entry.startTime;
            const endTime = entry.end_time || entry.endTime;
            
            // Get duration values - API returns hours, but also check for seconds format
            let workingDurationHours = entry.workingDurationHours || entry.working_duration_hours || 0;
            let breakDurationHours = entry.breakDurationHours || entry.break_duration_hours || 0;
            let durationHours = entry.durationHours || entry.duration_hours || 0;
            
            // If we have duration in seconds instead of hours, convert
            if (entry.duration && !entry.durationHours && !entry.workingDurationHours) {
                // Duration is in seconds, convert to hours
                workingDurationHours = (entry.duration || entry.working_duration || 0) / 3600;
            }
            if (entry.break_duration && !entry.breakDurationHours) {
                breakDurationHours = entry.break_duration / 3600;
            }
            
            // If we still don't have working duration, try to calculate from start/end times
            if (workingDurationHours === 0 && startTime && endTime) {
                const start = parseApiInstant(startTime);
                const end = parseApiInstant(endTime);
                const totalSeconds = (start && end) ? ((end.getTime() - start.getTime()) / 1000) : 0;
                durationHours = totalSeconds / 3600;
                // Subtract break time if available
                workingDurationHours = durationHours - breakDurationHours;
            }
            
            const status = entry.status || 'completed';
            
            const startTimeStr = startTime ? formatDisplayTime(startTime) : '-';
            const endTimeStr = endTime ? formatDisplayTime(endTime) : '-';
            
            // Format duration: show working hours and break time
            const workingHours = Math.floor(workingDurationHours);
            const workingMinutes = Math.floor((workingDurationHours - workingHours) * 60);
            let durationStr = `${workingHours}h ${workingMinutes}m`;
            
            // Add break time if available
            if (breakDurationHours > 0) {
                const breakHours = Math.floor(breakDurationHours);
                const breakMinutes = Math.floor((breakDurationHours - breakHours) * 60);
                const breakLabel = this.config.l10n?.breakTime || mainT('Break Time') || 'Break';
                durationStr += ` (${breakLabel}: ${breakHours}h ${breakMinutes}m)`;
            }

            // Translate status
            let statusLabel = status;
            if (status === 'completed') {
                statusLabel = this.config.l10n?.statusCompleted || 'Completed';
            } else if (status === 'active') {
                statusLabel = this.config.l10n?.statusActive || 'Active';
            } else if (status === 'pending' || status === 'pending_approval') {
                statusLabel = this.config.l10n?.statusPending || 'Pending';
            }

            return `
                <div class="timeline-item timeline-item--time-entry">
                    <div class="timeline-item-icon">${azcIcon('clock', 'timeline-item-icon-svg')}</div>
                    <div class="timeline-item-content">
                        <div class="timeline-item-header">
                            <span class="timeline-item-time">${escapeHtml(startTimeStr)} - ${escapeHtml(endTimeStr)}</span>
                            <span class="timeline-item-duration">${escapeHtml(durationStr)}</span>
                        </div>
                        <div class="timeline-item-status">
                            <span class="badge badge--${this.getTimeEntryStatusBadgeClass(status)}">${escapeHtml(statusLabel)}</span>
                        </div>
                    </div>
                </div>
            `;
        },

        /**
         * Get translated label for absence type (same keys as PHP form/tables)
         */
        getAbsenceTypeLabel: function(type) {
            const key = (type || '').toLowerCase();
            const map = {
                vacation: 'Vacation',
                holiday: 'Vacation',
                sick: 'Sick Leave',
                sick_leave: 'Sick Leave',
                personal_leave: 'Personal Leave',
                parental_leave: 'Parental Leave',
                special_leave: 'Special Leave',
                unpaid_leave: 'Unpaid Leave',
                home_office: 'Home Office',
                business_trip: 'Business Trip'
            };
            const labelKey = map[key] || 'Absence';
            // Prefer server-provided translation map when available (timeline/index.php),
            // fallback to global t() and then raw key.
            const absenceTypes = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n.absenceTypes) || {};
            if (absenceTypes[key]) {
                return absenceTypes[key];
            }
            const translated = mainT(labelKey) || labelKey;
            return translated;
        },

        /**
         * Return display label for absence (own absences: type label; substitute: "Covering for X")
         */
        getAbsenceDisplayLabel: function(absence) {
            if (absence && absence.role === 'substitute' && absence.ownerDisplayName) {
                const tmpl = (this.config.l10n && this.config.l10n.coveringFor) || 'Covering for %1$s';
                return String(tmpl).replace('%1$s', absence.ownerDisplayName);
            }
            return this.getAbsenceTypeLabel(absence ? (absence.type || 'absence') : 'absence');
        },

        /**
         * Translated workflow status for an absence (calendar, timeline, day panel).
         */
        getAbsenceStatusLabel: function(absence) {
            const status = absence && absence.status ? String(absence.status) : 'pending';
            const l10n = this.config.l10n || {};
            if (status === 'approved') {
                return l10n.statusApproved || mainT('Approved');
            }
            if (status === 'rejected') {
                return l10n.statusRejected || mainT('Rejected');
            }
            if (status === 'substitute_pending') {
                return l10n.statusSubstitutePending || mainT('Awaiting substitute approval');
            }
            if (status === 'substitute_declined') {
                return l10n.statusSubstituteDeclined || mainT('Declined by substitute');
            }
            if (status === 'pending') {
                return l10n.statusPending || mainT('Pending');
            }
            return status;
        },

        getTimeEntryStatusBadgeClass: function(status) {
            const normalized = status ? String(status) : 'completed';
            if (typeof window.ArbeitszeitCheckUtils !== 'undefined'
                && typeof window.ArbeitszeitCheckUtils.badgeVariantForTimeEntryStatus === 'function') {
                return window.ArbeitszeitCheckUtils.badgeVariantForTimeEntryStatus(normalized);
            }
            if (normalized === 'completed') {
                return 'success';
            }
            if (normalized === 'active') {
                return 'primary';
            }
            return 'warning';
        },

        getAbsenceStatusBadgeClass: function(absence) {
            const status = absence && absence.status ? String(absence.status) : 'pending';
            if (typeof window.ArbeitszeitCheckUtils !== 'undefined'
                && typeof window.ArbeitszeitCheckUtils.badgeVariantForAbsenceStatus === 'function') {
                return window.ArbeitszeitCheckUtils.badgeVariantForAbsenceStatus(status);
            }
            if (status === 'approved') {
                return 'success';
            }
            if (status === 'rejected' || status === 'substitute_declined') {
                return 'error';
            }
            if (status === 'cancelled') {
                return 'secondary';
            }
            return 'warning';
        },

        getAbsenceIconName: function(absence) {
            const type = absence && absence.type ? String(absence.type) : 'absence';
            if (absence && absence.role === 'substitute') {
                return 'user-check';
            }
            if (type === 'vacation' || type === 'holiday') {
                return 'calendar-heart';
            }
            if (type === 'sick' || type === 'sick_leave') {
                return 'circle-alert';
            }
            if (type === 'unpaid_leave') {
                return 'calendar-off';
            }
            if (type === 'business_trip') {
                return 'activity';
            }
            if (type === 'home_office') {
                return 'users';
            }
            return 'calendar-off';
        },

        getAbsenceTypeModifierClass: function(absence) {
            if (absence && absence.role === 'substitute') {
                return 'calendar-day-absence--coverage';
            }
            const type = absence && absence.type ? String(absence.type) : 'other';
            if (type === 'vacation' || type === 'holiday') {
                return 'calendar-day-absence--vacation';
            }
            if (type === 'sick' || type === 'sick_leave') {
                return 'calendar-day-absence--sick';
            }
            if (type === 'unpaid_leave') {
                return 'calendar-day-absence--unpaid';
            }
            return 'calendar-day-absence--other';
        },

        formatMoreAbsencesLabel: function(extraCount) {
            const count = Number(extraCount);
            if (!Number.isFinite(count) || count < 1) {
                return '';
            }
            const tpl = (this.config.l10n && this.config.l10n.moreAbsencesOnDay) || '+{count} more';
            return String(tpl).replace('{count}', String(count));
        },

        /**
         * Visible absence chip: icon + type label + status text (theme-safe, not icon-only).
         */
        renderCalendarDayAbsenceMarkup: function(absence, options) {
            const opts = options || {};
            const extraCount = Number(opts.extraCount) || 0;
            const displayLabel = this.getAbsenceDisplayLabel(absence);
            const statusLabel = this.getAbsenceStatusLabel(absence);
            const badgeClass = this.getAbsenceStatusBadgeClass(absence);
            const typeClass = this.getAbsenceTypeModifierClass(absence);
            const iconName = this.getAbsenceIconName(absence);
            const titleParts = [displayLabel, statusLabel].filter(Boolean).join(' – ');
            let html = `<div class="calendar-day-absence ${typeClass}" title="${escapeHtml(titleParts)}">`;
            html += '<span class="calendar-day-absence-chip">';
            html += `<span class="calendar-day-absence-chip__icon" aria-hidden="true">${azcIcon(iconName, 'calendar-day-absence-chip__icon-svg')}</span>`;
            html += `<span class="calendar-day-absence-chip__label">${escapeHtml(displayLabel)}</span>`;
            html += `<span class="calendar-day-absence-chip__status badge badge--${badgeClass}">${escapeHtml(statusLabel)}</span>`;
            html += '</span>';
            if (extraCount > 0) {
                const moreLabel = this.formatMoreAbsencesLabel(extraCount);
                const moreTitle = (this.config.l10n && this.config.l10n.moreAbsencesOnDayTitle)
                    || 'Additional absences on this day';
                html += `<span class="calendar-day-absence-more" title="${escapeHtml(moreTitle)}">${escapeHtml(moreLabel)}</span>`;
            }
            html += '</div>';
            return html;
        },

        buildCalendarDayAbsenceContent: function(dayData) {
            if (!dayData || !dayData.hasAbsence || !dayData.absences || dayData.absences.length === 0) {
                return '';
            }
            const absence = dayData.absences[0];
            let html = '';
            if (this.isPastAbsenceRecord(absence)) {
                html += `<span class="calendar-day-past-label">${escapeHtml(this.config.l10n?.pastRecord || 'Past record')}</span>`;
            }
            const extraCount = Math.max(0, dayData.absences.length - 1);
            html += this.renderCalendarDayAbsenceMarkup(absence, { extraCount });
            return html;
        },

        /**
         * Render an absence item for timeline
         */
        renderAbsenceItem: function(absence) {
            const startDate = absence.start_date || absence.startDate;
            const endDate = absence.end_date || absence.endDate;
            const _type = absence.type || 'unknown';
            const _status = absence.status || 'pending';
            const translatedType = this.getAbsenceDisplayLabel(absence);
            const isCoverage = absence && absence.role === 'substitute';

            const start = startDate ? parseYmdToLocalDate(String(startDate).slice(0, 10)) : null;
            const end = endDate ? parseYmdToLocalDate(String(endDate).slice(0, 10)) : null;
            
            // Format dates using translated month names
            const _months = this.config.l10n?.months || [
                mainT('January'),
                mainT('February'),
                mainT('March'),
                mainT('April'),
                mainT('May'),
                mainT('June'),
                mainT('July'),
                mainT('August'),
                mainT('September'),
                mainT('October'),
                mainT('November'),
                mainT('December')
            ];
            const formatDate = (date) => {
                if (!date) return '-';
                // Use German date format (DD.MM.YYYY)
                const day = String(date.getDate()).padStart(2, '0');
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const year = date.getFullYear();
                return `${day}.${month}.${year}`;
            };
            
            const dateStr = start && end && start.toDateString() === end.toDateString()
                ? formatDate(start)
                : `${formatDate(start)} - ${formatDate(end)}`;

            const statusLabel = this.getAbsenceStatusLabel(absence);
            const badgeClass = this.getAbsenceStatusBadgeClass(absence);
            const absenceIconName = this.getAbsenceIconName(absence);
            return `
                <div class="timeline-item timeline-item--absence${isCoverage ? ' timeline-item--coverage' : ''}">
                    <div class="timeline-item-icon">${azcIcon(absenceIconName, 'timeline-item-icon-svg')}</div>
                    <div class="timeline-item-content">
                        <div class="timeline-item-header">
                            <span class="timeline-item-type">${escapeHtml(translatedType)}</span>
                            <span class="timeline-item-date">${escapeHtml(dateStr)}</span>
                        </div>
                        <div class="timeline-item-status">
                            <span class="badge badge--${badgeClass}">${escapeHtml(statusLabel)}</span>
                        </div>
                    </div>
                </div>
            `;
        },

        /**
         * Render a holiday item for the timeline (statutory, company, or custom)
         */
        renderHolidayItem: function(holiday) {
            const dateStr = holiday && typeof holiday.date === 'string' ? holiday.date : '';
            const name = (holiday && typeof holiday.name === 'string' && holiday.name !== '') ? holiday.name : '';
            const scope = (holiday && typeof holiday.scope === 'string') ? holiday.scope : '';

            let scopeLabel;
            if (scope === 'statutory') {
                scopeLabel = this.config.l10n?.publicHoliday || mainT('Public holiday');
            } else if (scope === 'company') {
                scopeLabel = this.config.l10n?.companyHoliday || mainT('Company holiday');
            } else {
                scopeLabel = this.config.l10n?.customHoliday || mainT('Custom holiday');
            }

            const displayName = name !== '' ? name : scopeLabel;
            const ariaLabel = `${scopeLabel}: ${displayName}${dateStr ? ' (' + dateStr + ')' : ''}`;

            return `
                <div class="timeline-item timeline-item--holiday" aria-label="${escapeHtml(ariaLabel)}">
                    <div class="timeline-item-icon">${azcIcon('calendar-heart', 'timeline-item-icon-svg')}</div>
                    <div class="timeline-item-content">
                        <div class="timeline-item-header">
                            <span class="timeline-item-type">${escapeHtml(scopeLabel)}</span>
                            <span class="timeline-item-date">${escapeHtml(displayName)}</span>
                        </div>
                    </div>
                </div>
            `;
        },

        /**
         * Initialize calendar page
         */
        initCalendar: function() {
            const _now = new Date();
            const monthStr = this.config.currentMonth ||
                (_now.getFullYear() + '-' + String(_now.getMonth() + 1).padStart(2, '0'));
            this.calendarData = {
                timeEntries: [],
                absences: [],
                holidays: [],
                currentDate: new Date(monthStr + '-01'),
                currentView: this.config.currentView || 'month'
            };

            // Bind event listeners
            const prevBtn = document.getElementById('btn-prev-period');
            const nextBtn = document.getElementById('btn-next-period');
            const todayBtn = document.getElementById('btn-today');
            const monthViewBtn = document.getElementById('btn-month-view');
            const weekViewBtn = document.getElementById('btn-week-view');
            const closePanelBtn = document.getElementById('btn-close-panel');

            if (prevBtn) {
                prevBtn.addEventListener('click', () => this.navigateCalendar(-1));
            }
            if (nextBtn) {
                nextBtn.addEventListener('click', () => this.navigateCalendar(1));
            }
            if (todayBtn) {
                todayBtn.addEventListener('click', () => this.goToToday());
            }
            if (monthViewBtn) {
                monthViewBtn.addEventListener('click', () => this.switchView('month'));
            }
            if (weekViewBtn) {
                weekViewBtn.addEventListener('click', () => this.switchView('week'));
            }
            if (closePanelBtn) {
                closePanelBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.closeDayDetailsPanel();
                });
            }
            if (!this._dayPanelOutsideClickBound) {
                this._dayPanelOutsideClickBound = true;
                document.addEventListener('click', (e) => {
                    if (!this.isDayDetailsPanelOpen()) {
                        return;
                    }
                    const panel = document.getElementById('day-details-panel');
                    if (panel && panel.contains(e.target)) {
                        return;
                    }
                    if (e.target.closest('.calendar-day[data-date], .calendar-week-day[data-date]')) {
                        return;
                    }
                    this.closeDayDetailsPanel();
                });
            }
            if (!this._dayPanelEscHandlerBound) {
                this._dayPanelEscHandlerBound = true;
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && this.isDayDetailsPanelOpen()) {
                        e.preventDefault();
                        e.stopPropagation();
                        this.closeDayDetailsPanel();
                    }
                });
            }

            // Load calendar data
            this.loadCalendarData();
        },

        /**
         * Whether the calendar day details side panel is open.
         * @returns {boolean}
         */
        isDayDetailsPanelOpen: function() {
            const panel = document.getElementById('day-details-panel');
            return !!(panel && !panel.hidden);
        },

        /**
         * Mount panel on document.body so fixed positioning is not clipped by overflow ancestors.
         */
        _mountDayDetailsOverlay: function() {
            const panel = document.getElementById('day-details-panel');
            if (panel && panel.parentNode !== document.body) {
                document.body.appendChild(panel);
            }
        },

        /**
         * Align overlay top with the live Nextcloud header (never under profile menu).
         */
        _syncDayPanelOverlayMetrics: function() {
            const Utils = window.ArbeitszeitCheckUtils;
            if (Utils && typeof Utils.syncAzcOverlayMetrics === 'function') {
                return Utils.syncAzcOverlayMetrics();
            }
            const header = document.getElementById('header');
            let top = 50;
            if (header) {
                const rect = header.getBoundingClientRect();
                if (rect.height > 0) {
                    top = Math.ceil(rect.bottom);
                }
            }
            document.body.style.setProperty('--azc-overlay-top', top + 'px');
            document.body.style.setProperty('--azc-overlay-height', 'calc(100dvh - ' + top + 'px)');
            return top;
        },

        _bindDayPanelResizeSync: function() {
            if (this._dayPanelResizeHandlerBound) {
                return;
            }
            this._dayPanelResizeHandlerBound = true;
            this._dayPanelResizeHandler = () => {
                if (this.isDayDetailsPanelOpen()) {
                    this._syncDayPanelOverlayMetrics();
                }
            };
            window.addEventListener('resize', this._dayPanelResizeHandler, { passive: true });
        },

        /**
         * @param {string|null} dateKey YYYY-MM-DD or null to clear selection highlight
         */
        _setDayPanelSelectionHighlight: function(dateKey) {
            document.querySelectorAll('.calendar-day--panel-selected, .calendar-week-day.calendar-day--panel-selected')
                .forEach((el) => {
                    el.classList.remove('calendar-day--panel-selected');
                    el.removeAttribute('aria-current');
                });
            if (!dateKey) {
                return;
            }
            const selector = `.calendar-day[data-date="${dateKey}"], .calendar-week-day[data-date="${dateKey}"]`;
            document.querySelectorAll(selector).forEach((el) => {
                el.classList.add('calendar-day--panel-selected');
                el.setAttribute('aria-current', 'date');
            });
        },

        _setDayPanelSectionOpen: function(isOpen) {
            const section = document.querySelector('.calendar-section');
            if (section) {
                section.classList.toggle('calendar-section--day-panel-open', !!isOpen);
            }
            document.body.classList.toggle('azc-day-panel-open', !!isOpen);
        },

        /**
         * Build a correct API path for Nextcloud (uses OC.generateUrl when available)
         * @param {string} path - Path like /apps/arbeitszeitcheck/api/holidays
         * @returns {string}
         */
        _buildApiPath: function(path) {
            const Utils = window.ArbeitszeitCheckUtils;
            if (Utils && typeof Utils.buildAppUrl === 'function') {
                return Utils.buildAppUrl(path.startsWith('/') ? path : '/' + path);
            }
            if (typeof OC !== 'undefined' && OC.generateUrl) {
                return OC.generateUrl(path.startsWith('/') ? path : '/' + path);
            }
            return path.startsWith('/') ? path : '/' + path;
        },

        /**
         * Load calendar data from API for the currently displayed period (month or week)
         */
        loadCalendarData: function() {
            // Use runtime config so apiUrl is set even when main script ran before calendar inline script
            const apiUrl = (typeof window !== 'undefined' && window.ArbeitszeitCheck && window.ArbeitszeitCheck.apiUrl) || this.config.apiUrl || {};
            const timeEntriesPath = apiUrl.calendar || this._buildApiPath('/apps/arbeitszeitcheck/api/time-entries');
            const absencesPath = apiUrl.absences || this._buildApiPath('/apps/arbeitszeitcheck/api/absences');
            const holidaysPath = apiUrl.holidays || this._buildApiPath('/apps/arbeitszeitcheck/api/holidays');

            const d = this.calendarData.currentDate;
            const year = d.getFullYear();
            const month = d.getMonth();
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const startDate = formatLocalDateYmd(firstDay);
            const endDate = formatLocalDateYmd(lastDay);

            const monthViewEl = document.getElementById('calendar-month-view');
            const weekViewEl = document.getElementById('calendar-week-view');
            const l10n = (typeof window !== 'undefined' && window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n) || this.config.l10n || {};
            const loadingHtml = `
                <div class="calendar-loading" role="status" aria-live="polite">
                    <div class="loading-spinner" aria-hidden="true"></div>
                    <p>${escapeHtml(l10n.loadingCalendar || 'Loading calendar...')}</p>
                </div>
            `;
            if (this.calendarData.currentView === 'month') {
                if (monthViewEl) {
                    monthViewEl.innerHTML = loadingHtml;
                    monthViewEl.style.display = '';
                }
                if (weekViewEl) weekViewEl.style.display = 'none';
            } else {
                if (weekViewEl) {
                    weekViewEl.innerHTML = loadingHtml;
                    weekViewEl.style.display = '';
                }
                if (monthViewEl) monthViewEl.style.display = 'none';
            }

            const timeEntriesParams = { start_date: startDate, end_date: endDate, limit: '500' };
            const absencesParams = { start_date: startDate, end_date: endDate, limit: '500' };
            const holidaysParams = { start: startDate, end: endDate };

            Promise.all([
                this.fetchTimelineData(timeEntriesPath, timeEntriesParams),
                this.fetchTimelineData(absencesPath, absencesParams),
                this.fetchTimelineData(holidaysPath, holidaysParams)
            ]).then(([timeEntries, absences, holidaysResponse]) => {
                this.calendarData.timeEntries = Array.isArray(timeEntries) ? timeEntries : [];
                this.calendarData.absences = Array.isArray(absences) ? absences : [];
                // Extract holidays array robustly: {success,holidays}, {ocs:{data:{holidays}}}, or direct array
                let holidaysArray = [];
                if (Array.isArray(holidaysResponse)) {
                    holidaysArray = holidaysResponse;
                } else if (holidaysResponse && typeof holidaysResponse === 'object') {
                    holidaysArray = Array.isArray(holidaysResponse.holidays) ? holidaysResponse.holidays
                        : (Array.isArray(holidaysResponse.ocs?.data?.holidays) ? holidaysResponse.ocs.data.holidays : []);
                }
                this.calendarData.holidays = Array.isArray(holidaysArray) ? holidaysArray : [];
                this.renderCalendar();
            }).catch((error) => {
                const container = document.getElementById('calendar-month-view');
                if (container) {
                    const l10n = (typeof window !== 'undefined' && window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n) || this.config.l10n || {};
                    const errMsg = error && error.message ? escapeHtml(error.message) : '';
                    container.innerHTML = `
                        <div class="calendar-error" role="alert">
                            <p>${escapeHtml(l10n.error || 'An error occurred')}${errMsg ? ': ' + errMsg : ''}</p>
                        </div>
                    `;
                    container.style.display = '';
                }
                const weekContainer = document.getElementById('calendar-week-view');
                if (weekContainer) weekContainer.style.display = 'none';
            });
        },

        /**
         * Render calendar based on current view
         */
        renderCalendar: function() {
            if (this.calendarData.currentView === 'month') {
                this.renderMonthView();
            } else {
                this.renderWeekView();
            }
            this.updatePeriodLabel();
            if (this.isDayDetailsPanelOpen() && this.calendarData && this.calendarData.openDayDate) {
                this._setDayPanelSelectionHighlight(this.calendarData.openDayDate);
            }
        },

        /**
         * Render month view calendar
         */
        renderMonthView: function() {
            const container = document.getElementById('calendar-month-view');
            if (!container) return;

            const year = this.calendarData.currentDate.getFullYear();
            const month = this.calendarData.currentDate.getMonth();
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const daysInMonth = lastDay.getDate();
            const startingDayOfWeek = firstDay.getDay();

            let html = '<div class="calendar-month-grid">';
            
            // Weekday headers
            html += '<div class="calendar-weekdays">';
            const weekdays = this.config.l10n?.weekdaysShort || [
                mainT('Sun'),
                mainT('Mon'),
                mainT('Tue'),
                mainT('Wed'),
                mainT('Thu'),
                mainT('Fri'),
                mainT('Sat')
            ];
            weekdays.forEach(day => {
                html += `<div class="calendar-weekday">${day}</div>`;
            });
            html += '</div>';

            // Calendar days
            html += '<div class="calendar-days">';
            
            // Empty cells for days before month starts
            for (let i = 0; i < startingDayOfWeek; i++) {
                html += '<div class="calendar-day calendar-day--empty"></div>';
            }

            // Days of the month
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(year, month, day);
                const dateKey = formatLocalDateYmd(date);
                const dayData = this.getDayData(dateKey);
                
                const classes = ['calendar-day'];
                if (dayData.hasTimeEntry) classes.push('calendar-day--has-entry');
                if (dayData.hasAbsence) {
                    classes.push('calendar-day--has-absence');
                    const firstAbsence = dayData.absences[0];
                    if (firstAbsence && firstAbsence.role === 'substitute') {
                        classes.push('calendar-day--has-coverage');
                    }
                    if (dayData.absences.some(absence => this.isPastAbsenceRecord(absence))) {
                        classes.push('calendar-day--past-absence');
                    }
                }
                if (dayData.isToday) classes.push('calendar-day--today');
                if (dayData.isWeekend) classes.push('calendar-day--weekend');

                // Mark public holidays and company holidays; collect names for display
                const holidays = Array.isArray(this.calendarData.holidays) ? this.calendarData.holidays : [];
                const dayHolidays = holidays.filter(h => h && h.date === dateKey);
                const isHoliday = dayHolidays.some(h => h.scope === 'statutory');
                const isCompanyHoliday = dayHolidays.some(h => h.scope === 'company' || h.scope === 'custom');
                if (isHoliday) classes.push('calendar-day--holiday');
                if (isCompanyHoliday) classes.push('calendar-day--company-holiday');

                // Build day content with day number and optional holiday label
                let dayContent = `<div class="calendar-day-number">${day}</div>`;
                if (dayHolidays.length > 0) {
                    const firstHolidayName = dayHolidays[0].name ? String(dayHolidays[0].name).trim() : '';
                    if (firstHolidayName) {
                        dayContent += `<span class="calendar-day-holiday-label" aria-hidden="true">${escapeHtml(firstHolidayName)}</span>`;
                    }
                }
                
                // Show hours worked if available
                if (dayData.hours > 0) {
                    dayContent += `<div class="calendar-day-hours" title="${dayData.hours.toFixed(1)} ${this.config.l10n?.hours || 'hours'}">${dayData.hours.toFixed(1)}h</div>`;
                }
                
                if (dayData.hasAbsence && dayData.absences.length > 0) {
                    dayContent += this.buildCalendarDayAbsenceContent(dayData);
                }
                
                // Show entry count if multiple entries
                if (dayData.entries.length > 1) {
                    dayContent += `<div class="calendar-day-entry-count" title="${dayData.entries.length} ${this.config.l10n?.timeEntries || 'entries'}">${dayData.entries.length}×</div>`;
                }
                const dayAriaLabel = this.getDayCellAriaLabel(dateKey, dayData);
                const holidayLabels = dayHolidays
                    .map(h => h.name)
                    .filter(Boolean)
                    .join(', ');
                const ariaLabel = holidayLabels ? `${dayAriaLabel} – ${holidayLabels}` : dayAriaLabel;

                html += `<div class="${classes.join(' ')}" data-date="${dateKey}" role="button" tabindex="0" aria-label="${escapeHtml(ariaLabel)}">${dayContent}</div>`;
            }

            html += '</div></div>';

            // Add month summary and optional empty state
            const monthSummary = this.calculateMonthSummary(year, month);
            const isEmptyMonth = monthSummary.totalHours === 0 && monthSummary.absenceDays === 0;
            html += `
                <div class="calendar-month-summary">
                    <div class="summary-item">
                        <span class="summary-label">${this.config.l10n?.totalHours || 'Total Hours'}:</span>
                        <span class="summary-value">${monthSummary.totalHours.toFixed(1)}h</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">${this.config.l10n?.workingDays || 'Working Days'}:</span>
                        <span class="summary-value">${monthSummary.workingDays}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">${this.config.l10n?.absences || 'Absences'}:</span>
                        <span class="summary-value">${monthSummary.absenceDays}</span>
                    </div>
                </div>
                ${isEmptyMonth ? `<p class="calendar-empty-hint" role="status">${escapeHtml(this.config.l10n?.noEntriesThisMonth || 'No time entries or absences for this month.')}</p>` : ''}
            `;

            container.innerHTML = html;

            // Add click and keyboard handlers to days
            container.querySelectorAll('.calendar-day[data-date]').forEach(dayEl => {
                const openDay = (e) => {
                    const date = dayEl.dataset.date;
                    if (!date) {
                        return;
                    }
                    if (e && typeof e.stopPropagation === 'function') {
                        e.stopPropagation();
                    }
                    this.showDayDetails(date, dayEl);
                };
                dayEl.addEventListener('click', openDay);
                dayEl.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        openDay();
                    }
                });
            });
        },

        /**
         * Calculate summary statistics for a month
         */
        calculateMonthSummary: function(year, month) {
            let totalHours = 0;
            let workingDays = 0;
            let absenceDays = 0;
            
            const lastDay = new Date(year, month + 1, 0);
            const daysInMonth = lastDay.getDate();
            
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(year, month, day);
                const dateKey = formatLocalDateYmd(date);
                const dayData = this.getDayData(dateKey);
                
                if (dayData.hours > 0) {
                    totalHours += dayData.hours;
                    workingDays++;
                }
                if (dayData.hasOwnAbsence) {
                    absenceDays++;
                }
            }
            
            return {
                totalHours: totalHours,
                workingDays: workingDays,
                absenceDays: absenceDays
            };
        },

        /**
         * Render week view calendar
         */
        renderWeekView: function() {
            const container = document.getElementById('calendar-week-view');
            if (!container) return;

            const currentDate = new Date(this.calendarData.currentDate);
            const weekStart = new Date(currentDate);
            weekStart.setDate(currentDate.getDate() - currentDate.getDay());

            let html = '<div class="calendar-week-grid">';
            
            // Weekday headers
            html += '<div class="calendar-week-header">';
            const weekdays = this.config.l10n?.weekdays || ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const holidays = Array.isArray(this.calendarData.holidays) ? this.calendarData.holidays : [];
            for (let i = 0; i < 7; i++) {
                const date = new Date(weekStart);
                date.setDate(weekStart.getDate() + i);
                const dateKey = formatLocalDateYmd(date);
                const dayData = this.getDayData(dateKey);
                const isHoliday = holidays.some(h => h && h.date === dateKey && h.scope === 'statutory');
                const isCompanyHoliday = holidays.some(h => h && h.date === dateKey && h.scope !== 'statutory');
                const weekDayClasses = ['calendar-week-day'];
                if (isHoliday) weekDayClasses.push('calendar-day--holiday');
                if (isCompanyHoliday) weekDayClasses.push('calendar-day--company-holiday');
                if (dayData.hasTimeEntry) weekDayClasses.push('calendar-day--has-entry');
                if (dayData.hasAbsence) {
                    weekDayClasses.push('calendar-day--has-absence');
                    const firstAbsence = dayData.absences[0];
                    if (firstAbsence && firstAbsence.role === 'substitute') {
                        weekDayClasses.push('calendar-day--has-coverage');
                    }
                    if (dayData.absences.some(absence => this.isPastAbsenceRecord(absence))) {
                        weekDayClasses.push('calendar-day--past-absence');
                    }
                }
                if (dayData.isToday) weekDayClasses.push('calendar-day--today');
                const holidayNames = holidays.filter(h => h && h.date === dateKey).map(h => h.name).filter(Boolean).join(', ');
                const holidayHtml = holidayNames ? `<div class="week-day-holiday" aria-hidden="true">${escapeHtml(holidayNames)}</div>` : '';
                const absenceHtml = dayData.hasAbsence ? this.buildCalendarDayAbsenceContent(dayData) : '';
                html += `
                    <div class="${weekDayClasses.join(' ')}" data-date="${dateKey}">
                        <div class="week-day-name">${weekdays[i]}</div>
                        <div class="week-day-number">${date.getDate()}</div>
                        ${dayData.hours > 0 ? `<div class="week-day-hours">${dayData.hours.toFixed(1)}h</div>` : ''}
                        ${holidayHtml}
                        ${absenceHtml}
                    </div>
                `;
            }
            html += '</div>';

            html += '</div>';
            container.innerHTML = html;

            // Add click and keyboard handlers
            const holidaysForAria = Array.isArray(this.calendarData.holidays) ? this.calendarData.holidays : [];
            container.querySelectorAll('.calendar-week-day[data-date]').forEach(dayEl => {
                const openDay = (e) => {
                    const date = dayEl.dataset.date;
                    if (!date) {
                        return;
                    }
                    if (e && typeof e.stopPropagation === 'function') {
                        e.stopPropagation();
                    }
                    this.showDayDetails(date, dayEl);
                };
                dayEl.setAttribute('role', 'button');
                dayEl.setAttribute('tabindex', '0');
                const dateKey = dayEl.dataset.date;
                const dayData = this.getDayData(dateKey);
                let ariaLabel = this.getDayCellAriaLabel(dateKey, dayData);
                const hLabels = holidaysForAria.filter(h => h && h.date === dateKey).map(h => h.name).filter(Boolean).join(', ');
                if (hLabels) ariaLabel = ariaLabel.replace(/\.\s*$/, '') + ' – ' + hLabels + '.';
                dayEl.setAttribute('aria-label', ariaLabel);
                dayEl.addEventListener('click', openDay);
                dayEl.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        openDay();
                    }
                });
            });
        },

        /**
         * Get data for a specific day
         */
        getDayData: function(dateKey) {
            // Use local-date helpers so "today" and entry dates are never shifted by UTC conversion.
            const today = todayYmdLocal();
            const date = parseYmdToLocalDate(dateKey);
            const isWeekend = date ? (date.getDay() === 0 || date.getDay() === 6) : false;

            // Find time entries for this day
            const dayEntries = this.calendarData.timeEntries.filter(entry => {
                const startTime = entry.start_time || entry.startTime;
                if (!startTime) return false;
                const instant = parseApiInstant(startTime);
                const entryDate = instant ? formatLocalDateYmd(instant) : '';
                return entryDate === dateKey;
            });

            // Find absences for this day
            const dayAbsences = this.calendarData.absences.filter(absence => {
                const startDate = absence.start_date || absence.startDate;
                const endDate = absence.end_date || absence.endDate;
                if (!startDate) return false;
                // Absence dates are calendar dates; parse as local midnight to avoid UTC shift.
                const startParsed = parseYmdToLocalDate(String(startDate).slice(0, 10));
                const endParsed = endDate ? parseYmdToLocalDate(String(endDate).slice(0, 10)) : startParsed;
                const start = startParsed ? formatLocalDateYmd(startParsed) : '';
                const end = endParsed ? formatLocalDateYmd(endParsed) : start;
                
                return dateKey >= start && dateKey <= end;
            });

            // Calculate total hours (API returns workingDurationHours/durationHours in hours; legacy duration/working_duration in seconds)
            let totalHours = 0;
            dayEntries.forEach(entry => {
                if (entry.workingDurationHours != null && !isNaN(entry.workingDurationHours)) {
                    totalHours += Number(entry.workingDurationHours);
                } else if (entry.durationHours != null && !isNaN(entry.durationHours)) {
                    totalHours += Number(entry.durationHours);
                } else {
                    const sec = entry.duration || entry.working_duration || 0;
                    totalHours += Number(sec) / 3600;
                }
            });

            const hasOwnAbsence = dayAbsences.some(a => !a.role || a.role !== 'substitute');

            return {
                hasTimeEntry: dayEntries.length > 0,
                hasAbsence: dayAbsences.length > 0,
                hasOwnAbsence,
                hours: totalHours,
                entries: dayEntries,
                absences: dayAbsences,
                absenceType: dayAbsences.length > 0 ? (dayAbsences[0].type || 'absence') : null,
                isToday: dateKey === today,
                isWeekend: isWeekend
            };
        },

        /**
         * Past records ended before today. Keep this based on the absence end date,
         * not the viewed day, so multi-day historical records are tagged consistently.
         */
        isPastAbsenceRecord: function(absence) {
            const endDate = absence && (absence.end_date || absence.endDate || absence.start_date || absence.startDate);
            if (!endDate) return false;
            const today = todayYmdLocal();
            const endParsed = parseYmdToLocalDate(String(endDate).slice(0, 10));
            const end = endParsed ? formatLocalDateYmd(endParsed) : '';
            return end < today;
        },

        /**
         * Build accessible label for a day cell (for aria-label)
         */
        getDayCellAriaLabel: function(dateKey, dayData) {
            const d = new Date(dateKey);
            const day = d.getDate();
            const months = this.config.l10n?.months || [];
            const monthName = months[d.getMonth()] || (d.getMonth() + 1);
            const year = d.getFullYear();
            let label = `${day} ${monthName} ${year}`;
            if (dayData.isToday) label += ', ' + (this.config.l10n?.today || 'Today');
            if (dayData.hours > 0) label += ', ' + dayData.hours.toFixed(1) + ' ' + (this.config.l10n?.hours || 'hours');
            if (dayData.hasAbsence && dayData.absences.length > 0) {
                const firstAbsence = dayData.absences[0];
                const displayLabel = this.getAbsenceDisplayLabel(firstAbsence);
                const statusLabel = this.getAbsenceStatusLabel(firstAbsence);
                label += ', ' + displayLabel + ', ' + statusLabel;
                if (this.isPastAbsenceRecord(firstAbsence)) {
                    label += ', ' + (this.config.l10n?.pastRecord || 'Past record');
                }
                const extra = dayData.absences.length - 1;
                if (extra > 0) {
                    label += ', ' + this.formatMoreAbsencesLabel(extra);
                }
            }
            label += '. ' + (this.config.l10n?.clickForDetails || 'Click for details');
            return label;
        },

        /**
         * Navigate calendar (prev/next month or week). Reloads data for the new period.
         */
        navigateCalendar: function(direction) {
            if (this.isDayDetailsPanelOpen()) {
                this.closeDayDetailsPanel();
            }
            const currentDate = new Date(this.calendarData.currentDate);
            if (this.calendarData.currentView === 'month') {
                currentDate.setMonth(currentDate.getMonth() + direction);
            } else {
                currentDate.setDate(currentDate.getDate() + (direction * 7));
            }
            this.calendarData.currentDate = currentDate;
            this.loadCalendarData();
        },

        /**
         * Go to today - navigate to current month/week and reload data for that month
         */
        goToToday: function() {
            if (this.isDayDetailsPanelOpen()) {
                this.closeDayDetailsPanel();
            }
            this.calendarData.currentDate = new Date();
            this.loadCalendarData();
        },

        /**
         * Switch between month and week view
         */
        switchView: function(view) {
            if (this.isDayDetailsPanelOpen()) {
                this.closeDayDetailsPanel();
            }
            this.calendarData.currentView = view;
            
            const monthView = document.getElementById('calendar-month-view');
            const weekView = document.getElementById('calendar-week-view');
            const monthBtn = document.getElementById('btn-month-view');
            const weekBtn = document.getElementById('btn-week-view');

            if (view === 'month') {
                if (monthView) monthView.style.display = 'block';
                if (weekView) weekView.style.display = 'none';
                if (monthBtn) {
                    monthBtn.classList.add('active');
                    monthBtn.setAttribute('aria-pressed', 'true');
                }
                if (weekBtn) {
                    weekBtn.classList.remove('active');
                    weekBtn.setAttribute('aria-pressed', 'false');
                }
            } else {
                if (monthView) monthView.style.display = 'none';
                if (weekView) weekView.style.display = 'block';
                if (monthBtn) {
                    monthBtn.classList.remove('active');
                    monthBtn.setAttribute('aria-pressed', 'false');
                }
                if (weekBtn) {
                    weekBtn.classList.add('active');
                    weekBtn.setAttribute('aria-pressed', 'true');
                }
            }

            this.renderCalendar();
        },

        /**
         * Update period label
         */
        updatePeriodLabel: function() {
            const label = document.getElementById('current-period-label');
            if (!label) return;

            const date = this.calendarData.currentDate;
            const months = this.config.l10n?.months || [
                mainT('January'),
                mainT('February'),
                mainT('March'),
                mainT('April'),
                mainT('May'),
                mainT('June'),
                mainT('July'),
                mainT('August'),
                mainT('September'),
                mainT('October'),
                mainT('November'),
                mainT('December')
            ];
            
            if (this.calendarData.currentView === 'month') {
                // Use German date format (MM.YYYY for month view)
                label.textContent = `${months[date.getMonth()]} ${date.getFullYear()}`;
            } else {
                const weekStart = new Date(date);
                weekStart.setDate(date.getDate() - date.getDay());
                const weekEnd = new Date(weekStart);
                weekEnd.setDate(weekStart.getDate() + 6);
                label.textContent = `${weekStart.getDate()}. ${months[weekStart.getMonth()]} - ${weekEnd.getDate()}. ${months[weekEnd.getMonth()]} ${weekEnd.getFullYear()}`;
            }
        },

        /**
         * Show day details panel
         * @param {string} dateKey YYYY-MM-DD
         * @param {HTMLElement|null} triggerEl Day cell that opened the panel (focus restore)
         */
        showDayDetails: function(dateKey, triggerEl) {
            const panel = document.getElementById('day-details-panel');
            const label = document.getElementById('selected-date-label');
            const content = document.getElementById('day-details-content');
            
            if (!panel || !label || !content) return;

            const wasOpen = this.isDayDetailsPanelOpen();
            const switchingDay = wasOpen && this.calendarData.openDayDate && this.calendarData.openDayDate !== dateKey;

            this._mountDayDetailsOverlay();
            this._syncDayPanelOverlayMetrics();
            this._bindDayPanelResizeSync();

            // Remember the element that opened the panel so we can restore focus on close
            if (triggerEl && typeof triggerEl.focus === 'function') {
                this.calendarData.lastActiveDayElement = triggerEl;
            } else if (!wasOpen && typeof document !== 'undefined' && document.activeElement) {
                this.calendarData.lastActiveDayElement = document.activeElement;
            }
            this.calendarData.openDayDate = dateKey;

            const date = parseYmdToLocalDate(dateKey) || new Date();
            const dayData = this.getDayData(dateKey);

            const weekdays = this.config.l10n?.weekdays || ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const weekdayName = weekdays[date.getDay()];
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            label.textContent = `${weekdayName}, ${day}.${month}.${year}`;

            let html = '';

            const createBase = (this.config.apiUrl && this.config.apiUrl.absenceCreate)
                || this.resolveRequestUrl('/apps/arbeitszeitcheck/absences/create');
            const createUrl = createBase + (createBase.indexOf('?') >= 0 ? '&' : '?')
                + 'start=' + encodeURIComponent(dateKey) + '&end=' + encodeURIComponent(dateKey);
            const reqAbsLabelPlain = this.config.l10n?.requestAbsenceThisDay || mainT('Request absence for this day');
            const reqAbsHelpPlain = this.config.l10n?.requestAbsenceThisDayHelp || mainT('Request absence (opens form with this day prefilled). Past dates are allowed for migration.');
            const reqAbsLabel = escapeHtml(reqAbsLabelPlain);
            const reqAbsHelp = escapeHtml(reqAbsHelpPlain);
            const reqAbsAria = escapeHtml(reqAbsLabelPlain);
            html += `<div class="day-details-actions" role="region" aria-label="${reqAbsAria}">`;
            html += `<p class="day-details-actions__help" id="day-details-absence-help">${reqAbsHelp}</p>`;
            html += `<a class="azc-btn azc-btn--primary day-details-actions__link" href="${escapeHtml(createUrl)}">${reqAbsLabel}</a>`;
            html += '</div>';

            // Holiday info
            const holidays = Array.isArray(this.calendarData.holidays) ? this.calendarData.holidays : [];
            const holidayNames = holidays
                .filter(h => h.date === dateKey)
                .map(h => h.name)
                .filter(Boolean);
            if (holidayNames.length > 0) {
                const label = this.config.l10n?.holiday || mainT('Holiday');
                const namesText = holidayNames.join(', ');
                html += `
                    <div class="day-details-section">
                        <h4>${escapeHtml(label)}</h4>
                        <p>${escapeHtml(namesText)}</p>
                    </div>
                `;
            }

            if (dayData.entries.length === 0 && dayData.absences.length === 0 && holidayNames.length === 0) {
                html += `<p>${this.config.l10n?.noEntries || 'No entries for this day'}</p>`;
            } else {
                if (dayData.entries.length > 0) {
                    const timeEntriesLabel = this.config.l10n?.timeEntries || 'Time Entries';
                    html += `<div class="day-details-section"><h4>${timeEntriesLabel}</h4><ul>`;
                    dayData.entries.forEach(entry => {
                        const startTime = entry.start_time || entry.startTime;
                        const endTime = entry.end_time || entry.endTime;
                        const durationSec = (entry.workingDurationHours != null ? Number(entry.workingDurationHours) * 3600 : null)
                            || (entry.durationHours != null ? Number(entry.durationHours) * 3600 : null)
                            || entry.duration || entry.working_duration || 0;
                        const breakDuration = entry.breakDurationHours != null ? Number(entry.breakDurationHours) * 3600 : (entry.break_duration || 0);
                        const hours = Math.floor(durationSec / 3600);
                        const minutes = Math.floor((durationSec % 3600) / 60);
                        const breakHours = Math.floor(breakDuration / 3600);
                        const breakMinutes = Math.floor((breakDuration % 3600) / 60);
                        
                        const start = startTime ? formatDisplayTime(startTime) : '-';
                        const end = endTime ? formatDisplayTime(endTime) : '-';
                        
                        let entryHtml = `<li><strong>${start} - ${end}</strong> (${hours}h ${minutes}m`;
                        if (breakDuration > 0) {
                            entryHtml += `, ${this.config.l10n?.breakTime || 'Break'}: ${breakHours}h ${breakMinutes}m`;
                        }
                        entryHtml += `)</li>`;
                        html += entryHtml;
                    });
                    html += '</ul></div>';
                }

                if (dayData.absences.length > 0) {
                    const absencesLabel = this.config.l10n?.absences || 'Absences';
                    html += `<div class="day-details-section"><h4>${absencesLabel}</h4><ul>`;
                    dayData.absences.forEach(absence => {
                        const displayLabel = this.getAbsenceDisplayLabel(absence);
                        const statusLabel = this.getAbsenceStatusLabel(absence);
                        const badgeClass = this.getAbsenceStatusBadgeClass(absence);
                        const pastBadge = this.isPastAbsenceRecord(absence)
                            ? ` <span class="calendar-past-record-badge">${escapeHtml(this.config.l10n?.pastRecord || 'Past record')}</span>`
                            : '';
                        html += `<li><span class="day-details-absence-label">${escapeHtml(displayLabel)}</span>`
                            + ` <span class="badge badge--${badgeClass}">${escapeHtml(statusLabel)}</span>${pastBadge}</li>`;
                    });
                    html += '</ul></div>';
                }
            }

            content.innerHTML = html;
            panel.hidden = false;
            panel.removeAttribute('inert');
            this._setDayPanelSectionOpen(true);
            this._setDayPanelSelectionHighlight(dateKey);

            const closeBtn = document.getElementById('btn-close-panel');
            if (!wasOpen && closeBtn && typeof closeBtn.focus === 'function') {
                setTimeout(() => closeBtn.focus(), 0);
            } else if (switchingDay && label && typeof label.focus === 'function') {
                setTimeout(() => label.focus(), 0);
            }
        },

        /**
         * Close day details panel
         */
        closeDayDetailsPanel: function() {
            if (!this.isDayDetailsPanelOpen()) {
                return;
            }
            const panel = document.getElementById('day-details-panel');
            if (panel) {
                panel.hidden = true;
                panel.setAttribute('inert', '');
            }
            this._setDayPanelSectionOpen(false);
            this._setDayPanelSelectionHighlight(null);
            if (this.calendarData) {
                this.calendarData.openDayDate = null;
            }
            // Restore focus to the last active day tile to keep keyboard users oriented
            if (this.calendarData && this.calendarData.lastActiveDayElement && typeof this.calendarData.lastActiveDayElement.focus === 'function') {
                this.calendarData.lastActiveDayElement.focus();
            }
        },

        /**
         * Cleanup timers on page unload
         */
        cleanup: function() {
            Object.keys(this.timers).forEach(key => {
                if (this.timers[key]) {
                    clearInterval(this.timers[key]);
                }
            });
            this.timers = {};

            // Remove Page Visibility API listener if registered
            if (this._visibilityHandler) {
                document.removeEventListener('visibilitychange', this._visibilityHandler);
                this._visibilityHandler = null;
            }
        }
    };

    // Cleanup on page unload
    window.addEventListener('beforeunload', () => {
        ArbeitszeitCheck.cleanup();
    });

    // Initialize when DOM is ready or immediately if already loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            ArbeitszeitCheck.init();
        });
    } else {
        ArbeitszeitCheck.init();
    }

    // Expose to global scope for debugging
    window.ArbeitszeitCheckApp = ArbeitszeitCheck;

})(window, (typeof window !== 'undefined' && window.OC) ? window.OC : {});
