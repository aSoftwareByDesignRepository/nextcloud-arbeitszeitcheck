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

    function t(key, fallback) {
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

    function loadUseAppTeams() {
        Utils.ajax(baseUrl + '/api/admin/teams/config/use-app-teams', {
            method: 'GET',
            onSuccess: function(data) {
                const cb = document.getElementById('use-app-teams');
                if (cb) cb.checked = !!data.useAppTeams;
            },
            onError: function() {
                const cb = document.getElementById('use-app-teams');
                if (cb) cb.checked = false;
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
                if (cb) { cb.disabled = false; cb.removeAttribute('aria-busy'); }
                Messaging && Messaging.showSuccess && Messaging.showSuccess(t('Setting saved', 'Setting saved'));
                announceStatus(t('Use app teams setting saved', 'Setting saved'));
            },
            onError: function() {
                useAppTeamsSaving = false;
                if (cb) { cb.disabled = false; cb.removeAttribute('aria-busy'); cb.checked = !checked; }
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
            const name = Utils.escapeHtml ? Utils.escapeHtml(node.name) : String(node.name).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
            const safeName = node.name || '';
            const editLabel = (t('Edit unit', 'Edit') + ' ' + safeName).trim();
            const deleteLabel = (t('Delete unit', 'Delete unit') + ' ' + safeName).trim();
            const hasChildren = node.children && node.children.length > 0;
            const ariaExpanded = hasChildren ? ' aria-expanded="true"' : '';
            const selected = selectedTeamId === node.id ? ' teams-tree__item--selected' : '';
            html += '<li class="teams-tree__item' + selected + '" role="treeitem" tabindex="-1" data-team-id="' + node.id + '"' + ariaExpanded + ' aria-selected="' + (selectedTeamId === node.id) + '" style="padding-left: ' + (indent + 8) + 'px">';
            html += '<span class="teams-tree__label" tabindex="0" role="button">' + name + '</span>';
            html += '<span class="teams-tree__actions" role="group" aria-label="' + (t('Actions for unit', 'Actions')) + '">';
            html += '<button type="button" class="button button--icon teams-tree__edit" data-team-id="' + node.id + '" data-team-name="' + (Utils.escapeHtml ? Utils.escapeHtml(safeName) : safeName) + '" aria-label="' + editLabel + '"><span class="icon icon-rename" aria-hidden="true"></span></button>';
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
            membersPanel.setAttribute('aria-hidden', isMembers ? 'false' : 'true');
        }
        if (managersPanel) {
            managersPanel.classList.toggle('hidden', isMembers);
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
        var message = t('Are you sure you want to delete the unit "%s"? Members and managers will be unassigned.', 'Are you sure you want to delete this unit?').replace('%s', name);
        if (typeof OC !== 'undefined' && OC.dialogs && OC.dialogs.confirmDestructive) {
            OC.dialogs.confirmDestructive(message, t('Delete unit', 'Delete unit'), {
                type: OC.dialogs.YES_NO_BUTTONS,
                confirm: t('Delete', 'Delete'),
                confirmClasses: 'error',
                cancel: t('Cancel', 'Cancel')
            }).then(function(confirmed) {
                if (confirmed) deleteTeam(id);
            });
        } else if (window.confirm(message)) {
            deleteTeam(id);
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

    function openAddMemberModal(teamId) {
        Utils.ajax(baseUrl + '/api/admin/teams/' + teamId + '/members', {
            method: 'GET',
            onSuccess: function(membersData) {
                var excludeIds = (membersData.success && membersData.members) ? membersData.members.map(function(m) { return m.userId; }) : [];
                Utils.ajax(baseUrl + '/api/admin/users', { method: 'GET', onSuccess: function(data) {
                    if (!data.success || !data.users) {
                        Messaging && Messaging.showError && Messaging.showError(t('No users available', 'No users available'));
                        return;
                    }
                    var users = data.users.filter(function(u) { return excludeIds.indexOf(u.userId) === -1; });
                    if (!users.length) {
                        Messaging && Messaging.showError && Messaging.showError(t('All users are already members of this team', 'No users available'));
                        return;
                    }
                    var title = t('Add member', 'Add member');
            var selectLabel = t('Select user', 'Select user');
            var cancelLabel = t('Cancel', 'Cancel');
            var addLabel = t('Add', 'Add');
            var options = users.map(function(u) {
                return '<option value="' + (Utils.escapeHtml ? Utils.escapeHtml(u.userId) : u.userId) + '">' + (Utils.escapeHtml ? Utils.escapeHtml(u.displayName || u.userId) : (u.displayName || u.userId)) + '</option>';
            }).join('');
            var content = '<form id="form-add-member" class="form">' +
                '<div class="form-group"><label for="add-member-user" class="form-label">' + selectLabel + '</label>' +
                '<select id="add-member-user" name="userId" class="form-select" required>' + options + '</select></div>' +
                '<div class="form-actions">' +
                '<button type="button" class="btn btn--secondary" data-action="close-modal">' + cancelLabel + '</button>' +
                '<button type="submit" class="btn btn--primary">' + addLabel + '</button></div></form>';
            var modal = Components.createModal({
                id: 'modal-add-member',
                title: title,
                content: content,
                size: 'md',
                closable: true,
                onClose: function() { var m = document.getElementById('modal-add-member'); if (m && m.parentNode) m.parentNode.remove(); }
            });
            document.body.appendChild(modal);
            Components.openModal('modal-add-member');
            var form = document.getElementById('form-add-member');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var userId = (form.querySelector('#add-member-user') || {}).value;
                    if (!userId) return;
                    Utils.ajax(baseUrl + '/api/admin/teams/' + teamId + '/members', {
                        method: 'POST',
                        data: { userId: userId },
                        onSuccess: function(res) {
                            if (res.success) {
                                Components.closeModal(document.getElementById('modal-add-member'));
                                loadTeamMembers(teamId);
                                Messaging && Messaging.showSuccess && Messaging.showSuccess(t('Member added', 'Member added'));
                                announceStatus(t('Member added', 'Member added'));
                            } else {
                                Messaging && Messaging.showError && Messaging.showError(res.error || t('Failed to add member', 'Failed to add member'));
                            }
                        },
                        onError: function(err) {
                            Messaging && Messaging.showError && Messaging.showError((err && err.error) || t('Failed to add member', 'Failed to add member'));
                        }
                    });
                });
            }
            var closeBtn = modal.querySelector('[data-action="close-modal"]');
            if (closeBtn) closeBtn.addEventListener('click', function() { Components.closeModal(modal); });
        }, onError: function() {
            Messaging && Messaging.showError && Messaging.showError(t('Failed to load users', 'Failed to load users'));
        }});
            }, onError: function() {
                Messaging && Messaging.showError && Messaging.showError(t('Failed to load members', 'Failed to load members'));
            }});
    }

    function openAddManagerModal(teamId) {
        Utils.ajax(baseUrl + '/api/admin/teams/' + teamId + '/managers', {
            method: 'GET',
            onSuccess: function(managersData) {
                var excludeIds = (managersData.success && managersData.managers) ? managersData.managers.map(function(m) { return m.userId; }) : [];
                Utils.ajax(baseUrl + '/api/admin/users', { method: 'GET', onSuccess: function(data) {
            if (!data.success || !data.users) {
                Messaging && Messaging.showError && Messaging.showError(t('No users available', 'No users available'));
                return;
            }
            var users = data.users.filter(function(u) { return excludeIds.indexOf(u.userId) === -1; });
            if (!users.length) {
                Messaging && Messaging.showError && Messaging.showError(t('All users are already managers of this team', 'No users available'));
                return;
            }
            var title = t('Add manager', 'Add manager');
            var selectLabel = t('Select user', 'Select user');
            var cancelLabel = t('Cancel', 'Cancel');
            var addLabel = t('Add', 'Add');
            var options = users.map(function(u) {
                return '<option value="' + (Utils.escapeHtml ? Utils.escapeHtml(u.userId) : u.userId) + '">' + (Utils.escapeHtml ? Utils.escapeHtml(u.displayName || u.userId) : (u.displayName || u.userId)) + '</option>';
            }).join('');
            var content = '<form id="form-add-manager" class="form">' +
                '<div class="form-group"><label for="add-manager-user" class="form-label">' + selectLabel + '</label>' +
                '<select id="add-manager-user" name="userId" class="form-select" required>' + options + '</select></div>' +
                '<div class="form-actions">' +
                '<button type="button" class="btn btn--secondary" data-action="close-modal">' + cancelLabel + '</button>' +
                '<button type="submit" class="btn btn--primary">' + addLabel + '</button></div></form>';
            var modal = Components.createModal({
                id: 'modal-add-manager',
                title: title,
                content: content,
                size: 'md',
                closable: true,
                onClose: function() { var m = document.getElementById('modal-add-manager'); if (m && m.parentNode) m.parentNode.remove(); }
            });
            document.body.appendChild(modal);
            Components.openModal('modal-add-manager');
            var form = document.getElementById('form-add-manager');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var userId = (form.querySelector('#add-manager-user') || {}).value;
                    if (!userId) return;
                    Utils.ajax(baseUrl + '/api/admin/teams/' + teamId + '/managers', {
                        method: 'POST',
                        data: { userId: userId },
                        onSuccess: function(res) {
                            if (res.success) {
                                Components.closeModal(document.getElementById('modal-add-manager'));
                                loadTeamManagers(teamId);
                                Messaging && Messaging.showSuccess && Messaging.showSuccess(t('Manager added', 'Manager added'));
                                announceStatus(t('Manager added', 'Manager added'));
                            } else {
                                Messaging && Messaging.showError && Messaging.showError(res.error || t('Failed to add manager', 'Failed to add manager'));
                            }
                        },
                        onError: function(err) {
                            Messaging && Messaging.showError && Messaging.showError((err && err.error) || t('Failed to add manager', 'Failed to add manager'));
                        }
                    });
                });
            }
            var closeBtn = modal.querySelector('[data-action="close-modal"]');
            if (closeBtn) closeBtn.addEventListener('click', function() { Components.closeModal(modal); });
        }, onError: function() {
            Messaging && Messaging.showError && Messaging.showError(t('Failed to load users', 'Failed to load users'));
        }});
            }, onError: function() {
                Messaging && Messaging.showError && Messaging.showError(t('Failed to load managers', 'Failed to load managers'));
            }});
    }

    function confirmRemoveMember(teamId, userId, displayName) {
        var message = t('Remove "%s" from this team?', 'Remove this member?').replace('%s', displayName);
        if (typeof OC !== 'undefined' && OC.dialogs && OC.dialogs.confirmDestructive) {
            OC.dialogs.confirmDestructive(message, t('Remove member', 'Remove member'), {
                type: OC.dialogs.YES_NO_BUTTONS,
                confirm: t('Remove', 'Remove'),
                confirmClasses: 'error',
                cancel: t('Cancel', 'Cancel')
            }).then(function(confirmed) {
                if (confirmed) removeMember(teamId, userId);
            });
        } else if (window.confirm(message)) {
            removeMember(teamId, userId);
        }
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
        if (typeof OC !== 'undefined' && OC.dialogs && OC.dialogs.confirmDestructive) {
            OC.dialogs.confirmDestructive(message, t('Remove manager', 'Remove manager'), {
                type: OC.dialogs.YES_NO_BUTTONS,
                confirm: t('Remove', 'Remove'),
                confirmClasses: 'error',
                cancel: t('Cancel', 'Cancel')
            }).then(function(confirmed) {
                if (confirmed) removeManager(teamId, userId);
            });
        } else if (window.confirm(message)) {
            removeManager(teamId, userId);
        }
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
    }

    function init() {
        loadUseAppTeams();
        loadTeams();
        bindEvents();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
