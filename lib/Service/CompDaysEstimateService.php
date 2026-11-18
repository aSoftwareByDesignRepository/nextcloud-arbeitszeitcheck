<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

/**
 * Compensatory-day ("Abbummeltag") availability estimate.
 *
 * Converts the effective overtime balance — the exact number the user sees on
 * the overtime card (bank-adjusted, payout-adjusted, single source of truth is
 * {@see OvertimeDisplayService::getYearToDateBalanceForTrafficLight}) — into
 * whole compensatory days using schedule-aware average daily planned hours.
 *
 * Deliberately NOT a second balance source: it only divides the displayed
 * balance by the contract's day value. Floored, not rounded — you can only
 * take days you can actually cover (overtime_comp is full-day only).
 *
 * Returns null (fail-closed) when no contractual Soll exists (on_demand) or
 * when the planned-hours basis is unusable — the UI hides the affordance
 * rather than showing a misleading "≈ 0 days".
 */
class CompDaysEstimateService
{
	public function __construct(
		private readonly OvertimeService $overtimeService,
		private readonly OvertimeDisplayService $overtimeDisplayService,
	) {
	}

	/**
	 * @return array{
	 *   days: int,
	 *   daily_hours: float,
	 *   balance_hours: float,
	 *   basis: string
	 * }|null null when no meaningful estimate exists
	 */
	public function estimate(string $userId): ?array
	{
		try {
			$planned = $this->overtimeService->getAverageDailyPlannedHours($userId);
			$daily = (float)($planned['daily_hours'] ?? 0.0);
			$basis = (string)($planned['basis'] ?? '');
			if ($basis === 'on_demand' || $daily <= 0.0) {
				return null;
			}

			$balance = $this->overtimeDisplayService->getYearToDateBalanceForTrafficLight($userId);
			$days = $balance > 0.0 ? (int)floor($balance / $daily) : 0;

			return [
				'days' => $days,
				'daily_hours' => $daily,
				'balance_hours' => round($balance, 2),
				'basis' => $basis,
			];
		} catch (\Throwable $e) {
			// Fail-closed: a broken estimate must never masquerade as "0 days".
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'CompDaysEstimateService::estimate failed for user',
				['exception' => $e]
			);
			return null;
		}
	}
}
