/**
 * Custom absence types admin page ("weitere betriebliche Fehlzeiten")
 *
 * Inline create/edit panel + toggle/delete actions, same interaction pattern
 * as working-time-models.js: hidden editor card, inflight submit guard,
 * Messaging toasts, field-level errors with aria-invalid.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};

    // resolveUrl is a method using `this` — call it on Utils, not unbound.
    function generateUrl(path) {
        if (Utils.resolveUrl) {
            return Utils.resolveUrl(path);
        }
        if (window.OC && typeof window.OC.generateUrl === 'function') {
            return window.OC.generateUrl(path);
        }
        return path;
    }
    const API = generateUrl('/apps/arbeitszeitcheck/api/admin/absence-types');

    /** Guard against double Enter / double-click save races. */
    let editorSaveInflight = false;

    function msg(key, englishFallback) {
        const v = window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n && window.ArbeitszeitCheck.l10n[key];
        if (v !== undefined && v !== '') {
            return v;
        }
        return englishFallback || '';
    }

    function esc(value) {
        return Utils.escapeHtml ? Utils.escapeHtml(String(value ?? '')) : String(value ?? '');
    }

    function getHost() {
        return document.getElementById('cat-editor-panel');
    }

    function closeEditor() {
        const host = getHost();
        if (!host || editorSaveInflight || host.getAttribute('aria-busy') === 'true') {
            return;
        }
        host.hidden = true;
        host.innerHTML = '';
        host.removeAttribute('aria-busy');
        const createBtn = document.getElementById('cat-create');
        if (createBtn) {
            createBtn.focus();
        }
    }

    function catConfig() {
        return (window.ArbeitszeitCheck && window.ArbeitszeitCheck.catConfig) || { colors: [], icons: [], locales: [] };
    }

    function optionList(values, selected) {
        return (values || []).map(function(v) {
            const sel = v === selected ? ' selected' : '';
            return '<option value="' + esc(v) + '"' + sel + '>' + esc(v) + '</option>';
        }).join('');
    }

    function translationInputs(labelMap) {
        const locales = catConfig().locales || [];
        if (!locales.length) {
            return '';
        }
        const map = labelMap || {};
        return locales.map(function(loc) {
            const code = loc.code;
            const val = map[code] || '';
            return '<div class="form-group cat-i18n-row">'
                + '<label class="form-label" for="cat-i18n-' + esc(code) + '"><code>' + esc(code) + '</code> ' + esc(loc.label) + '</label>'
                + '<input type="text" class="form-input" id="cat-i18n-' + esc(code) + '" name="i18n_' + esc(code) + '" maxlength="80" value="' + esc(val) + '">'
                + '</div>';
        }).join('');
    }

    function renderEditor(mode, typeData) {
        const host = getHost();
        if (!host) {
            return;
        }
        const data = typeData || {};
        const isEdit = mode === 'edit';
        const title = isEdit ? msg('editorEditTitle', 'Edit absence type') : msg('editorCreateTitle', 'New absence type');

        host.innerHTML =
            '<header class="azc-card__header"><div class="azc-card__header-text">'
            + '<h2 id="cat-editor-heading" class="azc-card__title">' + esc(title) + '</h2>'
            + '<p class="azc-card__lead">' + esc(msg('editorLead', 'Set the name, semantics, and appearance. Cancel returns to the list.')) + '</p>'
            + '</div></header>'
            + '<div class="azc-card__body"><form id="cat-form" novalidate>'
            + '<div id="cat-form-errors" class="cat-form-errors" role="alert" hidden></div>'

            + '<div class="form-group">'
            + '<label class="form-label" for="cat-label">' + esc(msg('nameLabel', 'Name')) + ' <span class="form-required" aria-hidden="true">*</span></label>'
            + '<input type="text" class="form-input" id="cat-label" name="label" required maxlength="80" value="' + esc(data.label || '') + '" aria-describedby="cat-label-help">'
            + '<p id="cat-label-help" class="form-help">' + esc(msg('nameHelp', 'Shown to employees when they request an absence.')) + '</p>'
            + '<p class="cat-field-error" data-field="label" role="alert" hidden></p>'
            + '</div>'

            + (isEdit ? '' :
            '<div class="form-group">'
            + '<label class="form-label" for="cat-slug">' + esc(msg('slugLabel', 'Identifier (optional)')) + '</label>'
            + '<input type="text" class="form-input" id="cat-slug" name="slug" maxlength="56" pattern="[a-z0-9_]+" value="" aria-describedby="cat-slug-help">'
            + '<p id="cat-slug-help" class="form-help">' + esc(msg('slugHelp', 'Lowercase letters, numbers, underscores. Empty = generated from the name.')) + '</p>'
            + '<p class="cat-field-error" data-field="code" role="alert" hidden></p>'
            + '</div>')

            + '<div class="form-group form-group--inline"><div class="form-checkbox">'
            + '<input type="checkbox" id="cat-soll-credit" name="sollCredit" value="true"' + (data.sollCredit ? ' checked' : '') + ' aria-describedby="cat-soll-help">'
            + '<label for="cat-soll-credit">' + esc(msg('sollCreditLabel', 'Counts as paid absence')) + '</label>'
            + '</div>'
            + '<p id="cat-soll-help" class="form-help form-help--block">' + esc(msg('sollCreditHelp', 'Planned hours credit the working-time account when the paid-absence credit feature is on.')) + '</p>'
            + '</div>'

            + '<div class="form-group">'
            + '<label class="form-label" for="cat-max-days">' + esc(msg('maxDaysLabel', 'Maximum duration in days')) + '</label>'
            + '<input type="number" class="form-input cat-input--narrow" id="cat-max-days" name="maxDays" min="1" max="3650" step="1" value="' + esc(data.maxDays || 365) + '" aria-describedby="cat-max-days-help">'
            + '<p id="cat-max-days-help" class="form-help">' + esc(msg('maxDaysHelp', 'Longest period employees can request at once.')) + '</p>'
            + '<p class="cat-field-error" data-field="maxDays" role="alert" hidden></p>'
            + '</div>'

            + '<div class="cat-appearance-row">'
            + '<div class="form-group">'
            + '<label class="form-label" for="cat-color">' + esc(msg('colorLabel', 'Calendar color')) + '</label>'
            + '<select class="form-select" id="cat-color" name="color">' + optionList(catConfig().colors, data.color || 'info') + '</select>'
            + '<p class="cat-field-error" data-field="color" role="alert" hidden></p>'
            + '</div>'
            + '<div class="form-group">'
            + '<label class="form-label" for="cat-icon">' + esc(msg('iconLabel', 'Calendar icon')) + '</label>'
            + '<select class="form-select" id="cat-icon" name="icon">' + optionList(catConfig().icons, data.icon || 'calendar') + '</select>'
            + '<p class="cat-field-error" data-field="icon" role="alert" hidden></p>'
            + '</div>'
            + '</div>'

            + (isEdit ?
            '<div class="form-group form-group--inline"><div class="form-checkbox">'
            + '<input type="checkbox" id="cat-active" name="active" value="true"' + (data.active ? ' checked' : '') + '>'
            + '<label for="cat-active">' + esc(msg('activeLabel', 'Available for new requests')) + '</label>'
            + '</div></div>' : '')

            + '<details class="cat-i18n-details">'
            + '<summary>' + esc(msg('translationsLegend', 'Translations (optional)')) + '</summary>'
            + '<p class="form-help form-help--block">' + esc(msg('translationsHelp', 'Employees with another language see these; everyone else sees the name above.')) + '</p>'
            + translationInputs(data.labelMap)
            + '<p class="cat-field-error" data-field="labelMap" role="alert" hidden></p>'
            + '</details>'

            + '<div class="cat-editor-actions">'
            + '<button type="submit" class="azc-btn azc-btn--primary" id="cat-submit">' + esc(msg('save', 'Save')) + '</button>'
            + '<button type="button" class="azc-btn azc-btn--secondary" id="cat-cancel">' + esc(msg('cancel', 'Cancel')) + '</button>'
            + '</div>'
            + '</form></div>';

        host.hidden = false;
        host.scrollIntoView({ behavior: 'smooth', block: 'start' });
        const labelInput = host.querySelector('#cat-label');
        if (labelInput) {
            labelInput.focus();
        }

        host.querySelector('#cat-cancel').addEventListener('click', closeEditor);
        host.querySelector('#cat-form').addEventListener('submit', function(ev) {
            ev.preventDefault();
            submitEditor(host, isEdit ? data.id : null);
        });
    }

    function clearFieldErrors(host) {
        host.querySelectorAll('.cat-field-error').forEach(function(el) {
            el.hidden = true;
            el.textContent = '';
        });
        host.querySelectorAll('[aria-invalid="true"]').forEach(function(el) {
            el.removeAttribute('aria-invalid');
        });
        const banner = host.querySelector('#cat-form-errors');
        if (banner) {
            banner.hidden = true;
            banner.textContent = '';
        }
    }

    function showFieldErrors(host, fields, fallback) {
        const banner = host.querySelector('#cat-form-errors');
        let shown = false;
        const fieldInputs = {
            label: '#cat-label',
            code: '#cat-slug',
            maxDays: '#cat-max-days',
            color: '#cat-color',
            icon: '#cat-icon',
        };
        Object.keys(fields || {}).forEach(function(field) {
            const errorEl = host.querySelector('.cat-field-error[data-field="' + field + '"]');
            const messages = fields[field];
            const text = typeof messages === 'object' ? Object.values(messages).join(' ') : String(messages);
            if (errorEl) {
                errorEl.textContent = text;
                errorEl.hidden = false;
                shown = true;
            }
            const input = host.querySelector(fieldInputs[field]);
            if (input) {
                input.setAttribute('aria-invalid', 'true');
            }
        });
        if (banner) {
            banner.textContent = shown ? (fallback || msg('error', 'An error occurred')) : (fallback || msg('error', 'An error occurred'));
            banner.hidden = false;
        }
    }

    function collectPayload(host) {
        const get = function(sel) { const el = host.querySelector(sel); return el ? el.value : ''; };
        const payload = {
            label: get('#cat-label').trim(),
            maxDays: get('#cat-max-days'),
            color: get('#cat-color'),
            icon: get('#cat-icon'),
            sollCredit: host.querySelector('#cat-soll-credit') && host.querySelector('#cat-soll-credit').checked ? 'true' : 'false',
        };
        const slug = get('#cat-slug').trim();
        if (slug !== '') {
            payload.slug = slug;
        }
        const activeEl = host.querySelector('#cat-active');
        if (activeEl) {
            payload.active = activeEl.checked ? 'true' : 'false';
        }
        const labelMap = {};
        (catConfig().locales || []).forEach(function(loc) {
            const v = get('#cat-i18n-' + loc.code).trim();
            if (v !== '') {
                labelMap[loc.code] = v;
            }
        });
        payload.labelMap = labelMap;
        return payload;
    }

    function submitEditor(host, id) {
        if (editorSaveInflight) {
            return;
        }
        const submitBtn = host.querySelector('#cat-submit');
        if (submitBtn && submitBtn.disabled) {
            return;
        }
        clearFieldErrors(host);
        editorSaveInflight = true;
        host.setAttribute('aria-busy', 'true');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
        }

        const isEdit = id !== null;
        const failKey = isEdit ? 'failedToUpdate' : 'failedToCreate';
        const okKey = isEdit ? 'updated' : 'created';
        const done = function() {
            editorSaveInflight = false;
            host.removeAttribute('aria-busy');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.removeAttribute('aria-busy');
            }
        };

        Utils.ajax(isEdit ? API + '/' + encodeURIComponent(id) : API, {
            method: isEdit ? 'PUT' : 'POST',
            data: collectPayload(host),
            onSuccess: function(response) {
                done();
                if (response && response.success) {
                    if (Messaging.showSuccess) {
                        Messaging.showSuccess(msg(okKey, 'Saved'));
                    }
                    closeEditor();
                    setTimeout(function() { location.reload(); }, 800);
                } else {
                    const fields = response && response.fields ? response.fields : null;
                    if (fields) {
                        showFieldErrors(host, fields, response.error || msg(failKey, 'Save failed'));
                    } else if (Messaging.showError) {
                        Messaging.showError((response && response.error) || msg(failKey, 'Save failed'));
                    }
                }
            },
            onError: function() {
                done();
                if (Messaging.showError) {
                    Messaging.showError(msg(failKey, 'Save failed'));
                }
            }
        });
    }

    function toggleActive(btn) {
        const id = btn.getAttribute('data-type-id');
        const next = btn.getAttribute('data-active') !== '1';
        btn.disabled = true;
        Utils.ajax(API + '/' + encodeURIComponent(id), {
            method: 'PUT',
            data: { active: next ? 'true' : 'false' },
            onSuccess: function(response) {
                btn.disabled = false;
                if (response && response.success) {
                    if (Messaging.showSuccess) {
                        Messaging.showSuccess(msg(next ? 'activated' : 'deactivatedToggle', 'Saved'));
                    }
                    setTimeout(function() { location.reload(); }, 800);
                } else if (Messaging.showError) {
                    Messaging.showError((response && response.error) || msg('failedToToggle', 'Failed'));
                }
            },
            onError: function() {
                btn.disabled = false;
                if (Messaging.showError) {
                    Messaging.showError(msg('failedToToggle', 'Failed'));
                }
            }
        });
    }

    async function confirmDelete(btn) {
        const id = btn.getAttribute('data-type-id');
        const name = btn.getAttribute('data-type-name') || '';
        const inUse = parseInt(btn.getAttribute('data-in-use') || '0', 10) > 0;
        const template = msg(inUse ? 'confirmDelete' : 'confirmDeleteUnused', 'Delete this absence type?');
        const text = template.replace('{name}', name);
        // Accessible confirm — never window.confirm; helper fails closed (§7.3).
        const Components = window.AzcComponents || window.ArbeitszeitCheckComponents;
        if (!Components || typeof Components.confirmDialog !== 'function') {
            if (Messaging.showError) {
                Messaging.showError(msg('confirmUnavailable', 'Could not open the confirmation. Please reload the page and try again.'));
            }
            return;
        }
        const result = await Components.confirmDialog({
            title: msg('confirmDeleteTitle', 'Delete absence type?'),
            message: text,
            confirmLabel: msg('delete', 'Delete'),
            cancelLabel: msg('cancel', 'Cancel'),
            variant: 'destructive',
        });
        if (result !== true && !(result && result.confirmed)) {
            return;
        }
        btn.disabled = true;
        Utils.ajax(API + '/' + encodeURIComponent(id), {
            method: 'DELETE',
            onSuccess: function(response) {
                btn.disabled = false;
                if (response && response.success) {
                    if (Messaging.showSuccess) {
                        Messaging.showSuccess(msg(response.mode === 'deactivated' ? 'deactivated' : 'deleted', 'Done'));
                    }
                    setTimeout(function() { location.reload(); }, 800);
                } else if (Messaging.showError) {
                    Messaging.showError((response && response.error) || msg('failedToDelete', 'Failed'));
                }
            },
            onError: function() {
                btn.disabled = false;
                if (Messaging.showError) {
                    Messaging.showError(msg('failedToDelete', 'Failed'));
                }
            }
        });
    }

    function fetchType(id, cb) {
        Utils.ajax(API + '/' + encodeURIComponent(id), {
            method: 'GET',
            onSuccess: function(response) {
                if (response && response.success && response.type) {
                    cb(response.type);
                } else if (Messaging.showError) {
                    Messaging.showError(msg('error', 'An error occurred'));
                }
            },
            onError: function() {
                if (Messaging.showError) {
                    Messaging.showError(msg('error', 'An error occurred'));
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const createBtn = document.getElementById('cat-create');
        if (createBtn) {
            createBtn.addEventListener('click', function() {
                renderEditor('create', null);
            });
        }

        const tbody = document.getElementById('cat-tbody');
        if (tbody) {
            tbody.addEventListener('click', function(ev) {
                const btn = ev.target.closest('button[data-action]');
                if (!btn) {
                    return;
                }
                const action = btn.getAttribute('data-action');
                if (action === 'cat-edit') {
                    fetchType(btn.getAttribute('data-type-id'), function(type) {
                        renderEditor('edit', type);
                    });
                } else if (action === 'cat-toggle') {
                    toggleActive(btn);
                } else if (action === 'cat-delete') {
                    confirmDelete(btn);
                }
            });
        }
    });
})();
