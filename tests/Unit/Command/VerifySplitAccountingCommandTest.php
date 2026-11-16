<?php

declare(strict_types=1);

/**
 * Contract tests for {@see VerifySplitAccountingCommand} (spec §8 A31):
 * replay-verification exit codes, per-verdict reporting, scope filters, and
 * the read-only guarantee (no persist call, recomputation on clones only).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Command;

use OCA\ArbeitszeitCheck\Command\VerifySplitAccountingCommand;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class VerifySplitAccountingCommandTest extends TestCase
{
	private const CONTEXT = [
		'v' => 1,
		'model_id' => 1,
		'schedule_revision' => 'rev1',
		'timezone' => 'Europe/Berlin',
		'day' => ['blocks' => [['06:00', '10:00'], ['13:30', '16:30']]],
		'split' => ['automatic' => true, 'cutoff' => '16:00', 'break_cover' => true],
		'overrides' => ['decision' => 'auto', 'worked' => []],
		'result' => [
			'state' => 'applied',
			'split' => true,
			'reason' => 'clock_out_after_cutoff',
			'excluded' => [['2026-11-16T10:00:00+01:00', '2026-11-16T13:30:00+01:00']],
			'segments' => [
				['2026-11-16T06:00:00+01:00', '2026-11-16T10:00:00+01:00'],
				['2026-11-16T13:30:00+01:00', '2026-11-16T16:30:00+01:00'],
			],
			'break_handling' => 'covered_by_interruption',
			'engine' => 1,
		],
	];

	private function entry(int $id, ?array $context, string $status = TimeEntry::STATUS_COMPLETED): TimeEntry
	{
		$entry = new TimeEntry();
		$entry->setId($id);
		$entry->setUserId('alice');
		$entry->setStartTime(new \DateTime('2026-11-16T06:00:00+01:00'));
		$entry->setEndTime(new \DateTime('2026-11-16T16:30:00+01:00'));
		$entry->setStatus($status);
		if ($context !== null) {
			$entry->setWorkContextJson(json_encode($context));
			$entry->setExcludedWorkSeconds(12600);
		}
		return $entry;
	}

	/**
	 * @param list<TimeEntry> $entries
	 */
	private function command(
		array $entries,
		?callable $recomputeFn = null,
		?TimeEntryMapper $mapper = null,
		?IUserManager $userManager = null,
	): VerifySplitAccountingCommand {
		if ($mapper === null) {
			$mapper = $this->createMock(TimeEntryMapper::class);
			$mapper->method('findByUsersAndDateRange')->willReturn($entries);
			$mapper->method('find')->willReturnCallback(static function (int $id) use ($entries): TimeEntry {
				foreach ($entries as $e) {
					if ((int)$e->getId() === $id) {
						return $e;
					}
				}
				throw new DoesNotExistException('not found');
			});
		}
		if ($userManager === null) {
			$userManager = $this->createMock(IUserManager::class);
			$userManager->method('userExists')->willReturn(true);
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$userManager->method('callForAllUsers')->willReturnCallback(static function (callable $cb) use ($user): void {
				$cb($user);
			});
		}
		$cmd = new class($mapper, $userManager) extends VerifySplitAccountingCommand {
			/** @var callable|null */
			public $recomputeFn = null;

			protected function recomputeAccounting(TimeEntry $entry): void
			{
				if ($this->recomputeFn !== null) {
					($this->recomputeFn)($entry);
				}
			}
		};
		$cmd->recomputeFn = $recomputeFn;
		return $cmd;
	}

	private function runCmd(Command $command, array $options = []): array
	{
		$tester = new CommandTester($command);
		$code = $tester->execute($options);
		return [$code, $tester->getDisplay()];
	}

	public function testMetadata(): void
	{
		$cmd = $this->command([]);
		$this->assertSame('arbeitszeitcheck:verify-split-accounting', $cmd->getName());
		foreach (['user', 'from', 'to', 'entry', 'json'] as $opt) {
			$this->assertTrue($cmd->getDefinition()->hasOption($opt), "missing option $opt");
		}
	}

	public function testVerifiedEntriesExitZero(): void
	{
		// No-op recompute: the clone already carries the stored result — a
		// faithful engine reproduces identical values → verified.
		[$code, $display] = $this->runCmd(
			$this->command([$this->entry(5, self::CONTEXT), $this->entry(6, self::CONTEXT)]),
			['--user' => 'alice'],
		);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('2 verified', $display);
	}

	public function testMismatchExitOneAndNamesFieldAndId(): void
	{
		$recompute = static function (TimeEntry $e): void {
			// Engine produces a different exclusion — drift defect.
			$e->setExcludedWorkSeconds(9000);
			$ctx = $e->getWorkContext();
			$ctx['result']['excluded'] = [['2026-11-16T11:00:00+01:00', '2026-11-16T13:30:00+01:00']];
			$e->setWorkContextJson(json_encode($ctx));
		};
		[$code, $display] = $this->runCmd(
			$this->command([$this->entry(42, self::CONTEXT)], $recompute),
			['--user' => 'alice'],
		);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('MISMATCH entry 42', $display);
		$this->assertStringContainsString('excluded_work_seconds', $display);
		$this->assertStringContainsString('result.excluded', $display);
	}

	public function testUndecodableContextCountedInvalid(): void
	{
		$entry = $this->entry(9, self::CONTEXT);
		$entry->setWorkContextJson('{"v":1,');
		$this->assertTrue($entry->hasInvalidWorkContext());

		[$code, $display] = $this->runCmd(
			$this->command([$entry]),
			['--user' => 'alice'],
		);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('CONTEXT_INVALID entry 9', $display);
	}

	public function testNullContextRowIsOutOfScope(): void
	{
		[$code, $display] = $this->runCmd(
			$this->command([$this->entry(3, null)]),
			['--user' => 'alice'],
		);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('0 context entries scanned', $display);
	}

	public function testActiveEntryIsSkipped(): void
	{
		$entry = $this->entry(8, self::CONTEXT, TimeEntry::STATUS_ACTIVE);
		$entry->setEndTime(null);

		[$code, $display] = $this->runCmd(
			$this->command([$entry]),
			['--user' => 'alice'],
		);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('1 skipped', $display);
	}

	public function testEntryOptionVerifiesSingleEntry(): void
	{
		[$code, $display] = $this->runCmd(
			$this->command([$this->entry(55, self::CONTEXT)]),
			['--entry' => '55'],
		);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('1 verified', $display);
	}

	public function testEntryOptionUnknownIdFails(): void
	{
		[$code, $display] = $this->runCmd(
			$this->command([]),
			['--entry' => '999'],
		);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('not found', $display);
	}

	public function testUnknownUserFails(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(false);
		[$code, $display] = $this->runCmd(
			$this->command([], null, null, $um),
			['--user' => 'ghost'],
		);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('Unknown user', $display);
	}

	public function testInvalidDateRangeFails(): void
	{
		[$code] = $this->runCmd(
			$this->command([]),
			['--user' => 'alice', '--from' => '2026-11-30', '--to' => '2026-11-01'],
		);
		$this->assertSame(Command::FAILURE, $code);
	}

	public function testJsonReportShape(): void
	{
		$recompute = static function (TimeEntry $e): void {
			$e->setExcludedWorkSeconds(1);
		};
		[$code, $display] = $this->runCmd(
			$this->command([$this->entry(1, self::CONTEXT), $this->entry(2, self::CONTEXT)], $recompute),
			['--user' => 'alice', '--json' => true],
		);
		$this->assertSame(Command::FAILURE, $code);
		$json = json_decode($display, true);
		$this->assertIsArray($json);
		$this->assertSame(2, $json['scanned']);
		$this->assertSame(2, $json['mismatched']);
		$this->assertSame([1, 2], array_column($json['mismatched_entries'], 'id'));
	}

	public function testCommandNeverPersists(): void
	{
		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->method('findByUsersAndDateRange')->willReturn([$this->entry(7, self::CONTEXT)]);
		$mapper->expects($this->never())->method('update');
		$mapper->expects($this->never())->method('updateIfPendingApproval');
		$mapper->expects($this->never())->method('insert');
		$mapper->expects($this->never())->method('deleteByUser');

		$recompute = static function (TimeEntry $e): void {
			// Even a mutating "engine" must only ever touch the clone.
			$e->setExcludedWorkSeconds(0);
			$e->setWorkContextJson(json_encode(self::CONTEXT));
		};

		[$code] = $this->runCmd(
			$this->command([], $recompute, $mapper),
			['--user' => 'alice'],
		);
		$this->assertContains($code, [Command::SUCCESS, Command::FAILURE]);
	}
}
