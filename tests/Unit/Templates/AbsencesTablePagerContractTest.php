<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

final class AbsencesTablePagerContractTest extends TestCase {
	public function testAbsencesListFooterOffersThePageSizesAndATotal(): void {
		$root = dirname(__DIR__, 3);
		$template = (string)file_get_contents($root . '/templates/absences.php');
		$partial = (string)file_get_contents($root . '/templates/partials/table-pager.php');
		$css = (string)file_get_contents($root . '/css/common/table-pager.css');
		$controller = (string)file_get_contents($root . '/lib/Controller/PageController.php');

		self::assertStringContainsString("'id' => 'absences-table-pager'", $template);
		self::assertStringContainsString("include __DIR__ . '/partials/table-pager.php'", $template);
		self::assertStringContainsString('data-azc-row', $template);
		self::assertStringContainsString("name=\"<?php p(\$sizeKey); ?>\"", $partial);
		self::assertStringContainsString('TablePager::SIZES', $partial);
		self::assertStringContainsString("t('Total %s'", $partial);
		self::assertStringContainsString("t('%s/page'", $partial);
		self::assertStringContainsString("t('Entries per page')", $partial);
		self::assertStringContainsString('aria-current="page"', $partial);
		self::assertStringContainsString('common/table-pager', $controller);
		self::assertStringContainsString('justify-content: space-between', $css);
		self::assertStringContainsString('.azc-table-pager__page.is-current', $css);
		self::assertStringContainsString('.table-container > .azc-table-pager', $css);
		self::assertStringContainsString('border-top: 1px solid var(--azc-border, var(--color-border));', $css);
		self::assertStringContainsString('class="absences-table-scroll"', $template);
		$afterTable = substr($template, (int)strpos($template, '</table>'));
		$listClose = strpos($afterTable, '</section>');
		self::assertNotFalse($listClose);
		$region = substr($afterTable, 0, $listClose);
		self::assertStringContainsString("include __DIR__ . '/partials/table-pager.php'", $region);
		self::assertSame(2, substr_count($region, '</div>'));
		self::assertGreaterThan(
			(int)strpos($region, "include __DIR__ . '/partials/table-pager.php'"),
			(int)strrpos($region, '</div>'),
		);
	}
}
