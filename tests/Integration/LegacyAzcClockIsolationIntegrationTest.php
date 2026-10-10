<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/**
 * AC-L2 / LEGACY-AZC — clock + absence with suite hubs disabled.
 *
 * Uses an ephemeral user so ArbZG rest-period from prior admin clocks cannot
 * poison the run.
 *
 * @group integration
 */
final class LegacyAzcClockIsolationIntegrationTest extends TestCase
{
	private string $uid = '';

	/** @var array<string, bool> */
	private array $wasEnabled = [];
	private ?string $prevYearMode = null;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$config = \OC::$server->get(IConfig::class);
		$this->prevYearMode = $config->getAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_YEAR_MODE, Constants::VACATION_YEAR_MODE_CALENDAR);
		$config->setAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_YEAR_MODE, Constants::VACATION_YEAR_MODE_CALENDAR);
		$this->uid = 'azc_legacy_' . bin2hex(random_bytes(3));
		$um = \OC::$server->get(IUserManager::class);
		if ($um->userExists($this->uid)) {
			$um->get($this->uid)?->delete();
		}
		$um->createUser($this->uid, 'Azc-Legacy-' . bin2hex(random_bytes(4)) . '!');
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		try {
			$tracking = \OC::$server->get(TimeTrackingService::class);
			$tracking->clockOut($this->uid);
		} catch (\Throwable) {
		}
		\OC::$server->get(IUserSession::class)->setUser(null);
		$apps = \OC::$server->get(IAppManager::class);
		foreach ($this->wasEnabled as $appId => $enabled) {
			try {
				if ($enabled) {
					$apps->enableApp($appId);
				}
			} catch (\Throwable) {
			}
		}
		$this->wasEnabled = [];
		if ($this->prevYearMode !== null) {
			try {
				\OC::$server->get(IConfig::class)->setAppValue(
					'arbeitszeitcheck',
					Constants::CONFIG_VACATION_YEAR_MODE,
					$this->prevYearMode
				);
			} catch (\Throwable) {
			}
		}
		try {
			\OC::$server->get(IUserManager::class)->get($this->uid)?->delete();
		} catch (\Throwable) {
		}
	}

	public function testClockInOutAndAbsenceWithSuiteHubsDisabled(): void
	{
		$apps = \OC::$server->get(IAppManager::class);
		foreach (['invoicecheck', 'customercheck', 'projectcheck'] as $appId) {
			$this->wasEnabled[$appId] = $apps->isEnabledForUser($appId);
			if ($this->wasEnabled[$appId]) {
				$apps->disableApp($appId);
			}
		}

		$user = \OC::$server->get(IUserManager::class)->get($this->uid);
		$this->assertNotNull($user);
		\OC::$server->get(IUserSession::class)->setUser($user);

		$tracking = \OC::$server->get(TimeTrackingService::class);
		$in = $tracking->clockIn($this->uid, null, 'LEGACY AZC clock');
		$this->assertNotNull($in->getId());
		$this->assertSame('active', strtolower((string)$in->getStatus()));

		$out = $tracking->clockOut($this->uid);
		$this->assertSame('completed', strtolower((string)$out->getStatus()));
		$this->assertNotNull($out->getEndTime());

		$absence = \OC::$server->get(AbsenceService::class);
		$suffix = bin2hex(random_bytes(2));
		$row = null;
		$cursor = new \DateTimeImmutable('tomorrow');
		for ($i = 0; $i < 14 && $row === null; $i++, $cursor = $cursor->modify('+1 day')) {
			$from = $cursor->format('Y-m-d');
			try {
				$row = $absence->createAbsence([
					'type' => 'vacation',
					'start_date' => $from,
					'end_date' => $from,
					'reason' => 'LEGACY isolation ' . $suffix,
					'server_may_fill_hours' => true,
				], $this->uid);
			} catch (\Exception $e) {
				$this->assertStringContainsString('working day', $e->getMessage());
			}
		}
		$this->assertNotNull($row, 'no working day found in the next 14 days');
		$this->assertGreaterThan(0, (int)$row->getId());
	}
}
