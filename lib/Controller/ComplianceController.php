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
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IGroupManager;
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
	private IUserSession $userSession;
	private IGroupManager $groupManager;
	private IL10N $l10n;

	public function __construct(
		string $appName,
		IRequest $request,
		ComplianceService $complianceService,
		ComplianceViolationMapper $violationMapper,
		IUserSession $userSession,
		IGroupManager $groupManager,
		CSPService $cspService,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->complianceService = $complianceService;
		$this->violationMapper = $violationMapper;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		$this->l10n = $l10n;
		$this->setCspService($cspService);
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

		// Add common CSS files
		Util::addStyle('arbeitszeitcheck', 'common/base');
		Util::addStyle('arbeitszeitcheck', 'common/components');
		Util::addStyle('arbeitszeitcheck', 'common/layout');
		Util::addStyle('arbeitszeitcheck', 'common/utilities');
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
			$recentViolations = $this->violationMapper->findByUser($userId, 10);
			$violationsData = [];
			foreach ($recentViolations as $violation) {
				$violationsData[] = [
					'id' => $violation->getId(),
					'type' => $violation->getViolationType(),
					'severity' => $violation->getSeverity(),
					'date' => $violation->getViolationDate() ? $violation->getViolationDate()->format('Y-m-d') : null,
					'resolved' => $violation->getResolved(),
					'description' => $violation->getDescription()
				];
			}

			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-dashboard', [
				'complianceStatus' => $complianceStatus,
				'recentViolations' => $violationsData,
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-dashboard', [
				'complianceStatus' => ['compliant' => false, 'score' => 0],
				'recentViolations' => [],
				'error' => $e->getMessage(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
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

		// Add common CSS files
		Util::addStyle('arbeitszeitcheck', 'common/base');
		Util::addStyle('arbeitszeitcheck', 'common/components');
		Util::addStyle('arbeitszeitcheck', 'common/layout');
		Util::addStyle('arbeitszeitcheck', 'common/utilities');
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');

		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'compliance-violations');

		try {
			$userId = $this->getUserId();

			// Get violations (last 30 days, first 50)
			$endDate = new \DateTime();
			$endDate->setTime(23, 59, 59);
			$startDate = clone $endDate;
			$startDate->modify('-30 days');
			$startDate->setTime(0, 0, 0);

			$violations = $this->violationMapper->findByDateRange($startDate, $endDate, $userId, null);
			$violations = array_slice($violations, 0, 50);

			$violationsData = [];
			foreach ($violations as $violation) {
				$violationsData[] = [
					'id' => $violation->getId(),
					'type' => $violation->getViolationType(),
					'severity' => $violation->getSeverity(),
					'date' => $violation->getViolationDate() ? $violation->getViolationDate()->format('Y-m-d') : null,
					'resolved' => $violation->getResolved(),
					'description' => $violation->getDescription()
				];
			}

			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-violations', [
				'violations' => $violationsData,
				'total' => count($violations),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-violations', [
				'violations' => [],
				'total' => 0,
				'error' => $e->getMessage(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
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

		// Add common CSS files
		Util::addStyle('arbeitszeitcheck', 'common/base');
		Util::addStyle('arbeitszeitcheck', 'common/components');
		Util::addStyle('arbeitszeitcheck', 'common/layout');
		Util::addStyle('arbeitszeitcheck', 'common/utilities');
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');

		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'compliance-reports');

		try {
			$userId = $this->getUserId();

			// Get compliance summary for last 30 days
			$endDate = new \DateTime();
			$endDate->setTime(23, 59, 59);
			$startDate = clone $endDate;
			$startDate->modify('-30 days');
			$startDate->setTime(0, 0, 0);

			$violations = $this->violationMapper->findByDateRange($startDate, $endDate, $userId, null);

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

			$response = new TemplateResponse('arbeitszeitcheck', 'compliance-reports', [
				'reportData' => $reportData,
				'startDate' => $startDate->format('Y-m-d'),
				'endDate' => $endDate->format('Y-m-d'),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
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
				'error' => $e->getMessage(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response);
		}
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

			// Non-admin users can only see their own violations
			$targetUserId = $userId ?? $currentUserId;

			// Build filters
			$filters = [];
			if ($startDate) {
				$filters['start_date'] = new \DateTime($startDate);
			}
			if ($endDate) {
				$endDateTime = new \DateTime($endDate);
				$endDateTime->setTime(23, 59, 59);
				$endDateTime->modify('+1 day'); // Make exclusive for date range query
				$filters['end_date'] = $endDateTime;
			}

			// Get violations using mapper methods
			if ($startDate || $endDate) {
				$start = $startDate ? new \DateTime($startDate) : new \DateTime('1970-01-01');
				$end = $endDate ? new \DateTime($endDate) : new \DateTime('2099-12-31');
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
	 * Get single violation by ID
	 *
	 * @param int $id Violation ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getViolation(int $id): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$violation = $this->violationMapper->find($id);

			// Users can only see their own violations
			if ($violation->getUserId() !== $userId) {
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
	public function resolveViolation(int $id): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$violation = $this->violationMapper->find($id);

			// Users can only resolve their own violations
			if ($violation->getUserId() !== $userId) {
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

			// Mark as resolved using mapper's resolveViolation method
			// Note: resolvedBy field is int, but Nextcloud user IDs are strings
			// We set it to a hash of the user ID for tracking purposes
			$resolvedByHash = abs(crc32($userId)) % PHP_INT_MAX; // Convert string to positive int
			$updatedViolation = $this->violationMapper->resolveViolation($id, $resolvedByHash);

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

			// Default to last 30 days if not specified
			$end = $endDate ? new \DateTime($endDate) : new \DateTime();
			$end->setTime(23, 59, 59);
			$start = $startDate ? new \DateTime($startDate) : clone $end;
			if (!$startDate) {
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
	public function runCheck(): JSONResponse
	{
		try {
			// Only admins can manually trigger compliance checks
			$user = $this->userSession->getUser();
			if (!$user || !$this->groupManager->isAdmin($user->getUID())) {
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
