<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
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

	protected function setUp(): void
	{
		parent::setUp();

		$this->holidayMapper = $this->createMock(HolidayMapper::class);
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

		$this->service = new HolidayService(
			$this->holidayMapper,
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
}
