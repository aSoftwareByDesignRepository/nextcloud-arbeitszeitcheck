<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

final class LegacyBaselineScenario
{
	public static function load(string $path): array
	{
		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new \RuntimeException('Legacy baseline scenario is not readable.');
		}
		$scenario = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($scenario) || ($scenario['format'] ?? null) !== 1) {
			throw new \RuntimeException('Unsupported legacy baseline scenario format.');
		}
		$modes = array_column($scenario['vacationModes'] ?? [], 'key');
		if ($modes !== ['days', 'hours']) {
			throw new \RuntimeException('Legacy baseline scenario must define global vacation modes for days and hours.');
		}
		foreach ($scenario['vacationModes'] as $mode) {
			if (($mode['appConfig']['vacation_unit'] ?? null) !== $mode['key']) {
				throw new \RuntimeException('Legacy baseline vacation mode does not write the matching global unit.');
			}
		}
		self::requireValues($scenario['timeEntries'] ?? [], 'status', ['completed', 'paused', 'active']);
		self::requireValues($scenario['absences'] ?? [], 'status', ['pending', 'approved', 'rejected', 'cancelled']);
		self::requireValues($scenario['absences'] ?? [], 'type', ['vacation', 'sick', 'child_sick', 'home_office', 'business_trip', 'custom_bereavement']);
		self::requireValues($scenario['absences'] ?? [], 'unit', ['days', 'hours']);
		self::requireValues($scenario['months'] ?? [], 'status', ['open', 'closed', 'reopened']);
		self::requireValues($scenario['idempotency'] ?? [], 'channel', ['mobile', 'kiosk']);
		self::requireValues($scenario['idempotency'] ?? [], 'state', ['pending', 'completed']);
		self::requireValues($scenario['bridges'] ?? [], 'app', ['deskcheck', 'dutycheck']);
		self::assertUnique($scenario['users'] ?? [], 'key');
		self::assertUnique($scenario['models'] ?? [], 'key');
		self::assertUnique($scenario['timeEntries'] ?? [], 'key');
		self::assertUnique($scenario['absences'] ?? [], 'key');
		self::assertReferences($scenario);
		if (!self::contains($scenario['users'] ?? [], static fn (array $user): bool => ($user['deleted'] ?? false) === true && ($user['staleTeamRows'] ?? []) !== [])) {
			throw new \RuntimeException('Legacy baseline scenario must contain a deleted user with stale membership rows.');
		}
		if (!self::contains($scenario['timeEntries'] ?? [], static fn (array $entry): bool => ($entry['status'] ?? null) === 'completed' && ($entry['breaks'] ?? []) !== [])) {
			throw new \RuntimeException('Legacy baseline scenario must contain a completed entry with a break.');
		}
		return $scenario;
	}

	private static function requireValues(array $rows, string $field, array $required): void
	{
		$actual = array_values(array_unique(array_map(static fn (array $row): mixed => $row[$field] ?? null, $rows), SORT_REGULAR));
		$missing = array_diff($required, $actual);
		if ($missing !== []) {
			throw new \RuntimeException(sprintf('Legacy baseline scenario is missing %s values: %s.', $field, implode(', ', $missing)));
		}
	}

	private static function assertUnique(array $rows, string $field): void
	{
		$values = array_map(static fn (array $row): mixed => $row[$field] ?? null, $rows);
		if (in_array(null, $values, true) || count($values) !== count(array_unique($values, SORT_REGULAR))) {
			throw new \RuntimeException('Legacy baseline scenario contains missing or duplicate ' . $field . ' values.');
		}
	}

	private static function assertReferences(array $scenario): void
	{
		$users = array_column($scenario['users'], 'key', 'key');
		$models = array_column($scenario['models'], 'key', 'key');
		$types = array_column($scenario['absenceTypes'], 'code', 'code');
		$absences = array_column($scenario['absences'], 'key', 'key');
		foreach ($scenario['users'] as $user) {
			if (isset($user['model']) && !isset($models[$user['model']])) {
				throw new \RuntimeException('Legacy baseline scenario references an unknown model.');
			}
		}
		foreach ($scenario['timeEntries'] as $entry) {
			if (!isset($users[$entry['user'] ?? ''])) {
				throw new \RuntimeException('Legacy baseline scenario time entry references an unknown user.');
			}
		}
		foreach ($scenario['absences'] as $absence) {
			if (!isset($users[$absence['user'] ?? '']) || !isset($types[$absence['type'] ?? ''])) {
				throw new \RuntimeException('Legacy baseline scenario absence contains an unknown reference.');
			}
		}
		foreach ($scenario['substitutions'] as $substitution) {
			if (!isset($absences[$substitution['absence'] ?? '']) || !isset($users[$substitution['substitute'] ?? ''])) {
				throw new \RuntimeException('Legacy baseline scenario substitution contains an unknown reference.');
			}
		}
	}

	private static function contains(array $rows, callable $predicate): bool
	{
		foreach ($rows as $row) {
			if ($predicate($row)) {
				return true;
			}
		}
		return false;
	}
}
