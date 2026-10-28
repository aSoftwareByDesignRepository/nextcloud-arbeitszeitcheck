<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Test\TestCase;

/**
 * Real-DB coverage for AbsenceService::convertVacationToSick —
 * spec: planning/features/arbeitszeitcheck/vacation-to-sick-conversion-2026-10
 *
 * Verifies the atomic write set (vacation shorten/cancel/split + sick row +
 * linked audit rows), the revision CAS, and the role matrix against the real
 * mapper/closure/lock stack.
 *
 * @group integration
 */
final class VacationToSickConversionIntegrationTest extends TestCase
{
	private string $uid = '';
	private string $managerUid = '';
	/** @var list<int> */
	private array $createdAbsenceIds = [];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$config = \OCP\Server::get(IConfig::class);
		$config->deleteAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING);
		$config->setAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_UNIT, Constants::VACATION_UNIT_DAYS);
		$this->awaitVacationUnitMigrateIdle();

		$um = \OCP\Server::get(IUserManager::class);
		$this->uid = 'azc_c2s_' . bin2hex(random_bytes(3));
		$this->managerUid = 'azc_c2sm_' . bin2hex(random_bytes(3));
		foreach ([$this->uid, $this->managerUid] as $id) {
			if ($um->userExists($id)) {
				$um->get($id)?->delete();
			}
			$um->createUser($id, 'Azc-C2S-' . bin2hex(random_bytes(4)) . '!');
			$config->setUserValue($id, 'core', 'lang', 'en');
		}
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		try {
			$mapper = \OCP\Server::get(AbsenceMapper::class);
			foreach ($this->createdAbsenceIds as $id) {
				try {
					$mapper->delete($mapper->find($id));
				} catch (\Throwable) {
				}
			}
		} catch (\Throwable) {
		}
		try {
			\OCP\Server::get(IUserSession::class)->setUser(null);
		} catch (\Throwable) {
		}
		$um = \OCP\Server::get(IUserManager::class);
		foreach ([$this->uid, $this->managerUid] as $id) {
			if ($id === '') {
				continue;
			}
			try {
				$um->get($id)?->delete();
			} catch (\Throwable) {
			}
		}
	}

	private function awaitVacationUnitMigrateIdle(int $attempts = 40): void
	{
		$config = \OCP\Server::get(IConfig::class);
		$config->deleteAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING);
		$locking = \OCP\Server::get(ILockingProvider::class);
		$key = DbLockKeys::vacationUnitMigration();
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		for ($i = 0; $i < $attempts; $i++) {
			try {
				$locking->acquireLock($key, ILockingProvider::LOCK_SHARED, 'c2s migrate idle wait');
				$locking->releaseLock($key, ILockingProvider::LOCK_SHARED);
				return;
			} catch (LockedException) {
				if ($i === 3 || $i === 12 || $i === 24) {
					try {
						$qb = $db->getQueryBuilder();
						$qb->delete('file_locks')
							->where($qb->expr()->eq('key', $qb->createNamedParameter($key)));
						$qb->executeStatement();
					} catch (\Throwable) {
					}
				}
				usleep(200_000);
			}
		}
		$this->fail('Vacation unit migrate lock still exclusive after wait');
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

	private function seedVacation(string $start, string $end, ?string $userId = null): Absence
	{
		$mapper = \OCP\Server::get(AbsenceMapper::class);
		$owner = $userId ?? $this->uid;
		$a = new Absence();
		$a->setUserId($owner);
		$a->setType(Absence::TYPE_VACATION);
		$a->setStatus(Absence::STATUS_APPROVED);
		$startDate = new \DateTime($start);
		$endDate = new \DateTime($end);
		$a->setStartDate($startDate);
		$a->setEndDate($endDate);
		// Mirror what createAbsence persists: stored days == computed working
		// days, otherwise the fraction-boundary guard fires (correctly).
		$a->setDays(\OCP\Server::get(\OCA\ArbeitszeitCheck\Service\HolidayService::class)
			->computeWorkingDaysForUser($owner, $startDate, $endDate));
		$now = new \DateTime();
		$a->setCreatedAt($now);
		$a->setUpdatedAt($now);
		$saved = $mapper->insert($a);
		$this->createdAbsenceIds[] = (int)$saved->getId();
		return $saved;
	}

	private function convertData(Absence $a, string $sickStart, string $sickEnd, array $extra = []): array
	{
		return array_merge([
			'sickStart' => (new \DateTime($sickStart))->format('Y-m-d'),
			'sickEnd' => (new \DateTime($sickEnd))->format('Y-m-d'),
			'expectedUpdatedAt' => $a->getUpdatedAt()->format('c'),
			'reason' => '',
		], $extra);
	}

	private function auditActionsFor(int $absenceId): array
	{
		$rows = \OCP\Server::get(AuditLogMapper::class)->findByEntity('absence', $absenceId, 20, 0);
		return array_map(static fn ($row) => $row->getAction(), $rows);
	}

	public function testOwnerTailConversionShortensVacationAndCreatesPendingSick(): void
	{
		$vacation = $this->seedVacation('-5 days', '+5 days');
		$service = \OCP\Server::get(AbsenceService::class);

		$result = $this->withMigrateIdleRetry(fn () => $service->convertVacationToSick(
			(int)$vacation->getId(),
			$this->convertData($vacation, '+3 days', '+5 days'),
			$this->uid,
			false
		));
		$this->createdAbsenceIds[] = (int)$result['sick']['id'];

		$mapper = \OCP\Server::get(AbsenceMapper::class);
		$storedVacation = $mapper->find((int)$vacation->getId());
		$this->assertSame((new \DateTime('+2 days'))->format('Y-m-d'), $storedVacation->getEndDate()->format('Y-m-d'));
		$this->assertSame(Absence::STATUS_APPROVED, $storedVacation->getStatus());

		$storedSick = $mapper->find((int)$result['sick']['id']);
		$this->assertSame(Absence::TYPE_SICK_LEAVE, $storedSick->getType());
		$this->assertSame(Absence::STATUS_PENDING, $storedSick->getStatus());
		$this->assertSame($this->uid, $storedSick->getUserId());

		$this->assertContains('absence_converted_to_sick', $this->auditActionsFor((int)$vacation->getId()));
		$this->assertContains('absence_created', $this->auditActionsFor((int)$storedSick->getId()));
	}

	public function testManagerFullConversionOnEndedVacationCancelsAndApproves(): void
	{
		$vacation = $this->seedVacation('-10 days', '-3 days');
		$service = \OCP\Server::get(AbsenceService::class);

		$result = $this->withMigrateIdleRetry(fn () => $service->convertVacationToSick(
			(int)$vacation->getId(),
			$this->convertData($vacation, '-10 days', '-3 days'),
			$this->managerUid,
			true
		));
		$this->createdAbsenceIds[] = (int)$result['sick']['id'];

		$mapper = \OCP\Server::get(AbsenceMapper::class);
		$this->assertSame(Absence::STATUS_CANCELLED, $mapper->find((int)$vacation->getId())->getStatus());
		$storedSick = $mapper->find((int)$result['sick']['id']);
		$this->assertSame(Absence::STATUS_APPROVED, $storedSick->getStatus());
		$this->assertSame($this->managerUid, $storedSick->getApprovedByUserId());
		$this->assertContains('absence_converted_to_sick', $this->auditActionsFor((int)$vacation->getId()));
	}

	public function testManagerMidSplitCreatesTailVacationRow(): void
	{
		$vacation = $this->seedVacation('-10 days', '-2 days');
		$service = \OCP\Server::get(AbsenceService::class);

		$result = $this->withMigrateIdleRetry(fn () => $service->convertVacationToSick(
			(int)$vacation->getId(),
			$this->convertData($vacation, '-7 days', '-5 days'),
			$this->managerUid,
			true
		));
		$this->assertNotNull($result['tail']);
		$this->createdAbsenceIds[] = (int)$result['sick']['id'];
		$this->createdAbsenceIds[] = (int)$result['tail']['id'];

		$mapper = \OCP\Server::get(AbsenceMapper::class);
		$head = $mapper->find((int)$vacation->getId());
		$tail = $mapper->find((int)$result['tail']['id']);
		$this->assertSame((new \DateTime('-8 days'))->format('Y-m-d'), $head->getEndDate()->format('Y-m-d'));
		$this->assertSame((new \DateTime('-4 days'))->format('Y-m-d'), $tail->getStartDate()->format('Y-m-d'));
		$this->assertSame((new \DateTime('-2 days'))->format('Y-m-d'), $tail->getEndDate()->format('Y-m-d'));
		$this->assertSame(Absence::STATUS_APPROVED, $tail->getStatus());
		$this->assertSame(Absence::TYPE_VACATION, $tail->getType());
		$this->assertContains('absence_split_created', $this->auditActionsFor((int)$tail->getId()));
	}

	public function testStaleRevisionLeavesEverythingUntouched(): void
	{
		$vacation = $this->seedVacation('-5 days', '+5 days');
		$service = \OCP\Server::get(AbsenceService::class);
		$data = $this->convertData($vacation, '+1 days', '+3 days');
		$data['expectedUpdatedAt'] = '2001-01-01T00:00:00+00:00';

		$before = count(\OCP\Server::get(AbsenceMapper::class)->findByUser($this->uid, 100, 0));
		try {
			$this->withMigrateIdleRetry(fn () => $service->convertVacationToSick((int)$vacation->getId(), $data, $this->uid, false));
			$this->fail('stale revision must be rejected');
		} catch (\Exception $e) {
			$this->assertSame('revision_stale', $e->getMessage());
		}
		$after = \OCP\Server::get(AbsenceMapper::class)->findByUser($this->uid, 100, 0);
		$this->assertCount($before, $after);
		$this->assertSame((new \DateTime('+5 days'))->format('Y-m-d'), $vacation->getEndDate()->format('Y-m-d'));
	}

	public function testDryRunReturnsPlanWithoutWrites(): void
	{
		$vacation = $this->seedVacation('-5 days', '+5 days');
		$service = \OCP\Server::get(AbsenceService::class);
		$before = count(\OCP\Server::get(AbsenceMapper::class)->findByUser($this->uid, 100, 0));

		$plan = $service->convertVacationToSick(
			(int)$vacation->getId(),
			$this->convertData($vacation, '+1 days', '+3 days', ['dryRun' => '1']),
			$this->uid,
			false
		);
		$this->assertSame('shorten_and_split', $plan['vacation']['action']);
		$this->assertNotNull($plan['tail']);
		$this->assertCount($before, \OCP\Server::get(AbsenceMapper::class)->findByUser($this->uid, 100, 0));
		$this->assertSame([], $this->auditActionsFor((int)$vacation->getId()));
	}

	public function testManagerTailConversionHoursModeRecomputesDurationHours(): void
	{
		// Hours mode: the shortened vacation's duration_hours must be
		// recomputed server-side (schedule-aware), never left at the
		// pre-conversion value — the sick leave credits vacation hours back.
		$config = \OCP\Server::get(IConfig::class);
		$config->setAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_UNIT, Constants::VACATION_UNIT_HOURS);
		try {
			$vacation = $this->seedVacation('-10 days', '-3 days');
			$mapper = \OCP\Server::get(AbsenceMapper::class);
			$service = \OCP\Server::get(AbsenceService::class);

			$result = $this->withMigrateIdleRetry(fn () => $service->convertVacationToSick(
				(int)$vacation->getId(),
				$this->convertData($vacation, '-6 days', '-3 days'),
				$this->managerUid,
				true
			));

			$fresh = $mapper->find((int)$vacation->getId());
			$this->assertSame((new \DateTime('-7 days'))->format('Y-m-d'), $fresh->getEndDate()->format('Y-m-d'));
			$hours = $fresh->getDurationHours();
			$this->assertNotNull($hours, 'hours mode must recompute duration_hours, not drop it');
			$this->assertGreaterThan(0.0, (float)$hours);
			$this->assertSame('approved', $result['sick']['status']);
		} finally {
			$config->setAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_UNIT, Constants::VACATION_UNIT_DAYS);
		}
	}
}
