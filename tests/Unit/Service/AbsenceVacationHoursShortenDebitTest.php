<?php

declare(strict_types=1);

/**
 * Hours-mode vacation shorten must use schedule debit, not day-ratio (BANSS).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\VacationHoursDebitService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AbsenceVacationHoursShortenDebitTest extends TestCase
{
	/**
	 * @return array{0: AbsenceService, 1: VacationHoursDebitService&\PHPUnit\Framework\MockObject\MockObject}
	 */
	private function serviceWithHoursDebit(): array
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_HOURS;
				}
				if ($key === Constants::CONFIG_VACATION_HOURS_PER_DAY) {
					return '8';
				}
				return $default;
			}
		);
		$unit = new VacationUnitService($config);
		$debit = $this->createMock(VacationHoursDebitService::class);

		$ref = new ReflectionClass(AbsenceService::class);
		/** @var AbsenceService $svc */
		$svc = $ref->newInstanceWithoutConstructor();
		foreach ([
			'vacationUnitService' => $unit,
			'vacationHoursDebitService' => $debit,
			'l10n' => $this->createMock(\OCP\IL10N::class),
		] as $prop => $val) {
			$p = $ref->getProperty($prop);
			$p->setValue($svc, $val);
		}
		$l10n = $ref->getProperty('l10n')->getValue($svc);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);

		return [$svc, $debit];
	}

	private function invokeRecompute(
		AbsenceService $svc,
		Absence $absence,
		\DateTimeInterface $originalEnd,
		array $oldData,
		float $workingDays,
	): void {
		$ref = new ReflectionClass($svc);
		$m = $ref->getMethod('recomputeVacationHoursAfterShorten');
		$m->invoke($svc, $absence, $originalEnd, $oldData, $workingDays);
	}

	public function testShortenFullWeekToThursdayUsesBanSSNetsNotDayRatio(): void
	{
		[$svc, $debit] = $this->serviceWithHoursDebit();
		$debit->method('estimateForUserRange')->willReturnCallback(
			static function (string $userId, \DateTimeInterface $start, \DateTimeInterface $end): array {
				unset($userId);
				$s = $start->format('Y-m-d');
				$e = $end->format('Y-m-d');
				// Full BANSS week Mon–Fri = 38.5; Mon–Thu = 34.0 (not 5→4 day ratio 30.8).
				if ($s === '2026-08-03' && $e === '2026-08-07') {
					return ['hours' => 38.5, 'basis' => 'weekday_schedule', 'average_daily' => 7.7, 'one_day_hours' => 8.5, 'weekday_nets' => null];
				}
				if ($s === '2026-08-03' && $e === '2026-08-06') {
					return ['hours' => 34.0, 'basis' => 'weekday_schedule', 'average_daily' => 7.7, 'one_day_hours' => 8.5, 'weekday_nets' => null];
				}
				self::fail("unexpected range $s..$e");
			}
		);

		$absence = new Absence();
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setUserId('alice');
		$absence->setStartDate(new \DateTime('2026-08-03'));
		$absence->setEndDate(new \DateTime('2026-08-06'));
		$absence->setDays(4.0);
		$absence->setDurationHours(38.5);
		$oldData = ['days' => 5.0, 'duration_hours' => 38.5];

		$this->invokeRecompute($svc, $absence, new \DateTime('2026-08-07'), $oldData, 4.0);

		$this->assertSame(34.0, $absence->getDurationHours());
		$this->assertNotEquals(round(38.5 * (4.0 / 5.0), 2), $absence->getDurationHours());
	}

	public function testShortenPreservesPartialDayRatio(): void
	{
		[$svc, $debit] = $this->serviceWithHoursDebit();
		$debit->method('estimateForUserRange')->willReturnCallback(
			static function (string $userId, \DateTimeInterface $start, \DateTimeInterface $end): array {
				unset($userId, $start);
				$e = $end->format('Y-m-d');
				if ($e === '2026-08-07') {
					return ['hours' => 38.5, 'basis' => 'weekday_schedule', 'average_daily' => 7.7, 'one_day_hours' => 8.5, 'weekday_nets' => null];
				}
				return ['hours' => 34.0, 'basis' => 'weekday_schedule', 'average_daily' => 7.7, 'one_day_hours' => 8.5, 'weekday_nets' => null];
			}
		);

		$absence = new Absence();
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setUserId('alice');
		$absence->setStartDate(new \DateTime('2026-08-03'));
		$absence->setEndDate(new \DateTime('2026-08-06'));
		$absence->setDays(4.0);
		// Half of full-week schedule booked originally.
		$absence->setDurationHours(19.25);
		$oldData = ['days' => 5.0];

		$this->invokeRecompute($svc, $absence, new \DateTime('2026-08-07'), $oldData, 4.0);

		$this->assertSame(17.0, $absence->getDurationHours());
	}

	public function testMissingStoredHoursRefillsFromSchedule(): void
	{
		[$svc, $debit] = $this->serviceWithHoursDebit();
		$debit->method('estimateForUserRange')->willReturn(
			['hours' => 34.0, 'basis' => 'weekday_schedule', 'average_daily' => 7.7, 'one_day_hours' => 8.5, 'weekday_nets' => null]
		);

		$absence = new Absence();
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setUserId('alice');
		$absence->setStartDate(new \DateTime('2026-08-03'));
		$absence->setEndDate(new \DateTime('2026-08-06'));
		$absence->setDays(4.0);
		$absence->setDurationHours(null);

		$this->invokeRecompute($svc, $absence, new \DateTime('2026-08-07'), ['days' => 5.0], 4.0);

		$this->assertSame(34.0, $absence->getDurationHours());
	}

	public function testSourceRejectsDayRatioAndUsesServerFill(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AbsenceService.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('function recomputeVacationHoursAfterShorten', $src);
		$this->assertStringContainsString("['server_may_fill_hours' => true]", $src);
		$this->assertStringNotContainsString(
			'((float)$oldHours) * ($workingDays / $oldDays)',
			$src,
			'day-ratio shorten must not remain for hours mode'
		);
	}
}
