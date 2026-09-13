<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Notification;

use OCA\ArbeitszeitCheck\Notification\Notifier;
use OCA\ArbeitszeitCheck\Util\AbsenceWorkingDaysResolver;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase
{
	private Notifier $notifier;

	protected function setUp(): void
	{
		parent::setUp();

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(static fn (string $route): string => 'https://example.test/' . $route);

		$workingDaysResolver = $this->createMock(AbsenceWorkingDaysResolver::class);
		$workingDaysResolver->method('resolveFromNotificationParameters')
			->willReturnCallback(static function (array $parameters): float {
				if (isset($parameters['days']) && is_numeric($parameters['days'])) {
					return (float)$parameters['days'];
				}
				return 0.0;
			});

		$this->notifier = new Notifier($urlGenerator, $workingDaysResolver);
	}

	public function testPrepareAbsenceApprovedUsesMessageParametersForWorkingDays(): void
	{
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('arbeitszeitcheck');
		$notification->method('getSubject')->willReturn('absence_approved');
		$notification->method('getSubjectParameters')->willReturn([
			'absence_id' => 42,
			'start_date' => '2026-06-02',
			'end_date' => '2026-06-06',
		]);
		$notification->method('getMessageParameters')->willReturn([
			'type' => 'vacation',
			'days' => 3.0,
		]);

		$capturedMessage = null;
		$notification->expects($this->once())
			->method('setParsedSubject')
			->with($this->anything())
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setParsedMessage')
			->willReturnCallback(function (string $message) use (&$capturedMessage, $notification) {
				$capturedMessage = $message;
				return $notification;
			});
		$notification->expects($this->once())
			->method('setLink')
			->with('https://example.test/arbeitszeitcheck.page.absences')
			->willReturnSelf();

		$result = $this->notifier->prepare($notification, 'en');

		$this->assertSame($notification, $result);
		$this->assertNotNull($capturedMessage);
		$this->assertStringNotContainsString('0 day', (string)$capturedMessage);
		$this->assertStringNotContainsString('vacation request for 0', (string)$capturedMessage);
		// Factory may resolve host default (de) even when prepare(..., 'en') — assert days, not locale.
		$this->assertMatchesRegularExpression('/(?:3\s+working\s+days?|3\s+Arbeitstage)/u', (string)$capturedMessage);
		$this->assertStringContainsString('2026-06-02', (string)$capturedMessage);
		$this->assertStringContainsString('2026-06-06', (string)$capturedMessage);
	}

	public function testPrepareAbsenceApprovedWithSubjectOnlyDaysStillWorks(): void
	{
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('arbeitszeitcheck');
		$notification->method('getSubject')->willReturn('absence_approved');
		$notification->method('getSubjectParameters')->willReturn([
			'type' => 'sick_leave',
			'days' => 2.0,
			'start_date' => '2026-07-01',
			'end_date' => '2026-07-02',
		]);
		$notification->method('getMessageParameters')->willReturn([]);

		$capturedMessage = null;
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setParsedMessage')
			->willReturnCallback(function (string $message) use (&$capturedMessage, $notification) {
				$capturedMessage = $message;
				return $notification;
			});
		$notification->method('setLink')->willReturnSelf();

		$this->notifier->prepare($notification, 'en');

		$this->assertMatchesRegularExpression('/(?:2\s+working\s+days?|2\s+Arbeitstage)/u', (string)$capturedMessage);
		$this->assertStringNotContainsString('sick_leave', (string)$capturedMessage);
	}

	public function testPrepareOvertimeWarningReadsLimitFromMessageParameters(): void
	{
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('arbeitszeitcheck');
		$notification->method('getSubject')->willReturn('overtime_warning');
		$notification->method('getSubjectParameters')->willReturn([
			'overtime_hours' => 12.5,
		]);
		$notification->method('getMessageParameters')->willReturn([
			'overtime_hours' => 12.5,
			'limit' => 10.0,
		]);

		$capturedMessage = null;
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setParsedMessage')
			->willReturnCallback(function (string $message) use (&$capturedMessage, $notification) {
				$capturedMessage = $message;
				return $notification;
			});

		$this->notifier->prepare($notification, 'en');

		$this->assertStringContainsString('10.00', (string)$capturedMessage);
		$this->assertStringContainsString('12.50', (string)$capturedMessage);
	}

	public function testPrepareOvertimePayoutNotification(): void
	{
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('arbeitszeitcheck');
		$notification->method('getSubject')->willReturn('overtime_payout');
		$notification->method('getMessageParameters')->willReturn([
			'hours_paid' => 8.5,
			'year' => 2026,
			'month' => 3,
			'effective_balance_after' => 100.0,
		]);

		$capturedMessage = null;
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setParsedMessage')
			->willReturnCallback(function (string $message) use (&$capturedMessage, $notification) {
				$capturedMessage = $message;
				return $notification;
			});

		$this->notifier->prepare($notification, 'en');

		$this->assertStringContainsString('8.50', (string)$capturedMessage);
		$this->assertStringContainsString('2026-03', (string)$capturedMessage);
	}
}
