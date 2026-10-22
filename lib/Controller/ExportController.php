<?php

declare(strict_types=1);

/**
 * Export controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Service\DatevExportService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeEntryExportTransformer;
use OCA\ArbeitszeitCheck\Constants;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\IConfig;

/**
 * ExportController
 */
class ExportController extends Controller
{
	private TimeEntryMapper $timeEntryMapper;
	private AbsenceMapper $absenceMapper;
	private ComplianceViolationMapper $violationMapper;
	private DatevExportService $datevExportService;
	private TimeEntryExportTransformer $timeEntryExportTransformer;
	private IUserSession $userSession;
	private IL10N $l10n;
	private IConfig $config;
	private PermissionService $permissionService;
	private ?IUserManager $userManager;

	/**
	 * Guard CSV exports against spreadsheet formula injection.
	 */
	private static function sanitizeCsvCellValue(string $value): string
	{
		if ($value === '') {
			return $value;
		}
		if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
			return "'" . $value;
		}
		$first = $value[0];
		if ($first === "\t" || $first === "\r" || $first === "\n") {
			return "'" . $value;
		}
		return $value;
	}

	private function parseDateYmd(?string $value, string $fieldName): ?\DateTime
	{
		if ($value === null || trim($value) === '') {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		$errors = \DateTimeImmutable::getLastErrors();
		$isValid = $parsed !== false
			&& ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
			&& $parsed->format('Y-m-d') === $value;
		if (!$isValid) {
			throw new \Exception($this->l10n->t('Invalid %s format. Use Y-m-d (e.g. 2024-01-15)', [$fieldName]));
		}
		return new \DateTime($parsed->format('Y-m-d'));
	}

	private function safeExportErrorMessage(\Throwable $e): string
	{
		$raw = $e->getMessage();
		if (strpos($raw, 'User not authenticated') !== false) {
			return $this->l10n->t('User not authenticated');
		}
		if (strpos($raw, 'Access denied') !== false) {
			return $this->l10n->t('Access denied');
		}
		if (strpos($raw, 'User not found') !== false) {
			return $this->l10n->t('User not found');
		}
		if (strpos($raw, 'Start date must be before or equal to end date') !== false
			|| strpos($raw, 'Export date range must not exceed') !== false
			|| strpos($raw, 'DATEV configuration incomplete') !== false
			|| strpos($raw, 'Personalnummer not configured') !== false
			|| strpos($raw, 'Invalid ') === 0) {
			return $raw;
		}
		return $this->l10n->t('Export failed. Please try again. If the problem continues, contact your administrator.');
	}

	public function __construct(
		string $appName,
		IRequest $request,
		TimeEntryMapper $timeEntryMapper,
		AbsenceMapper $absenceMapper,
		ComplianceViolationMapper $violationMapper,
		DatevExportService $datevExportService,
		TimeEntryExportTransformer $timeEntryExportTransformer,
		IUserSession $userSession,
		IL10N $l10n,
		IConfig $config,
		PermissionService $permissionService,
		?IUserManager $userManager = null,
		private readonly ?\OCA\ArbeitszeitCheck\Service\OversightScopeService $oversightScope = null,
		private readonly ?\OCA\ArbeitszeitCheck\Db\AuditLogMapper $auditLogMapper = null,
	) {
		parent::__construct($appName, $request);
		$this->timeEntryMapper = $timeEntryMapper;
		$this->absenceMapper = $absenceMapper;
		$this->violationMapper = $violationMapper;
		$this->datevExportService = $datevExportService;
		$this->timeEntryExportTransformer = $timeEntryExportTransformer;
		$this->userSession = $userSession;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->permissionService = $permissionService;
		$this->userManager = $userManager;
	}

	private function oversightScope(): \OCA\ArbeitszeitCheck\Service\OversightScopeService
	{
		return $this->oversightScope ?? \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\OversightScopeService::class);
	}

	private function auditLogMapper(): ?\OCA\ArbeitszeitCheck\Db\AuditLogMapper
	{
		return $this->auditLogMapper ?? \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class);
	}

	/**
	 * Export time entries
	 *
	 * @param string $format Format: csv, json, pdf, datev
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	/**
	 * @return DataDownloadResponse|JSONResponse
	 */
	public function timeEntries(string $format = 'csv', ?string $startDate = null, ?string $endDate = null): DataDownloadResponse|JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				throw new \Exception($this->l10n->t('User not authenticated'));
			}

			$userId = $user->getUID();

		// Determine date range (default to last 30 days if not specified)
		$end = $this->parseDateYmd($endDate, 'end_date') ?? new \DateTime();
		// Normalise to midnight of the end date for range calculations and display.
		$end->setTime(0, 0, 0);
		$start = $this->parseDateYmd($startDate, 'start_date') ?? clone $end;
		if (!$startDate) {
			$start->modify('-30 days');
		}
		$start->setTime(0, 0, 0);

		if ($start > $end) {
			throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
		}

		// Enforce max date range to prevent heavy queries (midnight-to-midnight is exact).
		$diff = $end->diff($start);
		$days = (int) $diff->format('%a');
		if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
			throw new \Exception($this->l10n->t(
				'Export date range must not exceed %d days. Please narrow the range.',
				[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
			));
		}

		// Exclusive upper bound for DB query: start of next day (findByUserAndDateRange uses strict <).
		$endExclusive = (clone $end)->modify('+1 day');

		// Get time entries from database
		$entries = $this->timeEntryMapper->findByUserAndDateRange($userId, $start, $endExclusive);

			// Decide whether to virtually split entries that span midnight in CSV/JSON export
			$enableMidnightSplit = $this->config->getAppValue('arbeitszeitcheck', 'export_midnight_split_enabled', '1') === '1';

			$layoutParam = (string)$this->request->getParam('layout', 'long');
			$layout = in_array($layoutParam, ['long', 'wide'], true) ? $layoutParam : 'long';

			$data = [];
			foreach ($entries as $entry) {
				try {
					foreach ($this->timeEntryExportTransformer->entryToExportRows($entry, $enableMidnightSplit) as $row) {
						$data[] = $row;
					}
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error processing time entry ' . $entry->getId() . ' in export: ' . $e->getMessage(), ["exception" => $e]);
				}
			}

			if ($layout === 'wide' && ($format === 'csv' || $format === 'json')) {
				$displayName = $user->getDisplayName();
				foreach ($data as &$r) {
					$r['user_id'] = $userId;
					$r['display_name'] = $displayName;
				}
				unset($r);
				$l10n = $this->l10n;
				$data = $this->timeEntryExportTransformer->longExportRowsToWideDaily(
					$data,
					static function (string $dateStr) use ($l10n): string {
						$keys = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
						$n = (int)(new \DateTime($dateStr))->format('N');
						return $l10n->t($keys[$n - 1]);
					}
				);
			}

			$filename = 'time-entries-' . date('Y-m-d') . '.' . $format;

			if ($format === 'pdf') {
				return new JSONResponse([
					'error' => $this->l10n->t('PDF export is not available. Please use CSV.')
				], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$timezone = $this->getExportTimezone();
			return match($format) {
				'csv' => $this->exportAsCsv($data, $filename, $timezone),
				'json' => $this->exportAsJson($data, $filename, $timezone),
				// Inclusive calendar end → exclusive upper bound (same as export#datev).
				'datev' => $this->exportAsDatev($userId, $start, $endExclusive),
				default => $this->exportAsCsv($data, $filename, $timezone)
			};
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ExportController::timeEntries: ' . $e->getMessage(), ["exception" => $e]);
			// Return error as CSV with error message
			$errorData = [['error' => $this->safeExportErrorMessage($e)]];
			return $this->exportAsCsv($errorData, 'time-entries-export-error-' . date('Y-m-d') . '.csv', $this->getExportTimezone());
		}
	}

	/**
	 * Export absences
	 *
	 * @param string $format Format: csv, json, pdf
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	/**
	 * @return DataDownloadResponse|JSONResponse
	 */
	public function absences(
		string $format = 'csv',
		?string $startDate = null,
		?string $endDate = null,
		?string $sort = null,
		?string $userId = null,
		?string $userIds = null,
		?string $teamId = null,
		?string $scope = null,
		?string $status = null,
		?string $type = null,
	): DataDownloadResponse|JSONResponse {
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				throw new \Exception($this->l10n->t('User not authenticated'));
			}

			$actorUid = $user->getUID();

		// Determine date range (default to last year if not specified)
		$end = $this->parseDateYmd($endDate, 'end_date') ?? new \DateTime();
		$end->setTime(0, 0, 0);
		$start = $this->parseDateYmd($startDate, 'start_date') ?? clone $end;
		if (!$startDate) {
			$start->modify('-1 year');
		}
		$start->setTime(0, 0, 0);

		if ($start > $end) {
			throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
		}

		// Enforce max date range (midnight-to-midnight gives exact day count).
		$diff = $end->diff($start);
		$days = (int) $diff->format('%a');
		if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
			throw new \Exception($this->l10n->t(
				'Export date range must not exceed %d days. Please narrow the range.',
				[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
			));
		}

		// Scope selectors (userId / userIds / teamId / scope) — resolved through
		// the single OversightScopeService. No selector → legacy self export.
		$scopeResolution = $this->oversightScope()->resolveRequestedScope($actorUid, $userId, $userIds, $teamId, $scope);
		if (!$scopeResolution['ok']) {
			return $this->scopeErrorResponse($scopeResolution['code'] ?? '');
		}
		$scopeUserIds = $scopeResolution['userIds']; // null → self export
		$isSelfExport = $scopeUserIds === null;
		$scopeUserIds ??= [$actorUid];

		// Optional row filters (validated strictly — unknown values are rejected).
		$status = $status !== null ? trim($status) : '';
		if ($status !== '' && !in_array($status, [
			\OCA\ArbeitszeitCheck\Db\Absence::STATUS_PENDING,
			\OCA\ArbeitszeitCheck\Db\Absence::STATUS_SUBSTITUTE_PENDING,
			\OCA\ArbeitszeitCheck\Db\Absence::STATUS_SUBSTITUTE_DECLINED,
			\OCA\ArbeitszeitCheck\Db\Absence::STATUS_APPROVED,
			\OCA\ArbeitszeitCheck\Db\Absence::STATUS_REJECTED,
			\OCA\ArbeitszeitCheck\Db\Absence::STATUS_CANCELLED,
		], true)) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Invalid status filter.'),
			], Http::STATUS_BAD_REQUEST);
		}
		$type = $type !== null ? trim($type) : '';
		if ($type !== '' && !\OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog::isValid($type)) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Invalid absence type.'),
			], Http::STATUS_BAD_REQUEST);
		}

			// Get absences from database
			if ($isSelfExport) {
				// Legacy path: single-user mapper call, unchanged output columns.
				$absences = $this->absenceMapper->findByUserAndDateRange($actorUid, $start, $end);
				if ($status !== '' || $type !== '') {
					$absences = array_values(array_filter($absences, static function ($a) use ($status, $type): bool {
						return ($status === '' || $a->getStatus() === $status)
							&& ($type === '' || $a->getType() === $type);
					}));
				}
			} else {
				// Scoped export: fail loud instead of silently truncating payroll data.
				$rowCount = $this->absenceMapper->countByUsersAndDateRange(
					$scopeUserIds, $start, $end, $status !== '' ? $status : null, $type !== '' ? $type : null
				);
				if ($rowCount > Constants::MAX_EXPORT_ROWS) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Too many rows (%d). Please narrow the date range or selection.', [$rowCount]),
					], Http::STATUS_BAD_REQUEST);
				}
				$absences = $this->absenceMapper->findByUsersAndDateRange(
					$scopeUserIds, $start, $end, $status !== '' ? $status : null, $type !== '' ? $type : null
				);
			}

			$displayNameByUid = [];
			$displayName = function (string $uid) use (&$displayNameByUid): string {
				if (!array_key_exists($uid, $displayNameByUid)) {
					$u = $this->userManager?->get($uid);
					$displayNameByUid[$uid] = ($u !== null && $u->getDisplayName() !== '') ? $u->getDisplayName() : $uid;
				}
				return $displayNameByUid[$uid];
			};

			// Convert to array format
			$data = [];
			foreach ($absences as $absence) {
				try {
					$startDateRow = $absence->getStartDate();
					$endDateRow = $absence->getEndDate();
					$createdAt = $absence->getCreatedAt();
					$row = [
						'id' => $absence->getId(),
						'type' => $absence->getType(),
						'start_date' => $startDateRow ? $startDateRow->format('Y-m-d') : '',
						'end_date' => $endDateRow ? $endDateRow->format('Y-m-d') : '',
						'days' => $absence->getDays(),
						'reason' => $absence->getReason() ?? '',
						'status' => $absence->getStatus(),
						'approver_comment' => $absence->getApproverComment() ?? '',
						'approved_at' => $absence->getApprovedAt() ? $absence->getApprovedAt()->format('Y-m-d H:i:s') : '',
						'created_at' => $createdAt ? $createdAt->format('Y-m-d H:i:s') : ''
					];
					if (!$isSelfExport) {
						// Scoped exports carry the employee identity columns.
						$row = ['user_id' => $absence->getUserId(), 'display_name' => $displayName($absence->getUserId())] + $row;
					}
					$data[] = $row;
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error processing absence ' . $absence->getId() . ' in export: ' . $e->getMessage(), ["exception" => $e]);
					continue;
				}
			}

			$this->logExportAudit($actorUid, 'absence_export', $scopeResolution['scope'] !== '' ? $scopeResolution['scope'] : 'self', $scopeUserIds, $start, $end, count($data));

			$sortMode = strtolower(trim((string)($sort ?? $this->request->getParam('sort') ?? 'date')));
			if ($sortMode === 'type') {
				$data = $this->sortAbsenceExportRowsByType($data);
			}

			$filename = 'absences-' . date('Y-m-d') . '.' . $format;

			if ($format === 'pdf') {
				return new JSONResponse([
					'error' => $this->l10n->t('PDF export is not available. Please use CSV.')
				], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$timezone = $this->getExportTimezone();
			if ($format === 'csv' && $sortMode === 'type') {
				return $this->exportAbsencesCsvGroupedByType($data, $filename, $timezone);
			}
			return match($format) {
				'csv' => $this->exportAsCsv($data, $filename, $timezone),
				'json' => $this->exportAsJson($data, $filename, $timezone),
				default => $this->exportAsCsv($data, $filename, $timezone)
			};
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ExportController::absences: ' . $e->getMessage(), ["exception" => $e]);
			// Return error as CSV with error message
			$errorData = [['error' => $this->safeExportErrorMessage($e)]];
			return $this->exportAsCsv($errorData, 'absences-export-error-' . date('Y-m-d') . '.csv', $this->getExportTimezone());
		}
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function sortAbsenceExportRowsByType(array $rows): array
	{
		usort($rows, static function (array $a, array $b): int {
			$typeCmp = strcasecmp((string)($a['type'] ?? ''), (string)($b['type'] ?? ''));
			if ($typeCmp !== 0) {
				return $typeCmp;
			}
			$startCmp = strcmp((string)($a['start_date'] ?? ''), (string)($b['start_date'] ?? ''));
			if ($startCmp !== 0) {
				return $startCmp;
			}
			return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
		});

		return $rows;
	}

	/**
	 * CSV with section markers by absence type for payroll handoff (still flat-parseable:
	 * comment rows start with #; data rows keep the same column headers after each section).
	 *
	 * @param list<array<string, mixed>> $data
	 */
	private function exportAbsencesCsvGroupedByType(array $data, string $filename, string $timezone): DataDownloadResponse
	{
		$fp = fopen('php://temp', 'r+');
		if ($fp === false) {
			throw new \RuntimeException('Failed to open temporary stream for CSV export');
		}
		fputcsv($fp, ['# ' . $this->l10n->t('Timezone'), $timezone]);
		fputcsv($fp, ['# ' . $this->l10n->t('Exported at'), (new \DateTime('now', new \DateTimeZone($timezone)))->format('Y-m-d H:i:s T')]);
		fputcsv($fp, ['# ' . $this->l10n->t('Sorted by absence type')]);

		if ($data === []) {
			fputcsv($fp, ['message', 'No data available']);
		} else {
			$headers = array_keys($data[0]);
			$currentType = null;
			foreach ($data as $row) {
				$type = (string)($row['type'] ?? '');
				if ($type !== $currentType) {
					$currentType = $type;
					fputcsv($fp, ['# ' . $this->l10n->t('Absence type') . ': ' . ($type !== '' ? $type : $this->l10n->t('(none)'))]);
					fputcsv($fp, $headers);
				}
				$sanitizedRow = array_map(
					static fn ($value): string => self::sanitizeCsvCellValue((string)$value),
					array_values($row)
				);
				fputcsv($fp, $sanitizedRow);
			}
		}
		rewind($fp);
		$csv = stream_get_contents($fp);
		fclose($fp);

		return new DataDownloadResponse($csv !== false ? $csv : '', $filename, 'text/csv; charset=utf-8');
	}

	/**
	 * Export compliance reports
	 *
	 * @param string $format Format: csv, json
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @return DataDownloadResponse|JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function compliance(
		string $format = 'csv',
		?string $startDate = null,
		?string $endDate = null,
		?string $userId = null,
		?string $userIds = null,
		?string $teamId = null,
		?string $scope = null,
		?string $severity = null,
		?string $violationType = null,
		?string $resolved = null,
	): DataDownloadResponse|JSONResponse {
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				throw new \Exception($this->l10n->t('User not authenticated'));
			}

			$actorUid = $user->getUID();

		// Determine date range (default to last 30 days if not specified)
		$end = $this->parseDateYmd($endDate, 'end_date') ?? new \DateTime();
		$end->setTime(0, 0, 0);
		$start = $this->parseDateYmd($startDate, 'start_date') ?? clone $end;
		if (!$startDate) {
			$start->modify('-30 days');
		}
		$start->setTime(0, 0, 0);

		if ($start > $end) {
			throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
		}

		// Enforce max date range (midnight-to-midnight gives exact day count).
		$diff = $end->diff($start);
		$days = (int) $diff->format('%a');
		if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
			throw new \Exception($this->l10n->t(
				'Export date range must not exceed %d days. Please narrow the range.',
				[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
			));
		}

		// Scope selectors — same resolver as the absence export.
		$scopeResolution = $this->oversightScope()->resolveRequestedScope($actorUid, $userId, $userIds, $teamId, $scope);
		if (!$scopeResolution['ok']) {
			return $this->scopeErrorResponse($scopeResolution['code'] ?? '');
		}
		$scopeUserIds = $scopeResolution['userIds'];
		$isSelfExport = $scopeUserIds === null;
		$scopeUserIds ??= [$actorUid];

		$severity = $severity !== null ? trim($severity) : '';
		if ($severity !== '' && !in_array($severity, [
			\OCA\ArbeitszeitCheck\Db\ComplianceViolation::SEVERITY_INFO,
			\OCA\ArbeitszeitCheck\Db\ComplianceViolation::SEVERITY_WARNING,
			\OCA\ArbeitszeitCheck\Db\ComplianceViolation::SEVERITY_ERROR,
		], true)) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Invalid severity filter.'),
			], Http::STATUS_BAD_REQUEST);
		}
		$violationType = $violationType !== null ? trim($violationType) : '';
		// Form-encoded booleans arrive as strings — parse strictly.
		$resolvedFilter = null;
		if ($resolved !== null && trim($resolved) !== '') {
			$resolved = strtolower(trim($resolved));
			if ($resolved === '1' || $resolved === 'true') {
				$resolvedFilter = true;
			} elseif ($resolved === '0' || $resolved === 'false') {
				$resolvedFilter = false;
			} else {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid resolved filter.'),
				], Http::STATUS_BAD_REQUEST);
			}
		}

		// Violation `date` is day-grained; the request end date is inclusive,
		// so the exclusive upper bound is end+1 day. (Deliberate fix: the
		// legacy self export dropped violations on the end date itself.)
		$endExclusive = (clone $end)->modify('+1 day');

		// Get compliance violations from database
			if ($isSelfExport && $severity === '' && $violationType === '' && $resolvedFilter === null) {
				$violations = $this->violationMapper->findByDateRange($start, $endExclusive, $actorUid);
			} else {
				$rowCount = $this->violationMapper->countByUserIdsAndDateRange(
					$scopeUserIds, $start, $endExclusive,
					$violationType !== '' ? $violationType : null,
					$severity !== '' ? $severity : null,
					$resolvedFilter
				);
				if ($rowCount > Constants::MAX_EXPORT_ROWS) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Too many rows (%d). Please narrow the date range or selection.', [$rowCount]),
					], Http::STATUS_BAD_REQUEST);
				}
				$violations = $this->violationMapper->findByUserIdsAndDateRange(
					$scopeUserIds, $start, $endExclusive,
					$violationType !== '' ? $violationType : null,
					$severity !== '' ? $severity : null,
					$resolvedFilter
				);
			}

			$displayNameByUid = [];
			$displayName = function (string $uid) use (&$displayNameByUid): string {
				if (!array_key_exists($uid, $displayNameByUid)) {
					$u = $this->userManager?->get($uid);
					$displayNameByUid[$uid] = ($u !== null && $u->getDisplayName() !== '') ? $u->getDisplayName() : $uid;
				}
				return $displayNameByUid[$uid];
			};

			// Convert to array format
			$data = [];
			foreach ($violations as $violation) {
				try {
					$date = $violation->getDate();
					if (!$date) {
						continue; // Skip violations with no date
					}
					$row = [
						'id' => $violation->getId(),
						'date' => $date->format('Y-m-d'),
						'violation_type' => $violation->getViolationType(),
						'description' => $violation->getDescription(),
						'severity' => $violation->getSeverity(),
						'resolved' => $violation->getResolved() ? 'Yes' : 'No',
						'resolved_at' => $violation->getResolvedAt() ? $violation->getResolvedAt()->format('Y-m-d H:i:s') : '',
						'time_entry_id' => $violation->getTimeEntryId()
					];
					if (!$isSelfExport) {
						$row = ['user_id' => $violation->getUserId(), 'display_name' => $displayName($violation->getUserId())] + $row;
					}
					$data[] = $row;
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error processing violation ' . $violation->getId() . ' in export: ' . $e->getMessage(), ["exception" => $e]);
					continue;
				}
			}

			$this->logExportAudit($actorUid, 'compliance_export', $scopeResolution['scope'] !== '' ? $scopeResolution['scope'] : 'self', $scopeUserIds, $start, $end, count($data));

			$filename = 'compliance-report-' . date('Y-m-d') . '.' . $format;

			if ($format === 'pdf') {
				return new JSONResponse([
					'error' => $this->l10n->t('PDF export is not available. Please use CSV.')
				], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$timezone = $this->getExportTimezone();
			return match($format) {
				'csv' => $this->exportAsCsv($data, $filename, $timezone),
				'json' => $this->exportAsJson($data, $filename, $timezone),
				default => $this->exportAsCsv($data, $filename, $timezone)
			};
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ExportController::compliance: ' . $e->getMessage(), ["exception" => $e]);
			// Return error as CSV with error message
			$errorData = [['error' => $this->safeExportErrorMessage($e)]];
			return $this->exportAsCsv($errorData, 'compliance-export-error-' . date('Y-m-d') . '.csv', $this->getExportTimezone());
		}
	}

	/**
	 * Uniform error response for scope-selector failures: authorization
	 * denials and unknown/foreign ids collapse to one generic message
	 * (no existence oracle); malformed input is a 400.
	 */
	private function scopeErrorResponse(string $code): JSONResponse
	{
		if ($code === \OCA\ArbeitszeitCheck\Service\OversightScopeService::ERR_DENIED) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Access denied'),
			], Http::STATUS_FORBIDDEN);
		}
		return new JSONResponse([
			'success' => false,
			'error' => $this->l10n->t('Invalid scope parameters.'),
		], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * Audit record for every sensitive HR data download (actor, scope,
	 * user count, row count, window). Failures are logged but never block
	 * the download itself — an audit outage must not break exports.
	 *
	 * @param list<string> $scopeUserIds
	 */
	private function logExportAudit(
		string $actorUid,
		string $entityType,
		string $scopeLabel,
		array $scopeUserIds,
		\DateTime $start,
		\DateTime $end,
		int $rowCount,
	): void {
		try {
			$this->auditLogMapper()?->logAction(
				$actorUid,
				'export_download',
				$entityType,
				null,
				null,
				[
					'scope' => $scopeLabel,
					'user_count' => count($scopeUserIds),
					'row_count' => $rowCount,
					'start_date' => $start->format('Y-m-d'),
					'end_date' => $end->format('Y-m-d'),
				],
				$actorUid,
			);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Export audit logging failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}

	/**
	 * Export data as CSV
	 *
	 * @param array $data Data to export
	 * @param string $filename Filename
	 * @return DataDownloadResponse
	 */
	private function exportAsCsv(array $data, string $filename, string $timezone): DataDownloadResponse
	{
		$fp = fopen('php://temp', 'r+');
		fputcsv($fp, ['# ' . $this->l10n->t('Timezone'), $timezone]);
		fputcsv($fp, ['# ' . $this->l10n->t('Exported at'), (new \DateTime('now', new \DateTimeZone($timezone)))->format('Y-m-d H:i:s T')]);

		if (!empty($data)) {
			fputcsv($fp, array_keys($data[0]));
			foreach ($data as $row) {
				$sanitizedRow = array_map(
					static fn ($value): string => self::sanitizeCsvCellValue((string)$value),
					array_values($row)
				);
				fputcsv($fp, $sanitizedRow);
			}
		} else {
			fputcsv($fp, ['message', 'No data available']);
		}
		rewind($fp);
		$csv = stream_get_contents($fp);
		fclose($fp);

		return new DataDownloadResponse($csv, $filename, 'text/csv; charset=utf-8');
	}

	/**
	 * Export data as JSON
	 *
	 * @param array $data Data to export
	 * @param string $filename Filename
	 * @return DataDownloadResponse
	 */
	private function exportAsJson(array $data, string $filename, string $timezone): DataDownloadResponse
	{
		$json = json_encode([
			'timezone' => $timezone,
			'exported_at' => (new \DateTime('now', new \DateTimeZone($timezone)))->format('c'),
			'record_count' => count($data),
			'entries' => $data,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		
		if ($json === false) {
			throw new \Exception($this->l10n->t('Failed to encode data as JSON'));
		}

		return new DataDownloadResponse($json, $filename, 'application/json; charset=utf-8');
	}

	/**
	 * Export time entries in DATEV format for one user.
	 *
	 * @param string $userId User ID
	 * @param \DateTime $startDate Range start (inclusive)
	 * @param \DateTime $endDate Range end (exclusive upper bound)
	 * @return DataDownloadResponse
	 */
	private function exportAsDatev(string $userId, \DateTime $startDate, \DateTime $endDate): DataDownloadResponse
	{
		try {
			$content = $this->datevExportService->exportTimeEntries($userId, $startDate, $endDate);
			$filename = 'datev-export-' . date('Y-m-d') . '.txt';

			return new DataDownloadResponse($content, $filename, 'text/plain; charset=iso-8859-1');
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('ExportController::exportAsDatev failed: ' . $e->getMessage(), ['exception' => $e]);
			$errorData = [['error' => $this->safeExportErrorMessage($e)]];
			return $this->exportAsCsv($errorData, 'datev-export-error-' . date('Y-m-d') . '.csv', $this->getExportTimezone());
		}
	}

	private function getExportTimezone(): string
	{
		return $this->timeEntryExportTransformer->getTimezone();
	}

	/**
	 * Export time entries in DATEV format.
	 *
	 * Query params:
	 * - startDate / endDate (Y-m-d), inclusive calendar days
	 * - scope=self|organization (default self)
	 * - userId=… (admin only) export one other employee
	 *
	 * @return DataDownloadResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function datev(?string $startDate = null, ?string $endDate = null): DataDownloadResponse
	{
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				throw new \Exception($this->l10n->t('User not authenticated'));
			}

			$actorId = $user->getUID();
			[$start, $endExclusive] = $this->resolveInclusiveDateRange($startDate, $endDate);

			$scope = strtolower(trim((string)$this->request->getParam('scope', 'self')));
			$targetParam = trim((string)$this->request->getParam('userId', ''));

			if ($scope === 'organization' || $scope === 'org') {
				if (!$this->permissionService->isAdmin($actorId)) {
					$this->permissionService->logPermissionDenied($actorId, 'export_datev_org', 'datev_export');
					throw new \Exception($this->l10n->t('Access denied'));
				}
				return $this->exportAsDatevOrganization($start, $endExclusive);
			}

			$exportUserId = $actorId;
			if ($targetParam !== '' && $targetParam !== $actorId) {
				if (!$this->permissionService->isAdmin($actorId)) {
					$this->permissionService->logPermissionDenied($actorId, 'export_datev_user', 'datev_export', $targetParam);
					throw new \Exception($this->l10n->t('Access denied'));
				}
				if ($this->userManager === null || $this->userManager->get($targetParam) === null) {
					throw new \Exception($this->l10n->t('User not found'));
				}
				$exportUserId = $targetParam;
			}

			return $this->exportAsDatev($exportUserId, $start, $endExclusive);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ExportController::datev: ' . $e->getMessage(), ["exception" => $e]);
			$errorData = [['error' => $this->safeExportErrorMessage($e)]];
			return $this->exportAsCsv($errorData, 'datev-export-error-' . date('Y-m-d') . '.csv', $this->getExportTimezone());
		}
	}

	/**
	 * Get DATEV export configuration status (admin).
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function datevConfig(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not authenticated')
				], Http::STATUS_UNAUTHORIZED);
			}
			$userId = $user->getUID();
			if (!$this->permissionService->isAdmin($userId)) {
				$this->permissionService->logPermissionDenied($userId, 'read_datev_config', 'datev_config');
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied')
				], Http::STATUS_FORBIDDEN);
			}
			$status = $this->datevExportService->getConfigurationStatus();
			$personal = (string)$this->config->getUserValue(
				$userId,
				'arbeitszeitcheck',
				Constants::USER_DATEV_PERSONALNUMMER,
				''
			);
			$status['personalnummer_set_for_viewer'] = $personal !== '';
			$status['ready_for_self_export'] = !empty($status['configured']) && $personal !== '';
			return new JSONResponse([
				'success' => true,
				'config' => $status
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('ExportController::datevConfig failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->safeExportErrorMessage($e)
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * @return array{0: \DateTime, 1: \DateTime} [rangeStart, rangeEndExclusive]
	 */
	private function resolveInclusiveDateRange(?string $startDate, ?string $endDate): array
	{
		$end = $this->parseDateYmd($endDate, 'end_date') ?? new \DateTime();
		$end->setTime(0, 0, 0);
		$start = $this->parseDateYmd($startDate, 'start_date') ?? clone $end;
		if (!$startDate) {
			$start->modify('-30 days');
		}
		$start->setTime(0, 0, 0);

		if ($start > $end) {
			throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
		}

		$diff = $end->diff($start);
		$days = (int)$diff->format('%a');
		if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
			throw new \Exception($this->l10n->t(
				'Export date range must not exceed %d days. Please narrow the range.',
				[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
			));
		}

		$endExclusive = (clone $end)->modify('+1 day');
		return [$start, $endExclusive];
	}

	private function exportAsDatevOrganization(\DateTime $start, \DateTime $endExclusive): DataDownloadResponse
	{
		if ($this->userManager === null) {
			throw new \Exception($this->l10n->t('Export failed. Please try again. If the problem continues, contact your administrator.'));
		}
		$userIds = [];
		foreach ($this->userManager->search('') as $u) {
			if ($u->isEnabled()) {
				$userIds[] = $u->getUID();
			}
			if (count($userIds) >= 2000) {
				break;
			}
		}
		$content = $this->datevExportService->exportMultipleUsers($userIds, $start, $endExclusive);
		$filename = 'datev-export-org-' . date('Y-m-d') . '.txt';
		return new DataDownloadResponse($content, $filename, 'text/plain; charset=iso-8859-1');
	}
}
