<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Dashboard;

use OCA\ArbeitszeitCheck\Dashboard\AdminGlobalStatusWidget;
use OCA\ArbeitszeitCheck\Dashboard\WidgetIconHelper;
use OCA\ArbeitszeitCheck\Service\DashboardWidgetDataService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end user-journey contract for the company-status widget flow
 * (data → scannable items → obvious CTAs), including accessibility-oriented
 * spoken summary (title + subtitle + button labels a screen reader would hear).
 */
class AdminGlobalStatusWidgetJourneyTest extends TestCase {
	public function testAdminGlanceJourneyFromOverloadedDirectory(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $s, array $p = []): string => $p === [] ? $s : (string)vsprintf($s, $p)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string => $count === 1 ? $singular : str_replace('%n', (string)$count, $plural)
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/apps/arbeitszeitcheck/img/app-dark.svg');
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static function (string $route, array $params = []): string {
				return match ($route) {
					'arbeitszeitcheck.admin.users' => '/apps/arbeitszeitcheck/admin/users',
					'arbeitszeitcheck.admin.userDetail' => '/apps/arbeitszeitcheck/admin/users/' . rawurlencode((string)($params['userId'] ?? '')),
					default => '/apps/arbeitszeitcheck/admin',
				};
			}
		);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $v): string => 'https://example.local' . $v
		);

		$dataService = $this->createMock(DashboardWidgetDataService::class);
		$dataService->method('getAdminWidgetData')->willReturn([
			'authorized' => true,
			'users' => [
				['userId' => 'late', 'displayName' => 'Late Larry', 'status' => 'clocked_out', 'workingTodayHours' => 0],
				['userId' => 'work', 'displayName' => 'Working Wendy', 'status' => 'active', 'workingTodayHours' => 4.25],
			],
			'summary' => [
				'total' => 500,
				'active' => 12,
				'break' => 3,
				'paused' => 1,
				'clocked_out' => 484,
				'other' => 0,
			],
			'absenceSummary' => [
				'total_absent' => 2,
				'vacation' => 2,
				'sick' => 0,
				'other_absent' => 0,
			],
			'summaryTruncated' => true,
			'summaryScopeLimit' => 500,
			'directoryTotal' => 2628,
		]);

		$widget = new AdminGlobalStatusWidget(
			$l10n,
			$urlGenerator,
			$dataService,
			new WidgetIconHelper($urlGenerator),
		);

		$this->assertSame('Company status', $widget->getTitle());

		$items = $widget->getItemsV2('admin', null, 7)->getItems();
		$this->assertSame('12 of 500 working', $items[0]->getTitle());
		$this->assertSame('3 on break · 1 paused · 484 out · 2 away (2 vacation)', $items[0]->getSubtitle());
		$this->assertSame('Working Wendy', $items[1]->getTitle());
		$this->assertSame('Working · 4.25 h', $items[1]->getSubtitle());

		$spoken = [];
		$spoken[] = $widget->getTitle();
		foreach ($items as $item) {
			$spoken[] = trim($item->getTitle() . '. ' . $item->getSubtitle());
		}
		$buttons = $widget->getWidgetButtons('admin');
		foreach ($buttons as $button) {
			$spoken[] = $button->getText();
		}
		$script = implode(' ', $spoken);

		$this->assertStringContainsString('12 of 500 working', $script);
		$this->assertStringContainsString('Working Wendy', $script);
		$this->assertStringContainsString('Open employees', $script);
		$this->assertStringContainsString('Open dashboard', $script);
		$this->assertStringNotContainsString('Total:', $script);
		$this->assertStringNotContainsString('Status:', $script);
		$this->assertStringNotContainsString('directory', strtolower($script));

		$this->assertStringContainsString('/admin/users', $buttons[0]->getLink());
		$this->assertStringContainsString('/admin/users/work', $items[1]->getLink());

		$footer = $widget->getItemsV2('admin')->getHalfEmptyContentMessage();
		$this->assertSame('Showing counts for the first 500 of 2628 people.', $footer);
		$this->assertLessThanOrEqual(80, strlen($footer));
	}
}
