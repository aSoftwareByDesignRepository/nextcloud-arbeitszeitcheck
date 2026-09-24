<?php

declare(strict_types=1);

/**
 * Live DB proof that org-wide sized user IN lists no longer trip Nextcloud's
 * Oracle 1000-expression guard (the log spam behind the 25GB nextcloud.log).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use DateTimeImmutable;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Support\QueryInChunker;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class QueryInChunkerOracleLimitIntegrationTest extends TestCase
{
	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = \OCP\Server::get(IDBConnection::class);
	}

	public function testAbsenceCountWithOverThousandUsersDoesNotTripOracleInLimit(): void
	{
		$userIds = [];
		for ($i = 0; $i < 1001; $i++) {
			$userIds[] = 'ical-oracle-guard-' . $i;
		}

		$mapper = new AbsenceMapper($this->db);
		$start = new DateTimeImmutable('2026-01-01');
		$end = new DateTimeImmutable('2026-12-31');

		$count = $mapper->countByUsersAndDateRange(
			$userIds,
			$start,
			$end,
			Absence::STATUS_APPROVED,
		);
		$this->assertSame(0, $count);

		$found = $mapper->findByUsersAndDateRange(
			$userIds,
			$start,
			$end,
			Absence::STATUS_APPROVED,
			null,
			10,
		);
		$this->assertSame([], $found);

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from('at_absences')
			->where(QueryInChunker::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY));

		$arrayParamCount = 0;
		foreach ($qb->getParameters() as $parameter) {
			if (!is_array($parameter)) {
				continue;
			}
			$arrayParamCount++;
			$this->assertLessThanOrEqual(
				QueryInChunker::MAX_EXPRESSIONS_PER_LIST,
				count($parameter),
				'IN chunk must stay under Oracle/Nextcloud 1000-expression limit',
			);
		}
		$this->assertGreaterThanOrEqual(3, $arrayParamCount);
		$this->assertSame(0, (int)$qb->executeQuery()->fetchOne());
	}
}
