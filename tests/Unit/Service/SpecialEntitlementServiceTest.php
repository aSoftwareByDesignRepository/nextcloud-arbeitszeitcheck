<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshot;
use OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntry;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriod;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriodMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicyMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservation;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementType;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\SpecialEntitlementException;
use OCA\ArbeitszeitCheck\Service\EntitlementSnapshotService;
use OCA\ArbeitszeitCheck\Service\PolicyCohortResolver;
use OCA\ArbeitszeitCheck\Service\SpecialEntitlementResolver;
use OCA\ArbeitszeitCheck\Service\SpecialEntitlementService;
use OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService;
use OCA\ArbeitszeitCheck\Service\VacationHoursDebitService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SpecialEntitlementServiceTest extends TestCase
{
	private WorkforceSuiteConfig $config;
	private SpecialEntitlementTypeMapper&MockObject $types;
	private SpecialEntitlementPeriodMapper&MockObject $periods;
	private SpecialEntitlementReservationMapper&MockObject $reservations;
	private SpecialEntitlementLedgerEntryMapper&MockObject $ledger;
	private SpecialEntitlementResolver $resolver;
	private VacationHoursDebitService&MockObject $plannedHours;
	private EntitlementSnapshotService&MockObject $snapshots;
	private AuditLogMapper&MockObject $audit;
	private AbsenceMapper&MockObject $absences;
	private ILockingProvider&MockObject $locks;
	private IDBConnection&MockObject $db;
	private SpecialEntitlementService $service;

	protected function setUp(): void
	{
		$this->config = $this->suiteConfig(['special_entitlements_enabled' => true, 'policy_cohorts_enabled' => true]);
		$this->types = $this->createMock(SpecialEntitlementTypeMapper::class);
		$this->periods = $this->createMock(SpecialEntitlementPeriodMapper::class);
		$this->reservations = $this->createMock(SpecialEntitlementReservationMapper::class);
		$this->ledger = $this->createMock(SpecialEntitlementLedgerEntryMapper::class);
		$this->plannedHours = $this->createMock(VacationHoursDebitService::class);
		$this->snapshots = $this->createMock(EntitlementSnapshotService::class);
		$this->audit = $this->createMock(AuditLogMapper::class);
		$this->absences = $this->createMock(AbsenceMapper::class);
		$this->locks = $this->createMock(ILockingProvider::class);
		$this->db = $this->createMock(IDBConnection::class);

		$policies = $this->createMock(SpecialEntitlementPolicyMapper::class);
		$userModels = $this->createMock(UserWorkingTimeModelMapper::class);
		$teamMembers = $this->createMock(TeamMemberMapper::class);
		$teams = $this->createMock(TeamMapper::class);
		$memberships = $this->createMock(PolicyCohortMembershipMapper::class);
		$employment = $this->createMock(UserEmploymentSettingsService::class);
		$memberships->method('findEffectiveForUser')->willReturn([]);
		$teamMembers->method('findByUserId')->willReturn([]);
		$teams->method('getParentMap')->willReturn([]);
		$this->resolver = new SpecialEntitlementResolver(
			$policies,
			$userModels,
			$teamMembers,
			$teams,
			new PolicyCohortResolver($this->config, $memberships),
			$employment,
		);

		$this->service = new SpecialEntitlementService(
			$this->config,
			$this->types,
			$this->periods,
			$this->reservations,
			$this->ledger,
			$this->resolver,
			$this->plannedHours,
			$this->snapshots,
			$this->audit,
			$this->absences,
			$this->locks,
			$this->db,
		);
	}

	/** @param array<string,bool> $enabled */
	private function suiteConfig(array $enabled): WorkforceSuiteConfig
	{
		$appConfig = $this->createMock(IConfig::class);
		$appConfig->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default): string => ($enabled[$key] ?? false) ? '1' : '0');
		return new WorkforceSuiteConfig($appConfig);
	}

	private function type(string $unit = SpecialEntitlementType::UNIT_DAYS, bool $active = true, bool $allowNegative = false): SpecialEntitlementType
	{
		$type = new SpecialEntitlementType();
		$type->setId(7);
		$type->setCode('custom_recovery');
		$type->setPublicLabel('Recovery');
		$type->setConfidentialLabel('Health-related protected absence');
		$type->setAbsenceTypeCode('custom_recovery');
		$type->setUnit($unit);
		$type->setPeriodType(SpecialEntitlementType::PERIOD_CALENDAR_YEAR);
		$type->setAllowNegative($allowNegative);
		$type->setEmployeeMaySeeConfidentialLabel(false);
		$type->setActive($active);
		$type->setVersion(1);
		return $type;
	}

	private function absence(int $id = 21): Absence
	{
		$absence = new Absence();
		$absence->setId($id);
		$absence->setUserId('u1');
		$absence->setType('custom_recovery');
		$absence->setStartDate(new \DateTime('2026-03-02'));
		$absence->setEndDate(new \DateTime('2026-03-03'));
		$absence->setDays(2.0);
		return $absence;
	}

	private function period(int $id = 31, float $computed = 5.0): SpecialEntitlementPeriod
	{
		$period = new SpecialEntitlementPeriod();
		$period->setId($id);
		$period->setEntitlementTypeId(7);
		$period->setUserId('u1');
		$period->setPeriodStart(new \DateTime('2026-01-01'));
		$period->setPeriodEnd(new \DateTime('2026-12-31'));
		$period->setComputedAmount($computed);
		$period->setUnit('days');
		$period->setStatus(SpecialEntitlementPeriod::STATUS_OPEN);
		$period->setRevision(1);
		return $period;
	}

	/** @return array<string,mixed> */
	private function context(?SpecialEntitlementType $type = null, float $computed = 5.0, array $data = []): array
	{
		return [
			'type' => $type ?? $this->type(),
			'userId' => 'u1',
			'resolution' => [
				'computedAmount' => $computed,
				'enabled' => true,
				'periodStart' => new \DateTimeImmutable('2026-01-01'),
				'periodEnd' => new \DateTimeImmutable('2026-12-31'),
				'asOfDate' => new \DateTimeImmutable('2026-03-02'),
				'trace' => ['algorithm_version' => '1', 'as_of_date' => '2026-03-02'],
			],
			'locks' => [],
			'requestKey' => 'request-1234',
			'data' => $data,
		];
	}

	public function testFeatureRequiresCohortKernel(): void
	{
		self::assertTrue($this->service->isEnabled());
		$config = $this->suiteConfig(['special_entitlements_enabled' => true]);
		$service = new SpecialEntitlementService(
			$config,
			$this->types,
			$this->periods,
			$this->reservations,
			$this->ledger,
			$this->resolver,
			$this->plannedHours,
			$this->snapshots,
			$this->audit,
			$this->absences,
			$this->locks,
			$this->db,
		);
		self::assertFalse($service->isEnabled());
	}

	public function testBeginReservationReplaysCompletedRequest(): void
	{
		$type = $this->type();
		$absence = $this->absence();
		$reservation = new SpecialEntitlementReservation();
		$reservation->setAbsenceId(21);

		$this->types->method('findByAbsenceTypeCode')->willReturn($type);
		$this->reservations->method('findByRequestKey')->willReturn($reservation);
		$this->absences->method('find')->with(21)->willReturn($absence);

		$context = $this->service->beginReservation('u1', [
			'type' => 'custom_recovery',
			'start_date' => '2026-03-02',
			'end_date' => '2026-03-03',
			'request_key' => 'stable-request-1',
		]);
		self::assertSame($absence, $context['replayAbsence']);
	}

	public function testBeginReservationRejectsInactiveType(): void
	{
		$type = $this->type(active: false);
		$this->types->method('findByAbsenceTypeCode')->willReturn($type);
		$this->types->method('findByCode')->with('custom_recovery')->willReturn($type);
		$this->reservations->method('findByRequestKey')->willReturn(null);
		$this->locks->expects($this->exactly(2))->method('releaseLock');

		$this->expectException(SpecialEntitlementException::class);
		$this->expectExceptionMessage('not active');
		$this->service->beginReservation('u1', [
			'type' => 'custom_recovery',
			'start_date' => '2026-03-02',
			'end_date' => '2026-03-03',
			'request_key' => 'new-request-1',
		]);
	}

	public function testRequestAmountForDaysUsesServerWorkingDaysAndStrictFraction(): void
	{
		$context = $this->context(data: ['day_fraction' => '0.5']);
		$absence = $this->absence();
		$absence->setEndDate(new \DateTime('2026-03-02'));
		$absence->setDays(1.0);
		self::assertSame(0.5, $this->service->requestAmount($context, $absence));

		$this->expectException(SpecialEntitlementException::class);
		$this->service->requestAmount($this->context(data: ['day_fraction' => '0.6']), $absence);
	}

	public function testRequestAmountForHoursAcceptsExplicitWholeMinutesOnly(): void
	{
		$context = $this->context($this->type(SpecialEntitlementType::UNIT_HOURS), data: ['entitlement_minutes' => '90']);
		self::assertSame(1.5, $this->service->requestAmount($context, $this->absence()));

		$this->expectException(SpecialEntitlementException::class);
		$this->service->requestAmount($this->context($this->type(SpecialEntitlementType::UNIT_HOURS), data: ['entitlement_minutes' => '2.9']), $this->absence());
	}

	public function testReservationCannotExceedComputedLedgerMinusActiveReserved(): void
	{
		$period = $this->period();
		$this->periods->method('findForPeriod')->willReturn($period);
		$this->reservations->method('findByRequestKey')->willReturn(null);
		$this->reservations->method('activeAmountForPeriod')->willReturn(2.0);
		$this->ledger->method('balanceForPeriod')->willReturn(1.0);

		$this->expectException(SpecialEntitlementException::class);
		$this->expectExceptionMessage('exceeds');
		$this->service->completeReservation($this->context(computed: 4.0), $this->absence(), 3.01, 'u1');
	}

	public function testReservationAtExactAvailableSucceedsAndStoresAsOfSnapshot(): void
	{
		$type = $this->type();
		$context = $this->context($type, 4.0);
		$period = $this->period(computed: 4.0);
		$period->setComputationSnapshotId(null);
		$reservation = null;

		$this->periods->method('findForPeriod')->willReturn(null);
		$this->periods->expects($this->once())->method('insert')->willReturnCallback(function (SpecialEntitlementPeriod $p): SpecialEntitlementPeriod {
			$p->setId(31);
			return $p;
		});
		$this->snapshots->expects($this->once())->method('storeSpecial')->with(
			'u1',
			7,
			$this->anything(),
			$this->anything(),
			'days',
			$this->callback(static fn (\DateTimeInterface $d): bool => $d->format('Y-m-d') === '2026-03-02'),
			4.0,
			'1',
			$this->anything(),
			'u1',
		)->willReturnCallback(static function (): EntitlementComputationSnapshot {
			$snapshot = new EntitlementComputationSnapshot();
			$snapshot->setId(88);
			return $snapshot;
		});
		$this->periods->expects($this->once())->method('updateIfRevision')->willReturn(true);
		$this->reservations->method('findByRequestKey')->willReturn(null);
		$this->reservations->method('activeAmountForPeriod')->willReturn(2.0);
		$this->ledger->method('balanceForPeriod')->willReturn(0.0);
		$this->reservations->expects($this->once())->method('insert')->willReturnCallback(function (SpecialEntitlementReservation $r) use (&$reservation): SpecialEntitlementReservation {
			$r->setId(44);
			$reservation = $r;
			return $r;
		});

		$saved = $this->service->completeReservation($context, $this->absence(), 2.0, 'u1');
		self::assertSame($reservation, $saved);
		self::assertSame(44, $saved->getId());
	}

	public function testApprovalCreatesExactlyOneDebitAndConsumes(): void
	{
		$reservation = new SpecialEntitlementReservation();
		$reservation->setId(44);
		$reservation->setAbsenceId(21);
		$reservation->setPeriodId(31);
		$reservation->setAmount(2.0);
		$reservation->setStatus(SpecialEntitlementReservation::STATUS_ACTIVE);
		$reservation->setRevision(1);

		$this->reservations->method('findByAbsenceId')->willReturn($reservation);
		$this->ledger->expects($this->once())->method('insert')->willReturnCallback(static function (SpecialEntitlementLedgerEntry $entry): SpecialEntitlementLedgerEntry {
			$entry->setId(91);
			return $entry;
		});
		$this->reservations->expects($this->once())->method('transitionIfRevision')->with(44, 1, SpecialEntitlementReservation::STATUS_CONSUMED)->willReturn(true);
		$this->audit->expects($this->once())->method('logAction');

		$this->service->consumeForAbsence($this->absence(), 'manager');
	}

	public function testReleaseOfConsumedReservationPostsCompensatingEntryOnce(): void
	{
		$reservation = new SpecialEntitlementReservation();
		$reservation->setId(44);
		$reservation->setAbsenceId(21);
		$reservation->setPeriodId(31);
		$reservation->setAmount(2.0);
		$reservation->setStatus(SpecialEntitlementReservation::STATUS_CONSUMED);
		$reservation->setRevision(2);
		$debit = new SpecialEntitlementLedgerEntry();
		$debit->setId(91);
		$debit->setAmount(2.0);

		$this->reservations->method('findByAbsenceId')->willReturn($reservation);
		$this->ledger->method('findByEventKey')->willReturnCallback(static fn (string $key): ?SpecialEntitlementLedgerEntry => $key === 'absence:21:approve' ? $debit : null);
		$this->ledger->expects($this->once())->method('insert')->willReturnCallback(static function (SpecialEntitlementLedgerEntry $entry): SpecialEntitlementLedgerEntry {
			self::assertSame('absence:21:release', $entry->getEventKey());
			self::assertSame('correction', $entry->getKind());
			self::assertSame(2.0, $entry->getAmount());
			self::assertSame(91, $entry->getReversesLedgerId());
			return $entry;
		});
		$this->reservations->expects($this->once())->method('transitionIfRevision')->with(44, 2, SpecialEntitlementReservation::STATUS_RELEASED)->willReturn(true);

		$this->service->releaseForAbsence($this->absence(), 'manager');
	}

	public function testAccountsExposePublicLabelAndSeparateBalancesOnly(): void
	{
		$type = $this->type();
		$type->setConfidentialLabel('Sensitive medical label');
		$period = $this->period();
		$period->setStatus(SpecialEntitlementPeriod::STATUS_CLOSED);
		$this->types->method('findAll')->willReturn([$type]);
		$this->periods->method('findForPeriod')->willReturn($period);
		$this->reservations->method('activeAmountForPeriod')->willReturn(1.0);
		$this->ledger->method('balanceForPeriod')->willReturn(-2.0);
		$this->ledger->method('usedAmountForPeriod')->willReturn(2.0);

		$items = $this->service->listAccounts('u1', new \DateTimeImmutable('2026-03-02'));
		self::assertCount(1, $items);
		self::assertSame('Recovery', $items[0]['label']);
		self::assertSame('5.00', $items[0]['total']);
		self::assertSame('1.00', $items[0]['reserved']);
		self::assertSame('2.00', $items[0]['used']);
		self::assertSame('2.00', $items[0]['available']);
		self::assertArrayNotHasKey('confidentialLabel', $items[0]);
	}
}
