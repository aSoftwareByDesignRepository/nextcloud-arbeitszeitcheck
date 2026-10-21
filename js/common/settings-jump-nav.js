/**
 * Settings page jump navigation — highlights the active section (WCAG 2.4.1).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const SECTION_SELECTOR = '.admin-settings-section, .azc-settings-section, .azc-admin-notifications-section';

	function resolveSection(headingId) {
		const heading = document.getElementById(headingId);
		if (!heading) {
			return null;
		}
		return heading.closest(SECTION_SELECTOR) || heading;
	}

	function setActiveLink(links, activeLink) {
		links.forEach((link) => {
			const isActive = link === activeLink;
			if (isActive) {
				link.setAttribute('aria-current', 'location');
			} else {
				link.removeAttribute('aria-current');
			}
		});
	}

	function initSettingsJumpNav() {
		const nav = document.querySelector('.azc-jump-nav');
		if (!nav) {
			return;
		}

		const links = Array.from(nav.querySelectorAll('.azc-jump-nav__link[href^="#"]'));
		if (links.length === 0) {
			return;
		}

		const entries = [];
		links.forEach((link) => {
			const hash = link.getAttribute('href');
			if (!hash || hash.charAt(0) !== '#') {
				return;
			}
			const id = hash.slice(1);
			const section = resolveSection(id);
			if (section) {
				entries.push({ link, section, id });
			}
		});

		if (entries.length === 0) {
			return;
		}

		function activateForId(id) {
			const match = entries.find((e) => e.id === id);
			if (match) {
				setActiveLink(links, match.link);
			}
		}

		links.forEach((link) => {
			link.addEventListener('click', () => {
				const id = (link.getAttribute('href') || '').slice(1);
				if (id) {
					activateForId(id);
				}
			});
		});

		if (window.location.hash) {
			activateForId(window.location.hash.slice(1));
		}

		window.addEventListener('hashchange', (ev) => {
			// Prefer the event's target URL: a deferred write elsewhere may
			// already have rewritten location.hash before this listener ran.
			let href = window.location.hash;
			try {
				if (ev && typeof ev.newURL === 'string' && ev.newURL !== '' && typeof URL === 'function') {
					href = new URL(ev.newURL).hash;
				}
			} catch (e) {
				/* fall back to location.hash */
			}
			if (href) {
				activateForId(href.slice(1));
			}
		});

		if (typeof IntersectionObserver !== 'function') {
			return;
		}

		const visible = new Map();
		const observer = new IntersectionObserver(
			(observations) => {
				observations.forEach((obs) => {
					visible.set(obs.target, obs.intersectionRatio);
				});
				let best = null;
				let bestRatio = 0;
				entries.forEach((entry) => {
					const ratio = visible.get(entry.section) || 0;
					if (ratio > bestRatio) {
						bestRatio = ratio;
						best = entry;
					}
				});
				if (best && bestRatio > 0) {
					setActiveLink(links, best.link);
				}
			},
			{
				root: null,
				rootMargin: '-20% 0px -55% 0px',
				threshold: [0, 0.1, 0.25, 0.5, 0.75, 1],
			},
		);

		entries.forEach((entry) => observer.observe(entry.section));
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initSettingsJumpNav);
	} else {
		initSettingsJumpNav();
	}
})();
