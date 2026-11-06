<?php

declare(strict_types=1);

/**
 * Layout contract for the special-entitlements admin page:
 * the create/edit editor and the detail panel render ABOVE the list card
 * (same pattern as admin-absence-types / working-time-models), and the list
 * itself is a design-system table — never a custom card list.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

class AdminSpecialEntitlementsLayoutContractTest extends TestCase
{
	private function tpl(): string
	{
		$tpl = file_get_contents(dirname(__DIR__, 3) . '/templates/admin-special-entitlements.php');
		$this->assertNotFalse($tpl);
		return $tpl;
	}

	private function js(): string
	{
		$js = file_get_contents(dirname(__DIR__, 3) . '/js/admin-special-entitlements.js');
		$this->assertNotFalse($js);
		return $js;
	}

	public function testEditorAndDetailRenderAboveTheListCard(): void
	{
		$tpl = $this->tpl();
		$editor = strpos($tpl, 'id="se-editor"');
		$detail = strpos($tpl, 'id="se-detail"');
		$list = strpos($tpl, 'id="se-type-list"');
		$cohorts = strpos($tpl, 'id="se-cohorts-card"');
		$this->assertNotFalse($editor);
		$this->assertNotFalse($detail);
		$this->assertNotFalse($list);
		$this->assertNotFalse($cohorts);
		// Form + detail live before the list so the opened panel is in view,
		// identical to admin-absence-types (#cat-editor-panel before the card).
		$this->assertLessThan($list, $editor, 'Editor must render before the list card');
		$this->assertLessThan($list, $detail, 'Detail must render before the list card');
		$this->assertLessThan($cohorts, $list, 'List stays above the cohorts card');
	}

	public function testListIsADesignSystemTable(): void
	{
		$tpl = $this->tpl();
		$this->assertStringContainsString('class="table-container"', $tpl);
		$this->assertStringContainsString('table table--hover azc-table--responsive', $tpl);
		$this->assertStringContainsString('id="se-type-list"', $tpl);
		$this->assertStringContainsString('id="se-type-tbody"', $tpl);
		$this->assertStringContainsString('scope="col"', $tpl);
		$this->assertStringContainsString('azc-table-actions-col', $tpl);
		// Empty/loading stays inside the table frame (§3.7/§3.8).
		$this->assertStringContainsString('azc-table-loading', $tpl);
		$this->assertStringContainsString('colspan="7"', $tpl);
		// Entry data columns: amount and recipients come from the type's rules.
		$this->assertStringContainsString("\$l->t('Amount')", $tpl);
		$this->assertStringContainsString("\$l->t('For whom?')", $tpl);
		// The old custom card list is gone for good.
		$this->assertStringNotContainsString('se-type-card', $tpl);
		$this->assertStringNotContainsString('<ul id="se-type-list"', $tpl);
		$this->assertStringNotContainsString('id="se-empty"', $tpl);
	}

	public function testJsRendersTableRowsNotCards(): void
	{
		$js = $this->js();
		$this->assertStringContainsString('se-type-tbody', $js);
		$this->assertStringContainsString('data-label', $js);
		$this->assertStringContainsString('actions-cell', $js);
		$this->assertStringContainsString('azc-table-actions', $js);
		$this->assertStringContainsString('azc-icon-btn', $js);
		$this->assertStringContainsString('azc-table-empty', $js);
		$this->assertStringContainsString('azc-table-loading', $js);
		$this->assertStringContainsString('badge--success', $js);
		$this->assertStringContainsString('badge--neutral', $js);
		// Amount/recipient cells are built from each type's policies.
		$this->assertStringContainsString('item.policies', $js);
		$this->assertStringContainsString('se-type-rule', $js);
		$this->assertStringContainsString('scopeLabel', $js);
		// Text inputs carry the shared form-input styling contract.
		$this->assertStringContainsString('class="form-input"', $this->tpl());
		// Cohort editor opens above its table, mirroring the entitlement flow.
		$this->assertLessThan(
			strpos($this->tpl(), 'id="se-cohorts-card"'),
			strpos($this->tpl(), 'id="se-cohort-editor"')
		);
		$this->assertStringContainsString('id="se-cohort-table"', $this->tpl());
		$this->assertStringContainsString('id="se-cohort-member-chips"', $this->tpl());
		$this->assertStringContainsString('id="se-cohort-member-uid"', $this->tpl());
		$this->assertStringContainsString('id="se-new-cohort"', $this->tpl());
		// Panels surface in view like the absence-types editor.
		$this->assertStringContainsString('scrollIntoView', $js);
		$this->assertStringContainsString('prefersReducedMotion', $js);
		// Async loaders guard against stale responses.
		$this->assertStringContainsString('targetsToken', $js);
		$this->assertStringContainsString('policiesToken', $js);
		$this->assertStringContainsString('typesToken', $js);
		// Server field names are mapped to inline error slots.
		$this->assertStringContainsString('FIELD_ERROR_IDS', $js);
		$this->assertStringContainsString('se-label-error', $js);
		$this->assertStringNotContainsString('se-type-card', $js);
	}

	public function testCssKeepsOnlyTableCellStyles(): void
	{
		$css = file_get_contents(dirname(__DIR__, 3) . '/css/admin-special-entitlements.css');
		$this->assertNotFalse($css);
		$this->assertStringContainsString('se-type-name', $css);
		$this->assertStringContainsString('se-type-code', $css);
		$this->assertStringContainsString('se-type-confidential', $css);
		$this->assertStringNotContainsString('se-type-card', $css);
	}
}
