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

    /**
     * Format a Date as YYYY-MM-DD using local calendar values only (no timezone shifts).
     *
     * Using toISOString() here would convert the local time to UTC which can
     * move dates across day boundaries depending on the user's timezone.
     * For calendar logic we always want the local civil date.
     */
    function formatLocalDateYmd(date) {
        if (!(date instanceof Date)) {
            return '';
        }
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    // Main application object
    const ArbeitszeitCheck = {
        config: window.ArbeitszeitCheck || {},
        timers: {},
        initialized: false,

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
                            // Fallback: calculate from start time
                            const startTime = new Date(startTimeStr).getTime();
                            const now = new Date().getTime();
                            baseWorkingSeconds = Math.floor((now - startTime) / 1000);
                        }
                        
                        // Track when timer was last updated (for incrementing)
                        let lastUpdateTime = new Date().getTime();
                        let isOnBreak = (currentStatus === 'break');
                        let isClockedIn = (currentStatus === 'active' || currentStatus === 'break');
                        let lastStatusCheck = new Date().getTime();
                        // Initialize working today hours from initial status (includes completed entries + current session)
                        let workingTodayHours = 0.0;
                        if (status.working_today_hours !== null && status.working_today_hours !== undefined) {
                            workingTodayHours = parseFloat(status.working_today_hours) || 0.0;
                        }
                        const STATUS_CHECK_INTERVAL = 5000; // Check status every 5 seconds

                        // Clear any existing timer
                        if (this.timers.session) {
                            clearInterval(this.timers.session);
                        }

                        // Don't start timer if already clocked out or paused
                        if (!isClockedIn) {
                            return;
                        }

                        // Update timer every second
                        this.timers.session = setInterval(() => {
                            const now = new Date().getTime();
                            
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
                                            
                                            isOnBreak = (newStatus === 'break');
                                            isClockedIn = (newStatus === 'active' || newStatus === 'break');
                                            
                                            // Update working today hours from backend (includes completed entries + current session)
                                            if (response.status.working_today_hours !== null && response.status.working_today_hours !== undefined) {
                                                workingTodayHours = parseFloat(response.status.working_today_hours) || 0.0;
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

                            const hours = Math.floor(workingSeconds / 3600);
                            const minutes = Math.floor((workingSeconds % 3600) / 60);
                            const seconds = workingSeconds % 60;

                            timerEl.textContent =
                                String(hours).padStart(2, '0') + ':' +
                                String(minutes).padStart(2, '0') + ':' +
                                String(seconds).padStart(2, '0');
                            
                            // Warning for maximum working hours (ArbZG §3: max 10 hours)
                            // Show visual warning when approaching/exceeding 10 hours
                            // IMPORTANT: Check TOTAL daily working hours (previous entries + current session)
                            // not just the current session hours
                            const currentSessionHours = workingSeconds / 3600;
                            const maxWorkingHours = 10; // ArbZG §3 maximum
                            
                            // Calculate total daily working hours
                            // Note: workingTodayHours from status API already includes the current session
                            // if it's active, so we use it directly. If status check hasn't run yet,
                            // we calculate it manually from current session hours.
                            let totalDailyHours;
                            if (workingTodayHours > 0) {
                                // Use the backend value which is more accurate
                                // It includes all completed entries + current session duration
                                totalDailyHours = workingTodayHours;
                            } else {
                                // Fallback: use only current session if status hasn't been fetched yet
                                // This is conservative and will trigger once status is fetched
                                totalDailyHours = currentSessionHours;
                            }
                            
                            // Remove previous warning classes
                            timerEl.classList.remove('timer-warning', 'timer-error');
                            
                            if (totalDailyHours >= maxWorkingHours) {
                                // Exceeded 10 hours TOTAL for the day - show error state
                                timerEl.classList.add('timer-error');
                                if (sessionTimerEl) {
                                    sessionTimerEl.classList.add('timer-exceeded');
                                }
                                
                                // AUTOMATIC CLOCK-OUT: Stop timer and clock out automatically (ArbZG §3)
                                // This ensures compliance with German labor law - maximum 10 hours per day
                                if (!timerEl.dataset.autoClockOutTriggered) {
                                    timerEl.dataset.autoClockOutTriggered = 'true';
                                    
                                    // Stop the timer
                                    if (this.timers.session) {
                                        clearInterval(this.timers.session);
                                        this.timers.session = null;
                                    }
                                    
                                    // Show critical notification
                                    if (window.OC && OC.Notification) {
                                        const criticalMsg = (window.t && window.t('arbeitszeitcheck', 
                                            'CRITICAL: Maximum daily working hours (10h) exceeded! Automatically clocking out to comply with German labor law (ArbZG §3).')) ||
                                            'CRITICAL: Maximum daily working hours (10h) exceeded! Automatically clocking out to comply with German labor law (ArbZG §3).';
                                        OC.Notification.showTemporary(criticalMsg, { 
                                            type: 'error', 
                                            timeout: 20000 
                                        });
                                    }
                                    
                                    // Automatically complete the entry (set endTime and mark as completed)
                                    // Instead of just clocking out, we complete it to enforce the 10h limit
                                    // The backend getStatus() will automatically complete entries at 10h
                                    // So we just reload to trigger the backend check
                                    setTimeout(() => {
                                        // Reload to trigger backend automatic completion
                                        // The backend will automatically set endTime and STATUS_COMPLETED
                                        window.location.reload();
                                    }, 2000); // 2 second delay to show notification
                                }
                            } else if (totalDailyHours >= 8) {
                                // Approaching 10 hours (8+ hours) - show warning state
                                timerEl.classList.add('timer-warning');
                                if (sessionTimerEl) {
                                    sessionTimerEl.classList.add('timer-warning');
                                }
                                
                                // Show info notification when reaching 8 hours (only once)
                                if (!timerEl.dataset.infoShown) {
                                    timerEl.dataset.infoShown = 'true';
                                    
                                    if (window.OC && OC.Notification) {
                                        const infoMsg = (window.t && window.t('arbeitszeitcheck', 
                                            'Note: You are approaching the maximum working hours. Extended hours must be compensated within 6 months (ArbZG §3).')) ||
                                            'Note: You are approaching the maximum working hours. Extended hours must be compensated within 6 months (ArbZG §3).';
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
                            const breakStartTime = new Date(breakStartTimeStr).getTime();
                            
                            // Clear any existing break timer
                            if (this.timers.break) {
                                clearInterval(this.timers.break);
                            }
                            
                            // Update break timer every second
                            this.timers.break = setInterval(() => {
                                const now = new Date().getTime();
                                const breakSeconds = Math.floor((now - breakStartTime) / 1000);
                                
                                const hours = Math.floor(breakSeconds / 3600);
                                const minutes = Math.floor((breakSeconds % 3600) / 60);
                                const seconds = breakSeconds % 60;
                                
                                breakTimerValueEl.textContent =
                                    String(hours).padStart(2, '0') + ':' +
                                    String(minutes).padStart(2, '0') + ':' +
                                    String(seconds).padStart(2, '0');
                            }, 1000);
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
                    this.clockIn();
                });
            }

            clockOutButtons.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    this.clockOut();
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
                    if (confirm(this.config.l10n?.confirmDelete || (window.t && window.t('arbeitszeitcheck', 'Are you sure you want to delete this item?')) || 'Are you sure you want to delete this item?')) {
                        const endpoint = button.dataset.deleteEndpoint;
                        this.callApi(endpoint, 'DELETE');
                    }
                });
            });

            // Handle edit buttons in table rows (works on all pages including dashboard)
            const editButtons = document.querySelectorAll('table tbody button[data-entry-id]:not(.btn-delete)');
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
                    // Open export in new window/tab
                    window.open(this.config.apiUrl.export, '_blank');
                });
            }

            // Edit buttons in table rows
            const editButtons = document.querySelectorAll('table tbody button[data-entry-id]:not(.btn-delete)');
            editButtons.forEach(button => {
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

            // Delete buttons in table rows
            const deleteButtons = document.querySelectorAll('table tbody .btn-delete[data-entry-id]');
            deleteButtons.forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const entryId = button.dataset.entryId;
                    if (!entryId) return;
                    
                    const confirmMsg = this.config.l10n?.confirmDeleteTimeEntry || 
                                     this.config.l10n?.confirmDelete || 
                                     (window.t && window.t('arbeitszeitcheck', 'Are you sure you want to delete this time entry?')) ||
                                     'Are you sure you want to delete this time entry?';
                    
                    if (confirm(confirmMsg)) {
                        // Build delete URL using the API URL pattern or fallback
                        let deleteUrl = this.config.apiUrl?.delete || '';
                        if (deleteUrl && deleteUrl.includes('__ID__')) {
                            deleteUrl = deleteUrl.replace('__ID__', entryId);
                        } else if (!deleteUrl) {
                            deleteUrl = OC.generateUrl('/apps/arbeitszeitcheck/api/time-entries/' + entryId);
                        } else {
                            // If API URL doesn't have __ID__ placeholder, append ID
                            deleteUrl = deleteUrl.replace(/\/$/, '') + '/' + entryId;
                        }
                        
                        this.callApi(deleteUrl, 'DELETE')
                            .then(() => {
                                // Remove the row from the table
                                const row = button.closest('tr');
                                if (row) {
                                    row.remove();
                                }
                                
                                // Show success message
                                const successMsg = this.config.l10n?.deleted || 
                                    (window.t && window.t('arbeitszeitcheck', 'Time entry deleted successfully')) || 
                                    'Time entry deleted successfully';
                                this.showSuccess(successMsg);
                            })
                            .catch(error => {
                                console.error('Error deleting time entry:', error);
                            });
                    }
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
                    ? (window.t && window.t('arbeitszeitcheck', 'Absence request submitted successfully')) || 'Absence request submitted successfully'
                    : (window.t && window.t('arbeitszeitcheck', 'Absence request updated')) || 'Absence request updated';
                if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
                    window.OC.Notification.showTemporary(msg, { type: 'success' });
                }
                params.delete('created');
                params.delete('updated');
            }
            if (shortened === '1') {
                const msg = (window.t && window.t('arbeitszeitcheck', 'Absence shortened successfully. Your actual last day of absence has been updated.')) || 'Absence shortened successfully. Your actual last day of absence has been updated.';
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
                const msg = (window.t && window.t('arbeitszeitcheck', 'Absence cancelled successfully.')) || 'Absence cancelled successfully.';
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

            // Create/Edit form: update min date for start/end based on absence type
            const absenceTypeSel = document.getElementById('absence-type');
            const absenceStartInput = document.getElementById('absence-start-date');
            const absenceEndInput = document.getElementById('absence-end-date');
            if (absenceTypeSel && absenceStartInput && absenceEndInput) {
                const applyMinDateForType = function() {
                    const type = absenceTypeSel.value;
                    const isSickLeave = type === 'sick_leave';
                    const minVal = isSickLeave && absenceStartInput.getAttribute('data-datepicker-min-sick')
                        ? absenceStartInput.getAttribute('data-datepicker-min-sick')
                        : (function() {
                            const d = new Date();
                            const dd = String(d.getDate()).padStart(2, '0');
                            const mm = String(d.getMonth() + 1).padStart(2, '0');
                            return dd + '.' + mm + '.' + d.getFullYear();
                        })();
                    absenceStartInput.setAttribute('data-datepicker-min', minVal);
                    absenceEndInput.setAttribute('data-datepicker-min', absenceEndInput.getAttribute('data-datepicker-min-sick') && isSickLeave
                        ? absenceEndInput.getAttribute('data-datepicker-min-sick')
                        : minVal);
                };
                absenceTypeSel.addEventListener('change', applyMinDateForType);
                applyMinDateForType();
            }

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
                                     (window.t && window.t('arbeitszeitcheck', 'Are you sure you want to cancel this absence request?')) ||
                                     'Are you sure you want to cancel this absence request?';
                    
                    if (confirm(confirmMsg)) {
                        // Build delete URL using the API URL pattern or fallback
                        let deleteUrl = this.config.apiUrl?.delete || '';
                        if (deleteUrl && deleteUrl.includes('__ID__')) {
                            deleteUrl = deleteUrl.replace('__ID__', absenceId);
                        } else if (!deleteUrl) {
                            deleteUrl = OC.generateUrl('/apps/arbeitszeitcheck/api/absences/' + absenceId);
                        } else {
                            // If API URL doesn't have __ID__ placeholder, append ID
                            deleteUrl = deleteUrl.replace(/\/$/, '') + '/' + absenceId;
                        }
                        
                        this.callApi(deleteUrl, 'DELETE')
                            .then(() => {
                                // Remove the row from the table
                                const row = button.closest('tr');
                                if (row) {
                                    row.remove();
                                }
                                
                                // Show success message
                                const successMsg = this.config.l10n?.canceled || 
                                    (window.t && window.t('arbeitszeitcheck', 'Absence request cancelled successfully')) || 
                                    'Absence request cancelled successfully';
                                this.showSuccess(successMsg);
                            })
                            .catch(error => {
                                console.error('Error canceling absence request:', error);
                            });
                    }
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
            const data = {};
            if (projectCheckProjectId) data.projectCheckProjectId = projectCheckProjectId;
            if (description) data.description = description;

            this.callApi('/apps/arbeitszeitcheck/api/clock/in', 'POST', data).catch((err) => {
                this.showError(err && err.message ? err.message : (this.config.l10n?.error || 'An error occurred'));
            });
        },

        /**
         * Clock out action
         */
        clockOut: function() {
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
            
            this.callApi('/apps/arbeitszeitcheck/api/clock/out', 'POST').catch((err) => {
                this.showError(err && err.message ? err.message : (this.config.l10n?.error || 'An error occurred'));
            });
        },

        /**
         * Start break action
         */
        startBreak: function() {
            this.callApi('/apps/arbeitszeitcheck/api/break/start', 'POST').catch((err) => {
                this.showError(err && err.message ? err.message : (this.config.l10n?.error || 'An error occurred'));
            });
        },

        /**
         * End break action
         */
        endBreak: function() {
            this.callApi('/apps/arbeitszeitcheck/api/break/end', 'POST').catch((err) => {
                this.showError(err && err.message ? err.message : (this.config.l10n?.error || 'An error occurred'));
            });
        },

        /**
         * Get current status
         */
        getStatus: function() {
            return this.callApi('/apps/arbeitszeitcheck/api/clock/status', 'GET', null, false);
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
         * Generic API call helper
         * @param {string} endpoint - API endpoint URL
         * @param {string} method - HTTP method (GET, POST, PUT, DELETE)
         * @param {object|null} data - Data to send (null for GET/DELETE)
         * @param {boolean} reloadOnSuccess - Whether to reload page on success (default: true)
         */
        callApi: function(endpoint, method = 'POST', data = null, reloadOnSuccess = true) {
            // Build full URL: use OC.generateUrl for app paths so Nextcloud base (index.php) is correct
            let url;
            if (endpoint.startsWith('http')) {
                url = endpoint;
            } else if (typeof OC !== 'undefined' && OC.generateUrl) {
                // Use Nextcloud's URL generator for correct base path
                url = OC.generateUrl(endpoint.startsWith('/') ? endpoint : ('/' + endpoint));
            } else {
                url = endpoint.startsWith('/') ? endpoint : ('/' + endpoint);
            }

            // Build request options (requesttoken required for CSRF)
            const requestToken = this.getRequestToken();
            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': requestToken
                }
            };

            // Add body for POST/PUT requests; include requesttoken in body so Nextcloud finds it in decoded JSON (post)
            if (data !== null && (method === 'POST' || method === 'PUT')) {
                const bodyData = typeof data === 'object' && data !== null && !Array.isArray(data)
                    ? { ...data, requesttoken: requestToken }
                    : data;
                options.body = JSON.stringify(bodyData);
            }

            // Show loading state
            this.setLoadingState(true);

            // Make the API call
            return fetch(url, options)
                .then(async response => {
                    // Check if response is JSON
                    const contentType = response.headers.get('content-type');
                    let result;
                    
                    if (contentType && contentType.includes('application/json')) {
                        result = await response.json();
                    } else {
                        const text = await response.text();
                        try {
                            result = JSON.parse(text);
                        } catch (e) {
                            result = { success: response.ok, error: text || 'Unknown error' };
                        }
                    }
                    
                    // Check if HTTP response indicates error
                    if (!response.ok) {
                        // HTTP error status (400, 500, etc.)
                        if (!result.success && result.error) {
                            // Error message already in result
                        } else if (!result.error) {
                            // No error message, create one from status
                            result.error = result.message || `HTTP ${response.status}: ${response.statusText}`;
                        }
                        result.success = false;
                    }
                    
                    // Attach response to result for error handling
                    result._response = response;
                    return result;
                })
                .then(result => {
                    this.setLoadingState(false);

                    if (result.success !== false && result._response?.ok !== false) {
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
         * Set loading state on buttons and forms
         */
        setLoadingState: function(loading) {
            const buttons = document.querySelectorAll('button[data-api-action]');
            buttons.forEach(button => {
                if (loading) {
                    button.disabled = true;
                    button.dataset.originalText = button.textContent;
                    button.textContent = this.config.l10n?.loading || 'Loading...';
                } else {
                    button.disabled = false;
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
            // Ensure message is a string
            let errorMessage = typeof message === 'string' ? message : String(message || 'An error occurred');
            
            // Try to use Nextcloud's notification system if available
            if (window.OC && OC.Notification) {
                try {
                    // showTemporary displays messages as-is without translation
                    // Wrap in try-catch to handle any edge cases
                    OC.Notification.showTemporary(errorMessage);
                } catch (e) {
                    // If notification fails (e.g., translation error), use alert as fallback
                    console.warn('Failed to show notification:', e);
                    const translatedError = (window.t && window.t('arbeitszeitcheck', errorMessage)) || errorMessage;
                    alert(translatedError);
                }
            } else {
                // Fallback to alert
                alert(errorMessage);
            }
        },

        /**
         * Show success message to user
         */
        showSuccess: function(message) {
            if (window.OC && OC.Notification) {
                OC.Notification.showTemporary(message, { type: 'success' });
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
            const date = new Date(dateString);
            // Use German date format (DD.MM.YYYY)
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            const formatted = `${day}.${month}.${year}`;
            
            if (includeTime) {
                // Use central utility function for 24-hour time format
                const formatTime = (window.ArbeitszeitCheckUtils && window.ArbeitszeitCheckUtils.formatTime) || 
                    ((date) => {
                        const hours = String(date.getHours()).padStart(2, '0');
                        const minutes = String(date.getMinutes()).padStart(2, '0');
                        return `${hours}:${minutes}`;
                    });
                const timeStr = formatTime(date);
                return `${formatted} ${timeStr}`;
            }
            return formatted;
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
         * Initialize timeline page
         */
        initTimeline: function() {
            const container = document.getElementById('timeline-container');
            if (!container) {
                setTimeout(() => {
                    this.initTimeline();
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

            const timeEntriesUrl = this.config.apiUrl?.timeEntries || '/apps/arbeitszeitcheck/api/time-entries';
            const absencesUrl = this.config.apiUrl?.absences || '/apps/arbeitszeitcheck/api/absences';
            const holidaysUrl = this.config.apiUrl?.holidays || '/apps/arbeitszeitcheck/api/holidays';

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
                        const d = new Date(startTime);
                        if (!isNaN(d.getTime())) {
                            allDates.push(d);
                        }
                    }
                });
                abs.forEach(absence => {
                    const startDate = absence.start_date || absence.startDate;
                    if (startDate) {
                        const d = new Date(startDate);
                        if (!isNaN(d.getTime())) {
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
                    items.push({
                        type: 'time_entry',
                        date: new Date(startTime),
                        data: entry
                    });
                }
            });

            // Add absences
            absences.forEach(absence => {
                const startDate = absence.start_date || absence.startDate;
                if (startDate) {
                    items.push({
                        type: 'absence',
                        date: new Date(startDate),
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
                    // Treat holiday dates as local dates without time; append T00:00 to avoid timezone drift
                    const dateObj = new Date(holiday.date + 'T00:00:00');
                    if (isNaN(dateObj.getTime())) {
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

            // Group items by date
            const grouped = {};
            items.forEach(item => {
                const dateKey = item.date.toISOString().split('T')[0];
                if (!grouped[dateKey]) {
                    grouped[dateKey] = [];
                }
                grouped[dateKey].push(item);
            });

            // Render timeline
            let html = '<div class="timeline">';
            const sortedDates = Object.keys(grouped).sort((a, b) => new Date(b) - new Date(a));
            
            sortedDates.forEach(dateKey => {
                const date = new Date(dateKey);
                // Format date using translated month and weekday names
                const months = this.config.l10n?.months || [
                (window.t && window.t('arbeitszeitcheck', 'January')) || 'January',
                (window.t && window.t('arbeitszeitcheck', 'February')) || 'February',
                (window.t && window.t('arbeitszeitcheck', 'March')) || 'March',
                (window.t && window.t('arbeitszeitcheck', 'April')) || 'April',
                (window.t && window.t('arbeitszeitcheck', 'May')) || 'May',
                (window.t && window.t('arbeitszeitcheck', 'June')) || 'June',
                (window.t && window.t('arbeitszeitcheck', 'July')) || 'July',
                (window.t && window.t('arbeitszeitcheck', 'August')) || 'August',
                (window.t && window.t('arbeitszeitcheck', 'September')) || 'September',
                (window.t && window.t('arbeitszeitcheck', 'October')) || 'October',
                (window.t && window.t('arbeitszeitcheck', 'November')) || 'November',
                (window.t && window.t('arbeitszeitcheck', 'December')) || 'December'
            ];
                const weekdays = this.config.l10n?.weekdays || [
                    (window.t && window.t('arbeitszeitcheck', 'Sunday')) || 'Sunday',
                    (window.t && window.t('arbeitszeitcheck', 'Monday')) || 'Monday',
                    (window.t && window.t('arbeitszeitcheck', 'Tuesday')) || 'Tuesday',
                    (window.t && window.t('arbeitszeitcheck', 'Wednesday')) || 'Wednesday',
                    (window.t && window.t('arbeitszeitcheck', 'Thursday')) || 'Thursday',
                    (window.t && window.t('arbeitszeitcheck', 'Friday')) || 'Friday',
                    (window.t && window.t('arbeitszeitcheck', 'Saturday')) || 'Saturday'
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
                const start = new Date(startTime);
                const end = new Date(endTime);
                const totalSeconds = (end - start) / 1000;
                durationHours = totalSeconds / 3600;
                // Subtract break time if available
                workingDurationHours = durationHours - breakDurationHours;
            }
            
            const status = entry.status || 'completed';
            
            const startDate = startTime ? new Date(startTime) : null;
            const endDate = endTime ? new Date(endTime) : null;
            
            // Format time in 24-hour format (HH:MM) using central utility function
            const formatTime = (window.ArbeitszeitCheckUtils && window.ArbeitszeitCheckUtils.formatTime) || 
                ((date) => {
                    const hours = String(date.getHours()).padStart(2, '0');
                    const minutes = String(date.getMinutes()).padStart(2, '0');
                    return `${hours}:${minutes}`;
                });
            
            const startTimeStr = startDate ? formatTime(startDate) : '-';
            const endTimeStr = endDate ? formatTime(endDate) : '-';
            
            // Format duration: show working hours and break time
            const workingHours = Math.floor(workingDurationHours);
            const workingMinutes = Math.floor((workingDurationHours - workingHours) * 60);
            let durationStr = `${workingHours}h ${workingMinutes}m`;
            
            // Add break time if available
            if (breakDurationHours > 0) {
                const breakHours = Math.floor(breakDurationHours);
                const breakMinutes = Math.floor((breakDurationHours - breakHours) * 60);
                const breakLabel = (window.t && window.t('arbeitszeitcheck', 'Break Time')) || this.config.l10n?.breakTime || 'Break';
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
                    <div class="timeline-item-icon">⏱</div>
                    <div class="timeline-item-content">
                        <div class="timeline-item-header">
                            <span class="timeline-item-time">${escapeHtml(startTimeStr)} - ${escapeHtml(endTimeStr)}</span>
                            <span class="timeline-item-duration">${escapeHtml(durationStr)}</span>
                        </div>
                        <div class="timeline-item-status">
                            <span class="badge badge--${status === 'completed' ? 'success' : status === 'active' ? 'primary' : 'warning'}">${escapeHtml(statusLabel)}</span>
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
            const translated = (window.t && window.t('arbeitszeitcheck', labelKey)) || labelKey;
            return translated;
        },

        /**
         * Render an absence item for timeline
         */
        renderAbsenceItem: function(absence) {
            const startDate = absence.start_date || absence.startDate;
            const endDate = absence.end_date || absence.endDate;
            const type = absence.type || 'unknown';
            const status = absence.status || 'pending';
            const translatedType = this.getAbsenceTypeLabel(type);

            const start = startDate ? new Date(startDate) : null;
            const end = endDate ? new Date(endDate) : null;
            
            // Format dates using translated month names
            const _months = this.config.l10n?.months || [
                (window.t && window.t('arbeitszeitcheck', 'January')) || 'January',
                (window.t && window.t('arbeitszeitcheck', 'February')) || 'February',
                (window.t && window.t('arbeitszeitcheck', 'March')) || 'March',
                (window.t && window.t('arbeitszeitcheck', 'April')) || 'April',
                (window.t && window.t('arbeitszeitcheck', 'May')) || 'May',
                (window.t && window.t('arbeitszeitcheck', 'June')) || 'June',
                (window.t && window.t('arbeitszeitcheck', 'July')) || 'July',
                (window.t && window.t('arbeitszeitcheck', 'August')) || 'August',
                (window.t && window.t('arbeitszeitcheck', 'September')) || 'September',
                (window.t && window.t('arbeitszeitcheck', 'October')) || 'October',
                (window.t && window.t('arbeitszeitcheck', 'November')) || 'November',
                (window.t && window.t('arbeitszeitcheck', 'December')) || 'December'
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

            // Translate status
            let statusLabel = status;
            if (status === 'approved') {
                statusLabel = this.config.l10n?.statusApproved || 'Approved';
            } else if (status === 'rejected') {
                statusLabel = this.config.l10n?.statusRejected || 'Rejected';
            } else if (status === 'substitute_pending') {
                statusLabel = this.config.l10n?.statusSubstitutePending || 'Awaiting substitute approval';
            } else if (status === 'substitute_declined') {
                statusLabel = this.config.l10n?.statusSubstituteDeclined || 'Declined by substitute';
            } else if (status === 'pending') {
                statusLabel = this.config.l10n?.statusPending || 'Awaiting manager approval';
            }

            const badgeClass = status === 'approved' ? 'success' : status === 'rejected' || status === 'substitute_declined' ? 'error' : 'warning';

            return `
                <div class="timeline-item timeline-item--absence">
                    <div class="timeline-item-icon">📅</div>
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
                scopeLabel = this.config.l10n?.publicHoliday
                    || (window.t && window.t('arbeitszeitcheck', 'Public holiday'))
                    || 'Public holiday';
            } else if (scope === 'company') {
                scopeLabel = this.config.l10n?.companyHoliday
                    || (window.t && window.t('arbeitszeitcheck', 'Company holiday'))
                    || 'Company holiday';
            } else {
                scopeLabel = this.config.l10n?.customHoliday
                    || (window.t && window.t('arbeitszeitcheck', 'Custom holiday'))
                    || 'Custom holiday';
            }

            const displayName = name !== '' ? name : scopeLabel;
            const ariaLabel = `${scopeLabel}: ${displayName}${dateStr ? ' (' + dateStr + ')' : ''}`;

            return `
                <div class="timeline-item timeline-item--holiday" aria-label="${escapeHtml(ariaLabel)}">
                    <div class="timeline-item-icon">🎉</div>
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
            const monthStr = this.config.currentMonth || new Date().toISOString().slice(0, 7);
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
                closePanelBtn.addEventListener('click', () => this.closeDayDetailsPanel());
            }
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    const panel = document.getElementById('day-details-panel');
                    if (panel && panel.style.display === 'block') {
                        this.closeDayDetailsPanel();
                    }
                }
            });

            // Load calendar data
            this.loadCalendarData();
        },

        /**
         * Build a correct API path for Nextcloud (uses OC.generateUrl when available)
         * @param {string} path - Path like /apps/arbeitszeitcheck/api/holidays
         * @returns {string}
         */
        _buildApiPath: function(path) {
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
            const absencesParams = { limit: '500' };
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
                (window.t && window.t('arbeitszeitcheck', 'Sun')) || 'Sun',
                (window.t && window.t('arbeitszeitcheck', 'Mon')) || 'Mon',
                (window.t && window.t('arbeitszeitcheck', 'Tue')) || 'Tue',
                (window.t && window.t('arbeitszeitcheck', 'Wed')) || 'Wed',
                (window.t && window.t('arbeitszeitcheck', 'Thu')) || 'Thu',
                (window.t && window.t('arbeitszeitcheck', 'Fri')) || 'Fri',
                (window.t && window.t('arbeitszeitcheck', 'Sat')) || 'Sat'
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
                if (dayData.hasAbsence) classes.push('calendar-day--has-absence');
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
                
                // Show absence type with icon/indicator
                if (dayData.hasAbsence && dayData.absences.length > 0) {
                    const absence = dayData.absences[0];
                    const type = absence.type || 'absence';
                    const status = absence.status || 'pending';
                    
                    // Use emoji or text indicator for absence type
                    let absenceIndicator = '';
                    if (type === 'vacation' || type === 'holiday') {
                        absenceIndicator = '🏖️';
                    } else if (type === 'sick' || type === 'sick_leave') {
                        absenceIndicator = '🏥';
                    } else {
                        absenceIndicator = '📅';
                    }
                    
                    // Add status indicator
                    if (status === 'pending' || status === 'substitute_pending') {
                        absenceIndicator += ' ⏳';
                    } else if (status === 'approved') {
                        absenceIndicator += ' ✓';
                    } else if (status === 'rejected' || status === 'substitute_declined') {
                        absenceIndicator += ' ✗';
                    }
                    
                    const typeLabel = this.getAbsenceTypeLabel(type);
                    dayContent += `<div class="calendar-day-absence" title="${escapeHtml(typeLabel)}">${absenceIndicator}</div>`;
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
                const openDay = () => {
                    const date = dayEl.dataset.date;
                    if (date) this.showDayDetails(date);
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
                if (dayData.hasAbsence) {
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
                const holidayNames = holidays.filter(h => h && h.date === dateKey).map(h => h.name).filter(Boolean).join(', ');
                const holidayHtml = holidayNames ? `<div class="week-day-holiday" aria-hidden="true">${escapeHtml(holidayNames)}</div>` : '';
                html += `
                    <div class="${weekDayClasses.join(' ')}" data-date="${dateKey}">
                        <div class="week-day-name">${weekdays[i]}</div>
                        <div class="week-day-number">${date.getDate()}</div>
                        ${dayData.hours > 0 ? `<div class="week-day-hours">${dayData.hours.toFixed(1)}h</div>` : ''}
                        ${holidayHtml}
                    </div>
                `;
            }
            html += '</div>';

            html += '</div>';
            container.innerHTML = html;

            // Add click and keyboard handlers
            const holidaysForAria = Array.isArray(this.calendarData.holidays) ? this.calendarData.holidays : [];
            container.querySelectorAll('.calendar-week-day[data-date]').forEach(dayEl => {
                const openDay = () => {
                    const date = dayEl.dataset.date;
                    if (date) this.showDayDetails(date);
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
            const today = new Date().toISOString().split('T')[0];
            const date = new Date(dateKey);
            const isWeekend = date.getDay() === 0 || date.getDay() === 6;

            // Find time entries for this day
            const dayEntries = this.calendarData.timeEntries.filter(entry => {
                const startTime = entry.start_time || entry.startTime;
                if (!startTime) return false;
                const entryDate = new Date(startTime).toISOString().split('T')[0];
                return entryDate === dateKey;
            });

            // Find absences for this day
            const dayAbsences = this.calendarData.absences.filter(absence => {
                const startDate = absence.start_date || absence.startDate;
                const endDate = absence.end_date || absence.endDate;
                if (!startDate) return false;
                
                const start = new Date(startDate).toISOString().split('T')[0];
                const end = endDate ? new Date(endDate).toISOString().split('T')[0] : start;
                
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

            return {
                hasTimeEntry: dayEntries.length > 0,
                hasAbsence: dayAbsences.length > 0,
                hours: totalHours,
                entries: dayEntries,
                absences: dayAbsences,
                absenceType: dayAbsences.length > 0 ? (dayAbsences[0].type || 'absence') : null,
                isToday: dateKey === today,
                isWeekend: isWeekend
            };
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
                const typeLabel = this.getAbsenceTypeLabel(dayData.absences[0].type || 'absence');
                label += ', ' + typeLabel;
            }
            label += '. ' + (this.config.l10n?.clickForDetails || 'Click for details');
            return label;
        },

        /**
         * Navigate calendar (prev/next month or week). Reloads data for the new period.
         */
        navigateCalendar: function(direction) {
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
            this.calendarData.currentDate = new Date();
            this.loadCalendarData();
        },

        /**
         * Switch between month and week view
         */
        switchView: function(view) {
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
                (window.t && window.t('arbeitszeitcheck', 'January')) || 'January',
                (window.t && window.t('arbeitszeitcheck', 'February')) || 'February',
                (window.t && window.t('arbeitszeitcheck', 'March')) || 'March',
                (window.t && window.t('arbeitszeitcheck', 'April')) || 'April',
                (window.t && window.t('arbeitszeitcheck', 'May')) || 'May',
                (window.t && window.t('arbeitszeitcheck', 'June')) || 'June',
                (window.t && window.t('arbeitszeitcheck', 'July')) || 'July',
                (window.t && window.t('arbeitszeitcheck', 'August')) || 'August',
                (window.t && window.t('arbeitszeitcheck', 'September')) || 'September',
                (window.t && window.t('arbeitszeitcheck', 'October')) || 'October',
                (window.t && window.t('arbeitszeitcheck', 'November')) || 'November',
                (window.t && window.t('arbeitszeitcheck', 'December')) || 'December'
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
         */
        showDayDetails: function(dateKey) {
            const panel = document.getElementById('day-details-panel');
            const label = document.getElementById('selected-date-label');
            const content = document.getElementById('day-details-content');
            
            if (!panel || !label || !content) return;

            // Remember the element that opened the panel so we can restore focus on close
            if (typeof document !== 'undefined' && document.activeElement) {
                this.calendarData.lastActiveDayElement = document.activeElement;
            }

            const date = new Date(dateKey);
            const dayData = this.getDayData(dateKey);

            const weekdays = this.config.l10n?.weekdays || ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const weekdayName = weekdays[date.getDay()];
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            label.textContent = `${weekdayName}, ${day}.${month}.${year}`;

            let html = '';

            // Holiday info
            const holidays = Array.isArray(this.calendarData.holidays) ? this.calendarData.holidays : [];
            const holidayNames = holidays
                .filter(h => h.date === dateKey)
                .map(h => h.name)
                .filter(Boolean);
            if (holidayNames.length > 0) {
                const label = this.config.l10n?.holiday || (window.t ? window.t('arbeitszeitcheck', 'Holiday') : 'Holiday');
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
                        
                        // Format time in 24-hour format (HH:MM) using central utility function
                        const formatTime = (window.ArbeitszeitCheckUtils && window.ArbeitszeitCheckUtils.formatTime) || 
                            ((date) => {
                                const hours = String(date.getHours()).padStart(2, '0');
                                const minutes = String(date.getMinutes()).padStart(2, '0');
                                return `${hours}:${minutes}`;
                            });
                        const start = startTime ? formatTime(new Date(startTime)) : '-';
                        const end = endTime ? formatTime(new Date(endTime)) : '-';
                        
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
                        const translatedType = this.getAbsenceTypeLabel(absence.type || 'absence');
                        html += `<li>${escapeHtml(translatedType)}</li>`;
                    });
                    html += '</ul></div>';
                }
            }

            content.innerHTML = html;
            panel.style.display = 'block';
        },

        /**
         * Close day details panel
         */
        closeDayDetailsPanel: function() {
            const panel = document.getElementById('day-details-panel');
            if (panel) {
                panel.style.display = 'none';
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

})(window, OC);