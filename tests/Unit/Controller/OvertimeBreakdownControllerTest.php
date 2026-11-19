<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\OvertimeBreakdownController;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Service\CompDaysEstimateService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\OversightScopeService;
use OCA\ArbeitszeitCheck\Service\PaidAbsencePlannedHoursCreditService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Endpoint contract: scope authZ (uniform 404 — unknown and non-overseeable
 * targets are indistinguishable), period resolution (year/month/custom
 * incl. validation), target pass-through and response shape.
 *
 * OversightScopeService is final, so the real authZ chain runs here —
 * mocked PermissionService/TeamResolver/IUserManager drive the
 * self/admin/manager/denied matrix.
 */
class OvertimeBreakdownControllerTest extends TestCase
{
	/** @var IUserSession&\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;
	/** @var OvertimeService&\PHPUnit\Framework\MockObject\MockObject */
	private $overtimeService;
	/** @var PaidAbsencePlannedHoursCreditService&\PHPUnit\Framework\MockObject\MockObject */
	private $creditService;
	/** @var UserOvertimeSettingsService&\PHPUnit\Framework\MockObject\MockObject */
	private $settings;
	/** @var CompDaysEstimateService&\PHPUnit\Framework\MockObject\MockObject */
	private $estimate;
	/** @var IL10N&\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;
	/** @var PermissionService&\PHPUnit\Framework\MockObject\MockObject */
	private $permissions;
	/** @var TeamResolverService&\PHPUnit\Framework\MockObject\MockObject */
	private $teamResolver;
	/** @var IUserManager&\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	protected function setUp(): void
	{
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->overtimeService = $this->createMock(OvertimeService::class);
		$this->creditService = $this->createMock(PaidAbsencePlannedHoursCreditService::class);
		$this->settings = $this->createMock(UserOvertimeSettingsService::class);
		$this->estimate = $this->createMock(CompDaysEstimateService::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $s): string => $s,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		// Every uid resolves to an existing user — the scope service only
		// rejects targets that don't exist before checking admin/manager.
		$this->userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => $uid === 'ghost' ? null : $this->existingUser($uid),
		);

		$this->settings->method('resolveEffectiveYearStart')
			->willReturn(new \DateTime('2026-01-05'));
		$this->overtimeService->method('workedHoursByDayRange')
			->willReturn([0.0, []]);
		$this->emptyBreakdown();
	}

	private function existingUser(string $uid): IUser
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		return $u;
	}

	/** @var array<string, mixed> Returned by every creditBreakdownForRange call. */
	private array $breakdownFixture = [
		'credit_hours' => 0.0,
		'days_credited' => 0,
		'reduced_hours' => 0.0,
		'days_reduced' => 0,
		'credited' => [],
		'reduced' => [],
		'not_counted' => [],
	];

	/** @var list<array{0: string, 1: \DateTimeInterface, 2: \DateTimeInterface, 3: array}> Recorded calls. */
	private array $breakdownCalls = [];

	private function emptyBreakdown(): void
	{
		$this->creditService->method('creditBreakdownForRange')
			->willReturnCallback(function (string $uid, \DateTimeInterface $start, \DateTimeInterface $end, array $worked): array {
				$this->breakdownCalls[] = [$uid, $start, $end, $worked];
				return $this->breakdownFixture;
			});
	}

	private function scope(): OversightScopeService
	{
		return new OversightScopeService(
			$this->permissions,
			$this->teamResolver,
			$this->createMock(TeamMapper::class),
			$this->createMock(TeamMemberMapper::class),
			$this->createMock(TeamManagerMapper::class),
			$this->userManager,
		);
	}

	private function controller(): OvertimeBreakdownController
	{
		return new OvertimeBreakdownController(
			'arbeitszeitcheck',
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->overtimeService,
			$this->creditService,
			$this->scope(),
			$this->settings,
			$this->estimate,
			$this->l10n,
		);
	}

	public function testSelfScopeDefaultsToActor(): void
	{
		$this->estimate->method('estimate')->with('alice')->willReturn([
			'days' => 3, 'daily_hours' => 7.8, 'balance_hours' => 24.0, 'basis' => 'weekly_contract',
		]);

		$response = $this->controller()->breakdown();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['success']);
		$this->assertSame('alice', $data['user_id']);
		$this->assertSame('year', $data['period']['preset']);
		// Effective year start (tracking-from aware), not bare Jan 1.
		$this->assertSame('2026-01-05', $data['period']['start']);
		$this->assertArrayHasKey('credit_hours', $data);
		$this->assertArrayHasKey('not_counted', $data);
		$this->assertSame(3, $data['comp_estimate']['days']);
		$this->assertArrayHasKey('algorithm_version', $data);
		$this->assertArrayHasKey('generated_at', $data);
	}

	public function testAdminCanReadOverseeableTarget(): void
	{
		$this->permissions->method('isAdmin')->with('alice')->willReturn(true);
		$this->breakdownFixture = [
			'credit_hours' => 8.0, 'days_credited' => 1,
			'reduced_hours' => 0.0, 'days_reduced' => 0,
			'credited' => [[
				'absence_id' => 7, 'type' => 'vacation', 'status' => 'approved',
				'start' => '2026-03-02', 'end' => '2026-03-02',
				'credit_hours' => 8.0, 'overlap_days' => 0,
				'days' => [['date' => '2026-03-02', 'planned' => 8.0, 'absent' => 8.0, 'worked' => 0.0, 'credited' => 8.0]],
			]],
			'reduced' => [], 'not_counted' => [],
		];
		$this->creditService->expects($this->once())
			->method('creditBreakdownForRange')
			->with('bob', $this->anything(), $this->anything(), $this->anything());

		$response = $this->controller()->breakdown('bob');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('bob', $data['user_id']);
		// Labels are localized requester-side.
		$this->assertSame('Vacation', $data['credited'][0]['label']);
	}

	public function testManagerReadsManagedMemberOnly(): void
	{
		$this->permissions->method('isAdmin')->willReturn(false);
		$this->permissions->method('canAccessManagerDashboard')->with('alice')->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->with('alice')->willReturn(['bob']);

		$this->assertSame(Http::STATUS_OK, $this->controller()->breakdown('bob')->getStatus());
		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller()->breakdown('mallory')->getStatus(),
		);
	}

	public function testNonOverseeableTargetGetsUniform404(): void
	{
		$this->permissions->method('isAdmin')->willReturn(false);
		$this->permissions->method('canAccessManagerDashboard')->willReturn(false);
		$this->creditService->expects($this->never())->method('creditBreakdownForRange');

		$response = $this->controller()->breakdown('mallory');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('NOT_FOUND', $data['error']);
		$this->assertSame('Not found.', $data['message']);
		$this->assertCount(0, $data['fields']);
	}

	public function testUnknownTargetGetsIdentical404NoOracle(): void
	{
		// Nonexistent uid fails the existence leg inside canOverseeUser —
		// the wire payload must be identical to the permission deny.
		$unknown = $this->controller()->breakdown('ghost');
		$denied = $this->controller()->breakdown('mallory');

		$this->assertSame(Http::STATUS_NOT_FOUND, $unknown->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $denied->getStatus());
		$this->assertEquals(
			json_decode(json_encode($denied->getData()), true),
			json_decode(json_encode($unknown->getData()), true),
		);
	}

	public function testMonthPresetUsesCurrentMonthRange(): void
	{
		$response = $this->controller()->breakdown(null, 'month');

		$this->assertSame('month', $response->getData()['period']['preset']);
		$this->assertCount(1, $this->breakdownCalls);
		[$uid, $start, $end] = $this->breakdownCalls[0];
		$this->assertSame('alice', $uid);
		$this->assertSame('01', $start->format('d'));
		$this->assertSame((new \DateTime())->format('Y-m-d'), $end->format('Y-m-d'));
	}

	public function testCustomRangeIsValidated(): void
	{
		$this->creditService->expects($this->never())->method('creditBreakdownForRange');

		foreach ([
			['2026-03-99', '2026-03-10'],   // garbage start
			['2026-03-10', 'not-a-date'],   // garbage end
			['2026-03-10', '2026-03-01'],   // end before start
			[null, '2026-03-01'],           // missing start
			['2026-03-01', null],           // missing end
		] as [$start, $end]) {
			$response = $this->controller()->breakdown(null, 'custom', $start, $end);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), "start=$start end=$end");
			$this->assertSame('invalid_period', $response->getData()['error']);
		}
	}

	public function testCustomRangeTooLongRejected(): void
	{
		$this->creditService->expects($this->never())->method('creditBreakdownForRange');

		$response = $this->controller()->breakdown(null, 'custom', '2023-01-01', '2026-03-01');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_period', $response->getData()['error']);
	}

	public function testCustomRangeAtMaxSpanAccepted(): void
	{
		$end = new \DateTime('today');
		$start = (clone $end)->modify('-731 days');
		$response = $this->controller()->breakdown(null, 'custom', $start->format('Y-m-d'), $end->format('Y-m-d'));

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $this->breakdownCalls);
	}

	public function testCustomRangeClampedToToday(): void
	{
		$start = (new \DateTime('-300 days'))->format('Y-m-d');
		$response = $this->controller()->breakdown(null, 'custom', $start, '2999-01-01');

		$this->assertSame('custom', $response->getData()['period']['preset']);
		$this->assertCount(1, $this->breakdownCalls);
		[$uid, $startDt, $endDt] = $this->breakdownCalls[0];
		$this->assertSame($start, $startDt->format('Y-m-d'));
		// End far in the future → clamped to today before the span cap.
		$this->assertSame((new \DateTime())->format('Y-m-d'), $endDt->format('Y-m-d'));
	}

	public function testUnknownPeriodRejected(): void
	{
		$response = $this->controller()->breakdown(null, 'quarter');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_period', $response->getData()['error']);
	}

	public function testUnauthenticatedPropagatesNotAuthenticatedException(): void
	{
		// The 401 envelope lives in ApiExceptionMiddleware — the controller
		// must NOT swallow the exception into a 500 (regression: getUserId
		// was inside the try/catch(\Throwable)).
		$anon = $this->createMock(IUserSession::class);
		$anon->method('getUser')->willReturn(null);
		$controller = new OvertimeBreakdownController(
			'arbeitszeitcheck',
			$this->createMock(IRequest::class),
			$anon,
			$this->overtimeService,
			$this->creditService,
			$this->scope(),
			$this->settings,
			$this->estimate,
			$this->l10n,
		);

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\NotAuthenticatedException::class);
		$controller->breakdown();
	}
}
