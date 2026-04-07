<?php

declare(strict_types=1);

/**
 * Manual vacation carryover rollover (same logic as background job).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Command;

use OCA\ArbeitszeitCheck\Service\VacationRolloverService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class VacationRolloverCommand extends Command
{
	public function __construct(
		private VacationRolloverService $vacationRolloverService,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('arbeitszeitcheck:vacation-rollover')
			->setDescription('Apply vacation carryover rollover into the next calendar year (after deadline).')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be applied without writing.')
			->addOption('force', null, InputOption::VALUE_NONE, 'Ignore idempotency log and non-empty target balance (overwrites opening for target year).')
			->addOption('ignore-disabled', null, InputOption::VALUE_NONE, 'Run even if automatic rollover is disabled in admin settings.')
			->addOption('year', null, InputOption::VALUE_REQUIRED, 'Only process rollover from this calendar year (source year).')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Only process this user id.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$dryRun = (bool)$input->getOption('dry-run');
		$force = (bool)$input->getOption('force');
		/** When true, run even if automatic rollover is disabled in admin (same as ignoreEnabledCheck in service). */
		$ignoreEnabledCheck = (bool)$input->getOption('ignore-disabled');
		$yearOpt = $input->getOption('year');
		$onlyFromYear = $yearOpt !== null && $yearOpt !== '' ? (int)$yearOpt : null;
		if ($onlyFromYear !== null && ($onlyFromYear < 1990 || $onlyFromYear > 2100)) {
			$io->error('Invalid --year');
			return Command::FAILURE;
		}
		$userOpt = $input->getOption('user');
		$userId = is_string($userOpt) && $userOpt !== '' ? $userOpt : null;

		if ($userId !== null) {
			$stats = $this->vacationRolloverService->runForSingleUser($userId, $onlyFromYear, $dryRun, $force, $ignoreEnabledCheck);
		} else {
			$stats = $this->vacationRolloverService->runForAllUsers($onlyFromYear, $dryRun, $force, $ignoreEnabledCheck);
		}

		$io->success(sprintf(
			'Rollover finished: applied/would_apply=%d, skipped=%d, errors=%d',
			$stats['applied'],
			$stats['skipped'],
			$stats['errors']
		));

		return Command::SUCCESS;
	}
}
