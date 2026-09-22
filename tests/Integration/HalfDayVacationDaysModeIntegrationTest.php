<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Test\TestCase;

/**
 * AC-G1 / AC-G5b / AC-G7 / AC-G12 — days-mode half-day against real DB + allocation.
 *
 * @group integration
 */
final class HalfDayVacationDaysModeIntegrationTest extends TestCase
{
	private string $uid = '';
	private string $managerUid = '';
	private ?string $prevUnit = null;
	private ?string $prevYearMode = null;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}

		$config = \OC::$server->get(IConfig::class);
		$this->prevUnit = $config->getAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_UNIT, Constants::VACATION_UNIT_DAYS);
		$this->prevYearMode = $config->getAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_YEAR_MODE, Constants::VACATION_YEAR_MODE_CALENDAR);
		// Clear stale migrate-pending so shared-dev DB locks from aborted admin flips do not flake AC-G*.
		$config->deleteAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING);
		$config->setAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_UNIT, Constants::VACATION_UNIT_DAYS);
		$config->setAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_YEAR_MODE, Constants::VACATION_YEAR_MODE_CALENDAR);
		$this->awaitVacationUnitMigrateIdle();

		$unit = \OC::$server->get(VacationUnitService::class);
		if (!$unit->isDaysMode()) {
			$this->markTestSkipped('Could not force vacation_unit=days for integration run');
		}

		$um = \OC::$server->get(IUserManager::class);
		$this->uid = 'azc_half_' . bin2hex(random_bytes(3));
		$this->managerUid = 'azc_mgr_' . bin2hex(random_bytes(3));
		foreach ([$this->uid, $this->managerUid] as $id) {
			if ($um->userExists($id)) {
				$um->get($id)?->delete();
			}
			$um->createUser($id, 'Azc-Half-' . bin2hex(random_bytes(4)) . '!');
			// Force EN so overlap assertions are locale-stable (instance default_language may be pl/de/…).
			$config->setUserValue($id, 'core', 'lang', 'en');
		}
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		try {
			\OC::$server->get(IUserSession::class)->setUser(null);
		} catch (\Throwable) {
		}
		$um = \OC::$server->get(IUserManager::class);
		foreach ([$this->uid, $this->managerUid] as $id) {
			if ($id === '') {
				continue;
			}
			try {
				$um->get($id)?->delete();
			} catch (\Throwable) {
			}
		}
		if ($this->prevUnit !== null) {
			try {
				\OC::$server->get(IConfig::class)->setAppValue(
					'arbeitszeitcheck',
					Constants::CONFIG_VACATION_UNIT,
					$this->prevUnit
				);
			} catch (\Throwable) {
			}
		}
		if ($this->prevYearMode !== null) {
			try {
				\OC::$server->get(IConfig::class)->setAppValue(
					'arbeitszeitcheck',
					Constants::CONFIG_VACATION_YEAR_MODE,
					$this->prevYearMode
				);
			} catch (\Throwable) {
			}
		}
	}

	/**
	 * Shared-dev / farm: heal + brief exclusive migrate locks from concurrent web/cron
	 * must not flake AC-G*. Wait until SHARED acquire succeeds (migrate idle).
	 */
	private function awaitVacationUnitMigrateIdle(int $attempts = 40): void
	{
		$config = \OC::$server->get(IConfig::class);
		$config->deleteAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING);
		$locking = \OC::$server->get(ILockingProvider::class);
		$key = DbLockKeys::vacationUnitMigration();
		$db = \OC::$server->get(\OCP\IDBConnection::class);
		for ($i = 0; $i < $attempts; $i++) {
			try {
				$locking->acquireLock($key, ILockingProvider::LOCK_SHARED, 'halfday migrate idle wait');
				$locking->releaseLock($key, ILockingProvider::LOCK_SHARED);
				return;
			} catch (LockedException) {
				// Abandoned exclusive (hung cron / crashed heal) with no pending flag: clear for harness.
				if ($i === 3 || $i === 12 || $i === 24) {
					try {
						$qb = $db->getQueryBuilder();
						$qb->delete('file_locks')
							->where($qb->expr()->eq('key', $qb->createNamedParameter($key)));
						$qb->executeStatement();
					} catch (\Throwable) {
						// best-effort
					}
				}
				usleep(200_000);
			}
		}
		$this->fail('Vacation unit migrate lock still exclusive after wait — stop nextcloud-cron / clear oc_file_locks azc/vu/migrate');
	}

	/**
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function withMigrateIdleRetry(callable $fn): mixed
	{
		$last = null;
		for ($i = 0; $i < 8; $i++) {
			try {
				$this->awaitVacationUnitMigrateIdle(10);
				return $fn();
			} catch (BusinessRuleException $e) {
				$last = $e;
				if ($e->getReasonCode() !== Constants::VAC_UNIT_MIGRATE_IN_PROGRESS) {
					throw $e;
				}
				usleep(300_000);
			}
		}
		throw $last ?? new \RuntimeException('migrate idle retry exhausted');
	}

	public function testCreateApproveHalfDayReducesRemainingByHalf(): void
	{
		$user = \OC::$server->get(IUserManager::class)->get($this->uid);
		$this->assertNotNull($user);
		\OC::$server->get(IUserSession::class)->setUser($user);

		$absenceService = \OC::$server->get(AbsenceService::class);
		$alloc = \OC::$server->get(VacationAllocationService::class);

		// Pick a weekday at least 14 days ahead to avoid month-closure / past edges.
		$day = new \DateTimeImmutable('tomorrow');
		while ((int)$day->format('N') > 5) {
			$day = $day->modify('+1 day');
		}
		$day = $day->modify('+14 days');
		while ((int)$day->format('N') > 5) {
			$day = $day->modify('+1 day');
		}
		$ymd = $day->format('Y-m-d');
		$year = (int)$day->format('Y');

		$before = $alloc->computeYearAllocation(
			$this->uid,
			$year,
			null,
			null,
			null,
			\DateTime::createFromImmutable($day),
			null,
			false
		);
		$remainingBefore = (float)$before['total_remaining_for_new_requests'];
		if ($remainingBefore < 0.5) {
			$this->markTestSkipped('Employee entitlement remaining < 0.5 — cannot exercise half-day debit');
		}

		$row = $this->withMigrateIdleRetry(fn () => $absenceService->createAbsence([
			'type' => Absence::TYPE_VACATION,
			'start_date' => $ymd,
			'end_date' => $ymd,
			'day_fraction' => '0.5',
			'reason' => 'Half-day integration ' . bin2hex(random_bytes(2)),
		], $this->uid));

		$this->assertGreaterThan(0, (int)$row->getId());
		$this->assertEqualsWithDelta(0.5, (float)$row->getDays(), 0.011, 'AC-G1 persist days=0.5');

		if ($row->getStatus() !== Absence::STATUS_APPROVED) {
			$approved = $this->withMigrateIdleRetry(
				fn () => $absenceService->approveAbsence((int)$row->getId(), $this->managerUid, 'integration approve')
			);
			$this->assertSame(Absence::STATUS_APPROVED, $approved->getStatus());
			$this->assertEqualsWithDelta(0.5, (float)$approved->getDays(), 0.011);
		}

		$after = $alloc->computeYearAllocation(
			$this->uid,
			$year,
			null,
			null,
			null,
			\DateTime::createFromImmutable($day),
			null,
			false
		);
		$delta = $remainingBefore - (float)$after['total_remaining_for_new_requests'];
		$this->assertEqualsWithDelta(0.5, $delta, 0.02, 'AC-G5b remaining decreases by 0.5 not 1.0');

		// AC-G7: cannot stack a second half on the same day.
		$overlapThrown = false;
		try {
			$this->withMigrateIdleRetry(fn () => $absenceService->createAbsence([
				'type' => Absence::TYPE_VACATION,
				'start_date' => $ymd,
				'end_date' => $ymd,
				'day_fraction' => '0.5',
				'reason' => 'overlap attempt',
			], $this->uid));
		} catch (BusinessRuleException $e) {
			if ($e->getReasonCode() === Constants::VAC_UNIT_MIGRATE_IN_PROGRESS) {
				throw $e;
			}
			$overlapThrown = true;
			$msg = mb_strtolower($e->getMessage());
			$looksLikeOverlap = str_contains($msg, 'overlap')
				|| str_contains($msg, 'überschneid')
				|| str_contains($msg, 'uberschneid')
				|| str_contains($msg, 'nakłada')
				|| str_contains($msg, 'naklada')
				|| str_contains($msg, 'chevauche')
				|| str_contains($msg, 'solapa')
				|| str_contains($msg, 'overlapp');
			$this->assertTrue(
				$looksLikeOverlap,
				'Overlap rejection message must be recognisable across locales, got: ' . $e->getMessage()
			);
		} catch (\Throwable $e) {
			$overlapThrown = true;
			// Locale-safe: EN "overlap", DE "überschneid", PL "nakłada", FR "chevauche", …
			$msg = mb_strtolower($e->getMessage());
			$looksLikeOverlap = str_contains($msg, 'overlap')
				|| str_contains($msg, 'überschneid')
				|| str_contains($msg, 'uberschneid')
				|| str_contains($msg, 'nakłada')
				|| str_contains($msg, 'naklada')
				|| str_contains($msg, 'chevauche')
				|| str_contains($msg, 'solapa')
				|| str_contains($msg, 'overlapp');
			$this->assertTrue(
				$looksLikeOverlap,
				'Overlap rejection message must be recognisable across locales, got: ' . $e->getMessage()
			);
		}
		$this->assertTrue($overlapThrown, 'Second half on same day must be rejected');
	}

	public function testManagerRecordedHalfDayApproved(): void
	{
		$absenceService = \OC::$server->get(AbsenceService::class);
		$day = new \DateTimeImmutable('tomorrow');
		while ((int)$day->format('N') > 5) {
			$day = $day->modify('+1 day');
		}
		$day = $day->modify('+21 days');
		while ((int)$day->format('N') > 5) {
			$day = $day->modify('+1 day');
		}
		$ymd = $day->format('Y-m-d');

		$row = $this->withMigrateIdleRetry(
			fn () => $absenceService->createApprovedAbsenceForEmployeeByManager($this->managerUid, $this->uid, [
				'type' => Absence::TYPE_VACATION,
				'start_date' => $ymd,
				'end_date' => $ymd,
				'day_fraction' => '0.5',
				'reason' => 'Manager half ' . bin2hex(random_bytes(2)),
			])
		);
		$this->assertSame(Absence::STATUS_APPROVED, $row->getStatus());
		$this->assertEqualsWithDelta(0.5, (float)$row->getDays(), 0.011);
	}

	public function testHalfDayRangeForbiddenAgainstLiveService(): void
	{
		$absenceService = \OC::$server->get(AbsenceService::class);
		$start = (new \DateTimeImmutable('monday next week'))->modify('+28 days');
		$end = $start->modify('+2 days');
		$this->expectException(\Throwable::class);
		$absenceService->createAbsence([
			'type' => Absence::TYPE_VACATION,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
			'day_fraction' => '0.5',
		], $this->uid);
	}
}
