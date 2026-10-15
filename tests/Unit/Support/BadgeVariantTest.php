<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Support\BadgeVariant;
use PHPUnit\Framework\TestCase;

class BadgeVariantTest extends TestCase
{
	public function testForTimeEntryStatusMapsKnownValues(): void
	{
		$this->assertSame(BadgeVariant::SUCCESS, BadgeVariant::forTimeEntryStatus(TimeEntry::STATUS_COMPLETED));
		$this->assertSame(BadgeVariant::PRIMARY, BadgeVariant::forTimeEntryStatus(TimeEntry::STATUS_ACTIVE));
		$this->assertSame(BadgeVariant::WARNING, BadgeVariant::forTimeEntryStatus(TimeEntry::STATUS_BREAK));
		$this->assertSame(BadgeVariant::WARNING, BadgeVariant::forTimeEntryStatus(TimeEntry::STATUS_PAUSED));
		$this->assertSame(BadgeVariant::WARNING, BadgeVariant::forTimeEntryStatus(TimeEntry::STATUS_PENDING_APPROVAL));
		$this->assertSame(BadgeVariant::ERROR, BadgeVariant::forTimeEntryStatus(TimeEntry::STATUS_REJECTED));
		$this->assertSame(BadgeVariant::SECONDARY, BadgeVariant::forTimeEntryStatus('unknown'));
	}

	public function testForWorkLocationMapsKnownValues(): void
	{
		$this->assertSame(BadgeVariant::INFO, BadgeVariant::forWorkLocation('home'));
		$this->assertSame(BadgeVariant::NEUTRAL, BadgeVariant::forWorkLocation('external'));
		$this->assertSame(BadgeVariant::SECONDARY, BadgeVariant::forWorkLocation('office'));
		$this->assertSame(BadgeVariant::SECONDARY, BadgeVariant::forWorkLocation(null));
		$this->assertSame(BadgeVariant::SECONDARY, BadgeVariant::forWorkLocation('unknown'));
	}

	public function testForAbsenceStatusMapsKnownValues(): void
	{
		$this->assertSame(BadgeVariant::SUCCESS, BadgeVariant::forAbsenceStatus(Absence::STATUS_APPROVED));
		$this->assertSame(BadgeVariant::WARNING, BadgeVariant::forAbsenceStatus(Absence::STATUS_PENDING));
		$this->assertSame(BadgeVariant::WARNING, BadgeVariant::forAbsenceStatus(Absence::STATUS_SUBSTITUTE_PENDING));
		$this->assertSame(BadgeVariant::ERROR, BadgeVariant::forAbsenceStatus(Absence::STATUS_REJECTED));
		$this->assertSame(BadgeVariant::ERROR, BadgeVariant::forAbsenceStatus(Absence::STATUS_SUBSTITUTE_DECLINED));
		$this->assertSame(BadgeVariant::SECONDARY, BadgeVariant::forAbsenceStatus(Absence::STATUS_CANCELLED));
	}

	public function testForClockStatusMapsKnownValues(): void
	{
		$this->assertSame(BadgeVariant::SUCCESS, BadgeVariant::forClockStatus('active'));
		$this->assertSame(BadgeVariant::WARNING, BadgeVariant::forClockStatus('break'));
		$this->assertSame(BadgeVariant::SECONDARY, BadgeVariant::forClockStatus('clocked_out'));
	}

	public function testForComplianceSeverity(): void
	{
		$this->assertSame(BadgeVariant::ERROR, BadgeVariant::forComplianceSeverity('error'));
		$this->assertSame(BadgeVariant::WARNING, BadgeVariant::forComplianceSeverity('warning'));
		$this->assertSame(BadgeVariant::PRIMARY, BadgeVariant::forComplianceSeverity('info'));
	}

	public function testForMonthClosureStatus(): void
	{
		$this->assertSame(BadgeVariant::SUCCESS, BadgeVariant::forMonthClosureStatus('finalized'));
		$this->assertSame(BadgeVariant::WARNING, BadgeVariant::forMonthClosureStatus('open'));
		$this->assertSame(BadgeVariant::NEUTRAL, BadgeVariant::forMonthClosureStatus('loading'));
	}
}
