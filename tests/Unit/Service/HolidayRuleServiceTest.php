<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\HolidayRule;
use OCA\ArbeitszeitCheck\Db\HolidayRuleMapper;
use OCA\ArbeitszeitCheck\Db\HolidayRuleSkipMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\HolidayRuleService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * HolidayRuleService: create/update/skip/active/convert, dedup and the
 * read helpers the admin payload relies on (B-R5/R6/R7).
 */
class HolidayRuleServiceTest extends TestCase
{
	/** @var HolidayRuleMapper|MockObject */
	private $ruleMapper;

	/** @var HolidayRuleSkipMapper|MockObject */
	private $skipMapper;

	/** @var HolidayMapper|MockObject */
	private $holidayMapper;

	/** @var HolidayService|MockObject */
	private $holidayService;

	/** @var HolidayRuleService */
	private $service;

	/** @var HolidayRule|null last entity passed to insert/update */
	private $lastWritten = null;

	protected function setUp(): void
	{
		parent::setUp();

		$this->lastWritten = null;
		$this->ruleMapper = $this->createMock(HolidayRuleMapper::class);
		$this->skipMapper = $this->createMock(HolidayRuleSkipMapper::class);
		$this->holidayMapper = $this->createMock(HolidayMapper::class);
		$this->holidayService = $this->createMock(HolidayService::class);

		$this->ruleMapper->method('insert')->willReturnCallback(function (HolidayRule $r): HolidayRule {
			$this->lastWritten = $r;
			if ($r->getId() === null) {
				// Entity::setId throws when id already set — use reflection-safe path.
				$ref = new \ReflectionProperty($r, 'id');
				$ref->setValue($r, 42);
			}
			return $r;
		});
		$this->ruleMapper->method('update')->willReturnCallback(function (HolidayRule $r): HolidayRule {
			$this->lastWritten = $r;
			return $r;
		});

		$this->service = new HolidayRuleService(
			$this->ruleMapper,
			$this->skipMapper,
			$this->holidayMapper,
			$this->holidayService
		);
	}

	private function storedRule(array $over = []): HolidayRule
	{
		$rule = new HolidayRule();
		$rule->setId($over['id'] ?? 5);
		$rule->setState($over['state'] ?? 'NW');
		$rule->setAnchor($over['anchor'] ?? HolidayRule::ANCHOR_DATE);
		$rule->setMonth($over['month'] ?? 12);
		$rule->setDay($over['day'] ?? 24);
		$rule->setEasterOffset($over['easter_offset'] ?? 0);
		$rule->setRuleKey(HolidayRule::ruleKeyFor(
			$over['anchor'] ?? HolidayRule::ANCHOR_DATE,
			$over['month'] ?? 12,
			$over['day'] ?? 24,
			$over['easter_offset'] ?? 0
		));
		$rule->setName($over['name'] ?? 'Company day');
		$rule->setNameMsgid($over['name_msgid'] ?? null);
		$rule->setKind($over['kind'] ?? Holiday::KIND_FULL);
		$rule->setScope($over['scope'] ?? Holiday::SCOPE_COMPANY);
		$rule->setStartYear($over['start_year'] ?? 2025);
		$rule->setActive($over['active'] ?? true);
		$rule->setCreatedAt(new \DateTime('2025-01-01'));
		$rule->setUpdatedAt(new \DateTime('2025-01-01'));
		return $rule;
	}

	// ---------- create ----------

	public function testCreateDateRuleDerivesAnchorFromIsoDate(): void
	{
		$this->ruleMapper->method('findIdForStateRuleKeyScope')->willReturn(null);

		$rule = $this->service->createRule([
			'state' => 'nw',
			'date' => '2027-12-24',
			'name' => 'Heiligabend',
			'kind' => 'half',
			'scope' => 'company',
		], 'admin1');

		$this->assertSame('NW', $rule->getState());
		$this->assertSame(HolidayRule::ANCHOR_DATE, $rule->getAnchor());
		$this->assertSame(12, $rule->getMonth());
		$this->assertSame(24, $rule->getDay());
		$this->assertSame('d:12-24', $rule->getRuleKey());
		$this->assertSame(Holiday::KIND_HALF, $rule->getKind());
		$this->assertSame(2027, $rule->getStartYear());
		$this->assertTrue($rule->getActive());
		$this->assertSame('admin1', $rule->getCreatedBy());
	}

	public function testCreateEasterRuleUsesCuratedOffset(): void
	{
		$this->ruleMapper->method('findIdForStateRuleKeyScope')->willReturn(null);

		$rule = $this->service->createRule([
			'state' => 'NW',
			'anchor' => 'easter',
			'easterOffset' => -2,
			'name' => 'Good Friday extra',
			'kind' => 'full',
			'scope' => 'company',
			'startYear' => 2026,
		], 'admin1');

		$this->assertSame(HolidayRule::ANCHOR_EASTER, $rule->getAnchor());
		$this->assertSame('e:-02', $rule->getRuleKey());
		$this->assertSame(2026, $rule->getStartYear());
	}

	public function testCreateRejectsUncuratedEasterOffset(): void
	{
		$this->expectException(BusinessRuleException::class);
		$this->expectExceptionMessage('The holiday rule is invalid.');

		$this->service->createRule([
			'state' => 'NW',
			'anchor' => 'easter',
			'easterOffset' => 3,
			'name' => 'X',
			'kind' => 'full',
			'scope' => 'company',
		], 'admin1');
	}

	public function testCreateRejectsInvalidRegion(): void
	{
		try {
			$this->service->createRule([
				'state' => 'XX',
				'date' => '2027-12-24',
				'name' => 'X',
			], 'admin1');
			$this->fail('expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame('invalid_region', $e->getReasonCode());
		}
	}

	public function testCreateRejectsStatutoryScope(): void
	{
		try {
			$this->service->createRule([
				'state' => 'NW',
				'date' => '2027-12-25',
				'name' => 'X',
				'scope' => 'statutory',
			], 'admin1');
			$this->fail('expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame('holiday_rule_invalid', $e->getReasonCode());
		}
	}

	public function testCreateDuplicateRuleKeyConflicts(): void
	{
		$this->ruleMapper->method('findIdForStateRuleKeyScope')->willReturn(11);

		try {
			$this->service->createRule([
				'state' => 'NW',
				'date' => '2027-12-24',
				'name' => 'Dup',
			], 'admin1');
			$this->fail('expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame('holiday_rule_exists', $e->getReasonCode());
		}
	}

	public function testCreateResetsStateMemo(): void
	{
		$this->ruleMapper->method('findIdForStateRuleKeyScope')->willReturn(null);
		$this->holidayService->expects($this->once())
			->method('resetMemoForState')
			->with('NW');

		$this->service->createRule([
			'state' => 'NW',
			'date' => '2027-12-24',
			'name' => 'X',
		], 'admin1');
	}

	// ---------- update ----------

	public function testUpdateNameOnlyKeepsAnchorAndSkipsRededup(): void
	{
		$stored = $this->storedRule(['name' => 'Old name']);
		$this->ruleMapper->method('findById')->willReturn($stored);
		$this->ruleMapper->expects($this->never())->method('findIdForStateRuleKeyScope');

		$rule = $this->service->updateRule(5, ['name' => 'New name'], 'admin2');

		$this->assertSame('New name', $rule->getName());
		$this->assertSame('d:12-24', $rule->getRuleKey());
		$this->assertSame(12, $rule->getMonth());
		$this->assertSame(24, $rule->getDay());
		$this->assertSame('admin2', $rule->getUpdatedBy());
	}

	public function testUpdateAnchorChangeRerunsDedup(): void
	{
		$stored = $this->storedRule();
		$this->ruleMapper->method('findById')->willReturn($stored);
		$this->ruleMapper->method('findIdForStateRuleKeyScope')->willReturn(99);

		try {
			$this->service->updateRule(5, ['month' => 12, 'day' => 31], 'admin2');
			$this->fail('expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame('holiday_rule_exists', $e->getReasonCode());
		}
	}

	public function testUpdateMissingRuleThrowsNotFound(): void
	{
		$this->ruleMapper->method('findById')->willThrowException(new DoesNotExistException('nope'));

		try {
			$this->service->updateRule(5, ['name' => 'X'], 'admin2');
			$this->fail('expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame('holiday_rule_not_found', $e->getReasonCode());
		}
	}

	// ---------- skips ----------

	public function testAddSkipInsertsForProducedYear(): void
	{
		$stored = $this->storedRule(['start_year' => 2025]);
		$this->ruleMapper->method('findById')->willReturn($stored);
		$this->skipMapper->method('existsForRuleAndYear')->willReturn(false);
		$this->skipMapper->expects($this->once())->method('insert');
		$this->holidayService->expects($this->once())->method('resetMemoForState')->with('NW');

		$this->service->addSkip(5, 2027, 'admin1');
	}

	public function testAddSkipIsIdempotentWhenRowExists(): void
	{
		$stored = $this->storedRule();
		$this->ruleMapper->method('findById')->willReturn($stored);
		$this->skipMapper->method('existsForRuleAndYear')->willReturn(true);
		$this->skipMapper->expects($this->never())->method('insert');

		$this->service->addSkip(5, 2027, 'admin1');
	}

	public function testAddSkipBeforeStartYearRejected(): void
	{
		$stored = $this->storedRule(['start_year' => 2028]);
		$this->ruleMapper->method('findById')->willReturn($stored);

		try {
			$this->service->addSkip(5, 2027, 'admin1');
			$this->fail('expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame('holiday_rule_skip_before_start', $e->getReasonCode());
		}
	}

	public function testAddSkipRejectsInvalidYear(): void
	{
		try {
			$this->service->addSkip(5, 2101, 'admin1');
			$this->fail('expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame('invalid_year', $e->getReasonCode());
		}
	}

	public function testRemoveSkipDeletesAndResetsMemo(): void
	{
		$stored = $this->storedRule();
		$this->ruleMapper->method('findById')->willReturn($stored);
		$this->skipMapper->expects($this->once())
			->method('deleteByRuleAndYear')
			->with(5, 2027);
		$this->holidayService->expects($this->once())->method('resetMemoForState')->with('NW');

		$this->service->removeSkip(5, 2027);
	}

	// ---------- active ----------

	public function testSetActiveFalseStopsSeries(): void
	{
		$stored = $this->storedRule(['active' => true]);
		$this->ruleMapper->method('findById')->willReturn($stored);
		$this->holidayService->expects($this->once())->method('resetMemoForState')->with('NW');

		$rule = $this->service->setActive(5, false, 'admin2');
		$this->assertFalse($rule->getActive());
		$this->assertSame('admin2', $rule->getUpdatedBy());
	}

	// ---------- convert ----------

	public function testConvertRowToRuleDeletesSourceRow(): void
	{
		$row = new Holiday();
		$row->setId(20);
		$row->setState('NW');
		$row->setDate(new \DateTime('2027-12-24'));
		$row->setName('Heiligabend');
		$row->setKind(Holiday::KIND_FULL);
		$row->setScope(Holiday::SCOPE_COMPANY);
		$row->setSource(Holiday::SOURCE_MANUAL);

		$this->holidayMapper->method('findById')->willReturn($row);
		$this->ruleMapper->method('findIdForStateRuleKeyScope')->willReturn(null);
		$this->holidayMapper->expects($this->once())->method('deleteById')->with(20);
		$this->holidayService->expects($this->once())->method('resetMemoForState')->with('NW');

		$rule = $this->service->convertHolidayToRule(20, 'admin1');
		$this->assertSame('d:12-24', $rule->getRuleKey());
		$this->assertSame('Heiligabend', $rule->getName());
		$this->assertSame(2027, $rule->getStartYear());
		$this->assertSame(Holiday::SCOPE_COMPANY, $rule->getScope());
	}

	public function testConvertStatutoryRowRejected(): void
	{
		$row = new Holiday();
		$row->setId(20);
		$row->setState('NW');
		$row->setDate(new \DateTime('2027-12-25'));
		$row->setName('Christmas');
		$row->setKind(Holiday::KIND_FULL);
		$row->setScope(Holiday::SCOPE_STATUTORY);
		$row->setSource(Holiday::SOURCE_GENERATED);

		$this->holidayMapper->method('findById')->willReturn($row);
		$this->holidayMapper->expects($this->never())->method('deleteById');

		try {
			$this->service->convertHolidayToRule(20, 'admin1');
			$this->fail('expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame('holiday_rule_statutory_scope', $e->getReasonCode());
		}
	}

	public function testConvertMissingRowRejected(): void
	{
		$this->holidayMapper->method('findById')->willThrowException(new DoesNotExistException('nope'));

		try {
			$this->service->convertHolidayToRule(20, 'admin1');
			$this->fail('expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame('holiday_not_found', $e->getReasonCode());
		}
	}

	// ---------- read helpers ----------

	public function testSkippedInstancesForStateYearResolvesDates(): void
	{
		$ruleA = $this->storedRule(['id' => 5, 'month' => 12, 'day' => 24, 'name' => 'A']);
		$ruleB = $this->storedRule(['id' => 6, 'month' => 6, 'day' => 15, 'name' => 'B']);
		$this->ruleMapper->method('findByState')->willReturn([$ruleA, $ruleB]);
		$this->skipMapper->method('yearsForRuleIds')->willReturn([5 => [2027]]);

		$out = $this->service->skippedInstancesForStateYear('NW', 2027);
		$this->assertCount(1, $out);
		$this->assertSame(5, $out[0]['rule_id']);
		$this->assertSame('2027-12-24', $out[0]['date']);
		$this->assertSame('A', $out[0]['name']);
	}

	public function testRuleCoveringDateFindsNonSkippedRule(): void
	{
		$rule = $this->storedRule(['id' => 5, 'month' => 12, 'day' => 24]);
		$this->ruleMapper->method('findActiveByState')->willReturn([$rule]);
		$this->skipMapper->method('yearsForRuleIds')->willReturn([]);

		$hit = $this->service->ruleCoveringDate('NW', '2027-12-24');
		$this->assertNotNull($hit);
		$this->assertSame(5, $hit->getId());
		$this->assertNull($this->service->ruleCoveringDate('NW', '2027-12-25'));
	}

	public function testRuleCoveringDateIgnoresSkippedYear(): void
	{
		$rule = $this->storedRule(['id' => 5, 'month' => 12, 'day' => 24]);
		$this->ruleMapper->method('findActiveByState')->willReturn([$rule]);
		$this->skipMapper->method('yearsForRuleIds')->willReturn([5 => [2027]]);

		$this->assertNull($this->service->ruleCoveringDate('NW', '2027-12-24'));
	}

	public function testListForStateAttachesSkippedYears(): void
	{
		$rule = $this->storedRule(['id' => 5]);
		$this->ruleMapper->method('findByState')->willReturn([$rule]);
		$this->skipMapper->method('yearsForRuleIds')->willReturn([5 => [2026, 2027]]);

		$out = $this->service->listForState('NW');
		$this->assertCount(1, $out);
		$this->assertSame([2026, 2027], $out[0]['skipped_years']);
		$this->assertSame('d:12-24', $out[0]['rule_key']);
	}
}
