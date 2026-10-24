<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Dashboard;

use OCA\ArbeitszeitCheck\Dashboard\ManagerTeamStatusWidget;
use OCA\ArbeitszeitCheck\Dashboard\WidgetIconHelper;
use OCA\ArbeitszeitCheck\Service\DashboardWidgetDataService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class ManagerTeamStatusWidgetTest extends TestCase {
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
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/arbeitszeitcheck/manager');
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $v): string => 'https://example.local' . $v
		);

		$this->dataService = $this->createMock(DashboardWidgetDataService::class);
	}

	private function createWidget(): ManagerTeamStatusWidget {
		return new ManagerTeamStatusWidget(
			$this->l10n,
			$this->urlGenerator,
			$this->dataService,
			new WidgetIconHelper($this->urlGenerator),
		);
	}

	public function testEmptyTeamShowsClearEmptyState(): void {
		$this->dataService->method('getManagerWidgetData')->willReturn([
			'authorized' => true,
			'members' => [],
			'summary' => ['total' => 0, 'active' => 0, 'break' => 0, 'paused' => 0, 'clocked_out' => 0],
			'absenceSummary' => ['total_absent' => 0, 'vacation' => 0, 'sick' => 0, 'other_absent' => 0],
		]);
		$items = $this->createWidget()->getItemsV2('mgr');
		$this->assertSame([], $items->getItems());
		$this->assertSame('No team members found.', $items->getEmptyContentMessage());
		$this->assertSame('', $items->getHalfEmptyContentMessage());
	}

	public function testTeamItemsAreScannableWithoutWallOfText(): void {
		$this->dataService->method('getManagerWidgetData')->willReturn([
			'authorized' => true,
			'members' => [
				['userId' => 'c', 'displayName' => 'Cara', 'status' => 'clocked_out', 'workingTodayHours' => 0],
				['userId' => 'a', 'displayName' => 'Ada', 'status' => 'active', 'workingTodayHours' => 2],
			],
			'summary' => [
				'total' => 2,
				'active' => 1,
				'break' => 0,
				'paused' => 0,
				'clocked_out' => 1,
			],
			'absenceSummary' => [
				'total_absent' => 0,
				'vacation' => 0,
				'sick' => 0,
				'other_absent' => 0,
			],
		]);

		$items = $this->createWidget()->getItemsV2('mgr');
		$list = $items->getItems();
		$this->assertSame('1 of 2 working', $list[0]->getTitle());
		$this->assertSame('1 out', $list[0]->getSubtitle());
		$this->assertSame('Ada', $list[1]->getTitle());
		$this->assertSame('Working · 2 h', $list[1]->getSubtitle());
		$this->assertSame('', $items->getHalfEmptyContentMessage());
	}

	public function testButtonLabelIsShort(): void {
		$buttons = $this->createWidget()->getWidgetButtons('mgr');
		$this->assertCount(1, $buttons);
		$this->assertSame('Open dashboard', $buttons[0]->getText());
	}

	public function testLoadDoesNotPullTimeClientOrTranslations(): void {
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Dashboard/ManagerTeamStatusWidget.php');
		$this->assertStringNotContainsString('TimeClientBootstrap', $src);
		$this->assertStringNotContainsString('registerTimeClientForWidget', $src);
	}

	public function testUxContractForbidsLegacyWallOfTextPatterns(): void {
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Dashboard/ManagerTeamStatusWidget.php');
		$this->assertStringNotContainsString('Working:%1$d, Break:%2$d', $src);
		$this->assertStringNotContainsString('Status: %1$s, Today: %2$s h', $src);
		$this->assertStringNotContainsString('Open manager dashboard', $src);
	}
}
