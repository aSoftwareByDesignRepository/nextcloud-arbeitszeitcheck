/**
 * ArbeitszeitCheck - Vanilla JavaScript Application
 * Replaces the Vue.js implementation with a clean, simple JavaScript approach
 */
(function(window, OC) {
    'use strict';

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
                            const workingHours = workingSeconds / 3600;
                            const maxWorkingHours = 10; // ArbZG §3 maximum
                            
                            // Remove previous warning classes
                            timerEl.classList.remove('timer-warning', 'timer-error');
                            
                            if (workingHours >= maxWorkingHours) {
                                // Exceeded 10 hours - show error state
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
                                            'CRITICAL: Maximum working hours (10h) exceeded! Automatically clocking out to comply with German labor law (ArbZG §3).')) ||
                                            'CRITICAL: Maximum working hours (10h) exceeded! Automatically clocking out to comply with German labor law (ArbZG §3).';
                                        OC.Notification.showTemporary(criticalMsg, { 
                                            type: 'error', 
                                            timeout: 20000 
                                        });
                                    }
                                    
                                    // Automatically clock out after a short delay to show the notification
                                    setTimeout(() => {
                                        this.clockOut()
                                            .then(() => {
                                                // Reload page to show updated status
                                                window.location.reload();
                                            })
                                            .catch(error => {
                                                console.error('Error during automatic clock-out:', error);
                                                // Still reload to show error state
                                                window.location.reload();
                                            });
                                    }, 2000); // 2 second delay to show notification
                                }
                            } else if (workingHours >= 8) {
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
            const buttons = {
                clockIn: document.getElementById('btn-clock-in'),
                clockOut: document.getElementById('btn-clock-out'),
                startBreak: document.getElementById('btn-start-break'),
                endBreak: document.getElementById('btn-end-break')
            };

            if (buttons.clockIn) {
                buttons.clockIn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.clockIn();
                });
            }

            if (buttons.clockOut) {
                buttons.clockOut.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.clockOut();
                });
            }

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
                    if (confirm(this.config.l10n?.confirmDelete || 'Are you sure you want to delete this item?')) {
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
                    const startDate = document.getElementById('filter-start-date')?.value;
                    const endDate = document.getElementById('filter-end-date')?.value;
                    const status = document.getElementById('filter-status')?.value;
                    
                    // Build query string
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

            // Filter button - toggle filter section (if it exists)
            const filterBtn = document.getElementById('btn-filter');
            const filterSection = document.getElementById('filter-section');
            
            if (filterBtn) {
                if (filterSection) {
                    filterBtn.addEventListener('click', () => {
                        const isVisible = filterSection.style.display !== 'none';
                        filterSection.style.display = isVisible ? 'none' : 'block';
                    });
                } else {
                    // Filter section doesn't exist yet, just show a message or do nothing
                    filterBtn.addEventListener('click', () => {
                        console.log('Filter functionality not yet implemented');
                    });
                }
            }

            // Edit buttons in table rows (for pending absences)
            const editButtons = document.querySelectorAll('table tbody .btn-edit[data-absence-id]');
            editButtons.forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const absenceId = button.dataset.absenceId;
                    if (absenceId) {
                        // Redirect to edit page
                        const editUrl = OC.generateUrl('/apps/arbeitszeitcheck/absences/' + absenceId + '/edit');
                        window.location.href = editUrl;
                    }
                });
            });

            // Cancel buttons in table rows (for pending absences)
            const cancelButtons = document.querySelectorAll('table tbody .btn-cancel[data-absence-id]');
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
                                    (window.t && window.t('arbeitszeitcheck', 'Absence request canceled successfully')) || 
                                    'Absence request canceled successfully';
                                this.showSuccess(successMsg);
                            })
                            .catch(error => {
                                console.error('Error canceling absence request:', error);
                            });
                    }
                });
            });

            // View buttons in table rows (for approved/rejected absences)
            const viewButtons = document.querySelectorAll('table tbody .btn-view[data-absence-id]');
            viewButtons.forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const absenceId = button.dataset.absenceId;
                    if (absenceId) {
                        // Redirect to show/details page
                        const showUrl = OC.generateUrl('/apps/arbeitszeitcheck/absences/' + absenceId);
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

            this.callApi('/apps/arbeitszeitcheck/api/clock/in', 'POST', data);
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
            
            this.callApi('/apps/arbeitszeitcheck/api/clock/out', 'POST');
        },

        /**
         * Start break action
         */
        startBreak: function() {
            this.callApi('/apps/arbeitszeitcheck/api/break/start', 'POST');
        },

        /**
         * End break action
         */
        endBreak: function() {
            this.callApi('/apps/arbeitszeitcheck/api/break/end', 'POST');
        },

        /**
         * Get current status
         */
        getStatus: function() {
            return this.callApi('/apps/arbeitszeitcheck/api/clock/status', 'GET', null, false);
        },

        /**
         * Generic API call helper
         * @param {string} endpoint - API endpoint URL
         * @param {string} method - HTTP method (GET, POST, PUT, DELETE)
         * @param {object|null} data - Data to send (null for GET/DELETE)
         * @param {boolean} reloadOnSuccess - Whether to reload page on success (default: true)
         */
        callApi: function(endpoint, method = 'POST', data = null, reloadOnSuccess = true) {
            // Build full URL
            // If endpoint already starts with /apps/, use it directly
            // Otherwise, use OC.generateUrl to build the URL
            let url;
            if (endpoint.startsWith('http')) {
                url = endpoint;
            } else if (endpoint.startsWith('/apps/')) {
                // Already a full path, use it directly
                url = endpoint;
            } else {
                url = OC.generateUrl(endpoint);
            }

            // Build request options
            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken || ''
                }
            };

            // Add body for POST/PUT requests
            if (data !== null && (method === 'POST' || method === 'PUT')) {
                options.body = JSON.stringify(data);
            }

            // Show loading state
            this.setLoadingState(true);

            // Make the API call
            console.log('API Call:', { url, method, data, options });
            return fetch(url, options)
                .then(response => {
                    console.log('API Response:', { status: response.status, statusText: response.statusText, url: response.url });
                    // Check if response is JSON
                    const contentType = response.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        return response.json();
                    } else {
                        return response.text().then(text => {
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                return { success: response.ok, error: text || 'Unknown error' };
                            }
                        });
                    }
                })
                .then(result => {
                    this.setLoadingState(false);

                    if (result.success !== false) {
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
                        
                        this.showError(errorMsg);
                        throw new Error(errorMsg);
                    }
                })
                .catch(error => {
                    this.setLoadingState(false);
                    
                    // Get error message from error object
                    let errorMsg = error.message;
                    
                    // If error has a response with error data, use that
                    if (error.response && error.response.error) {
                        errorMsg = error.response.error;
                    } else if (error.error) {
                        errorMsg = error.error;
                    }
                    
                    // If still no error message, use fallback
                    if (!errorMsg) {
                        errorMsg = this.config.l10n?.error || 'An error occurred';
                    }
                    
                    // Ensure error message is a plain string (not a translation key)
                    errorMsg = String(errorMsg);
                    
                    this.showError(errorMsg);
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
         */
        formatDate: function(dateString, includeTime = false) {
            const date = new Date(dateString);
            // Use German date format (DD.MM.YYYY)
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            const formatted = `${day}.${month}.${year}`;
            
            if (includeTime) {
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                return `${formatted} ${hours}:${minutes}`;
            }
            return formatted;
        },

        /**
         * Initialize timeline page
         */
        initTimeline: function() {
            const container = document.getElementById('timeline-container');
            if (!container) {
                // Container not found, try again after a short delay
                setTimeout(() => {
                    this.initTimeline();
                }, 100);
                return;
            }

            const refreshBtn = document.getElementById('btn-refresh-timeline');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', () => {
                    this.loadTimeline();
                });
            }
            
            // Load timeline data on page load
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
            container.innerHTML = `
                <div class="timeline-loading">
                    <div class="loading-spinner"></div>
                    <p>${this.config.l10n?.loadingTimeline || 'Loading timeline...'}</p>
                </div>
            `;

            const timeEntriesUrl = this.config.apiUrl?.timeEntries || '/apps/arbeitszeitcheck/api/time-entries';
            const absencesUrl = this.config.apiUrl?.absences || '/apps/arbeitszeitcheck/api/absences';

            // Load both time entries and absences in parallel
            Promise.all([
                this.fetchTimelineData(timeEntriesUrl),
                this.fetchTimelineData(absencesUrl)
            ]).then(([timeEntries, absences]) => {
                // Ensure we have arrays
                const entries = Array.isArray(timeEntries) ? timeEntries : [];
                const abs = Array.isArray(absences) ? absences : [];
                this.renderTimeline(container, entries, abs);
            }).catch((error) => {
                container.innerHTML = `
                    <div class="timeline-error">
                        <p>${this.config.l10n?.error || 'An error occurred'}: ${error.message || 'Unknown error'}</p>
                    </div>
                `;
            });
        },

        /**
         * Fetch timeline data from API
         */
        fetchTimelineData: function(url) {
            const fullUrl = OC.generateUrl(url);
            return fetch(fullUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken || ''
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
                // Handle different response formats
                if (Array.isArray(data)) {
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
                // Silently return empty array on error
                return [];
            });
        },

        /**
         * Render timeline with time entries and absences
         */
        renderTimeline: function(container, timeEntries, absences) {
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

            // Sort by date (newest first)
            items.sort((a, b) => b.date - a.date);

            if (items.length === 0) {
                container.innerHTML = `
                    <div class="timeline-empty">
                        <p>${this.config.l10n?.noTimelineData || 'No timeline data available'}</p>
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
            
            // Format time in 24-hour format (HH:MM) for German locale
            const formatTime = (date) => {
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                return `${hours}:${minutes}`;
            };
            
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
                            <span class="timeline-item-time">${startTimeStr} - ${endTimeStr}</span>
                            <span class="timeline-item-duration">${durationStr}</span>
                        </div>
                        <div class="timeline-item-status">
                            <span class="badge badge--${status === 'completed' ? 'success' : status === 'active' ? 'primary' : 'warning'}">${statusLabel}</span>
                        </div>
                    </div>
                </div>
            `;
        },

        /**
         * Render an absence item for timeline
         */
        renderAbsenceItem: function(absence) {
            const startDate = absence.start_date || absence.startDate;
            const endDate = absence.end_date || absence.endDate;
            const type = absence.type || 'unknown';
            const status = absence.status || 'pending';
            
            const start = startDate ? new Date(startDate) : null;
            const end = endDate ? new Date(endDate) : null;
            
            // Format dates using translated month names
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

            // Translate absence type
            let translatedType = type;
            if (window.t && window.t('arbeitszeitcheck', type)) {
                translatedType = window.t('arbeitszeitcheck', type);
            } else {
                // Fallback: try common variations
                const typeLower = type.toLowerCase();
                if (typeLower === 'vacation' || typeLower === 'holiday') {
                    translatedType = (window.t && window.t('arbeitszeitcheck', 'Vacation')) || 'Vacation';
                } else if (typeLower === 'sick' || typeLower === 'sick_leave' || typeLower === 'sick leave') {
                    translatedType = (window.t && window.t('arbeitszeitcheck', 'Sick Leave')) || 'Sick Leave';
                } else {
                    // Capitalize first letter as fallback
                    translatedType = type.charAt(0).toUpperCase() + type.slice(1).toLowerCase();
                }
            }

            // Translate status
            let statusLabel = status;
            if (status === 'approved') {
                statusLabel = this.config.l10n?.statusApproved || 'Approved';
            } else if (status === 'rejected') {
                statusLabel = this.config.l10n?.statusRejected || 'Rejected';
            } else if (status === 'pending') {
                statusLabel = this.config.l10n?.statusPending || 'Pending';
            }

            return `
                <div class="timeline-item timeline-item--absence">
                    <div class="timeline-item-icon">📅</div>
                    <div class="timeline-item-content">
                        <div class="timeline-item-header">
                            <span class="timeline-item-type">${translatedType}</span>
                            <span class="timeline-item-date">${dateStr}</span>
                        </div>
                        <div class="timeline-item-status">
                            <span class="badge badge--${status === 'approved' ? 'success' : status === 'rejected' ? 'error' : 'warning'}">${statusLabel}</span>
                        </div>
                    </div>
                </div>
            `;
        },

        /**
         * Initialize calendar page
         */
        initCalendar: function() {
            this.calendarData = {
                timeEntries: [],
                absences: [],
                currentDate: new Date(this.config.currentMonth + '-01'),
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

            // Load calendar data
            this.loadCalendarData();
        },

        /**
         * Load calendar data from API
         */
        loadCalendarData: function() {
            const timeEntriesUrl = this.config.apiUrl?.calendar || '/apps/arbeitszeitcheck/api/time-entries';
            const absencesUrl = this.config.apiUrl?.absences || '/apps/arbeitszeitcheck/api/absences';

            Promise.all([
                this.fetchTimelineData(timeEntriesUrl),
                this.fetchTimelineData(absencesUrl)
            ]).then(([timeEntries, absences]) => {
                this.calendarData.timeEntries = timeEntries || [];
                this.calendarData.absences = absences || [];
                this.renderCalendar();
            }).catch((error) => {
                const container = document.getElementById('calendar-month-view');
                if (container) {
                    container.innerHTML = `
                        <div class="calendar-error">
                            <p>${this.config.l10n?.error || 'An error occurred'}: ${error.message}</p>
                        </div>
                    `;
                }
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
                const dateKey = date.toISOString().split('T')[0];
                const dayData = this.getDayData(dateKey);
                
                const classes = ['calendar-day'];
                if (dayData.hasTimeEntry) classes.push('calendar-day--has-entry');
                if (dayData.hasAbsence) classes.push('calendar-day--has-absence');
                if (dayData.isToday) classes.push('calendar-day--today');
                if (dayData.isWeekend) classes.push('calendar-day--weekend');

                // Build day content with more useful information
                let dayContent = `<div class="calendar-day-number">${day}</div>`;
                
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
                    if (status === 'pending') {
                        absenceIndicator += ' ⏳';
                    } else if (status === 'approved') {
                        absenceIndicator += ' ✓';
                    } else if (status === 'rejected') {
                        absenceIndicator += ' ✗';
                    }
                    
                    // Get absence type label for tooltip
                    let typeLabel = type;
                    if (window.t && window.t('arbeitszeitcheck', type)) {
                        typeLabel = window.t('arbeitszeitcheck', type);
                    } else if (type === 'vacation' || type === 'holiday') {
                        typeLabel = (window.t && window.t('arbeitszeitcheck', 'Vacation')) || 'Vacation';
                    } else if (type === 'sick' || type === 'sick_leave') {
                        typeLabel = (window.t && window.t('arbeitszeitcheck', 'Sick Leave')) || 'Sick Leave';
                    }
                    dayContent += `<div class="calendar-day-absence" title="${typeLabel}">${absenceIndicator}</div>`;
                }
                
                // Show entry count if multiple entries
                if (dayData.entries.length > 1) {
                    dayContent += `<div class="calendar-day-entry-count" title="${dayData.entries.length} ${this.config.l10n?.timeEntries || 'entries'}">${dayData.entries.length}×</div>`;
                }
                
                html += `<div class="${classes.join(' ')}" data-date="${dateKey}">${dayContent}</div>`;
            }

            html += '</div></div>';

            // Add month summary
            const monthSummary = this.calculateMonthSummary(year, month);
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
            `;

            container.innerHTML = html;

            // Add click handlers to days
            container.querySelectorAll('.calendar-day[data-date]').forEach(dayEl => {
                dayEl.addEventListener('click', () => {
                    const date = dayEl.dataset.date;
                    this.showDayDetails(date);
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
            
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const daysInMonth = lastDay.getDate();
            
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(year, month, day);
                const dateKey = date.toISOString().split('T')[0];
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
            for (let i = 0; i < 7; i++) {
                const date = new Date(weekStart);
                date.setDate(weekStart.getDate() + i);
                const dateKey = date.toISOString().split('T')[0];
                const dayData = this.getDayData(dateKey);
                
                html += `
                    <div class="calendar-week-day" data-date="${dateKey}">
                        <div class="week-day-name">${weekdays[i]}</div>
                        <div class="week-day-number">${date.getDate()}</div>
                        ${dayData.hours > 0 ? `<div class="week-day-hours">${dayData.hours.toFixed(1)}h</div>` : ''}
                    </div>
                `;
            }
            html += '</div>';

            html += '</div>';
            container.innerHTML = html;

            // Add click handlers
            container.querySelectorAll('.calendar-week-day[data-date]').forEach(dayEl => {
                dayEl.addEventListener('click', () => {
                    const date = dayEl.dataset.date;
                    this.showDayDetails(date);
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

            // Calculate total hours
            let totalHours = 0;
            dayEntries.forEach(entry => {
                const duration = entry.duration || entry.working_duration || 0;
                totalHours += duration / 3600;
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
         * Navigate calendar (prev/next month or week)
         */
        navigateCalendar: function(direction) {
            const currentDate = new Date(this.calendarData.currentDate);
            if (this.calendarData.currentView === 'month') {
                currentDate.setMonth(currentDate.getMonth() + direction);
            } else {
                currentDate.setDate(currentDate.getDate() + (direction * 7));
            }
            this.calendarData.currentDate = currentDate;
            this.renderCalendar();
        },

        /**
         * Go to today - navigate to current month/week and highlight today
         */
        goToToday: function() {
            const today = new Date();
            this.calendarData.currentDate = today;
            
            // Update URL to reflect current month and reload to sync with backend
            const year = today.getFullYear();
            const month = String(today.getMonth() + 1).padStart(2, '0');
            const monthKey = `${year}-${month}`;
            
            // Reload page with current month parameter to sync with backend
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('month', monthKey);
            window.location.href = currentUrl.toString();
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
                if (monthBtn) monthBtn.classList.add('active');
                if (weekBtn) weekBtn.classList.remove('active');
            } else {
                if (monthView) monthView.style.display = 'none';
                if (weekView) weekView.style.display = 'block';
                if (monthBtn) monthBtn.classList.remove('active');
                if (weekBtn) weekBtn.classList.add('active');
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
                const month = String(date.getMonth() + 1).padStart(2, '0');
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

            const date = new Date(dateKey);
            const dayData = this.getDayData(dateKey);
            
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
            const weekdays = this.config.l10n?.weekdays || ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const weekdayName = weekdays[date.getDay()];
            const monthName = months[date.getMonth()];
                // Use German date format (DD.MM.YYYY)
                const day = String(date.getDate()).padStart(2, '0');
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const year = date.getFullYear();
                label.textContent = `${weekdayName}, ${day}.${month}.${year}`;

            let html = '';
            
            if (dayData.entries.length === 0 && dayData.absences.length === 0) {
                html = `<p>${this.config.l10n?.noEntries || 'No entries for this day'}</p>`;
            } else {
                if (dayData.entries.length > 0) {
                    const timeEntriesLabel = this.config.l10n?.timeEntries || 'Time Entries';
                    html += `<div class="day-details-section"><h4>${timeEntriesLabel}</h4><ul>`;
                    dayData.entries.forEach(entry => {
                        const startTime = entry.start_time || entry.startTime;
                        const endTime = entry.end_time || entry.endTime;
                        const duration = entry.duration || entry.working_duration || 0;
                        const breakDuration = entry.break_duration || 0;
                        const hours = Math.floor(duration / 3600);
                        const minutes = Math.floor((duration % 3600) / 60);
                        const breakHours = Math.floor(breakDuration / 3600);
                        const breakMinutes = Math.floor((breakDuration % 3600) / 60);
                        
                        // Format time in 24-hour format (HH:MM) for German locale
                        const formatTime = (date) => {
                            const hours = String(date.getHours()).padStart(2, '0');
                            const minutes = String(date.getMinutes()).padStart(2, '0');
                            return `${hours}:${minutes}`;
                        };
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
                        const type = absence.type || 'absence';
                        // Translate common absence types
                        let translatedType = type;
                        if (window.t && window.t('arbeitszeitcheck', type)) {
                            translatedType = window.t('arbeitszeitcheck', type);
                        } else {
                            // Fallback: try common variations
                            const typeLower = type.toLowerCase();
                            if (typeLower === 'vacation' || typeLower === 'holiday') {
                                translatedType = (window.t && window.t('arbeitszeitcheck', 'Vacation')) || 'Vacation';
                            } else if (typeLower === 'sick' || typeLower === 'sick_leave' || typeLower === 'sick leave') {
                                translatedType = (window.t && window.t('arbeitszeitcheck', 'Sick Leave')) || 'Sick Leave';
                            } else {
                                // Capitalize first letter as fallback
                                translatedType = type.charAt(0).toUpperCase() + type.slice(1).toLowerCase();
                            }
                        }
                        html += `<li>${translatedType}</li>`;
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