<?php

declare(strict_types=1);

/**
 * Audit: approved absences must produce the Soll effect the matrix promises.
 *
 * For every employee with approved absences in the range, compares the
 * gate-agnostic expectation (what the matrix should credit / reduce) with
 * what the balance actually received. Three verdicts:
 *
 *   OK       expected == actual (matrix delivered)
 *   CONFIG   expected > actual because a gate is off — admin choice, listed
 *   ANOMALY  expected > actual while the gate is on — this is the
 *            vacation-Minusstunden bug class; exit code 1.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Command;

use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\PaidAbsencePlannedHoursCreditService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class VerifyAbsenceSollCommand extends Command
{
	private const EPSILON = 0.01;

	public function __construct(
		private readonly PaidAbsencePlannedHoursCreditService $creditService,
		private readonly AbsenceMapper $absenceMapper,
		private readonly TimeEntryMapper $timeEntryMapper,
		private readonly IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('arbeitszeitcheck:absence-soll:verify')
			->setDescription('Audit approved absences against the Soll-effects matrix (credits / reductions actually delivered).')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Restrict to one user id')
			->addOption('from', null, InputOption::VALUE_REQUIRED, 'Range start Y-m-d (default: Jan 1 of current year)')
			->addOption('to', null, InputOption::VALUE_REQUIRED, 'Range end Y-m-d (default: today)')
			->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output')
			->setHelp(
				"Verifies that approved absences deliver the Soll effect the type matrix promises:\n" .
				"paid leave credits planned hours, suspensions remove the day's target.\n\n" .
				"Examples:\n" .
				"  php occ arbeitszeitcheck:absence-soll:verify\n" .
				"  php occ arbeitszeitcheck:absence-soll:verify --user=peter --from=2026-09-01 --to=2026-10-31\n" .
				"  php occ arbeitszeitcheck:absence-soll:verify --json"
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$from = $this->parseDate((string)($input->getOption('from') ?? ''), 'first day of january this year');
		$to = $this->parseDate((string)($input->getOption('to') ?? ''), 'today');
		if ($from === null || $to === null || $to < $from) {
			$io->error('Invalid --from/--to dates (Y-m-d, to >= from).');
			return Command::FAILURE;
		}
		$to->setTime(23, 59, 59);

		$userIds = $this->resolveUsers((string)($input->getOption('user') ?? ''));
		if ($userIds === null) {
			$io->error('Unknown user id.');
			return Command::FAILURE;
		}

		$gates = [
			'sick_credit' => $this->creditService->isEnabled(),
			'leave_effects' => $this->creditService->isLeaveEffectsEnabled(),
		];

		$rows = [];
		$anomalies = 0;
		$configNotes = 0;
		foreach ($userIds as $userId) {
			$row = $this->auditUser($userId, $from, $to, $gates);
			if ($row === null) {
				continue;
			}
			$rows[] = $row;
			if ($row['verdict'] === 'anomaly') {
				$anomalies++;
			} elseif ($row['verdict'] === 'config') {
				$configNotes++;
			}
		}

		if ($input->getOption('json')) {
			$output->writeln((string)json_encode([
				'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
				'gates' => $gates,
				'scanned_users' => count($userIds),
				'affected_users' => count($rows),
				'anomalies' => $anomalies,
				'config_notes' => $configNotes,
				'rows' => $rows,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		} else {
			$io->title('Absence Soll-effects audit');
			$io->writeln(sprintf(
				'Range %s – %s · gates: sick credit %s, leave effects %s',
				$from->format('Y-m-d'),
				$to->format('Y-m-d'),
				$gates['sick_credit'] ? 'ON' : 'off',
				$gates['leave_effects'] ? 'ON' : 'off',
			));
			if ($rows !== []) {
				$io->table(
					['User', 'Verdict', 'Expected credit h', 'Actual credit h', 'Expected reduction h', 'Actual reduction h'],
					array_map(static fn (array $r): array => [
						$r['user'],
						$r['verdict'],
						number_format($r['expected_credit'], 2),
						number_format($r['actual_credit'], 2),
						number_format($r['expected_reduction'], 2),
						number_format($r['actual_reduction'], 2),
					], $rows),
				);
			}
			$io->writeln(sprintf(
				'%d user(s) scanned, %d with approved absences — %d anomalies, %d config notes.',
				count($userIds),
				count($rows),
				$anomalies,
				$configNotes,
			));
			if ($anomalies > 0) {
				$io->error('ANOMALY: enabled gates did not deliver the matrix effect — balances may show wrong minus hours.');
			} elseif ($configNotes > 0) {
				$io->note('Config notes: expected effects exceed actual because a gate is off. Verify that is intentional.');
			} else {
				$io->success('All approved absences deliver their matrix effect.');
			}
		}

		return $anomalies > 0 ? Command::FAILURE : Command::SUCCESS;
	}

	/**
	 * @return array{user: string, verdict: string, expected_credit: float, actual_credit: float, expected_reduction: float, actual_reduction: float}|null
	 */
	private function auditUser(string $userId, \DateTime $from, \DateTime $to, array $gates): ?array
	{
		$workedByDay = $this->workedHoursByDay($userId, $from, $to);

		$expected = $this->creditService->expectedSollEffectHours($userId, $from, $to, $workedByDay);
		$expectedCredit = (float)$expected['credit_hours'];
		$expectedReduction = (float)$expected['reduction_hours'];
		if ($expectedCredit < self::EPSILON && $expectedReduction < self::EPSILON) {
			return null; // nothing the matrix would touch — silent majority
		}

		$actualCredit = $expectedCredit < self::EPSILON
			? 0.0
			: (float)($this->creditService->creditHoursForRange($userId, $from, $to, $workedByDay)['hours'] ?? 0.0);
		$actualReduction = $expectedReduction < self::EPSILON
			? 0.0
			: (float)($this->creditService->sollReductionHoursForRange($userId, $from, $to, $workedByDay)['hours'] ?? 0.0);

		$creditShort = max(0.0, $expectedCredit - $actualCredit) >= self::EPSILON;
		$reductionShort = max(0.0, $expectedReduction - $actualReduction) >= self::EPSILON;

		$verdict = 'ok';
		if ($creditShort || $reductionShort) {
			// A shortfall under an enabled gate is a bug; under a disabled gate
			// it is configuration the admin should see listed explicitly.
			$gateOff = ($creditShort && !($gates['sick_credit'] || $gates['leave_effects']))
				|| ($creditShort && !$gates['leave_effects'])
				|| ($reductionShort && !$gates['leave_effects']);
			$verdict = $gateOff ? 'config' : 'anomaly';
		}

		return [
			'user' => $userId,
			'verdict' => $verdict,
			'expected_credit' => round($expectedCredit, 2),
			'actual_credit' => round($actualCredit, 2),
			'expected_reduction' => round($expectedReduction, 2),
			'actual_reduction' => round($actualReduction, 2),
		];
	}

	/**
	 * @return array<string, float> Y-m-d => completed paid hours
	 */
	private function workedHoursByDay(string $userId, \DateTime $from, \DateTime $to): array
	{
		$byDay = [];
		try {
			foreach ($this->timeEntryMapper->findByUserAndDateRange($userId, $from, $to) as $entry) {
				if (!$entry instanceof TimeEntry
					|| $entry->getStatus() !== TimeEntry::STATUS_COMPLETED
					|| $entry->getEndTime() === null
					|| $entry->getStartTime() === null) {
					continue;
				}
				$key = $entry->getStartTime()->format('Y-m-d');
				$byDay[$key] = ($byDay[$key] ?? 0.0) + (float)($entry->getPaidDurationHours() ?? 0.0);
			}
		} catch (\Throwable) {
			// Audit stays read-only and best-effort.
		}
		return $byDay;
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
