<?php

declare(strict_types=1);

/**
 * Atlas coverage lane — listener behavior tests:
 * CSPListener policy merge, LoadUsersSettingsArbeitszeitListener admin gating,
 * UserDeletedListener cascade cleanup incl. substitute re-notification.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Listener;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshotMapper;
use OCA\ArbeitszeitCheck\Db\KioskCredMapper;
use OCA\ArbeitszeitCheck\Db\KioskSessionMapper;
use OCA\ArbeitszeitCheck\Db\MobileSeatMapper;
use OCA\ArbeitszeitCheck\Db\MobileStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper;
use OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserOvertimeYearBalanceMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationRolloverLogMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Listener\CSPListener;
use OCA\ArbeitszeitCheck\Listener\LoadUsersSettingsArbeitszeitListener;
use OCA\ArbeitszeitCheck\Listener\UserDeletedListener;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskSettingsService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ListenerCoverageTest extends TestCase
{
	public function testCspListenerIgnoresForeignEvents(): void
	{
		$l = new CSPListener();
		$l->handle(new Event()); // must be a no-op
		$this->addToAssertionCount(1);
	}

	public function testCspListenerAddsFontAndStyleDomainsOnly(): void
	{
		$l = new CSPListener();
		$manager = $this->getMockBuilder(\OC\Security\CSP\ContentSecurityPolicyManager::class)
			->disableOriginalConstructor()->getMock();
		$captured = null;
		$manager->method('addDefaultPolicy')->willReturnCallback(
			static function ($policy) use (&$captured) { $captured = $policy; }
		);
		$event = new AddContentSecurityPolicyEvent($manager);
		$l->handle($event);

		// The listener must contribute an EmptyContentSecurityPolicy with
		// font/style relaxations and must NOT weaken script-src (no eval).
		$this->assertInstanceOf(EmptyContentSecurityPolicy::class, $captured);
		$built = $captured->buildPolicy();
		$this->assertStringContainsString('fonts.gstatic.com', $built);
		$this->assertStringContainsString('fonts.googleapis.com', $built);
		$this->assertStringNotContainsString('unsafe-eval', $built);
	}

	private function usersSettingsListener(IUserSession $session, PermissionService $perm, IInitialState $initial): LoadUsersSettingsArbeitszeitListener
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.test/apps/arbeitszeitcheck/admin/users/AZC_UID_TMPL/overtime');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		return new LoadUsersSettingsArbeitszeitListener($session, $perm, $initial, $url, $factory);
	}

	private function templateEvent(): object
	{
		// BeforeTemplateRenderedEvent lives in OCA\Settings — construct without ctor deps.
		$ref = new \ReflectionClass(\OCA\Settings\Events\BeforeTemplateRenderedEvent::class);
		return $ref->newInstanceWithoutConstructor();
	}

	public function testUsersSettingsListenerSkipsNonAdmin(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$perm = $this->createMock(PermissionService::class);
		$perm->method('isAdmin')->willReturn(false);

		$initial = $this->createMock(IInitialState::class);
		$initial->expects($this->never())->method('provideInitialState');

		$l = $this->usersSettingsListener($session, $perm, $initial);
		$l->handle($this->templateEvent());
		$l->handle(new Event()); // foreign event -> no-op
		$this->addToAssertionCount(1);
	}

	public function testUsersSettingsListenerProvidesInitialStateForAdmin(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$perm = $this->createMock(PermissionService::class);
		$perm->method('isAdmin')->with('admin')->willReturn(true);

		$initial = $this->createMock(IInitialState::class);
		$initial->expects($this->once())->method('provideInitialState')
			->with('arbeitszeitcheckNewUserOvertime', $this->callback(
				static fn ($v) => is_array($v)
					&& ($v['uidPlaceholder'] ?? '') === 'AZC_UID_TMPL'
					&& str_contains((string)($v['overtimePutUrlTemplate'] ?? ''), 'AZC_UID_TMPL')
					&& isset($v['strings']['fieldset'], $v['strings']['invalidDate'])
			));

		$l = $this->usersSettingsListener($session, $perm, $initial);
		$l->handle($this->templateEvent());
	}

	private function userDeletedListener(array $overrides = []): array
	{
		$mocks = [];
		foreach ([
			'timeEntryMapper' => TimeEntryMapper::class,
			'absenceMapper' => AbsenceMapper::class,
			'complianceViolationMapper' => ComplianceViolationMapper::class,
			'auditLogMapper' => AuditLogMapper::class,
			'userSettingsMapper' => UserSettingsMapper::class,
			'userWorkingTimeModelMapper' => UserWorkingTimeModelMapper::class,
			'teamMemberMapper' => TeamMemberMapper::class,
			'teamManagerMapper' => TeamManagerMapper::class,
			'mobileSeatMapper' => MobileSeatMapper::class,
			'mobileStampIdempotencyMapper' => MobileStampIdempotencyMapper::class,
			'kioskCredMapper' => KioskCredMapper::class,
			'kioskSessionMapper' => KioskSessionMapper::class,
			'kioskSettingsService' => KioskSettingsService::class,
			'vacationYearBalanceMapper' => VacationYearBalanceMapper::class,
			'vacationRolloverLogMapper' => VacationRolloverLogMapper::class,
			'userOvertimeYearBalanceMapper' => UserOvertimeYearBalanceMapper::class,
			'overtimePayoutMapper' => OvertimePayoutMapper::class,
			'overtimeAdjustmentMapper' => OvertimeAdjustmentMapper::class,
			'userVacationPolicyAssignmentMapper' => UserVacationPolicyAssignmentMapper::class,
			'entitlementComputationSnapshotMapper' => EntitlementComputationSnapshotMapper::class,
			'notificationService' => NotificationService::class,
			'permissionService' => PermissionService::class,
		] as $name => $class) {
			$mocks[$name] = $overrides[$name] ?? $this->createMock($class);
		}
		$mocks['l10n'] = $this->createMock(IL10N::class);
		$mocks['l10n']->method('t')->willReturnArgument(0);
		$mocks['logger'] = $this->createMock(LoggerInterface::class);

		$listener = new UserDeletedListener(
			$mocks['timeEntryMapper'], $mocks['absenceMapper'], $mocks['complianceViolationMapper'],
			$mocks['auditLogMapper'], $mocks['userSettingsMapper'], $mocks['userWorkingTimeModelMapper'],
			$mocks['teamMemberMapper'], $mocks['teamManagerMapper'], $mocks['mobileSeatMapper'],
			$mocks['mobileStampIdempotencyMapper'], $mocks['kioskCredMapper'], $mocks['kioskSessionMapper'],
			$mocks['kioskSettingsService'], $mocks['vacationYearBalanceMapper'], $mocks['vacationRolloverLogMapper'],
			$mocks['userOvertimeYearBalanceMapper'], $mocks['overtimePayoutMapper'], $mocks['overtimeAdjustmentMapper'],
			$mocks['userVacationPolicyAssignmentMapper'], $mocks['entitlementComputationSnapshotMapper'],
			$mocks['notificationService'], $mocks['permissionService'], $mocks['l10n'], $mocks['logger'],
		);
		return [$listener, $mocks];
	}

	private function userDeletedEvent(string $uid = 'deleted-user'): UserDeletedEvent
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return new UserDeletedEvent($user);
	}

	public function testUserDeletedListenerCascadesAllMappers(): void
	{
		[$listener, $m] = $this->userDeletedListener();

		$m['permissionService']->expects($this->once())->method('purgeUser')->with('deleted-user');
		$m['timeEntryMapper']->expects($this->once())->method('deleteByUser')->with('deleted-user');
		$m['absenceMapper']->expects($this->once())->method('deleteByUser')->with('deleted-user');
		$m['absenceMapper']->method('findBySubstituteUser')->willReturn([]);
		$m['complianceViolationMapper']->expects($this->once())->method('deleteByUser')->with('deleted-user');
		$m['auditLogMapper']->expects($this->once())->method('deleteByUser')->with('deleted-user');
		$m['userSettingsMapper']->expects($this->once())->method('deleteByUser')->with('deleted-user');
		$m['userWorkingTimeModelMapper']->expects($this->once())->method('deleteByUser')->with('deleted-user');
		$m['kioskSettingsService']->expects($this->once())->method('setUserKioskAllowed')->with('deleted-user', false);
		$m['notificationService']->expects($this->never())->method('notifySubstituteDeclined');

		$listener->handle($this->userDeletedEvent());
		$listener->handle(new Event()); // foreign event -> no-op
		$this->addToAssertionCount(1);
	}

	public function testUserDeletedListenerClearsSubstituteAndNotifies(): void
	{
		$absence = new Absence();
		$absence->setUserId('employee1');
		$absence->setSubstituteUserId('deleted-user');
		$absence->setStatus(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->setType('vacation');
		$absence->setStartDate(new \DateTime('2026-08-01'));
		$absence->setEndDate(new \DateTime('2026-08-10'));
		$absence->setDays(7.0);

		[$listener, $m] = $this->userDeletedListener();
		$m['absenceMapper']->method('findBySubstituteUser')->with('deleted-user')->willReturn([$absence]);
		$m['absenceMapper']->expects($this->once())->method('update')
			->with($this->callback(static fn (Absence $a) =>
				$a->getStatus() === Absence::STATUS_PENDING   // substitute-pending falls back to pending
				&& $a->getSubstituteUserId() === null
				&& $a->getUpdatedAt() instanceof \DateTime
			));
		$m['notificationService']->expects($this->once())->method('notifySubstituteDeclined')
			->with('employee1', 'deleted-user', $this->callback(static fn ($payload) =>
				is_array($payload)
				&& ($payload['start_date'] ?? '') === '2026-08-01'
				&& ($payload['end_date'] ?? '') === '2026-08-10'
				&& ($payload['days'] ?? null) === 7.0
			), $this->isType('string'));

		$listener->handle($this->userDeletedEvent());
	}

	public function testUserDeletedListenerSwallowsCleanupErrors(): void
	{
		[$listener, $m] = $this->userDeletedListener();
		$m['timeEntryMapper']->method('deleteByUser')->willThrowException(new \RuntimeException('db gone'));
		$m['logger']->expects($this->once())->method('error')
			->with($this->isType('string'), $this->callback(
				static fn ($ctx) => ($ctx['userId'] ?? '') === 'deleted-user' && ($ctx['exception'] ?? null) instanceof \Throwable
			));

		$listener->handle($this->userDeletedEvent()); // must not propagate
	}
}
