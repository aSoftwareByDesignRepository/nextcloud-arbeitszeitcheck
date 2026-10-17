<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineCanonicalizer;
use PHPUnit\Framework\TestCase;

final class LegacyBaselineCanonicalizerTest extends TestCase
{
	public function testMapInsertionOrderDoesNotChangeCanonicalHash(): void
	{
		$left = [
			'api' => ['status' => 'active', 'id' => 7],
			'account' => ['creditMinutes' => 0, 'actualMinutes' => 480],
		];
		$right = [
			'account' => ['actualMinutes' => 480, 'creditMinutes' => 0],
			'api' => ['id' => 7, 'status' => 'active'],
		];

		self::assertSame(LegacyBaselineCanonicalizer::encode($left), LegacyBaselineCanonicalizer::encode($right));
		self::assertSame(LegacyBaselineCanonicalizer::hash($left), LegacyBaselineCanonicalizer::hash($right));
	}

	public function testListOrderRemainsPartOfContract(): void
	{
		$oldestFirst = ['audit' => [['revision' => 1], ['revision' => 2]]];
		$newestFirst = ['audit' => [['revision' => 2], ['revision' => 1]]];

		self::assertNotSame(LegacyBaselineCanonicalizer::hash($oldestFirst), LegacyBaselineCanonicalizer::hash($newestFirst));
	}

	public function testBusinessIdsAndTimestampsAreNotSilentlyDiscarded(): void
	{
		$before = ['entry' => ['id' => 41, 'startTime' => '2026-01-15T08:00:00+01:00']];
		$changedId = ['entry' => ['id' => 42, 'startTime' => '2026-01-15T08:00:00+01:00']];
		$changedTime = ['entry' => ['id' => 41, 'startTime' => '2026-01-15T08:01:00+01:00']];

		self::assertNotSame(LegacyBaselineCanonicalizer::hash($before), LegacyBaselineCanonicalizer::hash($changedId));
		self::assertNotSame(LegacyBaselineCanonicalizer::hash($before), LegacyBaselineCanonicalizer::hash($changedTime));
	}

	public function testUnicodeAndSlashesRemainHumanReadableAndStable(): void
	{
		$encoded = LegacyBaselineCanonicalizer::encode(['label' => 'Sonderfreistellung für ÖPNV', 'url' => '/apps/arbeitszeitcheck/']);

		self::assertSame('{"label":"Sonderfreistellung für ÖPNV","url":"/apps/arbeitszeitcheck/"}', $encoded);
	}
}
