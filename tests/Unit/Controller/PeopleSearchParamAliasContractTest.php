<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Contract: people-search endpoints use PeopleSearchQuery (search preferred, q alias).
 */
class PeopleSearchParamAliasContractTest extends TestCase
{
	public function testAdminGetUsersForPickerUsesSharedHelper(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/AdminController.php');
		$this->assertStringContainsString('PeopleSearchQuery::fromRequest', $src);
		$pos = strpos($src, 'function getUsersForPicker');
		$this->assertNotFalse($pos);
		$chunk = substr($src, $pos, 1200);
		$this->assertStringContainsString('PeopleSearchQuery::fromRequest', $chunk);
		$this->assertStringContainsString('PeopleSearchQuery::clamp', $chunk);
	}

	public function testManagerScopedEmployeesUsesSharedHelper(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/ManagerController.php');
		$this->assertMatchesRegularExpression(
			'/function getScopedEmployees[\s\S]{0,2500}PeopleSearchQuery::fromRequest/',
			$src
		);
	}

	public function testManagerRevisionPdfUsersUsesSharedHelper(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/ManagerController.php');
		$this->assertMatchesRegularExpression(
			'/function revisionPdfUsers[\s\S]{0,1800}PeopleSearchQuery::fromRequest/',
			$src
		);
	}

	public function testLicenseSearchUsersUsesSharedHelper(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/LicenseAdminController.php');
		$pos = strpos($src, 'function searchUsers');
		$this->assertNotFalse($pos);
		$chunk = substr($src, $pos, 800);
		$this->assertStringContainsString('PeopleSearchQuery::fromRequest', $chunk);
		$this->assertStringContainsString("'userId' => \$uid", $chunk);
	}

	public function testKioskSearchUsersUsesSharedHelper(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/KioskAdminController.php');
		$pos = strpos($src, 'function searchUsers');
		$this->assertNotFalse($pos);
		$chunk = substr($src, $pos, 500);
		$this->assertStringContainsString('PeopleSearchQuery::fromRequest', $chunk);
	}

	public function testSharedHelperAcceptsBothParams(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Support/PeopleSearchQuery.php');
		$this->assertStringContainsString("getParam('search'", $src);
		$this->assertStringContainsString("getParam('q'", $src);
	}
}
