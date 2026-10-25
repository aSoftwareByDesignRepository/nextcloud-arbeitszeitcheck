<?php

declare(strict_types=1);

/**
 * Employee special-entitlement account API.
 *
 * Public labels, units and balances are server-supplied so the web and mobile
 * clients can render future entitlement types without shipping a new enum.
 * Confidential labels and resolution traces are intentionally absent here.
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Exception\SpecialEntitlementException;
use OCA\ArbeitszeitCheck\Exception\NotAuthenticatedException;
use OCA\ArbeitszeitCheck\Service\SpecialEntitlementService;
use OCA\ArbeitszeitCheck\Support\ApiError;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class SpecialEntitlementController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly SpecialEntitlementService $entitlements,
	) {
		parent::__construct($appName, $request);
	}

	/** GET /api/special-entitlements[?asOf=YYYY-MM-DD] */
	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		if (!$this->entitlements->isEnabled()) {
			return $this->notFound();
		}
		$asOf = new \DateTimeImmutable('today');
		$rawAsOf = trim((string)$this->request->getParam('asOf', ''));
		if ($rawAsOf !== '') {
			$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawAsOf);
			if ($parsed === false || $parsed->format('Y-m-d') !== $rawAsOf) {
				return new JSONResponse([
					'success' => false,
					'error' => 'Invalid date.',
					'error_code' => 'VALIDATION_ERROR',
					'fields' => ['asOf' => 'invalid'],
				], Http::STATUS_BAD_REQUEST);
			}
			$asOf = $parsed;
		}
		try {
			return new JSONResponse([
				'success' => true,
				'asOf' => $asOf->format('Y-m-d'),
				'items' => $this->entitlements->listAccounts($this->uid(), $asOf),
			]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	/** GET /api/special-entitlements/{typeCode}/history — own ledger movements only. */
	#[NoAdminRequired]
	public function history(string $typeCode): JSONResponse
	{
		if (!$this->entitlements->isEnabled()) {
			return $this->notFound();
		}
		if (preg_match('/^[a-z0-9_]{1,64}$/D', $typeCode) !== 1) {
			return $this->notFound();
		}
		$limit = $this->boundedInt('limit', 50, 1, 200);
		$offset = $this->boundedInt('offset', 0, 0, 1000000);
		if ($limit === null || $offset === null) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Invalid pagination parameter.',
				'error_code' => 'VALIDATION_ERROR',
				'fields' => ['limit' => 'invalid'],
			], Http::STATUS_BAD_REQUEST);
		}
		$result = $this->entitlements->historyForUser($this->uid(), $typeCode, $limit, $offset);
		if ($result === null) {
			return $this->notFound();
		}
		return new JSONResponse(['success' => true] + $result);
	}

	private function boundedInt(string $name, int $default, int $min, int $max): ?int
	{
		$raw = $this->request->getParam($name, null);
		if ($raw === null || $raw === '') {
			return $default;
		}
		$value = trim((string)(is_array($raw) ? reset($raw) : $raw));
		if (preg_match('/^\d+$/D', $value) !== 1 || (int)$value < $min || (int)$value > $max) {
			return null;
		}
		return (int)$value;
	}

	private function serviceError(SpecialEntitlementException $e): JSONResponse
	{
		if ($e->getReasonCode() === 'NOT_FOUND') {
			return $this->notFound();
		}
		return new JSONResponse([
			'success' => false,
			'error' => \OCA\ArbeitszeitCheck\Support\WireText::t($e->getMessage()),
			'error_code' => $e->getReasonCode(),
			'fields' => $e->fields(),
		], $e->httpStatus());
	}

	private function notFound(): JSONResponse
	{
		return ApiError::notFound();
	}

	private function uid(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotAuthenticatedException();
		}
		return $user->getUID();
	}
}
