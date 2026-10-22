<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\OvertimeBalancePdfBuilder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class OvertimeBalancePdfBuilderTest extends TestCase
{
	public function testBuildContainsBalanceAndWinAnsi(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $text, array $args = []) {
			if ($args === []) {
				return $text;
			}
			$out = $text;
			foreach (array_values($args) as $i => $arg) {
				$out = str_replace('%' . ($i + 1) . '$s', (string)$arg, $out);
				$out = preg_replace('/%s/', (string)$arg, $out, 1) ?? $out;
			}
			return $out;
		});

		$pdf = OvertimeBalancePdfBuilder::build([
			'balance' => 12.5,
			'balance_label' => 'cumulative',
			'bank_enabled' => false,
			'as_of' => '2026-09-04',
			'display_name' => 'Alex Wagner',
			'user_id' => 'wagner',
		], $l);

		$this->assertStringStartsWith('%PDF-1.4', $pdf);
		$this->assertStringContainsString('/Encoding /WinAnsiEncoding', $pdf);
		$this->assertStringContainsString('12.50', $pdf);
		$this->assertStringContainsString('Alex Wagner', $pdf);
	}

	public function testBuildIncludesBankSectionWhenEnabled(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $args = []) => $text);

		$pdf = OvertimeBalancePdfBuilder::build([
			'balance' => -3.25,
			'balance_label' => 'effective',
			'bank_enabled' => true,
			'bank' => [
				'banked_hours' => 40.0,
				'bank_max_hours' => 100.0,
				'payout_eligible_hours' => 2.5,
				'total_payouts_ytd' => 1.0,
			],
			'as_of' => '2026-09-04',
			'display_name' => 'Test',
			'user_id' => 't1',
		], $l);

		$this->assertStringContainsString('Overtime bank', $pdf);
		$this->assertStringContainsString('40.00', $pdf);
		$this->assertStringContainsString('2.50', $pdf);
	}
}
