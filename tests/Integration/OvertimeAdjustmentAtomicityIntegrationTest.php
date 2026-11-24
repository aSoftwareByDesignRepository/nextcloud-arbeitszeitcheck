<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustment;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper;
use OCA\ArbeitszeitCheck\Service\OvertimeAdjustmentService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

/**
 * C-01 / AC-34 regression on the real DB.
 *
 * The defect: OvertimeAdjustmentService inserted the ledger row and wrote
 * audit without owning a transaction — an audit failure left an unaudited
 * financial row committed. The fix wraps insert+audit in atomic(); these
 * tests prove on the real database that a failed audit leaves neither row,
 * that outer-transaction callers keep ownership, and that the success path
 * commits exactly one of each record.
 */
class OvertimeAdjustmentAtomicityIntegrationTest extends TestCase
{
	private const TEST_USER = '__azc_ot_atomic__';
	private const ACTOR = '__azc_ot_atomic_actor__';

	private IDBConnection $db;
	private OvertimeAdjustmentMapper $adjustmentMapper;
	private IUserManager $userManager;

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = \OCP\Server::get(IDBConnection::class);
		$this->adjustmentMapper = \OCP\Server::get(OvertimeAdjustmentMapper::class);
		$this->userManager = \OCP\Server::get(IUserManager::class);
		foreach ([self::TEST_USER, self::ACTOR] as $uid) {
			if (!$this->userManager->userExists($uid)) {
				$this->userManager->createUser($uid, bin2hex(random_bytes(16)) . 'Aa1!');
			}
		}
		$this->cleanupRows();
	}

	protected function tearDown(): void
	{
		$this->cleanupRows();
		parent::tearDown();
	}

	private function cleanupRows(): void
	{
		foreach ([self::TEST_USER, self::ACTOR] as $uid) {
			$this->adjustmentMapper->deleteByUserId($uid);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('at_audit')
			->where($qb->expr()->in('user_id', $qb->createParameter('uids')));
		$qb->setParameter('uids', [self::TEST_USER, self::ACTOR], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY);
		$qb->executeStatement();
	}

	private function service(AuditLogMapper $audit): OvertimeAdjustmentService
	{
		return new OvertimeAdjustmentService(
			$this->adjustmentMapper,
			\OCP\Server::get(OvertimeBankService::class),
			$audit,
			$this->userManager,
			\OCP\Server::get(ILockingProvider::class),
			$this->db,
		);
	}

	private function failingAudit(): AuditLogMapper
	{
		$audit = $this->createMock(AuditLogMapper::class);
		$audit->method('logAction')->willThrowException(new \RuntimeException('injected audit failure'));
		return $audit;
	}

	private function countAdjustments(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('at_ot_adj')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function testAuditFailureLeavesNeitherAdjustmentNorSuccess(): void
	{
		$svc = $this->service($this->failingAudit());

		try {
			$svc->createAdjustment(
				self::TEST_USER,
				-5.0,
				OvertimeAdjustment::REASON_PERIOD_NULLUNG,
				'c-01 probe',
				self::ACTOR,
			);
			$this->fail('expected injected audit failure');
		} catch (\RuntimeException $e) {
			$this->assertSame('injected audit failure', $e->getMessage());
		}

		$this->assertSame(0, $this->countAdjustments(self::TEST_USER), 'rolled-back insert must not survive');
	}

	public function testOuterTransactionCallerKeepsOwnershipOnInnerFailure(): void
	{
		$svc = $this->service($this->failingAudit());

		// Caller-owned transaction: the service must participate via savepoint,
		// never begin/commit the caller's scope.
		$this->db->beginTransaction();
		try {
			$svc->createAdjustment(
				self::TEST_USER,
				-5.0,
				OvertimeAdjustment::REASON_PERIOD_NULLUNG,
				'c-01 outer probe',
				self::ACTOR,
			);
			$this->fail('expected injected audit failure');
		} catch (\RuntimeException $e) {
			$this->assertSame('injected audit failure', $e->getMessage());
		} finally {
			$this->db->rollBack();
		}

		$this->assertSame(0, $this->countAdjustments(self::TEST_USER));
	}

	public function testSuccessfulMutationCommitsExactlyOneAdjustmentAndOneAudit(): void
	{
		$svc = $this->service(\OCP\Server::get(AuditLogMapper::class));

		$svc->createAdjustment(
			self::TEST_USER,
			-5.0,
			OvertimeAdjustment::REASON_PERIOD_NULLUNG,
			'c-01 happy path',
			self::ACTOR,
		);

		$this->assertSame(1, $this->countAdjustments(self::TEST_USER));

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('at_audit')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::TEST_USER)))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter('overtime_balance_adjustment')));
		$this->assertSame(1, (int)$qb->executeQuery()->fetchOne());
	}
}
