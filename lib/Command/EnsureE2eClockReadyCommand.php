<?php

declare(strict_types=1);

/**
 * Idempotent dev/E2E helper: ensure clock-in is not blocked by ArbZG §5 rest for test users.
 *
 * Only affects UIDs matching `e2e_` (case-sensitive prefix). Requires --force in CI.
 * Backdates the most recent completed entry end time when it falls inside the rest window.
 * Not for production operator use on real employee accounts.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Command;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\IConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class EnsureE2eClockReadyCommand extends Command
{
	public function __construct(
		private IUserManager $userManager,
		private TimeEntryMapper $timeEntryMapper,
		private TimeTrackingService $timeTrackingService,
		private IConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('arbeitszeitcheck:ensure-e2e-clock')
			->setDescription('E2E/dev only: clear active session and satisfy rest period for e2e_* users.')
			->addArgument('user_id', InputArgument::REQUIRED, 'Nextcloud user id (must start with e2e_)')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Required for non-interactive runs (CI/E2E).');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		if (!$input->getOption('force')) {
			if (!$input->isInteractive()) {
				$io->error('Non-interactive runs require --force.');
				return Command::FAILURE;
			}
			if (!$io->confirm('This adjusts time entries for E2E test users only. Continue?', false)) {
				return Command::SUCCESS;
			}
		}

		$userId = trim((string)$input->getArgument('user_id'));
		if ($userId === '' || !str_starts_with($userId, 'e2e_')) {
			$io->error('user_id must start with e2e_ (refusing to modify non-test accounts).');
			return Command::FAILURE;
		}
		if (!$this->userManager->userExists($userId)) {
			$io->error('User does not exist: ' . $userId);
			return Command::FAILURE;
		}

		$active = $this->timeEntryMapper->findActiveByUser($userId);
		if ($active !== null) {
			$this->timeTrackingService->clockOut($userId);
			$io->writeln('Clocked out active session.');
		}
		$break = $this->timeEntryMapper->findOnBreakByUser($userId);
		if ($break !== null) {
			$this->timeTrackingService->endBreak($userId);
			$this->timeTrackingService->clockOut($userId);
			$io->writeln('Ended break and clocked out.');
		}

		$minRestHours = (float)$this->config->getAppValue('arbeitszeitcheck', 'min_rest_period', '11');
		$minRestHours = max(1.0, min(24.0, $minRestHours));
		$bufferHours = $minRestHours + 1.0;

		// Backdate every completed entry whose end still sits inside the rest
		// window — a single-shot fix is not enough when e.g. a split shift left
		// two recent ends. Use findLastCompletedBeforeTime so the loop tracks the
		// same reference the clock-in check uses (future-dated ends excluded).
		for ($i = 0; $i < 20; $i++) {
			$now = new \DateTime();
			$last = $this->timeEntryMapper->findLastCompletedBeforeTime($userId, $now);
			if ($last === null || $last->getEndTime() === null) {
				$io->writeln('No completed entry to adjust; clock-in should be allowed.');
				break;
			}

			$end = $last->getEndTime();
			$hoursSinceEnd = ($now->getTimestamp() - $end->getTimestamp()) / 3600;
			if ($hoursSinceEnd >= $minRestHours) {
				$io->writeln(sprintf('Last shift ended %.1f h ago (rest %.0f h satisfied).', $hoursSinceEnd, $minRestHours));
				break;
			}

			$newEnd = (clone $now)->modify(sprintf('-%.0f hours', (int)ceil($bufferHours)));
			$last->setEndTime($newEnd);
			$last->setUpdatedAt($now);
			$this->timeEntryMapper->update($last);
			$io->writeln(sprintf(
				'Backdated entry #%d end to %s (was within %.1f h rest window).',
				$last->getId(),
				$newEnd->format('Y-m-d H:i:s'),
				$hoursSinceEnd,
			));
		}

		$now = new \DateTime();
		$last = $this->timeEntryMapper->findLastCompletedBeforeTime($userId, $now);
		if ($last !== null && $last->getEndTime() !== null
			&& ($now->getTimestamp() - $last->getEndTime()->getTimestamp()) / 3600 < $minRestHours) {
			$io->error(sprintf('Rest window still blocked by entry #%d after 20 adjustments.', $last->getId()));
			return Command::FAILURE;
		}

		$io->success('E2E clock ready for ' . $userId);
		return Command::SUCCESS;
	}
}
