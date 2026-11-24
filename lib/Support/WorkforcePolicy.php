<?php

declare(strict_types=1);

/**
 * Optional workforce policy (SPEC optional-absence-corrections-hourly-leave-year-end §9.1).
 *
 * Immutable normalized value object. Every switch defaults OFF — flags
 * absent or unset must produce identical behavior to the pre-feature app
 * (AS-05). Field validation lives in WorkforcePolicyService; this object
 * only canonicalizes a trusted normalized array.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Db\Absence;

final class WorkforcePolicy
{
	public const HOURLY_TYPES = [
		Absence::TYPE_UNPAID_LEAVE,
		Absence::TYPE_OVERTIME_COMP,
	];

	private function __construct(
		public readonly bool $pastCorrectionEnabled,
		public readonly bool $employeeCorrectionRequests,
		public readonly bool $scopedManagerCorrectionApproval,
		/** @var list<string> subset of HOURLY_TYPES */
		public readonly array $hourlyAbsenceTypes,
		public readonly bool $yearEndManualEnabled,
		public readonly bool $yearEndAnnualEnabled,
		public readonly bool $yearEndResetPositive,
		public readonly bool $yearEndWaiveNegative,
		/** @var list<string> Nextcloud group ids */
		public readonly array $yearEndTargetGroupIds,
		/** Storage-timezone snapshot — never a free-form second timezone. */
		public readonly string $yearEndTimezone,
	) {
	}

	public static function defaults(string $storageTimezone): self
	{
		return new self(
			false, false, false, [],
			false, false, false, false, [],
			$storageTimezone,
		);
	}

	/**
	 * @param array<string,mixed> $raw stored normalized array (tolerates missing keys)
	 */
	public static function fromArray(array $raw, string $storageTimezone): self
	{
		$past = is_array($raw['pastCancellation'] ?? null) ? $raw['pastCancellation'] : [];
		$ye = is_array($raw['yearEnd'] ?? null) ? $raw['yearEnd'] : [];
		$hourly = $raw['hourlyAbsenceTypes'] ?? [];
		if (!is_array($hourly)) {
			$hourly = [];
		}
		$hourly = array_values(array_intersect(
			array_map('strval', $hourly),
			self::HOURLY_TYPES,
		));

		$groups = $ye['targetGroupIds'] ?? [];
		if (!is_array($groups)) {
			$groups = [];
		}
		$groups = array_values(array_filter(array_map(
			static fn (mixed $g): string => is_string($g) ? trim($g) : '',
			$groups,
		), static fn (string $g): bool => $g !== ''));

		$tz = $ye['timezone'] ?? '';
		return new self(
			(bool)($past['enabled'] ?? false),
			(bool)($past['employeeRequests'] ?? false),
			(bool)($past['scopedManagerApproval'] ?? false),
			array_values(array_unique($hourly)),
			(bool)($ye['manualEnabled'] ?? false),
			(bool)($ye['annualEnabled'] ?? false),
			(bool)($ye['resetPositive'] ?? false),
			(bool)($ye['waiveNegative'] ?? false),
			$groups,
			is_string($tz) && $tz !== '' ? $tz : $storageTimezone,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'pastCancellation' => [
				'enabled' => $this->pastCorrectionEnabled,
				'employeeRequests' => $this->employeeCorrectionRequests,
				'scopedManagerApproval' => $this->scopedManagerCorrectionApproval,
			],
			'hourlyAbsenceTypes' => $this->hourlyAbsenceTypes,
			'yearEnd' => [
				'manualEnabled' => $this->yearEndManualEnabled,
				'annualEnabled' => $this->yearEndAnnualEnabled,
				'resetPositive' => $this->yearEndResetPositive,
				'waiveNegative' => $this->yearEndWaiveNegative,
				'targetGroupIds' => $this->yearEndTargetGroupIds,
				'timezone' => $this->yearEndTimezone,
			],
		];
	}

	public function isHourlyTypeEnabled(string $type): bool
	{
		return in_array($type, $this->hourlyAbsenceTypes, true);
	}

	/**
	 * Role-scoped capability view exposed to clients (GET/bootstrap parity,
	 * ui-api-client-contract §4). Capabilities are UX hints only — the
	 * server re-checks every gate on write.
	 *
	 * @return array<string,mixed>
	 */
	public function capabilities(bool $isAdmin, bool $isManager): array
	{
		return [
			'pastCorrectionEnabled' => $this->pastCorrectionEnabled,
			'employeeCorrectionRequestsEnabled' => $this->pastCorrectionEnabled && $this->employeeCorrectionRequests,
			'scopedManagerCorrectionApprovalEnabled' => $isManager && $this->pastCorrectionEnabled && $this->scopedManagerCorrectionApproval,
			'hourlyAbsenceTypes' => $this->hourlyAbsenceTypes,
			'yearEndAdminEnabled' => $isAdmin && $this->yearEndManualEnabled,
			'yearEndAnnualEnabled' => $isAdmin && $this->yearEndAnnualEnabled,
			'absenceContractVersion' => 2,
		];
	}
}
