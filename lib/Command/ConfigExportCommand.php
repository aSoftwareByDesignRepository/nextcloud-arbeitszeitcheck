<?php

declare(strict_types=1);

/**
 * `occ arbeitszeitcheck:config:export` — produce the encrypted transfer container.
 * Byte-identical format to the UI export (same TransferService pipeline).
 * Passphrase: interactive hidden prompt, --passphrase, or TRANSFER_PASSPHRASE.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\Command;

use OCA\ArbeitszeitCheck\ConfigTransfer\TransferException;
use OCA\ArbeitszeitCheck\ConfigTransfer\TransferService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfigExportCommand extends Command
{
	use TransferCommandPassphrase;

	public function __construct(
		private readonly TransferService $transfer,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('arbeitszeitcheck:config:export')
			->setDescription('Export ArbeitszeitCheck configuration + data as one passphrase-encrypted transfer container.')
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the container to this file (default: stdout)')
			->addOption('passphrase', null, InputOption::VALUE_REQUIRED, 'Container passphrase (or TRANSFER_PASSPHRASE env, or interactive prompt)')
			->addOption('include-secrets', null, InputOption::VALUE_NONE, 'Include secret values inside the encrypted container (default: redact)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$passphrase = $this->resolvePassphrase($input, $io, true);
		if ($passphrase === null) {
			return Command::FAILURE;
		}
		try {
			$bytes = $this->transfer->exportContainer($passphrase, (bool)$input->getOption('include-secrets'));
		} catch (TransferException $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}
		$out = (string)($input->getOption('output') ?? '');
		if ($out === '') {
			$output->write($bytes);
			return Command::SUCCESS;
		}
		if (file_put_contents($out, $bytes) === false) {
			$io->error('Cannot write to ' . $out);
			return Command::FAILURE;
		}
		$io->success('Transfer container written to ' . $out);
		return Command::SUCCESS;
	}
}
