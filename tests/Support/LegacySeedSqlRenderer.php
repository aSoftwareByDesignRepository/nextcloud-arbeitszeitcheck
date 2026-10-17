<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

final class LegacySeedSqlRenderer
{
	private const FIRST_ID = 900001;

	public static function render(array $compiled, array $schema, string $database): array
	{
		if (!in_array($database, ['mysql', 'pgsql'], true)) {
			throw new \RuntimeException('Unsupported legacy seed database.');
		}
		$statements = [];
		$external = [];
		$references = [];
		$touchedSequences = [];
		$nextId = self::FIRST_ID;
		$utcStorage = ($compiled['capabilities']['localDatetimeStorage'] ?? true) !== true;

		foreach ($compiled['operations'] as $operation) {
			$target = $operation['target'];
			if (str_starts_with($target, 'external.')) {
				$external[] = $operation;
				continue;
			}
			$table = self::schema($schema, $target);
			$columns = $table['columns'];
			$data = self::resolve($operation['data'], $references);
			$unknown = array_diff(array_keys($data), array_keys($columns));
			if ($unknown !== []) {
				throw new \RuntimeException('Compiled seed operation ' . $operation['key'] . ' targets unknown columns on ' . $target . ': ' . implode(',', $unknown));
			}

			if ($operation['operation'] === 'insert') {
				if (isset($columns['id']) && !array_key_exists('id', $data)) {
					$data['id'] = $nextId++;
				}
				foreach ($columns as $column => $meta) {
					if (array_key_exists($column, $data) || ($meta['auto'] ?? false) === true || ($meta['nullable'] ?? true) === true || ($meta['default'] ?? null) !== null) {
						continue;
					}
					if (in_array($column, ['created_at', 'updated_at'], true)) {
						$data[$column] = $compiled['clock'];
						continue;
					}
					throw new \RuntimeException('Compiled seed operation ' . $operation['key'] . ' omits required column ' . $target . '.' . $column);
				}
				ksort($data);
				$sqlColumns = array_map(static fn (string $column): string => self::identifier($database, $column), array_keys($data));
				$values = array_map(static fn (string $column): string => self::literal($database, $data[$column], $columns[$column], $utcStorage), array_keys($data));
				$statements[] = [
					'key' => $operation['key'],
					'sql' => 'INSERT INTO ' . self::identifier($database, 'oc_' . $target)
						. ' (' . implode(', ', $sqlColumns) . ') VALUES (' . implode(', ', $values) . ')',
				];
				if (isset($data['id'])) {
					$references[$operation['key']] = $data['id'];
					$touchedSequences[$target] = true;
				}
				continue;
			}

			if ($operation['operation'] === 'update') {
				if (!array_key_exists('id', $data) || !is_int($data['id'])) {
					throw new \RuntimeException('Compiled seed update ' . $operation['key'] . ' lacks a resolved id.');
				}
				$id = $data['id'];
				unset($data['id']);
				if ($data === []) {
					throw new \RuntimeException('Compiled seed update ' . $operation['key'] . ' has no assignments.');
				}
				$assignments = [];
				foreach ($data as $column => $value) {
					$assignments[] = self::identifier($database, $column) . ' = ' . self::literal($database, $value, $columns[$column], $utcStorage);
				}
				$statements[] = [
					'key' => $operation['key'],
					'sql' => 'UPDATE ' . self::identifier($database, 'oc_' . $target)
						. ' SET ' . implode(', ', $assignments) . ' WHERE ' . self::identifier($database, 'id') . ' = ' . $id,
				];
				continue;
			}

			throw new \RuntimeException('Unsupported compiled seed operation ' . $operation['operation']);
		}

		if ($database === 'pgsql') {
			foreach (array_keys($touchedSequences) as $target) {
				$table = 'oc_' . $target;
				$statements[] = [
					'key' => 'sequence:' . $target,
					'sql' => "SELECT CASE WHEN pg_get_serial_sequence('" . $table . "', 'id') IS NOT NULL THEN setval(pg_get_serial_sequence('" . $table . "', 'id'), (SELECT COALESCE(MAX(id), " . self::FIRST_ID . ") FROM " . self::identifier($database, $table) . "), true) ELSE NULL END",
				];
			}
		}

		return [
			'statements' => $statements,
			'references' => $references,
			'external' => $external,
			'hash' => LegacyBaselineCanonicalizer::hash($statements),
		];
	}

	private static function schema(array $schema, string $target): array
	{
		$table = $schema[$target] ?? null;
		if (!is_array($table) || !is_array($table['columns'] ?? null) || $table['columns'] === []) {
			throw new \RuntimeException('Live source schema is missing table ' . $target);
		}
		return $table;
	}

	private static function resolve(array $data, array $references): array
	{
		foreach ($data as $key => $value) {
			if (is_string($value) && str_starts_with($value, 'ref:')) {
				$ref = substr($value, 4);
				$data[$key] = $references[$ref] ?? throw new \RuntimeException('Compiled seed reference cannot be resolved: ' . $ref);
			}
		}
		return $data;
	}

	private static function identifier(string $database, string $identifier): string
	{
		return $database === 'mysql'
			? '`' . str_replace('`', '``', $identifier) . '`'
			: '"' . str_replace('"', '""', $identifier) . '"';
	}

	private static function literal(string $database, mixed $value, array $column, bool $utcStorage): string
	{
		if (is_string($value) && str_starts_with($value, 'ref:')) {
			throw new \RuntimeException('Unresolved seed reference reached SQL rendering.');
		}
		if ($value === null) {
			return 'NULL';
		}
		if (is_bool($value)) {
			return $database === 'mysql' ? ($value ? '1' : '0') : ($value ? 'TRUE' : 'FALSE');
		}
		if (is_int($value) || is_float($value)) {
			return (string)$value;
		}
		if (is_array($value)) {
			$value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		}
		if (!is_string($value)) {
			throw new \RuntimeException('Unsupported SQL literal type.');
		}
		if (str_contains((string)($column['type'] ?? ''), 'time') || str_contains((string)($column['type'] ?? ''), 'date')) {
			$value = self::normalizeTemporal($value, $utcStorage);
		}
		return "'" . str_replace("'", "''", $value) . "'";
	}

	private static function normalizeTemporal(string $value, bool $utcStorage): string
	{
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
			return $value;
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}(?:T|\s)/', $value) !== 1) {
			return $value;
		}
		$date = new \DateTimeImmutable($value, new \DateTimeZone('Europe/Berlin'));
		if ($utcStorage) {
			$date = $date->setTimezone(new \DateTimeZone('UTC'));
		}
		return $date->format('Y-m-d H:i:s');
	}
}
