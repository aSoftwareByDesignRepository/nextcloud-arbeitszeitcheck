<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Admin employee l10n partial must not call $l->t() with printf placeholders
 * without arguments — json_encode stringifies L10NString via vsprintf and 500s the page.
 */
class AdminUserEditL10nContractTest extends TestCase
{
	public function testOvertimeEffectiveBalanceProvidesPrintfPlaceholder(): void
	{
		$path = __DIR__ . '/../../templates/partials/admin-user-edit-l10n.php';
		$this->assertFileExists($path);
		$content = (string)file_get_contents($path);
		$this->assertStringContainsString(
			"\$l->t('Current Saldo: %1\$s h', ['%1\$s'])",
			$content,
			'overtimeEffectiveBalance l10n must pass a dummy %1$s so LazyL10N can stringify safely',
		);
		$this->assertDoesNotMatchRegularExpression(
			"/\\\$l->t\\('Current Saldo: %1\\\\\\\$s h'\\)/",
			$content,
		);
	}
}
