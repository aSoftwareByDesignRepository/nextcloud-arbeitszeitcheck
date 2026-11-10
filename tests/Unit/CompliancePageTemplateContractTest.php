<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The compliance screen is one page. Subpage links and the help disclosure stay gone,
 * and the admin scan stays behind its flag.
 *
 * The status card is a summary: stat tiles + severity shortcut pills + a
 * most-common line. Grouped breakdown tables were consolidated out — the
 * itemized list below is the only table.
 */
final class CompliancePageTemplateContractTest extends TestCase {
	public function testDashboardIsOnePageWithoutSubpageLinksOrHelpDisclosure(): void {
		$dashboard = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/compliance-dashboard.php');
		$start = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/page-start.php');
		$controller = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/ComplianceController.php');

		self::assertStringNotContainsString('compliance-tabs.php', $dashboard);
		self::assertStringNotContainsString('compliance.violations', $dashboard);
		self::assertStringNotContainsString('compliance.reports', $dashboard);
		self::assertStringNotContainsString('View All Violations', $dashboard);
		self::assertStringNotContainsString('Managers and administrators', $dashboard);
		self::assertStringNotContainsString('<h1', $dashboard);
		self::assertStringContainsString('id="compliance-status"', $dashboard);
		self::assertStringContainsString('id="compliance-violations"', $dashboard);
		self::assertStringContainsString('id="violations-table"', $dashboard);
		self::assertStringContainsString("'id' => 'violations-table-pager'", $dashboard);
		self::assertStringContainsString("'pageParam' => 'v_page'", $dashboard);
		self::assertStringContainsString('data-azc-row', $dashboard);
		self::assertStringContainsString('common/table-pager', $controller);
		self::assertStringContainsString('data-filter-user-id', $dashboard);
		self::assertStringContainsString('azc-table--responsive', $dashboard);
		self::assertStringContainsString('showComplianceRunCheck', $dashboard);
		self::assertStringContainsString('id="btn-run-compliance-check"', $dashboard);
		self::assertStringContainsString('collapsePageHelp', $start);
		self::assertStringContainsString("'collapsePageHelp' => false", $controller);
		self::assertStringContainsString('showComplianceRunCheck\' => $this->permissionService->isAdmin($userId)', $controller);
		self::assertSame(2, substr_count($dashboard, 'showComplianceRunCheck'));
		self::assertSame(1, substr_count($dashboard, 'id="btn-run-compliance-check"'));
	}

	public function testStatusCardIsTilesAndPillsNotBreakdownTables(): void {
		$dashboard = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/compliance-dashboard.php');

		// New summary structure: four stat tiles — score, open, fixed, top type.
		self::assertStringContainsString('compliance-status__tiles', $dashboard);
		self::assertStringContainsString('id="azc-compliance-open-count"', $dashboard);
		self::assertStringContainsString('id="azc-compliance-fixed-count"', $dashboard);
		self::assertStringContainsString('compliance-status__tile-value--text', $dashboard);
		self::assertStringContainsString('complianceTopTypeText', $dashboard);
		self::assertSame(4, substr_count($dashboard, 'class="compliance-status__tile"'));

		// The severity chips are gone — the filter bar owns severity filtering.
		self::assertStringNotContainsString('azc-severity-pill', $dashboard);
		self::assertStringNotContainsString('compliance-status__pills', $dashboard);
		self::assertStringNotContainsString('compliance-status__shortcuts', $dashboard);
		self::assertStringNotContainsString('compliance-status__top-types', $dashboard);
		self::assertStringNotContainsString('aria-pressed', $dashboard);

		// Old grouped tables and their pagers are gone.
		self::assertStringNotContainsString('compliance-reports-heading', $dashboard);
		self::assertStringNotContainsString('compliance-by-type', $dashboard);
		self::assertStringNotContainsString('compliance-by-severity', $dashboard);
		self::assertStringNotContainsString("'t_page'", $dashboard);
		self::assertStringNotContainsString("'s_page'", $dashboard);
		self::assertStringNotContainsString('typePager', $dashboard);
		self::assertStringNotContainsString('severityPager', $dashboard);
		self::assertStringNotContainsString('compliance-status__figure', $dashboard);
		self::assertStringNotContainsString('compliance-status__breakdown', $dashboard);
	}

	public function testViolationsListHasATypeFilterFedByTheCanonicalTypeList(): void {
		$dashboard = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/compliance-dashboard.php');
		$violations = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/compliance-violations.php');
		$controller = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/ComplianceController.php');
		$entity = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Db/ComplianceViolation.php');
		$labels = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Support/ViolationLabels.php');

		// Both filter bars carry the type select, fed from violationTypeOptions.
		foreach ([$dashboard, $violations] as $tpl) {
			self::assertStringContainsString('id="type-filter"', $tpl);
			self::assertStringContainsString('violationTypeOptions', $tpl);
		}
		// The options come from the canonical entity list via the shared labels
		// helper — never a third hand-maintained map.
		self::assertStringContainsString('ViolationLabels::typeMap', $controller);
		self::assertStringContainsString('ComplianceViolation::TYPES', $labels);
		self::assertStringContainsString('public const TYPES', $entity);
	}

	public function testReportFiguresHaveNoSecondFrameAndStackOnANarrowScreen(): void {
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/compliance-violations.css');
		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/compliance-violations.php');
		$patterns = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/page-patterns.css');
		self::assertStringContainsString('.compliance-status__tiles', $css);
		self::assertStringContainsString('.compliance-status__tile', $css);
		self::assertStringContainsString('order: -1;', $css);
		self::assertStringContainsString('repeat(4, minmax(0, 1fr))', $css);
		self::assertStringContainsString('repeat(2, minmax(0, 1fr))', $css);
		self::assertStringContainsString('.compliance-status__tile-value--text', $css);
		// The pill styling left with the chips.
		self::assertStringNotContainsString('azc-severity-pill', $css);
		self::assertStringNotContainsString('compliance-status__pills', $css);
		// Empty table rows render the shared empty-state recipe (td.azc-table-empty + .azc-empty-state).
		self::assertStringContainsString('class="azc-table-empty"', $tpl);
		self::assertStringContainsString('azc-empty-state', $tpl);
		self::assertStringContainsString('td.azc-table-empty > .azc-empty-state', $patterns);
		self::assertStringContainsString('white-space: normal;', $css);
		self::assertStringContainsString('text-align: center;', $patterns);
		self::assertStringContainsString('grid-template-columns: 1fr;', $css);
		self::assertStringContainsString('.compliance-dashboard__violations .compliance-violations__filters', $css);
		self::assertStringContainsString('border: 0;', $css);
		// The old figure/breakdown styling left with the tables.
		self::assertStringNotContainsString('.compliance-status__figures', $css);
		self::assertStringNotContainsString('.compliance-status__breakdown', $css);
		self::assertStringNotContainsString('.compliance-tabs', (string)file_get_contents(dirname(__DIR__, 2) . '/css/arbeitszeitcheck-main.css'));
	}
}
