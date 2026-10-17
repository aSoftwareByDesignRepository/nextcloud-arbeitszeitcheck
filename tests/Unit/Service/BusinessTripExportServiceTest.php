<?php

declare(strict_types=1);

/**
 * Export contract: reads frozen day rows only (never recomputes), keeps
 * work/compliance/credit/top-up/export minutes as separate columns,
 * formula-injection guards string cells, and never emits purpose text.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculation;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripSnapshot;
use OCA\ArbeitszeitCheck\Db\BusinessTripSnapshotMapper;
use OCA\ArbeitszeitCheck\Service\BusinessTripExportService;
use OCA\ArbeitszeitCheck\Service\BusinessTripSnapshotService;
use PHPUnit\Framework\TestCase;

final class BusinessTripExportServiceTest extends TestCase
{
	private BusinessTripDayCalculationMapper $dayCalcs;
	private BusinessTripSnapshotMapper $snapshotMapper;
	private BusinessTripExportService $export;

	protected function setUp(): void
	{
		$this->dayCalcs = $this->createMock(BusinessTripDayCalculationMapper::class);
		$this->snapshotMapper = $this->createMock(BusinessTripSnapshotMapper::class);
		$this->export = new BusinessTripExportService(
			$this->dayCalcs,
			new BusinessTripSnapshotService($this->snapshotMapper),
		);
	}

	private function trip(): BusinessTrip
	{
		$t = new BusinessTrip();
		$t->setId(7);
		$t->setUserId('alice');
		$t->setPurpose('must never appear in exports');
		$t->setCurrentSnapshotId(3);
		return $t;
	}

	private function day(string $date, array $overrides = []): BusinessTripDayCalculation
	{
		$d = new BusinessTripDayCalculation();
		$d->setTripId(7);
		$d->setLocalDate(new \DateTime($date));
		$d->setRuleSetId(5);
		$d->setPresenceMinutes(1440);
		$d->setActualWorkMinutes($overrides['work'] ?? 60);
		$d->setActiveTravelMinutes(120);
		$d->setPassiveTravelMinutes(30);
		$d->setComplianceMinutes(600);
		$d->setAccountTargetMinutes(480);
		$d->setAccountTopUpMinutes($overrides['topUp'] ?? 200);
		$d->setExportMinutes($overrides['export'] ?? 210);
		$d->setSnapshotHash($overrides['hash'] ?? 'abc123');
		if (isset($overrides['resultJson'])) {
			$d->setResultJson($overrides['resultJson']);
		}
		return $d;
	}

	public function testCsvKeepsMinuteColumnsSeparateAndSorted(): void
	{
		$this->dayCalcs->method('findForTrip')->willReturn([
			$this->day('2026-03-11'),
			$this->day('2026-03-10'),
		]);
		$csv = $this->export->csv($this->trip());
		$lines = array_values(array_filter(explode("\n", $csv)));
		$this->assertCount(3, $lines); // header + 2 rows
		$this->assertStringStartsWith('local_date,day_kind', $lines[0]);
		// Sorted by date despite mapper order.
		$this->assertStringStartsWith('2026-03-10', $lines[1]);
		$this->assertStringStartsWith('2026-03-11', $lines[2]);
		// Distinct columns stay distinct: work 60, compliance 600, top-up 200, export 210.
		$cols = str_getcsv($lines[1]);
		$this->assertSame('60', $cols[4]);
		$this->assertSame('600', $cols[7]);
		$this->assertSame('200', $cols[9]);
		$this->assertSame('210', $cols[10]);
		$this->assertStringNotContainsString('must never appear', $csv);
	}

	public function testCsvGuardsFormulaInjection(): void
	{
		// A day-kind value smuggled via stored JSON — the write boundary must
		// prefix a quote so spreadsheets do not evaluate it.
		$d = $this->day('2026-03-10');
		$d->setResultJson(json_encode([
			'localDate' => '2026-03-10',
			'dayKind' => '=cmd|"/c calc"!A1',
			'ruleSetId' => 5,
			'presenceMinutes' => 0,
			'actualWorkMinutes' => 0,
			'activeTravelMinutes' => 0,
			'passiveTravelMinutes' => 0,
			'complianceMinutes' => 0,
			'accountTargetMinutes' => 0,
			'accountTopUpMinutes' => 0,
			'exportMinutes' => 0,
			'snapshotHash' => 'h',
		]));
		$this->dayCalcs->method('findForTrip')->willReturn([$d]);
		$csv = $this->export->csv($this->trip());
		$row = str_getcsv(explode("\n", $csv)[1]);
		$this->assertStringStartsWith("'", $row[1]);
	}

	public function testPayrollDtoCarriesOnlyBoundedTopUps(): void
	{
		$snapshot = new BusinessTripSnapshot();
		$snapshot->setPayloadHash('payload-hash-1');
		$this->snapshotMapper->method('findByIdOrNull')->willReturn($snapshot);
		$this->dayCalcs->method('findForTrip')->willReturn([
			$this->day('2026-03-10', ['topUp' => 200, 'export' => 210]),
			$this->day('2026-03-11', ['topUp' => 0, 'export' => 0]),
		]);
		$dto = $this->export->payrollDto($this->trip());
		$this->assertSame('business_trip_top_up', $dto['kind']);
		$this->assertSame('payload-hash-1', $dto['snapshotHash']);
		$this->assertSame(200, $dto['totalTopUpMinutes']);
		$this->assertCount(2, $dto['days']);
		$this->assertArrayNotHasKey('purpose', $dto);
	}

	public function testPayrollDtoWithoutSnapshotHasNullHash(): void
	{
		$this->snapshotMapper->method('findByIdOrNull')->willReturn(null);
		$this->dayCalcs->method('findForTrip')->willReturn([]);
		$dto = $this->export->payrollDto($this->trip());
		$this->assertNull($dto['snapshotHash']);
		$this->assertSame(0, $dto['totalTopUpMinutes']);
	}
}
