<?php

declare(strict_types=1);

/**
 * Scoped widget tokens (suite widget spec D-03 / E.6 contract).
 *
 * Issue/list/revoke are session-authed. The token-authed execution surface
 * is exactly ONE route — POST /api/widget/clock-toggle — gated on a
 * narrowly-scoped token (scope 'azc-clock'), replay-safe via the stamp
 * replay service's client-request fingerprint, rate-limited, and stamped
 * with channel=widget provenance (MH-P24).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Exception\WidgetTokenLimitException;
use OCA\ArbeitszeitCheck\Service\MobileStampReplayService;
use OCA\ArbeitszeitCheck\Service\WidgetTokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class WidgetTokenController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private WidgetTokenService $tokens,
		private MobileStampReplayService $stampReplay,
		private TimeEntryMapper $timeEntryMapper,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	private function uid(): ?string
	{
		return $this->userSession->getUser()?->getUID();
	}

	/** Issue a scoped token. Raw token returned ONCE — never stored. */
	#[NoAdminRequired]
	public function issue(?string $scope = null, ?string $deviceLabel = null): JSONResponse
	{
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['error' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}
		$scope = $scope ?: WidgetTokenService::SCOPE_CLOCK;
		try {
			[$entity, $raw] = $this->tokens->issue($uid, $scope, $deviceLabel);
		} catch (\InvalidArgumentException) {
			return new JSONResponse(['error' => 'unknown_scope'], Http::STATUS_BAD_REQUEST);
		} catch (WidgetTokenLimitException) {
			return new JSONResponse(['error' => 'token_limit'], Http::STATUS_CONFLICT);
		}
		\OCP\Log\logger('arbeitszeitcheck')->info('widget_token issued', [
			'token_id' => $entity->getId(), 'scope' => $entity->getScope(), 'user' => $uid,
		]);
		return new JSONResponse([
			'id' => $entity->getId(),
			'token' => $raw,
			'scope' => $entity->getScope(),
			'expiresAt' => $entity->getExpiresAt()?->format('c'),
		], Http::STATUS_CREATED);
	}

	/** List the user's tokens (public-safe view — no hashes). */
	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['error' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}
		return new JSONResponse(['tokens' => $this->tokens->listForUser($uid)]);
	}

	/** Revoke a token — owner-only. */
	#[NoAdminRequired]
	public function revoke(int $id): JSONResponse
	{
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['error' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->tokens->revoke($id, $uid)) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		\OCP\Log\logger('arbeitszeitcheck')->info('widget_token revoked', [
			'token_id' => $id, 'user' => $uid,
		]);
		return new JSONResponse(['ok' => true]);
	}

	/**
	 * Token-authed clock toggle — the ONLY widget-executable mutation (D-03).
	 * Verifies the scoped token (deny-by-default), re-reads live server
	 * state (never trusts the snapshot — MH-P19), executes via the replay
	 * service (idempotent on clientRequestId), and stamps channel=widget.
	 * A revoked/expired token degrades to 'navigate' — the widget falls back
	 * to opening the app rather than failing silently (E.6).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'azc_widget_toggle')]
	public function clockToggle(?string $widgetToken = null, ?string $clientRequestId = null): JSONResponse
	{
		if ($widgetToken === null || $widgetToken === '') {
			return new JSONResponse(['action' => 'navigate'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$token = $this->tokens->verify($widgetToken, WidgetTokenService::SCOPE_CLOCK);
		} catch (\Throwable) {
			// Invalid/revoked/expired/wrong-scope → degrade to navigation (E.6).
			return new JSONResponse(['action' => 'navigate'], Http::STATUS_UNAUTHORIZED);
		}
		$uid = $token->getUserId();
		try {
			$active = $this->timeEntryMapper->findActiveByUser($uid);
			if ($active !== null) {
				$payload = $this->stampReplay->clockOut($uid, $clientRequestId, null, 'widget');
			} else {
				$payload = $this->stampReplay->clockIn($uid, null, null, $clientRequestId, null, null, 'widget');
			}
			\OCP\Log\logger('arbeitszeitcheck')->info('capture_channel stamp via widget',
				['action' => 'clock-toggle', 'capture_source' => 'widget', 'user' => $uid]);
			return new JSONResponse([
				'action' => 'executed',
				'clockedIn' => $active === null,
				'result' => $payload,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Widget clock-toggle failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['action' => 'navigate'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
