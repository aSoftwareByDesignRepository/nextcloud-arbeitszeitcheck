<?php

declare(strict_types=1);

/**
 * Replay verification for split-service-day accounting (spec §8 A31, §9.5).
 *
 * For every at_entries row carrying a non-NULL work_context_json, the frozen
 * context plus the raw stamps must reproduce the stored accounting result
 * bit-for-bit: excluded_work_seconds, segments, and the canonical result
 * hash. A mismatch is a defect, not a discrepancy — the command fails CI and
 * reports the offending entry ids, the same tool auditors run.
 *
 * Strictly read-only: recomputation happens on a CLONED entity and nothing
 * is ever persisted.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Command;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\SplitServiceAccountingService;
use OCA\ArbeitszeitCheck\Support\SplitServiceAccounting;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class VerifySplitAccountingCommand extends Command
{
	/** Result fields compared one-by-one so the report can name the drift. */
	private const COMPARED_RESULT_FIELDS = [
		'state',
		'split',
		'reason',
		'error',
		'excluded',
		'segments',
		'break_handling',
		'engine',
	];

	public function __construct(
		private readonly TimeEntryMapper $timeEntryMapper,
		private readonly IUserManager $userManager,
		private readonly ?SplitServiceAccountingService $splitAccounting = null,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('arbeitszeitcheck:verify-split-accounting')
			->setDescription(
				'Replay-verify split-service accounting: recomputes every entry with a frozen work context '
				. 'from raw stamps and compares excluded_work_seconds, segments and result hash bit-for-bit. '
				. 'Read-only; exit 1 lists the offending entry ids.'
			)
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Restrict to one user id')
			->addOption('from', null, InputOption::VALUE_REQUIRED, 'Range start Y-m-d (default: 1970-01-01)')
			->addOption('to', null, InputOption::VALUE_REQUIRED, 'Range end Y-m-d inclusive (default: today)')
			->addOption('entry', null, InputOption::VALUE_REQUIRED, 'Verify a single time entry id')
			->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output')
			->setHelp(
				"Replay verification over entries with a non-NULL work_context_json (spec A31).\n\n" .
				"Examples:\n" .
				"  php occ arbeitszeitcheck:verify-split-accounting\n" .
				"  php occ arbeitszeitcheck:verify-split-accounting --user=peter --from=2026-11-01 --to=2026-11-30\n" .
				"  php occ arbeitszeitcheck:verify-split-accounting --entry=4711\n" .
				"  php occ arbeitszeitcheck:verify-split-accounting --json"
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$entries = $this->selectEntries($input, $io);
		if ($entries === null) {
			return Command::FAILURE;
		}

		$verified = 0;
		$skipped = 0;
		/** @var list<array{id:int,reason:string}> $invalid */
		$invalid = [];
		/** @var list<array{id:int,fields:list<string>}> $mismatched */
		$mismatched = [];

		foreach ($entries as $entry) {
			$verdict = $this->verifyEntry($entry);
			switch ($verdict['verdict']) {
				case 'verified':
					$verified++;
					break;
				case 'skipped':
					$skipped++;
					break;
				case 'invalid':
					$invalid[] = ['id' => (int)$entry->getId(), 'reason' => (string)$verdict['reason']];
					break;
				default:
					$mismatched[] = ['id' => (int)$entry->getId(), 'fields' => $verdict['fields']];
			}
		}

		$bad = count($mismatched) + count($invalid);

		if ($input->getOption('json')) {
			$output->writeln((string)json_encode([
				'scanned' => count($entries),
				'verified' => $verified,
				'mismatched' => count($mismatched),
				'invalid' => count($invalid),
				'skipped' => $skipped,
				'mismatched_entries' => $mismatched,
				'invalid_entries' => $invalid,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		} else {
			$io->title('Split-accounting replay verification (A31)');
			$io->writeln(sprintf(
				'%d context entries scanned — %d verified, %d mismatched, %d invalid, %d skipped (active)',
				count($entries),
				$verified,
				count($mismatched),
				count($invalid),
				$skipped,
			));
			foreach ($mismatched as $row) {
				$io->writeln(sprintf('MISMATCH entry %d: %s', $row['id'], implode(', ', $row['fields'])));
			}
			foreach ($invalid as $row) {
				$io->writeln(sprintf('CONTEXT_INVALID entry %d: %s', $row['id'], $row['reason']));
			}
			if ($bad === 0) {
				$io->success('Every frozen context reproduces its stored accounting bit-for-bit.');
			} else {
				$io->error(sprintf('%d entr%s failed replay verification — a defect, not a tolerated drift.', $bad, $bad === 1 ? 'y' : 'ies'));
			}
		}

		return $bad === 0 ? Command::SUCCESS : Command::FAILURE;
	}

	/**
	 * Entry selection: explicit --entry, else all users (or --user) × the
	 * --from/--to range, narrowed to rows carrying a non-NULL context column.
	 *
	 * @return list<TimeEntry>|null null on invalid input (message already printed)
	 */
	private function selectEntries(InputInterface $input, SymfonyStyle $io): ?array
	{
		$entryOpt = trim((string)($input->getOption('entry') ?? ''));
		if ($entryOpt !== '') {
			if (!preg_match('/^\d+$/', $entryOpt)) {
				$io->error('Invalid --entry id (integer expected).');
				return null;
			}
			try {
				return [$this->timeEntryMapper->find((int)$entryOpt)];
			} catch (DoesNotExistException) {
				$io->error('Time entry ' . $entryOpt . ' not found.');
				return null;
			} catch (\Throwable $e) {
				$io->error('Failed to load time entry ' . $entryOpt . ': ' . $e->getMessage());
				return null;
			}
		}

		$from = $this->parseDate((string)($input->getOption('from') ?? ''), '1970-01-01');
		$to = $this->parseDate((string)($input->getOption('to') ?? ''), 'today');
		if ($from === null || $to === null || $to < $from) {
			$io->error('Invalid --from/--to dates (Y-m-d, to >= from).');
			return null;
		}
		$toExclusive = (clone $to)->modify('+1 day')->setTime(0, 0, 0);

		$userIds = $this->resolveUsers((string)($input->getOption('user') ?? ''));
		if ($userIds === null) {
			$io->error('Unknown user id.');
			return null;
		}
		if ($userIds === []) {
			return [];
		}

		$entries = [];
		foreach ($this->timeEntryMapper->findByUsersAndDateRange($userIds, $from, $toExclusive) as $entry) {
			if (!$entry instanceof TimeEntry) {
				continue;
			}
			$raw = $entry->getWorkContextJson();
			if ($raw === null || $raw === '') {
				continue; // legacy rows are out of scope by definition
			}
			$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * Verify one entry: clone → recompute → field-wise + hash compare.
	 *
	 * @return array{verdict:string, reason?:string, fields?:list<string>}
	 */
	private function verifyEntry(TimeEntry $entry): array
	{
		$raw = $entry->getWorkContextJson();
		if ($raw === null || $raw === '') {
			return ['verdict' => 'skipped', 'reason' => 'null_context'];
		}
		if ($entry->hasInvalidWorkContext() || $entry->getWorkContext() === null) {
			return ['verdict' => 'invalid', 'reason' => 'context_json_undecodable'];
		}
		$storedResult = $entry->getAccountingResult();
		if (!\is_array($storedResult)) {
			return ['verdict' => 'invalid', 'reason' => 'context_missing_result'];
		}

		$status = $entry->getStatus();
		if ($entry->getEndTime() === null
			|| ($storedResult['state'] ?? null) === SplitServiceAccounting::STATE_ACTIVE
			|| \in_array($status, [TimeEntry::STATUS_ACTIVE, TimeEntry::STATUS_BREAK, TimeEntry::STATUS_PAUSED], true)) {
			return ['verdict' => 'skipped', 'reason' => 'active'];
		}

		$probe = clone $entry;
		try {
			$this->recomputeAccounting($probe);
		} catch (\Throwable $e) {
			return ['verdict' => 'invalid', 'reason' => 'recompute_failed: ' . $e->getMessage()];
		}
		$recomputed = $probe->getAccountingResult();
		if (!\is_array($recomputed)) {
			return ['verdict' => 'mismatched', 'fields' => ['result']];
		}

		$fields = [];
		if ($probe->getExcludedWorkSeconds() !== $entry->getExcludedWorkSeconds()) {
			$fields[] = 'excluded_work_seconds';
		}
		foreach (self::COMPARED_RESULT_FIELDS as $field) {
			if (($recomputed[$field] ?? null) !== ($storedResult[$field] ?? null)) {
				$fields[] = 'result.' . $field;
			}
		}
		if ($fields === []
			&& SplitServiceAccounting::contextResultHash($recomputed) !== SplitServiceAccounting::contextResultHash($storedResult)) {
			$fields[] = 'result_hash';
		}

		return $fields === []
			? ['verdict' => 'verified']
			: ['verdict' => 'mismatched', 'fields' => $fields];
	}

	/**
	 * Recompute on the given (already cloned) entity — the ONLY place the
	 * sibling accounting service is touched, so the command never writes and
	 * tests can substitute the engine.
	 */
	protected function recomputeAccounting(TimeEntry $entry): void
	{
		$service = $this->splitAccounting ?? \OCP\Server::get(SplitServiceAccountingService::class);
		$service->recomputeAccounting($entry);
	}

	/**
	 * @return list<string>|null null on unknown single user
	 */
	private function resolveUsers(string $userOption): ?array
	{
		if ($userOption !== '') {
			return $this->userManager->userExists($userOption) ? [$userOption] : null;
		}
		$ids = [];
		$this->userManager->callForAllUsers(static function ($user) use (&$ids): void {
			$ids[] = $user->getUID();
		});
		sort($ids);
		return $ids;
	}

	private function parseDate(string $raw, string $defaultExpr): ?\DateTime
	{
		try {
			if ($raw === '') {
				return new \DateTime($defaultExpr);
			}
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
				return null;
			}
			return new \DateTime($raw);
		} catch (\Throwable) {
			return null;
		}
	}
}
