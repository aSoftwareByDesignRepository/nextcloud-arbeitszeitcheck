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
$settings = is_array($settings ?? null) ? $settings : (is_array($_['settings'] ?? null) ? $_['settings'] : []);
$availableGroups = is_array($availableGroups ?? null) ? $availableGroups : (is_array($_['availableGroups'] ?? null) ? $_['availableGroups'] : []);
$availableAppAdmins = is_array($availableAppAdmins ?? null) ? $availableAppAdmins : (is_array($_['availableAppAdmins'] ?? null) ? $_['availableAppAdmins'] : []);
$availableAccessUsers = is_array($availableAccessUsers ?? null) ? $availableAccessUsers : (is_array($_['availableAccessUsers'] ?? null) ? $_['availableAccessUsers'] : []);
$azcSettingsShowCardChrome = !empty($azcSettingsShowCardChrome) || !empty($renderAll);
$monthClosureOn = !empty($settings['monthClosureEnabled']);
?>
                <section class="azc-card admin-settings-section" aria-labelledby="section-month-closure-heading">
                                        <header class="azc-card__header<?php echo empty($azcSettingsShowCardChrome) ? ' azc-card__header--page-title-only' : ''; ?>">
                        <div class="azc-card__header-text">
                            <?php if (!empty($azcSettingsShowCardChrome)): ?>
                            <h2 id="section-month-closure-heading" class="azc-card__title"><?php p($l->t('Month closure (revision-safe)')); ?></h2>
                            <p class="azc-card__lead" id="month-closure-section-intro">
                                <?php p($l->t('Employees seal a calendar month when work is complete. Administrators can reopen a sealed month if corrections are needed.')); ?>
                            </p>
                            <?php else: ?>
                            <h2 id="section-month-closure-heading" class="azc-card__title visually-hidden"><?php p($l->t('Month closure (revision-safe)')); ?></h2>
                            <?php endif; ?>
                        </div>
                    </header>
                    <div class="azc-card__body">
                    <div class="form-group">
                        <div class="form-checkbox">
                            <input type="checkbox"
                                   id="monthClosureEnabled"
                                   name="monthClosureEnabled"
                                   <?php echo $monthClosureOn ? 'checked' : ''; ?>
                                   aria-describedby="month-closure-section-intro monthClosureEnabled-help">
                            <label for="monthClosureEnabled" class="form-label">
                                <?php p($l->t('Enable revision-safe month finalization')); ?>
                            </label>
                        </div>
                        <p id="monthClosureEnabled-help" class="form-help">
                            <?php p($l->t('When enabled, employees can finalize a calendar month to create a tamper-evident snapshot (hash) and PDF. Finalized months stay locked even if this option is turned off later. Reopening a month is limited to administrators.')); ?>
                        </p>
                    </div>
                    <div class="form-group">
                        <label for="monthClosureGraceDaysAfterEom" class="form-label"><?php p($l->t('Grace days after month end')); ?></label>
                        <input type="number"
                            class="form-input"
                            id="monthClosureGraceDaysAfterEom"
                            name="monthClosureGraceDaysAfterEom"
                            min="0"
                            max="90"
                            step="1"
                            value="<?php p((string)($settings['monthClosureGraceDaysAfterEom'] ?? 0)); ?>"
                            aria-describedby="month-closure-section-intro monthClosureGraceDaysAfterEom-help monthClosureGraceDaysAfterEom-editable-note">
                        <p id="monthClosureGraceDaysAfterEom-help" class="form-help">
                            <?php p($l->t('Number of calendar days after the last day of each month for employees to finalize manually. If the month is still open after that, a daily job seals it automatically (same snapshot as manual finalize). Pending time entry or absence approvals block auto-finalization. Use 0 to disable automatic sealing.')); ?>
                        </p>
                        <p id="monthClosureGraceDaysAfterEom-editable-note" class="form-help form-help--note">
                            <?php p($l->t('You can set this even while month finalization is disabled; the value is saved with Save on this page and applies when you enable month finalization above.')); ?>
                        </p>
                    </div>

                    <fieldset class="form-fieldset" aria-labelledby="month-closure-reopen-legend" aria-describedby="month-closure-reopen-intro month-closure-reopen-separate-notice">
                        <legend id="month-closure-reopen-legend" class="form-legend"><?php p($l->t('Reopen a finalized month (admin)')); ?></legend>
                        <p class="form-help form-help--block" id="month-closure-reopen-intro">
                            <?php p($l->t('If a calendar month was finalized by mistake or a correction is required, you can reopen it here as an administrator for the employee whose month should be opened again. Use the search field to select that person (their Nextcloud account). You must enter a reason; the audit log records your administrator action, the reason, and who the change applies to. Previous snapshot rows remain in the database for traceability.')); ?>
                        </p>
                        <p class="form-help form-help--block form-help--note" id="month-closure-reopen-separate-notice">
                            <?php p($l->t('The "Reopen month" button runs immediately and only performs this reopening step. It is not part of Save on this page.')); ?>
                        </p>
                        <div class="form-group month-reopen-user-picker">
                            <label for="monthClosureReopenUserSearch" class="form-label">
                                <?php p($l->t('Employee')); ?>
                                <span class="required-star" aria-hidden="true">*</span>
                            </label>
                            <input type="hidden" id="monthClosureReopenUserId" name="monthClosureReopenUserId" value="" required>
                            <div class="user-picker" id="month-reopen-picker">
                                <input type="search"
                                    id="monthClosureReopenUserSearch"
                                    class="form-input user-picker__search"
                                    autocomplete="off"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    placeholder="<?php p($l->t('Search by name or login…')); ?>"
                                    role="combobox"
                                    aria-autocomplete="list"
                                    aria-expanded="false"
                                    aria-controls="monthClosureReopenUserListbox"
                                    aria-describedby="monthClosureReopenUserSearch-help monthClosureReopenUserStatus"
                                    aria-required="true">
                                <div id="monthClosureReopenUserListbox"
                                    class="user-picker__list"
                                    role="listbox"
                                    hidden
                                    aria-label="<?php p($l->t('Matching users')); ?>"></div>
                                <p id="monthClosureReopenUserStatus" class="azc-sr-only" role="status" aria-live="polite" aria-atomic="true"></p>
                            </div>
                            <p id="monthClosureReopenUserSearch-help" class="form-help">
                                <?php p($l->t('Type at least 2 characters, then select the employee whose finalized month you are reopening.')); ?>
                            </p>
                        </div>
                        <div class="form-row form-row--inline" role="group" aria-labelledby="month-closure-reopen-legend" aria-describedby="month-closure-reopen-intro month-closure-reopen-separate-notice">
                            <div class="form-group">
                                <label for="monthClosureReopenYear" class="form-label"><?php p($l->t('Year')); ?></label>
                                <input type="number" id="monthClosureReopenYear" class="form-input" min="1970" max="2100" step="1" aria-required="true">
                            </div>
                            <div class="form-group">
                                <label for="monthClosureReopenMonth" class="form-label"><?php p($l->t('Month')); ?></label>
                                <input type="number" id="monthClosureReopenMonth" class="form-input" min="1" max="12" step="1" aria-required="true">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="monthClosureReopenReason" class="form-label"><?php p($l->t('Reason (required)')); ?></label>
                            <textarea id="monthClosureReopenReason" class="form-input" rows="3" aria-required="true" aria-describedby="month-closure-reopen-intro"></textarea>
                        </div>
                        <div class="card-actions card-actions--inline">
                            <button type="button" id="monthClosureReopenBtn" class="btn btn--secondary">
                                <?php p($l->t('Reopen month')); ?>
                            </button>
                        </div>
                        <div id="monthClosureReopenLive" class="form-help" role="status" aria-live="polite" aria-atomic="true"></div>
                    </fieldset>
                    </div><!-- /.azc-card__body -->
                </section>

