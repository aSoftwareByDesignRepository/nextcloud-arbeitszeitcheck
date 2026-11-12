<?php

declare(strict_types=1);

/**
 * `occ arbeitszeitcheck:config:import` — validate → mandatory dry-run report →
 * optional --apply (replace additionally needs --confirm-replace). Same
 * TransferService pipeline as the web path — no second implementation.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\Command;

use OCA\ArbeitszeitCheck\ConfigTransfer\TransferException;
use OCA\ArbeitszeitCheck\ConfigTransfer\TransferService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfigImportCommand extends Command
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
			->setName('arbeitszeitcheck:config:import')
			->setDescription('Validate a transfer container, print the dry-run report, optionally apply it. Raw `occ config:import` bypasses validation and mirror reconcile — do not use it; see arbeitszeitcheck:config:reconcile to heal.')
			->addArgument('file', InputArgument::REQUIRED, 'Path to the .nctransfer container')
			->addOption('passphrase', null, InputOption::VALUE_REQUIRED, 'Container passphrase (or TRANSFER_PASSPHRASE env, or interactive prompt)')
			->addOption('mode', null, InputOption::VALUE_REQUIRED, 'merge (default) or replace', TransferService::MODE_MERGE)
			->addOption('apply', null, InputOption::VALUE_NONE, 'Apply after the dry run (synchronous, same atomic path as the web job)')
			->addOption('confirm-replace', null, InputOption::VALUE_NONE, 'Required with --mode=replace: confirms prod-only rows may be deleted')
			->addOption('output', null, InputOption::VALUE_REQUIRED, 'text (default) or json for automation')
			->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor uid recorded in the receipt', '');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$file = (string)$input->getArgument('file');
		$mode = (string)$input->getOption('mode');
		$jsonOut = $input->getOption('output') === 'json';
		if (!is_file($file) || !is_readable($file)) {
			$this->emit($io, $output, $jsonOut, ['ok' => false, 'error' => 'FILE_UNREADABLE']);
			return Command::FAILURE;
		}
		$bytes = (string)file_get_contents($file);
		$passphrase = $this->resolvePassphrase($input, $io, false);
		if ($passphrase === null) {
			return Command::FAILURE;
		}
		$actor = (string)$input->getOption('actor');
		try {
			$result = $this->transfer->planForFile($bytes, $passphrase, $mode, $actor);
		} catch (TransferException $e) {
			$this->emit($io, $output, $jsonOut, ['ok' => false, 'error' => $e->isFileError() ? 'TRANSFER_CANNOT_READ_FILE' : $e->getMessage(), 'context' => $e->getContext()]);
			return Command::FAILURE;
		}

		$report = $result['report'];
		if (!$jsonOut) {
			$this->printReport($io, $report);
		}
		if (!$input->getOption('apply')) {
			$this->emit($io, $output, $jsonOut, ['ok' => true, 'applied' => false, 'report' => $report]);
			return Command::SUCCESS;
		}
		if ($mode === TransferService::MODE_REPLACE && !$input->getOption('confirm-replace')) {
			$this->emit($io, $output, $jsonOut, ['ok' => false, 'error' => 'TRANSFER_CONFIRM_REQUIRED']);
			return Command::FAILURE;
		}
		try {
			$receipt = $this->transfer->applyPayload(
				$result['payload'], $result['header'], $mode, $actor,
				$mode === TransferService::MODE_REPLACE,
			);
		} catch (TransferException $e) {
			$this->emit($io, $output, $jsonOut, ['ok' => false, 'error' => $e->getMessage(), 'context' => $e->getContext()]);
			return Command::FAILURE;
		}
		$this->emit($io, $output, $jsonOut, ['ok' => true, 'applied' => true, 'receipt' => $receipt]);
		return Command::SUCCESS;
	}

	private function printReport(SymfonyStyle $io, array $report): void
	{
		$io->title('ArbeitszeitCheck — transfer dry run (' . ($report['mode'] ?? 'merge') . ')');
		$file = $report['file'] ?? [];
		$io->text(sprintf(
			'Exported from %s at %s (app %s, schema %s)',
			$file['source_instance'] ?? '?', $file['exported_at'] ?? '?',
			$file['app_version'] ?? '?', $file['schema_version'] ?? '?',
		));
		foreach ($report['warnings'] ?? [] as $w) {
			$io->warning(($w['code'] ?? '') . ' ' . ($w['detail'] ?? ''));
		}
		if (($report['security'] ?? []) !== []) {
			$io->section('Security-relevant changes');
			foreach ($report['security'] as $s) {
				$io->text('  • ' . ($s['key'] ?? '') . ' — ' . ($s['detail'] ?? ''));
			}
		}
		if (($report['unresolved'] ?? []) !== []) {
			$io->section('Unresolved references');
			foreach ($report['unresolved'] as $u) {
				$io->text(sprintf('  • %s %s (%s) → %s%s',
					$u['type'] ?? '', $u['value'] ?? '', $u['scope'] ?? '',
					$u['action'] ?? '', isset($u['table']) ? ' [' . $u['table'] . ']' : ''));
			}
		}
		$c = $report['config'] ?? [];
		$io->section('Config');
		$io->text(sprintf('added %d / changed %d / unchanged %d / skipped %d / invalid %d / unknown %d',
			count($c['added'] ?? []), count($c['changed'] ?? []), count($c['unchanged'] ?? []),
			count($c['skipped'] ?? []), count($c['invalid'] ?? []), count($c['unknown'] ?? [])));
		$io->section('Tables');
		foreach (($report['tables'] ?? []) as $table => $t) {
			$io->text(sprintf('  %s: +%d ~%d -%d skip%d%s',
				$table, $t['insert'] ?? 0, $t['update'] ?? 0, $t['delete'] ?? 0, $t['skip'] ?? 0,
				($t['skipped'] ?? false) ? ' (skipped: ' . ($t['skipReason'] ?? '') . ')' : ''));
		}
		if (($report['secrets'] ?? []) !== []) {
			$io->note(count($report['secrets']) . ' secret key(s) were not transferred — set them on this instance.');
		}
	}

	private function emit(SymfonyStyle $io, OutputInterface $output, bool $json, array $payload): void
	{
		if ($json) {
			$output->writeln(json_encode($payload, JSON_UNESCAPED_UNICODE));
			return;
		}
		if (($payload['ok'] ?? false) === false) {
			$io->error((string)($payload['error'] ?? 'FAILED'));
			return;
		}
		$io->success(($payload['applied'] ?? false) ? 'Import applied.' : 'Dry run complete — re-run with --apply to write.');
	}
}
