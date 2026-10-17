<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTripSegment;

/**
 * Jurisdiction compliance classifier (spec §10) — the single source of
 * `compliance_minutes`. Conservative by design: when a profile cannot
 * classify a fact class, it raises `classificationRequired` rather than
 * silently returning zero.
 *
 * Output per local day:
 *   complianceMinutes       minutes counting as Arbeitszeit under the profile
 *   classificationRequired  true when facts are insufficient for a verdict
 *   uncertainties           list<non-empty string codes>
 */
final class TravelComplianceClassifier
{
	public const PROFILE_DE = 'DE';
	public const PROFILE_AT = 'AT';
	public const PROFILE_CH = 'CH';
	public const PROFILE_CUSTOM = 'custom';

	public const PROFILES = [self::PROFILE_DE, self::PROFILE_AT, self::PROFILE_CH, self::PROFILE_CUSTOM];

	/**
	 * @param int $actualWorkMinutes  real worked minutes from linked TimeEntries
	 * @param int $activeTravelMinutes
	 * @param int $passiveTravelMinutes
	 * @param int $workDuringTravelMinutes
	 * @param ?int $normalCommuteMinutes  CH only; null when no profile row exists
	 * @param bool $activeTravelLegallyReleased  profile config: active travel
	 *        counts as work only under a legally released agreement
	 * @param bool $passiveTravelLegallyReleased  same for passive travel
	 * @return array{complianceMinutes:int, classificationRequired:bool, uncertainties:list<string>}
	 */
	public function classify(
		string $jurisdiction,
		int $actualWorkMinutes,
		int $activeTravelMinutes,
		int $passiveTravelMinutes,
		int $workDuringTravelMinutes,
		?int $normalCommuteMinutes = null,
		bool $activeTravelLegallyReleased = false,
		bool $passiveTravelLegallyReleased = false,
	): array {
		return match ($jurisdiction) {
			self::PROFILE_DE => $this->de($actualWorkMinutes, $activeTravelMinutes, $passiveTravelMinutes, $workDuringTravelMinutes, $activeTravelLegallyReleased, $passiveTravelLegallyReleased),
			self::PROFILE_AT => $this->at($actualWorkMinutes, $activeTravelMinutes, $passiveTravelMinutes, $workDuringTravelMinutes),
			self::PROFILE_CH => $this->ch($actualWorkMinutes, $activeTravelMinutes, $passiveTravelMinutes, $workDuringTravelMinutes, $normalCommuteMinutes),
			default => $this->custom($actualWorkMinutes, $activeTravelMinutes, $workDuringTravelMinutes),
		};
	}

	/**
	 * DE (§10.2): actual work + work during travel always count; active travel
	 * counts only under a legally released profile; passive travel may never be
	 * auto-classified as rest — missing release flags classification.
	 */
	private function de(int $work, int $active, int $passive, int $during, bool $activeReleased, bool $passiveReleased): array
	{
		$minutes = $work + $during + ($activeReleased ? $active : 0) + ($passiveReleased ? $passive : 0);
		$uncertainties = [];
		if ($active > 0 && !$activeReleased) {
			$uncertainties[] = 'active_travel_unclassified';
		}
		if ($passive > 0 && !$passiveReleased) {
			$uncertainties[] = 'passive_travel_unclassified';
		}
		return [
			'complianceMinutes' => $minutes,
			'classificationRequired' => $uncertainties !== [],
			'uncertainties' => $uncertainties,
		];
	}

	/**
	 * AT (§10.3): active travel is full work performance; passive travel stays
	 * a separate fact and counts as work time under the AZG profile while its
	 * remuneration stays a different question (export class, not minutes).
	 */
	private function at(int $work, int $active, int $passive, int $during): array
	{
		return [
			'complianceMinutes' => $work + $during + $active + $passive,
			'classificationRequired' => false,
			'uncertainties' => $passive > 0 ? ['passive_travel_remuneration_unsettled'] : [],
		];
	}

	/**
	 * CH (§10.4, SECO ArGV 1 Art. 13): only the part of travel exceeding the
	 * normal commute counts. Missing `normal_commute_minutes` blocks
	 * finalization with an explainable input requirement.
	 */
	private function ch(int $work, int $active, int $passive, int $during, ?int $commute): array
	{
		$travel = $active + $passive;
		if ($travel > 0 && $commute === null) {
			return [
				'complianceMinutes' => $work + $during,
				'classificationRequired' => true,
				'uncertainties' => ['normal_commute_missing'],
			];
		}
		$extraCommute = max(0, $travel - (int)$commute);
		return [
			'complianceMinutes' => $work + $during + $extraCommute,
			'classificationRequired' => false,
			'uncertainties' => [],
		];
	}

	/** Custom profiles: only real work is compliance-relevant by default. */
	private function custom(int $work, int $active, int $during): array
	{
		return [
			'complianceMinutes' => $work + $during,
			'classificationRequired' => false,
			'uncertainties' => $active > 0 ? ['custom_profile_travel_unclassified'] : [],
		];
	}
}
