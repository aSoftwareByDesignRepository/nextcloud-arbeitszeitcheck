<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\HolidayRule;
use OCA\ArbeitszeitCheck\Db\HolidayRuleMapper;
use OCA\ArbeitszeitCheck\Db\HolidayRuleSkipMapper;
use OCA\ArbeitszeitCheck\Db\HolidaySuppressionMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Read-time merge of recurring holiday rules (B-R2, EC-B1/2/3/11/12/13).
 *
 * Every test goes through the public getHolidaysForRange() DTO seam — the
 * same payload the admin calendar, absence planning and the /api/holidays
 * endpoint consume.
 */
class HolidayServiceRuleMergeTest extends TestCase
{
	/** @var HolidayMapper|MockObject */
	private $holidayMapper;

	/** @var HolidayRuleMapper|MockObject */
	private $ruleMapper;

	/** @var HolidayRuleSkipMapper|MockObject */
	private $ruleSkipMapper;

	/** @var HolidayService */
	private $service;

	/** @var array<string,string> */
	private $configStore = [];

	/** @var Holiday[] persisted rows served by findByStateAndYear */
	private $persistedRows = [];

	/** @var HolidayRule[] active rules served by findActiveByState */
	private $activeRules = [];

	/** @var array<int,int[]> rule_id => skipped years */
	private $skippedYears = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->persistedRows = [];
		$this->activeRules = [];
		$this->skippedYears = [];

		$this->holidayMapper = $this->createMock(HolidayMapper::class);
		$this->ruleMapper = $this->createMock(HolidayRuleMapper::class);
		$this->ruleSkipMapper = $this->createMock(HolidayRuleSkipMapper::class);
		$suppressionMapper = $this->createMock(HolidaySuppressionMapper::class);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$config = $this->createMock(IConfig::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cache = $this->createMock(ICache::class);
		$l10n = $this->createMock(IL10N::class);
		$logger = $this->createMock(LoggerInterface::class);

		$cacheFactory->method('createDistributed')->willReturn($cache);
		// Cold cache every read so the merge path (not the hydration path)
		// is exercised; merge must also run on hydration, tested separately.
		$cache->method('get')->willReturn(null);

		// Auto-restore OFF + statutory present ⇒ reconcile is a no-op; the
		// test isolates the rule merge, not statutory seeding.
		$this->configStore = ['statutory_auto_reseed' => '0', 'german_state' => 'NW'];
		$config->method('getAppValue')->willReturnCallback(function (string $app, string $key, $default) {
			return $this->configStore[$key] ?? $default;
		});
		$this->holidayMapper->method('hasStatutoryHolidaysForStateAndYear')->willReturn(true);
		$this->holidayMapper->method('findByStateAndYear')->willReturnCallback(
			fn () => $this->persistedRows
		);

		$this->ruleMapper->method('findActiveByState')->willReturnCallback(
			fn () => $this->activeRules
		);
		$this->ruleSkipMapper->method('yearsForRuleIds')->willReturnCallback(
			fn () => $this->skippedYears
		);

		$l10n->method('t')->willReturnCallback(static fn (string $msg): string => 'T:' . $msg);
		$userSettingsMapper->method('getStringSetting')->willReturn('');

		$this->service = new HolidayService(
			$this->holidayMapper,
			$suppressionMapper,
			$this->ruleMapper,
			$this->ruleSkipMapper,
			$userSettingsMapper,
			$config,
			$cacheFactory,
			$l10n,
			$logger
		);
	}

	private function makeDateRule(int $id, int $month, int $day, string $name = 'Rule Day', int $startYear = 2020, string $scope = Holiday::SCOPE_COMPANY): HolidayRule
	{
		$rule = new HolidayRule();
		$rule->setId($id);
		$rule->setState('NW');
		$rule->setAnchor(HolidayRule::ANCHOR_DATE);
		$rule->setMonth($month);
		$rule->setDay($day);
		$rule->setEasterOffset(0);
		$rule->setRuleKey(HolidayRule::ruleKeyFor(HolidayRule::ANCHOR_DATE, $month, $day, 0));
		$rule->setName($name);
		$rule->setNameMsgid(null);
		$rule->setKind(Holiday::KIND_FULL);
		$rule->setScope($scope);
		$rule->setStartYear($startYear);
		$rule->setActive(true);
		return $rule;
	}

	private function makePersistedRow(string $ymd, string $scope = Holiday::SCOPE_COMPANY): Holiday
	{
		$h = new Holiday();
		$h->setState('NW');
		$h->setDate(new \DateTime($ymd));
		$h->setName('Persisted row');
		$h->setKind(Holiday::KIND_FULL);
		$h->setScope($scope);
		$h->setSource(Holiday::SOURCE_MANUAL);
		return $h;
	}

	/** @return array<int,array<string,mixed>> */
	private function range2027(): array
	{
		return $this->service->getHolidaysForRange(
			'NW',
			new \DateTime('2027-01-01'),
			new \DateTime('2027-12-31')
		);
	}

	public function testActiveRuleProducesRecurringInstanceDto(): void
	{
		$this->activeRules = [$this->makeDateRule(7, 12, 24, 'Heiligabend (Firma)')];

		$dtos = $this->range2027();
		$this->assertCount(1, $dtos);
		$this->assertSame('2027-12-24', $dtos[0]['date']);
		$this->assertSame('Heiligabend (Firma)', $dtos[0]['name']);
		$this->assertSame('rule', $dtos[0]['source']);
		$this->assertTrue($dtos[0]['recurring']);
		$this->assertSame(7, $dtos[0]['rule_id']);
		// EC-B13: synthesized instances must never carry a row id — the UI
		// must not be able to delete/edit them as persisted rows.
		$this->assertNull($dtos[0]['id']);
	}

	public function testSkippedYearSuppressesInstance(): void
	{
		$this->activeRules = [$this->makeDateRule(7, 12, 24)];
		$this->skippedYears = [7 => [2027]];

		$this->assertCount(0, $this->range2027());
	}

	public function testOtherYearsStillProduceWhenOneYearSkipped(): void
	{
		$this->activeRules = [$this->makeDateRule(7, 12, 24)];
		$this->skippedYears = [7 => [2026]];

		$dtos = $this->range2027();
		$this->assertCount(1, $dtos);
		$this->assertSame('2027-12-24', $dtos[0]['date']);
	}

	public function testPersistedRowWinsOverRuleInstance(): void
	{
		$this->activeRules = [$this->makeDateRule(7, 12, 24, 'Rule name')];
		$this->persistedRows = [$this->makePersistedRow('2027-12-24')];

		$dtos = $this->range2027();
		$this->assertCount(1, $dtos);
		$this->assertSame('Persisted row', $dtos[0]['name']);
		$this->assertFalse($dtos[0]['recurring']);
		$this->assertNull($dtos[0]['rule_id']);
	}

	public function testEasterRuleResolvesNamedMovableDay(): void
	{
		$rule = new HolidayRule();
		$rule->setId(9);
		$rule->setState('NW');
		$rule->setAnchor(HolidayRule::ANCHOR_EASTER);
		$rule->setMonth(0);
		$rule->setDay(0);
		$rule->setEasterOffset(-2);
		$rule->setRuleKey(HolidayRule::ruleKeyFor(HolidayRule::ANCHOR_EASTER, 0, 0, -2));
		$rule->setName('Karfreitag extra');
		$rule->setNameMsgid(null);
		$rule->setKind(Holiday::KIND_FULL);
		$rule->setScope(Holiday::SCOPE_COMPANY);
		$rule->setStartYear(2020);
		$rule->setActive(true);
		$this->activeRules = [$rule];

		$dtos = $this->range2027();
		$this->assertCount(1, $dtos);
		// Easter 2027 = March 28; easter-2 = Good Friday 2027-03-26.
		$this->assertSame('2027-03-26', $dtos[0]['date']);
		$this->assertSame(9, $dtos[0]['rule_id']);
	}

	public function testStartYearIsNotRetroactive(): void
	{
		$this->activeRules = [$this->makeDateRule(7, 12, 24, 'X', 2028)];

		$this->assertCount(0, $this->range2027());
	}

	public function testLeapDayRuleFiresOnlyInLeapYears(): void
	{
		$this->activeRules = [$this->makeDateRule(7, 2, 29)];

		$this->assertCount(0, $this->range2027()); // 2027 is not a leap year

		$dtos2028 = $this->service->getHolidaysForRange(
			'NW',
			new \DateTime('2028-01-01'),
			new \DateTime('2028-12-31')
		);
		$this->assertCount(1, $dtos2028);
		$this->assertSame('2028-02-29', $dtos2028[0]['date']);
	}

	public function testTwoRulesSameDateFirstWins(): void
	{
		// Same resolved date from two different anchors — the first rule in
		// mapper order claims the date; the second is suppressed (dedup).
		$fixed = $this->makeDateRule(3, 3, 26, 'Fixed day');
		$easter = new HolidayRule();
		$easter->setId(4);
		$easter->setState('NW');
		$easter->setAnchor(HolidayRule::ANCHOR_EASTER);
		$easter->setMonth(0);
		$easter->setDay(0);
		$easter->setEasterOffset(-2); // Good Friday 2027-03-26 — same date.
		$easter->setRuleKey(HolidayRule::ruleKeyFor(HolidayRule::ANCHOR_EASTER, 0, 0, -2));
		$easter->setName('Easter day');
		$easter->setNameMsgid(null);
		$easter->setKind(Holiday::KIND_FULL);
		$easter->setScope(Holiday::SCOPE_CUSTOM);
		$easter->setStartYear(2020);
		$easter->setActive(true);
		$this->activeRules = [$fixed, $easter];

		$dtos = $this->range2027();
		$this->assertCount(1, $dtos);
		$this->assertSame('Fixed day', $dtos[0]['name']);
		$this->assertSame(3, $dtos[0]['rule_id']);
	}

	public function testCatalogSourcedRuleNameTranslatesViaMsgid(): void
	{
		$rule = $this->makeDateRule(7, 12, 24);
		$rule->setNameMsgid('Heiligabend');
		$this->activeRules = [$rule];

		$dtos = $this->range2027();
		$this->assertCount(1, $dtos);
		// EC-B12: catalog msgid rules translate like statutory days.
		$this->assertSame('T:Heiligabend', $dtos[0]['name']);
	}

	public function testRuleAndPersistedRowsMergeSorted(): void
	{
		$this->activeRules = [$this->makeDateRule(7, 12, 24, 'Rule day')];
		$this->persistedRows = [$this->makePersistedRow('2027-06-15')];

		$dtos = $this->range2027();
		$this->assertCount(2, $dtos);
		$this->assertSame('2027-06-15', $dtos[0]['date']);
		$this->assertSame('2027-12-24', $dtos[1]['date']);
	}
}
