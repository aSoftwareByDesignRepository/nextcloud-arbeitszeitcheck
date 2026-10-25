<?php

declare(strict_types=1);

/**
 * Manager bulk-decision API (IMPLEMENTATION-OVERVIEW appendix, spec §9):
 * one atomic approve/reject over a mixed list of pending time entries,
 * absences and business trips. Scope and status are validated per element
 * before the transaction starts; any failure aborts the whole batch.
 * Missing, disabled or out-of-scope items collapse to a uniform 404 so
 * no existence or ownership oracle leaks (AC-GLOBAL-15).
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Exception\BulkDecisionException;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Service\ManagerBulkDecisionService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;

final class ManagerBulkDecisionController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ManagerBulkDecisionService $bulkDecisions,
		private readonly PermissionService $permissionService,
		private readonly IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * POST /api/manager/bulk-decision
	 *
	 * Body: {"action": "approve|reject", "items": [{"type","id"}...], "comment": "?"}
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function decide(): JSONResponse
	{
		try {
			$managerId = $this->uid();
			if (!$this->permissionService->isAdmin($managerId) && !$this->permissionService->canAccessManagerDashboard($managerId)) {
				$this->permissionService->logPermissionDenied($managerId, 'bulk_decision', 'manager_api');
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. This area is available to managers and administrators only.'),
				], Http::STATUS_FORBIDDEN);
			}

			$params = $this->request->getParams();
			$action = $params['action'] ?? null;
			if (!is_string($action)) {
				return $this->invalid('action');
			}
			$items = $params['items'] ?? null;
			if (!is_array($items)) {
				return $this->invalid('items');
			}
			$comment = null;
			if (is_string($params['comment'] ?? null)) {
				$comment = trim($params['comment']);
				$comment = $comment === '' ? null : mb_substr($comment, 0, 2000);
			}

			$results = $this->bulkDecisions->decide($managerId, $action, $items, $comment);

			return new JSONResponse([
				'success' => true,
				'results' => $results,
				'applied' => count($results),
			]);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t($e->getMessage()),
				'error_code' => 'VALIDATION_ERROR',
			], Http::STATUS_BAD_REQUEST);
		} catch (BulkDecisionException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t($e->getMessage()),
				'error_code' => $e->getReasonCode(),
				'error_details' => $e->getDetails(),
				'fields' => $e->fields(),
			], $e->httpStatus());
		} catch (ConcurrentDecisionException $e) {
			return new JSONResponse($e->toHttpPayload(), Http::STATUS_CONFLICT);
		} catch (MonthFinalizedException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t($e->getMessage()),
				'error_code' => 'month_finalized',
			], Http::STATUS_CONFLICT);
		} catch (BusinessTripException $e) {
			if (in_array($e->getReasonCode(), ['TRIP_NOT_FOUND', 'FEATURE_DISABLED'], true)) {
				return new JSONResponse([
					'success' => false,
					'error' => 'Not found.',
					'error_code' => 'NOT_FOUND',
					'fields' => [],
				], Http::STATUS_NOT_FOUND);
			}
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t($e->getMessage()),
				'error_code' => $e->getReasonCode(),
				'fields' => $e->fields(),
			], $e->httpStatus());
		} catch (\OCP\Lock\LockedException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('This item was already decided by another manager.'),
				'error_code' => 'already_decided',
			], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerBulkDecisionController::decide',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function invalid(string $field): JSONResponse
	{
		return new JSONResponse([
			'success' => false,
			'error' => 'Invalid request.',
			'error_code' => 'VALIDATION_ERROR',
			'fields' => [$field => 'invalid'],
		], Http::STATUS_BAD_REQUEST);
	}

	private function uid(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('User not authenticated');
		}
		return $user->getUID();
	}
}
