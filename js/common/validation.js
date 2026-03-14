/**
 * Form Validation Utilities for ArbeitszeitCheck App
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

const t = (s, opts) => (typeof window !== 'undefined' && window.t ? window.t('arbeitszeitcheck', s, opts || {}) : s);

const ArbeitszeitCheckValidation = {
  /**
   * Validate form field
   */
  validateField(field, rules = {}) {
    const value = field.value.trim();
    const errors = [];
    const fieldLabel = field.labels && field.labels[0] ? field.labels[0].textContent : t('This field');
    const l10n = window.ArbeitszeitCheck?.l10n || {};

    // Required validation
    if (rules.required && !value) {
      const message = l10n.fieldRequired || t('{field} is required. Please fill in this field.', { field: fieldLabel });
      errors.push(message);
    }

    // Email validation
    if (rules.email && value && !this.isEmail(value)) {
      const message = l10n.emailInvalid || t('Please enter a valid email address. Example: name@example.com');
      errors.push(message);
    }

    // Min length validation
    if (rules.minLength && value.length < rules.minLength) {
      const message = l10n.minLength || t('Please enter at least {min} characters. You entered {actual} characters.', { min: String(rules.minLength), actual: String(value.length) });
      errors.push(message);
    }

    // Max length validation
    if (rules.maxLength && value.length > rules.maxLength) {
      const message = l10n.maxLength || t('Please enter no more than {max} characters. You entered {actual} characters.', { max: String(rules.maxLength), actual: String(value.length) });
      errors.push(message);
    }

    // Pattern validation
    if (rules.pattern && value && !rules.pattern.test(value)) {
      const message = rules.patternMessage || l10n.invalidFormat || t('The format is incorrect. Please check the format and try again.');
      errors.push(message);
    }

    // Number validation
    if (rules.number) {
      const num = parseFloat(value);
      if (isNaN(num)) {
        const message = l10n.numberInvalid || t('Please enter a valid number. Example: {example}', { example: String(rules.example || '8') });
        errors.push(message);
      } else {
        if (rules.min !== undefined && num < rules.min) {
          const message = l10n.numberMin || t('Please enter a number that is at least {min}. You entered {actual}.', { min: String(rules.min), actual: String(num) });
          errors.push(message);
        }
        if (rules.max !== undefined && num > rules.max) {
          const message = l10n.numberMax || t('Please enter a number that is no more than {max}. You entered {actual}.', { max: String(rules.max), actual: String(num) });
          errors.push(message);
        }
      }
    }

    // Time validation
    if (rules.time && value) {
      const timePattern = /^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/;
      if (!timePattern.test(value)) {
        const message = l10n.timeInvalid || t('Please enter the time in this format: HH:MM (hours:minutes). Example: 09:00');
        errors.push(message);
      }
    }

    // Date validation
    if (rules.date && value) {
      const date = new Date(value);
      if (isNaN(date.getTime())) {
        const message = l10n.dateInvalid || t('Please enter a valid date. Click the calendar icon to pick a date, or enter it in format dd.mm.yyyy.');
        errors.push(message);
      }
    }

    // Custom validation
    if (rules.custom && typeof rules.custom === 'function') {
      const customError = rules.custom(value, field);
      if (customError) {
        errors.push(customError);
      }
    }

    return {
      valid: errors.length === 0,
      errors: errors
    };
  },

  /**
   * Validate entire form
   */
  validateForm(form, rules = {}) {
    if (typeof form === 'string') {
      form = document.querySelector(form);
    }

    if (!form) {
      return { valid: false, errors: {} };
    }

    const errors = {};
    let isValid = true;

    // Clear previous errors
    this.clearFormErrors(form);

    // Validate each field
    Object.keys(rules).forEach(fieldName => {
      const field = form.querySelector(`[name="${fieldName}"]`);
      if (field) {
        const result = this.validateField(field, rules[fieldName]);
        if (!result.valid) {
          isValid = false;
          errors[fieldName] = result.errors;
          this.showFieldError(field, result.errors[0]);
        }
      }
    });

    return {
      valid: isValid,
      errors: errors
    };
  },

  /**
   * Show field error with helpful message
   */
  showFieldError(field, message) {
    // Remove existing error
    this.clearFieldError(field);

    // Add error class to input
    field.classList.add('form-input--error');
    field.setAttribute('aria-invalid', 'true');
    
    // Get or create error container
    let errorContainer = field.parentNode.querySelector('.form-error-container');
    if (!errorContainer) {
      errorContainer = document.createElement('div');
      errorContainer.className = 'form-error-container';
      field.parentNode.appendChild(errorContainer);
    }

    // Create error message element with icon
    const errorElement = document.createElement('div');
    errorElement.className = 'form-error';
    errorElement.setAttribute('role', 'alert');
    errorElement.setAttribute('aria-live', 'polite');
    errorElement.setAttribute('id', field.id ? `${field.id}-error` : `error-${Date.now()}`);
    
    // Set aria-describedby on field
    const errorId = errorElement.id;
    const currentDescribedBy = field.getAttribute('aria-describedby') || '';
    if (!currentDescribedBy.includes(errorId)) {
      field.setAttribute('aria-describedby', 
        currentDescribedBy ? `${currentDescribedBy} ${errorId}` : errorId);
    }

    // Create error content with icon and message
    errorElement.innerHTML = `
      <span class="form-error__icon" aria-hidden="true">⚠️</span>
      <div class="form-error__content">
        <strong class="form-error__title">${this.escapeHtml(message.split('.')[0])}</strong>
        ${message.includes('.') ? `<p class="form-error__description">${this.escapeHtml(message.substring(message.indexOf('.') + 1).trim())}</p>` : ''}
      </div>
    `;

    errorContainer.appendChild(errorElement);
    
    // Scroll to error if needed
    if (errorElement.offsetParent === null || !this.isElementInViewport(errorElement)) {
      errorElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  },

  /**
   * Escape HTML to prevent XSS
   */
  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  },

  /**
   * Check if element is in viewport
   */
  isElementInViewport(el) {
    const rect = el.getBoundingClientRect();
    return (
      rect.top >= 0 &&
      rect.left >= 0 &&
      rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
      rect.right <= (window.innerWidth || document.documentElement.clientWidth)
    );
  },

  /**
   * Clear field error
   */
  clearFieldError(field) {
    field.classList.remove('form-input--error', 'error');
    field.removeAttribute('aria-invalid');
    
    // Remove aria-describedby reference to error
    const currentDescribedBy = field.getAttribute('aria-describedby') || '';
    const errorId = field.id ? `${field.id}-error` : '';
    if (errorId && currentDescribedBy.includes(errorId)) {
      const newDescribedBy = currentDescribedBy.replace(errorId, '').trim();
      if (newDescribedBy) {
        field.setAttribute('aria-describedby', newDescribedBy);
      } else {
        field.removeAttribute('aria-describedby');
      }
    }

    // Remove error message elements
    const errorContainer = field.parentNode.querySelector('.form-error-container');
    if (errorContainer) {
      errorContainer.remove();
    }
    
    // Also remove old-style .field-error for backward compatibility
    const oldErrorElement = field.parentNode.querySelector('.field-error');
    if (oldErrorElement) {
      oldErrorElement.remove();
    }
  },

  /**
   * Clear all form errors
   */
  clearFormErrors(form) {
    if (typeof form === 'string') {
      form = document.querySelector(form);
    }

    if (!form) return;

    // Clear errors from fields with new class
    const errorFields = form.querySelectorAll('.form-input--error, .error');
    errorFields.forEach(field => {
      this.clearFieldError(field);
    });
    
    // Also clear any standalone error containers
    const errorContainers = form.querySelectorAll('.form-error-container');
    errorContainers.forEach(container => {
      container.remove();
    });
  },

  /**
   * Check if email is valid
   */
  isEmail(value) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(value);
  },

  /**
   * Check if value is numeric
   */
  isNumeric(value) {
    return !isNaN(parseFloat(value)) && isFinite(value);
  },

  /**
   * Validate date string in German format (dd.mm.yyyy)
   * Handles all edge cases: leap years, invalid dates, future dates, past limits
   */
  validateDate(dateStr, options = {}) {
    const errors = [];
    const l10n = window.ArbeitszeitCheck?.l10n || {};
    
    if (!dateStr || !dateStr.trim()) {
      return {
        valid: false,
        errors: [l10n.dateRequired || t('Date is required. Please enter a date in the format dd.mm.yyyy (e.g., 15.01.2024).')],
        date: null
      };
    }

    // Clean up whitespace
    dateStr = dateStr.trim();
    
    // Support multiple formats: dd.mm.yyyy, dd-mm-yyyy, yyyy-mm-dd
    let day, month, year;
    
    // Try dd.mm.yyyy or dd-mm-yyyy first
    const dotFormat = /^(\d{1,2})[.-](\d{1,2})[.-](\d{4})$/;
    const isoFormat = /^(\d{4})[.-](\d{1,2})[.-](\d{1,2})$/;
    
    let match = dateStr.match(dotFormat);
    if (match) {
      day = parseInt(match[1], 10);
      month = parseInt(match[2], 10);
      year = parseInt(match[3], 10);
    } else {
      match = dateStr.match(isoFormat);
      if (match) {
        year = parseInt(match[1], 10);
        month = parseInt(match[2], 10);
        day = parseInt(match[3], 10);
      } else {
        return {
          valid: false,
          errors: [l10n.dateInvalidFormat || t('Invalid date format. Please use dd.mm.yyyy (e.g., 15.01.2024).')],
          date: null
        };
      }
    }

    // Validate date components
    if (isNaN(day) || isNaN(month) || isNaN(year)) {
      return {
        valid: false,
        errors: [l10n.dateInvalidFormat || t('Invalid date format. Please use dd.mm.yyyy (e.g., 15.01.2024).')],
        date: null
      };
    }

    // Validate year (reasonable range)
    if (year < 1900 || year > 2100) {
      errors.push(l10n.dateYearInvalid || t('Year must be between 1900 and 2100. You entered {year}.', { year: String(year) }));
    }

    // Validate month
    if (month < 1 || month > 12) {
      errors.push(l10n.dateMonthInvalid || t('Month must be between 1 and 12. You entered {month}.', { month: String(month) }));
      return { valid: false, errors, date: null };
    }

    // Validate day using checkdate logic (handles leap years automatically)
    const daysInMonth = new Date(year, month, 0).getDate();
    if (day < 1 || day > daysInMonth) {
      errors.push(l10n.dateDayInvalid || t('Invalid day for {month}/{year}. This month has {days} days.', { month: String(month), year: String(year), days: String(daysInMonth) }));
      return { valid: false, errors, date: null };
    }

    // Create date object
    const date = new Date(year, month - 1, day);
    
    // Verify date is valid (handles leap years, etc.)
    if (date.getFullYear() !== year || date.getMonth() !== (month - 1) || date.getDate() !== day) {
      errors.push(l10n.dateInvalid || t('Invalid date: {date}. Please check and try again.', { date: day + '.' + month + '.' + year }));
      return { valid: false, errors, date: null };
    }

    // Check if date is in future — only when caller explicitly opts in via noFuture: true.
    // Absence end dates and future-scheduled entries legitimately need future dates, so the
    // default must be permissive. Callers that want to block future dates pass noFuture: true.
    if (options.noFuture === true) {
      const today = new Date();
      today.setHours(23, 59, 59, 999); // End of today
      if (date > today) {
        errors.push(l10n.dateFutureNotAllowed || t('Future dates are not allowed. Please enter a date today or in the past.'));
      }
    }

    // Check if date is too far in past (if option set)
    if (options.maxDaysPast) {
      const minDate = new Date();
      minDate.setDate(minDate.getDate() - options.maxDaysPast);
      minDate.setHours(0, 0, 0, 0);
      if (date < minDate) {
        // Show days if less than 2 years, otherwise show years
        const totalDays = options.maxDaysPast;
        const displayText = totalDays < 730
          ? t('Date is too far in the past. Maximum allowed: {days} days ago.', { days: String(totalDays) })
          : t('Date is too far in the past. Maximum allowed: {years} years ago.', { years: String(Math.round(totalDays / 365)) });
        errors.push(l10n.dateTooOld || displayText);
      }
    }

    if (errors.length > 0) {
      return { valid: false, errors, date };
    }

    return { valid: true, errors: [], date };
  },

  /**
   * Validate time string (HH:MM format)
   */
  validateTime(timeStr) {
    const errors = [];
    const l10n = window.ArbeitszeitCheck?.l10n || {};
    
    if (!timeStr || !timeStr.trim()) {
      return {
        valid: false,
        errors: [l10n.timeRequired || t('Time is required. Please enter a time in the format HH:MM (e.g., 09:00).')],
        hour: null,
        minute: null
      };
    }

    const timePattern = /^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/;
    const match = timeStr.trim().match(timePattern);
    
    if (!match) {
      return {
        valid: false,
          errors: [l10n.timeInvalidFormat || t('Invalid time format. Please use HH:MM (24-hour format, e.g., 09:00 or 17:30).')],
        hour: null,
        minute: null
      };
    }

    const hour = parseInt(match[1], 10);
    const minute = parseInt(match[2], 10);

    if (hour < 0 || hour > 23) {
      errors.push(l10n.timeHourInvalid || t('Hour must be between 0 and 23. You entered {hour}.', { hour: String(hour) }));
    }

    if (minute < 0 || minute > 59) {
      errors.push(l10n.timeMinuteInvalid || t('Minute must be between 0 and 59. You entered {minute}.', { minute: String(minute) }));
    }

    if (errors.length > 0) {
      return { valid: false, errors, hour, minute };
    }

    return { valid: true, errors: [], hour, minute };
  },

  /**
   * Calculate working duration in hours (excluding breaks)
   */
  calculateWorkingDuration(startDateTime, endDateTime, breaks = []) {
    if (!startDateTime || !endDateTime) {
      return 0;
    }

    const start = new Date(startDateTime);
    const end = new Date(endDateTime);
    
    // Handle night shifts (end before start = next day)
    if (end < start) {
      end.setDate(end.getDate() + 1);
    }

    const totalDurationMs = end - start;
    const totalDurationHours = totalDurationMs / (1000 * 60 * 60);

    // Subtract break time
    let breakDurationHours = 0;
    breaks.forEach(breakTime => {
      if (breakTime.start && breakTime.end) {
        const breakStart = new Date(breakTime.start);
        const breakEnd = new Date(breakTime.end);
        if (breakEnd < breakStart) {
          breakEnd.setDate(breakEnd.getDate() + 1);
        }
        const breakMs = breakEnd - breakStart;
        const breakHours = breakMs / (1000 * 60 * 60);
        breakDurationHours += breakHours;
      }
    });

    return Math.max(0, totalDurationHours - breakDurationHours);
  },

  /**
   * Check rest period (11 hours between end of last entry and start of new) - ArbZG §5
   */
  async checkRestPeriod(userId, startDateTime, excludeEntryId = null) {
    const _l10n = window.ArbeitszeitCheck?.l10n || {};
    
    try {
      // Call backend API to check rest period
      const response = await fetch(
        `/apps/arbeitszeitcheck/api/compliance/check-rest-period?` +
        `userId=${encodeURIComponent(userId)}&` +
        `startTime=${encodeURIComponent(startDateTime.toISOString())}` +
        (excludeEntryId ? `&excludeEntryId=${excludeEntryId}` : ''),
        {
          method: 'GET',
          headers: {
            'Content-Type': 'application/json'
          }
        }
      );

      if (!response.ok) {
        // If API not available, return valid (backend will check on save)
        return { valid: true, message: null };
      }

      const data = await response.json();
      return {
        valid: data.valid || false,
        message: data.message || null,
        earliestStartTime: data.earliestStartTime ? new Date(data.earliestStartTime) : null
      };
    } catch (error) {
      // On error, allow but warn (backend will validate on save)
      console.warn('Rest period check failed:', error);
      return { valid: true, message: null };
    }
  },

  /**
   * Check for overlapping entries
   */
  async checkOverlappingEntries(userId, startDateTime, endDateTime, excludeEntryId = null) {
    const _l10n = window.ArbeitszeitCheck?.l10n || {};
    
    try {
      const response = await fetch(
        `/apps/arbeitszeitcheck/api/time-entries/check-overlap?` +
        `userId=${encodeURIComponent(userId)}&` +
        `startTime=${encodeURIComponent(startDateTime.toISOString())}&` +
        `endTime=${encodeURIComponent(endDateTime.toISOString())}` +
        (excludeEntryId ? `&excludeEntryId=${excludeEntryId}` : ''),
        {
          method: 'GET',
          headers: {
            'Content-Type': 'application/json'
          }
        }
      );

      if (!response.ok) {
        return { hasOverlap: false, entries: [] };
      }

      const data = await response.json();
      return {
        hasOverlap: data.hasOverlap || false,
        entries: data.entries || []
      };
    } catch (error) {
      console.warn('Overlap check failed:', error);
      return { hasOverlap: false, entries: [] };
    }
  },

  /**
   * Calculate required break time based on working hours (ArbZG §4)
   */
  calculateRequiredBreakTime(workingHours) {
    if (workingHours <= 6) {
      return 0; // No break required
    } else if (workingHours <= 9) {
      return 0.5; // 30 minutes
    } else {
      return 0.75; // 45 minutes
    }
  },

  /**
   * Validate break times
   */
  validateBreak(breakStartTime, breakEndTime, workStartDateTime, workEndDateTime, _index = 0) {
    const errors = [];
    const l10n = window.ArbeitszeitCheck?.l10n || {};
    const minBreakDurationMs = 15 * 60 * 1000; // 15 minutes

    // If both empty, skip (optional field)
    if (!breakStartTime && !breakEndTime) {
      return { valid: true, errors: [], duration: 0 };
    }

    // If only one filled, require both
    if (!breakStartTime || !breakEndTime) {
      return {
        valid: false,
        errors: [l10n.breakBothRequired || t('Both break start and end times are required if you enter a break.')],
        duration: 0
      };
    }

    const breakStart = new Date(breakStartTime);
    let breakEnd = new Date(breakEndTime);

    // Handle break spanning midnight
    if (breakEnd < breakStart) {
      breakEnd.setDate(breakEnd.getDate() + 1);
    }

    // Validate break is within work period
    const workStart = new Date(workStartDateTime);
    let workEnd = new Date(workEndDateTime);
    if (workEnd < workStart) {
      workEnd.setDate(workEnd.getDate() + 1);
    }

    if (breakStart < workStart || breakEnd > workEnd) {
      errors.push(l10n.breakOutsideWorkPeriod || t('Break must be completely within your work period. Please adjust the break times.'));
    }

    // Validate break order
    if (breakEnd <= breakStart) {
      errors.push(l10n.breakEndBeforeStart || t('Break end time must be after break start time.'));
    }

    // Validate minimum duration (15 minutes - ArbZG §4)
    const breakDurationMs = breakEnd - breakStart;
    if (breakDurationMs < minBreakDurationMs) {
      errors.push(
        l10n.breakTooShort ||
        t('Break must be at least 15 minutes long (ArbZG §4). Your break is {minutes} minutes.', { minutes: String(Math.round(breakDurationMs / 60000)) })
      );
    }

    const breakDurationHours = breakDurationMs / (1000 * 60 * 60);

    if (errors.length > 0) {
      return { valid: false, errors, duration: breakDurationHours };
    }

    return { valid: true, errors: [], duration: breakDurationHours };
  },

  /**
   * Validate multiple breaks don't overlap
   */
  validateBreaksNoOverlap(breaks) {
    const errors = [];
    const l10n = window.ArbeitszeitCheck?.l10n || {};
    
    for (let i = 0; i < breaks.length; i++) {
      for (let j = i + 1; j < breaks.length; j++) {
        const break1 = breaks[i];
        const break2 = breaks[j];
        
        if (!break1.start || !break1.end || !break2.start || !break2.end) {
          continue; // Skip incomplete breaks
        }

        const b1Start = new Date(break1.start);
        let b1End = new Date(break1.end);
        if (b1End < b1Start) b1End.setDate(b1End.getDate() + 1);

        const b2Start = new Date(break2.start);
        let b2End = new Date(break2.end);
        if (b2End < b2Start) b2End.setDate(b2End.getDate() + 1);

        // Check for overlap
        if ((b1Start < b2End && b1End > b2Start)) {
          errors.push(
            l10n.breaksOverlap ||
            t('Break {a} and Break {b} overlap. Please adjust the break times so they do not overlap.', { a: String(i + 1), b: String(j + 1) })
          );
        }
      }
    }

    return {
      valid: errors.length === 0,
      errors
    };
  }
};

// Export for use in other modules
if (typeof window !== 'undefined') {
  window.ArbeitszeitCheckValidation = ArbeitszeitCheckValidation;
  // Also add to ArbeitszeitCheck namespace for consistency
  if (!window.ArbeitszeitCheck) {
    window.ArbeitszeitCheck = {};
  }
  window.ArbeitszeitCheck.Validation = ArbeitszeitCheckValidation;
}
