<?php

declare(strict_types=1);

/**
 * Atlas coverage lane — job bodies without dedicated tests:
 * ManagerPendingApprovalDigestJob (send/silent/failure paths) and
 * BackfillAuditCaptureSourceJob (missing table, probe failure, chunked update).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\BackfillAuditCaptureSourceJob;
use OCA\ArbeitszeitCheck\BackgroundJob\ManagerPendingApprovalDigestJob;
use OCA\ArbeitszeitCheck\Service\ManagerPendingApprovalMailService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class JobCoverageTest extends TestCase
{
	private function invokeRun(object $job): void
	{
		$m = new \ReflectionMethod($job, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);
	}

	private function digestJob(int $sent, ?\Throwable $throw = null, ?LoggerInterface $logger = null): ManagerPendingApprovalDigestJob
	{
		$mail = $this->createMock(ManagerPendingApprovalMailService::class);
		if ($throw !== null) {
			$mail->method('sendDailyDigests')->willThrowException($throw);
		} else {
			$mail->method('sendDailyDigests')->willReturn($sent);
		}
		return new ManagerPendingApprovalDigestJob(
			$this->createMock(ITimeFactory::class),
			$mail,
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	public function testDigestJobLogsInfoWhenDigestsSent(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with($this->isType('string'), $this->callback(static fn ($c) => ($c['n'] ?? 0) === 3));
		$this->invokeRun($this->digestJob(3, null, $logger));
	}

	public function testDigestJobSilentWhenNothingSent(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$logger->expects($this->never())->method('warning');
		$this->invokeRun($this->digestJob(0, null, $logger));
	}

	public function testDigestJobSwallowsMailFailure(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->isType('string'), $this->callback(
				static fn ($c) => ($c['exception'] ?? null) instanceof \Throwable
			));
		$this->invokeRun($this->digestJob(0, new \RuntimeException('smtp down'), $logger));
	}

	private function qbMock(): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('isNull')->willReturn('x IS NULL');
		$expr->method('like')->willReturn('x LIKE ?');
		$expr->method('in')->willReturn('x IN (?)');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => ':p');
		return $qb;
	}

	private function backfillJob(IDBConnection $db, ?LoggerInterface $logger = null): BackfillAuditCaptureSourceJob
	{
		return new BackfillAuditCaptureSourceJob(
			$this->createMock(ITimeFactory::class),
			$db,
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	public function testBackfillReturnsEarlyWithoutTable(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('at_audit')->willReturn(false);
		$db->expects($this->never())->method('getQueryBuilder');
		$this->invokeRun($this->backfillJob($db));
	}

	public function testBackfillDebugLogsWhenColumnMissing(): void
	{
		$probe = $this->qbMock();
		$probe->method('executeQuery')->willThrowException(new \RuntimeException('unknown column'));

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($probe);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')
			->with($this->isType('string'), $this->callback(static fn ($c) => ($c['app'] ?? '') === 'arbeitszeitcheck'));
		$logger->expects($this->never())->method('info');

		$this->invokeRun($this->backfillJob($db, $logger));
	}

	public function testBackfillUpdatesMatchingRows(): void
	{
		$probe = $this->qbMock();
		$probeResult = $this->createMock(IResult::class);
		$probe->method('executeQuery')->willReturn($probeResult);

		$select = $this->qbMock();
		$selectResult = $this->createMock(IResult::class);
		$selectResult->method('fetchAll')->willReturn([['id' => '7'], ['id' => '8'], ['id' => '0'], ['id' => '-3']]);
		$select->method('executeQuery')->willReturn($selectResult);

		$update = $this->qbMock();
		$update->expects($this->once())->method('executeStatement')->willReturn(2);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($probe, $select, $update);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with($this->isType('string'), $this->callback(static fn ($c) => ($c['n'] ?? 0) === 2));

		$this->invokeRun($this->backfillJob($db, $logger));
	}

	public function testBackfillStopsWhenNoRowsRemain(): void
	{
		$probe = $this->qbMock();
		$probe->method('executeQuery')->willReturn($this->createMock(IResult::class));

		$select = $this->qbMock();
		$selectResult = $this->createMock(IResult::class);
		$selectResult->method('fetchAll')->willReturn([['id' => 0]]); // no valid ids
		$select->method('executeQuery')->willReturn($selectResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($probe, $select);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info'); // nothing updated

		$this->invokeRun($this->backfillJob($db, $logger));
	}
}
