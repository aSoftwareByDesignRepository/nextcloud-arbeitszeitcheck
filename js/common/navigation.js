/**
 * Navigation JavaScript for ArbeitszeitCheck App
 * Handles mobile menu toggle and accessibility
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const Navigation = {
        isMobileMenuOpen: false,
        menuToggle: null,
        menuOverlay: null,
        menuNav: null,

        /**
         * Initialize navigation
         */
        init() {
            this.menuToggle = document.getElementById('nav-mobile-toggle');
            this.menuOverlay = document.getElementById('nav-mobile-overlay');
            this.menuNav = document.getElementById('app-navigation');

            if (!this.menuToggle || !this.menuNav) {
                return;
            }

            this.setupEventListeners();
            this.setupAccessibility();
        },

        /**
         * Setup event listeners
         */
        setupEventListeners() {
            // Toggle button click
            this.menuToggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.toggleMobileMenu();
            });

            // Overlay click - close menu
            if (this.menuOverlay) {
                this.menuOverlay.addEventListener('click', () => {
                    this.closeMobileMenu();
                });
            }

            // Close menu when clicking on navigation links
            const navLinks = this.menuNav.querySelectorAll('a');
            navLinks.forEach(link => {
                link.addEventListener('click', () => {
                    // Small delay to allow navigation to start
                    setTimeout(() => {
                        this.closeMobileMenu();
                    }, 100);
                });
            });

            // Generic submenu toggle support (e.g., Manager, Administration)
            const parentToggles = this.menuNav.querySelectorAll('.nav-parent-toggle');
            parentToggles.forEach((toggle) => {
                const submenuId = toggle.getAttribute('aria-controls');
                const submenu = submenuId ? this.menuNav.querySelector(`#${submenuId}`) : null;
                if (!submenu) {
                    return;
                }
                toggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
                    toggle.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                    if (isExpanded) {
                        submenu.setAttribute('hidden', '');
                    } else {
                        submenu.removeAttribute('hidden');
                    }
                });
            });

            // Close menu on Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.isMobileMenuOpen) {
                    this.closeMobileMenu();
                }
            });

            // Close menu when clicking outside
            document.addEventListener('click', (e) => {
                const isClickInsideNav = this.menuNav.contains(e.target);
                const isClickOnToggle = this.menuToggle.contains(e.target);
                const isClickOnOverlay = this.menuOverlay && this.menuOverlay.contains(e.target);

                if (!isClickInsideNav && !isClickOnToggle && !isClickOnOverlay && this.isMobileMenuOpen) {
                    this.closeMobileMenu();
                }
            });

            // Handle window resize
            let resizeTimer;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(() => {
                    // Close mobile menu if window is resized to desktop size
                    if (window.innerWidth > 768 && this.isMobileMenuOpen) {
                        this.closeMobileMenu();
                    }
                }, 250);
            });
        },

        /**
         * Setup accessibility features
         */
        setupAccessibility() {
            // Set proper ARIA attributes
            if (this.menuNav) {
                this.menuNav.setAttribute('role', 'navigation');
                this.menuNav.setAttribute('aria-label', 
                    (typeof window !== 'undefined' && window.t) 
                        ? window.t('arbeitszeitcheck', 'Main navigation')
                        : 'Main navigation');
            }

            // Keyboard navigation support
            this.setupKeyboardNavigation();
        },

        /**
         * Setup keyboard navigation
         */
        setupKeyboardNavigation() {
            const navLinks = this.menuNav.querySelectorAll('a, .nav-parent-toggle');
            
            navLinks.forEach((link, index) => {
                link.addEventListener('keydown', (e) => {
                    // Arrow down - next item
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        const nextLink = navLinks[index + 1] || navLinks[0];
                        if (nextLink) {
                            nextLink.focus();
                        }
                    }
                    // Arrow up - previous item
                    else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        const prevLink = navLinks[index - 1] || navLinks[navLinks.length - 1];
                        if (prevLink) {
                            prevLink.focus();
                        }
                    }
                    // Home - first item
                    else if (e.key === 'Home') {
                        e.preventDefault();
                        navLinks[0].focus();
                    }
                    // End - last item
                    else if (e.key === 'End') {
                        e.preventDefault();
                        navLinks[navLinks.length - 1].focus();
                    }
                });
            });
        },

        /**
         * Toggle mobile menu
         */
        toggleMobileMenu() {
            if (this.isMobileMenuOpen) {
                this.closeMobileMenu();
            } else {
                this.openMobileMenu();
            }
        },

        /**
         * Open mobile menu
         */
        openMobileMenu() {
            this.isMobileMenuOpen = true;

            if (this.menuNav) {
                this.menuNav.classList.add('mobile-open');
            }

            if (this.menuOverlay) {
                this.menuOverlay.classList.add('mobile-open');
                this.menuOverlay.setAttribute('aria-hidden', 'false');
            }

            if (this.menuToggle) {
                this.menuToggle.setAttribute('aria-expanded', 'true');
                this.menuToggle.setAttribute('aria-label',
                    (typeof window !== 'undefined' && window.t)
                        ? window.t('arbeitszeitcheck', 'Close navigation menu')
                        : 'Close navigation menu');
            }

            // Prevent body scroll
            document.body.classList.add('mobile-menu-open');
            document.body.style.overflow = 'hidden';

            // Focus first menu item
            const firstLink = this.menuNav.querySelector('a');
            if (firstLink) {
                setTimeout(() => {
                    firstLink.focus();
                }, 100);
            }
        },

        /**
         * Close mobile menu
         */
        closeMobileMenu() {
            this.isMobileMenuOpen = false;

            if (this.menuNav) {
                this.menuNav.classList.remove('mobile-open');
            }

            if (this.menuOverlay) {
                this.menuOverlay.classList.remove('mobile-open');
                this.menuOverlay.setAttribute('aria-hidden', 'true');
            }

            if (this.menuToggle) {
                this.menuToggle.setAttribute('aria-expanded', 'false');
                this.menuToggle.setAttribute('aria-label',
                    (typeof window !== 'undefined' && window.t)
                        ? window.t('arbeitszeitcheck', 'Open navigation menu')
                        : 'Open navigation menu');
            }

            // Restore body scroll
            document.body.classList.remove('mobile-menu-open');
            document.body.style.overflow = '';

            // Return focus to toggle button
            if (this.menuToggle) {
                setTimeout(() => {
                    this.menuToggle.focus();
                }, 100);
            }
        },

        /**
         * Check if mobile menu is open
         * @returns {boolean}
         */
        getIsMobileMenuOpen() {
            return this.isMobileMenuOpen;
        }
    };

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            Navigation.init();
        });
    } else {
        Navigation.init();
    }

    // Export for use in other modules
    if (typeof window !== 'undefined') {
        window.ArbeitszeitCheckNavigation = Navigation;
    }
})();
