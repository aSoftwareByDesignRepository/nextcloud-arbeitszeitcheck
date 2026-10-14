/**
 * Reusable JavaScript Components for ArbeitszeitCheck App
 * Provides modal, toast, and other interactive component functionality
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

const ArbeitszeitCheckComponents = {
  _modalLockDepth: 0,
  _modalEscHandlerBound: false,

  /**
   * Lock nav/main for inline overlays (e.g. calendar day panel). Live regions stay active.
   */
  lockBackground() {
    this._lockPageBehindModal();
  },

  unlockBackground() {
    this._unlockPageBehindModal();
  },

  /**
   * Initialize all components
   */
  init() {
    this.initModals();
    this.initToasts();
    this.relocatePageActions();
  },

  // ===== MODAL COMPONENTS =====

  _getAppContent() {
    return document.getElementById('app-content');
  },

  _getModalInertTargets() {
    const targets = [];
    const header = document.getElementById('header');
    const nav = document.getElementById('app-navigation');
    const main = document.getElementById('azc-main-content');
    const shell = document.getElementById('app-content-wrapper');
    if (header) {
      targets.push(header);
    }
    if (nav) {
      targets.push(nav);
    }
    if (main) {
      targets.push(main);
    } else if (shell) {
      targets.push(shell);
    }
    return targets;
  },

  _lockPageBehindModal() {
    if (++this._modalLockDepth === 1) {
      document.body.style.overflow = 'hidden';
      this._getModalInertTargets().forEach((el) => {
        el.setAttribute('inert', '');
      });
    }
  },

  _unlockPageBehindModal() {
    if (this._modalLockDepth <= 0) {
      this._modalLockDepth = 0;
      return;
    }
    if (--this._modalLockDepth === 0) {
      document.body.style.overflow = '';
      this._getModalInertTargets().forEach((el) => {
        el.removeAttribute('inert');
      });
    }
  },

  _getVisibleModalBackdrops() {
    return Array.from(document.querySelectorAll('.modal-backdrop')).filter((backdrop) => {
      if (backdrop.style.display === 'flex') {
        return true;
      }
      const modal = backdrop.querySelector('.modal');
      if (modal && modal.getAttribute('aria-hidden') === 'false') {
        return true;
      }
      const style = window.getComputedStyle(backdrop);
      return style.display !== 'none' && style.visibility !== 'hidden';
    });
  },

  _getTopmostBackdropModal() {
    const backdrops = this._getVisibleModalBackdrops();
    if (backdrops.length === 0) {
      return null;
    }
    const topBackdrop = backdrops[backdrops.length - 1];
    return topBackdrop.querySelector('.modal');
  },

  _bindFocusTrap(modal) {
    if (!modal || modal.dataset.azcFocusTrapBound === '1') {
      return;
    }
    const handler = (e) => {
      if (e.key !== 'Tab') {
        return;
      }
      const inBackdrop = modal.closest('.modal-backdrop');
      const standalone = modal.dataset.azcFocusTrapStandalone === '1';
      if (!inBackdrop && !standalone) {
        return;
      }
      const focusable = Array.from(
        modal.querySelectorAll(
          'button:not([disabled]), [href], input:not([disabled]):not([hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
      ).filter((el) => el.offsetParent !== null || el === document.activeElement);
      if (focusable.length === 0) {
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (e.shiftKey) {
        if (document.activeElement === first || !modal.contains(document.activeElement)) {
          e.preventDefault();
          last.focus();
        }
      } else if (document.activeElement === last || !modal.contains(document.activeElement)) {
        e.preventDefault();
        first.focus();
      }
    };
    modal.addEventListener('keydown', handler);
    modal.dataset.azcFocusTrapBound = '1';
    modal._azcFocusTrapHandler = handler;
  },

  _unbindFocusTrap(modal) {
    if (!modal || modal.dataset.azcFocusTrapBound !== '1' || !modal._azcFocusTrapHandler) {
      return;
    }
    modal.removeEventListener('keydown', modal._azcFocusTrapHandler);
    delete modal._azcFocusTrapHandler;
    delete modal.dataset.azcFocusTrapBound;
  },

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

    // Close modals on backdrop click (topmost only)
    document.addEventListener('click', (e) => {
      if (!e.target.classList.contains('modal-backdrop')) {
        return;
      }
      const backdrops = this._getVisibleModalBackdrops();
      if (backdrops.length === 0 || e.target !== backdrops[backdrops.length - 1]) {
        return;
      }
      const modal = e.target.querySelector('.modal');
      if (modal && modal.dataset.azcModalDismiss !== 'false') {
        this.closeModal(modal);
      }
    });

    if (!this._modalEscHandlerBound) {
      this._modalEscHandlerBound = true;
      document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') {
          return;
        }
        // Native <dialog> handles Escape via the `cancel` event.
        if (document.querySelector('dialog[open]')) {
          return;
        }
        const modal = this._getTopmostBackdropModal();
        if (modal && modal.dataset.azcModalDismiss !== 'false') {
          e.preventDefault();
          this.closeModal(modal);
        }
      });
    }

    // Close buttons (including dynamically created modals)
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.modal-close');
      if (!btn) {
        return;
      }
      e.preventDefault();
      const modal = btn.closest('.modal');
      if (modal) {
        this.closeModal(modal);
      }
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

    if (!modal._azcReturnFocus && document.activeElement instanceof HTMLElement) {
      modal._azcReturnFocus = document.activeElement;
    }

    // Create backdrop if it doesn't exist
    let backdrop = modal.closest('.modal-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop azc-modal-backdrop';
      backdrop.setAttribute('role', 'presentation');
      document.body.appendChild(backdrop);
      if (modal.parentNode) {
        modal.parentNode.removeChild(modal);
      }
      backdrop.appendChild(modal);
    }

    backdrop.style.display = 'flex';
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    modal.removeAttribute('inert');

    this._lockPageBehindModal();
    this._bindFocusTrap(modal);

    setTimeout(() => {
      const closeBtn = modal.querySelector('.modal-close');
      this.focusFirstElement(modal, closeBtn ? null : undefined);
    }, 50);

    window.dispatchEvent(new CustomEvent('modal-open', {
      detail: { modalId: modal.id || modalId, modal }
    }));
  },

  /**
   * Close modal
   */
  closeModal(modal) {
    if (!modal) return;

    const modalElement = typeof modal === 'string' ? document.getElementById(modal) : modal;
    if (!modalElement) {
      console.warn('Modal element not found:', modal);
      return;
    }

    const returnFocus = modalElement._azcReturnFocus;
    delete modalElement._azcReturnFocus;

    this._unbindFocusTrap(modalElement);

    const backdrop = modalElement.closest('.modal-backdrop');
    const persist = modalElement.dataset.azcModalPersist === 'true';

    if (!backdrop) {
      modalElement.style.display = 'none';
      modalElement.setAttribute('aria-hidden', 'true');
      this._unlockPageBehindModal();
      this._restoreModalFocus(returnFocus);
      window.dispatchEvent(new CustomEvent('modal-close', {
        detail: { modalId: modalElement.id, modal: modalElement }
      }));
      return;
    }

    modalElement.style.display = 'none';
    modalElement.setAttribute('aria-hidden', 'true');
    backdrop.style.display = 'none';
    this._unlockPageBehindModal();

    const removeFromDom = () => {
      if (persist) {
        document.body.appendChild(modalElement);
        return;
      }
      if (backdrop.parentNode) {
        backdrop.remove();
      } else if (modalElement.parentNode) {
        modalElement.parentNode.removeChild(modalElement);
      }
    };

    setTimeout(removeFromDom, 300);

    this._restoreModalFocus(returnFocus);

    window.dispatchEvent(new CustomEvent('modal-close', {
      detail: { modalId: modalElement.id, modal: modalElement }
    }));
  },

  _restoreModalFocus(returnFocus) {
    if (returnFocus && document.body.contains(returnFocus) && typeof returnFocus.focus === 'function') {
      try {
        returnFocus.focus();
        return;
      } catch (e) { /* noop */ }
    }
    const main = document.getElementById('azc-main-content');
    if (main && typeof main.focus === 'function') {
      try {
        main.setAttribute('tabindex', '-1');
        main.focus({ preventScroll: false });
      } catch (e) { /* noop */ }
    }
  },

  /**
   * Unified dialog entry: create (if needed) and open.
   *
   * @param {Object} options - Same as createModal; opens when `open` is not false.
   * @returns {HTMLElement}
   */
  openDialog(options = {}) {
    const { id, open = true, ...rest } = options;
    let modal = id ? document.getElementById(id) : null;
    if (!modal) {
      modal = this.createModal({ id, ...rest });
    }
    if (open !== false) {
      this.openModal(modal);
    }
    return modal;
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
      persist = false,
      dismissOnBackdrop = true,
      onClose = null
    } = options;

    const modal = document.createElement('div');
    modal.className = `modal modal--${size} azc-dialog`;
    modal.id = id;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    if (title) {
      modal.setAttribute('aria-labelledby', `${id}-title`);
    }
    modal.setAttribute('aria-hidden', 'true');
    modal.style.display = 'none';
    if (persist) {
      modal.dataset.azcModalPersist = 'true';
    }
    if (!dismissOnBackdrop) {
      modal.dataset.azcModalDismiss = 'false';
    }

    const closeLabel = (typeof window !== 'undefined' && window.t) 
      ? window.t('arbeitszeitcheck', 'Close') 
      : 'Close';
    
    modal.innerHTML = `
      <div class="modal-header">
        <h2 class="modal-title" id="${id}-title">${this._escapeHtml(title)}</h2>
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
      title = null
    } = options;
    // Errors linger longer (MC critical = 9s); callers may still override duration.
    const duration = Object.prototype.hasOwnProperty.call(options, 'duration')
      ? options.duration
      : ((type === 'error' || type === 'critical' || type === 'danger') ? 9000 : 5000);

    // Dedup on kind+text: an identical toast already visible just gets its
    // timer reset instead of stacking duplicates.
    this._activeToasts = this._activeToasts || {};
    const dedupKey = `${type}|${title ?? ''}|${message}`;
    const existing = this._activeToasts[dedupKey];
    if (existing && existing.isConnected) {
      if (existing._dismissTimer) {
        clearTimeout(existing._dismissTimer);
      }
      existing.classList.add('toast--show');
      if (duration > 0) {
        existing._dismissTimer = setTimeout(() => {
          this.hideToast(existing);
        }, duration);
      }
      return existing;
    }

    const container = document.getElementById('toast-container') || document.body;
    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    // Errors are assertive alerts; routine success/info/warning are polite status.
    const isAssertive = type === 'error' || type === 'critical' || type === 'danger';
    toast.setAttribute('role', isAssertive ? 'alert' : 'status');
    toast.setAttribute('aria-live', isAssertive ? 'assertive' : 'polite');
    toast.setAttribute('aria-atomic', 'true');

    const icon = this.getToastIcon(options.type || type);
    const closeLabel = (typeof window !== 'undefined' && window.t) 
      ? window.t('arbeitszeitcheck', 'Close') 
      : 'Close';
    
    const toastVariant = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
    const toastWellClass = type === 'error' ? 'danger' : toastVariant;
    // Escape title/message/closeLabel — never render raw API/proxy HTML (e.g. NC guest
    // error pages with a giant "400") inside the toast. Icon markup is trusted app SVG.
    const safeTitle = title != null && String(title) !== '' ? this._escapeHtml(String(title)) : '';
    const safeMessage = this._escapeHtml(String(message ?? ''));
    const safeCloseLabel = this._escapeHtml(String(closeLabel));
    toast.innerHTML = `
      <div class="toast-icon azc-notif-icon-well azc-notif-icon-well--${toastWellClass}" aria-hidden="true">${icon}</div>
      <div class="toast-content">
        ${safeTitle ? `<div class="toast-title">${safeTitle}</div>` : ''}
        <div class="toast-message">${safeMessage}</div>
      </div>
      <button type="button" class="toast-close" aria-label="${safeCloseLabel}">&times;</button>
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
      toast._dismissTimer = setTimeout(() => {
        this.hideToast(toast);
      }, duration);
    }
    toast._dedupKey = dedupKey;
    this._activeToasts[dedupKey] = toast;

    return toast;
  },

  /**
   * Hide toast
   */
  hideToast(toast) {
    if (toast._dismissTimer) {
      clearTimeout(toast._dismissTimer);
      toast._dismissTimer = null;
    }
    if (this._activeToasts && toast._dedupKey && this._activeToasts[toast._dedupKey] === toast) {
      delete this._activeToasts[toast._dedupKey];
    }
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
    const map = {
      success: 'check',
      error: 'x',
      warning: 'circle-alert',
      info: 'info',
    };
    const name = map[type] || map.info;
    if (typeof window.AzcCatalog !== 'undefined' && typeof window.AzcCatalog.render === 'function') {
      return window.AzcCatalog.render(name, 'toast__icon-svg');
    }
    return '';
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
  /**
   * Alias matching sibling check-apps API.
   */
  confirmDialog(options = {}) {
    return this.showConfirmDialog(options);
  },

  /**
   * Single-button informational dialog (WCAG alertdialog).
   *
   * @param {Object} options
   * @returns {Promise<void>}
   */
  alertDialog(options = {}) {
    const gotIt = window.t ? window.t('arbeitszeitcheck', 'Got it') : 'Got it';
    return this.showConfirmDialog({
      ...options,
      alertOnly: true,
      confirmLabel: options.confirmLabel || gotIt,
      variant: options.variant || 'info',
    }).then(() => {});
  },

  showConfirmDialog(options = {}) {
    const {
      title = '',
      message = '',
      confirmLabel = (window.t ? window.t('arbeitszeitcheck', 'Confirm') : 'Confirm'),
      cancelLabel  = (window.t ? window.t('arbeitszeitcheck', 'Cancel') : 'Cancel'),
      variant = 'info',
      alertOnly = false,
      requireCheckbox = false,
      checkboxLabel = (window.t ? window.t('arbeitszeitcheck', 'I understand this action cannot be undone') : 'I understand this action cannot be undone'),
      requireReason = false,
      reasonLabel = (window.t ? window.t('arbeitszeitcheck', 'Reason (required)') : 'Reason (required)'),
      requireTypedConfirm = false,
      typedConfirmPhrase = 'DELETE',
      typedConfirmLabel,
    } = options;

    // Resolve the typed-confirmation label with the *actual* phrase the caller
    // requested. We keep `%s` as the placeholder for the translation system so
    // the substitution happens exactly once and reflects custom phrases too.
    let resolvedTypedConfirmLabel = typedConfirmLabel
      ? String(typedConfirmLabel)
      : (window.t
        ? window.t('arbeitszeitcheck', 'Type %s to confirm', [typedConfirmPhrase])
        : `Type ${typedConfirmPhrase} to confirm`);
    if (resolvedTypedConfirmLabel.includes('%s')) {
      resolvedTypedConfirmLabel = resolvedTypedConfirmLabel.replace('%s', typedConfirmPhrase);
    }

    const isDestructive = variant === 'danger' || variant === 'destructive';
    const needsExplicitAck = requireCheckbox || requireReason || requireTypedConfirm;

    return new Promise((resolve) => {
      const dialogId = `confirm-dialog-${Date.now()}`;
      const htmlLang = document.querySelector('[data-azc-html-lang]')?.getAttribute('data-azc-html-lang')
        || document.documentElement.lang
        || 'en';

      const backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop azc-modal-backdrop';
      backdrop.setAttribute('role', 'presentation');

      const dialog = document.createElement('div');
      dialog.className = 'modal modal--sm confirm-dialog azc-dialog';
      dialog.id = dialogId;
      dialog.setAttribute('role', 'alertdialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.setAttribute('aria-labelledby', `${dialogId}-title`);
      dialog.setAttribute('lang', htmlLang);

      const iconName = isDestructive ? 'alert-triangle' : (variant === 'warning' ? 'circle-alert' : 'info');
      const iconHtml = (window.AzcCatalog && window.AzcCatalog.render)
        ? window.AzcCatalog.render(iconName, 'confirm-dialog__icon-svg')
        : '';

      let extraFields = '';
      if (requireCheckbox) {
        extraFields += `
          <div class="form-checkbox confirm-dialog__checkbox-row">
            <input type="checkbox" id="${dialogId}-ack" class="confirm-dialog__ack">
            <label for="${dialogId}-ack">${this._escapeHtml(checkboxLabel)}</label>
          </div>`;
      }
      if (requireReason) {
        extraFields += `
          <label for="${dialogId}-reason" class="form-label">${this._escapeHtml(reasonLabel)}</label>
          <textarea id="${dialogId}-reason" class="form-textarea confirm-dialog__reason" rows="3" required minlength="3"></textarea>`;
      }
      if (requireTypedConfirm) {
        extraFields += `
          <label for="${dialogId}-typed" class="form-label">${this._escapeHtml(resolvedTypedConfirmLabel)}</label>
          <input type="text" id="${dialogId}-typed" class="form-input confirm-dialog__typed" autocomplete="off" spellcheck="false">`;
      }

      const describedBy = [`${dialogId}-message`];
      if (requireReason) describedBy.push(`${dialogId}-reason`);
      dialog.setAttribute('aria-describedby', describedBy.join(' '));

      const footerButtons = alertOnly
        ? `<button type="button" class="btn btn--primary confirm-dialog__confirm">${this._escapeHtml(confirmLabel)}</button>`
        : `<button type="button" class="btn btn--secondary confirm-dialog__cancel">${this._escapeHtml(cancelLabel)}</button>
          <button type="button" class="btn btn--${isDestructive ? 'danger' : 'primary'} confirm-dialog__confirm"${isDestructive && needsExplicitAck ? ' disabled' : ''}>${this._escapeHtml(confirmLabel)}</button>`;

      dialog.innerHTML = `
        <div class="modal-header">
          <span class="confirm-dialog__icon" aria-hidden="true">${iconHtml}</span>
          <h2 class="modal-title" id="${dialogId}-title">${this._escapeHtml(title)}</h2>
        </div>
        <div class="modal-body">
          <p class="confirm-dialog__message" id="${dialogId}-message">${this._escapeHtml(message)}</p>
          ${extraFields}
        </div>
        <div class="modal-footer${alertOnly ? ' modal-footer--single' : ''}">
          ${footerButtons}
        </div>
      `;

      backdrop.appendChild(dialog);
      document.body.appendChild(backdrop);
      backdrop.style.display = 'flex';
      dialog.style.display = 'flex';

      this._lockPageBehindModal();
      this._bindFocusTrap(dialog);

      const previouslyFocused = document.activeElement;
      const confirmBtn = dialog.querySelector('.confirm-dialog__confirm');
      const ackBox = dialog.querySelector('.confirm-dialog__ack');
      const reasonField = dialog.querySelector('.confirm-dialog__reason');
      const typedField = dialog.querySelector('.confirm-dialog__typed');

      const validateConfirmEnabled = () => {
        let ok = true;
        if (requireCheckbox && ackBox && !ackBox.checked) ok = false;
        if (requireReason && reasonField && reasonField.value.trim().length < 3) ok = false;
        if (requireTypedConfirm && typedField && typedField.value.trim() !== typedConfirmPhrase) ok = false;
        if (confirmBtn) confirmBtn.disabled = !ok;
      };

      ackBox?.addEventListener('change', validateConfirmEnabled);
      reasonField?.addEventListener('input', validateConfirmEnabled);
      typedField?.addEventListener('input', validateConfirmEnabled);
      validateConfirmEnabled();

      setTimeout(() => {
        const focusTarget = alertOnly ? confirmBtn : dialog.querySelector('.confirm-dialog__cancel');
        if (focusTarget) focusTarget.focus();
      }, 50);

      const cleanup = (result) => {
        this._unbindFocusTrap(dialog);
        backdrop.remove();
        this._unlockPageBehindModal();
        if (previouslyFocused && previouslyFocused.focus) {
          previouslyFocused.focus();
        }
        resolve(result);
      };

      confirmBtn.addEventListener('click', () => {
        if (confirmBtn.disabled) return;
        cleanup(alertOnly ? true : {
          confirmed: true,
          reason: reasonField ? reasonField.value.trim() : '',
        });
      });
      const cancelBtn = dialog.querySelector('.confirm-dialog__cancel');
      if (cancelBtn) {
        cancelBtn.addEventListener('click', () => cleanup(false));
      }

      const keyHandler = (e) => {
        if (e.key === 'Escape' && (!isDestructive || alertOnly)) {
          document.removeEventListener('keydown', keyHandler);
          cleanup(alertOnly ? true : false);
        }
      };
      document.addEventListener('keydown', keyHandler);

      if (!isDestructive || alertOnly) {
        backdrop.addEventListener('click', (e) => {
          if (e.target === backdrop) {
            document.removeEventListener('keydown', keyHandler);
            cleanup(alertOnly ? true : false);
          }
        });
      }

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
    }).then((result) => {
      if (result && typeof result === 'object' && result.confirmed) {
        return result;
      }
      return result === true;
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
  focusFirstElement(container, preferred) {
    if (preferred && typeof preferred.focus === 'function') {
      try {
        preferred.focus();
        return;
      } catch (e) { /* noop */ }
    }
    const focusableElements = container.querySelectorAll(
      'button:not([disabled]), [href], input:not([disabled]):not([hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );

    if (focusableElements.length > 0) {
      focusableElements[0].focus();
    }
  },

  /**
   * Move legacy .header-actions / .azc-page-actions-source buttons into #azc-page-actions.
   */
  relocatePageActions() {
    const target = document.getElementById('azc-page-actions');
    if (!target) {
      return;
    }
    document.querySelectorAll('.azc-page-actions-source, .header-actions').forEach((source) => {
      while (source.firstChild) {
        target.appendChild(source.firstChild);
      }
      source.remove();
    });
  }
};

// Methods delegate via `this`; bind once so detached references stay safe (audit-critical).
ArbeitszeitCheckComponents.confirmDialog = ArbeitszeitCheckComponents.confirmDialog.bind(ArbeitszeitCheckComponents);
ArbeitszeitCheckComponents.alertDialog = ArbeitszeitCheckComponents.alertDialog.bind(ArbeitszeitCheckComponents);
ArbeitszeitCheckComponents.showConfirmDialog = ArbeitszeitCheckComponents.showConfirmDialog.bind(ArbeitszeitCheckComponents);

// Export for use in other modules
if (typeof window !== 'undefined') {
  window.ArbeitszeitCheckComponents = ArbeitszeitCheckComponents;
  window.AzcComponents = ArbeitszeitCheckComponents;
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    ArbeitszeitCheckComponents.init();
  });
} else {
  ArbeitszeitCheckComponents.init();
}
