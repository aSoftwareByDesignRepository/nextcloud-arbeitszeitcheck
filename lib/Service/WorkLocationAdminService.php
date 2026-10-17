<?php

declare(strict_types=1);

/**
 * App-admin mutations for the work-location catalog and default policies
 * (Workforce Policy Suite, Phase 3).
 *
 * Integrity rules enforced here, not at the controller:
 * - `code` and `reporting_category` are immutable once a row exists; a code
 *   referenced by entries/policies cannot be hard-deleted (409 IN_USE) and a
 *   location with active policies cannot be deactivated (409 HAS_ACTIVE_DEFAULTS).
 * - Policy writes serialize on the `catalog_schema` lock slot (highest in
 *   {@see DbLockKeys::ORDER}) and reject overlapping effective ranges within
 *   the same scope — the overlap predicate cannot be a portable index.
 * - Every mutation writes an audit row (actor, entity, old/new, revision).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicy;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinition;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\WorkLocationConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;

final class WorkLocationAdminService
{
	public const ERR_CODE_EXISTS = 'WORK_LOCATION_CODE_EXISTS';
	public const ERR_IN_USE = 'WORK_LOCATION_IN_USE';
	public const ERR_HAS_ACTIVE_DEFAULTS = 'WORK_LOCATION_HAS_ACTIVE_DEFAULTS';
	public const ERR_POLICY_OVERLAP = 'WORK_LOCATION_POLICY_OVERLAP';
	public const ERR_NOT_FOUND = 'NOT_FOUND';
	public const ERR_INVALID = 'WORK_LOCATION_INVALID';

	public function __construct(
		private readonly WorkLocationDefinitionMapper $definitionMapper,
		private readonly WorkLocationDefaultPolicyMapper $policyMapper,
		private readonly WorkLocationCatalog $catalog,
		private readonly TeamMapper $teamMapper,
		private readonly WorkingTimeModelMapper $modelMapper,
		private readonly IUserManager $userManager,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly ILockingProvider $lockingProvider,
	) {
	}

	/**
	 * @return array<string, WorkLocationDefinition>
	 */
	public function catalogWithUsage(): array
	{
		return $this->catalog->all();
	}

	/**
	 * Creates a custom location. Admin-supplied `code` is normalized to a
	 * `custom_`-prefixed code; built-in codes are rejected as duplicates.
	 *
	 * @param array<string,mixed> $params label, code, category, color, icon, labelI18n
	 * @throws WorkLocationConflictException|\InvalidArgumentException
	 */
	public function create(array $params, string $actorUserId): WorkLocationDefinition
	{
		$stem = strtolower(trim((string)($params['code'] ?? '')));
		if ($stem === '' || preg_match(WorkLocationCatalog::CODE_PATTERN, $stem) !== 1) {
			throw new \InvalidArgumentException(self::ERR_INVALID);
		}
		$code = WorkLocationCatalog::customCodeFor($stem);
		if (strlen($code) > 64) {
			throw new \InvalidArgumentException(self::ERR_INVALID);
		}
		// A stem that already names a catalog entry (built-in or custom with
		// the prefix spelled out) collides directly — silently prefixing it
		// would create a shadow code like `custom_office` next to `office`.
		if ($this->catalog->isKnown($stem)) {
			throw new WorkLocationConflictException(self::ERR_CODE_EXISTS);
		}

		$label = trim((string)($params['label'] ?? $params['baseLabel'] ?? ''));
		if ($label === '' || mb_strlen($label) > WorkLocationCatalog::LABEL_MAX_LENGTH) {
			throw new \InvalidArgumentException('label');
		}
		$category = (string)($params['category'] ?? '');
		if (!in_array($category, WorkLocationCatalog::CATEGORIES, true)) {
			throw new \InvalidArgumentException('category');
		}
		$color = (string)($params['color'] ?? 'neutral');
		if (!in_array($color, WorkLocationCatalog::COLORS, true)) {
			throw new \InvalidArgumentException('color');
		}
		$icon = (string)($params['icon'] ?? 'map-pin');
		if (!in_array($icon, WorkLocationCatalog::ICONS, true)) {
			throw new \InvalidArgumentException('icon');
		}
		$labelI18n = WorkLocationCatalog::encodeLabelMap(
			is_array($params['labelI18n'] ?? null) ? $params['labelI18n'] : []
		);

		$lock = DbLockKeys::policyCatalog('work_location');
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Work-location catalog mutation');
		try {
			if ($this->catalog->isKnown($code) || $this->definitionMapper->codeExists($code)) {
				throw new WorkLocationConflictException(self::ERR_CODE_EXISTS);
			}
			$now = new \DateTime();
			$row = new WorkLocationDefinition();
			$row->setCode($code);
			$row->setBaseLabel($label);
			$row->setLabelI18n($labelI18n);
			$row->setReportingCategory($category);
			$row->setColor($color);
			$row->setIcon($icon);
			$row->setActive(true);
			$row->setBuiltIn(false);
			$row->setVersion(1);
			$row->setCreatedBy($actorUserId);
			$row->setUpdatedBy($actorUserId);
			$row->setCreatedAt($now);
			$row->setUpdatedAt($now);
			$this->definitionMapper->insert($row);
			$this->audit('created', 'work_location_def', (int)$row->getId(), null, [
				'code' => $code, 'label' => $label, 'category' => $category,
			], $actorUserId);
			$this->catalog->resetCache();
			return $row;
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Updates mutable fields of a definition: label, label_i18n, color, icon,
	 * active. `code`/`reporting_category`/`built_in` are immutable and ignored
	 * if present in $params (callers must not attempt to change them).
	 *
	 * @param array<string,mixed> $params
	 * @throws WorkLocationConflictException|\InvalidArgumentException
	 */
	public function update(int $id, array $params, string $actorUserId): WorkLocationDefinition
	{
		$lock = DbLockKeys::policyCatalog('work_location');
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Work-location catalog mutation');
		try {
			try {
				$row = $this->definitionMapper->find($id);
			} catch (DoesNotExistException) {
				throw new WorkLocationConflictException(self::ERR_NOT_FOUND);
			}

			$old = [
				'label' => $row->getBaseLabel(),
				'label_i18n' => $row->getLabelI18n(),
				'color' => $row->getColor(),
				'icon' => $row->getIcon(),
				'active' => (bool)$row->getActive(),
			];

			if (array_key_exists('label', $params) || array_key_exists('baseLabel', $params)) {
				$label = trim((string)($params['label'] ?? $params['baseLabel']));
				if ($label === '' || mb_strlen($label) > WorkLocationCatalog::LABEL_MAX_LENGTH) {
					throw new \InvalidArgumentException('label');
				}
				$row->setBaseLabel($label);
			}
			if (array_key_exists('labelI18n', $params)) {
				$row->setLabelI18n(WorkLocationCatalog::encodeLabelMap(
					is_array($params['labelI18n']) ? $params['labelI18n'] : []
				));
			}
			if (array_key_exists('color', $params)) {
				$color = (string)$params['color'];
				if (!in_array($color, WorkLocationCatalog::COLORS, true)) {
					throw new \InvalidArgumentException('color');
				}
				$row->setColor($color);
			}
			if (array_key_exists('icon', $params)) {
				$icon = (string)$params['icon'];
				if (!in_array($icon, WorkLocationCatalog::ICONS, true)) {
					throw new \InvalidArgumentException('icon');
				}
				$row->setIcon($icon);
			}
			if (array_key_exists('active', $params)) {
				$active = $params['active'] === true || $params['active'] === '1' || $params['active'] === 'true' || $params['active'] === 1;
				if (!$active && (bool)$row->getActive()) {
					$this->assertNoActiveDefaults((string)$row->getCode());
				}
				$row->setActive($active);
			}

			$row->setVersion((int)$row->getVersion() + 1);
			$row->setUpdatedBy($actorUserId);
			$row->setUpdatedAt(new \DateTime());
			$this->definitionMapper->update($row);
			$this->audit('updated', 'work_location_def', $id, $old, [
				'label' => $row->getBaseLabel(),
				'color' => $row->getColor(),
				'icon' => $row->getIcon(),
				'active' => (bool)$row->getActive(),
				'version' => (int)$row->getVersion(),
			], $actorUserId);
			$this->catalog->resetCache();
			return $row;
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Hard delete is only possible for unreferenced custom rows — historical
	 * entries keep their code; the normal lifecycle end is `active=false`.
	 *
	 * @throws WorkLocationConflictException
	 */
	public function delete(int $id, string $actorUserId): void
	{
		$lock = DbLockKeys::policyCatalog('work_location');
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Work-location catalog mutation');
		try {
			try {
				$row = $this->definitionMapper->find($id);
			} catch (DoesNotExistException) {
				throw new WorkLocationConflictException(self::ERR_NOT_FOUND);
			}
			$code = (string)$row->getCode();
			if ((bool)$row->getBuiltIn()) {
				throw new WorkLocationConflictException(self::ERR_IN_USE);
			}
			if ($this->definitionMapper->countReferencesByCode($code) > 0
				|| $this->policyMapper->findByLocationCode($code) !== []) {
				throw new WorkLocationConflictException(self::ERR_IN_USE);
			}
			$this->definitionMapper->delete($row);
			$this->audit('deleted', 'work_location_def', $id, ['code' => $code], null, $actorUserId);
			$this->catalog->resetCache();
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Creates an effectively dated default policy. Overlapping ranges in the
	 * same scope are rejected under the catalog-schema lock.
	 *
	 * @param array<string,mixed> $params scopeType, scopeId, locationCode, priority, effectiveFrom, effectiveTo
	 * @throws WorkLocationConflictException|\InvalidArgumentException
	 */
	public function createPolicy(array $params, string $actorUserId): WorkLocationDefaultPolicy
	{
		$scopeType = (string)($params['scopeType'] ?? '');
		if (!in_array($scopeType, WorkLocationDefaultPolicy::SCOPE_TYPES, true)) {
			throw new \InvalidArgumentException('scopeType');
		}
		$scopeId = $scopeType === WorkLocationDefaultPolicy::SCOPE_ORG
			? WorkLocationDefaultPolicy::ORG_SCOPE_ID
			: trim((string)($params['scopeId'] ?? ''));
		if ($scopeId === '') {
			throw new \InvalidArgumentException('scopeId');
		}
		$this->assertScopeExists($scopeType, $scopeId);

		$code = $this->catalog->normalize($params['locationCode'] ?? null);
		if ($code === null || !$this->catalog->isCreatable($code)) {
			throw new \InvalidArgumentException('locationCode');
		}
		$priority = filter_var($params['priority'] ?? 0, FILTER_VALIDATE_INT);
		if ($priority === false || $priority < -1000 || $priority > 1000) {
			throw new \InvalidArgumentException('priority');
		}
		$from = $this->parseDate($params['effectiveFrom'] ?? null, 'effectiveFrom');
		$to = $params['effectiveTo'] === null || $params['effectiveTo'] === ''
			? null
			: $this->parseDate($params['effectiveTo'], 'effectiveTo');
		if ($to !== null && $to < $from) {
			throw new \InvalidArgumentException('effectiveTo');
		}

		$lock = DbLockKeys::policyCatalog('work_location');
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Work-location policy mutation');
		try {
			if ($this->policyMapper->findOverlapping($scopeType, $scopeId, $from, $to) !== []) {
				throw new WorkLocationConflictException(self::ERR_POLICY_OVERLAP);
			}
			$now = new \DateTime();
			$row = new WorkLocationDefaultPolicy();
			$row->setScopeType($scopeType);
			$row->setScopeId($scopeId);
			$row->setLocationCode($code);
			$row->setPriority($priority);
			$row->setEffectiveFrom(new \DateTime($from));
			if ($to !== null) {
				$row->setEffectiveTo(new \DateTime($to));
			}
			$row->setRevision(1);
			$row->setCreatedBy($actorUserId);
			$row->setCreatedAt($now);
			$row->setUpdatedAt($now);
			$this->policyMapper->insert($row);
			$this->audit('created', 'work_location_policy', (int)$row->getId(), null, [
				'scope' => $scopeType . ':' . $scopeId,
				'location_code' => $code,
				'priority' => $priority,
				'effective_from' => $from,
				'effective_to' => $to,
			], $actorUserId);
			return $row;
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Ends a policy (sets effective_to). Ending rather than mutating keeps the
	 * effective-dated history honest — the past resolution result for a day
	 * must stay reconstructible.
	 *
	 * @throws WorkLocationConflictException|\InvalidArgumentException
	 */
	public function endPolicy(int $id, string $effectiveTo, string $actorUserId): WorkLocationDefaultPolicy
	{
		$to = $this->parseDate($effectiveTo, 'effectiveTo');
		$lock = DbLockKeys::policyCatalog('work_location');
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Work-location policy mutation');
		try {
			try {
				$row = $this->policyMapper->find($id);
			} catch (DoesNotExistException) {
				throw new WorkLocationConflictException(self::ERR_NOT_FOUND);
			}
			$from = $row->getEffectiveFrom()?->format('Y-m-d');
			if ($from !== null && $to < $from) {
				throw new \InvalidArgumentException('effectiveTo');
			}
			$oldTo = $row->getEffectiveTo()?->format('Y-m-d');
			$row->setEffectiveTo(new \DateTime($to));
			$row->setRevision((int)$row->getRevision() + 1);
			$row->setUpdatedAt(new \DateTime());
			$this->policyMapper->update($row);
			$this->audit('ended', 'work_location_policy', $id,
				['effective_to' => $oldTo],
				['effective_to' => $to, 'revision' => (int)$row->getRevision()],
				$actorUserId);
			return $row;
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * @return list<WorkLocationDefaultPolicy>
	 */
	public function policiesForScope(string $scopeType, string $scopeId): array
	{
		return $this->policyMapper->findByScope($scopeType, $scopeId);
	}

	private function assertNoActiveDefaults(string $code): void
	{
		if ($this->policyMapper->findActiveByLocationCode($code, (new \DateTime())->format('Y-m-d')) !== []) {
			throw new WorkLocationConflictException(self::ERR_HAS_ACTIVE_DEFAULTS);
		}
	}

	/**
	 * Scope-id existence check — a policy must never reference a ghost user,
	 * team, or model (existence AND digits-only numeric ids where numeric).
	 */
	private function assertScopeExists(string $scopeType, string $scopeId): void
	{
		$exists = match ($scopeType) {
			WorkLocationDefaultPolicy::SCOPE_ORG => $scopeId === WorkLocationDefaultPolicy::ORG_SCOPE_ID,
			WorkLocationDefaultPolicy::SCOPE_USER => $this->userManager->userExists($scopeId),
			WorkLocationDefaultPolicy::SCOPE_TEAM => $this->digitsOnly($scopeId) && $this->teamExists((int)$scopeId),
			WorkLocationDefaultPolicy::SCOPE_MODEL => $this->digitsOnly($scopeId) && $this->modelExists((int)$scopeId),
			default => false,
		};
		if (!$exists) {
			throw new WorkLocationConflictException(self::ERR_NOT_FOUND);
		}
	}

	private function digitsOnly(string $value): bool
	{
		return preg_match('/^\d+$/', $value) === 1;
	}

	private function teamExists(int $id): bool
	{
		try {
			$this->teamMapper->find($id);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function modelExists(int $id): bool
	{
		try {
			$this->modelMapper->find($id);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function parseDate(mixed $raw, string $field): string
	{
		if (!is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
			throw new \InvalidArgumentException($field);
		}
		$d = \DateTime::createFromFormat('!Y-m-d', $raw);
		if ($d === false || $d->format('Y-m-d') !== $raw) {
			throw new \InvalidArgumentException($field);
		}
		return $raw;
	}

	private function audit(string $action, string $entityType, int $entityId, ?array $old, ?array $new, string $actorUserId): void
	{
		$this->auditLogMapper->logAction(
			$actorUserId,
			'work_location_' . $action,
			$entityType,
			$entityId,
			$old,
			$new,
			$actorUserId,
		);
	}
}
