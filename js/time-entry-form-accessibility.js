/**
 * Time Entry Form Accessibility Enhancements
 * 
 * Implements WCAG 2.1 AAA compliance features:
 * - Keyboard navigation
 * - Screen reader support
 * - Focus management
 * - Skip links
 * 
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
  'use strict';

  const TimeEntryFormAccessibility = {
    /**
     * Initialize accessibility features
     */
    init() {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => this.setup());
      } else {
        this.setup();
      }
    },

    /**
     * Setup all accessibility features
     */
    setup() {
      const form = document.getElementById('time-entry-form');
      if (!form) {
        return; // Form not on this page
      }

      this.setupSkipLink();
      this.setupKeyboardNavigation();
      this.setupScreenReaderAnnouncements();
      this.setupFocusManagement();
      this.setupLiveRegions();
      this.setupARIAUpdates();
    },

    /**
     * Setup skip link to main content
     */
    setupSkipLink() {
      const form = document.getElementById('time-entry-form');
      if (!form) return;

      // Create skip link if it doesn't exist
      let skipLink = document.getElementById('time-entry-form-skip-link');
      if (!skipLink) {
        skipLink = document.createElement('a');
        skipLink.id = 'time-entry-form-skip-link';
        skipLink.href = '#time-entry-form';
        skipLink.className = 'sr-only-focusable';
        skipLink.textContent = window.ArbeitszeitCheck?.l10n?.skipToForm || 'Skip to form';
        skipLink.setAttribute('aria-label', window.ArbeitszeitCheck?.l10n?.skipToForm || 'Skip to time entry form');
        
        // Insert at the beginning of the page
        const firstElement = document.body.firstChild;
        if (firstElement) {
          document.body.insertBefore(skipLink, firstElement);
        } else {
          document.body.appendChild(skipLink);
        }

        skipLink.addEventListener('click', (e) => {
          e.preventDefault();
          form.focus();
          form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      }
    },

    /**
     * Setup keyboard navigation enhancements
     */
    setupKeyboardNavigation() {
      const form = document.getElementById('time-entry-form');
      if (!form) return;

      // Enable arrow key navigation for time selects
      const timeSelects = form.querySelectorAll('.time-input-group select');
      timeSelects.forEach(select => {
        select.addEventListener('keydown', (e) => {
          // Allow arrow keys for navigation
          if (['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(e.key)) {
            // Native select behavior handles this
            return;
          }

          // Enter/Space to open dropdown (native behavior)
          if (e.key === 'Enter' || e.key === ' ') {
            return;
          }

          // Escape to close (if open)
          if (e.key === 'Escape') {
            select.blur();
          }
        });
      });

      // Ensure logical tab order
      const interactiveElements = form.querySelectorAll(
        'input:not([type="hidden"]), select, textarea, button, a[href]'
      );
      
      // Set tabindex if needed (but preserve natural order)
      interactiveElements.forEach((el, _index) => {
        // Only set tabindex if element doesn't naturally have one
        if (!el.hasAttribute('tabindex') && 
            (el.disabled || el.getAttribute('aria-hidden') === 'true')) {
          el.setAttribute('tabindex', '-1');
        }
      });

      // Handle Escape key to cancel/reset form
      form.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && e.target === form) {
          const cancelButton = form.querySelector('a[href*="time-entries"]');
          if (cancelButton) {
            cancelButton.focus();
          }
        }
      });
    },

    /**
     * Setup screen reader announcements
     */
    setupScreenReaderAnnouncements() {
      const form = document.getElementById('time-entry-form');
      if (!form) return;

      // Create live region for announcements
      let liveRegion = document.getElementById('time-entry-form-announcements');
      if (!liveRegion) {
        liveRegion = document.createElement('div');
        liveRegion.id = 'time-entry-form-announcements';
        liveRegion.className = 'sr-only';
        liveRegion.setAttribute('role', 'status');
        liveRegion.setAttribute('aria-live', 'polite');
        liveRegion.setAttribute('aria-atomic', 'true');
        form.appendChild(liveRegion);
      }

      // Store reference for announcements
      this.liveRegion = liveRegion;

      // Announce validation errors
      const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          if (mutation.type === 'childList') {
            mutation.addedNodes.forEach((node) => {
              if (node.nodeType === 1 && node.classList && node.classList.contains('form-error')) {
                const errorText = node.textContent.trim();
                if (errorText) {
                  this.announce(errorText, 'assertive');
                }
              }
            });
          }
        });
      });

      observer.observe(form, {
        childList: true,
        subtree: true
      });
    },

    /**
     * Announce message to screen readers
     */
    announce(message, priority = 'polite') {
      if (!this.liveRegion) return;

      const announcement = document.createElement('div');
      announcement.textContent = message;
      announcement.setAttribute('aria-live', priority);
      announcement.className = 'sr-only';
      
      this.liveRegion.appendChild(announcement);
      
      // Remove after announcement
      setTimeout(() => {
        announcement.remove();
      }, 1000);
    },

    /**
     * Setup focus management
     */
    setupFocusManagement() {
      const form = document.getElementById('time-entry-form');
      if (!form) return;

      // Focus first error field when validation fails
      form.addEventListener('invalid', (e) => {
        e.preventDefault();
        const firstError = form.querySelector(':invalid, .form-input--error');
        if (firstError) {
          firstError.focus();
          firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
          
          // Announce error
          const errorContainer = firstError.closest('.form-group')?.querySelector('.form-error-container');
          if (errorContainer) {
            const errorText = errorContainer.textContent.trim();
            if (errorText) {
              this.announce(errorText, 'assertive');
            }
          }
        }
      }, true);

      // Move focus to error messages when shown
      const formGroup = form.querySelector('.form-group');
      if (formGroup) {
        const errorObserver = new MutationObserver((mutations) => {
          mutations.forEach((mutation) => {
            if (mutation.type === 'childList') {
              mutation.addedNodes.forEach((node) => {
                if (node.nodeType === 1 && 
                    node.classList && 
                    (node.classList.contains('form-error') || node.classList.contains('form-error-container'))) {
                  // Focus will be managed by the validation system
                  // Just ensure the error is visible
                  if (!this.isElementInViewport(node)) {
                    node.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                  }
                }
              });
            }
          });
        });

        errorObserver.observe(form, {
          childList: true,
          subtree: true
        });
      }
    },

    /**
     * Setup live regions for dynamic content
     */
    setupLiveRegions() {
      const form = document.getElementById('time-entry-form');
      if (!form) return;

      // Create live region for time calculations
      let timeSummary = document.getElementById('time-summary');
      if (timeSummary && !timeSummary.getAttribute('aria-live')) {
        timeSummary.setAttribute('aria-live', 'polite');
        timeSummary.setAttribute('role', 'status');
      }
    },

    /**
     * Setup ARIA attribute updates
     */
    setupARIAUpdates() {
      const form = document.getElementById('time-entry-form');
      if (!form) return;

      // Update aria-invalid when fields become invalid
      const inputs = form.querySelectorAll('input, select, textarea');
      inputs.forEach(input => {
        input.addEventListener('invalid', () => {
          input.setAttribute('aria-invalid', 'true');
        });

        input.addEventListener('input', () => {
          // Clear invalid state when user starts typing
          if (input.getAttribute('aria-invalid') === 'true') {
            input.setAttribute('aria-invalid', 'false');
          }
        });
      });
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
    }
  };

  // Initialize when script loads
  TimeEntryFormAccessibility.init();

  // Export for use in other modules
  if (typeof window !== 'undefined') {
    window.TimeEntryFormAccessibility = TimeEntryFormAccessibility;
    if (!window.ArbeitszeitCheck) {
      window.ArbeitszeitCheck = {};
    }
    window.ArbeitszeitCheck.FormAccessibility = TimeEntryFormAccessibility;
  }
})();
