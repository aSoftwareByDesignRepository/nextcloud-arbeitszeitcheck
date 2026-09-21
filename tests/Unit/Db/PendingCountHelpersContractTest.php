<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

final class PendingCountHelpersContractTest extends TestCase
{
	public function testAbsenceMapperHasCountPendingForUsers(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Db/AbsenceMapper.php');
		$this->assertStringContainsString('function countPendingForUsers', $src);
		$this->assertStringContainsString('QueryInChunker::in', $src);
	}

	public function testTimeEntryMapperHasCountPendingApprovalForUsers(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Db/TimeEntryMapper.php');
		$this->assertStringContainsString('function countPendingApprovalForUsers', $src);
		$this->assertStringContainsString('STATUS_PENDING_APPROVAL', $src);
	}
}
