<?php

declare(strict_types=1);

/**
 * CLI helper: insert deterministic demo data for UI / report testing (development only).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Command;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\Team;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GenerateTestDataCommand extends Command
{
	private const DEMO_MARKER = '###AZC_DEMO###';

	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private TimeEntryMapper $timeEntryMapper,
		private AbsenceMapper $absenceMapper,
		private ComplianceViolationMapper $violationMapper,
		private WorkingTimeModelMapper $workingTimeModelMapper,
		private UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private TeamMapper $teamMapper,
		private TeamMemberMapper $teamMemberMapper,
		private TeamManagerMapper $teamManagerMapper,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('arbeitszeitcheck:generate-test-data')
			->setDescription('Insert demo time entries, absences, and optional compliance data for development (not for production).')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Nextcloud user id (UID). If omitted, the first admin user is used.')
			->addOption('weeks', 'w', InputOption::VALUE_REQUIRED, 'How many weeks of weekday entries to generate (default: 8).', '8')
			->addOption('with-team', null, InputOption::VALUE_NONE, 'Create a demo app team and add the user as member + manager (if not already present).')
			->addOption('with-violations', null, InputOption::VALUE_NONE, 'Insert a few demo compliance violations.')
			->addOption('clear-demo', null, InputOption::VALUE_NONE, 'Remove rows previously created by this command for the target user (matched by demo marker), then continue unless --clear-only.')
			->addOption('clear-only', null, InputOption::VALUE_NONE, 'Only run the demo cleanup; do not insert new data.')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Required in non-interactive mode (e.g. cron/CI) to confirm.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		if (!$input->getOption('force')) {
			if (!$input->isInteractive()) {
				$io->error('Non-interactive runs require --force (demo data must not be created accidentally).');
				return Command::FAILURE;
			}
			if (!$io->confirm('This inserts artificial data into the ArbeitszeitCheck tables. Continue?', false)) {
				return Command::SUCCESS;
			}
		}

		$userId = $input->getOption('user');
		if ($userId !== null && $userId !== '') {
			if (!$this->userManager->userExists((string)$userId)) {
				$io->error('User does not exist: ' . $userId);
				return Command::FAILURE;
			}
			$userId = (string)$userId;
		} else {
			$userId = $this->resolveDefaultUserId($io);
			if ($userId === null) {
				$io->error('Could not resolve a user. Pass --user=<uid> or create an admin user.');
				return Command::FAILURE;
			}
		}

		$weeks = max(1, min(52, (int)$input->getOption('weeks')));
		$clearOnly = (bool)$input->getOption('clear-only');

		if ((bool)$input->getOption('clear-demo')) {
			$removed = $this->clearDemoRows($userId);
			$io->success(sprintf('Removed %d demo-marked row(s) for user %s.', $removed, $userId));
		}

		if ($clearOnly) {
			return Command::SUCCESS;
		}

		$modelId = $this->ensureWorkingTimeModel($io);
		$this->ensureUserWorkingTimeModel($userId, $modelId);

		$insertedEntries = $this->seedTimeEntries($userId, $weeks);
		$insertedAbsences = $this->seedAbsences($userId);
		$insertedViolations = 0;
		if ((bool)$input->getOption('with-violations')) {
			$insertedViolations = $this->seedViolations($userId);
		}

		if ((bool)$input->getOption('with-team')) {
			$this->ensureDemoTeam($io, $userId);
		}

		$io->success(sprintf(
			'Demo data for %s: %d time entries, %d absences, %d violations (team: %s).',
			$userId,
			$insertedEntries,
			$insertedAbsences,
			$insertedViolations,
			$input->getOption('with-team') ? 'yes' : 'skipped'
		));

		return Command::SUCCESS;
	}

	private function resolveDefaultUserId(SymfonyStyle $io): ?string
	{
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup !== null) {
			$users = $adminGroup->searchUsers('');
			foreach ($users as $user) {
				$uid = $user->getUID();
				if ($uid !== '') {
					$io->note('Using first admin user: ' . $uid);
					return $uid;
				}
			}
		}

		$users = $this->userManager->searchDisplayName('', 20);
		foreach ($users as $user) {
			$uid = $user->getUID();
			if ($uid !== '') {
				$io->note('Using first available user: ' . $uid);
				return $uid;
			}
		}

		return null;
	}

	private function clearDemoRows(string $userId): int
	{
		$marker = '%' . $this->db->escapeLikeParameter(self::DEMO_MARKER) . '%';
		$total = 0;

		$tables = [
			[$this->timeEntryMapper->getTableName(), 'description'],
			[$this->absenceMapper->getTableName(), 'reason'],
			[$this->violationMapper->getTableName(), 'description'],
		];

		foreach ($tables as [$table, $column]) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->like($column, $qb->createNamedParameter($marker)));
			$total += $qb->executeStatement();
		}

		return $total;
	}

	private function ensureWorkingTimeModel(SymfonyStyle $io): int
	{
		$existing = $this->workingTimeModelMapper->findDefault();
		if ($existing !== null) {
			return $existing->getId();
		}

		$all = $this->workingTimeModelMapper->findAll(1, 0);
		if ($all !== []) {
			return $all[0]->getId();
		}

		$now = new \DateTime();
		$model = new WorkingTimeModel();
		$model->setName('Demo Vollzeit (' . self::DEMO_MARKER . ')');
		$model->setDescription('Auto-created for CLI demo data.');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);
		$model->setIsDefault(true);
		$model->setCreatedAt($now);
		$model->setUpdatedAt($now);
		$this->workingTimeModelMapper->insert($model);
		$io->note('Created working time model id ' . $model->getId());

		return $model->getId();
	}

	private function ensureUserWorkingTimeModel(string $userId, int $modelId): void
	{
		if ($this->userWorkingTimeModelMapper->findCurrentByUser($userId) !== null) {
			return;
		}

		$now = new \DateTime();
		$start = (clone $now)->modify('-1 year');
		$start->setTime(0, 0, 0);

		$assign = new UserWorkingTimeModel();
		$assign->setUserId($userId);
		$assign->setWorkingTimeModelId($modelId);
		$assign->setVacationDaysPerYear(28);
		$assign->setStartDate($start);
		$assign->setEndDate(null);
		$assign->setCreatedAt($now);
		$assign->setUpdatedAt($now);
		$this->userWorkingTimeModelMapper->insert($assign);
	}

	private function seedTimeEntries(string $userId, int $weeks): int
	{
		$today = new \DateTime('today');
		$start = (clone $today)->modify('-' . ($weeks * 7) . ' days');
		$start->setTime(0, 0, 0);

		$cursor = clone $start;
		$count = 0;

		while ($cursor <= $today) {
			$dow = (int)$cursor->format('N');
			if ($dow >= 6) {
				$cursor->modify('+1 day');
				continue;
			}

			$seed = crc32($userId . $cursor->format('Y-m-d')) % 5;

			if ($seed === 0) {
				$this->insertCompletedDay($userId, $cursor, '08:00', '16:30', [
					['12:00', '12:30'],
				], 'Büro / Demo ' . self::DEMO_MARKER);
				$count++;
			} elseif ($seed === 1) {
				$this->insertCompletedDay($userId, $cursor, '08:00', '18:00', [
					['12:00', '12:45'],
					['15:30', '15:45'],
				], 'Projekt / Demo ' . self::DEMO_MARKER);
				$count++;
			} elseif ($seed === 2) {
				$this->insertManualPending($userId, $cursor, '09:15', '17:00', 'Nachtrag (Demo) ' . self::DEMO_MARKER);
				$count++;
			} elseif ($seed === 3) {
				$this->insertCompletedDay($userId, $cursor, '07:30', '15:30', [
					['11:45', '12:30'],
				], 'Frühschicht / Demo ' . self::DEMO_MARKER);
				$count++;
			} else {
				$this->insertCompletedDay($userId, $cursor, '10:00', '18:30', [
					['13:00', '13:30'],
				], 'Support / Demo ' . self::DEMO_MARKER);
				$count++;
			}

			$cursor->modify('+1 day');
		}

		return $count;
	}

	/**
	 * @param array<int, array{0: string, 1: string}> $breakRanges HH:MM pairs in local server time
	 */
	private function insertCompletedDay(
		string $userId,
		\DateTime $day,
		string $startHm,
		string $endHm,
		array $breakRanges,
		string $description,
	): void {
		$dateStr = $day->format('Y-m-d');
		[$sh, $sm] = array_map('intval', explode(':', $startHm));
		[$eh, $em] = array_map('intval', explode(':', $endHm));

		$start = new \DateTime($dateStr);
		$start->setTime($sh, $sm, 0);
		$end = new \DateTime($dateStr);
		$end->setTime($eh, $em, 0);

		$breaks = [];
		foreach ($breakRanges as [$bStart, $bEnd]) {
			[$bh1, $bm1] = array_map('intval', explode(':', $bStart));
			[$bh2, $bm2] = array_map('intval', explode(':', $bEnd));
			$bs = new \DateTime($dateStr);
			$bs->setTime($bh1, $bm1, 0);
			$be = new \DateTime($dateStr);
			$be->setTime($bh2, $bm2, 0);
			$breaks[] = [
				'start' => $bs->format('c'),
				'end' => $be->format('c'),
			];
		}

		$now = new \DateTime();
		$entry = new TimeEntry();
		$entry->setUserId($userId);
		$entry->setStartTime($start);
		$entry->setEndTime($end);
		$entry->setBreaks(json_encode($breaks, JSON_THROW_ON_ERROR));
		$entry->setDescription($description);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt($now);
		$entry->setUpdatedAt($now);
		$this->timeEntryMapper->insert($entry);
	}

	private function insertManualPending(string $userId, \DateTime $day, string $startHm, string $endHm, string $description): void
	{
		$dateStr = $day->format('Y-m-d');
		[$sh, $sm] = array_map('intval', explode(':', $startHm));
		[$eh, $em] = array_map('intval', explode(':', $endHm));
		$start = new \DateTime($dateStr);
		$start->setTime($sh, $sm, 0);
		$end = new \DateTime($dateStr);
		$end->setTime($eh, $em, 0);

		$now = new \DateTime();
		$entry = new TimeEntry();
		$entry->setUserId($userId);
		$entry->setStartTime($start);
		$entry->setEndTime($end);
		$entry->setBreaks(json_encode([
			[
				'start' => (new \DateTime($dateStr . ' 12:00:00'))->format('c'),
				'end' => (new \DateTime($dateStr . ' 12:30:00'))->format('c'),
			],
		], JSON_THROW_ON_ERROR));
		$entry->setDescription($description);
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setIsManualEntry(true);
		$entry->setJustification('Demo: manueller Eintrag zur Ansicht der Freigabe.');
		$entry->setCreatedAt($now);
		$entry->setUpdatedAt($now);
		$this->timeEntryMapper->insert($entry);
	}

	private function seedAbsences(string $userId): int
	{
		$now = new \DateTime();
		$today = new \DateTime('today');

		$ranges = [
			[
				'type' => Absence::TYPE_VACATION,
				'start' => (clone $today)->modify('+10 days'),
				'end' => (clone $today)->modify('+14 days'),
				'days' => 5.0,
				'status' => Absence::STATUS_PENDING,
				'reason' => 'Demo-Urlaub (noch offen) ' . self::DEMO_MARKER,
			],
			[
				'type' => Absence::TYPE_SICK_LEAVE,
				'start' => (clone $today)->modify('-20 days'),
				'end' => (clone $today)->modify('-18 days'),
				'days' => 3.0,
				'status' => Absence::STATUS_APPROVED,
				'reason' => 'Demo-Krankmeldung ' . self::DEMO_MARKER,
			],
			[
				'type' => Absence::TYPE_HOME_OFFICE,
				'start' => (clone $today)->modify('-5 days'),
				'end' => (clone $today)->modify('-5 days'),
				'days' => 1.0,
				'status' => Absence::STATUS_APPROVED,
				'reason' => 'Demo Homeoffice ' . self::DEMO_MARKER,
			],
		];

		$n = 0;
		foreach ($ranges as $r) {
			$a = new Absence();
			$a->setUserId($userId);
			$a->setType($r['type']);
			$s = clone $r['start'];
			$s->setTime(0, 0, 0);
			$e = clone $r['end'];
			$e->setTime(0, 0, 0);
			$a->setStartDate($s);
			$a->setEndDate($e);
			$a->setDays($r['days']);
			$a->setReason($r['reason']);
			$a->setStatus($r['status']);
			$a->setCreatedAt($now);
			$a->setUpdatedAt($now);
			$this->absenceMapper->insert($a);
			$n++;
		}

		return $n;
	}

	private function seedViolations(string $userId): int
	{
		$today = new \DateTime('today');
		$now = new \DateTime();

		$rows = [
			[
				'type' => ComplianceViolation::TYPE_MISSING_BREAK,
				'severity' => ComplianceViolation::SEVERITY_WARNING,
				'date' => (clone $today)->modify('-7 days'),
				'desc' => 'Demo: fehlende Pause (Reports/Compliance) ' . self::DEMO_MARKER,
				'resolved' => false,
			],
			[
				'type' => ComplianceViolation::TYPE_DAILY_HOURS_LIMIT_EXCEEDED,
				'severity' => ComplianceViolation::SEVERITY_ERROR,
				'date' => (clone $today)->modify('-10 days'),
				'desc' => 'Demo: Tageshöchstzeit überschritten ' . self::DEMO_MARKER,
				'resolved' => true,
			],
		];

		$n = 0;
		foreach ($rows as $r) {
			$v = new ComplianceViolation();
			$v->setUserId($userId);
			$v->setViolationType($r['type']);
			$v->setDescription($r['desc']);
			$d = clone $r['date'];
			$d->setTime(0, 0, 0);
			$v->setDate($d);
			$v->setTimeEntryId(null);
			$v->setSeverity($r['severity']);
			$v->setResolved($r['resolved']);
			if ($r['resolved']) {
				$v->setResolvedAt($now);
				$v->setResolvedBy($userId);
			} else {
				$v->setResolvedAt(null);
				$v->setResolvedBy(null);
			}
			$v->setCreatedAt($now);
			$this->violationMapper->insert($v);
			$n++;
		}

		return $n;
	}

	private function ensureDemoTeam(SymfonyStyle $io, string $userId): void
	{
		$name = 'Demo-Team (' . self::DEMO_MARKER . ')';
		foreach ($this->teamMapper->findAll() as $team) {
			if ($team->getName() === $name) {
				$this->ensureTeamLinks($team->getId(), $userId);
				$io->note('Demo team already exists (id ' . $team->getId() . ').');
				return;
			}
		}

		$now = new \DateTime();
		$team = new Team();
		$team->setName($name);
		$team->setParentId(null);
		$team->setSortOrder(0);
		$team->setCreatedAt($now);
		$this->teamMapper->insert($team);
		$this->ensureTeamLinks($team->getId(), $userId);
		$io->note('Created demo team id ' . $team->getId());
	}

	private function ensureTeamLinks(int $teamId, string $userId): void
	{
		$isMember = false;
		foreach ($this->teamMemberMapper->findByTeamId($teamId) as $m) {
			if ($m->getUserId() === $userId) {
				$isMember = true;
				break;
			}
		}
		if (!$isMember) {
			$this->teamMemberMapper->addMember($teamId, $userId);
		}

		$hasManager = false;
		foreach ($this->teamManagerMapper->findByTeamId($teamId) as $mgr) {
			if ($mgr->getUserId() === $userId) {
				$hasManager = true;
				break;
			}
		}
		if (!$hasManager) {
			$this->teamManagerMapper->addManager($teamId, $userId);
		}
	}
}
