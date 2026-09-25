<?php

declare(strict_types=1);

/**
 * Absence controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine;
use OCA\ArbeitszeitCheck\Service\VacationProrationService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCA\ArbeitszeitCheck\Service\VacationHoursDebitService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IURLGenerator;
use OCP\IL10N;

/**
 * AbsenceController
 */
class AbsenceController extends Controller
{
	use CSPTrait;
	use NavigationFlagsTrait;
	use PageShellTrait;

	private AbsenceService $absenceService;
	private AbsenceMapper $absenceMapper;
	private PermissionService $permissionService;
	private TeamResolverService $teamResolver;
	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private IUserManager $userManager;
	private IL10N $l10n;
	private IConfig $config;
	private MonthClosureService $monthClosureService;
	private VacationEntitlementEngine $vacationEntitlementEngine;
	private VacationProrationService $vacationProrationService;
	private LocaleFormatService $localeFormat;
	private NavigationFlagsService $navigationFlags;

	public function __construct(
		string $appName,
		IRequest $request,
		AbsenceService $absenceService,
		AbsenceMapper $absenceMapper,
		PermissionService $permissionService,
		TeamResolverService $teamResolver,
		IUserSession $userSession,
		IURLGenerator $urlGenerator,
		IUserManager $userManager,
		CSPService $cspService,
		IL10N $l10n,
		IConfig $config,
		MonthClosureService $monthClosureService,
		VacationEntitlementEngine $vacationEntitlementEngine,
		VacationProrationService $vacationProrationService,
		LocaleFormatService $localeFormat,
		NavigationFlagsService $navigationFlags
	) {
		parent::__construct($appName, $request);
		$this->absenceService = $absenceService;
		$this->absenceMapper = $absenceMapper;
		$this->permissionService = $permissionService;
		$this->teamResolver = $teamResolver;
		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->userManager = $userManager;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->monthClosureService = $monthClosureService;
		$this->vacationEntitlementEngine = $vacationEntitlementEngine;
		$this->vacationProrationService = $vacationProrationService;
		$this->localeFormat = $localeFormat;
		$this->navigationFlags = $navigationFlags;
		$this->setCspService($cspService);
	}

	private function vacationUnitService(): VacationUnitService
	{
		return new VacationUnitService($this->config);
	}

	private function registerAbsenceFormAssets(): void
	{
		$this->registerFrontEndAssets(
			'arbeitszeitcheck-main',
			'absences',
			['absences'],
			['common/datepicker', 'common/validation', 'entitlement-explainer'],
		);
	}

	/**
	 * Parse a user-supplied date string in either German (dd.mm.yyyy) or strict ISO
	 * (yyyy-mm-dd) form and return a `\DateTime` at 00:00:00.
	 *
	 * The form is rendered with European date inputs (dd.mm.yyyy). When JavaScript
	 * runs, those inputs are converted to ISO before submission. When it does NOT
	 * run (no-JS users, screen readers in some browsers, broken bundles, etc.) the
	 * raw German format is POSTed instead, and `new \DateTime("12.05.2026")` throws
	 * a hard `Exception` ("Failed to parse time string"). That generic error then
	 * surfaces as "An unexpected error occurred." even though the input is valid.
	 *
	 * Centralising the parser here:
	 *   1. Guarantees both representations are accepted server-side so the form
	 *      always works without JS (WCAG 2.1 + progressive enhancement).
	 *   2. Lets us reject ambiguous/relative strings ("now", "yesterday", or
	 *      ISO-with-time) early instead of letting `\DateTime` quietly accept
	 *      them.
	 *
	 * @throws \InvalidArgumentException if the input is not a valid calendar date
	 *         in one of the two accepted formats.
	 */
	private function parseFormDate(string $raw): \DateTime
	{
		$raw = trim($raw);
		if ($raw === '') {
			throw new \InvalidArgumentException('empty');
		}
		if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
			$d = (int)$m[1];
			$mo = (int)$m[2];
			$y = (int)$m[3];
			if (!checkdate($mo, $d, $y) || $y < 1900 || $y > 2999) {
				throw new \InvalidArgumentException('invalid_calendar_date');
			}
			$dt = \DateTime::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $y, $mo, $d));
			if ($dt === false) {
				throw new \InvalidArgumentException('parse_failed');
			}
			return $dt;
		}
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
			$y = (int)$m[1];
			$mo = (int)$m[2];
			$d = (int)$m[3];
			if (!checkdate($mo, $d, $y) || $y < 1900 || $y > 2999) {
				throw new \InvalidArgumentException('invalid_calendar_date');
			}
			$dt = \DateTime::createFromFormat('!Y-m-d', $raw);
			if ($dt === false) {
				throw new \InvalidArgumentException('parse_failed');
			}
			return $dt;
		}
		throw new \InvalidArgumentException('unrecognised_format');
	}

	/**
	 * Render the user-friendly error/redirect for invalid dates submitted via the form.
	 */
	private function invalidDatesResponse(): JSONResponse|RedirectResponse
	{
		$msg = $this->l10n->t('Please enter dates in the format dd.mm.yyyy.');
		if (!$this->wantsJson()) {
			$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.create') . '?error=' . rawurlencode($msg);
			return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
		}
		return new JSONResponse([
			'success' => false,
			'error' => $msg,
		], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @param array<string, mixed> $pageData
	 * @return array<string, mixed>
	 */
	private function buildAbsencePageTemplateParams(string $mode, array $pageData): array
	{
		$userId = $this->getUserId();
		$navFlags = $this->getNavigationFlags($userId);
		if (\in_array($mode, ['create', 'edit', 'view'], true)) {
			$this->registerAbsenceFormAssets();
		}

		$stats = is_array($pageData['stats'] ?? null) ? $pageData['stats'] : [];
		$unitService = $this->vacationUnitService();
		if (!isset($stats['vacation_unit'])) {
			$stats['vacation_unit'] = $unitService->getUnit();
		}
		if (!isset($stats['vacation_hours_per_day'])) {
			$stats['vacation_hours_per_day'] = $unitService->getHoursPerDay();
		}
		if (!isset($stats['vacation_weekday_nets'])) {
			try {
				/** @var VacationHoursDebitService $debit */
				$debit = \OCP\Server::get(VacationHoursDebitService::class);
				$snap = $debit->snapshotForUser($userId);
				$stats['vacation_debit_basis'] = $snap['basis'];
				$stats['vacation_weekday_nets'] = $snap['weekday_nets'];
				$stats['vacation_one_day_hours'] = $snap['one_day_hours'];
				$stats['vacation_average_daily'] = $snap['average_daily'];
			} catch (\Throwable) {
				$stats['vacation_debit_basis'] = 'org_hours_per_day';
				$stats['vacation_weekday_nets'] = null;
				$stats['vacation_one_day_hours'] = $unitService->getHoursPerDay();
				$stats['vacation_average_daily'] = $unitService->getHoursPerDay();
			}
		}
		$pageData['stats'] = $stats;

		return \array_merge($this->buildAbsencesShellParams($mode, $navFlags), $pageData);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildAbsenceListErrorParams(string $error): array
	{
		return $this->buildAbsencePageTemplateParams('list', [
			'mode' => 'list',
			'absence' => null,
			'absences' => [],
			'hasColleagues' => false,
			'requireSubstituteTypes' => [],
			'stats' => [],
			'currentUserId' => $this->getUserId(),
			'colleagues' => [],
			'usersUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.users'),
			'employeeHasAssignableManager' => true,
			'useAppTeams' => $this->teamResolver->useAppTeams(),
			'error' => $error,
		]);
	}

	/**
	 * Absence types for which a substitute must be designated (from admin config).
	 *
	 * @return string[]
	 */
	private function getRequireSubstituteTypes(): array
	{
		$json = $this->config->getAppValue('arbeitszeitcheck', 'require_substitute_types', '[]');
		$arr = json_decode($json, true);
		return is_array($arr) ? $arr : [];
	}

	/**
	 * Machine-readable reason for clients (mobile fail-closed, forms).
	 */
	private function getErrorCode(\Throwable $e): ?string
	{
		if ($e instanceof BusinessRuleException) {
			$code = $e->getReasonCode();
			return ($code !== null && $code !== '') ? $code : null;
		}
		if (preg_match('/\[([A-Z0-9_]{3,64})\]\s*$/', (string)$e->getMessage(), $m) === 1) {
			return $m[1];
		}
		return null;
	}
	private function getSafeErrorMessage(\Throwable $e): string
	{
		$generic = $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
		$forwardUserMessage = (get_class($e) === \Exception::class) || ($e instanceof BusinessRuleException);
		if (!$forwardUserMessage) {
			return $generic;
		}
		$msg = trim((string)$e->getMessage());
		// Strip trailing machine codes like [ABSENCE_HOURS_CLIENT_REQUIRED] from user text.
		$msg = trim((string)preg_replace('/\s*\[[A-Z0-9_]{3,64}\]\s*$/', '', $msg));
		if ($msg === '' || strlen($msg) > 500) {
			return $generic;
		}
		$lower = strtolower($msg);
		$blocked = [
			'sqlstate[',
			'syntax error',
			'pdoexception',
			'doctrine\\',
			'stack trace',
			' in /var/',
			' in /home/',
			' in /usr/',
			'/lib/',
			'/vendor/',
			'oc\\',
			'oca\\',
			'ocp\\',
			'fatal error',
			'argument #',
			'must be of type',
			'must be an instance of',
			'has not been initialized',
		];
		foreach ($blocked as $needle) {
			if (str_contains($lower, $needle)) {
				return $generic;
			}
		}
		return $msg;
	}

	/**
	 * Whether the request expects a JSON response (AJAX/API).
	 *
	 * @return bool
	 */
	private function wantsJson(): bool
	{
		$accept = $this->request->getHeader('Accept');
		$contentType = $this->request->getHeader('Content-Type');
		return str_contains($accept, 'application/json') || str_contains($contentType, 'application/json');
	}

	/**
	 * Colleague list for substitute dropdown (same data as users() API).
	 * Built on page load so the form works even if the API request fails.
	 *
	 * @return list<array{userId: string, displayName: string, display_name: string}>
	 */
	private function getColleaguesForSubstitute(string $userId): array
	{
		$colleagueIds = $this->teamResolver->getColleagueIds($userId);
		\OCP\Log\logger('arbeitszeitcheck')->debug(
			'[Vertretung] getColleaguesForSubstitute userId=' . $userId . ' colleagueIds=' . count($colleagueIds) . ' ' . json_encode($colleagueIds),
			['app' => 'arbeitszeitcheck']
		);
		$list = [];
		foreach ($colleagueIds as $uid) {
			$user = $this->userManager->get($uid);
			if ($user !== null && $user->isEnabled()) {
				$displayName = $user->getDisplayName();
				$list[] = [
					'userId' => $user->getUID(),
					'displayName' => $displayName ?? $user->getUID(),
					'display_name' => $displayName ?? $user->getUID(),
				];
			} else {
				\OCP\Log\logger('arbeitszeitcheck')->debug(
					'[Vertretung] getColleaguesForSubstitute uid=' . $uid . ' skipped (user=null or disabled)',
					['app' => 'arbeitszeitcheck']
				);
			}
		}
		\OCP\Log\logger('arbeitszeitcheck')->debug(
			'[Vertretung] getColleaguesForSubstitute returning ' . count($list) . ' colleagues',
			['app' => 'arbeitszeitcheck']
		);
		return $list;
	}

	/**
	 * Get current user ID
	 *
	 * @return string
	 */
	private function getUserId(): string
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			throw new \Exception('User not authenticated');
		}
		return $user->getUID();
	}

	/**
	 * API: Create absence (delegates to store)
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function apiStore(): JSONResponse
	{
		return $this->store();
	}

	/**
	 * API: Get absence by ID (JSON)
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function apiShow(int $id): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$absence = $this->absenceService->getAbsence($id, $userId);

			if (!$absence) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Absence not found')
				], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * API: Update absence (delegates to update)
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function apiUpdate(int $id): JSONResponse
	{
		$params = $this->request->getParams();
		$sub = $params['substitute_user_id'] ?? null;
		$dayFraction = array_key_exists('day_fraction', $params) ? $params['day_fraction'] : null;
		$durationHours = array_key_exists('duration_hours', $params) ? $params['duration_hours'] : null;
		return $this->update(
			$id,
			isset($params['start_date']) ? (is_array($params['start_date']) ? (string)reset($params['start_date']) : (string)$params['start_date']) : null,
			isset($params['end_date']) ? (is_array($params['end_date']) ? (string)reset($params['end_date']) : (string)$params['end_date']) : null,
			array_key_exists('reason', $params)
				? (is_array($params['reason']) ? (string)reset($params['reason']) : (string)$params['reason'])
				: null,
			$sub !== null ? (string)$sub : null,
			$dayFraction !== null ? (is_array($dayFraction) ? (string)reset($dayFraction) : (string)$dayFraction) : null,
			$durationHours !== null ? (is_array($durationHours) ? (string)reset($durationHours) : (string)$durationHours) : null
		);
	}

	/**
	 * API: Delete absence (delegates to delete)
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function apiDelete(int $id): JSONResponse
	{
		return $this->delete($id);
	}

	/**
	 * Cancel absence (set status to cancelled without deleting record).
	 *
	 * This is intended for employees to cancel their own future absences.
	 * The underlying service enforces that:
	 * - only the owner can cancel their own absences, and
	 * - the absence has not started yet.
	 *
	 * When called via form POST (e.g. from absence details page), redirects to
	 * absences list. When called via API with Accept: application/json, returns JSON.
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse|RedirectResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function cancel(int $id): JSONResponse|RedirectResponse
	{
		try {
			$userId = $this->getUserId();
			try {
				$absRow = $this->absenceMapper->find($id);
				if ($absRow->getUserId() === $userId) {
					$this->monthClosureService->assertDateRangeMutable(
						$userId,
						$absRow->getStartDate(),
						$absRow->getEndDate()
					);
				}
			} catch (MonthFinalizedException $e) {
				$msg = $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.');
				if (!$this->wantsJson()) {
					$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.absences') . '?error=' . rawurlencode($msg);
					return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
				}
				return new JSONResponse(['success' => false, 'error' => $msg], Http::STATUS_CONFLICT);
			} catch (DoesNotExistException $e) {
				// cancelAbsence will handle not found
			}

			$absence = $this->absenceService->cancelAbsence($id, $userId);

			if (!$this->wantsJson()) {
				$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.absences') . '?cancelled=1';
				return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
			}
			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (\Throwable $e) {
			$msg = $this->getSafeErrorMessage($e);
			if (!$this->wantsJson()) {
				$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.absences') . '?error=' . rawurlencode($msg);
				return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
			}
			return new JSONResponse([
				'success' => false,
				'error' => $msg
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * API: Shorten absence (early return). Accepts JSON or form-encoded end_date.
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function shorten(int $id): JSONResponse
	{
		$params = $this->request->getParams();
		$endDate = isset($params['end_date']) ? trim((string)$params['end_date']) : '';

		if ($endDate === '') {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('New end date is required.')
			], Http::STATUS_BAD_REQUEST);
		}

		try {
			$userId = $this->getUserId();
			$absence = $this->absenceService->shortenAbsence($id, $userId, $endDate);

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (MonthFinalizedException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.')
			], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->getSafeErrorMessage($e)
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Form POST: Shorten absence (early return). Redirects back to show page.
	 *
	 * @param int $id Absence ID
	 * @return RedirectResponse|TemplateResponse
	 */
	#[NoAdminRequired]
	public function shortenForm(int $id)
	{
		$endDate = trim((string)($this->request->getParam('end_date') ?? ''));

		if ($endDate === '') {
			$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.show', ['id' => $id]);
			return new RedirectResponse($url . '?shorten_error=' . rawurlencode($this->l10n->t('New end date is required.')), Http::STATUS_SEE_OTHER);
		}

		try {
			$userId = $this->getUserId();
			$this->absenceService->shortenAbsence($id, $userId, $endDate);
			$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.show', ['id' => $id]);
			return new RedirectResponse($url . '?shortened=1', Http::STATUS_SEE_OTHER);
		} catch (MonthFinalizedException $e) {
			$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.show', ['id' => $id]);
			$msg = $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.');
			return new RedirectResponse($url . '?shorten_error=' . rawurlencode($msg), Http::STATUS_SEE_OTHER);
		} catch (\Throwable $e) {
			$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.show', ['id' => $id]);
			return new RedirectResponse($url . '?shorten_error=' . rawurlencode($this->getSafeErrorMessage($e)), Http::STATUS_SEE_OTHER);
		}
	}

	/**
	 * Legacy API: Get absences (alias for index)
	 *
	 * Legacy endpoint for backward compatibility. Delegates to the index() method.
	 *
	 *
	 * @param string|null $status Filter by status
	 * @param string|null $type Filter by type
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function index_api(?string $status = null, ?string $type = null, ?int $limit = Constants::DEFAULT_LIST_LIMIT, ?int $offset = 0, ?string $start_date = null, ?string $end_date = null): JSONResponse
	{
		return $this->index($status, $type, $limit, $offset, $start_date, $end_date);
	}

	/**
	 * Legacy API (CamelCase alias): Nextcloud routes may call `indexApi()` when the route is defined as `index_api`.
	 */
	#[NoAdminRequired]
	public function indexApi(?string $status = null, ?string $type = null, ?int $limit = Constants::DEFAULT_LIST_LIMIT, ?int $offset = 0, ?string $start_date = null, ?string $end_date = null): JSONResponse
	{
		return $this->index_api($status, $type, $limit, $offset, $start_date, $end_date);
	}

	/**
	 * Get absences endpoint
	 *
	 *
	 * @param string|null $status Filter by status
	 * @param string|null $type Filter by type
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function index(?string $status = null, ?string $type = null, ?int $limit = Constants::DEFAULT_LIST_LIMIT, ?int $offset = 0, ?string $start_date = null, ?string $end_date = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$limit = $limit !== null ? min(max(1, (int)$limit), Constants::MAX_LIST_LIMIT) : Constants::DEFAULT_LIST_LIMIT;
			$offset = $offset !== null ? max(0, (int)$offset) : 0;
			$filters = [];

			if ($status) {
				$filters['status'] = $status;
			}
			if ($type) {
				$filters['type'] = $type;
			}
			if (($start_date !== null && $start_date !== '') || ($end_date !== null && $end_date !== '')) {
				if ($start_date === null || $start_date === '' || $end_date === null || $end_date === '') {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Start date and end date are required')
					], Http::STATUS_BAD_REQUEST);
				}
				try {
					$rangeStart = new \DateTime($start_date);
					$rangeEnd = new \DateTime($end_date);
					$rangeStart->setTime(0, 0, 0);
					$rangeEnd->setTime(23, 59, 59);
				} catch (\Throwable $e) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Invalid date format')
					], Http::STATUS_BAD_REQUEST);
				}
				if ($rangeStart > $rangeEnd) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Start date cannot be after end date')
					], Http::STATUS_BAD_REQUEST);
				}
				$filters['date_range'] = [
					'start' => $rangeStart,
					'end' => $rangeEnd,
				];
			}

			$absences = $this->absenceService->getAbsencesByUser($userId, $filters, $limit, $offset);

			// Also include absences where the current user is configured as substitute,
			// so upcoming coverages appear in calendar and timeline views.
			$coverageAbsences = $this->absenceMapper->findBySubstituteUser($userId);
			$coverageAbsences = array_filter($coverageAbsences, function (Absence $a) use ($status, $type, $filters): bool {
				// Only show absences that the user is actually covering or will cover:
				// - pending: substitute has already approved, waiting for manager
				// - approved: fully approved absence
				if (!in_array($a->getStatus(), [Absence::STATUS_PENDING, Absence::STATUS_APPROVED], true)) {
					return false;
				}
				if ($type !== null && $type !== '' && $a->getType() !== $type) {
					return false;
				}
				if (isset($filters['date_range'])) {
					$start = $a->getStartDate();
					$end = $a->getEndDate();
					if ($start === null || $end === null) {
						return false;
					}
					if (!($start <= $filters['date_range']['end'] && $end >= $filters['date_range']['start'])) {
						return false;
					}
				}
				if ($status !== null && $status !== '') {
					if ($status === 'pending') {
						// "pending" means awaiting any approval – for substitute role we
						// restrict this to the manager-pending state.
						return $a->getStatus() === Absence::STATUS_PENDING;
					}
					return $a->getStatus() === $status;
				}
				return true;
			});

			// Safely map absences to summaries, handling any potential null DateTime issues.
			// Track IDs so we don't accidentally duplicate entries if business rules change.
			$absenceSummaries = [];
			$seenIds = [];

			foreach ($absences as $absence) {
				try {
					$summary = $absence->getSummary();
					$absenceSummaries[] = $summary;
					if (isset($summary['id'])) {
						$seenIds[(int)$summary['id']] = true;
					}
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for absence ' . $absence->getId() . ': ' . $e->getMessage(), ["exception" => $e]);
					continue;
				}
			}

			// Add substitute-role absences with a small "role" flag and owner display name for frontend.
			foreach ($coverageAbsences as $absence) {
				$id = $absence->getId();
				if ($id !== null && isset($seenIds[(int)$id])) {
					continue;
				}
				try {
					$summary = $absence->getSummary();
					$summary['role'] = 'substitute';
					$owner = $this->userManager->get($absence->getUserId());
					$summary['ownerDisplayName'] = $owner !== null ? $owner->getDisplayName() : $absence->getUserId();
					$absenceSummaries[] = $summary;
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for substitute absence ' . $absence->getId() . ': ' . $e->getMessage(), ["exception" => $e]);
					continue;
				}
			}

			return new JSONResponse([
				'success' => true,
				'absences' => $absenceSummaries
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Show create absence form page
	 *
	 * @return TemplateResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): TemplateResponse
	{
		\OCA\ArbeitszeitCheck\Service\FrontEndAssetService::registerTranslations();

		$userId = $this->getUserId();
		$colleagues = $this->getColleaguesForSubstitute($userId);
		$hasColleagues = count($colleagues) > 0;
		$requireSubstituteTypes = $this->getRequireSubstituteTypes();

		$prefillStart = null;
		$prefillEnd = null;
		$prefillHalf = false;
		$qStart = trim((string)$this->request->getParam('start', ''));
		$qEnd = trim((string)$this->request->getParam('end', ''));
		$qHalf = strtolower(trim((string)$this->request->getParam('half', '')));
		$prefillHalf = in_array($qHalf, ['1', 'true', 'yes', '0.5'], true);
		$ymdOk = static function (string $s): bool {
			return (bool)preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', $s);
		};
		if ($qStart !== '' && $ymdOk($qStart)) {
			$ds = \DateTimeImmutable::createFromFormat('!Y-m-d', $qStart);
			if ($ds instanceof \DateTimeImmutable) {
				$prefillStart = $ds->format('d.m.Y');
			}
		}
		if ($qEnd !== '' && $ymdOk($qEnd)) {
			$de = \DateTimeImmutable::createFromFormat('!Y-m-d', $qEnd);
			if ($de instanceof \DateTimeImmutable) {
				$prefillEnd = $de->format('d.m.Y');
			}
		}
		if ($prefillStart !== null && $prefillEnd === null) {
			$prefillEnd = $prefillStart;
		}
		// Half-day shortcut only makes sense for a single calendar day.
		if ($prefillHalf && $prefillStart !== null && $prefillEnd !== null && $prefillStart !== $prefillEnd) {
			$prefillHalf = false;
		}

		// Surface server-side errors that came from a no-JS form POST so the user
		// sees a usable message instead of just a silent redirect. The error
		// string is already l10n-translated (built in this controller) and is
		// re-escaped by the template before printing.
		$queryError = trim((string)$this->request->getParam('error', ''));
		$displayError = ($queryError !== '' && strlen($queryError) <= 500) ? $queryError : null;

		$response = new TemplateResponse(
			$this->appName,
			'absences',
			$this->buildAbsencePageTemplateParams('create', [
				'mode' => 'create',
				'absence' => null,
				'absences' => [],
				'hasColleagues' => $hasColleagues,
				'requireSubstituteTypes' => $requireSubstituteTypes,
				'stats' => [],
				'currentUserId' => $userId,
				'colleagues' => $colleagues,
				'usersUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.users'),
				'employeeHasAssignableManager' => $this->teamResolver->hasAssignableManagerForEmployee($userId),
				'useAppTeams' => $this->teamResolver->useAppTeams(),
				'prefillStart' => $prefillStart,
				'prefillEnd' => $prefillEnd,
				'prefillHalf' => $prefillHalf,
				'error' => $displayError,
			])
		);
		return $this->configureCSP($response);
	}

	/**
	 * Show edit absence form page
	 *
	 * @param int $id Absence ID
	 * @return TemplateResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function edit(int $id): TemplateResponse
	{
		\OCA\ArbeitszeitCheck\Service\FrontEndAssetService::registerTranslations();

		try {
			$userId = $this->getUserId();
			$absence = $this->absenceService->getAbsence($id, $userId);

			if (!$absence) {
				$response = new TemplateResponse(
					$this->appName,
					'absences',
					$this->buildAbsenceListErrorParams($this->l10n->t('Absence not found'))
				);
				return $this->configureCSP($response);
			}

			$colleagues = $this->getColleaguesForSubstitute($userId);
			$hasColleagues = count($colleagues) > 0;
			$requireSubstituteTypes = $this->getRequireSubstituteTypes();

			$queryError = trim((string)$this->request->getParam('error', ''));
			$displayError = ($queryError !== '' && strlen($queryError) <= 500) ? $queryError : null;

			$response = new TemplateResponse(
				$this->appName,
				'absences',
				$this->buildAbsencePageTemplateParams('edit', [
					'mode' => 'edit',
					'absence' => $absence,
					'absences' => [],
					'hasColleagues' => $hasColleagues,
					'requireSubstituteTypes' => $requireSubstituteTypes,
					'stats' => [],
					'currentUserId' => $userId,
					'colleagues' => $colleagues,
					'usersUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.users'),
					'employeeHasAssignableManager' => $this->teamResolver->hasAssignableManagerForEmployee($userId),
					'useAppTeams' => $this->teamResolver->useAppTeams(),
					'error' => $displayError,
				])
			);
			return $this->configureCSP($response);
		} catch (DoesNotExistException $e) {
			$response = new TemplateResponse(
				$this->appName,
				'absences',
				$this->buildAbsenceListErrorParams($this->l10n->t('Absence not found'))
			);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AbsenceController::edit: ' . $e->getMessage(), ['exception' => $e]);
			$response = new TemplateResponse(
				$this->appName,
				'absences',
				$this->buildAbsenceListErrorParams(
					$this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
				)
			);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Get absence details page (HTML view)
	 *
	 * @param int $id Absence ID
	 * @return TemplateResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): TemplateResponse
	{
		\OCA\ArbeitszeitCheck\Service\FrontEndAssetService::registerTranslations();

		try {
			$userId = $this->getUserId();
			$absence = $this->absenceService->getAbsence($id, $userId);

			if (!$absence) {
				$response = new TemplateResponse(
					$this->appName,
					'absences',
					$this->buildAbsenceListErrorParams($this->l10n->t('Absence not found'))
				);
				return $this->configureCSP($response);
			}

			$colleagues = $this->getColleaguesForSubstitute($userId);
			$hasColleagues = count($colleagues) > 0;

			$substituteDisplayName = null;
			$subId = $absence->getSubstituteUserId();
			if ($subId !== null && $subId !== '') {
				$subUser = $this->userManager->get($subId);
				$substituteDisplayName = $subUser !== null ? $subUser->getDisplayName() : $subId;
			}

			// Precompute working days when days=NULL (HolidayService, state-aware)
			$displayDays = $absence->getDays() !== null
				? (float)$absence->getDays()
				: $this->absenceService->getWorkingDaysForDisplay($absence);

			$response = new TemplateResponse(
				$this->appName,
				'absences',
				$this->buildAbsencePageTemplateParams('view', [
					'mode' => 'view',
					'absence' => $absence,
					'displayDays' => $displayDays,
					'computedWorkingDays' => [],
					'absences' => [],
					'hasColleagues' => $hasColleagues,
					'colleagues' => $colleagues,
					'substituteDisplayName' => $substituteDisplayName,
					'stats' => [],
					'currentUserId' => $userId,
					'usersUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.users'),
					'employeeHasAssignableManager' => $this->teamResolver->hasAssignableManagerForEmployee($userId),
					'useAppTeams' => $this->teamResolver->useAppTeams(),
				])
			);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AbsenceController::show: ' . $e->getMessage(), ['exception' => $e]);
			$response = new TemplateResponse(
				$this->appName,
				'absences',
				$this->buildAbsenceListErrorParams(
					$this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
				)
			);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Create absence endpoint
	 *
	 * When request does not expect JSON (e.g. form POST without JS), returns redirect so the user never sees raw JSON.
	 *
	 * @return JSONResponse|RedirectResponse
	 */
	#[NoAdminRequired]
	public function store(): JSONResponse|RedirectResponse
	{
		try {
			$userId = $this->getUserId();
			
			// Get data from request body
			$params = $this->request->getParams();
			
			// Ensure type is a string (handle case where it might be an array)
			$type = $params['type'] ?? '';
			if (is_array($type)) {
				$type = !empty($type) ? (string)reset($type) : '';
			} else {
				$type = (string)$type;
			}
			
			$data = [
				'type' => $type,
				'start_date' => is_array($params['start_date'] ?? '') ? (string)reset($params['start_date']) : (string)($params['start_date'] ?? ''),
				'end_date' => is_array($params['end_date'] ?? '') ? (string)reset($params['end_date']) : (string)($params['end_date'] ?? ''),
				'reason' => is_array($params['reason'] ?? null) ? (string)reset($params['reason']) : ($params['reason'] ?? null),
				'substitute_user_id' => is_array($params['substitute_user_id'] ?? null) ? (string)reset($params['substitute_user_id']) : ($params['substitute_user_id'] ?? null),
			];
			if (array_key_exists('duration_hours', $params)) {
				$dh = $params['duration_hours'];
				$data['duration_hours'] = is_array($dh) ? (string)reset($dh) : (string)$dh;
			}
			if (!empty($params['require_duration_hours'])) {
				$data['require_duration_hours'] = true;
			}
			if (!empty($params['server_may_fill_hours'])) {
				$data['server_may_fill_hours'] = true;
			}
			// day_fraction only (SEC-02): never copy request `days` / `working_days`.
			if (array_key_exists('day_fraction', $params)) {
				$df = $params['day_fraction'];
				$data['day_fraction'] = is_array($df) ? (string)reset($df) : (string)$df;
			}

			if (empty($data['type']) || empty($data['start_date']) || empty($data['end_date'])) {
				$msg = $this->l10n->t('Please choose a type and fill in start and end date.');
				if (!$this->wantsJson()) {
					$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.create') . '?error=' . rawurlencode($msg);
					return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
				}
				return new JSONResponse([
					'success' => false,
					'error' => $msg,
				], Http::STATUS_BAD_REQUEST);
			}

			try {
				$ds = $this->parseFormDate($data['start_date']);
				$de = $this->parseFormDate($data['end_date']);
			} catch (\InvalidArgumentException $e) {
				return $this->invalidDatesResponse();
			}

			if ($ds > $de) {
				$msg = $this->l10n->t('End date cannot be before start date.');
				if (!$this->wantsJson()) {
					$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.create') . '?error=' . rawurlencode($msg);
					return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
				}
				return new JSONResponse(['success' => false, 'error' => $msg], Http::STATUS_BAD_REQUEST);
			}

			try {
				$de->setTime(23, 59, 59);
				$this->monthClosureService->assertDateRangeMutable($userId, $ds, $de);
			} catch (MonthFinalizedException $e) {
				$msg = $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.');
				if (!$this->wantsJson()) {
					$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.create') . '?error=' . rawurlencode($msg);
					return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
				}
				return new JSONResponse([
					'success' => false,
					'error' => $msg,
				], Http::STATUS_CONFLICT);
			}

			// Normalise to ISO so the service layer's parseDate() always sees a canonical value.
			$data['start_date'] = $ds->format('Y-m-d');
			$data['end_date'] = $de->format('Y-m-d');

			$absence = $this->absenceService->createAbsence($data, $userId);

			if (!$this->wantsJson()) {
				$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.absences') . '?created=1';
				$days = $absence->getDays();
				if ($days !== null && abs((float)$days - 0.5) < 0.011) {
					$url .= '&half=1';
				}
				return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
			}
			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AbsenceController::store: ' . $e->getMessage(), ['exception' => $e]);
			$msg = $this->getSafeErrorMessage($e);
			if (!$this->wantsJson()) {
				$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.create') . '?error=' . rawurlencode($msg);
				return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
			}
			$payload = [
				'success' => false,
				'error' => $msg,
			];
			$code = $this->getErrorCode($e);
			if ($code !== null) {
				$payload['code'] = $code;
				$payload['error_code'] = $code;
			}
			return new JSONResponse($payload, Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Update absence endpoint (POST method for form submissions)
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse|RedirectResponse
	 */
	#[NoAdminRequired]
	public function updatePost(int $id): JSONResponse|RedirectResponse
	{
		$params = $this->request->getParams();
		$start_date = $params['start_date'] ?? null;
		$end_date = $params['end_date'] ?? null;
		$reason = $params['reason'] ?? null;
		$substitute_user_id = isset($params['substitute_user_id']) ? (string)$params['substitute_user_id'] : null;
		$day_fraction = array_key_exists('day_fraction', $params) ? $params['day_fraction'] : null;
		$duration_hours = array_key_exists('duration_hours', $params) ? $params['duration_hours'] : null;

		return $this->update(
			$id,
			is_array($start_date) ? (string)reset($start_date) : ($start_date !== null ? (string)$start_date : null),
			is_array($end_date) ? (string)reset($end_date) : ($end_date !== null ? (string)$end_date : null),
			is_array($reason) ? (string)reset($reason) : ($reason !== null ? (string)$reason : null),
			$substitute_user_id,
			is_array($day_fraction) ? (string)reset($day_fraction) : ($day_fraction !== null ? (string)$day_fraction : null),
			is_array($duration_hours) ? (string)reset($duration_hours) : ($duration_hours !== null ? (string)$duration_hours : null)
		);
	}

	/**
	 * Update absence endpoint
	 *
	 * When request does not expect JSON, returns redirect so the user never sees raw JSON.
	 *
	 * @param int $id Absence ID
	 * @param string|null $start_date New start date
	 * @param string|null $end_date New end date
	 * @param string|null $reason New reason
	 * @param string|null $substitute_user_id New substitute user ID (empty to clear)
	 * @param string|null $day_fraction Days-mode length: 1 or 0.5 (never bind client days)
	 * @param string|null $duration_hours Hours-mode debit
	 * @return JSONResponse|RedirectResponse
	 */
	#[NoAdminRequired]
	public function update(
		int $id,
		?string $start_date = null,
		?string $end_date = null,
		?string $reason = null,
		?string $substitute_user_id = null,
		?string $day_fraction = null,
		?string $duration_hours = null,
	): JSONResponse|RedirectResponse
	{
		try {
			$userId = $this->getUserId();
			$data = [];

			if ($start_date) {
				$data['start_date'] = $start_date;
			}
			if ($end_date) {
				$data['end_date'] = $end_date;
			}
			if ($reason !== null) {
				$data['reason'] = $reason;
			}
			if ($substitute_user_id !== null) {
				$data['substitute_user_id'] = $substitute_user_id === '' ? null : $substitute_user_id;
			}
			if ($day_fraction !== null && $day_fraction !== '') {
				$data['day_fraction'] = $day_fraction;
			}
			if ($duration_hours !== null && $duration_hours !== '') {
				$data['duration_hours'] = $duration_hours;
			}

			try {
				$existing = $this->absenceMapper->find($id);
				if ($existing->getUserId() !== $userId) {
					return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Absence not found')], Http::STATUS_NOT_FOUND);
				}
				$this->monthClosureService->assertDateRangeMutable(
					$userId,
					$existing->getStartDate(),
					$existing->getEndDate()
				);
				try {
					$nStart = isset($data['start_date']) ? $this->parseFormDate((string)$data['start_date']) : $existing->getStartDate();
					$nEnd = isset($data['end_date']) ? $this->parseFormDate((string)$data['end_date']) : $existing->getEndDate();
				} catch (\InvalidArgumentException $e) {
					return $this->invalidDatesResponse();
				}
				if ($nStart > $nEnd) {
					$msg = $this->l10n->t('End date cannot be before start date.');
					if (!$this->wantsJson()) {
						$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.edit', ['id' => $id]) . '?error=' . rawurlencode($msg);
						return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
					}
					return new JSONResponse(['success' => false, 'error' => $msg], Http::STATUS_BAD_REQUEST);
				}
				$nEnd->setTime(23, 59, 59);
				$this->monthClosureService->assertDateRangeMutable($userId, $nStart, $nEnd);
				if (isset($data['start_date'])) {
					$data['start_date'] = $nStart->format('Y-m-d');
				}
				if (isset($data['end_date'])) {
					$data['end_date'] = $nEnd->format('Y-m-d');
				}
			} catch (MonthFinalizedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.'),
				], Http::STATUS_CONFLICT);
			} catch (DoesNotExistException $e) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Absence not found')], Http::STATUS_NOT_FOUND);
			}

			$absence = $this->absenceService->updateAbsence($id, $data, $userId);

			if (!$this->wantsJson()) {
				$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.absences') . '?updated=1';
				return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
			}
			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AbsenceController::update: ' . $e->getMessage(), ['exception' => $e]);
			$payload = [
				'success' => false,
				'error' => $this->getSafeErrorMessage($e),
			];
			$code = $this->getErrorCode($e);
			if ($code !== null) {
				$payload['code'] = $code;
				$payload['error_code'] = $code;
			}
			return new JSONResponse($payload, Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Delete absence endpoint
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function delete(int $id): JSONResponse
	{
		try {
			if ($id <= 0) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid absence ID')
				], Http::STATUS_BAD_REQUEST);
			}

			$userId = $this->getUserId();

			// First, distinguish between "not found" and "forbidden" using the mapper
			try {
				$absence = $this->absenceMapper->find($id);
			} catch (DoesNotExistException $e) {
				// Idempotent behaviour: deleting a non-existing absence returns 404,
				// so API clients can distinguish this from success.
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Absence not found')
				], Http::STATUS_NOT_FOUND);
			}

			if ($absence->getUserId() !== $userId) {
				// Do not leak whether the ID belongs to another user.
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Absence not found')
				], Http::STATUS_NOT_FOUND);
			}

			try {
				$this->monthClosureService->assertDateRangeMutable(
					$userId,
					$absence->getStartDate(),
					$absence->getEndDate()
				);
			} catch (MonthFinalizedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.'),
				], Http::STATUS_CONFLICT);
			}

			// Delegate business rules (pending/substitute_pending only, etc.) to the service.
			try {
				$this->absenceService->deleteAbsence($id, $userId);
			} catch (\Exception $e) {
				$message = trim($e->getMessage());
				// Map known state-related messages to 409 Conflict to signal that the
				// resource exists but is not deletable in its current state.
				if ($message === $this->l10n->t('Only pending absences can be deleted')) {
					return new JSONResponse([
						'success' => false,
						'error' => $message
					], Http::STATUS_CONFLICT);
				}

				// Fallback: bad request with the service's message.
				return new JSONResponse([
					'success' => false,
					'error' => $message !== '' ? $message : $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
				], Http::STATUS_BAD_REQUEST);
			}

			return new JSONResponse([
				'success' => true
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Approve absence endpoint.
	 * Only users who can manage the absence owner (same-group team) may approve.
	 *
	 * @param int $id Absence ID
	 * @param string|null $comment Approval comment
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function approve(int $id, ?string $comment = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$absence = $this->absenceMapper->find($id);
			// Scope check BEFORE the pending-status check: an out-of-scope caller must
			// not learn whether the absence exists or was decided (BOLA oracle).
			if (!$this->permissionService->canManageEmployee($userId, $absence->getUserId())) {
				$this->permissionService->logPermissionDenied($userId, 'approve_absence', 'absence', (string) $id);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. You can only approve absences for members of your team.')
				], Http::STATUS_FORBIDDEN);
			}
			if ($absence->getStatus() !== Absence::STATUS_PENDING) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This absence was already decided by another manager.'),
					'error_code' => 'already_decided',
				], Http::STATUS_CONFLICT);
			}
			try {
				$absence = $this->absenceService->approveAbsence($id, $userId, $comment);
			} catch (ConcurrentDecisionException $e) {
				return new JSONResponse($e->toHttpPayload(), Http::STATUS_CONFLICT);
			} catch (\OCP\Lock\LockedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This absence was already decided by another manager.'),
					'error_code' => 'already_decided',
				], Http::STATUS_CONFLICT);
			}

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Absence not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Reject absence endpoint.
	 * Only users who can manage the absence owner (same-group team) may reject.
	 *
	 * @param int $id Absence ID
	 * @param string|null $comment Rejection comment
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reject(int $id, ?string $comment = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$absence = $this->absenceMapper->find($id);
			// Scope check BEFORE the pending-status check (BOLA oracle — see approve()).
			if (!$this->permissionService->canManageEmployee($userId, $absence->getUserId())) {
				$this->permissionService->logPermissionDenied($userId, 'reject_absence', 'absence', (string) $id);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. You can only reject absences for members of your team.')
				], Http::STATUS_FORBIDDEN);
			}
			if ($absence->getStatus() !== Absence::STATUS_PENDING) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This absence was already decided by another manager.'),
					'error_code' => 'already_decided',
				], Http::STATUS_CONFLICT);
			}
			try {
				$absence = $this->absenceService->rejectAbsence($id, $userId, $comment);
			} catch (ConcurrentDecisionException $e) {
				return new JSONResponse($e->toHttpPayload(), Http::STATUS_CONFLICT);
			} catch (\OCP\Lock\LockedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This absence was already decided by another manager.'),
					'error_code' => 'already_decided',
				], Http::STATUS_CONFLICT);
			}

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Absence not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Get substitute candidates (colleagues in same team/group).
	 * Restricted to team members for data minimization and security.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function users(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$colleagueIds = $this->teamResolver->getColleagueIds($userId);

			$usersData = [];
			foreach ($colleagueIds as $uid) {
				$user = $this->userManager->get($uid);
				if ($user !== null && $user->isEnabled()) {
					$displayName = $user->getDisplayName();
					$usersData[] = [
						'userId' => $user->getUID(),
						'displayName' => $displayName ?? $user->getUID(),
						'display_name' => $displayName ?? $user->getUID(),
					];
				}
			}

			return new JSONResponse([
				'success' => true,
				'users' => $usersData,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Failed to load colleague list for substitute selection: ' . $e->getMessage(),
				[
					'app' => 'arbeitszeitcheck',
					'exception' => $e,
					'userId' => $this->userSession->getUser()?->getUID() ?? 'unknown',
				]
			);
			// Return empty list so the UI stays usable; user sees "None" and empty-state message
			return new JSONResponse([
				'success' => true,
				'users' => [],
			]);
		}
	}

	#[NoAdminRequired]
	public function estimateVacationHours(?string $start_date = null, ?string $end_date = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$startRaw = $start_date !== null && $start_date !== ''
				? $start_date
				: (string)$this->request->getParam('start_date', '');
			$endRaw = $end_date !== null && $end_date !== ''
				? $end_date
				: (string)$this->request->getParam('end_date', '');
			try {
				$start = $this->parseFormDate($startRaw);
				$end = $this->parseFormDate($endRaw !== '' ? $endRaw : $startRaw);
			} catch (\InvalidArgumentException) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Start date and end date are required'),
				], Http::STATUS_BAD_REQUEST);
			}
			/** @var VacationHoursDebitService $debit */
			$debit = \OCP\Server::get(VacationHoursDebitService::class);
			$est = $debit->estimateForUserRange($userId, $start, $end);
			return new JSONResponse([
				'success' => true,
				'hours' => $est['hours'],
				'basis' => $est['basis'],
				'average_daily' => $est['average_daily'],
				'one_day_hours' => $est['one_day_hours'],
				'weekday_nets' => $est['weekday_nets'],
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in AbsenceController::estimateVacationHours: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get absence statistics endpoint
	 *
	 * @param int|null $year Year for statistics (defaults to current year)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function stats(?int $year = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			if ($year === null) {
				$year = (int)date('Y');
			}

			$stats = $this->absenceService->getVacationStats($userId, $year);

			return new JSONResponse([
				'success' => true,
				'vacationStats' => [
					'used' => $stats['used'],
					'total' => $stats['entitlement'],
					'entitlement' => $stats['entitlement'],
					'total_available' => $stats['total_available'] ?? null,
					'carryover_days' => $stats['carryover_days'] ?? 0,
					'carryover_usable' => $stats['carryover_usable'] ?? 0,
					'carryover_expires_on' => $stats['carryover_expires_on'] ?? null,
					'carryover_unused_locked_after_deadline' => (bool)($stats['carryover_unused_locked_after_deadline'] ?? false),
					'carryover_remaining_after_approved' => (float)($stats['carryover_remaining_after_approved'] ?? 0),
					'annual_remaining_after_approved' => (float)($stats['annual_remaining_after_approved'] ?? 0),
					'carryover_max_cap' => $stats['carryover_max_cap'] ?? null,
					'remaining' => $stats['remaining'],
					'vacation_unit' => $stats['vacation_unit'] ?? 'days',
					'vacation_hours_per_day' => $stats['vacation_hours_per_day'] ?? null,
				],
				'sickLeaveStats' => [
					'days' => $stats['sick_days']
				]
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AbsenceController::stats: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Employee-facing "How is my vacation entitlement computed?" explainer.
	 *
	 * Resolves the entitlement for the current user as of the requested date
	 * (defaults to today) and returns a **redacted** trace suitable for
	 * end-user display. The redaction in
	 * {@see VacationEntitlementEngine::redactTraceForUser()} strips internal
	 * IDs and HR descriptions to prevent the explainer from leaking other
	 * employees' / teams' policy metadata (REQ-SEC-05).
	 */
  #[NoAdminRequired]
  #[NoCSRFRequired]
  public function entitlementTrace(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
			}
			$userId = $user->getUID();
			$asOfRaw = (string)($this->request->getParam('asOfDate') ?? date('Y-m-d'));
			$asOfDate = new \DateTime($asOfRaw);
			$result = $this->vacationEntitlementEngine->computeForDate($userId, $asOfDate);
			$redactedTrace = $this->vacationEntitlementEngine->redactTraceForUser($result['trace']);

			// Apply the same partial-year proration that the actual vacation
			// balance uses, so the employee-facing explainer never shows the
			// full annual figure while the usable balance is reduced.
			$fullDays = (float)$result['days'];
			$proration = $this->vacationProrationService->prorateForYear(
				$userId,
				(int)$asOfDate->format('Y'),
				$fullDays
			);

			return new JSONResponse([
				'success' => true,
				'asOfDate' => $asOfDate->format('Y-m-d'),
				'effectiveEntitlementDays' => $fullDays,
				'proratedEntitlementDays' => (float)$proration['days'],
				'prorated' => (bool)$proration['prorated'],
				'prorationMethod' => (string)$proration['method'],
				'prorationMonthsCovered' => (int)$proration['months_covered'],
				'employedInYear' => (bool)$proration['employed_in_year'],
				'matchedLayer' => $result['matchedLayer'],
				'trace' => $redactedTrace,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AbsenceController::entitlementTrace: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Could not load entitlement explanation')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}