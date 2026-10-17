<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

final class LegacyBaselineSeedCompiler
{
	public static function compile(array $scenario, array $plan, array $capabilities): array
	{
		$operations = [];
		foreach (self::orderedSections($plan) as $section) {
			$name = $section['scenario'];
			if (!self::sectionSupported($name, $capabilities)) {
				continue;
			}
			$operations = array_merge($operations, match ($name) {
				'users' => self::users($scenario, $capabilities),
				'vacationModes' => self::vacationModes($scenario, $capabilities),
				'tariffs' => self::tariffs($scenario),
				'models' => self::models($scenario, $capabilities),
				'timeEntries' => self::timeEntries($scenario, $plan, $capabilities),
				'absenceTypes' => self::absenceTypes($scenario),
				'absences' => self::absences($scenario, $plan, $capabilities),
				'substitutions' => self::substitutions($scenario, $plan, $capabilities),
				'months' => self::months($scenario, $plan),
				'idempotency' => self::idempotency($scenario, $capabilities),
				'audit' => self::audit($scenario, $plan, $capabilities),
				'delivery' => self::delivery($scenario, $plan, $capabilities),
				'bridges' => self::bridges($scenario),
			});
		}
		self::assertPlanCoverage($operations, $plan, $capabilities);
		return [
			'format' => 1,
			'clock' => $scenario['clock'],
			'capabilities' => $capabilities,
			'operations' => $operations,
			'hash' => LegacyBaselineCanonicalizer::hash($operations),
		];
	}

	private static function orderedSections(array $plan): array
	{
		$sections = $plan['sections'];
		usort($sections, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
		return $sections;
	}

	private static function uid(string $key): string
	{
		return 'azc_' . $key;
	}

	private static function users(array $scenario, array $capabilities): array
	{
		$operations = [];
		$teams = [];
		foreach ($scenario['users'] as $user) {
			$operations[] = self::op('users', 'external.nextcloud_users', 'provision', $user['key'], [
				'uid' => self::uid($user['key']),
				'deleted' => $user['deleted'] ?? false,
			]);
			if (($capabilities['teams'] ?? false) !== true) {
				continue;
			}
			foreach (array_merge($user['teams'] ?? [], $user['staleTeamRows'] ?? [], $user['manages'] ?? []) as $team) {
				$teams[$team] = true;
			}
		}
		if (($capabilities['teams'] ?? false) !== true) {
			return $operations;
		}
		$sort = 0;
		foreach (array_keys($teams) as $team) {
			$operations[] = self::op('users', 'at_teams', 'insert', 'team:' . $team, [
				'name' => $team,
				'sort_order' => $sort++,
				'created_at' => $scenario['clock'],
			]);
		}
		foreach ($scenario['users'] as $user) {
			foreach (array_merge($user['teams'] ?? [], $user['staleTeamRows'] ?? []) as $team) {
				$operations[] = self::op('users', 'at_team_members', 'insert', 'member:' . $user['key'] . ':' . $team, [
					'user_id' => self::uid($user['key']),
					'team_id' => 'ref:team:' . $team,
				]);
			}
			foreach ($user['manages'] ?? [] as $team) {
				$operations[] = self::op('users', 'at_team_managers', 'insert', 'manager:' . $user['key'] . ':' . $team, [
					'user_id' => self::uid($user['key']),
					'team_id' => 'ref:team:' . $team,
				]);
			}
		}
		return $operations;
	}

	private static function vacationModes(array $scenario, array $capabilities): array
	{
		$operations = [];
		foreach ($scenario['vacationModes'] as $mode) {
			if ($mode['key'] === 'hours' && ($capabilities['vacationDurationHours'] ?? false) !== true) {
				continue;
			}
			$operations[] = self::op('vacationModes', 'external.appconfig', 'emit', $mode['key'], $mode['appConfig']);
		}
		return $operations;
	}

	private static function tariffs(array $scenario): array
	{
		$operations = [];
		foreach ($scenario['tariffs'] as $tariff) {
			$operations[] = self::op('tariffs', 'at_tariff_rule_sets', 'insert', 'tariff:' . $tariff['key'], [
				'tariff_code' => $tariff['key'],
				'version' => (string)$tariff['revision'],
				'jurisdiction' => 'legacy-fixture',
				'valid_from' => '2026-01-01',
				'status' => ($tariff['active'] ?? false) ? 'active' : 'draft',
				'activation_mode' => 'immediate',
				'reference_model' => json_encode(['fixture' => $tariff['key']], JSON_THROW_ON_ERROR),
				'created_at' => $scenario['clock'],
				'updated_at' => $scenario['clock'],
			]);
			foreach (array_values($tariff['modules'] ?? []) as $sort => $module) {
				$operations[] = self::op('tariffs', 'at_tariff_rule_modules', 'insert', $tariff['key'] . ':' . $module, [
					'rule_set_id' => 'ref:tariff:' . $tariff['key'],
					'module_type' => $module,
					'config_json' => json_encode(['module' => $module, 'fixture' => true], JSON_THROW_ON_ERROR),
					'sort_order' => $sort,
					'created_at' => $scenario['clock'],
					'updated_at' => $scenario['clock'],
				]);
			}
		}
		return $operations;
	}

	private static function models(array $scenario, array $capabilities): array
	{
		$operations = [];
		$dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
		foreach ($scenario['models'] as $model) {
			$workDays = count(array_filter($model['weekdayMinutes'], static fn (int $minutes): bool => $minutes > 0));
			$scheduleDays = [];
			foreach ($model['weekdayMinutes'] as $index => $minutes) {
				$scheduleDays[$dayKeys[$index]] = $minutes > 0
					? ['work' => true, 'start' => '08:00', 'end' => sprintf('%02d:%02d', 8 + intdiv($minutes, 60), $minutes % 60), 'breaks' => []]
					: ['work' => false];
			}
			$data = [
				'name' => $model['key'],
				'description' => 'Legacy baseline working-time model ' . $model['key'],
				'type' => isset($model['owner']) ? 'flexible' : ($model['weeklyMinutes'] >= 2400 ? 'full_time' : 'part_time'),
				'weekly_hours' => $model['weeklyMinutes'] / 60,
				'daily_hours' => $model['weeklyMinutes'] / 60 / max(1, $workDays),
				'break_rules' => json_encode(['weekday_schedule' => ['version' => 1, 'days' => $scheduleDays]], JSON_THROW_ON_ERROR),
				'overtime_rules' => json_encode(['mode' => 'legacy_fixture'], JSON_THROW_ON_ERROR),
				'is_default' => $model['key'] === 'standard',
				'created_at' => $scenario['clock'],
				'updated_at' => $scenario['clock'],
			];
			if (($capabilities['workDaysPerWeek'] ?? false) === true) {
				$data['work_days_per_week'] = $workDays;
			}
			if (($capabilities['personalModels'] ?? false) === true) {
				$data['user_id'] = isset($model['owner']) ? self::uid($model['owner']) : null;
			}
			$operations[] = self::op('models', 'at_models', 'insert', 'model:' . $model['key'], $data);
			if (($capabilities['layeredVacationDefaults'] ?? false) === true) {
				$operations[] = self::op('models', 'at_model_vacation_defaults', 'insert', 'model-policy:' . $model['key'], [
					'working_time_model_id' => 'ref:model:' . $model['key'],
					'vacation_mode' => 'tariff_rule_based',
					'tariff_rule_set_id' => 'ref:tariff:' . $model['tariff'],
					'description' => 'Legacy baseline tariff policy',
					'effective_from' => '2026-01-01',
					'version' => 1,
					'created_by' => 'legacy_seed',
					'created_at' => $scenario['clock'],
					'updated_at' => $scenario['clock'],
				]);
			}
		}
		foreach ($scenario['users'] as $user) {
			if (isset($user['model'])) {
				$operations[] = self::op('models', 'at_user_models', 'insert', 'assignment:' . $user['key'], [
					'user_id' => self::uid($user['key']),
					'working_time_model_id' => 'ref:model:' . $user['model'],
					'vacation_days_per_year' => 25,
					'start_date' => '2026-01-01',
					'created_at' => $scenario['clock'],
					'updated_at' => $scenario['clock'],
				]);
			}
		}
		return $operations;
	}

	private static function timeEntries(array $scenario, array $plan, array $capabilities): array
	{
		return array_map(static function (array $entry) use ($plan, $capabilities, $scenario): array {
			$firstBreak = $entry['breaks'][0] ?? null;
			$data = [
				'user_id' => self::uid($entry['user']),
				'start_time' => $entry['start'],
				'end_time' => $entry['end'] ?? null,
				'break_start_time' => $firstBreak['start'] ?? null,
				'break_end_time' => $firstBreak['end'] ?? null,
				'description' => 'Legacy baseline time entry ' . $entry['key'],
				'status' => self::translate($plan, 'timeEntries.status', $entry['status']),
				'is_manual_entry' => false,
				'created_at' => $entry['start'],
				'updated_at' => $entry['end'] ?? $entry['start'],
			];
			if (($capabilities['entryBreaksJson'] ?? false) === true) {
				$data['breaks'] = json_encode($entry['breaks'], JSON_THROW_ON_ERROR);
			}
			if (($capabilities['workLocation'] ?? false) === true) {
				$data['work_location'] = $entry['workLocation'];
			}
			return self::op('timeEntries', 'at_entries', 'insert', 'entry:' . $entry['key'], $data);
		}, $scenario['timeEntries']);
	}

	private static function absenceTypes(array $scenario): array
	{
		$operations = [];
		foreach ($scenario['absenceTypes'] as $type) {
			if (($type['custom'] ?? false) !== true) {
				continue;
			}
			if (preg_match('/^custom_[a-z0-9_]+$/D', $type['code']) !== 1) {
				throw new \RuntimeException('Legacy custom absence fixture uses a non-persistable code.');
			}
			$operations[] = self::op('absenceTypes', 'at_custom_absence', 'insert', $type['code'], [
				'code' => $type['code'],
				'label' => $type['publicLabel'] ?? $type['code'],
				'label_i18n' => json_encode(['en' => $type['publicLabel'] ?? $type['code']], JSON_THROW_ON_ERROR),
				'soll_credit' => false,
				'max_days' => 5,
				'color' => '#6B7280',
				'icon' => 'calendar-blank',
				'active' => true,
				'created_at' => $scenario['clock'],
				'created_by' => 'legacy_seed',
			]);
		}
		return $operations;
	}

	private static function absences(array $scenario, array $plan, array $capabilities): array
	{
		$operations = [];
		foreach ($scenario['absences'] as $absence) {
			$type = self::translate($plan, 'absences.type', $absence['type']);
			if (!self::absenceSupported($absence, $type, $capabilities)) {
				continue;
			}
			$data = [
				'user_id' => self::uid($absence['user']),
				'type' => $type,
				'status' => self::translate($plan, 'absences.status', $absence['status']),
				'start_date' => $absence['from'],
				'end_date' => $absence['to'],
				'days' => $absence['unit'] === 'days' ? $absence['amount'] : null,
				'reason' => 'Legacy baseline absence ' . $absence['key'],
				'created_at' => $scenario['clock'],
				'updated_at' => $scenario['clock'],
			];
			if (($capabilities['vacationDurationHours'] ?? false) === true) {
				$data['duration_hours'] = $absence['unit'] === 'hours' ? $absence['amount'] : null;
			}
			$operations[] = self::op('absences', 'at_absences', 'insert', 'absence:' . $absence['key'], $data);
		}
		return $operations;
	}

	private static function absenceSupported(array $absence, string $type, array $capabilities): bool
	{
		if (str_starts_with($type, 'custom_') && ($capabilities['customAbsenceTypes'] ?? false) !== true) {
			return false;
		}
		return $absence['unit'] !== 'hours' || ($capabilities['vacationDurationHours'] ?? false) === true;
	}

	private static function substitutions(array $scenario, array $plan, array $capabilities): array
	{
		$absences = [];
		foreach ($scenario['absences'] as $absence) {
			$absences[$absence['key']] = $absence;
		}
		$operations = [];
		foreach ($scenario['substitutions'] as $substitution) {
			$absence = $absences[$substitution['absence']] ?? throw new \RuntimeException('Legacy substitution references an unknown absence.');
			$type = self::translate($plan, 'absences.type', $absence['type']);
			if (!self::absenceSupported($absence, $type, $capabilities)) {
				continue;
			}
			$data = [
				'id' => 'ref:absence:' . $substitution['absence'],
				'substitute_user_id' => self::uid($substitution['substitute']),
			];
			if ($substitution['status'] === 'pending') {
				$data['status'] = 'substitute_pending';
			}
			$operations[] = self::op('substitutions', 'at_absences', 'update', 'substitution:' . $substitution['absence'], $data);
		}
		return $operations;
	}

	private static function months(array $scenario, array $plan): array
	{
		$operations = [];
		foreach ($scenario['months'] as $month) {
			[$year, $monthNumber] = array_map('intval', explode('-', $month['month']));
			$key = $month['user'] . ':' . $month['month'];
			$status = self::translate($plan, 'months.status', $month['status']);
			$payload = ['user' => self::uid($month['user']), 'month' => $month['month'], 'status' => $status, 'revision' => $month['revision']];
			$data = [
				'user_id' => self::uid($month['user']),
				'year' => $year,
				'month' => $monthNumber,
				'version' => $month['revision'],
				'status' => $status,
				'snapshot_hash' => $month['revision'] > 0 ? hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)) : null,
				'prev_snapshot_hash' => $month['revision'] > 1 ? hash('sha256', $key . ':' . ($month['revision'] - 1)) : null,
				'canonical_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
			];
			if ($month['status'] === 'closed' || $month['status'] === 'reopened') {
				$data['finalized_at'] = $scenario['clock'];
				$data['finalized_by'] = 'legacy_seed';
			}
			if ($month['status'] === 'reopened') {
				$data['reopened_at'] = $scenario['clock'];
				$data['reopened_by'] = 'legacy_seed';
				$data['reopen_reason'] = 'Legacy baseline reopened month';
			}
			$operations[] = self::op('months', 'at_month_closure', 'insert', 'closure:' . $key, $data);
			if ($month['status'] === 'closed' || $month['status'] === 'reopened') {
				for ($version = 1; $version <= $month['revision']; $version++) {
					$revisionPayload = ['closure' => $key, 'version' => $version];
					$operations[] = self::op('months', 'at_month_closure_revision', 'insert', 'closure_revision:' . $key . ':' . $version, [
						'closure_id' => 'ref:closure:' . $key,
						'version' => $version,
						'snapshot_hash' => hash('sha256', json_encode($revisionPayload, JSON_THROW_ON_ERROR)),
						'prev_snapshot_hash' => $version > 1 ? hash('sha256', json_encode(['closure' => $key, 'version' => $version - 1], JSON_THROW_ON_ERROR)) : null,
						'canonical_payload' => json_encode($revisionPayload, JSON_THROW_ON_ERROR),
						'sealed_at' => $scenario['clock'],
						'sealed_by' => 'legacy_seed',
					]);
				}
			}
		}
		return $operations;
	}

	private static function idempotency(array $scenario, array $capabilities): array
	{
		$operations = [];
		foreach ($scenario['idempotency'] as $record) {
			$mobile = $record['channel'] === 'mobile';
			$data = [
				$mobile ? 'user_id' : 'terminal_id' => $mobile ? self::uid('employee_days') : 'legacy-terminal',
				'client_request_id' => $record['key'],
				'response_json' => $record['state'] === 'pending'
					? '{"pending":true}'
					: json_encode([
						'success' => true,
						'timeEntry' => ['fixture' => true, 'key' => $record['key'], 'status' => 'completed'],
						'break_warning' => null,
					], JSON_THROW_ON_ERROR),
				'created_at' => strtotime($scenario['clock']),
			];
			if (($capabilities['idempotencyPayloadHash'] ?? false) === true) {
				$data['payload_hash'] = $record['payloadHash'];
			}
			$operations[] = self::op('idempotency', $mobile ? 'at_mob_stamp_idem' : 'at_kiosk_stamp_idem', 'insert', $record['channel'] . ':' . $record['key'], $data);
		}
		return $operations;
	}

	private static function audit(array $scenario, array $plan, array $capabilities): array
	{
		$owners = [];
		foreach ($scenario['timeEntries'] as $entry) {
			$owners[$entry['key']] = ['time_entry', 'ref:entry:' . $entry['key'], $entry['user']];
		}
		foreach ($scenario['absences'] as $absence) {
			$type = self::translate($plan, 'absences.type', $absence['type']);
			if (self::absenceSupported($absence, $type, $capabilities)) {
				$owners[$absence['key']] = ['absence', 'ref:absence:' . $absence['key'], $absence['user']];
			}
		}
		if (($capabilities['monthClosures'] ?? false) === true) {
			foreach ($scenario['months'] as $month) {
				$owners[$month['user'] . ':' . $month['month']] = ['month_closure', 'ref:closure:' . $month['user'] . ':' . $month['month'], $month['user']];
			}
		}
		$operations = [];
		foreach ($scenario['audit'] as $event) {
			if (!isset($owners[$event['subject']])) {
				if (self::knownAuditSubject($scenario, $event['subject'])) {
					continue;
				}
				throw new \RuntimeException('Legacy audit event references an unknown subject.');
			}
			[$entityType, $entityRef, $owner] = $owners[$event['subject']];
			$operations[] = self::op('audit', 'at_audit', 'insert', 'audit:' . $event['sequence'], [
				'user_id' => self::uid($owner),
				'action' => $event['action'],
				'entity_type' => $entityType,
				'entity_id' => $entityRef,
				'old_values' => null,
				'new_values' => json_encode(['subject' => $event['subject'], 'sequence' => $event['sequence']], JSON_THROW_ON_ERROR),
				'ip_address' => '127.0.0.1',
				'user_agent' => 'legacy-baseline-seed',
				'performed_by' => self::uid($event['actor']),
				'created_at' => $scenario['clock'],
			]);
		}
		return $operations;
	}

	private static function knownAuditSubject(array $scenario, string $subject): bool
	{
		foreach ($scenario['absences'] as $absence) {
			if ($absence['key'] === $subject) {
				return true;
			}
		}
		foreach ($scenario['months'] as $month) {
			if ($month['user'] . ':' . $month['month'] === $subject) {
				return true;
			}
		}
		return false;
	}

	private static function delivery(array $scenario, array $plan, array $capabilities): array
	{
		$supportedAbsences = [];
		foreach ($scenario['absences'] as $absence) {
			$type = self::translate($plan, 'absences.type', $absence['type']);
			$supportedAbsences[$absence['key']] = self::absenceSupported($absence, $type, $capabilities);
		}
		$operations = [];
		foreach ($scenario['delivery']['mail'] as $mail) {
			if (!array_key_exists($mail['absence'], $supportedAbsences)) {
				throw new \RuntimeException('Legacy mail delivery references an unknown absence.');
			}
			if ($supportedAbsences[$mail['absence']] !== true) {
				continue;
			}
			$operations[] = self::op('delivery', 'external.mailhog', 'emit', $mail['template'] . ':' . $mail['absence'], $mail);
		}
		foreach ($scenario['delivery']['ical'] as $ical) {
			if (!array_key_exists($ical['absence'], $supportedAbsences)) {
				throw new \RuntimeException('Legacy iCal delivery references an unknown absence.');
			}
			if ($supportedAbsences[$ical['absence']] !== true) {
				continue;
			}
			$operations[] = self::op('delivery', 'external.ical', 'emit', $ical['scope'] . ':' . $ical['absence'], $ical);
		}
		return $operations;
	}

	private static function bridges(array $scenario): array
	{
		return array_map(static fn (array $bridge): array => self::op('bridges', 'external.' . $bridge['app'], 'emit', $bridge['app'], $bridge), $scenario['bridges']);
	}

	private static function translate(array $plan, string $field, string $value): string
	{
		return $plan['stateTranslations'][$field][$value] ?? throw new \RuntimeException('Missing legacy seed translation for ' . $field . '=' . $value);
	}

	private static function op(string $section, string $target, string $operation, string $key, array $data): array
	{
		return compact('section', 'target', 'operation', 'key', 'data');
	}

	private static function sectionSupported(string $section, array $capabilities): bool
	{
		return match ($section) {
			'tariffs' => ($capabilities['tariffs'] ?? false) === true,
			'absenceTypes' => ($capabilities['customAbsenceTypes'] ?? false) === true,
			'substitutions' => ($capabilities['absenceSubstitutions'] ?? false) === true,
			'months' => ($capabilities['monthClosures'] ?? false) === true,
			'idempotency' => ($capabilities['mobileKioskIdempotency'] ?? false) === true,
			default => true,
		};
	}

	private static function assertPlanCoverage(array $operations, array $plan, array $capabilities): void
	{
		$expected = [];
		foreach ($plan['sections'] as $section) {
			if (!self::sectionSupported($section['scenario'], $capabilities)) {
				continue;
			}
			foreach ($section['writes'] as $write) {
				if ($section['scenario'] === 'users' && ($capabilities['teams'] ?? false) !== true && $write['target'] !== 'external.nextcloud_users') {
					continue;
				}
				if ($write['target'] === 'at_model_vacation_defaults' && ($capabilities['layeredVacationDefaults'] ?? false) !== true) {
					continue;
				}
				$expected[$section['scenario'] . '|' . $write['target'] . '|' . $write['operation']] = true;
			}
		}
		$actual = [];
		foreach ($operations as $operation) {
			$actual[$operation['section'] . '|' . $operation['target'] . '|' . $operation['operation']] = true;
		}
		if (array_diff_key($expected, $actual) !== [] || array_diff_key($actual, $expected) !== []) {
			throw new \RuntimeException('Compiled legacy seed operations do not match the seed plan.');
		}
	}
}
