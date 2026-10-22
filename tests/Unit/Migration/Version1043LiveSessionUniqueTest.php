<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Migration;

use OCA\ArbeitszeitCheck\Migration\Version1043Date20260908220000;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Contract: live-session uniqueness migration is expand-only / idempotent.
 */
class Version1043LiveSessionUniqueTest extends TestCase
{
	public function testPostSchemaChangeNoopsWhenTableMissing(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('at_entries')->willReturn(false);
		$db->expects($this->never())->method('executeStatement');

		$config = $this->createMock(IConfig::class);
		$migration = new Version1043Date20260908220000($db, $config);
		$migration->postSchemaChange(
			$this->createMock(IOutput::class),
			static fn () => null,
			[]
		);
	}

	public function testConstantsStableForLegacyInstalls(): void
	{
		$this->assertSame('at_ent_live_uid_uq', Version1043Date20260908220000::LIVE_USER_UNIQUE);
		$this->assertSame('at_ent_open_uid_uq', Version1043Date20260908220000::PG_PARTIAL_UNIQUE);
	}
}
