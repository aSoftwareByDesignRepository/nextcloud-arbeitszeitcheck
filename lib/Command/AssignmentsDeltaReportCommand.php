<?php

declare(strict_types=1);

/**
 * MH-18: inventory + expected Soll delta for the effective-dated
 * assignment rollout. Lists every user whose assignment shape the
 * segmented resolver treats differently than the legacy uniform read:
 * multiple rows, coverage gaps, overlaps, only-ended, future-dated,
 * malformed rows — plus the expected required-hours delta over a
 * reference range so support can prove balance changes before they are
 * reported (D-03).
 */

namespace OCA\ArbeitszeitCheck\Command;

use OCA\ArbeitszeitCheck\Service\AssignmentDeltaReportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class AssignmentsDeltaReportCommand extends Command
{
	public function __construct(
		private readonly AssignmentDeltaReportService $report,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('arbeitszeitcheck:assignments:delta-report')
			->setDescription('List users whose working-time assignments change meaning under the effective-dated resolver, with expected Soll delta.')
			->addOption('from', null, InputOption::VALUE_REQUIRED, 'Delta range start Y-m-d (default: first day of current month)')
			->addOption('to', null, InputOption::VALUE_REQUIRED, 'Delta range end Y-m-d (default: today)')
			->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$from = $this->parseDate($input->getOption('from')) ?? new \DateTime('first day of this month');
		$to = $this->parseDate($input->getOption('to')) ?? new \DateTime('today');
		if ($to < $from) {
			$io->error('--to must not be before --from');
			return 2;
		}

		$data = $this->report->collect($from, $to);

		if ($input->getOption('json')) {
			$output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			return 0;
		}

		$io->title('Working-time assignment delta report');
		$io->writeln(sprintf(
			'Range %s → %s · gap policy: %s',
			$data['range']['from'],
			$data['range']['to'],
			$data['gap_policy'],
		));

		$rows = [];
		foreach ($data['users'] as $user) {
			$delta = $user['soll_delta'];
			$rows[] = [
				$user['user_id'],
				(string)$user['row_count'],
				implode(', ', $user['flags']),
				$delta === null
					? '—'
					: sprintf('%+.2fh (%s → %s)', $delta['delta_hours'], $delta['legacy_hours'], $delta['segmented_hours']),
				$user['malformed'] === null
					? ''
					: implode('; ', array_map(static fn (array $m): string => "#{$m['id']}: {$m['reason']}", $user['malformed'])),
			];
		}
		$io->table(['User', 'Rows', 'Flags', 'Soll delta (legacy → segmented)', 'Malformed'], $rows);

		$summary = $data['summary'];
		$io->writeln(sprintf(
			'%d users scanned, %d flagged · gaps:%d multi:%d overlap:%d ended:%d future:%d malformed:%d delta:%d',
			$summary['users_scanned'],
			$summary['users_flagged'],
			$summary['coverage_gap'],
			$summary['multiple_rows'],
			$summary['overlapping_rows'],
			$summary['only_ended'],
			$summary['future_dated'],
			$summary['malformed_rows'],
			$summary['soll_delta'],
		));
		return 0;
	}

	private function parseDate(mixed $value): ?\DateTime
	{
		if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
			return null;
		}
		$dt = \DateTime::createFromFormat('!Y-m-d', $value);
		return ($dt !== false && $dt->format('Y-m-d') === $value) ? $dt : null;
	}
}
