<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\IconCatalog;
use PHPUnit\Framework\TestCase;

class IconCatalogTest extends TestCase
{
	public function testAlertTriangleHasVisibleExclamationMark(): void
	{
		$svg = IconCatalog::render('alert-triangle');
		$this->assertStringContainsString('m12 3 10 17H2Z', $svg);
		$this->assertStringContainsString('M12 9v4', $svg);
		$this->assertStringContainsString('circle cx="12" cy="17"', $svg);
		$this->assertStringNotContainsString('h.01', $svg);
	}

	public function testRenderCalloutWellIncludesVariantClass(): void
	{
		$html = IconCatalog::renderCalloutWell('alert-triangle', 'warning');
		$this->assertStringContainsString('azc-notif-icon-well--warning', $html);
		$this->assertStringContainsString('azc-callout__icon-svg', $html);
	}

	public function testLightbulbAndChevronDownRender(): void
	{
		$bulb = IconCatalog::render('lightbulb');
		$chevron = IconCatalog::render('chevron-down');
		$this->assertStringContainsString('M15 14c.2-1', $bulb);
		$this->assertStringContainsString('aria-hidden="true"', $bulb);
		$this->assertStringContainsString('m6 9 6 6 6-6', $chevron);
	}

	public function testTableActionIconsRenderHiddenGlyphs(): void
	{
		foreach (['edit', 'trash', 'info', 'copy', 'history', 'archive'] as $name) {
			$svg = IconCatalog::render($name);
			$this->assertNotSame('', $svg, $name);
			$this->assertStringContainsString('aria-hidden="true"', $svg);
			$this->assertStringContainsString('class="azc-icon"', $svg);
		}
	}

	public function testRenderCalloutWellMapsErrorToDanger(): void
	{
		$html = IconCatalog::renderCalloutWell('circle-alert', 'error');
		$this->assertStringContainsString('azc-notif-icon-well--danger', $html);
	}

	public function testHasReflectsCatalogMembership(): void
	{
		$this->assertTrue(IconCatalog::has('edit'));
		$this->assertFalse(IconCatalog::has('square-pen'));
		$this->assertFalse(IconCatalog::has('does-not-exist'));
	}

	public function testNavFooterCandidateChainsResolve(): void
	{
		// Every candidate list in the synced nav-footer partial must contain at
		// least one catalog icon or the first miss renders a wrong/absent icon.
		$tpl = file_get_contents(__DIR__ . '/../../../templates/parts/feedback-nav-footer.php');
		$this->assertNotFalse($tpl);
		preg_match_all('/Icon\(\s*\$prefix\s*,\s*\[([^\]]+)\]/', $tpl, $matches);
		$this->assertNotEmpty($matches[1], 'no icon candidate call sites found in nav-footer partial');
		foreach ($matches[1] as $listSrc) {
			preg_match_all("/'([a-z0-9_-]+)'/", $listSrc, $names);
			$this->assertNotEmpty(
				array_filter($names[1], [IconCatalog::class, 'has']),
				'no resolvable candidate in [' . $listSrc . ']'
			);
		}
	}
}
