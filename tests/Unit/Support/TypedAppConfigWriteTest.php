<?php

declare(strict_types=1);

/**
 * TypedAppConfigWrite — untyped write first, typed fallback on conflict.
 *
 * Regression: VacationUnitMigrationService::rescaleCarryoverMaxConfig 500'd a
 * vacation-unit flip with AppConfigTypeConflictException once
 * vacation_carryover_max_days existed as VALUE_STRING (typed AdminController
 * write). Same class on PermissionService::purgeUser for
 * app_admin_user_ids / access_allowed_user_ids.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\TypedAppConfigWrite;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class TypedAppConfigWriteTest extends TestCase
{
	public function testUntypedWriteUsedWhenNoConflict(): void
	{
		$stored = [];
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			}
		);
		$typedCalls = 0;

		TypedAppConfigWrite::setString($config, 'arbeitszeitcheck', 'k1', 'v1', function () use (&$typedCalls): void {
			$typedCalls++;
		});

		$this->assertSame('v1', $stored['k1'] ?? null);
		$this->assertSame(0, $typedCalls);
	}

	public function testTypeConflictFallsBackToTypedWriter(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willThrowException(
			new AppConfigTypeConflictException('conflict between new type (mixed) and old type (string)')
		);
		$typed = [];

		TypedAppConfigWrite::setString($config, 'arbeitszeitcheck', 'vacation_carryover_max_days', '40',
			static function (string $app, string $key, string $value) use (&$typed): void {
				$typed[$key] = $value;
			}
		);

		$this->assertSame('40', $typed['vacation_carryover_max_days'] ?? null);
	}

	public function testOtherExceptionsPropagate(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willThrowException(new \RuntimeException('db gone'));

		$this->expectException(\RuntimeException::class);
		TypedAppConfigWrite::setString($config, 'arbeitszeitcheck', 'k', 'v', static function (): void {
			self::fail('typed fallback must not run for non-conflict errors');
		});
	}
}
