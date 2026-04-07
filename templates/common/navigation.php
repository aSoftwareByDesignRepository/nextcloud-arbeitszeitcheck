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
    <a href="#app-content" class="skip-link"><?php p($l->t('Skip to main content')); ?></a>
    <!-- Mobile hamburger menu button -->
    <button class="nav-mobile-toggle"
        id="nav-mobile-toggle"
        aria-label="<?php p($l->t('Navigationsmenü öffnen')); ?>"
        aria-expanded="false"
        aria-controls="app-navigation"
        title="<?php p($l->t('Zum Öffnen oder Schließen des Navigationsmenüs klicken')); ?>">
        <span class="hamburger-line"></span>
        <span class="hamburger-line"></span>
        <span class="hamburger-line"></span>
    </button>

    <!-- Mobile overlay background -->
    <div class="nav-mobile-overlay" id="nav-mobile-overlay" aria-hidden="true"></div>

    <div id="app-navigation" role="navigation" aria-label="<?php p($l->t('Hauptnavigation')); ?>">
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
                    title="<?php p($l->t('Dashboard: Aktuellen Status, heutige Stunden und letzte Einträge sehen')); ?>"
                    aria-label="<?php p($l->t('Zum Dashboard wechseln, um Status und heutige Stunden zu sehen')); ?>">
                    <i data-lucide="home" class="lucide-icon" aria-hidden="true"></i>
                    <span><?php p($l->t('Dashboard')); ?></span>
                </a>
            </li>
            <li class="<?php p($isTimeEntries ? 'active' : ''); ?>" <?php p($isTimeEntries ? 'aria-current="page"' : ''); ?>>
                <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
                    title="<?php p($l->t('Zeiteinträge: Arbeitszeiten ansehen, erfassen und bearbeiten')); ?>"
                    aria-label="<?php p($l->t('Zu „Zeiteinträge“ wechseln, um alle Arbeitszeiten zu sehen')); ?>">
                    <i data-lucide="clock" class="lucide-icon" aria-hidden="true"></i>
                    <span><?php p($l->t('Zeiteinträge')); ?></span>
                </a>
            </li>
            <li class="<?php p($isAbsences ? 'active' : ''); ?>" <?php p($isAbsences ? 'aria-current="page"' : ''); ?>>
                <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.absences')); ?>"
                    title="<?php p($l->t('Abwesenheiten: Urlaub, Krankheits- und sonstige Abwesenheiten verwalten')); ?>"
                    aria-label="<?php p($l->t('Zu „Abwesenheiten“ wechseln, um Urlaub oder Krankheitszeiten zu verwalten')); ?>">
                    <i data-lucide="calendar-off" class="lucide-icon" aria-hidden="true"></i>
                    <span><?php p($l->t('Abwesenheiten')); ?></span>
                </a>
            </li>
            <?php if ($showReportsLink): ?>
                <li class="<?php p($isReports ? 'active' : ''); ?>" <?php p($isReports ? 'aria-current="page"' : ''); ?>>
                    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.reports')); ?>"
                        title="<?php p($l->t('Berichte für Team- und Organisationsübersichten (nur für Leitungen und Administratoren sichtbar)')); ?>"
                        aria-label="<?php p($l->t('Zu den Berichten wechseln, um Übersichten für Team oder Organisation zu erstellen (nur für Leitungen und Administratoren)')); ?>">
                        <i data-lucide="file-text" class="lucide-icon" aria-hidden="true"></i>
                        <span><?php p($l->t('Berichte')); ?></span>
                    </a>
                </li>
            <?php endif; ?>
            <li class="<?php p($isCompliance ? 'active' : ''); ?>" <?php p($isCompliance ? 'aria-current="page"' : ''); ?>>
                <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.compliance.dashboard')); ?>"
                    title="<?php p($l->t('Arbeitszeit-Compliance: Prüfen, ob Ihre Arbeitszeit dem deutschen Arbeitszeitgesetz entspricht')); ?>"
                    aria-label="<?php p($l->t('Zur Arbeitszeit-Compliance wechseln, um die Einhaltung der Arbeitszeitregeln zu prüfen')); ?>">
                    <i data-lucide="shield-check" class="lucide-icon" aria-hidden="true"></i>
                    <span><?php p($l->t('Arbeitszeit-Compliance')); ?></span>
                </a>
            </li>
            <li class="<?php p($isCalendar ? 'active' : ''); ?>" <?php p($isCalendar ? 'aria-current="page"' : ''); ?>>
                <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.calendar')); ?>"
                    title="<?php p($l->t('Kalender: Arbeitszeiten und Abwesenheiten im Kalender sehen')); ?>"
                    aria-label="<?php p($l->t('Zum Kalender wechseln, um Arbeitszeiten im Kalender zu sehen')); ?>">
                    <i data-lucide="calendar" class="lucide-icon" aria-hidden="true"></i>
                    <span><?php p($l->t('Kalender')); ?></span>
                </a>
            </li>
            <li class="<?php p($isTimeline ? 'active' : ''); ?>" <?php p($isTimeline ? 'aria-current="page"' : ''); ?>>
                <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.page.timeline')); ?>"
                    title="<?php p($l->t('Zeitachse: Arbeitszeiten in zeitlicher Reihenfolge ansehen')); ?>"
                    aria-label="<?php p($l->t('Zu „Zeitachse“ wechseln, um die Arbeitszeithistorie zu sehen')); ?>">
                    <i data-lucide="activity" class="lucide-icon" aria-hidden="true"></i>
                    <span><?php p($l->t('Zeitachse')); ?></span>
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
                                title="<?php p($l->t('Übersicht mit Kennzahlen und aktuellen Hinweisen')); ?>"
                                aria-label="<?php p($l->t('Administrationsübersicht öffnen')); ?>">
                                <span><?php p($l->t('Übersicht')); ?></span>
                            </a>
                        </li>
                        <li class="<?php p($isAdminUsers ? 'active' : ''); ?>" <?php p($isAdminUsers ? 'aria-current="page"' : ''); ?>>
                            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.users')); ?>"
                                title="<?php p($l->t('Mitarbeitende und Arbeitszeitmodelle verwalten')); ?>"
                                aria-label="<?php p($l->t('Mitarbeitende verwalten')); ?>">
                                <span><?php p($l->t('Mitarbeitende')); ?></span>
                            </a>
                        </li>
                        <li class="<?php p($isAdminWorkingTimeModels ? 'active' : ''); ?>" <?php p($isAdminWorkingTimeModels ? 'aria-current="page"' : ''); ?>>
                            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.workingTimeModels')); ?>"
                                title="<?php p($l->t('Arbeitszeitmodelle konfigurieren')); ?>"
                                aria-label="<?php p($l->t('Arbeitszeitmodelle verwalten')); ?>">
                                <span><?php p($l->t('Arbeitszeitmodelle')); ?></span>
                            </a>
                        </li>
                        <li class="<?php p($isAdminHolidays ? 'active' : ''); ?>" <?php p($isAdminHolidays ? 'aria-current="page"' : ''); ?>>
                            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.holidays')); ?>"
                                title="<?php p($l->t('Feiertagskalender pro Bundesland und Standardkalender verwalten')); ?>"
                                aria-label="<?php p($l->t('Feiertage und Kalender verwalten')); ?>">
                                <span><?php p($l->t('Feiertage und Kalender')); ?></span>
                            </a>
                        </li>
                        <li class="<?php p($isAdminTeams ? 'active' : ''); ?>" <?php p($isAdminTeams ? 'aria-current="page"' : ''); ?>>
                            <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.admin.teams')); ?>"
                                title="<?php p($l->t('Teams, Standorte und Zuständigkeiten verwalten')); ?>"
                                aria-label="<?php p($l->t('Teams verwalten')); ?>">
                                <span><?php p($l->t('Teams und Standorte')); ?></span>
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
                                title="<?php p($l->t('Globale Regeln, Benachrichtigungen und Compliance-Einstellungen verwalten')); ?>"
                                aria-label="<?php p($l->t('Globale Administrationseinstellungen öffnen')); ?>">
                                <span><?php p($l->t('Globale Einstellungen')); ?></span>
                            </a>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>
            <?php if ($showManagerLink): ?>
                <li class="nav-section-divider" role="separator" aria-hidden="true"></li>
                <li class="<?php p($isManagerPage ? 'active' : ''); ?>" <?php p($isManagerPage ? 'aria-current="page"' : ''); ?>>
                    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.manager.dashboard')); ?>"
                        title="<?php p($l->t('Manager: Abwesenheiten und Zeiteintrag-Korrekturen genehmigen, Teamüberblick')); ?>"
                        aria-label="<?php p($l->t('Zum Manager-Dashboard, um Anträge zu genehmigen und Ihr Team zu sehen')); ?>">
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
                        title="<?php p($l->t('Vertretungsanfragen: Anfragen zum Vertreten von Kolleg*innen annehmen oder ablehnen')); ?>"
                        aria-label="<?php p($l->t('Zu den Vertretungsanfragen wechseln')); ?>">
                        <i data-lucide="user-check" class="lucide-icon" aria-hidden="true"></i>
                        <span><?php p($l->t('Vertretungsanfragen')); ?></span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>

    <!-- Icon rendering now handled by bundled JS: js/common/navigation-icons.js -->