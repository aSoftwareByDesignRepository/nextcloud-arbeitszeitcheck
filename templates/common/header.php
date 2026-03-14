<?php

/**
 * Common Header Template for ArbeitszeitCheck App
 * 
 * This template provides the header section that integrates with Nextcloud's
 * header system and provides navigation for the ArbeitszeitCheck app.
 */

// Ensure this file is being included within Nextcloud
if (!defined('OCP\AppFramework\App::class')) {
    die('Direct access not allowed');
}

// Get the current user and app context
$user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
$appName = 'arbeitszeitcheck';
$currentPage = isset($currentPage) ? $currentPage : 'dashboard';

// Get the current URL for navigation highlighting
$currentUrl = $_SERVER['REQUEST_URI'];
?>
<header class="header" role="banner">
    <div class="header__content">
        <!-- App Logo and Title -->
        <div class="header__logo">
            <a href="<?php print_unescaped(link_to($appName, 'index.php')); ?>" class="header__logo-link">
                <img src="<?php print_unescaped(image_path($appName, 'app.svg')); ?>"
                    alt="<?php p($l->t('ArbeitszeitCheck')); ?>"
                    class="header__logo-image">
                <span class="header__logo-text"><?php p($l->t('ArbeitszeitCheck')); ?></span>
            </a>
        </div>

        <!-- Main Navigation -->
        <nav class="header__navigation" role="navigation" aria-label="<?php p($l->t('Main navigation')); ?>">
            <ul class="header__nav-list">
                <li class="header__nav-item">
                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.dashboard')); ?>"
                        class="header__nav-link <?php echo ($currentPage === 'dashboard') ? 'header__nav-link--active' : ''; ?>"
                        title="<?php p($l->t('Dashboard')); ?>">
                        <span class="header__nav-icon" aria-hidden="true">📊</span>
                        <span class="header__nav-text"><?php p($l->t('Dashboard')); ?></span>
                    </a>
                </li>

                <li class="header__nav-item">
                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
                        class="header__nav-link <?php echo ($currentPage === 'time-entries') ? 'header__nav-link--active' : ''; ?>"
                        title="<?php p($l->t('Time Entries')); ?>">
                        <span class="header__nav-icon" aria-hidden="true">⏱️</span>
                        <span class="header__nav-text"><?php p($l->t('Time Entries')); ?></span>
                    </a>
                </li>

                <li class="header__nav-item">
                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.absences')); ?>"
                        class="header__nav-link <?php echo ($currentPage === 'absences') ? 'header__nav-link--active' : ''; ?>"
                        title="<?php p($l->t('Absences')); ?>">
                        <span class="header__nav-icon" aria-hidden="true">🏖️</span>
                        <span class="header__nav-text"><?php p($l->t('Absences')); ?></span>
                    </a>
                </li>

                <li class="header__nav-item">
                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.reports')); ?>"
                        class="header__nav-link <?php echo ($currentPage === 'reports') ? 'header__nav-link--active' : ''; ?>"
                        title="<?php p($l->t('Reports')); ?>">
                        <span class="header__nav-icon" aria-hidden="true">📈</span>
                        <span class="header__nav-text"><?php p($l->t('Reports')); ?></span>
                    </a>
                </li>

                <li class="header__nav-item">
                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.calendar')); ?>"
                        class="header__nav-link <?php echo ($currentPage === 'calendar') ? 'header__nav-link--active' : ''; ?>"
                        title="<?php p($l->t('Calendar')); ?>">
                        <span class="header__nav-icon" aria-hidden="true">📅</span>
                        <span class="header__nav-text"><?php p($l->t('Calendar')); ?></span>
                    </a>
                </li>

                <li class="header__nav-item">
                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.settings')); ?>"
                        class="header__nav-link <?php echo ($currentPage === 'settings') ? 'header__nav-link--active' : ''; ?>"
                        title="<?php p($l->t('Settings')); ?>">
                        <span class="header__nav-icon" aria-hidden="true">⚙️</span>
                        <span class="header__nav-text"><?php p($l->t('Settings')); ?></span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Header Actions -->
        <div class="header__actions">
            <!-- Quick Actions -->
            <div class="header__quick-actions">
                <button type="button"
                    id="quick-clock-btn"
                    class="header__action-btn header__action-btn--primary"
                    aria-label="<?php p($l->t('Clock In/Out')); ?>"
                    title="<?php p($l->t('Clock In/Out')); ?>">
                    <span class="header__action-icon">▶️</span>
                    <span class="header__action-text"><?php p($l->t('Clock In')); ?></span>
                </button>

                <button type="button"
                    id="quick-entry-btn"
                    class="header__action-btn"
                    aria-label="<?php p($l->t('Add Time Entry')); ?>"
                    title="<?php p($l->t('Add Time Entry')); ?>">
                    <span class="header__action-icon">➕</span>
                    <span class="header__action-text"><?php p($l->t('Add Entry')); ?></span>
                </button>
            </div>

            <!-- User Menu -->
            <div class="header__user-menu">
                <button type="button"
                    class="header__user-btn"
                    aria-expanded="false"
                    aria-haspopup="true"
                    aria-label="<?php p($l->t('User menu')); ?>"
                    title="<?php p($l->t('User menu')); ?>">
                    <span class="header__user-avatar">
                        <?php if ($user): ?>
                            <img src="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('core.avatar.getAvatar', ['userId' => $user->getUID(), 'size' => 32])); ?>"
                                alt="<?php p($user->getDisplayName()); ?>"
                                class="header__user-avatar-image">
                        <?php else: ?>
                            <span class="header__user-avatar-placeholder" aria-hidden="true">👤</span>
                        <?php endif; ?>
                    </span>
                    <span class="header__user-name">
                        <?php p($user ? $user->getDisplayName() : $l->t('Guest')); ?>
                    </span>
                    <span class="header__user-arrow">▼</span>
                </button>

                <div class="header__user-dropdown" aria-label="<?php p($l->t('User menu')); ?>" aria-hidden="true" style="display: none;">
                    <ul class="header__user-dropdown-list">
                        <?php if ($user): ?>
                            <li class="header__user-dropdown-item">
                                <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.settings')); ?>"
                                    class="header__user-dropdown-link">
                                    <span class="header__user-dropdown-icon" aria-hidden="true">👤</span>
                                    <span class="header__user-dropdown-text"><?php p($l->t('Personal Settings')); ?></span>
                                </a>
                            </li>

                            <?php if (\OCP\Server::get(\OCP\IGroupManager::class)->isAdmin($user->getUID())): ?>
                                <li class="header__user-dropdown-item">
                                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.admin.dashboard')); ?>"
                                        class="header__user-dropdown-link">
                                        <span class="header__user-dropdown-icon" aria-hidden="true">🔧</span>
                                        <span class="header__user-dropdown-text"><?php p($l->t('Admin Dashboard')); ?></span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <li class="header__user-dropdown-divider"></li>

                            <li class="header__user-dropdown-item">
                                <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('core.login.logout')); ?>"
                                    class="header__user-dropdown-link header__user-dropdown-link--logout">
                                    <span class="header__user-dropdown-icon" aria-hidden="true">🚪</span>
                                    <span class="header__user-dropdown-text"><?php p($l->t('Logout')); ?></span>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="header__user-dropdown-item">
                                <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('core.login.showLoginForm')); ?>"
                                    class="header__user-dropdown-link">
                                    <span class="header__user-dropdown-icon" aria-hidden="true">🔑</span>
                                    <span class="header__user-dropdown-text"><?php p($l->t('Login')); ?></span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Theme Toggle -->
            <button type="button"
                class="header__theme-toggle"
                aria-label="<?php p($l->t('Toggle theme')); ?>"
                title="<?php p($l->t('Toggle dark/light theme')); ?>">
                <span class="header__theme-icon header__theme-icon--light">☀️</span>
                <span class="header__theme-icon header__theme-icon--dark">🌙</span>
            </button>
        </div>

        <!-- Mobile Menu Toggle -->
        <button type="button"
            class="header__mobile-toggle"
            aria-label="<?php p($l->t('Toggle mobile menu')); ?>"
            aria-expanded="false">
            <span class="header__mobile-toggle-line"></span>
            <span class="header__mobile-toggle-line"></span>
            <span class="header__mobile-toggle-line"></span>
        </button>
    </div>

    <!-- Mobile Navigation -->
    <div class="header__mobile-nav" aria-hidden="true" style="display: none;">
        <nav class="header__mobile-navigation" role="navigation" aria-label="<?php p($l->t('Mobile navigation')); ?>">
            <ul class="header__mobile-nav-list">
                <li class="header__mobile-nav-item">
                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.dashboard')); ?>"
                        class="header__mobile-nav-link <?php echo ($currentPage === 'dashboard') ? 'header__mobile-nav-link--active' : ''; ?>">
                        <span class="header__mobile-nav-icon">📊</span>
                        <span class="header__mobile-nav-text"><?php p($l->t('Dashboard')); ?></span>
                    </a>
                </li>

                <li class="header__mobile-nav-item">
                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
                        class="header__mobile-nav-link <?php echo ($currentPage === 'time-entries') ? 'header__mobile-nav-link--active' : ''; ?>">
                        <span class="header__mobile-nav-icon">⏱️</span>
                        <span class="header__mobile-nav-text"><?php p($l->t('Time Entries')); ?></span>
                    </a>
                </li>

                <li class="header__mobile-nav-item">
                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.absences')); ?>"
                        class="header__mobile-nav-link <?php echo ($currentPage === 'absences') ? 'header__mobile-nav-link--active' : ''; ?>">
                        <span class="header__mobile-nav-icon">🏖️</span>
                        <span class="header__mobile-nav-text"><?php p($l->t('Absences')); ?></span>
                    </a>
                </li>

                <li class="header__mobile-nav-item">
                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.reports')); ?>"
                        class="header__mobile-nav-link <?php echo ($currentPage === 'reports') ? 'header__mobile-nav-link--active' : ''; ?>">
                        <span class="header__mobile-nav-icon">📈</span>
                        <span class="header__mobile-nav-text"><?php p($l->t('Reports')); ?></span>
                    </a>
                </li>

                <li class="header__mobile-nav-item">
                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.calendar')); ?>"
                        class="header__mobile-nav-link <?php echo ($currentPage === 'calendar') ? 'header__mobile-nav-link--active' : ''; ?>">
                        <span class="header__mobile-nav-icon">📅</span>
                        <span class="header__mobile-nav-text"><?php p($l->t('Calendar')); ?></span>
                    </a>
                </li>

                <li class="header__mobile-nav-item">
                    <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.settings')); ?>"
                        class="header__mobile-nav-link <?php echo ($currentPage === 'settings') ? 'header__mobile-nav-link--active' : ''; ?>">
                        <span class="header__mobile-nav-icon">⚙️</span>
                        <span class="header__mobile-nav-text"><?php p($l->t('Settings')); ?></span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Mobile Quick Actions -->
        <div class="header__mobile-actions">
            <button type="button"
                id="mobile-quick-clock-btn"
                class="header__mobile-action-btn header__mobile-action-btn--primary"
                aria-label="<?php p($l->t('Clock In/Out')); ?>"
                title="<?php p($l->t('Clock In/Out')); ?>">
                <span class="header__mobile-action-icon">▶️</span>
                <span class="header__mobile-action-text"><?php p($l->t('Clock In')); ?></span>
            </button>

            <button type="button"
                id="mobile-quick-entry-btn"
                class="header__mobile-action-btn"
                aria-label="<?php p($l->t('Add Time Entry')); ?>"
                title="<?php p($l->t('Add Time Entry')); ?>">
                <span class="header__mobile-action-icon">➕</span>
                <span class="header__mobile-action-text"><?php p($l->t('Add Entry')); ?></span>
            </button>
        </div>
    </div>
</header>

<script nonce="<?php p($_['cspNonce'] ?? '') ?>">
    // Header functionality
    document.addEventListener('DOMContentLoaded', function() {
        const header = document.querySelector('.header');
        if (!header) return;

        const mobileToggle = header.querySelector('.header__mobile-toggle');
        const mobileNav = header.querySelector('.header__mobile-nav');
        const userBtn = header.querySelector('.header__user-btn');
        const userDropdown = header.querySelector('.header__user-dropdown');
        const themeToggle = header.querySelector('.header__theme-toggle');

        // Mobile menu toggle
        if (mobileToggle && mobileNav) {
            mobileToggle.addEventListener('click', function() {
                const isExpanded = this.getAttribute('aria-expanded') === 'true';
                this.setAttribute('aria-expanded', !isExpanded);
                mobileNav.style.display = isExpanded ? 'none' : 'block';
                mobileNav.setAttribute('aria-hidden', isExpanded ? 'true' : 'false');
            });
        }

        // Escape key closes user dropdown and mobile nav (keyboard a11y)
        document.addEventListener('keydown', function(event) {
            if (event.key !== 'Escape') return;
            if (userDropdown && userDropdown.style.display === 'block') {
                userDropdown.style.display = 'none';
                userDropdown.setAttribute('aria-hidden', 'true');
                if (userBtn) userBtn.setAttribute('aria-expanded', 'false');
            }
            if (mobileNav && mobileNav.style.display === 'block') {
                mobileNav.style.display = 'none';
                mobileNav.setAttribute('aria-hidden', 'true');
                if (mobileToggle) mobileToggle.setAttribute('aria-expanded', 'false');
            }
        });

        // User dropdown toggle
        if (userBtn && userDropdown) {
            userBtn.addEventListener('click', function() {
                const isExpanded = this.getAttribute('aria-expanded') === 'true';
                this.setAttribute('aria-expanded', !isExpanded);
                userDropdown.style.display = isExpanded ? 'none' : 'block';
                userDropdown.setAttribute('aria-hidden', isExpanded ? 'true' : 'false');
            });
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            if (userDropdown && userBtn && !header.contains(event.target)) {
                userDropdown.style.display = 'none';
                userDropdown.setAttribute('aria-hidden', 'true');
                userBtn.setAttribute('aria-expanded', 'false');
            }
        });

        // Theme toggle
        if (themeToggle) {
            themeToggle.addEventListener('click', function() {
                const currentTheme = document.documentElement.getAttribute('data-theme');
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

                document.documentElement.setAttribute('data-theme', newTheme);
                localStorage.setItem('arbeitszeitcheck-theme', newTheme);

                // Dispatch theme change event
                window.dispatchEvent(new CustomEvent('theme-changed', {
                    detail: {
                        theme: newTheme
                    }
                }));
            });
        }

        // Initialize theme from localStorage
        const savedTheme = localStorage.getItem('arbeitszeitcheck-theme');
        if (savedTheme) {
            document.documentElement.setAttribute('data-theme', savedTheme);
        }

        // Quick clock in/out action
        const quickClockBtn = document.getElementById('quick-clock-btn');
        const mobileQuickClockBtn = document.getElementById('mobile-quick-clock-btn');

        if (quickClockBtn) {
            quickClockBtn.addEventListener('click', function() {
                // This will be handled by page-specific JavaScript
                window.dispatchEvent(new CustomEvent('quick-clock-action'));
            });
        }

        if (mobileQuickClockBtn) {
            mobileQuickClockBtn.addEventListener('click', function() {
                window.dispatchEvent(new CustomEvent('quick-clock-action'));
            });
        }

        // Quick add entry action
        const quickEntryBtn = document.getElementById('quick-entry-btn');
        const mobileQuickEntryBtn = document.getElementById('mobile-quick-entry-btn');

        if (quickEntryBtn) {
            quickEntryBtn.addEventListener('click', function() {
                window.dispatchEvent(new CustomEvent('quick-add-entry'));
            });
        }

        if (mobileQuickEntryBtn) {
            mobileQuickEntryBtn.addEventListener('click', function() {
                window.dispatchEvent(new CustomEvent('quick-add-entry'));
            });
        }
    });
</script>
