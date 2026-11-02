<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Row actions are icon-only. The accessible name stays on aria-label.
 * Edit is a pen, delete/remove is a bin, details is an i.
 */
class TableActionIconContractTest extends TestCase
{
	public function testTimeEntryRowActionsUseIconsAndKeepNames(): void
	{
		$html = $this->read('templates/time-entries.php');

		$this->assertMatchesRegularExpression(
			'/btn-edit-entry azc-icon-btn[\s\S]{0,1200}IconCatalog::render\(\'edit\'\)/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/btn-edit-entry azc-icon-btn[\s\S]{0,1200}aria-label=/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/btn-delete-entry azc-icon-btn[\s\S]{0,1600}IconCatalog::render\(\'trash\'\)/',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'/btn-edit-entry azc-icon-btn[\s\S]{0,1200}p\(\$l->t\(\'Edit\'\)\)/',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'/btn-delete-entry azc-icon-btn[\s\S]{0,1600}p\(\$l->t\(\'Delete\'\)\)/',
			$html
		);
	}

	public function testAbsenceRowActionsUsePenBinAndInfo(): void
	{
		$html = $this->read('templates/absences.php');

		$this->assertStringContainsString("IconCatalog::render('edit')", $html);
		$this->assertStringContainsString("IconCatalog::render('trash')", $html);
		$this->assertStringContainsString("IconCatalog::render('info')", $html);
		$this->assertStringContainsString('aria-label="<?php p($l->t(\'View details of this absence\')); ?>"', $html);
		$this->assertStringNotContainsString('icon-rename', $html);
		$this->assertStringNotContainsString('icon-details', $html);
	}

	public function testJsCatalogCanDrawTheRowActionIcons(): void
	{
		$js = $this->read('js/common/catalog.js');
		foreach (['edit', 'trash', 'info', 'copy', 'history', 'archive', 'key'] as $name) {
			$this->assertMatchesRegularExpression(
				'/' . preg_quote($name, '/') . '\s*:/',
				$js,
				$name . ' missing from AzcCatalog'
			);
		}
		$this->assertStringContainsString('aria-hidden="true"', $js);
	}

	private function read(string $relative): string
	{
		$path = dirname(__DIR__, 3) . '/' . $relative;
		$this->assertFileExists($path);

		return (string)file_get_contents($path);
	}
}
