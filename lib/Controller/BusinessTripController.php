<?php

declare(strict_types=1);

/**
 * Employee business-trip API (spec §9.9).
 *
 * Every ID-bearing route collapses "not found" and "not yours" into the
 * canonical NOT_FOUND payload. All mutations require `revision` (CAS) and
 * create requires `requestKey` (idempotent replay). The surface stays
 * flag-gated: while `business_trips_enabled` is off every route answers
 * the same 404 as an unknown resource — disabled features do not exist.
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCA\ArbeitszeitCheck\Service\BusinessTripService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCA\ArbeitszeitCheck\Support\ApiError;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class BusinessTripController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly BusinessTripService $trips,
		private readonly WorkforceSuiteConfig $config,
		private readonly \OCA\ArbeitszeitCheck\Service\BusinessTripExportService $exportService,
	) {
		parent::__construct($appName, $request);
	}

	/** GET /api/business-trips */
	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		$limit = $this->boundedInt('limit', 50, 1, 200);
		$offset = $this->boundedInt('offset', 0, 0, 1000000);
		if ($limit === null || $offset === null) {
			return $this->invalid('limit');
		}
		$items = array_map([$this, 'serialize'], $this->trips->listForUser($this->uid(), $limit, $offset));
		return new JSONResponse(['success' => true, 'items' => $items]);
	}

	/** GET /api/business-trips/{id} — own trip detail: segments, links, day rows, snapshot token. */
	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		try {
			$trip = $this->ownTrip($id);
			return new JSONResponse(['success' => true, 'trip' => $this->serialize($trip)] + $this->trips->detail($trip));
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** POST /api/business-trips — idempotent on requestKey. */
	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		$p = $this->request->getParams();
		try {
			$trip = $this->trips->createDraft(
				$this->uid(),
				$this->requiredTime($p['startsAtUtc'] ?? null, 'startsAtUtc'),
				$this->requiredTime($p['endsAtUtc'] ?? null, 'endsAtUtc'),
				(string)($p['originTimezone'] ?? ''),
				(string)($p['destinationTimezone'] ?? ''),
				(string)($p['evaluationTimezone'] ?? ''),
				isset($p['purpose']) ? trim((string)$p['purpose']) : null,
				(string)($p['requestKey'] ?? ''),
				$this->uid(),
			);
			return new JSONResponse(['success' => true, 'trip' => $this->serialize($trip)], Http::STATUS_CREATED);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		} catch (\InvalidArgumentException) {
			return $this->invalid('requestKey');
		}
	}

	/** PUT /api/business-trips/{id} — draft content update, CAS. */
	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		$p = $this->request->getParams();
		try {
			$revision = $this->requiredRevision($p);
			$fields = [];
			foreach (['startsAtUtc', 'endsAtUtc'] as $f) {
				if (isset($p[$f])) {
					$fields[$f] = $this->requiredTime($p[$f], $f);
				}
			}
			foreach (['originTimezone', 'destinationTimezone', 'evaluationTimezone'] as $f) {
				if (isset($p[$f])) {
					$fields[$f] = (string)$p[$f];
				}
			}
			if (array_key_exists('purpose', $p)) {
				$fields['purpose'] = $p['purpose'] === null ? null : trim((string)$p['purpose']);
			}
			$trip = $this->trips->updateDraft($this->ownTrip($id), $revision, $fields, $this->uid());
			return new JSONResponse(['success' => true, 'trip' => $this->serialize($trip)]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** DELETE /api/business-trips/{id}?revision=N — drafts only. */
	#[NoAdminRequired]
	public function delete(int $id): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		try {
			$this->trips->delete($this->ownTrip($id), $this->requiredRevision($this->request->getParams()), $this->uid());
			return new JSONResponse(['success' => true]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** PUT /api/business-trips/{id}/segments — atomic replace, CAS. */
	#[NoAdminRequired]
	public function replaceSegments(int $id): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		$p = $this->request->getParams();
		try {
			$segments = $p['segments'] ?? null;
			if (!is_array($segments) || count($segments) > 200) {
				return $this->invalid('segments');
			}
			$trip = $this->trips->replaceSegments($this->ownTrip($id), $this->requiredRevision($p), $segments, $this->uid());
			return new JSONResponse(['success' => true, 'trip' => $this->serialize($trip)]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** POST /api/business-trips/{id}/links — link an own time entry. */
	#[NoAdminRequired]
	public function linkTimeEntry(int $id): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		$raw = $this->request->getParam('timeEntryId', '');
		if (preg_match('/^\d+$/D', (string)$raw) !== 1) {
			return $this->invalid('timeEntryId');
		}
		try {
			$this->trips->linkTimeEntry($this->ownTrip($id), (int)$raw, $this->uid());
			return new JSONResponse(['success' => true], Http::STATUS_CREATED);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** DELETE /api/business-trips/{id}/links/{timeEntryId} — unlink (draft only). */
	#[NoAdminRequired]
	public function unlinkTimeEntry(int $id, int $timeEntryId): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		try {
			$this->trips->unlinkTimeEntry($this->ownTrip($id), $timeEntryId, $this->uid());
			return new JSONResponse(['success' => true]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** POST /api/business-trips/{id}/preview — recalculate + freeze token. */
	#[NoAdminRequired]
	public function preview(int $id): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		try {
			$result = $this->trips->preview($this->ownTrip($id), $this->uid());
			return new JSONResponse(['success' => true] + $result);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** POST /api/business-trips/{id}/submit — draft → submitted, CAS. */
	#[NoAdminRequired]
	public function submit(int $id): JSONResponse
	{
		return $this->mutate($id, fn (BusinessTrip $t, int $rev) => $this->trips->submit($t, $rev, $this->uid()));
	}

	/** POST /api/business-trips/{id}/cancel — CAS; reverses posted credits. */
	#[NoAdminRequired]
	public function cancel(int $id): JSONResponse
	{
		$reason = trim((string)$this->request->getParam('reason', ''));
		return $this->mutate($id, fn (BusinessTrip $t, int $rev) => $this->trips->cancel($t, $rev, $this->uid(), $reason !== '' ? $reason : null));
	}

	/**
	 * GET /api/business-trips/{id}/export.csv|pdf — frozen snapshot rows of
	 * a submitted/approved trip; draft trips have no exportable facts.
	 */
	#[NoAdminRequired]
	public function export(int $id, string $format): JSONResponse|\OCP\AppFramework\Http\DataDownloadResponse
	{
		if (!$this->enabled()) {
			return new JSONResponse(['success' => false, 'error' => 'NOT_FOUND', 'message' => 'Not found.', 'fields' => []], Http::STATUS_NOT_FOUND);
		}
		try {
			$trip = $this->ownTrip($id);
			if (!in_array($trip->getStatus(), [BusinessTrip::STATUS_SUBMITTED, BusinessTrip::STATUS_APPROVED], true)) {
				return ApiError::notFound();
			}
			if ($format === 'pdf') {
				$pdf = $this->exportService->pdf($trip, 'Business trip #' . $trip->getId());
				return new \OCP\AppFramework\Http\DataDownloadResponse($pdf, 'business-trip-' . $trip->getId() . '.pdf', 'application/pdf');
			}
			$csv = $this->exportService->csv($trip);
			return new \OCP\AppFramework\Http\DataDownloadResponse($csv, 'business-trip-' . $trip->getId() . '.csv', 'text/csv; charset=utf-8');
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	private function mutate(int $id, callable $op): JSONResponse
	{
		if (!$this->enabled()) {
			return ApiError::notFound();
		}
		try {
			$trip = $op($this->ownTrip($id), $this->requiredRevision($this->request->getParams()));
			return new JSONResponse(['success' => true, 'trip' => $this->serialize($trip)]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	private function ownTrip(int $id): BusinessTrip
	{
		return $this->trips->findForUserOrFail($this->uid(), $id);
	}

	/** @return array<string,mixed> public serialization — no internals. */
	private function serialize(BusinessTrip $trip): array
	{
		return [
			'id' => (int)$trip->getId(),
			'status' => (string)$trip->getStatus(),
			'startsAtUtc' => $trip->getStartsAtUtc()?->format('c'),
			'endsAtUtc' => $trip->getEndsAtUtc()?->format('c'),
			'originTimezone' => (string)$trip->getOriginTimezone(),
			'destinationTimezone' => (string)$trip->getDestinationTimezone(),
			'evaluationTimezone' => (string)$trip->getEvaluationTimezone(),
			'purpose' => $trip->getPurpose(),
			'revision' => (int)$trip->getRevision(),
		];
	}

	private function enabled(): bool
	{
		return $this->config->isEnabled('business_trips_enabled');
	}

	private function requiredTime(mixed $value, string $field): \DateTime
	{
		if (is_string($value)) {
			$parsed = \DateTime::createFromFormat(\DateTime::ATOM, $value);
			if ($parsed !== false) {
				return $parsed;
			}
		}
		throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'Invalid timestamp.', 400, [$field => 'invalid']);
	}

	private function requiredRevision(array $p): int
	{
		// Fail-closed CAS: missing or malformed revision is a conflict, not a
		// validation error — the client must reload before mutating.
		$raw = $p['revision'] ?? null;
		if ($raw === null || preg_match('/^\d+$/D', (string)$raw) !== 1) {
			throw new BusinessTripException('TRIP_REVISION_STALE', 'The trip changed; reload and retry.', 409, ['revision' => 'required']);
		}
		return (int)$raw;
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

	private function serviceError(BusinessTripException $e): JSONResponse
	{
		if (in_array($e->getReasonCode(), ['TRIP_NOT_FOUND', 'TIME_ENTRY_NOT_FOUND', 'FEATURE_DISABLED'], true)) {
			return ApiError::notFound();
		}
		return new JSONResponse([
			'success' => false,
			'error' => $e->getMessage(),
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
