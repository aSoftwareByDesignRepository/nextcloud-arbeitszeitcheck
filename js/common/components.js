/**
 * Reusable JavaScript Components for ArbeitszeitCheck App
 * Provides modal, toast, and other interactive component functionality
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

const ArbeitszeitCheckComponents = {
  /**
   * Initialize all components
   */
  init() {
    this.initModals();
    this.initToasts();
  },

  // ===== MODAL COMPONENTS =====

  /**
   * Initialize modal functionality
   */
  initModals() {
    const modalTriggers = document.querySelectorAll('[data-modal]');
    
    modalTriggers.forEach(trigger => {
      trigger.addEventListener('click', (e) => {
        e.preventDefault();
        const modalId = trigger.dataset.modal;
        this.openModal(modalId);
      });
    });

    // Close modals on backdrop click
    document.addEventListener('click', (e) => {
      if (e.target.classList.contains('modal-backdrop')) {
        const modal = e.target.querySelector('.modal');
        if (modal) {
          this.closeModal(modal);
        }
      }
    });

    // Close modals on escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        const openModal = document.querySelector('.modal-backdrop .modal');
        if (openModal) {
          this.closeModal(openModal);
        }
      }
    });

    // Close buttons
    document.querySelectorAll('.modal-close').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const modal = btn.closest('.modal');
        if (modal) {
          this.closeModal(modal);
        }
      });
    });
  },

  /**
   * Open modal by ID
   */
  openModal(modalId) {
    const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
    if (!modal) {
      console.warn('Modal not found:', modalId);
      return;
    }

    // Create backdrop if it doesn't exist
    let backdrop = modal.closest('.modal-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop';
      backdrop.setAttribute('aria-hidden', 'true');
      document.body.appendChild(backdrop);
      // Move modal into backdrop (if it's already in the DOM)
      if (modal.parentNode) {
        modal.parentNode.removeChild(modal);
      }
      backdrop.appendChild(modal);
    }

    // Show modal
    backdrop.style.display = 'flex';
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    backdrop.setAttribute('aria-hidden', 'false');

    // Lock body scroll
    document.body.style.overflow = 'hidden';

    // Focus first focusable element (with slight delay to ensure modal is visible)
    setTimeout(() => {
      this.focusFirstElement(modal);
    }, 50);

    // Dispatch event
    window.dispatchEvent(new CustomEvent('modal-open', {
      detail: { modalId: modal.id || modalId, modal }
    }));
  },

  /**
   * Close modal
   */
  closeModal(modal) {
    if (!modal) return;

    // Handle both ID string and element
    const modalElement = typeof modal === 'string' ? document.getElementById(modal) : modal;
    if (!modalElement) {
      console.warn('Modal element not found:', modal);
      return;
    }

    const backdrop = modalElement.closest('.modal-backdrop');
    if (!backdrop) {
      // If no backdrop, just hide the modal
      modalElement.style.display = 'none';
      modalElement.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      return;
    }

    // Hide modal
    modalElement.style.display = 'none';
    modalElement.setAttribute('aria-hidden', 'true');
    backdrop.style.display = 'none';
    backdrop.setAttribute('aria-hidden', 'true');

    // Unlock body scroll
    document.body.style.overflow = '';

    // Remove backdrop and modal
    setTimeout(() => {
      if (backdrop.parentNode) {
        backdrop.remove();
      }
      // Also remove modal if it's still in the DOM
      if (modalElement.parentNode) {
        modalElement.parentNode.removeChild(modalElement);
      }
    }, 300); // Wait for animation

    // Dispatch event
    window.dispatchEvent(new CustomEvent('modal-close', {
      detail: { modalId: modalElement.id, modal: modalElement }
    }));
  },

  /**
   * Create modal dynamically
   */
  createModal(options = {}) {
    const {
      id = `modal-${Date.now()}`,
      title = '',
      content = '',
      size = 'md',
      closable = true,
      onClose = null
    } = options;

    const modal = document.createElement('div');
    modal.className = `modal modal--${size}`;
    modal.id = id;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-hidden', 'true');
    modal.style.display = 'none';

    const closeLabel = (typeof window !== 'undefined' && window.t) 
      ? window.t('arbeitszeitcheck', 'Close') 
      : 'Close';
    
    modal.innerHTML = `
      <div class="modal-header">
        <h2 class="modal-title">${this._escapeHtml(title)}</h2>
        ${closable ? `<button type="button" class="modal-close" aria-label="${this._escapeHtml(closeLabel)}">&times;</button>` : ''}
      </div>
      <div class="modal-body">
        ${content}
      </div>
    `;

    // Add event listeners
    if (closable) {
      const closeBtn = modal.querySelector('.modal-close');
      closeBtn.addEventListener('click', () => {
        this.closeModal(modal);
        if (onClose) onClose();
      });
    }

    document.body.appendChild(modal);
    return modal;
  },

  // ===== TOAST COMPONENTS =====

  /**
   * Initialize toast functionality
   */
  initToasts() {
    // Create toast container if it doesn't exist
    if (!document.getElementById('toast-container')) {
      const container = document.createElement('div');
      container.id = 'toast-container';
      container.className = 'toast-container';
      document.body.appendChild(container);
    }
  },

  /**
   * Show toast notification
   */
  showToast(options = {}) {
    const {
      type = 'info',
      message = '',
      duration = 5000,
      title = null
    } = options;

    const container = document.getElementById('toast-container') || document.body;
    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    toast.setAttribute('role', 'alert');

    const icon = this.getToastIcon(options.type || type);
    const closeLabel = (typeof window !== 'undefined' && window.t) 
      ? window.t('arbeitszeitcheck', 'Close') 
      : 'Close';
    
    toast.innerHTML = `
      <div class="toast-icon">${icon}</div>
      <div class="toast-content">
        ${title ? `<div class="toast-title">${title}</div>` : ''}
        <div class="toast-message">${message}</div>
      </div>
      <button type="button" class="toast-close" aria-label="${closeLabel}">&times;</button>
    `;

    container.appendChild(toast);

    // Show toast with animation
    setTimeout(() => {
      toast.classList.add('toast--show');
    }, 10);

    // Close button
    const closeBtn = toast.querySelector('.toast-close');
    closeBtn.addEventListener('click', () => {
      this.hideToast(toast);
    });

    // Auto-dismiss
    if (duration > 0) {
      setTimeout(() => {
        this.hideToast(toast);
      }, duration);
    }

    return toast;
  },

  /**
   * Hide toast
   */
  hideToast(toast) {
    toast.classList.remove('toast--show');
    setTimeout(() => {
      if (toast.parentNode) {
        toast.remove();
      }
    }, 300);
  },

  /**
   * Get toast icon
   */
  getToastIcon(type) {
    const icons = {
      success: '✓',
      error: '✗',
      warning: '⚠',
      info: 'ℹ'
    };
    return icons[type] || icons.info;
  },

  // ===== CONFIRM DIALOG =====

  /**
   * Show an accessible confirmation dialog and return a Promise that resolves
   * to true (confirmed) or false (cancelled/dismissed).
   *
   * Replaces native window.confirm() which has no styling control and limited
   * accessibility support (WCAG 4.1.2, 2.1.1).
   *
   * @param {Object} options
   * @param {string} options.title       - Dialog heading
   * @param {string} options.message     - Dialog body text (plain text, not HTML)
   * @param {string} [options.confirmLabel] - Confirm button label (default: "Confirm")
   * @param {string} [options.cancelLabel]  - Cancel button label (default: "Cancel")
   * @param {string} [options.variant]      - "danger" | "warning" | "info" (default: "info")
   * @returns {Promise<boolean>}
   */
  showConfirmDialog(options = {}) {
    const {
      title = '',
      message = '',
      confirmLabel = (window.t ? window.t('arbeitszeitcheck', 'Confirm') : 'Confirm'),
      cancelLabel  = (window.t ? window.t('arbeitszeitcheck', 'Cancel') : 'Cancel'),
      variant = 'info'
    } = options;

    return new Promise((resolve) => {
      const dialogId = `confirm-dialog-${Date.now()}`;

      // Build dialog element
      const backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop';
      backdrop.setAttribute('role', 'none');

      const dialog = document.createElement('div');
      dialog.className = 'modal modal--sm confirm-dialog';
      dialog.id = dialogId;
      dialog.setAttribute('role', 'alertdialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.setAttribute('aria-labelledby', `${dialogId}-title`);
      dialog.setAttribute('aria-describedby', `${dialogId}-message`);

      const variantIconMap = { danger: '⚠️', warning: '⚠️', info: 'ℹ️' };
      const icon = variantIconMap[variant] || variantIconMap.info;

      dialog.innerHTML = `
        <div class="modal-header">
          <span class="confirm-dialog__icon" aria-hidden="true">${icon}</span>
          <h2 class="modal-title" id="${dialogId}-title">${this._escapeHtml(title)}</h2>
        </div>
        <div class="modal-body">
          <p class="confirm-dialog__message" id="${dialogId}-message">${this._escapeHtml(message)}</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn--secondary confirm-dialog__cancel">${this._escapeHtml(cancelLabel)}</button>
          <button type="button" class="btn btn--${variant === 'danger' ? 'danger' : 'primary'} confirm-dialog__confirm">${this._escapeHtml(confirmLabel)}</button>
        </div>
      `;

      backdrop.appendChild(dialog);
      document.body.appendChild(backdrop);
      backdrop.style.display = 'flex';

      // Lock scroll
      document.body.style.overflow = 'hidden';

      // Store element that had focus before the dialog opened
      const previouslyFocused = document.activeElement;

      // Focus the cancel button by default (safer: default action is "do nothing")
      setTimeout(() => {
        const cancelBtn = dialog.querySelector('.confirm-dialog__cancel');
        if (cancelBtn) cancelBtn.focus();
      }, 50);

      const cleanup = (result) => {
        backdrop.remove();
        document.body.style.overflow = '';
        if (previouslyFocused && previouslyFocused.focus) {
          previouslyFocused.focus();
        }
        resolve(result);
      };

      dialog.querySelector('.confirm-dialog__confirm').addEventListener('click', () => cleanup(true));
      dialog.querySelector('.confirm-dialog__cancel').addEventListener('click', () => cleanup(false));

      // Escape key cancels
      const keyHandler = (e) => {
        if (e.key === 'Escape') {
          document.removeEventListener('keydown', keyHandler);
          cleanup(false);
        }
      };
      document.addEventListener('keydown', keyHandler);

      // Backdrop click cancels
      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) {
          document.removeEventListener('keydown', keyHandler);
          cleanup(false);
        }
      });

      // Trap focus inside dialog
      dialog.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        const focusable = Array.from(
          dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
        ).filter((el) => !el.disabled);
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey) {
          if (document.activeElement === first) { e.preventDefault(); last.focus(); }
        } else {
          if (document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      });
    });
  },

  /**
   * Escape HTML special characters to prevent XSS when inserting into innerHTML.
   */
  _escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  },

  // ===== UTILITY FUNCTIONS =====

  /**
   * Focus first focusable element
   */
  focusFirstElement(container) {
    const focusableElements = container.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    
    if (focusableElements.length > 0) {
      focusableElements[0].focus();
    }
  }
};

// Export for use in other modules
if (typeof window !== 'undefined') {
  window.ArbeitszeitCheckComponents = ArbeitszeitCheckComponents;
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    ArbeitszeitCheckComponents.init();
  });
} else {
  ArbeitszeitCheckComponents.init();
}
