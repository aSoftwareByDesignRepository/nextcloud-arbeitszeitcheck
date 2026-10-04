<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\OvertimeAdjustment;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper;
use OCA\ArbeitszeitCheck\Db\OvertimePayout;
use OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper;
use Test\TestCase;

/**
 * Atlas regression: QBMapper::insert() only writes fields that Entity::setter()
 * marked as updated, and setter() skips values equal to the PHP property
 * default. For entities whose NOT NULL columns carry no DB-side default
 * (at_ot_adj, at_ot_payout), writing reasonCode='custom', a zero balance or
 * bankMaxHours=100.0 silently dropped the column and MySQL aborted with
 * "doesn't have a default value" (SQLSTATE 1364). These tests insert rows
 * whose values are exactly the property defaults and re-read them.
 */
class OvertimeInsertDefaultsIntegrationTest extends TestCase
{
	private const TEST_USER = '__azc_atlas_ot_insert_defaults__';

	private OvertimeAdjustmentMapper $adjustmentMapper;

	private OvertimePayoutMapper $payoutMapper;

	protected function setUp(): void
	{
		parent::setUp();
		$this->adjustmentMapper = \OC::$server->get(OvertimeAdjustmentMapper::class);
		$this->payoutMapper = \OC::$server->get(OvertimePayoutMapper::class);
		$this->cleanupUserRows();
	}

	protected function tearDown(): void
	{
		$this->cleanupUserRows();
		parent::tearDown();
	}

	public function testAdjustmentWithDefaultReasonAndZeroBalancesPersists(): void
	{
		$entity = new OvertimeAdjustment();
		$entity->setUserId(self::TEST_USER);
		$entity->setCalendarYear(2020);
		$entity->setEffectiveOn(new \DateTime('2020-06-15'));
		$entity->setHoursDelta(0.0);
		$entity->setReasonCode(OvertimeAdjustment::REASON_CUSTOM);
		$entity->setNote('atlas default-value insert regression');
		$entity->setBalanceBefore(0.0);
		$entity->setBalanceAfter(0.0);
		$entity->setProcessedBy(self::TEST_USER);
		$entity->setCreatedAt(new \DateTime('2020-06-15 12:00:00'));

		foreach (['userId', 'calendarYear', 'effectiveOn', 'hoursDelta', 'reasonCode', 'balanceBefore', 'balanceAfter', 'processedBy', 'createdAt'] as $field) {
			$this->assertArrayHasKey(
				$field,
				$entity->getUpdatedFields(),
				"Required column {$field} must be marked for insert",
			);
		}

		$saved = $this->adjustmentMapper->insertAdjustment($entity);
		$this->assertGreaterThan(0, $saved->getId());

		$rows = $this->adjustmentMapper->findByUserAndYear(self::TEST_USER, 2020);
		$this->assertCount(1, $rows, 'Inserted adjustment must be re-readable');
		$this->assertSame(OvertimeAdjustment::REASON_CUSTOM, $rows[0]->getReasonCode());
		$this->assertSame(0.0, $rows[0]->getHoursDelta());
		$this->assertSame(0.0, $rows[0]->getBalanceBefore());
		$this->assertSame(0.0, $rows[0]->getBalanceAfter());
	}

	public function testPayoutWithDefaultBankMaxAndZeroBalancesPersists(): void
	{
		$entity = new OvertimePayout();
		$entity->setUserId(self::TEST_USER);
		$entity->setCalendarYear(2020);
		$entity->setCalendarMonth(6);
		$entity->setHoursPaid(0.0);
		$entity->setEffectiveBalanceBefore(0.0);
		$entity->setEffectiveBalanceAfter(0.0);
		$entity->setRawBalanceBefore(0.0);
		$entity->setBankMaxHours(100.0);
		$entity->setProcessedBy(self::TEST_USER);
		$entity->setCreatedAt(new \DateTime('2020-06-30 12:00:00'));

		$saved = $this->payoutMapper->insertPayout($entity);
		$this->assertGreaterThan(0, $saved->getId());

		$row = $this->payoutMapper->findByUserAndMonth(self::TEST_USER, 2020, 6);
		$this->assertNotNull($row, 'Inserted payout must be re-readable');
		$this->assertSame(100.0, $row->getBankMaxHours());
		$this->assertSame(0.0, $row->getEffectiveBalanceBefore());
		$this->assertNotNull($row->getCreatedAt());
	}

	private function cleanupUserRows(): void
	{
		$this->adjustmentMapper->deleteByUserId(self::TEST_USER);
		$this->payoutMapper->deleteByUserId(self::TEST_USER);
	}
}
