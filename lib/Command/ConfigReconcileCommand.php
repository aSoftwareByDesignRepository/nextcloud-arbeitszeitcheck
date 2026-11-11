<?php

declare(strict_types=1);

/**
 * `occ arbeitszeitcheck:config:reconcile` — re-write mirrored keys through the
 * app's central permission writer. This heals a raw `occ config:import`
 * (which bypasses all validation and never reconciles the platform mirror).
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\Command;

use OCA\ArbeitszeitCheck\ConfigTransfer\TransferException;
use OCA\ArbeitszeitCheck\ConfigTransfer\TransferService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfigReconcileCommand extends Command
{
	public function __construct(
		private readonly TransferService $transfer,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('arbeitszeitcheck:config:reconcile')
			->setDescription('Re-run the mirrored-key writer (access door / permission mirror) with the stored values. Use after a raw occ config:import.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		try {
			$result = $this->transfer->reconcile();
		} catch (TransferException $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}
		$io->success('Mirrored keys reconciled (' . (int)($result['mirroredKeys'] ?? 0) . ' key(s)).');
		return Command::SUCCESS;
	}
}
