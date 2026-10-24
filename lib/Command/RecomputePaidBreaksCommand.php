<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Command;

use OCA\ArbeitszeitCheck\BackgroundJob\PaidBreakRecomputeJob;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recompute paid_break_seconds snapshots for users or a working-time model.
 *
 * Recovery/admin tool (NH-01): the same allocator the background job and the
 * mutation choke point use, executed inline under the per-user mutation lock
 * ({@see TimeTrackingService::recomputePaidBreaksForUserSpan()}) or queued as
 * the chunked job for large ranges. Every run — inline or queued — leaves a
 * `paid_break_recompute` audit row (NN-03).
 *
 * Usage:
 *   occ arbeitszeitcheck:recompute-paid-breaks --user=alice
 *   occ arbeitszeitcheck:recompute-paid-breaks --user=alice --user=bob --from=2026-01-01 --to=2026-12-31
 *   occ arbeitszeitcheck:recompute-paid-breaks --model=3
 *   occ arbeitszeitcheck:recompute-paid-breaks --model=3 --queue
 */
class RecomputePaidBreaksCommand extends Command
{
	private const ACTOR = 'occ:recompute-paid-breaks';

	public function __construct(
		private IUserManager $userManager,
		private TimeEntryMapper $timeEntryMapper,
		private UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private TimeTrackingService $timeTrackingService,
		private AuditLogMapper $auditLogMapper,
		private IJobList $jobList,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('arbeitszeitcheck:recompute-paid-breaks')
			->setDescription('Recompute paid-break snapshots (breaks counted as working time) for users or a working-time model.')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Nextcloud user id (repeatable)')
			->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Working-time model id — recompute every assignment window of the model')
			->addOption('from', null, InputOption::VALUE_REQUIRED, 'Lower bound YYYY-MM-DD (default: earliest time entry)')
			->addOption('to', null, InputOption::VALUE_REQUIRED, 'Upper bound YYYY-MM-DD inclusive (default: today)')
			->addOption('queue', null, InputOption::VALUE_NONE, 'Enqueue the chunked background job instead of running inline');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$modelId = trim((string)($input->getOption('model') ?? ''));
		if ($modelId !== '' && !preg_match('/^\d+$/', $modelId)) {
			$io->error('--model must be a positive integer');
			return Command::FAILURE;
		}

		$users = array_values(array_filter(array_map('strval', (array)$input->getOption('user')), static fn (string $u): bool => $u !== ''));
		if ($users === [] && $modelId === '') {
			$io->error('Provide at least one --user or a --model id. A blanket all-user recompute is not supported — scope deliberately.');
			return Command::FAILURE;
		}

		$from = null;
		$fromRaw = trim((string)($input->getOption('from') ?? ''));
		if ($fromRaw !== '') {
			$from = \DateTime::createFromFormat('!Y-m-d', $fromRaw);
			if ($from === false) {
				$io->error('Invalid --from date: ' . $fromRaw . ' (expected YYYY-MM-DD)');
				return Command::FAILURE;
			}
		}

		$to = new \DateTime('tomorrow');
		$toRaw = trim((string)($input->getOption('to') ?? ''));
		if ($toRaw !== '') {
			$toDate = \DateTime::createFromFormat('!Y-m-d', $toRaw);
			if ($toDate === false) {
				$io->error('Invalid --to date: ' . $toRaw . ' (expected YYYY-MM-DD)');
				return Command::FAILURE;
			}
			// --to is inclusive (calendar day), the span is end-exclusive.
			$to = (clone $toDate)->modify('+1 day');
		}
		if ($from !== null && $to->getTimestamp() <= $from->getTimestamp()) {
			$io->error('--to must be on or after --from');
			return Command::FAILURE;
		}

		if ($input->getOption('queue')) {
			if ($fromRaw !== '' || $toRaw !== '') {
				$io->warning('--from/--to are ignored with --queue: the background job always recomputes the full history/assignment windows.');
			}
			return $this->enqueue($io, $users, $modelId);
		}

		// Worklist: (user, from, to) spans. Model mode mirrors the job's
		// assignment-window expansion (end-date inclusive → +1 day), then
		// intersects with the CLI bounds.
		$worklist = [];
		foreach ($users as $uid) {
			$worklist[] = ['user' => $uid, 'from' => $from, 'to' => $to];
		}
		if ($modelId !== '') {
			foreach ($this->userWorkingTimeModelMapper->findByWorkingTimeModel((int)$modelId, false) as $assignment) {
				$wFrom = $assignment->getStartDate();
				$wTo = $assignment->getEndDate() !== null ? (clone $assignment->getEndDate())->modify('+1 day') : null;
				if ($from !== null && ($wFrom === null || $wFrom < $from)) {
					$wFrom = $from;
				}
				if ($wTo === null || $wTo > $to) {
					$wTo = $to;
				}
				if ($wFrom === null) {
					$wFrom = $this->earliestEntryStart($assignment->getUserId());
				}
				if ($wFrom === null || $wTo->getTimestamp() <= $wFrom->getTimestamp()) {
					continue;
				}
				$worklist[] = ['user' => $assignment->getUserId(), 'from' => $wFrom, 'to' => $wTo];
			}
		}

		$failures = 0;
		$totals = ['users' => [], 'changed' => 0, 'days' => 0, 'skipped' => []];
		foreach ($worklist as $item) {
			$uid = $item['user'];
			if ($this->userManager->get($uid) === null) {
				$io->warning('Skipping unknown user: ' . $uid);
				$failures++;
				continue;
			}
			$spanFrom = $item['from'] ?? $this->earliestEntryStart($uid);
			if ($spanFrom === null) {
				$io->writeln(sprintf('%s: no time entries — nothing to recompute', $uid));
				continue;
			}
			try {
				$result = $this->timeTrackingService->recomputePaidBreaksForUserSpan($uid, $spanFrom, $item['to']);
			} catch (\Throwable $e) {
				$io->error(sprintf('%s: recompute failed: %s', $uid, $e->getMessage()));
				$failures++;
				continue;
			}
			$totals['users'][$uid] = true;
			$totals['changed'] += (int)$result['changed'];
			$totals['days'] += (int)$result['days_recomputed'];
			foreach ($result['skipped_finalized'] as $monthKey) {
				$totals['skipped'][$uid . '@' . $monthKey] = true;
			}
			$io->writeln(sprintf(
				'%s [%s → %s): %d entries updated, %d days recomputed%s',
				$uid,
				$spanFrom->format('Y-m-d'),
				$item['to']->format('Y-m-d'),
				$result['changed'],
				$result['days_recomputed'],
				$result['skipped_finalized'] !== [] ? ', skipped finalized: ' . implode(', ', $result['skipped_finalized']) : ''
			));
		}

		$this->writeAudit($users, $modelId, $totals);

		$skippedList = array_keys($totals['skipped']);
		sort($skippedList);
		$io->success(sprintf(
			'Done: %d user(s), %d entries updated, %d days recomputed%s',
			count($totals['users']),
			$totals['changed'],
			$totals['days'],
			$skippedList !== [] ? '; skipped finalized: ' . implode(', ', $skippedList) : ''
		));
		return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
	}

	private function enqueue(SymfonyStyle $io, array $users, string $modelId): int
	{
		$queued = 0;
		foreach ($users as $uid) {
			if (PaidBreakRecomputeJob::enqueueForUser($this->jobList, $uid)) {
				$queued++;
				$io->writeln('Queued full-history recompute for user ' . $uid);
			} else {
				$io->writeln('Already queued, skipping user ' . $uid);
			}
		}
		if ($modelId !== '') {
			if (PaidBreakRecomputeJob::enqueueForModel($this->jobList, (int)$modelId)) {
				$queued++;
				$io->writeln('Queued model recompute for model ' . $modelId);
			} else {
				$io->writeln('Already queued, skipping model ' . $modelId);
			}
		}
		$this->writeAudit($users, $modelId, ['users' => array_fill_keys($users, true), 'changed' => 0, 'days' => 0, 'skipped' => []], 'queued');
		$io->success(sprintf('Queued %d recompute job(s) — the cron worker applies them chunked.', $queued));
		return Command::SUCCESS;
	}

	/**
	 * NN-03: every retro-mutation path leaves a `paid_break_recompute` row.
	 *
	 * @param array<string,bool> $usersTouched
	 * @param array{users: array<string,bool>, changed: int, days: int, skipped: array<string,bool>} $totals
	 */
	private function writeAudit(array $users, string $modelId, array $totals, string $mode = 'inline'): void
	{
		try {
			$skippedList = array_keys($totals['skipped']);
			sort($skippedList);
			$this->auditLogMapper->logAction(
				self::ACTOR,
				'paid_break_recompute',
				$modelId !== '' ? 'working_time_model' : 'user',
				$modelId !== '' ? (int)$modelId : null,
				null,
				[
					'trigger' => self::ACTOR,
					'mode' => $mode,
					'model_id' => $modelId !== '' ? (int)$modelId : null,
					'users' => $mode === 'queued' ? count($users) : count($totals['users']),
					'entries_changed' => $totals['changed'],
					'days_recomputed' => $totals['days'],
					'skipped_finalized_months' => $skippedList,
					'skipped_finalized_total' => count($skippedList),
				],
				self::ACTOR
			);
		} catch (\Throwable $e) {
			// Never fail the command on an audit write — but make it loud.
			fwrite(STDERR, 'WARNING: audit write failed: ' . $e->getMessage() . PHP_EOL);
		}
	}

	/**
	 * Earliest entry start bounds the span so the allocator's day cursor
	 * never walks empty decades before the user's first record.
	 */
	private function earliestEntryStart(string $userId): ?\DateTime
	{
		$first = $this->timeEntryMapper->findChunkByUserAfterId($userId, null, null, 0, 1);
		return $first === [] ? null : $first[0]->getStartTime();
	}
}
