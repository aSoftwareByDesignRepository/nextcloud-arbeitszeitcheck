<?php

declare(strict_types=1);

/**
 * Holiday controller for the arbeitszeitcheck app
 *
 * Provides a simple read-only API for state-specific holidays
 * for the currently authenticated user. This API is used by
 * the calendar UI and reporting logic to highlight public and
 * company holidays consistently.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class HolidayController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly HolidayService $holidayCalendarService,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(?string $start = null, ?string $end = null): JSONResponse
	{
		$params = $this->request->getParams();
		$start = $start ?? $params['start'] ?? null;
		$end = $end ?? $params['end'] ?? null;

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Unauthorized')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			[$startDt, $endDt] = $this->parseRange($start, $end);
			$state = $this->holidayCalendarService->resolveStateForUser($user->getUID());
			$holidays = $this->holidayCalendarService->getHolidaysForRange($state, $startDt, $endDt);

			return new JSONResponse([
				'success' => true,
				'state' => $state,
				'holidays' => $holidays,
			]);
		} catch (\InvalidArgumentException) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Invalid date range'),
			], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to load holidays', ['exception' => $e]);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Internal error'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Parse and validate the requested date range.
	 *
	 * If no dates are provided, defaults to the current month.
	 * The range is limited to a maximum of two years to avoid abuse.
	 *
	 * @return array{0:\DateTime,1:\DateTime}
	 */
	private function parseRange(?string $start, ?string $end): array
	{
		$today = new \DateTimeImmutable('today');

		$startDt = $start !== null && $start !== ''
			? new \DateTimeImmutable($start)
			: $today->modify('first day of this month');

		$endDt = $end !== null && $end !== ''
			? new \DateTimeImmutable($end)
			: $today->modify('last day of this month');

		if ($endDt < $startDt) {
			throw new \InvalidArgumentException('end before start');
		}

		// Limit range to at most 2 years
		if ($startDt->diff($endDt)->days > 731) {
			throw new \InvalidArgumentException('range too large');
		}

		return [
			new \DateTime($startDt->format('Y-m-d')),
			new \DateTime($endDt->format('Y-m-d')),
		];
	}
}

