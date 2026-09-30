<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class NavigationFlagsServiceTest extends TestCase
{
	private function service(
		?AbsenceMapper $absenceMapper = null,
		?PermissionService $permissionService = null,
		?IConfig $config = null,
	): NavigationFlagsService {
		return new NavigationFlagsService(
			$absenceMapper ?? $this->createMock(AbsenceMapper::class),
			$permissionService ?? $this->createMock(PermissionService::class),
			$config ?? $this->createMock(IConfig::class),
		);
	}

	public function testForAdminUserForcesAdminNavAndPreservesUserFlags(): void
	{
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findSubstitutePendingForUser')->willReturn([['id' => 1]]);
		$permission = $this->createMock(PermissionService::class);
		// non-admin manager: forUser() alone would not set showAdminNav
		$permission->method('canAccessManagerDashboard')->willReturn(true);
		$permission->method('isAdmin')->willReturn(false);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('1');

		$flags = $this->service($absenceMapper, $permission, $config)->forAdminUser('alice');

		$this->assertTrue($flags['showAdminNav']);
		$this->assertTrue($flags['showManagerLink']);
		$this->assertTrue($flags['showReportsLink']);
		$this->assertTrue($flags['showSubstitutionLink']);
		$this->assertTrue($flags['monthClosureEnabled']);
	}

	public function testForComplianceUserHidesSubstitutionLink(): void
	{
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findSubstitutePendingForUser')->willReturn([['id' => 1]]);
		$flags = $this->service($absenceMapper)->forComplianceUser('alice');
		$this->assertFalse($flags['showSubstitutionLink']);
	}

	public function testForUserSurvivesMapperAndPermissionFailures(): void
	{
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findSubstitutePendingForUser')
			->willThrowException(new \RuntimeException('db down'));
		$permission = $this->createMock(PermissionService::class);
		$permission->method('canAccessManagerDashboard')
			->willThrowException(new \RuntimeException('perm backend down'));
		$flags = $this->service($absenceMapper, $permission)->forUser('alice');
		$this->assertFalse($flags['showSubstitutionLink']);
		$this->assertFalse($flags['showManagerLink']);
		$this->assertFalse($flags['showAdminNav']);
	}
}
