<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\PremiumPolicy;
use OCA\ArbeitszeitCheck\Support\PremiumSurchargeClassifier;
use PHPUnit\Framework\TestCase;

class PremiumSurchargeClassifierTest extends TestCase
{
	private function tz(): \DateTimeZone
	{
		return new \DateTimeZone('Europe/Vienna');
	}

	private function interval(string $start, string $end): array
	{
		$tz = $this->tz();
		return [
			new \DateTimeImmutable($start, $tz),
			new \DateTimeImmutable($end, $tz),
		];
	}

	public function testSundayTwoHoursAt100Percent(): void
	{
		$policy = PremiumPolicy::fromValidated(PremiumPolicy::atStarterPreset());
		$classifier = new PremiumSurchargeClassifier();
		// Sunday 2026-08-09 10:00–12:00
		$result = $classifier->classify(
			[$this->interval('2026-08-09 10:00:00', '2026-08-09 12:00:00')],
			$policy,
			static fn (): bool => false,
			static fn (): float => 8.0,
		);
		$sunday = null;
		foreach ($result['buckets'] as $b) {
			if ($b['id'] === 'sunday') {
				$sunday = $b;
			}
		}
		$this->assertNotNull($sunday);
		$this->assertEqualsWithDelta(2.0, $sunday['hours'], 0.01);
		$this->assertEqualsWithDelta(1.0, $sunday['rate'], 0.001);
	}

	public function testSaturdayFourHours(): void
	{
		$policy = PremiumPolicy::fromValidated(PremiumPolicy::atStarterPreset());
		$classifier = new PremiumSurchargeClassifier();
		// Saturday 2026-08-08 08:00–12:00
		$result = $classifier->classify(
			[$this->interval('2026-08-08 08:00:00', '2026-08-08 12:00:00')],
			$policy,
			static fn (): bool => false,
			static fn (): float => 8.0,
		);
		$sat = null;
		foreach ($result['buckets'] as $b) {
			if ($b['id'] === 'saturday') {
				$sat = $b;
			}
		}
		$this->assertNotNull($sat);
		$this->assertEqualsWithDelta(4.0, $sat['hours'], 0.01);
	}

	public function testNightWindowSplitMondayEvening(): void
	{
		$policy = PremiumPolicy::fromValidated(PremiumPolicy::atStarterPreset());
		$classifier = new PremiumSurchargeClassifier();
		// Monday 2026-08-03 21:00–23:00 → 1h base (no premium), 1h night
		$result = $classifier->classify(
			[$this->interval('2026-08-03 21:00:00', '2026-08-03 23:00:00')],
			$policy,
			static fn (): bool => false,
			static fn (): float => 8.0,
		);
		$night = null;
		foreach ($result['buckets'] as $b) {
			if ($b['id'] === 'night') {
				$night = $b;
			}
		}
		$this->assertNotNull($night);
		$this->assertEqualsWithDelta(1.0, $night['hours'], 0.01);
	}

	public function testMaxSingleRatePrefersSundayOverNight(): void
	{
		$raw = PremiumPolicy::atStarterPreset();
		$raw['stacking'] = PremiumPolicy::STACKING_MAX_SINGLE;
		$policy = PremiumPolicy::fromValidated($raw);
		$classifier = new PremiumSurchargeClassifier();
		// Sunday night 2026-08-09 22:00–23:00 — sunday 100% > night 50%
		$result = $classifier->classify(
			[$this->interval('2026-08-09 22:00:00', '2026-08-09 23:00:00')],
			$policy,
			static fn (): bool => false,
			static fn (): float => 8.0,
		);
		$byId = [];
		foreach ($result['buckets'] as $b) {
			$byId[$b['id']] = $b['hours'];
		}
		$this->assertEqualsWithDelta(1.0, $byId['sunday'] ?? 0.0, 0.01);
		$this->assertArrayNotHasKey('night', $byId);
	}

	public function testOvertimeAboveDailyTarget(): void
	{
		$policy = PremiumPolicy::fromValidated(PremiumPolicy::atStarterPreset());
		$classifier = new PremiumSurchargeClassifier();
		// Monday 07:00–17:00 = 10h; daily soll 8 → 2h OT (after 15:00)
		$result = $classifier->classify(
			[$this->interval('2026-08-03 07:00:00', '2026-08-03 17:00:00')],
			$policy,
			static fn (): bool => false,
			static fn (): float => 8.0,
		);
		$ot = null;
		foreach ($result['buckets'] as $b) {
			if ($b['id'] === 'overtime_base') {
				$ot = $b;
			}
		}
		$this->assertNotNull($ot);
		$this->assertEqualsWithDelta(2.0, $ot['hours'], 0.01);
	}

	public function testDisabledPremiumCategoriesIgnored(): void
	{
		$raw = PremiumPolicy::atStarterPreset();
		foreach ($raw['categories'] as &$cat) {
			$cat['enabled'] = false;
		}
		unset($cat);
		$policy = PremiumPolicy::fromValidated($raw);
		$classifier = new PremiumSurchargeClassifier();
		$result = $classifier->classify(
			[$this->interval('2026-08-09 10:00:00', '2026-08-09 12:00:00')],
			$policy,
			static fn (): bool => false,
			static fn (): float => 8.0,
		);
		$this->assertSame([], $result['buckets']);
	}

	public function testAdditiveRatesSumValuedHoursForSundayNight(): void
	{
		$raw = PremiumPolicy::atStarterPreset();
		$raw['stacking'] = PremiumPolicy::STACKING_ADDITIVE;
		$policy = PremiumPolicy::fromValidated($raw);
		$classifier = new PremiumSurchargeClassifier();
		$result = $classifier->classify(
			[$this->interval('2026-08-09 22:00:00', '2026-08-09 23:00:00')],
			$policy,
			static fn (): bool => false,
			static fn (): float => 8.0,
		);
		$byId = [];
		foreach ($result['buckets'] as $b) {
			$byId[$b['id']] = $b;
		}
		$this->assertEqualsWithDelta(1.0, $byId['sunday']['hours'] ?? 0.0, 0.01);
		$this->assertEqualsWithDelta(1.0, $byId['night']['hours'] ?? 0.0, 0.01);
		// Unique wall clock = 1h; valued = 1.0 + 0.5
		$this->assertEqualsWithDelta(1.0, $result['total_classified_hours'], 0.01);
		$this->assertEqualsWithDelta(1.5, $result['total_valued_hours'], 0.01);
	}

	public function testTaggedMultiDoesNotSumMoney(): void
	{
		$raw = PremiumPolicy::atStarterPreset();
		$raw['stacking'] = PremiumPolicy::STACKING_TAGGED;
		$policy = PremiumPolicy::fromValidated($raw);
		$classifier = new PremiumSurchargeClassifier();
		$result = $classifier->classify(
			[$this->interval('2026-08-09 22:00:00', '2026-08-09 23:00:00')],
			$policy,
			static fn (): bool => false,
			static fn (): float => 8.0,
		);
		$byId = [];
		foreach ($result['buckets'] as $b) {
			$byId[$b['id']] = $b['hours'];
		}
		$this->assertEqualsWithDelta(1.0, $byId['sunday'] ?? 0.0, 0.01);
		$this->assertEqualsWithDelta(1.0, $byId['night'] ?? 0.0, 0.01);
		$this->assertEqualsWithDelta(1.0, $result['total_classified_hours'], 0.01);
		$this->assertEqualsWithDelta(0.0, $result['total_valued_hours'], 0.01);
	}

	public function testDstSpringForwardEuropeViennaNightWindow(): void
	{
		// 2026-03-29 is Sunday (EU spring-forward). Disable weekday premiums so
		// only the night window is classified across the missing 02:00 hour.
		$raw = PremiumPolicy::atStarterPreset();
		foreach ($raw['categories'] as &$cat) {
			if (($cat['id'] ?? '') !== 'night') {
				$cat['enabled'] = false;
			}
		}
		unset($cat);
		$policy = PremiumPolicy::fromValidated($raw);
		$classifier = new PremiumSurchargeClassifier();
		$result = $classifier->classify(
			[$this->interval('2026-03-29 01:30:00', '2026-03-29 03:30:00')],
			$policy,
			static fn (): bool => false,
			static fn (): float => 8.0,
		);
		$night = null;
		foreach ($result['buckets'] as $b) {
			if ($b['id'] === 'night') {
				$night = $b;
			}
		}
		$this->assertNotNull($night);
		// Absolute timestamps: ~1h wall after DST jump still all inside night window.
		$this->assertGreaterThan(0.9, $night['hours']);
		$this->assertLessThan(1.2, $night['hours']);
	}
}

class PremiumPolicyTest extends TestCase
{
	public function testAtStarterValidates(): void
	{
		$raw = PremiumPolicy::atStarterPreset();
		$this->assertSame([], PremiumPolicy::validate($raw));
		$p = PremiumPolicy::fromValidated($raw);
		$this->assertSame(PremiumPolicy::STACKING_MAX_SINGLE, $p->getStacking());
		$this->assertCount(4, $p->getEnabledCategories());
	}

	public function testRejectsBadRate(): void
	{
		$raw = PremiumPolicy::atStarterPreset();
		$raw['categories'][0]['rate'] = 9.0;
		$this->assertContains('PREMIUM_RATE_INVALID', PremiumPolicy::validate($raw));
	}

	/**
	 * enabled flag (SC-05): a "false"/"0" string must never activate a
	 * category — validate rejects non-canonical values, fromValidated parses
	 * strict-bool (fail closed = disabled).
	 */
	private function policyWithEnabled(mixed $enabled): array
	{
		$cat = [
			'id' => 'sun_premium',
			'rate' => 0.5,
			'applies_to' => PremiumPolicy::APPLIES_WEEKDAY,
			'weekdays' => ['sun'],
		];
		if ($enabled !== null) {
			$cat['enabled'] = $enabled;
		}
		return ['stacking' => PremiumPolicy::STACKING_MAX_SINGLE, 'categories' => [$cat]];
	}

	public function testEnabledAcceptsCanonicalValues(): void
	{
		foreach ([true, 1, '1', 'true'] as $on) {
			$policy = PremiumPolicy::fromValidated($this->policyWithEnabled($on));
			$this->assertTrue($policy->getCategories()[0]['enabled'], 'enabled ' . var_export($on, true));
			$this->assertCount(1, $policy->getEnabledCategories());
		}
		foreach ([false, 0, '0', 'false'] as $off) {
			$this->assertSame([], PremiumPolicy::validate($this->policyWithEnabled($off)), 'validate ' . var_export($off, true));
			$policy = PremiumPolicy::fromValidated($this->policyWithEnabled($off));
			$this->assertFalse($policy->getCategories()[0]['enabled'], 'enabled ' . var_export($off, true));
			$this->assertCount(0, $policy->getEnabledCategories());
		}
	}

	public function testEnabledAbsentDefaultsTrue(): void
	{
		$policy = PremiumPolicy::fromValidated($this->policyWithEnabled(null));
		$this->assertTrue($policy->getCategories()[0]['enabled']);
	}

	public function testValidateRejectsNonCanonicalEnabled(): void
	{
		foreach (['maybe', 'yes', 2, 'FALSE '] as $bad) {
			$errors = PremiumPolicy::validate($this->policyWithEnabled($bad));
			$this->assertContains('PREMIUM_ENABLED_INVALID', $errors, 'enabled ' . var_export($bad, true));
		}
	}
}
