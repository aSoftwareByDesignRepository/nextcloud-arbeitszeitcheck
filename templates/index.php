<?php

declare(strict_types=1);

/**
 * Main index template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

// CSS is loaded via Util::addStyle() in PageController.php
?>

<div id="arbeitszeitcheck-content">
	<!-- Vue.js app will be mounted here -->
</div>

<?php
// Define Vue feature flags BEFORE loading Vue
// This must be done in a separate script block that loads BEFORE the main JS
?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
// CRITICAL: Define Vue feature flags BEFORE Vue is loaded
// Vue checks these flags when it initializes, so they must be set first
(function() {
	if (typeof window !== 'undefined') {
		// Vue 3 feature flags
		window.__VUE_OPTIONS_API__ = true;
		window.__VUE_PROD_DEVTOOLS__ = false;
		window.__VUE_PROD_HYDRATION_MISMATCH_DETAILS__ = false;
		
		// @nextcloud/vue app information (prevents warnings)
		window.__NC_APP_NAME__ = 'arbeitszeitcheck';
		window.__NC_APP_VERSION__ = '1.0.0';
		
		// Translations are loaded via Util::addTranslations() in PageController
		// This ensures OC.L10N is properly initialized before Vue components load
		// No manual translation loading needed - Nextcloud handles it automatically
		// Note: Font CSP errors for Mulish are from Nextcloud core, not this app
		
		// Vue 2 compatibility shim for @nextcloud/vue v8 (until build succeeds with v9+)
		// This adds Vue.extend to Vue 3 for compatibility
		window.addEventListener('DOMContentLoaded', function() {
			if (window.Vue && typeof window.Vue.extend === 'undefined') {
				const Vue3 = window.Vue;
				// Add extend method for Vue 2 compatibility
				Vue3.extend = function(component) {
					// In Vue 3, we use defineComponent instead of extend
					return Vue3.defineComponent ? Vue3.defineComponent(component) : component;
				};
			}
		});
		
		// Also set as global for webpack DefinePlugin compatibility
		if (typeof globalThis !== 'undefined') {
			globalThis.__VUE_OPTIONS_API__ = true;
			globalThis.__VUE_PROD_DEVTOOLS__ = false;
			globalThis.__VUE_PROD_HYDRATION_MISMATCH_DETAILS__ = false;
			globalThis.__NC_APP_NAME__ = 'arbeitszeitcheck';
			globalThis.__NC_APP_VERSION__ = '1.0.0';
		}
	}
})();
</script>
<?php
// Script loading is now handled in PageController using Util::addScript()
// This replaces the deprecated script() function and follows Nextcloud best practices
// The script will be automatically included in the page via Nextcloud's script loading system
?>