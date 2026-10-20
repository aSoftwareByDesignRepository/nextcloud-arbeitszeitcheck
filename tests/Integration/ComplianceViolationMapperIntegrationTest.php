<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use Test\TestCase;

/**
 * DB-truth coverage for ComplianceViolationMapper::findByTimeEntryId —
 * the lookup a compliance report uses to attach violations to an entry.
 */
class ComplianceViolationMapperIntegrationTest extends TestCase
{
	private const TEST_USER = '__azc_viol_maint__';
	private const TEST_USER_B = '__azc_viol_maint_b__';
	private const TEST_ENTRY_ID = 99887766;

	private ComplianceViolationMapper $mapper;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = \OC::$server->get(ComplianceViolationMapper::class);
		$this->mapper->deleteByUser(self::TEST_USER);
		$this->mapper->deleteByUser(self::TEST_USER_B);
	}

	protected function tearDown(): void
	{
		$this->mapper->deleteByUser(self::TEST_USER);
		$this->mapper->deleteByUser(self::TEST_USER_B);
		parent::tearDown();
	}

	public function testFindByTimeEntryIdReturnsLinkedViolations(): void
	{
		$linked = $this->mapper->createViolation(
			self::TEST_USER,
			'test_break_missing',
			'integration test violation',
			new \DateTime('2026-10-01'),
			self::TEST_ENTRY_ID,
			ComplianceViolation::SEVERITY_WARNING,
		);
		$this->assertGreaterThan(0, (int)$linked->getId());

		$rows = $this->mapper->findByTimeEntryId(self::TEST_ENTRY_ID);
		$ids = array_map(static fn (ComplianceViolation $v): int => (int)$v->getId(), $rows);
		$this->assertContains((int)$linked->getId(), $ids);
		foreach ($rows as $row) {
			$this->assertSame(self::TEST_ENTRY_ID, $row->getTimeEntryId());
		}
	}

	public function testFindByUserIdsAndDateRangeScopesAndBoundsCorrectly(): void
	{
		$inRange = $this->mapper->createViolation(
			self::TEST_USER, 'test_break_missing', 'in range',
			new \DateTime('2026-10-05'), null, ComplianceViolation::SEVERITY_ERROR
		);
		$otherUser = $this->mapper->createViolation(
			self::TEST_USER_B, 'test_break_missing', 'other user in range',
			new \DateTime('2026-10-05'), null, ComplianceViolation::SEVERITY_WARNING
		);
		$this->mapper->createViolation(
			self::TEST_USER, 'test_break_missing', 'before range',
			new \DateTime('2026-09-30'), null, ComplianceViolation::SEVERITY_ERROR
		);
		// Boundary day: exclusive end means 2026-10-31 data must NOT appear
		// when the caller asks for October with end-exclusive 2026-11-01…
		$onEndBoundary = $this->mapper->createViolation(
			self::TEST_USER, 'test_break_missing', 'on exclusive end bound',
			new \DateTime('2026-11-01'), null, ComplianceViolation::SEVERITY_ERROR
		);

		$rows = $this->mapper->findByUserIdsAndDateRange(
			[self::TEST_USER, self::TEST_USER_B],
			new \DateTime('2026-10-01'),
			new \DateTime('2026-11-01')
		);
		$ids = array_map(static fn (ComplianceViolation $v): int => (int)$v->getId(), $rows);

		$this->assertContains((int)$inRange->getId(), $ids);
		$this->assertContains((int)$otherUser->getId(), $ids);
		$this->assertNotContains((int)$onEndBoundary->getId(), $ids, 'exclusive end must drop the boundary day');

		// Filters compose with the scope: severity + resolved in SQL.
		$filtered = $this->mapper->findByUserIdsAndDateRange(
			[self::TEST_USER, self::TEST_USER_B],
			null, null, null, ComplianceViolation::SEVERITY_WARNING, false
		);
		$this->assertSame([$otherUser->getId()], array_map(static fn ($v) => $v->getId(), $filtered));

		// Count variant agrees with the row set.
		$count = $this->mapper->countByUserIdsAndDateRange(
			[self::TEST_USER, self::TEST_USER_B],
			new \DateTime('2026-10-01'), new \DateTime('2026-11-01')
		);
		$this->assertSame(2, $count);
	}

	public function testCountUnresolvedByUserIdsGroupsPerUser(): void
	{
		$this->mapper->createViolation(
			self::TEST_USER, 'test_break_missing', 'u1 a',
			new \DateTime('2026-10-05')
		);
		$this->mapper->createViolation(
			self::TEST_USER, 'test_break_missing', 'u1 b',
			new \DateTime('2026-10-06')
		);
		$resolved = $this->mapper->createViolation(
			self::TEST_USER_B, 'test_break_missing', 'u2 resolved',
			new \DateTime('2026-10-05')
		);
		$resolved->setResolved(true);
		$resolved->setResolvedAt(new \DateTime());
		$this->mapper->update($resolved);

		$counts = $this->mapper->countUnresolvedByUserIds([self::TEST_USER, self::TEST_USER_B, '__nobody__']);

		$this->assertSame(2, $counts[self::TEST_USER] ?? null);
		// Resolved-only users must be absent from the map, not 0 — callers
		// coalesce missing keys; a present 0 would also work but absence is
		// the documented contract.
		$this->assertArrayNotHasKey(self::TEST_USER_B, $counts);
		$this->assertArrayNotHasKey('__nobody__', $counts);
	}

	public function testFindByUserIdsAndDateRangeEmptySetReturnsNothing(): void
	{
		$this->assertSame([], $this->mapper->findByUserIdsAndDateRange([]));
		$this->assertSame(0, $this->mapper->countByUserIdsAndDateRange([]));
		$this->assertSame([], $this->mapper->countUnresolvedByUserIds([]));
	}
}
