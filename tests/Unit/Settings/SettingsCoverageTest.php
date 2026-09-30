<?php

declare(strict_types=1);

/**
 * Atlas coverage lane — PersonalSettings metadata/form and the
 * AdminSettings access-allowlist normalization branches (ghost users,
 * disabled users, duplicates, malformed JSON).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Settings;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Settings\AdminSettings;
use OCA\ArbeitszeitCheck\Settings\PersonalSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class SettingsCoverageTest extends TestCase
{
	public function testPersonalSettingsFormSectionPriority(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$s = new PersonalSettings($session);
		$this->assertSame('arbeitszeitcheck', $s->getSection());
		$this->assertSame(50, $s->getPriority());

		$form = $s->getForm();
		$this->assertInstanceOf(TemplateResponse::class, $form);
		$this->assertSame('personal-settings', $form->getTemplateName());
		$this->assertSame('alice', ($form->getParams()['user_id'] ?? null));
	}

	public function testPersonalSettingsAnonymousUser(): void
	{
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$s = new PersonalSettings($session);
		$this->assertSame('', $s->getForm()->getParams()['user_id']);
	}

	private function buildAdminSettings(array $store, IUserManager $userManager): AdminSettings
	{
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(
			static function (string $key, string $default = '') use (&$store): string {
				return array_key_exists($key, $store) ? (string)$store[$key] : $default;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('search')->willReturn([]);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(false);
		$appManager->method('isInstalled')->willReturn(false);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/apps/arbeitszeitcheck/admin/settings');

		return new AdminSettings($appConfig, $l10n, $groupManager, $appManager, $urlGenerator, $userManager);
	}

	private function allowedUserIds(array $store, array $users): array
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturnCallback(function (string $uid) use ($users) {
			if (!array_key_exists($uid, $users)) {
				return null;
			}
			$u = $this->createMock(IUser::class);
			$u->method('isEnabled')->willReturn((bool)$users[$uid]);
			return $u;
		});
		$settings = $this->buildAdminSettings($store, $um);
		$params = $settings->getForm()->getParams();
		return $params['settings']['accessAllowedUserIds'] ?? [];
	}

	public function testAccessAllowedUserIdsFiltersGhostsDisabledAndDuplicates(): void
	{
		$ids = $this->allowedUserIds(
			[Constants::CONFIG_ACCESS_ALLOWED_USER_IDS => json_encode(['alice', 'ghost', 'disabled', ' alice ', '', 'alice'])],
			['alice' => true, 'disabled' => false],
		);
		$this->assertSame(['alice'], $ids);
	}

	public function testAccessAllowedUserIdsMalformedJsonYieldsEmpty(): void
	{
		$ids = $this->allowedUserIds(
			[Constants::CONFIG_ACCESS_ALLOWED_USER_IDS => 'not-json{'],
			[],
		);
		$this->assertSame([], $ids);
	}

	public function testAdminSettingsSectionPriorityAndAppAdminIds(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturn(null); // every configured admin uid is a ghost
		$settings = $this->buildAdminSettings(
			[Constants::CONFIG_APP_ADMIN_USER_IDS => '["admin1","admin1","ghost"]'],
			$um
		);

		$this->assertSame('arbeitszeitcheck', $settings->getSection());
		$this->assertSame(50, $settings->getPriority());

		// getForm exercises projectCheckAppsSettingsUrl + readConfiguredAppAdminUserIds
		$params = $settings->getForm()->getParams();
		$this->assertArrayHasKey('settings', $params);
		$this->assertSame(
			[],
			$params['settings']['appAdminUserIds'] ?? null,
			'ghost admin ids must be filtered out'
		);
	}

	public function testAccessAllowedUserIdsEmptyDefault(): void
	{
		$ids = $this->allowedUserIds([], []);
		$this->assertSame([], $ids);
	}

	public function testAdminSettingsProjectCheckAppsUrlFallsBackOnRouteFailure(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturn(null);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(static fn (string $k, string $d = '') => $d);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $x) => $x);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('search')->willReturn([]);
		$appManager = $this->createMock(IAppManager::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(static function (string $route) {
			if ($route === 'settings.AppSettings.viewApps') {
				throw new \RuntimeException('route gone');
			}
			return '/apps/x';
		});

		$s = new AdminSettings($appConfig, $l10n, $groupManager, $appManager, $urlGenerator, $um);
		$this->assertSame('', $s->getForm()->getParams()['projectCheckAppsUrl']);
	}
}