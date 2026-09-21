<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Util;

use OCA\ArbeitszeitCheck\Util\TemplateL10n;
use PHPUnit\Framework\TestCase;

class TemplateL10nTest extends TestCase {
	public function testPlaceholderArgumentsForPercentD(): void {
		$arguments = TemplateL10n::placeholderArguments('Date range must not exceed %d days.');
		$this->assertCount(1, $arguments);
		$this->assertIsInt($arguments[0]);
	}

	public function testPlaceholderArgumentsForPercentS(): void {
		$this->assertSame(
			['%s', '%s'],
			TemplateL10n::placeholderArguments('Delete "%s"? Members: %s'),
		);
	}

	public function testPlaceholderArgumentsForPlainMessage(): void {
		$this->assertSame([], TemplateL10n::placeholderArguments('Loading...'));
	}

	public function testPlaceholderArgumentsForPositionalPercentS(): void {
		$this->assertSame(
			['%1$s', '%2$s', '%3$s'],
			TemplateL10n::placeholderArguments('%1$s pending (%2$s h), %3$s already paid.'),
		);
	}

	public function testTranslatePreservesPositionalPlaceholdersForClientSideReplacement(): void {
		$l = $this->createMock(\OCP\IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $id, array $params): string {
			return vsprintf($id, $params);
		});

		$this->assertSame(
			'%1$s pending (%2$s h), %3$s already paid.',
			TemplateL10n::translate($l, '%1$s pending (%2$s h), %3$s already paid.'),
		);
	}

	public function testTranslatePreservesPositionalNumericPlaceholders(): void {
		$l = $this->createMock(\OCP\IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $id, array $params): string {
			return vsprintf($id, $params);
		});

		$this->assertSame(
			'%1$d–%2$d of %3$d entries',
			TemplateL10n::translate($l, '%1$d–%2$d of %3$d entries'),
		);
		$this->assertSame(
			'Page %1$d of %2$d',
			TemplateL10n::translate($l, 'Page %1$d of %2$d'),
		);
	}

	public function testTranslatePreservesSequentialNumericPlaceholders(): void {
		$l = $this->createMock(\OCP\IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $id, array $params): string {
			return vsprintf($id, $params);
		});

		$this->assertSame(
			'Date range must not exceed %d days. Please narrow the range.',
			TemplateL10n::translate($l, 'Date range must not exceed %d days. Please narrow the range.'),
		);
	}

	public function testTranslatePreservesOutlookSubscriptionClientTemplates(): void {
		$l = $this->createMock(\OCP\IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $id, array $params): string {
			return match ($id) {
				'Approved absences in the current window: %d' => vsprintf(
					'Genehmigte Abwesenheiten im aktuellen Fenster: %d',
					$params,
				),
				'Current window: %1$s through %2$s (last 3 months through next 12 months).' => vsprintf(
					'Aktuelles Fenster: %1$s bis %2$s (letzte 3 Monate bis nächste 12 Monate).',
					$params,
				),
				default => vsprintf($id, $params),
			};
		});

		$this->assertSame(
			'Genehmigte Abwesenheiten im aktuellen Fenster: %d',
			TemplateL10n::translate($l, 'Approved absences in the current window: %d'),
		);
		$this->assertSame(
			'Aktuelles Fenster: %1$s bis %2$s (letzte 3 Monate bis nächste 12 Monate).',
			TemplateL10n::translate($l, 'Current window: %1$s through %2$s (last 3 months through next 12 months).'),
		);
	}

	public function testTranslatePreservesLicenseBatchSeatSummary(): void {
		$l = $this->createMock(\OCP\IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $id, array $params): string {
			return vsprintf($id, $params);
		});

		$this->assertSame(
			'Assigned %1$d, skipped %2$d, failed %3$d.',
			TemplateL10n::translate($l, 'Assigned %1$d, skipped %2$d, failed %3$d.'),
		);
		$this->assertSame(
			'Assign mobile seats to %1$d people? %2$d seats remaining.',
			TemplateL10n::translate($l, 'Assign mobile seats to %1$d people? %2$d seats remaining.'),
		);
	}
}
