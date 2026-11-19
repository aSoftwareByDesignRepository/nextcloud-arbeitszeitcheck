<?php

declare(strict_types=1);

/**
 * Overtime explainability endpoint — the "where does this number come
 * from" drill-down behind the dashboard's absence-credit line.
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Exception\NotAuthenticatedException;
use OCA\ArbeitszeitCheck\Service\CompDaysEstimateService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\OversightScopeService;
use OCA\ArbeitszeitCheck\Service\PaidAbsencePlannedHoursCreditService;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read-only breakdown of the paid-absence credit / Soll reduction for one
 * user and period. Same engine as the aggregate, so `credit_hours` always
 * reconciles with the dashboard tile.
 *
 * AuthZ: self always; admins and managers (via
 * {@see OversightScopeService::canOverseeUser} — existence + scope in one)
 * may drill into overseeable users. Denied and nonexistent targets collapse
 * to one uniform 404 — no existence oracle.
 */
class OvertimeBreakdownController extends Controller
{
	/** Longest explainable custom range (~2 years incl. leap). */
	private const MAX_RANGE_DAYS = 731;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly OvertimeService $overtimeService,
		private readonly PaidAbsencePlannedHoursCreditService $paidAbsenceCreditService,
		private readonly OversightScopeService $oversightScope,
		private readonly UserOvertimeSettingsService $overtimeSettingsService,
		private readonly CompDaysEstimateService $compDaysEstimateService,
		private readonly IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * GET /api/overtime/breakdown?userId=&period=year|month|custom&start=&end=
	 *
	 * @param string|null $userId Optional target user (must be overseeable)
	 * @param string|null $period `year` (default), `month` or `custom`
	 * @param string|null $start  Y-m-d — required for `custom`
	 * @param string|null $end    Y-m-d — required for `custom`
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function breakdown(?string $userId = null, ?string $period = null, ?string $start = null, ?string $end = null): JSONResponse
	{
		// Outside try: NotAuthenticatedException must reach
		// ApiExceptionMiddleware → canonical 401 envelope, not a 500.
		$actor = $this->getUserId();
		try {
			$target = ($userId !== null && trim($userId) !== '') ? trim($userId) : $actor;

			// canOverseeUser covers self/admin/manager + rejects unknown
			// users — one canonical 404 for "not found" and "not yours".
			if (!$this->oversightScope->canOverseeUser($actor, $target)) {
				return $this->notFound();
			}

			$resolved = $this->resolvePeriod($target, $period, $start, $end);
			if (isset($resolved['error'])) {
				return new JSONResponse([
					'success' => false,
					'error' => 'invalid_period',
					'message' => $resolved['error'],
				], Http::STATUS_BAD_REQUEST);
			}
			/** @var \DateTime $periodStart */
			$periodStart = $resolved['start'];
			/** @var \DateTime $periodEnd */
			$periodEnd = $resolved['end'];

			// Same worked-hours input the aggregate uses — single source.
			[, $workedByDay] = $this->overtimeService->workedHoursByDayRange($target, $periodStart, $periodEnd);
			$breakdown = $this->paidAbsenceCreditService->creditBreakdownForRange(
				$target,
				$periodStart,
				$periodEnd,
				$workedByDay,
			);

			foreach (['credited', 'reduced', 'not_counted'] as $bucket) {
				foreach ($breakdown[$bucket] as &$item) {
					$item['label'] = AbsenceTypeCatalog::label($this->l10n, $item['type']);
				}
				unset($item);
			}

			return new JSONResponse([
				'success' => true,
				'user_id' => $target,
				'period' => [
					'preset' => $resolved['preset'],
					'start' => $periodStart->format('Y-m-d'),
					'end' => $periodEnd->format('Y-m-d'),
				],
				'credit_hours' => $breakdown['credit_hours'],
				'days_credited' => $breakdown['days_credited'],
				'reduced_hours' => $breakdown['reduced_hours'],
				'days_reduced' => $breakdown['days_reduced'],
				'credited' => $breakdown['credited'],
				'reduced' => $breakdown['reduced'],
				'not_counted' => $breakdown['not_counted'],
				'comp_estimate' => $this->compDaysEstimateService->estimate($target),
				'algorithm_version' => Constants::OVERTIME_ALGORITHM_VERSION,
				'generated_at' => (new \DateTime())->format(\DateTime::ATOM),
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'OvertimeBreakdownController::breakdown failed: ' . $e->getMessage(),
				['exception' => $e],
			);
			return new JSONResponse([
				'success' => false,
				'error' => 'internal_error',
				'message' => $this->l10n->t('An error occurred'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Resolve the requested period into concrete [start, end] days.
	 * Mirrors the aggregate's YTD semantics: `year` clamps to the
	 * effective year start (tracking-from aware), `month` is the current
	 * calendar month, both end today. `custom` requires two strict Y-m-d
	 * dates, start ≤ end, span ≤ MAX_RANGE_DAYS; the end is capped at
	 * today so the breakdown never explains "future" credit the aggregate
	 * has not counted yet.
	 *
	 * @return array{preset: string, start: \DateTime, end: \DateTime}|array{error: string}
	 */
	private function resolvePeriod(string $target, ?string $period, ?string $start, ?string $end): array
	{
		$now = new \DateTime();
		$now->setTime(0, 0, 0);
		$preset = ($period === null || $period === '') ? 'year' : $period;

		if ($preset === 'year') {
			$startDay = $this->overtimeSettingsService->resolveEffectiveYearStart($target, (int)$now->format('Y'));
			$startDay->setTime(0, 0, 0);
			return ['preset' => 'year', 'start' => $startDay, 'end' => clone $now];
		}

		if ($preset === 'month') {
			$startDay = new \DateTime($now->format('Y-m-01'));
			$startDay->setTime(0, 0, 0);
			return ['preset' => 'month', 'start' => $startDay, 'end' => clone $now];
		}

		if ($preset === 'custom') {
			$startDay = $this->parseDate($start);
			$endDay = $this->parseDate($end);
			if ($startDay === null || $endDay === null || $endDay < $startDay) {
				return ['error' => $this->l10n->t('Provide a valid start and end date (YYYY-MM-DD).')];
			}
			if ($endDay > $now) {
				$endDay = clone $now;
			}
			if ($endDay < $startDay) {
				// Fully-future range clamped to today: degenerate to an
				// empty period rather than shipping start > end on the wire.
				$endDay = clone $startDay;
			}
			if ($endDay->diff($startDay)->days > self::MAX_RANGE_DAYS) {
				return ['error' => $this->l10n->t('The selected range is too long.')];
			}
			return ['preset' => 'custom', 'start' => $startDay, 'end' => $endDay];
		}

		return ['error' => $this->l10n->t('Unknown period.')];
	}

	private function parseDate(?string $value): ?\DateTime
	{
		if ($value === null) {
			return null;
		}
		$value = trim($value);
		$dt = \DateTime::createFromFormat('!Y-m-d', $value);
		if ($dt === false || $dt->format('Y-m-d') !== $value) {
			return null;
		}
		return $dt;
	}

	/**
	 * Canonical uniform-404 payload — identical for unknown and
	 * non-overseeable targets (no existence oracle).
	 */
	private function notFound(): JSONResponse
	{
		return new JSONResponse([
			'success' => false,
			'error' => 'NOT_FOUND',
			'message' => 'Not found.',
			'fields' => new \ArrayObject(),
		], Http::STATUS_NOT_FOUND);
	}

	/**
	 * @throws NotAuthenticatedException
	 */
	private function getUserId(): string
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			throw new NotAuthenticatedException();
		}
		return $user->getUID();
	}
}
