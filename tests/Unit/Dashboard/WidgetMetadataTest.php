<?php

declare(strict_types=1);

/**
 * Metadata + rendering-contract tests for the three dashboard widgets.
 * Proves widget ids/titles/order/icons stay stable and getItemsV2/buttons
 * produce real content (Atlas coverage lane).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Dashboard;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Dashboard\AdminGlobalStatusWidget;
use OCA\ArbeitszeitCheck\Dashboard\EmployeeStatusWidget;
use OCA\ArbeitszeitCheck\Dashboard\ManagerTeamStatusWidget;
use OCA\ArbeitszeitCheck\Dashboard\WidgetIconHelper;
use OCA\ArbeitszeitCheck\Service\DashboardDeskletRenderService;
use OCA\ArbeitszeitCheck\Service\DashboardWidgetDataService;
use OCA\ArbeitszeitCheck\Support\TimeClientBootstrap;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class WidgetMetadataTest extends TestCase
{
	protected function tearDown(): void
	{
		// load() flips process-static registration flags inside
		// TimeClientBootstrap; reset so later tests see a clean slate.
		$ref = new \ReflectionClass(TimeClientBootstrap::class);
		foreach (['configRegistered', 'scriptsRegistered'] as $property) {
			$prop = $ref->getProperty($property);
			$prop->setAccessible(true);
			$prop->setValue(null, false);
		}
		parent::tearDown();
	}

	private function l10n(): IL10N
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $params = []): string {
				foreach ($params as $i => $p) {
					$text = str_replace('%' . ($i + 1) . '$s', (string)$p, $text);
				}
				return str_replace('%s', '%s', $text); // %s left literal for remaining placeholders
			}
		);
		return $l10n;
	}

	private function urlGen(): IURLGenerator
	{
		$u = $this->createMock(IURLGenerator::class);
		$u->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $p = []): string => '/apps/' . str_replace('.', '/', $route)
		);
		$u->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $u2): string => 'https://nc.test' . $u2
		);
		$u->method('imagePath')->willReturnCallback(
			static fn (string $app, string $file): string => '/apps/' . $app . '/img/' . $file
		);
		return $u;
	}

	private function iconHelper(): WidgetIconHelper
	{
		// final class — instantiate with a stubbed URL generator
		return new WidgetIconHelper($this->urlGen());
	}

	private function timeClientBootstrap(?IInitialState $initial = null): TimeClientBootstrap
	{
		// final classes — instantiate with stubbed collaborators
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn ($a, $k, $d = '') => $d !== '' ? $d : 'UTC'
		);
		$dtz = $this->createMock(\OCP\IDateTimeZone::class);
		$dtz->method('getTimeZone')->willReturn(new \DateTimeZone('UTC'));
		$tz = new \OCA\ArbeitszeitCheck\Service\TimeZoneService(
			$config, $dtz, $this->createMock(IUserSession::class),
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);
		return new TimeClientBootstrap($tz, $dtz, $initial ?? $this->createMock(IInitialState::class));
	}

	private function employeeWidget(array $data = [], ?DashboardWidgetDataService $dataService = null): EmployeeStatusWidget
	{
		$ds = $dataService ?? $this->createMock(DashboardWidgetDataService::class);
		if ($dataService === null) {
			$ds->method('getEmployeeWidgetData')->willReturn(array_merge([
			'userId' => 'alice',
			'status' => 'active',
			'workingTodayHours' => 3.5,
			'currentSessionDuration' => 3600,
			'sessionStartFormatted' => '08:00',
			'breakStartFormatted' => '',
			'weekHoursWorked' => 20.0,
			'weekHoursRequired' => 40.0,
			'weeklyContractHours' => 40.0,
			'cumulativeBalance' => 1.5,
			'displayBalance' => 1.5,
			'breakRequired' => true,
			'remainingBreakMinutes' => 15,
			'breakWarningLevel' => 'warn',
			'vacationYear' => 2026,
			'vacationYearLabel' => '',
			'vacationYearError' => null,
			'vacationRemaining' => 12.5,
			'vacationEntitlement' => 30.0,
			'vacationUnit' => 'days',
			'vacationCarryoverUsable' => 2.0,
			'timeCapture' => ['clockStampingEnabled' => true],
			'lawLabelBreaks' => 'ArbZG §4',
		], $data));
		}

		$tcb = $this->timeClientBootstrap();

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);

		$desklet = $this->createMock(DashboardDeskletRenderService::class);
		$desklet->method('renderForUser')->willReturn(['status' => 'active']);

		return new EmployeeStatusWidget(
			$this->l10n(),
			$this->urlGen(),
			$ds,
			$tcb,
			$this->iconHelper(),
			$session,
			$this->createMock(IInitialState::class),
			$desklet,
		);
	}

	private function managerWidget(array $data = []): ManagerTeamStatusWidget
	{
		$ds = $this->createMock(DashboardWidgetDataService::class);
		$ds->method('getManagerWidgetData')->willReturn(array_merge([
			'authorized' => true,
			'summary' => ['total' => 2, 'active' => 1],
			'absenceSummary' => ['today' => 0],
			'members' => [
				['userId' => 'bob', 'displayName' => 'Bob', 'status' => 'active', 'workingTodayHours' => 4.0],
				['userId' => 'carol', 'displayName' => 'Carol', 'status' => 'clocked_out', 'workingTodayHours' => 0.0],
			],
		], $data));

		return new ManagerTeamStatusWidget(
			$this->l10n(),
			$this->urlGen(),
			$ds,
			$this->iconHelper(),
		);
	}

	private function adminWidget(array $data = []): AdminGlobalStatusWidget
	{
		$ds = $this->createMock(DashboardWidgetDataService::class);
		$ds->method('getAdminWidgetData')->willReturn(array_merge([
			'authorized' => true,
			'summary' => ['total' => 5, 'active' => 3],
			'absenceSummary' => ['today' => 1],
			'users' => [
				['userId' => 'bob', 'displayName' => 'Bob', 'status' => 'active'],
			],
		], $data));

		return new AdminGlobalStatusWidget(
			$this->l10n(),
			$this->urlGen(),
			$ds,
			$this->iconHelper(),
		);
	}

	public function testEmployeeMetadata(): void
	{
		$w = $this->employeeWidget();
		$this->assertSame(Application::APP_ID . '-employee-status', $w->getId());
		$this->assertSame('My work status', $w->getTitle());
		$this->assertSame(30, $w->getOrder());
		$this->assertSame('icon-history', $w->getIconClass());
		$this->assertStringContainsString('app-dashboard.svg', (string)$w->getIconUrl());
		$this->assertStringContainsString('/apps/', (string)$w->getUrl());
		$this->assertSame(30, $w->getReloadInterval());
	}

	public function testManagerMetadata(): void
	{
		$w = $this->managerWidget();
		$this->assertSame(Application::APP_ID . '-manager-team-status', $w->getId());
		$this->assertSame('Team status', $w->getTitle());
		$this->assertSame(40, $w->getOrder());
		$this->assertSame('icon-group', $w->getIconClass());
		$this->assertStringContainsString('manager/dashboard', (string)$w->getUrl());
		$this->assertSame(45, $w->getReloadInterval());
	}

	public function testAdminMetadata(): void
	{
		$w = $this->adminWidget();
		$this->assertSame(Application::APP_ID . '-admin-global-status', $w->getId());
		$this->assertSame('Company status', $w->getTitle());
		$this->assertSame(50, $w->getOrder());
		$this->assertSame('icon-dashboard', $w->getIconClass());
		$this->assertStringContainsString('admin', (string)$w->getUrl());
		$this->assertSame(60, $w->getReloadInterval());
	}

	public function testWidgetLoadRegistersDeskletStyles(): void
	{
		// load() must not throw and must route through the trait's
		// registerTimeClientForWidget / registerDeskletStylesForWidget helpers.
		$this->adminWidget()->load();
		$this->managerWidget()->load();
		$this->addToAssertionCount(1);
	}

	public function testEmployeeItemsIncludeStatusTodayWeekBalanceVacation(): void
	{
		$items = $this->employeeWidget()->getItemsV2('alice')->getItems();
		$this->assertGreaterThanOrEqual(6, count($items));
		$this->assertInstanceOf(WidgetItem::class, $items[0]);
		// item 1: status headline 'Working' (active)
		$this->assertSame('Working', $items[0]->getTitle());
		// item 7 present: break-required warning (breakRequired + remainingBreakMinutes)
		$titles = array_map(static fn (WidgetItem $i) => $i->getTitle(), $items);
		$this->assertContains('Vacation pool', $titles);
		$this->assertTrue(count(array_filter($titles, static fn ($t) => str_contains($t, 'Break required'))) === 1);
	}

	public function testEmployeeItemsClockedOutFallbackOnServiceFailure(): void
	{
		// data service throws -> fallbackEmployeeWidgetData path
		$ds = $this->createMock(DashboardWidgetDataService::class);
		$ds->method('getEmployeeWidgetData')->willThrowException(new \RuntimeException('db down'));
		$w = $this->employeeWidget([], $ds);

		$items = $w->getItemsV2('alice')->getItems();
		$this->assertNotEmpty($items);
		$this->assertSame('Clocked Out', $items[0]->getTitle());
	}

	public function testEmployeeButtonsMatchStatus(): void
	{
		$buttons = $this->employeeWidget()->getWidgetButtons('alice');
		$this->assertCount(2, $buttons);
		$this->assertSame('Start Break', $buttons[0]->getText());

		$buttonsPaused = $this->employeeWidget(['status' => 'paused'])->getWidgetButtons('alice');
		$this->assertSame('Resume after break', $buttonsPaused[0]->getText());
	}

	public function testEmployeeLoadProvidesInitialState(): void
	{
		$provided = [];
		$initial = $this->createMock(IInitialState::class);
		$initial->method('provideInitialState')
			->willReturnCallback(function (string $key, $data) use (&$provided, $initial) {
				$provided[$key] = $data;
				return $initial;
			});

		$ds = $this->createMock(DashboardWidgetDataService::class);
		$ds->method('getEmployeeWidgetData')->willReturn(['status' => 'clocked_out']);
		$tcb = $this->timeClientBootstrap();
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);
		$desklet = $this->createMock(DashboardDeskletRenderService::class);
		$desklet->method('renderForUser')->willReturn(['status' => 'active']);

		$w = new EmployeeStatusWidget(
			$this->l10n(), $this->urlGen(), $ds, $tcb, $this->iconHelper(),
			$session, $initial, $desklet,
		);
		$w->load();

		$this->assertSame('active', $provided['desklet']['status'] ?? null);
		$this->assertSame($w->getId(), $provided['desklet']['widgetPanelId'] ?? null);
	}

	public function testManagerItemsListMembers(): void
	{
		$items = $this->managerWidget()->getItemsV2('alice')->getItems();
		$this->assertCount(3, $items); // summary + 2 members
		$this->assertSame('Bob', $items[1]->getTitle());
		$this->assertSame('Carol', $items[2]->getTitle());
	}

	public function testManagerItemsEmptyMembers(): void
	{
		$items = $this->managerWidget(['members' => []])->getItemsV2('alice');
		$this->assertSame('No team members found.', $items->getEmptyContentMessage());
	}

	public function testManagerButtonsPointAtDashboard(): void
	{
		$buttons = $this->managerWidget()->getWidgetButtons('alice');
		$this->assertCount(1, $buttons);
		$this->assertSame('Open dashboard', $buttons[0]->getText());
	}
}
