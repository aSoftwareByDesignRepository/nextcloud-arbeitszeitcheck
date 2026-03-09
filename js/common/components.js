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
        <h2 class="modal-title">${title}</h2>
        ${closable ? `<button type="button" class="modal-close" aria-label="${closeLabel}">&times;</button>` : ''}
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
