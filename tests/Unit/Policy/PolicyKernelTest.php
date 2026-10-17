<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Policy;

use OCA\ArbeitszeitCheck\Policy\EffectiveRange;
use OCA\ArbeitszeitCheck\Policy\IdempotencyKey;
use OCA\ArbeitszeitCheck\Policy\ImmutableTimeAccountContribution;
use OCA\ArbeitszeitCheck\Policy\MonthClosureContributor;
use OCA\ArbeitszeitCheck\Policy\MonthClosureContributorRegistry;
use OCA\ArbeitszeitCheck\Policy\PolicyCandidate;
use OCA\ArbeitszeitCheck\Policy\PolicyScope;
use OCA\ArbeitszeitCheck\Policy\PolicyScopeContext;
use OCA\ArbeitszeitCheck\Policy\PolicyScopeResolver;
use OCA\ArbeitszeitCheck\Policy\RevisionToken;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use PHPUnit\Framework\TestCase;

final class PolicyKernelTest extends TestCase
{
	public function testEffectiveRangeIsInclusiveAndRejectsReverseRange(): void
	{
		$range = new EffectiveRange(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
		self::assertTrue($range->contains(new \DateTimeImmutable('2026-01-01 23:59:59')));
		self::assertTrue($range->contains(new \DateTimeImmutable('2026-01-31')));
		self::assertFalse($range->contains(new \DateTimeImmutable('2026-02-01')));
		self::assertTrue($range->overlaps(new EffectiveRange(new \DateTimeImmutable('2026-01-31'), null)));

		$this->expectException(\InvalidArgumentException::class);
		new EffectiveRange(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-01-31'));
	}

	public function testResolverUsesScopeDepthPriorityAndStableIdOrder(): void
	{
		$resolver = new PolicyScopeResolver();
		$context = new PolicyScopeContext('u1', 'm1', ['c1'], ['parent' => 1, 'child' => 2]);
		$range = new EffectiveRange(new \DateTimeImmutable('2026-01-01'));
		$candidates = [
			new PolicyCandidate(9, PolicyScope::ORG, 'org', $range, 99, 0, 'org'),
			new PolicyCandidate(8, PolicyScope::TEAM, 'parent', $range, 99, 1, 'parent'),
			new PolicyCandidate(7, PolicyScope::TEAM, 'child', $range, 1, 2, 'child'),
			new PolicyCandidate(6, PolicyScope::POLICY_COHORT, 'c1', $range, 1, 0, 'cohort'),
			new PolicyCandidate(5, PolicyScope::USER, 'u1', $range, 0, 0, 'user'),
			new PolicyCandidate(4, PolicyScope::USER, 'other', $range, 100, 0, 'foreign'),
		];

		$resolution = $resolver->resolve($context, new \DateTimeImmutable('2026-06-01'), $candidates);
		self::assertSame('user', $resolution->winner?->value);
		self::assertSame('<redacted>', $resolution->trace?->scopeId);
		self::assertSame(5, $resolution->eligibleCount);

		$withoutUser = array_values(array_filter($candidates, static fn (PolicyCandidate $candidate): bool => $candidate->scope !== PolicyScope::USER));
		self::assertSame('cohort', $resolver->resolve($context, new \DateTimeImmutable('2026-06-01'), $withoutUser, false)->winner?->value);
	}

	public function testTeamResolutionUsesDeepestThenPriorityThenSmallestId(): void
	{
		$range = new EffectiveRange(new \DateTimeImmutable('2026-01-01'));
		$context = new PolicyScopeContext('u1', null, [], ['a' => 2, 'b' => 2, 'c' => 1]);
		$resolution = (new PolicyScopeResolver())->resolve($context, new \DateTimeImmutable('2026-01-01'), [
			new PolicyCandidate(5, PolicyScope::TEAM, 'a', $range, 3, 0, 'a'),
			new PolicyCandidate(4, PolicyScope::TEAM, 'b', $range, 3, 0, 'b'),
			new PolicyCandidate(1, PolicyScope::TEAM, 'c', $range, 99, 0, 'c'),
		]);
		self::assertSame('b', $resolution->winner?->value);
	}

	public function testRevisionTokenIsStrictAndFailClosed(): void
	{
		$token = RevisionToken::parse('12');
		$token->assertMatches(12);
		self::assertSame(12, $token->revision);
		foreach ([null, '', '0', '2.5', ' 2', '2e3'] as $invalid) {
			try {
				RevisionToken::parse($invalid);
				self::fail('Invalid revision token accepted.');
			} catch (\InvalidArgumentException) {
			}
		}
		$this->expectException(\DomainException::class);
		$token->assertMatches(13);
	}

	public function testIdempotencyPayloadHashIsMapOrderStableAndListOrderSensitive(): void
	{
		$key = IdempotencyKey::parse('request-1234');
		self::assertSame($key->payloadHash(['a' => 1, 'b' => ['x' => 2]]), $key->payloadHash(['b' => ['x' => 2], 'a' => 1]));
		self::assertNotSame($key->payloadHash(['items' => [1, 2]]), $key->payloadHash(['items' => [2, 1]]));
	}

	public function testContributionRejectsActualMinutesOutsideTimeEntry(): void
	{
		$hash = str_repeat('a', 64);
		$contribution = new ImmutableTimeAccountContribution('business_trip', '42', 1, 'u1', '2026-01-02', 0, 60, 480, $hash);
		self::assertSame('business_trip:42:1:2026-01-02', $contribution->deduplicationKey());
		self::assertSame(60, $contribution->creditMinutes());

		$this->expectException(\InvalidArgumentException::class);
		new ImmutableTimeAccountContribution('business_trip', '42', 1, 'u1', '2026-01-02', 1, 0, 0, $hash);
	}

	public function testLockKeysAreStableScopedAndMonthValidated(): void
	{
		self::assertSame(DbLockKeys::workforceUserMonth('u1', '2026-01'), DbLockKeys::workforceUserMonth('u1', '2026-01'));
		self::assertNotSame(DbLockKeys::workforceUserMonth('u1', '2026-01'), DbLockKeys::workforceUserMonth('u2', '2026-01'));
		self::assertSame(['catalog_schema', 'policy_cohort', 'user_month', 'domain_aggregate', 'linked_records', 'domain_ledger', 'month_closure'], DbLockKeys::ORDER);
		$this->expectException(\InvalidArgumentException::class);
		DbLockKeys::workforceUserMonth('u1', '2026-13');
	}

	public function testContributorRegistrySortsAndRejectsDuplicates(): void
	{
		$registry = new MonthClosureContributorRegistry();
		$registry->register($this->contributor('z_domain'));
		$registry->register($this->contributor('a_domain'));
		self::assertSame(['a_domain', 'z_domain'], $registry->ids());
		$this->expectException(\InvalidArgumentException::class);
		$registry->register($this->contributor('a_domain'));
	}

	private function contributor(string $id): MonthClosureContributor
	{
		$contributor = $this->createMock(MonthClosureContributor::class);
		$contributor->method('id')->willReturn($id);
		return $contributor;
	}
}
