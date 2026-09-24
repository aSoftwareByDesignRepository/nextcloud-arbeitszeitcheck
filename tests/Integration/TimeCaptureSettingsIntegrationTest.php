<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use Test\TestCase;

/**
 * Persists per-user time capture settings through the real service and database.
 */
class TimeCaptureSettingsIntegrationTest extends TestCase
{
	private const TEST_USER = '__arbeitszeitcheck_time_capture_int__';

	private UserSettingsMapper $userSettingsMapper;

	private TimeCaptureMethodService $timeCaptureMethodService;

	protected function setUp(): void
	{
		parent::setUp();
		$this->userSettingsMapper = \OCP\Server::get(UserSettingsMapper::class);
		$this->timeCaptureMethodService = \OCP\Server::get(TimeCaptureMethodService::class);
		$this->clearTestUserSettings();
	}

	protected function tearDown(): void
	{
		$this->clearTestUserSettings();
		parent::tearDown();
	}

	private function clearTestUserSettings(): void
	{
		$this->userSettingsMapper->deleteSetting(self::TEST_USER, Constants::SETTING_CLOCK_STAMPING_ENABLED);
		$this->userSettingsMapper->deleteSetting(self::TEST_USER, Constants::SETTING_MANUAL_TIME_ENTRY_ENABLED);
	}

	public function testDefaultsBothEnabledWhenNoRowsExist(): void
	{
		$settings = $this->timeCaptureMethodService->getSettings(self::TEST_USER);

		$this->assertTrue($settings['clockStampingEnabled']);
		$this->assertTrue($settings['manualTimeEntryEnabled']);
	}

	public function testDisableManualTimeEntryPersistsAndBlocksAssertion(): void
	{
		$updated = $this->timeCaptureMethodService->setSettings(
			self::TEST_USER,
			['manualTimeEntryEnabled' => false],
			'integration_test',
		);

		$this->assertTrue($updated['clockStampingEnabled']);
		$this->assertFalse($updated['manualTimeEntryEnabled']);
		$this->assertFalse($this->timeCaptureMethodService->isManualTimeEntryEnabled(self::TEST_USER));

		$this->expectException(BusinessRuleException::class);
		$this->timeCaptureMethodService->assertManualTimeEntryAllowed(self::TEST_USER);
	}

	public function testDisableClockStampingPersistsAndBlocksAssertion(): void
	{
		$updated = $this->timeCaptureMethodService->setSettings(
			self::TEST_USER,
			['clockStampingEnabled' => false],
			'integration_test',
		);

		$this->assertFalse($updated['clockStampingEnabled']);
		$this->assertTrue($updated['manualTimeEntryEnabled']);
		$this->assertFalse($this->timeCaptureMethodService->isClockStampingEnabled(self::TEST_USER));

		$this->expectException(BusinessRuleException::class);
		$this->timeCaptureMethodService->assertClockStampingAllowed(self::TEST_USER);
	}

	public function testCannotDisableBothMethods(): void
	{
		$this->expectException(BusinessRuleException::class);
		$this->timeCaptureMethodService->setSettings(
			self::TEST_USER,
			[
				'clockStampingEnabled' => false,
				'manualTimeEntryEnabled' => false,
			],
			'integration_test',
		);
	}

	public function testReEnableMethodRemovesPersistedDisableRow(): void
	{
		$this->timeCaptureMethodService->setSettings(
			self::TEST_USER,
			['clockStampingEnabled' => false],
			'integration_test',
		);
		$this->assertFalse($this->timeCaptureMethodService->isClockStampingEnabled(self::TEST_USER));

		$this->timeCaptureMethodService->setSettings(
			self::TEST_USER,
			['clockStampingEnabled' => true],
			'integration_test',
		);

		$this->assertTrue($this->timeCaptureMethodService->isClockStampingEnabled(self::TEST_USER));
		$this->assertNull(
			$this->userSettingsMapper->getSetting(self::TEST_USER, Constants::SETTING_CLOCK_STAMPING_ENABLED),
		);
	}

	public function testOrganizationDisabledClockOverridesUserPreference(): void
	{
		$this->timeCaptureMethodService->setOrganizationDefaults([
			'clockStampingEnabled' => false,
			'manualTimeEntryEnabled' => true,
		], 'integration_test');

		try {
			$this->assertFalse($this->timeCaptureMethodService->isClockStampingEnabled(self::TEST_USER));
			$this->assertTrue($this->timeCaptureMethodService->getUserPreferences(self::TEST_USER)['clockStampingEnabled']);
			$this->assertTrue($this->timeCaptureMethodService->isManualTimeEntryEnabled(self::TEST_USER));

			$settings = $this->timeCaptureMethodService->getSettings(self::TEST_USER);
			$this->assertFalse($settings['clockStampingEnabled']);
			$this->assertTrue($settings['manualTimeEntryEnabled']);
		} finally {
			$this->timeCaptureMethodService->setOrganizationDefaults([
				'clockStampingEnabled' => true,
				'manualTimeEntryEnabled' => true,
			], 'integration_test');
		}
	}

	public function testOrganizationDisabledManualOverridesUserPreference(): void
	{
		$this->timeCaptureMethodService->setOrganizationDefaults([
			'clockStampingEnabled' => true,
			'manualTimeEntryEnabled' => false,
		], 'integration_test');

		try {
			$this->timeCaptureMethodService->setSettings(
				self::TEST_USER,
				['manualTimeEntryEnabled' => true],
				'integration_test',
			);

			$this->assertFalse($this->timeCaptureMethodService->isManualTimeEntryEnabled(self::TEST_USER));
			$this->assertTrue($this->timeCaptureMethodService->getUserPreferences(self::TEST_USER)['manualTimeEntryEnabled']);
			$this->assertTrue($this->timeCaptureMethodService->isClockStampingEnabled(self::TEST_USER));

			$settings = $this->timeCaptureMethodService->getSettings(self::TEST_USER);
			$this->assertTrue($settings['clockStampingEnabled']);
			$this->assertFalse($settings['manualTimeEntryEnabled']);
		} finally {
			$this->timeCaptureMethodService->setOrganizationDefaults([
				'clockStampingEnabled' => true,
				'manualTimeEntryEnabled' => true,
			], 'integration_test');
		}
	}

	public function testCannotDisableBothOrganizationMethods(): void
	{
		$this->expectException(BusinessRuleException::class);
		$this->timeCaptureMethodService->setOrganizationDefaults([
			'clockStampingEnabled' => false,
			'manualTimeEntryEnabled' => false,
		], 'integration_test');
	}

	/**
	 * Regression guard: the Nextcloud "Administration → ArbeitszeitCheck" settings
	 * section (the ISettings render path) must reflect the persisted organisation
	 * time-capture config — otherwise it always shows "both active" and saving
	 * silently re-enables a disabled method.
	 */
	public function testAdminSettingsFormReflectsOrganizationTimeCapture(): void
	{
		$this->timeCaptureMethodService->setOrganizationDefaults([
			'clockStampingEnabled' => false,
			'manualTimeEntryEnabled' => true,
		], 'integration_test');

		try {
			$adminSettings = \OCP\Server::get(\OCA\ArbeitszeitCheck\Settings\AdminSettings::class);
			$params = $adminSettings->getForm()->getParams();

			$this->assertArrayHasKey('settings', $params);
			$this->assertArrayHasKey('clockStampingEnabled', $params['settings']);
			$this->assertArrayHasKey('manualTimeEntryEnabled', $params['settings']);
			$this->assertFalse($params['settings']['clockStampingEnabled']);
			$this->assertTrue($params['settings']['manualTimeEntryEnabled']);
		} finally {
			$this->timeCaptureMethodService->setOrganizationDefaults([
				'clockStampingEnabled' => true,
				'manualTimeEntryEnabled' => true,
			], 'integration_test');
		}
	}
}
