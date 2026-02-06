<?php

declare(strict_types=1);

/**
 * Report controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Service\ReportingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;

/**
 * ReportController for generating various reports
 */
class ReportController extends Controller
{
	private ReportingService $reportingService;
	private IUserSession $userSession;
	private IL10N $l10n;

	public function __construct(
		string $appName,
		IRequest $request,
		ReportingService $reportingService,
		IUserSession $userSession,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->reportingService = $reportingService;
		$this->userSession = $userSession;
		$this->l10n = $l10n;
	}

	/**
	 * Get current user ID
	 *
	 * @return string
	 */
	private function getUserId(): string
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			throw new \Exception('User not authenticated');
		}
		return $user->getUID();
	}

	/**
	 * Generate daily report
	 *
	 * @param string|null $date Date (Y-m-d format, defaults to today)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function daily(?string $date = null, ?string $userId = null): JSONResponse
	{
		try {
			$reportDate = $date ? new \DateTime($date) : new \DateTime();
			$reportUserId = $userId ?? $this->getUserId();

			$report = $this->reportingService->generateDailyReport($reportDate, $reportUserId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Generate weekly report
	 *
	 * @param string|null $weekStart Week start date (Y-m-d format, defaults to current week)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function weekly(?string $weekStart = null, ?string $userId = null): JSONResponse
	{
		try {
			if ($weekStart) {
				$weekStartDate = new \DateTime($weekStart);
			} else {
				$weekStartDate = new \DateTime();
				$dayOfWeek = (int)$weekStartDate->format('w');
				$weekStartDate->modify('-' . $dayOfWeek . ' days');
			}
			$weekStartDate->setTime(0, 0, 0);

			$reportUserId = $userId ?? $this->getUserId();

			$report = $this->reportingService->generateWeeklyReport($weekStartDate, $reportUserId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Generate monthly report
	 *
	 * @param string|null $month Month (Y-m format, defaults to current month)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function monthly(?string $month = null, ?string $userId = null): JSONResponse
	{
		try {
			if ($month) {
				$monthDate = new \DateTime($month . '-01');
			} else {
				$monthDate = new \DateTime();
			}

			$reportUserId = $userId ?? $this->getUserId();

			$report = $this->reportingService->generateMonthlyReport($monthDate, $reportUserId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Generate overtime report
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function overtime(?string $startDate = null, ?string $endDate = null, ?string $userId = null): JSONResponse
	{
		try {
			$start = $startDate ? new \DateTime($startDate) : (new \DateTime())->modify('-30 days');
			$end = $endDate ? new \DateTime($endDate) : new \DateTime();
			$start->setTime(0, 0, 0);
			$end->setTime(23, 59, 59);

			$reportUserId = $userId ?? $this->getUserId();

			$report = $this->reportingService->generateOvertimeReport($start, $end, $reportUserId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Generate absence report
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function absence(?string $startDate = null, ?string $endDate = null, ?string $userId = null): JSONResponse
	{
		try {
			$start = $startDate ? new \DateTime($startDate) : (new \DateTime())->modify('-1 year');
			$end = $endDate ? new \DateTime($endDate) : new \DateTime();
			$start->setTime(0, 0, 0);
			$end->setTime(23, 59, 59);

			$reportUserId = $userId ?? $this->getUserId();

			$report = $this->reportingService->generateAbsenceReport($start, $end, $reportUserId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Generate team report
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @param string|null $userIds Comma-separated user IDs (defaults to manager's team)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function team(?string $startDate = null, ?string $endDate = null, ?string $userIds = null): JSONResponse
	{
		try {
			$start = $startDate ? new \DateTime($startDate) : (new \DateTime())->modify('-30 days');
			$end = $endDate ? new \DateTime($endDate) : new \DateTime();
			$start->setTime(0, 0, 0);
			$end->setTime(23, 59, 59);

			// If userIds provided, use them; otherwise get manager's team
			if ($userIds) {
				$teamUserIds = array_filter(array_map('trim', explode(',', $userIds)));
			} else {
				// For now, return error - team reports should be accessed via ManagerController
				// This endpoint can be used by managers who provide user IDs
				throw new \Exception($this->l10n->t('User IDs must be provided for team reports'));
			}

			if (empty($teamUserIds)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('No user IDs provided')
				], Http::STATUS_BAD_REQUEST);
			}

			$report = $this->reportingService->generateTeamReport($teamUserIds, $start, $end);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], Http::STATUS_BAD_REQUEST);
		}
	}
}
