<?php

declare(strict_types=1);

/**
 * DACH preset contract (spec §10.2–10.5): every canonical preset must be
 * schema-valid, carry provenance, stay conservative (no silent travel
 * credit), and exist for exactly the built-in jurisdictions.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator;
use OCA\ArbeitszeitCheck\Service\BusinessTripRulePresets;
use PHPUnit\Framework\TestCase;

final class BusinessTripRulePresetsTest extends TestCase
{
	public function testAllBuiltinJurisdictionsHavePresets(): void
	{
		$all = BusinessTripRulePresets::all();
		foreach ([BusinessTripRuleSet::JURISDICTION_DE, BusinessTripRuleSet::JURISDICTION_AT, BusinessTripRuleSet::JURISDICTION_CH] as $j) {
			$this->assertArrayHasKey($j, $all);
		}
		// `custom` has no preset — that is the hand-authored path.
		$this->assertArrayNotHasKey(BusinessTripRuleSet::JURISDICTION_CUSTOM, $all);
	}

	public function testEveryPresetConfigPassesSchemaValidation(): void
	{
		$validator = new BusinessTripRuleConfigValidator();
		foreach (BusinessTripRulePresets::all() as $jurisdiction => $preset) {
			$validated = $validator->validate(json_encode($preset['config']));
			$this->assertSame(1, $validated['schemaVersion'], $jurisdiction);
		}
	}

	public function testPresetsCarryProvenance(): void
	{
		foreach (BusinessTripRulePresets::all() as $jurisdiction => $preset) {
			$p = $preset['provenance'];
			$this->assertNotSame('', (string)($p['sourceTitle'] ?? ''), $jurisdiction);
			$this->assertNotSame('', (string)($p['sourceReference'] ?? ''), $jurisdiction);
			$this->assertGreaterThan(0, (int)($p['classifierSchemaVersion'] ?? 0), $jurisdiction);
		}
	}

	public function testPresetsAreConservative(): void
	{
		foreach (BusinessTripRulePresets::all() as $jurisdiction => $preset) {
			$config = $preset['config'];
			// No automatic top-up until a legally reviewed company policy sets
			// real thresholds — presets must never silently credit travel time.
			$targets = array_column($config['thresholds'], 'targetMinutes');
			$this->assertSame([0], array_values(array_unique($targets)), $jurisdiction);
			// Actual work always counts fully — never below 100%.
			$this->assertSame(10000, $config['accountTreatment']['actualWorkFactorBps'], $jurisdiction);
		}
		// DE: without a released rule, passive travel must surface uncertainty.
		$de = BusinessTripRulePresets::forJurisdiction('DE')['config'];
		$this->assertTrue($de['requiresClassification']);
		$this->assertFalse($de['passiveTravelLegallyReleased']);
		// CH: travel factors stay at zero — only excess commute classifies.
		$ch = BusinessTripRulePresets::forJurisdiction('CH')['config'];
		$this->assertSame(0, $ch['accountTreatment']['activeTravelFactorBps']);
		$this->assertSame(0, $ch['accountTreatment']['passiveTravelFactorBps']);
	}

	public function testUnknownJurisdictionFailsClosed(): void
	{
		try {
			BusinessTripRulePresets::forJurisdiction('XX');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(404, $e->httpStatus());
		}
	}
}
