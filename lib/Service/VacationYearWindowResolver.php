<?php

declare(strict_types=1);

/**
 * Resolves calendar vs anniversary vacation year windows.
 *
 * Default mode is calendar — behaviour is identical to pre-Phase-B builds.
 * Anniversary mode uses employment_start; missing start fails closed.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Support\VacationYearWindow;
use OCP\IConfig;

final class VacationYearWindowResolver
{
	public function __construct(
		private readonly IConfig $config,
		private readonly UserEmploymentSettingsService $employmentSettings,
	) {
	}

	public function getMode(): string
	{
		$raw = strtolower(trim((string)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_YEAR_MODE,
			Constants::VACATION_YEAR_MODE_CALENDAR
		)));

		return $raw === Constants::VACATION_YEAR_MODE_ANNIVERSARY
			? Constants::VACATION_YEAR_MODE_ANNIVERSARY
			: Constants::VACATION_YEAR_MODE_CALENDAR;
	}

	public static function normalizeMode(string $mode): string
	{
		$mode = strtolower(trim($mode));
		return $mode === Constants::VACATION_YEAR_MODE_ANNIVERSARY
			? Constants::VACATION_YEAR_MODE_ANNIVERSARY
			: Constants::VACATION_YEAR_MODE_CALENDAR;
	}

	public function isAnniversaryMode(): bool
	{
		return $this->getMode() === Constants::VACATION_YEAR_MODE_ANNIVERSARY;
	}

	/**
	 * Window containing $asOf for this user under the configured org mode.
	 */
	public function resolveForUser(string $userId, \DateTimeInterface $asOf): VacationYearWindow
	{
		$asOfDay = \DateTimeImmutable::createFromInterface($asOf)->setTime(0, 0, 0);
		if (!$this->isAnniversaryMode()) {
			return $this->resolveCalendarYear((int)$asOfDay->format('Y'));
		}

		$start = $this->employmentSettings->getEmploymentStart($userId);
		if ($start === null) {
			$y = (int)$asOfDay->format('Y');
			$cal = $this->resolveCalendarYear($y);
			return new VacationYearWindow(
				VacationYearWindow::MODE_ANNIVERSARY,
				$cal->balanceYearKey,
				$cal->startInclusive,
				$cal->endExclusive,
				$cal->label,
				true,
			);
		}

		return $this->resolveAnniversaryWindow($start, $asOfDay);
	}

	/**
	 * Calendar Jan 1–Dec 31 for $year (half-open end = next Jan 1).
	 */
	public function resolveCalendarYear(int $year): VacationYearWindow
	{
		$year = max(1970, min(2100, $year));
		$start = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
		$end = $start->modify('+1 year');

		return new VacationYearWindow(
			VacationYearWindow::MODE_CALENDAR,
			$year,
			$start,
			$end,
			(string)$year,
			false,
		);
	}

	/**
	 * Anniversary window containing $asOf: [hire+N, hire+(N+1)).
	 */
	public function resolveAnniversaryWindow(
		\DateTimeImmutable $employmentStart,
		\DateTimeInterface $asOf,
	): VacationYearWindow {
		$hire = $employmentStart->setTime(0, 0, 0);
		$asOfDay = \DateTimeImmutable::createFromInterface($asOf)->setTime(0, 0, 0);

		if ($asOfDay < $hire) {
			// Before employment: empty-ish window starting at hire (no entitlement yet via employment end checks elsewhere).
			$end = $this->anniversaryOnOrAfter($hire, 1);
			return new VacationYearWindow(
				VacationYearWindow::MODE_ANNIVERSARY,
				(int)$hire->format('Y'),
				$hire,
				$end,
				$this->formatLabel($hire, $end->modify('-1 day')),
				false,
			);
		}

		$n = 0;
		$cursor = $hire;
		// Cap iterations against corrupt clocks / pathological dates.
		while ($n < 120) {
			$next = $this->anniversaryOnOrAfter($hire, $n + 1);
			if ($asOfDay < $next) {
				return new VacationYearWindow(
					VacationYearWindow::MODE_ANNIVERSARY,
					(int)$cursor->format('Y'),
					$cursor,
					$next,
					$this->formatLabel($cursor, $next->modify('-1 day')),
					false,
				);
			}
			$cursor = $next;
			$n++;
		}

		$end = $cursor->modify('+1 year');
		return new VacationYearWindow(
			VacationYearWindow::MODE_ANNIVERSARY,
			(int)$cursor->format('Y'),
			$cursor,
			$end,
			$this->formatLabel($cursor, $end->modify('-1 day')),
			false,
		);
	}

	/**
	 * All anniversary (or calendar) windows that overlap [rangeStart, rangeEnd] inclusive days.
	 *
	 * @return list<VacationYearWindow>
	 */
	public function windowsOverlappingRange(
		string $userId,
		\DateTimeInterface $rangeStart,
		\DateTimeInterface $rangeEnd,
	): array {
		$from = \DateTimeImmutable::createFromInterface($rangeStart)->setTime(0, 0, 0);
		$to = \DateTimeImmutable::createFromInterface($rangeEnd)->setTime(0, 0, 0);
		if ($to < $from) {
			return [];
		}

		if (!$this->isAnniversaryMode()) {
			$windows = [];
			for ($y = (int)$from->format('Y'); $y <= (int)$to->format('Y'); $y++) {
				$windows[] = $this->resolveCalendarYear($y);
			}
			return $windows;
		}

		$hire = $this->employmentSettings->getEmploymentStart($userId);
		if ($hire === null) {
			return [$this->resolveForUser($userId, $from)];
		}

		$windows = [];
		$cursor = $this->resolveAnniversaryWindow($hire, $from);
		$guard = 0;
		while ($cursor->startInclusive <= $to && $guard < 130) {
			if ($cursor->endExclusive > $from) {
				$windows[] = $cursor;
			}
			$cursor = $this->resolveAnniversaryWindow($hire, $cursor->endExclusive);
			$guard++;
			if ($cursor->startInclusive > $to) {
				break;
			}
		}

		return $windows;
	}

	/**
	 * Anniversary window whose start falls in calendar $balanceYear (balance PK).
	 */
	public function resolveAnniversaryBalanceYear(
		\DateTimeImmutable $employmentStart,
		int $balanceYear,
	): ?VacationYearWindow {
		$hire = $employmentStart->setTime(0, 0, 0);
		$hireYear = (int)$hire->format('Y');
		$n = $balanceYear - $hireYear;
		if ($n < 0 || $n > 120) {
			return null;
		}
		$start = $this->anniversaryOnOrAfter($hire, $n);
		if ((int)$start->format('Y') !== $balanceYear) {
			return null;
		}
		$end = $this->anniversaryOnOrAfter($hire, $n + 1);

		return new VacationYearWindow(
			VacationYearWindow::MODE_ANNIVERSARY,
			$balanceYear,
			$start,
			$end,
			$this->formatLabel($start, $end->modify('-1 day')),
			false,
		);
	}

	/**
	 * Resolve anniversary window for a user by balance year key, or missing-start sentinel.
	 */
	public function resolveAnniversaryForUserBalanceYear(string $userId, int $balanceYear): VacationYearWindow
	{
		$hire = $this->employmentSettings->getEmploymentStart($userId);
		if ($hire === null) {
			$cal = $this->resolveCalendarYear($balanceYear);
			return new VacationYearWindow(
				VacationYearWindow::MODE_ANNIVERSARY,
				$cal->balanceYearKey,
				$cal->startInclusive,
				$cal->endExclusive,
				$cal->label,
				true,
			);
		}
		$window = $this->resolveAnniversaryBalanceYear($hire, $balanceYear);
		if ($window !== null) {
			return $window;
		}
		// Fallback: window containing mid-year probe in that calendar year
		$probe = new \DateTimeImmutable(sprintf('%04d-07-01', max(1970, min(2100, $balanceYear))));
		return $this->resolveAnniversaryWindow($hire, $probe);
	}

	/**
	 * Nth anniversary of hire (N=0 → hire day). Feb 29 → Feb 28 in non-leap years.
	 */
	public function anniversaryOnOrAfter(\DateTimeImmutable $hire, int $n): \DateTimeImmutable
	{
		$n = max(0, $n);
		$month = (int)$hire->format('n');
		$day = (int)$hire->format('j');
		$year = (int)$hire->format('Y') + $n;
		if (!checkdate($month, $day, $year)) {
			// Feb 29 in non-leap → Feb 28
			$day = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
		}

		return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->setTime(0, 0, 0);
	}

	private function formatLabel(\DateTimeImmutable $start, \DateTimeImmutable $endInclusive): string
	{
		return $start->format('Y-m-d') . ' – ' . $endInclusive->format('Y-m-d');
	}
}
