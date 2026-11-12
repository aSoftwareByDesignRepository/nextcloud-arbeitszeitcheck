<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/**
 * AZC-HALF-DAY-NON-VACATION — opt-in half-day for non-vacation types
 * against the real DB + schedule-aware counter.
 *
 * @group integration
 */
final class HalfDayNonVacationIntegrationTest extends TestCase
{
	private string $uid = '';
	private string $managerUid = '';
	private ?string $prevEnabled = null;
	/** @var list<int> */
	private array $createdAbsenceIds = [];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$config = \OCP\Server::get(IConfig::class);
		$this->prevEnabled = $config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_HALF_DAY_ENABLED_TYPES,
			Constants::CONFIG_HALF_DAY_ENABLED_TYPES_DEFAULT
		);
		$config->setAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_HALF_DAY_ENABLED_TYPES,
			'["vacation","sick_leave"]'
		);

		$um = \OCP\Server::get(IUserManager::class);
		$this->uid = 'azc_hdnv_' . bin2hex(random_bytes(3));
		$this->managerUid = 'azc_mgrn_' . bin2hex(random_bytes(3));
		foreach ([$this->uid, $this->managerUid] as $id) {
			if ($um->userExists($id)) {
				$um->get($id)?->delete();
			}
			$um->createUser($id, 'Azc-HdNv-' . bin2hex(random_bytes(4)) . '!');
			$config->setUserValue($id, 'core', 'lang', 'en');
		}
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
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
		foreach ($this->createdAbsenceIds as $absenceId) {
			try {
				\OCP\Server::get(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class)->delete(
					\OCP\Server::get(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class)->find($absenceId)
				);
			} catch (\Throwable) {
			}
		}
		$this->createdAbsenceIds = [];
		if ($this->prevEnabled !== null) {
			try {
				\OCP\Server::get(IConfig::class)->setAppValue(
					'arbeitszeitcheck',
					Constants::CONFIG_HALF_DAY_ENABLED_TYPES,
					$this->prevEnabled
				);
			} catch (\Throwable) {
			}
		}
	}

	private function weekday(int $daysAhead): string
	{
		$day = new \DateTimeImmutable('tomorrow');
		$day = $day->modify('+' . $daysAhead . ' days');
		while ((int)$day->format('N') > 5) {
			$day = $day->modify('+1 day');
		}
		return $day->format('Y-m-d');
	}

	public function testCreateSickHalfDayPersistsHalfWeight(): void
	{
		$user = \OCP\Server::get(IUserManager::class)->get($this->uid);
		\OCP\Server::get(IUserSession::class)->setUser($user);
		$ymd = $this->weekday(14);

		$row = \OCP\Server::get(AbsenceService::class)->createAbsence([
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $ymd,
			'end_date' => $ymd,
			'day_fraction' => '0.5',
			'reason' => 'Half sick ' . bin2hex(random_bytes(2)),
		], $this->uid);

		$this->assertGreaterThan(0, (int)$row->getId());
		// Schedule weight for a weekday is 1.0 → half stores 0.5.
		$this->assertEqualsWithDelta(0.5, (float)$row->getDays(), 0.011);
	}

	public function testCreateSickHalfDayRejectedWhenTypeDisabled(): void
	{
		$config = \OCP\Server::get(IConfig::class);
		$config->setAppValue('arbeitszeitcheck', Constants::CONFIG_HALF_DAY_ENABLED_TYPES, '["vacation"]');
		$ymd = $this->weekday(15);

		try {
			\OCP\Server::get(AbsenceService::class)->createAbsence([
				'type' => Absence::TYPE_SICK_LEAVE,
				'start_date' => $ymd,
				'end_date' => $ymd,
				'day_fraction' => '0.5',
			], $this->uid);
			$this->fail('Expected HALF_DAY_TYPE_NOT_ENABLED');
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::HALF_DAY_TYPE_NOT_ENABLED, $e->getReasonCode());
		}
	}

	public function testCreateSickMultiDayHalfRejected(): void
	{
		$start = new \DateTimeImmutable($this->weekday(16));
		$end = $start->modify('+1 day');
		while ((int)$end->format('N') > 5) {
			$end = $end->modify('+1 day');
		}
		try {
			\OCP\Server::get(AbsenceService::class)->createAbsence([
				'type' => Absence::TYPE_SICK_LEAVE,
				'start_date' => $start->format('Y-m-d'),
				'end_date' => $end->format('Y-m-d'),
				'day_fraction' => '0.5',
			], $this->uid);
			$this->fail('Expected HALF_DAY_RANGE_FORBIDDEN');
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::HALF_DAY_RANGE_FORBIDDEN, $e->getReasonCode());
		}
	}

	public function testManagerRecordedSickHalfDayApproved(): void
	{
		$ymd = $this->weekday(21);
		$row = \OCP\Server::get(AbsenceService::class)
			->createApprovedAbsenceForEmployeeByManager($this->managerUid, $this->uid, [
				'type' => Absence::TYPE_SICK_LEAVE,
				'start_date' => $ymd,
				'end_date' => $ymd,
				'day_fraction' => '0.5',
				'reason' => 'Manager half sick ' . bin2hex(random_bytes(2)),
			]);
		$this->assertSame(Absence::STATUS_APPROVED, $row->getStatus());
		$this->assertEqualsWithDelta(0.5, (float)$row->getDays(), 0.011);

		// AC-G2: the manager-recorded audit row must carry the half debit.
		$audit = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class)
			->findByEntity('absence', (int)$row->getId(), 20, 0);
		$recorded = array_values(array_filter(
			$audit,
			static fn ($a) => $a->getAction() === 'absence_manager_recorded'
		));
		$this->assertNotEmpty($recorded, 'absence_manager_recorded audit row missing');
		$newValues = json_decode((string)$recorded[0]->getNewValues(), true);
		$this->assertIsArray($newValues);
		$this->assertEqualsWithDelta(0.5, (float)($newValues['days'] ?? -1), 0.011);
	}

	public function testOverlapBlocksSecondHalfSameDay(): void
	{
		$user = \OCP\Server::get(IUserManager::class)->get($this->uid);
		\OCP\Server::get(IUserSession::class)->setUser($user);
		$ymd = $this->weekday(28);
		$svc = \OCP\Server::get(AbsenceService::class);
		$svc->createAbsence([
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $ymd,
			'end_date' => $ymd,
			'day_fraction' => '0.5',
		], $this->uid);

		$this->expectException(\Throwable::class);
		$svc->createAbsence([
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $ymd,
			'end_date' => $ymd,
			'day_fraction' => '0.5',
		], $this->uid);
	}

	public function testUpdateWithoutFractionPreservesStoredHalf(): void
	{
		// Rows auto-approve when the employee has no assignable manager; pin a
		// real team + manager so this one stays PENDING and updatable.
		$teamMapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\TeamMapper::class);
		$team = new \OCA\ArbeitszeitCheck\Db\Team();
		$team->setName('azc-hdnv-' . bin2hex(random_bytes(3)));
		$team->setCreatedAt(new \DateTime());
		$team = $teamMapper->insert($team);
		try {
			\OCP\Server::get(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class)
				->addMember((int)$team->getId(), $this->uid);
			\OCP\Server::get(\OCA\ArbeitszeitCheck\Db\TeamManagerMapper::class)
				->addManager((int)$team->getId(), $this->managerUid);

			$user = \OCP\Server::get(IUserManager::class)->get($this->uid);
			\OCP\Server::get(IUserSession::class)->setUser($user);
			$ymd = $this->weekday(35);
			$svc = \OCP\Server::get(AbsenceService::class);
			$row = $svc->createAbsence([
				'type' => Absence::TYPE_SICK_LEAVE,
				'start_date' => $ymd,
				'end_date' => $ymd,
				'day_fraction' => '0.5',
			], $this->uid);
			$this->assertSame(Absence::STATUS_PENDING, $row->getStatus());
			$this->assertEqualsWithDelta(0.5, (float)$row->getDays(), 0.011);

			// Reason-only patch must not inflate the stored fraction.
			$updated = $svc->updateAbsence((int)$row->getId(), [
				'reason' => 'still sick ' . bin2hex(random_bytes(2)),
			], $this->uid);
			$this->assertEqualsWithDelta(0.5, (float)$updated->getDays(), 0.011);
		} finally {
			try {
				$teamMapper->delete($team);
			} catch (\Throwable) {
			}
		}
	}

	public function testVacationHalfDayConvertsToSickHalfDay(): void
	{
		// Past weekday so the manager conversion path accepts the range.
		$day = new \DateTimeImmutable('-7 days');
		while ((int)$day->format('N') > 5) {
			$day = $day->modify('-1 day');
		}
		$ymd = $day->format('Y-m-d');

		$mapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class);
		$vacation = new Absence();
		$vacation->setUserId($this->uid);
		$vacation->setType(Absence::TYPE_VACATION);
		$vacation->setStatus(Absence::STATUS_APPROVED);
		$vacation->setStartDate(new \DateTime($ymd));
		$vacation->setEndDate(new \DateTime($ymd));
		$vacation->setDays(0.5);
		$vacation->setCreatedAt(new \DateTime());
		$vacation->setUpdatedAt(new \DateTime());
		$vacation = $mapper->insert($vacation);
		$this->createdAbsenceIds[] = (int)$vacation->getId();

		$result = \OCP\Server::get(AbsenceService::class)->convertVacationToSick(
			(int)$vacation->getId(),
			[
				'sickStart' => $ymd,
				'sickEnd' => $ymd,
				'expectedUpdatedAt' => $vacation->getUpdatedAt()->format('c'),
				'reason' => 'got sick on vacation',
			],
			$this->managerUid,
			true
		);
		$this->createdAbsenceIds[] = (int)$result['sick']['id'];

		$storedSick = $mapper->find((int)$result['sick']['id']);
		$this->assertSame(Absence::TYPE_SICK_LEAVE, $storedSick->getType());
		// The stored fraction carries: 0.5 vacation must not become a full sick day.
		$this->assertEqualsWithDelta(0.5, (float)$storedSick->getDays(), 0.011);
	}

	public function testComplementarySlotHalvesCoexistOnSameDay(): void
	{
		\OCP\Server::get(IConfig::class)->setAppValue(
			'arbeitszeitcheck', Constants::CONFIG_HALF_DAY_ENABLED_TYPES,
			'["sick_leave","personal_leave"]'
		);
		$user = \OCP\Server::get(IUserManager::class)->get($this->uid);
		\OCP\Server::get(IUserSession::class)->setUser($user);
		$ymd = $this->weekday(70);
		$svc = \OCP\Server::get(AbsenceService::class);

		$am = $svc->createAbsence([
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $ymd, 'end_date' => $ymd,
			'day_fraction' => '0.5', 'day_slot' => 'am',
		], $this->uid);
		$this->createdAbsenceIds[] = (int)$am->getId();
		$pm = $svc->createAbsence([
			'type' => Absence::TYPE_PERSONAL_LEAVE,
			'start_date' => $ymd, 'end_date' => $ymd,
			'day_fraction' => '0.5', 'day_slot' => 'pm',
		], $this->uid);
		$this->createdAbsenceIds[] = (int)$pm->getId();

		// DB ground truth: both rows exist with explicit slot columns.
		$mapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class);
		$storedAm = $mapper->find((int)$am->getId());
		$storedPm = $mapper->find((int)$pm->getId());
		$this->assertSame('am', $storedAm->getStartDaySlot());
		$this->assertSame('pm', $storedPm->getStartDaySlot());
		$this->assertEqualsWithDelta(0.5, (float)$storedAm->getStartDayFraction(), 0.001);
		$this->assertEqualsWithDelta(0.5, (float)$storedPm->getStartDayFraction(), 0.001);

		// A third half on that day must still be rejected — coverage is full.
		try {
			$svc->createAbsence([
				'type' => Absence::TYPE_SICK_LEAVE,
				'start_date' => $ymd, 'end_date' => $ymd,
				'day_fraction' => '0.5', 'day_slot' => 'am',
			], $this->uid);
			$this->fail('Third half-day on a fully covered day must be rejected');
		} catch (\Throwable $e) {
			$this->assertStringContainsString('overlap', $e->getMessage());
		}
	}

	public function testSameSlotHalvesStillRejected(): void
	{
		\OCP\Server::get(IConfig::class)->setAppValue(
			'arbeitszeitcheck', Constants::CONFIG_HALF_DAY_ENABLED_TYPES,
			'["sick_leave","personal_leave"]'
		);
		$user = \OCP\Server::get(IUserManager::class)->get($this->uid);
		\OCP\Server::get(IUserSession::class)->setUser($user);
		$ymd = $this->weekday(71);
		$svc = \OCP\Server::get(AbsenceService::class);

		$first = $svc->createAbsence([
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $ymd, 'end_date' => $ymd,
			'day_fraction' => '0.5', 'day_slot' => 'am',
		], $this->uid);
		$this->createdAbsenceIds[] = (int)$first->getId();

		try {
			$svc->createAbsence([
				'type' => Absence::TYPE_PERSONAL_LEAVE,
				'start_date' => $ymd, 'end_date' => $ymd,
				'day_fraction' => '0.5', 'day_slot' => 'am',
			], $this->uid);
			$this->fail('Same-slot halves must be rejected');
		} catch (\Throwable $e) {
			$this->assertStringContainsString('overlap', $e->getMessage());
		}
	}

	public function testSlotlessHalfCannotPairComplementary(): void
	{
		// Fail-closed: a legacy/slotless half cannot prove complementary
		// coverage, so a slotted second half may not share the day.
		\OCP\Server::get(IConfig::class)->setAppValue(
			'arbeitszeitcheck', Constants::CONFIG_HALF_DAY_ENABLED_TYPES,
			'["sick_leave","personal_leave"]'
		);
		$user = \OCP\Server::get(IUserManager::class)->get($this->uid);
		\OCP\Server::get(IUserSession::class)->setUser($user);
		$ymd = $this->weekday(72);
		$svc = \OCP\Server::get(AbsenceService::class);

		$first = $svc->createAbsence([
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $ymd, 'end_date' => $ymd,
			'day_fraction' => '0.5',
		], $this->uid);
		$this->createdAbsenceIds[] = (int)$first->getId();

		try {
			$svc->createAbsence([
				'type' => Absence::TYPE_PERSONAL_LEAVE,
				'start_date' => $ymd, 'end_date' => $ymd,
				'day_fraction' => '0.5', 'day_slot' => 'pm',
			], $this->uid);
			$this->fail('Slotless half must not pair with a slotted half');
		} catch (\Throwable $e) {
			$this->assertStringContainsString('overlap', $e->getMessage());
		}
	}

	public function testMultiDayBoundaryHalvesPersist(): void
	{
		$user = \OCP\Server::get(IUserManager::class)->get($this->uid);
		\OCP\Server::get(IUserSession::class)->setUser($user);
		$start = new \DateTimeImmutable($this->weekday(80));
		$end = $start->modify('+2 days');
		while ((int)$end->format('N') > 5) {
			$end = $end->modify('+1 day');
		}
		$svc = \OCP\Server::get(AbsenceService::class);
		$row = $svc->createAbsence([
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
			'start_day_fraction' => '0.5', 'start_day_slot' => 'am',
			'end_day_fraction' => '0.5', 'end_day_slot' => 'pm',
		], $this->uid);
		$this->createdAbsenceIds[] = (int)$row->getId();

		$mapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class);
		$stored = $mapper->find((int)$row->getId());
		$this->assertSame('am', $stored->getStartDaySlot());
		$this->assertSame('pm', $stored->getEndDaySlot());
		$this->assertEqualsWithDelta(0.5, (float)$stored->getStartDayFraction(), 0.001);
		$this->assertEqualsWithDelta(0.5, (float)$stored->getEndDayFraction(), 0.001);
		// interior day (1.0) + two boundary halves (0.5 + 0.5) on a 3-day range.
		$this->assertEqualsWithDelta(2.0, (float)$stored->getDays(), 0.011);

		// A full-day absence overlapping the interior day is still rejected.
		$interior = $start->modify('+1 day');
		while ((int)$interior->format('N') > 5) {
			$interior = $interior->modify('+1 day');
		}
		try {
			$svc->createAbsence([
				'type' => Absence::TYPE_PERSONAL_LEAVE,
				'start_date' => $interior->format('Y-m-d'),
				'end_date' => $interior->format('Y-m-d'),
			], $this->uid);
			$this->fail('Full-day overlap on the interior day must be rejected');
		} catch (\Throwable $e) {
			$this->assertStringContainsString('overlap', $e->getMessage());
		}
	}
}
