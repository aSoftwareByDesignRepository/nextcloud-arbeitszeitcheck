<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\VacationRolloverLogMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationRolloverService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCA\ArbeitszeitCheck\Support\VacationYearWindow;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the rollover engine: eligible-year selection (calendar + anniversary),
 * amount computation, per-user processing guards and the sweep stats.
 */
final class VacationRolloverServiceTest extends TestCase
{
	private IConfig&MockObject $config;
	private VacationAllocationService&MockObject $allocation;
	private VacationYearBalanceMapper&MockObject $balanceMapper;
	private VacationRolloverLogMapper&MockObject $logMapper;
	private IUserManager&MockObject $userManager;
	private AuditLogMapper&MockObject $auditLogMapper;
	private PermissionService&MockObject $permissionService;
	private VacationUnitService $unitService;
	private VacationRolloverService $service;

	/** @var array<string,string> */
	private array $appValues = [];
	private bool $anniversaryMode = false;
	private bool $logExists = false;
	private float $carryoverRemaining = 4.0;
	private float $annualRemaining = 0.0;
	private float $existingCarryover = 0.0;
	private float $capResult = 0.0;
	private bool $allowed = true;
	private ?\DateTimeImmutable $expiryOverride = null;
	private ?VacationYearWindow $windowOverride = null;

	protected function setUp(): void
	{
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->allocation = $this->createMock(VacationAllocationService::class);
		$this->balanceMapper = $this->createMock(VacationYearBalanceMapper::class);
		$this->logMapper = $this->createMock(VacationRolloverLogMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->unitService = new VacationUnitService($this->config);

		$this->appValues = [
			Constants::CONFIG_VACATION_ROLLOVER_ENABLED => '1',
			Constants::CONFIG_VACATION_ROLLOVER_INCLUDE_UNUSED_ANNUAL => '0',
		];
		$this->config->method('getAppValue')->willReturnCallback(
			fn ($app, $key, $default = '') => $this->appValues[$key] ?? $default
		);

		$this->allocation->method('isAnniversaryMode')->willReturnCallback(fn () => $this->anniversaryMode);
		// calendar mode: every year's carryover expiry is May 31 of that year
		$this->allocation->method('getCarryoverExpiryDateForYear')->willReturnCallback(
			fn (int $y) => $this->expiryOverride ?? new \DateTimeImmutable("$y-05-31")
		);
		$this->allocation->method('getCarryoverExpiryDateForWindow')->willReturnCallback(
			static fn ($w) => new \DateTimeImmutable('2025-05-31')
		);
		$this->allocation->method('resolveWindowForUserYear')->willReturnCallback(
			fn () => $this->windowOverride ?? new VacationYearWindow(
				mode: VacationYearWindow::MODE_ANNIVERSARY,
				balanceYearKey: 2025,
				startInclusive: new \DateTimeImmutable('2025-01-01'),
				endExclusive: new \DateTimeImmutable('2026-01-01'),
				label: 'test',
			)
		);
		$this->allocation->method('computeYearAllocation')->willReturnCallback(
			fn () => [
				'carryover_remaining_after_approved' => $this->carryoverRemaining,
				'annual_remaining_after_approved' => $this->annualRemaining,
			]
		);
		$this->allocation->method('applyCapToOpeningBalance')->willReturnCallback(
			fn (float $v) => $this->capResult > 0 ? min($v, $this->capResult) : $v
		);

		$this->logMapper->method('existsForUserAndYears')->willReturnCallback(fn () => $this->logExists);
		$this->balanceMapper->method('getCarryoverDays')->willReturnCallback(fn () => $this->existingCarryover);
		$this->balanceMapper->method('getCarryoverAmount')->willReturnCallback(fn () => $this->existingCarryover);
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturnCallback(fn () => $this->allowed);
		$this->userManager->method('get')->willReturnCallback(function ($uid) {
			$u = $this->createMock(IUser::class);
			$u->method('isEnabled')->willReturn(true);
			$u->method('getUID')->willReturn($uid);
			return $u;
		});

		$this->service = new VacationRolloverService(
			$this->config,
			$this->allocation,
			$this->balanceMapper,
			$this->logMapper,
			$this->userManager,
			$this->auditLogMapper,
			$this->permissionService,
			$this->unitService,
		);
	}

	// ---------------------------------------------------------------
	// eligible-year selection
	// ---------------------------------------------------------------

	public function testGetEligibleFromYearsCalendarMode(): void
	{
		// expiry = May 31 of each year; today = 2026-09-01 -> every scanned
		// year including the current one has a past deadline.
		$years = $this->service->getEligibleFromYears(new \DateTime('2026-09-01'));
		$this->assertContains(2026, $years);
		$this->assertContains(2025, $years);
		$this->assertNotContains(2005, $years); // beyond the 20-year lookback

		// early in the year only the previous year is past its deadline
		$years2 = $this->service->getEligibleFromYears(new \DateTime('2026-02-01'));
		$this->assertContains(2025, $years2);
		$this->assertNotContains(2026, $years2);
	}

	public function testGetEligibleFromYearsForUserAnniversarySkipsMissingStart(): void
	{
		$this->anniversaryMode = true;
		$this->windowOverride = new VacationYearWindow(
			mode: VacationYearWindow::MODE_ANNIVERSARY,
			balanceYearKey: 2025,
			startInclusive: new \DateTimeImmutable('2025-01-01'),
			endExclusive: new \DateTimeImmutable('2026-01-01'),
			label: 'test',
			missingEmploymentStart: true,
		);
		$years = $this->service->getEligibleFromYearsForUser('alice', new \DateTime('2026-09-01'));
		$this->assertSame([], $years);
	}

	public function testGetAllocationAsOfAfterDeadlineIsDayAfterExpiry(): void
	{
		$asOf = $this->service->getAllocationAsOfAfterDeadline(2024);
		$this->assertSame('2024-06-01', $asOf->format('Y-m-d'));
	}

	// ---------------------------------------------------------------
	// computeRolloverAmountParts
	// ---------------------------------------------------------------

	public function testComputeRolloverAmountPartsCarryoverOnly(): void
	{
		$parts = $this->service->computeRolloverAmountParts('alice', 2024);
		$this->assertSame(4.0, $parts['carryover_part']);
		$this->assertSame(0.0, $parts['annual_part']);
		$this->assertSame(4.0, $parts['total']);
	}

	public function testComputeRolloverAmountPartsIncludesAnnualWhenEnabled(): void
	{
		$this->appValues[Constants::CONFIG_VACATION_ROLLOVER_INCLUDE_UNUSED_ANNUAL] = '1';
		$this->annualRemaining = 6.5;
		$parts = $this->service->computeRolloverAmountParts('alice', 2024);
		$this->assertSame(10.5, $parts['total']);
	}

	public function testComputeRolloverAmountPartsAppliesCap(): void
	{
		$this->capResult = 2.5;
		$parts = $this->service->computeRolloverAmountParts('alice', 2024);
		$this->assertSame(2.5, $parts['total']);
	}

	// ---------------------------------------------------------------
	// processUserForFromYear
	// ---------------------------------------------------------------

	public function testProcessUserForFromYearSkippedWhenDisabled(): void
	{
		$this->appValues[Constants::CONFIG_VACATION_ROLLOVER_ENABLED] = '0';
		$this->assertSame('skipped_disabled', $this->service->processUserForFromYear('alice', 2024, false, false, false)['action']);
	}

	public function testProcessUserForFromYearSkippedWhenAlreadyLogged(): void
	{
		$this->logExists = true;
		$this->assertSame('skipped_already_logged', $this->service->processUserForFromYear('alice', 2024, false, false, false)['action']);
	}

	public function testProcessUserForFromYearSkippedWhenTargetHasBalance(): void
	{
		$this->existingCarryover = 3.0;
		$this->assertSame('skipped_target_balance', $this->service->processUserForFromYear('alice', 2024, false, false, false)['action']);
	}

	public function testProcessUserForFromYearSkippedWhenZero(): void
	{
		$this->carryoverRemaining = 0.0;
		$this->assertSame('skipped_zero', $this->service->processUserForFromYear('alice', 2024, false, false, false)['action']);
	}

	public function testProcessUserForFromYearDryRunReportsWouldApply(): void
	{
		$out = $this->service->processUserForFromYear('alice', 2024, true, false, false);
		$this->assertSame('would_apply', $out['action']);
		$this->assertSame(4.0, $out['amount']);
		$this->assertSame(2025, $out['to_year']);
	}

	public function testProcessUserForFromYearAppliesWritePath(): void
	{
		$this->balanceMapper->expects($this->once())->method('upsert')
			->with('alice', 2025, 4.0, null, true);
		$this->logMapper->expects($this->once())->method('insertLog')
			->with('alice', 2024, 2025, 4.0);
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'vacation_rollover', 'vacation_year_balance');

		$out = $this->service->processUserForFromYear('alice', 2024, false, false, false);
		$this->assertSame('applied', $out['action']);
		$this->assertSame(4.0, $out['amount']);
	}

	public function testProcessUserForFromYearForceDeletesLogAndRewrites(): void
	{
		$this->logExists = true;
		$this->logMapper->expects($this->once())->method('deleteByUserAndYears')->with('alice', 2024, 2025);
		$out = $this->service->processUserForFromYear('alice', 2024, false, true, false);
		$this->assertSame('applied', $out['action']);
	}

	// ---------------------------------------------------------------
	// sweeps
	// ---------------------------------------------------------------

	public function testRunForAllUsersReturnsZerosWhenDisabledAndNotForced(): void
	{
		$this->appValues[Constants::CONFIG_VACATION_ROLLOVER_ENABLED] = '0';
		$this->userManager->expects($this->never())->method('callForAllUsers');
		$this->assertSame(
			['applied' => 0, 'skipped' => 0, 'errors' => 0],
			$this->service->runForAllUsers(null, false, false, false)
		);
	}

	public function testRunForAllUsersAppliesAndCountsErrors(): void
	{
		$good = $this->createMock(IUser::class);
		$good->method('isEnabled')->willReturn(true);
		$good->method('getUID')->willReturn('alice');
		$bad = $this->createMock(IUser::class);
		$bad->method('isEnabled')->willReturn(true);
		$bad->method('getUID')->willReturn('broken');
		$this->userManager->method('callForAllUsers')->willReturnCallback(
			static function (callable $cb) use ($good, $bad): void {
				$cb($good);
				$cb($bad);
			}
		);
		// 'broken' throws inside computeYearAllocation -> errors bucket
		$this->allocation->method('computeYearAllocation')->willReturnCallback(
			function ($uid) {
				if ($uid === 'broken') {
					throw new \RuntimeException('allocation exploded');
				}
				return ['carryover_remaining_after_approved' => $this->carryoverRemaining, 'annual_remaining_after_approved' => 0];
			}
		);

		$stats = $this->service->runForAllUsers(2024, false, false, false);
		$this->assertSame(1, $stats['applied']);
		$this->assertSame(1, $stats['errors']);
	}

	public function testRunForAllUsersSkipsDisallowedAndDisabled(): void
	{
		$disallowed = $this->createMock(IUser::class);
		$disallowed->method('isEnabled')->willReturn(true);
		$disallowed->method('getUID')->willReturn('mallory');
		$disabled = $this->createMock(IUser::class);
		$disabled->method('isEnabled')->willReturn(false);
		$this->userManager->method('callForAllUsers')->willReturnCallback(
			static function (callable $cb) use ($disallowed, $disabled): void {
				$cb($disallowed);
				$cb($disabled);
			}
		);
		$this->allowed = false;

		$stats = $this->service->runForAllUsers(2024, false, false, false);
		$this->assertSame(0, $stats['applied']);
		$this->assertSame(0, $stats['errors']);
	}

	public function testRunForSingleUserRejectsUnknownUser(): void
	{
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn(null);
		$service = new VacationRolloverService(
			$this->config, $this->allocation, $this->balanceMapper, $this->logMapper,
			$userManager, $this->auditLogMapper, $this->permissionService, $this->unitService
		);
		$this->assertSame(
			['applied' => 0, 'skipped' => 0, 'errors' => 0],
			$service->runForSingleUser('ghost', 2024, false, false, false)
		);
	}

	public function testRunForSingleUserSkipsWhenYearNotPastDeadline(): void
	{
		// onlyFromYear=2024, expiry 2024-05-31, today is real now (past) ->
		// eligible; to exercise the "not past" arm use a year whose expiry is
		// in the future relative to today.
		$this->expiryOverride = (new \DateTimeImmutable('today'))->modify('+30 days');
		$stats = $this->service->runForSingleUser('alice', 2024, false, false, false);
		$this->assertSame(0, $stats['applied']);
		$this->assertSame(0, $stats['errors']);
	}

	public function testRunForSingleUserAutoDiscoversEligibleYears(): void
	{
		$this->carryoverRemaining = 2.0;
		$stats = $this->service->runForSingleUser('alice', null, true, false, false);
		// many eligible past years -> dry-run applies to each
		$this->assertGreaterThan(10, $stats['applied']);
	}
}
