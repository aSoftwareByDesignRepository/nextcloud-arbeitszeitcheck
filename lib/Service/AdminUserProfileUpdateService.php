<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignment;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\OpeningBalanceYearValidator;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCA\ArbeitszeitCheck\Support\StrictYmdDates;
use OCA\ArbeitszeitCheck\Support\DatevOrgCredentials;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Atomic admin updates for a single employee profile (work schedule, vacation
 * policy, time capture, overtime). All writes for the combined save run inside
 * one DB transaction so operators never end up with half-persisted rows.
 */
class AdminUserProfileUpdateService
{
	use TTransactional;

	public function __construct(
		private readonly IUserManager $userManager,
		private readonly UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private readonly WorkingTimeModelMapper $workingTimeModelMapper,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly UserSettingsMapper $userSettingsMapper,
		private readonly VacationYearBalanceMapper $vacationYearBalanceMapper,
		private readonly VacationAllocationService $vacationAllocationService,
		private readonly TariffRuleSetMapper $tariffRuleSetMapper,
		private readonly UserVacationPolicyAssignmentMapper $userVacationPolicyAssignmentMapper,
		private readonly UserOvertimeSettingsService $userOvertimeSettingsService,
		private readonly UserEmploymentSettingsService $userEmploymentSettingsService,
		private readonly TimeCaptureMethodService $timeCaptureMethodService,
		private readonly IL10N $l10n,
		private readonly IDBConnection $db,
		private readonly ?LaborLawProfileFactory $laborLawProfileFactory = null,
		private readonly ?IConfig $config = null,
		private readonly ?VacationUnitService $vacationUnitService = null,
		private readonly ?VacationUnitMigrationService $vacationUnitMigrationService = null,
	) {
	}

	private function vacationAmountCeiling(): float
	{
		// Bachus: admins always enter calendar days (0–366). Storage may be hours.
		return 366.0;
	}

	private function adminDaysToStoredAmount(float $days): float
	{
		if ($this->vacationUnitService !== null) {
			return $this->vacationUnitService->adminDaysToStoredAmount($days);
		}
		return $days;
	}

	private function storedAmountToAdminDays(float $stored): float
	{
		if ($this->vacationUnitService !== null) {
			return $this->vacationUnitService->storedAmountToAdminDays($stored);
		}
		return $stored;
	}

	/**
	 * @param array<string, mixed> $summary
	 * @return array<string, mixed>
	 */
	private function presentUserModelSummary(array $summary): array
	{
		if (isset($summary['vacationDaysPerYear']) && $summary['vacationDaysPerYear'] !== null) {
			$summary['vacationDaysPerYear'] = $this->storedAmountToAdminDays((float)$summary['vacationDaysPerYear']);
		}

		return $summary;
	}

	/**
	 * @param array{
	 *   workingTimeModel?: array<string, mixed>,
	 *   vacationPolicy?: array<string, mixed>,
	 *   timeCapture?: array<string, mixed>,
	 *   overtime?: array<string, mixed>,
	 *   employment?: array<string, mixed>,
	 *   datev?: array<string, mixed>
	 * } $payload
	 * @return array<string, mixed>
	 */
	public function updateProfile(string $userId, array $payload, string $performedBy): array
	{
		$this->assertUserExists($userId);

		$workingTimeModel = is_array($payload['workingTimeModel'] ?? null) ? $payload['workingTimeModel'] : [];
		$vacationPolicy = is_array($payload['vacationPolicy'] ?? null) ? $payload['vacationPolicy'] : [];
		$timeCapture = is_array($payload['timeCapture'] ?? null) ? $payload['timeCapture'] : [];
		$overtime = is_array($payload['overtime'] ?? null) ? $payload['overtime'] : [];
		$employment = is_array($payload['employment'] ?? null) ? $payload['employment'] : [];
		$datev = is_array($payload['datev'] ?? null) ? $payload['datev'] : [];

		// Pre-flight validation (read-only) before opening a transaction.
		$this->preflightWorkingTimeModel($userId, $workingTimeModel);
		$this->preflightVacationPolicy($userId, $vacationPolicy);
		$this->preflightTimeCapture($userId, $timeCapture);
		$this->preflightOvertime($overtime);
		$this->preflightEmployment($userId, $employment);
		$this->preflightDatev($datev);

		return $this->atomic(function () use ($userId, $workingTimeModel, $vacationPolicy, $timeCapture, $overtime, $employment, $datev, $performedBy): array {
			$result = ['success' => true];
			$result = array_merge($result, $this->applyWorkingTimeModel($userId, $workingTimeModel, $performedBy));
			$result = array_merge($result, $this->applyVacationPolicy($userId, $vacationPolicy, $performedBy));
			$result = array_merge($result, $this->applyTimeCaptureSettings($userId, $timeCapture, $performedBy));
			$result = array_merge($result, $this->applyOvertimeSettings($userId, $overtime, $performedBy));
			$result = array_merge($result, $this->applyEmploymentSettings($userId, $employment, $performedBy));
			$result = array_merge($result, $this->applyDatevSettings($userId, $datev, $performedBy));

			return $result;
		}, $this->db);
	}

	/**
	 * Read-only validation for batch/dry-run callers — no writes.
	 *
	 * @param array{
	 *   workingTimeModel?: array<string, mixed>,
	 *   vacationPolicy?: array<string, mixed>
	 * } $payload
	 */
	public function validateProfileFields(string $userId, array $payload): void
	{
		$workingTimeModel = is_array($payload['workingTimeModel'] ?? null) ? $payload['workingTimeModel'] : [];
		$vacationPolicy = is_array($payload['vacationPolicy'] ?? null) ? $payload['vacationPolicy'] : [];
		if ($workingTimeModel !== []) {
			$this->preflightWorkingTimeModel($userId, $workingTimeModel);
		}
		if ($vacationPolicy !== []) {
			$this->preflightVacationPolicy($userId, $vacationPolicy);
		}
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function applyWorkingTimeModel(string $userId, array $params, string $performedBy): array
	{
		$this->preflightWorkingTimeModel($userId, $params);

		$workingTimeModelIdRaw = $params['workingTimeModelId'] ?? null;
		$workingTimeModelId = ($workingTimeModelIdRaw !== null && $workingTimeModelIdRaw !== '')
			? (int)$workingTimeModelIdRaw
			: null;
		$vacationDaysPerYear = null;
		if (isset($params['vacationDaysPerYear']) && $params['vacationDaysPerYear'] !== '' && $params['vacationDaysPerYear'] !== null) {
			$daysInput = (float)str_replace(',', '.', (string)$params['vacationDaysPerYear']);
			if ($daysInput < 0 || $daysInput > 366) {
				throw new AdminUserProfileUpdateException(
					$this->l10n->t('Vacation days per year must be between 0 and 366')
				);
			}
			$stored = $this->adminDaysToStoredAmount($daysInput);
			$vacationDaysPerYear = (int)round($stored);
		}
		$startDate = $params['startDate'] ?? null;
		$endDate = $params['endDate'] ?? null;
		$germanState = isset($params['germanState']) ? (string)$params['germanState'] : null;

		// Resolve the row this dialog edits *in place*. Using the editable
		// resolution (active-today, else the latest assignment) instead of
		// "active today" only is what stops a duplicate row being inserted on
		// every save when the assignment is future-dated ("Gültig von" set ahead
		// of time) or already ended.
		$currentModel = $this->userWorkingTimeModelMapper->findEditableByUser($userId);
		$oldValues = $currentModel ? $this->userWorkingTimeModelToAuditValues($currentModel) : null;
		$updated = null;

		// Assignment "unchanged" must only skip the model-row update. Region,
		// labour-law country, and vacation carryover live in the same payload
		// and must still run (customer bug: carryover alone never persisted).
		if ($currentModel && $workingTimeModelId !== null && $workingTimeModelId > 0) {
			if ($this->workingTimeModelAssignmentMatches($currentModel, $workingTimeModelId, $vacationDaysPerYear, $startDate, $endDate)) {
				$updated = $currentModel;
			} else {
				if ($startDate) {
					$currentModel->setStartDate(new \DateTime((string)$startDate));
				}
				if ($endDate !== null) {
					$currentModel->setEndDate($endDate ? new \DateTime((string)$endDate) : null);
				}
				$currentModel->setWorkingTimeModelId($workingTimeModelId);
				if ($vacationDaysPerYear !== null) {
					$currentModel->setVacationDaysPerYear($vacationDaysPerYear);
				}
				$currentModel->setUpdatedAt(new \DateTime());
				$this->assertEntityValid($currentModel->validate());
				$updated = $this->userWorkingTimeModelMapper->update($currentModel);
				$this->auditLogMapper->logAction(
					$userId,
					'user_working_time_model_updated',
					'user_working_time_model',
					$updated->getId(),
					$oldValues,
					$this->userWorkingTimeModelToAuditValues($updated),
					$performedBy
				);
			}
		} elseif ($workingTimeModelId !== null && $workingTimeModelId > 0) {
			$newModel = new UserWorkingTimeModel();
			$newModel->setUserId($userId);
			$newModel->setWorkingTimeModelId($workingTimeModelId);
			$newModel->setVacationDaysPerYear($vacationDaysPerYear ?? $this->defaultVacationDaysSuggestion());
			$newModel->setStartDate(new \DateTime($startDate ? (string)$startDate : 'now'));
			if ($endDate) {
				$newModel->setEndDate(new \DateTime((string)$endDate));
			}
			$newModel->setCreatedAt(new \DateTime());
			$newModel->setUpdatedAt(new \DateTime());
			$this->assertEntityValid($newModel->validate());
			$updated = $this->userWorkingTimeModelMapper->insert($newModel);
			$this->auditLogMapper->logAction(
				$userId,
				'user_working_time_model_created',
				'user_working_time_model',
				$updated->getId(),
				null,
				$this->userWorkingTimeModelToAuditValues($updated),
				$performedBy
			);
		} elseif ($currentModel) {
			// No model selected ("No Model Assigned"): retire the editable
			// assignment. A not-yet-started assignment is deleted outright —
			// ending it with today's date would produce an invalid start > end
			// range — while an active or past one is closed with an end date.
			$today = new \DateTime();
			$today->setTime(0, 0, 0);
			$startsInFuture = $currentModel->getStartDate() !== null && $currentModel->getStartDate() > $today;
			if ($startsInFuture) {
				$this->userWorkingTimeModelMapper->delete($currentModel);
				$this->auditLogMapper->logAction(
					$userId,
					'user_working_time_model_ended',
					'user_working_time_model',
					$currentModel->getId(),
					$oldValues,
					null,
					$performedBy
				);
			} else {
				$endDateForRemoval = $endDate ? new \DateTime((string)$endDate) : new \DateTime();
				$currentModel->setEndDate($endDateForRemoval);
				$currentModel->setUpdatedAt(new \DateTime());
				$this->assertEntityValid($currentModel->validate());
				$updated = $this->userWorkingTimeModelMapper->update($currentModel);
				$this->auditLogMapper->logAction(
					$userId,
					'user_working_time_model_ended',
					'user_working_time_model',
					$currentModel->getId(),
					$oldValues,
					$this->userWorkingTimeModelToAuditValues($updated),
					$performedBy
				);
			}
		}

		if ($germanState !== null) {
			if ($germanState === '') {
				$this->userSettingsMapper->deleteSetting($userId, 'german_state');
			} else {
				$this->userSettingsMapper->setSetting($userId, 'german_state', strtoupper(trim($germanState)));
			}
		}

		if (array_key_exists('laborLawCountry', $params)) {
			$laborLawCountry = strtoupper(trim((string)$params['laborLawCountry']));
			if ($laborLawCountry === '') {
				$this->userSettingsMapper->deleteSetting($userId, LaborLawProfileFactory::USER_SETTING_LABOR_LAW_COUNTRY);
			} elseif (!RegionRegistry::isSupportedCountry($laborLawCountry)) {
				throw new AdminUserProfileUpdateException($this->l10n->t('Invalid labour-law country'));
			} else {
				$this->userSettingsMapper->setSetting(
					$userId,
					LaborLawProfileFactory::USER_SETTING_LABOR_LAW_COUNTRY,
					$laborLawCountry
				);
			}
			// Drop request-cached profiles so same-request readers (compliance,
			// capabilities, break reminders) see the new effective country (E-9).
			$this->laborLawProfileFactory?->clearCache();
		}

		if (array_key_exists('vacationCarryoverDays', $params) && $params['vacationCarryoverDays'] !== '' && $params['vacationCarryoverDays'] !== null) {
			$carryoverYear = isset($params['vacationCarryoverYear']) ? (int)$params['vacationCarryoverYear'] : (int)date('Y');
			if ($carryoverYear < 2000 || $carryoverYear > 2100) {
				throw new AdminUserProfileUpdateException($this->l10n->t('Invalid year for vacation carryover'));
			}
			$carryoverDaysInput = $this->parseDecimalInput($params['vacationCarryoverDays'], 0.0);
			if ($carryoverDaysInput < 0 || $carryoverDaysInput > 366.0) {
				throw new AdminUserProfileUpdateException(
					$this->l10n->t('Vacation carryover must be between 0 and 366 days')
				);
			}
			$writeCarryover = function () use ($userId, $carryoverYear, $carryoverDaysInput): void {
				$carryoverVal = $this->adminDaysToStoredAmount($carryoverDaysInput);
				$carryoverVal = $this->vacationAllocationService->applyCapToOpeningBalance($carryoverVal);
				$hoursMode = $this->vacationUnitService?->isHoursMode() === true;
				$this->vacationYearBalanceMapper->upsert(
					$userId,
					$carryoverYear,
					$carryoverVal,
					$hoursMode ? $carryoverVal : null,
					!$hoursMode
				);
			};
			if ($this->vacationUnitMigrationService !== null) {
				try {
					$this->vacationUnitMigrationService->withIdleShared($writeCarryover);
				} catch (\RuntimeException $e) {
					if ($e->getMessage() === Constants::VAC_UNIT_MIGRATE_IN_PROGRESS) {
						throw new AdminUserProfileUpdateException(
							$this->l10n->t('Vacation unit migration is in progress. Please try again in a moment.')
						);
					}
					throw $e;
				}
			} else {
				$writeCarryover();
			}
			return [
				'userWorkingTimeModel' => $updated !== null ? $this->presentUserModelSummary($updated->getSummary()) : null,
				'vacationCarryoverDays' => $this->storedAmountToAdminDays(
					(float)$this->vacationYearBalanceMapper->getCarryoverDays($userId, $carryoverYear)
				),
				'vacationCarryoverYear' => $carryoverYear,
			];
		}

		return [
			'userWorkingTimeModel' => $updated !== null ? $this->presentUserModelSummary($updated->getSummary()) : null,
		];
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function applyVacationPolicy(string $userId, array $params, string $performedBy): array
	{
		$this->preflightVacationPolicy($userId, $params);

		$normalized = $this->normalizeVacationPolicyParams($params);
		$target = $this->resolveVacationPolicyRow($userId, $params, $normalized);

		if ($target !== null && $this->vacationPolicyMatches($target, $normalized)) {
			return ['policyId' => $target->getId(), 'unchanged' => true];
		}

		if ($target !== null && $target->getEffectiveFrom()?->format('Y-m-d') === $normalized['effectiveFrom']->format('Y-m-d')) {
			$target->setVacationMode($normalized['vacationMode']);
			$target->setManualDays($normalized['manualDays']);
			$target->setTariffRuleSetId($normalized['tariffRuleSetId']);
			$target->setOverrideReason($normalized['overrideReason']);
			$target->setEffectiveTo($normalized['effectiveTo']);
			$target->setInheritLowerLayers($normalized['inheritLowerLayers']);
			$target->setUpdatedAt(new \DateTime());
			$this->assertEntityValid($target->validate());
			$updated = $this->userVacationPolicyAssignmentMapper->update($target);

			return ['policyId' => $updated->getId(), 'updated' => true];
		}

		$this->closeOpenPoliciesBefore($userId, $normalized['effectiveFrom']);

		$assignment = new UserVacationPolicyAssignment();
		$assignment->setUserId($userId);
		$assignment->setVacationMode($normalized['vacationMode']);
		$assignment->setManualDays($normalized['manualDays']);
		$assignment->setTariffRuleSetId($normalized['tariffRuleSetId']);
		$assignment->setOverrideReason($normalized['overrideReason']);
		$assignment->setEffectiveFrom($normalized['effectiveFrom']);
		$assignment->setEffectiveTo($normalized['effectiveTo']);
		$assignment->setInheritLowerLayers($normalized['inheritLowerLayers']);
		$assignment->setCreatedBy($performedBy);
		$assignment->setCreatedAt(new \DateTime());
		$assignment->setUpdatedAt(new \DateTime());
		$this->assertEntityValid($assignment->validate());
		$saved = $this->userVacationPolicyAssignmentMapper->insert($assignment);

		return ['policyId' => $saved->getId(), 'created' => true];
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function applyOvertimeSettings(string $userId, array $params, string $performedBy): array
	{
		$this->preflightOvertime($params);

		if (array_key_exists('trackingFrom', $params)) {
			$raw = $params['trackingFrom'];
			if ($raw === null || $raw === '') {
				$this->userOvertimeSettingsService->setTrackingFrom($userId, null, $performedBy);
			} else {
				$date = $this->parseStrictYmd((string)$raw);
				$this->userOvertimeSettingsService->setTrackingFrom(
					$userId,
					\DateTimeImmutable::createFromMutable($date),
					$performedBy
				);
			}
		}

		$openingBalance = $params['openingBalance'] ?? null;
		$balanceYear = (int)date('Y');
		if (is_array($openingBalance) && isset($openingBalance['year'], $openingBalance['hours'])) {
			[$year, $yearErr] = OpeningBalanceYearValidator::parse($openingBalance['year']);
			if ($yearErr !== null) {
				throw new AdminUserProfileUpdateException(
					$yearErr === 'range'
						? $this->l10n->t('Opening balance year must be between 2000 and 2100')
						: $this->l10n->t('Opening balance year must be a four-digit year (e.g. 2026).')
				);
			}
			$hours = $this->parseDecimalInput($openingBalance['hours'], 0.0);
			if ($hours < -9999 || $hours > 9999) {
				throw new AdminUserProfileUpdateException($this->l10n->t('Opening balance hours must be between -9999 and 9999'));
			}
			$this->userOvertimeSettingsService->setOpeningBalance($userId, $year, $hours, $performedBy);
			$balanceYear = $year;
		}

		return [
			'overtimeTrackingFrom' => $this->userOvertimeSettingsService->getTrackingFrom($userId)?->format('Y-m-d'),
			'overtimeOpeningBalanceHours' => $this->userOvertimeSettingsService->getOpeningBalanceHours($userId, $balanceYear),
			'overtimeOpeningBalanceYear' => $balanceYear,
		];
	}

	/**
	 * Persist the employment period (Eintritts-/Austrittsdatum) that drives
	 * pro-rata vacation entitlement in partial years. Empty strings clear the
	 * respective date; absent keys leave the stored value untouched.
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function applyEmploymentSettings(string $userId, array $params, string $performedBy): array
	{
		if ($params === []) {
			return [];
		}
		$this->preflightEmployment($userId, $params);

		[$start, $end] = $this->resolveEmploymentPeriod($userId, $params);

		try {
			$this->userEmploymentSettingsService->setEmploymentPeriod($userId, $start, $end, $performedBy);
		} catch (\InvalidArgumentException $e) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Employment start date must be on or before the employment end date.')
			);
		}

		return [
			'employmentStart' => $this->userEmploymentSettingsService->getEmploymentStart($userId)?->format('Y-m-d'),
			'employmentEnd' => $this->userEmploymentSettingsService->getEmploymentEnd($userId)?->format('Y-m-d'),
		];
	}

	/**
	 * Persist DATEV Personalnummer for payroll export (IConfig user value).
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function applyDatevSettings(string $userId, array $params, string $performedBy): array
	{
		if ($params === [] || !array_key_exists('personalnummer', $params)) {
			return [];
		}
		$this->assertUserExists($userId);
		$this->preflightDatev($params);
		if ($this->config === null) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('DATEV Personalnummer cannot be saved right now. Try again or contact support.')
			);
		}

		$normalized = DatevOrgCredentials::normalizeDigits($params['personalnummer']);
		$previous = (string)$this->config->getUserValue($userId, 'arbeitszeitcheck', Constants::USER_DATEV_PERSONALNUMMER, '');
		if ($previous === $normalized) {
			return ['datevPersonalnummer' => $normalized];
		}
		$this->config->setUserValue($userId, 'arbeitszeitcheck', Constants::USER_DATEV_PERSONALNUMMER, $normalized);
		$this->auditLogMapper->logAction(
			$userId,
			'datev_personalnummer_changed',
			'user_config',
			0,
			['datev_personalnummer_set' => $previous !== ''],
			['datev_personalnummer_set' => $normalized !== ''],
			$performedBy
		);

		return ['datevPersonalnummer' => $normalized];
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function preflightDatev(array $params): void
	{
		if ($params === [] || !array_key_exists('personalnummer', $params)) {
			return;
		}
		$errors = DatevOrgCredentials::validatePersonalnummer($params['personalnummer'], true);
		if ($errors !== []) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('DATEV Personalnummer must be empty or 1–8 digits.'),
				400,
				['datevPersonalnummer' => $this->l10n->t('DATEV Personalnummer must be empty or 1–8 digits.')]
			);
		}
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function preflightEmployment(string $userId, array $params): void
	{
		if ($params === []) {
			return;
		}
		$this->assertUserExists($userId);
		[$start, $end] = $this->resolveEmploymentPeriod($userId, $params);
		if ($start !== null && $end !== null && $start > $end) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Employment start date must be on or before the employment end date.')
			);
		}
	}

	/**
	 * Resolve the target (start, end) pair for the employment period from the
	 * request, falling back to the currently stored value for any endpoint the
	 * caller did not supply.
	 *
	 * @param array<string, mixed> $params
	 * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
	 */
	private function resolveEmploymentPeriod(string $userId, array $params): array
	{
		$start = array_key_exists('start', $params)
			? $this->parseOptionalImmutableYmd($params['start'])
			: $this->userEmploymentSettingsService->getEmploymentStart($userId);
		$end = array_key_exists('end', $params)
			? $this->parseOptionalImmutableYmd($params['end'])
			: $this->userEmploymentSettingsService->getEmploymentEnd($userId);

		return [$start, $end];
	}

	private function parseOptionalImmutableYmd(mixed $raw): ?\DateTimeImmutable
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		if (!is_scalar($raw)) {
			throw new AdminUserProfileUpdateException($this->l10n->t('Invalid date; use YYYY-MM-DD.'));
		}
		$trimmed = trim((string)$raw);
		if ($trimmed === '') {
			return null;
		}

		return \DateTimeImmutable::createFromMutable($this->parseStrictYmd($trimmed))->setTime(0, 0, 0);
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function applyTimeCaptureSettings(string $userId, array $params, string $performedBy): array
	{
		$this->assertUserExists($userId);

		$settings = [];
		if (array_key_exists('clockStampingEnabled', $params)) {
			$settings['clockStampingEnabled'] = filter_var($params['clockStampingEnabled'], FILTER_VALIDATE_BOOLEAN);
		}
		if (array_key_exists('manualTimeEntryEnabled', $params)) {
			$settings['manualTimeEntryEnabled'] = filter_var($params['manualTimeEntryEnabled'], FILTER_VALIDATE_BOOLEAN);
		}

		if ($settings === []) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('No time recording settings were provided.')
			);
		}

		$this->preflightTimeCapture($userId, $params);

		try {
			$updated = $this->timeCaptureMethodService->setSettings($userId, $settings, $performedBy);
		} catch (BusinessRuleException $e) {
			throw new AdminUserProfileUpdateException($e->getMessage());
		}

		return ['timeCapture' => $updated];
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function preflightWorkingTimeModel(string $userId, array $params): void
	{
		$this->assertUserExists($userId);

		if (isset($params['vacationDaysPerYear'])) {
			$days = (float)str_replace(',', '.', (string)$params['vacationDaysPerYear']);
			if ($days < 0 || $days > 366.0) {
				throw new AdminUserProfileUpdateException(
					$this->l10n->t('Vacation days per year must be between 0 and 366')
				);
			}
		}

		$workingTimeModelIdRaw = $params['workingTimeModelId'] ?? null;
		$workingTimeModelId = ($workingTimeModelIdRaw !== null && $workingTimeModelIdRaw !== '')
			? (int)$workingTimeModelIdRaw
			: null;
		if ($workingTimeModelId !== null) {
			try {
				$this->workingTimeModelMapper->find($workingTimeModelId);
			} catch (DoesNotExistException) {
				throw new AdminUserProfileUpdateException($this->l10n->t('Working time model not found'), 404);
			}
		}

		$germanState = isset($params['germanState']) ? (string)$params['germanState'] : null;
		if ($germanState !== null && $germanState !== '') {
			// Per-user regions may belong to any supported country (cross-border
			// commuters) — only the region code itself must be valid.
			if (!RegionRegistry::isValidRegion($germanState)) {
				throw new AdminUserProfileUpdateException($this->l10n->t('Invalid region code'));
			}
		}

		if (array_key_exists('laborLawCountry', $params)) {
			$laborLawCountry = strtoupper(trim((string)$params['laborLawCountry']));
			if ($laborLawCountry !== '' && !RegionRegistry::isSupportedCountry($laborLawCountry)) {
				throw new AdminUserProfileUpdateException($this->l10n->t('Invalid labour-law country'));
			}
		}

		if (isset($params['startDate']) && $params['startDate'] !== null && $params['startDate'] !== '') {
			$this->parseStrictYmd((string)$params['startDate']);
		}
		if (isset($params['endDate']) && $params['endDate'] !== null && $params['endDate'] !== '') {
			$this->parseStrictYmd((string)$params['endDate']);
		}
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function preflightVacationPolicy(string $userId, array $params): void
	{
		$this->assertUserExists($userId);

		$vacationMode = (string)($params['vacationMode'] ?? Constants::VACATION_MODE_MANUAL_FIXED);
		$inheritLowerLayers = !empty($params['inheritLowerLayers'])
			|| $vacationMode === Constants::VACATION_MODE_INHERIT;
		if ($inheritLowerLayers) {
			$vacationMode = Constants::VACATION_MODE_INHERIT;
		}
		$tariffRuleSetId = isset($params['tariffRuleSetId']) && $params['tariffRuleSetId'] !== null && $params['tariffRuleSetId'] !== ''
			? (int)$params['tariffRuleSetId']
			: null;
		$effectiveFrom = $this->parseStrictYmd((string)($params['effectiveFrom'] ?? date('Y-m-d')));
		$this->parseOptionalEffectiveTo($params['effectiveTo'] ?? null);

		if ($vacationMode === Constants::VACATION_MODE_TARIFF_RULE_BASED && $tariffRuleSetId !== null) {
			try {
				$ruleSet = $this->tariffRuleSetMapper->find($tariffRuleSetId);
				if ($ruleSet->getStatus() !== Constants::TARIFF_RULE_SET_STATUS_ACTIVE) {
					throw new AdminUserProfileUpdateException($this->l10n->t('Only active tariff rule sets can be assigned'));
				}
				if ($ruleSet->getValidFrom() > $effectiveFrom) {
					throw new AdminUserProfileUpdateException($this->l10n->t('Tariff rule set starts after policy effective date'));
				}
				$ruleValidTo = $ruleSet->getValidTo();
				if ($ruleValidTo !== null && $ruleValidTo < $effectiveFrom) {
					throw new AdminUserProfileUpdateException($this->l10n->t('Tariff rule set is no longer valid for the selected policy date'));
				}
			} catch (DoesNotExistException) {
				throw new AdminUserProfileUpdateException($this->l10n->t('Tariff rule set not found'), 404);
			}
		}

		$assignment = new UserVacationPolicyAssignment();
		$assignment->setUserId($userId);
		$assignment->setVacationMode($vacationMode);
		$assignment->setManualDays(isset($params['manualDays']) ? $this->parseDecimalInput($params['manualDays'], 0.0) : null);
		$assignment->setTariffRuleSetId($tariffRuleSetId);
		$assignment->setOverrideReason(isset($params['overrideReason']) ? trim((string)$params['overrideReason']) : null);
		$assignment->setEffectiveFrom($effectiveFrom);
		$assignment->setEffectiveTo($this->parseOptionalEffectiveTo($params['effectiveTo'] ?? null));
		$assignment->setInheritLowerLayers($inheritLowerLayers);
		if ($inheritLowerLayers) {
			$assignment->setManualDays(null);
			$assignment->setTariffRuleSetId(null);
		}
		$this->assertEntityValid($assignment->validate());
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function preflightTimeCapture(string $userId, array $params): void
	{
		if ($params === []) {
			return;
		}
		$clock = array_key_exists('clockStampingEnabled', $params)
			? filter_var($params['clockStampingEnabled'], FILTER_VALIDATE_BOOLEAN)
			: null;
		$manual = array_key_exists('manualTimeEntryEnabled', $params)
			? filter_var($params['manualTimeEntryEnabled'], FILTER_VALIDATE_BOOLEAN)
			: null;
		// Universal guard: disabling both methods is invalid regardless of the
		// organisation ceiling. Enforced here so the request is rejected before
		// any persistence is attempted, independent of the org-aware check below.
		if ($clock === false && $manual === false) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Enable clock in/out or manual time entries — at least one method is required.')
			);
		}
		// Organisation-aware guard: e.g. enabling only a method the organisation
		// has disabled would leave the employee with no effective method.
		try {
			$this->timeCaptureMethodService->validateUserPreferences($userId, $clock, $manual);
		} catch (BusinessRuleException $e) {
			throw new AdminUserProfileUpdateException(
				$e->getMessage() === $this->l10n->t('At least one time recording method must stay enabled for each employee.')
					? $this->l10n->t('Enable clock in/out or manual time entries — at least one method is required.')
					: $e->getMessage()
			);
		}
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function preflightOvertime(array $params): void
	{
		if (array_key_exists('trackingFrom', $params) && $params['trackingFrom'] !== null && $params['trackingFrom'] !== '') {
			$this->parseStrictYmd((string)$params['trackingFrom']);
		}
		$openingBalance = $params['openingBalance'] ?? null;
		if (is_array($openingBalance) && isset($openingBalance['year'], $openingBalance['hours'])) {
			[$year, $yearErr] = OpeningBalanceYearValidator::parse($openingBalance['year']);
			if ($yearErr !== null) {
				throw new AdminUserProfileUpdateException(
					$yearErr === 'range'
						? $this->l10n->t('Opening balance year must be between 2000 and 2100')
						: $this->l10n->t('Opening balance year must be a four-digit year (e.g. 2026).')
				);
			}
			$hours = $this->parseDecimalInput($openingBalance['hours'], 0.0);
			if ($hours < -9999 || $hours > 9999) {
				throw new AdminUserProfileUpdateException($this->l10n->t('Opening balance hours must be between -9999 and 9999'));
			}
		}
	}

	private function assertUserExists(string $userId): void
	{
		if ($this->userManager->get($userId) === null) {
			throw new AdminUserProfileUpdateException($this->l10n->t('User not found'), 404);
		}
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array{
	 *   vacationMode: string,
	 *   inheritLowerLayers: bool,
	 *   manualDays: ?float,
	 *   tariffRuleSetId: ?int,
	 *   overrideReason: ?string,
	 *   effectiveFrom: \DateTime,
	 *   effectiveTo: ?\DateTime
	 * }
	 */
	private function normalizeVacationPolicyParams(array $params): array
	{
		$vacationMode = (string)($params['vacationMode'] ?? Constants::VACATION_MODE_MANUAL_FIXED);
		$inheritLowerLayers = !empty($params['inheritLowerLayers'])
			|| $vacationMode === Constants::VACATION_MODE_INHERIT;
		if ($inheritLowerLayers) {
			$vacationMode = Constants::VACATION_MODE_INHERIT;
		}
		$manualDays = isset($params['manualDays']) ? $this->parseDecimalInput($params['manualDays'], 0.0) : null;
		if ($manualDays !== null) {
			if ($manualDays < 0.0 || $manualDays > 366.0) {
				throw new AdminUserProfileUpdateException(
					$this->l10n->t('Vacation days per year must be between 0 and 366')
				);
			}
			$manualDays = $this->adminDaysToStoredAmount($manualDays);
		}
		$tariffRuleSetId = isset($params['tariffRuleSetId']) && $params['tariffRuleSetId'] !== null && $params['tariffRuleSetId'] !== ''
			? (int)$params['tariffRuleSetId']
			: null;
		$overrideReason = isset($params['overrideReason']) ? trim((string)$params['overrideReason']) : null;
		$effectiveFrom = $this->parseStrictYmd((string)($params['effectiveFrom'] ?? date('Y-m-d')));
		$effectiveTo = $this->parseOptionalEffectiveTo($params['effectiveTo'] ?? null);

		if ($inheritLowerLayers) {
			$manualDays = null;
			$tariffRuleSetId = null;
			$overrideReason = $overrideReason !== null && $overrideReason !== '' ? $overrideReason : null;
		}

		return [
			'vacationMode' => $vacationMode,
			'inheritLowerLayers' => $inheritLowerLayers,
			'manualDays' => $manualDays,
			'tariffRuleSetId' => $tariffRuleSetId,
			'overrideReason' => $overrideReason,
			'effectiveFrom' => $effectiveFrom,
			'effectiveTo' => $effectiveTo,
		];
	}

	/**
	 * @param array<string, mixed> $params
	 * @param array<string, mixed> $normalized
	 */
	private function resolveVacationPolicyRow(string $userId, array $params, array $normalized): ?UserVacationPolicyAssignment
	{
		$policyId = isset($params['policyId']) && $params['policyId'] !== null && $params['policyId'] !== ''
			? (int)$params['policyId']
			: null;
		if ($policyId !== null && $policyId > 0) {
			try {
				$row = $this->userVacationPolicyAssignmentMapper->find($policyId);
				if ($row->getUserId() !== $userId) {
					throw new AdminUserProfileUpdateException($this->l10n->t('User not found'), 404);
				}

				return $row;
			} catch (DoesNotExistException) {
				// Stale UI row — fall through to lookup by date.
			}
		}

		return $this->userVacationPolicyAssignmentMapper->findCurrentByUser(
			$userId,
			$normalized['effectiveFrom']
		);
	}

	/**
	 * @param array{
	 *   vacationMode: string,
	 *   inheritLowerLayers: bool,
	 *   manualDays: ?float,
	 *   tariffRuleSetId: ?int,
	 *   overrideReason: ?string,
	 *   effectiveFrom: \DateTime,
	 *   effectiveTo: ?\DateTime
	 * } $normalized
	 */
	private function vacationPolicyMatches(UserVacationPolicyAssignment $row, array $normalized): bool
	{
		$rowFrom = $row->getEffectiveFrom()?->format('Y-m-d');
		$rowTo = $row->getEffectiveTo()?->format('Y-m-d');
		$newTo = $normalized['effectiveTo']?->format('Y-m-d');

		return $row->getVacationMode() === $normalized['vacationMode']
			&& $row->isInherit() === $normalized['inheritLowerLayers']
			&& $this->floatsEqual($row->getManualDays(), $normalized['manualDays'])
			&& $row->getTariffRuleSetId() === $normalized['tariffRuleSetId']
			&& trim((string)($row->getOverrideReason() ?? '')) === trim((string)($normalized['overrideReason'] ?? ''))
			&& $rowFrom === $normalized['effectiveFrom']->format('Y-m-d')
			&& $rowTo === $newTo;
	}

	private function closeOpenPoliciesBefore(string $userId, \DateTime $newEffectiveFrom): void
	{
		$newStart = new \DateTimeImmutable($newEffectiveFrom->format('Y-m-d'));
		$endPrevious = $newStart->modify('-1 day');
		foreach ($this->userVacationPolicyAssignmentMapper->findByUser($userId) as $policy) {
			if ($policy->getEffectiveTo() !== null) {
				continue;
			}
			$from = $policy->getEffectiveFrom();
			if ($from === null || $from->format('Y-m-d') >= $newEffectiveFrom->format('Y-m-d')) {
				continue;
			}
			$policy->setEffectiveTo(new \DateTime($endPrevious->format('Y-m-d')));
			$policy->setUpdatedAt(new \DateTime());
			$this->userVacationPolicyAssignmentMapper->update($policy);
		}
	}

	private function workingTimeModelAssignmentMatches(
		UserWorkingTimeModel $current,
		int $workingTimeModelId,
		?int $vacationDaysPerYear,
		mixed $startDate,
		mixed $endDate,
	): bool {
		$currentStart = $current->getStartDate()?->format('Y-m-d');
		$currentEnd = $current->getEndDate()?->format('Y-m-d');
		$newStart = $startDate ? (string)$startDate : $currentStart;
		$newEnd = $endDate !== null ? ($endDate ? (string)$endDate : null) : $currentEnd;

		return $current->getWorkingTimeModelId() === $workingTimeModelId
			&& ($vacationDaysPerYear === null || $current->getVacationDaysPerYear() === $vacationDaysPerYear)
			&& $currentStart === $newStart
			&& $currentEnd === $newEnd;
	}

	private function floatsEqual(?float $a, ?float $b): bool
	{
		if ($a === null && $b === null) {
			return true;
		}
		if ($a === null || $b === null) {
			return false;
		}

		return abs($a - $b) < 0.0001;
	}

	private function parseStrictYmd(string $raw): \DateTime
	{
		$parsed = StrictYmdDates::parseRequired($raw);
		if ($parsed === null) {
			throw new AdminUserProfileUpdateException($this->l10n->t('Invalid date; use YYYY-MM-DD.'));
		}

		return $parsed;
	}

	private function parseOptionalEffectiveTo(mixed $raw): ?\DateTime
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		if (!is_scalar($raw)) {
			throw new AdminUserProfileUpdateException($this->l10n->t('Invalid date; use YYYY-MM-DD.'));
		}
		$trimmed = trim((string)$raw);
		if ($trimmed === '') {
			return null;
		}

		return $this->parseStrictYmd($trimmed);
	}

	private function parseDecimalInput(mixed $value, float $default): float
	{
		if ($value === null || $value === '') {
			return $default;
		}
		if (is_int($value) || is_float($value)) {
			return (float)$value;
		}
		$normalized = str_replace(',', '.', trim((string)$value));
		$normalized = preg_replace('/\s+/', '', $normalized ?? '');
		if ($normalized === null || $normalized === '' || !is_numeric($normalized)) {
			return $default;
		}

		return (float)$normalized;
	}

	/**
	 * @param array<string, string> $errors
	 */
	private function assertEntityValid(array $errors): void
	{
		if ($errors === []) {
			return;
		}
		$translated = [];
		foreach ($errors as $field => $message) {
			$translated[$field] = $this->l10n->t($message);
		}
		throw new AdminUserProfileUpdateException($this->l10n->t('Validation failed'), 400, $translated);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function userWorkingTimeModelToAuditValues(UserWorkingTimeModel $model): array
	{
		$start = $model->getStartDate();
		$end = $model->getEndDate();

		return [
			'id' => $model->getId(),
			'userId' => $model->getUserId(),
			'workingTimeModelId' => $model->getWorkingTimeModelId(),
			'vacationDaysPerYear' => $model->getVacationDaysPerYear(),
			'startDate' => $start ? $start->format('Y-m-d') : null,
			'endDate' => $end ? $end->format('Y-m-d') : null,
		];
	}

	/**
	 * Country-profile vacation suggestion for new assignments (CH → 20, DE/AT → 25).
	 * Falls back to {@see Constants::DEFAULT_VACATION_DAYS_PER_YEAR} when no factory is wired.
	 */
	private function defaultVacationDaysSuggestion(): int
	{
		if ($this->laborLawProfileFactory !== null) {
			return $this->laborLawProfileFactory->getProfile()->vacationDaysSuggestion;
		}

		return Constants::DEFAULT_VACATION_DAYS_PER_YEAR;
	}
}
