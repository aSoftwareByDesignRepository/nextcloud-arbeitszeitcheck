/**
 * General Utility Functions for ArbeitszeitCheck App
 * Provides common utility functions used throughout the application
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

const ArbeitszeitCheckUtils = {
  // ===== DOM UTILITIES =====

  /**
   * Get element by selector
   */
  $(selector, context = document) {
    return context.querySelector(selector);
  },

  /**
   * Get all elements by selector
   */
  $$(selector, context = document) {
    return context.querySelectorAll(selector);
  },

  /**
   * Create element with attributes.
   * SECURITY: When using innerHTML, only pass pre-escaped or trusted markup.
   * For user/API data use textContent or escapeHtml() first.
   */
  createElement(tag, attributes = {}, content = '') {
    const element = document.createElement(tag);
    
    Object.entries(attributes).forEach(([key, value]) => {
      if (key === 'className') {
        element.className = value;
      } else if (key === 'textContent') {
        element.textContent = value;
      } else if (key === 'innerHTML') {
        element.innerHTML = value;
      } else {
        element.setAttribute(key, value);
      }
    });
    
    if (content) {
      element.textContent = content;
    }
    
    return element;
  },

  /**
   * Add event listener with options
   */
  on(element, event, handler, options = {}) {
    if (typeof element === 'string') {
      element = this.$(element);
    }
    
    if (element) {
      element.addEventListener(event, handler, options);
    }
  },

  /**
   * Remove event listener
   */
  off(element, event, handler, options = {}) {
    if (typeof element === 'string') {
      element = this.$(element);
    }
    
    if (element) {
      element.removeEventListener(event, handler, options);
    }
  },

  /**
   * Toggle element visibility
   */
  toggle(element, show = null) {
    if (typeof element === 'string') {
      element = this.$(element);
    }
    
    if (!element) return;
    
    if (show === null) {
      show = element.style.display === 'none';
    }
    
    element.style.display = show ? '' : 'none';
  },

  /**
   * Show element
   */
  show(element) {
    this.toggle(element, true);
  },

  /**
   * Hide element
   */
  hide(element) {
    this.toggle(element, false);
  },

  /**
   * Add class to element
   */
  addClass(element, className) {
    if (typeof element === 'string') {
      element = this.$(element);
    }
    
    if (element) {
      element.classList.add(className);
    }
  },

  /**
   * Remove class from element
   */
  removeClass(element, className) {
    if (typeof element === 'string') {
      element = this.$(element);
    }
    
    if (element) {
      element.classList.remove(className);
    }
  },

  /**
   * Toggle class on element
   */
  toggleClass(element, className, force = null) {
    if (typeof element === 'string') {
      element = this.$(element);
    }
    
    if (element) {
      element.classList.toggle(className, force);
    }
  },

  /**
   * Check if element has class
   */
  hasClass(element, className) {
    if (typeof element === 'string') {
      element = this.$(element);
    }
    
    return element ? element.classList.contains(className) : false;
  },

  // ===== AJAX UTILITIES =====

  /**
   * Make AJAX request using Nextcloud's OC.generateUrl
   */
  ajax(url, options = {}) {
    const {
      method = 'GET',
      data = null,
      headers = {},
      onSuccess = null,
      onError = null
    } = options;

    const requestToken = (typeof OC !== 'undefined' && OC.requestToken) ||
      (document.querySelector('head') && document.querySelector('head').getAttribute('data-requesttoken')) || '';
    const defaultHeaders = {
      'Content-Type': 'application/json',
      'requesttoken': requestToken
    };

    const config = {
      method: method,
      headers: { ...defaultHeaders, ...headers }
    };

    if (data && method !== 'GET') {
      if (config.headers['Content-Type'] === 'application/json') {
        config.body = JSON.stringify(data);
      } else {
        config.body = data;
      }
    }

    const resolvedUrl = (() => {
      if (typeof url !== 'string') {
        return url;
      }

      // Keep fully-qualified and already-generated Nextcloud paths unchanged.
      if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('//') || url.startsWith('/index.php/')) {
        return url;
      }

      if (typeof OC !== 'undefined' && typeof OC.generateUrl === 'function') {
        return OC.generateUrl(url);
      }

      return url;
    })();

    return fetch(resolvedUrl, config)
      .then(async response => {
        const data = await response.json().catch(() => null);
        if (!response.ok) {
          const err = new Error(data?.error || `HTTP error! status: ${response.status}`);
          err.error = data?.error || err.message;
          err.status = response.status;
          err.data = data;
          throw err;
        }
        return data;
      })
      .then(data => {
        if (onSuccess) {
          onSuccess(data);
        }
        return data;
      })
      .catch(error => {
        if (onError) {
          onError(error);
          // Most call sites rely on callbacks and do not attach .catch().
          // Avoid unhandled promise rejections once the error callback ran.
          return null;
        }
        throw error;
      });
  },

  /**
   * Serialize form data
   */
  serializeForm(form) {
    if (typeof form === 'string') {
      form = this.$(form);
    }
    
    if (!form || form.tagName !== 'FORM') {
      return {};
    }

    const formData = new FormData(form);
    const data = {};
    
    for (const [key, value] of formData.entries()) {
      if (data[key]) {
        // Handle multiple values (e.g., checkboxes)
        if (Array.isArray(data[key])) {
          data[key].push(value);
        } else {
          data[key] = [data[key], value];
        }
      } else {
        data[key] = value;
      }
    }
    
    return data;
  },

  // ===== DATE UTILITIES =====

  /**
   * Format time in 24-hour format (HH:MM or HH:MM:SS)
   * Always returns 24-hour format regardless of locale settings
   * 
   * @param {Date|string} date - Date object or date string
   * @param {boolean} includeSeconds - Whether to include seconds in output (default: false)
   * @returns {string} Time in 24-hour format (HH:MM or HH:MM:SS)
   */
  formatTime(date, includeSeconds = false) {
    const d = new Date(date);
    
    // Validate date
    if (isNaN(d.getTime())) {
      return '00:00' + (includeSeconds ? ':00' : '');
    }
    
    const hours = String(d.getHours()).padStart(2, '0');
    const minutes = String(d.getMinutes()).padStart(2, '0');
    
    if (includeSeconds) {
      const seconds = String(d.getSeconds()).padStart(2, '0');
      return `${hours}:${minutes}:${seconds}`;
    }
    
    return `${hours}:${minutes}`;
  },

  /**
   * Format date
   * Default format is DD.MM.YYYY for European users
   * Time is always formatted in 24-hour format (HH:mm)
   */
  formatDate(date, format = 'DD.MM.YYYY') {
    const d = new Date(date);
    
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const hours = String(d.getHours()).padStart(2, '0');
    const minutes = String(d.getMinutes()).padStart(2, '0');
    const seconds = String(d.getSeconds()).padStart(2, '0');
    
    return format
      .replace('YYYY', year)
      .replace('MM', month)
      .replace('DD', day)
      .replace('HH', hours)
      .replace('mm', minutes)
      .replace('ss', seconds);
  },

  /**
   * Format time duration (seconds to HH:MM:SS)
   */
  formatDuration(seconds) {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
  },

  /**
   * Format hours (decimal to HH:MM)
   */
  formatHours(hours) {
    const h = Math.floor(hours);
    const m = Math.round((hours - h) * 60);
    return `${h}:${String(m).padStart(2, '0')}`;
  },

  /**
   * Get relative time (e.g., "2 hours ago")
   */
  relativeTime(date) {
    const now = new Date();
    const diff = now - new Date(date);
    const seconds = Math.floor(diff / 1000);
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    const replaceVars = (template, vars = {}) =>
      template.replace(/\{(\w+)\}/g, (_, key) => (vars && key in vars ? vars[key] : `{${key}}`));

    const tFn = (typeof window !== 'undefined' && typeof window.t === 'function')
      ? (s, vars) => window.t('arbeitszeitcheck', s, vars || {})
      : (s, vars) => replaceVars(s, vars);

    const nFn = (typeof window !== 'undefined' && typeof window.n === 'function')
      ? (singular, plural, count, vars) => window.n('arbeitszeitcheck', singular, plural, count, vars || {})
      : (singular, plural, count, vars) => {
        const template = count === 1 ? singular : plural;
        const allVars = { count, ...(vars || {}) };
        return replaceVars(template, allVars);
      };

    if (days > 0) {
      return nFn('{count} day ago', '{count} days ago', days, { count: days });
    }
    if (hours > 0) {
      return nFn('{count} hour ago', '{count} hours ago', hours, { count: hours });
    }
    if (minutes > 0) {
      return nFn('{count} minute ago', '{count} minutes ago', minutes, { count: minutes });
    }
    return tFn('Just now');
  },

  // ===== STRING UTILITIES =====

  /**
   * Escape HTML
   */
  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  },

  /**
   * Truncate string
   */
  truncate(str, length = 50, suffix = '...') {
    if (str.length <= length) return str;
    return str.substring(0, length) + suffix;
  },

  // ===== NUMBER UTILITIES =====

  /**
   * Format number with decimals
   */
  formatNumber(num, decimals = 2) {
    return Number(num).toFixed(decimals);
  },

  /**
   * Round to decimal places
   */
  round(value, decimals = 2) {
    const factor = Math.pow(10, decimals);
    return Math.round(value * factor) / factor;
  },

  // ===== FUNCTION UTILITIES =====

  /**
   * Debounce function
   */
  debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  },

  /**
   * Throttle function
   */
  throttle(func, limit) {
    let inThrottle;
    return function() {
      const args = arguments;
      const context = this;
      if (!inThrottle) {
        func.apply(context, args);
        inThrottle = true;
        setTimeout(() => inThrottle = false, limit);
      }
    };
  },

  // ===== VALIDATION UTILITIES =====

  /**
   * Check if value is empty
   */
  isEmpty(value) {
    if (value === null || value === undefined) return true;
    if (typeof value === 'string') return value.trim() === '';
    if (Array.isArray(value)) return value.length === 0;
    if (typeof value === 'object') return Object.keys(value).length === 0;
    return false;
  },

  /**
   * Check if value is email
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
  }
};

/**
 * Initialize common l10n strings for vanilla JS pages.
 *
 * We prefer Nextcloud's client-side translation function `t()` when available,
 * but keep safe English fallbacks. Templates can still override/extend
 * `window.ArbeitszeitCheck.l10n` with page-specific strings.
 */
(function initArbeitszeitCheckL10n() {
  if (typeof window === 'undefined') return;

  window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
  window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};

  const tt = (typeof window.t === 'function')
    ? (s) => window.t('arbeitszeitcheck', s)
    : (s) => s;

  const l10n = window.ArbeitszeitCheck.l10n;

  // Generic strings used across multiple pages / components
  l10n.loading = l10n.loading || tt('Loading...');
  l10n.error = l10n.error || tt('An error occurred');
  l10n.confirmDelete = l10n.confirmDelete || tt('Are you sure you want to delete this item?');

  // Timeline / calendar shared strings
  l10n.loadingTimeline = l10n.loadingTimeline || tt('Loading timeline...');
  l10n.noTimelineData = l10n.noTimelineData || tt('No timeline data available');
  l10n.selectAtLeastOneFilter = l10n.selectAtLeastOneFilter || tt('Select at least one type to display in the timeline.');
  l10n.loadingCalendar = l10n.loadingCalendar || tt('Loading calendar...');
  l10n.noEntries = l10n.noEntries || tt('No entries for this day');

  // Calendar labels (used for period header)
  l10n.months = l10n.months || [
    tt('January'), tt('February'), tt('March'), tt('April'),
    tt('May'), tt('June'), tt('July'), tt('August'),
    tt('September'), tt('October'), tt('November'), tt('December'),
  ];
  l10n.weekdays = l10n.weekdays || [
    tt('Sunday'), tt('Monday'), tt('Tuesday'), tt('Wednesday'),
    tt('Thursday'), tt('Friday'), tt('Saturday'),
  ];
  l10n.weekdaysShort = l10n.weekdaysShort || [
    tt('Sun'), tt('Mon'), tt('Tue'), tt('Wed'),
    tt('Thu'), tt('Fri'), tt('Sat'),
  ];
})();

// Export for use in other modules
if (typeof window !== 'undefined') {
  window.ArbeitszeitCheckUtils = ArbeitszeitCheckUtils;
}
