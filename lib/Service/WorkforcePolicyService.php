<?php

declare(strict_types=1);

/**
 * Optional workforce policy store (SPEC optional-absence-corrections-hourly-leave-year-end).
 *
 * Owns the company opt-ins for retrospective absence corrections, hourly
 * built-in absence types and year-end balance resets. Storage is the
 * existing app-config mechanism: one normalized JSON document plus a
 * monotonic revision used for settings CAS, preview binding and audits.
 *
 * Hard rules (§9.1 / §12):
 * - everything off by default; absent document == pre-feature behavior;
 * - dependent switches require their parent (requests/delegation need
 *   corrections; annual needs manual + ≥1 sign + ≥1 existing group);
 * - strict boolean parsing ('0'/'1', true/false, 'true'/'false' only);
 * - save requires the current revision (missing/stale → 409);
 * - disabling never reinterprets stored records or erases results.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException;
use OCA\ArbeitszeitCheck\Support\WorkforcePolicy;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;

class WorkforcePolicyService
{
	public const ERROR_DISABLED = 'FEATURE_DISABLED';
	public const ERROR_POLICY_INVALID = 'POLICY_INVALID';
	public const ERROR_REVISION_CONFLICT = 'REVISION_CONFLICT';
	public const ERROR_POLICY_CHANGED = 'POLICY_CHANGED';
	public const ERROR_SELF_APPROVAL = 'SELF_APPROVAL_FORBIDDEN';

	public const MAX_TARGET_GROUPS = 100;
	public const MAX_GROUP_ID_LENGTH = 255;

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly TimeZoneService $timeZoneService,
		private readonly IL10N $l10n,
		private readonly ?FinancialRevisionService $financialRevisionService = null,
	) {
	}

	public function get(): WorkforcePolicy
	{
		$raw = $this->appConfig->getAppValueString(Constants::CONFIG_WORKFORCE_POLICY_JSON, '');
		if ($raw === '') {
			return WorkforcePolicy::defaults($this->timeZoneService->storageTimeZoneName());
		}
		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return WorkforcePolicy::defaults($this->timeZoneService->storageTimeZoneName());
		}
		if (!is_array($decoded)) {
			return WorkforcePolicy::defaults($this->timeZoneService->storageTimeZoneName());
		}
		return WorkforcePolicy::fromArray($decoded, $this->timeZoneService->storageTimeZoneName());
	}

	public function revision(): int
	{
		return (int)$this->appConfig->getAppValueString(Constants::CONFIG_WORKFORCE_POLICY_REVISION, '0');
	}

	// ---------------------------------------------------------------
	// Capability predicates — read path; every write re-checks.
	// ---------------------------------------------------------------

	public function isPastCorrectionEnabled(): bool
	{
		return $this->get()->pastCorrectionEnabled;
	}

	public function isEmployeeCorrectionRequestEnabled(): bool
	{
		$p = $this->get();
		return $p->pastCorrectionEnabled && $p->employeeCorrectionRequests;
	}

	public function isScopedManagerCorrectionEnabled(): bool
	{
		$p = $this->get();
		return $p->pastCorrectionEnabled && $p->scopedManagerCorrectionApproval;
	}

	public function isHourlyAbsenceTypeEnabled(string $type): bool
	{
		return $this->get()->isHourlyTypeEnabled($type);
	}

	public function isYearEndManualEnabled(): bool
	{
		return $this->get()->yearEndManualEnabled;
	}

	public function isYearEndAnnualEnabled(): bool
	{
		$p = $this->get();
		return $p->yearEndManualEnabled && $p->yearEndAnnualEnabled;
	}

	public function capabilities(bool $isAdmin, bool $isManager): array
	{
		return $this->get()->capabilities($isAdmin, $isManager);
	}

	/**
	 * Throw when a gated write is attempted with the capability off.
	 *
	 * @throws AdminUserProfileUpdateException 403 FEATURE_DISABLED
	 */
	public function assertEnabled(bool $enabled): void
	{
		if (!$enabled) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Your organization has not enabled this action.'),
				403,
				[],
				self::ERROR_DISABLED,
			);
		}
	}

	// ---------------------------------------------------------------
	// Save (admin only — authorization lives in the controller)
	// ---------------------------------------------------------------

	/**
	 * Strict boolean parse: accepts bool, '0'/'1', 'true'/'false' (and the
	 * integer forms inside JSON bodies). Anything else is a field error —
	 * never silently coerced (NN-07).
	 */
	private function strictBool(mixed $value, string $field, array &$errors): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if ($value === 1 || $value === 0) {
			return $value === 1;
		}
		if (is_string($value)) {
			$v = strtolower(trim($value));
			if ($v === '1' || $v === 'true') {
				return true;
			}
			if ($v === '0' || $v === 'false') {
				return false;
			}
		}
		$errors[$field] = $this->l10n->t('Must be true or false.');
		return false;
	}

	/**
	 * Fail-closed optimistic lock: the request's workforcePolicyRevision
	 * token must equal the stored revision or the write aborts with 409.
	 * Controllers call this before any sibling writes; save() re-checks
	 * inside its own path so the compare stays atomic with the write.
	 *
	 * @param array<string,mixed> $params
	 */
	public function assertRevision(array $params): void
	{
		$expectedRaw = $params['workforcePolicyRevision'] ?? null;
		$expected = is_int($expectedRaw) ? $expectedRaw
			: (is_string($expectedRaw) && preg_match('/^\d+$/', trim($expectedRaw)) === 1 ? (int)trim($expectedRaw) : null);
		if ($expected === null || $expected !== $this->revision()) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('The company policy changed. Review the action again.'),
				409,
				['workforcePolicyRevision' => $this->l10n->t('Reload the settings before saving.')],
				self::ERROR_REVISION_CONFLICT,
			);
		}
	}

	/**
	 * Validate + persist a policy save. Requires the CURRENT revision —
	 * missing or stale revisions are a 409, never a silent overwrite of
	 * another admin's change (AC-02/03).
	 *
	 * @param array<string,mixed> $params flat settings payload
	 * @return array{policy: array<string,mixed>, revision: int}
	 * @throws AdminUserProfileUpdateException 409 / 422
	 */
	public function save(array $params, string $actor): array
	{
		$current = $this->get();
		$currentRevision = $this->revision();

		$this->assertRevision($params);

		$errors = [];
		$bool = fn (string $key, bool $fallback = false): bool => array_key_exists($key, $params)
			? $this->strictBool($params[$key], $key, $errors)
			: $fallback;

		$hasPast = array_key_exists('pastCorrectionEnabled', $params)
			|| array_key_exists('employeeCorrectionRequestsEnabled', $params)
			|| array_key_exists('scopedManagerCorrectionApprovalEnabled', $params);
		$hasHourly = array_key_exists('hourlyAbsenceTypes', $params)
			|| array_key_exists('hourlyUnpaidLeaveEnabled', $params)
			|| array_key_exists('hourlyOvertimeCompEnabled', $params);
		$hasYearEnd = array_key_exists('yearEndManualEnabled', $params)
			|| array_key_exists('yearEndAnnualEnabled', $params)
			|| array_key_exists('yearEndResetPositive', $params)
			|| array_key_exists('yearEndWaiveNegative', $params)
			|| array_key_exists('yearEndTargetGroupIds', $params);

		// Merge semantics: only submitted sections change; the rest keeps the
		// stored values so a single-section save cannot silently clear others.
		$pastEnabled = $hasPast ? $bool('pastCorrectionEnabled') : $current->pastCorrectionEnabled;
		$empRequests = $hasPast ? $bool('employeeCorrectionRequestsEnabled') : $current->employeeCorrectionRequests;
		$managerApproval = $hasPast ? $bool('scopedManagerCorrectionApprovalEnabled') : $current->scopedManagerCorrectionApproval;

		$hourly = $current->hourlyAbsenceTypes;
		if ($hasHourly) {
			if (array_key_exists('hourlyAbsenceTypes', $params)) {
				$raw = $params['hourlyAbsenceTypes'];
				if (is_string($raw)) {
					$decoded = json_decode($raw, true);
					$raw = is_array($decoded) ? $decoded
						: array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== '');
				}
				if (!is_array($raw)) {
					$errors['hourlyAbsenceTypes'] = $this->l10n->t('Must be a list of absence types.');
					$hourly = [];
				} else {
					$hourly = [];
					foreach ($raw as $t) {
						$t = is_string($t) ? trim($t) : '';
						if (!in_array($t, WorkforcePolicy::HOURLY_TYPES, true)) {
							$errors['hourlyAbsenceTypes'] = $this->l10n->t('Only the built-in unpaid-leave and compensatory-time-off types support hour requests.');
							continue;
						}
						if (!in_array($t, $hourly, true)) {
							$hourly[] = $t;
						}
					}
				}
			} else {
				$hourly = [];
				if ($bool('hourlyUnpaidLeaveEnabled')) {
					$hourly[] = Absence::TYPE_UNPAID_LEAVE;
				}
				if ($bool('hourlyOvertimeCompEnabled')) {
					$hourly[] = Absence::TYPE_OVERTIME_COMP;
				}
			}
		}

		$yeManual = $hasYearEnd ? $bool('yearEndManualEnabled') : $current->yearEndManualEnabled;
		$yeAnnual = $hasYearEnd ? $bool('yearEndAnnualEnabled') : $current->yearEndAnnualEnabled;
		$yePositive = $hasYearEnd ? $bool('yearEndResetPositive') : $current->yearEndResetPositive;
		$yeNegative = $hasYearEnd ? $bool('yearEndWaiveNegative') : $current->yearEndWaiveNegative;
		$yeGroups = $current->yearEndTargetGroupIds;
		if ($hasYearEnd && array_key_exists('yearEndTargetGroupIds', $params)) {
			$raw = $params['yearEndTargetGroupIds'];
			if (is_string($raw)) {
				$decoded = json_decode($raw, true);
				$raw = is_array($decoded) ? $decoded
					: array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== '');
			}
			if (!is_array($raw)) {
				$errors['yearEndTargetGroupIds'] = $this->l10n->t('Must be a list of groups.');
				$yeGroups = [];
			} else {
				$yeGroups = [];
				foreach ($raw as $gid) {
					$gid = is_string($gid) ? trim($gid) : '';
					if ($gid === '' || mb_strlen($gid) > self::MAX_GROUP_ID_LENGTH) {
						$errors['yearEndTargetGroupIds'] = $this->l10n->t('Group names must be 1–255 characters.');
						continue;
					}
					if (!in_array($gid, $yeGroups, true)) {
						$yeGroups[] = $gid;
					}
				}
				if (count($yeGroups) > self::MAX_TARGET_GROUPS) {
					$errors['yearEndTargetGroupIds'] = $this->l10n->t('At most %d groups can be selected.', [self::MAX_TARGET_GROUPS]);
				}
			}
		}

		// Dependency validation — never silently enable implied switches and
		// never silently disable stored dependents; require explicit values.
		if ($empRequests || $managerApproval) {
			if (!$pastEnabled) {
				$errors['pastCorrectionEnabled'] = $this->l10n->t('Enable completed-absence corrections to use requests or delegated approval.');
			}
		}
		if ($yeAnnual) {
			if (!$yeManual) {
				$errors['yearEndAnnualEnabled'] = $this->l10n->t('Enable manual year-end processing before scheduling it annually.');
			}
			if (!$yePositive && !$yeNegative) {
				$errors['yearEndResetPositive'] = $this->l10n->t('Choose at least one sign for the annual reset.');
			}
			if ($yeGroups === []) {
				$errors['yearEndTargetGroupIds'] = $this->l10n->t('Choose at least one target group for the annual reset.');
			}
		}
		foreach ($yeGroups as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				$errors['yearEndTargetGroupIds'] = $this->l10n->t('The group "%s" does not exist.', [$gid]);
				break;
			}
		}

		if ($errors !== []) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Review the highlighted policy settings.'),
				422,
				$errors,
				self::ERROR_POLICY_INVALID,
			);
		}

		$next = new WorkforcePolicy(
			$pastEnabled,
			$empRequests,
			$managerApproval,
			$hourly,
			$yeManual,
			$yeAnnual,
			$yePositive,
			$yeNegative,
			$yeGroups,
			$this->timeZoneService->storageTimeZoneName(),
		);

		$nextRevision = $currentRevision + 1;
		$this->appConfig->setAppValueString(
			Constants::CONFIG_WORKFORCE_POLICY_JSON,
			json_encode($next->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
		);
		$this->appConfig->setAppValueString(Constants::CONFIG_WORKFORCE_POLICY_REVISION, (string)$nextRevision);

		// Global policy epoch bump (§9.6): every policy change is a global
		// financial input — year-end preview sources holding the previous
		// epoch become needs_review without an unbounded per-user fan-out.
		if ($next->toArray() !== $current->toArray() && $this->financialRevisionService !== null) {
			$this->financialRevisionService->bumpEpoch(FinancialRevisionService::EPOCH_POLICY);
		}

		if ($next->toArray() !== $current->toArray()) {
			$this->auditLogMapper->logAction(
				$actor,
				Constants::AUDIT_ACTION_WORKFORCE_POLICY_CHANGED,
				'app_config',
				null,
				$current->toArray(),
				array_merge($next->toArray(), ['revision' => $nextRevision]),
				$actor
			);
		}

		return ['policy' => $next->toArray(), 'revision' => $nextRevision];
	}

	/**
	 * Annual-run due evaluation: next January 1 03:00 in the stored
	 * timezone closing the previous calendar year. Due check uses local
	 * date/time, never fragile cron-minute equality (§9.5).
	 *
	 * @return array{due: bool, closing_year: int|null, due_at: string, catch_up_until: string}
	 */
	public function annualDueState(?\DateTimeInterface $now = null): array
	{
		$policy = $this->get();
		$tz = new \DateTimeZone($policy->yearEndTimezone !== ''
			? $policy->yearEndTimezone
			: $this->timeZoneService->storageTimeZoneName());
		$now = $now !== null
			? \DateTimeImmutable::createFromInterface($now)->setTimezone($tz)
			: new \DateTimeImmutable('now', $tz);
		$year = (int)$now->format('Y');

		$dueAt = new \DateTimeImmutable(sprintf('%04d-01-01 03:00:00', $year), $tz);
		$catchUpEnd = new \DateTimeImmutable(sprintf('%04d-01-07 23:59:59', $year), $tz);

		$enabled = $policy->yearEndManualEnabled && $policy->yearEndAnnualEnabled;
		$due = $enabled && $now >= $dueAt && $now <= $catchUpEnd;

		return [
			'due' => $due,
			'closing_year' => $due ? $year - 1 : null,
			'due_at' => $dueAt->format('c'),
			'catch_up_until' => $catchUpEnd->format('c'),
		];
	}
}
