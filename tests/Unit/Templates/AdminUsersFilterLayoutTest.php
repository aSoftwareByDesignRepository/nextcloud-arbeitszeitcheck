<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

final class AdminUsersFilterLayoutTest extends TestCase
{
	public function testEmployeeListUsesFilterFieldsInsteadOfSearchRadios(): void
	{
		$template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin-users.php');

		$this->assertStringContainsString('id="employee-list-filter-form"', $template);
		$this->assertStringContainsString('id="user-search"', $template);
		$this->assertStringContainsString('id="employee-list-access-filter"', $template);
		$this->assertStringContainsString('id="employee-list-model-filter"', $template);
		$this->assertStringContainsString('id="employee-list-status-filter"', $template);
		$this->assertStringContainsString('id="employee-list-filter-apply"', $template);
		$this->assertStringContainsString('id="employee-list-filter-clear"', $template);
		$this->assertStringContainsString('id="btn-filter"', $template);
		$this->assertStringContainsString('id="filter-section"', $template);
		$this->assertStringContainsString("IconCatalog::render('rotate'", $template);
		$this->assertStringContainsString('admin-users-filter-reset', $template);
		$this->assertStringContainsString('style="display: none;"', $template);
		$this->assertStringNotContainsString('azc-filter-panel__intro', $template);
		$this->assertDoesNotMatchRegularExpression(
			'/id="employee-list-filter-clear"[\s\S]*?\>\s*<\?php p\(\$l->t\(\'Clear\'\)\); \?>/',
			$template
		);
		$this->assertStringContainsString("MODEL_NONE", $template);
		$this->assertStringContainsString("MODEL_PERSONAL", $template);
		$this->assertStringNotContainsString('employee-list-filter-app-access', $template);
		$this->assertStringNotContainsString('name="employee-list-access-filter"', $template);
		$this->assertStringNotContainsString('id="refresh-users"', $template);
	}
}
