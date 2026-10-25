<?php

declare(strict_types=1);

/**
 * App-admin special-entitlement API.
 *
 * Gated by {@see AppAdminMiddleware} and the feature flag. This is the only
 * HTTP surface that may expose confidential labels or resolution traces.
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Exception\SpecialEntitlementException;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\SpecialEntitlementAdminService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCA\ArbeitszeitCheck\Support\ApiError;
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
use OCP\L10N\IFactory;

final class SpecialEntitlementAdminController extends Controller
{
	use CSPTrait;
	use PageShellTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly SpecialEntitlementAdminService $service,
		private readonly WorkforceSuiteConfig $suiteConfig,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly IL10N $l10n,
		private readonly PermissionService $permissionService,
		private readonly LocaleFormatService $localeFormat,
		CSPService $cspService,
	) {
		parent::__construct($appName, $request);
		$this->setCspService($cspService);
	}

	/**
	 * Admin page: Sonderfreistellungen — entitlement definitions, policies,
	 * protected groups, and impact preview in one flow (spec §7.2/ADR-005).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function page(): TemplateResponse
	{
		$this->registerFrontEndAssets('admin-special-entitlements', 'admin-special-entitlements', [], ['common/admin-user-picker']);

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-special-entitlements', array_merge(
			$this->buildShellParams(
				'admin-special-entitlements',
				$this->l10n->t('Special leave entitlements'),
				$this->l10n->t('Define additional leave entitlements and their quotas'),
				[
					'showSubstitutionLink' => false,
					'showManagerLink' => true,
					'showReportsLink' => true,
					'showAdminNav' => true,
				],
				$this->l10n->t('Administration'),
			),
			[
				'specialEntitlementsEnabled' => $this->suiteConfig->isEnabled('special_entitlements_enabled')
					&& $this->suiteConfig->isEnabled('policy_cohorts_enabled'),
				'localeOptions' => \OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionLanguageCatalog::optionsForUi(),
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			],
		));
		return $this->configureCSP($response, 'admin');
	}

	#[NoAdminRequired]
	public function types(): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true, 'items' => $this->service->listTypes()]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function createType(): JSONResponse
	{
		try {
			$type = $this->service->createType($this->request->getParams(), $this->uid());
			return new JSONResponse(['success' => true, 'item' => $type], Http::STATUS_CREATED);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function showType(int $typeId): JSONResponse
	{
		try {
			$type = $this->service->type($typeId);
			return new JSONResponse([
				'success' => true,
				'item' => $this->service->adminTypeSummary($type),
				'policies' => $this->service->listPolicies($typeId),
			]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function updateType(int $typeId): JSONResponse
	{
		try {
			return new JSONResponse([
				'success' => true,
				'item' => $this->service->updateType($typeId, $this->request->getParams(), $this->uid()),
			]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function setTypeActive(int $typeId): JSONResponse
	{
		$active = $this->request->getParam('active', null);
		if (!is_bool($active) && !in_array($active, ['0', '1', 'true', 'false', 'on', 'off', 0, 1], true)) {
			return $this->serviceError(new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid active flag.', 400, ['active' => 'invalid']));
		}
		$active = $active === true || $active === 1 || $active === '1' || $active === 'true' || $active === 'on';
		$version = $this->request->getParam('version', $this->request->getParam('revision', null));
		if (!is_int($version) && !(is_string($version) && preg_match('/^\d+$/D', $version) === 1)) {
			$version = null;
		}
		try {
			return new JSONResponse([
				'success' => true,
				'item' => $this->service->setTypeActive($typeId, $active, $version === null ? 0 : (int)$version, $this->uid()),
			]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function policies(int $typeId): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true, 'items' => $this->service->listPolicies($typeId)]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function createPolicy(int $typeId): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$params['entitlementTypeId'] = $typeId;
			return new JSONResponse([
				'success' => true,
				'item' => $this->service->createPolicy($params, $this->uid()),
			], Http::STATUS_CREATED);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	/** Flat alias of createPolicy — spec §9.4 posts entitlementTypeId in the body. */
	#[NoAdminRequired]
	public function createPolicyFlat(): JSONResponse
	{
		try {
			return new JSONResponse([
				'success' => true,
				'item' => $this->service->createPolicy($this->request->getParams(), $this->uid()),
			], Http::STATUS_CREATED);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function updatePolicy(int $policyId): JSONResponse
	{
		try {
			return new JSONResponse([
				'success' => true,
				'item' => $this->service->updatePolicy($policyId, $this->request->getParams(), $this->uid()),
			]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	/** Deterministic resolution trace; admin-only because it is a support surface. */
	#[NoAdminRequired]
	public function trace(int $typeId): JSONResponse
	{
		try {
			$userId = trim((string)$this->request->getParam('userId', ''));
			$asOf = trim((string)$this->request->getParam('asOf', (new \DateTimeImmutable('today'))->format('Y-m-d')));
			if ($userId === '') {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'A user is required.', 400, ['userId' => 'required']);
			}
			return new JSONResponse(['success' => true, 'trace' => $this->service->resolveTrace($typeId, $userId, $asOf)]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	/**
	 * Server-side impact preview for a hypothetical policy: recipient count,
	 * before/after samples, warnings. Never persists.
	 */
	#[NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\UserRateLimit(limit: 30, period: 60)]
	public function previewPolicy(): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true] + $this->service->previewPolicy($this->request->getParams()));
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	/* ---- Protected policy cohorts (admin-only, spec §7.4) ---- */

	#[NoAdminRequired]
	public function cohorts(): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true, 'items' => $this->service->listCohorts()]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function createCohort(): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true, 'item' => $this->service->createCohort($this->request->getParams(), $this->uid())], Http::STATUS_CREATED);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function updateCohort(int $cohortId): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true, 'item' => $this->service->updateCohort($cohortId, $this->request->getParams(), $this->uid())]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function cohortMembers(int $cohortId): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true, 'items' => $this->service->listCohortMembers($cohortId)]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function addCohortMember(int $cohortId): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true, 'item' => $this->service->addCohortMember($cohortId, $this->request->getParams(), $this->uid())], Http::STATUS_CREATED);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function endCohortMember(int $membershipId): JSONResponse
	{
		try {
			return new JSONResponse(['success' => true, 'item' => $this->service->endCohortMember($membershipId, $this->request->getParams(), $this->uid())]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function accounts(): JSONResponse
	{
		try {
			$userId = trim((string)$this->request->getParam('userId', ''));
			$asOf = trim((string)$this->request->getParam('asOf', (new \DateTimeImmutable('today'))->format('Y-m-d')));
			if ($userId === '') {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'A user is required.', 400, ['userId' => 'required']);
			}
			return new JSONResponse([
				'success' => true,
				'userId' => $userId,
				'asOf' => $asOf,
				'items' => $this->service->accountsForUser($userId, $asOf),
			]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function period(int $periodId): JSONResponse
	{
		try {
			$limit = $this->positiveBoundedInt($this->request->getParam('limit', 50), 50, 200, 'limit');
			$offset = $this->positiveBoundedInt($this->request->getParam('offset', 0), 0, 1000000, 'offset', true);
			return new JSONResponse(['success' => true] + $this->service->periodDetail($periodId, $limit, $offset));
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function closePeriod(int $periodId): JSONResponse
	{
		try {
			$revision = $this->positiveBoundedInt($this->request->getParam('revision', $this->request->getParam('version', null)), 0, 1000000, 'revision', true);
			return new JSONResponse([
				'success' => true,
				'item' => $this->service->closePeriod($periodId, $revision, $this->uid()),
			]);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	#[NoAdminRequired]
	public function postCorrection(int $periodId): JSONResponse
	{
		try {
			return new JSONResponse([
				'success' => true,
				'item' => $this->service->postCorrection($periodId, $this->request->getParams(), $this->uid()),
			], Http::STATUS_CREATED);
		} catch (SpecialEntitlementException $e) {
			return $this->serviceError($e);
		}
	}

	private function positiveBoundedInt(mixed $raw, int $default, int $max, string $field, bool $allowZero = false): int
	{
		if (is_array($raw)) {
			$raw = reset($raw);
		}
		if ($raw === null || $raw === '') {
			return $default;
		}
		$value = trim((string)$raw);
		if (preg_match('/^\d+$/D', $value) !== 1 || (!$allowZero && (int)$value < 1) || (int)$value > $max) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid pagination parameter.', 400, [$field => 'invalid']);
		}
		return (int)$value;
	}

	private function serviceError(SpecialEntitlementException $e): JSONResponse
	{
		if ($e->getReasonCode() === 'NOT_FOUND') {
			return ApiError::notFound();
		}
		return new JSONResponse([
			'success' => false,
			'error' => $this->l10n->t($e->getMessage()),
			'message' => $this->l10n->t($e->getMessage()),
			'error_code' => $e->getReasonCode(),
			'fields' => $e->fields(),
		], $e->httpStatus());
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
