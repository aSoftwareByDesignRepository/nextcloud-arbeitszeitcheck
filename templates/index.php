<?php

declare(strict_types=1);

/**
 * Main index template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

// style('arbeitszeitcheck', 'arbeitszeitcheck-main'); // CSS not yet generated
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
		
		// Load translations from JSON file and register with OC.L10N
		// This ensures translations are available before Vue components load
		// Wait for OC to be available before loading translations
		(function() {
			function loadTranslations() {
				if (typeof OC === 'undefined' || !OC || typeof OC.L10N === 'undefined') {
					// Retry after a short delay
					setTimeout(loadTranslations, 100);
					return;
				}
				
				var docEl = document.documentElement || {};
				var locale = (docEl.dataset && (docEl.dataset.locale || docEl.locale)) || docEl.lang || 'en';
				var shortLocale = (locale || '').toLowerCase().split('-')[0] || 'en';
				
				// Build translation URL - try both /apps/ and /custom_apps/ paths
				var currentPath = window.location.pathname;
				var basePath = currentPath.split('/apps/')[0] || currentPath.split('/custom_apps/')[0] || '';
				
				// Try both paths
				var candidates = [
					basePath + '/apps/arbeitszeitcheck/l10n/' + shortLocale + '.json',
					basePath + '/custom_apps/arbeitszeitcheck/l10n/' + shortLocale + '.json'
				];
				
				// Add English fallback if not already English
				if (shortLocale !== 'en') {
					candidates.push(
						basePath + '/apps/arbeitszeitcheck/l10n/en.json',
						basePath + '/custom_apps/arbeitszeitcheck/l10n/en.json'
					);
				}
				
				// Function to try loading from candidates
				function loadFirstAvailable(candidates, index) {
					if (index >= candidates.length) {
						// All candidates failed - silently fail
						return Promise.reject(new Error('No translation file found'));
					}
					
					return fetch(candidates[index])
						.then(function(response) {
							if (!response.ok) {
								// Try next candidate
								return loadFirstAvailable(candidates, index + 1);
							}
							return response.json();
						})
						.catch(function() {
							// Try next candidate
							return loadFirstAvailable(candidates, index + 1);
						});
				}
				
				if (typeof fetch !== 'undefined') {
					loadFirstAvailable(candidates, 0)
						.then(function(bundle) {
							if (bundle && bundle.translations && typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.register === 'function') {
								var pluralForm = bundle.pluralForm || 'nplurals=2; plural=(n != 1);';
								OC.L10N.register('arbeitszeitcheck', bundle.translations, pluralForm);
								console.log('[ArbeitszeitCheck] Translations registered for locale:', shortLocale, Object.keys(bundle.translations).length, 'keys');
							}
						})
						.catch(function(err) {
							// Silently fail - translations will fall back to English or key names
							console.debug('[ArbeitszeitCheck] Translation loading failed (will use fallback):', err.message);
						});
				}
			}
			
			// Start loading translations
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', loadTranslations);
			} else {
				loadTranslations();
			}
		})();
		
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