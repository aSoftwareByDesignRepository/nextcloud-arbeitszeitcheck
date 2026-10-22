<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service\Kiosk;

use PHPUnit\Framework\TestCase;

/**
 * PIN lockout must retry exclusive lock acquisition — a single LockedException
 * must not silently drop the failed-attempt counter (foyer brute-force undercount).
 */
class KioskCredentialServiceLockoutRetryContractTest extends TestCase
{
	public function testRecordFailedAttemptRetriesBusyLock(): void
	{
		$src = file_get_contents(dirname(__DIR__, 4) . '/lib/Service/Kiosk/KioskCredentialService.php');
		$this->assertNotFalse($src);
		$start = strpos($src, 'function recordFailedAttempt');
		$this->assertNotFalse($start);
		$end = strpos($src, 'function acquireExclusive(', $start);
		$this->assertNotFalse($end);
		$body = substr($src, $start, $end - $start);
		$this->assertStringContainsString('for ($i = 0;', $body);
		$this->assertStringContainsString('KIOSK_BUSY', $body);
		$this->assertStringContainsString('usleep', $body);
		$this->assertDoesNotMatchRegularExpression(
			'/catch \(KioskException\) \{\s*\/\/ Lockout accounting must never block[\s\S]*?return;\s*\}/',
			$body,
			'Silent single-shot drop of lockout accounting is forbidden',
		);
	}
}
