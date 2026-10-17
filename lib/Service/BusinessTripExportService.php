<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripSnapshot;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;

/**
 * Snapshot-driven exports (spec §9.10): CSV, PDF and the payroll DTO all
 * read the persisted day-calculations frozen at approval — never a live
 * recomputation. Export minutes are a separate projection and never alter
 * compliance or time-account values.
 */
final class BusinessTripExportService
{
	public function __construct(
		private readonly BusinessTripDayCalculationMapper $dayCalcs,
		private readonly BusinessTripSnapshotService $snapshots,
	) {
	}

	/**
	 * CSV export of the frozen day rows. Customer-controlled strings are
	 * formula-injection guarded; only snapshot data is emitted.
	 */
	public function csv(BusinessTrip $trip): string
	{
		$rows = $this->dayRows($trip);
		$fp = fopen('php://temp', 'r+');
		fputcsv($fp, ['local_date', 'day_kind', 'rule_set_id', 'presence_minutes', 'actual_work_minutes',
			'active_travel_minutes', 'passive_travel_minutes', 'compliance_minutes',
			'account_target_minutes', 'account_top_up_minutes', 'export_minutes', 'snapshot_hash']);
		foreach ($rows as $day) {
			fputcsv($fp, [
				$this->csvField((string)$day['localDate']),
				$this->csvField((string)$day['dayKind']),
				(int)$day['ruleSetId'],
				(int)$day['presenceMinutes'],
				(int)$day['actualWorkMinutes'],
				(int)$day['activeTravelMinutes'],
				(int)$day['passiveTravelMinutes'],
				(int)$day['complianceMinutes'],
				(int)$day['accountTargetMinutes'],
				(int)$day['accountTopUpMinutes'],
				(int)$day['exportMinutes'],
				$this->csvField((string)$day['snapshotHash']),
			]);
		}
		rewind($fp);
		$csv = (string)stream_get_contents($fp);
		fclose($fp);
		return $csv;
	}

	/** Payroll DTO: bounded top-up contribution only, keyed by snapshot. */
	public function payrollDto(BusinessTrip $trip): array
	{
		$snapshot = $this->snapshots->current($trip);
		$total = 0;
		$perDay = [];
		foreach ($this->dayRows($trip) as $day) {
			$total += (int)$day['accountTopUpMinutes'];
			$perDay[] = [
				'localDate' => (string)$day['localDate'],
				'topUpMinutes' => (int)$day['accountTopUpMinutes'],
				'exportMinutes' => (int)$day['exportMinutes'],
			];
		}
		return [
			'kind' => 'business_trip_top_up',
			'tripId' => (int)$trip->getId(),
			'userId' => (string)$trip->getUserId(),
			'snapshotHash' => $snapshot instanceof BusinessTripSnapshot ? (string)$snapshot->getPayloadHash() : null,
			'totalTopUpMinutes' => $total,
			'days' => $perDay,
		];
	}

	/** Simple PDF summary of the frozen calculation. */
	public function pdf(BusinessTrip $trip, string $title): string
	{
		$lines = [];
		foreach ($this->dayRows($trip) as $day) {
			$lines[] = sprintf(
				'%s  %s  work %d min  travel %d/%d min  compliance %d min  top-up %d min',
				$day['localDate'],
				$day['dayKind'],
				$day['actualWorkMinutes'],
				$day['activeTravelMinutes'],
				$day['passiveTravelMinutes'],
				$day['complianceMinutes'],
				$day['accountTopUpMinutes'],
			);
		}
		if ($lines === []) {
			$lines[] = '(no calculated days)';
		}
		return MinimalPdfBuilder::build($title, $lines);
	}

	/** @return list<array<string,mixed>> frozen day rows (never recomputed). */
	private function dayRows(BusinessTrip $trip): array
	{
		$rows = [];
		foreach ($this->dayCalcs->findForTrip((int)$trip->getId()) as $calc) {
			$result = json_decode((string)$calc->getResultJson(), true);
			$rows[] = is_array($result) ? $result : [
				'localDate' => $calc->getLocalDate()?->format('Y-m-d'),
				'dayKind' => '',
				'ruleSetId' => (int)$calc->getRuleSetId(),
				'presenceMinutes' => (int)$calc->getPresenceMinutes(),
				'actualWorkMinutes' => (int)$calc->getActualWorkMinutes(),
				'activeTravelMinutes' => (int)$calc->getActiveTravelMinutes(),
				'passiveTravelMinutes' => (int)$calc->getPassiveTravelMinutes(),
				'complianceMinutes' => (int)$calc->getComplianceMinutes(),
				'accountTargetMinutes' => (int)$calc->getAccountTargetMinutes(),
				'accountTopUpMinutes' => (int)$calc->getAccountTopUpMinutes(),
				'exportMinutes' => (int)$calc->getExportMinutes(),
				'snapshotHash' => (string)$calc->getSnapshotHash(),
			];
		}
		usort($rows, static fn (array $a, array $b): int => strcmp((string)$a['localDate'], (string)$b['localDate']));
		return $rows;
	}

	/** Guard spreadsheet formula injection for customer-controlled values. */
	private function csvField(string $value): string
	{
		if ($value === '') {
			return $value;
		}
		if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1 || in_array($value[0], ["\t", "\r", "\n", "\v"], true)) {
			return "'" . $value;
		}
		return $value;
	}
}
