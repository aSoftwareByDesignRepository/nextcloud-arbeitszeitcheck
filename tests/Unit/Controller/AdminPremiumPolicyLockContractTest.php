<?php

declare(strict_types=1);

/**
 * Premium policy exclusive lock: save busy conflict + seal path contract.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Constants;
use PHPUnit\Framework\TestCase;

class AdminPremiumPolicyLockContractTest extends TestCase
{
	public function testAdminPolicySaveUsesExclusivePremiumPolicyLock(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../lib/Controller/AdminController.php');
		$this->assertNotFalse($src);
		$start = strpos($src, 'public function updateNotificationSettings');
		$end = strpos($src, 'public function migrateVacationUnit', $start !== false ? $start : 0);
		$this->assertNotFalse($start);
		$this->assertNotFalse($end);
		$method = substr($src, $start, $end - $start);
		$this->assertStringContainsString('DbLockKeys::premiumPolicy()', $method);
		$this->assertStringContainsString('PREMIUM_POLICY_BUSY', $method);
		$this->assertStringContainsString('Premium policy save', $method);
		$this->assertStringContainsString('LOCK_EXCLUSIVE', $method);
		$this->assertStringContainsString('lockingProvider ?? \\OCP\\Server::get', $method);
		// Busy paths must acquire before sibling IConfig writes (atomic preflight).
		$premiumPos = strpos($method, 'acquire($locking, $policyLock');
		$yearPos = strpos($method, 'acquire($locking, $yearLock');
		$commitPos = strpos($method, '// ── Commit phase (sibling settings)');
		$this->assertNotFalse($premiumPos);
		$this->assertNotFalse($yearPos);
		$this->assertNotFalse($commitPos);
		$this->assertLessThan($commitPos, $premiumPos, 'Premium lock must be acquired before sibling commits');
		$this->assertLessThan($commitPos, $yearPos, 'Year-mode lock must be acquired before sibling commits');
		$this->assertStringContainsString('VAC_YEAR_MODE_BUSY', $method);
	}

	public function testClosureSealUsesExclusivePremiumPolicyLockAndOverride(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../lib/Service/PremiumSurchargeService.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('DbLockKeys::premiumPolicy()', $src);
		$this->assertStringContainsString('Premium seal snapshot', $src);
		$this->assertStringContainsString('summariseForUser($userId, $start, $end, $policy)', $src);
		$this->assertStringContainsString('?PremiumPolicy $policyOverride', $src);
	}

	public function testPremiumPolicyBusyConstantStable(): void
	{
		$this->assertSame('azc/pp/policy', \OCA\ArbeitszeitCheck\Service\DbLockKeys::premiumPolicy());
		$this->assertLessThanOrEqual(64, strlen(\OCA\ArbeitszeitCheck\Service\DbLockKeys::premiumPolicy()));
	}
}
