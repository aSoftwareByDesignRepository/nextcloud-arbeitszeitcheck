<?php
declare(strict_types=1);

/**
 * Settings template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCP\Util;

/** @var array $_ */
/** @var \OCP\IL10N $l */

// Add common + page-specific styles and scripts
Util::addTranslations('arbeitszeitcheck');
Util::addStyle('arbeitszeitcheck', 'common/colors');
Util::addStyle('arbeitszeitcheck', 'common/typography');
Util::addStyle('arbeitszeitcheck', 'common/base');
Util::addStyle('arbeitszeitcheck', 'common/components');
Util::addStyle('arbeitszeitcheck', 'common/layout');
Util::addStyle('arbeitszeitcheck', 'common/app-layout');
Util::addStyle('arbeitszeitcheck', 'common/utilities');
Util::addStyle('arbeitszeitcheck', 'common/responsive');
Util::addStyle('arbeitszeitcheck', 'common/accessibility');
Util::addStyle('arbeitszeitcheck', 'navigation');
Util::addStyle('arbeitszeitcheck', 'settings');
Util::addScript('arbeitszeitcheck', 'common/utils');
Util::addScript('arbeitszeitcheck', 'settings');

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb-container">
            <nav class="breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
                <ol>
                    <li><a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"><?php p($l->t('Dashboard')); ?></a></li>
                    <li aria-current="page"><?php p($l->t('Settings')); ?></li>
                </ol>
            </nav>
        </div>

        <!-- Page Header -->
        <header class="section page-header-section" aria-labelledby="settings-page-title">
            <div class="header-content">
                <div class="header-text">
                    <h2 id="settings-page-title"><?php p($l->t('Settings')); ?></h2>
                    <p><?php p($l->t('Manage your personal preferences and notification settings')); ?></p>
                </div>
            </div>
        </header>

        <!-- Settings Sections -->
        <section class="section" aria-labelledby="settings-sections-heading" aria-label="<?php p($l->t('Settings options')); ?>">
            <div class="settings-container">
                <!-- Working Time Preferences -->
                <div class="settings-section">
                    <h3 id="settings-sections-heading" class="section-title"><?php p($l->t('Working Time Preferences')); ?></h3>
                    <form id="working-time-settings-form" class="form">
                        <div class="form-group">
                            <div class="form-checkbox">
                                <input type="checkbox" 
                                       id="auto-break-calculation" 
                                       name="auto_break_calculation"
                                       checked
                                       aria-describedby="auto-break-calculation-help">
                                <label for="auto-break-calculation" class="form-label">
                                    <?php p($l->t('Calculate breaks automatically')); ?>
                                </label>
                            </div>
                            <p id="auto-break-calculation-help" class="form-help">
                                <?php p($l->t('The system will automatically calculate when you need to take breaks according to German labor law. For example, if you work more than 6 hours, you must take at least a 30-minute break.')); ?>
                            </p>
                        </div>

                        <div class="card-actions">
                            <button type="submit" 
                                    class="btn btn--primary"
                                    aria-label="<?php p($l->t('Save your preferences')); ?>"
                                    title="<?php p($l->t('Click to save your preferences')); ?>">
                                <?php p($l->t('Save Settings')); ?>
                            </button>
                            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"
                               class="btn btn--secondary"
                               aria-label="<?php p($l->t('Cancel and go back to dashboard')); ?>"
                               title="<?php p($l->t('Click to cancel and go back without saving changes')); ?>">
                                <?php p($l->t('Cancel')); ?>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Notifications -->
                <div class="settings-section">
                    <h3 class="section-title"><?php p($l->t('Notifications')); ?></h3>
                    <form id="notification-settings-form" class="form">
                        <div class="form-group">
                            <div class="form-checkbox">
                                <input type="checkbox" 
                                       id="notifications-enabled" 
                                       name="notifications_enabled"
                                       checked>
                                <label for="notifications-enabled" class="form-label">
                                    <?php p($l->t('Enable Notifications')); ?>
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="form-checkbox">
                                <input type="checkbox" 
                                       id="break-reminders" 
                                       name="break_reminders_enabled"
                                       checked
                                       aria-describedby="break-reminders-help">
                                <label for="break-reminders" class="form-label">
                                    <?php p($l->t('Remind me to take breaks')); ?>
                                </label>
                            </div>
                            <p id="break-reminders-help" class="form-help">
                                <?php p($l->t('Get a notification when it\'s time to take a required break. For example, if you work more than 6 hours, you\'ll get a reminder to take at least a 30-minute break.')); ?>
                            </p>
                        </div>

                        <div class="card-actions">
                            <button type="submit" 
                                    class="btn btn--primary"
                                    aria-label="<?php p($l->t('Save your working time settings')); ?>"
                                    title="<?php p($l->t('Click to save your working time preferences')); ?>">
                                <?php p($l->t('Save Settings')); ?>
                            </button>
                            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"
                               class="btn btn--secondary"
                               aria-label="<?php p($l->t('Cancel and go back to dashboard')); ?>"
                               title="<?php p($l->t('Click to cancel and go back without saving changes')); ?>">
                                <?php p($l->t('Cancel')); ?>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Working Time Model -->
                <div class="settings-section">
                    <h3 class="section-title"><?php p($l->t('Working Time Model')); ?></h3>
                    <div id="working-time-model-info" class="info-box">
                        <p><?php p($l->t('Your working time model, vacation days, and working hours are assigned by your administrator. Contact your administrator if you have questions or need changes.')); ?></p>
                    </div>
                </div>

                <!-- Compliance Information -->
                <div class="settings-section">
                    <h3 class="section-title"><?php p($l->t('Compliance Information')); ?></h3>
                    <div class="info-box">
                        <h4><?php p($l->t('German Labor Law (Arbeitszeitgesetz - ArbZG)')); ?></h4>
                        <ul>
                            <li><?php p($l->t('Maximum working time: 8 hours per day (can be extended to 10 hours)')); ?></li>
                            <li><?php p($l->t('Minimum rest period: 11 hours between working days')); ?></li>
                            <li><?php p($l->t('Mandatory breaks: 30 min after 6 hours, 45 min after 9 hours')); ?></li>
                            <li><?php p($l->t('Sunday work is generally prohibited with exceptions')); ?></li>
                        </ul>
                    </div>
                </div>

                <!-- Data Export -->
                <div class="settings-section">
                    <h3 class="section-title"><?php p($l->t('Data Export')); ?></h3>
                    <p><?php p($l->t('Export your personal data in accordance with GDPR')); ?></p>
                    <div class="form-actions">
                        <a href="<?php print_unescaped($urlGenerator->linkToRoute('arbeitszeitcheck.gdpr.export')); ?>" 
                           class="button secondary">
                            <?php p($l->t('Export My Data')); ?>
                        </a>
                    </div>
                </div>

                <!-- Version Information -->
                <div class="settings-section">
                    <h3 class="section-title"><?php p($l->t('Version Information')); ?></h3>
                    <div class="info-box">
                        <p>
                            <strong><?php p($l->t('ArbeitszeitCheck')); ?></strong>
                            <?php p($l->t('Version:')); ?> <?php p(\OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion('arbeitszeitcheck')); ?>
                        </p>
                        <p><?php p($l->t('German labor law compliant time tracking for Nextcloud')); ?></p>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<!-- Initialize JavaScript -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.page = 'settings';
    
    window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
    window.ArbeitszeitCheck.l10n.settingsSaved = <?php echo json_encode($l->t('Settings saved successfully'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    
    window.ArbeitszeitCheck.apiUrl = {
        updateSettings: <?php echo json_encode($urlGenerator->linkToRoute('arbeitszeitcheck.settings.update'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };
</script>
