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
use OCA\ArbeitszeitCheck\Support\SchemaHealth;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IL10N;

/**
 * HealthController
 */
class HealthController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ComplianceService $complianceService,
		private readonly ProjectCheckIntegrationService $projectCheckService,
		private readonly IDBConnection $db,
		private readonly IL10N $l10n,
	) {
		parent::__construct($appName, $request);
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
				]
			];

			// Determine overall status
			$hasErrors = array_filter($health['services'], fn($service) => $service['status'] !== 'healthy');
			if (!empty($hasErrors)) {
				$health['status'] = 'degraded';
			}

			return new JSONResponse($health);
		} catch (\Throwable $e) {
			// Do not expose exception message to unauthenticated users (PublicPage).
			// Log internally; return generic message for health checks (load balancers, monitoring).
			\OCP\Log\logger('arbeitszeitcheck')->error('HealthController::check failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'status' => 'unhealthy',
				'timestamp' => time(),
				'error' => $this->l10n->t('Service unavailable')
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
			$schema = SchemaHealth::assess($this->db);
			if (!$schema['ready']) {
				return [
					'status' => 'unhealthy',
					'message' => $this->l10n->n(
						'Database schema incomplete (%n missing table)',
						'Database schema incomplete (%n missing tables)',
						$schema['missing_count'],
					),
					'missing_tables' => $schema['missing_count'],
				];
			}

			// Execute a minimal DB round-trip that works across supported drivers.
			$result = $this->db->executeQuery('SELECT 1')->fetchOne();

			if ((string)$result === '1') {
				return [
					'status' => 'healthy',
					'message' => $this->l10n->t('Database connection successful'),
				];
			}

			return [
				'status' => 'unhealthy',
				'message' => $this->l10n->t('Database query failed'),
			];
		} catch (\Throwable $e) {
			// Do not expose raw exception to PublicPage health endpoint
			return [
				'status' => 'unhealthy',
				'message' => $this->l10n->t('Database connection failed'),
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
		// Do not expose raw exception to PublicPage health endpoint
		return [
			'status' => 'unhealthy',
			'message' => $this->l10n->t('Compliance service check failed')
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
			$linkingEnabled = $this->projectCheckService->isAdminIntegrationEnabled();

			if (!$isAvailable) {
				return [
					'status' => 'healthy',
					'message' => $this->l10n->t('ProjectCheck not installed'),
					'available' => false,
					'linkingEnabled' => false,
				];
			}

			return [
				'status' => 'healthy',
				'message' => $linkingEnabled
					? $this->l10n->t('ProjectCheck integration available')
					: $this->l10n->t('ProjectCheck linking is turned off'),
				'available' => true,
				'linkingEnabled' => $linkingEnabled,
			];
		} catch (\Throwable $e) {
			// Do not expose raw exception to PublicPage health endpoint
			return [
				'status' => 'unhealthy',
				'message' => $this->l10n->t('ProjectCheck integration check failed')
			];
		}
	}
}