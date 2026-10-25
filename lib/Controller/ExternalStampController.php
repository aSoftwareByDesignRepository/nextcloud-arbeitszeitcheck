<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Service\ExternalStampService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Worker-facing surface for suite draft stamps (F16/F19b). Every route is
 * scoped to the authenticated member — a foreign id serializes as the same
 * 404 as a nonexistent one.
 */
class ExternalStampController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ExternalStampService $service,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	private function uid(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new BusinessRuleException('User not authenticated', 'unauthenticated');
		}
		return $user->getUID();
	}

	private function notFound(): JSONResponse
	{
		return new JSONResponse([
			'success' => false,
			'error' => 'NOT_FOUND',
			'message' => 'Not found.',
			'fields' => new \stdClass(),
		], Http::STATUS_NOT_FOUND);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		try {
			$uid = $this->uid();
		} catch (BusinessRuleException) {
			return new JSONResponse(['success' => false, 'error' => 'UNAUTHENTICATED'], Http::STATUS_UNAUTHORIZED);
		}
		$out = [];
		foreach ($this->service->listPending($uid) as $s) {
			$out[] = [
				'id' => (int)$s->getId(),
				'kind' => $s->getKind(),
				'startTs' => $s->getStartTs(),
				'endTs' => $s->getEndTs(),
				'source' => $s->getSourceApp(),
			];
		}
		return new JSONResponse(['success' => true, 'stamps' => $out]);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function confirm(int $id): JSONResponse
	{
		try {
			$result = $this->service->confirm($this->uid(), $id);
			return new JSONResponse(['success' => true, 'timeEntryId' => $result['timeEntryId']]);
		} catch (BusinessRuleException $e) {
			return $this->mapError($e);
		} catch (StampReplayException $e) {
			return $this->stampReplayErrorResponse($e);
		} catch (\Throwable $e) {
			return $this->unexpected($e);
		}
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function dismiss(int $id): JSONResponse
	{
		try {
			$this->service->dismiss($this->uid(), $id);
			return new JSONResponse(['success' => true]);
		} catch (BusinessRuleException $e) {
			return $this->mapError($e);
		} catch (StampReplayException $e) {
			return $this->stampReplayErrorResponse($e);
		} catch (\Throwable $e) {
			return $this->unexpected($e);
		}
	}

	private function mapError(BusinessRuleException $e): JSONResponse
	{
		return match ($e->getReasonCode()) {
			'not_found' => $this->notFound(),
			'already_decided' => new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t($e->getMessage()),
				'message' => $this->l10n->t($e->getMessage()),
				'error_code' => 'already_decided',
			], Http::STATUS_CONFLICT),
			'unauthenticated' => new JSONResponse([
				'success' => false,
				'error' => 'UNAUTHENTICATED',
			], Http::STATUS_UNAUTHORIZED),
			default => new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t($e->getMessage()),
				'message' => $this->l10n->t($e->getMessage()),
				'error_code' => $e->getReasonCode() ?? 'rejected',
			], Http::STATUS_BAD_REQUEST),
		};
	}

	private function stampReplayErrorResponse(StampReplayException $e): JSONResponse
	{
		$code = $e->getErrorCode();
		$message = match ($code) {
			'STAMP_OCCURRED_AT_OUT_OF_BOUNDS' => $this->l10n->t('This suggestion is too old to confirm. Dismiss it or ask your administrator.'),
			'STAMP_OCCURRED_AT_INVALID' => $this->l10n->t('The suggested time is invalid.'),
			'STAMP_CLIENT_REQUEST_IN_FLIGHT' => $this->l10n->t('That suggestion is still being processed. Please wait a moment.'),
			default => $this->l10n->t('Could not apply the suggested time.'),
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

	private function unexpected(\Throwable $e): JSONResponse
	{
		\OCP\Log\logger('arbeitszeitcheck')->error('ExternalStampController failed: ' . $e->getMessage(), ['exception' => $e]);
		return new JSONResponse([
			'success' => false,
			'error' => $this->l10n->t('An unexpected error occurred. Please try again.'),
		], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
