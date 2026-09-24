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
		$config = \OCP\Server::get(IConfig::class);
		$this->prevYearMode = $config->getAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_YEAR_MODE, Constants::VACATION_YEAR_MODE_CALENDAR);
		$config->setAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_YEAR_MODE, Constants::VACATION_YEAR_MODE_CALENDAR);
		$this->uid = 'azc_legacy_' . bin2hex(random_bytes(3));
		$um = \OCP\Server::get(IUserManager::class);
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
			$tracking = \OCP\Server::get(TimeTrackingService::class);
			$tracking->clockOut($this->uid);
		} catch (\Throwable) {
		}
		\OCP\Server::get(IUserSession::class)->setUser(null);
		$apps = \OCP\Server::get(IAppManager::class);
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
				\OCP\Server::get(IConfig::class)->setAppValue(
					'arbeitszeitcheck',
					Constants::CONFIG_VACATION_YEAR_MODE,
					$this->prevYearMode
				);
			} catch (\Throwable) {
			}
		}
		try {
			\OCP\Server::get(IUserManager::class)->get($this->uid)?->delete();
		} catch (\Throwable) {
		}
	}

	public function testClockInOutAndAbsenceWithSuiteHubsDisabled(): void
	{
		$apps = \OCP\Server::get(IAppManager::class);
		foreach (['invoicecheck', 'customercheck', 'projectcheck'] as $appId) {
			$this->wasEnabled[$appId] = $apps->isEnabledForUser($appId);
			if ($this->wasEnabled[$appId]) {
				$apps->disableApp($appId);
			}
		}

		$user = \OCP\Server::get(IUserManager::class)->get($this->uid);
		$this->assertNotNull($user);
		\OCP\Server::get(IUserSession::class)->setUser($user);

		$tracking = \OCP\Server::get(TimeTrackingService::class);
		$in = $tracking->clockIn($this->uid, null, 'LEGACY AZC clock');
		$this->assertNotNull($in->getId());
		$this->assertSame('active', strtolower((string)$in->getStatus()));

		$out = $tracking->clockOut($this->uid);
		$this->assertSame('completed', strtolower((string)$out->getStatus()));
		$this->assertNotNull($out->getEndTime());

		$absence = \OCP\Server::get(AbsenceService::class);
		$suffix = bin2hex(random_bytes(2));
		$from = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
		$row = $absence->createAbsence([
			'type' => 'vacation',
			'start_date' => $from,
			'end_date' => $from,
			'reason' => 'LEGACY isolation ' . $suffix,
			'server_may_fill_hours' => true,
		], $this->uid);
		$this->assertGreaterThan(0, (int)$row->getId());
	}
}
