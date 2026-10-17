<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

use OCA\ArbeitszeitCheck\Migration\ArbeitszeitCheckTableCatalog;

final class LegacyBaselineSeedPlan
{
	private const SCENARIO_SECTIONS = [
		'users',
		'vacationModes',
		'models',
		'tariffs',
		'timeEntries',
		'absenceTypes',
		'absences',
		'months',
		'idempotency',
		'substitutions',
		'audit',
		'delivery',
		'bridges',
	];

	private const OPERATIONS = ['provision', 'insert', 'update', 'emit'];
	private const TRANSLATED_FIELDS = [
		'timeEntries.status',
		'absences.status',
		'absences.type',
		'months.status',
	];

	public static function load(string $path, array $scenario): array
	{
		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new \RuntimeException('Legacy baseline seed plan is not readable.');
		}
		$plan = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($plan) || ($plan['format'] ?? null) !== 1) {
			throw new \RuntimeException('Unsupported legacy baseline seed plan format.');
		}

		$sections = $plan['sections'] ?? null;
		if (!is_array($sections) || count($sections) !== count(self::SCENARIO_SECTIONS)) {
			throw new \RuntimeException('Legacy baseline seed plan must define every scenario section exactly once.');
		}
		$orders = [];
		$covered = [];
		foreach ($sections as $section) {
			$name = $section['scenario'] ?? null;
			$order = $section['order'] ?? null;
			$writes = $section['writes'] ?? null;
			if (!is_string($name) || !in_array($name, self::SCENARIO_SECTIONS, true) || !is_int($order) || !is_array($writes) || $writes === []) {
				throw new \RuntimeException('Legacy baseline seed plan section is malformed.');
			}
			if (isset($covered[$name]) || isset($orders[$order])) {
				throw new \RuntimeException('Legacy baseline seed plan contains duplicate sections or write orders.');
			}
			$covered[$name] = $section;
			$orders[$order] = true;
			foreach ($writes as $write) {
				$target = $write['target'] ?? null;
				$operation = $write['operation'] ?? null;
				if (!is_string($target) || !is_string($operation) || !in_array($operation, self::OPERATIONS, true)) {
					throw new \RuntimeException('Legacy baseline seed plan write is malformed.');
				}
				if (!in_array($target, ArbeitszeitCheckTableCatalog::TABLES, true) && !in_array($target, $plan['externalTargets'] ?? [], true)) {
					throw new \RuntimeException('Legacy baseline seed plan targets an unknown or retired table.');
				}
			}
		}
		if (array_diff(self::SCENARIO_SECTIONS, array_keys($covered)) !== []) {
			throw new \RuntimeException('Legacy baseline seed plan omits a scenario section.');
		}

		foreach (self::TRANSLATED_FIELDS as $fieldPath) {
			self::assertTranslations($scenario, $plan, $fieldPath);
		}
		self::assertReopenEvidence($covered['months'], $plan);
		return $plan;
	}

	private static function assertTranslations(array $scenario, array $plan, string $fieldPath): void
	{
		[$section, $field] = explode('.', $fieldPath, 2);
		$actual = array_values(array_unique(array_map(
			static fn (array $row): mixed => $row[$field] ?? null,
			$scenario[$section] ?? []
		), SORT_REGULAR));
		$translations = $plan['stateTranslations'][$fieldPath] ?? null;
		if (!is_array($translations) || array_diff($actual, array_keys($translations)) !== [] || array_diff(array_keys($translations), $actual) !== []) {
			throw new \RuntimeException('Legacy baseline seed plan has incomplete or stale state translations for ' . $fieldPath . '.');
		}
		foreach ($translations as $source => $target) {
			if (!is_string($source) || !is_string($target) || $target === '') {
				throw new \RuntimeException('Legacy baseline seed plan contains an invalid state translation.');
			}
			self::assertTranslatedValue($fieldPath, $target);
		}
	}

	private static function assertTranslatedValue(string $fieldPath, string $value): void
	{
		if ($fieldPath === 'absences.type') {
			$builtIns = ['vacation', 'sick_leave', 'personal_leave', 'parental_leave', 'special_leave', 'unpaid_leave', 'home_office', 'business_trip', 'child_sick', 'overtime_comp'];
			if (!in_array($value, $builtIns, true) && preg_match('/^custom_[a-z0-9_]+$/D', $value) !== 1) {
				throw new \RuntimeException('Legacy baseline seed plan translates ' . $fieldPath . ' to an invalid persisted value.');
			}
			return;
		}
		$allowed = match ($fieldPath) {
			'timeEntries.status' => ['active', 'completed', 'break', 'paused', 'pending_approval', 'rejected'],
			'absences.status' => ['pending', 'substitute_pending', 'substitute_declined', 'approved', 'rejected', 'cancelled'],
			'months.status' => ['open', 'finalized'],
		};
		if (!in_array($value, $allowed, true)) {
			throw new \RuntimeException('Legacy baseline seed plan translates ' . $fieldPath . ' to an invalid persisted value.');
		}
	}

	private static function assertReopenEvidence(array $monthsSection, array $plan): void
	{
		$targets = array_column($monthsSection['writes'] ?? [], 'target');
		$required = $plan['stateEvidence']['months.reopened']['required'] ?? [];
		if (array_diff($required, $targets) !== [] || ($plan['stateEvidence']['months.reopened']['openStatus'] ?? null) !== 'open' || ($plan['stateEvidence']['months.reopened']['finalizedStatus'] ?? null) !== 'finalized') {
			throw new \RuntimeException('Legacy baseline seed plan cannot prove reopened-month revision evidence.');
		}
	}
}
