<?php

declare(strict_types=1);

/**
 * App-admin work-location catalog + default-policy API
 * (Workforce Policy Suite, Phase 3).
 *
 * Gated by {@see AppAdminMiddleware} (instanceof list) plus
 * `PermissionService::isAdmin` — delegated app admins included; CSRF stays
 * enforced on every mutating route via the framework default.
 *
 * Error contract (spec §9.8): NOT_FOUND is uniform — "doesn't exist" and
 * "not yours" collapse into the same payload.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicy;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinition;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper;
use OCA\ArbeitszeitCheck\Exception\WorkLocationConflictException;
use OCA\ArbeitszeitCheck\Service\WorkLocationAdminService;
use OCA\ArbeitszeitCheck\Service\WorkLocationCatalog;
use OCA\ArbeitszeitCheck\Service\WorkLocationDefaultResolver;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCA\ArbeitszeitCheck\Support\ApiError;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\L10N\IFactory;

class WorkLocationAdminController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly WorkforceSuiteConfig $suiteConfig,
		private readonly WorkLocationCatalog $catalog,
		private readonly WorkLocationAdminService $adminService,
		private readonly WorkLocationDefinitionMapper $definitionMapper,
		private readonly WorkLocationDefaultPolicyMapper $policyMapper,
		private readonly WorkLocationDefaultResolver $resolver,
		private readonly \OCA\ArbeitszeitCheck\Service\WorkLocationMigrationReadinessService $readiness,
		private readonly IFactory $l10nFactory,
		private readonly IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * GET /api/admin/work-locations — full catalog including inactive rows,
	 * with usage and active-policy impact per code.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		if (!$this->suiteConfig->isEnabled('work_location_catalog_enabled')) {
			return $this->notFound();
		}
		$locale = $this->userLocale();
		$today = (new \DateTime('today'))->format('Y-m-d');
		$items = [];
		foreach ($this->catalog->all() as $code => $def) {
			$activePolicies = count($this->policyMapper->findActiveByLocationCode($code, $today));
			$items[] = array_merge($this->serialize($def, $locale), [
				'usage' => [
					'entryCount' => $def->getId() !== null ? $this->definitionMapper->countReferencesByCode($code) : 0,
					'activePolicyCount' => $activePolicies,
				],
			]);
		}
		return new JSONResponse(['success' => true, 'items' => array_values($items)]);
	}

	/**
	 * POST /api/admin/work-locations — create a custom location.
	 * Body: {label, code, category, color?, icon?, labelI18n?}
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		if (!$this->suiteConfig->isEnabled('work_location_catalog_enabled')) {
			return $this->notFound();
		}
		try {
			$row = $this->adminService->create($this->request->getParams(), $this->uid());
		} catch (WorkLocationConflictException $e) {
			return $this->conflictResponse($e->getReasonCode() ?? WorkLocationAdminService::ERR_CODE_EXISTS);
		} catch (\InvalidArgumentException $e) {
			return $this->fieldError($e->getMessage());
		}
		return new JSONResponse([
			'success' => true,
			'item' => $this->serialize($row, $this->userLocale()),
		], Http::STATUS_CREATED);
	}

	/**
	 * PUT /api/admin/work-locations/{id} — label, labelI18n, color, icon,
	 * active. `code` and `reporting_category` are immutable.
	 */
	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		if (!$this->suiteConfig->isEnabled('work_location_catalog_enabled')) {
			return $this->notFound();
		}
		try {
			$row = $this->adminService->update($id, $this->request->getParams(), $this->uid());
		} catch (WorkLocationConflictException $e) {
			return $this->conflictResponse($e->getReasonCode() ?? WorkLocationAdminService::ERR_IN_USE);
		} catch (\InvalidArgumentException $e) {
			return $this->fieldError($e->getMessage());
		}
		return new JSONResponse(['success' => true, 'item' => $this->serialize($row, $this->userLocale())]);
	}

	/**
	 * DELETE /api/admin/work-locations/{id} — hard delete only for
	 * unreferenced custom rows; otherwise 409 WORK_LOCATION_IN_USE and the
	 * lifecycle end is `active=false`.
	 */
	#[NoAdminRequired]
	public function delete(int $id): JSONResponse
	{
		if (!$this->suiteConfig->isEnabled('work_location_catalog_enabled')) {
			return $this->notFound();
		}
		try {
			$this->adminService->delete($id, $this->uid());
		} catch (WorkLocationConflictException $e) {
			return $this->conflictResponse($e->getReasonCode() ?? WorkLocationAdminService::ERR_IN_USE);
		}
		return new JSONResponse(['success' => true]);
	}

	/**
	 * GET /api/admin/work-location-policies?scopeType=&scopeId= — list
	 * policies for a scope (all policies when the filter is absent).
	 */
	#[NoAdminRequired]
	public function policies(): JSONResponse
	{
		if (!$this->suiteConfig->isEnabled('work_location_catalog_enabled')) {
			return $this->notFound();
		}
		$scopeType = trim((string)$this->request->getParam('scopeType', ''));
		$scopeId = trim((string)$this->request->getParam('scopeId', ''));
		if ($scopeType === '') {
			return $this->notFound();
		}
		if (!in_array($scopeType, WorkLocationDefaultPolicy::SCOPE_TYPES, true)) {
			return $this->fieldError('scopeType');
		}
		if ($scopeType === WorkLocationDefaultPolicy::SCOPE_ORG) {
			$scopeId = WorkLocationDefaultPolicy::ORG_SCOPE_ID;
		} elseif ($scopeId === '') {
			return $this->fieldError('scopeId');
		}
		$items = array_map(
			fn (WorkLocationDefaultPolicy $p) => $this->serializePolicy($p),
			$this->adminService->policiesForScope($scopeType, $scopeId),
		);
		return new JSONResponse(['success' => true, 'items' => array_values($items)]);
	}

	/**
	 * POST /api/admin/work-location-policies — create an effective-dated
	 * default policy. Overlapping ranges in the same scope → 409
	 * WORK_LOCATION_POLICY_OVERLAP.
	 */
	#[NoAdminRequired]
	public function createPolicy(): JSONResponse
	{
		if (!$this->suiteConfig->isEnabled('work_location_catalog_enabled')) {
			return $this->notFound();
		}
		try {
			$row = $this->adminService->createPolicy($this->request->getParams(), $this->uid());
		} catch (WorkLocationConflictException $e) {
			return $this->conflictResponse($e->getReasonCode() ?? WorkLocationAdminService::ERR_POLICY_OVERLAP);
		} catch (\InvalidArgumentException $e) {
			return $this->fieldError($e->getMessage());
		}
		return new JSONResponse(['success' => true, 'item' => $this->serializePolicy($row)], Http::STATUS_CREATED);
	}

	/**
	 * POST /api/admin/work-location-policies/{id}/end — set effective_to.
	 * Policies are ended rather than edited/deleted so historical resolution
	 * stays reconstructible.
	 */
	#[NoAdminRequired]
	public function endPolicy(int $id): JSONResponse
	{
		if (!$this->suiteConfig->isEnabled('work_location_catalog_enabled')) {
			return $this->notFound();
		}
		$effectiveTo = (string)$this->request->getParam('effectiveTo', '');
		try {
			$row = $this->adminService->endPolicy($id, $effectiveTo, $this->uid());
		} catch (WorkLocationConflictException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->fieldError($e->getMessage());
		}
		return new JSONResponse(['success' => true, 'item' => $this->serializePolicy($row)]);
	}

	/**
	 * GET /api/admin/work-location-migration — migration card data: readiness
	 * preflight plus the current suite flags relevant to the rollout.
	 */
	#[NoAdminRequired]
	public function migration(): JSONResponse
	{
		$preflight = $this->readiness->preflight();
		return new JSONResponse([
			'success' => true,
			'preflight' => $preflight,
			'flags' => [
				'work_location_catalog_enabled' => $this->suiteConfig->isEnabled('work_location_catalog_enabled'),
				'legacy_home_absence_create_enabled' => $this->suiteConfig->isEnabled('legacy_home_absence_create_enabled'),
			],
		]);
	}

	/**
	 * POST /api/admin/work-location-catalog/enable — explicit admin rollout
	 * activation. Requires `acknowledgeRollbackWarning=true`: once custom
	 * codes exist, rolling back to a pre-catalog build cannot write them.
	 */
	#[NoAdminRequired]
	public function enableCatalog(): JSONResponse
	{
		$ack = $this->request->getParam('acknowledgeRollbackWarning', false);
		if ($ack !== true && $ack !== '1' && $ack !== 'true') {
			return $this->fieldError('acknowledgeRollbackWarning');
		}
		$this->suiteConfig->updateFlags(['work_location_catalog_enabled' => true]);
		return new JSONResponse(['success' => true, 'work_location_catalog_enabled' => true]);
	}

	/**
	 * POST /api/admin/legacy-home-absence/disable — Phase C cutover. Refused
	 * while the readiness preflight reports pending legacy requests or
	 * unmigrated user defaults (409 WORK_LOCATION_READINESS_BLOCKED).
	 */
	#[NoAdminRequired]
	public function disableLegacyHomeAbsence(): JSONResponse
	{
		$preflight = $this->readiness->preflight();
		if (!$preflight['ready']) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Legacy home-office requests are still open or user defaults are not migrated yet.'),
				'error_code' => 'WORK_LOCATION_READINESS_BLOCKED',
				'fields' => [],
				'preflight' => $preflight,
			], Http::STATUS_CONFLICT);
		}
		$this->suiteConfig->updateFlags(['legacy_home_absence_create_enabled' => false]);
		return new JSONResponse(['success' => true, 'legacy_home_absence_create_enabled' => false, 'preflight' => $preflight]);
	}

	/**
	 * GET /api/admin/work-location-resolve?userId=&date= — support trace for
	 * the resolution chain (admin/support simulation, spec §9.5 step 8).
	 */
	#[NoAdminRequired]
	public function explain(): JSONResponse
	{
		if (!$this->suiteConfig->isEnabled('work_location_catalog_enabled')) {
			return $this->notFound();
		}
		$userId = trim((string)$this->request->getParam('userId', ''));
		$dateStr = trim((string)$this->request->getParam('date', (new \DateTime('today'))->format('Y-m-d')));
		if ($userId === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) !== 1) {
			return $this->fieldError($userId === '' ? 'userId' : 'date');
		}
		$explained = $this->resolver->explain($userId, new \DateTimeImmutable($dateStr));
		return new JSONResponse(['success' => true, 'resolution' => $explained]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function serialize(WorkLocationDefinition $def, ?string $locale): array
	{
		$code = (string)$def->getCode();
		return [
			'id' => $def->getId(),
			'code' => $code,
			'label' => $this->catalog->labelFor($code, $locale),
			'baseLabel' => (string)$def->getBaseLabel(),
			'labelI18n' => WorkLocationCatalog::decodeLabelMap($def->getLabelI18n()),
			'category' => $this->catalog->categoryOf($code),
			'color' => (string)$def->getColor(),
			'icon' => (string)$def->getIcon(),
			'active' => (bool)$def->getActive(),
			'builtIn' => (bool)$def->getBuiltIn(),
			'version' => (int)$def->getVersion(),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function serializePolicy(WorkLocationDefaultPolicy $p): array
	{
		return [
			'id' => $p->getId(),
			'scopeType' => (string)$p->getScopeType(),
			'scopeId' => (string)$p->getScopeId(),
			'locationCode' => (string)$p->getLocationCode(),
			'locationLabel' => $this->catalog->labelFor((string)$p->getLocationCode(), $this->userLocale()),
			'priority' => (int)$p->getPriority(),
			'effectiveFrom' => $p->getEffectiveFrom()?->format('Y-m-d'),
			'effectiveTo' => $p->getEffectiveTo()?->format('Y-m-d'),
			'revision' => (int)$p->getRevision(),
		];
	}

	private function conflictResponse(string $code): JSONResponse
	{
		if ($code === WorkLocationAdminService::ERR_NOT_FOUND) {
			return $this->notFound();
		}
		$messages = [
			WorkLocationAdminService::ERR_CODE_EXISTS => $this->l10n->t('A work location with this code already exists.'),
			WorkLocationAdminService::ERR_IN_USE => $this->l10n->t('This work location is still referenced and cannot be removed.'),
			WorkLocationAdminService::ERR_HAS_ACTIVE_DEFAULTS => $this->l10n->t('This work location still has active default policies. End them first.'),
			WorkLocationAdminService::ERR_POLICY_OVERLAP => $this->l10n->t('An effective-dated policy already covers part of this range for the same scope.'),
		];
		$message = $messages[$code] ?? $this->l10n->t('The change conflicts with the current state. Reload and try again.');
		return new JSONResponse([
			'success' => false,
			'error' => $message,
			'error_code' => $code,
			'fields' => [],
		], Http::STATUS_CONFLICT);
	}

	private function fieldError(string $field): JSONResponse
	{
		$message = $this->l10n->t('Invalid or missing field.');
		return new JSONResponse([
			'success' => false,
			'error' => $message,
			'error_code' => WorkLocationAdminService::ERR_INVALID,
			'fields' => [$field => $message],
		], Http::STATUS_BAD_REQUEST);
	}

	private function notFound(): JSONResponse
	{
		return ApiError::notFound();
	}

	private function uid(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('User not authenticated');
		}
		return $user->getUID();
	}

	private function userLocale(): ?string
	{
		try {
			return $this->l10nFactory->getUserLanguage();
		} catch (\Throwable) {
			return null;
		}
	}
}
