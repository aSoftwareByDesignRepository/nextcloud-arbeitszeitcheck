<?php

declare(strict_types=1);

/**
 * Personal compliance totals for the status card.
 *
 * @copyright Copyright (c) 2026, Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

/**
 * Counts one person's violations. Rows that are not arrays are ignored
 * so a bad mapper result cannot inflate the totals.
 */
final class ComplianceReportSummary {
	/**
	 * @param list<mixed> $rows
	 * @return array{
	 *   total_violations: int,
	 *   unresolved: int,
	 *   by_type: array<string, int>,
	 *   by_severity: array<string, int>
	 * }
	 */
	public static function fromRows(array $rows): array {
		$report = [
			'total_violations' => 0,
			'unresolved' => 0,
			'by_type' => [],
			'by_severity' => [],
		];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$report['total_violations']++;
			if (empty($row['resolved'])) {
				$report['unresolved']++;
			}
			$type = isset($row['type']) ? (string)$row['type'] : '';
			if ($type !== '') {
				$report['by_type'][$type] = ($report['by_type'][$type] ?? 0) + 1;
			}
			$severity = isset($row['severity']) ? (string)$row['severity'] : '';
			if ($severity !== '') {
				$report['by_severity'][$severity] = ($report['by_severity'][$severity] ?? 0) + 1;
			}
		}
		ksort($report['by_type']);
		ksort($report['by_severity']);

		return $report;
	}
}
