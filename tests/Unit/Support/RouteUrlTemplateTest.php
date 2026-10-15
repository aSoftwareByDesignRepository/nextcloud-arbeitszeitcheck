<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\RouteUrlTemplate;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class RouteUrlTemplateTest extends TestCase
{
	private function urlGeneratorReturning(string $url): IURLGenerator
	{
		$gen = $this->createMock(IURLGenerator::class);
		$gen->method('linkToRoute')->willReturn($url);
		return $gen;
	}

	public function testSentinelReplacesNumericSegment(): void
	{
		$gen = $this->urlGeneratorReturning('/apps/arbeitszeitcheck/api/time-entries/0/request-correction');
		$this->assertSame(
			'/apps/arbeitszeitcheck/api/time-entries/__ID__/request-correction',
			RouteUrlTemplate::for($gen, 'arbeitszeitcheck.time_entry.requestCorrection')
		);
	}

	public function testSentinelAtPathEnd(): void
	{
		$gen = $this->urlGeneratorReturning('/index.php/apps/arbeitszeitcheck/api/time-entries/0');
		$this->assertSame(
			'/index.php/apps/arbeitszeitcheck/api/time-entries/__ID__',
			RouteUrlTemplate::for($gen, 'arbeitszeitcheck.time_entry.apiDelete')
		);
	}

	public function testCustomParamAndSentinel(): void
	{
		$gen = $this->createMock(IURLGenerator::class);
		$gen->expects($this->once())
			->method('linkToRoute')
			->with('arbeitszeitcheck.substitute.approve', ['absenceId' => 0])
			->willReturn('/apps/arbeitszeitcheck/api/substitution-requests/0/approve');
		$this->assertSame(
			'/apps/arbeitszeitcheck/api/substitution-requests/__ID__/approve',
			RouteUrlTemplate::for($gen, 'arbeitszeitcheck.substitute.approve', 'absenceId')
		);
	}

	public function testNumericInsideSegmentIsNotReplaced(): void
	{
		$gen = $this->urlGeneratorReturning('/apps/arbeitszeitcheck/time-entries/0/edit');
		$this->assertSame(
			'/apps/arbeitszeitcheck/time-entries/__ENTRY_ID__/edit',
			RouteUrlTemplate::for($gen, 'arbeitszeitcheck.time_entry.edit', 'id', '__ENTRY_ID__')
		);
	}

	public function testFailsLoudlyWhenSegmentMissing(): void
	{
		$gen = $this->urlGeneratorReturning('/apps/arbeitszeitcheck/api/time-entries');
		$this->expectException(\RuntimeException::class);
		RouteUrlTemplate::for($gen, 'arbeitszeitcheck.time_entry.apiIndex');
	}
}
