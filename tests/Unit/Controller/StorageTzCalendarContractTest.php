<?php

declare(strict_types=1);

/**
 * Hard gate: everything time-related in the manager/admin controllers must
 * come from the storage time zone (org/payroll wall clock), never the
 * PHP-default zone clock.
 *
 * Bug class: `new \DateTime()` in a UTC-default container reports yesterday
 * (or tomorrow) for the ~TZ-offset hours around storage midnight. Mappers
 * then serialize the bound via format() in the *object's* zone, so a PHP-TZ
 * clock both picks the wrong calendar day AND shifts naive-string column
 * comparisons — wrong week buckets, wrong default year, wrong "tomorrow"
 * filters, and off-by-offset created_at stamps.
 *
 * Allowed constructions (input-encoded dates, not clocks):
 *   new \DateTime($var)            — caller-supplied or mapper-returned value
 *   new \DateTime('2026-10-21')    — explicit literal date string
 *   new \DateTime($x->format(...)) — naive-date round-trips
 *
 * Forbidden (clock-derived — must go through TimeZoneService):
 *   new \DateTime()                — bare "now"
 *   new \DateTime('tomorrow'|...)  — relative phrases evaluated on the clock
 *   new \DateTime(date(...))       — PHP-TZ date() feeding a bound
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class StorageTzCalendarContractTest extends TestCase
{
	private string $appRoot;

	protected function setUp(): void
	{
		parent::setUp();
		$this->appRoot = dirname(__DIR__, 3);
	}

	public static function controllerFiles(): array
	{
		return [
			['lib/Controller/ManagerController.php'],
			['lib/Controller/AdminController.php'],
		];
	}

	/**
	 * @dataProvider controllerFiles
	 */
	public function testNoPhpDefaultZoneClocks(string $rel): void
	{
		$src = (string)file_get_contents($this->appRoot . '/' . $rel);
		$this->assertStringContainsString(
			'nowInStorage()',
			$src,
			$rel . ' must obtain "now" via TimeZoneService::nowInStorage()'
		);

		foreach (preg_split('/\r?\n/', $src) as $i => $line) {
			$at = $i + 1;
			$this->assertDoesNotMatchRegularExpression(
				'/new \\\\DateTime\(\)/',
				$line,
				$rel . ':' . $at . ' bare new \\DateTime() reads the PHP-default clock — use nowInStorage()'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/new \\\\DateTime\(\s*[\'"][a-zA-Z@]/',
				$line,
				$rel . ':' . $at . ' relative phrase in \\DateTime reads the PHP-default clock — use nowInStorage()->modify()'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/new \\\\DateTime\(\s*(?:date|strftime|gmdate)\(/',
				$line,
				$rel . ':' . $at . ' PHP-zone date()/strftime() feeding a bound — derive from nowInStorage()'
			);
		}
	}
}
