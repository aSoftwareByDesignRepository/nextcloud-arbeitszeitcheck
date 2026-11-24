<?php

declare(strict_types=1);

/**
 * NN-06: closed-month premium snapshot is frozen in the canonical payload.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\MonthClosureMapper;
use OCA\ArbeitszeitCheck\Db\MonthClosureRevisionMapper;
use OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\PremiumSurchargeService;
use OCA\ArbeitszeitCheck\Service\ReportingService;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MonthClosurePremiumSnapshotTest extends TestCase
{
	private function makeService(PremiumSurchargeService $premium): MonthClosureService
	{
		$reporting = $this->createMock(ReportingService::class);
		$reporting->method('generateMonthlyReport')->willReturn(['type' => 'monthly', 'total_hours' => 0]);

		$entries = $this->createMock(TimeEntryMapper::class);
		$entries->method('findByUserAndDateRange')->willReturn([]);
		$absences = $this->createMock(AbsenceMapper::class);
		$absences->method('findByUserAndDateRange')->willReturn([]);

		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('buildClosureAuditBlock')->willReturn(null);
		$payouts = $this->createMock(OvertimePayoutMapper::class);
		$payouts->method('findByUserAndMonth')->willReturn(null);

		$config = $this->createMock(IConfig::class);
		// WorkforceSuiteConfig defaults must shine through — notably
		// working_time_contract_evidence_enabled (always-on, NN-12).
		$config->method('getAppValue')->willReturnArgument(2);

		return new MonthClosureService(
			$this->createMock(MonthClosureMapper::class),
			$this->createMock(MonthClosureRevisionMapper::class),
			$reporting,
			$entries,
			$absences,
			$this->createMock(AuditLogMapper::class),
			$this->createMock(IDBConnection::class),
			$config,
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(PermissionService::class),
			$bank,
			$payouts,
			null,
			null,
			$premium,
			$this->contractContributorRegistry(),
			new \OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig($config),
		);
	}

	/**
	 * Registry containing the mandatory working-time-contract contributor
	 * (NN-12): a canonical payload without it must refuse to seal.
	 */
	private function contractContributorRegistry(): \OCA\ArbeitszeitCheck\Policy\MonthClosureContributorRegistry
	{
		$assignments = $this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class);
		$models = $this->createMock(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class);
		$registry = new \OCA\ArbeitszeitCheck\Policy\MonthClosureContributorRegistry();
		$registry->register(new \OCA\ArbeitszeitCheck\Service\WorkingTimeContractClosureContributor(
			new \OCA\ArbeitszeitCheck\Service\WorkingTimeModelAssignmentResolver($assignments, $models),
			$assignments,
			$models,
		));
		return $registry;
	}

	public function testBuildCanonicalPayloadEmbedsPremiumWhenEnabled(): void
	{
		$premium = $this->createMock(PremiumSurchargeService::class);
		$premium->expects($this->once())
			->method('buildClosureAuditBlock')
			->with('u1', 2026, 8)
			->willReturn([
				'enabled' => true,
				'policy_version' => 4,
				'summary' => ['buckets' => [['id' => 'sunday', 'hours' => 2.0]]],
				'orthogonal_to_saldo' => true,
			]);

		$payload = $this->makeService($premium)->buildCanonicalPayload('u1', 2026, 8);
		$this->assertArrayHasKey('premium', $payload);
		$this->assertSame(4, $payload['premium']['policy_version']);
		$this->assertTrue($payload['premium']['orthogonal_to_saldo']);
		$this->assertSame(2.0, $payload['premium']['summary']['buckets'][0]['hours']);
	}

	public function testBuildCanonicalPayloadOmitsPremiumWhenServiceReturnsNull(): void
	{
		$premium = $this->createMock(PremiumSurchargeService::class);
		$premium->method('buildClosureAuditBlock')->willReturn(null);

		$payload = $this->makeService($premium)->buildCanonicalPayload('u1', 2026, 8);
		$this->assertArrayNotHasKey('premium', $payload);
	}

	public function testGetFinalizedMonthlyReportAttachesFrozenPremium(): void
	{
		$closure = new \OCA\ArbeitszeitCheck\Db\MonthClosure();
		$closure->setStatus(\OCA\ArbeitszeitCheck\Db\MonthClosure::STATUS_FINALIZED);
		$closure->setCanonicalPayload(json_encode([
			'report' => ['type' => 'monthly', 'total_hours' => 160],
			'premium' => [
				'enabled' => true,
				'policy_version' => 7,
				'summary' => ['buckets' => [['id' => 'night', 'hours' => 3.5, 'label' => 'Night']]],
			],
		], JSON_THROW_ON_ERROR));
		$closure->setSnapshotHash('abc123');
		$closure->setVersion(2);

		$mapper = $this->createMock(MonthClosureMapper::class);
		$mapper->method('findByUserAndMonthOptional')->with('u1', 2026, 8)->willReturn($closure);

		$reporting = $this->createMock(ReportingService::class);
		$svc = new MonthClosureService(
			$mapper,
			$this->createMock(MonthClosureRevisionMapper::class),
			$reporting,
			$this->createMock(TimeEntryMapper::class),
			$this->createMock(AbsenceMapper::class),
			$this->createMock(AuditLogMapper::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(IConfig::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(PermissionService::class),
			$this->createMock(OvertimeBankService::class),
			$this->createMock(OvertimePayoutMapper::class),
			null,
			null,
			$this->createMock(PremiumSurchargeService::class),
		);

		$report = $svc->getFinalizedMonthlyReportForUser('u1', 2026, 8);
		$this->assertNotNull($report);
		$this->assertTrue($report['from_month_closure_snapshot']);
		$this->assertSame('abc123', $report['snapshot_hash']);
		$this->assertSame(2, $report['month_closure_version']);
		$this->assertTrue($report['premium']['enabled']);
		$this->assertSame(7, $report['premium']['policy_version']);
		$this->assertSame(3.5, $report['premium']['summary']['buckets'][0]['hours']);
	}
}
