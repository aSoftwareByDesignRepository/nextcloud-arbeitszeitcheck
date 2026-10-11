<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\BusinessRuleCode;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\TimeCaptureForbiddenException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\DashboardWidgetDataService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Lock\LockedException;

class DashboardWidgetController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly DashboardWidgetDataService $widgetDataService,
		private readonly TimeTrackingService $timeTrackingService,
		private readonly PermissionService $permissionService,
		private readonly ?ComplianceService $complianceService = null,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function employeeData(): JSONResponse {
		$userId = $this->getUserId();
		return new JSONResponse([
			'success' => true,
			'data' => $this->widgetDataService->getEmployeeStatusSummary($userId),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function managerData(?int $limit = 7): JSONResponse {
		$userId = $this->getUserId();
		$data = $this->widgetDataService->getManagerWidgetData($userId, $this->normalizeLimit($limit, 7, 50));
		if (!(bool)$data['authorized']) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Access denied'),
			], Http::STATUS_FORBIDDEN);
		}
		return new JSONResponse(['success' => true, 'data' => $data]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function adminData(?int $limit = 10): JSONResponse {
		$userId = $this->getUserId();
		$data = $this->widgetDataService->getAdminWidgetData($userId, $this->normalizeLimit($limit, 10, 50));
		if (!(bool)$data['authorized']) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Access denied'),
			], Http::STATUS_FORBIDDEN);
		}
		return new JSONResponse(['success' => true, 'data' => $data]);
	}

	#[NoAdminRequired]
	public function clockIn(?string $projectCheckProjectId = null): JSONResponse {
		return $this->handleAction(
			fn (string $userId) => $this->timeTrackingService->clockIn($userId, $projectCheckProjectId),
		);
	}

	#[NoAdminRequired]
	public function startBreak(): JSONResponse {
		return $this->handleAction(fn (string $userId) => $this->timeTrackingService->startBreak($userId));
	}

	#[NoAdminRequired]
	public function endBreak(): JSONResponse {
		return $this->handleAction(fn (string $userId) => $this->timeTrackingService->endBreak($userId));
	}

	#[NoAdminRequired]
	public function clockOut(): JSONResponse {
		return $this->handleAction(fn (string $userId) => $this->timeTrackingService->clockOut($userId));
	}

	private function handleAction(callable $action): JSONResponse {
		try {
			$userId = $this->getUserId();
			$entry = $action($userId);
			try {
				$summary = $entry->getSummary();
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Dashboard widget getSummary failed: ' . $e->getMessage(), [
					'exception' => $e,
				]);
				$summary = [
					'id' => $entry->getId(),
					'userId' => $userId,
					'status' => $entry->getStatus(),
				];
			}
			return new JSONResponse([
				'success' => true,
				'timeEntry' => $summary,
				'break_warning' => $this->breakWarningPayload($entry),
				'status' => $this->timeTrackingService->getStatus($userId),
			]);
		} catch (MonthFinalizedException $e) {
			$message = $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.');
			return new JSONResponse([
				'success' => false,
				'error' => $message,
				'message' => $message,
				'error_code' => BusinessRuleCode::MONTH_FINALIZED,
			], Http::STATUS_CONFLICT);
		} catch (TimeCaptureForbiddenException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage(),
				'message' => $e->getMessage(),
				'error_code' => $e->getErrorCode(),
			], Http::STATUS_FORBIDDEN);
		} catch (BusinessRuleException $e) {
			return new JSONResponse($e->toHttpPayload(), Http::STATUS_BAD_REQUEST);
		} catch (LockedException $e) {
			$message = $this->l10n->t('Another time-tracking action is in progress on your account. Please wait a moment and try again.');
			return new JSONResponse([
				'success' => false,
				'error' => $message,
				'message' => $message,
				'error_code' => BusinessRuleCode::LOCKED,
			], Http::STATUS_LOCKED);
		} catch (\Throwable $e) {
			if (strpos($e->getMessage(), 'User not authenticated') !== false) {
				$message = $this->l10n->t('User not authenticated');
				return new JSONResponse([
					'success' => false,
					'error' => $message,
					'message' => $message,
				], Http::STATUS_UNAUTHORIZED);
			}
			\OCP\Log\logger('arbeitszeitcheck')->warning('Dashboard widget action failed', [
				'exception' => $e,
			]);
			$message = $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
			return new JSONResponse([
				'success' => false,
				'error' => $message,
				'message' => $message,
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Additive break_warning for stamp responses — policy warnings never block.
	 */
	private function breakWarningPayload(TimeEntry $entry): ?array {
		try {
			if ($entry->getStatus() !== TimeEntry::STATUS_COMPLETED) {
				return null;
			}
			$service = $this->complianceService ?? \OCP\Server::get(ComplianceService::class);
			return $service->breakWarningForEntry($entry);
		} catch (\Throwable) {
			return null;
		}
	}

	private function normalizeLimit(?int $limit, int $default, int $max): int {
		if ($limit === null) {
			return $default;
		}
		return max(1, min($max, $limit));
	}

	private function getUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('User not authenticated');
		}
		return $user->getUID();
	}
}
