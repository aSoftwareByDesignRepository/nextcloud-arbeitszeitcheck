<?php

declare(strict_types=1);

/**
 * Routes for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

return [
	'routes' => [
		// Main page routes
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#dashboard', 'url' => '/dashboard', 'verb' => 'GET'],
		['name' => 'page#timeEntries', 'url' => '/time-entries', 'verb' => 'GET'],
		['name' => 'page#absences', 'url' => '/absences', 'verb' => 'GET'],
		['name' => 'page#reports', 'url' => '/reports', 'verb' => 'GET'],
		['name' => 'page#calendar', 'url' => '/calendar', 'verb' => 'GET'],
		['name' => 'page#timeline', 'url' => '/timeline', 'verb' => 'GET'],
		['name' => 'page#settings', 'url' => '/settings', 'verb' => 'GET'],

		// Time tracking routes
		['name' => 'time_tracking#clockIn', 'url' => '/api/clock/in', 'verb' => 'POST'],
		['name' => 'time_tracking#clockOut', 'url' => '/api/clock/out', 'verb' => 'POST'],
		['name' => 'time_tracking#getStatus', 'url' => '/api/clock/status', 'verb' => 'GET'],
		['name' => 'time_tracking#startBreak', 'url' => '/api/break/start', 'verb' => 'POST'],
		['name' => 'time_tracking#endBreak', 'url' => '/api/break/end', 'verb' => 'POST'],
		['name' => 'time_tracking#getBreakStatus', 'url' => '/api/break/status', 'verb' => 'GET'],

		// Time entry management routes
		['name' => 'time_entry#index_api', 'url' => '/api/time-entries-legacy', 'verb' => 'GET'],
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

		// API routes for time entries
		['name' => 'time_entry#apiIndex', 'url' => '/api/time-entries', 'verb' => 'GET'],
		['name' => 'time_entry#apiShow', 'url' => '/api/time-entries/{id}', 'verb' => 'GET'],
		['name' => 'time_entry#apiStore', 'url' => '/api/time-entries', 'verb' => 'POST'],
		['name' => 'time_entry#apiUpdate', 'url' => '/api/time-entries/{id}', 'verb' => 'PUT'],
		['name' => 'time_entry#apiUpdatePost', 'url' => '/api/time-entries/{id}', 'verb' => 'POST'],
		['name' => 'time_entry#apiDelete', 'url' => '/api/time-entries/{id}', 'verb' => 'DELETE'],
		['name' => 'time_entry#requestCorrection', 'url' => '/api/time-entries/{id}/request-correction', 'verb' => 'POST'],

		// Absence management routes
		['name' => 'absence#index_api', 'url' => '/api/absences-legacy', 'verb' => 'GET'],
		['name' => 'absence#create', 'url' => '/absences/create', 'verb' => 'GET'],
		['name' => 'absence#store', 'url' => '/absences', 'verb' => 'POST'],
		['name' => 'absence#show', 'url' => '/absences/{id}', 'verb' => 'GET'],
		['name' => 'absence#edit', 'url' => '/absences/{id}/edit', 'verb' => 'GET'],
		['name' => 'absence#update', 'url' => '/absences/{id}', 'verb' => 'PUT'],
		['name' => 'absence#updatePost', 'url' => '/absences/{id}/update', 'verb' => 'POST'],
		['name' => 'absence#delete', 'url' => '/absences/{id}', 'verb' => 'DELETE'],

		// API routes for absences (specific routes must come before parameterized routes)
		['name' => 'absence#stats', 'url' => '/api/absences/stats', 'verb' => 'GET'],
		['name' => 'absence#users', 'url' => '/api/users', 'verb' => 'GET'],
		['name' => 'absence#index', 'url' => '/api/absences', 'verb' => 'GET'],
		['name' => 'absence#apiStore', 'url' => '/api/absences', 'verb' => 'POST'],
		['name' => 'absence#apiShow', 'url' => '/api/absences/{id}', 'verb' => 'GET'],
		['name' => 'absence#apiUpdate', 'url' => '/api/absences/{id}', 'verb' => 'PUT'],
		['name' => 'absence#apiDelete', 'url' => '/api/absences/{id}', 'verb' => 'DELETE'],
		['name' => 'absence#approve', 'url' => '/api/absences/{id}/approve', 'verb' => 'POST'],
		['name' => 'absence#reject', 'url' => '/api/absences/{id}/reject', 'verb' => 'POST'],

		// Manager routes
		['name' => 'manager#dashboard', 'url' => '/manager', 'verb' => 'GET'],
		['name' => 'manager#getTeamOverview', 'url' => '/api/manager/team-overview', 'verb' => 'GET'],
		['name' => 'manager#getPendingApprovals', 'url' => '/api/manager/pending-approvals', 'verb' => 'GET'],
		['name' => 'manager#getTeamCompliance', 'url' => '/api/manager/team-compliance', 'verb' => 'GET'],
		['name' => 'manager#getTeamHoursSummary', 'url' => '/api/manager/team-hours', 'verb' => 'GET'],
		['name' => 'manager#approveAbsence', 'url' => '/api/manager/absences/{absenceId}/approve', 'verb' => 'POST'],
		['name' => 'manager#rejectAbsence', 'url' => '/api/manager/absences/{absenceId}/reject', 'verb' => 'POST'],
		['name' => 'manager#getTeamAbsenceCalendar', 'url' => '/api/manager/absence-calendar', 'verb' => 'GET'],
		['name' => 'manager#approveTimeEntryCorrection', 'url' => '/api/manager/time-entries/{timeEntryId}/approve-correction', 'verb' => 'POST'],
		['name' => 'manager#rejectTimeEntryCorrection', 'url' => '/api/manager/time-entries/{timeEntryId}/reject-correction', 'verb' => 'POST'],
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

		// Report routes
		['name' => 'report#daily', 'url' => '/api/reports/daily', 'verb' => 'GET'],
		['name' => 'report#weekly', 'url' => '/api/reports/weekly', 'verb' => 'GET'],
		['name' => 'report#monthly', 'url' => '/api/reports/monthly', 'verb' => 'GET'],
		['name' => 'report#overtime', 'url' => '/api/reports/overtime', 'verb' => 'GET'],
		['name' => 'report#absence', 'url' => '/api/reports/absence', 'verb' => 'GET'],
		['name' => 'report#team', 'url' => '/api/reports/team', 'verb' => 'GET'],

		// Settings routes
		['name' => 'settings#index_api', 'url' => '/api/settings-legacy', 'verb' => 'GET'],
		['name' => 'settings#update', 'url' => '/settings', 'verb' => 'POST'],

		// Admin routes
		['name' => 'admin#dashboard', 'url' => '/admin', 'verb' => 'GET'],
		['name' => 'admin#users', 'url' => '/admin/users', 'verb' => 'GET'],
		['name' => 'admin#settings', 'url' => '/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#workingTimeModels', 'url' => '/admin/working-time-models', 'verb' => 'GET'],
		['name' => 'admin#auditLog', 'url' => '/admin/audit-log', 'verb' => 'GET'],
		['name' => 'admin#getAdminSettings', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#updateAdminSettings', 'url' => '/api/admin/settings', 'verb' => 'POST'],
		['name' => 'admin#getStatistics', 'url' => '/api/admin/statistics', 'verb' => 'GET'],
		['name' => 'admin#getAuditLogs', 'url' => '/api/admin/audit-logs', 'verb' => 'GET'],
		['name' => 'admin#getAuditLogStats', 'url' => '/api/admin/audit-logs/stats', 'verb' => 'GET'],
		['name' => 'admin#exportAuditLogs', 'url' => '/api/admin/audit-logs/export', 'verb' => 'GET'],
		['name' => 'admin#getUsers', 'url' => '/api/admin/users', 'verb' => 'GET'],
		['name' => 'admin#getUser', 'url' => '/api/admin/users/{userId}', 'verb' => 'GET'],
		['name' => 'admin#updateUserWorkingTimeModel', 'url' => '/api/admin/users/{userId}/working-time-model', 'verb' => 'PUT'],
		['name' => 'admin#getWorkingTimeModels', 'url' => '/api/admin/working-time-models', 'verb' => 'GET'],
		['name' => 'admin#getWorkingTimeModel', 'url' => '/api/admin/working-time-models/{id}', 'verb' => 'GET'],
		['name' => 'admin#createWorkingTimeModel', 'url' => '/api/admin/working-time-models', 'verb' => 'POST'],
		['name' => 'admin#updateWorkingTimeModel', 'url' => '/api/admin/working-time-models/{id}', 'verb' => 'PUT'],
		['name' => 'admin#deleteWorkingTimeModel', 'url' => '/api/admin/working-time-models/{id}', 'verb' => 'DELETE'],
		['name' => 'admin#exportUsers', 'url' => '/api/admin/users/export', 'verb' => 'GET'],

		// Admin teams (app-owned teams/departments)
		['name' => 'admin#teams', 'url' => '/admin/teams', 'verb' => 'GET'],
		['name' => 'admin#getTeams', 'url' => '/api/admin/teams', 'verb' => 'GET'],
		['name' => 'admin#createTeam', 'url' => '/api/admin/teams', 'verb' => 'POST'],
		['name' => 'admin#updateTeam', 'url' => '/api/admin/teams/{id}', 'verb' => 'PUT'],
		['name' => 'admin#deleteTeam', 'url' => '/api/admin/teams/{id}', 'verb' => 'DELETE'],
		['name' => 'admin#getTeamMembers', 'url' => '/api/admin/teams/{id}/members', 'verb' => 'GET'],
		['name' => 'admin#addTeamMember', 'url' => '/api/admin/teams/{id}/members', 'verb' => 'POST'],
		['name' => 'admin#removeTeamMember', 'url' => '/api/admin/teams/{id}/members/{userId}', 'verb' => 'DELETE'],
		['name' => 'admin#getTeamManagers', 'url' => '/api/admin/teams/{id}/managers', 'verb' => 'GET'],
		['name' => 'admin#addTeamManager', 'url' => '/api/admin/teams/{id}/managers', 'verb' => 'POST'],
		['name' => 'admin#removeTeamManager', 'url' => '/api/admin/teams/{id}/managers/{userId}', 'verb' => 'DELETE'],
		['name' => 'admin#getTeamsUseAppTeams', 'url' => '/api/admin/teams/config/use-app-teams', 'verb' => 'GET'],
		['name' => 'admin#setTeamsUseAppTeams', 'url' => '/api/admin/teams/config/use-app-teams', 'verb' => 'PUT'],

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
	],
];