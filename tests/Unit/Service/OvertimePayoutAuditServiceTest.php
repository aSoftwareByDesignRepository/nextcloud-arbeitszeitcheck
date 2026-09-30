<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\OvertimePayout;
use OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\OvertimePayoutAuditService;
use OCA\ArbeitszeitCheck\Service\OvertimePayoutService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class OvertimePayoutAuditServiceTest extends TestCase
{
	private function payoutEntity(): OvertimePayout
	{
		$e = new OvertimePayout();
		$e->setId(7);
		$e->setUserId('alice');
		$e->setCalendarYear(2026);
		$e->setCalendarMonth(6);
		$e->setHoursPaid(3.5);
		return $e;
	}

	private function service(array $deps = []): OvertimePayoutAuditService
	{
		$payoutService = $deps['payoutService'] ?? $this->createMock(OvertimePayoutService::class);
		if (!isset($deps['payoutService'])) {
			$payoutService->method('entityToArray')->willReturnCallback(
				static fn (OvertimePayout $e) => ['user_id' => $e->getUserId(), 'hours_paid' => $e->getHoursPaid()]
			);
		}
		$urlGen = $this->createMock(IURLGenerator::class);
		$urlGen->method('linkToRoute')->willReturn('/audit');
		return new OvertimePayoutAuditService(
			$deps['payoutMapper'] ?? $this->createMock(OvertimePayoutMapper::class),
			$payoutService,
			$deps['bankService'] ?? $this->createMock(OvertimeBankService::class),
			$deps['closureService'] ?? $this->createMock(MonthClosureService::class),
			$this->createMock(AuditLogMapper::class),
			$deps['userManager'] ?? $this->createMock(IUserManager::class),
			$urlGen,
		);
	}

	public function testListAuditEntriesEnrichesRows(): void
	{
		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('countFiltered')->willReturn(1);
		$payoutMapper->method('findFiltered')->willReturn([$this->payoutEntity()]);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice A.');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$closure = $this->createMock(MonthClosureService::class);
		$closure->method('isMonthFinalized')->willReturn(true);

		$result = $this->service([
			'payoutMapper' => $payoutMapper,
			'userManager' => $userManager,
			'closureService' => $closure,
		])->listAuditEntries(2026, 6, null);

		$this->assertSame(1, $result['total']);
		$this->assertCount(1, $result['items']);
		$row = $result['items'][0];
		$this->assertSame('Alice A.', $row['display_name']);
		$this->assertSame('2026-06', $row['period']);
		$this->assertTrue($row['month_finalized']);
		$this->assertStringContainsString('overtime_payout_processed', $row['audit_log_url']);
		$this->assertSame(3.5, $result['summary']['total_hours']);
	}

	public function testListAuditEntriesFallsBackToUserIdWhenUserGone(): void
	{
		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('countFiltered')->willReturn(1);
		$payoutMapper->method('findFiltered')->willReturn([$this->payoutEntity()]);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn(null);

		$row = $this->service([
			'payoutMapper' => $payoutMapper,
			'userManager' => $userManager,
		])->listAuditEntries(null, null, 'alice')['items'][0];
		$this->assertSame('alice', $row['display_name']);
	}

	public function testFindComplianceGapsDisabledBankReturnsEmpty(): void
	{
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(false);
		$this->assertSame([], $this->service(['bankService' => $bank])->findComplianceGaps(2026, null));
	}

	public function testFindComplianceGapsFlagsFinalizedPendingMonth(): void
	{
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(true);
		$payoutService = $this->createMock(OvertimePayoutService::class);
		$payoutService->method('listMonthOverview')->willReturn([
			'items' => [
				['user_id' => 'alice', 'status' => 'pending'],
				['user_id' => 'bob', 'status' => 'paid'],
			],
		]);
		$closure = $this->createMock(MonthClosureService::class);
		$closure->method('isMonthFinalized')
			->willReturnCallback(static fn (string $uid) => $uid === 'alice');

		$gaps = $this->service([
			'bankService' => $bank,
			'payoutService' => $payoutService,
			'closureService' => $closure,
		])->findComplianceGaps(2026, 6);

		$this->assertCount(1, $gaps);
		$this->assertSame('alice', $gaps[0]['user_id']);
		$this->assertSame('finalized_without_payout', $gaps[0]['gap_type']);
		$this->assertSame(2026, $gaps[0]['calendar_year']);
		$this->assertSame(6, $gaps[0]['calendar_month']);
	}
}
