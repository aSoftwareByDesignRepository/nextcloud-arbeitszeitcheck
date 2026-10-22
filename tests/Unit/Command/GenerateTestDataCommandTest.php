<?php

declare(strict_types=1);

/**
 * Atlas coverage lane — GenerateTestDataCommand: option gates (force,
 * user existence, clear paths) and a full demo-seed run covering the
 * internal seeders (entries incl. overnight + manual-pending, absences,
 * violations, demo team/member/manager wiring, marker cleanup).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Command;

use OCA\ArbeitszeitCheck\Command\GenerateTestDataCommand;
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
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateTestDataCommandTest extends TestCase
{
	private function qbMock(): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class));
		foreach (['eq', 'like', 'in', 'isNull'] as $m) {
			$expr->method($m)->willReturn('cond');
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		foreach (['delete', 'select', 'from', 'where', 'andWhere', 'update', 'set', 'setMaxResults'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeStatement')->willReturn(1);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(0);
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	private function buildCommand(array $opts = []): array
	{
		$config = $opts['config'] ?? $this->createMock(IConfig::class);
		$appConfig = $opts['appConfig'] ?? $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('0');

		$db = $opts['db'] ?? $this->createMock(IDBConnection::class);
		$db->method('escapeLikeParameter')->willReturnCallback(static fn ($s) => $s);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->qbMock());

		$userManager = $opts['userManager'] ?? $this->createMock(IUserManager::class);
		$groupManager = $opts['groupManager'] ?? $this->createMock(IGroupManager::class);

		$timeEntryMapper = $opts['timeEntryMapper'] ?? $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('getTableName')->willReturn('at_entries');
		$absenceMapper = $opts['absenceMapper'] ?? $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('getTableName')->willReturn('at_absences');
		$violationMapper = $opts['violationMapper'] ?? $this->createMock(ComplianceViolationMapper::class);
		$violationMapper->method('getTableName')->willReturn('at_violations');

		$wtm = $opts['workingTimeModelMapper'] ?? $this->createMock(WorkingTimeModelMapper::class);
		if (!isset($opts['workingTimeModelMapper'])) {
			$existing = new WorkingTimeModel();
			$existing->setId(1);
			$wtm->method('findDefault')->willReturn($existing);
		}
		$uwtm = $opts['userWorkingTimeModelMapper'] ?? $this->createMock(UserWorkingTimeModelMapper::class);
		$teamMapper = $opts['teamMapper'] ?? $this->createMock(TeamMapper::class);
		$teamMemberMapper = $opts['teamMemberMapper'] ?? $this->createMock(TeamMemberMapper::class);
		$teamManagerMapper = $opts['teamManagerMapper'] ?? $this->createMock(TeamManagerMapper::class);

		$cmd = new GenerateTestDataCommand(
			$config, $appConfig, $db, $userManager, $groupManager,
			$timeEntryMapper, $absenceMapper, $violationMapper,
			$wtm, $uwtm, $teamMapper, $teamMemberMapper, $teamManagerMapper,
		);
		return [$cmd, compact('timeEntryMapper', 'absenceMapper', 'violationMapper', 'wtm', 'uwtm', 'teamMapper', 'teamMemberMapper', 'teamManagerMapper', 'userManager', 'appConfig')];
	}

	private function runCmd(object $command, array $args = [], bool $interactive = false): array
	{
		$app = new \Symfony\Component\Console\Application();
		$app->add($command);
		$tester = new CommandTester($app->find($command->getName()));
		$code = $tester->execute($args, ['interactive' => $interactive]);
		return [$code, $tester->getDisplay()];
	}

	public function testFailsWithoutForceInNonInteractiveMode(): void
	{
		[$cmd] = $this->buildCommand();
		[$code, $display] = $this->runCmd($cmd, [], false); // non-interactive without --force
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('--force', $display);
	}

	public function testFailsForUnknownUser(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(false);
		[$cmd] = $this->buildCommand(['userManager' => $um]);
		[$code, $display] = $this->runCmd($cmd, ['--force' => true, '--user' => 'ghost']);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('does not exist', $display);
	}

	public function testFailsWhenNoUserResolvable(): void
	{
		$gm = $this->createMock(IGroupManager::class);
		$gm->method('get')->willReturn(null);
		$um = $this->createMock(IUserManager::class);
		$um->method('searchDisplayName')->willReturn([]);
		[$cmd] = $this->buildCommand(['userManager' => $um, 'groupManager' => $gm]);
		[$code, $display] = $this->runCmd($cmd, ['--force' => true]);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('Could not resolve a user', $display);
	}

	public function testResolvesFirstAdminUserWhenNoUserGiven(): void
	{
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin1');
		$group = $this->createMock(\OCP\IGroup::class);
		$group->method('searchUsers')->willReturn([$admin]);
		$gm = $this->createMock(IGroupManager::class);
		$gm->method('get')->with('admin')->willReturn($group);

		$entries = 0;
		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('getTableName')->willReturn('at_entries');
		$timeEntryMapper->method('insert')->willReturnCallback(function (TimeEntry $e) use (&$entries) {
			$entries++;
			return $e;
		});
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('getTableName')->willReturn('at_absences');

		[$cmd] = $this->buildCommand([
			'groupManager' => $gm,
			'timeEntryMapper' => $timeEntryMapper,
			'absenceMapper' => $absenceMapper,
		]);
		[$code, $display] = $this->runCmd($cmd, ['--force' => true, '--weeks' => '1']);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('admin1', $display);
		$this->assertGreaterThan(0, $entries);
	}

	public function testFullSeedRunWithTeamAndViolations(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(true);

		$entries = [];
		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('getTableName')->willReturn('at_entries');
		$timeEntryMapper->method('insert')->willReturnCallback(function (TimeEntry $e) use (&$entries) {
			$entries[] = $e;
			return $e;
		});

		$absences = [];
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('getTableName')->willReturn('at_absences');
		$absenceMapper->method('insert')->willReturnCallback(function (Absence $a) use (&$absences) {
			$absences[] = $a;
			return $a;
		});

		$violations = [];
		$violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$violationMapper->method('getTableName')->willReturn('at_violations');
		$violationMapper->method('insert')->willReturnCallback(function (ComplianceViolation $v) use (&$violations) {
			$violations[] = $v;
			return $v;
		});

		// No default model -> command creates one
		$wtm = $this->createMock(WorkingTimeModelMapper::class);
		$wtm->method('findDefault')->willReturn(null);
		$wtm->method('findAll')->willReturn([]);
		$wtm->expects($this->atLeastOnce())->method('insert')
			->with($this->callback(static fn (WorkingTimeModel $m) =>
				$m->getIsDefault() === true && str_contains((string)$m->getName(), 'AZC_DEMO')
			))
			->willReturnCallback(static function (WorkingTimeModel $m) {
				$m->setId(7); // QBMapper sets the auto-increment id on insert
				return $m;
			});

		$uwtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$uwtm->method('findCurrentByUser')->willReturn(null);
		$uwtm->expects($this->exactly(2))->method('insert'); // user + team member

		$teamMapper = $this->createMock(TeamMapper::class);
		$teamMapper->method('findAll')->willReturn([]);
		$teamMapper->expects($this->once())->method('insert')
			->with($this->callback(static fn (Team $t) => str_contains((string)$t->getName(), 'AZC_DEMO')))
			->willReturnCallback(static function (Team $t) {
				$t->setId(3);
				return $t;
			});

		$teamMemberMapper = $this->createMock(TeamMemberMapper::class);
		$teamMemberMapper->method('findByTeamId')->willReturn([]);
		$teamMemberMapper->expects($this->once())->method('addMember')->with(3, 'e2e_member');

		$teamManagerMapper = $this->createMock(TeamManagerMapper::class);
		$teamManagerMapper->method('findByTeamId')->willReturn([]);
		$teamManagerMapper->expects($this->once())->method('addManager')->with(3, 'demo_user');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('0');
		$appConfig->expects($this->once())->method('setValueString')
			->with('arbeitszeitcheck', 'use_app_teams', '1');

		[$cmd] = $this->buildCommand([
			'userManager' => $um,
			'timeEntryMapper' => $timeEntryMapper,
			'absenceMapper' => $absenceMapper,
			'violationMapper' => $violationMapper,
			'workingTimeModelMapper' => $wtm,
			'userWorkingTimeModelMapper' => $uwtm,
			'teamMapper' => $teamMapper,
			'teamMemberMapper' => $teamMemberMapper,
			'teamManagerMapper' => $teamManagerMapper,
			'appConfig' => $appConfig,
		]);

		[$code, $display] = $this->runCmd($cmd, [
			'--force' => true,
			'--user' => 'demo_user',
			'--weeks' => '8',
			'--with-team' => true,
			'--team-member' => 'e2e_member',
			'--with-violations' => true,
			'--with-overnight' => true,
			'--clear-demo' => true,
		]);

		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('Demo data for demo_user', $display);
		$this->assertGreaterThan(30, count($entries)); // ~40 weekday rows + overnight
		// overnight entry crosses midnight 22:00 -> 06:00 next day
		$overnight = array_filter($entries, static fn (TimeEntry $e) =>
			(int)$e->getStartTime()->format('H') === 22 && $e->getEndTime()->format('Y-m-d') !== $e->getStartTime()->format('Y-m-d'));
		$this->assertCount(1, $overnight);
		$this->assertGreaterThanOrEqual(6, count($absences)); // 3 user + 3 member feed
		$this->assertCount(2, $violations);
		$this->assertSame(ComplianceViolation::SEVERITY_WARNING, $violations[0]->getSeverity());
		$this->assertSame(ComplianceViolation::SEVERITY_ERROR, $violations[1]->getSeverity());
		$this->assertTrue($violations[1]->getResolved());
	}

	public function testClearOnlyShortCircuitsSeeding(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(true);

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('getTableName')->willReturn('at_entries');
		$timeEntryMapper->expects($this->never())->method('insert');
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('getTableName')->willReturn('at_absences');
		$violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$violationMapper->method('getTableName')->willReturn('at_violations');

		[$cmd] = $this->buildCommand([
			'userManager' => $um,
			'timeEntryMapper' => $timeEntryMapper,
			'absenceMapper' => $absenceMapper,
			'violationMapper' => $violationMapper,
		]);

		[$code, $display] = $this->runCmd($cmd, [
			'--force' => true, '--user' => 'demo_user', '--clear-demo' => true, '--clear-only' => true,
		]);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('demo-marked row', $display);
	}

	public function testUnknownTeamMemberFails(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturnCallback(static fn (string $u) => $u !== 'ghost_member');

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('getTableName')->willReturn('at_entries');
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('getTableName')->willReturn('at_absences');
		$violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$violationMapper->method('getTableName')->willReturn('at_violations');
		$wtm = $this->createMock(WorkingTimeModelMapper::class);
		$wtm->method('findDefault')->willReturn(null);
		$wtm->method('findAll')->willReturn([]);
		$wtm->method('insert')->willReturnCallback(static function (WorkingTimeModel $m) {
			$m->setId(7);
			return $m;
		});

		[$cmd] = $this->buildCommand([
			'userManager' => $um,
			'timeEntryMapper' => $timeEntryMapper,
			'absenceMapper' => $absenceMapper,
			'violationMapper' => $violationMapper,
			'workingTimeModelMapper' => $wtm,
		]);

		[$code, $display] = $this->runCmd($cmd, [
			'--force' => true, '--user' => 'demo_user', '--weeks' => '1',
			'--with-team' => true, '--team-member' => 'ghost_member',
		]);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('ghost_member', $display);
	}

	public function testExistingDefaultModelIsReused(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(true);

		$model = new WorkingTimeModel();
		$model->setName('existing');
		$model->setId(42);

		$wtm = $this->createMock(WorkingTimeModelMapper::class);
		$wtm->method('findDefault')->willReturn($model);
		$wtm->expects($this->never())->method('insert');

		$uwtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$uwtm->method('findCurrentByUser')->willReturn(new \OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel());

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('getTableName')->willReturn('at_entries');
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('getTableName')->willReturn('at_absences');
		$violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$violationMapper->method('getTableName')->willReturn('at_violations');

		[$cmd] = $this->buildCommand([
			'userManager' => $um,
			'timeEntryMapper' => $timeEntryMapper,
			'absenceMapper' => $absenceMapper,
			'violationMapper' => $violationMapper,
			'workingTimeModelMapper' => $wtm,
			'userWorkingTimeModelMapper' => $uwtm,
		]);

		[$code] = $this->runCmd($cmd, ['--force' => true, '--user' => 'demo_user', '--weeks' => '1']);
		$this->assertSame(Command::SUCCESS, $code);
	}
}
