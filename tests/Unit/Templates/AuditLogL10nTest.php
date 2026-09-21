<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use OCA\ArbeitszeitCheck\Util\TemplateL10n;
use PHPUnit\Framework\TestCase;

/**
 * Guards audit-log.php from Internal Server Error when building auditLogViewerL10n.
 */
class AuditLogL10nTest extends TestCase {
	/** @return array<string, string> */
	private function auditLogViewerMessageIds(): array {
		return [
			'Loading…' => 'Loading…',
			'Error loading audit logs' => 'Error loading audit logs',
			'Failed to load audit logs. Please try again.' => 'Failed to load audit logs. Please try again.',
			'No audit log entries found' => 'No audit log entries found',
			'Date and time' => 'Date and time',
			'Employee' => 'Employee',
			'Action' => 'Action',
			'What was changed' => 'What was changed',
			'Who did it' => 'Who did it',
			'0 entries' => '0 entries',
			'%1$d–%2$d of %3$d entries' => '%1$d–%2$d of %3$d entries',
			'Page %1$d of %2$d' => 'Page %1$d of %2$d',
			'Previous' => 'Previous',
			'Next' => 'Next',
			'Start date must be before or equal to end date' => 'Start date must be before or equal to end date',
			'Please enter valid dates in dd.mm.yyyy format.' => 'Please enter valid dates in dd.mm.yyyy format.',
			'Date range must not exceed %d days. Please narrow the range.' => 'Date range must not exceed %d days. Please narrow the range.',
			'User filter is too long.' => 'User filter is too long.',
			'Offline sync' => 'Offline sync',
			'Occurred: %s' => 'Occurred: %s',
			'Synced: %s' => 'Synced: %s',
		];
	}

	public function testAuditLogViewerL10nMapBuildsWithoutVsprintfCrash(): void {
		$l = $this->createMock(\OCP\IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $id, array $params): string {
			return vsprintf($id, $params);
		});

		$map = [];
		foreach ($this->auditLogViewerMessageIds() as $messageId) {
			$map[$messageId] = TemplateL10n::translate($l, $messageId);
		}

		$this->assertSame('%1$d–%2$d of %3$d entries', $map['%1$d–%2$d of %3$d entries']);
		$this->assertSame('Page %1$d of %2$d', $map['Page %1$d of %2$d']);
		$this->assertSame(
			'Date range must not exceed %d days. Please narrow the range.',
			$map['Date range must not exceed %d days. Please narrow the range.'],
		);
	}
}
