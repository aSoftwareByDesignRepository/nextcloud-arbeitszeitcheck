<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCP\IConfig;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Fake DutyCheck facade — the provider resolves it dynamically by service id
 * and calls methods via duck-typing, so a plain stub object is the honest seam.
 */
final class FakeDutyRotationFacade
{
	public ?object $weekTarget = null;
	public ?object $dayTarget = null;
	public bool $enabledForUser = true;
	/** @var list<array> */
	public array $calls = [];

	public function isSollFromDutyEnabledForUser(string $userId): bool
	{
		return $this->enabledForUser;
	}

	public function getWeekTarget(string $userId, \DateTimeImmutable $monday): ?object
	{
		$this->calls[] = ['week', $userId, $monday->format('Y-m-d')];
		return $this->weekTarget;
	}

	public function getDayTarget(string $userId, \DateTimeImmutable $day): ?object
	{
		$this->calls[] = ['day', $userId, $day->format('Y-m-d')];
		return $this->dayTarget;
	}
}

/**
 * @covers \OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider
 */
final class DutyRotationSollProviderTest extends TestCase
{
	private IConfig&MockObject $config;
	private ContainerInterface&MockObject $container;
	private MonthClosureService&MockObject $monthClosure;
	private LoggerInterface&MockObject $logger;
	private FakeDutyRotationFacade $facade;

	/** @var array<string,string> */
	private array $appValues = [];
	private bool $facadeAvailable = true;
	private ?object $facadeOverride = null;
	private ?MonthClosureService $ctorClosure = null;

	protected function setUp(): void
	{
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->monthClosure = $this->createMock(MonthClosureService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->facade = new FakeDutyRotationFacade();
		$this->appValues = [Constants::CONFIG_DUTY_ROTATION_SOLL_ENABLED => '1'];
		$this->ctorClosure = $this->monthClosure;

		$this->config->method('getAppValue')->willReturnCallback(
			fn ($app, $key, $default = '') => $this->appValues[$key] ?? $default
		);
		$this->monthClosure->method('isMonthFinalized')->willReturn(false);
		$this->container->method('get')->willReturnCallback(function (string $id) {
			if ($this->facadeAvailable && $id === 'dutycheck.effective_target_hours_facade') {
				return $this->facadeOverride ?? $this->facade;
			}
			if ($this->ctorClosure === null && $id === MonthClosureService::class) {
				return $this->monthClosure;
			}
			throw new \RuntimeException('not found: ' . $id);
		});
	}

	private function svc(): DutyRotationSollProvider
	{
		return new DutyRotationSollProvider(
			$this->config,
			$this->container,
			$this->ctorClosure,
			$this->logger
		);
	}

	public function testIsEnabledForOrgRespectsConfig(): void
	{
		$this->assertTrue($this->svc()->isEnabledForOrg());
		$this->appValues[Constants::CONFIG_DUTY_ROTATION_SOLL_ENABLED] = '0';
		$this->assertFalse($this->svc()->isEnabledForOrg());
	}

	public function testIsEffectiveForUserFalseWhenOrgDisabled(): void
	{
		$this->appValues[Constants::CONFIG_DUTY_ROTATION_SOLL_ENABLED] = '0';
		$this->assertFalse($this->svc()->isEffectiveForUser('alice', new \DateTime('2025-02-05')));
	}

	public function testIsEffectiveForUserFalseWhenFacadeMissing(): void
	{
		$this->facadeAvailable = false;
		$this->assertFalse($this->svc()->isEffectiveForUser('alice', new \DateTime('2025-02-05')));
	}

	public function testIsEffectiveForUserTrueWithWeekTarget(): void
	{
		$this->facade->weekTarget = (object)['basis' => 'model', 'weekLabel' => 'KW 6'];
		$this->assertTrue($this->svc()->isEffectiveForUser('alice', new \DateTime('2025-02-05')));
		// Wednesday 2025-02-05 -> ISO Monday 2025-02-03
		$this->assertSame('2025-02-03', $this->facade->calls[0][2]);
	}

	public function testIsEffectiveForUserFalseInSealedMonth(): void
	{
		$this->facade->weekTarget = (object)['basis' => 'model'];
		$closure = $this->createMock(MonthClosureService::class);
		$closure->method('isMonthFinalized')->willReturn(true);
		$svc = new DutyRotationSollProvider($this->config, $this->container, $closure, $this->logger);
		$this->assertFalse($svc->isEffectiveForUser('alice', new \DateTime('2025-02-05')));
	}

	public function testGetWeekTargetBasisAndLabel(): void
	{
		$svc = $this->svc();
		$this->facade->weekTarget = (object)['basis' => 'tariff', 'weekLabel' => 'KW 6'];
		$this->assertSame('tariff', $svc->getWeekTargetBasis('alice', new \DateTime('2025-02-05')));
		$this->assertSame('KW 6', $svc->getWeekTargetLabel('alice', new \DateTime('2025-02-05')));

		$this->facade->weekTarget = (object)['basis' => '', 'weekLabel' => ''];
		$this->assertNull($svc->getWeekTargetBasis('alice', new \DateTime('2025-02-05')));
		$this->assertNull($svc->getWeekTargetLabel('alice', new \DateTime('2025-02-05')));

		$this->facade->weekTarget = null;
		$this->assertNull($svc->getWeekTargetBasis('alice', new \DateTime('2025-02-05')));
	}

	public function testDayNetHoursForUser(): void
	{
		$this->facade->dayTarget = (object)['netMinutes' => 480];
		$this->assertSame(8.0, $this->svc()->dayNetHoursForUser('alice', new \DateTime('2025-02-05')));
	}

	public function testDayNetHoursForUserNullWhenDisabled(): void
	{
		$this->appValues[Constants::CONFIG_DUTY_ROTATION_SOLL_ENABLED] = '0';
		$this->assertNull($this->svc()->dayNetHoursForUser('alice', new \DateTime('2025-02-05')));
	}

	public function testDayNetHoursForUserNullInSealedMonth(): void
	{
		$closure = $this->createMock(MonthClosureService::class);
		$closure->method('isMonthFinalized')->willReturn(true);
		$svc = new DutyRotationSollProvider($this->config, $this->container, $closure, $this->logger);
		$this->assertNull($svc->dayNetHoursForUser('alice', new \DateTime('2025-02-05')));
	}

	public function testRequiredHoursForDateRangeSumsDayTargets(): void
	{
		$this->facade->dayTarget = (object)['netMinutes' => 480];
		// Mon–Wed of one week
		$hours = $this->svc()->requiredHoursForDateRange('alice', new \DateTime('2025-02-03'), new \DateTime('2025-02-05'));
		$this->assertSame(24.0, $hours);
		$this->assertCount(3, $this->facade->calls);
	}

	public function testRequiredHoursForDateRangeHandlesReversedRange(): void
	{
		$this->facade->dayTarget = (object)['netMinutes' => 240];
		$hours = $this->svc()->requiredHoursForDateRange('alice', new \DateTime('2025-02-05'), new \DateTime('2025-02-03'));
		$this->assertSame(12.0, $hours);
	}

	public function testRequiredHoursForDateRangeNullWhenDayMissing(): void
	{
		$this->facade->dayTarget = null;
		$this->assertNull($this->svc()->requiredHoursForDateRange('alice', new \DateTime('2025-02-03'), new \DateTime('2025-02-05')));
	}

	public function testRequiredHoursForDateRangeNullInSealedMonth(): void
	{
		$this->facade->dayTarget = (object)['netMinutes' => 480];
		$closure = $this->createMock(MonthClosureService::class);
		$closure->method('isMonthFinalized')->willReturn(true);
		$svc = new DutyRotationSollProvider($this->config, $this->container, $closure, $this->logger);
		$this->assertNull($svc->requiredHoursForDateRange('alice', new \DateTime('2025-02-03'), new \DateTime('2025-02-05')));
	}

	public function testFacadeRotationDisabledForUserReturnsNullTargets(): void
	{
		$this->facade->enabledForUser = false;
		$this->facade->dayTarget = (object)['netMinutes' => 480];
		$this->assertNull($this->svc()->dayNetHoursForUser('alice', new \DateTime('2025-02-05')));
		$this->assertSame([], $this->facade->calls);
	}

	public function testFacadeExceptionIsSwallowedAndLogged(): void
	{
		$this->facade->dayTarget = new class {
			public int $netMinutes = 480;
		};
		// a facade whose method throws mid-call
		$this->facadeAvailable = true;
		$throwing = new class {
			public function isSollFromDutyEnabledForUser(string $u): bool { return true; }
			public function getDayTarget(string $u, \DateTimeImmutable $d): object { throw new \RuntimeException('boom'); }
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($throwing) {
			if ($id === 'dutycheck.effective_target_hours_facade') {
				return $throwing;
			}
			throw new \RuntimeException('nf');
		});
		$svc = new DutyRotationSollProvider($this->config, $container, $this->monthClosure, $this->logger);
		$this->logger->expects($this->once())->method('info')->with('azc.soll.facade_miss');
		$this->assertNull($svc->dayNetHoursForUser('alice', new \DateTime('2025-02-05')));
	}

	public function testLazyMonthClosureResolutionViaContainer(): void
	{
		$this->ctorClosure = null;
		$svc = $this->svc();
		$this->facade->dayTarget = (object)['netMinutes' => 60];
		// sealed via lazily-resolved MonthClosureService
		$this->monthClosure = $this->createMock(MonthClosureService::class);
		$this->monthClosure->method('isMonthFinalized')->willReturn(true);
		$this->assertNull($svc->dayNetHoursForUser('alice', new \DateTime('2025-02-05')));
	}

	public function testIsoMondayNormalisesToWeekStart(): void
	{
		$svc = $this->svc();
		$rm = new \ReflectionMethod(DutyRotationSollProvider::class, 'isoMonday');
		$rm->setAccessible(true);
		// Sunday 2025-02-09 -> Monday 2025-02-03
		$this->assertSame('2025-02-03', $rm->invoke($svc, new \DateTime('2025-02-09'))->format('Y-m-d'));
		// already Monday stays
		$this->assertSame('2025-02-03', $rm->invoke($svc, new \DateTimeImmutable('2025-02-03'))->format('Y-m-d'));
	}


	public function testFacadeWithoutPerUserGateFallsBackToOrgGate(): void
	{
		// no isSollFromDutyEnabledForUser, org gate returns false -> not effective
		$this->facadeOverride = new class {
			public function isRotationEnabledForOrg(string $userId): bool
			{
				return false;
			}
			public function getWeekTarget(string $userId, \DateTimeImmutable $monday): ?object
			{
				return (object)['basis' => 'model', 'weekLabel' => 'KW 6'];
			}
		};
		$this->assertFalse($this->svc()->isEffectiveForUser('alice', new \DateTime('2025-02-05')));
	}

	public function testFacadeWithoutAnyGateDefaultsToEnabled(): void
	{
		// neither gate method exists -> treated as enabled, target resolves
		$this->facadeOverride = new class {
			public function getWeekTarget(string $userId, \DateTimeImmutable $monday): ?object
			{
				return (object)['basis' => 'model', 'weekLabel' => 'KW 6'];
			}
		};
		$this->assertTrue($this->svc()->isEffectiveForUser('alice', new \DateTime('2025-02-05')));
	}

	public function testFacadeGateExceptionDisablesRotation(): void
	{
		$this->facadeOverride = new class {
			public function isSollFromDutyEnabledForUser(string $userId): bool
			{
				throw new \RuntimeException('facade broken');
			}
		};
		$this->assertFalse($this->svc()->isEffectiveForUser('alice', new \DateTime('2025-02-05')));
	}
}
