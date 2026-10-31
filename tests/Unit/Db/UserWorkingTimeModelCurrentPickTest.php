<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use PHPUnit\Framework\TestCase;

final class UserWorkingTimeModelCurrentPickTest extends TestCase
{
	public function testLatestStartThenHighestIdWins(): void
	{
		$map = UserWorkingTimeModelMapper::pickCurrentModelIds([
			['user_id' => 'a', 'working_time_model_id' => 1, 'start_date' => '2026-01-01', 'id' => 10],
			['user_id' => 'a', 'working_time_model_id' => 2, 'start_date' => '2026-06-01', 'id' => 3],
			['user_id' => 'a', 'working_time_model_id' => 9, 'start_date' => '2026-06-01', 'id' => 4],
			['user_id' => 'b', 'working_time_model_id' => 7, 'start_date' => '2026-02-01', 'id' => 1],
			['user_id' => '  ', 'working_time_model_id' => 5, 'start_date' => '2026-01-01', 'id' => 1],
			['user_id' => 'c', 'working_time_model_id' => 0, 'start_date' => '2026-01-01', 'id' => 1],
		]);

		$this->assertSame([
			'a' => 9,
			'b' => 7,
		], $map);
	}

	public function testEarlierStartDoesNotReplaceALaterOne(): void
	{
		$map = UserWorkingTimeModelMapper::pickCurrentModelIds([
			['user_id' => 'a', 'working_time_model_id' => 4, 'start_date' => '2026-08-01', 'id' => 1],
			['user_id' => 'a', 'working_time_model_id' => 3, 'start_date' => '2026-01-01', 'id' => 99],
		]);

		$this->assertSame(['a' => 4], $map);
	}
}
