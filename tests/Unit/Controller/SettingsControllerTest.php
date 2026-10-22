<?php

declare(strict_types=1);

/**
 * Unit tests for SettingsController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\SettingsController;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserSetting;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Class SettingsControllerTest
 */
class SettingsControllerTest extends TestCase
{
	/** @var SettingsController */
	private $controller;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var UserSettingsMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $userSettingsMapper;

	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	/** @var CSPService|\PHPUnit\Framework\MockObject\MockObject */
	private $cspService;

	/** @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject */
	private $urlGenerator;

	/** @var PermissionService|\PHPUnit\Framework\MockObject\MockObject */
	private $permissionService;

	protected function setUp(): void
	{
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->request = $this->createMock(IRequest::class);
		$this->cspService = $this->createMock(CSPService::class);
		$this->cspService->method('applyPolicyWithNonce')->willReturnCallback(static fn ($response) => $response);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->permissionService = $this->createMock(PermissionService::class);

		$this->l10n->method('t')
			->willReturnCallback(function ($text) {
				return $text;
			});

		// Inert resolver bound to this test's own config — without it the
		// controller's lazy Server::get fallback would bind the live dev
		// database and let a saved org policy flip auto_break_calculation on.
		$resolverConfig = $this->createMock(\OCP\IConfig::class);
		$resolverConfig->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default) => $key === 'country' ? 'DE' : $default
		);
		$dateTimeZone = $this->createMock(\OCP\IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSessionForResolver = $this->createMock(IUserSession::class);
		$userSessionForResolver->method('getUser')->willReturn(null);
		$inertResolver = new \OCA\ArbeitszeitCheck\Support\BreakPolicyResolver(
			$resolverConfig,
			new \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory($resolverConfig, $this->userSettingsMapper),
			$this->userSettingsMapper,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
			new \OCA\ArbeitszeitCheck\Service\TimeZoneService(
				$resolverConfig, $dateTimeZone, $userSessionForResolver, new \Psr\Log\NullLogger()
			),
			$this->l10n,
		);

		$this->controller = new SettingsController(
			'arbeitszeitcheck',
			$this->request,
			$this->userSession,
			$this->userSettingsMapper,
			$this->auditLogMapper,
			$this->l10n,
			$this->cspService,
			$this->urlGenerator,
			$this->permissionService,
			$inertResolver
		);
	}

	/**
	 * Test index returns template response
	 */
	public function testIndexReturnsTemplateResponse(): void
	{
		$response = $this->controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	/**
	 * Test update settings with valid data
	 */
	public function testUpdateSettingsSuccess(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->request->expects($this->once())
			->method('getParams')
			->willReturn([
				'notifications_enabled' => '1'
			]);

		// Mock old settings
		$oldSetting = new UserSetting();
		$oldSetting->setId(1);
		$oldSetting->setUserId($userId);
		$oldSetting->setSettingKey('notifications_enabled');
		$oldSetting->setSettingValue('0');
		$oldSetting->setCreatedAt(new \DateTime());
		$oldSetting->setUpdatedAt(new \DateTime());

		$this->userSettingsMapper->expects($this->once())
			->method('getSetting')
			->willReturn($oldSetting);

		$this->userSettingsMapper->expects($this->once())
			->method('setSetting')
			->with($userId, 'notifications_enabled', '1')
			->willReturn($oldSetting);

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with(
				$userId,
				'settings_updated',
				'user_settings',
				null,
				$this->isType('array'),
				$this->isType('array')
			);

		$response = $this->controller->update();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('settings', $data);
		$this->assertSame('1', $data['settings']['notifications_enabled']);
	}

	/**
	 * Test update settings validates vacation days
	 */
	public function testUpdateSettingsIgnoresVacationDaysParam(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'vacation_days_per_year' => '-5' // Negative value should be clamped to 0
			]);

		$response = $this->controller->update();
		$data = $response->getData();

		$this->assertFalse($data['success']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('No valid settings provided', $data['error']);
	}

	/**
	 * Test update settings validates boolean values
	 */
	public function testUpdateSettingsValidatesBooleanValues(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'notifications_enabled' => true, // Boolean true
				'break_reminders_enabled' => 'true', // String 'true'
				'auto_break_calculation' => '1', // String '1'
				'missing_clock_in_reminders_enabled' => 'true'
			]);

		$oldSetting = new UserSetting();
		$oldSetting->setId(1);
		$oldSetting->setUserId($userId);
		$oldSetting->setSettingKey('notifications_enabled');
		$oldSetting->setSettingValue('0');
		$oldSetting->setCreatedAt(new \DateTime());
		$oldSetting->setUpdatedAt(new \DateTime());

		$this->userSettingsMapper->method('getSetting')->willReturn($oldSetting);

		// All should be converted to '1'
		$this->userSettingsMapper->expects($this->exactly(4))
			->method('setSetting')
			->withConsecutive(
				[$userId, 'notifications_enabled', '1'],
				[$userId, 'break_reminders_enabled', '1'],
				[$userId, 'auto_break_calculation', '1'],
				[$userId, 'missing_clock_in_reminders_enabled', '1']
			)
			->willReturn($oldSetting);

		$this->auditLogMapper->expects($this->once())
			->method('logAction');

		$response = $this->controller->update();
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Org-enforced break policy: an employee POST of auto_break_calculation='0'
	 * must not reach persistence and must report the effective '1' — a stored
	 * '0' would make the POST echo contradict index_api's effective value.
	 */
	public function testUpdateAutoBreakOptOutLockedByEnforcedPolicy(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['auto_break_calculation' => '0']);

		// Resolver backed by an enforced org policy (same wiring as setUp,
		// but break_policy_json carries enforced=true).
		$resolverConfig = $this->createMock(\OCP\IConfig::class);
		$resolverConfig->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default) => $key === \OCA\ArbeitszeitCheck\Constants::CONFIG_BREAK_POLICY_JSON
				? json_encode(['enforced' => true, 'tiers' => [['afterHours' => 6, 'breakMinutes' => 45]]])
				: ($key === 'country' ? 'DE' : $default)
		);
		$dateTimeZone = $this->createMock(\OCP\IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSessionForResolver = $this->createMock(IUserSession::class);
		$userSessionForResolver->method('getUser')->willReturn(null);
		$enforcedResolver = new \OCA\ArbeitszeitCheck\Support\BreakPolicyResolver(
			$resolverConfig,
			new \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory($resolverConfig, $this->userSettingsMapper),
			$this->userSettingsMapper,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
			new \OCA\ArbeitszeitCheck\Service\TimeZoneService(
				$resolverConfig, $dateTimeZone, $userSessionForResolver, new \Psr\Log\NullLogger()
			),
			$this->l10n,
		);

		$controller = new SettingsController(
			'arbeitszeitcheck',
			$this->request,
			$this->userSession,
			$this->userSettingsMapper,
			$this->auditLogMapper,
			$this->l10n,
			$this->cspService,
			$this->urlGenerator,
			$this->permissionService,
			$enforcedResolver
		);

		// The opt-out must never be written.
		$this->userSettingsMapper->expects($this->never())
			->method('setSetting')
			->with($userId, 'auto_break_calculation', '0');

		$response = $controller->update();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('1', $data['settings']['auto_break_calculation'],
			'POST echo must report the enforced effective value, not the rejected opt-out');
		$this->assertTrue($data['breakPolicy']['enforced']);
	}

	/**
	 * B-2: dismissed_hints stores a canonical JSON list — invalid ids are
	 * dropped, unknown-but-well-formed ids are kept (forward-compat).
	 */
	public function testUpdateDismissedHintsStoresCanonicalList(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['dismissed_hints' => ['valid-hint', 'UPPER_BAD', 'ok_2', 42, 'future-hint-9']]);

		$this->userSettingsMapper->method('getSetting')->willReturn(null);

		$this->userSettingsMapper->expects($this->once())
			->method('setSetting')
			->with($userId, 'dismissed_hints', '["valid-hint","ok_2","future-hint-9"]');

		$this->auditLogMapper->expects($this->once())->method('logAction');

		$response = $this->controller->update();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('["valid-hint","ok_2","future-hint-9"]', $data['settings']['dismissed_hints']);
	}

	/**
	 * B-2: the "show all help texts" reset sends an empty list — it must
	 * persist as an empty set, not be treated as "nothing provided".
	 */
	public function testUpdateDismissedHintsEmptyListClearsAll(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['dismissed_hints' => []]);

		$this->userSettingsMapper->method('getSetting')->willReturn(null);

		$this->userSettingsMapper->expects($this->once())
			->method('setSetting')
			->with($userId, 'dismissed_hints', '[]');

		$this->auditLogMapper->expects($this->once())->method('logAction');

		$response = $this->controller->update();
		$this->assertTrue($response->getData()['success']);
	}

	/**
	 * B-2: malformed dismissed_hints payloads are skipped entirely — no
	 * write, no canonicalisation of garbage into storage.
	 */
	public function testUpdateDismissedHintsRejectsMalformedPayloads(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		foreach ([
			'not-json',                       // undecodable string
			['key' => 'value'],               // assoc — not a list
			array_fill(0, 65, 'hint'),        // over the 64-entry cap
			'{"a":1}',                        // decodes to assoc, not list
		] as $payload) {
			$request = $this->createMock(IRequest::class);
			$request->method('getParams')->willReturn(['dismissed_hints' => $payload]);

			$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
			$userSettingsMapper->expects($this->never())->method('setSetting');

			$controller = new SettingsController(
				'arbeitszeitcheck',
				$request,
				$this->userSession,
				$userSettingsMapper,
				$this->auditLogMapper,
				$this->l10n,
				$this->cspService,
				$this->urlGenerator,
				$this->permissionService,
				null
			);

			$response = $controller->update();
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(),
				'malformed dismissed_hints payload must be rejected: ' . json_encode($payload));
		}
	}

	/**
	 * Test update settings returns error when no settings provided
	 */
	public function testUpdateSettingsReturnsErrorWhenNoSettings(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')
			->willReturn([]); // No settings provided

		$response = $this->controller->update();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test update settings returns 401 when user not authenticated
	 *
	 * Auth failures must surface as 401 Unauthorized so the frontend can
	 * distinguish them from generic validation errors and prompt for re-login.
	 */
	public function testUpdateSettingsReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->update();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('not authenticated', $data['error']);
	}

	/**
	 * Test getOnboardingCompleted returns false when not completed
	 */
	public function testGetOnboardingCompletedReturnsFalse(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$setting = new UserSetting();
		$setting->setId(1);
		$setting->setUserId($userId);
		$setting->setSettingKey('onboarding_completed');
		$setting->setSettingValue('0');
		$setting->setCreatedAt(new \DateTime());
		$setting->setUpdatedAt(new \DateTime());

		$this->userSettingsMapper->expects($this->once())
			->method('getSetting')
			->with($userId, 'onboarding_completed')
			->willReturn($setting);

		$response = $this->controller->getOnboardingCompleted();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertFalse($data['completed']);
	}

	/**
	 * Test getOnboardingCompleted returns true when completed
	 */
	public function testGetOnboardingCompletedReturnsTrue(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$setting = new UserSetting();
		$setting->setId(1);
		$setting->setUserId($userId);
		$setting->setSettingKey('onboarding_completed');
		$setting->setSettingValue('1');
		$setting->setCreatedAt(new \DateTime());
		$setting->setUpdatedAt(new \DateTime());

		$this->userSettingsMapper->method('getSetting')
			->willReturn($setting);

		$response = $this->controller->getOnboardingCompleted();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertTrue($data['completed']);
	}

	/**
	 * Test getOnboardingCompleted returns false when setting doesn't exist
	 */
	public function testGetOnboardingCompletedReturnsFalseWhenSettingMissing(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->userSettingsMapper->expects($this->once())
			->method('getSetting')
			->willReturn(null);

		$response = $this->controller->getOnboardingCompleted();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertFalse($data['completed']);
	}

	/**
	 * Test setOnboardingCompleted sets setting and logs action
	 */
	public function testSetOnboardingCompletedSuccess(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->request->method('getParam')
			->willReturnCallback(static function (string $key, $default = null) {
				return match ($key) {
					'completed' => true,
					'scope' => $default,
					default => $default,
				};
			});

		$this->userSettingsMapper->expects($this->once())
			->method('setSetting')
			->with($userId, 'onboarding_completed', '1');

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with(
				$userId,
				'onboarding_completed',
				'user_settings',
				null,
				null,
				['onboarding_completed' => '1']
			);

		$response = $this->controller->setOnboardingCompleted();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('message', $data);
	}

	/**
	 * Test setOnboardingCompleted returns error when user not authenticated
	 */
	public function testSetOnboardingCompletedReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->setOnboardingCompleted();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	public function testIndexApiReturnsSettings(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userSettingsMapper->method('getUserSettings')->willReturn([]);

		$response = $this->controller->index_api();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertTrue($response->getData()['success']);
		$this->assertArrayHasKey('settings', $response->getData());
	}

	public function testIndexApiReturnsEmptySettingsWhenTableMissing(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userSettingsMapper->method('getUserSettings')
			->willThrowException(new \RuntimeException("Table 'oc_arbeitszeitcheck_user_settings' doesn't exist"));

		$d = $this->controller->index_api()->getData();
		$this->assertTrue($d['success']);
		$this->assertSame([], $d['settings']);
	}

	public function testIndexApiSurvivesNonDbException(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userSettingsMapper->method('getUserSettings')
			->willThrowException(new \RuntimeException('connection reset by peer'));

		$d = $this->controller->index_api()->getData();
		$this->assertTrue($d['success']);
		$this->assertSame([], $d['settings']);
	}

	public function testIndexApiSerializesSettingRows(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$setting = new UserSetting();
		$setting->setSettingKey('theme');
		$setting->setSettingValue('dark');
		$this->userSettingsMapper->method('getUserSettings')->willReturn([$setting]);

		$d = $this->controller->index_api()->getData();
		$this->assertSame(['theme' => 'dark'], $d['settings']);
	}
}
