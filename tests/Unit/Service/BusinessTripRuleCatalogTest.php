<?php

declare(strict_types=1);

/**
 * Rule-catalog invariants: draft-only mutation (immutable once live),
 * jurisdiction allowlist, validity window sanity, monotonic versioning,
 * atomic single-active activation under the catalog lock.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLog;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCA\ArbeitszeitCheck\Service\BusinessTripRuleCatalog;
use OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class BusinessTripRuleCatalogTest extends TestCase
{
	private const VALID_CONFIG = '{"schemaVersion":1,"dayBasis":"trip_presence","operation":"minimum","thresholds":[{"fromMinutes":0,"targetMinutes":480}],"accountTreatment":{"actualWorkFactorBps":10000,"activeTravelFactorBps":10000,"passiveTravelFactorBps":0,"workDuringTravelFactorBps":10000}}';

	private IDBConnection $db;
	private ILockingProvider $locks;
	private BusinessTripRuleSetMapper $ruleSets;
	private AuditLogMapper $audit;
	private BusinessTripRuleCatalog $catalog;
	/** @var list<string> */
	private array $released = [];
	/** @var list<array{user:string,action:string,entityType:string,entityId:?int,old:?array,new:?array,performedBy:?string}> */
	private array $audited = [];

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->locks = $this->createMock(ILockingProvider::class);
		$this->released = [];
		$this->audited = [];
		$this->locks->method('releaseLock')->willReturnCallback(function (string $key): void {
			$this->released[] = $key;
		});
		$this->ruleSets = $this->createMock(BusinessTripRuleSetMapper::class);
		$this->audit = $this->createMock(AuditLogMapper::class);
		$this->audit->method('logAction')->willReturnCallback(
			function (string $u, string $a, string $et, ?int $eid, ?array $old, ?array $new, ?string $pb): AuditLog {
				$this->audited[] = ['user' => $u, 'action' => $a, 'entityType' => $et, 'entityId' => $eid, 'old' => $old, 'new' => $new, 'performedBy' => $pb];
				return $this->createMock(AuditLog::class);
			}
		);
		$this->catalog = new BusinessTripRuleCatalog(
			$this->db,
			$this->locks,
			$this->ruleSets,
			new BusinessTripRuleConfigValidator(),
			$this->audit,
		);
	}

	public function testCreateDraftRejectsUnknownJurisdiction(): void
	{
		try {
			$this->catalog->createDraft('de-default', 'n', 'XX', self::VALID_CONFIG, null, null, 'admin');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(400, $e->httpStatus());
			$this->assertSame('invalid', $e->fields()['jurisdiction'] ?? null);
		}
	}

	public function testCreateDraftRejectsInvertedValidity(): void
	{
		try {
			$this->catalog->createDraft('de-default', 'n', 'DE', self::VALID_CONFIG,
				new \DateTime('2026-06-01'), new \DateTime('2026-01-01'), 'admin');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(400, $e->httpStatus());
			$this->assertSame('before_valid_from', $e->fields()['validTo'] ?? null);
		}
	}

	public function testCreateDraftRejectsInvalidConfig(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->catalog->createDraft('de-default', 'n', 'DE', '{"schemaVersion":1}', null, null, 'admin');
	}

	public function testCreateDraftAssignsNextVersion(): void
	{
		$existing = new BusinessTripRuleSet();
		$existing->setVersion(3);
		$this->ruleSets->method('findVersions')->with('de-default')->willReturn([$existing]);
		$this->ruleSets->expects($this->once())->method('insert')->willReturnCallback(
			static function (BusinessTripRuleSet $s): BusinessTripRuleSet {
				$s->setId(9);
				return $s;
			}
		);
		$saved = $this->catalog->createDraft('de-default', 'Default DE', 'DE', self::VALID_CONFIG, null, null, 'admin');
		$this->assertSame(4, (int)$saved->getVersion());
		$this->assertSame(BusinessTripRuleSet::STATUS_DRAFT, $saved->getStatus());
		$this->assertSame(hash('sha256', self::VALID_CONFIG), $saved->getConfigHash());
		$this->assertSame('admin', $saved->getCreatedBy());
		// Lock released on success.
		$this->assertContains(DbLockKeys::businessTripRuleCatalog(), $this->released);
		// Policy mutation writes an audit row naming actor + entity.
		$this->assertCount(1, $this->audited);
		$row = $this->audited[0];
		$this->assertSame('business_trip_rule_set_created', $row['action']);
		$this->assertSame('business_trip_rule_set', $row['entityType']);
		$this->assertSame(9, $row['entityId']);
		$this->assertSame('admin', $row['performedBy']);
		$this->assertSame('de-default', $row['new']['code'] ?? null);
	}

	public function testUpdateNonDraftIsImmutable(): void
	{
		$set = new BusinessTripRuleSet();
		$set->setStatus(BusinessTripRuleSet::STATUS_ACTIVE);
		$this->ruleSets->expects($this->never())->method('update');
		try {
			$this->catalog->updateDraft($set, self::VALID_CONFIG, 'n', 'admin');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('RULE_SET_IMMUTABLE', $e->getReasonCode());
			$this->assertSame(409, $e->httpStatus());
		}
	}

	public function testUpdateDraftRevalidatesConfig(): void
	{
		$set = new BusinessTripRuleSet();
		$set->setStatus(BusinessTripRuleSet::STATUS_DRAFT);
		$this->ruleSets->expects($this->never())->method('update');
		try {
			$this->catalog->updateDraft($set, '{broken', 'n', 'admin');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('RULE_SCHEMA_INVALID', $e->getReasonCode());
		}
	}

	public function testActivateMissingIs404(): void
	{
		$this->ruleSets->method('find')->willThrowException(new DoesNotExistException('x'));
		try {
			$this->catalog->activate(99, 'admin');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(404, $e->httpStatus());
		}
		$this->assertContains(DbLockKeys::businessTripRuleCatalog(), $this->released);
	}

	public function testActivateRefusesUnreviewedDraft(): void
	{
		// Spec §10.5: legal review is a release gate — never skippable.
		$target = new BusinessTripRuleSet();
		$target->setId(9);
		$target->setCode('de-default');
		$target->setStatus(BusinessTripRuleSet::STATUS_DRAFT);
		$this->ruleSets->method('find')->with(9)->willReturn($target);
		$this->db->expects($this->never())->method('beginTransaction');
		try {
			$this->catalog->activate(9, 'admin');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('RULE_REVIEW_REQUIRED', $e->getReasonCode());
			$this->assertSame(409, $e->httpStatus());
		}
		$this->assertContains(DbLockKeys::businessTripRuleCatalog(), $this->released);
	}

	public function testMarkReviewedOnlyOnDrafts(): void
	{
		$set = new BusinessTripRuleSet();
		$set->setId(9);
		$set->setStatus(BusinessTripRuleSet::STATUS_DRAFT);
		$set->setConfigHash(hash('sha256', self::VALID_CONFIG));
		$this->ruleSets->method('update')->willReturnArgument(0);
		$saved = $this->catalog->markReviewed($set, 'legal@corp', hash('sha256', self::VALID_CONFIG));
		$this->assertSame('legal@corp', $saved->getReviewedBy());
		$this->assertNotNull($saved->getReviewedAt());
		// The §10.5 legal gate lands its own audit row.
		$this->assertCount(1, $this->audited);
		$this->assertSame('business_trip_rule_set_reviewed', $this->audited[0]['action']);
		$this->assertSame('legal@corp', $this->audited[0]['performedBy']);

		$active = new BusinessTripRuleSet();
		$active->setStatus(BusinessTripRuleSet::STATUS_ACTIVE);
		try {
			$this->catalog->markReviewed($active, 'legal@corp', hash('sha256', self::VALID_CONFIG));
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('RULE_SET_IMMUTABLE', $e->getReasonCode());
		}
	}

	public function testMarkReviewedRejectsStaleConfigHash(): void
	{
		// The review must be stamped on the exact hash the reviewer saw —
		// a concurrent draft edit in between must fail closed (spec §10.5).
		$set = new BusinessTripRuleSet();
		$set->setStatus(BusinessTripRuleSet::STATUS_DRAFT);
		$set->setConfigHash(hash('sha256', 'other-config'));
		$this->ruleSets->expects($this->never())->method('update');
		try {
			$this->catalog->markReviewed($set, 'legal@corp', hash('sha256', self::VALID_CONFIG));
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('RULE_REVIEW_STALE', $e->getReasonCode());
			$this->assertSame(409, $e->httpStatus());
		}
		$this->assertNull($set->getReviewedAt());
		$this->assertNull($set->getReviewedBy());
	}

	public function testConfigChangeVoidsTheReview(): void
	{
		$set = new BusinessTripRuleSet();
		$set->setStatus(BusinessTripRuleSet::STATUS_DRAFT);
		$set->setConfigJson(self::VALID_CONFIG);
		$set->setConfigHash(hash('sha256', self::VALID_CONFIG));
		$set->setReviewedAt(new \DateTime());
		$set->setReviewedBy('legal@corp');
		$this->ruleSets->method('update')->willReturnArgument(0);

		$changed = str_replace('"targetMinutes":480', '"targetMinutes":540', self::VALID_CONFIG);
		$this->catalog->updateDraft($set, $changed, 'n', 'admin');
		$this->assertNull($set->getReviewedAt());
		$this->assertNull($set->getReviewedBy());
	}

	public function testActivateRetiresSiblingsAndCommits(): void
	{
		$target = new BusinessTripRuleSet();
		$target->setId(9);
		$target->setCode('de-default');
		$target->setStatus(BusinessTripRuleSet::STATUS_DRAFT);
		$target->setReviewedAt(new \DateTime());
		$target->setReviewedBy('legal@corp');
		$this->ruleSets->method('find')->with(9)->willReturn($target);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('e');
		$expr->method('neq')->willReturn('n');
		$result = $this->createMock(IResult::class);
		// One still-active sibling version (id 7) gets retired by the activate.
		$result->method('fetchAll')->willReturn([['id' => 7]]);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('select')->willReturn($qb);
		$qb->method('from')->willReturn($qb);
		$qb->method('update')->willReturn($qb);
		$qb->method('set')->willReturn($qb);
		$qb->method('where')->willReturn($qb);
		$qb->method('andWhere')->willReturn($qb);
		$qb->method('executeQuery')->willReturn($result);
		$qb->expects($this->once())->method('executeStatement')->willReturn(1);
		$this->db->method('getQueryBuilder')->willReturn($qb);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->ruleSets->expects($this->once())->method('update')->willReturnCallback(
			static fn (BusinessTripRuleSet $s): BusinessTripRuleSet => $s
		);

		$saved = $this->catalog->activate(9, 'admin');
		$this->assertSame(BusinessTripRuleSet::STATUS_ACTIVE, $saved->getStatus());
		$this->assertSame('admin', $saved->getActivatedBy());
		$this->assertContains(DbLockKeys::businessTripRuleCatalog(), $this->released);
		// Audit trail: the retired sibling + the activation itself.
		$actions = array_column($this->audited, 'action');
		$this->assertContains('business_trip_rule_set_retired', $actions);
		$this->assertContains('business_trip_rule_set_activated', $actions);
		$retired = $this->audited[array_search('business_trip_rule_set_retired', $actions, true)];
		$this->assertSame(7, $retired['entityId']);
		$this->assertSame(9, $retired['new']['supersededByRuleSetId'] ?? null);
		$activated = $this->audited[array_search('business_trip_rule_set_activated', $actions, true)];
		$this->assertSame(9, $activated['entityId']);
		$this->assertSame([7], $activated['new']['retiredRuleSetIds'] ?? null);
	}
}
