<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

final class PolicyScopeResolver
{
	public function resolve(PolicyScopeContext $context, \DateTimeInterface $asOfDate, array $candidates, bool $redactedTrace = true): PolicyResolution
	{
		$eligible = array_values(array_filter(
			$candidates,
			static fn (mixed $candidate): bool => $candidate instanceof PolicyCandidate
				&& $candidate->effectiveRange->contains($asOfDate)
				&& $context->matches($candidate),
		));
		usort($eligible, static function (PolicyCandidate $a, PolicyCandidate $b) use ($context): int {
			$scope = $b->scope->precedence() <=> $a->scope->precedence();
			if ($scope !== 0) {
				return $scope;
			}
			if ($a->scope === PolicyScope::TEAM) {
				$depth = ($context->teamDepths[$b->scopeId] ?? $b->depth) <=> ($context->teamDepths[$a->scopeId] ?? $a->depth);
				if ($depth !== 0) {
					return $depth;
				}
			}
			$priority = $b->priority <=> $a->priority;
			return $priority !== 0 ? $priority : $a->id <=> $b->id;
		});
		$winner = $eligible[0] ?? null;
		return new PolicyResolution(
			$winner,
			$winner === null ? null : PolicyWinnerTrace::fromCandidate($winner, $redactedTrace),
			count($eligible),
			$eligible,
		);
	}
}
