<?php

declare(strict_types=1);

/**
 * Kiosk stamp brute-force contract.
 *
 * POST /api/kiosk/stamp maps an RFID UID to a clock action — each guess
 * produces real time entries, so the endpoint must carry its own
 * BruteForceProtection attribute (pre-check sleep/MaxDelayReached) and must
 * register failures into ITS OWN bucket. Registering stamp failures into
 * the identify bucket would let an attacker DoS legitimate PIN identifies;
 * having no attribute at all leaves RFID enumeration unthrottled.
 *
 * @copyright Copyright (c) 2026, Alexander Mäule / Software by Design
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

class KioskStampBruteForceContractTest extends TestCase
{
	private function stampMethodChunk(): string
	{
		$src = file_get_contents(__DIR__ . '/../../../lib/Controller/KioskController.php');
		$this->assertNotFalse($src);
		$start = strpos($src, 'public function stamp(');
		$this->assertNotFalse($start, 'missing KioskController::stamp');
		$next = strpos($src, "\n\tpublic function ", $start + 10);
		return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
	}

	public function testStampCarriesBruteForceProtectionAttribute(): void
	{
		$src = file_get_contents(__DIR__ . '/../../../lib/Controller/KioskController.php');
		$this->assertNotFalse($src);
		$this->assertMatchesRegularExpression(
			'/#\[BruteForceProtection\(action:\s*[\'"]arbeitszeitcheck_kiosk_stamp[\'"]\)\]\s*[\r\n\t ]*public function stamp\(/',
			$src,
			'stamp() must carry #[BruteForceProtection] so the middleware pre-check throttles RFID enumeration'
		);
	}

	public function testStampFailuresRegisterInOwnBucketNotIdentify(): void
	{
		$chunk = $this->stampMethodChunk();
		$this->assertStringContainsString("'arbeitszeitcheck_kiosk_stamp'", $chunk);
		$this->assertStringNotContainsString(
			"kioskError(\$e, 'arbeitszeitcheck_kiosk_identify')",
			$chunk,
			'stamp failures must not inflate the identify bucket (victim lockout DoS)'
		);
	}
}
