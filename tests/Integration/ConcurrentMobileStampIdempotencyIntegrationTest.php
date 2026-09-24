<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\MobileStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Service\MobileStampReplayService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\StampOccurredAtParser;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUserSession;
use Psr\Log\NullLogger;
use Test\TestCase;

/**
 * Atlas Lens 1 — DB truth: concurrent claim for the same clientRequestId
 * cannot leave two idempotency rows or double-apply clock-in.
 */
class ConcurrentMobileStampIdempotencyIntegrationTest extends TestCase
{
	private const TEST_USER = '__azc_atlas_stamp_idem__';
	private const REQUEST_ID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

	private MobileStampIdempotencyMapper $idempotency;

	private TimeTrackingService $timeTracking;

	protected function setUp(): void
	{
		parent::setUp();
		$this->idempotency = \OCP\Server::get(MobileStampIdempotencyMapper::class);
		$this->timeTracking = \OCP\Server::get(TimeTrackingService::class);
		$this->cleanup();
	}

	protected function tearDown(): void
	{
		$this->cleanup();
		parent::tearDown();
	}

	public function testConcurrentTryInsertLeavesExactlyOneRow(): void
	{
		$now = time();
		$first = $this->idempotency->tryInsert(self::TEST_USER, self::REQUEST_ID, '{"pending":true}', $now);
		$second = $this->idempotency->tryInsert(self::TEST_USER, self::REQUEST_ID, '{"pending":true}', $now);

		$this->assertTrue($first, 'First claim must succeed');
		$this->assertFalse($second, 'Second claim must lose unique race');
		$this->assertSame(1, $this->countIdemRows(), 'DB truth: one row for (user, clientRequestId)');
	}

	public function testReplayDoesNotDoubleClockIn(): void
	{
		$occurredAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
			->modify('-2 minutes')
			->format(\DateTimeInterface::ATOM);

		$service = $this->buildReplayService();
		$first = $service->clockIn(self::TEST_USER, null, null, self::REQUEST_ID, $occurredAt);
		$this->assertTrue($first['success']);
		$entryId = (int)($first['timeEntry']['id'] ?? 0);
		$this->assertGreaterThan(0, $entryId);

		$second = $service->clockIn(self::TEST_USER, null, null, self::REQUEST_ID, $occurredAt);
		$this->assertTrue($second['success']);
		$this->assertSame($entryId, (int)($second['timeEntry']['id'] ?? 0));
		$this->assertSame(1, $this->countActiveRows(), 'DB truth: exactly one active session');
		$this->assertSame(1, $this->countIdemRows(), 'DB truth: one completed idempotency row');
	}

	private function buildReplayService(): MobileStampReplayService
	{
		$tzConfig = \OCP\Server::get(IConfig::class);
		$tzDateTime = \OCP\Server::get(IDateTimeZone::class);
		$tzUserSession = \OCP\Server::get(IUserSession::class);
		$tz = new TimeZoneService($tzConfig, $tzDateTime, $tzUserSession, new NullLogger());
		$timeFactory = \OCP\Server::get(ITimeFactory::class);

		return new MobileStampReplayService(
			$this->timeTracking,
			$this->idempotency,
			new StampOccurredAtParser($tz, $tzConfig),
			$timeFactory,
		);
	}

	private function countIdemRows(): int
	{
		$qb = \OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from(MobileStampIdempotencyMapper::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::TEST_USER)))
			->andWhere($qb->expr()->eq('client_request_id', $qb->createNamedParameter(self::REQUEST_ID)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function countActiveRows(): int
	{
		$qb = \OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('at_entries')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::TEST_USER)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_ACTIVE)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function cleanup(): void
	{
		$this->idempotency->deleteByUserId(self::TEST_USER);
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		foreach (['at_audit', 'at_entries'] as $table) {
			if (!$db->tableExists($table)) {
				continue;
			}
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::TEST_USER)))
				->executeStatement();
		}
	}
}
