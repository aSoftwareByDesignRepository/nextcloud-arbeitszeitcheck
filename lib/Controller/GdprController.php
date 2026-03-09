<?php

declare(strict_types=1);

/**
 * GDPR controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;

/**
 * GdprController
 */
class GdprController extends Controller
{
	private TimeEntryMapper $timeEntryMapper;
	private AbsenceMapper $absenceMapper;
	private UserSettingsMapper $userSettingsMapper;
	private ComplianceViolationMapper $violationMapper;
	private AuditLogMapper $auditLogMapper;
	private IUserSession $userSession;
	private IL10N $l10n;

	public function __construct(
		string $appName,
		IRequest $request,
		TimeEntryMapper $timeEntryMapper,
		AbsenceMapper $absenceMapper,
		UserSettingsMapper $userSettingsMapper,
		ComplianceViolationMapper $violationMapper,
		AuditLogMapper $auditLogMapper,
		IUserSession $userSession,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->timeEntryMapper = $timeEntryMapper;
		$this->absenceMapper = $absenceMapper;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->violationMapper = $violationMapper;
		$this->auditLogMapper = $auditLogMapper;
		$this->userSession = $userSession;
		$this->l10n = $l10n;
	}

	/**
	 * Export all user data for GDPR compliance (Art. 15 GDPR - Right to access)
	 * NoCSRFRequired: GET download is often opened via direct link (e.g. from settings); user must be logged in.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function export(): DataDownloadResponse
	{
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				throw new \Exception($this->l10n->t('User not authenticated'));
			}

			$userId = $user->getUID();

		// Collect all user data from database
		$allTimeEntries = $this->timeEntryMapper->findByUser($userId);
		$allAbsences = $this->absenceMapper->findByUser($userId);
		$allSettings = $this->userSettingsMapper->getUserSettings($userId);
		$allViolations = $this->violationMapper->findByUser($userId);
		$allAuditLogs = $this->auditLogMapper->findByUser($userId);

		// Convert entities to arrays for JSON export
		$timeEntriesData = [];
		foreach ($allTimeEntries as $entry) {
			try {
				$timeEntriesData[] = $entry->getSummary();
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for time entry ' . $entry->getId() . ' in GDPR export: ' . $e->getMessage(), ["exception" => $e]);
				continue;
			}
		}

		$absencesData = [];
		foreach ($allAbsences as $absence) {
			try {
				$startDate = $absence->getStartDate();
				$endDate = $absence->getEndDate();
				$absencesData[] = [
					'id' => $absence->getId(),
					'type' => $absence->getType(),
					'start_date' => $startDate ? $startDate->format('c') : null,
					'end_date' => $endDate ? $endDate->format('c') : null,
					'days' => $absence->getDays(),
					'reason' => $absence->getReason(),
					'status' => $absence->getStatus(),
					'approver_comment' => $absence->getApproverComment(),
					'approved_at' => $absence->getApprovedAt() ? $absence->getApprovedAt()->format('c') : null,
					'created_at' => ($createdAt = $absence->getCreatedAt()) ? $createdAt->format('c') : null,
					'updated_at' => ($updatedAt = $absence->getUpdatedAt()) ? $updatedAt->format('c') : null
				];
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error processing absence ' . $absence->getId() . ' in GDPR export: ' . $e->getMessage(), ["exception" => $e]);
				continue;
			}
		}

		$settingsData = [];
		foreach ($allSettings as $setting) {
			try {
				$createdAt = $setting->getCreatedAt();
				$updatedAt = $setting->getUpdatedAt();
				$settingsData[] = [
					'setting_key' => $setting->getSettingKey(),
					'setting_value' => $setting->getSettingValue(),
					'created_at' => $createdAt ? $createdAt->format('c') : null,
					'updated_at' => $updatedAt ? $updatedAt->format('c') : null
				];
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error processing setting in GDPR export: ' . $e->getMessage(), ["exception" => $e]);
				continue;
			}
		}

		$violationsData = [];
		foreach ($allViolations as $violation) {
			try {
				$date = $violation->getDate();
				$createdAt = $violation->getCreatedAt();
				$violationsData[] = [
					'id' => $violation->getId(),
					'violation_type' => $violation->getViolationType(),
					'description' => $violation->getDescription(),
					'date' => $date ? $date->format('c') : null,
					'time_entry_id' => $violation->getTimeEntryId(),
					'severity' => $violation->getSeverity(),
					'resolved' => $violation->getResolved(),
					'resolved_at' => $violation->getResolvedAt() ? $violation->getResolvedAt()->format('c') : null,
					'created_at' => $createdAt ? $createdAt->format('c') : null
				];
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error processing violation ' . $violation->getId() . ' in GDPR export: ' . $e->getMessage(), ["exception" => $e]);
				continue;
			}
		}

		$auditLogsData = [];
		foreach ($allAuditLogs as $log) {
			try {
				// Decode JSON values if present
				$oldValues = $log->getOldValues();
				$newValues = $log->getNewValues();

				if ($oldValues !== null) {
					$decoded = json_decode($oldValues, true);
					$oldValues = $decoded !== null ? $decoded : $oldValues;
				}

				if ($newValues !== null) {
					$decoded = json_decode($newValues, true);
					$newValues = $decoded !== null ? $decoded : $newValues;
				}

				$createdAt = $log->getCreatedAt();
				$auditLogsData[] = [
					'id' => $log->getId(),
					'action' => $log->getAction(),
					'entity_type' => $log->getEntityType(),
					'entity_id' => $log->getEntityId(),
					'old_values' => $oldValues,
					'new_values' => $newValues,
					'ip_address' => $log->getIpAddress(),
					'user_agent' => $log->getUserAgent(),
					'performed_by' => $log->getPerformedBy(),
					'created_at' => $createdAt ? $createdAt->format('c') : null
				];
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error processing audit log ' . $log->getId() . ' in GDPR export: ' . $e->getMessage(), ["exception" => $e]);
				continue;
			}
		}

		// Compile complete export data
		$data = [
			'export_metadata' => [
				'user_id' => $userId,
				'export_date' => date('c'),
				'export_reason' => 'GDPR Article 15 - Right to access',
				'app_version' => '1.0.0'
			],
			'time_entries' => $timeEntriesData,
			'absences' => $absencesData,
			'user_settings' => $settingsData,
			'compliance_violations' => $violationsData,
			'audit_logs' => $auditLogsData,
			'data_summary' => [
				'total_time_entries' => count($timeEntriesData),
				'total_absences' => count($absencesData),
				'total_settings' => count($settingsData),
				'total_violations' => count($violationsData),
				'total_audit_logs' => count($auditLogsData)
			]
		];

			$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

			if ($json === false) {
				throw new \Exception($this->l10n->t('Failed to encode data as JSON'));
			}

			$filename = 'arbeitszeitcheck-gdpr-export-' . $userId . '-' . date('Y-m-d') . '.json';

			return new DataDownloadResponse($json, $filename, 'application/json; charset=utf-8');
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in GdprController::export: ' . $e->getMessage(), ["exception" => $e]);
			// Return error as JSON file
			$errorData = ['error' => $e->getMessage()];
			$errorJson = json_encode($errorData, JSON_PRETTY_PRINT);
			$filename = 'arbeitszeitcheck-gdpr-export-error-' . date('Y-m-d') . '.json';
			return new DataDownloadResponse($errorJson, $filename, 'application/json; charset=utf-8');
		}
	}

	/**
	 * Delete all user data (GDPR Art. 17 - Right to erasure)
	 * Note: This respects legal retention periods (2 years minimum for time records per German labor law)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function delete(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				throw new \Exception($this->l10n->t('User not authenticated'));
			}

			$userId = $user->getUID();
			$now = new \DateTime();

			// Legal retention period: 2 years minimum for time records (German labor law)
			$retentionDate = clone $now;
			$retentionDate->modify('-2 years');

			// Get all time entries for user first
			$allTimeEntries = $this->timeEntryMapper->findByUser($userId);

			// Separate entries by retention period
			$oldTimeEntries = array_filter($allTimeEntries, function ($entry) use ($retentionDate) {
				return $entry->getStartTime() < $retentionDate;
			});

			$deletedCount = 0;
			$retainedCount = 0;

			// Delete old time entries (beyond retention period)
			foreach ($oldTimeEntries as $entry) {
				$this->timeEntryMapper->delete($entry);
				$deletedCount++;
			}

			// Count entries that must be retained (within 2 years)
			$recentEntries = array_filter($allTimeEntries, function ($entry) use ($retentionDate) {
				return $entry->getStartTime() >= $retentionDate;
			});
			$retainedCount = count($recentEntries);

			// Delete user settings (no retention required)
			$userSettings = $this->userSettingsMapper->getUserSettings($userId);
			foreach ($userSettings as $setting) {
				$this->userSettingsMapper->deleteSetting($userId, $setting->getSettingKey());
			}

			// Note: We keep absences, violations, and audit logs for compliance/legal purposes
			// These are necessary for legal compliance and audit trails
			// In a real implementation, these would have their own retention policies

			// Create audit log entry for this deletion request
			$this->auditLogMapper->logAction(
				$userId,
				'gdpr_data_deletion_request',
				'user',
				null, // entityId - not applicable for user-level deletion
				null, // oldValues
				[
					'deleted_time_entries' => $deletedCount,
					'retained_time_entries' => $retainedCount,
					'retention_period_years' => 2,
					'request_date' => $now->format('c')
				]
			);

			$message = $this->l10n->n(
				'Data deletion request processed. %d time entry deleted. %d entries retained due to legal 2-year retention requirement.',
				'Data deletion request processed. %d time entries deleted. %d entries retained due to legal 2-year retention requirement.',
				$deletedCount,
				[$deletedCount, $retainedCount]
			);

			return new JSONResponse([
				'success' => true,
				'message' => $message,
				'deleted_entries' => $deletedCount,
				'retained_entries' => $retainedCount,
				'retention_period' => '2 years',
				'note' => 'Some data must be retained for 2 years per German labor law (ArbZG) requirements. Audit logs and compliance violations are retained for legal compliance purposes.'
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_BAD_REQUEST);
		}
	}
}
