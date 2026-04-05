<?php

declare(strict_types=1);

/**
 * Re-sync all approved absences to the Nextcloud Calendar (recovery / migration).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Command;

use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceCalendarSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CalendarSyncBackfillCommand extends Command
{
	public function __construct(
		private AbsenceMapper $absenceMapper,
		private AbsenceCalendarSyncService $absenceCalendarSyncService,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('arbeitszeitcheck:calendar-sync-backfill')
			->setDescription('Write all approved absences to the ArbeitszeitCheck Nextcloud calendars (idempotent).');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$batch = 200;
		$offset = 0;
		$total = 0;
		do {
			$rows = $this->absenceMapper->findApprovedBatch($batch, $offset);
			foreach ($rows as $absence) {
				try {
					$this->absenceCalendarSyncService->syncApprovedAbsence($absence);
					$total++;
				} catch (\Throwable $e) {
					$io->warning('Absence ' . $absence->getId() . ': ' . $e->getMessage());
				}
			}
			$offset += $batch;
		} while (\count($rows) === $batch);

		$io->success('Processed ' . $total . ' approved absence(s).');
		return Command::SUCCESS;
	}
}
