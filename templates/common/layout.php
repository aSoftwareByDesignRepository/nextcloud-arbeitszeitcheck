<?php

/**
 * Base Layout Template for ArbeitszeitCheck App
 * 
 * This template provides the foundation HTML structure that all pages extend.
 * It includes common meta tags, CSS, JavaScript, and semantic HTML structure.
 * 
 * Based on ProjectCheck architecture pattern
 */

// Ensure this file is being included within Nextcloud
if (!defined('OCP\AppFramework\App::class')) {
    die('Direct access not allowed');
}

// Get the current user and app context
$user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
$appName = 'arbeitszeitcheck';
$appVersion = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion($appName);
$l = $l ?? $_['l'] ?? \OCP\Util::getL10N($appName);

// Get the page title and meta information
$pageTitle = isset($pageTitle) ? $pageTitle : $l->t('ArbeitszeitCheck');
$pageDescription = isset($pageDescription) ? $pageDescription : $l->t('Time tracking and compliance management for German labor law');
$pageKeywords = isset($pageKeywords) ? $pageKeywords : $l->t('time tracking, arbeitszeit, compliance, German labor law');

// Inherit theme from Nextcloud without app overrides; fallback to light
$theme = 'light';
$primary = '#0082c9'; // default Nextcloud blue
try {
    $theming = \OCP\Server::get(\OCA\Theming\ThemingDefaults::class);
    $primary = $theming->getColorPrimary();
    // Compute simple luminance heuristic from hex color to decide dark vs light
    if (is_string($primary) && preg_match('/^#?([0-9a-fA-F]{6})$/', $primary, $m)) {
        $hex = $m[1];
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        $theme = ($luminance < 96) ? 'dark' : 'light';
    }
    // Optional admin-only QA override via app config and query param
    try {
        /** @var \OCP\IConfig $config */
        $config = \OCP\Server::get(\OCP\IConfig::class);
        $allowOverride = $config->getAppValue($appName, 'theme_dev_override', 'no') === 'yes';
        if ($allowOverride && isset($_GET['theme'])) {
            $q = $_GET['theme'];
            if ($q === 'dark' || $q === 'light') {
                $theme = $q;
            }
        }
    } catch (\Throwable $ie) {
        // ignore
    }
} catch (\Throwable $e) {
    $theme = 'light';
}
?>
<!DOCTYPE html>
<html lang="<?php p($_['language']); ?>" data-theme="<?php p($theme); ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php p($pageDescription); ?>">
    <meta name="keywords" content="<?php p($pageKeywords); ?>">
    <meta name="author" content="Nextcloud">
    <meta name="robots" content="noindex, nofollow">

    <!-- Security Headers (also set as HTTP headers via CSPService) -->
    <!-- Note: HTTP headers are preferred, but meta tags provide fallback -->

    <!-- Theme Color (from Nextcloud theming if available) -->
    <meta name="theme-color" content="<?php p($primary ?? '#0082c9'); ?>">
    <meta name="msapplication-TileColor" content="<?php p($primary ?? '#0082c9'); ?>">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <!-- Favicon (use app icon if dedicated favicon not present) -->
    <link rel="icon" type="image/svg+xml" href="<?php print_unescaped(image_path($appName, 'app.svg')); ?>">
    <link rel="apple-touch-icon" href="<?php print_unescaped(image_path($appName, 'app.svg')); ?>">

    <!-- Page Title -->
    <title><?php p($pageTitle); ?> - <?php p($l->t('ArbeitszeitCheck')); ?></title>

    <!-- Nextcloud Core Styles -->
    <?php if (isset($_['cssfiles'])): ?>
        <?php foreach ($_['cssfiles'] as $cssfile): ?>
            <link rel="stylesheet" href="<?php print_unescaped($cssfile); ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- ArbeitszeitCheck Common Styles -->
    <link rel="stylesheet" href="<?php print_unescaped(link_to($appName, 'css/common/colors.css')); ?>">
    <link rel="stylesheet" href="<?php print_unescaped(link_to($appName, 'css/common/typography.css')); ?>">
    <link rel="stylesheet" href="<?php print_unescaped(link_to($appName, 'css/common/base.css')); ?>">
    <link rel="stylesheet" href="<?php print_unescaped(link_to($appName, 'css/common/components.css')); ?>">
    <link rel="stylesheet" href="<?php print_unescaped(link_to($appName, 'css/common/layout.css')); ?>">
    <link rel="stylesheet" href="<?php print_unescaped(link_to($appName, 'css/common/app-layout.css')); ?>">
    <link rel="stylesheet" href="<?php print_unescaped(link_to($appName, 'css/common/utilities.css')); ?>">
    <link rel="stylesheet" href="<?php print_unescaped(link_to($appName, 'css/common/responsive.css')); ?>">
    <link rel="stylesheet" href="<?php print_unescaped(link_to($appName, 'css/common/accessibility.css')); ?>">

    <!-- Page Specific Styles -->
    <?php if (isset($pageStyles)): ?>
        <?php foreach ($pageStyles as $style): ?>
            <link rel="stylesheet" href="<?php print_unescaped($style); ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Nextcloud Core Scripts -->
    <?php if (isset($_['jsfiles'])): ?>
        <?php foreach ($_['jsfiles'] as $jsfile): ?>
            <script src="<?php print_unescaped($jsfile); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- ArbeitszeitCheck Common Scripts -->
    <script src="<?php print_unescaped(link_to($appName, 'js/common/utils.js')); ?>"></script>
    <script src="<?php print_unescaped(link_to($appName, 'js/common/components.js')); ?>"></script>
    <script src="<?php print_unescaped(link_to($appName, 'js/common/messaging.js')); ?>"></script>
    <script src="<?php print_unescaped(link_to($appName, 'js/common/validation.js')); ?>"></script>
    <script src="<?php print_unescaped(link_to($appName, 'js/common/navigation.js')); ?>"></script>

    <!-- Page Specific Scripts -->
    <?php if (isset($pageScripts)): ?>
        <?php foreach ($pageScripts as $script): ?>
            <script src="<?php print_unescaped($script); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Inline Styles for Critical CSS -->
    <style nonce="<?php p($_['cspNonce'] ?? '') ?>">
        /* Critical CSS for above-the-fold content */
        .page-layout {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .page-main {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .page-content {
            flex: 1;
            padding: 1.5rem 0;
        }

        .container {
            width: 100%;
            margin-left: auto;
            margin-right: auto;
            padding-left: 1rem;
            padding-right: 1rem;
        }
    </style>
</head>

<body id="<?php p($_['bodyid'] ?? 'body-arbeitszeitcheck'); ?>" class="<?php p($_['bodyclass'] ?? ''); ?>">
    <!-- Skip Link for Accessibility -->
    <a href="#main-content" class="skip-link"><?php p($l->t('Skip to main content')); ?></a>

    <!-- Page Layout Container -->
    <div class="page-layout">
        <!-- Header -->
        <header class="page-header">
            <?php include 'header.php'; ?>
        </header>

        <!-- Main Content -->
        <main id="main-content" class="page-main" role="main" tabindex="-1" aria-label="<?php p($l->t('Main content')); ?>">
            <div class="page-content">
                <div class="container">
                    <div id="app-content"></div>
                    <!-- Page Header -->
                    <?php if (isset($pageHeader)): ?>
                        <?php include 'page-header.php'; ?>
                    <?php endif; ?>

                    <!-- Main Content Area -->
                    <div class="content-layout">
                        <div class="content-main">
                            <?php if (isset($content)): ?>
                                <?php print_unescaped($content); ?>
                            <?php endif; ?>
                        </div>

                        <!-- Sidebar (if needed) -->
                        <?php if (isset($sidebar)): ?>
                            <aside class="content-sidebar">
                                <?php print_unescaped($sidebar); ?>
                            </aside>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="page-footer">
            <?php include 'footer.php'; ?>
        </footer>
    </div>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="loading-overlay" style="display: none;">
        <div class="loading-spinner"></div>
    </div>

    <!-- Toast Container for Messages -->
    <div id="toast-container" class="toast-container"></div>

    <!-- Modal Container -->
    <div id="modal-container" class="modal-container"></div>

    <!-- Inline Scripts -->
    <script nonce="<?php p($_['cspNonce'] ?? '') ?>">
        // Initialize the application
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize common components
            if (typeof ArbeitszeitCheck !== 'undefined') {
                if (ArbeitszeitCheck.init) {
                    ArbeitszeitCheck.init();
                }
                if (ArbeitszeitCheck.Layout && ArbeitszeitCheck.Layout.init) {
                    ArbeitszeitCheck.Layout.init();
                }
                if (ArbeitszeitCheck.Validation && ArbeitszeitCheck.Validation.init) {
                    ArbeitszeitCheck.Validation.init();
                }
                if (ArbeitszeitCheck.Messaging && ArbeitszeitCheck.Messaging.init) {
                    ArbeitszeitCheck.Messaging.init();
                }
            }

            // Page-specific initialization
            <?php if (isset($pageInitScript)): ?>
                <?php print_unescaped($pageInitScript); ?>
            <?php endif; ?>
        });

        // Handle theme changes
        window.addEventListener('theme-changed', function(event) {
            document.documentElement.setAttribute('data-theme', event.detail.theme);
        });

        // Handle loading states
        window.addEventListener('loading-start', function() {
            document.getElementById('loading-overlay').style.display = 'flex';
        });

        window.addEventListener('loading-end', function() {
            document.getElementById('loading-overlay').style.display = 'none';
        });

        // Handle form submissions
        document.addEventListener('submit', function(event) {
            const form = event.target;
            if (form.classList.contains('ajax-form')) {
                event.preventDefault();

                // Dispatch loading event
                window.dispatchEvent(new CustomEvent('loading-start'));

                // Handle form submission via AJAX
                const formData = new FormData(form);
                const url = form.action || window.location.href;

                const requestToken = (typeof OC !== 'undefined' && OC.requestToken) ||
                    (document.querySelector('head') && document.querySelector('head').getAttribute('data-requesttoken')) || '';
                fetch(url, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'requesttoken': requestToken
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Show success message
                            if (typeof ArbeitszeitCheck !== 'undefined' && ArbeitszeitCheck.Messaging) {
                                const successMsg = data.message || 
                                                    window.ArbeitszeitCheck?.l10n?.success || 
                                                    (window.t && window.t('arbeitszeitcheck', 'Operation completed successfully')) ||
                                                    'Operation completed successfully';
                                ArbeitszeitCheck.Messaging.show('success', successMsg);
                            }

                            // Redirect if specified
                            if (data.redirect) {
                                window.location.href = data.redirect;
                            }
                        } else {
                            // Show helpful error message
                            if (typeof ArbeitszeitCheck !== 'undefined' && ArbeitszeitCheck.Messaging) {
                                let errorMsg = data.message || (window.ArbeitszeitCheck?.l10n?.error || 'An error occurred');
                                
                                // If there are field-specific errors, show them
                                if (data.errors && typeof data.errors === 'object') {
                                    const firstError = Object.values(data.errors)[0];
                                    if (Array.isArray(firstError) && firstError.length > 0) {
                                        errorMsg = firstError[0];
                                    } else if (typeof firstError === 'string') {
                                        errorMsg = firstError;
                                    }
                                }
                                
                                ArbeitszeitCheck.Messaging.show('error', errorMsg);
                                
                                // Show field-specific errors if available
                                if (data.errors && typeof data.errors === 'object' && typeof ArbeitszeitCheck !== 'undefined' && ArbeitszeitCheck.Validation) {
                                    Object.keys(data.errors).forEach(fieldName => {
                                        const field = form.querySelector(`[name="${fieldName}"]`);
                                        if (field) {
                                            const fieldErrors = data.errors[fieldName];
                                            const errorMsg = Array.isArray(fieldErrors) ? fieldErrors[0] : fieldErrors;
                                            ArbeitszeitCheck.Validation.showFieldError(field, errorMsg);
                                        }
                                    });
                                }
                            }
                        }
                    })
                    .catch(error => {
                        if (typeof ArbeitszeitCheck !== 'undefined' && ArbeitszeitCheck.Messaging) {
                            const errorMsg = window.ArbeitszeitCheck?.l10n?.unexpectedError || 
                                'Something went wrong. Please check your internet connection and try again. If the problem continues, contact support.';
                            ArbeitszeitCheck.Messaging.show('error', errorMsg);
                        }
                    })
                    .finally(() => {
                        // Dispatch loading end event
                        window.dispatchEvent(new CustomEvent('loading-end'));
                    });
            }
        });
    </script>

    <!-- Print Styles -->
    <style nonce="<?php p($_['cspNonce'] ?? '') ?>" media="print">
        .page-header,
        .page-footer,
        .content-sidebar,
        .loading-overlay,
        .toast-container,
        .modal-container {
            display: none !important;
        }

        .page-layout {
            display: block;
            min-height: auto;
        }

        .page-main {
            display: block;
        }

        .page-content {
            padding: 0;
        }

        .content-layout {
            display: block;
        }

        .content-main {
            width: 100%;
        }
    </style>
</body>

</html>
