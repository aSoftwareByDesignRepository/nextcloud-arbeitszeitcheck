<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Filter panels must use azc-filter-panel__head + intro + body (not azc-card__header).
 * Canonical partial: templates/common/azc-filter-panel.php
 */
final class FilterPanelLayoutContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testCanonicalPartialUsesHeadIntroBody(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/common/azc-filter-panel.php');
		$this->assertStringContainsString('azc-card azc-filter-panel', $html);
		$this->assertStringContainsString('azc-filter-panel__head', $html);
		$this->assertStringContainsString('azc-filter-panel__intro', $html);
		$this->assertStringContainsString('azc-filter-panel__body', $html);
		$this->assertStringNotContainsString('azc-card__header', $html);
		$this->assertStringNotContainsString('azc-card__title', $html);
		$this->assertStringNotContainsString('azc-card__lead', $html);
	}

	public function testAbsencesAndTimeEntriesFiltersMatchPartialChrome(): void
	{
		foreach (['templates/absences.php', 'templates/time-entries.php', 'templates/admin-users.php'] as $rel) {
			$html = (string)file_get_contents($this->root . '/' . $rel);
			$this->assertStringContainsString('azc-card azc-filter-panel', $html, $rel);
			preg_match_all('/<section[^>]*\bazc-filter-panel\b[^>]*>[\s\S]*?<\/section>/', $html, $blocks);
			$this->assertNotEmpty($blocks[0], $rel . ' missing filter panel section');
			$titleOnly = $rel !== 'templates/admin-users.php';
			foreach ($blocks[0] as $i => $block) {
				$label = $rel . ' panel#' . $i;
				$this->assertStringContainsString('azc-filter-panel__head', $block, $label);
				if ($titleOnly) {
					$this->assertStringNotContainsString('azc-filter-panel__intro', $block, $label);
				} else {
					$this->assertStringContainsString('azc-filter-panel__intro', $block, $label);
				}
				$this->assertStringContainsString('azc-filter-panel__body', $block, $label);
				$this->assertStringContainsString('azc-filter-panel__form', $block, $label);
				$this->assertStringNotContainsString('azc-card__header', $block, $label . ' must not use azc-card__header');
				$this->assertStringNotContainsString('azc-card__title', $block, $label . ' must not use azc-card__title');
				$this->assertStringNotContainsString('azc-card__lead', $block, $label . ' must not use azc-card__lead');
				$this->assertStringNotContainsString('azc-card__body', $block, $label . ' must not use azc-card__body');
			}
		}
	}

	public function testPagePatternsDefineFilterHeadChrome(): void
	{
		$css = (string)file_get_contents($this->root . '/css/common/page-patterns.css');
		$this->assertStringContainsString('.azc-filter-panel__head', $css);
		$this->assertStringContainsString('.azc-filter-panel__intro', $css);
		$this->assertStringContainsString('.azc-filter-panel__body', $css);
		$this->assertMatchesRegularExpression(
			'/\.azc-filter-panel\s*\{[^}]*padding:\s*0/s',
			$css,
			'filter panel root must zero card padding so __head/__body own spacing'
		);
	}
}
