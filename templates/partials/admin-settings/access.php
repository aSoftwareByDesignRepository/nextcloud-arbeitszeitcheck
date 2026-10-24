<?php
declare(strict_types=1);

/**
 * Admin global settings section partial.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var array $settings
 */

/** @var \OCP\IL10N $l */
/** @var array $settings */
/** @var array $availableGroups */
/** @var array $availableAppAdmins */
/** @var array $availableAccessUsers */
$settings = is_array($settings ?? null) ? $settings : (is_array($_['settings'] ?? null) ? $_['settings'] : []);
$availableGroups = isset($availableGroups) && is_array($availableGroups) ? $availableGroups : (is_array($_['availableGroups'] ?? null) ? $_['availableGroups'] : []);
$availableAppAdmins = isset($availableAppAdmins) && is_array($availableAppAdmins) ? $availableAppAdmins : (is_array($_['availableAppAdmins'] ?? null) ? $_['availableAppAdmins'] : []);
$availableAccessUsers = isset($availableAccessUsers) && is_array($availableAccessUsers) ? $availableAccessUsers : (is_array($_['availableAccessUsers'] ?? null) ? $_['availableAccessUsers'] : []);
$azcSettingsShowCardChrome = !empty($azcSettingsShowCardChrome) || !empty($renderAll);
?>
                <section class="azc-card admin-settings-section" aria-labelledby="section-access-heading">
                                        <header class="azc-card__header<?php echo empty($azcSettingsShowCardChrome) ? ' azc-card__header--page-title-only' : ''; ?>">
                        <div class="azc-card__header-text">
                            <?php if (!empty($azcSettingsShowCardChrome)): ?>
                            <h2 id="section-access-heading" class="azc-card__title"><?php p($l->t('Access control')); ?></h2>
                            <p class="azc-card__lead"><?php p($l->t('Choose who may administer ArbeitszeitCheck and who may open the app (Open or Restricted).')); ?></p>
                            <?php else: ?>
                            <h2 id="section-access-heading" class="azc-card__title visually-hidden"><?php p($l->t('Access control')); ?></h2>
                            <?php endif; ?>
                        </div>
                    </header>
                    <div class="azc-card__body">
                    <?php
                    $selectedAppAdmins = is_array($settings['appAdminUserIds'] ?? null) ? $settings['appAdminUserIds'] : [];
                    $accessRestrictionEnabled = !empty($settings['accessRestrictionEnabled']);
                    $selectedAccessUsers = is_array($settings['accessAllowedUserIds'] ?? null) ? $settings['accessAllowedUserIds'] : [];
                    $selectedAccessGroups = is_array($settings['accessAllowedGroups'] ?? null) ? $settings['accessAllowedGroups'] : [];
                    ?>
                    <section class="azc-access-overview" aria-labelledby="section-access-heading">
                        <div class="azc-access-overview__item">
                            <p class="azc-access-overview__label"><?php p($l->t('Access mode')); ?></p>
                            <p id="azcAccessModeSummary" class="azc-access-overview__value">
                                <?php p($accessRestrictionEnabled
                                    ? $l->t('Restricted — only allow-listed users and groups can open the app')
                                    : $l->t('Open — every logged-in Nextcloud user can open ArbeitszeitCheck')); ?>
                            </p>
                        </div>
                        <div class="azc-access-overview__item">
                            <p class="azc-access-overview__label"><?php p($l->t('ArbeitszeitCheck app administrators')); ?></p>
                            <p id="azcAccessAdminsSummary" class="azc-access-overview__value">
                                <?php
                                $selectedAdminCount = count($selectedAppAdmins);
                                p($selectedAdminCount > 0
                                    ? $l->n('%n app admin selected', '%n app admins selected', $selectedAdminCount)
                                    : $l->t('No app admins selected (all Nextcloud admins are allowed).'));
                                ?>
                            </p>
                        </div>
                        <div class="azc-access-overview__item" data-azc-access-summary-panel <?php echo $accessRestrictionEnabled ? '' : 'hidden'; ?>>
                            <p class="azc-access-overview__label"><?php p($l->t('Allowed users')); ?></p>
                            <p id="azcAccessUsersSummary" class="azc-access-overview__value">
                                <?php
                                $selectedUserCount = count($selectedAccessUsers);
                                p($selectedUserCount > 0
                                    ? $l->n('%n user selected', '%n users selected', $selectedUserCount)
                                    : $l->t('No individual users selected.'));
                                ?>
                            </p>
                        </div>
                        <div class="azc-access-overview__item" data-azc-access-summary-panel <?php echo $accessRestrictionEnabled ? '' : 'hidden'; ?>>
                            <p class="azc-access-overview__label"><?php p($l->t('Allowed Nextcloud groups')); ?></p>
                            <p id="azcAccessGroupsSummary" class="azc-access-overview__value">
                                <?php
                                $selectedGroupCount = count($selectedAccessGroups);
                                p($selectedGroupCount > 0
                                    ? $l->n('%n group selected', '%n groups selected', $selectedGroupCount)
                                    : $l->t('No groups selected.'));
                                ?>
                            </p>
                        </div>
                    </section>
                    <div class="form-group azc-settings-step azc-settings-step--access-mode">
                        <fieldset class="azc-access-mode" aria-describedby="accessMode-help">
                            <legend class="form-label"><?php p($l->t('Access mode')); ?></legend>
                            <label class="access-mode-option">
                                <input type="radio"
                                       name="accessRestrictionEnabled"
                                       value="0"
                                       <?php echo !$accessRestrictionEnabled ? 'checked' : ''; ?>>
                                <span><?php p($l->t('Open — every logged-in Nextcloud user can open ArbeitszeitCheck')); ?></span>
                            </label>
                            <label class="access-mode-option">
                                <input type="radio"
                                       name="accessRestrictionEnabled"
                                       value="1"
                                       <?php echo $accessRestrictionEnabled ? 'checked' : ''; ?>>
                                <span><?php p($l->t('Restricted — only allow-listed users and groups can open the app')); ?></span>
                            </label>
                        </fieldset>
                        <p id="accessMode-help" class="form-help">
                            <?php p($l->t('Open shows ArbeitszeitCheck in the menu for everyone. Restricted hides it unless the person is allow-listed. System and delegated app administrators always pass the door. Employee and manager roles still apply after the door.')); ?>
                        </p>
                    </div>
                    <div class="form-group azc-settings-step">
                        <label for="appAdminUsersSearch" class="form-label"><?php p($l->t('ArbeitszeitCheck app administrators')); ?></label>
                        <input type="text"
                               id="appAdminUsersSearch"
                               class="form-input"
                               autocomplete="off"
                               spellcheck="false"
                               placeholder="<?php p($l->t('Search administrators...')); ?>"
                               aria-describedby="appAdminUsers-help appAdminUsers-note appAdminUsersCount">
                        <p id="appAdminUsersCount" class="form-help form-help--note" aria-live="polite">
                            <?php
                            $selectedAdminCount = count($selectedAppAdmins);
                            p($selectedAdminCount > 0
                                ? $l->n('%n app admin selected', '%n app admins selected', $selectedAdminCount)
                                : $l->t('No app admins selected (all Nextcloud admins are allowed).'));
                            ?>
                        </p>
                        <div id="appAdminUsersList" class="access-groups-list" role="group" aria-label="<?php p($l->t('App administrator selection')); ?>">
                            <?php foreach ($availableAppAdmins as $adminOption): ?>
                                <?php
                                $adminId = (string)($adminOption['id'] ?? '');
                                if ($adminId === '') {
                                    continue;
                                }
                                $adminDisplayName = (string)($adminOption['displayName'] ?? $adminId);
                                $isSelectedAdmin = in_array($adminId, $selectedAppAdmins, true);
                                ?>
                                <label class="access-groups-item" data-app-admin-search="<?php p(strtolower($adminDisplayName . ' ' . $adminId)); ?>">
                                    <input type="checkbox"
                                           name="appAdminUserIds[]"
                                           value="<?php p($adminId); ?>"
                                           <?php echo $isSelectedAdmin ? 'checked' : ''; ?>>
                                    <span class="access-groups-item__label"><?php p($adminDisplayName); ?></span>
                                    <span class="access-groups-item__meta"><?php p($adminId); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p id="appAdminUsersEmpty" class="form-help form-help--note" hidden>
                            <?php p($l->t('No matching administrators found for your search.')); ?>
                        </p>
                        <p id="appAdminUsers-help" class="form-help">
                            <?php p($l->t('Nextcloud admins always keep ArbeitszeitCheck admin powers. Add any colleague below to delegate app administration without making them a Nextcloud admin. Search and pick — never type a raw user id.')); ?>
                        </p>
                        <p id="appAdminUsers-note" class="form-help form-help--note">
                            <?php p($l->t('Dedicated app administrators appear in the list together with Nextcloud admins. Use search to find colleagues, then tick them. After saving they can open Administration (and Manager) without Nextcloud admin rights. You cannot remove your own last app-admin seat — ask a Nextcloud admin if you need to step down.')); ?>
                        </p>
                        <div class="user-picker" id="appAdminUsersAddPicker" data-azc-app-admin-add>
                            <div class="user-picker__control">
                                <input type="search" id="appAdminUsersAddSearch" class="form-input user-picker__search"
                                    role="combobox" aria-autocomplete="list" aria-expanded="false"
                                    aria-controls="appAdminUsersAddList"
                                    placeholder="<?php p($l->t('Search colleagues to add as app admins…')); ?>"
                                    autocomplete="off" spellcheck="false">
                            </div>
                            <ul id="appAdminUsersAddList" class="user-picker__list" role="listbox" hidden></ul>
                        </div>
                    </div>
                    <div class="form-group azc-settings-step" data-azc-access-allowlists <?php echo $accessRestrictionEnabled ? '' : 'hidden'; ?>>
                        <label for="accessAllowedUsersSearch" class="form-label"><?php p($l->t('Allowed users')); ?></label>
                        <input type="text"
                               id="accessAllowedUsersSearch"
                               class="form-input"
                               autocomplete="off"
                               spellcheck="false"
                               placeholder="<?php p($l->t('Search people...')); ?>"
                               aria-describedby="accessAllowedUsers-help accessAllowedUsersCount">
                        <p id="accessAllowedUsersCount" class="form-help form-help--note" aria-live="polite">
                            <?php
                            $selectedUserCount = count($selectedAccessUsers);
                            p($selectedUserCount > 0
                                ? $l->n('%n user selected', '%n users selected', $selectedUserCount)
                                : $l->t('No individual users selected.'));
                            ?>
                        </p>
                        <div id="accessAllowedUsersList" class="access-groups-list" role="group" aria-label="<?php p($l->t('Allowed user selection')); ?>">
                            <?php foreach ($availableAccessUsers as $userOption): ?>
                                <?php
                                $uid = (string)($userOption['id'] ?? '');
                                if ($uid === '') {
                                    continue;
                                }
                                $userDisplayName = (string)($userOption['displayName'] ?? $uid);
                                $isSelectedUser = in_array($uid, $selectedAccessUsers, true);
                                ?>
                                <label class="access-groups-item" data-access-user-search="<?php p(strtolower($userDisplayName . ' ' . $uid)); ?>">
                                    <input type="checkbox"
                                           name="accessAllowedUserIds[]"
                                           value="<?php p($uid); ?>"
                                           <?php echo $isSelectedUser ? 'checked' : ''; ?>>
                                    <span class="access-groups-item__label"><?php p($userDisplayName); ?></span>
                                    <span class="access-groups-item__meta"><?php p($uid); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p id="accessAllowedUsersEmpty" class="form-help form-help--note" hidden>
                            <?php p($l->t('No matching people found for your search.')); ?>
                        </p>
                        <p id="accessAllowedUsers-help" class="form-help">
                            <?php p($l->t('Search and pick people. Never type a raw user id. Used together with Allowed groups when Restricted is on. Empty user and group lists fail closed.')); ?>
                        </p>
                        <div class="user-picker" id="accessAllowedUsersAddPicker" data-azc-access-user-add>
                            <div class="user-picker__control">
                                <input type="search" id="accessAllowedUsersAddSearch" class="form-input user-picker__search"
                                    role="combobox" aria-autocomplete="list" aria-expanded="false"
                                    aria-controls="accessAllowedUsersAddList"
                                    placeholder="<?php p($l->t('Search colleagues to allow…')); ?>"
                                    autocomplete="off" spellcheck="false">
                            </div>
                            <ul id="accessAllowedUsersAddList" class="user-picker__list" role="listbox" hidden></ul>
                        </div>
                    </div>
                    <div class="form-group azc-settings-step" data-azc-access-allowlists <?php echo $accessRestrictionEnabled ? '' : 'hidden'; ?>>
                        <label for="accessAllowedGroupsSearch" class="form-label"><?php p($l->t('Allowed Nextcloud groups')); ?></label>
                        <input type="text"
                               id="accessAllowedGroupsSearch"
                               class="form-input"
                               autocomplete="off"
                               spellcheck="false"
                               placeholder="<?php p($l->t('Search groups...')); ?>"
                               aria-describedby="accessAllowedGroups-help accessAllowedGroups-note accessAllowedGroupsCount">
                        <p id="accessAllowedGroupsCount" class="form-help form-help--note" aria-live="polite">
                            <?php
                            $selectedCount = count($selectedAccessGroups);
                            p($selectedCount > 0
                                ? $l->n('%n group selected', '%n groups selected', $selectedCount)
                                : $l->t('No groups selected.'));
                            ?>
                        </p>
                        <div id="accessAllowedGroupsList" class="access-groups-list" role="group" aria-label="<?php p($l->t('Group selection')); ?>">
                            <?php foreach ($availableGroups as $groupOption): ?>
                                <?php
                                $groupId = (string)($groupOption['id'] ?? '');
                                if ($groupId === '') {
                                    continue;
                                }
                                $groupDisplayName = (string)($groupOption['displayName'] ?? $groupId);
                                $isSelected = in_array($groupId, $selectedAccessGroups, true);
                                ?>
                                <label class="access-groups-item" data-access-group-search="<?php p(strtolower($groupDisplayName . ' ' . $groupId)); ?>">
                                    <input type="checkbox"
                                           name="accessAllowedGroups[]"
                                           value="<?php p($groupId); ?>"
                                           <?php echo $isSelected ? 'checked' : ''; ?>>
                                    <span class="access-groups-item__label"><?php p($groupDisplayName); ?></span>
                                    <span class="access-groups-item__meta"><?php p($groupId); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p id="accessAllowedGroupsEmpty" class="form-help form-help--note" hidden>
                            <?php p($l->t('No matching groups found for your search.')); ?>
                        </p>
                        <p id="accessAllowedGroups-help" class="form-help">
                            <?php p($l->t('Search and pick groups. When Restricted is on, only members of these groups or allow-listed users can open the app. Administrators always pass.')); ?>
                        </p>
                        <p id="accessAllowedGroups-note" class="form-help form-help--note">
                            <?php p($l->t('Select one or more groups. The rule applies immediately after saving settings.')); ?>
                        </p>
                    </div>
                    </div><!-- /.azc-card__body -->
                </section>
