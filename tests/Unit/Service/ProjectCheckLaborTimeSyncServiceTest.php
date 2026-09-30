<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Billing sync must use instance-level ProjectCheck install, not the current
 * user (cron / group-restricted admins have no session user).
 */
final class ProjectCheckLaborTimeSyncServiceTest extends TestCase
{
	private function timeZoneService(): TimeZoneService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default = '') => $default
		);
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		return new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());
	}

	public function testSyncNoopsWhenProjectCheckIsNotInstalledEvenIfCurrentUserWouldHaveIt(): void
	{
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())
			->method('isInstalled')
			->with(Constants::APP_ID_PROJECTCHECK)
			->willReturn(false);
		$appManager->expects($this->never())->method('isEnabledForUser');

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->expects($this->never())->method('update');

		$service = new ProjectCheckLaborTimeSyncService(
			$appManager,
			$timeEntryMapper,
			$this->timeZoneService(),
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
			new \stdClass(),
		);

		$entry = $this->createMock(TimeEntry::class);
		$result = $service->syncFromTimeEntry($entry, 'admin');
		$this->assertTrue($result['success']);
		$this->assertNull($result['projectCheckTimeEntryId']);
	}

	public function testDeleteNoopsWhenProjectCheckIsNotInstalled(): void
	{
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())
			->method('isInstalled')
			->with(Constants::APP_ID_PROJECTCHECK)
			->willReturn(false);
		$appManager->expects($this->never())->method('isEnabledForUser');

		$service = new ProjectCheckLaborTimeSyncService(
			$appManager,
			$this->createMock(TimeEntryMapper::class),
			$this->timeZoneService(),
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
			new \stdClass(),
		);

		$service->onTimeEntryDeleted([
			'projectCheckTimeEntryId' => 9,
			'userId' => 'bob',
		], 'admin');
	}

	public function testSyncUsesInstalledCheckNotEnabledForUserWhenAppIsPresent(): void
	{
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())
			->method('isInstalled')
			->with(Constants::APP_ID_PROJECTCHECK)
			->willReturn(true);
		$appManager->expects($this->never())->method('isEnabledForUser');

		$service = new ProjectCheckLaborTimeSyncService(
			$appManager,
			$this->createMock(TimeEntryMapper::class),
			$this->timeZoneService(),
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
			null,
		);

		$entry = $this->createMock(TimeEntry::class);
		$result = $service->syncFromTimeEntry($entry, 'cron');
		$this->assertTrue($result['success']);
	}

	private function completedBillingEntry(int $id = 9, ?int $pcId = null, string $pid = '6'): TimeEntry
	{
		// real entity: getId/getWorkingDurationHours are derived from real state
		$e = new TimeEntry();
		$e->setId($id);
		$e->setUserId('alice');
		$e->setStatus(TimeEntry::STATUS_COMPLETED);
		$e->setStartTime(new \DateTime('2026-03-05 09:00'));
		$e->setEndTime(new \DateTime('2026-03-05 17:00'));   // 8h span
		$e->setBreaks(json_encode([[
			'start' => '2026-03-05T12:00:00+00:00',
			'end' => '2026-03-05T12:30:00+00:00',
			'duration_minutes' => 30,
		]]));                                                  // -> 7.5h working
		$e->setProjectCheckProjectId($pid);
		$e->setProjectCheckTimeEntryId($pcId);
		$e->setDescription('work');
		return $e;
	}

	public function testSyncUpsertsAndStoresReturnedPcId(): void
	{
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$svc = $this->getMockBuilder(\stdClass::class)
			->addMethods(['upsertFromArbeitszeitCheckBilling', 'deleteFromArbeitszeitCheckBilling'])
			->getMock();
		$svc->expects($this->once())->method('upsertFromArbeitszeitCheckBilling')
			->with('cron', 'alice', null, 6, $this->isInstanceOf(\DateTimeImmutable::class), 7.5, 'work')
			->willReturn(88);

		$entry = $this->completedBillingEntry();
		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->expects($this->once())->method('update')->with($entry);

		$service = new ProjectCheckLaborTimeSyncService(
			$appManager, $mapper, $this->timeZoneService(),
			$this->createMock(IConfig::class), $this->createMock(LoggerInterface::class), $svc,
		);

		$r = $service->syncFromTimeEntry($entry, 'cron');
		$this->assertTrue($r['success']);
		$this->assertSame(88, $r['projectCheckTimeEntryId']);
		$this->assertSame(88, $entry->getProjectCheckTimeEntryId());
	}

	public function testSyncDeletesLinkedRowWhenEntryNoLongerBillable(): void
	{
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$svc = $this->getMockBuilder(\stdClass::class)
			->addMethods(['upsertFromArbeitszeitCheckBilling', 'deleteFromArbeitszeitCheckBilling'])
			->getMock();
		$svc->expects($this->once())->method('deleteFromArbeitszeitCheckBilling')
			->with('admin', 'alice', 55);

		// entry without project link but with existing pc id -> unlink path
		$entry = $this->completedBillingEntry(9, 55, '');

		// deleteLinkedRow re-fetches and updates the entry
		$fresh = $this->completedBillingEntry(9, 55, '');
		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->method('find')->with(9)->willReturn($fresh);
		$mapper->expects($this->once())->method('update')->with($fresh);

		$service = new ProjectCheckLaborTimeSyncService(
			$appManager, $mapper, $this->timeZoneService(),
			$this->createMock(IConfig::class), $this->createMock(LoggerInterface::class), $svc,
		);

		$r = $service->syncFromTimeEntry($entry, 'admin');
		$this->assertTrue($r['success']);
		$this->assertNull($r['projectCheckTimeEntryId']);
		$this->assertNull($fresh->getProjectCheckTimeEntryId());
	}

	public function testOnTimeEntryDeletedForwardsLinkedPcId(): void
	{
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$svc = $this->getMockBuilder(\stdClass::class)
			->addMethods(['deleteFromArbeitszeitCheckBilling'])
			->getMock();
		$svc->expects($this->once())->method('deleteFromArbeitszeitCheckBilling')
			->with('admin', 'alice', 77);

		$service = new ProjectCheckLaborTimeSyncService(
			$appManager, $this->createMock(TimeEntryMapper::class), $this->timeZoneService(),
			$this->createMock(IConfig::class), $this->createMock(LoggerInterface::class), $svc,
		);

		$service->onTimeEntryDeleted(
			['projectCheckTimeEntryId' => 77, 'userId' => 'alice'],
			'admin'
		);
	}

	public function testOnTimeEntryDeletedSkipsWhenNoLink(): void
	{
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$svc = $this->getMockBuilder(\stdClass::class)
			->addMethods(['deleteFromArbeitszeitCheckBilling'])
			->getMock();
		$svc->expects($this->never())->method('deleteFromArbeitszeitCheckBilling');

		$service = new ProjectCheckLaborTimeSyncService(
			$appManager, $this->createMock(TimeEntryMapper::class), $this->timeZoneService(),
			$this->createMock(IConfig::class), $this->createMock(LoggerInterface::class), $svc,
		);
		// no pc id / missing user -> early return
		$service->onTimeEntryDeleted(['userId' => 'alice'], 'admin');
		$service->onTimeEntryDeleted(['projectCheckTimeEntryId' => 5], 'admin');
	}
}