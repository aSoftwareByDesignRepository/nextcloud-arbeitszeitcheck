<?php

declare(strict_types=1);

/**
 * Routes for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

use OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog;
use OCA\ArbeitszeitCheck\Service\EmployeeSettingsSectionCatalog;

return [
	'routes' => [
		// Main page routes
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#dashboard', 'url' => '/dashboard', 'verb' => 'GET'],
		['name' => 'page#overtimeBalancePdf', 'url' => '/api/overtime/balance-pdf', 'verb' => 'GET'],
		['name' => 'page#timeEntries', 'url' => '/time-entries', 'verb' => 'GET'],
		['name' => 'page#absences', 'url' => '/absences', 'verb' => 'GET'],
		['name' => 'page#reports', 'url' => '/reports', 'verb' => 'GET'],
		['name' => 'page#calendar', 'url' => '/calendar', 'verb' => 'GET'],
		['name' => 'page#timeline', 'url' => '/timeline', 'verb' => 'GET'],
		['name' => 'page#settings', 'url' => '/settings', 'verb' => 'GET'],
		['name' => 'page#getTheApp', 'url' => '/get-the-app', 'verb' => 'GET'],
		['name' => 'page#settingsSection', 'url' => '/settings/{section}', 'verb' => 'GET', 'requirements' => ['section' => EmployeeSettingsSectionCatalog::routeRequirement()]],

		// Time tracking routes
		['name' => 'time_tracking#clockIn', 'url' => '/api/clock/in', 'verb' => 'POST'],
		['name' => 'time_tracking#clockOut', 'url' => '/api/clock/out', 'verb' => 'POST'],
		['name' => 'time_tracking#getStatus', 'url' => '/api/clock/status', 'verb' => 'GET'],
		['name' => 'time_tracking#enforceDailyMaximum', 'url' => '/api/clock/enforce-daily-maximum', 'verb' => 'POST'],
		['name' => 'time_tracking#startBreak', 'url' => '/api/break/start', 'verb' => 'POST'],
		['name' => 'time_tracking#endBreak', 'url' => '/api/break/end', 'verb' => 'POST'],
		['name' => 'time_tracking#getBreakStatus', 'url' => '/api/break/status', 'verb' => 'GET'],

		// Dashboard widget API routes
		['name' => 'dashboard_widget#employeeData', 'url' => '/api/dashboard-widget/employee', 'verb' => 'GET'],
		['name' => 'dashboard_widget#managerData', 'url' => '/api/dashboard-widget/manager', 'verb' => 'GET'],
		['name' => 'dashboard_widget#adminData', 'url' => '/api/dashboard-widget/admin', 'verb' => 'GET'],
		['name' => 'dashboard_widget#clockIn', 'url' => '/api/dashboard-widget/clock/in', 'verb' => 'POST'],
		['name' => 'dashboard_widget#startBreak', 'url' => '/api/dashboard-widget/break/start', 'verb' => 'POST'],
		['name' => 'dashboard_widget#endBreak', 'url' => '/api/dashboard-widget/break/end', 'verb' => 'POST'],
		['name' => 'dashboard_widget#clockOut', 'url' => '/api/dashboard-widget/clock/out', 'verb' => 'POST'],

		// Mobile cold-start + home dashboard (proprietary employee app)
		['name' => 'mobile_bootstrap#bootstrap', 'url' => '/api/mobile/bootstrap', 'verb' => 'GET'],
		['name' => 'mobile_bootstrap#dashboard', 'url' => '/api/mobile/dashboard', 'verb' => 'GET'],

		// Time entry management routes
		['name' => 'time_entry#index_api', 'url' => '/api/time-entries-legacy', 'verb' => 'GET'],
		['name' => 'time_entry#apiIndex', 'url' => '/api/time-entries', 'verb' => 'GET'],
		['name' => 'time_entry#create', 'url' => '/time-entries/create', 'verb' => 'GET'],
		['name' => 'time_entry#store', 'url' => '/time-entries', 'verb' => 'POST'],
		['name' => 'time_entry#show', 'url' => '/time-entries/{id}', 'verb' => 'GET'],
		['name' => 'time_entry#edit', 'url' => '/time-entries/{id}/edit', 'verb' => 'GET'],
		['name' => 'time_entry#update', 'url' => '/time-entries/{id}', 'verb' => 'PUT'],
		['name' => 'time_entry#updatePost', 'url' => '/time-entries/{id}/update', 'verb' => 'POST'],
		['name' => 'time_entry#delete', 'url' => '/time-entries/{id}', 'verb' => 'DELETE'],
		['name' => 'time_entry#getDeletionImpact', 'url' => '/api/time-entries/{id}/deletion-impact', 'verb' => 'GET'],
		['name' => 'time_entry#getStats', 'url' => '/api/time-entries/stats', 'verb' => 'GET'],
		['name' => 'time_entry#getOvertime', 'url' => '/api/time-entries/overtime', 'verb' => 'GET'],
		['name' => 'time_entry#getOvertimeBalance', 'url' => '/api/time-entries/overtime/balance', 'verb' => 'GET'],
		['name' => 'time_entry#getOvertimeBank', 'url' => '/api/time-entries/overtime/bank', 'verb' => 'GET'],

		// API routes for time entries
		['name' => 'time_entry#apiAssignableProjectcheckProjects', 'url' => '/api/projectcheck/assignable-projects', 'verb' => 'GET'],
		['name' => 'time_entry#apiShow', 'url' => '/api/time-entries/{id}', 'verb' => 'GET'],
		['name' => 'time_entry#apiStore', 'url' => '/api/time-entries', 'verb' => 'POST'],
		['name' => 'time_entry#apiUpdate', 'url' => '/api/time-entries/{id}', 'verb' => 'PUT'],
		['name' => 'time_entry#apiUpdatePost', 'url' => '/api/time-entries/{id}', 'verb' => 'POST'],
		['name' => 'time_entry#apiDelete', 'url' => '/api/time-entries/{id}', 'verb' => 'DELETE'],
		['name' => 'time_entry#requestCorrection', 'url' => '/api/time-entries/{id}/request-correction', 'verb' => 'POST'],
		['name' => 'time_entry#cancelCorrection', 'url' => '/api/time-entries/{id}/cancel-correction', 'verb' => 'POST'],
		['name' => 'time_entry#complete', 'url' => '/api/time-entries/{id}/complete', 'verb' => 'POST'],
		['name' => 'time_entry#checkOverlap', 'url' => '/api/time-entries/check-overlap', 'verb' => 'GET'],

		// Absence management routes
		['name' => 'absence#index_api', 'url' => '/api/absences-legacy', 'verb' => 'GET'],
		['name' => 'absence#create', 'url' => '/absences/create', 'verb' => 'GET'],
		['name' => 'absence#store', 'url' => '/absences', 'verb' => 'POST'],
		['name' => 'absence#show', 'url' => '/absences/{id}', 'verb' => 'GET'],
		['name' => 'absence#edit', 'url' => '/absences/{id}/edit', 'verb' => 'GET'],
		['name' => 'absence#update', 'url' => '/absences/{id}', 'verb' => 'PUT'],
		['name' => 'absence#updatePost', 'url' => '/absences/{id}/update', 'verb' => 'POST'],
		['name' => 'absence#shortenForm', 'url' => '/absences/{id}/shorten', 'verb' => 'POST'],
		['name' => 'absence#delete', 'url' => '/absences/{id}', 'verb' => 'DELETE'],

		// API routes for absences (specific routes must come before parameterized routes)
		['name' => 'absence#stats', 'url' => '/api/absences/stats', 'verb' => 'GET'],
		['name' => 'absence#estimateVacationHours', 'url' => '/api/absences/estimate-hours', 'verb' => 'GET'],
		['name' => 'absence#entitlementTrace', 'url' => '/api/absences/entitlement-trace', 'verb' => 'GET'],
		['name' => 'absence#users', 'url' => '/api/colleagues', 'verb' => 'GET'],
		['name' => 'absence#index', 'url' => '/api/absences', 'verb' => 'GET'],
		['name' => 'absence#apiStore', 'url' => '/api/absences', 'verb' => 'POST'],
		['name' => 'absence#apiShow', 'url' => '/api/absences/{id}', 'verb' => 'GET'],
		['name' => 'absence#apiUpdate', 'url' => '/api/absences/{id}', 'verb' => 'PUT'],
		['name' => 'absence#apiDelete', 'url' => '/api/absences/{id}', 'verb' => 'DELETE'],
		['name' => 'absence#approve', 'url' => '/api/absences/{id}/approve', 'verb' => 'POST'],
		['name' => 'absence#reject', 'url' => '/api/absences/{id}/reject', 'verb' => 'POST'],
		['name' => 'absence#cancel', 'url' => '/api/absences/{id}/cancel', 'verb' => 'POST'],
		['name' => 'absence#shorten', 'url' => '/api/absences/{id}/shorten', 'verb' => 'POST'],

		// Manager routes
		['name' => 'manager#dashboard', 'url' => '/manager', 'verb' => 'GET'],
		['name' => 'manager#employeeTimeEntriesPage', 'url' => '/manager/time-entries', 'verb' => 'GET'],
		['name' => 'manager#employeeAbsencesPage', 'url' => '/manager/absences', 'verb' => 'GET'],
		['name' => 'manager#monthClosuresPage', 'url' => '/manager/month-closures', 'verb' => 'GET'],
		['name' => 'manager#revisionPdfUsers', 'url' => '/api/manager/revision-pdf/users', 'verb' => 'GET'],
		['name' => 'manager#revisionPdfAvailableMonths', 'url' => '/api/manager/revision-pdf/available-months', 'verb' => 'GET'],
		['name' => 'manager#revisionPdfUsersForMonth', 'url' => '/api/manager/revision-pdf/users-for-month', 'verb' => 'GET'],
		['name' => 'manager#getTeamOverview', 'url' => '/api/manager/team-overview', 'verb' => 'GET'],
		['name' => 'manager#getScopedEmployees', 'url' => '/api/manager/scoped-employees', 'verb' => 'GET'],
		['name' => 'manager#getEmployeeTimeEntries', 'url' => '/api/manager/employee-time-entries', 'verb' => 'GET'],
		['name' => 'manager#createEmployeeTimeEntry', 'url' => '/api/manager/employee-time-entries', 'verb' => 'POST'],
		['name' => 'manager#getManagerAssignableProjectcheckProjects', 'url' => '/api/manager/employees/{employeeId}/projectcheck-assignable-projects', 'verb' => 'GET'],
		['name' => 'manager#getEmployeeAbsences', 'url' => '/api/manager/employee-absences', 'verb' => 'GET'],
		['name' => 'manager#estimateEmployeeVacationHours', 'url' => '/api/manager/employee-absences/estimate-hours', 'verb' => 'GET'],
		['name' => 'manager#createEmployeeAbsence', 'url' => '/api/manager/employee-absences', 'verb' => 'POST'],
		['name' => 'manager#getPendingApprovals', 'url' => '/api/manager/pending-approvals', 'verb' => 'GET'],
		['name' => 'manager#getTeamCompliance', 'url' => '/api/manager/team-compliance', 'verb' => 'GET'],
		['name' => 'manager#getTeamOvertimeAlerts', 'url' => '/api/manager/team-overtime-alerts', 'verb' => 'GET'],
		['name' => 'manager#exportTeamOvertimeCsv', 'url' => '/api/manager/team-overtime-export', 'verb' => 'GET'],
		['name' => 'manager#getTeamHoursSummary', 'url' => '/api/manager/team-hours', 'verb' => 'GET'],
		['name' => 'manager#getManagedTeams', 'url' => '/api/manager/teams', 'verb' => 'GET'],
		['name' => 'manager#approveAbsence', 'url' => '/api/manager/absences/{absenceId}/approve', 'verb' => 'POST'],
		['name' => 'manager#rejectAbsence', 'url' => '/api/manager/absences/{absenceId}/reject', 'verb' => 'POST'],
		['name' => 'manager#getTeamAbsenceCalendar', 'url' => '/api/manager/absence-calendar', 'verb' => 'GET'],
		['name' => 'manager#approveTimeEntryCorrection', 'url' => '/api/manager/time-entries/{timeEntryId}/approve-correction', 'verb' => 'POST'],
		['name' => 'manager#rejectTimeEntryCorrection', 'url' => '/api/manager/time-entries/{timeEntryId}/reject-correction', 'verb' => 'POST'],
		['name' => 'manager#correctTimeEntry', 'url' => '/api/manager/time-entries/{timeEntryId}/correct', 'verb' => 'POST'],
		['name' => 'manager#getPendingTimeEntryCorrections', 'url' => '/api/manager/pending-time-entry-corrections', 'verb' => 'GET'],

		// Substitute (Vertretungs-Freigabe) routes
		['name' => 'substitute#index', 'url' => '/substitution-requests', 'verb' => 'GET'],
		['name' => 'substitute#getPending', 'url' => '/api/substitution-requests', 'verb' => 'GET'],
		['name' => 'substitute#approve', 'url' => '/api/substitution-requests/{absenceId}/approve', 'verb' => 'POST'],
		['name' => 'substitute#decline', 'url' => '/api/substitution-requests/{absenceId}/decline', 'verb' => 'POST'],

		// Compliance routes
		['name' => 'compliance#dashboard', 'url' => '/compliance', 'verb' => 'GET'],
		['name' => 'compliance#violations', 'url' => '/compliance/violations', 'verb' => 'GET'],
		['name' => 'compliance#reports', 'url' => '/compliance/reports', 'verb' => 'GET'],

		// Compliance API routes
		['name' => 'compliance#getViolations', 'url' => '/api/compliance/violations', 'verb' => 'GET'],
		['name' => 'compliance#getViolation', 'url' => '/api/compliance/violations/{id}', 'verb' => 'GET'],
		['name' => 'compliance#resolveViolation', 'url' => '/api/compliance/violations/{id}/resolve', 'verb' => 'POST'],
		['name' => 'compliance#getStatus', 'url' => '/api/compliance/status', 'verb' => 'GET'],
		['name' => 'compliance#getReport', 'url' => '/api/compliance/report', 'verb' => 'GET'],
		['name' => 'compliance#runCheck', 'url' => '/api/compliance/run-check', 'verb' => 'POST'],
		['name' => 'compliance#checkRestPeriod', 'url' => '/api/compliance/check-rest-period', 'verb' => 'GET'],

		// Holiday routes
		['name' => 'holiday#index', 'url' => '/api/holidays', 'verb' => 'GET'],

		// Report routes
		['name' => 'report#daily', 'url' => '/api/reports/daily', 'verb' => 'GET'],
		['name' => 'report#weekly', 'url' => '/api/reports/weekly', 'verb' => 'GET'],
		['name' => 'report#monthly', 'url' => '/api/reports/monthly', 'verb' => 'GET'],
		['name' => 'report#overtime', 'url' => '/api/reports/overtime', 'verb' => 'GET'],
		['name' => 'report#premium', 'url' => '/api/reports/premium', 'verb' => 'GET'],
		['name' => 'report#absence', 'url' => '/api/reports/absence', 'verb' => 'GET'],
		['name' => 'report#team', 'url' => '/api/reports/team', 'verb' => 'GET'],

		// Settings routes
		['name' => 'settings#index_api', 'url' => '/api/settings-legacy', 'verb' => 'GET'],
		['name' => 'settings#update', 'url' => '/settings', 'verb' => 'POST'],

		// Admin routes
		['name' => 'admin#dashboard', 'url' => '/admin', 'verb' => 'GET'],
		['name' => 'admin#users', 'url' => '/admin/users', 'verb' => 'GET'],
		['name' => 'admin#userDetail', 'url' => '/admin/users/{userId}', 'verb' => 'GET'],
		['name' => 'admin#settings', 'url' => '/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#settingsSection', 'url' => '/admin/settings/{section}', 'verb' => 'GET', 'requirements' => ['section' => AdminSettingsSectionCatalog::routeRequirement()]],
		['name' => 'admin#supportUs', 'url' => '/admin/support-us', 'verb' => 'GET'],
		['name' => 'license_admin#index', 'url' => '/admin/license', 'verb' => 'GET'],
		['name' => 'license_admin#applyLicense', 'url' => '/api/admin/license', 'verb' => 'POST'],
		['name' => 'license_admin#clearLicense', 'url' => '/api/admin/license', 'verb' => 'DELETE'],
		['name' => 'license_admin#assignSeat', 'url' => '/api/admin/license/mobile-seats', 'verb' => 'POST'],
		['name' => 'license_admin#removeSeat', 'url' => '/api/admin/license/mobile-seats/remove', 'verb' => 'POST'],
		['name' => 'license_admin#searchUsers', 'url' => '/api/admin/license/users/search', 'verb' => 'GET'],

		// Kiosk admin + terminal API
		['name' => 'kiosk_admin#index', 'url' => '/admin/kiosk', 'verb' => 'GET'],
		['name' => 'kiosk_admin#setKioskEnabled', 'url' => '/api/admin/kiosk/enabled', 'verb' => 'POST'],
		['name' => 'kiosk_admin#createTerminal', 'url' => '/api/admin/kiosk/terminals', 'verb' => 'POST'],
		['name' => 'kiosk_admin#revokeTerminal', 'url' => '/api/admin/kiosk/terminals/{terminalId}/revoke', 'verb' => 'POST'],
		['name' => 'kiosk_admin#listCredentials', 'url' => '/api/admin/kiosk/credentials', 'verb' => 'GET'],
		['name' => 'kiosk_admin#assignRfid', 'url' => '/api/admin/kiosk/credentials/rfid', 'verb' => 'POST'],
		['name' => 'kiosk_admin#generatePin', 'url' => '/api/admin/kiosk/credentials/pin/generate', 'verb' => 'POST'],
		['name' => 'kiosk_admin#deleteCredential', 'url' => '/api/admin/kiosk/credentials/{id}', 'verb' => 'DELETE'],
		['name' => 'kiosk_admin#setUserAllowed', 'url' => '/api/admin/kiosk/users/{userId}/allowed', 'verb' => 'PUT'],
		['name' => 'kiosk_admin#importCredentials', 'url' => '/api/admin/kiosk/credentials/import', 'verb' => 'POST'],
		['name' => 'kiosk_admin#startEnrollment', 'url' => '/api/admin/kiosk/enrollment/start', 'verb' => 'POST'],
		['name' => 'kiosk_admin#enrollmentStatus', 'url' => '/api/admin/kiosk/enrollment/status', 'verb' => 'GET'],
		['name' => 'kiosk_admin#cancelEnrollment', 'url' => '/api/admin/kiosk/enrollment/cancel', 'verb' => 'POST'],
		['name' => 'kiosk_admin#searchUsers', 'url' => '/api/admin/kiosk/users/search', 'verb' => 'GET'],
		['name' => 'kiosk#pair', 'url' => '/api/kiosk/pair', 'verb' => 'POST'],
		['name' => 'kiosk#config', 'url' => '/api/kiosk/config', 'verb' => 'GET'],
		['name' => 'kiosk#users', 'url' => '/api/kiosk/users', 'verb' => 'GET'],
		['name' => 'kiosk#identify', 'url' => '/api/kiosk/identify', 'verb' => 'POST'],
		['name' => 'kiosk#action', 'url' => '/api/kiosk/action', 'verb' => 'POST'],
		['name' => 'kiosk#stamp', 'url' => '/api/kiosk/stamp', 'verb' => 'POST'],
		['name' => 'kiosk#heartbeat', 'url' => '/api/kiosk/heartbeat', 'verb' => 'POST'],
		['name' => 'kiosk#enrollScan', 'url' => '/api/kiosk/enroll-scan', 'verb' => 'POST'],
		['name' => 'admin#notifications', 'url' => '/admin/notifications', 'verb' => 'GET'],
		['name' => 'admin#overtimeSettings', 'url' => '/admin/overtime-settings', 'verb' => 'GET'],
		['name' => 'overtime_payout#index', 'url' => '/admin/overtime-payouts', 'verb' => 'GET'],
		['name' => 'overtime_payout#listMonth', 'url' => '/api/admin/overtime-payouts', 'verb' => 'GET'],
		['name' => 'overtime_payout#processOne', 'url' => '/api/admin/overtime-payouts/process', 'verb' => 'POST'],
		['name' => 'overtime_payout#processBulk', 'url' => '/api/admin/overtime-payouts/process-bulk', 'verb' => 'POST'],
		['name' => 'overtime_payout#exportCsv', 'url' => '/api/admin/overtime-payouts/export', 'verb' => 'GET'],
		['name' => 'overtime_payout#auditIndex', 'url' => '/admin/overtime-payout-audit', 'verb' => 'GET'],
		['name' => 'overtime_payout#listAudit', 'url' => '/api/admin/overtime-payout-audit', 'verb' => 'GET'],
		['name' => 'overtime_payout#adminMonthClosurePdf', 'url' => '/api/admin/overtime-payout-audit/month-closure-pdf', 'verb' => 'GET'],
		['name' => 'overtime_payout#myHistory', 'url' => '/api/overtime/payout-history', 'verb' => 'GET'],
		['name' => 'admin#holidays', 'url' => '/admin/holidays', 'verb' => 'GET'],
		['name' => 'admin#workingTimeModels', 'url' => '/admin/working-time-models', 'verb' => 'GET'],
		['name' => 'admin#auditLog', 'url' => '/admin/audit-log', 'verb' => 'GET'],
		['name' => 'admin#getAdminSettings', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#updateAdminSettings', 'url' => '/api/admin/settings', 'verb' => 'POST'],
		['name' => 'admin#getNotificationSettings', 'url' => '/api/admin/notifications/settings', 'verb' => 'GET'],
		['name' => 'admin#updateNotificationSettings', 'url' => '/api/admin/notifications/settings', 'verb' => 'POST'],
		['name' => 'admin#migrateVacationUnit', 'url' => '/api/admin/vacation-unit/migrate', 'verb' => 'POST'],
		// Legacy company_holidays JSON (kept for backward compatibility; new code should use state-holidays endpoints)
		['name' => 'admin#getCompanyHolidays', 'url' => '/api/admin/holidays', 'verb' => 'GET'],
		['name' => 'admin#saveCompanyHoliday', 'url' => '/api/admin/holidays', 'verb' => 'POST'],
		['name' => 'admin#deleteCompanyHoliday', 'url' => '/api/admin/holidays', 'verb' => 'DELETE'],
		// New state-based holidays API (backed by at_holidays via HolidayMapper)
		['name' => 'admin#getStateHolidays', 'url' => '/api/admin/state-holidays', 'verb' => 'GET'],
		['name' => 'admin#getHolidaySuggestions', 'url' => '/api/admin/state-holidays/suggestions', 'verb' => 'GET'],
		['name' => 'admin#saveStateHoliday', 'url' => '/api/admin/state-holidays', 'verb' => 'POST'],
		['name' => 'admin#deleteStateHoliday', 'url' => '/api/admin/state-holidays/{id}', 'verb' => 'DELETE'],
		['name' => 'admin#getStatistics', 'url' => '/api/admin/statistics', 'verb' => 'GET'],
		['name' => 'admin#getDashboardEmployees', 'url' => '/api/admin/dashboard-employees', 'verb' => 'GET'],
		['name' => 'admin#getAuditLogs', 'url' => '/api/admin/audit-logs', 'verb' => 'GET'],
		['name' => 'admin#getAuditLogStats', 'url' => '/api/admin/audit-logs/stats', 'verb' => 'GET'],
		['name' => 'admin#exportAuditLogs', 'url' => '/api/admin/audit-logs/export', 'verb' => 'GET'],
		['name' => 'admin#getUsers', 'url' => '/api/admin/users', 'verb' => 'GET'],
		['name' => 'admin#exportUsers', 'url' => '/api/admin/users/export', 'verb' => 'GET'],
		['name' => 'admin#getUser', 'url' => '/api/admin/users/{userId}', 'verb' => 'GET'],
		['name' => 'admin#updateUserProfile', 'url' => '/api/admin/users/{userId}/profile', 'verb' => 'PUT'],
		['name' => 'admin#updateUserWorkingTimeModel', 'url' => '/api/admin/users/{userId}/working-time-model', 'verb' => 'PUT'],
		['name' => 'admin#getUserAssignmentHistory', 'url' => '/api/admin/users/{userId}/working-time-model/history', 'verb' => 'GET'],
		['name' => 'admin#getWorkingTimeModels', 'url' => '/api/admin/working-time-models', 'verb' => 'GET'],
		['name' => 'admin#getWorkingTimeModel', 'url' => '/api/admin/working-time-models/{id}', 'verb' => 'GET'],
		['name' => 'admin#createWorkingTimeModel', 'url' => '/api/admin/working-time-models', 'verb' => 'POST'],
		['name' => 'admin#updateWorkingTimeModel', 'url' => '/api/admin/working-time-models/{id}', 'verb' => 'PUT'],
		['name' => 'admin#deleteWorkingTimeModel', 'url' => '/api/admin/working-time-models/{id}', 'verb' => 'DELETE'],
		['name' => 'admin#tariffRuleSets', 'url' => '/admin/tariff-rules', 'verb' => 'GET'],
		['name' => 'admin#getTariffRuleSets', 'url' => '/api/admin/tariff-rule-sets', 'verb' => 'GET'],
		['name' => 'admin#getTariffRuleSet', 'url' => '/api/admin/tariff-rule-sets/{id}', 'verb' => 'GET'],
		['name' => 'admin#createTariffRuleSet', 'url' => '/api/admin/tariff-rule-sets', 'verb' => 'POST'],
		['name' => 'admin#updateTariffRuleSet', 'url' => '/api/admin/tariff-rule-sets/{id}', 'verb' => 'PUT'],
		['name' => 'admin#deleteTariffRuleSet', 'url' => '/api/admin/tariff-rule-sets/{id}', 'verb' => 'DELETE'],
		['name' => 'admin#activateTariffRuleSet', 'url' => '/api/admin/tariff-rule-sets/{id}/activate', 'verb' => 'POST'],
		['name' => 'admin#retireTariffRuleSet', 'url' => '/api/admin/tariff-rule-sets/{id}/retire', 'verb' => 'POST'],
		['name' => 'admin#assignVacationPolicy', 'url' => '/api/admin/users/{userId}/vacation-policy', 'verb' => 'PUT'],
		['name' => 'admin#updateUserOvertimeSettings', 'url' => '/api/admin/users/{userId}/overtime-settings', 'verb' => 'PUT'],
		['name' => 'admin#listUserOvertimeAdjustments', 'url' => '/api/admin/users/{userId}/overtime-adjustments', 'verb' => 'GET'],
		['name' => 'admin#createUserOvertimeAdjustment', 'url' => '/api/admin/users/{userId}/overtime-adjustments', 'verb' => 'POST'],
		['name' => 'admin#resetUserOvertimeBalance', 'url' => '/api/admin/users/{userId}/overtime-adjustments/reset', 'verb' => 'POST'],
		['name' => 'admin#updateUserTimeCaptureSettings', 'url' => '/api/admin/users/{userId}/time-capture-settings', 'verb' => 'PUT'],
		['name' => 'admin#simulateVacationPolicy', 'url' => '/api/admin/vacation-policy/simulate', 'verb' => 'POST'],

		// Layered vacation entitlement (L0/L1/L2) admin endpoints
		['name' => 'admin#vacationRules', 'url' => '/admin/vacation-rules', 'verb' => 'GET'],
		['name' => 'admin#vacationLayers', 'url' => '/admin/vacation-layers', 'verb' => 'GET'],
		['name' => 'admin#getVacationLayers', 'url' => '/api/admin/vacation-layers', 'verb' => 'GET'],
		['name' => 'admin#saveOrgVacationDefault', 'url' => '/api/admin/vacation-layers/org', 'verb' => 'POST'],
		['name' => 'admin#deleteOrgVacationDefault', 'url' => '/api/admin/vacation-layers/org/{id}', 'verb' => 'DELETE'],
		['name' => 'admin#saveModelVacationDefault', 'url' => '/api/admin/vacation-layers/model', 'verb' => 'POST'],
		['name' => 'admin#deleteModelVacationDefault', 'url' => '/api/admin/vacation-layers/model/{id}', 'verb' => 'DELETE'],
		['name' => 'admin#saveTeamVacationPolicy', 'url' => '/api/admin/vacation-layers/team', 'verb' => 'POST'],
		['name' => 'admin#deleteTeamVacationPolicy', 'url' => '/api/admin/vacation-layers/team/{id}', 'verb' => 'DELETE'],
		['name' => 'admin#searchVacationLayersUsers', 'url' => '/api/admin/vacation-layers/users', 'verb' => 'GET'],
		['name' => 'admin#previewVacationLayerImpact', 'url' => '/api/admin/vacation-layers/impact', 'verb' => 'GET'],

		// Admin teams (app-owned teams/departments)
		['name' => 'admin#teams', 'url' => '/admin/teams', 'verb' => 'GET'],
		['name' => 'admin#getTeams', 'url' => '/api/admin/teams', 'verb' => 'GET'],
		['name' => 'admin#createTeam', 'url' => '/api/admin/teams', 'verb' => 'POST'],
		['name' => 'admin#updateTeam', 'url' => '/api/admin/teams/{id}', 'verb' => 'PUT'],
		['name' => 'admin#deleteTeam', 'url' => '/api/admin/teams/{id}', 'verb' => 'DELETE'],
		['name' => 'admin#getTeamDeleteImpact', 'url' => '/api/admin/teams/{id}/delete-impact', 'verb' => 'GET'],
		['name' => 'admin#getTeamMembers', 'url' => '/api/admin/teams/{id}/members', 'verb' => 'GET'],
		['name' => 'admin#addTeamMember', 'url' => '/api/admin/teams/{id}/members', 'verb' => 'POST'],
		['name' => 'admin#removeTeamMember', 'url' => '/api/admin/teams/{id}/members/{userId}', 'verb' => 'DELETE'],
		['name' => 'admin#getTeamManagers', 'url' => '/api/admin/teams/{id}/managers', 'verb' => 'GET'],
		['name' => 'admin#addTeamManager', 'url' => '/api/admin/teams/{id}/managers', 'verb' => 'POST'],
		['name' => 'admin#removeTeamManager', 'url' => '/api/admin/teams/{id}/managers/{userId}', 'verb' => 'DELETE'],
		['name' => 'admin#getTeamsUseAppTeams', 'url' => '/api/admin/teams/config/use-app-teams', 'verb' => 'GET'],
		['name' => 'admin#setTeamsUseAppTeams', 'url' => '/api/admin/teams/config/use-app-teams', 'verb' => 'PUT'],

		// Outlook iCalendar subscriptions (RFC 5545)
		['name' => 'outlook_ical_subscription#adminTeams', 'url' => '/api/admin/outlook-ical/teams', 'verb' => 'GET'],
		['name' => 'outlook_ical_subscription#adminWebcalLocalAccess', 'url' => '/api/admin/outlook-ical/webcal-local-access', 'verb' => 'GET'],
		['name' => 'outlook_ical_subscription#adminEnableWebcalLocalAccess', 'url' => '/api/admin/outlook-ical/webcal-local-access', 'verb' => 'POST'],
		['name' => 'outlook_ical_subscription#adminCreateToken', 'url' => '/api/admin/outlook-ical/create', 'verb' => 'POST'],
		['name' => 'outlook_ical_subscription#adminCreateSubscriptionLink', 'url' => '/api/admin/outlook-ical/subscription-links', 'verb' => 'POST'],
		['name' => 'outlook_ical_subscription#adminRotateToken', 'url' => '/api/admin/outlook-ical/rotate', 'verb' => 'POST'],
		['name' => 'outlook_ical_subscription#adminActiveSubscriptions', 'url' => '/api/admin/outlook-ical/active-subscriptions', 'verb' => 'GET'],
		['name' => 'outlook_ical_subscription#tokenizedFeed', 'url' => '/api/outlook-ical/feed.ics', 'verb' => 'GET'],
		['name' => 'outlook_ical_subscription#tokenizedFeedLegacy', 'url' => '/api/outlook-ical/tokenized', 'verb' => 'GET'],
		['name' => 'outlook_ical_subscription#authenticatedFeed', 'url' => '/api/outlook-ical/authenticated', 'verb' => 'GET'],

		// Export routes
		['name' => 'export#timeEntries', 'url' => '/export/time-entries', 'verb' => 'GET'],
		['name' => 'export#absences', 'url' => '/export/absences', 'verb' => 'GET'],
		['name' => 'export#compliance', 'url' => '/export/compliance', 'verb' => 'GET'],
		['name' => 'export#datev', 'url' => '/export/datev', 'verb' => 'GET'],
		['name' => 'export#datevConfig', 'url' => '/api/export/datev/config', 'verb' => 'GET'],

		// GDPR/DSGVO compliance routes
		['name' => 'gdpr#export', 'url' => '/gdpr/export', 'verb' => 'GET'],
		['name' => 'gdpr#delete', 'url' => '/gdpr/delete', 'verb' => 'POST'],

		// Settings routes
		['name' => 'settings#getOnboardingCompleted', 'url' => '/api/settings/onboarding-completed', 'verb' => 'GET'],
		['name' => 'settings#setOnboardingCompleted', 'url' => '/api/settings/onboarding-completed', 'verb' => 'POST'],

		// Health check route
		['name' => 'health#check', 'url' => '/health', 'verb' => 'GET'],

		// Revision-safe month closure
		['name' => 'month_closure#feature', 'url' => '/api/month-closure/feature', 'verb' => 'GET'],
		['name' => 'month_closure#periods', 'url' => '/api/month-closure/periods', 'verb' => 'GET'],
		['name' => 'month_closure#status', 'url' => '/api/month-closure/status', 'verb' => 'GET'],
		['name' => 'month_closure#finalize', 'url' => '/api/month-closure/finalize', 'verb' => 'POST'],
		['name' => 'month_closure#pdf', 'url' => '/api/month-closure/pdf', 'verb' => 'GET'],
		['name' => 'month_closure#finalizedMonths', 'url' => '/api/month-closure/finalized-months', 'verb' => 'GET'],
		['name' => 'month_closure#reopen', 'url' => '/api/month-closure/reopen', 'verb' => 'POST'],
	],
];