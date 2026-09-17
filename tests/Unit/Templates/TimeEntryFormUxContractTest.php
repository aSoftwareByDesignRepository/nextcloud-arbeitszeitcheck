<?php

declare(strict_types=1);

/**
 * Template contract: manual form fields precede day summary; 5-min picker + free type.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

class TimeEntryFormUxContractTest extends TestCase
{
	private string $template;

	protected function setUp(): void
	{
		parent::setUp();
		$this->template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/time-entries.php');
	}

	public function testPrimaryFieldsPrecedeDaySummary(): void
	{
		$primary = strpos($this->template, 'time-entry-form__primary');
		$summary = strpos($this->template, 'id="time-summary"');
		$this->assertNotFalse($primary);
		$this->assertNotFalse($summary);
		$this->assertLessThan($summary, $primary, 'Day summary must come after primary date/time fields');
	}

	public function testMinuteOptionsUseTimePickerHelper(): void
	{
		$this->assertStringContainsString('TimePickerMinutes::options', $this->template);
		$this->assertStringNotContainsString('for ($m = 0; $m < 60; $m += 1)', $this->template);
	}

	public function testFreeTypeInputsPresent(): void
	{
		$this->assertStringContainsString('id="entry-start-time-type"', $this->template);
		$this->assertStringContainsString('id="entry-end-time-type"', $this->template);
	}

	public function testCriticalFieldHelpIsAlwaysVisible(): void
	{
		// Night-shift / format hints must not be buried only inside collapsed Tip details.
		$this->assertStringContainsString('id="entry-date-help"', $this->template);
		$this->assertStringContainsString('id="entry-start-time-help"', $this->template);
		$this->assertStringContainsString('id="entry-end-time-help"', $this->template);
		$this->assertStringContainsString('Night shifts', $this->template);
		$this->assertDoesNotMatchRegularExpression(
			'/<details class="azc-form-help-details">\s*<summary>[^<]*<\/summary>\s*<p id="entry-end-time-help"/s',
			$this->template
		);
	}

	public function testTimezoneCalloutOnlyWhenMismatch(): void
	{
		$this->assertStringContainsString('$tzMismatchForm', $this->template);
		$this->assertStringContainsString('$tzMismatchList', $this->template);
	}

	public function testManualCreateShowsJustificationWhenApprovalRequired(): void
	{
		$this->assertStringContainsString('$manualTimeEntriesRequireApproval', $this->template);
		$this->assertStringContainsString('id="entry-justification"', $this->template);
		$this->assertStringContainsString('name="justification"', $this->template);
		$this->assertStringContainsString('time-entry-form-fieldset--justification', $this->template);
		$this->assertStringContainsString('Why are you adding this time?', $this->template);
		$this->assertMatchesRegularExpression(
			'/\$mode === \'create\' && \$manualTimeEntriesRequireApproval[\s\S]*id="entry-justification"/',
			$this->template
		);
		// Callout alone is not enough — the field must be gated with the same flag.
		$calloutPos = strpos($this->template, 'New manual entries need manager approval');
		$fieldPos = strpos($this->template, 'id="entry-justification"');
		$this->assertNotFalse($calloutPos);
		$this->assertNotFalse($fieldPos);
		$this->assertLessThan($fieldPos, $calloutPos);
	}
}
