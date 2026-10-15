<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use Test\TestCase;

/**
 * DB-truth coverage for ComplianceViolationMapper::findByTimeEntryId —
 * the lookup a compliance report uses to attach violations to an entry.
 */
class ComplianceViolationMapperIntegrationTest extends TestCase
{
	private const TEST_USER = '__azc_viol_maint__';
	private const TEST_ENTRY_ID = 99887766;

	private ComplianceViolationMapper $mapper;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = \OC::$server->get(ComplianceViolationMapper::class);
		$this->mapper->deleteByUser(self::TEST_USER);
	}

	protected function tearDown(): void
	{
		$this->mapper->deleteByUser(self::TEST_USER);
		parent::tearDown();
	}

	public function testFindByTimeEntryIdReturnsLinkedViolations(): void
	{
		$linked = $this->mapper->createViolation(
			self::TEST_USER,
			'test_break_missing',
			'integration test violation',
			new \DateTime('2026-10-01'),
			self::TEST_ENTRY_ID,
			ComplianceViolation::SEVERITY_WARNING,
		);
		$this->assertGreaterThan(0, (int)$linked->getId());

		$rows = $this->mapper->findByTimeEntryId(self::TEST_ENTRY_ID);
		$ids = array_map(static fn (ComplianceViolation $v): int => (int)$v->getId(), $rows);
		$this->assertContains((int)$linked->getId(), $ids);
		foreach ($rows as $row) {
			$this->assertSame(self::TEST_ENTRY_ID, $row->getTimeEntryId());
		}
	}
}
