<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Contract: MH-P24 channel attribution actually reaches the indexed audit
 * capture_source column — the controller's `channel` param is normalized
 * (never a stamp rejection), forwarded through MobileStampReplayService into
 * TimeTrackingService, and merged into every clock-in/out audit newValues.
 * The token-authed widget toggle hard-codes channel 'widget'.
 */
final class WidgetChannelAttributionContractTest extends TestCase
{
	public function testControllerNormalizesChannelInsteadOfRejecting(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/TimeTrackingController.php');
		// A wrong channel must never reject a legal stamp: the validator
		// collapses to null + warning log, it does not throw.
		$this->assertStringContainsString('normalizeCaptureChannel', $src);
		$this->assertStringNotContainsString('assertValidCaptureChannel', $src);
		$this->assertStringNotContainsString("InvalidArgumentException('Invalid capture channel')", $src);
	}

	public function testControllerForwardsChannelToReplayService(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/TimeTrackingController.php');
		$this->assertMatchesRegularExpression('/stampReplayService->clockIn\([\s\S]*\$channel,?\s*\);/', $src);
		$this->assertMatchesRegularExpression('/stampReplayService->clockOut\([\s\S]*\$channel,?\s*\);/', $src);
	}

	public function testReplayServiceForwardsCaptureSource(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Service/MobileStampReplayService.php');
		$this->assertStringContainsString('?string $captureSource', $src);
		$this->assertStringContainsString('$captureSource,', $src);
	}

	public function testServiceMergesChannelIntoEveryStampAudit(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Service/TimeTrackingService.php');
		$this->assertStringContainsString('channelCaptureAuditMeta', $src);
		// Fresh clock-in, resumed-paused clock-in, manual clock-out, and
		// project-switch clock-out all carry the channel meta.
		$this->assertSame(4, substr_count($src, 'channelCaptureAuditMeta($captureSource)'));
	}

	public function testWidgetToggleHardcodesWidgetChannel(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/WidgetTokenController.php');
		$this->assertStringContainsString("clockOut(\$uid, \$clientRequestId, null, 'widget')", $src);
		$this->assertStringContainsString("clockIn(\$uid, null, null, \$clientRequestId, null, null, 'widget')", $src);
	}

	public function testAuditApiSurfacesAllowlistedSources(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/AdminController.php');
		$this->assertStringContainsString('AUDIT_CAPTURE_SOURCES', $src);
	}
}
