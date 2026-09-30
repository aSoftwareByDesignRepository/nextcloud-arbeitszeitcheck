<?php

declare(strict_types=1);

/**
 * Atlas coverage lane — exhaustively exercises Notifier::prepare subject
 * branches not covered by NotifierTest (compliance, substitution lifecycle,
 * reminders, traffic-light states, correction results, fallback, wrong app).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Notification;

use OCA\ArbeitszeitCheck\Notification\Notifier;
use OCA\ArbeitszeitCheck\Util\AbsenceWorkingDaysResolver;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

class NotifierSubjectCoverageTest extends TestCase
{
	private Notifier $notifier;

	protected function setUp(): void
	{
		parent::setUp();
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(static fn (string $route): string => 'https://example.test/' . $route);
		$resolver = $this->createMock(AbsenceWorkingDaysResolver::class);
		$resolver->method('resolveFromNotificationParameters')
			->willReturnCallback(static fn (array $p): float => is_numeric($p['days'] ?? null) ? (float)$p['days'] : 0.0);
		$this->notifier = new Notifier($urlGenerator, $resolver);
	}

	public function testWrongAppThrows(): void
	{
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('otherapp');
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($n, 'en');
	}

	/**
	 * @dataProvider subjectProvider
	 */
	public function testSubjectBranchesRenderSubjectAndMessage(
		string $subject,
		array $subjectParams,
		array $messageParams,
		?string $expectedFragment,
		?string $expectedRoute,
	): void {
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('arbeitszeitcheck');
		$n->method('getSubject')->willReturn($subject);
		$n->method('getSubjectParameters')->willReturn($subjectParams);
		$n->method('getMessageParameters')->willReturn($messageParams);

		$parsed = ['subject' => null, 'message' => null, 'link' => null];
		$n->method('setParsedSubject')->willReturnCallback(function ($s) use (&$parsed, $n) {
			$parsed['subject'] = $s;
			return $n;
		});
		$n->method('setParsedMessage')->willReturnCallback(function ($m) use (&$parsed, $n) {
			$parsed['message'] = $m;
			return $n;
		});
		$n->method('setLink')->willReturnCallback(function ($l) use (&$parsed, $n) {
			$parsed['link'] = $l;
			return $n;
		});

		$result = $this->notifier->prepare($n, 'en');
		$this->assertSame($n, $result);
		$this->assertNotNull($parsed['subject']);
		$this->assertNotNull($parsed['message']);
		if ($expectedFragment !== null) {
			$this->assertStringContainsString($expectedFragment, (string)$parsed['message'] . ' ' . (string)$parsed['subject']);
		}
		if ($expectedRoute !== null) {
			$this->assertSame('https://example.test/' . $expectedRoute, $parsed['link']);
		}
	}

	public static function subjectProvider(): array
	{
		return [
			'compliance_violation' => [
				'compliance_violation',
				['violation_type' => 'max_daily_hours'],
				[],
				'max_daily_hours',
				null,
			],
			'substitution_request' => [
				'substitution_request',
				['employee_display_name' => 'Alice', 'start_date' => '2026-08-01', 'end_date' => '2026-08-05'],
				['days' => 3.0],
				'Alice',
				'arbeitszeitcheck.substitute.index',
			],
			'substitute_approved' => [
				'substitute_approved',
				['substitute_display_name' => 'Bob', 'start_date' => '2026-08-01', 'end_date' => '2026-08-05'],
				[],
				'Bob',
				'arbeitszeitcheck.page.absences',
			],
			'substitute_declined with reason' => [
				'substitute_declined',
				['substitute_display_name' => 'Bob', 'reason' => 'Sick'],
				[],
				'Sick',
				'arbeitszeitcheck.page.absences',
			],
			'substitute_declined without reason' => [
				'substitute_declined',
				['substitute_display_name' => 'Bob'],
				[],
				'Bob',
				'arbeitszeitcheck.page.absences',
			],
			'absence_rejected with reason' => [
				'absence_rejected',
				['reason' => 'Peak season'],
				[],
				'Peak season',
				'arbeitszeitcheck.page.absences',
			],
			'absence_rejected without reason' => [
				'absence_rejected',
				[],
				[],
				null,
				'arbeitszeitcheck.page.absences',
			],
			'reminder_clock_out' => [
				'reminder_clock_out',
				[],
				['hours_worked' => 9.5],
				'9.50',
				null,
			],
			'reminder_break' => [
				'reminder_break',
				[],
				['hours_worked' => 6.25, 'required_break' => 30],
				'30',
				null,
			],
			'missing_time_entry' => [
				'missing_time_entry',
				['date' => '2026-09-28'],
				[],
				'2026-09-28',
				null,
			],
			'overtime_traffic_light green' => [
				'overtime_traffic_light',
				['state' => 'green', 'balance' => 1.5],
				[],
				null,
				null,
			],
			'overtime_traffic_light yellow_over' => [
				'overtime_traffic_light',
				['state' => 'yellow_over', 'balance' => 12.5],
				[],
				'12.50',
				null,
			],
			'overtime_traffic_light red_over' => [
				'overtime_traffic_light',
				['state' => 'red_over', 'balance' => 25.0],
				[],
				'25.00',
				null,
			],
			'overtime_traffic_light yellow_under' => [
				'overtime_traffic_light',
				['state' => 'yellow_under', 'balance' => -8.0],
				[],
				'-8.00',
				null,
			],
			'overtime_traffic_light red_under' => [
				'overtime_traffic_light',
				['state' => 'red_under', 'balance' => -20.0],
				[],
				'-20.00',
				null,
			],
			'time_entry_correction_approved' => [
				'time_entry_correction_approved',
				['date' => '2026-09-20'],
				[],
				'2026-09-20',
				null,
			],
			'time_entry_manager_corrected' => [
				'time_entry_manager_corrected',
				['date' => '2026-09-21', 'reason' => 'Typo'],
				[],
				'Typo',
				null,
			],
			'time_entry_correction_rejected' => [
				'time_entry_correction_rejected',
				['date' => '2026-09-22', 'reason' => 'Insufficient proof'],
				[],
				'Insufficient proof',
				null,
			],
			'unknown subject fallback' => [
				'some_future_subject',
				[],
				[],
				null,
				null,
			],
		];
	}
}
