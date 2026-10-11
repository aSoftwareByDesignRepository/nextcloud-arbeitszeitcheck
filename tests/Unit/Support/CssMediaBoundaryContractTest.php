<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Media-query boundary contract (2026-10 UI-master pass).
 *
 * Convention: `min-width` rules own the integer boundary (B), `max-width`
 * rules end 0.02px below it (B - 0.02). Only boundary PAIRS matter — a max
 * and a min within 2px of each other describe the same breakpoint edge and
 * must not overlap or leave a sub-pixel gap:
 *   - overlap: `max-width: 768px` + `min-width: 768px` both firing at 768px
 *     (broke the dashboard 12-col grid into implicit columns + overflow);
 *   - gap: `max-width: 767px` + `min-width: 768px` leaves 767.xx–767.99
 *     fractional viewports unstyled.
 */
class CssMediaBoundaryContractTest extends TestCase
{
	private const REM_PX = 16.0;
	private const EPSILON = 0.001;
	private const PAIR_WINDOW = 2.0;
	private const BOUNDARY_BELOW = 0.02;

	/** @return array{min: float[], max: float[]} values normalised to px */
	private static function collectBoundaries(): array
	{
		$files = array_merge(
			glob(__DIR__ . '/../../../css/*.css') ?: [],
			glob(__DIR__ . '/../../../css/common/*.css') ?: []
		);
		self::assertNotEmpty($files, 'no css files found');

		$min = [];
		$max = [];
		foreach ($files as $file) {
			$src = (string)file_get_contents($file);
			preg_match_all('/@media[^{}]*\b(min|max)-width:\s*([0-9.]+)(px|rem)\b/', $src, $m, PREG_SET_ORDER);
			foreach ($m as $hit) {
				$value = (float)$hit[2] * ($hit[3] === 'rem' ? self::REM_PX : 1.0);
				if ($hit[1] === 'min') {
					$min[] = $value;
				} else {
					$max[] = $value;
				}
			}
		}
		return ['min' => array_unique($min), 'max' => array_unique($max)];
	}

	public function testBoundaryPairsDoNotOverlapOrGap(): void
	{
		$b = self::collectBoundaries();
		$violations = [];
		foreach ($b['min'] as $min) {
			foreach ($b['max'] as $max) {
				if (abs($min - $max) >= self::PAIR_WINDOW) {
					continue; // different breakpoints, not a pair
				}
				$delta = $min - $max;
				if ($delta <= 0) {
					$violations[] = "overlap: max-width {$max}px >= min-width {$min}px (both fire at the boundary)";
				} elseif (abs($delta - self::BOUNDARY_BELOW) > 0.005) {
					$violations[] = "gap/off-convention: max-width {$max}px vs min-width {$min}px (gap {$delta}px, expected 0.02px boundary-below)";
				}
			}
		}
		$this->assertSame([], $violations, "Media boundary violation(s):\n" . implode("\n", $violations));
	}
}
