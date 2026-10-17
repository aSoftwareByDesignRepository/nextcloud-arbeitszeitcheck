<?php

declare(strict_types=1);

/**
 * App-admin business-trip API: versioned rule sets, scope assignments,
 * user commute profiles, and approval decisions.
 *
 * Gated by {@see AppAdminMiddleware} plus the feature flag. Approval
 * requires the caller's snapshot token — the decision binds to exactly
 * the frozen fact set that was previewed (spec §9.7).
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCA\ArbeitszeitCheck\Service\BusinessTripAdminService;
use OCA\ArbeitszeitCheck\Service\BusinessTripService;
use OCA\ArbeitszeitCheck\Support\ApiError;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

final class BusinessTripAdminController extends Controller
{
	use CSPTrait;
	use PageShellTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly BusinessTripAdminService $service,
		private readonly BusinessTripService $tripService,
		private readonly WorkforceSuiteConfig $config,
		protected readonly IURLGenerator $urlGenerator,
		protected readonly IL10N $l10n,
		protected readonly PermissionService $permissionService,
		protected readonly LocaleFormatService $localeFormat,
		\OCA\ArbeitszeitCheck\Service\CSPService $cspService,
	) {
		parent::__construct($appName, $request);
		$this->setCspService($cspService);
	}

	/**
	 * Admin page: guided rule builder (spec §7.4), scope assignments, and
	 * user commute profiles. Never a raw-JSON UI — the JS composes the
	 * schema-validated config document from guided choices.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function page(): TemplateResponse
	{
		$this->registerFrontEndAssets('admin-business-trips', null, ['business-trips'], ['common/admin-user-picker']);

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-business-trips', array_merge(
			$this->buildShellParams(
				'admin-business-trips',
				$this->l10n->t('Business trip rules'),
				$this->l10n->t('Define how business trips count towards the time account'),
				[
					'showSubstitutionLink' => false,
					'showManagerLink' => true,
					'showReportsLink' => true,
					'showAdminNav' => true,
				],
				$this->l10n->t('Administration'),
			),
			[
				'businessTripsEnabled' => $this->config->isEnabled('business_trips_enabled'),
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			],
		));
		return $this->configureCSP($response, 'admin');
	}

	// ---------- rule sets ----------

	/** GET /api/admin/business-trip-rule-sets */
	#[NoAdminRequired]
	public function ruleSets(): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true, 'items' => $this->service->listRuleSets()]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** POST /api/admin/business-trip-rule-sets — create a draft version. */
	#[NoAdminRequired]
	public function createRuleSet(): JSONResponse
	{
		$p = $this->request->getParams();
		try {
			$item = $this->service->createRuleSetDraft(
				(string)($p['code'] ?? ''),
				(string)($p['name'] ?? ''),
				(string)($p['jurisdiction'] ?? ''),
				(string)($p['configJson'] ?? ''),
				$this->date($p['validFrom'] ?? null),
				$this->date($p['validTo'] ?? null),
				$this->uid(),
			);
			return new JSONResponse(['success' => true, 'item' => $item], Http::STATUS_CREATED);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** PUT /api/admin/business-trip-rule-sets/{id} — edit a draft. */
	#[NoAdminRequired]
	public function updateRuleSet(int $id): JSONResponse
	{
		$p = $this->request->getParams();
		try {
			$item = $this->service->updateRuleSetDraft($id, (string)($p['configJson'] ?? ''), (string)($p['name'] ?? ''), $this->uid());
			return new JSONResponse(['success' => true, 'item' => $item]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/**
	 * POST /api/admin/business-trip-rule-sets/preview — config dry run:
	 * account targets at basis−1 / basis / basis+1 (spec §7.4.7).
	 */
	#[NoAdminRequired]
	public function previewRuleConfig(): JSONResponse
	{
		$p = $this->request->getParams();
		try {
			$basisRaw = $p['basisMinutes'] ?? null;
			if ($basisRaw === null || preg_match('/^\d+$/D', (string)$basisRaw) !== 1 || (int)$basisRaw > 1440) {
				throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'basisMinutes must be an integer 0..1440.', 400, ['basisMinutes' => 'invalid']);
			}
			$rows = $this->service->previewRuleConfig((string)($p['configJson'] ?? ''), (int)$basisRaw);
			return new JSONResponse(['success' => true, 'rows' => $rows]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** POST /api/admin/business-trip-rule-sets/{id}/activate — atomic single-active. */
	#[NoAdminRequired]
	public function activateRuleSet(int $id): JSONResponse
	{
		try {
			$item = $this->service->activateRuleSet($id, $this->uid());
			return new JSONResponse(['success' => true, 'item' => $item]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/**
	 * POST /api/admin/business-trip-rule-sets/{id}/mark-reviewed — records
	 * the documented legal review that gates activation (spec §10.5).
	 */
	#[NoAdminRequired]
	public function markReviewed(int $id): JSONResponse
	{
		try {
			$configHash = (string)$this->request->getParam('configHash', '');
			if ($configHash === '' || !preg_match('/^[0-9a-f]{64}$/', $configHash)) {
				return $this->serviceError(new BusinessTripException('VALIDATION', 'The reviewed configuration hash is required.', 400, ['configHash' => 'required']));
			}
			$item = $this->service->markRuleSetReviewed($id, $this->uid(), $configHash);
			return new JSONResponse(['success' => true, 'item' => $item]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** GET /api/admin/business-trip-rule-presets — canonical DACH presets. */
	#[NoAdminRequired]
	public function presets(): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true, 'items' => $this->service->listPresets()]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** POST /api/admin/business-trip-rule-presets/{jurisdiction} — preset → draft. */
	#[NoAdminRequired]
	public function createPreset(string $jurisdiction): JSONResponse
	{
		try {
			$item = $this->service->createPresetDraft($jurisdiction, $this->uid());
			return new JSONResponse(['success' => true, 'item' => $item], Http::STATUS_CREATED);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	// ---------- rollout (Phase 6) ----------

	/** GET /api/admin/business-trips/rollout — preflight + flags. */
	#[NoAdminRequired]
	public function rollout(): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true] + $this->service->rolloutStatus());
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/**
	 * POST /api/admin/business-trips/rollout/enable — turns the feature on.
	 * The feature is additive, so enabling is safe any time; the ack gate
	 * mirrors the work-location catalog enable (downgrades cannot write
	 * domain rows created afterwards, so the admin confirms awareness).
	 */
	#[NoAdminRequired]
	public function enable(): JSONResponse
	{
		$ack = $this->request->getParam('acknowledgeRollbackWarning', false);
		if ($ack !== true && $ack !== '1' && $ack !== 'true') {
			return $this->serviceError(new BusinessTripException('VALIDATION', 'Please confirm the rollback warning.', 400, ['acknowledgeRollbackWarning' => 'required']));
		}
		$this->service->enableFeature($this->uid());
		return new JSONResponse(['success' => true, 'business_trips_enabled' => true]);
	}

	/**
	 * POST /api/admin/business-trips/rollout/disable-legacy — Phase-6
	 * cutover; refused (409 + preflight) while readiness is not green.
	 */
	#[NoAdminRequired]
	public function disableLegacy(): JSONResponse
	{
		try {
			$result = $this->service->disableLegacyTripAbsence($this->uid());
			return new JSONResponse(['success' => true] + $result);
		} catch (BusinessTripException $e) {
			if ($e->getReasonCode() === 'TRIP_ROLLOUT_BLOCKED') {
				return new JSONResponse([
					'success' => false,
					'error' => $e->getMessage(),
					'error_code' => $e->getReasonCode(),
					'fields' => [],
					'preflight' => $this->service->rolloutStatus()['preflight'],
				], Http::STATUS_CONFLICT);
			}
			return $this->serviceError($e);
		}
	}

	// ---------- assignments ----------

	/** GET /api/admin/business-trip-rule-assignments */
	#[NoAdminRequired]
	public function assignments(): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true, 'items' => $this->service->listAssignments()]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** POST /api/admin/business-trip-rule-assignments */
	#[NoAdminRequired]
	public function createAssignment(): JSONResponse
	{
		$p = $this->request->getParams();
		try {
			$ruleSetId = $this->positiveInt($p['ruleSetId'] ?? null, 'ruleSetId');
			$effectiveFrom = $this->date($p['effectiveFrom'] ?? null)
				?? throw new BusinessTripException('RULE_ASSIGNMENT_INVALID', 'effectiveFrom is required.', 400, ['effectiveFrom' => 'required']);
			$priority = $p['priority'] ?? 0;
			if (!is_numeric($priority)) {
				throw new BusinessTripException('RULE_ASSIGNMENT_INVALID', 'Priority must be numeric.', 400, ['priority' => 'invalid']);
			}
			$item = $this->service->createAssignment(
				$ruleSetId,
				(string)($p['scopeType'] ?? ''),
				(string)($p['scopeId'] ?? ''),
				(int)$priority,
				$effectiveFrom,
				$this->date($p['effectiveTo'] ?? null),
				$this->uid(),
			);
			return new JSONResponse(['success' => true, 'item' => $item], Http::STATUS_CREATED);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** POST /api/admin/business-trip-rule-assignments/{id}/end — CAS end-date. */
	#[NoAdminRequired]
	public function endAssignment(int $id): JSONResponse
	{
		$p = $this->request->getParams();
		try {
			$to = $this->date($p['effectiveTo'] ?? null)
				?? throw new BusinessTripException('RULE_ASSIGNMENT_INVALID', 'effectiveTo is required.', 400, ['effectiveTo' => 'required']);
			$item = $this->service->endAssignment($id, $this->revision($p), $to, $this->uid());
			return new JSONResponse(['success' => true, 'item' => $item]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	// ---------- user trip profiles ----------

	/** POST /api/admin/business-trip-profiles — normal commute only. */
	#[NoAdminRequired]
	public function setProfile(): JSONResponse
	{
		$p = $this->request->getParams();
		try {
			$userId = trim((string)($p['userId'] ?? ''));
			if ($userId === '' || strlen($userId) > 64) {
				throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'userId is required.', 400, ['userId' => 'required']);
			}
			$commute = $p['normalCommuteMinutes'] ?? null;
			if ($commute !== null && $commute !== '' && preg_match('/^\d+$/D', (string)$commute) !== 1) {
				throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'normalCommuteMinutes must be an integer.', 400, ['normalCommuteMinutes' => 'invalid']);
			}
			$item = $this->service->setProfile(
				$userId,
				($commute === null || $commute === '') ? null : (int)$commute,
				$this->date($p['effectiveFrom'] ?? null) ?? new \DateTimeImmutable('today'),
				$this->uid(),
			);
			return new JSONResponse(['success' => true, 'item' => $item], Http::STATUS_CREATED);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	// ---------- decisions ----------

	/** POST /api/admin/business-trips/{id}/approve — snapshot CAS required. */
	#[NoAdminRequired]
	public function approve(int $id): JSONResponse
	{
		$p = $this->request->getParams();
		try {
			$token = trim((string)($p['snapshotToken'] ?? ''));
			if ($token === '') {
				throw new BusinessTripException('TRIP_SNAPSHOT_REQUIRED', 'A snapshot token is required.', 409, ['snapshot' => 'required']);
			}
			$item = $this->service->approve($id, $this->revision($p), $token, $this->uid());
			return new JSONResponse(['success' => true, 'trip' => $item]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	/** POST /api/admin/business-trips/{id}/reject — CAS. */
	#[NoAdminRequired]
	public function reject(int $id): JSONResponse
	{
		$p = $this->request->getParams();
		$reason = trim((string)($p['reason'] ?? ''));
		try {
			$item = $this->service->reject($id, $this->revision($p), $this->uid(), $reason !== '' ? $reason : null);
			return new JSONResponse(['success' => true, 'trip' => $item]);
		} catch (BusinessTripException $e) {
			return $this->serviceError($e);
		}
	}

	// ------------------------------------------------------------------

	private function serviceError(BusinessTripException $e): JSONResponse
	{
		if (in_array($e->getReasonCode(), ['TRIP_NOT_FOUND', 'RULE_SET_NOT_FOUND', 'RULE_ASSIGNMENT_NOT_FOUND', 'USER_NOT_FOUND', 'FEATURE_DISABLED'], true)) {
			return ApiError::notFound();
		}
		return new JSONResponse([
			'success' => false,
			'error' => $e->getMessage(),
			'error_code' => $e->getReasonCode(),
			'fields' => $e->fields(),
		], $e->httpStatus());
	}

	private function revision(array $p): int
	{
		$raw = $p['revision'] ?? null;
		if ($raw === null || preg_match('/^\d+$/D', (string)$raw) !== 1) {
			throw new BusinessTripException('TRIP_REVISION_STALE', 'The resource changed; reload and retry.', 409, ['revision' => 'required']);
		}
		return (int)$raw;
	}

	private function date(mixed $value): ?\DateTimeImmutable
	{
		if ($value === null || $value === '') {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
		if ($parsed === false || $parsed->format('Y-m-d') !== (string)$value) {
			throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'Invalid date.', 400, ['date' => 'invalid']);
		}
		return $parsed;
	}

	private function positiveInt(mixed $value, string $field): int
	{
		if ($value === null || preg_match('/^\d+$/D', (string)$value) !== 1 || (int)$value <= 0) {
			throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'Invalid id.', 400, [$field => 'invalid']);
		}
		return (int)$value;
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
