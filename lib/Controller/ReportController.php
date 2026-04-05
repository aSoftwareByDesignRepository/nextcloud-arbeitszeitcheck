<?php

declare(strict_types=1);

/**
 * Report controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\ReportingService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeEntryExportTransformer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IL10N;

/**
 * ReportController for generating various reports
 */
class ReportController extends Controller
{
	private ReportingService $reportingService;
	private PermissionService $permissionService;
	private TeamResolverService $teamResolver;
	private TeamMemberMapper $teamMemberMapper;
	private TeamManagerMapper $teamManagerMapper;
	private TimeEntryMapper $timeEntryMapper;
	private TimeEntryExportTransformer $timeEntryExportTransformer;
	private IConfig $config;
	private IUserManager $userManager;
	private IUserSession $userSession;
	private IL10N $l10n;

	public function __construct(
		string $appName,
		IRequest $request,
		ReportingService $reportingService,
		PermissionService $permissionService,
		TeamResolverService $teamResolver,
		TeamMemberMapper $teamMemberMapper,
		TeamManagerMapper $teamManagerMapper,
		TimeEntryMapper $timeEntryMapper,
		TimeEntryExportTransformer $timeEntryExportTransformer,
		IConfig $config,
		IUserManager $userManager,
		IUserSession $userSession,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->reportingService = $reportingService;
		$this->permissionService = $permissionService;
		$this->teamResolver = $teamResolver;
		$this->teamMemberMapper = $teamMemberMapper;
		$this->teamManagerMapper = $teamManagerMapper;
		$this->timeEntryMapper = $timeEntryMapper;
		$this->timeEntryExportTransformer = $timeEntryExportTransformer;
		$this->config = $config;
		$this->userManager = $userManager;
		$this->userSession = $userSession;
		$this->l10n = $l10n;
	}

	/**
	 * Ensure current user may access reports for the given target user.
	 * Allowed: own user, team members (manager), or admin.
	 */
	private function ensureCanAccessUserReport(string $currentUserId, string $targetUserId): void
	{
		if ($this->permissionService->canViewUserReport($currentUserId, $targetUserId)) {
			return;
		}
		$this->permissionService->logPermissionDenied($currentUserId, 'view_report', 'report', $targetUserId);
		throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
	}

	/**
	 * Get current user ID
	 *
	 * @return string
	 */
	private function getUserId(): string
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			throw new \Exception('User not authenticated');
		}
		return $user->getUID();
	}

	/**
	 * Generate daily report
	 *
	 * @param string|null $date Date (Y-m-d format, defaults to today)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function daily(?string $date = null, ?string $userId = null): JSONResponse
	{
		try {
			$currentUserId = $this->getUserId();
			// Special case: admin organization scope (userId="") -> generate report for all enabled users.
			// We must not run per-user permission checks in this mode.
			$reportUserId = null;
			if ($userId === '') {
				if (!$this->permissionService->isAdmin($currentUserId)) {
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
			} else {
				$reportUserId = $userId ?? $currentUserId;
				$this->ensureCanAccessUserReport($currentUserId, $reportUserId);
			}

			$reportDate = $date ? new \DateTime($date) : new \DateTime();
			$report = $this->reportingService->generateDailyReport($reportDate, $reportUserId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$status = strpos($e->getMessage(), 'Access denied') !== false ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], $status);
		}
	}

	/**
	 * Generate weekly report
	 *
	 * @param string|null $weekStart Week start date (Y-m-d format, defaults to current week)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function weekly(?string $weekStart = null, ?string $userId = null): JSONResponse
	{
		try {
			if ($weekStart) {
				$weekStartDate = new \DateTime($weekStart);
			} else {
				$weekStartDate = new \DateTime();
				$dayOfWeek = (int)$weekStartDate->format('w');
				$weekStartDate->modify('-' . $dayOfWeek . ' days');
			}
			$weekStartDate->setTime(0, 0, 0);

			$currentUserId = $this->getUserId();
			// Special case: admin organization scope (userId="") -> report for all enabled users.
			$reportUserId = null;
			if ($userId === '') {
				if (!$this->permissionService->isAdmin($currentUserId)) {
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
			} else {
				$reportUserId = $userId ?? $currentUserId;
				$this->ensureCanAccessUserReport($currentUserId, $reportUserId);
			}

			$report = $this->reportingService->generateWeeklyReport($weekStartDate, $reportUserId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$status = strpos($e->getMessage(), 'Access denied') !== false ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], $status);
		}
	}

	/**
	 * Generate monthly report
	 *
	 * @param string|null $month Month (Y-m format, defaults to current month)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function monthly(?string $month = null, ?string $userId = null, ?string $startDate = null, ?string $endDate = null): JSONResponse
	{
		try {
			if ($month) {
				$monthDate = new \DateTime($month . '-01');
			} else {
				$monthDate = new \DateTime();
			}

			$currentUserId = $this->getUserId();
			// Special case: admin organization scope (userId="") -> report for all enabled users.
			$reportUserId = null;
			if ($userId === '') {
				if (!$this->permissionService->isAdmin($currentUserId)) {
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
			} else {
				$reportUserId = $userId ?? $currentUserId;
				$this->ensureCanAccessUserReport($currentUserId, $reportUserId);
			}

			$periodStart = null;
			$periodEnd = null;
			if ($startDate !== null && $startDate !== '' && $endDate !== null && $endDate !== '') {
				$periodStart = new \DateTime($startDate);
				$periodEnd = new \DateTime($endDate);
				if ($periodStart > $periodEnd) {
					throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
				}
				$days = (int)$periodEnd->diff($periodStart)->format('%a');
				if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
					throw new \Exception($this->l10n->t(
						'Export date range must not exceed %d days. Please narrow the range.',
						[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
					));
				}
			}

			$report = $this->reportingService->generateMonthlyReport($monthDate, $reportUserId, $periodStart, $periodEnd);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$status = strpos($e->getMessage(), 'Access denied') !== false ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], $status);
		}
	}

	/**
	 * Generate overtime report
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function overtime(?string $startDate = null, ?string $endDate = null, ?string $userId = null): JSONResponse
	{
		try {
			$start = $startDate ? new \DateTime($startDate) : (new \DateTime())->modify('-30 days');
			$end = $endDate ? new \DateTime($endDate) : new \DateTime();
			$start->setTime(0, 0, 0);
			$end->setTime(23, 59, 59);
			if ($start > $end) {
				throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
			}
			$days = (int)$end->diff($start)->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				throw new \Exception($this->l10n->t('Export date range must not exceed %d days. Please narrow the range.', [Constants::MAX_EXPORT_DATE_RANGE_DAYS]));
			}

			$currentUserId = $this->getUserId();
			// Special case: admin organization scope (userId="") -> report for all enabled users.
			$reportUserId = null;
			if ($userId === '') {
				if (!$this->permissionService->isAdmin($currentUserId)) {
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
			} else {
				$reportUserId = $userId ?? $currentUserId;
				$this->ensureCanAccessUserReport($currentUserId, $reportUserId);
			}

			$report = $this->reportingService->generateOvertimeReport($start, $end, $reportUserId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$status = strpos($e->getMessage(), 'Access denied') !== false ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], $status);
		}
	}

	/**
	 * Generate absence report
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function absence(?string $startDate = null, ?string $endDate = null, ?string $userId = null): JSONResponse
	{
		try {
			$currentUserId = $this->getUserId();
			// Special case: admin organization scope (userId="") -> report for all enabled users.
			$reportUserId = null;
			if ($userId === '') {
				if (!$this->permissionService->isAdmin($currentUserId)) {
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
			} else {
				$reportUserId = $userId ?? $currentUserId;
				$this->ensureCanAccessUserReport($currentUserId, $reportUserId);
			}

			$start = $startDate ? new \DateTime($startDate) : (new \DateTime())->modify('-1 year');
			$end = $endDate ? new \DateTime($endDate) : new \DateTime();
			$start->setTime(0, 0, 0);
			$end->setTime(23, 59, 59);
			if ($start > $end) {
				throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
			}
			$days = (int)$end->diff($start)->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				throw new \Exception($this->l10n->t('Export date range must not exceed %d days. Please narrow the range.', [Constants::MAX_EXPORT_DATE_RANGE_DAYS]));
			}

			$report = $this->reportingService->generateAbsenceReport($start, $end, $reportUserId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$status = strpos($e->getMessage(), 'Access denied') !== false ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], $status);
		}
	}

	/**
	 * Generate team report
	 *
	 * Accepts either userIds (comma-separated) or teamId. When userIds is empty:
	 * - If teamId is provided: resolve team members (admin: any team; manager: must manage that team)
	 * - If teamId is empty and user has manager capabilities: use everyone the manager is responsible for
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @param string|null $userIds Comma-separated user IDs (optional if teamId or manager scope)
	 * @param string|null $teamId Team ID for app-owned teams (optional)
	 * @return JSONResponse|DataDownloadResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function team(
		?string $startDate = null,
		?string $endDate = null,
		?string $userIds = null,
		?string $teamId = null
	): JSONResponse|DataDownloadResponse {
		try {
			$start = $startDate ? new \DateTime($startDate) : (new \DateTime())->modify('-30 days');
			$end = $endDate ? new \DateTime($endDate) : new \DateTime();
			$start->setTime(0, 0, 0);
			$end->setTime(23, 59, 59);
			if ($start > $end) {
				throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
			}
			$days = (int)$end->diff($start)->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				throw new \Exception($this->l10n->t('Export date range must not exceed %d days. Please narrow the range.', [Constants::MAX_EXPORT_DATE_RANGE_DAYS]));
			}

			$currentUserId = $this->getUserId();
			$teamUserIds = [];

			if ($userIds && trim($userIds) !== '') {
				$teamUserIds = array_values(array_unique(array_filter(array_map('trim', explode(',', $userIds)))));
			} elseif ($teamId !== null && $teamId !== '') {
				$tid = (int) $teamId;
				if ($tid <= 0) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Invalid team selected.')
					], Http::STATUS_BAD_REQUEST);
				}
				$teamUserIds = $this->resolveTeamMemberIdsFromTeamId($currentUserId, $tid);
			} elseif ($this->permissionService->canAccessManagerDashboard($currentUserId)) {
				// Manager scope: "everyone I manage" – resolve via TeamResolverService
				$teamUserIds = $this->teamResolver->getTeamMemberIds($currentUserId);
			}

			$teamUserIds = array_values(array_unique($teamUserIds));
			if (empty($teamUserIds)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('No users to include in the report. Please select a scope or team.')
				], Http::STATUS_BAD_REQUEST);
			}

			// Security: current user may view report only for self or users they can manage
			foreach ($teamUserIds as $uid) {
				if (!$this->permissionService->canViewUserReport($currentUserId, $uid)) {
					$this->permissionService->logPermissionDenied($currentUserId, 'view_team_report', 'report', $uid);
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
			}

			$report = $this->reportingService->generateTeamReport($teamUserIds, $start, $end);

			$download = (string)$this->request->getParam('download', '0') === '1';
			if ($download) {
				$format = (string)$this->request->getParam('format', 'csv');
				$variantParam = (string)$this->request->getParam('variant', 'summary');
				$variant = in_array($variantParam, ['summary', 'time_entries'], true) ? $variantParam : 'summary';
				$layoutParam = (string)$this->request->getParam('layout', 'long');
				$layout = in_array($layoutParam, ['long', 'wide'], true) ? $layoutParam : 'long';

				if ($variant === 'time_entries') {
					$enableMidnightSplit = $this->config->getAppValue('arbeitszeitcheck', 'export_midnight_split_enabled', '1') === '1';
					$longRows = [];
					foreach ($teamUserIds as $uid) {
						$entries = $this->timeEntryMapper->findByUserAndDateRange($uid, $start, $end);
						$displayName = $this->getUserDisplayName($uid);
						foreach ($this->timeEntryExportTransformer->entriesToExportRows($entries, $enableMidnightSplit) as $row) {
							$row['user_id'] = $uid;
							$row['display_name'] = $displayName;
							$longRows[] = $row;
						}
					}
					usort($longRows, static function (array $a, array $b): int {
						return [
							(string)($a['user_id'] ?? ''),
							(string)($a['date'] ?? ''),
							(string)($a['start_time'] ?? ''),
							(int)($a['id'] ?? 0),
						] <=> [
							(string)($b['user_id'] ?? ''),
							(string)($b['date'] ?? ''),
							(string)($b['start_time'] ?? ''),
							(int)($b['id'] ?? 0),
						];
					});

					$l10n = $this->l10n;
					$weekdayFormatter = static function (string $dateStr) use ($l10n): string {
						$keys = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
						$n = (int)(new \DateTime($dateStr))->format('N');
						return $l10n->t($keys[$n - 1]);
					};

					if ($layout === 'wide') {
						$exportRows = $this->timeEntryExportTransformer->longExportRowsToWideDaily($longRows, $weekdayFormatter);
					} else {
						$exportRows = array_map(static function (array $row): array {
							return [
								'user_id' => $row['user_id'] ?? '',
								'display_name' => $row['display_name'] ?? '',
								'id' => $row['id'] ?? '',
								'date' => $row['date'] ?? '',
								'start_time' => $row['start_time'] ?? '',
								'end_time' => $row['end_time'] ?? '',
								'break_start' => $row['break_start'] ?? '',
								'break_end' => $row['break_end'] ?? '',
								'duration_hours' => $row['duration_hours'] ?? '',
								'break_duration_hours' => $row['break_duration_hours'] ?? '',
								'working_hours' => $row['working_hours'] ?? '',
								'description' => $row['description'] ?? '',
								'status' => $row['status'] ?? '',
								'is_manual_entry' => $row['is_manual_entry'] ?? '',
								'project_id' => $row['project_id'] ?? '',
							];
						}, $longRows);
					}

					$filenameBase = 'team-time-entries-' . date('Y-m-d');
					if ($format === 'json') {
						$json = json_encode($exportRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
						if ($json === false) {
							throw new \Exception($this->l10n->t('Failed to encode report as JSON'));
						}
						return new DataDownloadResponse(
							$json,
							$filenameBase . '.json',
							'application/json; charset=utf-8'
						);
					}

					$csv = $this->encodeTabularDataToCsv($exportRows);
					return new DataDownloadResponse(
						$csv,
						$filenameBase . '.csv',
						'text/csv; charset=utf-8'
					);
				}

				$filenameBase = 'team-report-' . date('Y-m-d');

				if ($format === 'json') {
					$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					if ($json === false) {
						throw new \Exception($this->l10n->t('Failed to encode report as JSON'));
					}
					return new DataDownloadResponse(
						$json,
						$filenameBase . '.json',
						'application/json; charset=utf-8'
					);
				}

				$csv = $this->buildTeamReportCsv($report);
				return new DataDownloadResponse(
					$csv,
					$filenameBase . '.csv',
					'text/csv; charset=utf-8'
				);
			}

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$status = strpos($e->getMessage(), 'Access denied') !== false ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], $status);
		}
	}

	/**
	 * Resolve team member user IDs from a team ID, enforcing permissions.
	 * Admins: any team. Managers: only teams they manage.
	 *
	 * @param string $currentUserId
	 * @param int $teamId
	 * @return list<string>
	 */
	private function resolveTeamMemberIdsFromTeamId(string $currentUserId, int $teamId): array {
		if ($this->permissionService->isAdmin($currentUserId)) {
			$members = $this->teamMemberMapper->findByTeamId($teamId);
			$ids = [];
			foreach ($members as $m) {
				$ids[] = $m->getUserId();
			}
			return $ids;
		}

		if ($this->permissionService->canAccessManagerDashboard($currentUserId)) {
			$managedTeamIds = $this->teamManagerMapper->getTeamIdsForManager($currentUserId);
			if (!in_array($teamId, $managedTeamIds, true)) {
				throw new \Exception($this->l10n->t('Access denied. You can only view reports for teams you manage.'));
			}
			$members = $this->teamMemberMapper->findByTeamId($teamId);
			$ids = [];
			foreach ($members as $m) {
				$ids[] = $m->getUserId();
			}
			return $ids;
		}

		throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
	}

	/**
	 * Build a CSV payload for a team report.
	 *
	 * The CSV focuses on per-member aggregates so that it stays compact and
	 * easy to consume in tools like Excel while matching the preview data.
	 *
	 * @param array $report
	 * @return string
	 */
	private function getUserDisplayName(string $userId): string
	{
		$user = $this->userManager->get($userId);
		return $user ? $user->getDisplayName() : $userId;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function encodeTabularDataToCsv(array $rows): string
	{
		if ($rows === []) {
			return "No data available\n";
		}
		$fp = fopen('php://temp', 'r+');
		if ($fp === false) {
			throw new \RuntimeException('Failed to open temporary stream for CSV export');
		}
		fputcsv($fp, array_keys($rows[0]));
		foreach ($rows as $row) {
			$line = [];
			foreach (array_keys($rows[0]) as $k) {
				$v = $row[$k] ?? '';
				if ($v === null) {
					$v = '';
				} elseif (is_float($v) || is_int($v)) {
					$v = (string)$v;
				} elseif (!is_string($v)) {
					$v = (string)$v;
				}
				$line[] = $v;
			}
			fputcsv($fp, $line);
		}
		rewind($fp);
		$csv = stream_get_contents($fp);
		fclose($fp);

		return $csv === false ? '' : $csv;
	}

	private function buildTeamReportCsv(array $report): string
	{
		$headers = [
			'user_id',
			'display_name',
			'total_hours',
			'required_hours',
			'overtime_hours',
			'break_hours',
			'violations_count',
			'absence_days',
			'entries_count',
		];

		$fp = fopen('php://temp', 'r+');
		if ($fp === false) {
			throw new \RuntimeException('Failed to open temporary stream for CSV export');
		}

		fputcsv($fp, $headers);

		$members = $report['members'] ?? [];
		if (is_array($members)) {
			foreach ($members as $member) {
				if (!is_array($member)) {
					continue;
				}
				$row = [
					$member['user_id'] ?? '',
					$member['display_name'] ?? '',
					isset($member['total_hours']) ? (string)$member['total_hours'] : '',
					isset($member['required_hours']) ? (string)$member['required_hours'] : '',
					isset($member['overtime_hours']) ? (string)$member['overtime_hours'] : '',
					isset($member['break_hours']) ? (string)$member['break_hours'] : '',
					isset($member['violations_count']) ? (string)$member['violations_count'] : '',
					isset($member['absence_days']) ? (string)$member['absence_days'] : '',
					isset($member['entries_count']) ? (string)$member['entries_count'] : '',
				];
				fputcsv($fp, $row);
			}
		}

		rewind($fp);
		$csv = stream_get_contents($fp);
		fclose($fp);

		return $csv === false ? '' : $csv;
	}
}
