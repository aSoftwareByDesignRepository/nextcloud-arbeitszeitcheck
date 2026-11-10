<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Support\ViolationLabels;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The label helper is the single source for type/severity wording on every
 * compliance surface (dashboard, standalone page, filter selects).
 */
final class ViolationLabelsTest extends TestCase {
	private IL10N $l10n;

	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
	}

	public function testEveryCanonicalTypeHasALabelAndNoExtraTypesSneakIn(): void {
		foreach (ComplianceViolation::TYPES as $type) {
			// Labeled types never fall through to the raw-key default —
			// every canonical key produces the mapped English source string.
			self::assertNotSame(
				$type,
				ViolationLabels::type($this->l10n, $type),
				"type '$type' fell back to its raw key"
			);
		}
		self::assertSame(
			[
				'missing_break',
				'excessive_working_hours',
				'insufficient_rest_period',
				'daily_hours_limit_exceeded',
				'weekly_hours_limit_exceeded',
				'weekly_absolute_hours_exceeded',
				'night_work',
				'sunday_work',
				'holiday_work',
				'missing_presence',
			],
			ComplianceViolation::TYPES
		);
	}

	public function testUnknownTypeFallsBackToTheRawKeyNeverBlank(): void {
		self::assertSame('totally_new_type', ViolationLabels::type($this->l10n, 'totally_new_type'));
		self::assertSame('', ViolationLabels::type($this->l10n, ''));
	}

	public function testSeverityLabelsAndBadgesMatchTheBadgeVariants(): void {
		self::assertSame('High', ViolationLabels::severity($this->l10n, 'error'));
		self::assertSame('Medium', ViolationLabels::severity($this->l10n, 'warning'));
		self::assertSame('Low', ViolationLabels::severity($this->l10n, 'info'));
		self::assertSame('unknown-sev', ViolationLabels::severity($this->l10n, 'unknown-sev'));

		self::assertSame('error', ViolationLabels::severityBadge('error'));
		self::assertSame('warning', ViolationLabels::severityBadge('warning'));
		self::assertSame('primary', ViolationLabels::severityBadge('info'));
		self::assertSame('primary', ViolationLabels::severityBadge(''));
	}

	public function testTypeMapCoversExactlyTheCanonicalTypes(): void {
		$map = ViolationLabels::typeMap($this->l10n);
		self::assertSame(ComplianceViolation::TYPES, array_keys($map));
		self::assertContainsOnly('string', $map);
		foreach ($map as $key => $label) {
			self::assertSame(ViolationLabels::type($this->l10n, $key), $label);
		}
	}

	public function testEntityValidationAcceptsExactlyTheCanonicalLists(): void {
		// The validator and the TYPES/SEVERITIES constants must agree —
		// a type the filter offers but the entity rejects would be a trap.
		$row = new ComplianceViolation();
		$row->setUserId('u');
		$row->setViolationType('missing_break');
		$row->setSeverity('error');
		$row->setDescription('d');
		$row->setDate(new \DateTime('2026-10-21'));
		self::assertSame([], $row->validate());

		$row->setViolationType('not_a_type');
		self::assertArrayHasKey('violationType', $row->validate());

		$row->setViolationType('missing_break');
		$row->setSeverity('critical');
		self::assertArrayHasKey('severity', $row->validate());
	}
}
