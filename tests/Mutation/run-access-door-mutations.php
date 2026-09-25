<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for ArbeitszeitCheck portfolio access door.
 * Run: php tests/Mutation/run-access-door-mutations.php
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

// nextcloud/ocp stubs are not installed; autoload OCP interfaces from the
// server checkout (<nc>/{custom_apps,apps}/<app> → <nc>/lib/public).
spl_autoload_register(static function (string $class) use ($root): void {
	if (!str_starts_with($class, 'OCP\\')) {
		return;
	}
	$file = dirname($root, 2) . '/lib/public/' . str_replace('\\', '/', substr($class, 4)) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});
// PSR-3 ships with the server 3rdparty tree, not the app vendor dir.
spl_autoload_register(static function (string $class) use ($root): void {
	if (!str_starts_with($class, 'Psr\\')) {
		return;
	}
	foreach ([
		dirname($root, 2) . '/3rdparty/psr/log/src/' . str_replace('\\', '/', substr($class, 8)) . '.php',
		dirname($root, 2) . '/3rdparty/' . str_replace('\\', '/', $class) . '.php',
		dirname($root, 2) . '/lib/private/' . str_replace('\\', '/', $class) . '.php',
	] as $file) {
		if (is_file($file)) {
			require_once $file;
			return;
		}
	}
});

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

$failures = 0;

/**
 * PHPUnit 9: Generator::testDouble() does not exist; use a lightweight
 * TestCase context so createMock() is available outside a suite run.
 */
$mockContext = new class('access-door-mutations') extends TestCase {
	public function mock(string $class)
	{
		return $this->createMock($class);
	}
};

function kill(string $label, callable $assert): void
{
	global $failures;
	try {
		$assert();
		fwrite(STDOUT, "KILL  {$label}\n");
	} catch (Throwable $e) {
		$failures++;
		fwrite(STDOUT, "SURVIVE {$label}: {$e->getMessage()}\n");
	}
}

function makeService(array $configMap, ?callable $inGroup = null): PermissionService
{
	global $mockContext;
	$groupManager = $mockContext->mock(IGroupManager::class);
	$groupManager->method('isAdmin')->willReturn(false);
	$groupManager->method('isInGroup')->willReturnCallback($inGroup ?? static fn (): bool => false);

	$config = $mockContext->mock(IConfig::class);
	$config->method('getAppValue')->willReturnCallback(
		static function (string $app, string $key, string $default = '') use ($configMap): string {
			return $configMap[$key] ?? $default;
		}
	);

	return new PermissionService(
		$groupManager,
		$mockContext->mock(IAppManager::class),
		$config,
		$mockContext->mock(IUserManager::class),
		$mockContext->mock(TeamResolverService::class),
		$mockContext->mock(LoggerInterface::class),
	);
}

// Mutant: collapse Open into Restricted fail-closed
kill('open-mode-allows-stranger', static function (): void {
	$svc = makeService([
		Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => '0',
		Constants::CONFIG_ACCESS_ALLOWED_USER_IDS => '[]',
		Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS => '[]',
		Constants::CONFIG_APP_ADMIN_USER_IDS => '[]',
	]);
	if (!$svc->isUserAllowedByAccessGroups('stranger')) {
		throw new RuntimeException('Open mode must admit logged-in users');
	}
});

// Mutant: Restricted empty allowlists fall open
kill('restricted-empty-fail-closed', static function (): void {
	$svc = makeService([
		Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => '1',
		Constants::CONFIG_ACCESS_ALLOWED_USER_IDS => '[]',
		Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS => '[]',
		Constants::CONFIG_APP_ADMIN_USER_IDS => '[]',
	]);
	if ($svc->isUserAllowedByAccessGroups('stranger')) {
		throw new RuntimeException('Restricted empty lists must deny');
	}
});

// Mutant: invert user allowlist check
kill('restricted-user-allowlist', static function (): void {
	$svc = makeService([
		Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => '1',
		Constants::CONFIG_ACCESS_ALLOWED_USER_IDS => '["alice"]',
		Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS => '[]',
		Constants::CONFIG_APP_ADMIN_USER_IDS => '[]',
	]);
	if (!$svc->isUserAllowedByAccessGroups('alice') || $svc->isUserAllowedByAccessGroups('bob')) {
		throw new RuntimeException('User allowlist broken');
	}
});

fwrite(STDOUT, $failures === 0 ? "All mutants killed.\n" : "{$failures} mutant(s) survived.\n");
exit($failures === 0 ? 0 : 1);
