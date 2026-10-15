<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\BackgroundJob\StampIdempotencyCleanupJob;
use OCA\ArbeitszeitCheck\Db\KioskStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\MobileStampIdempotencyMapper;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Atlas Lens 1 — DB truth for the retention/reclaim maintenance paths of the
 * offline-stamp idempotency tables. The happy-path claims are proven by
 * ConcurrentMobileStampIdempotencyIntegrationTest; this file exercises the
 * rows past that path: stale-claim reclaim, response finalisation by id,
 * retention sweeps, and the cron cleanup job.
 */
class StampIdempotencyMaintenanceIntegrationTest extends TestCase
{
	private const TEST_USER = '__azc_idem_maint__';
	private const TEST_TERMINAL = '__azc_idem_term__';

	private MobileStampIdempotencyMapper $mobile;

	private KioskStampIdempotencyMapper $kiosk;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mobile = \OC::$server->get(MobileStampIdempotencyMapper::class);
		$this->kiosk = \OC::$server->get(KioskStampIdempotencyMapper::class);
		$this->cleanup();
	}

	protected function tearDown(): void
	{
		$this->cleanup();
		parent::tearDown();
	}

	public function testMobileResponseUpdateByUserAndById(): void
	{
		$row = $this->mobile->tryInsert(self::TEST_USER, 'req-m1', '{"pending":true}', time());
		$this->assertNotNull($row);

		$this->mobile->updateResponseJson(self::TEST_USER, 'req-m1', '{"done":1}');
		$this->assertSame('{"done":1}', $this->mobile->findByUserAndRequestId(self::TEST_USER, 'req-m1')?->getResponseJson());

		$this->mobile->updateResponseJsonForId((int)$row->getId(), '{"done":2}');
		$this->assertSame('{"done":2}', $this->mobile->findByUserAndRequestId(self::TEST_USER, 'req-m1')?->getResponseJson());
	}

	public function testKioskResponseUpdateByTerminalAndById(): void
	{
		$row = $this->kiosk->tryInsert(self::TEST_TERMINAL, 'req-k1', '{"pending":true}', time());
		$this->assertNotNull($row);

		$this->kiosk->updateResponseJson(self::TEST_TERMINAL, 'req-k1', '{"done":1}');
		$this->assertSame('{"done":1}', $this->kiosk->findByTerminalAndRequestId(self::TEST_TERMINAL, 'req-k1')?->getResponseJson());

		$this->kiosk->updateResponseJsonForId((int)$row->getId(), '{"done":2}');
		$this->assertSame('{"done":2}', $this->kiosk->findByTerminalAndRequestId(self::TEST_TERMINAL, 'req-k1')?->getResponseJson());
	}

	public function testDeleteStalePendingOnlyRemovesMatchingClaim(): void
	{
		$oldTs = time() - 3600;
		foreach (
			[
				[$this->mobile, self::TEST_USER, 'findByUserAndRequestId'],
				[$this->kiosk, self::TEST_TERMINAL, 'findByTerminalAndRequestId'],
			] as [$mapper, $owner, $finder]
		) {
			$this->assertNotNull($mapper->tryInsert($owner, 'req-stale', '{"pending":true}', $oldTs));

			// A claim that completed between read and DELETE must survive.
			$this->assertSame(
				0,
				$mapper->deleteStalePending($owner, 'req-stale', '{"done":1}', time()),
				'mismatched response_json must not delete the row'
			);
			$this->assertNotNull($mapper->{$finder}($owner, 'req-stale'));

			// A stale PENDING claim is reclaimed exactly once.
			$this->assertSame(1, $mapper->deleteStalePending($owner, 'req-stale', '{"pending":true}', time()));
			$this->assertNull($mapper->{$finder}($owner, 'req-stale'));
		}
	}

	public function testDeleteOlderThanSweepsExpiredRowsOnly(): void
	{
		$this->assertNotNull($this->mobile->tryInsert(self::TEST_USER, 'req-old', '{}', time() - 90000));
		$this->assertNotNull($this->mobile->tryInsert(self::TEST_USER, 'req-new', '{}', time()));
		$this->assertNotNull($this->kiosk->tryInsert(self::TEST_TERMINAL, 'req-old', '{}', time() - 90000));
		$this->assertNotNull($this->kiosk->tryInsert(self::TEST_TERMINAL, 'req-new', '{}', time()));

		$cutoff = time() - 60;
		$this->assertGreaterThanOrEqual(1, $this->mobile->deleteOlderThan($cutoff));
		$this->assertGreaterThanOrEqual(1, $this->kiosk->deleteOlderThan($cutoff));

		$this->assertNull($this->mobile->findByUserAndRequestId(self::TEST_USER, 'req-old'));
		$this->assertNotNull($this->mobile->findByUserAndRequestId(self::TEST_USER, 'req-new'));
		$this->assertNull($this->kiosk->findByTerminalAndRequestId(self::TEST_TERMINAL, 'req-old'));
		$this->assertNotNull($this->kiosk->findByTerminalAndRequestId(self::TEST_TERMINAL, 'req-new'));
	}

	public function testDeleteByIdRemovesOnlyThatRow(): void
	{
		$victim = $this->mobile->tryInsert(self::TEST_USER, 'req-del', '{}', time());
		$keep = $this->mobile->tryInsert(self::TEST_USER, 'req-keep', '{}', time());
		$this->assertNotNull($victim);
		$this->assertNotNull($keep);

		$this->mobile->deleteById((int)$victim->getId());
		$this->assertNull($this->mobile->findByUserAndRequestId(self::TEST_USER, 'req-del'));
		$this->assertNotNull($this->mobile->findByUserAndRequestId(self::TEST_USER, 'req-keep'));

		$victimK = $this->kiosk->tryInsert(self::TEST_TERMINAL, 'req-del', '{}', time());
		$this->assertNotNull($victimK);
		$this->kiosk->deleteById((int)$victimK->getId());
		$this->assertNull($this->kiosk->findByTerminalAndRequestId(self::TEST_TERMINAL, 'req-del'));
	}

	public function testCleanupJobReapsRowsPastRetention(): void
	{
		$expired = time() - (8 * 24 * 3600);
		$this->assertNotNull($this->mobile->tryInsert(self::TEST_USER, 'req-exp', '{}', $expired));
		$this->assertNotNull($this->kiosk->tryInsert(self::TEST_TERMINAL, 'req-exp', '{}', $expired));
		$this->assertNotNull($this->mobile->tryInsert(self::TEST_USER, 'req-fresh', '{}', time()));
		$this->assertNotNull($this->kiosk->tryInsert(self::TEST_TERMINAL, 'req-fresh', '{}', time()));

		$job = \OC::$server->get(StampIdempotencyCleanupJob::class);
		$run = new \ReflectionMethod(StampIdempotencyCleanupJob::class, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

		$this->assertNull($this->mobile->findByUserAndRequestId(self::TEST_USER, 'req-exp'));
		$this->assertNull($this->kiosk->findByTerminalAndRequestId(self::TEST_TERMINAL, 'req-exp'));
		$this->assertNotNull($this->mobile->findByUserAndRequestId(self::TEST_USER, 'req-fresh'));
		$this->assertNotNull($this->kiosk->findByTerminalAndRequestId(self::TEST_TERMINAL, 'req-fresh'));
	}

	private function cleanup(): void
	{
		$this->mobile->deleteByUserId(self::TEST_USER);
		$db = \OC::$server->get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete(KioskStampIdempotencyMapper::TABLE)
			->where($qb->expr()->eq('terminal_id', $qb->createNamedParameter(self::TEST_TERMINAL)))
			->executeStatement();
	}
}
