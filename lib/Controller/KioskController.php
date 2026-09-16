<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Config\VendorPublicKey;
use OCA\ArbeitszeitCheck\Middleware\KioskUnauthorizedException;
use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskActionService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskOfflineStampService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskAuthService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskEnrollmentService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskErrorMessages;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskHttp;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskTerminalService;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCA\ArbeitszeitCheck\Service\TerminalDeviceService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

class KioskController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly KioskTerminalService $terminalService,
		private readonly KioskAuthService $authService,
		private readonly KioskActionService $actionService,
		private readonly KioskOfflineStampService $offlineStampService,
		private readonly KioskEnrollmentService $enrollmentService,
		private readonly KioskErrorMessages $kioskErrorMessages,
		private readonly LicenseService $licenseService,
		private readonly TerminalDeviceService $terminalDeviceService,
		private readonly TimeZoneService $timeZoneService,
		private readonly LoggerInterface $logger,
		private readonly IThrottler $throttler,
		private readonly IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'arbeitszeitcheck_kiosk_pair')]
	public function pair(string $pairingCode = '', string $label = ''): JSONResponse
	{
		try {
			$result = $this->terminalService->pair($pairingCode, $label);
			return new JSONResponse([
				'success' => true,
				'data' => $result,
			], Http::STATUS_CREATED);
		} catch (KioskException $e) {
			return $this->kioskError($e, 'arbeitszeitcheck_kiosk_pair');
		} catch (\Throwable $e) {
			$this->logger->error('Kiosk pair failed: ' . $e->getMessage(), ['exception' => $e]);
			return $this->kioskError(new KioskException('KIOSK_INTERNAL_ERROR'));
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function config(): JSONResponse
	{
		// requireTerminal must stay outside try — unauthorized must reach middleware.
		$terminal = $this->requireTerminal();
		try {
			$this->terminalService->recordHeartbeat($terminal);

			$enrollment = $this->enrollmentService->getConfigEnrollment($terminal->getTerminalId());
			$envelope = $this->licenseService->buildEnvelope();
			$state = $this->licenseService->getLicenseSummary() ?? [];

			$data = [
				'serverNow' => $this->timeZoneService->nowAsIso(),
				'serverTimezone' => $this->timeZoneService->storageTimeZoneName(),
				'label' => $terminal->getLabel(),
				'licensing' => [
					'terminal' => [
						'planActive' => $this->licenseService->isTerminalPlanActive(),
						'devices' => (int)($state['terminalDevices'] ?? 0),
						'devicesRegistered' => $this->terminalDeviceService->getActiveCount(),
						'expiresAt' => $state['validUntil'] ?? null,
					],
					'envelope' => $envelope,
					'vendorPublicKeyB64' => VendorPublicKey::publicKeyB64(),
				],
			];
			if ($enrollment !== null) {
				$data['enrollment'] = $enrollment;
			}

			return new JSONResponse(['success' => true, 'data' => $data]);
		} catch (KioskException $e) {
			return $this->kioskError($e);
		} catch (\Throwable $e) {
			$this->logger->error('Kiosk config failed: ' . $e->getMessage(), ['exception' => $e]);
			return $this->kioskError(new KioskException('KIOSK_INTERNAL_ERROR'));
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function users(): JSONResponse
	{
		$this->requireTerminal();
		try {
			return new JSONResponse([
				'success' => true,
				'data' => ['users' => $this->authService->listPinUsers()],
			]);
		} catch (KioskException $e) {
			return $this->kioskError($e);
		} catch (\Throwable $e) {
			$this->logger->error('Kiosk users failed: ' . $e->getMessage(), ['exception' => $e]);
			return $this->kioskError(new KioskException('KIOSK_INTERNAL_ERROR'));
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'arbeitszeitcheck_kiosk_identify')]
	public function identify(string $method = '', ?string $rfidUid = null, ?string $userId = null, ?string $pin = null): JSONResponse
	{
		$terminal = $this->requireTerminal();
		try {
			$data = $this->authService->identify($terminal, $method, $rfidUid, $userId, $pin);
			return new JSONResponse(['success' => true, 'data' => $data]);
		} catch (KioskException $e) {
			return $this->kioskError($e, 'arbeitszeitcheck_kiosk_identify');
		} catch (\Throwable $e) {
			// Never leak HTML/exception pages to the tablet — those become "Fehler unknown".
			$this->logger->error('Kiosk identify failed: ' . $e->getMessage(), [
				'exception' => $e,
				'method' => $method,
			]);
			return $this->kioskError(new KioskException('KIOSK_INTERNAL_ERROR'));
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function action(string $sessionToken = '', string $action = ''): JSONResponse
	{
		$terminal = $this->requireTerminal();
		try {
			$data = $this->actionService->performAction($terminal, $sessionToken, $action);
			return new JSONResponse(['success' => true, 'data' => $data]);
		} catch (KioskException $e) {
			return $this->kioskError($e);
		} catch (\Throwable $e) {
			$this->logger->error('Kiosk action failed: ' . $e->getMessage(), [
				'exception' => $e,
				'action' => $action,
			]);
			return $this->kioskError(new KioskException('KIOSK_INTERNAL_ERROR'));
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function stamp(
		string $method = '',
		string $rfidUid = '',
		string $action = '',
		string $clientRequestId = '',
		string $client_request_id = '',
		string $occurredAt = '',
		string $occurred_at = '',
	): JSONResponse {
		$terminal = $this->requireTerminal();
		try {
			if ($method !== 'rfid' || trim($rfidUid) === '') {
				throw new KioskException('KIOSK_ACTION_INVALID');
			}
			$payload = $this->offlineStampService->stampRfid(
				$terminal,
				trim($rfidUid),
				$action,
				$clientRequestId !== '' ? $clientRequestId : ($client_request_id !== '' ? $client_request_id : null),
				$occurredAt !== '' ? $occurredAt : ($occurred_at !== '' ? $occurred_at : null),
			);
			return new JSONResponse($payload);
		} catch (StampReplayException $e) {
			return $this->stampReplayErrorResponse($e);
		} catch (KioskException $e) {
			return $this->kioskError($e, 'arbeitszeitcheck_kiosk_identify');
		} catch (\Throwable $e) {
			$this->logger->error('Kiosk stamp failed: ' . $e->getMessage(), ['exception' => $e]);
			return $this->kioskError(new KioskException('KIOSK_INTERNAL_ERROR'));
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function heartbeat(): JSONResponse
	{
		$terminal = $this->requireTerminal();
		try {
			$this->terminalService->recordHeartbeat($terminal);
			return new JSONResponse(['success' => true]);
		} catch (KioskException $e) {
			return $this->kioskError($e);
		} catch (\Throwable $e) {
			$this->logger->error('Kiosk heartbeat failed: ' . $e->getMessage(), ['exception' => $e]);
			return $this->kioskError(new KioskException('KIOSK_INTERNAL_ERROR'));
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function enrollScan(?string $rfidUid = null): JSONResponse
	{
		$terminal = $this->requireTerminal();
		try {
			$data = $this->enrollmentService->completeScan($terminal->getTerminalId(), $rfidUid ?? '');
			return new JSONResponse(['success' => true, 'data' => $data], Http::STATUS_CREATED);
		} catch (KioskException $e) {
			return $this->kioskError($e);
		} catch (\Throwable $e) {
			$this->logger->error('Kiosk enroll-scan failed: ' . $e->getMessage(), ['exception' => $e]);
			return $this->kioskError(new KioskException('KIOSK_INTERNAL_ERROR'));
		}
	}

	private function requireTerminal(): \OCA\ArbeitszeitCheck\Db\KioskTerminal
	{
		$terminalId = (string)$this->request->getHeader('X-Kiosk-Terminal-Id');
		$token = (string)$this->request->getHeader('X-Kiosk-Token');
		$terminal = $this->terminalService->validateTerminalToken($terminalId, $token);
		if ($terminal === null) {
			throw new KioskUnauthorizedException();
		}
		return $terminal;
	}

	/**
	 * @param string|null $bruteForceAction Matching #[BruteForceProtection] action when the
	 *                                      failure should count toward rate limiting.
	 */
	private function kioskError(KioskException $e, ?string $bruteForceAction = null): JSONResponse
	{
		$code = $e->getErrorCode();
		$response = new JSONResponse([
			'success' => false,
			'error' => $code,
			'message' => $this->kioskErrorMessages->resolve($e),
		], KioskHttp::statusForCode($code));

		/*
		 * Do NOT use JSONResponse::throttle().
		 *
		 * BruteForceMiddleware.afterController runs AFTER app middlewares (reverse order).
		 * When max delay is reached it replaces the body with HTML TooManyRequestsResponse —
		 * exactly the “Fehler unknown” class of bug. Register attempts directly; the
		 * #[BruteForceProtection] attribute still sleeps / MaxDelayReached on the next call,
		 * which our middleware converts to JSON.
		 */
		if ($bruteForceAction !== null && KioskHttp::shouldRegisterBruteForceAttempt($code)) {
			$this->throttler->registerAttempt(
				$bruteForceAction,
				$this->request->getRemoteAddress(),
				['reason' => $code],
			);
		}

		return $response;
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
			'error' => $code,
			'error_code' => $code,
			'message' => $message,
		], $status);
	}
}
