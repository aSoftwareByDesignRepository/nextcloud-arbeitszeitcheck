<?php

declare(strict_types=1);

/**
 * Bachus A3: Working time models use an inline editor panel, not a create/edit modal.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

class WorkingTimeModelsInlineEditorContractTest extends TestCase
{
	public function testTemplateHostsInlineEditorPanel(): void
	{
		$tpl = file_get_contents(dirname(__DIR__, 3) . '/templates/working-time-models.php');
		$this->assertNotFalse($tpl);
		$this->assertStringContainsString('id="wtm-editor-panel"', $tpl);
		$this->assertStringContainsString('wtm-editor-panel', $tpl);
		$this->assertStringContainsString('descriptionOptional', $tpl);
		$this->assertStringContainsString('editorLead', $tpl);
		$this->assertStringContainsString('class="azc-table-empty"', $tpl);
		$this->assertStringContainsString('class="azc-empty-state"', $tpl);
		$this->assertStringContainsString('class="azc-empty-state__title"', $tpl);
		$this->assertStringNotContainsString('colspan="7" class="text-center"', $tpl);
	}

	public function testJsUsesInlinePanelNotCreateEditModals(): void
	{
		$js = file_get_contents(dirname(__DIR__, 3) . '/js/working-time-models.js');
		$this->assertNotFalse($js);
		$this->assertStringContainsString('function openEditorPanel', $js);
		$this->assertStringContainsString('function closeEditorPanel', $js);
		$this->assertStringContainsString('wtm-editor-panel', $js);
		$this->assertStringContainsString('wtm-editor-desc', $js);
		$this->assertStringContainsString("selectedType || 'full_time'", $js);
		$this->assertStringContainsString('editorSaveInflight', $js);
		$this->assertStringContainsString('Please wait until the current save finishes', $js);
		$this->assertStringContainsString('!Array.isArray(model.breakRules)', $js);
		$this->assertStringNotContainsString('breakRules: model.breakRules || []', $js);
		$this->assertStringNotContainsString('create-model-modal', $js);
		$this->assertStringNotContainsString('edit-model-modal', $js);
		$this->assertStringNotContainsString('function showCreateModal', $js);
		$this->assertStringNotContainsString('function showEditModal', $js);
		// Duplicate stays a small name-only dialog (not the matrix kitchen-sink).
		$this->assertStringContainsString('duplicate-model-modal', $js);
	}

	public function testCssStylesInlineEditorTouchTargets(): void
	{
		$css = file_get_contents(dirname(__DIR__, 3) . '/css/working-time-models.css');
		$this->assertNotFalse($css);
		$this->assertStringContainsString('wtm-editor-panel', $css);
		$this->assertStringContainsString('wtm-editor-desc__summary', $css);
		$this->assertStringContainsString('--azc-touch, 44px', $css);
		$this->assertStringNotContainsString('#0082c9', $css);
	}
}
