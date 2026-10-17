<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMember;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicy;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper;
use OCA\ArbeitszeitCheck\Policy\PolicyScopeResolver;
use OCA\ArbeitszeitCheck\Service\WorkLocationCatalog;
use OCA\ArbeitszeitCheck\Service\WorkLocationDefaultResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WorkLocationDefaultResolverTest extends TestCase
{
	private WorkLocationDefaultPolicyMapper $policyMapper;
	private TeamMemberMapper $teamMemberMapper;
	private TeamMapper $teamMapper;
	private UserWorkingTimeModelMapper $userModelMapper;
	private WorkLocationDefaultResolver $resolver;

	/** @var list<WorkLocationDefaultPolicy> */
	private array $policies = [];
	private array $teams = [];
	private array $memberTeams = [];
	private ?int $modelId = null;

	protected function setUp(): void
	{
		$this->policies = [];
		$this->teams = [];
		$this->memberTeams = [];
		$this->modelId = null;

		$this->policyMapper = $this->createMock(WorkLocationDefaultPolicyMapper::class);
		$this->policyMapper->method('findEffectiveForScopes')->willReturnCallback(function (array $scopes, string $date) {
			$hits = [];
			foreach ($this->policies as $p) {
				foreach ($scopes as [$type, $id]) {
					if ($p->getScopeType() === $type && $p->getScopeId() === $id) {
						$from = $p->getEffectiveFrom()?->format('Y-m-d');
						$to = $p->getEffectiveTo()?->format('Y-m-d');
						if ($from !== null && $from <= $date && ($to === null || $to >= $date)) {
							$hits[] = $p;
						}
						break;
					}
				}
			}
			return $hits;
		});

		$this->teamMemberMapper = $this->createMock(TeamMemberMapper::class);
		$this->teamMemberMapper->method('findByUserId')->willReturnCallback(function () {
			$out = [];
			foreach ($this->memberTeams as $teamId) {
				$m = new TeamMember();
				$m->setTeamId($teamId);
				$out[] = $m;
			}
			return $out;
		});
		$this->teamMapper = $this->createMock(TeamMapper::class);
		$this->teamMapper->method('getParentMap')->willReturnCallback(fn () => $this->teams);
		$this->teamMapper->method('computeDepth')->willReturnCallback(function (int $teamId, array $parentMap) {
			// mirror of the real computeDepth for the fixture tree
			if (!array_key_exists($teamId, $parentMap)) {
				return -1;
			}
			$depth = 0;
			$current = $teamId;
			$seen = [$current => true];
			while (($parent = $parentMap[$current] ?? null) !== null) {
				if (isset($seen[$parent])) {
					return -1;
				}
				$depth++;
				$seen[$parent] = true;
				$current = $parent;
			}
			return $depth;
		});

		$this->userModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->userModelMapper->method('findByUserAndDate')->willReturnCallback(function () {
			if ($this->modelId === null) {
				return null;
			}
			$m = new UserWorkingTimeModel();
			$m->setWorkingTimeModelId($this->modelId);
			return $m;
		});

		$defMapper = $this->createMock(WorkLocationDefinitionMapper::class);
		$defMapper->method('findAllIndexed')->willReturn([]);
		$catalog = new WorkLocationCatalog($defMapper, new NullLogger());

		$this->resolver = new WorkLocationDefaultResolver(
			$this->policyMapper,
			$catalog,
			$this->teamMemberMapper,
			$this->teamMapper,
			$this->userModelMapper,
			new PolicyScopeResolver(),
		);
	}

	private function policy(int $id, string $scopeType, string $scopeId, string $code, int $priority = 0, string $from = '2020-01-01', ?string $to = null): WorkLocationDefaultPolicy
	{
		$p = new WorkLocationDefaultPolicy();
		$p->setId($id);
		$p->setScopeType($scopeType);
		$p->setScopeId($scopeId);
		$p->setLocationCode($code);
		$p->setPriority($priority);
		$p->setEffectiveFrom(new \DateTime($from));
		if ($to !== null) {
			$p->setEffectiveTo(new \DateTime($to));
		}
		$p->setRevision(1);
		$this->policies[] = $p;
		return $p;
	}

	public function testNoPoliciesReturnsNull(): void
	{
		self::assertNull($this->resolver->resolve('u1', new \DateTimeImmutable('2026-06-01')));
	}

	public function testUserScopeBeatsEverything(): void
	{
		$this->policy(1, 'org', 'org', 'office');
		$this->policy(2, 'user', 'u1', 'home');
		$this->memberTeams = [7];
		$this->teams = [7 => null];
		$this->policy(3, 'team', '7', 'external', 100);
		$this->modelId = 5;
		$this->policy(4, 'model', '5', 'external');

		$r = $this->resolver->resolve('u1', new \DateTimeImmutable('2026-06-01'));
		self::assertSame('home', $r['code']);
		self::assertSame('policy_user', $r['source']);
		self::assertSame(2, $r['policyId']);
	}

	public function testTeamBeatsModelAndOrg(): void
	{
		$this->policy(1, 'org', 'org', 'office');
		$this->modelId = 5;
		$this->policy(2, 'model', '5', 'office');
		$this->memberTeams = [7];
		$this->teams = [7 => null];
		$this->policy(3, 'team', '7', 'home');

		$r = $this->resolver->resolve('u1', new \DateTimeImmutable('2026-06-01'));
		self::assertSame('home', $r['code']);
		self::assertSame('policy_team', $r['source']);
	}

	public function testDeepestTeamWins(): void
	{
		// team 9 is a child of team 7 → depth 1 > depth 0
		$this->teams = [7 => null, 9 => 7];
		$this->memberTeams = [7, 9];
		$this->policy(1, 'team', '7', 'office');
		$this->policy(2, 'team', '9', 'home');

		$r = $this->resolver->resolve('u1', new \DateTimeImmutable('2026-06-01'));
		self::assertSame('home', $r['code']);
		self::assertSame(2, $r['policyId']);
	}

	public function testSameDepthHigherPriorityWins(): void
	{
		$this->teams = [7 => null, 8 => null];
		$this->memberTeams = [7, 8];
		$this->policy(1, 'team', '7', 'office', 0);
		$this->policy(2, 'team', '8', 'home', 50);

		$r = $this->resolver->resolve('u1', new \DateTimeImmutable('2026-06-01'));
		self::assertSame('home', $r['code']);
	}

	public function testSameDepthPrioritySmallestPolicyIdWins(): void
	{
		$this->teams = [7 => null, 8 => null];
		$this->memberTeams = [7, 8];
		$this->policy(9, 'team', '7', 'home', 0);
		$this->policy(3, 'team', '8', 'external', 0);

		$r = $this->resolver->resolve('u1', new \DateTimeImmutable('2026-06-01'));
		self::assertSame('external', $r['code']);
		self::assertSame(3, $r['policyId']);
	}

	public function testModelBeatsOrg(): void
	{
		$this->policy(1, 'org', 'org', 'office');
		$this->modelId = 5;
		$this->policy(2, 'model', '5', 'external');

		$r = $this->resolver->resolve('u1', new \DateTimeImmutable('2026-06-01'));
		self::assertSame('external', $r['code']);
		self::assertSame('policy_model', $r['source']);
	}

	public function testExpiredPolicyIsIneligible(): void
	{
		$this->policy(1, 'user', 'u1', 'home', 0, '2020-01-01', '2020-12-31');
		self::assertNull($this->resolver->resolve('u1', new \DateTimeImmutable('2026-06-01')));
	}

	public function testFuturePolicyIsIneligible(): void
	{
		$this->policy(1, 'user', 'u1', 'home', 0, '2030-01-01');
		self::assertNull($this->resolver->resolve('u1', new \DateTimeImmutable('2026-06-01')));
	}

	public function testInactiveLocationCodeCannotWin(): void
	{
		// Catalog knows 'home' but marks it inactive → the user policy for it
		// is ineligible and the org fallback wins.
		$defMapper = $this->createMock(WorkLocationDefinitionMapper::class);
		$inactiveHome = new \OCA\ArbeitszeitCheck\Db\WorkLocationDefinition();
		$inactiveHome->setCode('home');
		$inactiveHome->setBaseLabel('Home');
		$inactiveHome->setReportingCategory('home');
		$inactiveHome->setActive(false);
		$defMapper->method('findAllIndexed')->willReturn(['home' => $inactiveHome]);
		$catalog = new WorkLocationCatalog($defMapper, new NullLogger());
		$resolver = new WorkLocationDefaultResolver(
			$this->policyMapper, $catalog, $this->teamMemberMapper, $this->teamMapper,
			$this->userModelMapper, new PolicyScopeResolver(),
		);
		$this->policy(1, 'user', 'u1', 'home');
		$this->policy(2, 'org', 'org', 'office');
		$r = $resolver->resolve('u1', new \DateTimeImmutable('2026-06-01'));
		self::assertSame('office', $r['code']);
		self::assertSame('policy_org', $r['source']);
	}

	public function testExplainFallsBackToOffice(): void
	{
		$r = $this->resolver->explain('u1', new \DateTimeImmutable('2026-06-01'));
		self::assertSame('office', $r['code']);
		self::assertSame('fallback', $r['source']);
		self::assertNull($r['policyId']);
	}

	public function testTraceContainsSortedEligibleCandidates(): void
	{
		$this->teams = [7 => null];
		$this->memberTeams = [7];
		$this->policy(1, 'org', 'org', 'office');
		$this->policy(2, 'team', '7', 'external');
		$this->policy(3, 'user', 'u1', 'home');

		$r = $this->resolver->resolve('u1', new \DateTimeImmutable('2026-06-01'));
		self::assertSame(3, $r['trace']['eligible_count']);
		self::assertSame('user', $r['trace']['winner_scope']);
		self::assertSame([3, 2, 1], array_column($r['trace']['candidates'], 'policy_id'));
	}
}
