<?php

declare(strict_types=1);

/**
 * Admin page + JSON CRUD for custom absence types
 * ("weitere betriebliche Fehlzeiten").
 *
 * All methods are admin-gated by {@see AppAdminMiddleware} (instanceof list)
 * plus `PermissionService::isAdmin`, so delegated app admins work too.
 * `#[NoAdminRequired]` disables the built-in *system*-admin requirement; CSRF
 * stays enforced on every mutating route.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\CustomAbsenceType;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\CustomAbsenceTypeService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
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

class AbsenceTypeAdminController extends Controller
{
	use CSPTrait;
	use PageShellTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CustomAbsenceTypeService $customAbsenceTypeService,
		private readonly IUserSession $userSession,
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

	/** @return array{showSubstitutionLink: bool, showManagerLink: bool, showReportsLink: bool, showAdminNav: bool} */
	private function buildAdminNavFlags(): array
	{
		return [
			'showSubstitutionLink' => false,
			'showManagerLink' => true,
			'showReportsLink' => true,
			'showAdminNav' => true,
		];
	}

	/** @return array<string, mixed> */
	private function buildAdminShellParams(string $pageId, string $title, string $help): array
	{
		return $this->buildShellParams($pageId, $title, $help, $this->buildAdminNavFlags(), $this->l10n->t('Administration'));
	}

	/**
	 * Admin CRUD page for custom absence types.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function absenceTypes(): TemplateResponse
	{
		$this->registerFrontEndAssets('admin-absence-types', 'admin-absence-types');

		$locale = (string)$this->l10nFactory->getUserLanguage();
		$response = new TemplateResponse('arbeitszeitcheck', 'admin-absence-types', array_merge(
			$this->buildAdminShellParams(
				'admin-absence-types',
				$this->l10n->t('Custom absence types'),
				$this->l10n->t('Define additional absence types for your organisation'),
			),
			[
				'absenceTypes' => $this->customAbsenceTypeService->listWithUsage($locale),
				'allowedColors' => CustomAbsenceType::COLORS,
				'allowedIcons' => CustomAbsenceType::ICONS,
				// Canonical shipped locales — the same set the app and the
				// mobile companion translate for (custom label overrides are
				// optional per locale; the base label is the fallback).
				'localeOptions' => \OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionLanguageCatalog::optionsForUi(),
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			],
		));
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * List all custom types (incl. inactive) with usage counts.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listAbsenceTypes(): JSONResponse
	{
		return new JSONResponse([
			'success' => true,
			'types' => $this->customAbsenceTypeService->listWithUsage((string)$this->l10nFactory->getUserLanguage()),
		]);
	}

	/**
	 * Get one custom type by id — uniform 404, no existence oracle.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getAbsenceType(int $id): JSONResponse
	{
		try {
			return new JSONResponse([
				'success' => true,
				'type' => $this->customAbsenceTypeService->get($id, (string)$this->l10nFactory->getUserLanguage()),
			]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return $this->notFound();
		}
	}

	/**
	 * Create a custom absence type.
	 */
	#[NoAdminRequired]
	public function createAbsenceType(): JSONResponse
	{
		$result = $this->customAbsenceTypeService->create($this->request->getParams(), $this->actorUid());
		return $this->serviceResult($result, 'type');
	}

	/**
	 * Update a custom absence type (label, translations, semantics, active flag).
	 */
	#[NoAdminRequired]
	public function updateAbsenceType(int $id): JSONResponse
	{
		$result = $this->customAbsenceTypeService->update($id, $this->request->getParams(), $this->actorUid());
		return $this->serviceResult($result, 'type');
	}

	/**
	 * Delete when unused; soft-delete (deactivate) when absences reference it.
	 */
	#[NoAdminRequired]
	public function deleteAbsenceType(int $id): JSONResponse
	{
		$result = $this->customAbsenceTypeService->deleteOrDeactivate($id, $this->actorUid());
		if ($result['code'] === 404) {
			return $this->notFound();
		}
		return new JSONResponse([
			'success' => true,
			'mode' => $result['data']['mode'],
			'absenceCount' => $result['data']['usage'],
		]);
	}

	/**
	 * @param array{data: ?array<string,mixed>, errors: array<string,string>, code: int} $result
	 */
	private function serviceResult(array $result, string $key): JSONResponse
	{
		if ($result['code'] === 404) {
			return $this->notFound();
		}
		if ($result['errors'] !== []) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Validation failed'),
				'fields' => $result['errors'],
			], $result['code']);
		}
		return new JSONResponse([
			'success' => true,
			$key => $result['data'],
		], $result['code']);
	}

	private function notFound(): JSONResponse
	{
		// Canonical payload shared by "missing" and "not accessible" (API rule §1).
		return new JSONResponse([
			'success' => false,
			'error' => $this->l10n->t('Absence type not found'),
		], Http::STATUS_NOT_FOUND);
	}

	private function actorUid(): string
	{
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}
}
