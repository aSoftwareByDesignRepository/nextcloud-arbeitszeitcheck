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
		// The list and the three summary columns are their own frames.
		// An outer card around either one is a second box.
		$this->assertDoesNotMatchRegularExpression(
			'/class="[^"]*azc-card[^"]*time-entries-page__list/',
			$content
		);
		$this->assertDoesNotMatchRegularExpression(
			'/class="[^"]*azc-card[^"]*time-entries-page__stats/',
			$content
		);
		$this->assertSame(3, substr_count($content, 'class="stat-card"'));
	}

	public function testTimeEntriesTableHeaderIsLightAndSpaced(): void
	{
		$css = self::src(__DIR__ . '/../../../css/time-entries.css');

		$this->assertMatchesRegularExpression(
			'/time-entries-page__list \.table thead th\s*\{[^}]*background-color:\s*var\(--azc-bg-soft\)/s',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/time-entries-page__list \.table-container\s*\{[^}]*margin:\s*4em 0;/s',
			$css
		);
		$pagerCss = self::src(__DIR__ . '/../../../css/common/table-pager.css');
		$this->assertStringContainsString('.table-container > .azc-table-pager', $pagerCss);
		$content = self::src(self::$timeEntries);
		$this->assertStringContainsString("'id' => 'time-entries-table-pager'", $content);
		$this->assertStringContainsString('data-azc-row', $content);
		$this->assertStringNotContainsString('id="btn-prev-page"', $content);
		$this->assertStringNotContainsString('id="btn-next-page"', $content);
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

	public function testTimeEntriesFilterKeepsWorkLocationAndResetOnOneRow(): void
	{
		$content = self::src(self::$timeEntries);
		$css = self::src(__DIR__ . '/../../../css/time-entries.css');
		$js = self::src(__DIR__ . '/../../../js/arbeitszeitcheck-main.js');

		$this->assertStringContainsString('id="filter-work-location"', $content);
		$this->assertStringContainsString('name="work_location"', $content);
		$this->assertStringContainsString("IconCatalog::render('rotate'", $content);
		$this->assertStringContainsString('time-entries-page__filter-reset', $content);
		$this->assertStringContainsString("title=\"<?php p(\$l->t('Clear')); ?>\"", $content);
		// The reset control is the icon. The word stays on the tooltip and the accessible name.
		$this->assertDoesNotMatchRegularExpression(
			'/id="btn-clear-filter"[\s\S]*?\>\s*<\?php p\(\$l->t\(\'Clear\'\)\); \?>/',
			$content
		);
		$this->assertStringNotContainsString('azc-app--time-entries .azc-btn--secondary', $css);
		$this->assertStringContainsString('flex-wrap: nowrap', $css);
		$this->assertStringContainsString('flex-direction: row', $css);
		$this->assertStringContainsString("params.append('work_location', workLocation)", $js);
		$this->assertStringNotContainsString('azc-filter-panel__intro', $content);
		$this->assertStringContainsString('time-entries-page__filter.is-open + .time-entries-page__list .table-container', $css);
		$this->assertMatchesRegularExpression(
			'/time-entries-page__filter\.is-open \+ \.time-entries-page__list \.table-container\s*\{[^}]*margin-top:\s*2em/s',
			$css
		);
	}

	public function testAbsencesFilterMatchesTimeEntriesRow(): void
	{
		$content = self::src(__DIR__ . '/../../../templates/absences.php');
		$css = self::src(__DIR__ . '/../../../css/absences.css');
		$js = self::src(__DIR__ . '/../../../js/arbeitszeitcheck-main.js');

		$this->assertStringContainsString("IconCatalog::render('rotate'", $content);
		$this->assertStringContainsString('id="filter-type"', $content);
		$this->assertStringContainsString('AbsenceTypeCatalog::creatableLabels($l)', $content);
		$this->assertStringContainsString('absences-page__filter-reset', $content);
		$this->assertStringContainsString("title=\"<?php p(\$l->t('Clear')); ?>\"", $content);
		$this->assertDoesNotMatchRegularExpression(
			'/id="btn-clear-filter"[\s\S]*?\>\s*<\?php p\(\$l->t\(\'Clear\'\)\); \?>/',
			$content
		);
		$this->assertStringNotContainsString('azc-filter-panel__intro', $content);
		$this->assertStringContainsString('flex-wrap: nowrap', $css);
		$this->assertStringContainsString('flex-direction: row', $css);
		$this->assertStringContainsString('absences-page__filter.is-open + .section--list .table-container', $css);
		$this->assertStringContainsString("filterSection.classList.toggle('is-open', visible)", $js);
		$this->assertStringContainsString("params.append('type', type)", $js);
	}

	public function testStatCardBlockIsSharedBetweenVacationAndSick(): void
	{
		$content = self::src(self::$dashboard);
		$css = self::src(self::$dashboardCss);

		$this->assertStringContainsString('azc-dashboard-sick dashboard-stat-card', $content);
		$this->assertStringContainsString('azc-dashboard-vacation dashboard-stat-card', $content);
		$this->assertStringNotContainsString('dashboard-vacation-card__', $content);
		$this->assertStringContainsString('.azc-dashboard-balance-row > .azc-card', $css);
		$this->assertStringContainsString('.azc-dashboard-sick.azc-card', $css);
	}

	public function testProjectCheckLinkingOffSitsBelowTheTimezoneAndDismisses(): void
	{
		$dashboard = self::src(self::$dashboard);
		$tz = strpos($dashboard, 'azc-dashboard-punch__tz');
		$include = strpos($dashboard, 'projectcheck-linking-disabled-callout.php');
		$this->assertNotFalse($tz);
		$this->assertNotFalse($include);
		$this->assertGreaterThan($tz, $include);
		$this->assertSame(1, substr_count($dashboard, 'projectcheck-linking-disabled-callout.php'));
		$this->assertStringContainsString('$azcProjectCheckCalloutDismissible = true', $dashboard);

		$partial = self::src(__DIR__ . '/../../../templates/partials/projectcheck-linking-disabled-callout.php');
		$this->assertStringContainsString('data-hint-dismiss="<?php p($hintId); ?>"', $partial);
		$this->assertStringContainsString("\$hintId = 'dashboard-projectcheck-linking-off'", $partial);
		$this->assertStringContainsString('azc-hint__dismiss', $partial);
		$this->assertStringContainsString('Hide this help text permanently', $partial);
		// Time-entry and edit keep the plain callout when the flag is off.
		$this->assertStringContainsString('role="status"', $partial);

		$js = self::src(__DIR__ . '/../../../js/arbeitszeitcheck-main.js');
		$this->assertStringContainsString('this.initDismissibleHints()', $js);
		$initPage = substr($js, (int)strpos($js, 'const initPage'), 600);
		$this->assertStringContainsString('this.initDismissibleHints()', $initPage);
	}
}
