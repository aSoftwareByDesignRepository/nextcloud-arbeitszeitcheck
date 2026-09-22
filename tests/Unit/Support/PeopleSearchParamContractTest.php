<?php

declare(strict_types=1);

/**
 * Hard gate: every people-search client must match its API param contract,
 * dual-read `search`+`q` on every picker endpoint, and never send `q=` alone
 * to admin/manager picker APIs that historically only honored `search`.
 *
 * Regression class: Kraft 1.7.10 empty “Find a person” / bulk Find people.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

final class PeopleSearchParamContractTest extends TestCase
{
	private string $appRoot;

	protected function setUp(): void
	{
		parent::setUp();
		$this->appRoot = dirname(__DIR__, 3);
	}

	public function testSharedHelperExistsAndIsUsedByPickerControllers(): void
	{
		$helper = $this->appRoot . '/lib/Support/PeopleSearchQuery.php';
		$this->assertFileExists($helper);

		$controllers = [
			'lib/Controller/AdminController.php',
			'lib/Controller/ManagerController.php',
			'lib/Controller/LicenseAdminController.php',
			'lib/Controller/KioskAdminController.php',
		];
		foreach ($controllers as $rel) {
			$src = (string)file_get_contents($this->appRoot . '/' . $rel);
			$this->assertStringContainsString(
				'PeopleSearchQuery::fromRequest',
				$src,
				$rel . ' must read people search via PeopleSearchQuery (search + q alias)'
			);
		}
	}

	public function testAdminUserPickerAlwaysSendsSearchParam(): void
	{
		$src = (string)file_get_contents($this->appRoot . '/js/common/admin-user-picker.js');
		$this->assertStringContainsString("params.set('search', q)", $src);
		$this->assertDoesNotMatchRegularExpression('/params\.set\(\s*[\'"]q[\'"]/', $src);
	}

	public function testTeamsBulkSearchDoesNotUseBareQAgainstAdminUsersApi(): void
	{
		$src = (string)file_get_contents($this->appRoot . '/js/admin-teams.js');
		$this->assertStringContainsString('search: q', $src);
		$this->assertStringContainsString("picker: '1'", $src);
		$this->assertStringNotContainsString("'?q=' + encodeURIComponent(q) + '&picker=1", $src);
		$this->assertStringNotContainsString('"?q=" + encodeURIComponent(q) + "&picker=1', $src);
	}

	public function testLicenseAndKioskMayUseQBecauseApisAcceptIt(): void
	{
		$licenseJs = (string)file_get_contents($this->appRoot . '/js/admin-license.js');
		$kioskJs = (string)file_get_contents($this->appRoot . '/js/admin-kiosk.js');
		$this->assertStringContainsString("'?q=' + encodeURIComponent(q)", $licenseJs);
		$this->assertStringContainsString("'?q=' + encodeURIComponent(q)", $kioskJs);

		$licensePhp = (string)file_get_contents($this->appRoot . '/lib/Controller/LicenseAdminController.php');
		$kioskPhp = (string)file_get_contents($this->appRoot . '/lib/Controller/KioskAdminController.php');
		$this->assertStringContainsString('PeopleSearchQuery::fromRequest', $licensePhp);
		$this->assertStringContainsString('PeopleSearchQuery::fromRequest', $kioskPhp);
	}

	public function testUserIdAliasesAcceptedInPickerClients(): void
	{
		$files = [
			'js/common/admin-user-picker.js',
			'js/admin-teams.js',
			'js/admin-license.js',
		];
		foreach ($files as $rel) {
			$src = (string)file_get_contents($this->appRoot . '/' . $rel);
			$this->assertStringContainsString(
				'u.userId || u.uid || u.id',
				$src,
				$rel . ' must accept userId/uid/id response shapes'
			);
		}
	}

	public function testInModalPickerListIsInDocumentFlow(): void
	{
		$css = (string)file_get_contents($this->appRoot . '/css/common/user-picker.css');
		$this->assertMatchesRegularExpression(
			'/user-picker--in-modal\s+\.user-picker__list[\s\S]{0,400}position:\s*static/s',
			$css,
			'In-modal suggestion lists must not be absolute (modal overflow clips them)'
		);
	}

	public function testTeamBulkResultsCssShipsOnTeamsPage(): void
	{
		$css = (string)file_get_contents($this->appRoot . '/css/admin-teams.css');
		$this->assertStringContainsString('.team-bulk-results', $css);
	}
}
