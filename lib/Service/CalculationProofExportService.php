<?php

declare(strict_types=1);

/**
 * MH-17: Berechnungsnachweis — the calculation-proof export.
 *
 * Assembles, for one user and range, exactly what an auditor would
 * reconstruct: the effective-dated assignment windows with calc-relevant
 * model inputs, the holiday context, creditable absences, the resolved
 * `soll_segments` and the balance derivation (carry-in → worked − required
 * → closing). Every number comes from the same resolver/services the live
 * calculation consumes — this artefact must never produce different
 * figures than the app (NN-01).
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCP\IConfig;
use OCP\IL10N;

final class CalculationProofExportService
{
	public function __construct(
		private readonly OvertimeService $overtimeService,
		private readonly WorkingTimeModelAssignmentResolver $resolver,
		private readonly UserWorkingTimeModelMapper $assignments,
		private readonly WorkingTimeModelMapper $models,
		private readonly AbsenceMapper $absenceMapper,
		private readonly HolidayService $holidays,
		private readonly IConfig $config,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return array<string,mixed> the full proof document (JSON-safe)
	 */
	public function buildDocument(string $userId, \DateTime $start, \DateTime $end): array
	{
		$calc = $this->overtimeService->calculateOvertime($userId, $start, $end, true);

		$assignments = [];
		foreach ($this->assignments->findOverlapping($userId, $start, $end) as $row) {
			$model = null;
			try {
				$model = $this->models->find((int)$row->getWorkingTimeModelId());
			} catch (\Throwable) {
				$model = null;
			}
			$assignments[] = [
				'assignment_id' => (int)$row->getId(),
				'model_id' => (int)$row->getWorkingTimeModelId(),
				'model_name' => $model !== null
					? ($model->isPersonal() ? (string)$this->l10n->t('Individual contract') : $model->getName())
					: null,
				'start' => $row->getStartDate()?->format('Y-m-d'),
				'end' => $row->getEndDate()?->format('Y-m-d'),
				'vacation_days_per_year' => $row->getVacationDaysPerYear(),
				'model_inputs' => $model !== null ? [
					'type' => $model->getType(),
					'weekly_hours' => $model->getWeeklyHours(),
					'daily_hours' => $model->getDailyHours(),
					'work_days_per_week' => $model->getWorkDaysPerWeek(),
					'schedule_revision' => $model->getScheduleRevision(),
				] : null,
			];
		}

		$state = $this->holidays->resolveStateForUser($userId);
		$holidayRows = [];
		foreach ($this->holidays->getHolidaysForRange($state, $start, $end) as $holiday) {
			$holidayRows[] = [
				'date' => $holiday->getDate()?->format('Y-m-d'),
				'name' => $holiday->getName(),
			];
		}

		$absences = [];
		foreach ($this->absenceMapper->findByUserAndDateRange($userId, $start, $end) as $absence) {
			$absences[] = [
				'id' => (int)$absence->getId(),
				'type' => $absence->getType(),
				'start' => $absence->getStartDate()->format('Y-m-d'),
				'end' => $absence->getEndDate()->format('Y-m-d'),
				'days' => $absence->getDays(),
				'status' => $absence->getStatus(),
			];
		}

		return [
			'document' => 'berechnungsnachweis',
			'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
			'app_version' => (string)$this->config->getAppValue('arbeitszeitcheck', 'installed_version', ''),
			'contract_schema_version' => WorkingTimeContractClosureContributor::CONTRACT_SCHEMA_VERSION,
			'algorithm_version' => $calc['algorithm_version'] ?? Constants::OVERTIME_ALGORITHM_VERSION,
			'gap_policy' => $this->resolver->isGapPolicyStrict() ? 'strict' : 'lenient',
			'user_id' => $userId,
			'range' => ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')],
			'holiday_context' => [
				'state' => $state,
				'holidays' => $holidayRows,
			],
			'assignments' => $assignments,
			'soll_segments' => $calc['soll_segments'] ?? [],
			'totals' => [
				'total_hours_worked' => $calc['total_hours_worked'] ?? 0.0,
				'absence_credit_hours' => $calc['absence_credit_hours'] ?? 0.0,
				'soll_reduction_hours' => $calc['soll_reduction_hours'] ?? 0.0,
				'required_hours' => $calc['required_hours'] ?? 0.0,
				'required_hours_basis' => $calc['required_hours_basis'] ?? null,
				'overtime_hours' => $calc['overtime_hours'] ?? 0.0,
				'opening_balance_hours' => $calc['opening_balance_hours'] ?? null,
				'cumulative_balance_before' => $calc['cumulative_balance_before'] ?? null,
				'cumulative_balance_after' => $calc['cumulative_balance_after'] ?? null,
			],
			'absences' => $absences,
		];
	}

	/**
	 * Flat, sectioned CSV — one table per block, spreadsheet-safe via the
	 * caller's formula-injection guard.
	 */
	public function buildCsv(array $doc): string
	{
		$out = [];
		$put = function (array $cols) use (&$out): void {
			$out[] = implode(';', array_map(
				static fn ($v): string => \OCA\ArbeitszeitCheck\Support\CsvCellSanitizer::sanitize($v === null ? '' : (string)$v),
				$cols
			));
		};

		$put(['Berechnungsnachweis']);
		$put(['generated_at', $doc['generated_at']]);
		$put(['app_version', $doc['app_version']]);
		$put(['contract_schema_version', $doc['contract_schema_version']]);
		$put(['algorithm_version', $doc['algorithm_version']]);
		$put(['gap_policy', $doc['gap_policy']]);
		$put(['user_id', $doc['user_id']]);
		$put(['range', $doc['range']['from'], $doc['range']['to']]);
		$put([]);

		$put(['assignments']);
		$put(['assignment_id', 'model_id', 'model_name', 'start', 'end', 'vacation_days_per_year', 'type', 'weekly_hours', 'daily_hours', 'work_days_per_week', 'schedule_revision']);
		foreach ($doc['assignments'] as $a) {
			$m = $a['model_inputs'] ?? [];
			$put([
				$a['assignment_id'], $a['model_id'], $a['model_name'], $a['start'], $a['end'],
				$a['vacation_days_per_year'], $m['type'] ?? null, $m['weekly_hours'] ?? null,
				$m['daily_hours'] ?? null, $m['work_days_per_week'] ?? null, $m['schedule_revision'] ?? null,
			]);
		}
		$put([]);

		$put(['soll_segments']);
		$put(['start', 'end', 'model_id', 'model_name', 'basis', 'hours']);
		foreach ($doc['soll_segments'] as $s) {
			$put([$s['start'], $s['end'], $s['model_id'], $s['model_name'], $s['basis'], $s['hours']]);
		}
		$put([]);

		$put(['holiday_context']);
		$put(['state', $doc['holiday_context']['state']]);
		$put(['date', 'name']);
		foreach ($doc['holiday_context']['holidays'] as $h) {
			$put([$h['date'], $h['name']]);
		}
		$put([]);

		$put(['absences']);
		$put(['id', 'type', 'start', 'end', 'days', 'status']);
		foreach ($doc['absences'] as $a) {
			$put([$a['id'], $a['type'], $a['start'], $a['end'], $a['days'], $a['status']]);
		}
		$put([]);

		$put(['totals']);
		foreach ($doc['totals'] as $key => $value) {
			$put([$key, $value]);
		}

		return "\xEF\xBB\xBF" . implode("\r\n", $out) . "\r\n";
	}

	/** @return list<string> PDF body lines for MinimalPdfBuilder. */
	public function buildPdfLines(array $doc): array
	{
		$lines = [
			sprintf('User: %s', $doc['user_id']),
			sprintf('Range: %s - %s', $doc['range']['from'], $doc['range']['to']),
			sprintf('Generated: %s · App %s · Contract schema v%s · Gap policy: %s',
				$doc['generated_at'], $doc['app_version'], $doc['contract_schema_version'], $doc['gap_policy']),
			'',
			'Assignments:',
		];
		foreach ($doc['assignments'] as $a) {
			$lines[] = sprintf(
				'  #%s model %s (%s): %s → %s',
				$a['assignment_id'],
				$a['model_id'],
				$a['model_name'] ?? '—',
				$a['start'] ?? '—',
				$a['end'] ?? 'open',
			);
		}
		$lines[] = '';
		$lines[] = 'Soll segments:';
		foreach ($doc['soll_segments'] as $s) {
			$lines[] = sprintf(
				'  %s → %s · %s · basis %s · %.2fh',
				$s['start'], $s['end'], $s['model_name'] ?? '—', $s['basis'], (float)$s['hours'],
			);
		}
		$lines[] = '';
		$lines[] = sprintf('Holiday context: %s (%d holidays in range)', $doc['holiday_context']['state'], count($doc['holiday_context']['holidays']));
		$lines[] = '';
		$lines[] = 'Totals:';
		foreach ($doc['totals'] as $key => $value) {
			$lines[] = sprintf('  %s: %s', $key, $value === null ? '—' : (string)$value);
		}
		return $lines;
	}
}
