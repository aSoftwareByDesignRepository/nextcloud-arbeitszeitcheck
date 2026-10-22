<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

/**
 * Labor-trust contract: post-timeout retries after wall TTL must still resolve
 * a consumed claim as USED, not INVALID.
 */
class KioskSessionMapperUsedSessionContractTest extends TestCase
{
	public function testFindUsedSessionDoesNotRequireUnexpiredExpiresAt(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Db/KioskSessionMapper.php');
		$this->assertNotFalse($src);
		$start = strpos($src, 'function findUsedSession');
		$this->assertNotFalse($start);
		$end = strpos($src, 'function markUsed', $start);
		$this->assertNotFalse($end);
		$body = substr($src, $start, $end - $start);
		$this->assertStringNotContainsString(
			"gt('expires_at'",
			$body,
			'findUsedSession must not gate on expires_at — stamps that finish near TTL end are retried after wall expiry',
		);
		$this->assertStringContainsString("isNotNull('used_at')", $body);
	}
}
