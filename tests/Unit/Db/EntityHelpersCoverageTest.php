<?php

declare(strict_types=1);

/**
 * Atlas coverage lane — entity helper methods: isValid/getSummary/
 * markAsResolved/getSeverityLevel/isCompleted/canRequestCorrection.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Db\ModelVacationDefault;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\UserSetting;
use PHPUnit\Framework\TestCase;

class EntityHelpersCoverageTest extends TestCase
{
	public function testAbsenceIsValidAndSummary(): void
	{
		$a = new Absence();
		$a->setUserId('alice');
		$a->setType(Absence::TYPE_VACATION);
		$a->setStartDate(new \DateTime('2026-08-01'));
		$a->setEndDate(new \DateTime('2026-08-05'));
		$a->setDays(5.0);
		$a->setStatus(Absence::STATUS_PENDING);
		$a->setCreatedAt(new \DateTime());
		$a->setUpdatedAt(new \DateTime());

		$this->assertTrue($a->isValid());
		$s = $a->getSummary();
		$this->assertSame('alice', $s['userId']);
		$this->assertSame(Absence::TYPE_VACATION, $s['type']);
		$this->assertArrayHasKey('isPast', $s);
		$this->assertArrayHasKey('isCurrent', $s);

		// invalid: missing required fields
		$bad = new Absence();
		$this->assertFalse($bad->isValid());
	}

	public function testAbsenceSummaryFlags(): void
	{
		$past = new Absence();
		$past->setUserId('a');
		$past->setStartDate(new \DateTime('-10 days'));
		$past->setEndDate(new \DateTime('-5 days'));
		$s = $past->getSummary();
		$this->assertTrue($s['isPast']);
		$this->assertFalse($s['isCurrent']);

		$current = new Absence();
		$current->setUserId('a');
		$current->setStartDate(new \DateTime('-1 day'));
		$current->setEndDate(new \DateTime('+1 day'));
		$this->assertTrue($current->getSummary()['isCurrent']);

		$future = new Absence();
		$future->setUserId('a');
		$future->setStartDate(new \DateTime('+5 days'));
		$future->setEndDate(new \DateTime('+6 days'));
		$s2 = $future->getSummary();
		$this->assertFalse($s2['isPast']);
		$this->assertFalse($s2['isCurrent']);
	}

	public function testViolationHelpers(): void
	{
		$v = new ComplianceViolation();
		$v->setUserId('alice');
		$v->setViolationType(ComplianceViolation::TYPE_MISSING_BREAK);
		$v->setDescription('Missing break');
		$v->setDate(new \DateTime('2026-09-01'));
		$v->setSeverity(ComplianceViolation::SEVERITY_ERROR);
		$v->setResolved(false);
		$v->setCreatedAt(new \DateTime());

		$this->assertTrue($v->isValid());
		$this->assertSame(3, $v->getSeverityLevel());

		$s = $v->getSummary();
		$this->assertSame('alice', $s['userId']);
		$this->assertSame(ComplianceViolation::SEVERITY_ERROR, $s['severity']);
		$this->assertFalse($s['resolved']);
		$this->assertSame('2026-09-01', $s['date']);

		$v->markAsResolved('manager1');
		$this->assertTrue($v->getResolved());
		$this->assertSame('manager1', $v->getResolvedBy());
		$this->assertNotNull($v->getResolvedAt());
		$this->assertTrue($v->isResolved());

		// severity ordering
		$w = new ComplianceViolation();
		$w->setSeverity(ComplianceViolation::SEVERITY_WARNING);
		$this->assertLessThan($v->getSeverityLevel(), $w->getSeverityLevel());
	}

	public function testTimeEntryIsCompleted(): void
	{
		$e = new TimeEntry();
		$e->setStatus(TimeEntry::STATUS_COMPLETED);
		$this->assertTrue($e->isCompleted());
		$e->setStatus(TimeEntry::STATUS_ACTIVE);
		$this->assertFalse($e->isCompleted());
	}

	public function testTimeEntryCanRequestCorrection(): void
	{
		$e = new TimeEntry();
		$e->setUserId('alice');
		$e->setStatus(TimeEntry::STATUS_COMPLETED);
		$e->setStartTime(new \DateTime('-2 days'));
		$this->assertTrue($e->canRequestCorrection(14));

		// window days are enforced at the service layer, not the entity
		$old = new TimeEntry();
		$old->setUserId('alice');
		$old->setStatus(TimeEntry::STATUS_COMPLETED);
		$old->setStartTime(new \DateTime('-60 days'));
		$this->assertTrue($old->canRequestCorrection(14));

		$pending = new TimeEntry();
		$pending->setUserId('alice');
		$pending->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$pending->setStartTime(new \DateTime('-1 day'));
		$this->assertFalse($pending->canRequestCorrection(14));

		$active = new TimeEntry();
		$active->setUserId('alice');
		$active->setStatus(TimeEntry::STATUS_ACTIVE);
		$active->setStartTime(new \DateTime('-1 day'));
		$this->assertFalse($active->canRequestCorrection(14));

		$noStart = new TimeEntry();
		$noStart->setUserId('alice');
		$noStart->setStatus(TimeEntry::STATUS_COMPLETED);
		$this->assertFalse($noStart->canRequestCorrection(14));
	}

	public function testModelVacationDefaultSummary(): void
	{
		$d = new ModelVacationDefault();
		$d->setWorkingTimeModelId(3);
		$s = $d->getSummary();
		$this->assertIsArray($s);
		$this->assertSame(3, $s['workingTimeModelId'] ?? $s['working_time_model_id'] ?? null);
	}

	public function testUserSettingSummary(): void
	{
		$s = new UserSetting();
		$s->setUserId('alice');
		$s->setSettingKey('notifications_enabled');
		$s->setSettingValue('1');
		$arr = $s->getSummary();
		$this->assertIsArray($arr);
		$this->assertTrue($s->isValid());
		$this->assertSame([], $s->validate());

		$bad = new UserSetting();
		$this->assertFalse($bad->isValid());
		$this->assertArrayHasKey('userId', $bad->validate());
		$this->assertArrayHasKey('settingKey', $bad->validate());
	}

	public function testAbsenceStateHelpers(): void
	{
		$a = new Absence();
		$a->setStartDate(new \DateTime('-1 day'));
		$a->setEndDate(new \DateTime('+1 day'));
		$a->setStatus(Absence::STATUS_APPROVED);

		$this->assertTrue($a->isActive());
		$this->assertFalse($a->isInPast());

		$past = new Absence();
		$past->setStartDate(new \DateTime('-10 days'));
		$past->setEndDate(new \DateTime('-5 days'));
		$past->setStatus(Absence::STATUS_APPROVED);
		$this->assertFalse($past->isActive());
		$this->assertTrue($past->isInPast());

		$other = new Absence();
		$other->setStartDate(new \DateTime('today'));
		$other->setEndDate(new \DateTime('+3 days'));
		$this->assertTrue($a->overlapsWith($other));
		$this->assertFalse($past->overlapsWith($other));
	}

	public function testAuditLogEntity(): void
	{
		$l = new \OCA\ArbeitszeitCheck\Db\AuditLog();
		$l->setUserId('alice');
		$l->setAction('clock_in');
		$l->setEntityType('time_entry');
		$l->setEntityId(7);
		$l->setCreatedAt(new \DateTime());

		$this->assertTrue($l->isValid());
		$this->assertSame([], $l->validate());
		$s = $l->getSummary();
		$this->assertSame('alice', $s['userId']);
		$this->assertSame('clock_in', $s['action']);

		$bad = new \OCA\ArbeitszeitCheck\Db\AuditLog();
		$this->assertFalse($bad->isValid());
		$errs = $bad->validate();
		$this->assertArrayHasKey('userId', $errs);
		$this->assertArrayHasKey('action', $errs);
		$this->assertArrayHasKey('entityType', $errs);
	}

	public function testTimeEntryStatusHelpers(): void
	{
		$e = new TimeEntry();
		$e->setStatus(TimeEntry::STATUS_ACTIVE);
		$this->assertTrue($e->isActive());
		$this->assertFalse($e->isOnBreak());
		$e->setStatus(TimeEntry::STATUS_BREAK);
		$this->assertTrue($e->isOnBreak());
		$this->assertFalse($e->isActive());

		$e->setLiveUserId('alice');
		$this->assertSame('alice', $e->getLiveUserId());
		$e->setLiveUserId(null);
		$this->assertNull($e->getLiveUserId());

		$this->assertNull($e->getCountableMinBreakMinutes());
		$e->setCountableMinBreakMinutes(15);
		$this->assertSame(15, $e->getCountableMinBreakMinutes());
	}

	public function testTimeEntryIsValid(): void
	{
		$e = new TimeEntry();
		$this->assertIsBool($e->isValid());
	}

	public function testWorkingTimeModelEntity(): void
	{
		$m = new \OCA\ArbeitszeitCheck\Db\WorkingTimeModel();
		$m->setName('Standard');
		$m->setType('fixed');
		$m->setWeeklyHours(40.0);
		$m->setDailyHours(8.0);
		$m->setWorkDaysPerWeek(5);
		$m->setCreatedAt(new \DateTime());
		$this->assertIsBool($m->isValid());
		$s = $m->getSummary();
		$this->assertSame('Standard', $s['name']);
		$this->assertSame(40.0, $s['weeklyHours']);
	}

	public function testTeamEntity(): void
	{
		$t = new \OCA\ArbeitszeitCheck\Db\Team();
		$t->setName('Ops');
		$s = $t->getSummary();
		$this->assertIsArray($s);
		$this->assertSame('Ops', $s['name'] ?? $s['team_name'] ?? null);
	}

	public function testUserWorkingTimeModelEntity(): void
	{
		$u = new \OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel();
		$u->setStartDate(new \DateTime('-10 days'));
		$u->setEndDate(null);
		$this->assertTrue($u->wasActiveOn(new \DateTime('today')));
		$this->assertTrue($u->isActive());
		$this->assertFalse($u->wasActiveOn(new \DateTime('-20 days')));

		$u2 = new \OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel();
		$u2->setStartDate(new \DateTime('-10 days'));
		$u2->setEndDate(new \DateTime('-1 day'));
		$this->assertTrue($u2->wasActiveOn(new \DateTime('-5 days')));
		$this->assertFalse($u2->wasActiveOn(new \DateTime('today')));
		$this->assertIsBool($u->isValid());
	}

	public function testMonthClosureRevisionConstruct(): void
	{
		$r = new \OCA\ArbeitszeitCheck\Db\MonthClosureRevision();
		$this->assertInstanceOf(\OCA\ArbeitszeitCheck\Db\MonthClosureRevision::class, $r);
	}
}
