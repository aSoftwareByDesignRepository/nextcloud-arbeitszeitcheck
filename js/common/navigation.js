/**
 * Navigation JavaScript for ArbeitszeitCheck App
 * Submenu toggles and keyboard navigation. Mobile drawer uses in-page Menu
 * (`js/common/mobile-nav.js`) — not core #app-navigation-toggle (unreliable /
 * clipped under #content overflow:hidden; see GH #33).
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const APP_ID = 'arbeitszeitcheck';

    function translate(key, fallback) {
        if (typeof window !== 'undefined' && typeof window.t === 'function') {
            const value = window.t(APP_ID, key);
            if (value && value !== key) {
                return value;
            }
        }
        return fallback;
    }

    const Navigation = {
        menuNav: null,

        init() {
            this.menuNav = document.getElementById('app-navigation');
            if (!this.menuNav) {
                return;
            }

            this.setupSubmenuToggles();
            this.setupAccessibility();
        },

        setupSubmenuToggles() {
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
        },

        setupAccessibility() {
            this.menuNav.setAttribute('role', 'navigation');
            this.menuNav.setAttribute('aria-label',
                translate('Main navigation', 'Main navigation'));
            this.setupKeyboardNavigation();
        },

        setupKeyboardNavigation() {
            const navLinks = this.menuNav.querySelectorAll('a, .nav-parent-toggle');

            navLinks.forEach((link, index) => {
                link.addEventListener('keydown', (e) => {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        const nextLink = navLinks[index + 1] || navLinks[0];
                        if (nextLink) {
                            nextLink.focus();
                        }
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        const prevLink = navLinks[index - 1] || navLinks[navLinks.length - 1];
                        if (prevLink) {
                            prevLink.focus();
                        }
                    } else if (e.key === 'Home') {
                        e.preventDefault();
                        navLinks[0].focus();
                    } else if (e.key === 'End') {
                        e.preventDefault();
                        navLinks[navLinks.length - 1].focus();
                    }
                });
            });
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            Navigation.init();
        });
    } else {
        Navigation.init();
    }

    if (typeof window !== 'undefined') {
        window.ArbeitszeitCheckNavigation = Navigation;
    }
})();
