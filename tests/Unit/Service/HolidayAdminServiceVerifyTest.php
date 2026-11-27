<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\HolidaySuppressionMapper;
use OCA\ArbeitszeitCheck\Service\HolidayAdminService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class HolidayAdminServiceVerifyTest extends TestCase
{
	public function testVerifyFailsWhenExtraStatutoryRowsInDb(): void
	{
		$state = 'ST';
		$year = 2026;

		$stale = new Holiday();
		$stale->setState($state);
		$stale->setName('Corpus Christi');
		$stale->setKind(Holiday::KIND_FULL);
		$stale->setScope(Holiday::SCOPE_STATUTORY);
		$stale->setSource(Holiday::SOURCE_GENERATED);
		$stale->setDate(new \DateTime('2026-06-04'));
		$stale->setCreatedAt(new \DateTime());
		$stale->setUpdatedAt(new \DateTime());

		$holidayMapper = $this->createMock(HolidayMapper::class);
		$holidayMapper
			->method('findByStateAndYear')
			->with($state, $year)
			->willReturn([$stale]);

		$suppressionMapper = $this->createMock(HolidaySuppressionMapper::class);
		$suppressionMapper
			->method('findSuppressedDatesForStateAndYear')
			->willReturn([]);

		$config = $this->createMock(IConfig::class);
		$config
			->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', 'statutory_auto_reseed', '1', '1'],
			]);

		$ruleMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\HolidayRuleMapper::class);
		$ruleMapper->method('findByState')->willReturn([]);
		$ruleSkipMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\HolidayRuleSkipMapper::class);
		$ruleSkipMapper->method('yearsForRuleIds')->willReturn([]);

		$service = new HolidayAdminService(
			$holidayMapper,
			$suppressionMapper,
			$ruleMapper,
			$ruleSkipMapper,
			$this->createMock(HolidayService::class),
			$config,
		);

		$report = $service->verifyStateYear($state, $year);

		$this->assertFalse($report['ok']);
		$this->assertArrayHasKey('2026-06-04', $report['extraInDb']);
		$this->assertNotEmpty($report['missingInDb'] ?? [], 'Epiphany and other ST days should be reported missing when only stale row exists');
	}
}
