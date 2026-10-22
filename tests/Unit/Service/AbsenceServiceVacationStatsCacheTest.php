<?php

declare(strict_types=1);

/**
 * Vacation-stats cache contract.
 *
 * ManagerController::getPeopleOverview() fans getVacationStats() out once per
 * in-scope employee; each uncached call costs several mapper queries plus a
 * full layered entitlement resolution. The distributed read-through cache
 * (azc_vacation_stats) behind a per-user version key must therefore:
 *
 *  - serve repeated reads without recomputing,
 *  - drop a user's entries when a mutation commits (post-commit, so a
 *    rolled-back write can never orphan valid data),
 *  - degrade to plain recomputation when the cache backend is unavailable.
 *
 * @copyright Copyright (c) 2026, Alexander Mäule / Software by Design
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceIcalMailService;
use OCA\ArbeitszeitCheck\Service\AbsenceNotificationMailService;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\ManagerPendingApprovalMailService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\PostCommitEffectQueue;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationHoursDebitService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AbsenceServiceVacationStatsCacheTest extends TestCase
{
	private AbsenceMapper $absenceMapper;
	private AuditLogMapper $auditLogMapper;
	private IDBConnection $db;
	private VacationAllocationService $allocationService;
	private IL10N $l10n;
	private int $allocationCalls = 0;

	protected function setUp(): void
	{
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->allocationService = $this->createMock(VacationAllocationService::class);
		$this->allocationCalls = 0;

		$this->allocationService->method('computeYearAllocation')
			->willReturnCallback(function () {
				$this->allocationCalls++;
				return [
					'year' => 2026,
					'entitlement' => 30.0,
					'entitlement_source' => 'manual',
					'carryover_opening' => 0.0,
					'used_total_working_days' => 5.0,
					'total_remaining_for_new_requests' => 25.0,
					'carryover_remaining_after_approved' => 0.0,
					'annual_remaining_after_approved' => 25.0,
					'carryover_usable_for_new_requests' => 0.0,
					'carryover_expires_on' => null,
					'vacation_unit' => 'days',
					'vacation_year_mode' => 'calendar',
					'vacation_year_label' => '2026',
				];
			});
		$this->allocationService->method('getMaxCarryoverOpeningCap')->willReturn(null);

		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
	}

	/** In-memory ICache standing in for the distributed backend. */
	private function makeCacheStore(): ICache
	{
		$store = new \ArrayObject();
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			static fn (string $key): mixed => $store[$key] ?? null
		);
		$cache->method('set')->willReturnCallback(
			static function (string $key, $value, $ttl = 0) use ($store): bool {
				$store[$key] = $value;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			static function (string $key) use ($store): bool {
				unset($store[$key]);
				return true;
			}
		);
		return $cache;
	}

	private function makeService(?ICacheFactory $cacheFactory, ?PostCommitEffectQueue $queue = null): AbsenceService
	{
		return new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(TeamResolverService::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(IConfig::class),
			$this->db,
			$this->createMock(ILockingProvider::class),
			$this->createMock(IUserManager::class),
			$this->l10n,
			$this->createMock(NotificationService::class),
			$this->createMock(AbsenceIcalMailService::class),
			$this->createMock(HolidayService::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->allocationService,
			$this->createMock(AbsenceNotificationMailService::class),
			$this->createMock(MonthClosureService::class),
			null,
			null,
			null,
			$this->createMock(VacationHoursDebitService::class),
			$this->createMock(ManagerPendingApprovalMailService::class),
			$this->createMock(TimeEntryMapper::class),
			null,
			$queue,
			$cacheFactory,
		);
	}

	private function factoryReturning(ICache $cache): ICacheFactory
	{
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		return $factory;
	}

	private function pendingVacationAbsence(int $id): Absence
	{
		$absence = new Absence();
		$absence->setId($id);
		$absence->setUserId('employee');
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('+14 days'));
		$absence->setEndDate(new \DateTime('+16 days'));
		$absence->setDays(3.0);
		return $absence;
	}

	public function testRepeatedReadsAreServedFromCache(): void
	{
		$service = $this->makeService($this->factoryReturning($this->makeCacheStore()));

		$first = $service->getVacationStats('employee', 2026);
		$second = $service->getVacationStats('employee', 2026);

		$this->assertSame(1, $this->allocationCalls, 'second read must be a cache hit, not a recomputation');
		$this->assertEquals($first, $second);
		$this->assertEquals(30.0, $first['entitlement']);
		$this->assertEquals(25.0, $first['remaining']);
	}

	public function testCommittedMutationDropsCachedStats(): void
	{
		$this->db->method('inTransaction')->willReturn(false);
		$queue = new PostCommitEffectQueue($this->db, $this->createMock(LoggerInterface::class));
		$service = $this->makeService($this->factoryReturning($this->makeCacheStore()), $queue);

		$service->getVacationStats('employee', 2026);
		$this->assertSame(1, $this->allocationCalls);

		$absence = $this->pendingVacationAbsence(7);
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a): Absence => $a);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);

		$service->approveAbsence(7, 'manager', 'ok');

		$service->getVacationStats('employee', 2026);
		$this->assertSame(2, $this->allocationCalls, 'committed approval must invalidate the cached stats');
	}

	public function testRolledBackMutationKeepsCachedStats(): void
	{
		// An outer transaction being open models the bulk-decision path: the
		// invalidation effect is queued, and commit() throwing means it is
		// discarded with the write — never executed.
		$this->db->method('inTransaction')->willReturn(true);
		$queue = new PostCommitEffectQueue($this->db, $this->createMock(LoggerInterface::class));
		$service = $this->makeService($this->factoryReturning($this->makeCacheStore()), $queue);

		$service->getVacationStats('employee', 2026);
		$this->assertSame(1, $this->allocationCalls);

		$absence = $this->pendingVacationAbsence(7);
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a): Absence => $a);
		$this->db->method('commit')->willThrowException(new \RuntimeException('forced rollback'));

		try {
			$service->approveAbsence(7, 'manager', 'ok');
			$this->fail('approveAbsence must propagate the storage failure');
		} catch (\Throwable) {
		}

		$service->getVacationStats('employee', 2026);
		$this->assertSame(1, $this->allocationCalls, 'a rolled-back write must not orphan the still-valid cache entry');
	}

	public function testUnavailableCacheBackendDegradesGracefully(): void
	{
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willThrowException(new \RuntimeException('memcached down'));
		$service = $this->makeService($factory);

		$first = $service->getVacationStats('employee', 2026);
		$second = $service->getVacationStats('employee', 2026);

		$this->assertSame(2, $this->allocationCalls, 'every call recomputes when no cache exists');
		$this->assertSame(30.0, $first['entitlement']);
		$this->assertSame($first, $second);
	}
}
