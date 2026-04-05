/**
 * Messaging Utilities for ArbeitszeitCheck App
 * Provides toast notifications and user feedback
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

const ArbeitszeitCheckMessaging = {
  /**
   * Show success message
   */
  showSuccess(message, title = null) {
    if (window.ArbeitszeitCheckComponents) {
      return window.ArbeitszeitCheckComponents.showToast({
        type: 'success',
        message: message,
        title: title,
        duration: 3000
      });
    } else if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
      window.OC.Notification.showTemporary(message);
    } else {
      try {
        alert(message);
      } catch (e) {
        // ignore: alert may be blocked in some contexts
      }
    }
  },

  /**
   * Show error message
   */
  showError(message, title = null) {
    if (window.ArbeitszeitCheckComponents) {
      return window.ArbeitszeitCheckComponents.showToast({
        type: 'error',
        message: message,
        title: title,
        duration: 5000
      });
    } else if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
      window.OC.Notification.showTemporary(message);
    } else {
      try {
        alert(message);
      } catch (e) {
        // ignore: alert may be blocked in some contexts
      }
    }
  },

  /**
   * Show warning message
   */
  showWarning(message, title = null) {
    if (window.ArbeitszeitCheckComponents) {
      return window.ArbeitszeitCheckComponents.showToast({
        type: 'warning',
        message: message,
        title: title,
        duration: 4000
      });
    } else if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
      window.OC.Notification.showTemporary(message);
    } else {
      try {
        alert(message);
      } catch (e) {
        // ignore: alert may be blocked in some contexts
      }
    }
  },

  /**
   * Show info message
   */
  showInfo(message, title = null) {
    if (window.ArbeitszeitCheckComponents) {
      return window.ArbeitszeitCheckComponents.showToast({
        type: 'info',
        message: message,
        title: title,
        duration: 3000
      });
    } else if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
      window.OC.Notification.showTemporary(message);
    } else {
      try {
        alert(message);
      } catch (e) {
        // ignore: alert may be blocked in some contexts
      }
    }
  },

  /**
   * Show generic toast
   */
  show(type, message, title = null, duration = null) {
    if (window.ArbeitszeitCheckComponents) {
      return window.ArbeitszeitCheckComponents.showToast({
        type: type,
        message: message,
        title: title,
        duration: duration
      });
    } else if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
      window.OC.Notification.showTemporary(message);
    } else {
      try {
        alert(message);
      } catch (e) {
        // ignore: alert may be blocked in some contexts
      }
    }
  }
};

// Export for use in other modules
if (typeof window !== 'undefined') {
  window.ArbeitszeitCheckMessaging = ArbeitszeitCheckMessaging;
}
