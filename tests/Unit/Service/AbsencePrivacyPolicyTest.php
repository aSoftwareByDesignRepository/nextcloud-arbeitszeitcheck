<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Service\AbsencePrivacyPolicy;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class AbsencePrivacyPolicyTest extends TestCase
{
	public function testSummaryForTeamViewerRemovesReasonAndComment(): void
	{
		$a = new Absence();
		$a->setId(1);
		$a->setUserId('u1');
		$a->setType(Absence::TYPE_VACATION);
		$a->setStartDate(new \DateTime('2025-01-10'));
		$a->setEndDate(new \DateTime('2025-01-12'));
		$a->setDays(3);
		$a->setReason('Family trip');
		$a->setStatus(Absence::STATUS_APPROVED);
		$a->setApproverComment('OK');
		$a->setCreatedAt(new \DateTime());
		$a->setUpdatedAt(new \DateTime());

		$s = AbsencePrivacyPolicy::summaryForTeamViewer($a);
		$this->assertArrayNotHasKey('reason', $s);
		$this->assertArrayNotHasKey('approverComment', $s);
		$this->assertSame(Absence::TYPE_VACATION, $s['type']);
	}

	public function testSummaryForTeamViewerOpaqueTypeForSickLeave(): void
	{
		$a = new Absence();
		$a->setId(2);
		$a->setUserId('u1');
		$a->setType(Absence::TYPE_SICK_LEAVE);
		$a->setStartDate(new \DateTime('2025-02-01'));
		$a->setEndDate(new \DateTime('2025-02-03'));
		$a->setDays(3);
		$a->setReason('flu');
		$a->setStatus(Absence::STATUS_APPROVED);
		$a->setCreatedAt(new \DateTime());
		$a->setUpdatedAt(new \DateTime());

		$s = AbsencePrivacyPolicy::summaryForTeamViewer($a);
		$this->assertSame('absence', $s['type']);
		$this->assertArrayNotHasKey('reason', $s);
	}

	public function testNcCalendarSummaryIsGenericForAllTypes(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($m) => $m);
		$s = AbsencePrivacyPolicy::ncCalendarEventSummary($l10n);
		$this->assertStringContainsString('Absence', $s);
		$this->assertStringContainsString('ArbeitszeitCheck', $s);
		$this->assertStringNotContainsString('Vacation', $s);
		$this->assertStringNotContainsString('Sick', $s);
	}
}
