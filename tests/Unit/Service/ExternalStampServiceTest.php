<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ExternalStamp;
use OCA\ArbeitszeitCheck\Db\ExternalStampMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\ExternalStampService;
use OCA\ArbeitszeitCheck\Service\MobileStampReplayService;
use OCA\ArbeitszeitCheck\Service\MonthClosureGuard;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F16/F19b — propose validation, idempotent dedupe, and decision edge
 * semantics (same-decision replay ok, contradicting decision 409).
 */
class ExternalStampServiceTest extends TestCase
{
	private ExternalStampMapper $stamps;
	private IUserManager $userManager;
	private ExternalStampService $svc;

	private function makeService(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($m, $p = []) => vsprintf($m, (array)$p));
		$this->svc = new ExternalStampService(
			$this->stamps,
			$this->createMock(TimeEntryMapper::class),
			$this->createMock(AuditLogMapper::class),
			$this->createMock(TimeTrackingService::class),
			$this->createMock(MobileStampReplayService::class),
			$this->createMock(ComplianceService::class),
			$this->createMock(MonthClosureGuard::class),
			$this->userManager,
			$this->createMock(IDBConnection::class),
			$this->createMock(IConfig::class),
			$l10n,
			$this->createMock(ILockingProvider::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	protected function setUp(): void
	{
		$this->stamps = $this->createMock(ExternalStampMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->makeService();
	}

	private function knownUser(string $uid): void
	{
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with($uid)->willReturn($user);
	}

	public function testProposeRejectsUnknownUser(): void
	{
		$this->userManager->method('get')->willReturn(null);
		$this->assertNull($this->svc->propose('ghost', time(), null, 'tourcheck', 'round:1:start'));
	}

	public function testProposeRejectsDisallowedSourceApp(): void
	{
		$this->knownUser('w1');
		$this->assertNull($this->svc->propose('w1', time(), null, 'evil_app', 'x:1'));
	}

	public function testProposeRejectsMalformedRef(): void
	{
		$this->knownUser('w1');
		$this->assertNull($this->svc->propose('w1', time(), null, 'tourcheck', 'bad ref with spaces'));
		$this->assertNull($this->svc->propose('w1', time(), null, 'tourcheck', ''));
	}

	public function testProposeRejectsImpossibleTimestamps(): void
	{
		$this->knownUser('w1');
		$this->assertNull($this->svc->propose('w1', 100, null, 'tourcheck', 'r:1')); // pre-2000
		$this->assertNull($this->svc->propose('w1', time() + 3600, null, 'tourcheck', 'r:2')); // far future
	}

	public function testProposeRejectsBadSegment(): void
	{
		$this->knownUser('w1');
		$now = time();
		$this->assertNull($this->svc->propose('w1', $now, $now - 60, 'carecheck', 'visit:1')); // end<start
		$this->assertNull($this->svc->propose('w1', $now - 100000, $now, 'carecheck', 'visit:2')); // >24h
	}

	public function testProposeDedupesExistingRef(): void
	{
		$this->knownUser('w1');
		$existing = new ExternalStamp();
		$existing->setId(9);
		$existing->setStatus(ExternalStamp::STATUS_PENDING);
		$this->stamps->method('findBySourceRef')->willReturn($existing);
		$this->stamps->expects($this->never())->method('tryInsert');
		$out = $this->svc->propose('w1', time(), null, 'tourcheck', 'round:9:start');
		$this->assertSame(9, (int)$out['stamp']->getId());
		$this->assertFalse($out['created']);
	}

	public function testProposeInsertsPendingStamp(): void
	{
		$this->knownUser('w1');
		$this->stamps->method('findBySourceRef')->willReturn(null);
		$this->stamps->method('tryInsert')->willReturnCallback(static function (ExternalStamp $s) {
			$s->setId(11);
			return $s;
		});
		$out = $this->svc->propose('w1', time(), null, 'tourcheck', 'round:11:start');
		$this->assertTrue($out['created']);
		$this->assertSame(ExternalStamp::KIND_STAMP, $out['stamp']->getKind());
	}

	public function testProposeSegmentSetsKind(): void
	{
		$this->knownUser('w1');
		$this->stamps->method('findBySourceRef')->willReturn(null);
		$this->stamps->method('tryInsert')->willReturnCallback(static function (ExternalStamp $s) {
			$s->setId(12);
			return $s;
		});
		$now = time();
		$out = $this->svc->propose('w1', $now - 3600, $now, 'carecheck', 'cck:visit:5');
		$this->assertSame(ExternalStamp::KIND_SEGMENT, $out['stamp']->getKind());
	}

	public function testDecideOnUnknownStampIsNotFound(): void
	{
		$lock = $this->createMock(ILockingProvider::class);
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(true);
		$this->stamps->method('findForUserLocked')->willReturn(null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$svc = new ExternalStampService(
			$this->stamps,
			$this->createMock(TimeEntryMapper::class),
			$this->createMock(AuditLogMapper::class),
			$this->createMock(TimeTrackingService::class),
			$this->createMock(MobileStampReplayService::class),
			$this->createMock(ComplianceService::class),
			$this->createMock(MonthClosureGuard::class),
			$this->userManager,
			$db,
			$this->createMock(IConfig::class),
			$l10n,
			$lock,
			$this->createMock(LoggerInterface::class),
		);
		$this->expectException(BusinessRuleException::class);
		$this->expectExceptionMessage('Not found');
		$svc->confirm('w1', 55);
	}

	public function testContradictingDecisionIsRejected(): void
	{
		$lock = $this->createMock(ILockingProvider::class);
		$db = $this->createMock(IDBConnection::class);
		$stamp = new ExternalStamp();
		$stamp->setId(7);
		$stamp->setStatus(ExternalStamp::STATUS_CONFIRMED);
		$stamp->setPromotedEntryId(88);
		$this->stamps->method('findForUserLocked')->willReturn($stamp);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$svc = new ExternalStampService(
			$this->stamps,
			$this->createMock(TimeEntryMapper::class),
			$this->createMock(AuditLogMapper::class),
			$this->createMock(TimeTrackingService::class),
			$this->createMock(MobileStampReplayService::class),
			$this->createMock(ComplianceService::class),
			$this->createMock(MonthClosureGuard::class),
			$this->userManager,
			$db,
			$this->createMock(IConfig::class),
			$l10n,
			$lock,
			$this->createMock(LoggerInterface::class),
		);
		$this->expectException(BusinessRuleException::class);
		$this->expectExceptionMessage('already decided');
		$svc->dismiss('w1', 7);
	}

	public function testRepeatedSameDecisionIsIdempotent(): void
	{
		$lock = $this->createMock(ILockingProvider::class);
		$db = $this->createMock(IDBConnection::class);
		$stamp = new ExternalStamp();
		$stamp->setId(7);
		$stamp->setStatus(ExternalStamp::STATUS_CONFIRMED);
		$stamp->setPromotedEntryId(88);
		$this->stamps->method('findForUserLocked')->willReturn($stamp);
		$l10n = $this->createMock(IL10N::class);
		$svc = new ExternalStampService(
			$this->stamps,
			$this->createMock(TimeEntryMapper::class),
			$this->createMock(AuditLogMapper::class),
			$this->createMock(TimeTrackingService::class),
			$this->createMock(MobileStampReplayService::class),
			$this->createMock(ComplianceService::class),
			$this->createMock(MonthClosureGuard::class),
			$this->userManager,
			$db,
			$this->createMock(IConfig::class),
			$l10n,
			$lock,
			$this->createMock(LoggerInterface::class),
		);
		$this->assertSame(['timeEntryId' => 88], $svc->confirm('w1', 7));
	}
}
