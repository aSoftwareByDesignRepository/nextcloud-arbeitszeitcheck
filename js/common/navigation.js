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

    const NAV_SCROLL_KEY = 'azc-nav-menu-scroll';

    const Navigation = {
        menuNav: null,

        init() {
            this.menuNav = document.getElementById('app-navigation');
            if (!this.menuNav) {
                return;
            }

            this.setupSubmenuToggles();
            this.setupAccessibility();
            this.rememberScrollOnNavigate();
            this.restoreScroll();
        },

        /**
         * The scrolling box is the menu list, not #app-navigation (that one
         * clips). A full page load otherwise resets it to the top.
         */
        scrollport() {
            const menu = this.menuNav.querySelector('.nav-menu');
            return menu || this.menuNav;
        },

        rememberScrollOnNavigate() {
            this.menuNav.addEventListener('click', (e) => {
                const link = e.target && e.target.closest ? e.target.closest('a') : null;
                if (!link || !link.closest('.nav-menu')) {
                    return;
                }
                this.rememberScroll();
            });
        },

        rememberScroll() {
            const el = this.scrollport();
            if (!el) {
                return;
            }
            try {
                sessionStorage.setItem(NAV_SCROLL_KEY, String(el.scrollTop || 0));
            } catch (e) {
                // Private mode or a full quota — the next page just starts at the top.
            }
        },

        restoreScroll() {
            const el = this.scrollport();
            if (!el) {
                document.documentElement.classList.remove('azc-nav-scroll-pending');
                return;
            }
            // The template applies this before first paint. Running it again
            // after DOMContentLoaded is what flashed the top of the menu.
            if (el.dataset.azcNavScrollReady === '1') {
                document.documentElement.classList.remove('azc-nav-scroll-pending');
                return;
            }
            let saved = 0;
            try {
                saved = Number(sessionStorage.getItem(NAV_SCROLL_KEY));
            } catch (e) {
                saved = 0;
            }
            if (Number.isFinite(saved) && saved > 0) {
                el.scrollTop = saved;
            }
            const current = this.menuNav.querySelector(
                '.nav-menu a[aria-current="page"], .nav-menu li.active > a'
            );
            if (current && typeof current.getBoundingClientRect === 'function') {
                const view = el.getBoundingClientRect();
                const item = current.getBoundingClientRect();
                if (view && item && view.height > 0) {
                    if (item.top < view.top) {
                        el.scrollTop -= (view.top - item.top);
                    } else if (item.bottom > view.bottom) {
                        el.scrollTop += (item.bottom - view.bottom);
                    }
                }
            }
            el.dataset.azcNavScrollReady = '1';
            document.documentElement.classList.remove('azc-nav-scroll-pending');
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
