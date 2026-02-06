<?php

declare(strict_types=1);

/**
 * Health controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IL10N;

/**
 * HealthController
 */
class HealthController extends Controller
{
	private ComplianceService $complianceService;
	private ProjectCheckIntegrationService $projectCheckService;
	private IL10N $l10n;

	public function __construct(
		string $appName,
		IRequest $request,
		ComplianceService $complianceService,
		ProjectCheckIntegrationService $projectCheckService,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->complianceService = $complianceService;
		$this->projectCheckService = $projectCheckService;
		$this->l10n = $l10n;
	}

	/**
	 * Health check endpoint
	 *
	 * @return JSONResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function check(): JSONResponse
	{
		try {
			$health = [
				'status' => 'healthy',
				'timestamp' => time(),
				'services' => [
					'database' => $this->checkDatabase(),
					'compliance' => $this->checkCompliance(),
					'projectcheck_integration' => $this->checkProjectCheckIntegration()
				],
				'version' => '1.0.0',
				'nextcloud_version' => \OCP\Server::get(\OCP\ServerVersion::class)->getVersionString()
			];

			// Determine overall status
			$hasErrors = array_filter($health['services'], fn($service) => $service['status'] !== 'healthy');
			if (!empty($hasErrors)) {
				$health['status'] = 'degraded';
			}

			return new JSONResponse($health);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'status' => 'unhealthy',
				'timestamp' => time(),
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Check database connectivity
	 *
	 * @return array
	 */
	private function checkDatabase(): array
	{
		try {
			$db = \OC::$server->getDatabaseConnection();

			// Test basic query
			$query = $db->getQueryBuilder();
			$query->select('1');
			$result = $query->executeQuery()->fetchOne();

			if ($result === '1') {
				return [
					'status' => 'healthy',
					'message' => $this->l10n->t('Database connection successful')
				];
			} else {
				return [
					'status' => 'unhealthy',
					'message' => $this->l10n->t('Database query failed')
				];
			}
		} catch (\Throwable $e) {
			return [
				'status' => 'unhealthy',
				'message' => $this->l10n->t('Database error: %s', [$e->getMessage()])
			];
		}
	}

	/**
	 * Check compliance service
	 *
	 * @return array
	 */
	private function checkCompliance(): array
	{
		try {
			// Test compliance service by checking German holiday calculation
			$testDate = new \DateTime('2024-12-25'); // Christmas Day
			$isHoliday = $this->complianceService->isGermanPublicHoliday($testDate, 'NW');

		if ($isHoliday) {
			return [
				'status' => 'healthy',
				'message' => $this->l10n->t('Compliance service working correctly')
			];
		} else {
			return [
				'status' => 'unhealthy',
				'message' => $this->l10n->t('Compliance service holiday check failed')
			];
		}
	} catch (\Throwable $e) {
		return [
			'status' => 'unhealthy',
			'message' => $this->l10n->t('Compliance service error: %s', [$e->getMessage()])
		];
	}
	}

	/**
	 * Check ProjectCheck integration
	 *
	 * @return array
	 */
	private function checkProjectCheckIntegration(): array
	{
		try {
			$isAvailable = $this->projectCheckService->isProjectCheckAvailable();

			return [
				'status' => 'healthy',
				'message' => $isAvailable ? $this->l10n->t('ProjectCheck integration available') : $this->l10n->t('ProjectCheck not installed'),
				'available' => $isAvailable
			];
		} catch (\Throwable $e) {
			return [
				'status' => 'unhealthy',
				'message' => $this->l10n->t('ProjectCheck integration error: %s', [$e->getMessage()])
			];
		}
	}
}