<?php

declare(strict_types=1);

/**
 * TimeTracking controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;

/**
 * TimeTrackingController
 */
class TimeTrackingController extends Controller
{
	private TimeTrackingService $timeTrackingService;
	private IUserSession $userSession;
	private IL10N $l10n;

	public function __construct(
		string $appName,
		IRequest $request,
		TimeTrackingService $timeTrackingService,
		IUserSession $userSession,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->timeTrackingService = $timeTrackingService;
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
	 * Clock in endpoint
	 */
	#[NoAdminRequired]
	public function clockIn(?string $projectCheckProjectId = null, ?string $description = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$timeEntry = $this->timeTrackingService->clockIn($userId, $projectCheckProjectId, $description);

			try {
				$summary = $timeEntry->getSummary();
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary in clockIn: ' . $e->getMessage(), ["exception" => $e]);
				$summary = ['id' => $timeEntry->getId(), 'userId' => $userId, 'status' => $timeEntry->getStatus()];
			}

			return new JSONResponse([
				'success' => true,
				'timeEntry' => $summary
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeTrackingController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			if (strpos($e->getMessage(), 'User not authenticated') !== false) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not authenticated')
				], Http::STATUS_UNAUTHORIZED);
			}
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Clock out endpoint
	 */
	#[NoAdminRequired]
	public function clockOut(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$timeEntry = $this->timeTrackingService->clockOut($userId);

			try {
				$summary = $timeEntry->getSummary();
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary in clockOut: ' . $e->getMessage(), ["exception" => $e]);
				$summary = ['id' => $timeEntry->getId(), 'userId' => $userId, 'status' => $timeEntry->getStatus()];
			}

			return new JSONResponse([
				'success' => true,
				'timeEntry' => $summary
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeTrackingController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			if (strpos($e->getMessage(), 'User not authenticated') !== false) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not authenticated')
				], Http::STATUS_UNAUTHORIZED);
			}
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get current status endpoint
	 */
	#[NoAdminRequired]
	public function getStatus(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$status = $this->timeTrackingService->getStatus($userId);

			return new JSONResponse([
				'success' => true,
				'status' => $status
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeTrackingController::getStatus: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Start break endpoint
	 */
	#[NoAdminRequired]
	public function startBreak(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$timeEntry = $this->timeTrackingService->startBreak($userId);

			try {
				$summary = $timeEntry->getSummary();
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary in startBreak: ' . $e->getMessage(), ["exception" => $e]);
				$summary = ['id' => $timeEntry->getId(), 'userId' => $userId, 'status' => $timeEntry->getStatus()];
			}

			return new JSONResponse([
				'success' => true,
				'timeEntry' => $summary
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeTrackingController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			if (strpos($e->getMessage(), 'User not authenticated') !== false) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not authenticated')
				], Http::STATUS_UNAUTHORIZED);
			}
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * End break endpoint
	 */
	#[NoAdminRequired]
	public function endBreak(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$timeEntry = $this->timeTrackingService->endBreak($userId);

			try {
				$summary = $timeEntry->getSummary();
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary in endBreak: ' . $e->getMessage(), ["exception" => $e]);
				$summary = ['id' => $timeEntry->getId(), 'userId' => $userId, 'status' => $timeEntry->getStatus()];
			}

			return new JSONResponse([
				'success' => true,
				'timeEntry' => $summary
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeTrackingController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			if (strpos($e->getMessage(), 'User not authenticated') !== false) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not authenticated')
				], Http::STATUS_UNAUTHORIZED);
			}
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get break status endpoint
	 */
	#[NoAdminRequired]
	public function getBreakStatus(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$breakStatus = $this->timeTrackingService->getBreakStatus($userId);

			return new JSONResponse([
				'success' => true,
				'breakStatus' => $breakStatus
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeTrackingController::getBreakStatus: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}