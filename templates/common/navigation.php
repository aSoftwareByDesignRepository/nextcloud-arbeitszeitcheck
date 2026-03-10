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

// Ensure navigation.js loads on all pages with sidebar (mobile menu, keyboard nav)
Util::addScript('arbeitszeitcheck', 'common/navigation');

// Get URL generator and translation from controller params or OCP API
$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

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
// Dashboard is active if URL contains /dashboard OR if it's the base app URL without any specific section
$isDashboard = strpos($currentPage, '/dashboard') !== false || 
               (!$isTimeEntries && !$isAbsences && !$isReports && !$isCompliance && !$isCalendar && !$isTimeline && !$isSettings && 
                !$isSubstitutionRequests && !$isAdmin && strpos($currentPage, '/apps/arbeitszeitcheck') !== false) && !$isManagerPage;

// Show Substitution requests link only when user has pending requests (where they are the substitute)
$showSubstitutionLink = isset($_['showSubstitutionLink']) ? (bool) $_['showSubstitutionLink'] : null;
if ($showSubstitutionLink === null) {
	$showSubstitutionLink = false;
	try {
		$user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
		if ($user !== null) {
			$absenceMapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class);
			$pending = $absenceMapper->findSubstitutePendingForUser($user->getUID(), 1, 0);
			$showSubstitutionLink = count($pending) > 0;
		}
	} catch (\Throwable $e) {
		$showSubstitutionLink = false;
	}
}

// Show Manager link only when user can actually access the manager dashboard (admin or has team members)
$showManagerLink = isset($_['showManagerLink']) ? (bool) $_['showManagerLink'] : null;
if ($showManagerLink === null) {
	$showManagerLink = false;
	try {
		$user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
		if ($user !== null) {
			$permissionService = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\PermissionService::class);
			$showManagerLink = $permissionService->canAccessManagerDashboard($user->getUID());
		}
	} catch (\Throwable $e) {
		$showManagerLink = false;
	}
}
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
        <li class="<?php p($isReports ? 'active' : ''); ?>" <?php p($isReports ? 'aria-current="page"' : ''); ?>>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.reports')); ?>"
               title="<?php p($l->t('Reports: Create and download reports about your working time')); ?>"
               aria-label="<?php p($l->t('Go to reports to create and download working time reports')); ?>">
                <i data-lucide="file-text" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Reports')); ?></span>
            </a>
        </li>
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
               title="<?php p($l->t('Settings: Change your personal preferences and working time settings')); ?>"
               aria-label="<?php p($l->t('Go to settings to change your preferences')); ?>">
                <i data-lucide="settings" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Settings')); ?></span>
            </a>
        </li>
        <?php 
        $showAdminNav = false;
        try {
            $user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
            if ($user !== null) {
                $showAdminNav = \OCP\Server::get(\OCP\IGroupManager::class)->isAdmin($user->getUID());
            }
        } catch (\Throwable $e) {
            $showAdminNav = false;
        }
        if ($showAdminNav): ?>
        <li class="nav-section-divider" role="separator" aria-hidden="true"></li>
        <li class="<?php p($isAdmin ? 'active' : ''); ?>" <?php p($isAdmin ? 'aria-current="page"' : ''); ?>>
            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.dashboard')); ?>"
               title="<?php p($l->t('Administration: Employees, work schedules, teams, and app settings')); ?>"
               aria-label="<?php p($l->t('Go to administration')); ?>">
                <i data-lucide="shield" class="lucide-icon" aria-hidden="true"></i>
                <span><?php p($l->t('Administration')); ?></span>
            </a>
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

<!-- Initialize Lucide Icons for Navigation -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    // Local SVG icon library for navigation
    const arbeitszeitcheckNavSvgIcons = {
        clock: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>',
        home: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9,22 9,12 15,12 15,22"/></svg>',
        'calendar-off': '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon"><path d="M4.18 4.18A2 2 0 0 0 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 1.82-1.18"/><path d="M21 15.5V6a2 2 0 0 0-2-2H9.5"/><path d="M16 2v4"/><path d="M3 10h7"/><path d="M21 10h-5.5"/><line x1="2" y1="2" x2="22" y2="22"/></svg>',
        calendar: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        activity: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon"><polyline points="22,12 18,12 15,21 9,3 6,12 2,12"/></svg>',
        'file-text': '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10,9 9,9 8,9"/></svg>',
        settings: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.39a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
        users: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'user-check': '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17,11 19,13 23,9"/></svg>',
        'shield-check': '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>',
        'building-2': '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/></svg>',
        shield: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>'
    };

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-lucide]').forEach(function(el) {
            const iconName = el.getAttribute('data-lucide');
            if (arbeitszeitcheckNavSvgIcons[iconName]) {
                el.innerHTML = arbeitszeitcheckNavSvgIcons[iconName];
            }
        });
    });
</script>
