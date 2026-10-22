<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Support\AdminBatchUserIds;
use PHPUnit\Framework\TestCase;

final class AdminBatchUserIdsTest extends TestCase
{
	public function testNormalizeDedupesAndTrims(): void
	{
		$result = AdminBatchUserIds::normalize([' a ', 'b', 'a', '', 'b', 7]);
		$this->assertTrue($result['ok']);
		$this->assertSame(['a', 'b', '7'], $result['userIds']);
	}

	public function testEmptyRejected(): void
	{
		$result = AdminBatchUserIds::normalize([]);
		$this->assertFalse($result['ok']);
		$this->assertSame('user_ids_required', $result['error']);
		$this->assertSame(400, $result['httpStatus']);
	}

	public function testOversizeRejected(): void
	{
		$ids = [];
		for ($i = 0; $i < Constants::MAX_BATCH_USERS + 1; $i++) {
			$ids[] = 'u' . $i;
		}
		$result = AdminBatchUserIds::normalize($ids);
		$this->assertFalse($result['ok']);
		$this->assertSame('batch_too_large', $result['error']);
		$this->assertSame(400, $result['httpStatus']);
	}

	public function testNonArrayRejected(): void
	{
		$result = AdminBatchUserIds::normalize('nope');
		$this->assertFalse($result['ok']);
		$this->assertSame('user_ids_required', $result['error']);
	}
}
