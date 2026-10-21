<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

/**
 * Create/edit time-entry form: condensed layout, day summary last, break rules as help text.
 */
class TimeEntryCreateFormLayoutTest extends TestCase {
	private static string $template = __DIR__ . '/../../../templates/time-entries.php';
	private static string $shell = __DIR__ . '/../../../lib/Controller/PageShellTrait.php';

	public function testCreateHelpIsOneSentenceSoMoreHelpStaysHidden(): void {
		$src = (string)file_get_contents(self::$shell);
		$split = strpos($src, "preg_split('/(?<=[.!?])\\s+/u'");
		$create = strpos($src, "\$this->l10n->t('Record working time')");
		$this->assertNotFalse($split);
		$this->assertNotFalse($create);
		$this->assertLessThan($create, $split);
	}

	public function testDateStartAndEndShareOneRowAndBreakRulesFollowThePickers(): void {
		$src = (string)file_get_contents(self::$template);
		$when = strpos($src, 'time-entry-form__when');
		$date = strpos($src, 'id="entry-date"');
		$start = strpos($src, "'name' => 'startTime'");
		$end = strpos($src, "'name' => 'endTime'");
		$pickers = strpos($src, 'id="breaks-container"');
		$hint = strpos($src, 'id="break-rules-hint"');
		$add = strpos($src, 'id="btn-add-break"');
		$this->assertNotFalse($when);
		$this->assertGreaterThan($when, $date);
		$this->assertGreaterThan($date, $start);
		$this->assertGreaterThan($start, $end);
		$this->assertStringContainsString('form-grid--3 time-entry-form__when', $src);
		$whenCss = (string)file_get_contents(__DIR__ . '/../../../css/time-entries.css');
		$this->assertStringContainsString('container-name: azc-when', $whenCss);
		$this->assertStringContainsString('grid-template-columns: repeat(3, minmax(0, 1fr));', $whenCss);
		$this->assertStringContainsString('@container azc-when (max-width: 35.75rem)', $whenCss);
		$this->assertStringContainsString('.time-entry-form__when > .form-group:first-child', $whenCss);
		$this->assertStringContainsString('grid-column: 1 / -1', $whenCss);
		$this->assertStringContainsString('@container azc-when (max-width: 23.5rem)', $whenCss);
		$narrow = strpos($whenCss, '@container azc-when (max-width: 23.5rem)');
		$this->assertNotFalse($narrow);
		$this->assertStringContainsString('grid-template-columns: 1fr;', substr($whenCss, $narrow, 240));
		$a11y = (string)file_get_contents(__DIR__ . '/../../../css/time-entry-form-accessibility.css');
		$this->assertStringNotContainsString('#time-entry-form .form-grid', $a11y);
		$this->assertGreaterThan($pickers, $hint);
		$this->assertLessThan($add, $hint);
	}

	public function testDaySummarySitsAfterBreaksAndBeforeTheActions(): void {
		$src = (string)file_get_contents(self::$template);
		$breaks = strpos($src, 'time-entry-form-fieldset--breaks');
		$summary = strpos($src, 'id="time-summary"');
		$actions = strpos($src, 'time-entry-form__actions');
		$this->assertNotFalse($breaks);
		$this->assertNotFalse($summary);
		$this->assertNotFalse($actions);
		$this->assertGreaterThan($breaks, $summary);
		$this->assertLessThan($actions, $summary);
	}

	public function testBreakRulesAreHelpTextAndOptionalMarkersAreGone(): void {
		$src = (string)file_get_contents(self::$template);
		$this->assertStringNotContainsString('time-entry-form__break-intro', $src);
		$this->assertStringNotContainsString('id="work-time-intro"', $src);
		$this->assertStringContainsString('time-entry-form__break-hint', $src);
		$this->assertStringNotContainsString("render('coffee'", $src);
		$this->assertStringNotContainsString('break-requirement-indicator', $src);
		$this->assertStringContainsString('id="break-submit-error"', $src);
		$this->assertStringNotContainsString('auto-break-panel-title', $src);
		$this->assertStringNotContainsString('auto-break-panel azc-callout', $src);
		$this->assertStringContainsString('id="auto-break-enabled"', $src);
		$this->assertStringContainsString('id="auto-break-toggle-state"', $src);

		foreach (["t('Breaks')", "t('Work location')", "t('Note')"] as $legend) {
			$at = strpos($src, $legend);
			$this->assertNotFalse($at, $legend);
			$snippet = substr($src, $at, 80);
			$this->assertStringNotContainsString('form-optional', $snippet, $legend);
		}

		$project = strpos($src, "t('Project')");
		$this->assertNotFalse($project);
		$this->assertStringContainsString('form-optional', substr($src, $project, 120));
	}
}
