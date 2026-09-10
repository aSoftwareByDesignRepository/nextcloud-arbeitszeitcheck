/**
 * Compliance dashboard — manual admin recheck (POST compliance.runCheck).
 *
 * @license AGPL-3.0-or-later
 */
(function() {
    'use strict';

    const t = (msg, fallback) => {
        if (typeof window.t === 'function') {
            return window.t('arbeitszeitcheck', msg);
        }
        return fallback || msg;
    };

    function announcePolite(message) {
        if (window.AzcMessaging?.announcePolite) {
            window.AzcMessaging.announcePolite(message);
            return;
        }
        const region = document.getElementById('azc-live-region');
        if (region) {
            region.textContent = message;
        }
    }

    function announceAssertive(message) {
        if (window.AzcMessaging?.announceAssertive) {
            window.AzcMessaging.announceAssertive(message);
            return;
        }
        const region = document.getElementById('azc-alert-region');
        if (region) {
            region.textContent = message;
        }
    }

    async function runComplianceCheck(btn) {
        const url = btn.getAttribute('data-run-check-url');
        if (!url) {
            return;
        }

        const Utils = window.ArbeitszeitCheckUtils;
        const confirmed = Utils?.confirmDestructiveAction
            ? await Utils.confirmDestructiveAction({
                title: t('Run compliance check?', 'Run compliance check?'),
                message: t(
                    'This starts a manual compliance scan for all users. Review the violations list afterward; no data is changed automatically.',
                    'This starts a manual compliance scan for all users. Review the violations list afterward; no data is changed automatically.'
                ),
                confirmLabel: t('Run check', 'Run check'),
                cancelLabel: t('Cancel', 'Cancel'),
                variant: 'primary',
            })
            : null;
        if (!confirmed) {
            return;
        }

        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
        const label = btn.querySelector('span');
        const prevLabel = label ? label.textContent : '';
        if (label) {
            label.textContent = t('Running check…', 'Running check…');
        }

        const api = window.AzcApi;
        const result = api
            ? await api.fetch(url, { method: 'POST', json: {} })
            : null;

        btn.removeAttribute('aria-busy');
        btn.disabled = false;
        if (label) {
            label.textContent = prevLabel;
        }

        if (window.AzcApi && window.AzcApi.isApiSuccess(result)) {
            const stats = result.data.stats || {};
            const usersChecked = stats.users_checked ?? stats.usersChecked ?? '—';
            const violationsFound = stats.violations_found ?? stats.violationsFound ?? '—';
            const msg = t(
                'Compliance check completed. Users checked: %1$s, new issues found: %2$s.',
                'Compliance check completed. Users checked: %1$s, new issues found: %2$s.'
            )
                .replace('%1$s', String(usersChecked))
                .replace('%2$s', String(violationsFound));
            window.ArbeitszeitCheckMessaging?.showSuccess?.(msg);
            announcePolite(msg);

            const target = btn.getAttribute('data-violations-url');
            if (target && violationsFound && Number(violationsFound) > 0) {
                window.setTimeout(() => {
                    window.location.href = target;
                }, 2000);
            }
            return;
        }

        const err = (result && result.error)
            || (result && result.data && (result.data.error || result.data.message))
            || t('Compliance check failed. Please try again.', 'Compliance check failed. Please try again.');
        window.ArbeitszeitCheckMessaging?.showError?.(err);
        announceAssertive(err);
    }

    function init() {
        const btn = document.getElementById('btn-run-compliance-check');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', () => {
            runComplianceCheck(btn);
        });
    }

    if (typeof window !== 'undefined') {
        window.__ArbeitszeitCheckComplianceDashboardTestables = {
            runComplianceCheck: runComplianceCheck,
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
