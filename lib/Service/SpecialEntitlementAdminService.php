<?php

declare(strict_types=1);

/**
 * App-admin management for special entitlements.
 *
 * Definitions and policies are write-serialized, existence-checked and audited.
 * Confidential fields never leave this service on employee/manager serializers;
 * only this admin service may expose them.
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\CustomAbsenceType;
use OCA\ArbeitszeitCheck\Db\CustomAbsenceTypeMapper;
use OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshotMapper;
use OCA\ArbeitszeitCheck\Db\PolicyCohortMapper;
use OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntry;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriod;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriodMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicy;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicyMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementType;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\SpecialEntitlementException;
use OCA\ArbeitszeitCheck\Policy\IdempotencyKey;
use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;

final class SpecialEntitlementAdminService
{
	private const LABEL_PATTERN_MAX = 80;
	private const LOCALE_PATTERN = '/^[a-z]{2,3}([_-][a-zA-Z0-9]{2,8})*$/';
	private const MAX_I18N = 12;
	private const MAX_AMOUNT = 4000.0;

	public function __construct(
		private readonly WorkforceSuiteConfig $suiteConfig,
		private readonly SpecialEntitlementTypeMapper $types,
		private readonly SpecialEntitlementPolicyMapper $policies,
		private readonly SpecialEntitlementPeriodMapper $periods,
		private readonly SpecialEntitlementReservationMapper $reservations,
		private readonly SpecialEntitlementLedgerEntryMapper $ledger,
		private readonly SpecialEntitlementResolver $resolver,
		private readonly SpecialEntitlementService $entitlements,
		private readonly EntitlementComputationSnapshotMapper $snapshots,
		private readonly CustomAbsenceTypeMapper $customAbsenceTypes,
		private readonly WorkingTimeModelMapper $workingTimeModels,
		private readonly TeamMapper $teams,
		private readonly PolicyCohortMapper $cohorts,
		private readonly TariffRuleSetMapper $tariffRuleSets,
		private readonly IUserManager $users,
		private readonly AuditLogMapper $audit,
		private readonly ILockingProvider $lockingProvider,
		private readonly IDBConnection $db,
		private readonly ?TeamMemberMapper $teamMembers = null,
		private readonly ?UserWorkingTimeModelMapper $userModels = null,
		private readonly ?PolicyCohortMembershipMapper $cohortMemberships = null,
	) {
	}

	public function assertEnabled(): void
	{
		if (!$this->suiteConfig->isEnabled('special_entitlements_enabled') || !$this->suiteConfig->isEnabled('policy_cohorts_enabled')) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
	}

	/** @return list<array<string,mixed>> */
	public function listTypes(): array
	{
		$this->assertEnabled();
		return array_map([$this, 'adminTypeSummary'], $this->types->findAll(false));
	}

	/** @return array<string,mixed> */
	public function createType(array $params, string $actorUid): array
	{
		$this->assertEnabled();
		$publicLabel = $this->requiredLabel($params['publicLabel'] ?? $params['public_label'] ?? null, 'publicLabel');
		$code = $this->typeCode($params['code'] ?? null, $publicLabel);
		$absenceTypeCode = $this->nullableString($params['absenceTypeCode'] ?? $params['absence_type_code'] ?? null, 'absenceTypeCode', 64);
		$createLinkedType = $absenceTypeCode === null;
		if ($createLinkedType) {
			$absenceTypeCode = $code;
		}
		$unit = $this->enumString($params['unit'] ?? null, SpecialEntitlementType::UNITS, 'unit');
		$periodType = $this->enumString($params['periodType'] ?? $params['period_type'] ?? null, SpecialEntitlementType::PERIODS, 'periodType');
		$labelI18n = $this->labelMap($params['labelI18n'] ?? $params['label_i18n'] ?? null);
		$confidentialLabel = $this->optionalLabel($params['confidentialLabel'] ?? $params['confidential_label'] ?? null, 'confidentialLabel');

		$this->lockingProvider->acquireLock(DbLockKeys::absenceTypeCatalog(), ILockingProvider::LOCK_EXCLUSIVE, 'Absence type catalog');
		$this->lockingProvider->acquireLock(DbLockKeys::policyCatalog('special_entitlement'), ILockingProvider::LOCK_EXCLUSIVE, 'Special entitlement catalog');
		try {
			$this->db->beginTransaction();
			try {
				if ($this->types->findByCode($code) !== null) {
					throw new SpecialEntitlementException('VALIDATION_ERROR', 'This identifier is already in use.', 400, ['code' => 'exists']);
				}
				if ($this->types->findByAbsenceTypeCode($absenceTypeCode) !== null) {
					throw new SpecialEntitlementException('VALIDATION_ERROR', 'This absence type is already linked to an entitlement.', 400, ['absenceTypeCode' => 'linked']);
				}
				if ($createLinkedType) {
					if ($this->customAbsenceTypes->codeExists($absenceTypeCode) || AbsenceTypeCatalog::isValid($absenceTypeCode)) {
						throw new SpecialEntitlementException('VALIDATION_ERROR', 'This identifier is already in use.', 400, ['code' => 'exists']);
					}
					$custom = new CustomAbsenceType();
					$custom->setCode($absenceTypeCode);
					$custom->setLabel($publicLabel);
					$custom->setLabelI18n($labelI18n !== [] ? json_encode($labelI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);
					$custom->setSollCredit(false);
					$custom->setMaxDays(365);
					$custom->setColor('info');
					$custom->setIcon('calendar-heart');
					$custom->setActive(true);
					$custom->setCreatedAt(new \DateTime());
					$custom->setCreatedBy($actorUid);
					$custom->setUpdatedAt(new \DateTime());
					$custom->setUpdatedBy($actorUid);
					$this->customAbsenceTypes->insert($custom);
				} else {
					$this->assertCreatableAbsenceType($absenceTypeCode);
				}

				$type = new SpecialEntitlementType();
				$type->setCode($code);
				$type->setPublicLabel($publicLabel);
				$type->setConfidentialLabel($confidentialLabel);
				$type->setLabelI18n($labelI18n !== [] ? json_encode($labelI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);
				$type->setAbsenceTypeCode($absenceTypeCode);
				$type->setUnit($unit);
				$type->setPeriodType($periodType);
				$type->setAllowNegative($this->parseBool($params['allowNegative'] ?? $params['allow_negative'] ?? false));
				$type->setEmployeeMaySeeConfidentialLabel($this->parseBool($params['employeeMaySeeConfidentialLabel'] ?? $params['employee_may_see_confidential_label'] ?? false));
				$type->setActive(false);
				$type->setVersion(1);
				$type->setCreatedBy($actorUid);
				$type->setUpdatedBy($actorUid);
				$now = new \DateTime();
				$type->setCreatedAt($now);
				$type->setUpdatedAt($now);
				$type = $this->types->insert($type);
				$this->audit->logAction($actorUid, 'special_entitlement_type_created', 'special_entitlement_type', (int)$type->getId(), null, $this->auditTypeSummary($type), $actorUid);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} finally {
			$this->lockingProvider->releaseLock(DbLockKeys::policyCatalog('special_entitlement'), ILockingProvider::LOCK_EXCLUSIVE);
			$this->lockingProvider->releaseLock(DbLockKeys::absenceTypeCatalog(), ILockingProvider::LOCK_EXCLUSIVE);
		}
		AbsenceTypeCatalog::resetCustomTypeCache();
		return $this->adminTypeSummary($type);
	}

	/** @return array<string,mixed> */
	public function updateType(int $typeId, array $params, string $actorUid): array
	{
		$this->assertEnabled();
		$type = $this->requireType($typeId);
		$this->assertRevision($params, (int)$type->getVersion());
		if (array_key_exists('code', $params) && $params['code'] !== $type->getCode()) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The entitlement code is immutable.', 400, ['code' => 'immutable']);
		}
		if ($type->getActive()) {
			foreach (['unit' => $type->getUnit(), 'periodType' => $type->getPeriodType(), 'period_type' => $type->getPeriodType(), 'absenceTypeCode' => $type->getAbsenceTypeCode(), 'absence_type_code' => $type->getAbsenceTypeCode()] as $field => $current) {
				if (array_key_exists($field, $params) && $params[$field] !== $current) {
					throw new SpecialEntitlementException('VALIDATION_ERROR', 'Activated entitlement semantics are immutable.', 400, [$field => 'immutable']);
				}
			}
		}
		$locks = [DbLockKeys::specialEntitlementType($typeId)];
		$relinking = !$type->getActive() && (
			array_key_exists('absenceTypeCode', $params) || array_key_exists('absence_type_code', $params)
		);
		if ($relinking) {
			// Same order as setTypeActive / createAbsence: type before catalog.
			$locks[] = DbLockKeys::absenceTypeCatalog();
		}
		foreach ($locks as $lock) {
			$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Special entitlement type');
		}
		try {
			$type = $this->requireType($typeId);
			$this->assertRevision($params, (int)$type->getVersion());
			$old = $this->auditTypeSummary($type);
			if (array_key_exists('publicLabel', $params) || array_key_exists('public_label', $params)) {
				$type->setPublicLabel($this->requiredLabel($params['publicLabel'] ?? $params['public_label'], 'publicLabel'));
			}
			if (array_key_exists('confidentialLabel', $params) || array_key_exists('confidential_label', $params)) {
				$type->setConfidentialLabel($this->optionalLabel($params['confidentialLabel'] ?? $params['confidential_label'], 'confidentialLabel'));
			}
			if (array_key_exists('labelI18n', $params) || array_key_exists('label_i18n', $params)) {
				$map = $this->labelMap($params['labelI18n'] ?? $params['label_i18n']);
				$type->setLabelI18n($map !== [] ? json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);
			}
			if (!$type->getActive()) {
				if (array_key_exists('unit', $params)) {
					$type->setUnit($this->enumString($params['unit'], SpecialEntitlementType::UNITS, 'unit'));
				}
				if (array_key_exists('periodType', $params) || array_key_exists('period_type', $params)) {
					$type->setPeriodType($this->enumString($params['periodType'] ?? $params['period_type'], SpecialEntitlementType::PERIODS, 'periodType'));
				}
				if (array_key_exists('absenceTypeCode', $params) || array_key_exists('absence_type_code', $params)) {
					$code = $this->nullableString($params['absenceTypeCode'] ?? $params['absence_type_code'], 'absenceTypeCode', 64);
					if ($code === null) {
						throw new SpecialEntitlementException('VALIDATION_ERROR', 'An absence type is required.', 400, ['absenceTypeCode' => 'required']);
					}
					$this->assertCreatableAbsenceType($code);
					$linked = $this->types->findByAbsenceTypeCode($code);
					if ($linked !== null && (int)$linked->getId() !== $typeId) {
						throw new SpecialEntitlementException('VALIDATION_ERROR', 'This absence type is already linked to an entitlement.', 400, ['absenceTypeCode' => 'linked']);
					}
					$type->setAbsenceTypeCode($code);
				}
			}
			if (array_key_exists('allowNegative', $params) || array_key_exists('allow_negative', $params)) {
				$type->setAllowNegative($this->parseBool($params['allowNegative'] ?? $params['allow_negative']));
			}
			if (array_key_exists('employeeMaySeeConfidentialLabel', $params) || array_key_exists('employee_may_see_confidential_label', $params)) {
				$type->setEmployeeMaySeeConfidentialLabel($this->parseBool($params['employeeMaySeeConfidentialLabel'] ?? $params['employee_may_see_confidential_label']));
			}
			$type->setVersion((int)$type->getVersion() + 1);
			$type->setUpdatedBy($actorUid);
			$type->setUpdatedAt(new \DateTime());
			$type = $this->types->update($type);
			$this->audit->logAction($actorUid, 'special_entitlement_type_updated', 'special_entitlement_type', $typeId, $old, $this->auditTypeSummary($type), $actorUid);
			return $this->adminTypeSummary($type);
		} finally {
			foreach (array_reverse($locks) as $lock) {
				$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	/** @return array<string,mixed> */
	public function setTypeActive(int $typeId, bool $active, int $version, string $actorUid): array
	{
		$this->assertEnabled();
		// Lock order must match AbsenceService::createAbsence (entitlement type
		// first, catalog last): catalog→type here would ABBA-deadlock against a
		// create that holds the type lock and waits for the shared catalog lock.
		$locks = [
			DbLockKeys::specialEntitlementType($typeId),
			DbLockKeys::absenceTypeCatalog(),
		];
		foreach ($locks as $lock) {
			$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Special entitlement type');
		}
		try {
			$this->db->beginTransaction();
			try {
				$type = $this->requireType($typeId);
				$this->assertRevision(['version' => $version], (int)$type->getVersion());
				$absenceCode = (string)$type->getAbsenceTypeCode();
				$custom = null;
				try {
					$custom = $this->customAbsenceTypes->findByCode($absenceCode);
				} catch (DoesNotExistException) {
					$custom = null;
				}
				if ($active && !$type->getActive() && $custom === null) {
					$this->assertCreatableAbsenceType($absenceCode);
				}
				$old = $this->auditTypeSummary($type);
				$type->setActive($active);
				$type->setVersion((int)$type->getVersion() + 1);
				$type->setUpdatedBy($actorUid);
				$type->setUpdatedAt(new \DateTime());
				$type = $this->types->update($type);
				if ($custom !== null && (bool)$custom->getActive() !== $active) {
					$custom->setActive($active);
					$custom->setUpdatedAt(new \DateTime());
					$custom->setUpdatedBy($actorUid);
					$this->customAbsenceTypes->update($custom);
				}
				$this->audit->logAction($actorUid, $active ? 'special_entitlement_type_activated' : 'special_entitlement_type_deactivated', 'special_entitlement_type', $typeId, $old, $this->auditTypeSummary($type), $actorUid);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			AbsenceTypeCatalog::resetCustomTypeCache();
			return $this->adminTypeSummary($type);
		} finally {
			foreach (array_reverse($locks) as $lock) {
				$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	/** @return array<string,mixed> */
	public function createPolicy(array $params, string $actorUid): array
	{
		$this->assertEnabled();
		$type = $this->requireType($this->strictId($params['entitlementTypeId'] ?? $params['entitlement_type_id'] ?? null, 'entitlementTypeId'));
		if (!$type->getActive()) {
			throw new SpecialEntitlementException('ENTITLEMENT_DISABLED', 'Activate the entitlement definition before assigning policies.', 409, ['entitlementTypeId' => 'inactive']);
		}
		[$scopeType, $scopeId] = $this->scope($params);
		$operation = $this->enumString($params['operation'] ?? null, SpecialEntitlementPolicy::OPERATIONS, 'operation');
		$amount = $this->policyAmount($params['amount'] ?? null, $operation);
		$tariffRuleSetId = $this->optionalId($params['tariffRuleSetId'] ?? $params['tariff_rule_set_id'] ?? null, 'tariffRuleSetId');
		$priority = $this->priority($params['priority'] ?? 0);
		$from = $this->date($params['effectiveFrom'] ?? $params['effective_from'] ?? null, 'effectiveFrom');
		$to = $this->optionalDate($params['effectiveTo'] ?? $params['effective_to'] ?? null, 'effectiveTo');
		if ($to !== null && $to < $from) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The end date must not be before the start date.', 400, ['effectiveTo' => 'after_from']);
		}
		$reason = $this->nullableString($params['reason'] ?? null, 'reason', 500);
		$this->assertScopeExists($scopeType, $scopeId, $type);
		if ($tariffRuleSetId !== null) {
			$this->assertTariffSet($tariffRuleSetId, $from, $to);
		}

		$locks = [DbLockKeys::specialEntitlementType((int)$type->getId()), DbLockKeys::specialEntitlementPolicy((int)$type->getId())];
		foreach ($locks as $lock) {
			$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Special entitlement policy');
		}
		try {
			if ($this->policies->findOverlapping((int)$type->getId(), $scopeType, $scopeId, $priority, $from, $to) !== []) {
				throw new SpecialEntitlementException('ASSIGNMENT_OVERLAP', 'An overlapping rule with the same target and priority already exists.', 409, ['effectiveFrom' => 'overlap']);
			}
			$this->db->beginTransaction();
			try {
				$policy = new SpecialEntitlementPolicy();
				$policy->setEntitlementTypeId((int)$type->getId());
				$policy->setScopeType($scopeType);
				$policy->setScopeId($scopeId);
				$policy->setOperation($operation);
				$policy->setAmount($amount);
				$policy->setTariffRuleSetId($tariffRuleSetId);
				$policy->setPriority($priority);
				$policy->setEffectiveFrom(new \DateTime($from->format('Y-m-d')));
				$policy->setEffectiveTo($to !== null ? new \DateTime($to->format('Y-m-d')) : null);
				$policy->setReason($reason);
				$policy->setVersion(1);
				$policy->setCreatedBy($actorUid);
				$policy->setUpdatedBy($actorUid);
				$now = new \DateTime();
				$policy->setCreatedAt($now);
				$policy->setUpdatedAt($now);
				$policy = $this->policies->insert($policy);
				$this->audit->logAction($actorUid, 'special_entitlement_policy_created', 'special_entitlement_policy', (int)$policy->getId(), null, $this->auditPolicySummary($policy), $actorUid);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return $this->policySummary($policy);
		} finally {
			foreach (array_reverse($locks) as $lock) {
				$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	/** @return list<array<string,mixed>> */
	public function listPolicies(int $typeId): array
	{
		$this->assertEnabled();
		$this->requireType($typeId);
		return array_map([$this, 'policySummary'], $this->policies->findByType($typeId));
	}

	/**
	 * Mutable policy fields: effectiveTo, reason, priority. Scope, operation,
	 * amount and effectiveFrom are immutable — a semantic change is a new
	 * effective-dated policy so historical traces stay explainable.
	 */
	public function updatePolicy(int $policyId, array $params, string $actorUid): array
	{
		$this->assertEnabled();
		try {
			$policy = $this->policies->find($policyId);
		} catch (DoesNotExistException) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
		$type = $this->requireType((int)$policy->getEntitlementTypeId());
		foreach (['scopeType' => $policy->getScopeType(), 'scope_type' => $policy->getScopeType(), 'scopeId' => $policy->getScopeId(), 'scope_id' => $policy->getScopeId(), 'operation' => $policy->getOperation()] as $field => $current) {
			if (array_key_exists($field, $params) && $params[$field] !== $current) {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'This policy field is immutable.', 400, [$field => 'immutable']);
			}
		}
		foreach (['effectiveFrom' => $policy->getEffectiveFrom()?->format('Y-m-d'), 'effective_from' => $policy->getEffectiveFrom()?->format('Y-m-d')] as $field => $current) {
			if (array_key_exists($field, $params) && $params[$field] !== $current) {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'This policy field is immutable.', 400, [$field => 'immutable']);
			}
		}
		if (array_key_exists('amount', $params) && $this->decimal($params['amount'], 'amount') !== (float)$policy->getAmount()) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'This policy field is immutable.', 400, ['amount' => 'immutable']);
		}

		$newTo = array_key_exists('effectiveTo', $params) || array_key_exists('effective_to', $params)
			? $this->optionalDate($params['effectiveTo'] ?? $params['effective_to'], 'effectiveTo')
			: ($policy->getEffectiveTo() !== null ? \DateTimeImmutable::createFromMutable($policy->getEffectiveTo()) : null);
		$from = \DateTimeImmutable::createFromMutable($policy->getEffectiveFrom());
		if ($newTo !== null && $newTo < $from) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The end date must not be before the start date.', 400, ['effectiveTo' => 'after_from']);
		}
		$newPriority = array_key_exists('priority', $params)
			? $this->priority($params['priority'])
			: (int)$policy->getPriority();
		$newReason = array_key_exists('reason', $params)
			? $this->nullableString($params['reason'], 'reason', 500)
			: $policy->getReason();

		$locks = [DbLockKeys::specialEntitlementType((int)$type->getId()), DbLockKeys::specialEntitlementPolicy((int)$type->getId())];
		foreach ($locks as $lock) {
			$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Special entitlement policy');
		}
		try {
			try {
				$policy = $this->policies->find($policyId);
			} catch (DoesNotExistException) {
				throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
			}
			$this->assertRevision($params, (int)$policy->getVersion());
			if ($this->policies->findOverlapping((int)$type->getId(), (string)$policy->getScopeType(), (string)$policy->getScopeId(), $newPriority, $from, $newTo, $policyId) !== []) {
				throw new SpecialEntitlementException('ASSIGNMENT_OVERLAP', 'An overlapping rule with the same target and priority already exists.', 409, ['effectiveTo' => 'overlap']);
			}
			$old = $this->auditPolicySummary($policy);
			$policy->setEffectiveTo($newTo !== null ? new \DateTime($newTo->format('Y-m-d')) : null);
			$policy->setPriority($newPriority);
			$policy->setReason($newReason);
			$policy->setUpdatedBy($actorUid);
			$policy->setUpdatedAt(new \DateTime());
			$this->db->beginTransaction();
			try {
				if (!$this->policies->updateIfVersion($policy)) {
					throw new SpecialEntitlementException('REVISION_CONFLICT', 'The policy changed concurrently.', 409);
				}
				$this->audit->logAction($actorUid, 'special_entitlement_policy_updated', 'special_entitlement_policy', $policyId, $old, $this->auditPolicySummary($policy), $actorUid);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return $this->policySummary($policy);
		} finally {
			foreach (array_reverse($locks) as $lock) {
				$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	/**
	 * Impact preview for a hypothetical policy: recipient count (DB-side
	 * where possible), at most three privacy-safe sample users with
	 * before/after resolution, and warnings. Never persists.
	 *
	 * @return array<string,mixed>
	 */
	public function previewPolicy(array $params): array
	{
		$this->assertEnabled();
		$type = $this->requireType($this->strictId($params['entitlementTypeId'] ?? $params['entitlement_type_id'] ?? null, 'entitlementTypeId'));
		[$scopeType, $scopeId] = $this->scope($params);
		$operation = $this->enumString($params['operation'] ?? null, [SpecialEntitlementPolicy::OP_ADD, SpecialEntitlementPolicy::OP_REPLACE, SpecialEntitlementPolicy::OP_DISABLE], 'operation');
		$amount = $this->policyAmount($params['amount'] ?? null, $operation);
		$from = $this->date($params['effectiveFrom'] ?? $params['effective_from'] ?? null, 'effectiveFrom');
		$to = $this->optionalDate($params['effectiveTo'] ?? $params['effective_to'] ?? null, 'effectiveTo');
		if ($to !== null && $to < $from) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The end date must not be before the start date.', 400, ['effectiveTo' => 'after_from']);
		}
		$this->assertScopeExists($scopeType, $scopeId, $type);

		$candidate = new SpecialEntitlementPolicy();
		$candidate->setId(0);
		$candidate->setEntitlementTypeId((int)$type->getId());
		$candidate->setScopeType($scopeType);
		$candidate->setScopeId($scopeId);
		$candidate->setOperation($operation);
		$candidate->setAmount($amount);
		$candidate->setPriority($this->priority($params['priority'] ?? 0));
		$candidate->setEffectiveFrom(new \DateTime($from->format('Y-m-d')));
		$candidate->setEffectiveTo($to !== null ? new \DateTime($to->format('Y-m-d')) : null);

		$asOf = $from;
		[$count, $truncated, $sampleIds] = $this->previewRecipients($scopeType, $scopeId, $asOf);

		$samples = [];
		foreach (array_slice($sampleIds, 0, 3) as $userId) {
			try {
				$before = $this->resolver->resolve($type, $userId, $asOf);
				$after = $this->resolver->resolve($type, $userId, $asOf, [$candidate]);
				$samples[] = [
					'userId' => $userId,
					'displayName' => $this->users->get($userId)?->getDisplayName() ?? $userId,
					'before' => number_format($before['computedAmount'], 2, '.', ''),
					'after' => number_format($after['computedAmount'], 2, '.', ''),
					'enabledBefore' => $before['enabled'],
					'enabledAfter' => $after['enabled'],
				];
			} catch (SpecialEntitlementException) {
				// e.g. missing employment start for service-year types — skip
				// the sample rather than failing the whole preview.
			}
		}

		$warnings = [];
		if ($count === 0) {
			$warnings[] = 'no_recipients';
		}
		if ($truncated) {
			$warnings[] = 'recipient_sample_truncated';
		}
		if ($operation === SpecialEntitlementPolicy::OP_DISABLE) {
			$warnings[] = 'disables_entitlement';
		}
		if ($operation === SpecialEntitlementPolicy::OP_REPLACE) {
			foreach ($samples as $sample) {
				if ((float)$sample['after'] < (float)$sample['before']) {
					$warnings[] = 'shrinks_existing_entitlement';
					break;
				}
			}
		}
		$amountLabel = number_format((float)$amount, 2, '.', '');

		return [
			'recipientCount' => $count,
			'recipientCountTruncated' => $truncated,
			'samples' => $samples,
			'warnings' => array_values(array_unique($warnings)),
			'summary' => [
				'operation' => $operation,
				'amount' => $amountLabel,
				'unit' => (string)$type->getUnit(),
				'effectiveFrom' => $from->format('Y-m-d'),
				'effectiveTo' => $to?->format('Y-m-d'),
			],
		];
	}

	/**
	 * @return array{0:int,1:bool,2:list<string>} [count, truncated, sampleUserIds]
	 */
	private function previewRecipients(string $scopeType, string $scopeId, \DateTimeImmutable $asOf): array
	{
		switch ($scopeType) {
			case SpecialEntitlementPolicy::SCOPE_USER:
				return [1, false, [$scopeId]];
			case SpecialEntitlementPolicy::SCOPE_TEAM:
				$teamId = (int)$scopeId;
				$count = $this->teamMembers?->countMembers($teamId) ?? 0;
				$sample = $this->teamMembers?->findMemberUserIds($teamId, 3) ?? [];
				return [$count, false, $sample];
			case SpecialEntitlementPolicy::SCOPE_GROUP:
				$cohortId = (int)$scopeId;
				$count = $this->cohortMemberships?->countEffectiveForCohort($cohortId, $asOf->format('Y-m-d')) ?? 0;
				$rows = $this->cohortMemberships?->findEffectiveForCohort($cohortId, $asOf->format('Y-m-d'), 3) ?? [];
				return [$count, false, array_map(static fn ($m): string => (string)$m->getUserId(), $rows)];
			case SpecialEntitlementPolicy::SCOPE_MODEL:
				$modelId = (int)$scopeId;
				$date = $asOf->format('Y-m-d');
				$count = $this->userModels?->countUserIdsEffectiveAt($modelId, $date) ?? 0;
				$sample = $this->userModels?->findUserIdsEffectiveAt($modelId, $date, 3) ?? [];
				return [$count, false, $sample];
			case SpecialEntitlementPolicy::SCOPE_ORG:
			default:
				// DB-side count via the user backends — no object
				// materialization — plus a bounded 3-user sample.
				$count = 0;
				foreach ($this->users->countUsers([]) as $backendCount) {
					$count += (int)$backendCount;
				}
				$sample = array_map(
					static fn (\OCP\IUser $u): string => $u->getUID(),
					array_slice($this->users->search('', 3, 0), 0, 3),
				);
				return [$count, false, $sample];
		}
	}

	/* ---- Protected policy cohorts (Anspruchsgruppen) — spec §7.4 ---- */

	/** @return list<array<string,mixed>> */
	public function listCohorts(): array
	{
		$this->assertEnabled();
		$out = [];
		foreach ($this->cohorts->findAll(true) as $cohort) {
			$out[] = $this->cohortSummary($cohort);
		}
		return $out;
	}

	/** @return array<string,mixed> */
	public function createCohort(array $params, string $actorUid): array
	{
		$this->assertEnabled();
		$label = $this->requiredLabel($params['publicLabel'] ?? $params['public_label'] ?? $params['label'] ?? null, 'label');
		$confidentialLabel = $this->optionalLabel($params['confidentialLabel'] ?? $params['confidential_label'] ?? null, 'confidentialLabel');
		$code = $this->cohortCode($params['code'] ?? null, $label);

		$this->lockingProvider->acquireLock(DbLockKeys::policyCatalog('policy_cohort'), ILockingProvider::LOCK_EXCLUSIVE, 'Policy cohort catalog');
		try {
			foreach ($this->cohorts->findAll(true) as $existing) {
				if (hash_equals((string)$existing->getCode(), $code)) {
					throw new SpecialEntitlementException('VALIDATION_ERROR', 'A protected group with this code already exists.', 400, ['code' => 'taken']);
				}
			}
			$this->db->beginTransaction();
			try {
				$cohort = new \OCA\ArbeitszeitCheck\Db\PolicyCohort();
				$cohort->setCode($code);
				$cohort->setPublicLabel($label);
				$cohort->setConfidentialLabel($confidentialLabel);
				$cohort->setActive(true);
				$cohort->setRevision(1);
				$cohort->setCreatedBy($actorUid);
				$cohort->setUpdatedBy($actorUid);
				$now = new \DateTime();
				$cohort->setCreatedAt($now);
				$cohort->setUpdatedAt($now);
				$cohort = $this->cohorts->insert($cohort);
				$this->audit->logAction($actorUid, 'policy_cohort_created', 'policy_cohort', (int)$cohort->getId(), null, $this->cohortSummary($cohort), $actorUid);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return $this->cohortSummary($cohort);
		} finally {
			$this->lockingProvider->releaseLock(DbLockKeys::policyCatalog('policy_cohort'), ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return array<string,mixed> */
	public function updateCohort(int $cohortId, array $params, string $actorUid): array
	{
		$this->assertEnabled();
		$this->lockingProvider->acquireLock(DbLockKeys::policyCohort($cohortId), ILockingProvider::LOCK_EXCLUSIVE, 'Policy cohort');
		try {
			try {
				$cohort = $this->cohorts->find($cohortId);
			} catch (DoesNotExistException) {
				throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
			}
			$this->assertRevision($params, (int)$cohort->getRevision());
			if (array_key_exists('code', $params) && $params['code'] !== $cohort->getCode()) {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'This field is immutable.', 400, ['code' => 'immutable']);
			}
			$old = $this->cohortSummary($cohort);
			if (array_key_exists('publicLabel', $params) || array_key_exists('label', $params)) {
				$cohort->setPublicLabel($this->requiredLabel($params['publicLabel'] ?? $params['label'], 'label'));
			}
			if (array_key_exists('confidentialLabel', $params) || array_key_exists('confidential_label', $params)) {
				$cohort->setConfidentialLabel($this->optionalLabel($params['confidentialLabel'] ?? $params['confidential_label'], 'confidentialLabel'));
			}
			if (array_key_exists('active', $params)) {
				$cohort->setActive($this->boolParam($params['active']));
			}
			$cohort->setRevision((int)$cohort->getRevision() + 1);
			$cohort->setUpdatedBy($actorUid);
			$cohort->setUpdatedAt(new \DateTime());
			$this->db->beginTransaction();
			try {
				$this->cohorts->update($cohort);
				$this->audit->logAction($actorUid, 'policy_cohort_updated', 'policy_cohort', $cohortId, $old, $this->cohortSummary($cohort), $actorUid);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return $this->cohortSummary($cohort);
		} finally {
			$this->lockingProvider->releaseLock(DbLockKeys::policyCohort($cohortId), ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return list<array<string,mixed>> */
	public function listCohortMembers(int $cohortId): array
	{
		$this->assertEnabled();
		$this->requireCohort($cohortId);
		if ($this->cohortMemberships === null) {
			return [];
		}
		$out = [];
		foreach ($this->cohortMemberships->findForCohort($cohortId) as $m) {
			$out[] = $this->membershipSummary($m);
		}
		return $out;
	}

	/** @return array<string,mixed> */
	public function addCohortMember(int $cohortId, array $params, string $actorUid): array
	{
		$this->assertEnabled();
		$this->requireCohort($cohortId);
		if ($this->cohortMemberships === null) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Membership management is unavailable.', 400);
		}
		$userId = $this->nullableString($params['userId'] ?? $params['user_id'] ?? null, 'userId', 64);
		if ($userId === null || $userId === '') {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'A person is required.', 400, ['userId' => 'required']);
		}
		$user = $this->users->get($userId);
		if ($user === null || !$user->isEnabled()) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
		$from = $this->date($params['effectiveFrom'] ?? $params['effective_from'] ?? null, 'effectiveFrom');
		$to = $this->optionalDate($params['effectiveTo'] ?? $params['effective_to'] ?? null, 'effectiveTo');
		if ($to !== null && $to < $from) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The end date must not be before the start date.', 400, ['effectiveTo' => 'after_from']);
		}

		$this->lockingProvider->acquireLock(DbLockKeys::policyCohort($cohortId), ILockingProvider::LOCK_EXCLUSIVE, 'Policy cohort');
		try {
			if ($this->cohortMemberships->findOverlapping($cohortId, $userId, $from->format('Y-m-d'), $to?->format('Y-m-d')) !== []) {
				throw new SpecialEntitlementException('ASSIGNMENT_OVERLAP', 'This person already has an overlapping membership.', 409, ['effectiveFrom' => 'overlap']);
			}
			$this->db->beginTransaction();
			try {
				$m = new \OCA\ArbeitszeitCheck\Db\PolicyCohortMembership();
				$m->setCohortId($cohortId);
				$m->setUserId($userId);
				$m->setEffectiveFrom($from->format('Y-m-d'));
				$m->setEffectiveTo($to?->format('Y-m-d'));
				$m->setRevision(1);
				$m->setCreatedBy($actorUid);
				$m->setUpdatedBy($actorUid);
				$now = new \DateTime();
				$m->setCreatedAt($now);
				$m->setUpdatedAt($now);
				$m = $this->cohortMemberships->insert($m);
				$this->audit->logAction($actorUid, 'policy_cohort_member_added', 'policy_cohort', $cohortId, null, $this->membershipSummary($m), $actorUid);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return $this->membershipSummary($m);
		} finally {
			$this->lockingProvider->releaseLock(DbLockKeys::policyCohort($cohortId), ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * End a membership (set effectiveTo) — memberships are never hard-deleted
	 * so historical resolution stays explainable.
	 *
	 * @return array<string,mixed>
	 */
	public function endCohortMember(int $membershipId, array $params, string $actorUid): array
	{
		$this->assertEnabled();
		if ($this->cohortMemberships === null) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Membership management is unavailable.', 400);
		}
		try {
			$m = $this->cohortMemberships->find($membershipId);
		} catch (DoesNotExistException) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
		$this->lockingProvider->acquireLock(DbLockKeys::policyCohort((int)$m->getCohortId()), ILockingProvider::LOCK_EXCLUSIVE, 'Policy cohort');
		try {
			$m = $this->cohortMemberships->find($membershipId);
			$this->assertRevision($params, (int)$m->getRevision());
			$to = $this->date($params['effectiveTo'] ?? $params['effective_to'] ?? null, 'effectiveTo');
			$from = \DateTimeImmutable::createFromFormat('Y-m-d', (string)$m->getEffectiveFrom()) ?: new \DateTimeImmutable((string)$m->getEffectiveFrom());
			if ($to < $from) {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'The end date must not be before the start date.', 400, ['effectiveTo' => 'after_from']);
			}
			$old = $this->membershipSummary($m);
			$this->db->beginTransaction();
			try {
				$m->setEffectiveTo($to->format('Y-m-d'));
				$m->setRevision((int)$m->getRevision() + 1);
				$m->setUpdatedBy($actorUid);
				$m->setUpdatedAt(new \DateTime());
				$this->cohortMemberships->update($m);
				$this->audit->logAction($actorUid, 'policy_cohort_member_ended', 'policy_cohort', (int)$m->getCohortId(), $old, $this->membershipSummary($m), $actorUid);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return $this->membershipSummary($m);
		} finally {
			$this->lockingProvider->releaseLock(DbLockKeys::policyCohort((int)$m->getCohortId()), ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return array<string,mixed> */
	public function cohortSummary(\OCA\ArbeitszeitCheck\Db\PolicyCohort $cohort): array
	{
		return [
			'id' => (int)$cohort->getId(),
			'code' => (string)$cohort->getCode(),
			'publicLabel' => (string)$cohort->getPublicLabel(),
			'confidentialLabel' => $cohort->getConfidentialLabel(),
			'active' => (bool)$cohort->getActive(),
			'revision' => (int)$cohort->getRevision(),
			'memberCount' => $this->cohortMemberships?->countForCohort((int)$cohort->getId()) ?? 0,
			'createdBy' => $cohort->getCreatedBy(),
			'updatedBy' => $cohort->getUpdatedBy(),
		];
	}

	/** @return array<string,mixed> */
	private function membershipSummary(\OCA\ArbeitszeitCheck\Db\PolicyCohortMembership $m): array
	{
		return [
			'id' => (int)$m->getId(),
			'cohortId' => (int)$m->getCohortId(),
			'userId' => (string)$m->getUserId(),
			'displayName' => $this->users->get((string)$m->getUserId())?->getDisplayName() ?? (string)$m->getUserId(),
			'effectiveFrom' => $m->getEffectiveFrom(),
			'effectiveTo' => $m->getEffectiveTo(),
			'revision' => (int)$m->getRevision(),
		];
	}

	private function requireCohort(int $cohortId): \OCA\ArbeitszeitCheck\Db\PolicyCohort
	{
		try {
			return $this->cohorts->find($cohortId);
		} catch (DoesNotExistException) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
	}

	private function cohortCode(mixed $raw, string $label): string
	{
		$code = $this->nullableString($raw, 'code', 64);
		if ($code === null) {
			$ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
			$ascii = is_string($ascii) ? mb_strtolower($ascii) : mb_strtolower($label);
			$slug = trim((string)preg_replace('/[^a-z0-9]+/', '_', $ascii), '_');
			$code = 'cohort_' . ($slug !== '' ? $slug : 'group');
		}
		$code = mb_substr($code, 0, 64);
		if (preg_match('/^cohort_[a-z0-9_]+$/D', $code) !== 1) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The code must use the cohort_* identifier format.', 400, ['code' => 'invalid']);
		}
		return $code;
	}

	public function type(int $typeId): SpecialEntitlementType
	{
		$this->assertEnabled();
		return $this->requireType($typeId);
	}

	/** @return array<string,mixed> */
	public function resolveTrace(int $typeId, string $userId, string $asOf): array
	{
		$this->assertEnabled();
		$type = $this->requireType($typeId);
		$user = $this->users->get($userId);
		if ($user === null || !$user->isEnabled()) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
		$resolution = $this->resolver->resolve($type, $userId, $this->date($asOf, 'asOf'));
		return $resolution['trace'] + [
			'computedAmount' => number_format($resolution['computedAmount'], 2, '.', ''),
			'enabled' => $resolution['enabled'],
			'periodStart' => $resolution['periodStart']->format('Y-m-d'),
			'periodEnd' => $resolution['periodEnd']->format('Y-m-d'),
		];
	}

	/** @return list<array<string,mixed>> */
	public function accountsForUser(string $userId, string $asOf): array
	{
		$this->assertEnabled();
		$user = $this->users->get($userId);
		if ($user === null || !$user->isEnabled()) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
		return $this->entitlements->listAccounts($userId, $this->date($asOf, 'asOf'));
	}

	/** @return array<string,mixed> */
	public function periodDetail(int $periodId, int $limit = 50, int $offset = 0): array
	{
		$this->assertEnabled();
		try {
			$period = $this->periods->find($periodId);
		} catch (DoesNotExistException) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
		$snapshot = null;
		if ($period->getComputationSnapshotId() !== null) {
			try {
				$entity = $this->snapshots->find((int)$period->getComputationSnapshotId());
				$snapshot = [
					'id' => (int)$entity->getId(),
					'asOfDate' => $entity->getAsOfDate()?->format('Y-m-d'),
					'computedAt' => $entity->getComputedAt()?->format('c'),
					'computedBy' => $entity->getComputedBy(),
					'algorithmVersion' => $entity->getAlgorithmVersion(),
					'policyFingerprint' => $entity->getPolicyFingerprint(),
					'trace' => $entity->getCalculationTrace(),
				];
			} catch (DoesNotExistException) {
				$snapshot = null;
			}
		}
		return [
			'period' => [
				'id' => (int)$period->getId(),
				'entitlementTypeId' => (int)$period->getEntitlementTypeId(),
				'userId' => (string)$period->getUserId(),
				'periodStart' => $period->getPeriodStart()?->format('Y-m-d'),
				'periodEnd' => $period->getPeriodEnd()?->format('Y-m-d'),
				'computedAmount' => number_format((float)$period->getComputedAmount(), 2, '.', ''),
				'unit' => (string)$period->getUnit(),
				'status' => (string)$period->getStatus(),
				'revision' => (int)$period->getRevision(),
			],
			'snapshot' => $snapshot,
			'ledger' => array_map([$this, 'ledgerSummary'], $this->ledger->findForPeriodPage($periodId, $limit, $offset)),
			'reservations' => array_map(
				static fn ($reservation): array => [
					'id' => (int)$reservation->getId(),
					'absenceId' => (int)$reservation->getAbsenceId(),
					'amount' => number_format((float)$reservation->getAmount(), 2, '.', ''),
					'status' => (string)$reservation->getStatus(),
					'requestKey' => (string)$reservation->getRequestKey(),
					'revision' => (int)$reservation->getRevision(),
				],
				$this->reservations->findForPeriod($periodId),
			),
		];
	}

	/**
	 * Close an ended period exactly once; closed periods reject all balance writes.
	 *
	 * Serializes on the same um-tier period key that completeReservation /
	 * syncForUpdatedAbsence hold for the whole absence transaction — closing
	 * can never interleave with an in-flight reservation.
	 */
	public function closePeriod(int $periodId, int $revision, string $actorUid): array
	{
		$this->assertEnabled();
		try {
			$period = $this->periods->find($periodId);
		} catch (DoesNotExistException) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
		$lock = $this->periodLockKey($period);
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Special entitlement period');
		try {
			$period = $this->periods->find($periodId);
			$this->assertRevision(['revision' => $revision], (int)$period->getRevision());
			if ($period->getStatus() === SpecialEntitlementPeriod::STATUS_CLOSED) {
				return ['id' => $periodId, 'status' => 'closed', 'revision' => (int)$period->getRevision()];
			}
			if ($period->getPeriodEnd() === null || $period->getPeriodEnd() >= new \DateTime('today')) {
				throw new SpecialEntitlementException('PERIOD_CLOSED', 'Only an ended entitlement period can be closed.', 409, ['period' => 'not_ended']);
			}
			if ($period->getComputationSnapshotId() === null) {
				throw new SpecialEntitlementException('PERIOD_CLOSED', 'The period has no immutable computation snapshot.', 409, ['period' => 'missing_snapshot']);
			}
			if ($this->reservations->activeAmountForPeriod($periodId) > 0.0000001) {
				throw new SpecialEntitlementException('PERIOD_CLOSED', 'The period still has active reservations.', 409, ['period' => 'active_reservations']);
			}
			$this->db->beginTransaction();
			try {
				$period->setStatus(SpecialEntitlementPeriod::STATUS_CLOSED);
				if (!$this->periods->updateIfRevision($period)) {
					throw new SpecialEntitlementException('REVISION_CONFLICT', 'The period changed concurrently.', 409);
				}
				$this->audit->logAction($actorUid, 'special_entitlement_period_closed', 'special_entitlement_period', $periodId, ['status' => 'open'], ['status' => 'closed'], $actorUid);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return ['id' => $periodId, 'status' => 'closed', 'revision' => (int)$period->getRevision()];
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return array<string,mixed> */
	public function postCorrection(int $periodId, array $params, string $actorUid): array
	{
		$this->assertEnabled();
		try {
			$period = $this->periods->find($periodId);
		} catch (DoesNotExistException) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
		$revision = $params['revision'] ?? null;
		$this->assertRevision(['revision' => $revision], (int)$period->getRevision());
		if ($period->getStatus() === SpecialEntitlementPeriod::STATUS_CLOSED) {
			throw new SpecialEntitlementException('PERIOD_CLOSED', 'This entitlement period is closed.', 409, ['period' => 'closed']);
		}
		$amount = $this->decimal($params['amount'] ?? null, 'amount');
		if ($amount == 0.0 || abs($amount) > self::MAX_AMOUNT) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The correction amount is outside the supported range.', 400, ['amount' => 'range']);
		}
		$reason = $this->nullableString($params['reason'] ?? null, 'reason', 500);
		if ($reason === null) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'A correction reason is required.', 400, ['reason' => 'required']);
		}
		$eventKey = $this->eventKey($params['eventKey'] ?? $params['event_key'] ?? null);
		$lock = $this->periodLockKey($period);
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Special entitlement ledger');
		try {
			$period = $this->periods->find($periodId);
			$this->assertRevision(['revision' => $revision], (int)$period->getRevision());
			if ($period->getStatus() === SpecialEntitlementPeriod::STATUS_CLOSED) {
				throw new SpecialEntitlementException('PERIOD_CLOSED', 'This entitlement period is closed.', 409, ['period' => 'closed']);
			}
			$this->db->beginTransaction();
			try {
				$existing = $this->ledger->findByEventKey($eventKey);
				if ($existing === null) {
					$entry = new SpecialEntitlementLedgerEntry();
					$entry->setPeriodId($periodId);
					$entry->setEventKey($eventKey);
					$entry->setKind(SpecialEntitlementLedgerEntry::KIND_CORRECTION);
					$entry->setAmount($amount);
					$entry->setAbsenceId(null);
					$entry->setReversesLedgerId(null);
					$entry->setActorUid($actorUid);
					$entry->setReason($reason);
					$entry->setCreatedAt(new \DateTime());
					$entry = $this->ledger->insert($entry);
					$period->setRevision((int)$period->getRevision() + 1);
					if (!$this->periods->updateIfRevision($period)) {
						throw new SpecialEntitlementException('REVISION_CONFLICT', 'The period changed concurrently.', 409);
					}
					$this->audit->logAction($actorUid, 'special_entitlement_correction_posted', 'special_entitlement_ledger', (int)$entry->getId(), null, $this->ledgerSummary($entry), $actorUid);
				} else {
					if ((int)$existing->getPeriodId() !== $periodId
						|| $existing->getKind() !== SpecialEntitlementLedgerEntry::KIND_CORRECTION
						|| abs((float)$existing->getAmount() - $amount) > 0.0000001) {
						throw new SpecialEntitlementException('REVISION_CONFLICT', 'This event key was already used with a different correction.', 409, ['eventKey' => 'conflict']);
					}
					$entry = $existing;
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return $this->ledgerSummary($entry);
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return array<string,mixed> */
	public function adminTypeSummary(SpecialEntitlementType $type): array
	{
		return [
			'id' => (int)$type->getId(),
			'code' => (string)$type->getCode(),
			'publicLabel' => (string)$type->getPublicLabel(),
			'confidentialLabel' => $type->getConfidentialLabel(),
			'labelI18n' => $this->decodeLabelMap($type->getLabelI18n()),
			'absenceTypeCode' => (string)$type->getAbsenceTypeCode(),
			'unit' => (string)$type->getUnit(),
			'periodType' => (string)$type->getPeriodType(),
			'allowNegative' => (bool)$type->getAllowNegative(),
			'employeeMaySeeConfidentialLabel' => (bool)$type->getEmployeeMaySeeConfidentialLabel(),
			'active' => (bool)$type->getActive(),
			'version' => (int)$type->getVersion(),
			'createdBy' => $type->getCreatedBy(),
			'updatedBy' => $type->getUpdatedBy(),
			'createdAt' => $type->getCreatedAt()?->format('c'),
			'updatedAt' => $type->getUpdatedAt()?->format('c'),
		];
	}

	/** @return array<string,mixed> */
	public function policySummary(SpecialEntitlementPolicy $policy): array
	{
		return [
			'id' => (int)$policy->getId(),
			'entitlementTypeId' => (int)$policy->getEntitlementTypeId(),
			'scopeType' => (string)$policy->getScopeType(),
			'scopeId' => (string)$policy->getScopeId(),
			'operation' => (string)$policy->getOperation(),
			'amount' => $policy->getAmount() !== null ? number_format((float)$policy->getAmount(), 2, '.', '') : null,
			'tariffRuleSetId' => $policy->getTariffRuleSetId(),
			'priority' => (int)$policy->getPriority(),
			'effectiveFrom' => $policy->getEffectiveFrom()?->format('Y-m-d'),
			'effectiveTo' => $policy->getEffectiveTo()?->format('Y-m-d'),
			'reason' => $policy->getReason(),
			'version' => (int)$policy->getVersion(),
			'createdBy' => $policy->getCreatedBy(),
			'updatedBy' => $policy->getUpdatedBy(),
		];
	}

	/** @return array<string,mixed> */
	public function ledgerSummary(SpecialEntitlementLedgerEntry $entry): array
	{
		return [
			'id' => (int)$entry->getId(),
			'periodId' => (int)$entry->getPeriodId(),
			'eventKey' => (string)$entry->getEventKey(),
			'kind' => (string)$entry->getKind(),
			'amount' => number_format((float)$entry->getAmount(), 2, '.', ''),
			'absenceId' => $entry->getAbsenceId(),
			'reversesLedgerId' => $entry->getReversesLedgerId(),
			'actorUid' => $entry->getActorUid(),
			'reason' => $entry->getReason(),
			'createdAt' => $entry->getCreatedAt()?->format('c'),
		];
	}

	private function requireType(int $typeId): SpecialEntitlementType
	{
		try {
			return $this->types->find($typeId);
		} catch (DoesNotExistException) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
	}

	private function assertCreatableAbsenceType(string $code): void
	{
		if (!str_starts_with($code, CustomAbsenceType::CODE_PREFIX)
			|| !AbsenceTypeCatalog::isValid($code)
			|| !AbsenceTypeCatalog::isCreatable($code)) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The linked absence type must be an active custom type.', 400, ['absenceTypeCode' => 'custom_required']);
		}
	}

	/** @return array{0:string,1:string} */
	private function scope(array $params): array
	{
		$scopeType = $this->enumString($params['scopeType'] ?? $params['scope_type'] ?? null, SpecialEntitlementPolicy::SCOPES, 'scopeType');
		$scopeId = $this->nullableString($params['scopeId'] ?? $params['scope_id'] ?? null, 'scopeId', 64);
		if ($scopeType === SpecialEntitlementPolicy::SCOPE_ORG) {
			if ($scopeId !== null && $scopeId !== SpecialEntitlementPolicy::ORG_SCOPE_ID) {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'Organisation scope uses the fixed scope id.', 400, ['scopeId' => 'org']);
			}
			$scopeId = SpecialEntitlementPolicy::ORG_SCOPE_ID;
		}
		if ($scopeId === null || $scopeId === '') {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'A scope id is required.', 400, ['scopeId' => 'required']);
		}
		return [$scopeType, $scopeId];
	}

	private function assertScopeExists(string $scopeType, string $scopeId, SpecialEntitlementType $type): void
	{
		try {
			match ($scopeType) {
				SpecialEntitlementPolicy::SCOPE_ORG => $scopeId === SpecialEntitlementPolicy::ORG_SCOPE_ID,
				SpecialEntitlementPolicy::SCOPE_MODEL => $this->workingTimeModels->find($this->strictId($scopeId, 'scopeId')) !== null,
				SpecialEntitlementPolicy::SCOPE_GROUP => $this->cohorts->find($this->strictId($scopeId, 'scopeId'))->getActive(),
				SpecialEntitlementPolicy::SCOPE_TEAM => $this->teams->find($this->strictId($scopeId, 'scopeId')) !== null,
				SpecialEntitlementPolicy::SCOPE_USER => $this->users->get($scopeId)?->isEnabled() === true,
				default => false,
			} === true || throw new DoesNotExistException('Scope not found.');
		} catch (DoesNotExistException|\InvalidArgumentException) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
		if ($scopeType === SpecialEntitlementPolicy::SCOPE_TEAM && $type->getConfidentialLabel() !== null && trim((string)$type->getConfidentialLabel()) !== '') {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Operational teams cannot receive confidential entitlement rules.', 400, ['scopeType' => 'protected_scope_required']);
		}
	}

	private function assertTariffSet(int $id, \DateTimeImmutable $from, ?\DateTimeImmutable $to): void
	{
		try {
			$set = $this->tariffRuleSets->find($id);
		} catch (DoesNotExistException) {
			throw new SpecialEntitlementException('NOT_FOUND', 'Not found.', 404);
		}
		$validFrom = \DateTimeImmutable::createFromMutable($set->getValidFrom());
		$validTo = $set->getValidTo() !== null ? \DateTimeImmutable::createFromMutable($set->getValidTo()) : null;
		if ($set->getStatus() !== Constants::TARIFF_RULE_SET_STATUS_ACTIVE || $from < $validFrom || ($validTo !== null && ($to === null || $to > $validTo))) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The tariff rule set is not active for the whole policy range.', 400, ['tariffRuleSetId' => 'inactive']);
		}
	}

	private function typeCode(mixed $raw, string $label): string
	{
		$code = $this->nullableString($raw, 'code', 64);
		if ($code === null) {
			$ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
			$ascii = is_string($ascii) ? mb_strtolower($ascii) : mb_strtolower($label);
			$slug = trim((string)preg_replace('/[^a-z0-9]+/', '_', $ascii), '_');
			$code = 'custom_' . ($slug !== '' ? $slug : 'entitlement');
		}
		$code = mb_substr($code, 0, 64);
		if (preg_match('/^custom_[a-z0-9_]+$/D', $code) !== 1) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The code must use the custom_* identifier format.', 400, ['code' => 'invalid']);
		}
		return $code;
	}

	private function requiredLabel(mixed $raw, string $field): string
	{
		$value = $this->sanitizeText($raw, $field, self::LABEL_PATTERN_MAX);
		if ($value === null || $value === '') {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'A label is required.', 400, [$field => 'required']);
		}
		return $value;
	}

	private function optionalLabel(mixed $raw, string $field): ?string
	{
		$value = $this->sanitizeText($raw, $field, self::LABEL_PATTERN_MAX);
		return $value === '' ? null : $value;
	}

	/** @return array<string,string> */
	private function labelMap(mixed $raw): array
	{
		if ($raw === null || $raw === '') {
			return [];
		}
		if (!is_array($raw) || count($raw) > self::MAX_I18N) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'At most 12 translations are supported.', 400, ['labelI18n' => 'max_entries']);
		}
		$map = [];
		foreach ($raw as $locale => $label) {
			if (!is_string($locale) || preg_match(self::LOCALE_PATTERN, $locale) !== 1) {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid locale identifier.', 400, ['labelI18n' => 'locale']);
			}
			$map[str_replace('-', '_', $locale)] = $this->requiredLabel($label, 'labelI18n');
		}
		return $map;
	}

	private function sanitizeText(mixed $raw, string $field, int $max): ?string
	{
		if (is_array($raw)) {
			$raw = reset($raw);
		}
		if ($raw === null || is_bool($raw) || is_object($raw)) {
			return null;
		}
		$value = trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', (string)$raw));
		if (mb_strlen($value) > $max || strpbrk($value, '<>') !== false) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The text is invalid.', 400, [$field => 'invalid']);
		}
		return $value;
	}

	private function enumString(mixed $raw, array $allowed, string $field): string
	{
		$value = $this->nullableString($raw, $field, 32);
		if ($value === null || !in_array($value, $allowed, true)) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid value.', 400, [$field => 'invalid']);
		}
		return $value;
	}

	private function nullableString(mixed $raw, string $field, int $max): ?string
	{
		if (is_array($raw)) {
			$raw = reset($raw);
		}
		if ($raw === null || is_bool($raw) || is_object($raw)) {
			return null;
		}
		$value = trim((string)$raw);
		if (mb_strlen($value) > $max) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The value is too long.', 400, [$field => 'length']);
		}
		return $value;
	}

	private function strictId(mixed $raw, string $field): int
	{
		if (is_array($raw)) {
			$raw = reset($raw);
		}
		if (!is_string($raw) && !is_int($raw)) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid identifier.', 400, [$field => 'invalid']);
		}
		$value = trim((string)$raw);
		if (preg_match('/^\d+$/D', $value) !== 1 || (int)$value < 1) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid identifier.', 400, [$field => 'invalid']);
		}
		return (int)$value;
	}

	private function optionalId(mixed $raw, string $field): ?int
	{
		$value = $this->nullableString($raw, $field, 20);
		return $value === null || $value === '' ? null : $this->strictId($value, $field);
	}

	private function decimal(mixed $raw, string $field): float
	{
		if (is_array($raw)) {
			$raw = reset($raw);
		}
		if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid amount.', 400, [$field => 'invalid']);
		}
		$value = str_replace(',', '.', trim((string)$raw));
		if (preg_match('/^-?\d{1,4}(\.\d{1,2})?$/D', $value) !== 1) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid amount.', 400, [$field => 'invalid']);
		}
		return round((float)$value, 2, PHP_ROUND_HALF_UP);
	}

	private function policyAmount(mixed $raw, string $operation): ?float
	{
		$requires = in_array($operation, [SpecialEntitlementPolicy::OP_ADD, SpecialEntitlementPolicy::OP_REPLACE], true);
		if ($requires) {
			$amount = $this->decimal($raw, 'amount');
			if ($amount <= 0.0 || $amount > self::MAX_AMOUNT) {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'Amount must be between 0.01 and 4000.', 400, ['amount' => 'range']);
			}
			return $amount;
		}
		if ($raw !== null && $raw !== '') {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'This operation must not carry an amount.', 400, ['amount' => 'forbidden']);
		}
		return null;
	}

	private function priority(mixed $raw): int
	{
		if (is_array($raw)) {
			$raw = reset($raw);
		}
		$value = trim((string)($raw ?? '0'));
		if (preg_match('/^-?\d+$/D', $value) !== 1 || (int)$value < -1000 || (int)$value > 1000) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Priority must be between -1000 and 1000.', 400, ['priority' => 'range']);
		}
		return (int)$value;
	}

	private function date(mixed $raw, string $field): \DateTimeImmutable
	{
		$value = $this->nullableString($raw, $field, 10);
		if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid date.', 400, [$field => 'invalid']);
		}
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($date === false || $date->format('Y-m-d') !== $value) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid date.', 400, [$field => 'invalid']);
		}
		return $date;
	}

	private function optionalDate(mixed $raw, string $field): ?\DateTimeImmutable
	{
		$value = $this->nullableString($raw, $field, 10);
		return $value === null || $value === '' ? null : $this->date($value, $field);
	}

	private function eventKey(mixed $raw): string
	{
		try {
			return IdempotencyKey::parse(is_array($raw) ? reset($raw) : $raw)->value;
		} catch (\InvalidArgumentException) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid event key.', 400, ['eventKey' => 'invalid']);
		}
	}

	/**
	 * Same key SpecialEntitlementService acquires via beginReservation /
	 * syncForUpdatedAbsence for the whole absence transaction.
	 */
	private function periodLockKey(SpecialEntitlementPeriod $period): string
	{
		return DbLockKeys::specialEntitlementPeriod(
			(string)$period->getUserId(),
			(int)$period->getEntitlementTypeId(),
			(string)$period->getPeriodStart()?->format('Y-m-d'),
		);
	}

	private function assertRevision(array $params, int $current): void
	{
		$revision = $params['revision'] ?? $params['version'] ?? null;
		if (!is_int($revision) && !(is_string($revision) && preg_match('/^\d+$/D', $revision) === 1)) {
			throw new SpecialEntitlementException('REVISION_CONFLICT', 'The revision is missing or stale.', 409, ['revision' => 'required']);
		}
		if ((int)$revision !== $current) {
			throw new SpecialEntitlementException('REVISION_CONFLICT', 'The revision is missing or stale.', 409, ['revision' => 'stale']);
		}
	}

	private function parseBool(mixed $value): bool
	{
		if (is_array($value)) {
			$value = reset($value);
		}
		return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
	}

	/** @return array<string,mixed> */
	private function auditTypeSummary(SpecialEntitlementType $type): array
	{
		$summary = $this->adminTypeSummary($type);
		unset($summary['confidentialLabel'], $summary['labelI18n']);
		return $summary;
	}

	/** @return array<string,mixed> */
	private function auditPolicySummary(SpecialEntitlementPolicy $policy): array
	{
		$summary = $this->policySummary($policy);
		unset($summary['reason']);
		return $summary;
	}

	/** @return array<string,string> */
	private function decodeLabelMap(?string $json): array
	{
		if ($json === null || trim($json) === '') {
			return [];
		}
		$decoded = json_decode($json, true);
		return is_array($decoded) ? $decoded : [];
	}
}
