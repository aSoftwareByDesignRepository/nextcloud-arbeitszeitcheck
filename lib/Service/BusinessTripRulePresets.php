<?php

declare(strict_types=1);

/**
 * Canonical DACH rule-set presets (spec §10.2–10.5).
 *
 * Each preset is a conservative starting point — it hard-codes the
 * classification posture the law dictates (uncertainty surfaces as
 * `classification_required`, never a silent zero) while leaving company
 * policy (account targets, factor weighting) deliberately neutral: the
 * empty top-up threshold credits nothing automatically. Presets always
 * materialize as DRAFT rule sets; activation additionally requires the
 * recorded legal review (RULE_REVIEW_REQUIRED gate in the catalog).
 *
 * Provenance fields satisfy §10.5's Quellen-/Versionspflicht.
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;

final class BusinessTripRulePresets
{
	public const CLASSIFIER_SCHEMA_VERSION = 1;

	/**
	 * @return array<string,array{code:string,name:string,provenance:array<string,mixed>,config:array<string,mixed>}>
	 */
	public static function all(): array
	{
		$accountNeutral = [
			'actualWorkFactorBps' => 10000,
			'activeTravelFactorBps' => 10000,
			'passiveTravelFactorBps' => 0,
			'workDuringTravelFactorBps' => 10000,
		];
		$noTopUp = [['fromMinutes' => 0, 'targetMinutes' => 0]];

		return [
			BusinessTripRuleSet::JURISDICTION_DE => [
				'code' => 'de-default',
				'name' => 'Germany (ArbZG, conservative)',
				'provenance' => [
					'sourceTitle' => 'ArbZG + BAG case law on travel time',
					'sourceReference' => 'ArbZG §§2, 5, 7; BAG travel-time rulings; EuGH Tyco C-266/14',
					'classifierSchemaVersion' => self::CLASSIFIER_SCHEMA_VERSION,
				],
				'config' => [
					'schemaVersion' => 1,
					'evaluationTimezone' => 'organization',
					'dayBasis' => 'trip_presence',
					'operation' => 'minimum',
					'compareAgainst' => 'actual_accountable_minutes',
					'thresholds' => $noTopUp,
					'accountTreatment' => $accountNeutral,
					'export' => ['mode' => 'compliance'],
					// §10.2: without a legally released company rule, active and
					// passive travel surface as classification_required — never
					// silently classified as rest.
					'requiresClassification' => true,
					'activeTravelLegallyReleased' => false,
					'passiveTravelLegallyReleased' => false,
				],
			],
			BusinessTripRuleSet::JURISDICTION_AT => [
				'code' => 'at-default',
				'name' => 'Austria (AZG, conservative)',
				'provenance' => [
					'sourceTitle' => 'AZG / Arbeiterkammer travel-time guidance',
					'sourceReference' => 'AZG; AK information on active vs passive Reisezeit',
					'classifierSchemaVersion' => self::CLASSIFIER_SCHEMA_VERSION,
				],
				'config' => [
					'schemaVersion' => 1,
					'evaluationTimezone' => 'organization',
					'dayBasis' => 'trip_presence',
					'operation' => 'minimum',
					'compareAgainst' => 'actual_accountable_minutes',
					'thresholds' => $noTopUp,
					// §10.3: active travel is full work performance; passive is
					// remunerated separately — account factors stay neutral and
					// the export keeps the classes separate.
					'accountTreatment' => [
						'actualWorkFactorBps' => 10000,
						'activeTravelFactorBps' => 10000,
						'passiveTravelFactorBps' => 0,
						'workDuringTravelFactorBps' => 10000,
					],
					'export' => [
						'mode' => 'separate_factors',
						'activeTravelFactorBps' => 10000,
						'passiveTravelFactorBps' => 10000,
						'workDuringTravelFactorBps' => 10000,
					],
					'requiresClassification' => false,
					'activeTravelLegallyReleased' => true,
					'passiveTravelLegallyReleased' => true,
				],
			],
			BusinessTripRuleSet::JURISDICTION_CH => [
				'code' => 'ch-default',
				'name' => 'Switzerland (ArGV 1, conservative)',
				'provenance' => [
					'sourceTitle' => 'SECO ArGV 1 Art. 13 (Wegzeit)',
					'sourceReference' => 'ArGV 1 Art. 13; SECO Wegleitungen',
					'classifierSchemaVersion' => self::CLASSIFIER_SCHEMA_VERSION,
				],
				'config' => [
					'schemaVersion' => 1,
					'evaluationTimezone' => 'organization',
					'dayBasis' => 'trip_presence',
					'operation' => 'minimum',
					'compareAgainst' => 'actual_accountable_minutes',
					'thresholds' => $noTopUp,
					// §10.4: normal Wegzeit is not working time — only the
					// excess over the recorded normal commute classifies, so
					// travel itself never auto-credits.
					'accountTreatment' => [
						'actualWorkFactorBps' => 10000,
						'activeTravelFactorBps' => 0,
						'passiveTravelFactorBps' => 0,
						'workDuringTravelFactorBps' => 10000,
					],
					'export' => ['mode' => 'compliance'],
					// Missing normal-commute profile blocks finalization with an
					// explainable input request (spec §10.4).
					'requiresClassification' => true,
					'activeTravelLegallyReleased' => false,
					'passiveTravelLegallyReleased' => false,
				],
			],
		];
	}

	/**
	 * @return array{code:string,name:string,provenance:array<string,mixed>,config:array<string,mixed>}
	 */
	public static function forJurisdiction(string $jurisdiction): array
	{
		$all = self::all();
		if (!isset($all[$jurisdiction])) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'No preset for this jurisdiction.', 404, ['jurisdiction' => 'invalid']);
		}
		return $all[$jurisdiction];
	}
}
