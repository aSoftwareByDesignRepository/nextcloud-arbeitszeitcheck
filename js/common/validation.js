/**
 * Form Validation Utilities for ArbeitszeitCheck App
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

const t = (s, opts) => (typeof window !== 'undefined' && window.t ? window.t('arbeitszeitcheck', s, opts || {}) : s);

function validationResolveUrl(path) {
  if (window.ArbeitszeitCheckUtils && typeof window.ArbeitszeitCheckUtils.resolveUrl === 'function') {
    return window.ArbeitszeitCheckUtils.resolveUrl(path);
  }
  if (typeof window !== 'undefined' && window.OC && typeof window.OC.generateUrl === 'function') {
    return window.OC.generateUrl(path);
  }
  return path;
}

function validationRequestToken() {
  if (window.ArbeitszeitCheckUtils && typeof window.ArbeitszeitCheckUtils.getRequestToken === 'function') {
    return window.ArbeitszeitCheckUtils.getRequestToken();
  }
  if (typeof window !== 'undefined' && window.OC && window.OC.requestToken) {
    return window.OC.requestToken;
  }
  const head = document.querySelector('head');
  return head ? (head.getAttribute('data-requesttoken') || '') : '';
}

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

    const errorIcon = (typeof window !== 'undefined' && window.AzcCatalog && typeof window.AzcCatalog.render === 'function')
      ? window.AzcCatalog.render('triangle-alert', 'form-error__icon-svg')
      : '';
    // Create error content with icon and message
    errorElement.innerHTML = `
      <span class="form-error__icon" aria-hidden="true">${errorIcon}</span>
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
        validationResolveUrl(
          `/apps/arbeitszeitcheck/api/compliance/check-rest-period?` +
          `userId=${encodeURIComponent(userId)}&` +
          `startTime=${encodeURIComponent(startDateTime.toISOString())}` +
          (excludeEntryId ? `&excludeEntryId=${encodeURIComponent(String(excludeEntryId))}` : '')
        ),
        {
          method: 'GET',
          headers: {
            'requesttoken': validationRequestToken()
          },
          credentials: 'same-origin'
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
        validationResolveUrl(
          `/apps/arbeitszeitcheck/api/time-entries/check-overlap?` +
          `userId=${encodeURIComponent(userId)}&` +
          `startTime=${encodeURIComponent(startDateTime.toISOString())}&` +
          `endTime=${encodeURIComponent(endDateTime.toISOString())}` +
          (excludeEntryId ? `&excludeEntryId=${encodeURIComponent(String(excludeEntryId))}` : '')
        ),
        {
          method: 'GET',
          headers: {
            'requesttoken': validationRequestToken()
          },
          credentials: 'same-origin'
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
   * Country-profile break tiers from bootstrap (DE: 6→30, 9→45; AT: 6→30).
   * Falls back to historic German ArbZG tiers when the payload is missing.
   * @returns {Array<{afterHours: number, breakMinutes: number}>}
   */
  getBreakTiers() {
    const raw = window.ArbeitszeitCheck?.complianceParams?.breakTiers;
    if (Array.isArray(raw) && raw.length > 0) {
      return raw
        .map((t) => ({
          afterHours: Number(t.afterHours),
          breakMinutes: Math.round(Number(t.breakMinutes)),
        }))
        .filter((t) => Number.isFinite(t.afterHours) && t.afterHours > 0 && t.breakMinutes > 0)
        .sort((a, b) => a.afterHours - b.afterHours);
    }
    return [
      { afterHours: 6, breakMinutes: 30 },
      { afterHours: 9, breakMinutes: 45 },
    ];
  },

  /**
   * Net working hours from a wall-clock span minus countable break minutes.
   * ArbZG §4 / AZG §11 / ArG Art. 15 apply to net working time, not the clock span.
   */
  netWorkingHours(grossHours, countableBreakMinutes) {
    const gross = Number(grossHours);
    const breakMinutes = Number(countableBreakMinutes);
    if (!Number.isFinite(gross) || gross <= 0) {
      return 0;
    }
    const breakHours = Number.isFinite(breakMinutes) && breakMinutes > 0 ? breakMinutes / 60 : 0;
    return Math.max(0, gross - breakHours);
  },

  /**
   * Required break minutes for a shift duration, using profile tiers
   * (highest exclusive threshold wins — same as LaborLawProfile::requiredBreakMinutes).
   * ArbZG §4 / AZG §11 / ArG Art. 15: more than afterHours, not equal to it.
   */
  calculateRequiredBreakMinutes(workingHours) {
    const hours = Number(workingHours);
    if (!Number.isFinite(hours) || hours <= 0) {
      return 0;
    }
    const tiers = this.getBreakTiers().slice().reverse(); // highest first
    for (const tier of tiers) {
      if (hours > tier.afterHours) {
        return tier.breakMinutes;
      }
    }
    return 0;
  },

  /**
   * Effective deduction convention from bootstrap complianceParams.
   * 'strict' when the key is missing or unknown (older servers) — never
   * a laxer guess.
   */
  getDeductionMode() {
    const raw = window.ArbeitszeitCheck?.complianceParams?.deductionMode;
    return raw === 'cap_overhang' ? 'cap_overhang' : 'strict';
  },

  /**
   * Client mirror of BreakPolicyResolver::minimalConsistentBreakSeconds.
   *
   * Statutory break requirements apply to the RESULTING net working time —
   * the smallest total deduction d* such that required(P − d*) ≤ d* where
   * P is the gross presence. Evaluating the pre-decision duration instead
   * is the over-deduction this fixes (≈9h day → 45 min demanded although
   * the post-deduction net only requires 30 min).
   *
   * - strict:       d* = min { t ∈ {0} ∪ tier amounts : R(P − t) ≤ t }
   * - cap_overhang: d iterates d := min(r, P − T(r)) from the recorded
   *   break total until consistent — landing the net on the producing
   *   threshold so only the overhang is recorded.
   *
   * All math runs in whole minutes against minute-precision thresholds —
   * form inputs are HH:MM so sub-minute overhang never occurs here; the
   * server rounds the capped insert up to whole minutes where it does.
   *
   * @param {number} presenceMinutes Gross presence (merged work span)
   * @param {number} existingBreakMinutes Already-recorded break minutes
   * @param {Array<{afterHours:number,breakMinutes:number}>|null} tiers
   *        null/undefined uses bootstrap complianceParams tiers.
   * @param {string} mode 'strict' | 'cap_overhang'
   * @returns {{targetMinutes:number, insertMinutes:number, capped:boolean}}
   */
  minimalConsistentBreakMinutes(presenceMinutes, existingBreakMinutes, tiers, mode) {
    const presence = Number(presenceMinutes);
    const existing = Math.max(0, Number(existingBreakMinutes) || 0);
    const empty = { targetMinutes: 0, insertMinutes: 0, capped: false };
    if (!Number.isFinite(presence) || presence <= 0) {
      return empty;
    }

    const tierList = (Array.isArray(tiers) && tiers.length > 0 ? tiers : this.getBreakTiers())
      .map((t) => ({
        thresholdMinutes: Math.round(Number(t.afterHours) * 60),
        breakMinutes: Math.round(Number(t.breakMinutes)),
      }))
      .filter((t) => Number.isFinite(t.thresholdMinutes) && t.thresholdMinutes > 0
        && Number.isFinite(t.breakMinutes) && t.breakMinutes > 0)
      .sort((a, b) => a.thresholdMinutes - b.thresholdMinutes);

    const requiredAt = (netMinutes) => {
      let required = 0;
      for (const tier of tierList) {
        if (netMinutes > tier.thresholdMinutes && tier.breakMinutes > required) {
          required = tier.breakMinutes;
        }
      }
      return required;
    };

    // Strict fixpoint: smallest candidate satisfying R(P − t) ≤ t.
    const candidates = [...new Set([0, ...tierList.map((t) => t.breakMinutes)])].sort((a, b) => a - b);
    let strictTarget = candidates[candidates.length - 1] || 0;
    for (const candidate of candidates) {
      if (requiredAt(Math.max(0, presence - candidate)) <= candidate) {
        strictTarget = candidate;
        break;
      }
    }

    if (mode !== 'cap_overhang') {
      return {
        targetMinutes: strictTarget,
        insertMinutes: Math.max(0, strictTarget - existing),
        capped: false,
      };
    }

    // Cap-overhang iteration — terminates because every producer threshold
    // is strictly below the current net (T < P − d ⟹ P − T > d).
    let deduction = existing;
    for (let guard = 0; guard < 16; guard++) {
      const net = Math.max(0, presence - deduction);
      let required = 0;
      let producer = 0;
      for (const tier of tierList) {
        if (net > tier.thresholdMinutes && tier.breakMinutes >= required) {
          if (tier.breakMinutes > required || producer === 0 || tier.thresholdMinutes < producer) {
            producer = tier.thresholdMinutes;
          }
          required = tier.breakMinutes;
        }
      }
      if (required <= deduction) {
        break;
      }
      const next = Math.min(required, presence - producer);
      if (next <= deduction) {
        break; // defensive — unreachable for a triggered producer
      }
      deduction = next;
    }
    const insert = Math.max(0, deduction - existing);
    return {
      targetMinutes: existing + insert,
      insertMinutes: insert,
      capped: insert > 0,
    };
  },

  /**
   * @deprecated Prefer calculateRequiredBreakMinutes(); kept for callers that expect hours.
   */
  calculateRequiredBreakTime(workingHours) {
    return this.calculateRequiredBreakMinutes(workingHours) / 60;
  },

  /**
   * Validate break times
   */
  getMinBreakMinutes() {
    const raw = Number(window.ArbeitszeitCheck?.complianceParams?.minBreakMinutes);
    return Number.isFinite(raw) && raw > 0 ? Math.round(raw) : 15;
  },

  /**
   * AZG §11 allowed split patterns from bootstrap, or null for sum-only (DE/CH).
   * Mirrors server LaborLawProfile::allowedBreakSplitPatterns.
   * @returns {number[][]|null}
   */
  getAllowedBreakSplitPatterns() {
    const raw = window.ArbeitszeitCheck?.complianceParams?.allowedBreakSplitPatterns;
    if (raw == null) {
      return null;
    }
    if (!Array.isArray(raw)) {
      return null;
    }
    const patterns = raw
      .filter((p) => Array.isArray(p) && p.length > 0)
      .map((p) => p.map((m) => Math.round(Number(m))).filter((m) => Number.isFinite(m) && m > 0));
    return patterns.length > 0 ? patterns : null;
  },

  /**
   * Client mirror of BreakSplitValidator::meetsRequirement (server remains authoritative).
   * @param {number[]} portionMinutes
   * @param {number} requiredMinutes
   * @param {number[][]|null|undefined} allowedPatterns
   * @returns {boolean}
   */
  meetsBreakSplitRequirement(portionMinutes, requiredMinutes, allowedPatterns) {
    const required = Math.round(Number(requiredMinutes));
    if (!Number.isFinite(required) || required <= 0) {
      return true;
    }

    const portions = (Array.isArray(portionMinutes) ? portionMinutes : [])
      .map((m) => Math.max(0, Math.round(Number(m))))
      .filter((m) => Number.isFinite(m));

    let total = 0;
    for (const minutes of portions) {
      total += minutes;
    }
    if (total < required) {
      return false;
    }

    if (allowedPatterns == null) {
      return true;
    }

    for (const minutes of portions) {
      if (minutes >= required) {
        return true;
      }
    }

    for (const pattern of allowedPatterns) {
      if (!Array.isArray(pattern) || pattern.length === 0) {
        continue;
      }
      if (this.portionsMatchBreakPattern(portions, pattern)) {
        return true;
      }
    }

    return false;
  },

  /**
   * @param {number[]} portionMinutes
   * @param {number[]} patternMins
   * @returns {boolean}
   */
  portionsMatchBreakPattern(portionMinutes, patternMins) {
    if (portionMinutes.length !== patternMins.length) {
      return false;
    }
    const sortedPortions = portionMinutes.slice().sort((a, b) => b - a);
    const sortedPattern = patternMins.map((m) => Math.round(Number(m))).sort((a, b) => b - a);
    for (let i = 0; i < sortedPattern.length; i++) {
      if (sortedPortions[i] < sortedPattern[i]) {
        return false;
      }
    }
    return true;
  },

  /**
   * @param {number[]} portionMinutes Whole-minute break lengths
   * @param {number} workingHours Net working hours (breaks already subtracted)
   * @returns {{ ok: boolean, requiredMinutes: number, splitInvalid: boolean, message: string|null }}
   */
  evaluateBreakCompliance(portionMinutes, workingHours) {
    const requiredMinutes = this.calculateRequiredBreakMinutes(workingHours);
    if (requiredMinutes <= 0) {
      return { ok: true, requiredMinutes: 0, splitInvalid: false, message: null };
    }
    const allowed = this.getAllowedBreakSplitPatterns();
    const ok = this.meetsBreakSplitRequirement(portionMinutes, requiredMinutes, allowed);
    if (ok) {
      return { ok: true, requiredMinutes, splitInvalid: false, message: null };
    }
    const l10n = window.ArbeitszeitCheck?.l10n || {};
    const total = (Array.isArray(portionMinutes) ? portionMinutes : [])
      .reduce((sum, m) => sum + Math.max(0, Math.round(Number(m) || 0)), 0);
    const splitInvalid = allowed != null && total >= requiredMinutes;
    const message = splitInvalid
      ? (l10n.complianceBreakSplitInvalid
        || 'Breaks must be one continuous block of the required length, or 2×15 minutes, or 3×10 minutes.')
      : (l10n.complianceBreakNotMet || 'Break requirement not met');
    return { ok: false, requiredMinutes, splitInvalid, message: String(message) };
  },

  validateBreak(breakStartTime, breakEndTime, workStartDateTime, workEndDateTime, _index = 0) {
    const errors = [];
    const l10n = window.ArbeitszeitCheck?.l10n || {};
    const minBreakMinutes = this.getMinBreakMinutes();
    const minBreakDurationMs = minBreakMinutes * 60 * 1000;

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

    // Validate minimum duration (profile floor: DE/CH 15, AT 10)
    const breakDurationMs = breakEnd - breakStart;
    if (breakDurationMs < minBreakDurationMs) {
      errors.push(
        (l10n.breakTooShortProfile && t(l10n.breakTooShortProfile, { min: String(minBreakMinutes), minutes: String(Math.round(breakDurationMs / 60000)) })) ||
        t('Break must be at least {min} minutes long to count toward the legal break requirement. Your break is {minutes} minutes.', { min: String(minBreakMinutes), minutes: String(Math.round(breakDurationMs / 60000)) })
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
