<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

/**
 * Contract: the vacation-days consumption preview must honor boundary
 * half-day selections (SF-Z2: estimate × fraction client-side). A 5-day
 * range with "first day half" + "last day half" previews 4.0 days, not 5.0.
 */
final class AbsencesDaysConsumptionContractTest extends TestCase {

	private function src(): string {
		$src = file_get_contents(dirname(__DIR__, 3) . '/templates/absences.php');
		$this->assertNotFalse($src);
		return (string)$src;
	}

	public function testEstimatePreviewSubtractsSelectedBoundaryHalves(): void {
		$src = $this->src();
		// Boundary state is read live from the checked radios, not cached.
		$this->assertStringContainsString('boundaryHalvesSelected', $src);
		$this->assertStringContainsString('input[name="start_day_fraction"]:checked', $src);
		$this->assertStringContainsString('input[name="end_day_fraction"]:checked', $src);
		// The displayed estimate is reduced by 0.5 per selected boundary half.
		$this->assertMatchesRegularExpression(
			'/boundaryHalvesSelected\(\)\s*\*\s*0\.5/',
			$src
		);
		$this->assertMatchesRegularExpression(
			'/res\.data\.days\s*-\s*boundaryHalvesSelected\(\)/',
			$src
		);
	}

	public function testEstimatePreviewRefreshesOnBoundaryToggle(): void {
		$src = $this->src();
		// Changing a boundary radio re-runs the estimate so the preview
		// cannot display a stale full-weight count.
		$this->assertMatchesRegularExpression(
			'/absence-boundary-half-group.*addEventListener\(.change., deb\)/s',
			$src
		);
	}

	public function testEstimatePreviewSkipsZeroResult(): void {
		$src = $this->src();
		// A range whose halves consume the whole weight must not claim
		// "uses 0 vacation days" — hide instead.
		$this->assertMatchesRegularExpression('/if \(n <= 0\) \{ hide\(\); return; \}/', $src);
	}
}
