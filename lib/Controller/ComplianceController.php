<?php

declare(strict_types=1);

/**
 * Compliance controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCA\ArbeitszeitCheck\Support\CompliancePageScope;
use OCA\ArbeitszeitCheck\Support\TablePager;
use OCA\ArbeitszeitCheck\Support\ComplianceReportSummary;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Constants;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IL10N;

/**
 * ComplianceController
 */
class ComplianceController extends Controller
{
	use CSPTrait;
	use NavigationFlagsTrait;
	use PageShellTrait;

	/** Latest rows painted on the page. Filters load the rest through the API. */
	private const COMPLIANCE_PAGE_VIOLATION_LIMIT = 50;

	private ComplianceService $complianceService;
	private ComplianceViolationMapper $violationMapper;
	private AuditLogMapper $auditLogMapper;
	protected PermissionService $permissionService;
	protected IUserSession $userSession;
	private IUserManager $userManager;
	protected IURLGenerator $urlGenerator;
	protected IL10N $l10n;
	protected LocaleFormatService $localeFormat;
	protected NavigationFlagsService $navigationFlags;
	private LaborLawProfileFactory $laborLawProfileFactory;

	public function __construct(
		string $appName,
		IRequest $request,
		ComplianceService $complianceService,
		ComplianceViolationMapper $violationMapper,
		AuditLogMapper $auditLogMapper,
		PermissionService $permissionService,
		IUserSession $userSession,
		IUserManager $userManager,
		IURLGenerator $urlGenerator,
		CSPService $cspService,
		LocaleFormatService $localeFormat,
		IL10N $l10n,
		NavigationFlagsService $navigationFlags,
		LaborLawProfileFactory $laborLawProfileFactory,
		private readonly ?\OCA\ArbeitszeitCheck\Service\OversightScopeService $oversightScope = null,
	) {
		parent::__construct($appName, $request);
		$this->complianceService = $complianceService;
		$this->violationMapper = $violationMapper;
		$this->auditLogMapper = $auditLogMapper;
		$this->permissionService = $permissionService;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->urlGenerator = $urlGenerator;
		$this->localeFormat = $localeFormat;
		$this->l10n = $l10n;
		$this->navigationFlags = $navigationFlags;
		$this->laborLawProfileFactory = $laborLawProfileFactory;
		$this->setCspService($cspService);
	}

	/**
	 * Country-aware lead copy for the compliance dashboard (DE ArbZG / AT AZG / CH ArG).
	 * Uses the viewing user's effective labour-law country (E-9), not only the
	 * organisation default — otherwise a DE-instance user with AT override still
	 * sees the German lead while compliance checks already follow AZG.
	 */
	private function complianceDashboardLead(?string $userId = null): string
	{
		$country = $userId !== null && $userId !== ''
			? $this->laborLawProfileFactory->getEffectiveCountry($userId)
			: $this->laborLawProfileFactory->getConfiguredCountry();
		return match ($country) {
			RegionRegistry::COUNTRY_AT => $this->l10n->t('Check if your working time follows Austrian labour law and see any problems that need fixing'),
			RegionRegistry::COUNTRY_CH => $this->l10n->t('Check if your working time follows Swiss labour law and see any problems that need fixing'),
			default => $this->l10n->t('Check if your working time follows German labor law and see any problems that need fixing'),
		};
	}

	/**
	 * Compliance pages hide substitution-requests nav even when pending items exist.
	 *
	 * @return array{
	 *   showSubstitutionLink: bool,
	 *   showManagerLink: bool,
	 *   showReportsLink: bool,
	 *   showAdminNav: bool,
	 *   monthClosureEnabled: bool
	 * }
	 */
	protected function getNavigationFlags(string $userId): array
	{
		return $this->navigationFlags->forComplianceUser($userId);
	}

	/**
	 * Ensure current user may access compliance data for the given target user.
	 * Allowed: own user, team members (manager), or admin.
	 */
	/**
	 * @param \OCA\ArbeitszeitCheck\Db\ComplianceViolation[] $violations
	 * @return list<array<string, mixed>>
	 */
	private function summarizeViolations(array $violations, string $currentUserId): array
	{
		$violationSummaries = [];
		foreach ($violations as $violation) {
			try {
				$summary = $violation->getSummary();
				$summary['type'] = $summary['violationType'] ?? $violation->getViolationType();
				$summary['displayName'] = $this->userManager->getDisplayName($violation->getUserId())
					?: $violation->getUserId();
				$summary['can_resolve'] = !$violation->getResolved()
					&& $this->permissionService->canResolveViolation($currentUserId, $violation->getUserId());
				$violationSummaries[] = $summary;
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for violation ' . $violation->getId() . ': ' . $e->getMessage(), ["exception" => $e]);
				continue;
			}
		}
		return $violationSummaries;
	}

	private function ensureCanAccessUserCompliance(string $currentUserId, string $targetUserId): void
	{
		// Existence leg first — deleted users leave stale member rows, so a
		// membership-only check would accept ghost uids. Nonexistent and
		// out-of-scope collapse into the same denial (no existence oracle).
		if ($this->userManager->get($targetUserId) === null) {
			$this->permissionService->logPermissionDenied($currentUserId, 'view_compliance', 'compliance', $targetUserId);
			throw new \Exception($this->l10n->t('Access denied. You can only view compliance data for yourself or your team members.'));
		}
		if ($this->permissionService->canViewUserCompliance($currentUserId, $targetUserId)) {
			return;
		}
		$this->permissionService->logPermissionDenied($currentUserId, 'view_compliance', 'compliance', $targetUserId);
		throw new \Exception($this->l10n->t('Access denied. You can only view compliance data for yourself or your team members.'));
	}

	private function oversightScope(): \OCA\ArbeitszeitCheck\Service\OversightScopeService
	{
		return $this->oversightScope ?? \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\OversightScopeService::class);
	}

	/**
	 * Generic error response for failed scope-selector resolution:
	 * denials collapse to a uniform 403 (no existence oracle), malformed
	 * input to 400.
	 */
	private function scopeErrorResponse(string $code): JSONResponse
	{
		if ($code === \OCA\ArbeitszeitCheck\Service\OversightScopeService::ERR_DENIED) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Access denied. You can only view compliance data for yourself or your team members.'),
			], Http::STATUS_FORBIDDEN);
		}
		return new JSONResponse([
			'success' => false,
			'error' => $this->l10n->t('Invalid scope parameters.'),
		], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * Strict form-encoded boolean parsing ("1"/"true"/"0"/"false");
	 * returns [ok, value] — ok=false means reject with 400.
	 *
	 * @return array{0: bool, 1: ?bool}
	 */
	private function parseBoolParam(mixed $raw): array
	{
		if ($raw === null || $raw === '' ) {
			return [true, null];
		}
		if (is_bool($raw)) {
			return [true, $raw];
		}
		$norm = strtolower(trim((string)$raw));
		if ($norm === '1' || $norm === 'true') {
			return [true, true];
		}
		if ($norm === '0' || $norm === 'false') {
			return [true, false];
		}
		return [false, null];
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
	 * Compliance page: status, personal report, and the violations table.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		$this->registerFrontEndAssets(
			'compliance-dashboard',
			'compliance-violations',
			[],
			['common/datepicker', 'common/table-pager', 'compliance-violations'],
		);

		try {
			$userId = $this->getUserId();
			$scope = $this->complianceScope($userId);
			$targetUserId = $scope['targetUserId'];

			$complianceStatus = $this->complianceService->getComplianceStatus($targetUserId);
			$rows = $this->mapViolationRows($userId, $this->violationMapper->findByUser($targetUserId, null));
			$reportData = ComplianceReportSummary::fromRows($rows);
			$shownViolations = array_slice($rows, 0, self::COMPLIANCE_PAGE_VIOLATION_LIMIT);
			$violationPager = TablePager::state(
				count($shownViolations),
				$this->request->getParam('v_page'),
				$this->request->getParam('v_per_page'),
			);
			$typePager = TablePager::state(
				count(is_array($reportData['by_type'] ?? null) ? $reportData['by_type'] : []),
				$this->request->getParam('t_page'),
				$this->request->getParam('t_per_page'),
			);
			$severityPager = TablePager::state(
				count(is_array($reportData['by_severity'] ?? null) ? $reportData['by_severity'] : []),
				$this->request->getParam('s_page'),
				$this->request->getParam('s_per_page'),
			);

			$navFlags = $this->getNavigationFlags($userId);
			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-dashboard', $this->buildShellParams(
				'compliance',
				$this->l10n->t('Compliance Dashboard'),
				$this->complianceDashboardLead($userId),
				$navFlags,
			) + [
				'collapsePageHelp' => false,
				'complianceStatus' => $complianceStatus,
				'violations' => $shownViolations,
				'violationTotal' => count($rows),
				'violationPager' => $violationPager,
				'typePager' => $typePager,
				'severityPager' => $severityPager,
				'reportData' => $reportData,
				'filterUserId' => $scope['filterUserId'],
				'filterDisplayName' => $scope['filterDisplayName'],
				'showComplianceRunCheck' => $this->permissionService->isAdmin($userId),
				'complianceRunCheckUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.compliance.runCheck'),
				'complianceViolationsUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.compliance.violations'),
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Compliance dashboard error: ' . $e->getMessage(), ['exception' => $e]);
			$navFlags = $this->getNavigationFlagsForSession();
			$leadUserId = null;
			try {
				$leadUserId = $this->getUserId();
			} catch (\Throwable) {
				$leadUserId = null;
			}
			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-dashboard', $this->buildShellParams(
				'compliance',
				$this->l10n->t('Compliance Dashboard'),
				$this->complianceDashboardLead($leadUserId),
				$navFlags,
			) + [
				'collapsePageHelp' => false,
				'complianceStatus' => [
					'compliant' => false,
					'score' => 0,
					'has_data' => false,
					'load_error' => true,
				],
				'violations' => [],
				'violationTotal' => 0,
				'reportData' => ComplianceReportSummary::fromRows([]),
				'filterUserId' => null,
				'filterDisplayName' => null,
				'showComplianceRunCheck' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Get violations page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function violations(): TemplateResponse
	{
		$this->registerFrontEndAssets('compliance-violations', 'compliance-violations');
		Util::addScript('arbeitszeitcheck', 'common/datepicker');

		try {
			$currentUserId = $this->getUserId();
			$isAdmin = $this->permissionService->isAdmin($currentUserId);
			// Managers and admins may look beyond their own violations —
			// they get the scoped-employee picker + a "who" column.
			$canSeeScope = $isAdmin || $this->permissionService->canAccessManagerDashboard($currentUserId);
			if ($canSeeScope) {
				Util::addScript('arbeitszeitcheck', 'common/admin-user-picker');
				Util::addScript('arbeitszeitcheck', 'common/manager-scoped-employee-picker');
			}
			$filterUserId = trim((string)($this->request->getParam('userId') ?? ''));
			$filterScope = trim((string)($this->request->getParam('scope') ?? ''));
			$targetUserId = $currentUserId;
			$filterDisplayName = null;
			$showWholeScope = false;
			if ($filterUserId !== '' && $filterUserId !== $currentUserId) {
				$this->ensureCanAccessUserCompliance($currentUserId, $filterUserId);
				$targetUserId = $filterUserId;
				$user = $this->userManager->get($filterUserId);
				$filterDisplayName = $user ? $user->getDisplayName() : $filterUserId;
			} elseif ($canSeeScope && ($filterScope === '' || $filterScope === 'managed' || $filterScope === 'organization')) {
				// Default for managers/admins: the whole oversight scope — the
				// discovery path the HR request asked for. `scope=self` or an
				// explicit userId narrows back down.
				$showWholeScope = $filterScope !== 'self';
			}

			// Initial view; filters and date range are handled via the API (getViolations).
			if ($showWholeScope) {
				$scopeIds = $this->oversightScope()->managedUserIds($currentUserId);
				$violations = $this->violationMapper->findByUserIdsAndDateRange(
					$scopeIds, null, null, null, null, null, 50, 0
				);
			} else {
				$violations = $this->violationMapper->findByUser($targetUserId, null);
				$violations = array_slice($violations, 0, 50);
			}

			$violationsData = [];
			foreach ($violations as $violation) {
				$violationsData[] = [
					'id' => $violation->getId(),
					'userId' => $violation->getUserId(),
					'displayName' => $this->userManager->getDisplayName($violation->getUserId())
						?: $violation->getUserId(),
					'type' => $violation->getViolationType(),
					'severity' => $violation->getSeverity(),
					'date' => $violation->getDate() ? $violation->getDate()->format('Y-m-d') : null,
					'resolved' => $violation->getResolved(),
					'description' => $violation->getDescription(),
					'can_resolve' => !$violation->getResolved()
						&& $this->permissionService->canResolveViolation($currentUserId, $violation->getUserId()),
				];
			}

			$navFlags = $this->getNavigationFlags($currentUserId);

			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-violations', $this->buildShellParams(
				'compliance-violations',
				$this->l10n->t('Compliance Violations'),
				$this->l10n->t('Review and resolve working time rule violations'),
				$navFlags,
			) + [
				'violations' => $violationsData,
				'total' => count($violations),
				'filterUserId' => $filterUserId !== '' && $filterUserId !== $currentUserId ? $filterUserId : null,
				'filterDisplayName' => $filterDisplayName,
				'canSeeScope' => $canSeeScope,
				'wholeScope' => $showWholeScope,
				'scopeValue' => $isAdmin ? 'organization' : 'managed',
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('ComplianceController::violations failed: ' . $e->getMessage(), ['exception' => $e]);
			$navFlags = $this->getNavigationFlagsForSession();
			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-violations', $this->buildShellParams(
				'compliance-violations',
				$this->l10n->t('Compliance Violations'),
				$this->l10n->t('Review and resolve working time rule violations'),
				$navFlags,
			) + [
				'violations' => [],
				'total' => 0,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Old reports URL. The summary now lives in the status card.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reports(): RedirectResponse
	{
		return $this->redirectToCompliancePage('#compliance-status');
	}

	private function redirectToCompliancePage(string $fragment): RedirectResponse
	{
		$raw = $this->request->getParam('userId');
		$params = [];
		if (is_string($raw) && trim($raw) !== '') {
			$params['userId'] = trim($raw);
		}
		$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.compliance.dashboard', $params);

		return new RedirectResponse($url . $fragment);
	}

	/**
	 * @return array{targetUserId: string, filterUserId: ?string, filterDisplayName: ?string}
	 */
	private function complianceScope(string $currentUserId): array
	{
		$raw = $this->request->getParam('userId');
		try {
			$scope = CompliancePageScope::resolve(
				$currentUserId,
				$raw,
				fn (string $actor, string $target): bool => $this->permissionService->canViewUserCompliance($actor, $target),
			);
		} catch (\InvalidArgumentException $e) {
			if ($e->getMessage() !== CompliancePageScope::DENIED) {
				throw $e;
			}
			$requested = is_string($raw) ? trim($raw) : '';
			$this->permissionService->logPermissionDenied($currentUserId, 'view_compliance', 'compliance', $requested);
			throw new \Exception($this->l10n->t('Access denied. You can only view compliance data for yourself or your team members.'));
		}

		$filterDisplayName = null;
		if ($scope['filterUserId'] !== null) {
			$user = $this->userManager->get($scope['filterUserId']);
			$filterDisplayName = $user ? $user->getDisplayName() : $scope['filterUserId'];
		}

		return [
			'targetUserId' => $scope['targetUserId'],
			'filterUserId' => $scope['filterUserId'],
			'filterDisplayName' => $filterDisplayName,
		];
	}

	/**
	 * @param list<ComplianceViolation> $violations
	 * @return list<array{id: int, type: string, severity: string, date: string, resolved: bool, description: string, can_resolve: bool}>
	 */
	private function mapViolationRows(string $actorId, array $violations): array
	{
		$rows = [];
		foreach ($violations as $violation) {
			if (!$violation instanceof ComplianceViolation) {
				continue;
			}
			$date = $violation->getDate();
			$resolved = (bool)$violation->getResolved();
			$rows[] = [
				'id' => (int)$violation->getId(),
				'type' => (string)$violation->getViolationType(),
				'severity' => (string)$violation->getSeverity(),
				'date' => $date instanceof \DateTimeInterface ? $date->format('d.m.Y') : '-',
				'resolved' => $resolved,
				'description' => (string)$violation->getDescription(),
				'can_resolve' => !$resolved
					&& $this->permissionService->canResolveViolation($actorId, (string)$violation->getUserId()),
			];
		}

		return $rows;
	}

	/**
	 * Parse and validate date string (Y-m-d format). Returns null for empty, throws on invalid.
	 *
	 * @param string|null $dateStr Date string in Y-m-d format
	 * @param string $paramName Parameter name for error message
	 * @return \DateTime|null Parsed DateTime or null if empty
	 * @throws \Exception On invalid date format
	 */
	private function parseDateParam(?string $dateStr, string $paramName = 'date'): ?\DateTime
	{
		if ($dateStr === null || $dateStr === '') {
			return null;
		}
		$d = \DateTime::createFromFormat('Y-m-d', $dateStr);
		if ($d === false || $d->format('Y-m-d') !== $dateStr) {
			throw new \Exception($this->l10n->t('Invalid %s format. Use Y-m-d (e.g. 2024-01-15)', [$paramName]));
		}
		return $d;
	}

	private function parseIsoDateTimeParam(string $value, string $paramName): \DateTime
	{
		$formats = [
			\DateTime::ATOM,
			'Y-m-d\TH:i:s.u\Z',
			'Y-m-d\TH:i:s\Z',
			'Y-m-d\TH:i:sP',
		];
		foreach ($formats as $format) {
			$parsed = \DateTime::createFromFormat($format, $value);
			if ($parsed !== false) {
				return $parsed;
			}
		}

		throw new \InvalidArgumentException($this->l10n->t(
			'Invalid %s format. Use ISO-8601 (e.g. 2024-01-15T09:00:00Z).',
			[$paramName]
		));
	}

	private function normalizeLimit(?int $limit): int
	{
		$value = $limit ?? Constants::DEFAULT_LIST_LIMIT;
		if ($value < 1) {
			return Constants::DEFAULT_LIST_LIMIT;
		}
		return min($value, Constants::MAX_LIST_LIMIT);
	}

	private function normalizeOffset(?int $offset): int
	{
		return max(0, (int)($offset ?? 0));
	}

	/**
	 * Get compliance violations API endpoint
	 *
	 * @param string|null $userId Filter by user ID (admin only)
	 * @param string|null $violationType Filter by violation type
	 * @param bool|null $resolved Filter by resolved status
	 * @param string|null $severity Filter by severity
	 * @param string|null $startDate Start date filter (Y-m-d format)
	 * @param string|null $endDate End date filter (Y-m-d format)
	 * @param int|null $limit Pagination limit
	 * @param int|null $offset Pagination offset
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getViolations(
		?string $userId = null,
		?string $violationType = null,
		?string $resolved = null,
		?string $severity = null,
		?string $startDate = null,
		?string $endDate = null,
		?int $limit = Constants::DEFAULT_LIST_LIMIT,
		?int $offset = 0,
		?string $scope = null,
	): JSONResponse {
		try {
			$currentUserId = $this->getUserId();
			$limit = $this->normalizeLimit($limit);
			$offset = $this->normalizeOffset($offset);

			// Strict form-encoded boolean parsing — `?resolved=false` must
			// not collapse to "resolved" via PHP string truthiness.
			[$resolvedOk, $resolvedFilter] = $this->parseBoolParam($resolved);
			if (!$resolvedOk) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid resolved filter.'),
				], Http::STATUS_BAD_REQUEST);
			}

			// Parse and validate date params (Y-m-d format)
			$startDt = $this->parseDateParam($startDate, 'start_date');
			$endDt = $this->parseDateParam($endDate, 'end_date');
			$endExclusive = null;
			if ($endDt) {
				$endExclusive = clone $endDt;
				$endExclusive->setTime(0, 0, 0);
				$endExclusive->modify('+1 day'); // inclusive end date → exclusive upper bound
			}

			// Scoped listing (manager scope / organization scope for admins).
			$scopeParam = $scope !== null ? trim($scope) : '';
			if ($scopeParam !== '') {
				if ($userId !== null && trim($userId) !== '') {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Invalid scope parameters.'),
					], Http::STATUS_BAD_REQUEST);
				}
				$resolution = $this->oversightScope()->resolveRequestedScope(
					$currentUserId, null, null, null, $scopeParam
				);
				if (!$resolution['ok']) {
					return $this->scopeErrorResponse($resolution['code'] ?? '');
				}
				$scopeUserIds = $resolution['userIds'];

				// Filters applied in SQL on the scoped set — no PHP post-filter.
				$totalCount = $this->violationMapper->countByUserIdsAndDateRange(
					$scopeUserIds, $startDt, $endExclusive,
					$violationType !== null && trim($violationType) !== '' ? trim($violationType) : null,
					$severity !== null && trim($severity) !== '' ? trim($severity) : null,
					$resolvedFilter
				);
				$violations = $this->violationMapper->findByUserIdsAndDateRange(
					$scopeUserIds, $startDt, $endExclusive,
					$violationType !== null && trim($violationType) !== '' ? trim($violationType) : null,
					$severity !== null && trim($severity) !== '' ? trim($severity) : null,
					$resolvedFilter,
					$limit,
					$offset
				);

				$violationSummaries = $this->summarizeViolations($violations, $currentUserId);

				return new JSONResponse([
					'success' => true,
					'violations' => $violationSummaries,
					'total' => $totalCount,
					'scope' => $resolution['scope'],
				]);
			}

			$targetUserId = $userId ?? $currentUserId;
			$this->ensureCanAccessUserCompliance($currentUserId, $targetUserId);

			// Get violations using mapper methods
			if ($startDt || $endDt) {
				$start = $startDt ?: new \DateTime('1970-01-01');
				$start->setTime(0, 0, 0);
				$end = $endDt ? clone $endDt : new \DateTime('2099-12-31');
				$end->setTime(0, 0, 0);
				$end->modify('+1 day'); // Exclusive upper bound: next day midnight
				$allViolations = $this->violationMapper->findByDateRange($start, $end, $targetUserId, $resolvedFilter);
			} else {
				$allViolations = $this->violationMapper->findByUser($targetUserId, $resolvedFilter);
			}

			// Apply additional filters in PHP (violation type, severity)
			if ($violationType) {
				$allViolations = array_filter($allViolations, function ($violation) use ($violationType) {
					return $violation->getViolationType() === $violationType;
				});
			}

			if ($severity) {
				$allViolations = array_filter($allViolations, function ($violation) use ($severity) {
					return $violation->getSeverity() === $severity;
				});
			}

			// Get total count for pagination (after filtering)
			$totalCount = count($allViolations);

			// Re-index array after filtering
			$allViolations = array_values($allViolations);

			// Apply pagination
			$violations = array_slice($allViolations, $offset, $limit);

			$violationSummaries = $this->summarizeViolations($violations, $currentUserId);

			return new JSONResponse([
				'success' => true,
				'violations' => $violationSummaries,
				'total' => $totalCount
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ComplianceController: ' . $e->getMessage(), ["exception" => $e]);
			$raw = $e->getMessage();
			if (strpos($raw, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
				$status = Http::STATUS_UNAUTHORIZED;
			} elseif (strpos($raw, 'Access denied') !== false) {
				$errorMessage = $this->l10n->t('Access denied');
				$status = Http::STATUS_FORBIDDEN;
			} else {
				$errorMessage = $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
				$status = Http::STATUS_INTERNAL_SERVER_ERROR;
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], $status);
		}
	}

	/**
	 * Get single violation by ID
	 *
	 * @param int $id Violation ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getViolation(int $id): JSONResponse
	{
		try {
			$currentUserId = $this->getUserId();
			$violation = $this->violationMapper->find($id);
			$violationOwnerId = $violation->getUserId();

			// Allow: owner, admin, or manager of the violation owner
			if (!$this->permissionService->canViewUserCompliance($currentUserId, $violationOwnerId)) {
				$this->permissionService->logPermissionDenied($currentUserId, 'get_violation', 'compliance_violation', (string) $id);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Violation not found')
				], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse([
				'success' => true,
				'violation' => $violation->getSummary()
			]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Violation not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ComplianceController: ' . $e->getMessage(), ["exception" => $e]);
			$raw = $e->getMessage();
			$isUnauthenticated = strpos($raw, 'User not authenticated') !== false;
			$errorMessage = $isUnauthenticated
				? $this->l10n->t('User not authenticated')
				: $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], $isUnauthenticated ? Http::STATUS_UNAUTHORIZED : Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Resolve a violation
	 *
	 * @param int $id Violation ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function resolveViolation(int $id): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$violation = $this->violationMapper->find($id);
			$violationOwnerId = $violation->getUserId();

			// Admin or manager for the violation owner may resolve (owner cannot resolve own)
			if (!$this->permissionService->canResolveViolation($userId, $violationOwnerId)) {
				$this->permissionService->logPermissionDenied($userId, 'resolve_violation', 'compliance_violation', (string) $id);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Violation not found')
				], Http::STATUS_NOT_FOUND);
			}

			if ($violation->getResolved()) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Violation is already resolved'),
					'error_code' => 'ALREADY_RESOLVED'
				], Http::STATUS_CONFLICT);
			}

			$oldValues = $violation->getSummary();

			// Atomic CAS in the mapper: a concurrent resolve that won the race
			// between the read above and this write throws ConcurrentDecisionException.
			$updatedViolation = $this->violationMapper->resolveViolation($id, $userId);

			$newValues = $updatedViolation->getSummary();
			$this->auditLogMapper->logAction(
				$violationOwnerId,
				'compliance_violation_resolved',
				'compliance_violation',
				$id,
				$oldValues,
				$newValues,
				$userId
			);

			return new JSONResponse([
				'success' => true,
				'violation' => $updatedViolation->getSummary()
			]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Violation not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException $e) {
			// Lost the resolve race — the first resolver's attribution stands.
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Violation is already resolved'),
				'error_code' => 'ALREADY_RESOLVED'
			], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ComplianceController: ' . $e->getMessage(), ["exception" => $e]);
			$raw = $e->getMessage();
			$isUnauthenticated = strpos($raw, 'User not authenticated') !== false;
			$errorMessage = $isUnauthenticated
				? $this->l10n->t('User not authenticated')
				: $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], $isUnauthenticated ? Http::STATUS_UNAUTHORIZED : Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get compliance status for current user
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getStatus(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$status = $this->complianceService->getComplianceStatus($userId);

			return new JSONResponse([
				'success' => true,
				'status' => $status
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ComplianceController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$raw = $e->getMessage();
			$isUnauthenticated = strpos($raw, 'User not authenticated') !== false;
			$errorMessage = $isUnauthenticated
				? $this->l10n->t('User not authenticated')
				: $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], $isUnauthenticated ? Http::STATUS_UNAUTHORIZED : Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Generate compliance report
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @param string|null $userId When set to an empty string (`userId=`) and the caller is an admin,
	 *                            the report is generated for all enabled users. Otherwise, generates for that user.
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getReport(
		?string $startDate = null,
		?string $endDate = null,
		?string $userId = null,
		?string $userIds = null,
		?string $teamId = null,
		?string $scope = null,
	): JSONResponse {
		try {
			$currentUserId = $this->getUserId();
			$targetUserId = null;
			$targetUserIds = null;

			$hasScopeSelector = ($scope !== null && trim($scope) !== '')
				|| ($userIds !== null && trim($userIds) !== '')
				|| ($teamId !== null && trim($teamId) !== '');

			// Special case: organization scope for admins is expressed as an empty string (`userId=`).
			// Resolved through the same OversightScopeService so the preview/export
			// boundary matches the door-filtered organization everywhere.
			if ($userId === '' || $hasScopeSelector) {
				if ($userId !== null && trim($userId) !== '' && $hasScopeSelector) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Invalid scope parameters.'),
					], Http::STATUS_BAD_REQUEST);
				}
				$effectiveScope = $userId === '' && !$hasScopeSelector
					? \OCA\ArbeitszeitCheck\Service\OversightScopeService::SCOPE_ORGANIZATION
					: ($scope !== null ? trim($scope) : null);
				$resolution = $this->oversightScope()->resolveRequestedScope(
					$currentUserId, null, $userIds, $teamId, $effectiveScope
				);
				if (!$resolution['ok']) {
					return $this->scopeErrorResponse($resolution['code'] ?? '');
				}
				if ($resolution['userIds'] === null) {
					$targetUserId = $currentUserId;
				} elseif (count($resolution['userIds']) === 1 && $resolution['userIds'][0] === $currentUserId) {
					$targetUserId = $currentUserId; // legacy self-report shape
				} else {
					$targetUserIds = $resolution['userIds'];
				}
			} else {
				// Default: current user (or an explicitly provided userId if a legacy caller passes it).
				$targetUserId = $userId ?? $currentUserId;
				$this->ensureCanAccessUserCompliance($currentUserId, $targetUserId);
			}

			// Parse and validate date params (Y-m-d format)
			$startDt = $this->parseDateParam($startDate, 'start_date');
			$endDt = $this->parseDateParam($endDate, 'end_date');

			// Default to last 30 days if not specified
			$end = $endDt ?: new \DateTime();
			$end->setTime(23, 59, 59);
			$start = $startDt ?: clone $end;
			if (!$startDt) {
				$start->modify('-30 days');
			}
			$start->setTime(0, 0, 0);
			if ($start > $end) {
				throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
			}
			$days = (int)$end->diff($start)->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				throw new \Exception($this->l10n->t(
					'Export date range must not exceed %d days. Please narrow the range.',
					[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
				));
			}

			$report = $this->complianceService->generateComplianceReport($start, $end, $targetUserId, $targetUserIds);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ComplianceController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$raw = $e->getMessage();
			$isUnauthenticated = strpos($raw, 'User not authenticated') !== false;
			$errorMessage = $isUnauthenticated
				? $this->l10n->t('User not authenticated')
				: $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], $isUnauthenticated ? Http::STATUS_UNAUTHORIZED : Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Check whether the minimum rest period (ArbZG §5) is met for a given start time.
	 *
	 * Used by the frontend validation layer to give real-time feedback before the user
	 * saves a new time entry. The authenticated user's ID is always used — the optional
	 * `userId` query parameter from legacy JS callers is intentionally ignored to prevent
	 * checking another user's rest period.
	 *
	 * @param string $startTime ISO-8601 start time of the new entry
	 * @param int|null $excludeEntryId Entry ID to exclude from the check (when editing)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function checkRestPeriod(string $startTime, ?int $excludeEntryId = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();

			$startDt = $this->parseIsoDateTimeParam($startTime, 'start_time');

			$result = $this->complianceService->checkRestPeriodForStartTime($userId, $startDt, $excludeEntryId);

			return new JSONResponse([
				'success' => true,
				'valid' => $result['valid'],
				'message' => $result['message'] ?? null,
				'earliestStartTime' => isset($result['earliestStartTime'])
					? $result['earliestStartTime']->format(\DateTime::ATOM)
					: null,
			]);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('checkRestPeriod error: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Manually trigger compliance check (admin only)
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function runCheck(): JSONResponse
	{
		try {
			// Only admins can manually trigger compliance checks
			$user = $this->userSession->getUser();
			if (!$user || !$this->permissionService->isAdmin($user->getUID())) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Admin access required')
				], Http::STATUS_FORBIDDEN);
			}

			// Run compliance check for all users
			$stats = $this->complianceService->runDailyComplianceCheck();

			return new JSONResponse([
				'success' => true,
				'message' => $this->l10n->t('Compliance check completed successfully'),
				'stats' => $stats
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ComplianceController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$raw = $e->getMessage();
			$isUnauthenticated = strpos($raw, 'User not authenticated') !== false;
			$errorMessage = $isUnauthenticated
				? $this->l10n->t('User not authenticated')
				: $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], $isUnauthenticated ? Http::STATUS_UNAUTHORIZED : Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
