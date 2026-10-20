<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use Test\TestCase;

/**
 * DB-truth coverage for AbsenceMapper::countPendingApprovalByUser.
 *
 * The method feeds scoped overview pending counts and must match the
 * 'pending' filter semantics of AbsenceService::getAbsencesByUser():
 * STATUS_PENDING + STATUS_SUBSTITUTE_PENDING — nothing else.
 */
class AbsenceMapperPendingCountIntegrationTest extends TestCase
{
	private const USER_A = '__azc_pend_a__';
	private const USER_B = '__azc_pend_b__';

	private AbsenceMapper $mapper;
	private AbsenceService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = \OC::$server->get(AbsenceMapper::class);
		$this->service = \OC::$server->get(AbsenceService::class);
		$this->mapper->deleteByUser(self::USER_A);
		$this->mapper->deleteByUser(self::USER_B);
	}

	protected function tearDown(): void
	{
		$this->mapper->deleteByUser(self::USER_A);
		$this->mapper->deleteByUser(self::USER_B);
		parent::tearDown();
	}

	private function absence(string $userId, string $status, string $startDate): Absence
	{
		$a = new Absence();
		$a->setUserId($userId);
		$a->setType(Absence::TYPE_VACATION);
		$a->setStartDate(new \DateTime($startDate));
		$a->setEndDate(new \DateTime($startDate));
		$a->setReason('pending-count integration test');
		$a->setStatus($status);
		$a->setCreatedAt(new \DateTime());
		$a->setUpdatedAt(new \DateTime());
		return $this->mapper->insert($a);
	}

	public function testCountsBothPendingStatusesAndNothingElse(): void
	{
		// USER_A: one pending + one substitute_pending + three non-pending.
		$this->absence(self::USER_A, Absence::STATUS_PENDING, '2026-05-10');
		$this->absence(self::USER_A, Absence::STATUS_SUBSTITUTE_PENDING, '2026-05-11');
		$this->absence(self::USER_A, Absence::STATUS_SUBSTITUTE_DECLINED, '2026-05-12');
		$this->absence(self::USER_A, Absence::STATUS_APPROVED, '2026-05-13');
		$this->absence(self::USER_A, Absence::STATUS_REJECTED, '2026-05-14');
		// USER_B: only a cancelled row → must not appear in the map.
		$this->absence(self::USER_B, Absence::STATUS_CANCELLED, '2026-05-10');

		$counts = $this->mapper->countPendingApprovalByUser([self::USER_A, self::USER_B, '__nobody__']);

		$this->assertSame(2, $counts[self::USER_A]);
		$this->assertArrayNotHasKey(self::USER_B, $counts);
		$this->assertArrayNotHasKey('__nobody__', $counts);
	}

	public function testMatchesServicePendingFilterSemantics(): void
	{
		$this->absence(self::USER_A, Absence::STATUS_PENDING, '2026-05-10');
		$this->absence(self::USER_A, Absence::STATUS_SUBSTITUTE_PENDING, '2026-05-11');

		$batched = $this->mapper->countPendingApprovalByUser([self::USER_A]);
		$serviceRows = $this->service->getAbsencesByUser(self::USER_A, ['status' => 'pending']);

		$this->assertSame(count($serviceRows), $batched[self::USER_A]);
		$this->assertSame(2, $batched[self::USER_A]);
	}

	public function testEmptyAndDegenerateInput(): void
	{
		$this->assertSame([], $this->mapper->countPendingApprovalByUser([]));
		// Duplicates/empties must be normalised away, not SQL-corrupting.
		$this->assertSame([], $this->mapper->countPendingApprovalByUser(['', '']));
	}
}
