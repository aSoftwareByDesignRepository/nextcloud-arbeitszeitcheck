<?php

declare(strict_types=1);

/**
 * Recurring holiday rules (spec B): create/edit/skip/active/convert.
 *
 * Every mutation runs under the named policyCatalog('holiday') lock — the
 * same lock the statutory reconciler and HolidayAdminService deletes use —
 * so check-then-insert dedup and rule-delete→skip-cascade serialise
 * against every other catalog writer (EC-B5). After each write the
 * request memo for the whole state is reset (rules span all years ≥
 * start_year; single-year invalidation is insufficient, B-R3).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\HolidayRule;
use OCA\ArbeitszeitCheck\Db\HolidayRuleMapper;
use OCA\ArbeitszeitCheck\Db\HolidayRuleSkip;
use OCA\ArbeitszeitCheck\Db\HolidayRuleSkipMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class HolidayRuleService
{
	public function __construct(
		private readonly HolidayRuleMapper $ruleMapper,
		private readonly HolidayRuleSkipMapper $skipMapper,
		private readonly HolidayMapper $holidayMapper,
		private readonly HolidayService $holidayCalendarService,
		private readonly ?ILockingProvider $lockingProvider = null,
	) {
	}

	private const YEAR_MIN = 1970;
	private const YEAR_MAX = 2100;

	/**
	 * Rules of a state with their skipped years attached — the payload the
	 * admin list needs (B-R7 skipped instances stay visible).
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function listForState(string $state): array
	{
		$state = strtoupper(trim($state));
		$rules = $this->ruleMapper->findByState($state);
		if ($rules === []) {
			return [];
		}
		$ruleIds = array_map(static fn (HolidayRule $r): int => (int)$r->getId(), $rules);
		$skipped = $this->skipMapper->yearsForRuleIds($ruleIds);

		$out = [];
		foreach ($rules as $rule) {
			$dto = $rule->toArray();
			$dto['skipped_years'] = $skipped[(int)$rule->getId()] ?? [];
			$out[] = $dto;
		}
		return $out;
	}

	/**
	 * Stored rule values for old-state audit logging (controller reads
	 * before mutation; returns the raw toArray snapshot).
	 *
	 * @return array<string,mixed>|null
	 */
	public function getRuleForAudit(int $id): ?array
	{
		try {
			return $this->ruleMapper->findById($id)->toArray();
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Whether an active (non-skipped) rule already covers a resolved date —
	 * the shadow-check behind the "this date is covered by the annual rule
	 * X — your entry replaces the rule instance for {year}" hint (B-R5/B-R7).
	 */
	public function ruleCoveringDate(string $state, string $dateYmd): ?HolidayRule
	{
		$state = strtoupper(trim($state));
		try {
			$year = (int)(new \DateTimeImmutable($dateYmd))->format('Y');
		} catch (\Throwable) {
			return null;
		}
		$rules = $this->ruleMapper->findActiveByState($state);
		$ruleIds = array_map(static fn (HolidayRule $r): int => (int)$r->getId(), $rules);
		$skipped = $this->skipMapper->yearsForRuleIds($ruleIds);
		foreach ($rules as $rule) {
			$ruleId = (int)$rule->getId();
			if (in_array($year, $skipped[$ruleId] ?? [], true)) {
				continue;
			}
			$resolved = $rule->resolveDateForYear($year);
			if ($resolved !== null && $resolved->format('Y-m-d') === $dateYmd) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Skipped instances visible for a state/year — muted list entries with
	 * a restore action (B-R7).
	 *
	 * @return array<int, array{rule_id:int, name:string, date:string|null, kind:string, scope:string}>
	 */
	public function skippedInstancesForStateYear(string $state, int $year): array
	{
		$state = strtoupper(trim($state));
		$out = [];
		$rules = $this->ruleMapper->findByState($state);
		if ($rules === []) {
			return $out;
		}
		$skipped = $this->skipMapper->yearsForRuleIds(
			array_map(static fn (HolidayRule $r): int => (int)$r->getId(), $rules)
		);
		foreach ($rules as $rule) {
			$ruleId = (int)$rule->getId();
			if (!in_array($year, $skipped[$ruleId] ?? [], true)) {
				continue;
			}
			$resolved = $rule->resolveDateForYear($year);
			$out[] = [
				'rule_id' => $ruleId,
				'name' => (string)$rule->getName(),
				'date' => $resolved !== null ? $resolved->format('Y-m-d') : null,
				'kind' => (string)$rule->getKind(),
				'scope' => (string)$rule->getScope(),
			];
		}
		return $out;
	}

	/**
	 * Create a rule from request params (shared by POST /holiday-rules and
	 * the saveStateHoliday recurring path — identical validation).
	 *
	 * @param array<string,mixed> $params
	 * @throws BusinessRuleException validation (400) / duplicate rule_key (409)
	 */
	public function createRule(array $params, string $actorUid): HolidayRule
	{
		$rule = $this->buildRuleFromParams($params, null);

		$lock = DbLockKeys::policyCatalog('holiday');
		$this->acquireLock($lock);
		try {
			$dupId = $this->ruleMapper->findIdForStateRuleKeyScope(
				$rule->getState(), $rule->getRuleKey(), $rule->getScope()
			);
			if ($dupId !== null) {
				throw new BusinessRuleException(
					'An annual holiday for this day already exists in this region.',
					'holiday_rule_exists',
					['rule_id' => $dupId]
				);
			}
			$rule->setCreatedBy($actorUid !== '' ? $actorUid : null);
			$rule->setCreatedAt(new \DateTime());
			$rule->setUpdatedBy($actorUid !== '' ? $actorUid : null);
			$rule->setUpdatedAt(new \DateTime());
			/** @var HolidayRule $saved */
			$saved = $this->ruleMapper->insert($rule);
			$this->holidayCalendarService->resetMemoForState($saved->getState());
			return $saved;
		} finally {
			$this->releaseLock($lock);
		}
	}

	/**
	 * Edit name/kind/start_year/anchor of a rule — applies to every year
	 * (B-R6). Anchor changes recompute rule_key and re-run dedup.
	 *
	 * @param array<string,mixed> $params
	 */
	public function updateRule(int $id, array $params, string $actorUid): HolidayRule
	{
		$lock = DbLockKeys::policyCatalog('holiday');
		$this->acquireLock($lock);
		try {
			$rule = $this->requireRule($id);
			$updated = $this->buildRuleFromParams($params, $rule);

			// Anchor/key change ⇒ re-dedup against every other rule.
			if ($updated->getRuleKey() !== $rule->getRuleKey()
				|| $updated->getScope() !== $rule->getScope()
				|| $updated->getState() !== $rule->getState()) {
				$dupId = $this->ruleMapper->findIdForStateRuleKeyScope(
					$updated->getState(), $updated->getRuleKey(), $updated->getScope()
				);
				if ($dupId !== null && $dupId !== $id) {
					throw new BusinessRuleException(
						'An annual holiday for this day already exists in this region.',
						'holiday_rule_exists',
						['rule_id' => $dupId]
					);
				}
			}

			$updated->setId($id);
			$updated->setCreatedBy($rule->getCreatedBy());
			$updated->setCreatedAt($rule->getCreatedAt());
			$updated->setActive($rule->getActive());
			$updated->setUpdatedBy($actorUid !== '' ? $actorUid : null);
			$updated->setUpdatedAt(new \DateTime());
			/** @var HolidayRule $saved */
			$saved = $this->ruleMapper->update($updated);
			$this->holidayCalendarService->resetMemoForState($saved->getState());
			if ($saved->getState() !== $rule->getState()) {
				$this->holidayCalendarService->resetMemoForState($rule->getState());
			}
			return $saved;
		} finally {
			$this->releaseLock($lock);
		}
	}

	/**
	 * Reversible series stop/continue (B-R6). Skips stay stored but inert.
	 */
	public function setActive(int $id, bool $active, string $actorUid): HolidayRule
	{
		$lock = DbLockKeys::policyCatalog('holiday');
		$this->acquireLock($lock);
		try {
			$rule = $this->requireRule($id);
			$rule->setActive($active);
			$rule->setUpdatedBy($actorUid !== '' ? $actorUid : null);
			$rule->setUpdatedAt(new \DateTime());
			/** @var HolidayRule $saved */
			$saved = $this->ruleMapper->update($rule);
			$this->holidayCalendarService->resetMemoForState($saved->getState());
			return $saved;
		} finally {
			$this->releaseLock($lock);
		}
	}

	/**
	 * Per-year exception (B-R6): the instance stays visible but muted in
	 * the admin list until restored.
	 */
	public function addSkip(int $id, int $year, string $actorUid): void
	{
		$this->assertYear($year);
		$lock = DbLockKeys::policyCatalog('holiday');
		$this->acquireLock($lock);
		try {
			$rule = $this->requireRule($id);
			if ($year < (int)$rule->getStartYear()) {
				throw new BusinessRuleException(
					'The rule does not produce a day before its start year.',
					'holiday_rule_skip_before_start',
					['year' => $year, 'start_year' => (int)$rule->getStartYear()]
				);
			}
			if ($rule->resolveDateForYear($year) === null) {
				throw new BusinessRuleException(
					'The rule does not produce a day in this year.',
					'holiday_rule_skip_no_instance',
					['year' => $year]
				);
			}
			if (!$this->skipMapper->existsForRuleAndYear($id, $year)) {
				$skip = new HolidayRuleSkip();
				$skip->setRuleId($id);
				$skip->setYear($year);
				$skip->setCreatedBy($actorUid !== '' ? $actorUid : null);
				$skip->setCreatedAt(new \DateTime());
				$this->skipMapper->insert($skip);
			}
			$this->holidayCalendarService->resetMemoForState($rule->getState());
		} finally {
			$this->releaseLock($lock);
		}
	}

	public function removeSkip(int $id, int $year): void
	{
		$this->assertYear($year);
		$lock = DbLockKeys::policyCatalog('holiday');
		$this->acquireLock($lock);
		try {
			$rule = $this->requireRule($id);
			$this->skipMapper->deleteByRuleAndYear($id, $year);
			$this->holidayCalendarService->resetMemoForState($rule->getState());
		} finally {
			$this->releaseLock($lock);
		}
	}

	/**
	 * "Repeat every year" on an existing single-date row (B-R7): create the
	 * rule from the row and delete the row, atomically under the named lock.
	 * The rule anchors on the row's fixed month/day — never an easter guess
	 * (date-equality inference is forbidden by B-R4).
	 */
	public function convertHolidayToRule(int $holidayId, string $actorUid): HolidayRule
	{
		$lock = DbLockKeys::policyCatalog('holiday');
		$this->acquireLock($lock);
		try {
			try {
				$row = $this->holidayMapper->findById($holidayId);
			} catch (DoesNotExistException) {
				throw new BusinessRuleException('Holiday not found', 'holiday_not_found', ['id' => $holidayId]);
			}
			$date = $row->getDate();
			if ($date === null) {
				throw new BusinessRuleException('Holiday has no date', 'holiday_no_date', ['id' => $holidayId]);
			}
			if ($row->getSource() === Holiday::SOURCE_RULE) {
				throw new BusinessRuleException('This entry already belongs to an annual rule', 'holiday_is_rule_instance');
			}
			if ($row->getScope() === Holiday::SCOPE_STATUTORY) {
				throw new BusinessRuleException('Statutory holidays cannot become annual rules', 'holiday_rule_statutory_scope');
			}

			$rule = new HolidayRule();
			$rule->setState($row->getState());
			$rule->setAnchor(HolidayRule::ANCHOR_DATE);
			$rule->setMonth((int)$date->format('n'));
			$rule->setDay((int)$date->format('j'));
			$rule->setEasterOffset(0);
			$rule->setRuleKey(HolidayRule::ruleKeyFor(
				HolidayRule::ANCHOR_DATE, (int)$date->format('n'), (int)$date->format('j'), 0
			));
			$rule->setName((string)$row->getName());
			$rule->setNameMsgid(null);
			$rule->setKind($row->getKind() === Holiday::KIND_HALF ? Holiday::KIND_HALF : Holiday::KIND_FULL);
			$rule->setScope($row->getScope());
			$rule->setStartYear((int)$date->format('Y'));
			$rule->setActive(true);

			$this->assertRuleValid($rule);
			$dupId = $this->ruleMapper->findIdForStateRuleKeyScope(
				$rule->getState(), $rule->getRuleKey(), $rule->getScope()
			);
			if ($dupId !== null) {
				throw new BusinessRuleException(
					'An annual holiday for this day already exists in this region.',
					'holiday_rule_exists',
					['rule_id' => $dupId]
				);
			}

			$rule->setCreatedBy($actorUid !== '' ? $actorUid : null);
			$rule->setCreatedAt(new \DateTime());
			$rule->setUpdatedBy($actorUid !== '' ? $actorUid : null);
			$rule->setUpdatedAt(new \DateTime());
			/** @var HolidayRule $saved */
			$saved = $this->ruleMapper->insert($rule);

			$this->holidayMapper->deleteById($holidayId);
			$this->holidayCalendarService->resetMemoForState($rule->getState());
			return $saved;
		} finally {
			$this->releaseLock($lock);
		}
	}

	/**
	 * Shared param → entity normalisation + validation. With $base set,
	 * missing params keep the stored values (PUT edit semantics).
	 *
	 * @param array<string,mixed> $params
	 */
	private function buildRuleFromParams(array $params, ?HolidayRule $base): HolidayRule
	{
		$rule = new HolidayRule();
		if ($base !== null) {
			$rule->setState($base->getState());
			$rule->setAnchor($base->getAnchor());
			$rule->setMonth($base->getMonth());
			$rule->setDay($base->getDay());
			$rule->setEasterOffset($base->getEasterOffset());
			$rule->setName($base->getName());
			$rule->setNameMsgid($base->getNameMsgid());
			$rule->setKind($base->getKind());
			$rule->setScope($base->getScope());
			$rule->setStartYear($base->getStartYear());
		}

		if (array_key_exists('state', $params) || $base === null) {
			$rule->setState(strtoupper(trim((string)($params['state'] ?? ''))));
		}
		if (array_key_exists('anchor', $params)) {
			$rule->setAnchor(trim((string)$params['anchor']));
		}
		if (array_key_exists('month', $params)) {
			$rule->setMonth((int)$params['month']);
		}
		if (array_key_exists('day', $params)) {
			$rule->setDay((int)$params['day']);
		}
		if (array_key_exists('easterOffset', $params) || array_key_exists('easter_offset', $params)) {
			$rule->setEasterOffset((int)($params['easterOffset'] ?? $params['easter_offset']));
		}
		if (array_key_exists('date', $params) && trim((string)$params['date']) !== '') {
			// saveStateHoliday recurring path: month/day default from the
			// submitted date unless the client sent an easter anchor.
			$anchor = array_key_exists('anchor', $params)
				? trim((string)$params['anchor'])
				: ($base !== null ? $base->getAnchor() : HolidayRule::ANCHOR_DATE);
			if ($anchor !== HolidayRule::ANCHOR_EASTER) {
				try {
					$d = new \DateTimeImmutable((string)$params['date']);
					if (!array_key_exists('month', $params)) {
						$rule->setMonth((int)$d->format('n'));
					}
					if (!array_key_exists('day', $params)) {
						$rule->setDay((int)$d->format('j'));
					}
					if (!array_key_exists('anchor', $params)) {
						$rule->setAnchor(HolidayRule::ANCHOR_DATE);
					}
				} catch (\Throwable) {
					// Left as-is; validate() reports the missing/invalid anchor fields.
				}
			}
		}
		if (array_key_exists('name', $params) || $base === null) {
			$rule->setName(trim((string)($params['name'] ?? '')));
		}
		if (array_key_exists('nameMsgid', $params) || array_key_exists('name_msgid', $params)) {
			$msgid = trim((string)($params['nameMsgid'] ?? $params['name_msgid'] ?? ''));
			$rule->setNameMsgid($msgid !== '' ? $msgid : null);
		}
		if (array_key_exists('kind', $params) || $base === null) {
			$kind = (string)($params['kind'] ?? Holiday::KIND_FULL);
			$rule->setKind($kind === Holiday::KIND_HALF ? Holiday::KIND_HALF : Holiday::KIND_FULL);
		}
		if (array_key_exists('scope', $params) || $base === null) {
			$rule->setScope(trim((string)($params['scope'] ?? Holiday::SCOPE_COMPANY)));
		}
		if (array_key_exists('startYear', $params) || array_key_exists('start_year', $params)) {
			$rule->setStartYear((int)($params['startYear'] ?? $params['start_year']));
		} elseif ($base === null) {
			if (array_key_exists('date', $params) && trim((string)$params['date']) !== '') {
				try {
					$rule->setStartYear((int)(new \DateTimeImmutable((string)$params['date']))->format('Y'));
				} catch (\Throwable) {
					$rule->setStartYear((int)date('Y'));
				}
			} else {
				$rule->setStartYear((int)date('Y'));
			}
		}

		$rule->setRuleKey(HolidayRule::ruleKeyFor(
			(string)$rule->getAnchor(),
			(int)$rule->getMonth(),
			(int)$rule->getDay(),
			(int)$rule->getEasterOffset()
		));
		$rule->setActive($base !== null ? $base->getActive() : true);

		$this->assertRuleValid($rule);
		return $rule;
	}

	private function assertRuleValid(HolidayRule $rule): void
	{
		if (!RegionRegistry::isValidRegion((string)$rule->getState())) {
			throw new BusinessRuleException('Invalid region code', 'invalid_region');
		}
		$errors = $rule->validate();
		if ($errors !== []) {
			throw new BusinessRuleException(
				'The holiday rule is invalid.',
				'holiday_rule_invalid',
				['fields' => implode(',', array_keys($errors))]
			);
		}
	}

	private function assertYear(int $year): void
	{
		if ($year < self::YEAR_MIN || $year > self::YEAR_MAX) {
			throw new BusinessRuleException('Invalid year', 'invalid_year', ['year' => $year]);
		}
	}

	private function requireRule(int $id): HolidayRule
	{
		if ($id <= 0) {
			throw new BusinessRuleException('Invalid holiday rule ID', 'invalid_id');
		}
		try {
			return $this->ruleMapper->findById($id);
		} catch (DoesNotExistException) {
			throw new BusinessRuleException('Holiday rule not found', 'holiday_rule_not_found', ['id' => $id]);
		}
	}

	private function acquireLock(string $lock): void
	{
		if ($this->lockingProvider === null) {
			return;
		}
		try {
			DbLockGuard::acquire($this->lockingProvider, $lock, ILockingProvider::LOCK_EXCLUSIVE, 'Holiday rule mutation');
		} catch (LockedException) {
			throw new BusinessRuleException(
				'The holiday catalog is currently locked by another operation. Please try again.',
				'locked'
			);
		}
	}

	private function releaseLock(string $lock): void
	{
		if ($this->lockingProvider !== null) {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
