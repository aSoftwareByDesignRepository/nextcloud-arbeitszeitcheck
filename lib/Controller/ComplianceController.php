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
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\Util;

/**
 * ComplianceController
 */
class ComplianceController extends Controller
{
	use CSPTrait;

	private ComplianceService $complianceService;
	private ComplianceViolationMapper $violationMapper;
	private AuditLogMapper $auditLogMapper;
	private PermissionService $permissionService;
	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private IL10N $l10n;

	public function __construct(
		string $appName,
		IRequest $request,
		ComplianceService $complianceService,
		ComplianceViolationMapper $violationMapper,
		AuditLogMapper $auditLogMapper,
		PermissionService $permissionService,
		IUserSession $userSession,
		IURLGenerator $urlGenerator,
		CSPService $cspService,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->complianceService = $complianceService;
		$this->violationMapper = $violationMapper;
		$this->auditLogMapper = $auditLogMapper;
		$this->permissionService = $permissionService;
		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->l10n = $l10n;
		$this->setCspService($cspService);
	}

	/**
	 * Ensure current user may access compliance data for the given target user.
	 * Allowed: own user, team members (manager), or admin.
	 */
	private function ensureCanAccessUserCompliance(string $currentUserId, string $targetUserId): void
	{
		if ($this->permissionService->canViewUserCompliance($currentUserId, $targetUserId)) {
			return;
		}
		$this->permissionService->logPermissionDenied($currentUserId, 'view_compliance', 'compliance', $targetUserId);
		throw new \Exception($this->l10n->t('Access denied. You can only view compliance data for yourself or your team members.'));
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
	 * Compliance dashboard page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		// Add common CSS files (including colors, typography for consistent fonts)
		Util::addStyle('arbeitszeitcheck', 'common/colors');
		Util::addStyle('arbeitszeitcheck', 'common/typography');
		Util::addStyle('arbeitszeitcheck', 'common/base');
		Util::addStyle('arbeitszeitcheck', 'common/components');
		Util::addStyle('arbeitszeitcheck', 'common/layout');
		Util::addStyle('arbeitszeitcheck', 'common/utilities');
		Util::addStyle('arbeitszeitcheck', 'common/accessibility');
		Util::addStyle('arbeitszeitcheck', 'common/app-layout');
		Util::addStyle('arbeitszeitcheck', 'common/responsive');
		Util::addStyle('arbeitszeitcheck', 'navigation');
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');

		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'compliance-dashboard');

		try {
			$userId = $this->getUserId();

			// Get compliance status
			$complianceStatus = $this->complianceService->getComplianceStatus($userId);

			// Get recent violations
			$recentViolations = $this->violationMapper->findByUser($userId, null, 10, 0);
			$violationsData = [];
			foreach ($recentViolations as $violation) {
				$violationsData[] = [
					'id' => $violation->getId(),
					'type' => $violation->getViolationType(),
					'severity' => $violation->getSeverity(),
					'date' => $violation->getDate() ? $violation->getDate()->format('Y-m-d') : null,
					'resolved' => $violation->getResolved(),
					'description' => $violation->getDescription()
				];
			}

			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-dashboard', [
				'complianceStatus' => $complianceStatus,
				'recentViolations' => $violationsData,
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Compliance dashboard error: ' . $e->getMessage(), ['exception' => $e]);
			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-dashboard', [
				'complianceStatus' => [
					'compliant' => false,
					'score' => 0,
					'has_data' => false,
					'load_error' => true,
				],
				'recentViolations' => [],
				'urlGenerator' => $this->urlGenerator,
				'error' => null,
				'l' => $this->l10n,
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
		Util::addTranslations('arbeitszeitcheck');

		// Add common CSS files (including colors, typography for consistent fonts)
		Util::addStyle('arbeitszeitcheck', 'common/colors');
		Util::addStyle('arbeitszeitcheck', 'common/typography');
		Util::addStyle('arbeitszeitcheck', 'common/base');
		Util::addStyle('arbeitszeitcheck', 'common/components');
		Util::addStyle('arbeitszeitcheck', 'common/layout');
		Util::addStyle('arbeitszeitcheck', 'common/utilities');
		Util::addStyle('arbeitszeitcheck', 'common/accessibility');
		Util::addStyle('arbeitszeitcheck', 'common/app-layout');
		Util::addStyle('arbeitszeitcheck', 'common/responsive');
		Util::addStyle('arbeitszeitcheck', 'navigation');
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');

		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/datepicker');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'compliance-violations');

		try {
			$userId = $this->getUserId();

			// Get all violations for the current user (initial view).
			// The filters and date range in the UI are handled via the API (getViolations).
			$violations = $this->violationMapper->findByUser($userId, null);
			$violations = array_slice($violations, 0, 50);

			$violationsData = [];
			foreach ($violations as $violation) {
				$violationsData[] = [
					'id' => $violation->getId(),
					'type' => $violation->getViolationType(),
					'severity' => $violation->getSeverity(),
					'date' => $violation->getDate() ? $violation->getDate()->format('Y-m-d') : null,
					'resolved' => $violation->getResolved(),
					'description' => $violation->getDescription()
				];
			}

			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-violations', [
				'violations' => $violationsData,
				'total' => count($violations),
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-violations', [
				'violations' => [],
				'total' => 0,
				'urlGenerator' => $this->urlGenerator,
				'error' => $e->getMessage(),
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Compliance reports page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reports(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		// Add common CSS files (including colors, typography for consistent fonts)
		Util::addStyle('arbeitszeitcheck', 'common/colors');
		Util::addStyle('arbeitszeitcheck', 'common/typography');
		Util::addStyle('arbeitszeitcheck', 'common/base');
		Util::addStyle('arbeitszeitcheck', 'common/components');
		Util::addStyle('arbeitszeitcheck', 'common/layout');
		Util::addStyle('arbeitszeitcheck', 'common/utilities');
		Util::addStyle('arbeitszeitcheck', 'common/accessibility');
		Util::addStyle('arbeitszeitcheck', 'common/app-layout');
		Util::addStyle('arbeitszeitcheck', 'common/responsive');
		Util::addStyle('arbeitszeitcheck', 'navigation');
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');

		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'compliance-reports');

		try {
			$userId = $this->getUserId();

			// Get compliance summary for all recorded violations
			$violations = $this->violationMapper->findByUser($userId, null);

			$reportData = [
				'total_violations' => count($violations),
				'unresolved' => 0,
				'by_type' => [],
				'by_severity' => []
			];

			foreach ($violations as $violation) {
				if (!$violation->getResolved()) {
					$reportData['unresolved']++;
				}

				$type = $violation->getViolationType();
				$reportData['by_type'][$type] = ($reportData['by_type'][$type] ?? 0) + 1;

				$severity = $violation->getSeverity();
				$reportData['by_severity'][$severity] = ($reportData['by_severity'][$severity] ?? 0) + 1;
			}

			$response = new TemplateResponse(
				'arbeitszeitcheck',
				'compliance-reports',
				[
					'reportData' => $reportData,
					// No fixed date range; the page shows an all-time summary.
					'startDate' => null,
					'endDate' => null,
					'urlGenerator' => $this->urlGenerator,
					'l' => $this->l10n,
				]
			);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-reports', [
				'reportData' => [
					'total_violations' => 0,
					'unresolved' => 0,
					'by_type' => [],
					'by_severity' => []
				],
				'startDate' => date('Y-m-d', strtotime('-30 days')),
				'endDate' => date('Y-m-d'),
				'urlGenerator' => $this->urlGenerator,
				'error' => $e->getMessage(),
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		}
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
		?bool $resolved = null,
		?string $severity = null,
		?string $startDate = null,
		?string $endDate = null,
		?int $limit = 25,
		?int $offset = 0
	): JSONResponse {
		try {
			$currentUserId = $this->getUserId();
			$targetUserId = $userId ?? $currentUserId;
			$this->ensureCanAccessUserCompliance($currentUserId, $targetUserId);

			// Parse and validate date params (Y-m-d format)
			$startDt = $this->parseDateParam($startDate, 'start_date');
			$endDt = $this->parseDateParam($endDate, 'end_date');

			// Build filters
			$filters = [];
			if ($startDt) {
				$filters['start_date'] = $startDt;
			}
			if ($endDt) {
				$endDateTime = clone $endDt;
				$endDateTime->setTime(23, 59, 59);
				$endDateTime->modify('+1 day'); // Make exclusive for date range query
				$filters['end_date'] = $endDateTime;
			}

			// Get violations using mapper methods
			if ($startDt || $endDt) {
				$start = $startDt ?: new \DateTime('1970-01-01');
				$end = $endDt ? clone $endDt : new \DateTime('2099-12-31');
				$end->setTime(23, 59, 59);
				$end->modify('+1 day'); // Make exclusive for findByDateRange
				$allViolations = $this->violationMapper->findByDateRange($start, $end, $targetUserId, $resolved);
			} else {
				$allViolations = $this->violationMapper->findByUser($targetUserId, $resolved);
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
			$violations = array_slice($allViolations, $offset ?? 0, $limit ?? 25);

			// Safely map violations to summaries
			$violationSummaries = [];
			foreach ($violations as $violation) {
				try {
					$violationSummaries[] = $violation->getSummary();
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for violation ' . $violation->getId() . ': ' . $e->getMessage(), ["exception" => $e]);
					continue;
				}
			}

			return new JSONResponse([
				'success' => true,
				'violations' => $violationSummaries,
				'total' => $totalCount
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ComplianceController: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$status = strpos($e->getMessage(), 'Access denied') !== false ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
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
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Resolve a violation
	 *
	 * @param int $id Violation ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
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
					'error' => 'Violation is already resolved'
				], Http::STATUS_BAD_REQUEST);
			}

			$oldValues = $violation->getSummary();

			// Mark as resolved using mapper's resolveViolation method
			// Note: resolvedBy field is int, but Nextcloud user IDs are strings
			// We set it to a hash of the user ID for tracking purposes
			$resolvedByHash = abs(crc32($userId)) % PHP_INT_MAX; // Convert string to positive int
			$updatedViolation = $this->violationMapper->resolveViolation($id, $resolvedByHash);

			$newValues = $updatedViolation->getSummary();
			$newValues['resolved_by_user_id'] = $userId;
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
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ComplianceController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], Http::STATUS_BAD_REQUEST);
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
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Generate compliance report
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getReport(?string $startDate = null, ?string $endDate = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();

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

			$report = $this->complianceService->generateComplianceReport($start, $end, $userId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ComplianceController: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Manually trigger compliance check (admin only)
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
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
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], Http::STATUS_BAD_REQUEST);
		}
	}
}
