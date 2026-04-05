<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Service\TimeEntryExportTransformer;
use PHPUnit\Framework\TestCase;

class TimeEntryExportTransformerTest extends TestCase
{
	private TimeEntryExportTransformer $transformer;

	protected function setUp(): void
	{
		parent::setUp();
		$this->transformer = new TimeEntryExportTransformer();
	}

	public function testNoSplitWhenDisabled(): void
	{
		$entry = $this->makeEntry(
			1,
			new \DateTime('2024-06-10 22:00:00'),
			new \DateTime('2024-06-11 06:00:00')
		);
		$rows = $this->transformer->entryToExportRows($entry, false);
		$this->assertCount(1, $rows);
		$this->assertSame('2024-06-10', $rows[0]['date']);
	}

	public function testSplitOverMidnightProducesTwoRows(): void
	{
		$entry = $this->makeEntry(
			2,
			new \DateTime('2024-06-10 22:00:00'),
			new \DateTime('2024-06-11 06:00:00')
		);
		$rows = $this->transformer->entryToExportRows($entry, true);
		$this->assertCount(2, $rows);
		$this->assertSame('2024-06-10', $rows[0]['date']);
		$this->assertSame('22:00:00', $rows[0]['start_time']);
		$this->assertStringStartsWith('23:59:', $rows[0]['end_time']);
		$this->assertSame('2024-06-11', $rows[1]['date']);
		$this->assertSame('00:00:00', $rows[1]['start_time']);
		$this->assertSame('06:00:00', $rows[1]['end_time']);
	}

	public function testMultiMidnightSpanProducesThreeRows(): void
	{
		$entry = $this->makeEntry(
			3,
			new \DateTime('2024-06-10 20:00:00'),
			new \DateTime('2024-06-12 04:00:00')
		);
		$rows = $this->transformer->entryToExportRows($entry, true);
		$this->assertCount(3, $rows);
		$this->assertSame('2024-06-10', $rows[0]['date']);
		$this->assertSame('2024-06-11', $rows[1]['date']);
		$this->assertSame('2024-06-12', $rows[2]['date']);
	}

	public function testWideDailyUsesGermanDateAndMidnightDisplay(): void
	{
		$long = [
			[
				'user_id' => 'u1',
				'display_name' => 'Test',
				'date' => '2024-06-10',
				'start_time' => '09:00:00',
				'end_time' => '12:00:00',
			],
			[
				'user_id' => 'u1',
				'display_name' => 'Test',
				'date' => '2024-06-10',
				'start_time' => '13:00:00',
				'end_time' => '23:59:59',
			],
		];
		$wide = $this->transformer->longExportRowsToWideDaily($long, static fn (string $d): string => 'Mon');
		$this->assertCount(1, $wide);
		$this->assertSame('10.06.2024', $wide[0]['date']);
		$this->assertSame('9:00', $wide[0]['von_1']);
		$this->assertSame('12:00', $wide[0]['bis_1']);
		$this->assertSame('13:00', $wide[0]['von_2']);
		$this->assertSame('0:00', $wide[0]['bis_2']);
	}

	private function makeEntry(int $id, \DateTime $start, \DateTime $end): TimeEntry
	{
		$e = new TimeEntry();
		$e->setId($id);
		$e->setUserId('user1');
		$e->setStartTime($start);
		$e->setEndTime($end);
		$e->setStatus(TimeEntry::STATUS_COMPLETED);
		$e->setIsManualEntry(false);
		$e->setDescription('');
		return $e;
	}
}
