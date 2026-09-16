<?php

declare(strict_types=1);

/**
 * TimeTracking controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\BusinessRuleCode;
use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Service\MobileStampReplayService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\TimeCaptureForbiddenException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\Lock\LockedException;

/**
 * TimeTrackingController
 */
class TimeTrackingController extends Controller
{
	private TimeTrackingService $timeTrackingService;
	private MobileStampReplayService $stampReplayService;
	private IUserSession $userSession;
	private IL10N $l10n;

	public function __construct(
		string $appName,
		IRequest $request,
		TimeTrackingService $timeTrackingService,
		MobileStampReplayService $stampReplayService,
		IUserSession $userSession,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->timeTrackingService = $timeTrackingService;
		$this->stampReplayService = $stampReplayService;
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
	 * Translate a service-level exception into a safe HTTP JSONResponse.
	 *
	 * Business-rule violations (typed via BusinessRuleException) carry a
	 * message that the service has already translated for the current
	 * IL10N locale; we forward it verbatim with HTTP 400. Auth failures
	 * map to HTTP 401. Concurrent-mutation lock failures map to HTTP 423
	 * (Locked) so the frontend can present an actionable retry hint instead
	 * of a generic "internal server error". Anything else is treated as an
	 * internal error and replaced with a generic, localized message so we
	 * never leak raw exception text (or PII) to end users.
	 */
	private function buildSafeErrorResponse(\Throwable $e): JSONResponse
	{
		if ($e instanceof TimeCaptureForbiddenException) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage(),
				'error_code' => $e->getErrorCode(),
			], Http::STATUS_FORBIDDEN);
		}

		if ($e instanceof BusinessRuleException) {
			return new JSONResponse($e->toHttpPayload(), Http::STATUS_BAD_REQUEST);
		}

		if ($e instanceof LockedException) {
			$message = $this->l10n->t('Another time-tracking action is in progress on your account. Please wait a moment and try again.');
			return new JSONResponse([
				'success' => false,
				'error' => $message,
				'message' => $message,
				'error_code' => BusinessRuleCode::LOCKED,
			], Http::STATUS_LOCKED);
		}

		if (strpos($e->getMessage(), 'User not authenticated') !== false) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('User not authenticated'),
			], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse([
			'success' => false,
			'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
		], Http::STATUS_INTERNAL_SERVER_ERROR);
	}

	/**
	 * @return JSONResponse HTTP 409 with stable error_code for closed months
	 */
	private function monthFinalizedResponse(?string $errorOverride = null): JSONResponse
	{
		$message = $errorOverride
			?? $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.');
		return new JSONResponse([
			'success' => false,
			'error' => $message,
			'message' => $message,
			'error_code' => BusinessRuleCode::MONTH_FINALIZED,
		], Http::STATUS_CONFLICT);
	}

	/**
	 * Clock in endpoint (called via AJAX with JSON)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'arbeitszeitcheck_clock')]
	#[UserRateLimit(limit: 20, period: 60)]
	public function clockIn(
		?string $projectCheckProjectId = null,
		?string $description = null,
		?string $clientRequestId = null,
		?string $client_request_id = null,
		?string $occurredAt = null,
		?string $occurred_at = null,
	): JSONResponse {
		try {
			$userId = $this->getUserId();
			$payload = $this->stampReplayService->clockIn(
				$userId,
				$projectCheckProjectId,
				$description,
				$clientRequestId ?? $client_request_id,
				$occurredAt ?? $occurred_at,
			);
			return new JSONResponse($payload);
		} catch (StampReplayException $e) {
			return $this->stampReplayErrorResponse($e);
		} catch (MonthFinalizedException $e) {
			return $this->monthFinalizedResponse();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeTrackingController: ' . $e->getMessage(), ["exception" => $e]);
			return $this->buildSafeErrorResponse($e);
		}
	}

	/**
	 * Clock out endpoint (called via AJAX with JSON)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'arbeitszeitcheck_clock')]
	#[UserRateLimit(limit: 20, period: 60)]
	public function clockOut(
		?string $clientRequestId = null,
		?string $client_request_id = null,
		?string $occurredAt = null,
		?string $occurred_at = null,
	): JSONResponse {
		try {
			$userId = $this->getUserId();
			$payload = $this->stampReplayService->clockOut(
				$userId,
				$clientRequestId ?? $client_request_id,
				$occurredAt ?? $occurred_at,
			);
			return new JSONResponse($payload);
		} catch (StampReplayException $e) {
			return $this->stampReplayErrorResponse($e);
		} catch (MonthFinalizedException $e) {
			return $this->monthFinalizedResponse();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeTrackingController: ' . $e->getMessage(), ["exception" => $e]);
			return $this->buildSafeErrorResponse($e);
		}
	}

	/**
	 * Get current status endpoint
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
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
			return $this->buildSafeErrorResponse($e);
		}
	}

	/**
	 * Explicitly enforce the ArbZG §3 daily maximum for the current user.
	 *
	 * Called by the frontend timer when it detects that the 10h ceiling has
	 * been crossed. Returns the resulting status so the caller can refresh
	 * its UI without a blind page reload, eliminating reload loops.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'arbeitszeitcheck_clock')]
	#[UserRateLimit(limit: 20, period: 60)]
	public function enforceDailyMaximum(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$wasReachedBeforeEnforcement = $this->timeTrackingService->isAtOrAboveDailyMaximum($userId);

			try {
				$updatedEntry = $this->timeTrackingService->enforceDailyMaximumForUser($userId);
			} catch (MonthFinalizedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Automatic clock-out blocked: this calendar month has been finalized. Please contact an administrator.'),
					'message' => $this->l10n->t('Automatic clock-out blocked: this calendar month has been finalized. Please contact an administrator.'),
					'error_code' => BusinessRuleCode::MONTH_FINALIZED,
					'status' => $this->timeTrackingService->getStatus($userId),
				], Http::STATUS_CONFLICT);
			}

			$status = $this->timeTrackingService->getStatus($userId);

			return new JSONResponse([
				'success' => true,
				'enforced' => $updatedEntry !== null,
				'daily_maximum_reached' => $wasReachedBeforeEnforcement || $this->timeTrackingService->isAtOrAboveDailyMaximum($userId),
				'status' => $status,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeTrackingController::enforceDailyMaximum: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return $this->buildSafeErrorResponse($e);
		}
	}

	/**
	 * Start break endpoint (called via AJAX with JSON)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'arbeitszeitcheck_clock')]
	#[UserRateLimit(limit: 20, period: 60)]
	public function startBreak(
		?string $clientRequestId = null,
		?string $client_request_id = null,
		?string $occurredAt = null,
		?string $occurred_at = null,
	): JSONResponse {
		try {
			$userId = $this->getUserId();
			$payload = $this->stampReplayService->startBreak(
				$userId,
				$clientRequestId ?? $client_request_id,
				$occurredAt ?? $occurred_at,
			);
			return new JSONResponse($payload);
		} catch (StampReplayException $e) {
			return $this->stampReplayErrorResponse($e);
		} catch (MonthFinalizedException $e) {
			return $this->monthFinalizedResponse();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeTrackingController: ' . $e->getMessage(), ["exception" => $e]);
			return $this->buildSafeErrorResponse($e);
		}
	}

	/**
	 * End break endpoint (called via AJAX with JSON)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'arbeitszeitcheck_clock')]
	#[UserRateLimit(limit: 20, period: 60)]
	public function endBreak(
		?string $clientRequestId = null,
		?string $client_request_id = null,
		?string $occurredAt = null,
		?string $occurred_at = null,
	): JSONResponse {
		try {
			$userId = $this->getUserId();
			$payload = $this->stampReplayService->endBreak(
				$userId,
				$clientRequestId ?? $client_request_id,
				$occurredAt ?? $occurred_at,
			);
			return new JSONResponse($payload);
		} catch (StampReplayException $e) {
			return $this->stampReplayErrorResponse($e);
		} catch (MonthFinalizedException $e) {
			return $this->monthFinalizedResponse();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeTrackingController: ' . $e->getMessage(), ["exception" => $e]);
			return $this->buildSafeErrorResponse($e);
		}
	}

	private function stampReplayErrorResponse(StampReplayException $e): JSONResponse
	{
		$code = $e->getErrorCode();
		$message = match ($code) {
			'STAMP_OCCURRED_AT_OUT_OF_BOUNDS' => $this->l10n->t('The recorded time is outside the allowed range. Check the device clock and try again.'),
			'STAMP_OCCURRED_AT_INVALID' => $this->l10n->t('The recorded time is invalid.'),
			'STAMP_CLIENT_REQUEST_ID_INVALID' => $this->l10n->t('Invalid offline sync reference.'),
			'STAMP_CLIENT_REQUEST_ID_REQUIRED' => $this->l10n->t('Offline sync requires a client reference id.'),
			'STAMP_CLIENT_REQUEST_IN_FLIGHT' => $this->l10n->t('That stamp is still being processed. Please wait a moment.'),
			default => $this->l10n->t('Could not apply the offline stamp.'),
		};
		$status = $code === 'STAMP_CLIENT_REQUEST_IN_FLIGHT'
			? Http::STATUS_CONFLICT
			: Http::STATUS_UNPROCESSABLE_ENTITY;
		return new JSONResponse([
			'success' => false,
			'error' => $message,
			'message' => $message,
			'error_code' => $code,
		], $status);
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
			return $this->buildSafeErrorResponse($e);
		}
	}
}