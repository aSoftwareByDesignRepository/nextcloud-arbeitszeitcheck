<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

/**
 * LWS employee-UX layout contract (2026-10): dashboard separates sick leave
 * from vacation, dashboard links straight into manual entry, the page shell
 * no longer renders the role/timezone intro strip, and the time-entries page
 * keeps overview/help material below the list.
 */
class EmployeeUxLayoutContractTest extends TestCase
{
	private static string $dashboard = __DIR__ . '/../../../templates/dashboard.php';
	private static string $pageStart = __DIR__ . '/../../../templates/common/page-start.php';
	private static string $timeEntries = __DIR__ . '/../../../templates/time-entries.php';
	private static string $dashboardCss = __DIR__ . '/../../../css/dashboard.css';

	private static function src(string $path): string
	{
		self::assertFileExists($path);
		return (string)file_get_contents($path);
	}

	public function testSickLeaveIsItsOwnDashboardCard(): void
	{
		$content = self::src(self::$dashboard);

		$this->assertStringContainsString('azc-dashboard-sick', $content);
		$this->assertStringContainsString('id="dashboard-sick-days-value"', $content);
		$this->assertStringContainsString('id="dashboard-sick-heading"', $content);
		// Sick days are counted per calendar year — the card must not reuse the
		// (possibly anniversary-based) vacation-year label.
		$sickCard = substr($content, (int)strpos($content, 'azc-dashboard-sick'), 2000);
		$this->assertStringNotContainsString('vyLabel', $sickCard);
		$this->assertStringContainsString('sick_year', $sickCard);
		// Sick days render exactly once — inside the dedicated card, not the
		// vacation breakdown (separation, not duplication).
		$this->assertSame(1, substr_count($content, 'Sick days this year'));
		$this->assertSame(1, substr_count($content, 'sick_days_this_year'));
	}

	public function testDashboardLinksDirectlyToManualEntry(): void
	{
		$content = self::src(self::$dashboard);

		$this->assertStringContainsString('id="dashboard-add-entry"', $content);
		$this->assertStringContainsString('time_entry.create', $content);
	}

	public function testPageShellDropsRoleTimezoneStrip(): void
	{
		$content = self::src(self::$pageStart);

		// The strip survives only as the pending-corrections chip container —
		// the unconditional role/timezone intro row is gone.
		$this->assertStringContainsString('azc-scope-strip', $content);
		$this->assertStringNotContainsString('azc-scope-strip__label', $content);
		$this->assertStringNotContainsString('azc-scope-strip__badge', $content);
		$this->assertStringNotContainsString('azc-scope-strip__value', $content);
		$this->assertLessThan(
			strpos($content, 'azc-scope-strip"'),
			strpos($content, 'pendingCorrectionCount > 0'),
			'scope strip must be guarded by the pending-corrections condition'
		);
	}

	public function testTimeEntriesKeepsOverviewBelowTheList(): void
	{
		$content = self::src(self::$timeEntries);

		$listPos = strpos($content, 'time-entries-page__list"');
		$this->assertNotFalse($listPos);
		foreach (['time-entries-page__stats', 'month-closure-section', 'time-entries-page__deletion-guide', 'time-entries-export-hint', 'arbeitszeit-check-tz-context'] as $below) {
			$this->assertGreaterThan(
				$listPos,
				strpos($content, $below),
				$below . ' must render below the entries list'
			);
		}
		// Primary controls stay on top.
		$this->assertLessThan($listPos, strpos($content, 'btn-add-entry'));
	}

	public function testCreateFormShowsWorkflowNoticesAboveSubmit(): void
	{
		$content = self::src(self::$timeEntries);

		$formPos = strpos($content, 'azc-card time-entries-page__form');
		$calloutPos = strpos($content, 'time-entries-page__workflow-callout');
		$actionsPos = strpos($content, 'time-entry-form__actions');
		$this->assertNotFalse($formPos);
		$this->assertNotFalse($calloutPos);
		$this->assertNotFalse($actionsPos);
		$this->assertGreaterThan($formPos, $calloutPos, 'workflow callouts belong inside the form card');
		$this->assertGreaterThan($calloutPos, $actionsPos, 'workflow callouts sit above the submit row');
	}

	public function testStatCardBlockIsSharedBetweenVacationAndSick(): void
	{
		$content = self::src(self::$dashboard);
		$css = self::src(self::$dashboardCss);

		$this->assertStringContainsString('azc-dashboard-sick dashboard-stat-card', $content);
		$this->assertStringContainsString('azc-dashboard-vacation dashboard-stat-card', $content);
		$this->assertStringNotContainsString('dashboard-vacation-card__', $content);
		$this->assertStringContainsString('grid-area: sick', $css);
		$this->assertStringContainsString('sick sick sick sick', $css);
	}
}
