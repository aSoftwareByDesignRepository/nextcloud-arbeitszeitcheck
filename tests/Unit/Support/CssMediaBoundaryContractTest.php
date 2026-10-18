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
	private const EPSILON = 0.005;
	private const WINDOW_UP = 3.0;
	private const WINDOW_DOWN = 1.5;
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
		// Scan each query head (`@media … {` / `@container … {`) so compound
		// queries contribute ALL features, e.g. `(min-width: 520px) and
		// (max-width: 899.98px)`. `width < V` is an exact complement of edge V
		// (modelled as V - 0.02 so it pairs, never violates).
		foreach ($files as $file) {
			$src = (string)file_get_contents($file);
			preg_match_all('/@(media|container)\b([^{]*)\{/', $src, $heads, PREG_SET_ORDER);
			foreach ($heads as $head) {
				preg_match_all(
					'/\b(min|max)-width:\s*([0-9.]+)(px|rem)\b|\bwidth\s*<(=)?\s*([0-9.]+)(px|rem)\b/',
					$head[2],
					$m,
					PREG_SET_ORDER
				);
				foreach ($m as $hit) {
					if ($hit[1] !== '') {
						$value = (float)$hit[2] * ($hit[3] === 'rem' ? self::REM_PX : 1.0);
						if ($hit[1] === 'min') {
							$min[] = $value;
						} else {
							$max[] = $value;
						}
					} else {
						// `width < V` owns edge V exactly (strict complement);
						// `width <= V` is an inclusive cap like max-width.
						$inclusive = $hit[4] !== '';
						$v = (float)$hit[5] * ($hit[6] === 'rem' ? self::REM_PX : 1.0);
						$max[] = $inclusive ? $v : 'strict:' . $v;
					}
				}
			}
		}
		return ['min' => array_unique($min), 'max' => array_unique($max)];
	}

	public function testBoundaryPairsDoNotOverlapOrGap(): void
	{
		$b = self::collectBoundaries();
		$violations = [];
		foreach ($b['max'] as $maxRaw) {
			// `width < V` strict caps own edge V: they cannot gap a V+1 min edge
			// (deliberate adjacent bands: 480/481, 640/641, 720/721) — only a min
			// edge strictly below V overlaps them (both fire on [B, V)).
			$strict = is_string($maxRaw) && str_starts_with($maxRaw, 'strict:');
			$max = $strict ? (float)substr($maxRaw, 7) : (float)$maxRaw;
			// A cap is judged against its governing min edge: the nearest min
			// at/above within the window — or, when the cap sits just above an
			// edge, that edge (the cap then overlaps it). Edges further away
			// belong to other breakpoints; caps with no edge nearby are
			// standalone clamps (band tops, dialog limits) and are skipped.
			$above = null;
			$below = null;
			foreach ($b['min'] as $min) {
				$d = $min - $max;
				if (!$strict && $d >= 0 && $d <= self::WINDOW_UP && ($above === null || $min < $above)) {
					$above = $min;
				}
				if ($d < 0 && $d >= -self::WINDOW_DOWN && ($below === null || $min > $below)) {
					$below = $min;
				}
			}
			if ($above !== null) {
				$delta = $above - $max;
				if ($delta === 0.0) {
					$violations[] = "overlap: max-width {$max}px == min-width {$above}px (both fire at the boundary)";
				} elseif (abs($delta - self::BOUNDARY_BELOW) > self::EPSILON) {
					$violations[] = "gap/off-convention: max-width {$max}px vs min-width {$above}px (gap {$delta}px, expected 0.02px boundary-below)";
				}
			} elseif ($below !== null) {
				$violations[] = "overlap: max-width {$max}px extends past min-width {$below}px edge";
			}
		}
		$this->assertSame([], $violations, "Media boundary violation(s):\n" . implode("\n", $violations));
	}
}
