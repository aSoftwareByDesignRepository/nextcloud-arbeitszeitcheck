<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Repair;

use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class InitializeWorkforceSuiteFreshState implements IRepairStep
{
	public function __construct(private readonly WorkforceSuiteConfig $config)
	{
	}

	public function getName(): string
	{
		return 'Initialize Workforce Policy Suite defaults for a fresh installation';
	}

	public function run(IOutput $output): void
	{
		$this->config->initialize(WorkforceSuiteConfig::STATE_FRESH);
		$output->info('ArbeitszeitCheck: Workforce Policy Suite fresh-install defaults initialized.');
	}
}
