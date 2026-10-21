<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\TimePickerField;
use PHPUnit\Framework\TestCase;

class TimePickerFieldTest extends TestCase {
	public function testRendersTheSubmittedHiddenValueAndLabels(): void {
		$html = TimePickerField::render([
			'id' => 'entry-start-time',
			'name' => 'startTime',
			'value' => '09:00',
			'labelId' => 'entry-start-time-label',
			'describedBy' => 'entry-start-time-help entry-start-time-error',
			'hourLabel' => 'Start hour',
			'minuteLabel' => 'Start minute',
			'openLabel' => 'Choose start time',
			'required' => true,
			'allowEmpty' => false,
		]);

		$this->assertStringContainsString('data-azc-timepicker', $html);
		$this->assertStringContainsString('id="entry-start-time"', $html);
		$this->assertStringContainsString('name="startTime"', $html);
		$this->assertStringContainsString('value="09:00"', $html);
		$this->assertStringContainsString('data-allow-empty="0"', $html);
		$this->assertStringContainsString('data-required="1"', $html);
		$this->assertStringContainsString('data-open-label="Choose start time"', $html);
		$this->assertStringContainsString('required="required"', $html);
	}

	public function testDropsAValueThatIsNotAClockTime(): void {
		$html = TimePickerField::render([
			'id' => 'entry-end-time',
			'name' => 'endTime',
			'value' => '25:99"><script>',
			'hourLabel' => 'End hour',
			'minuteLabel' => 'End minute',
			'openLabel' => 'Choose end time',
		]);

		$this->assertStringContainsString('value=""', $html);
		$this->assertStringNotContainsString('script', $html);
		$this->assertStringNotContainsString('25:99', $html);
	}

	public function testEscapesLabelsAndKeepsABreakName(): void {
		$html = TimePickerField::render([
			'id' => 'break-0-start',
			'name' => 'breaks[0][start]',
			'value' => '',
			'hourLabel' => 'Hour "onclick',
			'minuteLabel' => 'Minute',
			'openLabel' => 'Open',
			'hiddenClass' => 'break-start-time',
			'breakIndex' => '0',
			'allowEmpty' => true,
			'clearLabel' => "Clear\ntime",
		]);

		$this->assertStringContainsString('name="breaks[0][start]"', $html);
		$this->assertStringContainsString('class="break-start-time"', $html);
		$this->assertStringContainsString('data-break-index="0"', $html);
		$this->assertStringContainsString('data-allow-empty="1"', $html);
		$this->assertStringContainsString('Hour &quot;onclick', $html);
		$this->assertStringNotContainsString("Clear\ntime", $html);
		$this->assertStringContainsString('data-clear-label="Clear time"', $html);
	}

	public function testRejectsAnUnsafeIdOrName(): void {
		$this->expectException(\InvalidArgumentException::class);
		TimePickerField::render([
			'id' => 'entry-start-time"',
			'name' => 'startTime',
			'hourLabel' => 'Hour',
			'minuteLabel' => 'Minute',
			'openLabel' => 'Open',
		]);
	}
}
