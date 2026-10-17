<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DedicatedAppAdminContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testPermissionServiceUsesOrSemantics(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/PermissionService.php');
		$start = strpos($src, 'public function isAdmin(string $userId): bool');
		$this->assertNotFalse($start);
		$end = strpos($src, 'public function getConfiguredAppAdminUserIds', $start);
		$this->assertNotFalse($end);
		$body = substr($src, $start, $end - $start);
		$this->assertStringContainsString('if ($this->groupManager->isAdmin($userId))', $body);
		$this->assertStringContainsString('return true;', $body);
		$this->assertStringContainsString('getConfiguredAppAdminUserIds()', $body);
		$this->assertStringContainsString('userManager->get($userId)', $body);
		$this->assertStringContainsString('isEnabled()', $body);
		$this->assertStringNotContainsString('if (!$this->groupManager->isAdmin($userId))', $body);
	}

	public function testNormalizeAllowsNonSystemAdmins(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/AdminController.php');
		$start = strpos($src, 'private function normalizeAppAdminUserIds');
		$this->assertNotFalse($start);
		$body = substr($src, $start, 600);
		$this->assertStringContainsString('isEnabled()', $body);
		$this->assertStringNotContainsString('groupManager->isAdmin($candidate)', $body);
	}

	public function testSettingsTemplateAllowsDelegatedAppAdmins(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/partials/admin-settings/access.php');
		$this->assertStringContainsString('without making them a Nextcloud admin', $html);
		$this->assertStringContainsString('appAdminUsersAddSearch', $html);
		$this->assertStringNotContainsString('Only users in the Nextcloud admin group are listed', $html);
		$this->assertStringContainsString('never type a raw user id', $html);
	}

	public function testTeamsPickerHelpDoesNotAskForRawUserId(): void
	{
		$l10n = (string) file_get_contents($this->root . '/templates/common/teams-l10n.php');
		$js = (string) file_get_contents($this->root . '/js/admin-teams.js');
		$this->assertStringContainsString('Start typing their name or login, then pick them from the list.', $l10n);
		$this->assertStringNotContainsString('name or user ID, then pick', $l10n);
		$this->assertStringContainsString('Start typing their name or login, then pick them from the list.', $js);
		$this->assertStringNotContainsString('name or user ID, then pick', $js);
	}

	public function testVacationSimulatorRequiresSuggestionPickNotFreeTextUid(): void
	{
		$html = (string) file_get_contents($this->root . '/templates/admin-vacation-layers.php');
		$js = (string) file_get_contents($this->root . '/js/admin-vacation-layers.js');
		$this->assertStringContainsString('id="sim-user"', $html);
		$this->assertStringContainsString('type="search"', $html);
		$this->assertStringContainsString('sim-user-suggest', $html);
		$this->assertStringContainsString('name or login', $html);
		$this->assertStringContainsString('const userId = simSelectedUserId || \'\'', $js);
		$this->assertStringNotContainsString('simSelectedUserId || typedValue', $js);
		$this->assertStringNotContainsString('usedFreeText', $js);
		$this->assertStringNotContainsString('type a UID exactly', $js);
		$this->assertStringContainsString('Please pick an employee from the suggestions first.', $js);
	}

	public function testAdminControllersRequireNoAdminRequiredOnEveryPublicAction(): void
	{
		$controllers = [
			'lib/Controller/AdminController.php',
			'lib/Controller/LicenseAdminController.php',
			'lib/Controller/KioskAdminController.php',
			'lib/Controller/WorkLocationAdminController.php',
			'lib/Controller/SpecialEntitlementAdminController.php',
		];
		foreach ($controllers as $rel) {
			$src = (string)file_get_contents($this->root . '/' . $rel);
			$this->assertStringContainsString('use OCP\AppFramework\Http\Attribute\NoAdminRequired;', $src, $rel);
			preg_match_all('/^\tpublic function (\w+)\(/m', $src, $matches);
			foreach ($matches[1] as $method) {
				if ($method === '__construct') {
					continue;
				}
				$pos = strpos($src, 'public function ' . $method . '(');
				$this->assertNotFalse($pos, $rel . '::' . $method);
				$preceding = substr($src, max(0, $pos - 400), 400);
				$lines = array_reverse(explode("\n", $preceding));
				$attrs = [];
				foreach ($lines as $line) {
					$s = trim($line);
					if (str_starts_with($s, '#[')) {
						$attrs[] = $s;
						continue;
					}
					if ($s === '') {
						continue;
					}
					break;
				}
				$joined = implode("\n", $attrs);
				$this->assertStringContainsString('NoAdminRequired', $joined, $rel . '::' . $method . ' must declare #[NoAdminRequired]');
			}
		}
	}

	public function testOvertimePayoutAdminActionsHaveNoAdminRequired(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/OvertimePayoutController.php');
		foreach (['auditIndex', 'listAudit', 'adminMonthClosurePdf', 'index', 'listMonth', 'processOne', 'exportCsv', 'processBulk', 'myHistory'] as $method) {
			$pos = strpos($src, 'public function ' . $method . '(');
			$this->assertNotFalse($pos, $method);
			$preceding = substr($src, max(0, $pos - 400), 400);
			$this->assertStringContainsString('NoAdminRequired', $preceding, $method);
		}
	}

	public function testAppAdminMiddlewareGatesOvertimeAndUsesAccessDeniedTemplate(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Middleware/AppAdminMiddleware.php');
		$this->assertStringContainsString('OvertimePayoutController', $src);
		$this->assertStringContainsString('SpecialEntitlementAdminController', $src);
		$this->assertStringContainsString("'myHistory'", $src);
		$this->assertStringContainsString('access-denied', $src);
		$this->assertStringNotContainsString("'core', '403'", $src);
	}

	public function testAdminControllerDocRequiresNoAdminRequiredPattern(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/AdminController.php');
		$this->assertStringContainsString('AppAdminMiddleware', $src);
		$this->assertStringContainsString('PermissionService::isAdmin()', $src);
		$this->assertStringNotContainsString('Do not add NoAdminRequired', $src);
	}

	public function testAuthenticatedFeedHasNoAdminRequired(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/OutlookIcalSubscriptionController.php');
		$pos = strpos($src, 'public function authenticatedFeed');
		$this->assertNotFalse($pos);
		$preceding = substr($src, max(0, $pos - 250), 250);
		$this->assertStringContainsString('NoAdminRequired', $preceding);
	}

	public function testAppAccessMiddlewareSkipsPublicPage(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Middleware/AppAccessMiddleware.php');
		$this->assertStringContainsString('isPublicPage', $src);
		$this->assertStringContainsString('PublicPage::class', $src);
		$this->assertStringContainsString('IControllerMethodReflector', $src);
	}

	public function testMobileSeatAssignUsesExclusiveCapacityLock(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/MobileSeatService.php');
		$this->assertStringContainsString('DbLockKeys::mobileSeatCapacity()', $src);
		$this->assertStringContainsString('LOCK_EXCLUSIVE', $src);
		$this->assertStringContainsString('acquireLock', $src);
		$this->assertStringContainsString('releaseLock', $src);
	}

}
