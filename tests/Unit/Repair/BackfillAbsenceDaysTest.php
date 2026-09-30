<?php

declare(strict_types=1);

/**
 * Atlas coverage lane — BackfillAbsenceDays repair step:
 * empty set early return, per-row compute + update, invalid date skip,
 * per-row failure isolation.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Repair;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Repair\BackfillAbsenceDays;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCP\AppFramework\Db\Entity;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class BackfillAbsenceDaysTest extends TestCase
{
	public function testNoAbsencesIsQuietNoOp(): void
	{
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('findWithNullDays')->willReturn([]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');
		$output->expects($this->never())->method('startProgress');

		(new BackfillAbsenceDays($mapper, $this->createMock(HolidayService::class)))->run($output);
	}

	public function testBackfillsDaysForEachAbsence(): void
	{
		$a1 = new Absence();
		$a1->setUserId('alice');
		$a1->setStartDate(new \DateTime('2026-08-03'));
		$a1->setEndDate(new \DateTime('2026-08-07'));

		$bad = new Absence(); // start > end -> skipped
		$bad->setUserId('bob');
		$bad->setStartDate(new \DateTime('2026-08-10'));
		$bad->setEndDate(new \DateTime('2026-08-01'));

		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('findWithNullDays')->willReturn([$a1, $bad]);
		$mapper->expects($this->once())->method('update')
			->with($this->callback(static fn (Absence $a) =>
				$a === $a1 && $a->getDays() === 5.0 && $a->getUpdatedAt() instanceof \DateTime
			))
			->willReturnArgument(0);

		$holiday = $this->createMock(HolidayService::class);
		$holiday->expects($this->once())->method('computeWorkingDaysForUser')
			->with('alice', $this->isInstanceOf(\DateTime::class), $this->isInstanceOf(\DateTime::class))
			->willReturn(5.0);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('startProgress')->with(2);
		$output->expects($this->exactly(2))->method('advance');
		$output->expects($this->once())->method('finishProgress');
		$output->expects($this->exactly(2))->method('info')
			->withConsecutive(
				[$this->stringContains('2')],
				[$this->stringContains('1')],
			);

		(new BackfillAbsenceDays($mapper, $holiday))->run($output);
	}

	public function testRowFailureDoesNotAbortBatch(): void
	{
		$a1 = new Absence();
		$a1->setUserId('alice');
		$a1->setStartDate(new \DateTime('2026-08-03'));
		$a1->setEndDate(new \DateTime('2026-08-04'));

		$a2 = new Absence();
		$a2->setUserId('bob');
		$a2->setStartDate(new \DateTime('2026-08-05'));
		$a2->setEndDate(new \DateTime('2026-08-06'));

		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('findWithNullDays')->willReturn([$a1, $a2]);
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')
			->willReturnCallback(static function (string $uid) {
				if ($uid === 'alice') {
					throw new \RuntimeException('calendar broken');
				}
				return 2.0;
			});

		$output = $this->createMock(IOutput::class);
		$output->expects($this->exactly(2))->method('advance');
		$output->expects($this->once())->method('finishProgress');

		(new BackfillAbsenceDays($mapper, $holiday))->run($output);
		$this->assertSame(2.0, $a2->getDays()); // second row still processed
	}
}
