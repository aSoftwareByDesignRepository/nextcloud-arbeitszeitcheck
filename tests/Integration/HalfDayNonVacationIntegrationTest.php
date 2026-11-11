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
}
