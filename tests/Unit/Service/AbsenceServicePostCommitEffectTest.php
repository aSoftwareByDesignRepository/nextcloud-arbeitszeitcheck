<?php

declare(strict_types=1);

/**
 * AbsenceService post-commit side-effect contract.
 *
 * Manager bulk decisions run AbsenceService::approveAbsence()/rejectAbsence()
 * inside their own OUTER transaction. The service's inner commit() is not
 * final there — an outer rollback can still revert the decision. SMTP mail
 * cannot roll back, so mail effects must be deferred via PostCommitEffectQueue
 * whenever a transaction is still open, and run inline only when the
 * service's own commit was the outermost one.
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
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AbsenceServicePostCommitEffectTest extends TestCase
{
	private AbsenceMapper $absenceMapper;
	private AuditLogMapper $auditLogMapper;
	private IDBConnection $db;
	private AbsenceIcalMailService $icalMail;
	private AbsenceNotificationMailService $hrMail;
	private int $icalCalls = 0;
	private int $hrCalls = 0;

	protected function setUp(): void
	{
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->icalMail = $this->createMock(AbsenceIcalMailService::class);
		$this->hrMail = $this->createMock(AbsenceNotificationMailService::class);
		$this->icalCalls = 0;
		$this->hrCalls = 0;
		$this->icalMail->method('sendIcalForApprovedAbsence')
			->willReturnCallback(function () {
				$this->icalCalls++;
			});
		$this->hrMail->method('sendHrOfficeNotification')
			->willReturnCallback(function () {
				$this->hrCalls++;
			});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$this->l10n = $l10n;
	}

	private IL10N $l10n;

	private function makeService(?PostCommitEffectQueue $queue): AbsenceService
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
			$this->icalMail,
			$this->createMock(HolidayService::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(VacationAllocationService::class),
			$this->hrMail,
			$this->createMock(MonthClosureService::class),
			null,
			null,
			null,
			$this->createMock(VacationHoursDebitService::class),
			$this->createMock(ManagerPendingApprovalMailService::class),
			$this->createMock(TimeEntryMapper::class),
			null,
			$queue,
		);
	}

	private function pendingSickAbsence(int $id): Absence
	{
		$absence = new Absence();
		$absence->setId($id);
		$absence->setUserId('employee');
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStartDate(new \DateTime('+3 days'));
		$absence->setEndDate(new \DateTime('+5 days'));
		$absence->setDays(3.0);
		return $absence;
	}

	public function testMailEffectsDeferWhileOuterTransactionOpen(): void
	{
		$this->db->method('inTransaction')->willReturn(true);
		$queue = new PostCommitEffectQueue($this->db, $this->createMock(LoggerInterface::class));
		$service = $this->makeService($queue);

		$absence = $this->pendingSickAbsence(7);
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a): Absence => $a);

		$service->approveAbsence(7, 'manager', 'ok');

		$this->assertSame(0, $this->icalCalls, 'iCal mail must not fire while the outer transaction is open');
		$this->assertSame(0, $this->hrCalls, 'HR mail must not fire while the outer transaction is open');
		// 2 mails + 1 vacation-stats cache invalidation, all deferred together.
		$this->assertSame(3, $queue->pendingCount(), 'all post-commit effects must be queued for post-commit flush');

		$queue->flush();

		$this->assertSame(1, $this->icalCalls);
		$this->assertSame(1, $this->hrCalls);
	}

	public function testMailEffectsRunInlineWhenOwnCommitIsFinal(): void
	{
		$this->db->method('inTransaction')->willReturn(false);
		$queue = new PostCommitEffectQueue($this->db, $this->createMock(LoggerInterface::class));
		$service = $this->makeService($queue);

		$absence = $this->pendingSickAbsence(7);
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a): Absence => $a);

		$service->approveAbsence(7, 'manager', 'ok');

		$this->assertSame(1, $this->icalCalls, 'without an outer transaction the mail must still fire (legacy semantics)');
		$this->assertSame(1, $this->hrCalls);
		$this->assertSame(0, $queue->pendingCount());
	}

	public function testRejectHrMailDefersWhileOuterTransactionOpen(): void
	{
		$this->db->method('inTransaction')->willReturn(true);
		$queue = new PostCommitEffectQueue($this->db, $this->createMock(LoggerInterface::class));
		$service = $this->makeService($queue);

		$absence = $this->pendingSickAbsence(9);
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a): Absence => $a);

		$service->rejectAbsence(9, 'manager', 'no');

		$this->assertSame(0, $this->hrCalls);
		// 1 mail + 1 vacation-stats cache invalidation.
		$this->assertSame(2, $queue->pendingCount());

		$queue->discard();
		$queue->flush();
		$this->assertSame(0, $this->hrCalls, 'a discarded batch must never emit rejection mail');
	}
}
