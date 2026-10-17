<?php

declare(strict_types=1);

/**
 * Employee work-location API (Workforce Policy Suite, Phase 3).
 *
 * Session-scoped, uid always comes from the session — employees can only
 * ever read/write their own day override (no uid parameter exists). The
 * whole surface returns the uniform 404 when `work_location_catalog_enabled`
 * is off: a disabled feature does not exist, and its absence must not leak
 * through a different error code.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\WorkLocationDefinition;
use OCA\ArbeitszeitCheck\Exception\WorkLocationDayConflictException;
use OCA\ArbeitszeitCheck\Service\UserWorkLocationService;
use OCA\ArbeitszeitCheck\Service\WorkLocationCatalog;
use OCA\ArbeitszeitCheck\Service\WorkLocationDayOverrideService;
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

class WorkLocationController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly WorkforceSuiteConfig $suiteConfig,
		private readonly WorkLocationCatalog $catalog,
		private readonly WorkLocationDayOverrideService $dayOverrideService,
		private readonly UserWorkLocationService $userWorkLocationService,
		private readonly IFactory $l10nFactory,
		private readonly IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * GET /api/work-locations — active catalog plus the caller's effective
	 * default for today (day override or resolved policy chain).
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		if (!$this->suiteConfig->isEnabled('work_location_catalog_enabled')) {
			return $this->notFound();
		}
		$locale = $this->userLocale();
		$items = [];
		foreach ($this->catalog->active() as $code => $def) {
			$items[] = $this->serialize($def, $locale);
		}

		$effective = $this->userWorkLocationService->effectiveToday($this->uid());
		$override = $this->dayOverrideService->get($this->uid(), (new \DateTime('today'))->format('Y-m-d'));
		$default = [
			'code' => $effective['code'],
			'label' => $this->catalog->labelFor($effective['code'], $locale),
			'source' => $effective['source'],
			// Present only when a day-override row backs the effective value —
			// this is the CAS token the client must send back on update.
			'revision' => $effective['source'] === 'day_override' && $override !== null ? $override['revision'] : null,
		];

		return new JSONResponse(['success' => true, 'items' => array_values($items), 'default' => $default]);
	}

	/**
	 * PUT /api/work-location/day/{date} — set/replace the day override.
	 * Body: {code: string|null, revision: int|string|null}. CAS is enforced
	 * in the service; conflict → 409 REVISION_CONFLICT.
	 */
	#[NoAdminRequired]
	public function setDayOverride(string $date): JSONResponse
	{
		if (!$this->suiteConfig->isEnabled('work_location_catalog_enabled')) {
			return $this->notFound();
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return $this->notFound();
		}
		$params = $this->request->getParams();
		$uid = $this->uid();
		try {
			$result = $this->dayOverrideService->setOverride(
				$uid,
				$date,
				$params['code'] ?? null,
				$params['revision'] ?? null,
				WorkLocationDayOverrideService::SOURCE_WEB,
				$uid,
			);
		} catch (WorkLocationDayConflictException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('The work location for this day changed in the meantime. Reload and try again.'),
				'error_code' => $e->getReasonCode(),
				'fields' => [],
			], Http::STATUS_CONFLICT);
		} catch (\InvalidArgumentException $e) {
			return $this->invalidResponse($params['code'] ?? null, $e);
		}

		$locale = $this->userLocale();
		return new JSONResponse([
			'success' => true,
			'date' => $date,
			'code' => $result['locationCode'],
			'label' => $result['locationCode'] !== null ? $this->catalog->labelFor($result['locationCode'], $locale) : null,
			'revision' => $result['revision'],
		]);
	}

	/**
	 * DELETE /api/work-location/day/{date} — clears the override; `revision`
	 * param is required and CAS-checked like PUT.
	 */
	#[NoAdminRequired]
	public function clearDayOverride(string $date): JSONResponse
	{
		if (!$this->suiteConfig->isEnabled('work_location_catalog_enabled')) {
			return $this->notFound();
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return $this->notFound();
		}
		$params = $this->request->getParams();
		$uid = $this->uid();
		try {
			$this->dayOverrideService->setOverride(
				$uid,
				$date,
				null,
				$params['revision'] ?? null,
				WorkLocationDayOverrideService::SOURCE_WEB,
				$uid,
			);
		} catch (WorkLocationDayConflictException) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('The work location for this day changed in the meantime. Reload and try again.'),
				'error_code' => 'REVISION_CONFLICT',
				'fields' => [],
			], Http::STATUS_CONFLICT);
		} catch (\InvalidArgumentException $e) {
			return $this->invalidResponse(null, $e);
		}
		return new JSONResponse(['success' => true, 'date' => $date, 'code' => null, 'revision' => 0]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function serialize(WorkLocationDefinition $def, ?string $locale): array
	{
		return [
			'code' => (string)$def->getCode(),
			'label' => $this->catalog->labelFor((string)$def->getCode(), $locale),
			'category' => $this->catalog->categoryOf((string)$def->getCode()),
			'color' => (string)$def->getColor(),
			'icon' => (string)$def->getIcon(),
			'active' => (bool)$def->getActive(),
			'builtIn' => (bool)$def->getBuiltIn(),
		];
	}

	private function invalidResponse(mixed $rawCode, \InvalidArgumentException $e): JSONResponse
	{
		if ($e->getMessage() === WorkLocationDayOverrideService::ERROR_DATE_OUT_OF_WINDOW) {
			$message = $this->l10n->t('The day override can only be set for today or up to %d days ahead.', [WorkLocationDayOverrideService::FUTURE_WINDOW_DAYS]);
			return new JSONResponse([
				'success' => false,
				'error' => $message,
				'error_code' => WorkLocationDayOverrideService::ERROR_DATE_OUT_OF_WINDOW,
				'fields' => ['date' => $message],
			], Http::STATUS_BAD_REQUEST);
		}
		if ($this->catalog->isInactiveCode($rawCode)) {
			$message = $this->l10n->t('This work location is no longer active.');
			return new JSONResponse([
				'success' => false,
				'error' => $message,
				'error_code' => 'WORK_LOCATION_INACTIVE',
				'fields' => ['workLocation' => $message],
			], Http::STATUS_BAD_REQUEST);
		}
		$message = $this->l10n->t('Invalid work location.');
		return new JSONResponse([
			'success' => false,
			'error' => $message,
			'error_code' => 'WORK_LOCATION_INVALID',
			'fields' => ['workLocation' => $message],
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
