<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Service\MonthClosureGuard;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use PHPUnit\Framework\TestCase;

class MonthClosureGuardTest extends TestCase
{
	public function testAssertAbsenceMutableDelegatesRangeToClosureService(): void
	{
		$closure = $this->createMock(MonthClosureService::class);
		$closure->expects($this->once())
			->method('assertDateRangeMutable')
			->with(
				'alice',
				$this->callback(static fn (\DateTime $d) => $d->format('Y-m-d') === '2026-05-01'),
				$this->callback(static fn (\DateTime $d) => $d->format('Y-m-d') === '2026-05-03')
			);
		$absence = new Absence();
		$absence->setUserId('alice');
		$absence->setStartDate(new \DateTime('2026-05-01'));
		$absence->setEndDate(new \DateTime('2026-05-03'));
		(new MonthClosureGuard($closure))->assertAbsenceMutable($absence);
	}

	public function testAssertUserDayMutableCoversWholeDay(): void
	{
		$closure = $this->createMock(MonthClosureService::class);
		$closure->expects($this->once())
			->method('assertDateRangeMutable')
			->with(
				'alice',
				$this->callback(static fn (\DateTime $d) => $d->format('H:i:s') === '00:00:00'),
				$this->callback(static fn (\DateTime $d) => $d->format('H:i:s') === '23:59:59')
			);
		(new MonthClosureGuard($closure))->assertUserDayMutable('alice', new \DateTime('2026-05-02 14:33:00'));
	}
}
