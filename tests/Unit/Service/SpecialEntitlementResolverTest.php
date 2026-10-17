<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\PolicyCohortMembership;
use OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicy;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicyMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementType;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMember;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\SpecialEntitlementException;
use OCA\ArbeitszeitCheck\Service\PolicyCohortResolver;
use OCA\ArbeitszeitCheck\Service\SpecialEntitlementResolver;
use OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class SpecialEntitlementResolverTest extends TestCase
{
	/** @var list<SpecialEntitlementPolicy> */
	private array $policies = [];
	private ?int $modelId = null;
	/** @var array<int,list<array{int,string,?string}>> */
	private array $cohortRows = [];
	/** @var list<int> */
	private array $teamIds = [];
	/** @var array<int,?int> */
	private array $teamParents = [];
	private ?\DateTimeImmutable $employmentStart = null;
	private SpecialEntitlementResolver $resolver;

	protected function setUp(): void
	{
		$this->policies = [];
		$this->modelId = null;
		$this->cohortRows = [];
		$this->teamIds = [];
		$this->teamParents = [];
		$this->employmentStart = null;

		$policyMapper = $this->createMock(SpecialEntitlementPolicyMapper::class);
		$policyMapper->method('findActiveForType')->willReturnCallback(function (int $typeId, string $asOf): array {
			return array_values(array_filter($this->policies, static function (SpecialEntitlementPolicy $p) use ($typeId, $asOf): bool {
				$from = $p->getEffectiveFrom()?->format('Y-m-d');
				$to = $p->getEffectiveTo()?->format('Y-m-d');
				return (int)$p->getEntitlementTypeId() === $typeId
					&& $from !== null
					&& $from <= $asOf
					&& ($to === null || $to >= $asOf);
			}));
		});

		$userModels = $this->createMock(UserWorkingTimeModelMapper::class);
		$userModels->method('findByUserAndDate')->willReturnCallback(function (): ?UserWorkingTimeModel {
			if ($this->modelId === null) {
				return null;
			}
			$model = new UserWorkingTimeModel();
			$model->setWorkingTimeModelId($this->modelId);
			return $model;
		});

		$teamMembers = $this->createMock(TeamMemberMapper::class);
		$teamMembers->method('findByUserId')->willReturnCallback(function (): array {
			return array_map(static function (int $teamId): TeamMember {
				$member = new TeamMember();
				$member->setTeamId($teamId);
				return $member;
			}, $this->teamIds);
		});

		$teams = $this->createMock(TeamMapper::class);
		$teams->method('getParentMap')->willReturnCallback(fn (): array => $this->teamParents);
		$teams->method('computeDepth')->willReturnCallback(function (int $teamId, array $parents): int {
			if (!array_key_exists($teamId, $parents)) {
				return -1;
			}
			$depth = 0;
			$current = $teamId;
			$seen = [$current => true];
			while (($parent = $parents[$current] ?? null) !== null) {
				if (isset($seen[$parent])) {
					return -1;
				}
				$depth++;
				$seen[$parent] = true;
				$current = $parent;
			}
			return $depth;
		});

		$appConfig = $this->createMock(IConfig::class);
		$appConfig->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default): string => $key === 'policy_cohorts_enabled' ? '1' : $default);
		$config = new WorkforceSuiteConfig($appConfig);
		$memberships = $this->createMock(PolicyCohortMembershipMapper::class);
		$memberships->method('findEffectiveForUser')->willReturnCallback(function (string $userId, string $date): array {
			$out = [];
			foreach ($this->cohortRows as [$cohortId, $from, $to]) {
				if ($from <= $date && ($to === null || $to >= $date)) {
					$membership = new PolicyCohortMembership();
					$membership->setCohortId($cohortId);
					$out[] = $membership;
				}
			}
			return $out;
		});

		$employment = $this->createMock(UserEmploymentSettingsService::class);
		$employment->method('getEmploymentStart')->willReturnCallback(fn (): ?\DateTimeImmutable => $this->employmentStart);

		$this->resolver = new SpecialEntitlementResolver(
			$policyMapper,
			$userModels,
			$teamMembers,
			$teams,
			new PolicyCohortResolver($config, $memberships),
			$employment,
		);
	}

	private function type(string $periodType = SpecialEntitlementType::PERIOD_CALENDAR_YEAR): SpecialEntitlementType
	{
		$type = new SpecialEntitlementType();
		$type->setId(11);
		$type->setCode('custom_recovery');
		$type->setPublicLabel('Recovery');
		$type->setAbsenceTypeCode('custom_recovery');
		$type->setUnit(SpecialEntitlementType::UNIT_DAYS);
		$type->setPeriodType($periodType);
		$type->setActive(true);
		$type->setVersion(1);
		return $type;
	}

	private function policy(int $id, string $scope, string $scopeId, string $operation, ?float $amount, int $priority = 0, string $from = '2020-01-01', ?string $to = null): SpecialEntitlementPolicy
	{
		$policy = new SpecialEntitlementPolicy();
		$policy->setId($id);
		$policy->setEntitlementTypeId(11);
		$policy->setScopeType($scope);
		$policy->setScopeId($scopeId);
		$policy->setOperation($operation);
		$policy->setAmount($amount);
		$policy->setPriority($priority);
		$policy->setEffectiveFrom(new \DateTime($from));
		$policy->setEffectiveTo($to !== null ? new \DateTime($to) : null);
		$policy->setVersion(1);
		$this->policies[] = $policy;
		return $policy;
	}

	public function testLayerOperationsAndInclusiveEndDate(): void
	{
		$this->policy(1, 'org', 'org', 'add', 2.0);
		$this->modelId = 7;
		$this->policy(2, 'model', '7', 'add', 3.0);
		$this->cohortRows[] = [41, '2020-01-01', null];
		$this->policy(3, 'policy_group', '41', 'replace', 8.0);
		$this->policy(4, 'user', 'u1', 'disable', null, 0, '2026-06-01', '2026-06-30');

		$inclusiveEnd = $this->resolver->resolve($this->type(), 'u1', new \DateTimeImmutable('2026-06-30'));
		self::assertSame(0.0, $inclusiveEnd['computedAmount']);
		self::assertFalse($inclusiveEnd['enabled']);

		$afterEnd = $this->resolver->resolve($this->type(), 'u1', new \DateTimeImmutable('2026-07-01'));
		self::assertSame(8.0, $afterEnd['computedAmount']);
		self::assertTrue($afterEnd['enabled']);
		self::assertSame('2026-01-01', $afterEnd['periodStart']->format('Y-m-d'));
		self::assertSame('2026-12-31', $afterEnd['periodEnd']->format('Y-m-d'));
		self::assertSame('2026-07-01', $afterEnd['asOfDate']->format('Y-m-d'));
		self::assertSame('1', $afterEnd['trace']['algorithm_version']);
		self::assertSame('2026-07-01', $afterEnd['trace']['as_of_date']);
		self::assertFalse($afterEnd['trace']['inputs_redacted']);
	}

	public function testL2ProtectedGroupBeatsOperationalTeamBeforeDepth(): void
	{
		$this->policy(1, 'org', 'org', 'add', 1.0);
		$this->cohortRows[] = [42, '2020-01-01', null];
		$this->teamIds = [9];
		$this->teamParents = [9 => 7, 7 => null];
		$this->policy(2, 'team', '9', 'replace', 9.0, 50);
		$this->policy(3, 'policy_group', '42', 'replace', 4.0, 50);
		$this->policy(4, 'policy_group', '42', 'add', 2.0, 40);

		$resolution = $this->resolver->resolve($this->type(), 'u1', new \DateTimeImmutable('2026-06-01'));
		self::assertSame(4.0, $resolution['computedAmount']);
		self::assertSame(3, $resolution['trace']['layers_evaluated'][2]['winner']['policy_id']);
	}

	public function testBackdatedOperationalTeamMembershipIsIgnored(): void
	{
		$this->teamIds = [7];
		$this->teamParents = [7 => null];
		$this->policy(1, 'team', '7', 'add', 10.0);

		$resolution = $this->resolver->resolve($this->type(), 'u1', new \DateTimeImmutable('2020-06-01'));
		self::assertSame(0.0, $resolution['computedAmount']);
		self::assertFalse($resolution['enabled']);
		self::assertFalse($resolution['trace']['team_history_reliable']);
	}

	public function testServiceYearUsesEmploymentAnniversaryAndRejectsPreEmployment(): void
	{
		$this->employmentStart = new \DateTimeImmutable('2020-02-29');
		$this->policy(1, 'org', 'org', 'add', 5.0);

		$resolution = $this->resolver->resolve($this->type(SpecialEntitlementType::PERIOD_SERVICE_YEAR), 'u1', new \DateTimeImmutable('2026-02-28'));
		self::assertSame('2026-02-28', $resolution['periodStart']->format('Y-m-d'));
		self::assertSame('2027-02-27', $resolution['periodEnd']->format('Y-m-d'));

		$this->expectException(SpecialEntitlementException::class);
		$this->resolver->resolve($this->type(SpecialEntitlementType::PERIOD_SERVICE_YEAR), 'u1', new \DateTimeImmutable('2020-02-28'));
	}

	public function testHigherPriorityWinsWithinSingleScopeLayer(): void
	{
		$this->modelId = 7;
		$this->policy(1, 'model', '7', 'add', 2.0, 10);
		$this->policy(2, 'model', '7', 'add', 5.0, 90);

		$resolution = $this->resolver->resolve($this->type(), 'u1', new \DateTimeImmutable('2026-06-01'));
		self::assertSame(5.0, $resolution['computedAmount']);
		self::assertSame(2, $resolution['trace']['layers_evaluated'][1]['winner']['policy_id']);
	}

	public function testDeeperOperationalTeamWinsL2OnPriorityTie(): void
	{
		$this->teamIds = [9, 3];
		$this->teamParents = [9 => 7, 7 => null, 3 => null];
		$this->policy(1, 'team', '9', 'replace', 9.0, 50);
		$this->policy(2, 'team', '3', 'replace', 4.0, 50);

		// Team membership is not historically versioned — teamDepths only
		// resolve for as-of dates >= today.
		$resolution = $this->resolver->resolve($this->type(), 'u1', new \DateTimeImmutable('2100-06-01'));
		self::assertSame(9.0, $resolution['computedAmount']);
		self::assertSame(1, $resolution['trace']['layers_evaluated'][2]['winner']['policy_id']);
	}

	public function testAddAccumulatesAcrossLayersInsteadOfReplacing(): void
	{
		$this->policy(1, 'org', 'org', 'add', 2.0);
		$this->modelId = 7;
		$this->policy(2, 'model', '7', 'add', 3.0);
		$this->policy(3, 'user', 'u1', 'add', 1.0);

		$resolution = $this->resolver->resolve($this->type(), 'u1', new \DateTimeImmutable('2026-06-01'));
		self::assertSame(6.0, $resolution['computedAmount']);
		self::assertTrue($resolution['enabled']);
	}

	public function testNoPolicyIsDisabledAndTraceIsDeterministic(): void
	{
		$first = $this->resolver->resolve($this->type(), 'u1', new \DateTimeImmutable('2026-01-15'));
		$second = $this->resolver->resolve($this->type(), 'u1', new \DateTimeImmutable('2026-01-15'));
		self::assertFalse($first['enabled']);
		self::assertSame(0.0, $first['computedAmount']);
		self::assertSame($first['trace'], $second['trace']);
		self::assertCount(4, $first['trace']['layers_evaluated']);
	}
}
