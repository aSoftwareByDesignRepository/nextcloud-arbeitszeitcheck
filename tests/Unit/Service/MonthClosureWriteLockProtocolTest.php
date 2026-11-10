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
 * state_transition_toctou (r46 fan-out): every month-scoped write path
 * must hold the month-closure advisory locks (azc/mc/) across the
 * check→commit window, acquired BEFORE the write tx begins — the DB
 * locking provider writes oc_file_locks on the same connection, so an
 * in-tx acquire would be invisible to a concurrent finalize.
 */
class MonthClosureWriteLockProtocolTest extends TestCase
{
	private function mkEntry(string $status, string $start = '2026-06-10 08:00:00'): TimeEntry
	{
		$e = new TimeEntry();
		$e->setId(7);
		$e->setUserId('testuser');
		$e->setStatus($status);
		$e->setStartTime(new \DateTime($start));
		return $e;
	}

	private function buildService(
		TimeEntryMapper $mapper,
		ILockingProvider $lp,
		MonthClosureGuard $guard,
		IDBConnection $db,
	): TimeTrackingService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []) => $s);
		$l10n->method('n')->willReturnCallback(static fn (string $s1, string $s2, int $n, array $a = []) => $n === 1 ? $s1 : $s2);

		$compliance = $this->createMock(ComplianceService::class);
		$compliance->method('checkComplianceBeforeClockIn')->willReturn([]);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => match ($key) {
			'max_daily_hours' => '10',
			'min_rest_period' => '11',
			'app_timezone' => 'Europe/Berlin',
			default => $default,
		});
		$config->method('getUserValue')->willReturn('');

		$dtz = $this->createMock(IDateTimeZone::class);
		$dtz->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$tz = new TimeZoneService($config, $dtz, $session, new NullLogger());
		$calc = new DailyWorkingHoursCalculator($mapper, $tz);

		$timeCapture = $this->createMock(TimeCaptureMethodService::class);
		$timeCapture->method('assertClockStampingAllowed')->willReturnCallback(static function (): void {
		});

		return new TimeTrackingService(
			$mapper,
			$this->createMock(ComplianceViolationMapper::class),
			$this->createMock(AuditLogMapper::class),
			$this->createMock(ProjectCheckIntegrationService::class),
			$compliance,
			$l10n,
			$config,
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			$guard,
			$db,
			$lp,
			$tz,
			$calc,
			null,
			$timeCapture,
		);
	}

	/**
	 * For each write path: the month-closure locks must be acquired
	 * BEFORE the write transaction opens, and released on exit.
	 *
	 * @dataProvider monthClosureWritePaths
	 */
	public function testWritePathHoldsMonthClosureLocksAcrossTx(string $method): void
	{
		$events = [];
		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->method('findStalePausedAutomaticEntries')->willReturn([]);
		$mapper->method('findByUserAndDateRange')->willReturn([]);
		$mapper->method('findOverlapping')->willReturn([]);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('insert')->willReturnArgument(0);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(static function () use (&$events): void {
			$events[] = 'tx';
		});
		$db->method('commit');
		$db->method('rollBack');

		$guard = $this->createMock(MonthClosureGuard::class);
		$guard->method('acquireMutableRangeLocks')->willReturnCallback(
			static function () use (&$events): array {
				$events[] = 'mc_acq';
				return ['azc/mc/test'];
			}
		);
		$guard->method('releaseMutableRangeLocks')->willReturnCallback(
			static function () use (&$events): void {
				$events[] = 'mc_rel';
			}
		);

		$lp = $this->createMock(ILockingProvider::class);
		$lp->method('acquireLock');
		$lp->method('releaseLock');

		switch ($method) {
			case 'clockIn':
				break; // all finds default to null — clean clock-in
			case 'clockOut':
			case 'startBreak':
				$mapper->method('findActiveByUser')->willReturn($this->mkEntry(TimeEntry::STATUS_ACTIVE));
				break;
			case 'endBreak':
				$mapper->method('findOnBreakByUser')->willReturn($this->mkEntry(TimeEntry::STATUS_BREAK));
				break;
			case 'completePausedEntry':
				$mapper->method('find')->willReturn($this->mkEntry(TimeEntry::STATUS_PAUSED));
				break;
			case 'enforceDailyMaximumForUser':
				$mapper->method('findActiveByUser')->willReturn($this->mkEntry(TimeEntry::STATUS_ACTIVE));
				break;
			case 'recomputePaidBreaksForUserSpan':
				break;
			default:
				$this->fail('unknown path ' . $method);
		}

		$service = $this->buildService($mapper, $lp, $guard, $db);
		try {
			match ($method) {
				'clockIn' => $service->clockIn('testuser'),
				'clockOut' => $service->clockOut('testuser'),
				'startBreak' => $service->startBreak('testuser'),
				'endBreak' => $service->endBreak('testuser'),
				'completePausedEntry' => $service->completePausedEntry('testuser', 7),
				'enforceDailyMaximumForUser' => $service->enforceDailyMaximumForUser('testuser'),
				'recomputePaidBreaksForUserSpan' => $service->recomputePaidBreaksForUserSpan(
					'testuser', new \DateTime('2026-06-01'), new \DateTime('2026-06-30')),
				default => $this->fail('unknown path ' . $method),
			};
		} catch (\Throwable) {
			// A post-lock business failure still must satisfy the protocol.
		}

		$acq = array_search('mc_acq', $events, true);
		$tx = array_search('tx', $events, true);
		$rel = array_search('mc_rel', $events, true);

		if ($method === 'recomputePaidBreaksForUserSpan') {
			// No db tx on this path — the span rewrite is autocommitted
			// inside PaidBreakAllocator; the mc hold still must wrap it.
			$this->assertNotFalse($acq, $method . ': mc locks must be acquired');
			$this->assertNotFalse($rel, $method . ': mc locks must be released');
			return;
		}

		$this->assertNotFalse($acq, $method . ': mc locks must be acquired');
		$this->assertNotFalse($tx, $method . ': a transaction must open');
		$this->assertLessThan($tx, $acq, $method . ': mc locks must be acquired BEFORE beginTransaction');
		$this->assertNotFalse($rel, $method . ': mc locks must be released');
		$this->assertGreaterThan($acq, $rel, $method . ': release must follow acquire');
	}

	/** @return array<string, array{0:string}> */
	public function monthClosureWritePaths(): array
	{
		return [
			'clockIn' => ['clockIn'],
			'clockOut' => ['clockOut'],
			'startBreak' => ['startBreak'],
			'endBreak' => ['endBreak'],
			'completePausedEntry' => ['completePausedEntry'],
			'enforceDailyMaximumForUser' => ['enforceDailyMaximumForUser'],
			'recomputePaidBreaksForUserSpan' => ['recomputePaidBreaksForUserSpan'],
		];
	}
}
