<?php

declare(strict_types=1);

/**
 * Daily compliance check background job for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily compliance check job
 *
 * Runs daily to check all users for compliance violations
 */
class DailyComplianceCheckJob extends TimedJob
{
	private ComplianceService $complianceService;
	private IConfig $config;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $timeFactory,
		ComplianceService $complianceService,
		IConfig $config,
		LoggerInterface $logger
	) {
		parent::__construct($timeFactory);
		$this->complianceService = $complianceService;
		$this->config = $config;
		$this->logger = $logger;

		// Run daily at 2 AM
		$this->setInterval(24 * 60 * 60);
	}

	/**
	 * @inheritDoc
	 */
	protected function run($argument): void
	{
		// Check if auto compliance check is enabled
		$autoComplianceCheck = $this->config->getAppValue('arbeitszeitcheck', 'auto_compliance_check', '1') === '1';
		
		if (!$autoComplianceCheck) {
			$this->logger->info('Daily compliance check skipped (disabled in settings)');
			return;
		}

		$this->logger->info('Starting daily compliance check');

		try {
			$stats = $this->complianceService->runDailyComplianceCheck();

			$this->logger->info('Daily compliance check completed', [
				'users_checked' => $stats['users_checked'],
				'violations_found' => $stats['violations_found'],
				'check_date' => $stats['check_date']
			]);
		} catch (\Exception $e) {
			$this->logger->error('Daily compliance check failed', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
		}
	}
}
