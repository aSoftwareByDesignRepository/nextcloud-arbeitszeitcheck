<?php

declare(strict_types=1);

/**
 * Integration test for the team hierarchy resolution behind report scopes
 * (customer-requests spec C): recursive descendant expansion, the C-R7
 * cycle guard + depth cap on corrupted trees, and member aggregation
 * across a subtree.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Team;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMember;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCP\IDBConnection;
use Test\TestCase;

final class TeamHierarchyIntegrationTest extends TestCase
{
	private TeamMapper $teamMapper;
	private TeamMemberMapper $teamMemberMapper;
	private IDBConnection $db;

	/** @var Team[] */
	private array $createdTeams = [];
	/** @var TeamMember[] */
	private array $createdMembers = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->teamMapper = \OC::$server->get(TeamMapper::class);
		$this->teamMemberMapper = \OC::$server->get(TeamMemberMapper::class);
		$this->db = \OC::$server->get(IDBConnection::class);

		if (!$this->db->tableExists('at_teams')) {
			self::markTestSkipped('at_teams table not present — run the app upgrade first.');
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->createdMembers as $member) {
			try {
				$this->teamMemberMapper->delete($member);
			} catch (\Throwable $e) {
				// best effort cleanup
			}
		}
		foreach ($this->createdTeams as $team) {
			try {
				$this->teamMapper->delete($team);
			} catch (\Throwable $e) {
				// best effort cleanup
			}
		}
		parent::tearDown();
	}

	private function createTeam(string $name, ?int $parentId): int
	{
		$team = new Team();
		$team->setName($name);
		$team->setParentId($parentId);
		$team->setCreatedAt(new \DateTime());
		$team = $this->teamMapper->insert($team);
		$this->createdTeams[] = $team;
		return (int)$team->getId();
	}

	private function createMember(int $teamId, string $userId): void
	{
		$member = new TeamMember();
		$member->setTeamId($teamId);
		$member->setUserId($userId);
		$member = $this->teamMemberMapper->insert($member);
		$this->createdMembers[] = $member;
	}

	public function testDescendantsResolveRecursivelyAcrossLevels(): void
	{
		$root = $this->createTeam('it-root-' . uniqid(), null);
		$child = $this->createTeam('it-child-' . uniqid(), $root);
		$grandchild = $this->createTeam('it-grand-' . uniqid(), $child);
		$sibling = $this->createTeam('it-sibling-' . uniqid(), $root);
		$unrelated = $this->createTeam('it-unrelated-' . uniqid(), null);

		$ids = $this->teamMapper->getIdsWithDescendants($root);
		sort($ids);
		$expected = [$root, $child, $grandchild, $sibling];
		sort($expected);
		$this->assertSame($expected, $ids);
		$this->assertNotContains($unrelated, $ids);

		// A leaf resolves to itself only.
		$this->assertSame([$grandchild], $this->teamMapper->getIdsWithDescendants($grandchild));
	}

	public function testCycleGuardTerminatesOnCorruptedTree(): void
	{
		// Direct writes bypass write-level cycle validation — exactly the
		// corrupted-tree scenario C-R7 must not loop forever on.
		$a = $this->createTeam('it-cycle-a-' . uniqid(), null);
		$b = $this->createTeam('it-cycle-b-' . uniqid(), $a);
		// Close the cycle: a's parent is b.
		$qb = $this->db->getQueryBuilder();
		$qb->update('at_teams')
			->set('parent_id', $qb->createNamedParameter($b, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($a, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$ids = $this->teamMapper->getIdsWithDescendants($a);
		sort($ids);
		$expected = [$a, $b];
		sort($expected);
		$this->assertSame($expected, $ids, 'cycle must terminate with exactly the loop members');
	}

	public function testDepthCapBoundsDeepChains(): void
	{
		$parent = $this->createTeam('it-deep-0-' . uniqid(), null);
		$chain = [$parent];
		for ($i = 1; $i <= Constants::TEAM_HIERARCHY_MAX_DEPTH + 3; $i++) {
			$parent = $this->createTeam('it-deep-' . $i . '-' . uniqid(), $parent);
			$chain[] = $parent;
		}

		$ids = $this->teamMapper->getIdsWithDescendants($chain[0]);
		$this->assertLessThanOrEqual(Constants::TEAM_HIERARCHY_MAX_DEPTH + 1, count($ids));
		$this->assertContains($chain[0], $ids);
	}

	public function testMemberUserIdsAggregateAcrossSubtree(): void
	{
		$root = $this->createTeam('it-mem-root-' . uniqid(), null);
		$child = $this->createTeam('it-mem-child-' . uniqid(), $root);
		$this->createMember($root, 'it-user-root-' . uniqid());
		$this->createMember($child, 'it-user-child-' . uniqid());
		$this->createMember($child, 'it-user-child2-' . uniqid());

		$uids = $this->teamMemberMapper->getMemberUserIdsByTeamIds(
			$this->teamMapper->getIdsWithDescendants($root)
		);
		$this->assertCount(3, $uids, 'members of root + descendant must aggregate');
	}
}
