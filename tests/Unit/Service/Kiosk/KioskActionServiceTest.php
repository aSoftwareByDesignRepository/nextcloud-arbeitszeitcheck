<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service\Kiosk;

use OCA\ArbeitszeitCheck\BusinessRuleCode;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Db\KioskSession;
use OCA\ArbeitszeitCheck\Db\KioskSessionMapper;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskActionService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskAuthService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskBusinessRuleMapper;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class KioskActionServiceTest extends TestCase
{
	private function createService(
		KioskAuthService $auth,
		KioskSessionMapper $sessionMapper,
		TimeTrackingService $tracking,
		?IL10N $l10n = null,
		?ITimeFactory $timeFactory = null,
	): KioskActionService {
		return new KioskActionService(
			$auth,
			$sessionMapper,
			$tracking,
			$this->createMock(AuditLogMapper::class),
			new KioskBusinessRuleMapper(),
			$l10n ?? $this->createMock(IL10N::class),
			$timeFactory ?? $this->createMock(ITimeFactory::class),
		);
	}

	public function testPerformActionReChecksUserEligibility(): void
	{
		$terminal = new KioskTerminal();
		$terminal->setTerminalId('tid-1');

		$session = new KioskSession();
		$session->setId(9);
		$session->setUserId('alice');

		$auth = $this->createMock(KioskAuthService::class);
		$auth->method('validateSession')->willReturn($session);
		$auth->expects(self::once())
			->method('assertUserEligibleForAction')
			->with('alice')
			->willThrowException(new KioskException('KIOSK_USER_NOT_ALLOWED'));

		$sessionMapper = $this->createMock(KioskSessionMapper::class);
		$sessionMapper->expects(self::never())->method('claimUnused');

		$service = $this->createService(
			$auth,
			$sessionMapper,
			$this->createMock(TimeTrackingService::class),
		);

		$this->expectException(KioskException::class);
		$service->performAction($terminal, 'session-token', 'clock_in');
	}

	public function testPerformActionClaimsSessionBeforeClockIn(): void
	{
		$terminal = new KioskTerminal();
		$terminal->setTerminalId('tid-1');

		$session = new KioskSession();
		$session->setId(9);
		$session->setUserId('alice');

		$auth = $this->createMock(KioskAuthService::class);
		$auth->method('validateSession')->willReturn($session);
		$auth->method('assertUserEligibleForAction');

		$now = new \DateTime('2026-06-10 12:00:00');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);

		$sessionMapper = $this->createMock(KioskSessionMapper::class);
		$sessionMapper->expects(self::once())
			->method('claimUnused')
			->with($session, $now)
			->willReturn(true);

		$tracking = $this->createMock(TimeTrackingService::class);
		$tracking->expects(self::once())->method('clockIn')->with('alice');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$service = $this->createService($auth, $sessionMapper, $tracking, $l10n, $timeFactory);

		$result = $service->performAction($terminal, 'session-token', 'clock_in');
		self::assertSame('working', $result['newStatus']);
	}

	public function testPerformActionRejectsAlreadyUsedSession(): void
	{
		$terminal = new KioskTerminal();
		$terminal->setTerminalId('tid-1');

		$session = new KioskSession();
		$session->setId(9);
		$session->setUserId('alice');

		$auth = $this->createMock(KioskAuthService::class);
		$auth->method('validateSession')->willReturn($session);
		$auth->method('assertUserEligibleForAction');

		$now = new \DateTime('2026-06-10 12:00:00');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);

		$sessionMapper = $this->createMock(KioskSessionMapper::class);
		$sessionMapper->method('claimUnused')->willReturn(false);
		$sessionMapper->expects(self::once())
			->method('findUsedSession')
			->with('tid-1', 'session-token', $now)
			->willReturn($session);

		$tracking = $this->createMock(TimeTrackingService::class);
		$tracking->expects(self::never())->method('clockIn');

		$service = $this->createService($auth, $sessionMapper, $tracking, null, $timeFactory);

		try {
			$service->performAction($terminal, 'session-token', 'clock_in');
			self::fail('expected KioskException');
		} catch (KioskException $e) {
			self::assertSame('KIOSK_SESSION_USED', $e->getErrorCode());
		}
	}

	public function testPerformActionRejectsExpiredUnusedClaimAsInvalidNotUsed(): void
	{
		$terminal = new KioskTerminal();
		$terminal->setTerminalId('tid-1');

		$session = new KioskSession();
		$session->setId(9);
		$session->setUserId('alice');

		$auth = $this->createMock(KioskAuthService::class);
		$auth->method('validateSession')->willReturn($session);
		$auth->method('assertUserEligibleForAction');

		$now = new \DateTime('2026-06-10 12:00:00');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);

		$sessionMapper = $this->createMock(KioskSessionMapper::class);
		$sessionMapper->method('claimUnused')->willReturn(false);
		$sessionMapper->expects(self::once())
			->method('findUsedSession')
			->with('tid-1', 'session-token', $now)
			->willReturn(null);

		$tracking = $this->createMock(TimeTrackingService::class);
		$tracking->expects(self::never())->method('clockIn');

		$service = $this->createService($auth, $sessionMapper, $tracking, null, $timeFactory);

		try {
			$service->performAction($terminal, 'session-token', 'clock_in');
			self::fail('expected KioskException');
		} catch (KioskException $e) {
			self::assertSame('KIOSK_SESSION_INVALID', $e->getErrorCode());
		}
	}

	public function testPerformActionReleasesClaimWhenClockInRejected(): void
	{
		$terminal = new KioskTerminal();
		$terminal->setTerminalId('tid-1');

		$session = new KioskSession();
		$session->setId(9);
		$session->setUserId('alice');

		$auth = $this->createMock(KioskAuthService::class);
		$auth->method('validateSession')->willReturn($session);
		$auth->method('assertUserEligibleForAction');

		$now = new \DateTime('2026-06-10 12:00:00');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);

		$sessionMapper = $this->createMock(KioskSessionMapper::class);
		$sessionMapper->expects(self::once())
			->method('claimUnused')
			->with($session, $now)
			->willReturn(true);
		$sessionMapper->expects(self::once())
			->method('releaseClaim')
			->with($session, $now)
			->willReturn(true);

		$tracking = $this->createMock(TimeTrackingService::class);
		$tracking->expects(self::once())
			->method('clockIn')
			->willThrowException(new \OCA\ArbeitszeitCheck\Exception\BusinessRuleException(
				'already in',
				\OCA\ArbeitszeitCheck\BusinessRuleCode::ALREADY_CLOCKED_IN,
			));

		$service = $this->createService($auth, $sessionMapper, $tracking, null, $timeFactory);

		try {
			$service->performAction($terminal, 'session-token', 'clock_in');
			self::fail('Expected KioskException');
		} catch (KioskException $e) {
			self::assertSame('KIOSK_ALREADY_CLOCKED_IN', $e->getErrorCode());
			self::assertSame('already in', $e->getMessage());
		}
	}

	public function testPerformActionReleasesClaimOnUnexpectedFailure(): void
	{
		$terminal = new KioskTerminal();
		$terminal->setTerminalId('tid-1');

		$session = new KioskSession();
		$session->setId(9);
		$session->setUserId('alice');

		$auth = $this->createMock(KioskAuthService::class);
		$auth->method('validateSession')->willReturn($session);
		$auth->method('assertUserEligibleForAction');

		$now = new \DateTime('2026-06-10 12:00:00');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);

		$sessionMapper = $this->createMock(KioskSessionMapper::class);
		$sessionMapper->expects(self::once())
			->method('claimUnused')
			->with($session, $now)
			->willReturn(true);
		$sessionMapper->expects(self::once())
			->method('releaseClaim')
			->with($session, $now)
			->willReturn(true);

		$tracking = $this->createMock(TimeTrackingService::class);
		$tracking->expects(self::once())
			->method('clockIn')
			->willThrowException(new \RuntimeException('db down'));

		$service = $this->createService($auth, $sessionMapper, $tracking, null, $timeFactory);

		$this->expectException(\RuntimeException::class);
		$service->performAction($terminal, 'session-token', 'clock_in');
	}

	public function testPerformActionReleasesClaimWhenStampingDisabledMidMutation(): void
	{
		$terminal = new KioskTerminal();
		$terminal->setTerminalId('tid-1');

		$session = new KioskSession();
		$session->setId(9);
		$session->setUserId('alice');

		$auth = $this->createMock(KioskAuthService::class);
		$auth->method('validateSession')->willReturn($session);
		$auth->method('assertUserEligibleForAction');

		$now = new \DateTime('2026-06-10 12:00:00');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);

		$sessionMapper = $this->createMock(KioskSessionMapper::class);
		$sessionMapper->method('claimUnused')->willReturn(true);
		$sessionMapper->expects(self::once())->method('releaseClaim')->with($session, $now);

		$tracking = $this->createMock(TimeTrackingService::class);
		$tracking->method('clockIn')->willThrowException(
			new \OCA\ArbeitszeitCheck\Exception\TimeCaptureForbiddenException(
				'off',
				\OCA\ArbeitszeitCheck\Exception\TimeCaptureForbiddenException::CODE_CLOCK_STAMPING_DISABLED,
			),
		);

		$service = $this->createService($auth, $sessionMapper, $tracking, null, $timeFactory);

		try {
			$service->performAction($terminal, 'session-token', 'clock_in');
			self::fail('Expected KioskException');
		} catch (KioskException $e) {
			self::assertSame('KIOSK_CLOCK_STAMPING_DISABLED', $e->getErrorCode());
		}
	}

	private function claimedSessionFixture(): array
	{
		$terminal = new KioskTerminal();
		$terminal->setTerminalId('tid-1');
		$session = new KioskSession();
		$session->setId(9);
		$session->setUserId('alice');
		$auth = $this->createMock(KioskAuthService::class);
		$auth->method('validateSession')->willReturn($session);
		$auth->method('assertUserEligibleForAction');
		$now = new \DateTime('2026-06-10 12:00:00');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);
		$sessionMapper = $this->createMock(KioskSessionMapper::class);
		$sessionMapper->method('claimUnused')->willReturn(true);
		return [$terminal, $session, $auth, $sessionMapper, $timeFactory, $now];
	}

	public function testPerformActionBreakAndClockOutArms(): void
	{
		foreach ([
			'clock_out' => ['clockOut', 'off'],
			'break_start' => ['startBreak', 'on_break'],
			'break_end' => ['endBreak', 'working'],
		] as $action => [$method, $status]) {
			[$terminal, , $auth, $sessionMapper, $timeFactory] = $this->claimedSessionFixture();
			$tracking = $this->createMock(TimeTrackingService::class);
			$tracking->expects(self::once())->method($method)->with('alice');
			$service = $this->createService($auth, $sessionMapper, $tracking, null, $timeFactory);
			self::assertSame($status, $service->performAction($terminal, 'tok', $action)['newStatus']);
		}
	}

	public function testPerformActionInvalidActionKeepsClaim(): void
	{
		[$terminal, , $auth, $sessionMapper, $timeFactory] = $this->claimedSessionFixture();
		// client bug/replay: claim must NOT be released
		$sessionMapper->expects(self::never())->method('releaseClaim');
		$service = $this->createService($auth, $sessionMapper, $this->createMock(TimeTrackingService::class), null, $timeFactory);
		try {
			$service->performAction($terminal, 'tok', 'bogus');
			self::fail('expected KioskException');
		} catch (KioskException $e) {
			self::assertSame('KIOSK_ACTION_INVALID', $e->getErrorCode());
		}
	}

	public function testPerformActionReleasesClaimOnMonthFinalized(): void
	{
		[$terminal, $session, $auth, $sessionMapper, $timeFactory, $now] = $this->claimedSessionFixture();
		$sessionMapper->expects(self::once())->method('releaseClaim')->with($session, $now);
		$tracking = $this->createMock(TimeTrackingService::class);
		$tracking->method('clockIn')->willThrowException(new MonthFinalizedException('sealed'));
		$service = $this->createService($auth, $sessionMapper, $tracking, null, $timeFactory);
		try {
			$service->performAction($terminal, 'tok', 'clock_in');
			self::fail('expected KioskException');
		} catch (KioskException $e) {
			self::assertSame('MONTH_FINALIZED', $e->getErrorCode());
		}
	}

	public function testPerformActionReleasesClaimOnBusinessRule(): void
	{
		[$terminal, $session, $auth, $sessionMapper, $timeFactory, $now] = $this->claimedSessionFixture();
		$sessionMapper->expects(self::once())->method('releaseClaim')->with($session, $now);
		$tracking = $this->createMock(TimeTrackingService::class);
		$tracking->method('clockIn')->willThrowException(new BusinessRuleException('rest needed', BusinessRuleCode::REST_PERIOD_REQUIRED));
		$service = $this->createService($auth, $sessionMapper, $tracking, null, $timeFactory);
		try {
			$service->performAction($terminal, 'tok', 'clock_in');
			self::fail('expected KioskException');
		} catch (KioskException $e) {
			self::assertSame('KIOSK_REST_PERIOD_REQUIRED', $e->getErrorCode());
		}
	}

	public function testActionMessageForReturnsTranslatedCopy(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$service = $this->createService(
			$this->createMock(KioskAuthService::class),
			$this->createMock(KioskSessionMapper::class),
			$this->createMock(TimeTrackingService::class),
			$l10n,
		);
		self::assertSame('Clocked in', $service->actionMessageFor('clock_in'));
		self::assertSame('Clocked out', $service->actionMessageFor('clock_out'));
		self::assertSame('Break started', $service->actionMessageFor('break_start'));
		self::assertSame('Break ended', $service->actionMessageFor('break_end'));
		self::assertSame('Action completed', $service->actionMessageFor('anything_else'));
	}
}