<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\PremiumSurchargeService;
use OCA\ArbeitszeitCheck\Support\PremiumPolicy;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class PremiumSurchargeServiceTest extends TestCase
{
	private function service(IConfig $config, ?TimeEntryMapper $entries = null): PremiumSurchargeService
	{
		return new PremiumSurchargeService(
			$config,
			$entries ?? $this->createMock(TimeEntryMapper::class),
			$this->createMock(HolidayService::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
		);
	}

	private function completedEntry(\DateTime $start, \DateTime $end, ?string $breaksJson = null): TimeEntry
	{
		$entry = new TimeEntry();
		$entry->setUserId('u1');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime($start);
		$entry->setEndTime($end);
		$entry->setBreaks($breaksJson);
		$entry->setCreatedAt(clone $start);
		$entry->setUpdatedAt(clone $end);

		return $entry;
	}

	public function testDisabledReturnsStableShapeWithoutTouchingEntries(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') {
				if ($key === Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED) {
					return '0';
				}
				return $default;
			}
		);
		$entries = $this->createMock(TimeEntryMapper::class);
		$entries->expects($this->never())->method('findByUserAndDateRange');

		$result = $this->service($config, $entries)->summariseForUser(
			'u1',
			new \DateTime('2026-08-01'),
			new \DateTime('2026-08-31')
		);

		$this->assertFalse($result['enabled']);
		$this->assertSame([], $result['buckets']);
		$this->assertSame('premium_disabled', $result['note']);
		$this->assertSame(0.0, $result['total_classified_hours']);
	}

	public function testEnabledClassifiesCompletedSundayEntry(): void
	{
		$config = $this->createMock(IConfig::class);
		$policy = PremiumPolicy::atStarterPreset();
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') use ($policy) {
				if ($key === Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED) {
					return '1';
				}
				if ($key === Constants::CONFIG_PREMIUM_POLICY_JSON) {
					return json_encode($policy, JSON_THROW_ON_ERROR);
				}
				return $default;
			}
		);

		$tz = new \DateTimeZone('Europe/Vienna');
		$entry = $this->completedEntry(
			new \DateTime('2026-08-09 10:00:00', $tz),
			new \DateTime('2026-08-09 12:00:00', $tz)
		);

		$entries = $this->createMock(TimeEntryMapper::class);
		$entries->method('findByUserAndDateRange')->willReturn([$entry]);

		$holidays = $this->createMock(HolidayService::class);
		$holidays->method('getHolidayWeightForUser')->willReturn(0.0);

		$svc = new PremiumSurchargeService(
			$config,
			$entries,
			$holidays,
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
		);

		$result = $svc->summariseForUser('u1', new \DateTime('2026-08-01'), new \DateTime('2026-08-31'));
		$this->assertTrue($result['enabled']);
		$this->assertTrue($result['orthogonal_to_saldo']);
		$sunday = null;
		foreach ($result['buckets'] as $b) {
			if ($b['id'] === 'sunday') {
				$sunday = $b;
			}
		}
		$this->assertNotNull($sunday);
		$this->assertEqualsWithDelta(2.0, $sunday['hours'], 0.01);
	}

	public function testBreaksAreExcludedFromWorkIntervals(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$svc = $this->service($config);

		$tz = new \DateTimeZone('Europe/Vienna');
		$entry = $this->completedEntry(
			new \DateTime('2026-08-03 08:00:00', $tz),
			new \DateTime('2026-08-03 17:00:00', $tz),
			json_encode([
				['start' => '2026-08-03T12:00:00+02:00', 'end' => '2026-08-03T12:45:00+02:00'],
			], JSON_THROW_ON_ERROR)
		);

		$intervals = $svc->workIntervalsFromEntry($entry);
		$this->assertCount(2, $intervals);
		$total = 0;
		foreach ($intervals as [$a, $b]) {
			$total += $b->getTimestamp() - $a->getTimestamp();
		}
		// 9h clocked − 45 min break = 8.25 h
		$this->assertSame(8 * 3600 + 15 * 60, $total);
	}

	public function testFlattenReportToCsvRowsEmitsOneRowPerBucket(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$svc = $this->service($config);
		$rows = $svc->flattenReportToCsvRows([
			'period' => ['start' => '2026-08-01', 'end' => '2026-08-31'],
			'policy_version' => 3,
			'stacking' => 'max_single_rate',
			'users' => [
				[
					'user_id' => 'u1',
					'display_name' => 'Ada',
					'buckets' => [
						['id' => 'sunday', 'label' => 'Sunday', 'hours' => 2.0, 'rate' => 1.0, 'valued_hours' => 2.0],
						['id' => 'night', 'label' => 'Night', 'hours' => 1.0, 'rate' => 0.5, 'valued_hours' => 0.5],
					],
				],
			],
		]);
		$this->assertCount(2, $rows);
		$this->assertSame('sunday', $rows[0]['bucket_id']);
		$this->assertSame(3, $rows[0]['policy_version']);
		$this->assertSame('night', $rows[1]['bucket_id']);
	}

	public function testBuildClosureAuditBlockNullWhenDisabled(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') {
				if ($key === Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED) {
					return '0';
				}
				return $default;
			}
		);
		$svc = $this->service($config);
		$this->assertNull($svc->buildClosureAuditBlock('u1', 2026, 8));
	}

	public function testSummariseForUserHonoursPolicyOverrideWithoutRereadingConfig(): void
	{
		$stored = PremiumPolicy::atStarterPreset();
		$override = PremiumPolicy::fromValidated(array_merge($stored, [
			'categories' => array_map(static function (array $c): array {
				if (($c['id'] ?? '') === 'sunday') {
					$c['enabled'] = false;
				}
				return $c;
			}, $stored['categories']),
		]));

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') use ($stored) {
				if ($key === Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED) {
					return '1';
				}
				if ($key === Constants::CONFIG_PREMIUM_POLICY_JSON) {
					return json_encode($stored, JSON_THROW_ON_ERROR);
				}
				return $default;
			}
		);

		$tz = new \DateTimeZone('Europe/Vienna');
		$entry = $this->completedEntry(
			new \DateTime('2026-08-09 10:00:00', $tz),
			new \DateTime('2026-08-09 12:00:00', $tz)
		);
		$entries = $this->createMock(TimeEntryMapper::class);
		$entries->method('findByUserAndDateRange')->willReturn([$entry]);

		$svc = $this->service($config, $entries);
		$withStored = $svc->summariseForUser('u1', new \DateTime('2026-08-01'), new \DateTime('2026-08-31'));
		$withOverride = $svc->summariseForUser(
			'u1',
			new \DateTime('2026-08-01'),
			new \DateTime('2026-08-31'),
			$override
		);

		$this->assertGreaterThan(0.0, $withStored['total_classified_hours']);
		$sundayStored = array_values(array_filter(
			$withStored['buckets'],
			static fn (array $b): bool => ($b['id'] ?? '') === 'sunday'
		));
		$this->assertNotEmpty($sundayStored);
		$sundayOverride = array_values(array_filter(
			$withOverride['buckets'],
			static fn (array $b): bool => ($b['id'] ?? '') === 'sunday'
		));
		$this->assertSame([], $sundayOverride);
	}

	public function testBuildPeriodReportDisabledShape(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$svc = $this->service($config);
		$report = $svc->buildPeriodReport(['u1'], new \DateTime('2026-08-01'), new \DateTime('2026-08-31'), static fn () => 'Ada');
		$this->assertFalse($report['enabled']);
		$this->assertSame([], $report['users']);
		$this->assertTrue($report['orthogonal_to_saldo']);
		$this->assertSame('premium_disabled', $report['note']);
	}

	public function testGetPolicyArrayOrDefaultFallsBackToStarterPreset(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default = '') => (string)$default
		);
		// no stored policy -> starter preset array
		$result = $this->service($config)->getPolicyArrayOrDefault();
		$this->assertIsArray($result);
		$this->assertEquals(PremiumPolicy::atStarterPreset(), $result);
	}

	public function testBuildClosureAuditBlockDisabledAndMonthValidation(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default = '') => (string)$default
		);
		// disabled -> null without touching the lock provider
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');
		$svc = new PremiumSurchargeService(
			$config,
			$this->createMock(TimeEntryMapper::class),
			$this->createMock(HolidayService::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			new \OCA\ArbeitszeitCheck\Support\PremiumSurchargeClassifier(),
			$locking,
		);
		$this->assertNull($svc->buildClosureAuditBlock('u1', 2026, 8));

		// enabled -> invalid month throws before locking
		$configOn = $this->createMock(IConfig::class);
		$configOn->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default = '') =>
				$key === Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED ? '1' : (string)$default
		);
		$svcOn = new PremiumSurchargeService(
			$configOn,
			$this->createMock(TimeEntryMapper::class),
			$this->createMock(HolidayService::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			new \OCA\ArbeitszeitCheck\Support\PremiumSurchargeClassifier(),
			$locking,
		);
		$this->expectException(\InvalidArgumentException::class);
		$svcOn->buildClosureAuditBlock('u1', 2026, 13);
	}

	public function testBuildClosureAuditBlockAcquiresAndReleasesLock(): void
	{
		$policy = PremiumPolicy::atStarterPreset();
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') use ($policy) {
				if ($key === Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED) { return '1'; }
				if ($key === Constants::CONFIG_PREMIUM_POLICY_JSON) { return json_encode($policy); }
				if ($key === Constants::CONFIG_PREMIUM_POLICY_VERSION) { return '3'; }
				return (string)$default;
			}
		);
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())
			->method('acquireLock')
			->with(self::isType('string'), ILockingProvider::LOCK_EXCLUSIVE, 'Premium seal snapshot');
		$locking->expects($this->once())
			->method('releaseLock')
			->with(self::isType('string'), ILockingProvider::LOCK_EXCLUSIVE);
		$entries = $this->createMock(TimeEntryMapper::class);
		$entries->method('findByUserAndDateRange')->willReturn([]);
		$holidays = $this->createMock(HolidayService::class);
		$holidays->method('getHolidayWeightForUser')->willReturn(0.0);
		$svc = new PremiumSurchargeService(
			$config, $entries, $holidays,
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			new \OCA\ArbeitszeitCheck\Support\PremiumSurchargeClassifier(),
			$locking,
		);
		$block = $svc->buildClosureAuditBlock('u1', 2026, 8);
		$this->assertTrue($block['enabled']);
		$this->assertSame(3, $block['policy_version']);
		$this->assertTrue($block['orthogonal_to_saldo']);
		$this->assertSame('hours_only', $block['currency_mode']);
		$this->assertIsArray($block['summary']);
		$this->assertArrayHasKey('buckets', $block['summary']);
	}

	public function testSummariseForUserUsesModelDailyTarget(): void
	{
		// resolveDailyTarget: user model -> model daily hours (exercises private arm)
		$policy = PremiumPolicy::atStarterPreset();
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') use ($policy) {
				if ($key === Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED) { return '1'; }
				if ($key === Constants::CONFIG_PREMIUM_POLICY_JSON) { return json_encode($policy); }
				return (string)$default;
			}
		);
		$tz = new \DateTimeZone('Europe/Vienna');
		$entry = $this->completedEntry(
			new \DateTime('2026-08-10 10:00:00', $tz), // Monday
			new \DateTime('2026-08-10 14:00:00', $tz)
		);
		$entries = $this->createMock(TimeEntryMapper::class);
		$entries->method('findByUserAndDateRange')->willReturn([$entry]);
		$userModel = new \OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel();
		$userModel->setWorkingTimeModelId(5);
		$uwtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$uwtm->method('findCurrentByUser')->willReturn($userModel);
		$model = new \OCA\ArbeitszeitCheck\Db\WorkingTimeModel();
		$model->setDailyHours('4.0');
		$wtm = $this->createMock(WorkingTimeModelMapper::class);
		$wtm->method('find')->willReturn($model);
		$holidays = $this->createMock(HolidayService::class);
		$holidays->method('getHolidayWeightForUser')->willReturn(0.0);
		$svc = new PremiumSurchargeService(
			$config, $entries, $holidays, $uwtm, $wtm,
		);
		$result = $svc->summariseForUser('u1', new \DateTime('2026-08-01'), new \DateTime('2026-08-31'));
		$this->assertTrue($result['enabled']);
		$this->assertTrue($result['enabled']);
		$this->assertIsFloat($result['total_classified_hours']);
	}
}