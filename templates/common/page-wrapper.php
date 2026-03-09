<?php

/**
 * Common page wrapper for arbeitszeitcheck app
 * 
 * This provides a consistent wrapper structure for all pages within Nextcloud's app framework.
 * Nextcloud handles the main HTML structure, so this just provides the app-specific layout.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


// Get app context
$appName = 'arbeitszeitcheck';

// Get page title (default if not set)
$pageTitle = $pageTitle ?? $l->t('ArbeitszeitCheck');
?>

<!-- Loading Overlay -->
<div id="arbeitszeitcheck-loading-overlay" class="loading-overlay" style="display: none;" aria-hidden="true">
    <div class="loading-spinner" role="status" aria-label="<?php p($l->t('Loading')); ?>">
        <span class="sr-only"><?php p($l->t('Loading')); ?></span>
    </div>
</div>

<!-- Toast Container for Messages -->
<div id="arbeitszeitcheck-toast-container" class="toast-container" role="status" aria-live="polite" aria-atomic="true"></div>

<!-- Modal Container -->
<div id="arbeitszeitcheck-modal-container" class="modal-container"></div>

<!-- Main App Layout -->
<div id="app" class="arbeitszeitcheck-app">
    <div id="app-navigation" class="arbeitszeitcheck-navigation-wrapper">
        <?php include __DIR__ . '/navigation.php'; ?>
    </div>

    <div id="app-content" class="arbeitszeitcheck-content-wrapper">
        <div id="app-content-wrapper" class="arbeitszeitcheck-content">
            <?php if (isset($content)): ?>
                <?php print_unescaped($content); ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Common Initialization Script -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    (function() {
        'use strict';
        
        // Initialize on DOM ready
        function init() {
            // Initialize common utilities if available
            if (typeof ArbeitszeitCheckUtils !== 'undefined' && ArbeitszeitCheckUtils.init) {
                ArbeitszeitCheckUtils.init();
            }
            
            if (typeof ArbeitszeitCheckMessaging !== 'undefined' && ArbeitszeitCheckMessaging.init) {
                ArbeitszeitCheckMessaging.init();
            }
            
            if (typeof ArbeitszeitCheckComponents !== 'undefined' && ArbeitszeitCheckComponents.init) {
                ArbeitszeitCheckComponents.init();
            }
            
            // Page-specific initialization
            <?php if (isset($pageInitScript)): ?>
                <?php print_unescaped($pageInitScript); ?>
            <?php endif; ?>
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
        
        // Handle loading states
        window.addEventListener('arbeitszeitcheck-loading-start', function() {
            const overlay = document.getElementById('arbeitszeitcheck-loading-overlay');
            if (overlay) {
                overlay.style.display = 'flex';
                overlay.setAttribute('aria-hidden', 'false');
            }
        });
        
        window.addEventListener('arbeitszeitcheck-loading-end', function() {
            const overlay = document.getElementById('arbeitszeitcheck-loading-overlay');
            if (overlay) {
                overlay.style.display = 'none';
                overlay.setAttribute('aria-hidden', 'true');
            }
        });
    })();
</script>
