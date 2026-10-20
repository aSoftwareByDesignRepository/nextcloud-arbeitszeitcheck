<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use Test\TestCase;

/**
 * DB-truth coverage for the batched overview helpers:
 * findOverlappingForUsers must return exactly the entries findOverlapping
 * would surface per user — same padded range, same live-row union, same
 * overlap filtering — and countByUserIds must group like countByUser.
 */
class TimeEntryMapperOverlapBatchIntegrationTest extends TestCase
{
	private const USER_A = '__azc_overlap_a__';
	private const USER_B = '__azc_overlap_b__';

	private TimeEntryMapper $mapper;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = \OC::$server->get(TimeEntryMapper::class);
		$this->mapper->deleteByUser(self::USER_A);
		$this->mapper->deleteByUser(self::USER_B);
	}

	protected function tearDown(): void
	{
		$this->mapper->deleteByUser(self::USER_A);
		$this->mapper->deleteByUser(self::USER_B);
		parent::tearDown();
	}

	private function entry(
		string $userId,
		string $status,
		?\DateTime $start,
		?\DateTime $end = null,
	): TimeEntry {
		$e = new TimeEntry();
		$e->setUserId($userId);
		$e->setStatus($status);
		if ($start !== null) {
			$e->setStartTime($start);
		}
		if ($end !== null) {
			$e->setEndTime($end);
		}
		$e->setIsManualEntry(false);
		$e->setCreatedAt(new \DateTime());
		$e->setUpdatedAt(new \DateTime());
		return $this->mapper->insert($e);
	}

	public function testBatchedMatchesPerUserOverlapForSameWindow(): void
	{
		$dayStart = new \DateTime('2026-05-19 00:00:00');
		$dayEnd = new \DateTime('2026-05-20 00:00:00');

		$aIn = $this->entry(self::USER_A, TimeEntry::STATUS_COMPLETED,
			new \DateTime('2026-05-19 08:00:00'), new \DateTime('2026-05-19 16:00:00'));
		// Overnight row that only clips into the window's first day.
		$aOvernight = $this->entry(self::USER_A, TimeEntry::STATUS_COMPLETED,
			new \DateTime('2026-05-18 22:00:00'), new \DateTime('2026-05-19 06:00:00'));
		// Outside the window entirely.
		$this->entry(self::USER_A, TimeEntry::STATUS_COMPLETED,
			new \DateTime('2026-05-15 08:00:00'), new \DateTime('2026-05-15 12:00:00'));
		$bIn = $this->entry(self::USER_B, TimeEntry::STATUS_COMPLETED,
			new \DateTime('2026-05-19 09:00:00'), new \DateTime('2026-05-19 12:30:00'));

		$batched = $this->mapper->findOverlappingForUsers(
			[self::USER_A, self::USER_B], $dayStart, $dayEnd);
		$soloA = $this->mapper->findOverlapping(self::USER_A, $dayStart, $dayEnd);
		$soloB = $this->mapper->findOverlapping(self::USER_B, $dayStart, $dayEnd);

		$idsOf = static fn (array $rows): array => array_map(
			static fn (TimeEntry $e): int => (int)$e->getId(), $rows);

		$this->assertEqualsCanonicalizing(
			$idsOf($soloA),
			$idsOf($batched[self::USER_A] ?? []),
			'user A: batched fetch must equal the per-user overlap set'
		);
		$this->assertEqualsCanonicalizing(
			$idsOf($soloB),
			$idsOf($batched[self::USER_B] ?? []),
			'user B: batched fetch must equal the per-user overlap set'
		);
		$this->assertContains((int)$aIn->getId(), $idsOf($batched[self::USER_A]));
		$this->assertContains((int)$aOvernight->getId(), $idsOf($batched[self::USER_A]));
		$this->assertContains((int)$bIn->getId(), $idsOf($batched[self::USER_B]));
	}

	public function testBatchedIncludesLiveRowsStartedBeforeTheWindow(): void
	{
		$dayStart = new \DateTime('2026-05-19 00:00:00');
		$dayEnd = new \DateTime('2026-05-20 00:00:00');

		// Active session started before the padded fetch window — only the
		// live union can surface it.
		$active = $this->entry(self::USER_A, TimeEntry::STATUS_ACTIVE,
			new \DateTime('2026-05-10 22:00:00'));
		$pendingOpen = $this->entry(self::USER_B, TimeEntry::STATUS_PENDING_APPROVAL,
			new \DateTime('2026-05-01 08:00:00'));

		$batched = $this->mapper->findOverlappingForUsers(
			[self::USER_A, self::USER_B], $dayStart, $dayEnd);
		$soloA = $this->mapper->findOverlapping(self::USER_A, $dayStart, $dayEnd);
		$soloB = $this->mapper->findOverlapping(self::USER_B, $dayStart, $dayEnd);

		$idsOf = static fn (array $rows): array => array_map(
			static fn (TimeEntry $e): int => (int)$e->getId(), $rows);

		$this->assertContains((int)$active->getId(), $idsOf($batched[self::USER_A] ?? []));
		$this->assertContains((int)$active->getId(), $idsOf($soloA));
		$this->assertContains((int)$pendingOpen->getId(), $idsOf($batched[self::USER_B] ?? []));
		$this->assertContains((int)$pendingOpen->getId(), $idsOf($soloB));
	}

	public function testBatchedEmptyInputReturnsEmptyMap(): void
	{
		$this->assertSame(
			[],
			$this->mapper->findOverlappingForUsers(
				[], new \DateTime('2026-05-19'), new \DateTime('2026-05-20'))
		);
		$this->assertSame([], $this->mapper->countByUserIds([]));
	}

	public function testCountByUserIdsGroupsLikeCountByUser(): void
	{
		$this->entry(self::USER_A, TimeEntry::STATUS_COMPLETED,
			new \DateTime('2026-05-19 08:00:00'), new \DateTime('2026-05-19 10:00:00'));
		$this->entry(self::USER_A, TimeEntry::STATUS_COMPLETED,
			new \DateTime('2026-05-19 11:00:00'), new \DateTime('2026-05-19 12:00:00'));
		$this->entry(self::USER_B, TimeEntry::STATUS_COMPLETED,
			new \DateTime('2026-05-19 08:00:00'), new \DateTime('2026-05-19 09:00:00'));

		$counts = $this->mapper->countByUserIds([self::USER_A, self::USER_B, '__nobody__']);

		$this->assertSame($this->mapper->countByUser(self::USER_A), $counts[self::USER_A]);
		$this->assertSame($this->mapper->countByUser(self::USER_B), $counts[self::USER_B]);
		$this->assertSame(2, $counts[self::USER_A]);
		$this->assertSame(1, $counts[self::USER_B]);
		$this->assertArrayNotHasKey('__nobody__', $counts);
	}
}
