<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\CustomAbsenceType;
use OCA\ArbeitszeitCheck\Db\CustomAbsenceTypeMapper;
use OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshotMapper;
use OCA\ArbeitszeitCheck\Db\PolicyCohortMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriod;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriodMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicy;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicyMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementType;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\SpecialEntitlementException;
use OCA\ArbeitszeitCheck\Service\SpecialEntitlementAdminService;
use OCA\ArbeitszeitCheck\Service\SpecialEntitlementResolver;
use OCA\ArbeitszeitCheck\Service\SpecialEntitlementService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SpecialEntitlementAdminServiceTest extends TestCase
{
	private SpecialEntitlementTypeMapper&MockObject $types;
	private SpecialEntitlementPolicyMapper&MockObject $policies;
	private SpecialEntitlementPeriodMapper&MockObject $periods;
	private SpecialEntitlementReservationMapper&MockObject $reservations;
	private SpecialEntitlementLedgerEntryMapper&MockObject $ledger;
	private CustomAbsenceTypeMapper&MockObject $customTypes;
	private IUserManager&MockObject $users;
	private AuditLogMapper&MockObject $audit;
	private ILockingProvider&MockObject $locks;
	private IDBConnection&MockObject $db;
	private SpecialEntitlementAdminService $service;
	private WorkforceSuiteConfig $cfg;
	private SpecialEntitlementResolver $resolver;

	protected function setUp(): void
	{
		$appConfig = $this->createMock(IConfig::class);
		$appConfig->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default): string =>
				in_array($key, ['special_entitlements_enabled', 'policy_cohorts_enabled'], true) ? '1' : '0'
		);
		$this->cfg = new WorkforceSuiteConfig($appConfig);

		$this->types = $this->createMock(SpecialEntitlementTypeMapper::class);
		$this->policies = $this->createMock(SpecialEntitlementPolicyMapper::class);
		$this->periods = $this->createMock(SpecialEntitlementPeriodMapper::class);
		$this->reservations = $this->createMock(SpecialEntitlementReservationMapper::class);
		$this->ledger = $this->createMock(SpecialEntitlementLedgerEntryMapper::class);
		$this->customTypes = $this->createMock(CustomAbsenceTypeMapper::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->audit = $this->createMock(AuditLogMapper::class);
		$this->locks = $this->createMock(ILockingProvider::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->resolver = $this->makeResolver();
		$this->service = $this->makeService();
	}

	private function makeResolver(): SpecialEntitlementResolver
	{
		$memberships = $this->createMock(\OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper::class);
		$memberships->method('findEffectiveForUser')->willReturn([]);
		return new SpecialEntitlementResolver(
			$this->policies,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class),
			$this->createMock(TeamMapper::class),
			new \OCA\ArbeitszeitCheck\Service\PolicyCohortResolver($this->cfg, $memberships),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService::class),
		);
	}

	private function makeService(
		?\OCA\ArbeitszeitCheck\Db\TeamMemberMapper $teamMembers = null,
		?\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper $userModels = null,
		?\OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper $cohortMemberships = null,
	): SpecialEntitlementAdminService {
		$entitlements = new SpecialEntitlementService(
			$this->cfg,
			$this->types,
			$this->periods,
			$this->reservations,
			$this->ledger,
			$this->resolver,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\VacationHoursDebitService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\EntitlementSnapshotService::class),
			$this->audit,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
			$this->locks,
			$this->db,
		);

		return new SpecialEntitlementAdminService(
			$this->cfg,
			$this->types,
			$this->policies,
			$this->periods,
			$this->reservations,
			$this->ledger,
			$this->resolver,
			$entitlements,
			$this->createMock(EntitlementComputationSnapshotMapper::class),
			$this->customTypes,
			$this->createMock(WorkingTimeModelMapper::class),
			$this->createMock(TeamMapper::class),
			$this->createMock(PolicyCohortMapper::class),
			$this->createMock(TariffRuleSetMapper::class),
			$this->users,
			$this->audit,
			$this->locks,
			$this->db,
			$teamMembers,
			$userModels,
			$cohortMemberships,
		);
	}

	private function type(bool $active = true): SpecialEntitlementType
	{
		$type = new SpecialEntitlementType();
		$type->setId(7);
		$type->setCode('custom_recovery');
		$type->setPublicLabel('Recovery');
		$type->setAbsenceTypeCode('custom_recovery');
		$type->setUnit(SpecialEntitlementType::UNIT_DAYS);
		$type->setPeriodType(SpecialEntitlementType::PERIOD_CALENDAR_YEAR);
		$type->setActive($active);
		$type->setVersion(3);
		return $type;
	}

	private function period(string $status = SpecialEntitlementPeriod::STATUS_OPEN, string $end = '2026-01-31'): SpecialEntitlementPeriod
	{
		$period = new SpecialEntitlementPeriod();
		$period->setId(31);
		$period->setEntitlementTypeId(7);
		$period->setUserId('u1');
		$period->setPeriodStart(new \DateTime('2026-01-01'));
		$period->setPeriodEnd(new \DateTime($end));
		$period->setComputedAmount(5.0);
		$period->setUnit('days');
		$period->setStatus($status);
		$period->setRevision(2);
		$period->setComputationSnapshotId(88);
		return $period;
	}

	private function policy(): SpecialEntitlementPolicy
	{
		$policy = new SpecialEntitlementPolicy();
		$policy->setId(12);
		$policy->setEntitlementTypeId(7);
		$policy->setScopeType(SpecialEntitlementPolicy::SCOPE_TEAM);
		$policy->setScopeId('42');
		$policy->setOperation(SpecialEntitlementPolicy::OP_ADD);
		$policy->setAmount(2.0);
		$policy->setPriority(100);
		$policy->setEffectiveFrom(new \DateTime('2026-01-01'));
		$policy->setEffectiveTo(null);
		$policy->setVersion(4);
		return $policy;
	}

	public function testClosePeriodRejectsMissingRevisionFailClosed(): void
	{
		$this->periods->method('find')->willReturn($this->period(end: '2020-01-31'));
		$this->expectException(SpecialEntitlementException::class);
		$this->expectExceptionMessage('revision');
		$this->service->closePeriod(31, 0, 'admin');
	}

	public function testClosePeriodRejectsActiveReservations(): void
	{
		$this->periods->method('find')->willReturn($this->period(end: '2020-01-31'));
		$this->reservations->method('activeAmountForPeriod')->with(31)->willReturn(1.0);

		$this->expectException(SpecialEntitlementException::class);
		$this->expectExceptionMessage('active reservations');
		$this->service->closePeriod(31, 2, 'admin');
	}

	public function testClosePeriodRejectsUnendedPeriod(): void
	{
		$this->periods->method('find')->willReturn($this->period(end: '2999-12-31'));
		$this->expectException(SpecialEntitlementException::class);
		$this->expectExceptionMessage('ended');
		$this->service->closePeriod(31, 2, 'admin');
	}

	public function testClosePeriodCommitsStatusExactlyOnce(): void
	{
		$period = $this->period(end: '2020-01-31');
		$this->periods->method('find')->willReturn($period);
		$this->reservations->method('activeAmountForPeriod')->willReturn(0.0);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->periods->expects($this->once())->method('updateIfRevision')->willReturn(true);
		$this->audit->expects($this->once())->method('logAction');

		$result = $this->service->closePeriod(31, 2, 'admin');
		self::assertSame('closed', $result['status']);
		self::assertSame(SpecialEntitlementPeriod::STATUS_CLOSED, $period->getStatus());
	}

	public function testClosePeriodIsIdempotentWhenAlreadyClosed(): void
	{
		$this->periods->method('find')->willReturn($this->period(SpecialEntitlementPeriod::STATUS_CLOSED, '2020-01-31'));
		$this->periods->expects($this->never())->method('updateIfRevision');
		$result = $this->service->closePeriod(31, 2, 'admin');
		self::assertSame('closed', $result['status']);
	}

	public function testPostCorrectionRejectsClosedPeriod(): void
	{
		$this->periods->method('find')->willReturn($this->period(SpecialEntitlementPeriod::STATUS_CLOSED, '2020-01-31'));
		$this->expectException(SpecialEntitlementException::class);
		$this->expectExceptionMessage('closed');
		$this->service->postCorrection(31, ['revision' => 2, 'amount' => '1.00', 'reason' => 'x', 'eventKey' => 'evt-12345678'], 'admin');
	}

	public function testUpdatePolicyRejectsImmutableScopeChange(): void
	{
		$this->policies->method('find')->willReturn($this->policy());
		$this->types->method('find')->willReturn($this->type());
		$this->expectException(SpecialEntitlementException::class);
		$this->expectExceptionMessage('immutable');
		$this->service->updatePolicy(12, ['scopeType' => 'user', 'revision' => 4], 'admin');
	}

	public function testUpdatePolicyDetectsOverlapOnNewRange(): void
	{
		$this->policies->method('find')->willReturn($this->policy());
		$this->types->method('find')->willReturn($this->type());
		$this->policies->method('findOverlapping')->willReturn([new SpecialEntitlementPolicy()]);

		$this->expectException(SpecialEntitlementException::class);
		$this->expectExceptionMessage('overlapping');
		$this->service->updatePolicy(12, ['revision' => 4, 'effectiveTo' => '2026-12-31'], 'admin');
	}

	public function testUpdatePolicyCasFailsOnStaleVersion(): void
	{
		$this->policies->method('find')->willReturn($this->policy());
		$this->types->method('find')->willReturn($this->type());
		$this->policies->method('findOverlapping')->willReturn([]);

		$this->expectException(SpecialEntitlementException::class);
		$this->expectExceptionMessage('stale');
		$this->service->updatePolicy(12, ['revision' => 1, 'reason' => 'x'], 'admin');
	}

	public function testUpdatePolicyCommitsCasAndAudit(): void
	{
		$policy = $this->policy();
		$this->policies->method('find')->willReturn($policy);
		$this->types->method('find')->willReturn($this->type());
		$this->policies->method('findOverlapping')->willReturn([]);
		$this->policies->expects($this->once())->method('updateIfVersion')
			->willReturnCallback(static function (SpecialEntitlementPolicy $p): bool {
				$p->setVersion((int)$p->getVersion() + 1);
				return true;
			});
		$this->db->expects($this->once())->method('commit');
		$this->audit->expects($this->once())->method('logAction')
			->with('admin', 'special_entitlement_policy_updated', 'special_entitlement_policy', 12);

		$result = $this->service->updatePolicy(12, ['revision' => 4, 'effectiveTo' => '2026-06-30', 'reason' => 'Amended'], 'admin');
		self::assertSame('2026-06-30', $result['effectiveTo']);
		self::assertSame(5, $result['version']);
	}

	public function testSetTypeActiveDeactivationDisablesLinkedCustomType(): void
	{
		$type = $this->type(true);
		$custom = new CustomAbsenceType();
		$custom->setCode('custom_recovery');
		$custom->setActive(true);

		$this->types->method('find')->willReturn($type);
		$this->customTypes->method('findByCode')->with('custom_recovery')->willReturn($custom);
		$this->customTypes->expects($this->once())->method('update')
			->with(self::callback(static fn (CustomAbsenceType $c): bool => $c->getActive() === false));
		$this->types->expects($this->once())->method('update')->willReturnArgument(0);
		$this->db->expects($this->once())->method('commit');

		$this->service->setTypeActive(7, false, 3, 'admin');
		self::assertFalse($type->getActive());
	}

	public function testPreviewPolicyEnumeratesTeamScopeAndSimulatesCandidate(): void
	{
		$this->types->method('find')->willReturn($this->type());
		// An existing L0 org policy so "before" has a baseline of 1.00.
		$org = new SpecialEntitlementPolicy();
		$org->setId(5);
		$org->setEntitlementTypeId(7);
		$org->setScopeType(SpecialEntitlementPolicy::SCOPE_ORG);
		$org->setScopeId(SpecialEntitlementPolicy::ORG_SCOPE_ID);
		$org->setOperation(SpecialEntitlementPolicy::OP_REPLACE);
		$org->setAmount(1.0);
		$org->setPriority(0);
		$org->setEffectiveFrom(new \DateTime('2020-01-01'));
		$this->policies->method('findActiveForType')->willReturn([$org]);

		$teamMembers = $this->createMock(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class);
		$teamMembers->method('countMembers')->with(42)->willReturn(7);
		$teamMembers->method('findMemberUserIds')->with(42, 3)->willReturn(['u1', 'u2']);
		// u1/u2 must be resolvable as team members: the resolver needs teamDepths.
		// TeamMemberMapper::findByUserId is consumed by the resolver's scopeContext —
		// our mock answers it inside makeResolver's team-members mock? No: the
		// resolver got its own TeamMemberMapper mock in setUp. Give it memberships.
		$member = new \OCA\ArbeitszeitCheck\Db\TeamMember();
		$member->setUserId('u1');
		$member->setTeamId(42);

		// Build a resolver whose TeamMemberMapper returns membership for u1/u2.
		$tm = $this->createMock(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class);
		$tm->method('findByUserId')->willReturnCallback(
			static fn (string $uid): array => in_array($uid, ['u1', 'u2'], true) ? [$member] : [],
		);
		$memberships = $this->createMock(\OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper::class);
		$memberships->method('findEffectiveForUser')->willReturn([]);
		$this->resolver = new SpecialEntitlementResolver(
			$this->policies,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			$tm,
			$this->createMock(TeamMapper::class),
			new \OCA\ArbeitszeitCheck\Service\PolicyCohortResolver($this->cfg, $memberships),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService::class),
		);

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$user->method('getDisplayName')->willReturn('User One');
		$this->users->method('get')->willReturn($user);

		$service = $this->makeService($teamMembers);
		$out = $service->previewPolicy([
			'entitlementTypeId' => 7,
			'scopeType' => 'team',
			'scopeId' => '42',
			'operation' => 'add',
			'amount' => '2.00',
			'effectiveFrom' => '2027-06-01',
		], 'admin');

		self::assertSame(7, $out['recipientCount']);
		self::assertCount(2, $out['samples']);
		self::assertSame('1.00', $out['samples'][0]['before']);
		self::assertSame('3.00', $out['samples'][0]['after']);
		self::assertSame([], $out['warnings']);
	}

	public function testPreviewPolicyWarnsOnZeroRecipientsAndShrink(): void
	{
		$this->types->method('find')->willReturn($this->type());
		$teamMembers = $this->createMock(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class);
		$teamMembers->method('countMembers')->willReturn(0);
		$teamMembers->method('findMemberUserIds')->willReturn([]);

		$service = $this->makeService($teamMembers);
		$out = $service->previewPolicy([
			'entitlementTypeId' => 7,
			'scopeType' => 'team',
			'scopeId' => '42',
			'operation' => 'replace',
			'amount' => '1.00',
			'effectiveFrom' => '2027-06-01',
		], 'admin');
		self::assertSame(0, $out['recipientCount']);
		self::assertContains('no_recipients', $out['warnings']);
	}

	public function testPreviewPolicyRejectsUnknownScope(): void
	{
		$this->types->method('find')->willReturn($this->type());
		$this->expectException(SpecialEntitlementException::class);
		$this->service->previewPolicy([
			'entitlementTypeId' => 7,
			'scopeType' => 'user',
			'scopeId' => 'ghost-user',
			'operation' => 'add',
			'amount' => '1.00',
			'effectiveFrom' => '2027-06-01',
		], 'admin');
	}

	public function testSetTypeActiveActivationWithoutLinkedCustomRowFails(): void
	{
		$type = $this->type(false);
		$this->types->method('find')->willReturn($type);
		$this->customTypes->method('findByCode')->willThrowException(new DoesNotExistException('missing'));

		$this->expectException(SpecialEntitlementException::class);
		$this->service->setTypeActive(7, true, 3, 'admin');
	}
}
