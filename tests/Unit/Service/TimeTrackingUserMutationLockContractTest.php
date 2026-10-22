<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use OCA\ArbeitszeitCheck\Service\MonthClosureGuard;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Zeus concurrency contract: exclusive per-user lock must wrap the
 * check-then-act clock-in critical section (acquire before active lookup;
 * release even when the business rule rejects a second clock-in).
 */
class TimeTrackingUserMutationLockContractTest extends TestCase
{
	public function testClockInAcquiresExclusiveLockBeforeActiveLookup(): void
	{
		$userId = 'zeus-lock-order';
		$expectedKey = DbLockKeys::timeTrackingUser($userId);
		$order = [];

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('findStalePausedAutomaticEntries')->willReturn([]);
		$timeEntryMapper->expects($this->once())
			->method('findActiveByUser')
			->with($userId)
			->willReturnCallback(static function () use (&$order): ?TimeEntry {
				$order[] = 'findActiveByUser';
				$active = new TimeEntry();
				$active->setId(1);
				$active->setStatus(TimeEntry::STATUS_ACTIVE);
				return $active;
			});

		$lockingProvider = $this->createMock(ILockingProvider::class);
		$lockingProvider->expects($this->once())
			->method('acquireLock')
			->with($expectedKey, ILockingProvider::LOCK_EXCLUSIVE, $this->anything())
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'acquireLock';
			});
		$lockingProvider->expects($this->once())
			->method('releaseLock')
			->with($expectedKey, ILockingProvider::LOCK_EXCLUSIVE)
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'releaseLock';
			});

		$service = $this->buildService($timeEntryMapper, $lockingProvider);

		$l10n = $this->createMock(IL10N::class);
		// Rebuild with controllable l10n for exception message
		$service = $this->buildService($timeEntryMapper, $lockingProvider, $l10n);
		$l10n->method('t')->willReturn('User is already clocked in');

		try {
			$service->clockIn($userId);
			$this->fail('Expected already-clocked-in exception');
		} catch (\Throwable $e) {
			$this->assertStringContainsString('already clocked in', strtolower($e->getMessage()));
		}

		$this->assertSame(['acquireLock', 'findActiveByUser', 'releaseLock'], $order);
	}

	public function testWithUserMutationLockReleasesAfterCallbackThrows(): void
	{
		$userId = 'zeus-lock-finally';
		$expectedKey = DbLockKeys::timeTrackingUser($userId);
		$released = false;

		$lockingProvider = $this->createMock(ILockingProvider::class);
		$lockingProvider->expects($this->once())
			->method('acquireLock')
			->with($expectedKey, ILockingProvider::LOCK_EXCLUSIVE, $this->anything());
		$lockingProvider->expects($this->once())
			->method('releaseLock')
			->with($expectedKey, ILockingProvider::LOCK_EXCLUSIVE)
			->willReturnCallback(static function () use (&$released): void {
				$released = true;
			});

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('findStalePausedAutomaticEntries')->willReturn([]);
		$service = $this->buildService($timeEntryMapper, $lockingProvider);

		try {
			$service->withUserMutationLock($userId, static function (): void {
				throw new \RuntimeException('boom');
			});
			$this->fail('Expected boom');
		} catch (\RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertTrue($released, 'Exclusive user mutation lock must release in finally');
	}

	private function buildService(
		TimeEntryMapper $timeEntryMapper,
		ILockingProvider $lockingProvider,
		?IL10N $l10n = null,
	): TimeTrackingService {
		$l10n ??= $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $args = []) => $s);

		$complianceService = $this->createMock(ComplianceService::class);
		$complianceService->method('checkComplianceBeforeClockIn')->willReturn([]);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => match ($key) {
			'max_daily_hours' => '10',
			'min_rest_period' => '11',
			'app_timezone' => 'Europe/Berlin',
			default => $default,
		});
		$config->method('getUserValue')->willReturn('');

		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());
		$dailyHoursCalculator = new DailyWorkingHoursCalculator($timeEntryMapper, $timeZoneService);

		$timeCapture = $this->createMock(TimeCaptureMethodService::class);
		$timeCapture->method('assertClockStampingAllowed')->willReturnCallback(static function (): void {
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('commit');
		$db->method('rollBack');

		return new TimeTrackingService(
			$timeEntryMapper,
			$this->createMock(ComplianceViolationMapper::class),
			$this->createMock(AuditLogMapper::class),
			$this->createMock(ProjectCheckIntegrationService::class),
			$complianceService,
			$l10n,
			$config,
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			$this->createMock(MonthClosureGuard::class),
			$db,
			$lockingProvider,
			$timeZoneService,
			$dailyHoursCalculator,
			null,
			$timeCapture,
		);
	}
}
