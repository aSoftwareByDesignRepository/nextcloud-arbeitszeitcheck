<?php

declare(strict_types=1);

/**
 * API for revision-safe month closure (finalize, status, PDF, admin reopen).
 *
 * Mutating routes (finalize, reopen) require a valid session and a CSRF token.
 * The frontend AJAX wrapper sends `requesttoken` in headers automatically.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\MonthClosure;
use OCA\ArbeitszeitCheck\Service\MonthClosureFeature;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class MonthClosureController extends Controller
{
	private IUserSession $userSession;
	private MonthClosureService $monthClosureService;
	private PermissionService $permissionService;
	private IConfig $config;
	private IL10N $l10n;
	private IUserManager $userManager;
	private AuditLogMapper $auditLogMapper;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		MonthClosureService $monthClosureService,
		PermissionService $permissionService,
		IConfig $config,
		IL10N $l10n,
		IUserManager $userManager,
		AuditLogMapper $auditLogMapper,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
		$this->userSession = $userSession;
		$this->monthClosureService = $monthClosureService;
		$this->permissionService = $permissionService;
		$this->config = $config;
		$this->l10n = $l10n;
		$this->userManager = $userManager;
		$this->auditLogMapper = $auditLogMapper;
		$this->logger = $logger;
	}

	private function uid(): string
	{
		$u = $this->userSession->getUser();
		if ($u === null) {
			throw new \RuntimeException('not_logged_in');
		}
		return $u->getUID();
	}

	/**
	 * @return array{type: 'ok', userId: string}|array{type: 'not_found'}|array{type: 'forbidden'}
	 */
	private function resolveClosureTargetUserId(string $actorUserId, ?string $requestedUserId, string $deniedLogAction): array
	{
		$rid = $requestedUserId !== null ? trim($requestedUserId) : '';
		if ($rid === '') {
			return ['type' => 'ok', 'userId' => $actorUserId];
		}
		$u = $this->userManager->get($rid);
		if ($u === null) {
			return ['type' => 'not_found'];
		}
		if ($rid === $actorUserId) {
			return ['type' => 'ok', 'userId' => $actorUserId];
		}
		if (!$this->permissionService->canManageEmployee($actorUserId, $rid)) {
			$this->permissionService->logPermissionDenied($actorUserId, $deniedLogAction, 'user', $rid);

			return ['type' => 'forbidden'];
		}

		return ['type' => 'ok', 'userId' => $rid];
	}

	private function displayNameForPdf(string $actorUserId, string $targetUserId): string
	{
		if ($targetUserId === $actorUserId) {
			$u = $this->userSession->getUser();
			if ($u !== null && $u->getUID() === $actorUserId) {
				$dn = $u->getDisplayName();
				return ($dn !== '' && $dn !== null) ? $dn : $actorUserId;
			}
		}
		$dn = $this->userManager->getDisplayName($targetUserId);

		return ($dn !== '' && $dn !== false) ? $dn : $targetUserId;
	}

	private function safeFilenameSegment(string $userId): string
	{
		$s = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $userId);

		return ($s !== '' && $s !== null) ? $s : 'user';
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function feature(): JSONResponse
	{
		return new JSONResponse([
			'enabled' => MonthClosureFeature::isEnabledFromIConfig($this->config),
			'graceDaysAfterEom' => $this->monthClosureService->getGraceDaysAfterEndOfMonth(),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function periods(): JSONResponse
	{
		try {
			$userId = $this->uid();
			if (!MonthClosureFeature::isEnabledFromIConfig($this->config)) {
				return new JSONResponse([
					'success' => true,
					'featureEnabled' => false,
					'periods' => [],
				]);
			}
			$periods = $this->monthClosureService->getEligiblePeriodsWithTimeEntriesForUser($userId);
			return new JSONResponse([
				'success' => true,
				'featureEnabled' => true,
				'periods' => $periods,
			]);
		} catch (\RuntimeException $e) {
			if ($e->getMessage() === 'not_logged_in') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
			}
			\OCP\Log\logger('arbeitszeitcheck')->error('MonthClosureController::periods failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('MonthClosureController::periods failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(): JSONResponse
	{
		try {
			$userId = $this->uid();
			$year = (int)$this->request->getParam('year', 0);
			$month = (int)$this->request->getParam('month', 0);
			if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Invalid month')], Http::STATUS_BAD_REQUEST);
			}
			$row = $this->monthClosureService->getClosureRow($userId, $year, $month);
			$grace = $this->monthClosureService->getGraceDaysAfterEndOfMonth();
			$deadline = $grace > 0 ? $this->monthClosureService->getManualFinalizeDeadlineDate($year, $month) : null;
			$featureEnabled = MonthClosureFeature::isEnabledFromIConfig($this->config);
			$isFinalized = $row !== null && $row->getStatus() === MonthClosure::STATUS_FINALIZED;

			$finalizeBlockedReason = null;
			$canFinalize = false;
			if (!$featureEnabled) {
				$finalizeBlockedReason = 'feature_disabled';
			} elseif ($isFinalized) {
				$canFinalize = false;
			} elseif ($this->monthClosureService->isCalendarMonthStrictlyAfterCurrent($year, $month)) {
				$finalizeBlockedReason = 'future_month';
			} elseif (!$this->monthClosureService->isCalendarMonthFullyEnded($year, $month)) {
				$finalizeBlockedReason = 'month_not_ended';
			} elseif (!$this->monthClosureService->hasTimeEntryInCalendarMonth($userId, $year, $month)) {
				$finalizeBlockedReason = 'no_time_entries';
			} else {
				$blockReason = $this->monthClosureService->getMonthFinalizeBlockReason($userId, $year, $month);
				if ($blockReason !== null) {
					$finalizeBlockedReason = $blockReason;
				}
			}
			if ($finalizeBlockedReason === null && !$isFinalized) {
				$canFinalize = true;
			}

			$finalizeBlockedMessage = null;
			if ($finalizeBlockedReason !== null) {
				$finalizeBlockedMessage = match ($finalizeBlockedReason) {
					'feature_disabled' => $this->l10n->t('Month finalization is disabled by the administrator.'),
					'future_month' => $this->l10n->t('You cannot finalize a future calendar month.'),
					'month_not_ended' => $this->l10n->t('You can finalize only after that calendar month has ended.'),
					'no_time_entries' => $this->l10n->t('There are no time entries in this calendar month.'),
					'pending_workflow' => $this->l10n->t('Resolve pending time entry or absence approvals in this month before finalizing.'),
					'pending_overtime_payout' => $this->l10n->t('Payroll must record the overtime payout for this month before you can finalize.'),
					default => null,
				};
			}

			return new JSONResponse([
				'success' => true,
				'featureEnabled' => $featureEnabled,
				'year' => $year,
				'month' => $month,
				'status' => $row ? $row->getStatus() : null,
				'version' => $row ? $row->getVersion() : null,
				'snapshotHash' => $row ? $row->getSnapshotHash() : null,
				'finalizedAt' => $row && $row->getFinalizedAt() ? $row->getFinalizedAt()->format(\DateTimeInterface::ATOM) : null,
				'finalizedBy' => $row ? $row->getFinalizedBy() : null,
				'reopenedAt' => $row && $row->getReopenedAt() ? $row->getReopenedAt()->format(\DateTimeInterface::ATOM) : null,
				'graceDaysAfterEom' => $grace,
				'manualFinalizeDeadline' => $deadline ? $deadline->format('Y-m-d') : null,
				'autoFinalized' => $row && $row->getFinalizedBy() === MonthClosureService::AUTO_FINALIZE_ACTOR_ID,
				'canFinalize' => $canFinalize,
				'finalizeBlockedReason' => $finalizeBlockedReason,
				'finalizeBlockedMessage' => $finalizeBlockedMessage,
			]);
		} catch (\RuntimeException $e) {
			if ($e->getMessage() === 'not_logged_in') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
			}
			\OCP\Log\logger('arbeitszeitcheck')->error('MonthClosureController::status failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('MonthClosureController::status failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function finalize(): JSONResponse
	{
		try {
			$userId = $this->uid();
			$params = $this->request->getParams();
			$year = (int)($params['year'] ?? 0);
			$month = (int)($params['month'] ?? 0);
			if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Invalid month')], Http::STATUS_BAD_REQUEST);
			}
			$this->monthClosureService->finalizeMonth($userId, $userId, $year, $month);
			return new JSONResponse(['success' => true]);
		} catch (\RuntimeException $e) {
			$code = $e->getMessage();
			$map = [
				'not_logged_in' => [Http::STATUS_UNAUTHORIZED, $this->l10n->t('Authentication required')],
				'feature_disabled' => [Http::STATUS_FORBIDDEN, $this->l10n->t('Month closure is disabled by the administrator.')],
				'forbidden' => [Http::STATUS_FORBIDDEN, $this->l10n->t('Access denied')],
				'already_finalized' => [Http::STATUS_CONFLICT, $this->l10n->t('This month is already finalized.')],
				'future_month' => [Http::STATUS_BAD_REQUEST, $this->l10n->t('You cannot finalize a future calendar month.')],
				'month_not_ended' => [Http::STATUS_BAD_REQUEST, $this->l10n->t('You can finalize only after that calendar month has ended.')],
				'no_time_entries' => [Http::STATUS_BAD_REQUEST, $this->l10n->t('There are no time entries in this calendar month.')],
				'pending_correction' => [Http::STATUS_CONFLICT, $this->l10n->t('Resolve pending time entry or absence approvals in this month before finalizing.')],
				'pending_workflow' => [Http::STATUS_CONFLICT, $this->l10n->t('Resolve pending time entry or absence approvals in this month before finalizing.')],
				'pending_overtime_payout' => [Http::STATUS_CONFLICT, $this->l10n->t('Payroll must record the overtime payout for this month before you can finalize.')],
			];
			if (isset($map[$code])) {
				return new JSONResponse(['success' => false, 'error' => $map[$code][1]], $map[$code][0]);
			}
			\OCP\Log\logger('arbeitszeitcheck')->error('MonthClosureController::finalize failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Could not finalize month')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function finalizedMonths(): JSONResponse
	{
		try {
			$actor = $this->uid();
			$requested = $this->request->getParam('userId');
			$res = $this->resolveClosureTargetUserId($actor, is_string($requested) ? $requested : null, 'list_finalized_months');
			if ($res['type'] === 'not_found') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User not found.')], Http::STATUS_NOT_FOUND);
			}
			if ($res['type'] === 'forbidden') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Access denied')], Http::STATUS_FORBIDDEN);
			}
			$targetUserId = $res['userId'];
			$months = $this->monthClosureService->listFinalizedYearMonthsForUser($targetUserId);

			return new JSONResponse([
				'success' => true,
				'months' => $months,
				'featureEnabled' => MonthClosureFeature::isEnabledFromIConfig($this->config),
			]);
		} catch (\RuntimeException $e) {
			if ($e->getMessage() === 'not_logged_in') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
			}
			\OCP\Log\logger('arbeitszeitcheck')->error('MonthClosureController::finalizedMonths failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('MonthClosureController::finalizedMonths failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pdf(): DataDownloadResponse|JSONResponse
	{
		try {
			$actor = $this->uid();
			$requested = $this->request->getParam('userId');
			$res = $this->resolveClosureTargetUserId($actor, is_string($requested) ? $requested : null, 'download_month_closure_pdf');
			if ($res['type'] === 'not_found') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User not found.')], Http::STATUS_NOT_FOUND);
			}
			if ($res['type'] === 'forbidden') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Access denied')], Http::STATUS_FORBIDDEN);
			}
			$targetUserId = $res['userId'];
			$year = (int)$this->request->getParam('year', 0);
			$month = (int)$this->request->getParam('month', 0);
			if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Invalid month')], Http::STATUS_BAD_REQUEST);
			}
			$name = $this->displayNameForPdf($actor, $targetUserId);
			$pdf = $this->monthClosureService->buildPdfContent($targetUserId, $year, $month, $name, $this->l10n);
			if ($actor !== $targetUserId) {
				$row = $this->monthClosureService->getClosureRow($targetUserId, $year, $month);
				if ($row !== null) {
					try {
						$this->auditLogMapper->logAction(
							$targetUserId,
							'month_closure_pdf_downloaded',
							'month_closure',
							$row->getId(),
							null,
							['year' => $year, 'month' => $month, 'target_user_id' => $targetUserId],
							$actor
						);
					} catch (\Throwable $e) {
						$this->logger->warning('Audit log failed after month closure PDF download', ['exception' => $e, 'app' => 'arbeitszeitcheck']);
					}
				}
			}
			if ($actor === $targetUserId) {
				$fn = sprintf('arbeitszeitcheck-month-%04d-%02d.pdf', $year, $month);
			} else {
				$fn = sprintf(
					'arbeitszeitcheck-%s-%04d-%02d.pdf',
					$this->safeFilenameSegment($targetUserId),
					$year,
					$month
				);
			}

			return new DataDownloadResponse($pdf, $fn, 'application/pdf');
		} catch (\RuntimeException $e) {
			if ($e->getMessage() === 'not_logged_in') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
			}
			if ($e->getMessage() === 'not_finalized') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Month is not finalized.')], Http::STATUS_NOT_FOUND);
			}
			\OCP\Log\logger('arbeitszeitcheck')->error('MonthClosureController::pdf failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('MonthClosureController::pdf failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function reopen(): JSONResponse
	{
		try {
			$adminId = $this->uid();
			if (!$this->permissionService->isAdmin($adminId)) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Only administrators can reopen a finalized month.')], Http::STATUS_FORBIDDEN);
			}
			$params = $this->request->getParams();
			$year = (int)($params['year'] ?? 0);
			$month = (int)($params['month'] ?? 0);
			$targetUserId = isset($params['userId']) ? (string)$params['userId'] : '';
			if ($targetUserId === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User ID is required.')], Http::STATUS_BAD_REQUEST);
			}
			$reason = isset($params['reason']) ? trim((string)$params['reason']) : '';
			if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Invalid month')], Http::STATUS_BAD_REQUEST);
			}
			$this->monthClosureService->reopenMonth($adminId, $targetUserId, $year, $month, $reason);
			return new JSONResponse(['success' => true]);
		} catch (\RuntimeException $e) {
			$code = $e->getMessage();
			if ($code === 'not_logged_in') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
			}
			if ($code === 'reason_required') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('A reason is required.')], Http::STATUS_BAD_REQUEST);
			}
			if ($code === 'not_finalized') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Month is not finalized.')], Http::STATUS_NOT_FOUND);
			}
			\OCP\Log\logger('arbeitszeitcheck')->error('MonthClosureController::reopen failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
