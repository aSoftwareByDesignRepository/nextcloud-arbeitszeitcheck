<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

/**
 * Labor-trust: claimUnused must refuse sessions that expired between
 * validateSession and the claim UPDATE (not one DB transaction).
 */
class KioskSessionMapperClaimUnusedExpiryContractTest extends TestCase
{
	public function testClaimUnusedRequiresExpiresAtAfterUsedAt(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Db/KioskSessionMapper.php');
		$this->assertNotFalse($src);
		$start = strpos($src, 'function claimUnused');
		$this->assertNotFalse($start);
		$end = strpos($src, 'function releaseClaim', $start);
		$this->assertNotFalse($end);
		$body = substr($src, $start, $end - $start);
		$this->assertMatchesRegularExpression(
			"/gt\\(\\s*'expires_at'/s",
			$body,
			'claimUnused must re-check expires_at so a session cannot stamp after wall TTL',
		);
		$this->assertStringContainsString("isNull('used_at')", $body);
	}
}
