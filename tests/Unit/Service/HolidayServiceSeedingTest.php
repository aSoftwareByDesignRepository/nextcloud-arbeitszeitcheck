<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
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
 * DB seeding and cache behaviour for HolidayService (state-aware holidays).
 */
class HolidayServiceSeedingTest extends TestCase
{
	/** @var HolidayMapper|MockObject */
	private $holidayMapper;

	/** @var HolidaySuppressionMapper|MockObject */
	private $suppressionMapper;

	/** @var UserSettingsMapper|MockObject */
	private $userSettingsMapper;

	/** @var IConfig|MockObject */
	private $config;

	/** @var ICacheFactory|MockObject */
	private $cacheFactory;

	/** @var ICache|MockObject */
	private $cache;

	/** @var IL10N|MockObject */
	private $l10n;

	/** @var LoggerInterface|MockObject */
	private $logger;

	/** @var HolidayService */
	private $service;

	/** @var array<string,string> */
	private $configStore = [];

	/** @var string[] "STATE|Y-m-d" entries currently suppressed */
	private $suppressedDates = [];

	/** @var string[] "STATE|Y-m-d" entries passed to removeSuppression() */
	private $removedSuppressions = [];

	/** @var array<string,int> date Y-m-d => holiday id for findIdForStateDateScope */
	private $existingStatutoryIdsByDate = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->holidayMapper = $this->createMock(HolidayMapper::class);
		$this->suppressionMapper = $this->createMock(HolidaySuppressionMapper::class);
		$this->suppressedDates = [];
		$this->removedSuppressions = [];
		$this->existingStatutoryIdsByDate = [];
		// A single mutable stub: tests set $this->suppressedDates instead of
		// re-stubbing (re-stubbing the same method does not override the first
		// configuration in PHPUnit 9).
		$this->suppressionMapper
			->method('isSuppressed')
			->willReturnCallback(function (string $state, string $date): bool {
				return in_array($state . '|' . $date, $this->suppressedDates, true);
			});
		$this->suppressionMapper
			->method('removeSuppression')
			->willReturnCallback(function (string $state, string $date): void {
				$key = $state . '|' . $date;
				$this->removedSuppressions[] = $key;
				$this->suppressedDates = array_values(array_filter(
					$this->suppressedDates,
					static fn (string $x): bool => $x !== $key
				));
			});
		$this->userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$this->config = $this->createMock(IConfig::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(ICache::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->cacheFactory
			->method('createDistributed')
			->with('arbeitszeitcheck_holidays')
			->willReturn($this->cache);

		$this->userSettingsMapper
			->method('getStringSetting')
			->willReturn('');

		$this->configStore = [];
		$this->config
			->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, $default) {
				if ($key === 'german_state') {
					return $this->configStore[$key] ?? 'NW';
				}
				if (array_key_exists($key, $this->configStore)) {
					return $this->configStore[$key];
				}
				return $default;
			});
		$this->config
			->method('setAppValue')
			->willReturnCallback(function (string $app, string $key, string $value): void {
				$this->configStore[$key] = $value;
			});

		$this->l10n
			->method('t')
			->willReturnCallback(static function (string $msg) {
				return $msg;
			});

		// Mutable stub — tests populate $this->existingStatutoryIdsByDate.
		$this->holidayMapper
			->method('findIdForStateDateScope')
			->willReturnCallback(function (string $state, string $dateYmd, string $scope): ?int {
				return $this->existingStatutoryIdsByDate[$dateYmd] ?? null;
			});

		$ruleMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\HolidayRuleMapper::class);
		$ruleMapper->method('findActiveByState')->willReturn([]);
		$ruleSkipMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\HolidayRuleSkipMapper::class);
		$ruleSkipMapper->method('yearsForRuleIds')->willReturn([]);

		$this->service = new HolidayService(
			$this->holidayMapper,
			$this->suppressionMapper,
			$ruleMapper,
			$ruleSkipMapper,
			$this->userSettingsMapper,
			$this->config,
			$this->cacheFactory,
			$this->l10n,
			$this->logger
		);
	}

	public function testSeedingHappensOnlyOncePerStateYear(): void
	{
		$state = 'NW';
		$year = 2030;

		$holiday = new Holiday();
		$holiday->setState($state);
		$holiday->setName('New Year');
		$holiday->setKind(Holiday::KIND_FULL);
		$holiday->setScope(Holiday::SCOPE_STATUTORY);
		$holiday->setSource(Holiday::SOURCE_GENERATED);
		$holiday->setDate(new \DateTime("$year-01-01"));
		$holiday->setCreatedAt(new \DateTime());
		$holiday->setUpdatedAt(new \DateTime());

		$this->holidayMapper
			->method('findByStateAndYear')
			->with($state, $year)
			->willReturn([$holiday]);

		$this->holidayMapper
			->method('hasStatutoryHolidaysForStateAndYear')
			->with($state, $year)
			->willReturnOnConsecutiveCalls(false, true);

		$this->holidayMapper
			->expects($this->atLeastOnce())
			->method('insert');

		$result1 = $this->service->getHolidaysForRange(
			$state,
			new \DateTime("$year-01-01"),
			new \DateTime("$year-12-31")
		);
		$this->assertNotEmpty($result1);

		$result2 = $this->service->getHolidaysForRange(
			$state,
			new \DateTime("$year-01-01"),
			new \DateTime("$year-12-31")
		);
		$this->assertNotEmpty($result2);
	}

	public function testAutoReseedOffSkipsReseedWhenAllDatesSuppressed(): void
	{
		$state = 'NW';
		$year = 2031;
		$this->configStore['statutory_auto_reseed'] = '0';
		// Fully emptied year: every statutory delete recorded a per-date
		// suppression (migration 1034 converted the legacy "initialized" flag
		// into exactly these rows). The year must stay empty.
		foreach (array_keys(HolidayService::getGermanPublicHolidaysForYear($year, $state)) as $dateYmd) {
			$this->suppressedDates[] = $state . '|' . $dateYmd;
		}

		$this->holidayMapper
			->method('hasStatutoryHolidaysForStateAndYear')
			->with($state, $year)
			->willReturn(false);

		$this->holidayMapper
			->expects($this->never())
			->method('insert');

		$this->holidayMapper
			->method('findByStateAndYear')
			->with($state, $year)
			->willReturn([]);

		$result = $this->service->getHolidaysForRange(
			$state,
			new \DateTime("$year-01-01"),
			new \DateTime("$year-12-31")
		);

		$this->assertSame([], $result);
	}

	public function testAutoReseedOnRestoresMissingStatutoryEvenWhenSomeRemain(): void
	{
		$state = 'NW';
		$year = 2032;
		$this->configStore['statutory_auto_reseed'] = '1';

		$remaining = new Holiday();
		$remaining->setState($state);
		$remaining->setName('Labour Day');
		$remaining->setKind(Holiday::KIND_FULL);
		$remaining->setScope(Holiday::SCOPE_STATUTORY);
		$remaining->setSource(Holiday::SOURCE_GENERATED);
		$remaining->setDate(new \DateTime("$year-05-01"));
		$remaining->setCreatedAt(new \DateTime());
		$remaining->setUpdatedAt(new \DateTime());

		$this->holidayMapper
			->method('hasStatutoryHolidaysForStateAndYear')
			->willReturn(true);

		$this->holidayMapper
			->expects($this->atLeastOnce())
			->method('insert');

		$this->holidayMapper
			->method('findByStateAndYear')
			->with($state, $year)
			->willReturn([$remaining]);

		$this->cache
			->expects($this->atLeastOnce())
			->method('remove');

		$result = $this->service->getHolidaysForRange(
			$state,
			new \DateTime("$year-01-01"),
			new \DateTime("$year-12-31")
		);

		$this->assertNotEmpty($result);
	}

	public function testAutoReseedOnIgnoresAndClearsStaleSuppression(): void
	{
		$state = 'NW';
		$year = 2034;
		$this->configStore['statutory_auto_reseed'] = '1';

		// A date that was suppressed while auto-restore was OFF.
		$suppressedDate = "$year-01-01"; // New Year — always statutory
		$this->suppressedDates = [$state . '|' . $suppressedDate];

		// The previously deleted statutory row is gone from the DB.
		$this->existingStatutoryIdsByDate = [];

		$this->holidayMapper
			->method('hasStatutoryHolidaysForStateAndYear')
			->willReturn(true);

		$this->holidayMapper
			->method('findByStateAndYear')
			->willReturn([]);

		// … and re-insert the statutory date (along with the rest of the catalog).
		$this->holidayMapper
			->expects($this->atLeastOnce())
			->method('insert');

		$this->service->getHolidaysForRange(
			$state,
			new \DateTime("$year-01-01"),
			new \DateTime("$year-12-31")
		);

		// Auto-restore ON must clear the stale opt-out for the restored date.
		$this->assertContains(
			$state . '|' . $suppressedDate,
			$this->removedSuppressions,
			'Stale suppression must be cleared when auto-restore is on.'
		);
	}

	/**
	 * Sachsen-Anhalt must not keep Corpus Christi / All Saints from legacy nationwide seed (issue #13).
	 */
	public function testAutoReseedOnPrunesStaleCorpusChristiForSaxonyAnhalt(): void
	{
		$state = 'ST';
		$year = 2026;

		$this->configStore['statutory_auto_reseed'] = '1';

		$staleCorpus = new Holiday();
		$staleCorpus->setId(6001);
		$staleCorpus->setState($state);
		$staleCorpus->setName('Corpus Christi');
		$staleCorpus->setKind(Holiday::KIND_FULL);
		$staleCorpus->setScope(Holiday::SCOPE_STATUTORY);
		$staleCorpus->setSource(Holiday::SOURCE_GENERATED);
		$staleCorpus->setDate(new \DateTime('2026-06-04'));
		$staleCorpus->setCreatedAt(new \DateTime());
		$staleCorpus->setUpdatedAt(new \DateTime());

		$staleAllSaints = new Holiday();
		$staleAllSaints->setId(6002);
		$staleAllSaints->setState($state);
		$staleAllSaints->setName('All Saints');
		$staleAllSaints->setKind(Holiday::KIND_FULL);
		$staleAllSaints->setScope(Holiday::SCOPE_STATUTORY);
		$staleAllSaints->setSource(Holiday::SOURCE_GENERATED);
		$staleAllSaints->setDate(new \DateTime('2026-11-01'));
		$staleAllSaints->setCreatedAt(new \DateTime());
		$staleAllSaints->setUpdatedAt(new \DateTime());

		$this->holidayMapper
			->method('hasStatutoryHolidaysForStateAndYear')
			->willReturn(true);

		// Catalog dates already present — only pruning of stale rows matters.
		foreach (array_keys(\OCA\ArbeitszeitCheck\Support\HolidayCatalogResolver::statutoryHolidaysForRegionAndYear($state, 2026)) as $ymd) {
			$this->existingStatutoryIdsByDate[$ymd] = 9001;
		}

		$existingFull = new Holiday();
		$existingFull->setId(9001);
		$existingFull->setState($state);
		$existingFull->setName('Existing');
		$existingFull->setKind(Holiday::KIND_FULL);
		$existingFull->setScope(Holiday::SCOPE_STATUTORY);
		$existingFull->setSource(Holiday::SOURCE_GENERATED);
		$existingFull->setDate(new \DateTime('2026-01-01'));
		$existingFull->setCreatedAt(new \DateTime());
		$existingFull->setUpdatedAt(new \DateTime());
		$this->holidayMapper
			->method('findById')
			->willReturn($existingFull);

		$this->holidayMapper
			->method('findByStateAndYear')
			->willReturn([$staleCorpus, $staleAllSaints]);

		$deletedIds = [];
		$this->holidayMapper
			->method('deleteById')
			->willReturnCallback(static function (int $id) use (&$deletedIds): void {
				$deletedIds[] = $id;
			});

		$this->service->getHolidaysForRange(
			$state,
			new \DateTime('2026-01-01'),
			new \DateTime('2026-12-31')
		);

		$this->assertContains(6001, $deletedIds);
		$this->assertContains(6002, $deletedIds);
	}

	public function testAutoReseedOnPrunesGeneratedStatutoryNotInStateCatalog(): void
	{
		$state = 'NW';
		$year = 2035;
		$this->configStore['statutory_auto_reseed'] = '1';

		// Reformation Day is NOT a NW statutory holiday — stale generated row
		// from before the catalog became Bundesland-aware.
		$stale = new Holiday();
		$stale->setId(4242);
		$stale->setState($state);
		$stale->setName('Reformation Day');
		$stale->setKind(Holiday::KIND_FULL);
		$stale->setScope(Holiday::SCOPE_STATUTORY);
		$stale->setSource(Holiday::SOURCE_GENERATED);
		$stale->setDate(new \DateTime("$year-10-31"));
		$stale->setCreatedAt(new \DateTime());
		$stale->setUpdatedAt(new \DateTime());

		// A legitimately added manual statutory entry must NOT be pruned even if
		// it is not in the catalog (admin intent is respected).
		$manual = new Holiday();
		$manual->setId(99);
		$manual->setState($state);
		$manual->setName('Local custom statutory');
		$manual->setKind(Holiday::KIND_FULL);
		$manual->setScope(Holiday::SCOPE_STATUTORY);
		$manual->setSource(Holiday::SOURCE_MANUAL);
		$manual->setDate(new \DateTime("$year-11-15"));
		$manual->setCreatedAt(new \DateTime());
		$manual->setUpdatedAt(new \DateTime());

		$this->holidayMapper
			->method('hasStatutoryHolidaysForStateAndYear')
			->willReturn(true);

		foreach (array_keys(\OCA\ArbeitszeitCheck\Support\HolidayCatalogResolver::statutoryHolidaysForRegionAndYear($state, $year)) as $ymd) {
			$this->existingStatutoryIdsByDate[$ymd] = 9001;
		}

		$existingFull = new Holiday();
		$existingFull->setId(9001);
		$existingFull->setState($state);
		$existingFull->setName('Existing');
		$existingFull->setKind(Holiday::KIND_FULL);
		$existingFull->setScope(Holiday::SCOPE_STATUTORY);
		$existingFull->setSource(Holiday::SOURCE_GENERATED);
		$existingFull->setDate(new \DateTime("$year-01-01"));
		$existingFull->setCreatedAt(new \DateTime());
		$existingFull->setUpdatedAt(new \DateTime());
		$this->holidayMapper
			->method('findById')
			->willReturn($existingFull);

		$this->holidayMapper
			->method('findByStateAndYear')
			->willReturn([$stale, $manual]);

		$deletedIds = [];
		$this->holidayMapper
			->method('deleteById')
			->willReturnCallback(static function (int $id) use (&$deletedIds): void {
				$deletedIds[] = $id;
			});

		$this->service->getHolidaysForRange(
			$state,
			new \DateTime("$year-01-01"),
			new \DateTime("$year-12-31")
		);

		$this->assertContains(4242, $deletedIds, 'Stale generated statutory row must be pruned.');
		$this->assertNotContains(99, $deletedIds, 'Manual statutory entry must never be auto-pruned.');
	}

	/**
	 * E-6: a generated statutory row seeded as full must be corrected to half
	 * when the catalog says half (Zurich Sechseläuten).
	 */
	public function testSeedReconcilesGeneratedStatutoryKindToHalf(): void
	{
		$state = 'CH-ZH';
		$year = 2026;
		$this->configStore['statutory_auto_reseed'] = '1';

		$staleFull = new Holiday();
		$staleFull->setId(77);
		$staleFull->setState($state);
		$staleFull->setName('Sechseläuten');
		$staleFull->setKind(Holiday::KIND_FULL);
		$staleFull->setScope(Holiday::SCOPE_STATUTORY);
		$staleFull->setSource(Holiday::SOURCE_GENERATED);
		$staleFull->setDate(new \DateTime('2026-04-20'));
		$staleFull->setCreatedAt(new \DateTime());
		$staleFull->setUpdatedAt(new \DateTime());

		$otherFull = new Holiday();
		$otherFull->setId(9001);
		$otherFull->setState($state);
		$otherFull->setName('New Year');
		$otherFull->setKind(Holiday::KIND_FULL);
		$otherFull->setScope(Holiday::SCOPE_STATUTORY);
		$otherFull->setSource(Holiday::SOURCE_GENERATED);
		$otherFull->setDate(new \DateTime('2026-01-01'));
		$otherFull->setCreatedAt(new \DateTime());
		$otherFull->setUpdatedAt(new \DateTime());

		$this->holidayMapper
			->method('hasStatutoryHolidaysForStateAndYear')
			->willReturn(true);

		foreach (array_keys(\OCA\ArbeitszeitCheck\Support\HolidayCatalogResolver::statutoryHolidaysForRegionAndYear($state, $year)) as $ymd) {
			$this->existingStatutoryIdsByDate[$ymd] = ($ymd === '2026-04-20') ? 77 : 9001;
		}

		$this->holidayMapper
			->method('findById')
			->willReturnCallback(static function (int $id) use ($staleFull, $otherFull): Holiday {
				return $id === 77 ? $staleFull : $otherFull;
			});

		$this->holidayMapper
			->method('findByStateAndYear')
			->willReturn([$staleFull]);

		$updated = [];
		$this->holidayMapper
			->expects($this->atLeastOnce())
			->method('update')
			->willReturnCallback(static function (Holiday $h) use (&$updated): Holiday {
				$updated[] = $h;
				return $h;
			});

		$this->service->getHolidaysForRange(
			$state,
			new \DateTime('2026-01-01'),
			new \DateTime('2026-12-31')
		);

		$kinds = array_map(static fn (Holiday $h): string => (string)$h->getKind(), $updated);
		$this->assertContains(Holiday::KIND_HALF, $kinds, 'Sechseläuten must be corrected to half');
	}

	public function testSuppressedStatutoryDateCountsAsWorkingDayWhenAutoReseedOff(): void
	{
		$state = 'NW';
		$year = 2033;
		$labourDay = "$year-05-01"; // suppressed; working-day probe uses Tuesday 05-03
		$this->configStore['statutory_auto_reseed'] = '0';

		$this->suppressedDates = [$state . '|' . $labourDay];

		$companyOnly = new Holiday();
		$companyOnly->setState($state);
		$companyOnly->setName('Company day');
		$companyOnly->setKind(Holiday::KIND_FULL);
		$companyOnly->setScope(Holiday::SCOPE_COMPANY);
		$companyOnly->setSource(Holiday::SOURCE_MANUAL);
		$companyOnly->setDate(new \DateTime("$year-04-30"));
		$companyOnly->setCreatedAt(new \DateTime());
		$companyOnly->setUpdatedAt(new \DateTime());

		$this->holidayMapper
			->method('hasStatutoryHolidaysForStateAndYear')
			->willReturn(true);

		$this->holidayMapper
			->method('findByStateAndYear')
			->willReturn([$companyOnly]);

		$this->holidayMapper
			->expects($this->never())
			->method('insert');

		$userId = 'user-a';
		$this->userSettingsMapper
			->method('getStringSetting')
			->willReturn($state);

		// 2033-05-03 is a Tuesday (Labour Day 2033-05-01 is a Sunday).
		$start = new \DateTime("$year-05-03");
		$end = new \DateTime("$year-05-03");
		$days = $this->service->computeWorkingDaysForUser($userId, $start, $end);

		$this->assertSame(1.0, $days);
	}

	public function testPerDayHolidayProbesReconcileOnlyOncePerStateYear(): void
	{
		$state = 'NW';
		$year = 2036;
		$this->configStore['statutory_auto_reseed'] = '1';

		$labourDay = new Holiday();
		$labourDay->setState($state);
		$labourDay->setName('Labour Day');
		$labourDay->setKind(Holiday::KIND_FULL);
		$labourDay->setScope(Holiday::SCOPE_STATUTORY);
		$labourDay->setSource(Holiday::SOURCE_GENERATED);
		$labourDay->setDate(new \DateTime("$year-05-01"));
		$labourDay->setCreatedAt(new \DateTime());
		$labourDay->setUpdatedAt(new \DateTime());

		$this->holidayMapper
			->method('hasStatutoryHolidaysForStateAndYear')
			->willReturn(true);

		// Reconcile (prune) + load = at most 2 DB reads per state/year per request.
		// Without request memo, 30 per-day probes would cause 30+ reads.
		$this->holidayMapper
			->expects($this->exactly(2))
			->method('findByStateAndYear')
			->with($state, $year)
			->willReturn([$labourDay]);

		// Simulate ReportingService / ComplianceService per-day loops (30 days).
		$start = new \DateTime("$year-06-01");
		$end = new \DateTime("$year-06-30");
		$cursor = clone $start;
		while ($cursor <= $end) {
			$this->service->isHolidayForState($state, $cursor);
			$cursor->modify('+1 day');
		}
	}
}
