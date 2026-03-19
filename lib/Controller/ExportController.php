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
use OCA\ArbeitszeitCheck\Constants;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
	private IUserSession $userSession;
	private IL10N $l10n;
	private IConfig $config;

	public function __construct(
		string $appName,
		IRequest $request,
		TimeEntryMapper $timeEntryMapper,
		AbsenceMapper $absenceMapper,
		ComplianceViolationMapper $violationMapper,
		DatevExportService $datevExportService,
		IUserSession $userSession,
		IL10N $l10n,
		IConfig $config
	) {
		parent::__construct($appName, $request);
		$this->timeEntryMapper = $timeEntryMapper;
		$this->absenceMapper = $absenceMapper;
		$this->violationMapper = $violationMapper;
		$this->datevExportService = $datevExportService;
		$this->userSession = $userSession;
		$this->l10n = $l10n;
		$this->config = $config;
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
			$end = $endDate ? new \DateTime($endDate) : new \DateTime();
			$end->setTime(23, 59, 59);
			$start = $startDate ? new \DateTime($startDate) : clone $end;
			if (!$startDate) {
				$start->modify('-30 days');
			}
			$start->setTime(0, 0, 0);

			if ($start > $end) {
				throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
			}

			// Enforce max date range to prevent heavy queries
			$diff = $end->diff($start);
			$days = (int) $diff->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				throw new \Exception($this->l10n->t(
					'Export date range must not exceed %d days. Please narrow the range.',
					[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
				));
			}

			// Get time entries from database
			$entries = $this->timeEntryMapper->findByUserAndDateRange($userId, $start, $end);

			// Decide whether to virtually split entries that span midnight in CSV/JSON export
			$enableMidnightSplit = $this->config->getAppValue('arbeitszeitcheck', 'export_midnight_split_enabled', '1') === '1';

			// Convert to array format (optionally with midnight split for entries spanning two calendar days)
			$data = [];
			foreach ($entries as $entry) {
				try {
					$startTime = $entry->getStartTime();
					if (!$startTime) {
						continue; // Skip entries with no start time
					}

					$endTime = $entry->getEndTime();

					// If the optional midnight split is disabled, always export as a single, unsplit row
					if (!$enableMidnightSplit || $endTime === null) {
						$data[] = [
							'id' => $entry->getId(),
							'date' => $startTime->format('Y-m-d'),
							'start_time' => $startTime->format('H:i:s'),
							'end_time' => $endTime ? $endTime->format('H:i:s') : '',
							'break_start' => ($breakStart = $entry->getBreakStartTime()) ? $breakStart->format('H:i:s') : '',
							'break_end' => ($breakEnd = $entry->getBreakEndTime()) ? $breakEnd->format('H:i:s') : '',
							'duration_hours' => $entry->getDurationHours(),
							'break_duration_hours' => $entry->getBreakDurationHours(),
							'working_hours' => $entry->getWorkingDurationHours(),
							'description' => $entry->getDescription() ?? '',
							'status' => $entry->getStatus(),
							'is_manual_entry' => $entry->getIsManualEntry() ? 'Yes' : 'No',
							'project_id' => $entry->getProjectCheckProjectId() ?? ''
						];
						continue;
					}

					// If entry has no end time or does not cross midnight, export as a single row (current behaviour)
					if ($startTime->format('Y-m-d') === $endTime->format('Y-m-d')) {
						$data[] = [
							'id' => $entry->getId(),
							'date' => $startTime->format('Y-m-d'),
							'start_time' => $startTime->format('H:i:s'),
							'end_time' => $endTime ? $endTime->format('H:i:s') : '',
							'break_start' => ($breakStart = $entry->getBreakStartTime()) ? $breakStart->format('H:i:s') : '',
							'break_end' => ($breakEnd = $entry->getBreakEndTime()) ? $breakEnd->format('H:i:s') : '',
							'duration_hours' => $entry->getDurationHours(),
							'break_duration_hours' => $entry->getBreakDurationHours(),
							'working_hours' => $entry->getWorkingDurationHours(),
							'description' => $entry->getDescription() ?? '',
							'status' => $entry->getStatus(),
							'is_manual_entry' => $entry->getIsManualEntry() ? 'Yes' : 'No',
							'project_id' => $entry->getProjectCheckProjectId() ?? ''
						];
						continue;
					}

					// Entry spans midnight: split into two virtual segments (before/after 00:00)
					$startTs = $startTime->getTimestamp();
					$endTs = $endTime->getTimestamp();

					if ($endTs <= $startTs) {
						// Defensive: if end before start (should not happen for completed entries), fall back to single row
						$data[] = [
							'id' => $entry->getId(),
							'date' => $startTime->format('Y-m-d'),
							'start_time' => $startTime->format('H:i:s'),
							'end_time' => $endTime->format('H:i:s'),
							'break_start' => ($breakStart = $entry->getBreakStartTime()) ? $breakStart->format('H:i:s') : '',
							'break_end' => ($breakEnd = $entry->getBreakEndTime()) ? $breakEnd->format('H:i:s') : '',
							'duration_hours' => $entry->getDurationHours(),
							'break_duration_hours' => $entry->getBreakDurationHours(),
							'working_hours' => $entry->getWorkingDurationHours(),
							'description' => $entry->getDescription() ?? '',
							'status' => $entry->getStatus(),
							'is_manual_entry' => $entry->getIsManualEntry() ? 'Yes' : 'No',
							'project_id' => $entry->getProjectCheckProjectId() ?? ''
						];
						continue;
					}

					// Midnight boundary (00:00 of the day after startTime)
					$midnight = clone $startTime;
					$midnight->setTime(0, 0, 0);
					if ($midnight->getTimestamp() <= $startTs) {
						$midnight->modify('+1 day');
					}
					$midnightTs = $midnight->getTimestamp();

					// If, ausnahmsweise, der Endzeitpunkt nicht hinter dieser Mitternacht liegt, behalten wir die Eintragslogik unverändert
					if ($endTs <= $midnightTs) {
						$data[] = [
							'id' => $entry->getId(),
							'date' => $startTime->format('Y-m-d'),
							'start_time' => $startTime->format('H:i:s'),
							'end_time' => $endTime->format('H:i:s'),
							'break_start' => ($breakStart = $entry->getBreakStartTime()) ? $breakStart->format('H:i:s') : '',
							'break_end' => ($breakEnd = $entry->getBreakEndTime()) ? $breakEnd->format('H:i:s') : '',
							'duration_hours' => $entry->getDurationHours(),
							'break_duration_hours' => $entry->getBreakDurationHours(),
							'working_hours' => $entry->getWorkingDurationHours(),
							'description' => $entry->getDescription() ?? '',
							'status' => $entry->getStatus(),
							'is_manual_entry' => $entry->getIsManualEntry() ? 'Yes' : 'No',
							'project_id' => $entry->getProjectCheckProjectId() ?? ''
						];
						continue;
					}

					$totalDurationSeconds = $endTs - $startTs;
					if ($totalDurationSeconds <= 0) {
						// Fallback – sollte durch obige Checks eigentlich nicht vorkommen
						$data[] = [
							'id' => $entry->getId(),
							'date' => $startTime->format('Y-m-d'),
							'start_time' => $startTime->format('H:i:s'),
							'end_time' => $endTime->format('H:i:s'),
							'break_start' => ($breakStart = $entry->getBreakStartTime()) ? $breakStart->format('H:i:s') : '',
							'break_end' => ($breakEnd = $entry->getBreakEndTime()) ? $breakEnd->format('H:i:s') : '',
							'duration_hours' => $entry->getDurationHours(),
							'break_duration_hours' => $entry->getBreakDurationHours(),
							'working_hours' => $entry->getWorkingDurationHours(),
							'description' => $entry->getDescription() ?? '',
							'status' => $entry->getStatus(),
							'is_manual_entry' => $entry->getIsManualEntry() ? 'Yes' : 'No',
							'project_id' => $entry->getProjectCheckProjectId() ?? ''
						];
						continue;
					}

					// Dauer vor und nach Mitternacht
					$durationBeforeMidnightSeconds = max(0, $midnightTs - $startTs);
					$durationAfterMidnightSeconds = max(0, $endTs - $midnightTs);

					// Falls es wider Erwarten nur einen winzigen Überlauf gibt, behalten wir zur Sicherheit das Original bei
					if ($durationBeforeMidnightSeconds === 0 || $durationAfterMidnightSeconds === 0) {
						$data[] = [
							'id' => $entry->getId(),
							'date' => $startTime->format('Y-m-d'),
							'start_time' => $startTime->format('H:i:s'),
							'end_time' => $endTime->format('H:i:s'),
							'break_start' => ($breakStart = $entry->getBreakStartTime()) ? $breakStart->format('H:i:s') : '',
							'break_end' => ($breakEnd = $entry->getBreakEndTime()) ? $breakEnd->format('H:i:s') : '',
							'duration_hours' => $entry->getDurationHours(),
							'break_duration_hours' => $entry->getBreakDurationHours(),
							'working_hours' => $entry->getWorkingDurationHours(),
							'description' => $entry->getDescription() ?? '',
							'status' => $entry->getStatus(),
							'is_manual_entry' => $entry->getIsManualEntry() ? 'Yes' : 'No',
							'project_id' => $entry->getProjectCheckProjectId() ?? ''
						];
						continue;
					}

					$durationBeforeMidnight = $durationBeforeMidnightSeconds / 3600.0;
					$durationAfterMidnight = $durationAfterMidnightSeconds / 3600.0;

					$totalDurationHours = $entry->getDurationHours();
					$totalWorkingHours = $entry->getWorkingDurationHours();
					$totalBreakHours = $entry->getBreakDurationHours();

					// Wenn keine Dauer berechnet werden kann, behalten wir das bisherige Verhalten bei
					if ($totalDurationHours === null || $totalDurationHours <= 0) {
						$data[] = [
							'id' => $entry->getId(),
							'date' => $startTime->format('Y-m-d'),
							'start_time' => $startTime->format('H:i:s'),
							'end_time' => $endTime->format('H:i:s'),
							'break_start' => ($breakStart = $entry->getBreakStartTime()) ? $breakStart->format('H:i:s') : '',
							'break_end' => ($breakEnd = $entry->getBreakEndTime()) ? $breakEnd->format('H:i:s') : '',
							'duration_hours' => $totalDurationHours,
							'break_duration_hours' => $totalBreakHours,
							'working_hours' => $totalWorkingHours,
							'description' => $entry->getDescription() ?? '',
							'status' => $entry->getStatus(),
							'is_manual_entry' => $entry->getIsManualEntry() ? 'Yes' : 'No',
							'project_id' => $entry->getProjectCheckProjectId() ?? ''
						];
						continue;
					}

					// Proportionale Zuordnung von Arbeits- und Pausenzeit auf die beiden Kalendertage
					$ratioBefore = $durationBeforeMidnight / $totalDurationHours;
					$ratioAfter = $durationAfterMidnight / $totalDurationHours;

					$workingBefore = $totalWorkingHours !== null ? $totalWorkingHours * $ratioBefore : null;
					$workingAfter = $totalWorkingHours !== null ? $totalWorkingHours * $ratioAfter : null;

					$breakBefore = $totalBreakHours !== null ? $totalBreakHours * $ratioBefore : null;
					$breakAfter = $totalBreakHours !== null ? $totalBreakHours * $ratioAfter : null;

					// Segment 1: Von Start bis kurz vor Mitternacht (23:59:59)
					$data[] = [
						'id' => $entry->getId(),
						'date' => $startTime->format('Y-m-d'),
						'start_time' => $startTime->format('H:i:s'),
						'end_time' => '23:59:59',
						// Einzelne Break-Start/-End-Zeiten lassen sich nicht eindeutig segmentieren, daher leer
						'break_start' => '',
						'break_end' => '',
						'duration_hours' => $durationBeforeMidnight,
						'break_duration_hours' => $breakBefore,
						'working_hours' => $workingBefore,
						'description' => $entry->getDescription() ?? '',
						'status' => $entry->getStatus(),
						'is_manual_entry' => $entry->getIsManualEntry() ? 'Yes' : 'No',
						'project_id' => $entry->getProjectCheckProjectId() ?? ''
					];

					// Segment 2: Von Mitternacht bis Endzeit
					$data[] = [
						'id' => $entry->getId(),
						'date' => $midnight->format('Y-m-d'),
						'start_time' => '00:00:00',
						'end_time' => $endTime->format('H:i:s'),
						'break_start' => '',
						'break_end' => '',
						'duration_hours' => $durationAfterMidnight,
						'break_duration_hours' => $breakAfter,
						'working_hours' => $workingAfter,
						'description' => $entry->getDescription() ?? '',
						'status' => $entry->getStatus(),
						'is_manual_entry' => $entry->getIsManualEntry() ? 'Yes' : 'No',
						'project_id' => $entry->getProjectCheckProjectId() ?? ''
					];
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error processing time entry ' . $entry->getId() . ' in export: ' . $e->getMessage(), ["exception" => $e]);
					continue;
				}
			}

			$filename = 'time-entries-' . date('Y-m-d') . '.' . $format;

			if ($format === 'pdf') {
				return new JSONResponse([
					'error' => $this->l10n->t('PDF export is not available. Please use CSV.')
				], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			return match($format) {
				'csv' => $this->exportAsCsv($data, $filename),
				'json' => $this->exportAsJson($data, $filename),
				'datev' => $this->exportAsDatev($userId, $start, $end),
				default => $this->exportAsCsv($data, $filename)
			};
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ExportController::timeEntries: ' . $e->getMessage(), ["exception" => $e]);
			// Return error as CSV with error message
			$errorData = [['error' => $this->l10n->t('Export failed: %s', [$e->getMessage()])]];
			return $this->exportAsCsv($errorData, 'time-entries-export-error-' . date('Y-m-d') . '.csv');
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
	public function absences(string $format = 'csv', ?string $startDate = null, ?string $endDate = null): DataDownloadResponse|JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				throw new \Exception($this->l10n->t('User not authenticated'));
			}

			$userId = $user->getUID();

			// Determine date range (default to last year if not specified)
			$end = $endDate ? new \DateTime($endDate) : new \DateTime();
			$start = $startDate ? new \DateTime($startDate) : clone $end;
			if (!$startDate) {
				$start->modify('-1 year');
			}
			$start->setTime(0, 0, 0);
			$end->setTime(23, 59, 59);

			if ($start > $end) {
				throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
			}

			// Enforce max date range to prevent heavy queries
			$diff = $end->diff($start);
			$days = (int) $diff->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				throw new \Exception($this->l10n->t(
					'Export date range must not exceed %d days. Please narrow the range.',
					[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
				));
			}

			// Get absences from database
			$absences = $this->absenceMapper->findByUserAndDateRange($userId, $start, $end);

			// Convert to array format
			$data = [];
			foreach ($absences as $absence) {
				try {
					$startDate = $absence->getStartDate();
					$endDate = $absence->getEndDate();
					$createdAt = $absence->getCreatedAt();
					$data[] = [
						'id' => $absence->getId(),
						'type' => $absence->getType(),
						'start_date' => $startDate ? $startDate->format('Y-m-d') : '',
						'end_date' => $endDate ? $endDate->format('Y-m-d') : '',
						'days' => $absence->getDays(),
						'reason' => $absence->getReason() ?? '',
						'status' => $absence->getStatus(),
						'approver_comment' => $absence->getApproverComment() ?? '',
						'approved_at' => $absence->getApprovedAt() ? $absence->getApprovedAt()->format('Y-m-d H:i:s') : '',
						'created_at' => $createdAt ? $createdAt->format('Y-m-d H:i:s') : ''
					];
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error processing absence ' . $absence->getId() . ' in export: ' . $e->getMessage(), ["exception" => $e]);
					continue;
				}
			}

			$filename = 'absences-' . date('Y-m-d') . '.' . $format;

			if ($format === 'pdf') {
				return new JSONResponse([
					'error' => $this->l10n->t('PDF export is not available. Please use CSV.')
				], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			return match($format) {
				'csv' => $this->exportAsCsv($data, $filename),
				'json' => $this->exportAsJson($data, $filename),
				default => $this->exportAsCsv($data, $filename)
			};
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ExportController::absences: ' . $e->getMessage(), ["exception" => $e]);
			// Return error as CSV with error message
			$errorData = [['error' => $this->l10n->t('Export failed: %s', [$e->getMessage()])]];
			return $this->exportAsCsv($errorData, 'absences-export-error-' . date('Y-m-d') . '.csv');
		}
	}

	/**
	 * Export compliance reports
	 *
	 * @param string $format Format: csv, json
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function compliance(string $format = 'csv', ?string $startDate = null, ?string $endDate = null): DataDownloadResponse
	{
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				throw new \Exception($this->l10n->t('User not authenticated'));
			}

			$userId = $user->getUID();

			// Determine date range (default to last 30 days if not specified)
			$end = $endDate ? new \DateTime($endDate) : new \DateTime();
			$end->setTime(23, 59, 59);
			$start = $startDate ? new \DateTime($startDate) : clone $end;
			if (!$startDate) {
				$start->modify('-30 days');
			}
			$start->setTime(0, 0, 0);

			if ($start > $end) {
				throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
			}

			// Enforce max date range to prevent heavy queries
			$diff = $end->diff($start);
			$days = (int) $diff->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				throw new \Exception($this->l10n->t(
					'Export date range must not exceed %d days. Please narrow the range.',
					[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
				));
			}

			// Get compliance violations for user from database
			$violations = $this->violationMapper->findByDateRange($start, $end, $userId);

			// Convert to array format
			$data = [];
			foreach ($violations as $violation) {
				try {
					$date = $violation->getDate();
					if (!$date) {
						continue; // Skip violations with no date
					}
					$data[] = [
						'id' => $violation->getId(),
						'date' => $date->format('Y-m-d'),
						'violation_type' => $violation->getViolationType(),
						'description' => $violation->getDescription(),
						'severity' => $violation->getSeverity(),
						'resolved' => $violation->getResolved() ? 'Yes' : 'No',
						'resolved_at' => $violation->getResolvedAt() ? $violation->getResolvedAt()->format('Y-m-d H:i:s') : '',
						'time_entry_id' => $violation->getTimeEntryId()
					];
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error processing violation ' . $violation->getId() . ' in export: ' . $e->getMessage(), ["exception" => $e]);
					continue;
				}
			}

			$filename = 'compliance-report-' . date('Y-m-d') . '.' . $format;

			return match($format) {
				'csv' => $this->exportAsCsv($data, $filename),
				'json' => $this->exportAsJson($data, $filename),
				default => $this->exportAsCsv($data, $filename)
			};
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ExportController::compliance: ' . $e->getMessage(), ["exception" => $e]);
			// Return error as CSV with error message
			$errorData = [['error' => $this->l10n->t('Export failed: %s', [$e->getMessage()])]];
			return $this->exportAsCsv($errorData, 'compliance-export-error-' . date('Y-m-d') . '.csv');
		}
	}

	/**
	 * Export data as CSV
	 *
	 * @param array $data Data to export
	 * @param string $filename Filename
	 * @return DataDownloadResponse
	 */
	private function exportAsCsv(array $data, string $filename): DataDownloadResponse
	{
		if (empty($data)) {
			$csv = "No data available\n";
		} else {
			// Create CSV content
			$fp = fopen('php://temp', 'r+');
			
			// Write header
			fputcsv($fp, array_keys($data[0]));
			
			// Write data rows
			foreach ($data as $row) {
				fputcsv($fp, $row);
			}
			
			rewind($fp);
			$csv = stream_get_contents($fp);
			fclose($fp);
		}

		return new DataDownloadResponse($csv, $filename, 'text/csv; charset=utf-8');
	}

	/**
	 * Export data as JSON
	 *
	 * @param array $data Data to export
	 * @param string $filename Filename
	 * @return DataDownloadResponse
	 */
	private function exportAsJson(array $data, string $filename): DataDownloadResponse
	{
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		
		if ($json === false) {
			throw new \Exception($this->l10n->t('Failed to encode data as JSON'));
		}

		return new DataDownloadResponse($json, $filename, 'application/json; charset=utf-8');
	}

	/**
	 * Export data as PDF (simple text-based PDF)
	 * Note: For production, consider using a PDF library like TCPDF or FPDF
	 *
	 * @param array $data Data to export
	 * @param string $filename Filename
	 * @param string $title Report title
	 * @return DataDownloadResponse
	 */
	private function exportAsPdf(array $data, string $filename, string $title): DataDownloadResponse
	{
		// For now, export as CSV since PDF generation requires external libraries
		// In production, this should use a proper PDF library
		// This is a workaround that provides the data in a usable format
		return $this->exportAsCsv($data, str_replace('.pdf', '.csv', $filename));
	}

	/**
	 * Export time entries in DATEV format
	 *
	 * @param string $userId User ID
	 * @param \DateTime $startDate Start date
	 * @param \DateTime $endDate End date
	 * @return DataDownloadResponse
	 */
	private function exportAsDatev(string $userId, \DateTime $startDate, \DateTime $endDate): DataDownloadResponse
	{
		try {
			$content = $this->datevExportService->exportTimeEntries($userId, $startDate, $endDate);
			$filename = 'datev-export-' . date('Y-m-d') . '.txt';
			
			return new DataDownloadResponse($content, $filename, 'text/plain; charset=iso-8859-1');
		} catch (\Throwable $e) {
			// Return error as CSV with error message
			$errorData = [['error' => $e->getMessage()]];
			return $this->exportAsCsv($errorData, 'datev-export-error-' . date('Y-m-d') . '.csv');
		}
	}

	/**
	 * Export time entries in DATEV format
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
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

			$userId = $user->getUID();

			// Determine date range (default to last 30 days if not specified)
			$end = $endDate ? new \DateTime($endDate) : new \DateTime();
			$end->setTime(23, 59, 59);
			$start = $startDate ? new \DateTime($startDate) : clone $end;
			if (!$startDate) {
				$start->modify('-30 days');
			}
			$start->setTime(0, 0, 0);

			if ($start > $end) {
				throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
			}

			// Enforce max date range to prevent heavy queries
			$diff = $end->diff($start);
			$days = (int) $diff->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				throw new \Exception($this->l10n->t(
					'Export date range must not exceed %d days. Please narrow the range.',
					[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
				));
			}

			// Use the existing DATEV export method
			return $this->exportAsDatev($userId, $start, $end);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ExportController::datev: ' . $e->getMessage(), ["exception" => $e]);
			// Return error as CSV with error message
			$errorData = [['error' => $e->getMessage()]];
			return $this->exportAsCsv($errorData, 'datev-export-error-' . date('Y-m-d') . '.csv');
		}
	}

	/**
	 * Get DATEV export configuration status
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function datevConfig(): JSONResponse
	{
		try {
			$status = $this->datevExportService->getConfigurationStatus();
			return new JSONResponse([
				'success' => true,
				'config' => $status
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Export failed: %s', [$e->getMessage()])
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}