<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Command;

use OCA\ArbeitszeitCheck\Service\HolidayAdminService;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class VerifyHolidaysCommand extends Command
{
	public function __construct(
		private readonly HolidayAdminService $holidayAdminService,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('arbeitszeitcheck:holidays:verify')
			->setDescription('Compare statutory catalog, DB rows, and suppressions for a region/year.')
			->addArgument('state', InputArgument::REQUIRED, 'Region code (e.g. NW, BB, AT-W, AT-OOE, CH-ZH, CH-GE)')
			->addArgument('year', InputArgument::REQUIRED, 'Calendar year (e.g. 2026)')
			->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON')
			->setHelp(
				"Compare the statutory holiday catalog for a DACH region/year with the database\n" .
				"and any admin suppressions.\n\n" .
				"Examples:\n" .
				"  php occ arbeitszeitcheck:holidays:verify NW 2026\n" .
				"  php occ arbeitszeitcheck:holidays:verify AT-OOE 2027\n" .
				"  php occ arbeitszeitcheck:holidays:verify CH-ZH 2026 --json"
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$state = strtoupper(trim((string)$input->getArgument('state')));
		$year = (int)$input->getArgument('year');

		if (!RegionRegistry::isValidRegion($state)) {
			$io->error(sprintf(
				'Unknown region code "%s". Valid codes: %s.',
				$state,
				implode(', ', RegionRegistry::allRegionCodes())
			));
			return Command::FAILURE;
		}
		if ($year < 1970 || $year > 2100) {
			$io->error('Year must be between 1970 and 2100.');
			return Command::FAILURE;
		}

		$report = $this->holidayAdminService->verifyStateYear($state, $year);

		if ($input->getOption('json')) {
			$output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			return ($report['ok'] ?? false) ? Command::SUCCESS : Command::FAILURE;
		}

		$io->title(sprintf('Holiday verify: %s %d', $state, $year));
		$io->definitionList(
			['Auto-restore statutory' => ($report['statutoryAutoReseed'] ?? false) ? 'enabled' : 'disabled'],
			['Catalog entries' => (string)($report['catalogCount'] ?? 0)],
			['Active statutory in DB' => (string)($report['activeStatutoryCount'] ?? 0)],
			['Suppressed dates' => (string)count($report['suppressedDates'] ?? [])],
		);

		if (!empty($report['suppressedDates'])) {
			$io->section('Suppressed dates');
			$io->listing($report['suppressedDates']);
		}

		if (!empty($report['missingInDb'])) {
			$io->warning('Catalog dates missing in DB (not suppressed):');
			foreach ($report['missingInDb'] as $date => $name) {
				$io->writeln(sprintf('  %s — %s', $date, $name));
			}
		}

		if (!empty($report['rules'])) {
			$io->section('Recurring rules');
			foreach ($report['rules'] as $rule) {
				$skipped = $rule['skipped_years'] ?? [];
				$io->writeln(sprintf(
					'  %s [%s, %s] resolves %s%s%s',
					(string)($rule['name'] ?? ''),
					(string)($rule['rule_key'] ?? ''),
					(string)($rule['scope'] ?? ''),
					(string)($rule['resolved_date'] ?? 'n/a'),
					($rule['active'] ?? true) ? '' : ' — inactive',
					$skipped !== [] ? ' — skipped years: ' . implode(', ', array_map('strval', $skipped)) : ''
				));
			}
		}

		if (!empty($report['extraInDb'])) {
			$io->warning('Statutory rows in DB not in catalog for this region (wrong region rules or legacy seed):');
			foreach ($report['extraInDb'] as $date => $name) {
				$io->writeln(sprintf('  %s — %s', $date, $name));
			}
			if ($report['statutoryAutoReseed'] ?? false) {
				$io->writeln('Enable auto-restore (default) and open Administration → Holidays for this state/year, or run a working-day request, to prune generated extras.');
			} else {
				$io->writeln('Remove obsolete statutory rows manually or enable auto-restore statutory holidays in settings.');
			}
		}

		if ($report['ok'] ?? false) {
			$io->success('No gaps between catalog and active DB (respecting suppressions).');
			return Command::SUCCESS;
		}

		$io->error('Gaps detected — run with --json for full report or enable auto-restore / re-seed.');
		return Command::FAILURE;
	}
}
