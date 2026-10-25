<?php

declare(strict_types=1);

/**
 * Manager business-trip API (spec §7.5, §9.9).
 *
 * Managers are NOT app admins — these routes live under /api/manager and
 * are scope-checked per trip via PermissionService::canManageEmployee.
 * The scope check runs before any state inspection: an out-of-scope
 * caller gets the same uniform 404 as a missing id (AC-GLOBAL-15, no
 * existence oracle). Approval requires both the revision CAS and the
 * snapshot token — the manager signs off on exactly the fact set the
 * detail endpoint served.
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCA\ArbeitszeitCheck\Service\BusinessTripService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCA\ArbeitszeitCheck\Support\ApiError;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class BusinessTripManagerController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly BusinessTripService $trips,
		private readonly BusinessTripMapper $tripMapper,
		private readonly PermissionService $permissions,
		private readonly WorkforceSuiteConfig $config,
	) {
		parent::__construct($appName, $request);
	}

	/** GET /api/manager/business-trips — submitted trips within scope. */
	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		$manager = $this->uid();
		if (!$this->permissions->isAdmin($manager) && !$this->permissions->canAccessManagerDashboard($manager)) {
			$this->permissions->logPermissionDenied($manager, 'list_business_trips', 'manager_api');
			return new JSONResponse([
				'success' => false,
				'error' => 'Access denied.',
			], Http::STATUS_FORBIDDEN);
		}
		// Admin sees everything; a manager only their own team members.
		$members = $this->permissions->isAdmin($manager)
			? null
			: array_fill_keys($this->permissions->getManagedEmployeeIds($manager), true);
		$items = [];
		foreach ($this->tripMapper->findSubmitted(200) as $trip) {
			if ($members === null || isset($members[(string)$trip->getUserId()])) {
				$items[] = $this->serialize($trip);
			}
		}
		return new JSONResponse(['success' => true, 'items' => $items]);
	}

	/** GET /api/manager/business-trips/{id} — fact/rule/credit card data. */
	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		try {
			$trip = $this->scopedTrip($id);
			return new JSONResponse(['success' => true, 'trip' => $this->serialize($trip)] + $this->trips->detail($trip));
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** POST /api/manager/business-trips/{id}/approve — revision + snapshot CAS. */
	#[NoAdminRequired]
	public function approve(int $id): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		$p = $this->request->getParams();
		try {
			$token = trim((string)($p['snapshotToken'] ?? ''));
			if ($token === '') {
				throw new BusinessTripException('TRIP_SNAPSHOT_REQUIRED', 'A snapshot token is required.', 409, ['snapshot' => 'required']);
			}
			$revision = $this->revision($p);
			$trip = $this->scopedTrip($id);
			$saved = $this->trips->approve($trip, $revision, $token, $this->uid());
			return new JSONResponse(['success' => true, 'trip' => $this->serialize($saved)]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** POST /api/manager/business-trips/{id}/reject — revision CAS + reason. */
	#[NoAdminRequired]
	public function reject(int $id): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		$p = $this->request->getParams();
		$reason = trim((string)($p['reason'] ?? ''));
		if (strlen($reason) > 500) {
			return $this->invalid('reason');
		}
		try {
			$revision = $this->revision($p);
			$trip = $this->scopedTrip($id);
			$saved = $this->trips->reject($trip, $revision, $this->uid(), $reason !== '' ? $reason : null);
			return new JSONResponse(['success' => true, 'trip' => $this->serialize($saved)]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	// ------------------------------------------------------------------

	/** Scope check before any state inspection — uniform 404 otherwise. */
	private function scopedTrip(int $id): BusinessTrip
	{
		try {
			$trip = $this->tripMapper->find($id);
		} catch (DoesNotExistException) {
			throw new BusinessTripException('TRIP_NOT_FOUND', 'Not found.', 404);
		}
		if (!$this->permissions->canManageEmployee($this->uid(), (string)$trip->getUserId())) {
			$this->permissions->logPermissionDenied($this->uid(), 'view_business_trip', 'business_trip', (string)$id);
			throw new BusinessTripException('TRIP_NOT_FOUND', 'Not found.', 404);
		}
		return $trip;
	}

	/** @return array<string,mixed> */
	private function serialize(BusinessTrip $trip): array
	{
		return [
			'id' => (int)$trip->getId(),
			'userId' => (string)$trip->getUserId(),
			'status' => (string)$trip->getStatus(),
			'startsAtUtc' => $trip->getStartsAtUtc()?->format('c'),
			'endsAtUtc' => $trip->getEndsAtUtc()?->format('c'),
			'originTimezone' => (string)$trip->getOriginTimezone(),
			'destinationTimezone' => (string)$trip->getDestinationTimezone(),
			'evaluationTimezone' => (string)$trip->getEvaluationTimezone(),
			'purpose' => $trip->getPurpose(),
			'revision' => (int)$trip->getRevision(),
			'currentSnapshotId' => $trip->getCurrentSnapshotId(),
		];
	}

	private function enabled(): bool
	{
		return $this->config->isEnabled('business_trips_enabled');
	}

	private function revision(array $p): int
	{
		$raw = $p['revision'] ?? null;
		if ($raw === null || preg_match('/^\d+$/D', (string)$raw) !== 1) {
			throw new BusinessTripException('TRIP_REVISION_STALE', 'The trip changed; reload and retry.', 409, ['revision' => 'required']);
		}
		return (int)$raw;
	}

	private function serviceError(BusinessTripException $e): JSONResponse
	{
		if (in_array($e->getReasonCode(), ['TRIP_NOT_FOUND', 'FEATURE_DISABLED'], true)) {
			return ApiError::notFound();
		}
		return new JSONResponse([
			'success' => false,
			'error' => \OCA\ArbeitszeitCheck\Support\WireText::t($e->getMessage()),
			'error_code' => $e->getReasonCode(),
			'fields' => $e->fields(),
		], $e->httpStatus());
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
