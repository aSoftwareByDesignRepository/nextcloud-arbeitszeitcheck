<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The compliance screen is one page. Subpage links and the help disclosure stay gone,
 * and the admin scan stays behind its flag.
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
		self::assertStringContainsString('id="compliance-reports-heading"', $dashboard);
		self::assertStringContainsString('id="compliance-violations"', $dashboard);
		self::assertStringContainsString('id="violations-table"', $dashboard);
		self::assertStringContainsString("'id' => 'violations-table-pager'", $dashboard);
		self::assertStringContainsString("'id' => 'compliance-by-type-pager'", $dashboard);
		self::assertStringContainsString("'id' => 'compliance-by-severity-pager'", $dashboard);
		self::assertStringContainsString("'pageParam' => 'v_page'", $dashboard);
		self::assertStringContainsString("'pageParam' => 't_page'", $dashboard);
		self::assertStringContainsString("'pageParam' => 's_page'", $dashboard);
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

	public function testReportFiguresHaveNoSecondFrameAndStackOnANarrowScreen(): void {
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/compliance-violations.css');
		self::assertStringContainsString('.compliance-status__figures', $css);
		self::assertStringContainsString('order: -1;', $css);
		self::assertStringContainsString('#violations-table td.empty-state', $css);
		self::assertStringContainsString('#violations-table td.empty-state .empty-state__description', $css);
		self::assertStringContainsString('white-space: normal;', $css);
		self::assertStringContainsString('max-width: none;', $css);
		self::assertStringContainsString('text-align: center;', $css);
		self::assertStringContainsString('grid-template-columns: 1fr;', $css);
		self::assertStringContainsString('.compliance-dashboard__violations .compliance-violations__filters', $css);
		self::assertStringContainsString('border: 0;', $css);
		self::assertStringNotContainsString('.compliance-tabs', (string)file_get_contents(dirname(__DIR__, 2) . '/css/arbeitszeitcheck-main.css'));
	}
}
