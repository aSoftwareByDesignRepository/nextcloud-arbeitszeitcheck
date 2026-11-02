<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

final class KraftBulkRoutesContractTest extends TestCase
{
	public function testBatchRoutesRegistered(): void
	{
		$routes = (string)file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
		foreach ([
			"/api/admin/license/mobile-seats/batch",
			"/api/admin/teams/{id}/members/batch",
			"/api/admin/teams/{id}/managers/batch",
			"/api/admin/users/batch-profile",
			"/api/admin/users/batch-vacation-policy",
			'license_admin#assignSeatsBatch',
			'admin#addTeamMembersBatch',
			'admin#addTeamManagersBatch',
			'admin#batchUpdateUserProfiles',
			'admin#batchAssignVacationPolicy',
		] as $needle) {
			$this->assertStringContainsString($needle, $routes);
		}
	}

	public function testTeamsUiHasOneAddPeopleButtonPerPanel(): void
	{
		$tpl = (string)file_get_contents(__DIR__ . '/../../../templates/admin-teams.php');
		$this->assertStringNotContainsString('id="team-add-member"', $tpl);
		$this->assertStringNotContainsString('id="team-add-manager"', $tpl);
		$this->assertStringContainsString('id="team-add-members-bulk"', $tpl);
		$this->assertStringContainsString('id="team-add-managers-bulk"', $tpl);
		$this->assertSame(2, substr_count($tpl, "t('Add people')"));
	}
}
