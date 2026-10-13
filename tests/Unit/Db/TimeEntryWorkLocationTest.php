<?php

declare(strict_types=1);

/**
 * Work-location coverage for {@see TimeEntry} (GH #43):
 * entity validation must flag out-of-allowlist values, and getSummary()
 * must serialize the mark so web/API consumers see it.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use PHPUnit\Framework\TestCase;

class TimeEntryWorkLocationTest extends TestCase
{
	private function validEntry(): TimeEntry
	{
		$e = new TimeEntry();
		$e->setUserId('alice');
		$e->setStartTime(new \DateTime('2026-01-15T09:00:00+00:00'));
		$e->setEndTime(new \DateTime('2026-01-15T17:00:00+00:00'));
		$e->setStatus(TimeEntry::STATUS_COMPLETED);
		return $e;
	}

	public function testValidationAcceptsNullAndAllowlistValues(): void
	{
		foreach ([null, 'office', 'home', 'external'] as $loc) {
			$e = $this->validEntry();
			$e->setWorkLocation($loc);
			$this->assertArrayNotHasKey('workLocation', $e->validate(), var_export($loc, true));
		}
	}

	public function testValidationRejectsNonAllowlistValue(): void
	{
		$e = $this->validEntry();
		$e->setWorkLocation('remote');
		$errors = $e->validate();
		$this->assertArrayHasKey('workLocation', $errors);
	}

	public function testSummarySerializesWorkLocation(): void
	{
		$e = $this->validEntry();
		$e->setWorkLocation('home');
		$summary = $e->getSummary();
		$this->assertArrayHasKey('workLocation', $summary);
		$this->assertSame('home', $summary['workLocation']);
	}

	public function testSummarySerializesNullForLegacyRows(): void
	{
		$e = $this->validEntry();
		$summary = $e->getSummary();
		$this->assertArrayHasKey('workLocation', $summary);
		$this->assertNull($summary['workLocation']);
	}
}
