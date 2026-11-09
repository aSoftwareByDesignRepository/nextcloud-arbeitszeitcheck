<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

/**
 * Special-entitlement accounts on the absences page: each account renders as
 * a stat-card inside the vacation-balance stats grid — not as a prominent
 * card above the page content.
 *
 * Contract: #se-accounts lives inside section--stats AFTER the .stats-grid
 * with the five balance cards, reuses .stats-grid for identical box layout,
 * and absence-entitlements.js renders .stat-card children with the same
 * label/value/sublabel/facts structure as the server-rendered cards.
 */
class AbsencesSpecialEntitlementLayoutTest extends TestCase
{
	private static function template(): string
	{
		return (string)file_get_contents(__DIR__ . '/../../../templates/absences.php');
	}

	private static function js(): string
	{
		return (string)file_get_contents(__DIR__ . '/../../../js/absence-entitlements.js');
	}

	public function testSeAccountsLivesInsideTheStatsSectionBelowTheGrid(): void
	{
		$src = self::template();

		$statsSection = strpos($src, 'class="section section--stats vacation-stats"');
		$statsGrid = strpos($src, 'class="stats-grid"');
		$seAccounts = strpos($src, 'id="se-accounts"');
		$statsSectionEnd = strpos($src, '</section>', (int)$statsSection);

		$this->assertNotFalse($statsSection);
		$this->assertNotFalse($statsGrid);
		$this->assertNotFalse($seAccounts);
		$this->assertNotFalse($statsSectionEnd);
		$this->assertLessThan($seAccounts, $statsSection, 'se-accounts must be inside the vacation-stats section');
		$this->assertLessThan($seAccounts, $statsGrid, 'se-accounts must render after the balance stats-grid');
		$this->assertLessThan($statsSectionEnd, $seAccounts, 'se-accounts must stay inside the stats section');

		// Below the balance grid means: se-accounts appears before the stats
		// section closes but after the five balance cards — never before them.
		$pendingCard = strpos($src, 'stat-card--pending');
		$this->assertNotFalse($pendingCard);
		$this->assertLessThan($seAccounts, $pendingCard, 'se-accounts must come after all five balance cards');
	}

	public function testSeAccountsIsAGridNotAProminentCard(): void
	{
		$src = self::template();

		// No azc-card chrome on the container — that is what made it prominent.
		$this->assertStringNotContainsString('id="se-accounts" class="azc-card', $src);
		$this->assertStringNotContainsString('id="se-accounts-title"', $src);

		// The container carries the shared stats-grid so boxes look identical.
		$this->assertStringContainsString('id="se-accounts-grid" class="stats-grid"', $src);
		$this->assertStringContainsString('role="group"', $src);
		$this->assertStringContainsString('aria-label', $src);

		$this->assertStringContainsString('id="se-accounts-status"', $src);

		// History feature was removed — no dead markup or dead data attributes.
		$this->assertStringNotContainsString('se-account-history', $src);
		$this->assertStringNotContainsString('data-history-template', $src);

		// Old list markup is gone.
		$this->assertStringNotContainsString('se-accounts-list', $src);
		$this->assertStringNotContainsString('se-accounts__list', $src);
	}

	public function testJsRendersStatCardsWithHistoryAction(): void
	{
		$js = self::js();

		$this->assertStringContainsString("getElementById('se-accounts-grid')", $js);
		$this->assertStringContainsString("'stat-card stat-card--se-account'", $js);
		$this->assertStringContainsString("'stat-label'", $js);
		$this->assertStringContainsString("'stat-value stat-value--secondary'", $js);
		$this->assertStringContainsString("'stat-sublabel'", $js);
		$this->assertStringContainsString("'stat-card__facts'", $js);
		$this->assertStringContainsString('aria-labelledby', $js);

		// Facts show exactly Total + Reserved + optional until — Available is
		// the headline number already and Used/History were dropped on purpose.
		foreach (['Total', 'Reserved', 'until'] as $needle) {
			$this->assertStringContainsString("t('{$needle}')", $js, "JS lost the '{$needle}' label");
		}
		foreach (['Available', 'Used', 'History', 'se-account-history'] as $needle) {
			$this->assertStringNotContainsString($needle, $js, "JS still references removed '{$needle}'");
		}
		$this->assertStringNotContainsString('se-accounts-list', $js);
	}

	public function testGapBetweenStatsGridAndSeGrid(): void
	{
		$css = (string)file_get_contents(__DIR__ . '/../../../css/absences.css');
		$this->assertMatchesRegularExpression(
			'/\.section--stats \.se-accounts\s*\{[^}]*margin-top:\s*var\(--space-4/s',
			$css,
			'SE grid needs the same row gap as the stats grid above it'
		);
	}
}
