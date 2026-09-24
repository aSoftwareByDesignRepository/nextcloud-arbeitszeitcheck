<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Controller\FakeControllerForMiddlewareTest;
use OCA\ArbeitszeitCheck\Exception\AppAccessDeniedException;
use OCA\ArbeitszeitCheck\Middleware\AppAccessMiddleware;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/**
 * Portfolio access door — PermissionService restriction flag + group allowlist
 * (not only legacy IAppManager::enableAppForGroups when restriction is Open).
 */
final class AppAccessGateIntegrationTest extends TestCase
{
	private const ALLOWED = 'azc_gate_allowed';
	private const DENIED = 'azc_gate_denied';
	private const GATE_GROUP = 'azc_int_gate_grp';
	private const PASSWORD = 'azc-test-pass-9xK!';

	/** @var list<string> */
	private array $prevAppRestriction = [];

	private string $prevRestrictionEnabled = '';

	private string $prevAllowedGroups = '';

	private string $prevAllowedUsers = '';

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (run inside Docker with NEXTCLOUD_ROOT).');
		}

		/** @var IAppManager $appManager */
		$appManager = \OCP\Server::get(IAppManager::class);
		$this->prevAppRestriction = $appManager->getAppRestriction(Application::APP_ID);

		/** @var IConfig $config */
		$config = \OCP\Server::get(IConfig::class);
		$this->prevRestrictionEnabled = $config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_ACCESS_RESTRICTION_ENABLED,
			'',
		);
		$this->prevAllowedGroups = $config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS,
			'',
		);
		$this->prevAllowedUsers = $config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_ACCESS_ALLOWED_USER_IDS,
			'',
		);

		/** @var IUserManager $userManager */
		$userManager = \OCP\Server::get(IUserManager::class);
		foreach ([self::ALLOWED, self::DENIED] as $uid) {
			if ($userManager->userExists($uid)) {
				$userManager->get($uid)?->delete();
			}
		}
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}

		/** @var IConfig $config */
		$config = \OCP\Server::get(IConfig::class);
		$config->setAppValue(
			Application::APP_ID,
			Constants::CONFIG_ACCESS_RESTRICTION_ENABLED,
			$this->prevRestrictionEnabled,
		);
		$config->setAppValue(
			Application::APP_ID,
			Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS,
			$this->prevAllowedGroups,
		);
		$config->setAppValue(
			Application::APP_ID,
			Constants::CONFIG_ACCESS_ALLOWED_USER_IDS,
			$this->prevAllowedUsers,
		);

		/** @var IAppManager $appManager */
		$appManager = \OCP\Server::get(IAppManager::class);
		// Empty group list for enableAppForGroups means "enabled for nobody".
		// Restore unrestricted installs with enableApp(), not enableAppForGroups([]).
		if ($this->prevAppRestriction === []) {
			$appManager->enableApp(Application::APP_ID);
		} else {
			$appManager->enableAppForGroups(Application::APP_ID, $this->prevAppRestriction);
		}

		/** @var IUserManager $canaryUsers */
		$canaryUsers = \OCP\Server::get(IUserManager::class);
		$canary = $canaryUsers->get('admin') ?? $canaryUsers->get('e2e_employee');
		if ($canary !== null) {
			$this->assertTrue(
				$appManager->isEnabledForUser(Application::APP_ID, $canary),
				'Access-gate tearDown must leave arbeitszeitcheck enabled for existing users',
			);
		}

		/** @var IGroupManager $groupManager */
		$groupManager = \OCP\Server::get(IGroupManager::class);
		if ($groupManager->groupExists(self::GATE_GROUP)) {
			$groupManager->get(self::GATE_GROUP)?->delete();
		}

		/** @var IUserManager $userManager */
		$userManager = \OCP\Server::get(IUserManager::class);
		foreach ([self::ALLOWED, self::DENIED] as $uid) {
			if ($userManager->userExists($uid)) {
				$userManager->get($uid)?->delete();
			}
		}

		/** @var IUserSession $session */
		$session = \OCP\Server::get(IUserSession::class);
		$session->setUser(null);
	}

	public function testUserOutsideAllowedGroupsBlockedByMiddleware(): void
	{
		$this->enableRestrictedDoor([self::GATE_GROUP]);

		/** @var IUserManager $userManager */
		$userManager = \OCP\Server::get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);
		$userManager->createUser(self::DENIED, self::PASSWORD);

		/** @var IGroupManager $groupManager */
		$groupManager = \OCP\Server::get(IGroupManager::class);
		if (!$groupManager->groupExists(self::GATE_GROUP)) {
			$groupManager->createGroup(self::GATE_GROUP);
		}
		$groupManager->get(self::GATE_GROUP)?->addUser($userManager->get(self::ALLOWED));

		/** @var IAppManager $appManager */
		$appManager = \OCP\Server::get(IAppManager::class);
		$appManager->enableAppForGroups(Application::APP_ID, [self::GATE_GROUP]);

		/** @var IUserSession $session */
		$session = \OCP\Server::get(IUserSession::class);
		$session->setUser($userManager->get(self::DENIED));

		$controller = new FakeControllerForMiddlewareTest();
		$middleware = $this->middlewareWithMockRequest();

		try {
			$middleware->beforeController($controller, 'dashboard');
			$this->fail('Expected AppAccessDeniedException for gated user');
		} catch (AppAccessDeniedException) {
			$this->addToAssertionCount(1);
		}

		$response = $middleware->afterException($controller, 'dashboard', new AppAccessDeniedException('restriction'));
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUserInAllowedGroupPassesGate(): void
	{
		$this->enableRestrictedDoor([self::GATE_GROUP]);

		/** @var IUserManager $userManager */
		$userManager = \OCP\Server::get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);

		/** @var IGroupManager $groupManager */
		$groupManager = \OCP\Server::get(IGroupManager::class);
		if (!$groupManager->groupExists(self::GATE_GROUP)) {
			$groupManager->createGroup(self::GATE_GROUP);
		}
		$groupManager->get(self::GATE_GROUP)?->addUser($userManager->get(self::ALLOWED));

		/** @var IAppManager $appManager */
		$appManager = \OCP\Server::get(IAppManager::class);
		$appManager->enableAppForGroups(Application::APP_ID, [self::GATE_GROUP]);

		/** @var IUserSession $session */
		$session = \OCP\Server::get(IUserSession::class);
		$session->setUser($userManager->get(self::ALLOWED));

		$controller = new FakeControllerForMiddlewareTest();
		$this->middlewareWithMockRequest()->beforeController($controller, 'dashboard');
		$this->addToAssertionCount(1);
	}

	/**
	 * @param list<string> $groupIds
	 */
	private function enableRestrictedDoor(array $groupIds): void
	{
		/** @var IConfig $config */
		$config = \OCP\Server::get(IConfig::class);
		$config->setAppValue(
			Application::APP_ID,
			Constants::CONFIG_ACCESS_RESTRICTION_ENABLED,
			'1',
		);
		$config->setAppValue(
			Application::APP_ID,
			Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS,
			json_encode(array_values($groupIds), JSON_THROW_ON_ERROR),
		);
		$config->setAppValue(
			Application::APP_ID,
			Constants::CONFIG_ACCESS_ALLOWED_USER_IDS,
			'[]',
		);
	}

	private function middlewareWithMockRequest(): AppAccessMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/arbeitszeitcheck/api/dashboard');
		$request->method('getMethod')->willReturn('GET');
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match (strtolower($name)) {
				'accept' => 'application/json',
				default => '',
			},
		);

		return new AppAccessMiddleware(
			\OCP\Server::get(IUserSession::class),
			\OCP\Server::get(PermissionService::class),
			$request,
			\OCP\Server::get(\OCP\IURLGenerator::class),
			\OCP\Server::get(\OCP\L10N\IFactory::class),
			\OCP\Server::get(\Psr\Log\LoggerInterface::class),
			\OCP\Server::get(\OCP\AppFramework\Utility\IControllerMethodReflector::class),
		);
	}
}
