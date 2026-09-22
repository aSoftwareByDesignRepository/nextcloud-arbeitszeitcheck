<?php

declare(strict_types=1);

/**
 * Contract: teams bulk people search must use `search=` (not `q=`),
 * and bulk F5 UI strings must be injected via teams-l10n.php.
 */
namespace OCA\ArbeitszeitCheck\Tests\Unit\Js;

use PHPUnit\Framework\TestCase;

class AdminTeamsBulkSearchParamContractTest extends TestCase
{
	public function testBulkSearchUsesSearchQueryParam(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../js/admin-teams.js');
		$this->assertStringContainsString("picker: '1'", $src);
		$this->assertStringContainsString('search: q', $src);
		$this->assertStringNotContainsString("'?q=' + encodeURIComponent(q) + '&picker=1", $src);
		$this->assertStringContainsString('u.userId || u.uid || u.id', $src);
	}

	public function testInModalPickerCssKeepsListInFlow(): void
	{
		$css = (string)file_get_contents(__DIR__ . '/../../../css/common/user-picker.css');
		$this->assertStringContainsString('.user-picker--in-modal .user-picker__list', $css);
		$this->assertStringContainsString('position: static', $css);
	}

	public function testTeamBulkResultsCssLivesOnTeamsStylesheet(): void
	{
		$css = (string)file_get_contents(__DIR__ . '/../../../css/admin-teams.css');
		$this->assertStringContainsString('.team-bulk-results', $css);
	}

	public function testTeamsL10nIncludesBulkAssignStrings(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../templates/common/teams-l10n.php');
		$this->assertStringContainsString('TemplateL10n::mapFromMessageIds', $src);
		foreach ([
			'Find people',
			'Find a person',
			'Add several people…',
			'Add several managers…',
			'Add selected',
			'Add selected (%n)',
			'%n selected',
			'Select at least one person.',
			'Added %1$d, skipped %2$d, failed %3$d.',
		] as $needle) {
			$this->assertStringContainsString("'" . $needle . "'", $src, 'Missing teams l10n key: ' . $needle);
		}
	}

	public function testAdminUserPickerAcceptsIdAlias(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../js/common/admin-user-picker.js');
		$this->assertStringContainsString('u.userId || u.uid || u.id', $src);
	}

	public function testLicenseAndKioskAcceptSearchAlias(): void
	{
		$license = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/LicenseAdminController.php');
		$kiosk = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/KioskAdminController.php');
		$manager = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/ManagerController.php');
		$this->assertStringContainsString('PeopleSearchQuery::fromRequest', $license);
		$this->assertStringContainsString('PeopleSearchQuery::fromRequest', $kiosk);
		$this->assertStringContainsString('PeopleSearchQuery::fromRequest', $manager);
	}
}
