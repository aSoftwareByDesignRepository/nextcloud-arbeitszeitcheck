<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

/**
 * Audit log Search activity filter: layout isolation + stable JS hooks.
 *
 * Contract: the action buttons live in their own row BELOW the filter grid
 * (never inside it), the reset is the family-standard rotate icon button,
 * and the old combined footnote is split into per-field hints bound via
 * aria-describedby.
 */
class AuditLogFilterLayoutTest extends TestCase
{
	private static function template(): string
	{
		return (string)file_get_contents(__DIR__ . '/../../../templates/audit-log.php');
	}

	private static function css(): string
	{
		return (string)file_get_contents(__DIR__ . '/../../../css/audit-log.css');
	}

	public function testFilterTemplateKeepsStableIdsAndIsolatedClasses(): void
	{
		$template = self::template();

		foreach ([
			'id="audit-log-filter-form"',
			'id="start-date"',
			'id="end-date"',
			'id="user-filter"',
			'id="audit-user-search"',
			'id="audit-user-picker"',
			'id="action-category-filter"',
			'id="entity-type-filter"',
			'id="offline-sync-filter"',
			'id="apply-filters"',
			'id="reset-filters"',
			'id="export-logs"',
			'id="audit-log-filter-error"',
			'id="audit-log-date-hint"',
			'id="audit-log-user-hint"',
		] as $needle) {
			$this->assertStringContainsString($needle, $template);
		}

		$this->assertStringContainsString('audit-log-page__filter-grid', $template);
		$this->assertStringContainsString('audit-log-filter__field--from', $template);
		$this->assertStringContainsString('audit-log-filter__actions', $template);

		/* Must not reuse global .azc-filter-field (page-patterns subgrid breaks named areas). */
		$this->assertStringNotContainsString('azc-filter-field', $template);
		$this->assertStringNotContainsString('azc-audit-filter-grid', $template);
	}

	public function testActionButtonsLiveBelowTheFilterGrid(): void
	{
		$template = self::template();

		$lastField = strpos($template, 'audit-log-filter__field--offline');
		$actions = strpos($template, 'class="audit-log-filter__actions"');
		$error = strpos($template, 'id="audit-log-filter-error"');
		$this->assertNotFalse($lastField, 'offline field missing');
		$this->assertNotFalse($actions, 'actions row missing');
		$this->assertNotFalse($error, 'error callout missing');
		$this->assertLessThan($actions, $lastField, 'actions row must render after the last filter field');
		$this->assertLessThan($error, $actions, 'actions row must render before the error callout');

		// Actions must not be a grid cell any more.
		$this->assertStringNotContainsString('audit-log-filter__field--actions', $template);
		$this->assertStringNotContainsString('audit-log-filter__label--spacer', $template);

		// Order inside the row: primary first, export, reset icon last.
		$apply = strpos($template, 'id="apply-filters"');
		$export = strpos($template, 'id="export-logs"');
		$reset = strpos($template, 'id="reset-filters"');
		$this->assertNotFalse($apply);
		$this->assertNotFalse($export);
		$this->assertNotFalse($reset);
		$this->assertLessThan($export, $apply, 'Show must come before Export CSV');
		$this->assertLessThan($reset, $export, 'Export CSV must come before the reset icon');
	}

	public function testResetIsTheFamilyRotateIconButton(): void
	{
		$template = self::template();

		$resetPos = strpos($template, 'id="reset-filters"');
		$this->assertNotFalse($resetPos);
		$buttonHtml = substr($template, $resetPos - 400, 800);

		$this->assertStringContainsString('audit-log-filter-reset', $buttonHtml);
		$this->assertStringContainsString('IconCatalog::render(\'rotate\'', $buttonHtml, 'reset must render the family rotate icon');
		$this->assertStringContainsString('azc-btn__icon', $buttonHtml);
		$this->assertMatchesRegularExpression('/aria-label="<\?php p\(\$l->t\(\'Reset\'\)\); \?>"/', $buttonHtml, 'icon-only button needs an accessible name');
		// Icon-only: no visible text between the button tags.
		$this->assertDoesNotMatchRegularExpression('/id="reset-filters"[^>]*>\s*<\?php p\(/', $template, 'reset must not print a text label');
	}

	public function testHintsAreBoundToTheirFields(): void
	{
		$template = self::template();

		// Date range hint sits under the two date inputs and describes both.
		$this->assertSame(2, substr_count($template, 'aria-describedby="audit-log-date-hint"'), 'both date inputs must reference the date hint');
		$this->assertStringContainsString('audit-log-filter__hint--dates', $template);
		$this->assertStringContainsString('Maximum date range: %d days.', $template);

		// User hint sits under the account combobox and is phrased positively.
		$this->assertStringContainsString('aria-describedby="audit-log-user-hint audit-user-status"', $template);
		$this->assertStringContainsString('audit-log-filter__hint--user', $template);
		$this->assertStringContainsString('Search by name — no need to enter a raw user ID.', $template);
		$this->assertStringNotContainsString('Never type a raw user id', $template);

		// The retired combined footnote is gone — no dangling describedby refs.
		$this->assertStringNotContainsString('audit-log-filter-footnote', $template);
		$this->assertStringNotContainsString('audit-log-page__toolbar-footnote', $template);
	}

	public function testFilterCssUsesNamedAreasWithoutSubgridSpan(): void
	{
		$css = self::css();

		$this->assertStringContainsString("'from to user'", $css);
		$this->assertStringContainsString("'datehint datehint userhint'", $css);
		$this->assertStringContainsString("'action entity offline'", $css);
		$this->assertStringContainsString('audit-log-filter__field', $css);
		$this->assertStringContainsString('audit-log-filter__hint', $css);
		// The actions row must not be a grid area any more.
		$this->assertDoesNotMatchRegularExpression('/grid-template-areas:[^;]*\'[^;\']*\bactions\b[^;\']*\'/s', $css, 'grid-template-areas must not contain an actions area');
		$this->assertStringContainsString('audit-log-filter-reset', $css, 'reset icon needs the square touch-target override');
		$this->assertDoesNotMatchRegularExpression('/\.audit-log-filter__field\s*\{[^}]*grid-row:\s*span\s*2/s', $css);
		$this->assertStringNotContainsString('azc-audit-filter-grid', $css);
		$this->assertStringNotContainsString('audit-log-page__toolbar-footnote', $css, 'retired footnote style must be removed');
	}
}
