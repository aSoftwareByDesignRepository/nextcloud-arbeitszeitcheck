<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineScenario;
use PHPUnit\Framework\TestCase;

final class LegacyBaselineScenarioTest extends TestCase
{
	private string $path;

	protected function setUp(): void
	{
		$this->path = dirname(__DIR__, 2) . '/fixtures/legacy-baseline-scenario.json';
	}

	public function testCommittedScenarioCoversLegacyDomainsAndEdges(): void
	{
		$scenario = LegacyBaselineScenario::load($this->path);

		self::assertCount(4, $scenario['users']);
		self::assertCount(4, $scenario['timeEntries']);
		self::assertCount(11, $scenario['absences']);
		self::assertCount(3, $scenario['months']);
		self::assertCount(4, $scenario['idempotency']);
		self::assertCount(2, $scenario['bridges']);
	}

	public function testRejectsMissingGlobalVacationMode(): void
	{
		$scenario = $this->scenario();
		array_pop($scenario['vacationModes']);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('global vacation modes');
		LegacyBaselineScenario::load($this->write($scenario));
	}

	public function testRejectsMissingLegacyAbsenceStatus(): void
	{
		$scenario = $this->scenario();
		foreach ($scenario['absences'] as &$absence) {
			if ($absence['status'] === 'cancelled') {
				$absence['status'] = 'rejected';
			}
		}
		unset($absence);

		$this->expectException(\RuntimeException::class);
		LegacyBaselineScenario::load($this->write($scenario));
	}

	public function testRejectsDuplicateStableKeys(): void
	{
		$scenario = $this->scenario();
		$scenario['timeEntries'][1]['key'] = $scenario['timeEntries'][0]['key'];

		$this->expectException(\RuntimeException::class);
		LegacyBaselineScenario::load($this->write($scenario));
	}

	public function testRejectsUnknownCrossReference(): void
	{
		$scenario = $this->scenario();
		$scenario['substitutions'][0]['absence'] = 'missing';

		$this->expectException(\RuntimeException::class);
		LegacyBaselineScenario::load($this->write($scenario));
	}

	public function testRejectsScenarioWithoutGhostMembership(): void
	{
		$scenario = $this->scenario();
		$scenario['users'][3]['staleTeamRows'] = [];

		$this->expectException(\RuntimeException::class);
		LegacyBaselineScenario::load($this->write($scenario));
	}

	public function testRejectsScenarioWithoutCompletedBreakEntry(): void
	{
		$scenario = $this->scenario();
		foreach ($scenario['timeEntries'] as &$entry) {
			$entry['breaks'] = [];
		}
		unset($entry);

		$this->expectException(\RuntimeException::class);
		LegacyBaselineScenario::load($this->write($scenario));
	}

	private function scenario(): array
	{
		return json_decode((string)file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
	}

	private function write(array $scenario): string
	{
		$path = tempnam(sys_get_temp_dir(), 'azc-legacy-');
		self::assertNotFalse($path);
		file_put_contents($path, json_encode($scenario, JSON_THROW_ON_ERROR));
		return $path;
	}
}
