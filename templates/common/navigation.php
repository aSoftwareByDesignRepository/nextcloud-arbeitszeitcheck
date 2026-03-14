<?php

declare(strict_types=1);

/**
 * Common navigation template for the arbeitszeitcheck app
 *
 * Shows Manager link only to users who can actually use it (admin, manager group, or have team members).
 * Substitution requests shown to all (anyone can be selected as substitute).
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCP\Util;

// Ensure navigation scripts load on all pages with sidebar (mobile menu, keyboard nav, SVG icons)
Util::addScript('arbeitszeitcheck', 'common/navigation');
Util::addScript('arbeitszeitcheck', 'common/navigation-icons');

// URL generator and translation must be passed in from the controller
/** @var \OCP\IURLGenerator $urlGenerator */
/** @var \OCP\IL10N $l */
$urlGenerator = $_['urlGenerator'];
$l = $_['l'];

// Get current page to highlight active navigation item
$currentPage = $_SERVER['REQUEST_URI'] ?? '';
$isTimeEntries = strpos($currentPage, '/time-entries') !== false;
$isAbsences = strpos($currentPage, '/absences') !== false;
$isReports = strpos($currentPage, '/reports') !== false;
$isCalendar = strpos($currentPage, '/calendar') !== false;
$isTimeline = strpos($currentPage, '/timeline') !== false;
$isSettings = strpos($currentPage, '/settings') !== false;
$isManagerPage = strpos($currentPage, '/manager') !== false;
$isSubstitutionRequests = strpos($currentPage, '/substitution-requests') !== false;
$isCompliance = strpos($currentPage, '/compliance') !== false;
$isAdmin = strpos($currentPage, '/admin') !== false;
// Finer-grained admin section flags for clear highlighting of sub-items
$isAdminDashboard = strpos($currentPage, '/admin/dashboard') !== false || ($isAdmin && strpos($currentPage, '/admin/') === false);
$isAdminUsers = strpos($currentPage, '/admin/users') !== false;
$isAdminWorkingTimeModels = strpos($currentPage, '/admin/working-time-models') !== false;
$isAdminHolidays = strpos($currentPage, '/admin/holidays') !== false;
$isAdminTeams = strpos($currentPage, '/admin/teams') !== false;
$isAdminAuditLog = strpos($currentPage, '/admin/audit-log') !== false;
$isAdminSettingsPage = strpos($currentPage, '/admin/settings') !== false;
// Dashboard is active if URL contains /dashboard OR if it's the base app URL without any specific section
$isDashboard = strpos($currentPage, '/dashboard') !== false || 
               (!$isTimeEntries && !$isAbsences && !$isReports && !$isCompliance && !$isCalendar && !$isTimeline && !$isSettings && 
                !$isSubstitutionRequests && !$isAdmin && strpos($currentPage, '/apps/arbeitszeitcheck') !== false) && !$isManagerPage;

// Show Substitution requests link only when user has pending requests (where they are the substitute)
$showSubstitutionLink = !empty($_['showSubstitutionLink']);

// Show Manager link only when user can actually access the manager dashboard (admin or has team members)
$showManagerLink = !empty($_['showManagerLink']);

// Show Reports link only when the user can access manager features (manager dashboard) or is an admin.
// This keeps the Reports area strictly limited to managers and administrators.
$showReportsLink = !empty($_['showReportsLink']);
// Admin section visibility (admin navigation)
$showAdminNav = !empty($_['showAdminNav']);
?>

<!-- App layout wrapper: flex container for sidebar + content (desktop), stacked (mobile) -->
<div id="arbeitszeitcheck-app" class="arbeitszeitcheck-app">
<!-- Mobile hamburger menu button -->
<button class="nav-mobile-toggle" 
        id="nav-mobile-toggle" 
        aria-label="<?php p($l->t('Open navigation menu')); ?>" 
        aria-expanded="false"
        aria-controls="app-navigation"
        title="<?php p($l->t('Click to open or close the navigation menu')); ?>">
    <span class="hamburger-line"></span>
    <span class="hamburger-line"></span>
    <span class="hamburger-line"></span>
</button>

<!-- Mobile overlay background -->
<div class="nav-mobile-overlay" id="nav-mobile-overlay" aria-hidden="true"></div>

<div id="app-navigation" role="navigation" aria-label="<?php p($l->t('Main navigation')); ?>">
    <!-- Sidebar Header -->
    <div class="sidebar-header">
        <div class="app-brand">
            <div class="app-icon">
                <i data-lucide="clock" class="lucide-icon" aria-hidden="true"></i>
            </div>
            <div class="app-info">
                <h3><?php p($l->t('ArbeitszeitCheck')); ?></h3>
            </div>
        </div>
    </div>

    <!-- Navigation Menu -->
    <ul class="nav-menu">
        <li class="<?php p($isDashboard ? 'active' : ''); ?>" <?php p($isDashboard ? 'aria-current="page"' : ''); ?>>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.index')); ?>"
               title="<?php p($l->t('Dashboard: See your current work status, today\'s hours, and recent time entries')); ?>"
               aria-label="<?php p($l->t('Go to dashboard to see your work status and today\'s hours')); ?>">
                <i data-lucide="home" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Dashboard')); ?></span>
            </a>
        </li>
        <li class="<?php p($isTimeEntries ? 'active' : ''); ?>" <?php p($isTimeEntries ? 'aria-current="page"' : ''); ?>>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
               title="<?php p($l->t('Time Entries: View, add, edit, or delete all your working time records')); ?>"
               aria-label="<?php p($l->t('Go to time entries to see all your working time records')); ?>">
                <i data-lucide="clock" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Time Entries')); ?></span>
            </a>
        </li>
        <li class="<?php p($isAbsences ? 'active' : ''); ?>" <?php p($isAbsences ? 'aria-current="page"' : ''); ?>>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.absences')); ?>"
               title="<?php p($l->t('Absences: Request and manage vacation days, sick leave, and other time off')); ?>"
               aria-label="<?php p($l->t('Go to absences to request vacation or sick leave')); ?>">
                <i data-lucide="calendar-off" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Absences')); ?></span>
            </a>
        </li>
        <?php if ($showReportsLink): ?>
        <li class="<?php p($isReports ? 'active' : ''); ?>" <?php p($isReports ? 'aria-current="page"' : ''); ?>>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.reports')); ?>"
               title="<?php p($l->t('Reports for team and organisation summaries (visible to managers and admins only)')); ?>"
               aria-label="<?php p($l->t('Go to reports to create summaries for team or organisation (managers/admins only)')); ?>">
                <i data-lucide="file-text" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Reports (Manager/Admin)')); ?></span>
            </a>
        </li>
        <?php endif; ?>
        <li class="<?php p($isCompliance ? 'active' : ''); ?>" <?php p($isCompliance ? 'aria-current="page"' : ''); ?>>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.compliance.dashboard')); ?>"
               title="<?php p($l->t('Compliance: Check if your working time follows German labor law')); ?>"
               aria-label="<?php p($l->t('Go to compliance to check working time rules')); ?>">
                <i data-lucide="shield-check" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Compliance')); ?></span>
            </a>
        </li>
        <li class="<?php p($isCalendar ? 'active' : ''); ?>" <?php p($isCalendar ? 'aria-current="page"' : ''); ?>>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.calendar')); ?>"
               title="<?php p($l->t('Calendar: View your working time and absences in a calendar view')); ?>"
               aria-label="<?php p($l->t('Go to calendar to see your working time in a calendar')); ?>">
                <i data-lucide="calendar" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Calendar')); ?></span>
            </a>
        </li>
        <li class="<?php p($isTimeline ? 'active' : ''); ?>" <?php p($isTimeline ? 'aria-current="page"' : ''); ?>>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeline')); ?>"
               title="<?php p($l->t('Timeline: See your working time history in chronological order')); ?>"
               aria-label="<?php p($l->t('Go to timeline to see your working time history')); ?>">
                <i data-lucide="activity" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Timeline')); ?></span>
            </a>
        </li>
        <li class="nav-section-divider" role="separator" aria-hidden="true"></li>
        <li class="<?php p($isSettings ? 'active' : ''); ?>" <?php p($isSettings ? 'aria-current="page"' : ''); ?>>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.settings')); ?>"
               title="<?php p($l->t('Meine Einstellungen: Persönliche Ansichten und Benachrichtigungen anpassen')); ?>"
               aria-label="<?php p($l->t('Zu „Meine Einstellungen“ wechseln, um persönliche Optionen zu ändern')); ?>">
                <i data-lucide="settings" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Meine Einstellungen')); ?></span>
            </a>
        </li>
        <?php if ($showAdminNav): ?>
        <li class="nav-section-divider" role="separator" aria-hidden="true"></li>
        <li class="nav-item-has-children <?php p($isAdmin ? 'is-open' : ''); ?>">
            <button class="nav-parent-toggle"
                    type="button"
                    aria-expanded="<?php p($isAdmin ? 'true' : 'false'); ?>"
                    aria-controls="admin-subnav">
                <i data-lucide="shield" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Administration')); ?></span>
            </button>
            <ul id="admin-subnav" class="nav-submenu" <?php p($isAdmin ? '' : 'hidden'); ?>>
                <li class="<?php p($isAdminDashboard ? 'active' : ''); ?>" <?php p($isAdminDashboard ? 'aria-current="page"' : ''); ?>>
                    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.dashboard')); ?>"
                       title="<?php p($l->t('Overview with metrics and current issues')); ?>"
                       aria-label="<?php p($l->t('Open administration overview')); ?>">
                        <span><?php p($l->t('Overview')); ?></span>
                    </a>
                </li>
                <li class="<?php p($isAdminUsers ? 'active' : ''); ?>" <?php p($isAdminUsers ? 'aria-current="page"' : ''); ?>>
                    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.users')); ?>"
                       title="<?php p($l->t('Mitarbeitende & Arbeitszeitmodelle verwalten')); ?>"
                       aria-label="<?php p($l->t('Mitarbeitende verwalten')); ?>">
                        <span><?php p($l->t('Mitarbeitende')); ?></span>
                    </a>
                </li>
                <li class="<?php p($isAdminWorkingTimeModels ? 'active' : ''); ?>" <?php p($isAdminWorkingTimeModels ? 'aria-current="page"' : ''); ?>>
                    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.workingTimeModels')); ?>"
                       title="<?php p($l->t('Configure working time models')); ?>"
                       aria-label="<?php p($l->t('Manage working time models')); ?>">
                        <span><?php p($l->t('Working time models')); ?></span>
                    </a>
                </li>
                <li class="<?php p($isAdminHolidays ? 'active' : ''); ?>" <?php p($isAdminHolidays ? 'aria-current="page"' : ''); ?>>
                    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.holidays')); ?>"
                       title="<?php p($l->t('Manage holiday calendar per state and default calendar')); ?>"
                       aria-label="<?php p($l->t('Manage holidays and calendar')); ?>">
                        <span><?php p($l->t('Holidays & calendar')); ?></span>
                    </a>
                </li>
                <li class="<?php p($isAdminTeams ? 'active' : ''); ?>" <?php p($isAdminTeams ? 'aria-current="page"' : ''); ?>>
                    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.teams')); ?>"
                       title="<?php p($l->t('Manage teams, locations and responsibilities')); ?>"
                       aria-label="<?php p($l->t('Manage teams')); ?>">
                        <span><?php p($l->t('Teams & locations')); ?></span>
                    </a>
                </li>
                <li class="<?php p($isAdminAuditLog ? 'active' : ''); ?>" <?php p($isAdminAuditLog ? 'aria-current="page"' : ''); ?>>
                    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.auditLog')); ?>"
                       title="<?php p($l->t('Aktivitäten- und Änderungsprotokoll einsehen')); ?>"
                       aria-label="<?php p($l->t('Protokolle einsehen')); ?>">
                        <span><?php p($l->t('Protokoll')); ?></span>
                    </a>
                </li>
                <li class="<?php p($isAdminSettingsPage ? 'active' : ''); ?>" <?php p($isAdminSettingsPage ? 'aria-current="page"' : ''); ?>>
                    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.settings')); ?>"
                       title="<?php p($l->t('Manage global rules, notifications and compliance settings')); ?>"
                       aria-label="<?php p($l->t('Open global administration settings')); ?>">
                        <span><?php p($l->t('Global settings')); ?></span>
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>
        <?php if ($showManagerLink): ?>
        <li class="nav-section-divider" role="separator" aria-hidden="true"></li>
        <li class="<?php p($isManagerPage ? 'active' : ''); ?>" <?php p($isManagerPage ? 'aria-current="page"' : ''); ?>>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.manager.dashboard')); ?>"
               title="<?php p($l->t('Manager: Approve absences and time corrections, see team overview')); ?>"
               aria-label="<?php p($l->t('Go to manager dashboard to approve requests and see your team')); ?>">
                <i data-lucide="users" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Manager')); ?></span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (!$showManagerLink && $showSubstitutionLink): ?>
        <li class="nav-section-divider" role="separator" aria-hidden="true"></li>
        <?php endif; ?>
        <?php if ($showSubstitutionLink): ?>
        <li class="<?php p($isSubstitutionRequests ? 'active' : ''); ?>" <?php p($isSubstitutionRequests ? 'aria-current="page"' : ''); ?>>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.substitute.index')); ?>"
               title="<?php p($l->t('Substitution requests: Approve or decline requests to cover for colleagues')); ?>"
               aria-label="<?php p($l->t('Go to substitution requests')); ?>">
                <i data-lucide="user-check" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Substitution requests')); ?></span>
            </a>
        </li>
        <?php endif; ?>
    </ul>
</div>

<!-- Icon rendering now handled by bundled JS: js/common/navigation-icons.js -->

