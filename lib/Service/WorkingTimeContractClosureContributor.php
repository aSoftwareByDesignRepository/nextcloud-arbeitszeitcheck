<?php

declare(strict_types=1);

/**
 * Freezes the working-time contract state into month closures (MH-15/MH-16).
 *
 * The sealed `contract_context` documents "what was in force at finalize
 * time": the assignment windows overlapping the month, the calc-relevant
 * model inputs behind each window, the resolved Soll segments and the org
 * gap policy. Cosmetic model fields (name, description, timestamps) are
 * deliberately excluded so a label fix never raises `contract_diverged`
 * (R-16). `app_version` and `contract_schema_version` on the canonical
 * payload pin the algorithm stand that produced the evidence.
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Policy\MonthClosureContributor;

final class WorkingTimeContractClosureContributor implements MonthClosureContributor
{
	/** Bumped whenever the contract_context shape or comparison basis changes. */
	public const CONTRACT_SCHEMA_VERSION = 1;

	public const CONTRIBUTOR_ID = 'working_time_contract';

	public function __construct(
		private readonly WorkingTimeModelAssignmentResolver $resolver,
		private readonly UserWorkingTimeModelMapper $assignments,
		private readonly WorkingTimeModelMapper $models,
	) {
	}

	public function id(): string
	{
		return self::CONTRIBUTOR_ID;
	}

	public function featureFlag(): string
	{
		return 'working_time_contract_evidence_enabled';
	}

	/** @return list<string> Contract state never blocks a closure. */
	public function blockers(string $userId, string $month): array
	{
		return [];
	}

	/** @return array<string,mixed> */
	public function snapshot(string $userId, string $month): array
	{
		$context = $this->contractContext($userId, $month);
		return [
			'contract_context' => $context,
			'contract_context_hash' => self::hashContext($context),
		];
	}

	/**
	 * Assignment rows created during the sealed month — the audit-visible
	 * mutations that produced (or will produce) the windows.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function contributions(string $userId, string $month): array
	{
		[$start, $end] = self::monthRange($month);
		$rows = [];
		foreach ($this->assignments->findByUser($userId) as $row) {
			$created = $row->getCreatedAt();
			if (!$created instanceof \DateTimeInterface || $created < $start || $created > $end) {
				continue;
			}
			$rows[] = $this->windowRow($row);
		}
		usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
		return $rows;
	}

	public function canonicalHash(string $userId, string $month): string
	{
		return hash('sha256', MonthClosureCanonical::encode([
			'snapshot' => $this->snapshot($userId, $month),
			'contributions' => $this->contributions($userId, $month),
			'blockers' => $this->blockers($userId, $month),
		]));
	}

	/**
	 * Live contract context — used both to seal and to detect later
	 * divergence (contract_diverged) against the sealed hash.
	 *
	 * @return array<string,mixed>
	 */
	public function contractContext(string $userId, string $month): array
	{
		[$start, $end] = self::monthRange($month);
		$startDt = \DateTime::createFromImmutable($start);
		$endDt = \DateTime::createFromImmutable($end);

		$modelCache = [];
		$windows = [];
		foreach ($this->assignments->findOverlapping($userId, $startDt, $endDt) as $row) {
			$modelId = (int)$row->getWorkingTimeModelId();
			$windows[] = $this->windowRow($row) + [
				'model' => $this->modelInputs($modelId, $modelCache),
			];
		}
		usort($windows, static fn (array $a, array $b): int => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);

		$segments = [];
		foreach ($this->resolver->resolveRange($userId, $startDt, $endDt) as $seg) {
			$segments[] = [
				'start' => $seg->start->format('Y-m-d'),
				'end' => $seg->end->format('Y-m-d'),
				'assignment_id' => $seg->contract->assignmentId,
				'model_id' => $seg->contract->model !== null ? (int)$seg->contract->model->getId() : null,
				'basis' => $seg->contract->basis,
				'daily_hours' => number_format($seg->contract->dailyHours, 4, '.', ''),
				'weekly_hours' => number_format($seg->contract->weeklyHours, 4, '.', ''),
			];
		}

		return [
			'gap_policy' => $this->resolver->isGapPolicyStrict() ? 'strict' : 'lenient',
			'windows' => $windows,
			'segments' => $segments,
		];
	}

	/** Hash over windows + calc-relevant inputs + segments + policy (R-16). */
	public static function hashContext(array $context): string
	{
		return hash('sha256', MonthClosureCanonical::encode($context));
	}

	/**
	 * Live-vs-sealed divergence check for the `contract_diverged` badge.
	 */
	public function diverged(string $userId, string $month, string $sealedHash): bool
	{
		try {
			$live = self::hashContext($this->contractContext($userId, $month));
		} catch (\Throwable) {
			return false;
		}
		return !hash_equals($sealedHash, $live);
	}

	/**
	 * Calc-relevant model inputs only — name/description/timestamps are
	 * cosmetic and must not trigger divergence (R-16).
	 *
	 * @param array<int, array<string,mixed>|null> $cache
	 * @return array<string,mixed>|null
	 */
	private function modelInputs(int $modelId, array &$cache): ?array
	{
		if (!array_key_exists($modelId, $cache)) {
			$model = null;
			try {
				$model = $this->models->find($modelId);
			} catch (\Throwable) {
				$model = null;
			}
			$cache[$modelId] = $model instanceof WorkingTimeModel ? [
				'type' => (string)$model->getType(),
				'weekly_hours' => number_format($model->getWeeklyHours(), 4, '.', ''),
				'daily_hours' => number_format($model->getDailyHours(), 4, '.', ''),
				'work_days_per_week' => number_format($model->getWorkDaysPerWeek(), 4, '.', ''),
				'break_rules' => $model->getBreakRulesArray(),
				'overtime_rules' => $model->getOvertimeRulesArray(),
				'schedule_revision' => $model->getScheduleRevision(),
			] : null;
		}
		return $cache[$modelId];
	}

	/** @return array<string,mixed> */
	private function windowRow(UserWorkingTimeModel $row): array
	{
		return [
			'id' => (int)$row->getId(),
			'model_id' => (int)$row->getWorkingTimeModelId(),
			'start' => $row->getStartDate()?->format('Y-m-d'),
			'end' => $row->getEndDate()?->format('Y-m-d'),
			'vacation_days_per_year' => (float)$row->getVacationDaysPerYear(),
		];
	}

	/** @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} */
	private static function monthRange(string $month): array
	{
		if (preg_match('/^\d{4}-\d{2}$/D', $month) !== 1) {
			throw new \InvalidArgumentException('Invalid month period.');
		}
		$start = new \DateTimeImmutable($month . '-01');
		return [$start, $start->modify('last day of this month')];
	}
}
