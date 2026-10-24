<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Dashboard;

use OCA\ArbeitszeitCheck\Dashboard\AdminGlobalStatusWidget;
use OCA\ArbeitszeitCheck\Dashboard\WidgetIconHelper;
use OCA\ArbeitszeitCheck\Service\DashboardWidgetDataService;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class AdminGlobalStatusWidgetTest extends TestCase {
	/** @var IL10N&\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;
	/** @var IURLGenerator&\PHPUnit\Framework\MockObject\MockObject */
	private $urlGenerator;
	/** @var DashboardWidgetDataService&\PHPUnit\Framework\MockObject\MockObject */
	private $dataService;

	protected function setUp(): void {
		parent::setUp();

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $s, array $p = []): string => $p === [] ? $s : (string)vsprintf($s, $p)
		);
		$this->l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string => $count === 1 ? $singular : str_replace('%n', (string)$count, $plural)
		);

		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('imagePath')->willReturn('/apps/arbeitszeitcheck/img/app-dark.svg');
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(
			static function (string $route, array $params = []): string {
				if ($route === 'arbeitszeitcheck.admin.userDetail') {
					return '/apps/arbeitszeitcheck/admin/users/' . rawurlencode((string)($params['userId'] ?? ''));
				}
				if ($route === 'arbeitszeitcheck.admin.users') {
					return '/apps/arbeitszeitcheck/admin/users';
				}
				return '/apps/arbeitszeitcheck/admin';
			}
		);
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $v): string => 'https://example.local' . $v
		);

		$this->dataService = $this->createMock(DashboardWidgetDataService::class);
	}

	private function createWidget(): AdminGlobalStatusWidget {
		return new AdminGlobalStatusWidget(
			$this->l10n,
			$this->urlGenerator,
			$this->dataService,
			new WidgetIconHelper($this->urlGenerator),
		);
	}

	/** @return array<string, mixed> */
	private function sampleAuthorizedData(bool $truncated = false): array {
		return [
			'authorized' => true,
			'users' => [
				['userId' => 'ac_dbg', 'displayName' => 'ac_dbg', 'status' => 'clocked_out', 'workingTodayHours' => 0.0],
				['userId' => 'alice', 'displayName' => 'Alice', 'status' => 'active', 'workingTodayHours' => 3.5],
				['userId' => 'bob', 'displayName' => 'Bob', 'status' => 'break', 'workingTodayHours' => 1.0],
			],
			'summary' => [
				'total' => 500,
				'active' => 0,
				'break' => 0,
				'paused' => 0,
				'clocked_out' => 500,
				'other' => 0,
			],
			'absenceSummary' => [
				'vacation' => 0,
				'sick' => 0,
				'other_absent' => 0,
				'total_absent' => 0,
			],
			'summaryTruncated' => $truncated,
			'summaryScopeLimit' => 500,
			'directoryTotal' => 2628,
		];
	}

	public function testTitleIsShortEnoughForPanelHeader(): void {
		$widget = $this->createWidget();
		$title = $widget->getTitle();
		$this->assertSame('Company status', $title);
		$this->assertLessThanOrEqual(20, mb_strlen($title));
		$this->assertStringNotContainsString('overview', strtolower($title));
	}

	public function testUnauthorizedShowsClearEmptyState(): void {
		$this->dataService->method('getAdminWidgetData')->willReturn([
			'authorized' => false,
			'users' => [],
			'summary' => [],
		]);
		$items = $this->createWidget()->getItemsV2('u1');
		$this->assertInstanceOf(WidgetItems::class, $items);
		$this->assertSame([], $items->getItems());
		$this->assertStringContainsString('administrators', $items->getEmptyContentMessage());
	}

	public function testItemsLeadWithScannableSummaryNotWallOfText(): void {
		$this->dataService->method('getAdminWidgetData')->willReturn($this->sampleAuthorizedData(true));
		$items = $this->createWidget()->getItemsV2('admin', null, 7);
		$list = $items->getItems();

		$this->assertNotEmpty($list);
		$this->assertSame('0 of 500 working', $list[0]->getTitle());
		$this->assertSame('500 out', $list[0]->getSubtitle());

		$footer = $items->getHalfEmptyContentMessage();
		$this->assertSame('Showing counts for the first 500 of 2628 people.', $footer);
		$this->assertStringNotContainsString('Total:', $footer);
		$this->assertStringNotContainsString('Working:', $footer);
		$this->assertStringNotContainsString('Open Employees', $footer);
		$this->assertStringNotContainsString('directory', strtolower($footer));
	}

	public function testPeopleSortedWorkingFirstWithCleanSubtitles(): void {
		$this->dataService->method('getAdminWidgetData')->willReturn($this->sampleAuthorizedData());
		$list = $this->createWidget()->getItemsV2('admin', null, 7)->getItems();

		$this->assertSame('Alice', $list[1]->getTitle());
		$this->assertSame('Working · 3.5 h', $list[1]->getSubtitle());
		$this->assertSame('Bob', $list[2]->getTitle());
		$this->assertSame('On Break · 1 h', $list[2]->getSubtitle());
		$this->assertSame('ac_dbg', $list[3]->getTitle());
		$this->assertSame('Clocked Out · 0 h', $list[3]->getSubtitle());

		foreach (array_slice($list, 1) as $item) {
			$this->assertStringNotContainsString('Status:', $item->getSubtitle());
			$this->assertStringNotContainsString('Today:', $item->getSubtitle());
		}
	}

	public function testPersonRowsLinkToEmployeeDetail(): void {
		$this->dataService->method('getAdminWidgetData')->willReturn($this->sampleAuthorizedData());
		$list = $this->createWidget()->getItemsV2('admin')->getItems();
		$this->assertStringContainsString('/admin/users/alice', $list[1]->getLink());
	}

	public function testTruncationAddsEmployeesButton(): void {
		$this->dataService->expects($this->once())
			->method('getAdminWidgetData')
			->willReturn($this->sampleAuthorizedData(true));

		$widget = $this->createWidget();
		$widget->getItemsV2('admin');
		$buttons = $widget->getWidgetButtons('admin');

		$this->assertCount(2, $buttons);
		$this->assertSame('Open employees', $buttons[0]->getText());
		$this->assertStringContainsString('/admin/users', $buttons[0]->getLink());
		$this->assertSame('Open dashboard', $buttons[1]->getText());
		$this->assertSame(WidgetButton::TYPE_MORE, $buttons[0]->getType());
	}

	public function testNoTruncationKeepsSingleDashboardButtonAndEmptyFooter(): void {
		$this->dataService->method('getAdminWidgetData')->willReturn($this->sampleAuthorizedData(false));
		$widget = $this->createWidget();
		$items = $widget->getItemsV2('admin');
		$buttons = $widget->getWidgetButtons('admin');

		$this->assertSame('', $items->getHalfEmptyContentMessage());
		$this->assertCount(1, $buttons);
		$this->assertSame('Open dashboard', $buttons[0]->getText());
	}

	public function testLoadDoesNotPullTimeClientOrTranslations(): void {
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Dashboard/AdminGlobalStatusWidget.php');
		$this->assertStringNotContainsString('TimeClientBootstrap', $src);
		$this->assertStringNotContainsString('registerTimeClientForWidget', $src);
		$this->assertStringContainsString('registerDeskletStylesForWidget', $src);
	}

	public function testUxContractForbidsLegacyWallOfTextPatterns(): void {
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Dashboard/AdminGlobalStatusWidget.php');
		$this->assertStringNotContainsString('Total:%1$d, Working:%2$d', $src);
		$this->assertStringNotContainsString('Status: %1$s, Today: %2$s h', $src);
		$this->assertStringNotContainsString('Open admin dashboard', $src);
		$this->assertStringNotContainsString('Company status overview', $src);
		$this->assertStringNotContainsString('Open Employees for the full list', $src);
	}
}
