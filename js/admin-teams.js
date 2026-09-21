/**
 * Admin Teams JavaScript for arbeitszeitcheck app
 * Teams & departments: CRUD, members, managers. WCAG 2.1 AA aware.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Components = window.ArbeitszeitCheckComponents || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};
    const baseUrl = '/apps/arbeitszeitcheck';

    /**
     * Destructive confirmation via AzcComponents.confirmDialog.
     *
     * @param {string} message
     * @param {string} title
     * @param {string} confirmLabel
     * @param {Function} onConfirm - called only when the user explicitly confirms
     */
    async function confirmDestructiveCompat(message, title, confirmLabel, onConfirm) {
        const Utils = window.ArbeitszeitCheckUtils;
        if (!Utils?.confirmDestructiveAction) {
            return;
        }
        const confirmed = await Utils.confirmDestructiveAction({
            title: title || '',
            message: message,
            confirmLabel: confirmLabel || t('Confirm', 'Confirm'),
            variant: 'destructive',
        });
        if (confirmed) {
            onConfirm();
        }
    }

    function t(key, fallback) {
        const map = window.ArbeitszeitCheck && window.ArbeitszeitCheck.teamsL10n;
        if (map && Object.prototype.hasOwnProperty.call(map, key) && map[key] !== undefined && map[key] !== '') {
            return map[key];
        }
        if (typeof window.t === 'function') {
            return window.t('arbeitszeitcheck', key);
        }
        return fallback || key;
    }

    let selectedTeamId = null;
    let teamsTreeData = [];

    function announceStatus(message) {
        const el = document.getElementById('admin-teams-status');
        if (el) {
            el.textContent = message;
        }
    }

    function setLoading(loading) {
        const loadingEl = document.getElementById('teams-loading');
        const emptyEl = document.getElementById('teams-empty');
        const treeEl = document.getElementById('admin-teams-tree');
        if (!treeEl) return;
        if (loadingEl) loadingEl.classList.toggle('hidden', !loading);
        if (emptyEl) emptyEl.classList.add('hidden');
        if (loading) {
            treeEl.querySelectorAll('.teams-tree__list').forEach(n => n.remove());
        }
    }

    function setEmpty(empty) {
        const loadingEl = document.getElementById('teams-loading');
        const emptyEl = document.getElementById('teams-empty');
        if (loadingEl) loadingEl.classList.add('hidden');
        if (emptyEl) emptyEl.classList.toggle('hidden', !empty);
    }

    let useAppTeamsSaving = false;

    function syncUseAppTeamsAria(cb) {
        if (!cb) {
            return;
        }
        cb.setAttribute('aria-checked', cb.checked ? 'true' : 'false');
    }

    function loadUseAppTeams() {
        Utils.ajax(baseUrl + '/api/admin/teams/config/use-app-teams', {
            method: 'GET',
            onSuccess: function(data) {
                const cb = document.getElementById('use-app-teams');
                if (cb) {
                    cb.checked = !!data.useAppTeams;
                    syncUseAppTeamsAria(cb);
                }
            },
            onError: function() {
                const cb = document.getElementById('use-app-teams');
                if (cb) {
                    cb.checked = false;
                    syncUseAppTeamsAria(cb);
                }
            }
        });
    }

    function saveUseAppTeams(checked) {
        const cb = document.getElementById('use-app-teams');
        if (useAppTeamsSaving || !cb) return;
        useAppTeamsSaving = true;
        cb.disabled = true;
        cb.setAttribute('aria-busy', 'true');
        Utils.ajax(baseUrl + '/api/admin/teams/config/use-app-teams', {
            method: 'PUT',
            data: { useAppTeams: !!checked },
            onSuccess: function() {
                useAppTeamsSaving = false;
                if (cb) {
                    cb.disabled = false;
                    cb.removeAttribute('aria-busy');
                    syncUseAppTeamsAria(cb);
                }
                Messaging && Messaging.showSuccess && Messaging.showSuccess(t('Setting saved', 'Setting saved'));
                announceStatus(t('Use app teams setting saved', 'Setting saved'));
            },
            onError: function() {
                useAppTeamsSaving = false;
                if (cb) {
                    cb.disabled = false;
                    cb.removeAttribute('aria-busy');
                    cb.checked = !checked;
                    syncUseAppTeamsAria(cb);
                }
                Messaging && Messaging.showError && Messaging.showError(t('Failed to save setting', 'Failed to save setting'));
            }
        });
    }

    function buildTreeNodes(nodes, depth) {
        if (!nodes || nodes.length === 0) return '';
        depth = depth || 0;
        const indent = depth * 16;
        let html = '<ul class="teams-tree__list" role="group">';
        nodes.forEach(function(node) {
            const name = Utils.escapeHtml ? Utils.escapeHtml(node.name) : String(node.name || '').replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
            const _displayName = node.name || '';
            const editLabel = (t('Edit unit', 'Edit') + ' ' + name).trim();
            const deleteLabel = (t('Delete unit', 'Delete unit') + ' ' + name).trim();
            const hasChildren = node.children && node.children.length > 0;
            const ariaExpanded = hasChildren ? ' aria-expanded="true"' : '';
            const selected = selectedTeamId === node.id ? ' teams-tree__item--selected' : '';
            html += '<li class="teams-tree__item' + selected + '" role="treeitem" tabindex="-1" data-team-id="' + node.id + '"' + ariaExpanded + ' aria-selected="' + (selectedTeamId === node.id) + '" style="padding-left: ' + (indent + 8) + 'px">';
            html += '<span class="teams-tree__label" tabindex="0" role="button">' + name + '</span>';
            html += '<span class="teams-tree__actions" role="group" aria-label="' + (t('Actions for unit', 'Actions')) + '">';
            html += '<button type="button" class="button button--icon teams-tree__edit" data-team-id="' + node.id + '" data-team-name="' + name + '" aria-label="' + editLabel + '"><span class="icon icon-rename" aria-hidden="true"></span></button>';
            html += '<button type="button" class="button button--icon teams-tree__delete" data-team-id="' + node.id + '" data-team-name="' + name + '" aria-label="' + deleteLabel + '"><span class="icon icon-delete" aria-hidden="true"></span></button>';
            html += '</span>';
            if (hasChildren) {
                html += buildTreeNodes(node.children, depth + 1);
            }
            html += '</li>';
        });
        html += '</ul>';
        return html;
    }

    function renderTree(tree) {
        const container = document.getElementById('admin-teams-tree');
        if (!container) return;
        const loadingEl = document.getElementById('teams-loading');
        const emptyEl = document.getElementById('teams-empty');
        if (loadingEl) loadingEl.classList.add('hidden');
        if (emptyEl) emptyEl.classList.add('hidden');
        container.querySelectorAll('.teams-tree__root').forEach(n => n.remove());
        if (!tree || tree.length === 0) {
            setEmpty(true);
            return;
        }
        setEmpty(false);
        const wrap = document.createElement('div');
        wrap.className = 'teams-tree__root';
        wrap.innerHTML = buildTreeNodes(tree);
        container.appendChild(wrap);
        bindTreeEvents(container);
    }

    function bindTreeEvents(container) {
        if (!container) return;
        container.querySelectorAll('.teams-tree__item').forEach(function(item) {
            const id = item.getAttribute('data-team-id');
            if (!id) return;
            const teamId = parseInt(id, 10);
            const label = item.querySelector('.teams-tree__label');
            if (label) {
                label.addEventListener('click', function() { selectTeam(teamId); });
                label.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        selectTeam(teamId);
                    }
                });
            }
            item.querySelectorAll('.teams-tree__edit').forEach(function(btn) {
                btn.addEventListener('click', function(e) { e.stopPropagation(); openEditTeamModal(parseInt(btn.getAttribute('data-team-id'), 10)); });
            });
            item.querySelectorAll('.teams-tree__delete').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const name = (btn.getAttribute('data-team-name') || '').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
                    confirmDeleteTeam(parseInt(btn.getAttribute('data-team-id'), 10), name);
                });
            });
        });
    }

    function selectTeam(id) {
        selectedTeamId = id;
        const detail = document.getElementById('admin-team-detail');
        const nameEl = document.getElementById('team-detail-name');
        const team = findTeamById(teamsTreeData, id);
        if (detail) detail.classList.remove('hidden');
        if (nameEl) nameEl.textContent = team ? team.name : '';
        document.querySelectorAll('.teams-tree__item').forEach(function(item) {
            const tid = item.getAttribute('data-team-id');
            item.classList.toggle('teams-tree__item--selected', tid && parseInt(tid, 10) === id);
            item.setAttribute('aria-selected', tid && parseInt(tid, 10) === id ? 'true' : 'false');
        });
        showTab('members');
        loadTeamMembers(id);
        loadTeamManagers(id);
        announceStatus(t('Unit selected', 'Unit selected'));
    }

    function findTeamById(nodes, id) {
        if (!nodes) return null;
        for (let i = 0; i < nodes.length; i++) {
            if (nodes[i].id === id) return nodes[i];
            const found = findTeamById(nodes[i].children, id);
            if (found) return found;
        }
        return null;
    }

    function showTab(tab) {
        const membersTab = document.getElementById('tab-members');
        const managersTab = document.getElementById('tab-managers');
        const membersPanel = document.getElementById('panel-members');
        const managersPanel = document.getElementById('panel-managers');
        const isMembers = tab === 'members';
        if (membersTab) { membersTab.setAttribute('aria-selected', isMembers ? 'true' : 'false'); }
        if (managersTab) { managersTab.setAttribute('aria-selected', !isMembers ? 'true' : 'false'); }
        if (membersPanel) {
            membersPanel.classList.toggle('hidden', !isMembers);
            membersPanel.hidden = !isMembers;
            membersPanel.setAttribute('aria-hidden', isMembers ? 'false' : 'true');
        }
        if (managersPanel) {
            managersPanel.classList.toggle('hidden', isMembers);
            managersPanel.hidden = isMembers;
            managersPanel.setAttribute('aria-hidden', isMembers ? 'true' : 'false');
        }
    }

    function loadTeams() {
        setLoading(true);
        Utils.ajax(baseUrl + '/api/admin/teams', {
            method: 'GET',
            onSuccess: function(data) {
                setLoading(false);
                if (data.success && data.teams) {
                    teamsTreeData = data.teams;
                    renderTree(data.teams);
                    if (selectedTeamId) {
                        const stillExists = findTeamById(data.teams, selectedTeamId);
                        if (!stillExists) {
                            selectedTeamId = null;
                            const detail = document.getElementById('admin-team-detail');
                            if (detail) detail.classList.add('hidden');
                        } else {
                            selectTeam(selectedTeamId);
                        }
                    }
                } else {
                    setEmpty(true);
                }
            },
            onError: function() {
                setLoading(false);
                setEmpty(true);
                Messaging && Messaging.showError && Messaging.showError(t('Failed to load structure', 'Failed to load structure'));
            }
        });
    }

    function loadTeamMembers(teamId) {
        const list = document.getElementById('team-members-list');
        if (!list) return;
        list.innerHTML = '<li class="team-list__loading">' + t('Loading…', 'Loading…') + '</li>';
        Utils.ajax(baseUrl + '/api/admin/teams/' + teamId + '/members', {
            method: 'GET',
            onSuccess: function(data) {
                list.innerHTML = '';
                if (data.success && data.members && data.members.length) {
                    data.members.forEach(function(m) {
                        const li = document.createElement('li');
                        li.className = 'team-list__item';
                        li.setAttribute('data-user-id', m.userId);
                        const name = document.createElement('span');
                        name.className = 'team-list__name';
                        name.textContent = m.displayName || m.userId;
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'button button--icon team-list__remove';
                        btn.setAttribute('aria-label', t('Remove member', 'Remove') + ' ' + (m.displayName || m.userId));
                        btn.innerHTML = '<span class="icon icon-delete" aria-hidden="true"></span>';
                        btn.addEventListener('click', function() { confirmRemoveMember(teamId, m.userId, m.displayName || m.userId); });
                        li.appendChild(name);
                        li.appendChild(btn);
                        list.appendChild(li);
                    });
                } else {
                    const empty = document.createElement('li');
                    empty.className = 'team-list__empty';
                    empty.textContent = t('No members', 'No members');
                    list.appendChild(empty);
                }
            },
            onError: function() {
                list.innerHTML = '<li class="team-list__error">' + t('Failed to load members', 'Failed to load members') + '</li>';
            }
        });
    }

    function loadTeamManagers(teamId) {
        const list = document.getElementById('team-managers-list');
        if (!list) return;
        list.innerHTML = '<li class="team-list__loading">' + t('Loading…', 'Loading…') + '</li>';
        Utils.ajax(baseUrl + '/api/admin/teams/' + teamId + '/managers', {
            method: 'GET',
            onSuccess: function(data) {
                list.innerHTML = '';
                if (data.success && data.managers && data.managers.length) {
                    data.managers.forEach(function(m) {
                        const li = document.createElement('li');
                        li.className = 'team-list__item';
                        li.setAttribute('data-user-id', m.userId);
                        const name = document.createElement('span');
                        name.className = 'team-list__name';
                        name.textContent = m.displayName || m.userId;
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'button button--icon team-list__remove';
                        btn.setAttribute('aria-label', t('Remove manager', 'Remove') + ' ' + (m.displayName || m.userId));
                        btn.innerHTML = '<span class="icon icon-delete" aria-hidden="true"></span>';
                        btn.addEventListener('click', function() { confirmRemoveManager(teamId, m.userId, m.displayName || m.userId); });
                        li.appendChild(name);
                        li.appendChild(btn);
                        list.appendChild(li);
                    });
                } else {
                    const empty = document.createElement('li');
                    empty.className = 'team-list__empty';
                    empty.textContent = t('No managers', 'No managers');
                    list.appendChild(empty);
                }
            },
            onError: function() {
                list.innerHTML = '<li class="team-list__error">' + t('Failed to load managers', 'Failed to load managers') + '</li>';
            }
        });
    }

    function confirmDeleteTeam(id, name) {
        // Load a small impact summary so the confirmation dialog can explain
        // clearly what will happen (members, managers, sub-teams).
        Utils.ajax(baseUrl + '/api/admin/teams/' + id + '/delete-impact', {
            method: 'GET',
            onSuccess: function(data) {
                if (!data || !data.success || !data.impact) {
                    showSimpleDeleteConfirm(id, name);
                    return;
                }
                showImpactDeleteModal(id, name, data.impact);
            },
            onError: function() {
                // Fallback to a simple confirmation when impact cannot be loaded.
                showSimpleDeleteConfirm(id, name);
            }
        });
    }

    function showSimpleDeleteConfirm(id, name) {
        var message = t('Are you sure you want to delete the unit "%s"? Members and managers will be unassigned.', 'Are you sure you want to delete this unit?').replace('%s', name);
        confirmDestructiveCompat(
            message,
            t('Delete unit', 'Delete unit'),
            t('Delete', 'Delete'),
            function() { deleteTeam(id); }
        );
    }

    function showImpactDeleteModal(id, name, impact) {
        const title = t('Delete unit', 'Delete unit');
        const memberCount = impact.memberCount || 0;
        const managerCount = impact.managerCount || 0;
        const childCount = impact.childTeamCount || 0;

        const heading = t('Delete "%s"?', 'Delete unit').replace('%s', name);
        const intro = t('Deleting this unit will unassign all members and managers from it. Sub-teams must be handled separately.', 'Deleting this unit will unassign all members and managers.');

        const impactList = [
            t('Members in this unit: %s', 'Members in this unit:').replace('%s', String(memberCount)),
            t('Managers in this unit: %s', 'Managers in this unit:').replace('%s', String(managerCount)),
            t('Direct sub-units: %s', 'Direct sub-units:').replace('%s', String(childCount))
        ];

        const content = `
            <div class="modal-section">
                <h2 id="team-delete-title" class="modal-title">${heading}</h2>
                <p id="team-delete-intro" class="modal-text">${intro}</p>
                <ul class="modal-list">
                    <li>${impactList[0]}</li>
                    <li>${impactList[1]}</li>
                    <li>${impactList[2]}</li>
                </ul>
                <p class="modal-text">
                    ${t('This action cannot be undone.', 'This action cannot be undone.')}
                </p>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn--secondary" data-action="close-modal">${t('Cancel', 'Cancel')}</button>
                <button type="button" class="btn btn--primary btn--danger" data-action="confirm-delete-team">
                    ${t('Delete', 'Delete')}
                </button>
            </div>
        `;

        const modal = Components.createModal({
            id: 'modal-delete-team',
            title: title,
            content: content,
            size: 'md',
            closable: true,
            ariaLabelledBy: 'team-delete-title',
            ariaDescribedBy: 'team-delete-intro',
            onClose: function() {
                const el = document.getElementById('modal-delete-team');
                if (el && el.parentNode) {
                    el.parentNode.remove();
                }
            }
        });

        document.body.appendChild(modal);
        Components.openModal('modal-delete-team');

        const modalEl = document.getElementById('modal-delete-team');
        if (!modalEl) {
            return;
        }

        const cancelBtn = modalEl.querySelector('[data-action="close-modal"]');
        const confirmBtn = modalEl.querySelector('[data-action="confirm-delete-team"]');

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                Components.closeModal(modalEl);
            });
        }
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                Components.closeModal(modalEl);
                deleteTeam(id);
            });
            confirmBtn.focus();
        }
    }

    function deleteTeam(id) {
        Utils.ajax(baseUrl + '/api/admin/teams/' + id, {
            method: 'DELETE',
            onSuccess: function() {
                if (selectedTeamId === id) {
                    selectedTeamId = null;
                    var detail = document.getElementById('admin-team-detail');
                    if (detail) detail.classList.add('hidden');
                }
                loadTeams();
                Messaging && Messaging.showSuccess && Messaging.showSuccess(t('Unit deleted', 'Unit deleted'));
                announceStatus(t('Unit deleted', 'Unit deleted'));
            },
            onError: function(err) {
                var msg = (err && err.error) ? err.error : t('Failed to delete unit', 'Failed to delete unit');
                Messaging && Messaging.showError && Messaging.showError(msg);
            }
        });
    }

    function openAddTeamModal(parentId) {
        var title = t('Add unit', 'Add unit');
        var nameLabel = t('Unit name', 'Unit name');
        var parentLabel = t('Parent unit', 'Parent unit');
        var cancelLabel = t('Cancel', 'Cancel');
        var createLabel = t('Create', 'Create');
        var noParent = t('None (top level)', 'None (top level)');
        var options = '<option value="">' + noParent + '</option>';
        function addOptions(nodes, depth) {
            if (!nodes) return;
            depth = depth || 0;
            nodes.forEach(function(n) {
                if (n.id === parentId) return;
                var indent = '';
                for (var i = 0; i < depth; i++) indent += '— ';
                options += '<option value="' + n.id + '">' + (Utils.escapeHtml ? Utils.escapeHtml(indent + n.name) : indent + n.name) + '</option>';
                if (n.children && n.children.length) addOptions(n.children, depth + 1);
            });
        }
        addOptions(teamsTreeData, 0);
        var content = '<form id="form-add-team" class="form">' +
            '<div class="form-group"><label for="new-team-name" class="form-label">' + nameLabel + '</label>' +
            '<input type="text" id="new-team-name" name="name" class="form-input" required autocomplete="off"></div>' +
            '<div class="form-group"><label for="new-team-parent" class="form-label">' + parentLabel + '</label>' +
            '<select id="new-team-parent" name="parentId" class="form-select">' + options + '</select></div>' +
            '<div class="form-actions">' +
            '<button type="button" class="btn btn--secondary" data-action="close-modal">' + cancelLabel + '</button>' +
            '<button type="submit" class="btn btn--primary">' + createLabel + '</button></div></form>';
        var modal = Components.createModal({
            id: 'modal-add-team',
            title: title,
            content: content,
            size: 'md',
            closable: true,
            onClose: function() { document.getElementById('modal-add-team') && document.getElementById('modal-add-team').parentNode && document.getElementById('modal-add-team').parentNode.remove(); }
        });
        document.body.appendChild(modal);
        Components.openModal('modal-add-team');
        var form = document.getElementById('form-add-team');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var name = (form.querySelector('#new-team-name') || {}).value;
                var parentVal = (form.querySelector('#new-team-parent') || {}).value;
                var parentId = parentVal ? parseInt(parentVal, 10) : null;
                if (!name || !name.trim()) return;
                Utils.ajax(baseUrl + '/api/admin/teams', {
                    method: 'POST',
                    data: { name: name.trim(), parentId: parentId, sortOrder: 0 },
                    onSuccess: function(data) {
                        if (data.success) {
                            Components.closeModal(document.getElementById('modal-add-team'));
                            loadTeams();
                            Messaging && Messaging.showSuccess && Messaging.showSuccess(t('Unit created', 'Unit created'));
                            announceStatus(t('Unit created', 'Unit created'));
                        } else {
                            Messaging && Messaging.showError && Messaging.showError(data.error || t('Failed to create unit', 'Failed to create unit'));
                        }
                    },
                    onError: function(err) {
                        Messaging && Messaging.showError && Messaging.showError((err && err.error) || t('Failed to create unit', 'Failed to create unit'));
                    }
                });
            });
        }
        var closeBtn = modal.querySelector('[data-action="close-modal"]');
        if (closeBtn) closeBtn.addEventListener('click', function() { Components.closeModal(modal); });
    }

    function openEditTeamModal(id) {
        var team = findTeamById(teamsTreeData, id);
        if (!team) return;
        var title = t('Edit unit', 'Edit unit');
        var nameLabel = t('Unit name', 'Unit name');
        var parentLabel = t('Parent unit', 'Parent unit');
        var cancelLabel = t('Cancel', 'Cancel');
        var saveLabel = t('Save', 'Save');
        var noParent = t('None (top level)', 'None (top level)');
        var options = '<option value="">' + noParent + '</option>';
        function addOptions(nodes, depth) {
            if (!nodes) return;
            depth = depth || 0;
            nodes.forEach(function(n) {
                if (n.id === id) return;
                var indent = '';
                for (var i = 0; i < depth; i++) indent += '— ';
                options += '<option value="' + n.id + '"' + (n.id === team.parentId ? ' selected' : '') + '>' + (Utils.escapeHtml ? Utils.escapeHtml(indent + n.name) : indent + n.name) + '</option>';
                if (n.children && n.children.length) addOptions(n.children, depth + 1);
            });
        }
        addOptions(teamsTreeData, 0);
        var content = '<form id="form-edit-team" class="form">' +
            '<div class="form-group"><label for="edit-team-name" class="form-label">' + nameLabel + '</label>' +
            '<input type="text" id="edit-team-name" name="name" class="form-input" value="' + (Utils.escapeHtml ? Utils.escapeHtml(team.name) : team.name.replace(/[&<>"']/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; })) + '" required autocomplete="off"></div>' +
            '<div class="form-group"><label for="edit-team-parent" class="form-label">' + parentLabel + '</label>' +
            '<select id="edit-team-parent" name="parentId" class="form-select">' + options + '</select></div>' +
            '<div class="form-actions">' +
            '<button type="button" class="btn btn--secondary" data-action="close-modal">' + cancelLabel + '</button>' +
            '<button type="submit" class="btn btn--primary">' + saveLabel + '</button></div></form>';
        var modal = Components.createModal({
            id: 'modal-edit-team',
            title: title,
            content: content,
            size: 'md',
            closable: true,
            onClose: function() { var m = document.getElementById('modal-edit-team'); if (m && m.parentNode) m.parentNode.remove(); }
        });
        document.body.appendChild(modal);
        Components.openModal('modal-edit-team');
        var form = document.getElementById('form-edit-team');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var name = (form.querySelector('#edit-team-name') || {}).value;
                var parentVal = (form.querySelector('#edit-team-parent') || {}).value;
                var parentId = parentVal ? parseInt(parentVal, 10) : null;
                if (!name || !name.trim()) return;
                Utils.ajax(baseUrl + '/api/admin/teams/' + id, {
                    method: 'PUT',
                    data: { name: name.trim(), parentId: parentId, sortOrder: team.sortOrder || 0 },
                    onSuccess: function(data) {
                        if (data.success) {
                            Components.closeModal(document.getElementById('modal-edit-team'));
                            loadTeams();
                            if (selectedTeamId === id) selectTeam(id);
                            Messaging && Messaging.showSuccess && Messaging.showSuccess(t('Unit updated', 'Unit updated'));
                            announceStatus(t('Unit updated', 'Unit updated'));
                        } else {
                            Messaging && Messaging.showError && Messaging.showError(data.error || t('Failed to update unit', 'Failed to update unit'));
                        }
                    },
                    onError: function(err) {
                        Messaging && Messaging.showError && Messaging.showError((err && err.error) || t('Failed to update unit', 'Failed to update unit'));
                    }
                });
            });
        }
        var closeBtn = modal.querySelector('[data-action="close-modal"]');
        if (closeBtn) closeBtn.addEventListener('click', function() { Components.closeModal(modal); });
    }

    function getAdminUserSearchUrl() {
        const cfg = window.ArbeitszeitCheck && window.ArbeitszeitCheck.teamsConfig;
        return (cfg && cfg.adminUserSearchUrl) ? cfg.adminUserSearchUrl : (baseUrl + '/api/admin/users');
    }

    function getPickerL10n() {
        return {
            loading: t('Loading…', 'Loading…'),
            searchError: t('Failed to load users', 'Failed to load users'),
            noUsersFound: t('No matching users found', 'No matching users found'),
            typeToSearch: t('Type at least 2 characters to search for a person.', 'Type at least 2 characters to search.'),
            employeeSelected: t('Selected: %s', 'Selected: %s'),
            resultsCount: t('%n results', '%n results'),
            moreResults: t('Showing the first %n matches. Keep typing to narrow it down.', 'Showing the first %n matches. Keep typing to narrow it down.'),
            allExcluded: t('Everyone matching your search is already assigned to this unit.', 'No one available to add.'),
        };
    }

    /**
     * @param {number} teamId
     * @param {'member'|'manager'} role
     */
    function openAddTeamPersonModal(teamId, role) {
        const isMember = role === 'member';
        const listUrl = baseUrl + '/api/admin/teams/' + teamId + (isMember ? '/members' : '/managers');
        const postUrl = listUrl;
        const loadFailKey = isMember ? 'Failed to load members' : 'Failed to load managers';
        const modalId = isMember ? 'modal-add-member' : 'modal-add-manager';
        const formId = isMember ? 'form-add-member' : 'form-add-manager';
        const prefix = isMember ? 'add-member' : 'add-manager';
        const title = isMember ? t('Add member', 'Add member') : t('Add manager', 'Add manager');
        const successKey = isMember ? 'Member added' : 'Manager added';
        const failKey = isMember ? 'Failed to add member' : 'Failed to add manager';
        const findLabel = t('Find a person', 'Find a person');
        const findHelp = t('Start typing their name or login, then pick them from the list.', 'Start typing, then select from the list.');
        const cancelLabel = t('Cancel', 'Cancel');
        const addLabel = t('Add', 'Add');
        const selectRequired = t('Please select a person from the search results.', 'Please select a person.');

        Utils.ajax(listUrl, {
            method: 'GET',
            onSuccess: function(listData) {
                const listKey = isMember ? 'members' : 'managers';
                const rows = (listData.success && listData[listKey]) ? listData[listKey] : [];
                const excludeIds = rows.map(function(row) { return row.userId; });

                const esc = Utils.escapeHtml ? Utils.escapeHtml.bind(Utils) : function(s) { return String(s); };
                const content = '<form id="' + formId + '" class="form team-person-form" novalidate>'
                    + '<div class="form-group team-person-form__picker">'
                    + '<label for="' + prefix + '-search" class="form-label">' + esc(findLabel) + ' <span class="required-star" aria-hidden="true">*</span></label>'
                    + '<input type="hidden" id="' + prefix + '-user-id" name="userId" value="" required>'
                    + '<div class="user-picker user-picker--in-modal team-person-form__user-picker" id="' + prefix + '-picker">'
                    + '<input type="search" id="' + prefix + '-search" class="form-input user-picker__search" autocomplete="off" autocapitalize="none" spellcheck="false"'
                    + ' placeholder="' + esc(t('Search by name or login…', 'Search by name or login…')) + '"'
                    + ' role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' + prefix + '-listbox"'
                    + ' aria-describedby="' + prefix + '-help ' + prefix + '-status" aria-required="true">'
                    + '<div id="' + prefix + '-listbox" class="user-picker__list" role="listbox" hidden'
                    + ' aria-label="' + esc(t('Matching users', 'Matching users')) + '"></div>'
                    + '<p id="' + prefix + '-status" class="team-person-form__picker-status azc-sr-only" role="status" aria-live="polite" aria-atomic="true"></p>'
                    + '</div>'
                    + '<p id="' + prefix + '-help" class="form-help">' + esc(findHelp) + '</p>'
                    + '</div>'
                    + '<div class="form-actions">'
                    + '<button type="button" class="btn btn--secondary" data-action="close-modal">' + esc(cancelLabel) + '</button>'
                    + '<button type="submit" class="btn btn--primary">' + esc(addLabel) + '</button>'
                    + '</div></form>';

                let picker = null;
                function teardownTeamPersonPicker() {
                    if (picker && typeof picker.destroy === 'function') {
                        picker.destroy();
                        picker = null;
                    }
                }

                const modal = Components.createModal({
                    id: modalId,
                    title: title,
                    content: content,
                    size: 'md',
                    closable: true,
                    onClose: function() {
                        teardownTeamPersonPicker();
                        const m = document.getElementById(modalId);
                        if (m && m.parentNode) {
                            m.parentNode.removeChild(m);
                        }
                    },
                });
                Components.openModal(modalId);

                const initPicker = window.ArbeitszeitCheck && window.ArbeitszeitCheck.initAdminUserPicker;
                if (typeof initPicker === 'function') {
                    picker = initPicker({
                        hiddenSelector: '#' + prefix + '-user-id',
                        searchSelector: '#' + prefix + '-search',
                        listSelector: '#' + prefix + '-listbox',
                        wrapSelector: '#' + prefix + '-picker',
                        statusSelector: '#' + prefix + '-status',
                        searchUrl: getAdminUserSearchUrl(),
                        limit: 20,
                        minQueryLength: 2,
                        excludeUserIds: excludeIds,
                        idPrefix: prefix + '-user',
                        l10n: getPickerL10n(),
                    });
                }

                if (!picker) {
                    Messaging && Messaging.showError && Messaging.showError(t('Failed to load users', 'Failed to load users'));
                    Components.closeModal(document.getElementById(modalId));
                    return;
                }

                const form = document.getElementById(formId);
                if (form) {
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                        const userId = picker ? picker.getUserId() : String((document.getElementById(prefix + '-user-id') || {}).value || '').trim();
                        if (!userId) {
                            Messaging && Messaging.showError && Messaging.showError(selectRequired);
                            const searchInput = document.getElementById(prefix + '-search');
                            if (searchInput) {
                                searchInput.focus();
                            }
                            return;
                        }
                        Utils.ajax(postUrl, {
                            method: 'POST',
                            data: { userId: userId },
                            onSuccess: function(res) {
                                if (res.success) {
                                    teardownTeamPersonPicker();
                                    Components.closeModal(document.getElementById(modalId));
                                    if (isMember) {
                                        loadTeamMembers(teamId);
                                    } else {
                                        loadTeamManagers(teamId);
                                    }
                                    Messaging && Messaging.showSuccess && Messaging.showSuccess(t(successKey, successKey));
                                    announceStatus(t(successKey, successKey));
                                } else {
                                    Messaging && Messaging.showError && Messaging.showError(res.error || t(failKey, failKey));
                                }
                            },
                            onError: function(err) {
                                Messaging && Messaging.showError && Messaging.showError((err && err.error) || t(failKey, failKey));
                            },
                        });
                    });
                }

                const searchInput = document.getElementById(prefix + '-search');
                if (searchInput) {
                    setTimeout(function() { searchInput.focus(); }, 100);
                }

                const closeBtn = modal.querySelector('[data-action="close-modal"]');
                if (closeBtn) {
                    closeBtn.addEventListener('click', function() {
                        teardownTeamPersonPicker();
                        Components.closeModal(modal);
                    });
                }
            },
            onError: function() {
                Messaging && Messaging.showError && Messaging.showError(t(loadFailKey, loadFailKey));
            },
        });
    }

    function openAddMemberModal(teamId) {
        openAddTeamPersonModal(teamId, 'member');
    }

    function openAddManagerModal(teamId) {
        openAddTeamPersonModal(teamId, 'manager');
    }

    function confirmRemoveMember(teamId, userId, displayName) {
        var message = t('Remove "%s" from this team?', 'Remove this member?').replace('%s', displayName);
        confirmDestructiveCompat(
            message,
            t('Remove member', 'Remove member'),
            t('Remove', 'Remove'),
            function() { removeMember(teamId, userId); }
        );
    }

    function removeMember(teamId, userId) {
        Utils.ajax(baseUrl + '/api/admin/teams/' + teamId + '/members/' + encodeURIComponent(userId), {
            method: 'DELETE',
            onSuccess: function() {
                loadTeamMembers(teamId);
                Messaging && Messaging.showSuccess && Messaging.showSuccess(t('Member removed', 'Member removed'));
                announceStatus(t('Member removed', 'Member removed'));
            },
            onError: function(err) {
                Messaging && Messaging.showError && Messaging.showError((err && err.error) || t('Failed to remove member', 'Failed to remove member'));
            }
        });
    }

    function confirmRemoveManager(teamId, userId, displayName) {
        var message = t('Remove "%s" as manager?', 'Remove this manager?').replace('%s', displayName);
        confirmDestructiveCompat(
            message,
            t('Remove manager', 'Remove manager'),
            t('Remove', 'Remove'),
            function() { removeManager(teamId, userId); }
        );
    }

    function removeManager(teamId, userId) {
        Utils.ajax(baseUrl + '/api/admin/teams/' + teamId + '/managers/' + encodeURIComponent(userId), {
            method: 'DELETE',
            onSuccess: function() {
                loadTeamManagers(teamId);
                Messaging && Messaging.showSuccess && Messaging.showSuccess(t('Manager removed', 'Manager removed'));
                announceStatus(t('Manager removed', 'Manager removed'));
            },
            onError: function(err) {
                Messaging && Messaging.showError && Messaging.showError((err && err.error) || t('Failed to remove manager', 'Failed to remove manager'));
            }
        });
    }

    function bindEvents() {
        var useAppTeamsCb = document.getElementById('use-app-teams');
        if (useAppTeamsCb) {
            useAppTeamsCb.addEventListener('change', function() {
                syncUseAppTeamsAria(useAppTeamsCb);
                saveUseAppTeams(useAppTeamsCb.checked);
            });
        }
        var addTeamBtn = document.getElementById('admin-teams-add');
        if (addTeamBtn) addTeamBtn.addEventListener('click', function() { openAddTeamModal(null); });
        var tabMembers = document.getElementById('tab-members');
        var tabManagers = document.getElementById('tab-managers');
        if (tabMembers) tabMembers.addEventListener('click', function() { showTab('members'); });
        if (tabManagers) tabManagers.addEventListener('click', function() { showTab('managers'); });
        var addMemberBtn = document.getElementById('team-add-member');
        if (addMemberBtn) addMemberBtn.addEventListener('click', function() {
            if (selectedTeamId) openAddMemberModal(selectedTeamId);
        });
        var addManagerBtn = document.getElementById('team-add-manager');
        if (addManagerBtn) addManagerBtn.addEventListener('click', function() {
            if (selectedTeamId) openAddManagerModal(selectedTeamId);
        });
        var addMembersBulkBtn = document.getElementById('team-add-members-bulk');
        if (addMembersBulkBtn) addMembersBulkBtn.addEventListener('click', function() {
            if (selectedTeamId) openBulkAddTeamPeopleModal(selectedTeamId, 'member');
        });
        var addManagersBulkBtn = document.getElementById('team-add-managers-bulk');
        if (addManagersBulkBtn) addManagersBulkBtn.addEventListener('click', function() {
            if (selectedTeamId) openBulkAddTeamPeopleModal(selectedTeamId, 'manager');
        });
    }

    /**
     * Multi-select add for members or managers (F5).
     * @param {number} teamId
     * @param {'member'|'manager'} role
     */
    function openBulkAddTeamPeopleModal(teamId, role) {
        const isMember = role === 'member';
        const listUrl = baseUrl + '/api/admin/teams/' + teamId + (isMember ? '/members' : '/managers');
        const batchUrl = listUrl + '/batch';
        const modalId = isMember ? 'modal-bulk-add-members' : 'modal-bulk-add-managers';
        const formId = isMember ? 'form-bulk-add-members' : 'form-bulk-add-managers';
        const prefix = isMember ? 'bulk-member' : 'bulk-manager';
        const title = isMember
            ? t('Add several people…', 'Add several people…')
            : t('Add several managers…', 'Add several managers…');
        const teamName = (document.getElementById('admin-team-detail-name') || {}).textContent || '';

        Utils.ajax(listUrl, {
            method: 'GET',
            onSuccess: function(listData) {
                const listKey = isMember ? 'members' : 'managers';
                const rows = (listData.success && listData[listKey]) ? listData[listKey] : [];
                const excludeIds = rows.map(function(row) { return row.userId; });
                const esc = Utils.escapeHtml ? Utils.escapeHtml.bind(Utils) : function(s) { return String(s); };

                const content = '<form id="' + formId + '" class="form team-person-form team-person-form--bulk" novalidate>'
                    + '<p class="form-help">' + esc(t('Search and tick people, then confirm to add them to this team.', 'Search and tick people, then confirm.')) + '</p>'
                    + (teamName ? '<p class="form-help"><strong>' + esc(teamName) + '</strong></p>' : '')
                    + '<div class="form-group">'
                    + '<label for="' + prefix + '-search" class="form-label">' + esc(t('Find people', 'Find people')) + '</label>'
                    + '<input type="search" id="' + prefix + '-search" class="form-input" autocomplete="off"'
                    + ' placeholder="' + esc(t('Search by name or login…', 'Search by name or login…')) + '">'
                    + '<div id="' + prefix + '-results" class="team-bulk-results" role="group" aria-label="' + esc(t('Matching users', 'Matching users')) + '"></div>'
                    + '<p id="' + prefix + '-status" class="azc-sr-only" role="status" aria-live="polite"></p>'
                    + '</div>'
                    + '<div id="' + prefix + '-result-callout" class="azc-callout azc-callout--info" role="status" aria-live="polite" hidden></div>'
                    + '<div class="form-actions">'
                    + '<button type="button" class="btn btn--secondary" data-action="close-modal">' + esc(t('Cancel', 'Cancel')) + '</button>'
                    + '<button type="submit" class="btn btn--primary" id="' + prefix + '-submit" disabled>' + esc(t('Add selected', 'Add selected')) + '</button>'
                    + '</div></form>';

                const modal = Components.createModal({
                    id: modalId,
                    title: title,
                    content: content,
                    size: 'md',
                    closable: true,
                    onClose: function() {
                        const m = document.getElementById(modalId);
                        if (m && m.parentNode) {
                            m.parentNode.removeChild(m);
                        }
                    },
                });
                Components.openModal(modalId);

                const selected = new Map();
                const resultsEl = document.getElementById(prefix + '-results');
                const searchEl = document.getElementById(prefix + '-search');
                const submitBtn = document.getElementById(prefix + '-submit');
                const statusEl = document.getElementById(prefix + '-status');
                const calloutEl = document.getElementById(prefix + '-result-callout');
                let searchTimer = null;

                function refreshSubmit() {
                    if (submitBtn) {
                        submitBtn.disabled = selected.size === 0;
                        submitBtn.textContent = selected.size === 0
                            ? t('Add selected', 'Add selected')
                            : t('Add selected (%n)', 'Add selected (%n)').replace('%n', String(selected.size));
                    }
                    if (statusEl) {
                        statusEl.textContent = selected.size === 0
                            ? ''
                            : t('%n selected', '%n selected').replace('%n', String(selected.size));
                    }
                }

                function renderHits(users) {
                    if (!resultsEl) return;
                    resultsEl.innerHTML = '';
                    users.forEach(function(u) {
                        const uid = u.id || u.userId;
                        if (!uid || excludeIds.indexOf(uid) !== -1) return;
                        const label = document.createElement('label');
                        label.className = 'team-bulk-results__item';
                        const cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.checked = selected.has(uid);
                        cb.addEventListener('change', function() {
                            if (cb.checked) {
                                selected.set(uid, u.displayName || uid);
                            } else {
                                selected.delete(uid);
                            }
                            refreshSubmit();
                        });
                        const span = document.createElement('span');
                        span.textContent = (u.displayName || uid) + ' (' + uid + ')';
                        label.appendChild(cb);
                        label.appendChild(span);
                        resultsEl.appendChild(label);
                    });
                    if (!resultsEl.children.length) {
                        resultsEl.textContent = t('No matching users found', 'No matching users found');
                    }
                }

                if (searchEl) {
                    searchEl.addEventListener('input', function() {
                        clearTimeout(searchTimer);
                        const q = searchEl.value.trim();
                        if (q.length < 2) {
                            if (resultsEl) resultsEl.innerHTML = '';
                            return;
                        }
                        searchTimer = setTimeout(function() {
                            Utils.ajax(getAdminUserSearchUrl() + '?q=' + encodeURIComponent(q) + '&picker=1&limit=25', {
                                method: 'GET',
                                onSuccess: function(data) {
                                    const users = (data && data.users) ? data.users : (data && data.data) ? data.data : [];
                                    renderHits(Array.isArray(users) ? users : []);
                                },
                                onError: function() {
                                    if (resultsEl) resultsEl.textContent = t('Failed to load users', 'Failed to load users');
                                }
                            });
                        }, 250);
                    });
                    setTimeout(function() { searchEl.focus(); }, 80);
                }

                const form = document.getElementById(formId);
                if (form) {
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                        const userIds = Array.from(selected.keys());
                        if (!userIds.length) {
                            Messaging && Messaging.showError && Messaging.showError(t('Select at least one person.', 'Select at least one person.'));
                            return;
                        }
                        const confirmMsg = t('Add %1$d people to team “%2$s”?', 'Add %1$d people to this team?')
                            .replace('%1$d', String(userIds.length))
                            .replace('%2$s', teamName || '');
                        if (!window.confirm(confirmMsg)) {
                            return;
                        }
                        Utils.ajax(batchUrl, {
                            method: 'POST',
                            data: { userIds: userIds },
                            onSuccess: function(res) {
                                if (res.success) {
                                    const s = res.summary || {};
                                    const msg = t('Added %1$d, skipped %2$d, failed %3$d.', 'Added %1$d, skipped %2$d, failed %3$d.')
                                        .replace('%1$d', String(s.added ?? 0))
                                        .replace('%2$d', String(s.skipped ?? 0))
                                        .replace('%3$d', String(s.failed ?? 0));
                                    if (calloutEl) {
                                        calloutEl.hidden = false;
                                        calloutEl.textContent = msg;
                                    }
                                    Messaging && Messaging.showSuccess && Messaging.showSuccess(msg);
                                    announceStatus(msg);
                                    if (isMember) {
                                        loadTeamMembers(teamId);
                                    } else {
                                        loadTeamManagers(teamId);
                                    }
                                    selected.clear();
                                    refreshSubmit();
                                    if (resultsEl) resultsEl.innerHTML = '';
                                    if (searchEl) searchEl.value = '';
                                } else {
                                    Messaging && Messaging.showError && Messaging.showError(res.message || res.error || t('Failed to add member', 'Failed'));
                                }
                            },
                            onError: function(err) {
                                Messaging && Messaging.showError && Messaging.showError((err && (err.message || err.error)) || t('Failed to add member', 'Failed'));
                            }
                        });
                    });
                }
            },
            onError: function() {
                Messaging && Messaging.showError && Messaging.showError(t(isMember ? 'Failed to load members' : 'Failed to load managers', 'Failed to load'));
            }
        });
    }

    function init() {
        loadUseAppTeams();
        loadTeams();
        bindEvents();
    }

    if (typeof window !== 'undefined') {
        window.__ArbeitszeitCheckAdminTeamsTestables = {
            confirmDestructiveCompat: confirmDestructiveCompat,
            confirmRemoveMember: confirmRemoveMember,
            confirmRemoveManager: confirmRemoveManager,
            showSimpleDeleteConfirm: showSimpleDeleteConfirm,
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
