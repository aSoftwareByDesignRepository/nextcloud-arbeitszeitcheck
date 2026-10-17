<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\HolidaySuppressionMapper;
use OCA\ArbeitszeitCheck\Service\HolidayAdminService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCP\IConfig;
use Test\TestCase;

/**
 * End-to-end holiday policy: delete, suppress, admin list, calendar API, working days.
 */
class HolidayPolicyIntegrationTest extends TestCase
{
	private const TEST_STATE = 'NW';
	private const TEST_YEAR = 2099;

	private function setAppConfigValue(string $key, string $value): void
	{
		$config = \OCP\Server::get(IConfig::class);
		$config->deleteAppValue('arbeitszeitcheck', $key);
		$config->setAppValue('arbeitszeitcheck', $key, $value);
	}

	protected function tearDown(): void
	{
		$this->setAppConfigValue('statutory_auto_reseed', '1');

		$mapper = \OCP\Server::get(HolidayMapper::class);
		$suppressionMapper = \OCP\Server::get(HolidaySuppressionMapper::class);
		foreach ($mapper->findByStateAndYear(self::TEST_STATE, self::TEST_YEAR) as $h) {
			if ($h->getId() !== null) {
				$mapper->deleteById((int)$h->getId());
			}
		}
		// Best-effort cleanup of suppressions for test year
		foreach ($suppressionMapper->findSuppressedDatesForStateAndYear(self::TEST_STATE, self::TEST_YEAR) as $dateYmd) {
			$suppressionMapper->removeSuppression(self::TEST_STATE, $dateYmd);
		}

		parent::tearDown();
	}

	public function testDeleteStatutoryAlignsAdminCalendarAndWorkingDays(): void
	{
		$this->setAppConfigValue('statutory_auto_reseed', '0');

		/** @var HolidayService $holidayService */
		$holidayService = \OCP\Server::get(HolidayService::class);
		/** @var HolidayAdminService $adminService */
		$adminService = \OCP\Server::get(HolidayAdminService::class);
		/** @var HolidayMapper $holidayMapper */
		$holidayMapper = \OCP\Server::get(HolidayMapper::class);

		$holidayService->clearCacheForStateYear(self::TEST_STATE, self::TEST_YEAR);

		$start = new \DateTime(self::TEST_YEAR . '-01-01');
		$end = new \DateTime(self::TEST_YEAR . '-12-31');
		$before = $holidayService->getHolidaysForRange(self::TEST_STATE, $start, $end);
		$this->assertNotEmpty($before);

		$target = null;
		foreach ($before as $row) {
			if (($row['scope'] ?? '') !== Holiday::SCOPE_STATUTORY || empty($row['date'])) {
				continue;
			}
			$d = new \DateTime((string)$row['date']);
			if ((int)$d->format('N') < 6) {
				$target = $row;
				break;
			}
		}
		$this->assertNotNull($target, 'Need at least one weekday statutory holiday for test year');
		$targetId = (int)($target['id'] ?? 0);
		$targetDate = (string)$target['date'];

		$deleteResult = $adminService->deleteStateHolidayById($targetId, 'integration-test');
		$this->assertTrue($deleteResult['success'] ?? false);

		$after = $holidayService->getHolidaysForRange(self::TEST_STATE, $start, $end);
		$datesAfter = array_column($after, 'date');
		$this->assertNotContains($targetDate, $datesAfter, 'Admin/calendar list must not show deleted statutory day');

		$probe = new \DateTime($targetDate);
		$this->assertFalse($holidayService->isHolidayForState(self::TEST_STATE, $probe));

		$verify = $adminService->verifyStateYear(self::TEST_STATE, self::TEST_YEAR);
		$this->assertContains($targetDate, $verify['suppressedDates'] ?? []);
	}

	/**
	 * Toggling auto-restore OFF→ON must bring previously suppressed statutory
	 * days back and clear the stale suppression (no phantom opt-outs remain).
	 */
	public function testEnablingAutoRestoreRevivesSuppressedStatutoryDay(): void
	{
		$this->setAppConfigValue('statutory_auto_reseed', '0');

		/** @var HolidayService $holidayService */
		$holidayService = \OCP\Server::get(HolidayService::class);
		/** @var HolidayAdminService $adminService */
		$adminService = \OCP\Server::get(HolidayAdminService::class);

		$holidayService->clearCacheForStateYear(self::TEST_STATE, self::TEST_YEAR);

		$start = new \DateTime(self::TEST_YEAR . '-01-01');
		$end = new \DateTime(self::TEST_YEAR . '-12-31');
		$before = $holidayService->getHolidaysForRange(self::TEST_STATE, $start, $end);

		$target = null;
		foreach ($before as $row) {
			if (($row['scope'] ?? '') === Holiday::SCOPE_STATUTORY && !empty($row['date'])) {
				$target = $row;
				break;
			}
		}
		$this->assertNotNull($target, 'Need a statutory holiday to suppress.');
		$targetId = (int)($target['id'] ?? 0);
		$targetDate = (string)$target['date'];

		// Delete while auto-restore is OFF → date becomes suppressed.
		$adminService->deleteStateHolidayById($targetId, 'integration-test');
		$this->assertTrue($holidayService->isHolidayForState(self::TEST_STATE, new \DateTime($targetDate)) === false);
		$verifyOff = $adminService->verifyStateYear(self::TEST_STATE, self::TEST_YEAR);
		$this->assertContains($targetDate, $verifyOff['suppressedDates'] ?? []);

		// Flip auto-restore ON and reload.
		$this->setAppConfigValue('statutory_auto_reseed', '1');
		$holidayService->clearCacheForStateYear(self::TEST_STATE, self::TEST_YEAR);

		$this->assertTrue(
			$holidayService->isHolidayForState(self::TEST_STATE, new \DateTime($targetDate)),
			'Statutory day must be restored once auto-restore is enabled.'
		);

		$verifyOn = $adminService->verifyStateYear(self::TEST_STATE, self::TEST_YEAR);
		$this->assertNotContains(
			$targetDate,
			$verifyOn['suppressedDates'] ?? [],
			'Suppression must be cleared after the date is restored.'
		);
		$this->assertTrue($verifyOn['ok'] ?? false, 'No catalog/DB gaps expected with auto-restore on.');
	}
}
