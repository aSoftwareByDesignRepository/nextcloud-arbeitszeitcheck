<?php

declare(strict_types=1);

/**
 * Per-app transfer manifest contract.
 *
 * The app subclasses this and declares:
 *   const SCHEMA_VERSION = <int>;
 *   const KEYS    = [ 'config_key' => 'class' | spec-array, ... ];
 *   const TABLES  = [ 'xx_table' => spec-array, ... ];
 *   const APPDATA_FOLDERS = [ 'dirname' => ['budget' => bytes], ... ]; // optional
 *
 * Key classes (D1/D3/spec §9):
 *   plain | uid-ref | gid-ref | json-refs | mirrored | secret | env | internal
 *
 * Key spec fields:
 *   class        required
 *   refs         ['kind' => 'uid'|'gid'|'uid_list_json'|'gid_list_json'|'json_paths',
 *                 'uid_paths' => list<string>, 'gid_paths' => list<string>]
 *                (list kinds: the value is a JSON array of ids; json_paths:
 *                 `$[*].field[*].sub` selector segments, `*` = array wildcard)
 *   unresolved   'skip' (default: key/row is skipped) | 'keep' (written verbatim)
 *   section      settings-section slug for "set it on this instance" links
 *   security     bool — force into the security-relevant report group
 *   store        config-in-table backing (apps that persist settings in a DB
 *                table instead of appconfig — wchk_config-style kv tables and
 *                cc_settings-style singleton JSON docs):
 *                  ['table' => t, 'kind' => 'kv',
 *                   'key_col' => c, 'value_col' => c]     — row per key; the
 *                    manifest key name IS the key_col value
 *                  ['table' => t, 'kind' => 'json', 'row_id' => n,
 *                   'json_col' => c, 'json_key' => name]  — field of a
 *                    singleton JSON document row
 *                Store-backed keys are exported/imported through the normal
 *                KEY pipeline (refs, validators, security flags) and MUST be
 *                class 'mirrored' (writes route through writeMirrored → the
 *                app's own CAS/validated writer) or 'secret' (redacted).
 *                Their backing table MUST be declared class 'internal' in
 *                tables() so raw rows never transfer.
 *
 * Table spec fields:
 *   class         catalog|business|user-data|internal|derived|log
 *   strategy      upsert-natural-key|seed-if-empty|wipe-load
 *   natural_key   list<string> — required for upsert-natural-key
 *   pk            list<string> — row-identity columns for matched-row UPDATEs
 *                 and late-FK addressing; default ['id']. Tables without an
 *                 `id` column MUST declare their composite pk (usually the
 *                 same columns as natural_key) or upsert/late-FK cannot
 *                 address the row.
 *   fk            col => target-table (resolved pre-insert)
 *   fk_late       col => target-table (resolved post-load UPDATE — self/cyclic)
 *   uid_ref       list<col> — row skipped when uid does not exist on target
 *   gid_ref       list<col> — row skipped when gid does not exist on target
 *   uid_ref_nullable list<col> — uid refs where '' / null stays null
 *   json_uid_list list<col> — JSON arrays of uids; ghosts filtered + reported
 *   secret_columns list<col> — redacted at export, NULL on insert, untouched on update
 *   env_columns   list<col> — instance-bound (file ids); NULL on insert, untouched on update
 *   depends_on    list<table> — load order parents
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\ConfigTransfer;

abstract class AbstractTransferManifest
{
	public const KEY_CLASSES = [
		'plain', 'uid-ref', 'gid-ref', 'json-refs', 'mirrored',
		'secret', 'env', 'internal',
	];

	public const TABLE_CLASSES = [
		'catalog', 'business', 'user-data', 'internal', 'derived', 'log',
	];

	public const TABLE_CLASSES_TRANSFERABLE = ['catalog', 'business', 'user-data'];

	public const TABLE_STRATEGIES = ['upsert-natural-key', 'seed-if-empty', 'wipe-load'];

	abstract public function schemaVersion(): int;

	/**
	 * Normalised key spec for $key, or null when the manifest does not
	 * declare the key (unmanifested keys are never transferred).
	 * Concrete in the base so apps declare only {@see keys()}.
	 *
	 * @return array{class:string,refs:?array,unresolved:string,section:?string,security:bool,store:?array}|null
	 */
	final public function keySpec(string $key): ?array
	{
		return $this->keys()[$key] ?? null;
	}

	/**
	 * @return array<string,array> key => normalised spec
	 */
	abstract public function keys(): array;

	/**
	 * Normalised table spec for $table, or null when undeclared.
	 * Concrete in the base so apps declare only {@see tables()}.
	 *
	 * @return array{class:string,strategy:string,natural_key:list<string>,pk:list<string>,fk:array<string,string>,fk_late:array<string,string>,uid_ref:list<string>,gid_ref:list<string>,uid_ref_nullable:list<string>,json_uid_list:list<string>,secret_columns:list<string>,env_columns:list<string>,depends_on:list<string>}|null
	 */
	final public function tableSpec(string $table): ?array
	{
		return $this->tables()[$table] ?? null;
	}

	/**
	 * @return array<string,array> table => normalised spec
	 */
	abstract public function tables(): array;

	/**
	 * Declared app-owned appdata folders for NH-01 blob transfer.
	 * @return array<string,int> folder => byte budget
	 */
	public function appDataFolders(): array
	{
		return [];
	}

	/**
	 * Declared transferable tables in `depends_on` order (topologically
	 * sorted; independent tables keep declaration order for determinism).
	 *
	 * @return list<string>
	 */
	final public function transferableTablesSorted(): array
	{
		$declared = [];
		foreach ($this->tables() as $table => $spec) {
			if (in_array($spec['class'], self::TABLE_CLASSES_TRANSFERABLE, true)) {
				$declared[$table] = $spec;
			}
		}
		$out = [];
		$seen = [];
		$visit = function (string $table) use (&$visit, &$seen, &$out, $declared): void {
			if (isset($seen[$table])) {
				return;
			}
			$seen[$table] = true;
			foreach ($declared[$table]['depends_on'] ?? [] as $parent) {
				if (isset($declared[$parent])) {
					$visit($parent);
				}
			}
			$out[] = $table;
		};
		foreach (array_keys($declared) as $table) {
			$visit($table);
		}
		return $out;
	}

	/**
	 * @return list<string> transferable tables in reverse depends_on order
	 */
	final public function transferableTablesReverseSorted(): array
	{
		return array_reverse($this->transferableTablesSorted());
	}

	/**
	 * Structural self-check — the gate script and the service both run this
	 * so a malformed manifest fails loudly, never silently.
	 *
	 * @return list<string> problems (empty = valid)
	 */
	public function validate(): array
	{
		$problems = [];
		foreach ($this->keys() as $key => $spec) {
			if ($key === '' || strlen($key) > 256 || !preg_match('/^[a-z0-9._:-]+$/i', $key)) {
				$problems[] = 'invalid config key name: ' . $key;
			}
			if (!in_array($spec['class'], self::KEY_CLASSES, true)) {
				$problems[] = "key {$key}: unknown class {$spec['class']}";
			}
			if ($spec['refs'] !== null) {
				$kind = $spec['refs']['kind'] ?? '';
				if (!in_array($kind, ['uid', 'gid', 'uid_list_json', 'gid_list_json', 'json_paths'], true)) {
					$problems[] = "key {$key}: unknown refs.kind {$kind}";
				}
				if ($kind === 'json_paths') {
					foreach (['uid_paths', 'gid_paths'] as $pl) {
						foreach ($spec['refs'][$pl] ?? [] as $path) {
							if (!self::isWellFormedJsonPath((string)$path)) {
								$problems[] = "key {$key}: malformed json path {$path}";
							}
						}
					}
				}
			}
			if (!in_array($spec['unresolved'], ['skip', 'keep'], true)) {
				$problems[] = "key {$key}: unresolved must be skip|keep";
			}
			if (($spec['store'] ?? null) !== null) {
				$store = $spec['store'];
				if (!in_array($spec['class'], ['mirrored', 'secret'], true)) {
					$problems[] = "key {$key}: store-backed keys must be class mirrored|secret (writes go through the app's validated writer)";
				}
				if (!in_array($store['kind'], ['kv', 'json'], true)) {
					$problems[] = "key {$key}: store.kind must be kv|json";
				} elseif ($store['kind'] === 'kv' && ($store['key_col'] === '' || $store['value_col'] === '')) {
					$problems[] = "key {$key}: store kv requires key_col + value_col";
				} elseif ($store['kind'] === 'json' && ($store['row_id'] < 1 || $store['json_col'] === '' || $store['json_key'] === '')) {
					$problems[] = "key {$key}: store json requires row_id + json_col + json_key";
				}
				$storeTable = $this->tableSpec((string)$store['table']);
				if ($storeTable === null || $storeTable['class'] !== 'internal') {
					$problems[] = "key {$key}: store table {$store['table']} must be declared class internal in tables()";
				}
			}
		}
		foreach ($this->tables() as $table => $spec) {
			if ($table === '' || !preg_match('/^[a-z_][a-z0-9_]*$/i', $table)) {
				$problems[] = 'invalid table name: ' . $table;
			}
			if (!in_array($spec['class'], self::TABLE_CLASSES, true)) {
				$problems[] = "table {$table}: unknown class {$spec['class']}";
			}
			if (!in_array($spec['strategy'], self::TABLE_STRATEGIES, true)) {
				$problems[] = "table {$table}: unknown strategy {$spec['strategy']}";
			}
			if ($spec['strategy'] === 'upsert-natural-key' && ($spec['natural_key'] ?? []) === []) {
				$problems[] = "table {$table}: upsert-natural-key requires natural_key";
			}
			if (($spec['pk'] ?? ['id']) === []) {
				$problems[] = "table {$table}: pk must not be empty";
			}
			foreach (array_merge($spec['fk'] ?? [], $spec['fk_late'] ?? []) as $col => $target) {
				$targetSpec = $this->tableSpec((string)$target);
				if ($targetSpec === null) {
					$problems[] = "table {$table}: fk {$col} targets undeclared table {$target}";
				} elseif (!in_array($targetSpec['class'], self::TABLE_CLASSES_TRANSFERABLE, true)) {
					$problems[] = "table {$table}: fk {$col} targets non-transferable table {$target}";
				}
			}
			foreach ($spec['depends_on'] ?? [] as $parent) {
				$parentSpec = $this->tableSpec((string)$parent);
				if ($parentSpec === null || !in_array($parentSpec['class'], self::TABLE_CLASSES_TRANSFERABLE, true)) {
					$problems[] = "table {$table}: depends_on undeclared/non-transferable {$parent}";
				}
			}
		}
		// depends_on cycles: a table that cannot be ordered fails loudly.
		$declared = array_filter(
			array_map(fn (array $s): string => $s['class'], $this->tables()),
			fn (string $c): bool => in_array($c, self::TABLE_CLASSES_TRANSFERABLE, true),
		);
		$visiting = [];
		$done = [];
		$dfs = function (string $t) use (&$dfs, &$visiting, &$done, $declared, &$problems): void {
			if (isset($done[$t])) {
				return;
			}
			if (isset($visiting[$t])) {
				$problems[] = "depends_on cycle involving {$t}";
				return;
			}
			$visiting[$t] = true;
			$spec = $this->tableSpec($t) ?? [];
			foreach ($spec['depends_on'] ?? [] as $p) {
				if (isset($declared[$p])) {
					$dfs($p);
				}
			}
			unset($visiting[$t]);
			$done[$t] = true;
		};
		foreach (array_keys($declared) as $t) {
			$dfs($t);
		}
		return array_values(array_unique($problems));
	}

	/**
	 * Normaliser shared by all app manifests: accepts the spec's string
	 * shorthand (`'key' => 'plain'`) or the array form.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,array>
	 */
	final protected static function normaliseKeys(array $raw): array
	{
		$out = [];
		foreach ($raw as $key => $spec) {
			if (is_string($spec)) {
				$spec = ['class' => $spec];
			}
			$class = (string)($spec['class'] ?? 'plain');
			$refs = null;
			if (isset($spec['refs']) && is_array($spec['refs'])) {
				$refs = [
					'kind' => (string)($spec['refs']['kind'] ?? ''),
					'uid_paths' => array_values((array)($spec['refs']['uid_paths'] ?? [])),
					'gid_paths' => array_values((array)($spec['refs']['gid_paths'] ?? [])),
				];
			}
			// Class shorthands that imply a ref kind.
			if ($refs === null) {
				$refs = match ($class) {
					'uid-ref' => ['kind' => 'uid', 'uid_paths' => [], 'gid_paths' => []],
					'gid-ref' => ['kind' => 'gid', 'uid_paths' => [], 'gid_paths' => []],
					default => null,
				};
			}
			$store = null;
			if (isset($spec['store']) && is_array($spec['store'])) {
				$store = [
					'table' => (string)($spec['store']['table'] ?? ''),
					'kind' => (string)($spec['store']['kind'] ?? ''),
					'key_col' => (string)($spec['store']['key_col'] ?? ''),
					'value_col' => (string)($spec['store']['value_col'] ?? ''),
					'row_id' => (int)($spec['store']['row_id'] ?? 0),
					'json_col' => (string)($spec['store']['json_col'] ?? ''),
					'json_key' => (string)($spec['store']['json_key'] ?? ''),
				];
			}
			$out[(string)$key] = [
				'class' => $class,
				'refs' => $refs,
				'unresolved' => (string)($spec['unresolved'] ?? 'skip'),
				'section' => isset($spec['section']) ? (string)$spec['section'] : null,
				'security' => (bool)($spec['security'] ?? false),
				'store' => $store,
			];
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,array>
	 */
	final protected static function normaliseTables(array $raw): array
	{
		$out = [];
		foreach ($raw as $table => $spec) {
			$spec = is_array($spec) ? $spec : [];
			$out[(string)$table] = [
				'class' => (string)($spec['class'] ?? 'internal'),
				'strategy' => (string)($spec['strategy'] ?? 'seed-if-empty'),
				'natural_key' => array_values(array_map('strval', (array)($spec['natural_key'] ?? []))),
				'pk' => array_values(array_map('strval', (array)($spec['pk'] ?? ['id']))),
				'fk' => array_map('strval', (array)($spec['fk'] ?? [])),
				'fk_late' => array_map('strval', (array)($spec['fk_late'] ?? [])),
				'uid_ref' => array_values(array_map('strval', (array)($spec['uid_ref'] ?? []))),
				'gid_ref' => array_values(array_map('strval', (array)($spec['gid_ref'] ?? []))),
				'uid_ref_nullable' => array_values(array_map('strval', (array)($spec['uid_ref_nullable'] ?? []))),
				'json_uid_list' => array_values(array_map('strval', (array)($spec['json_uid_list'] ?? []))),
				'secret_columns' => array_values(array_map('strval', (array)($spec['secret_columns'] ?? []))),
				'env_columns' => array_values(array_map('strval', (array)($spec['env_columns'] ?? []))),
				'depends_on' => array_values(array_map('strval', (array)($spec['depends_on'] ?? []))),
			];
		}
		return $out;
	}

	public static function isWellFormedJsonPath(string $path): bool
	{
		if ($path === '' || $path[0] !== '$') {
			return false;
		}
		return (bool)preg_match('/^\$(\.[A-Za-z_][A-Za-z0-9_]*|\[\*\])+$/', $path);
	}

	/**
	 * Extract scalar refs at declared json paths.
	 * Segments: `.field` descend into assoc arrays, `[*]` iterate lists.
	 *
	 * @return list<array{path:string,value:string}>
	 */
	public static function extractJsonRefs(mixed $node, string $path, string $prefix = '$'): array
	{
		if (!self::isWellFormedJsonPath($path)) {
			return [];
		}
		// preg_split with a lookahead keeps a leading empty element
		// ('$[*].a' → ['', '[*]', '.a']) — it must be dropped or the walk
		// dies on segment '' and NO ref is ever found (fail-silent hole).
		$segments = array_values(array_filter(
			preg_split('/(?=\.|\\[\*\])/', substr($path, 1)) ?: [],
			static fn (string $s): bool => $s !== '',
		));
		$found = [];
		$walk = function (mixed $cur, int $i, string $at) use (&$walk, &$found, $segments, $path): void {
			if ($i >= count($segments)) {
				if ((is_string($cur) || is_int($cur)) && (string)$cur !== '') {
					$found[] = ['path' => $at, 'value' => (string)$cur];
				}
				return;
			}
			$seg = $segments[$i];
			if ($seg === '[*]') {
				if (is_array($cur)) {
					foreach ($cur as $idx => $v) {
						$walk($v, $i + 1, $at . '[' . $idx . ']');
					}
				}
				return;
			}
			$field = substr($seg, 1);
			if (is_array($cur) && !array_is_list($cur) && array_key_exists($field, $cur)) {
				$walk($cur[$field], $i + 1, $at . '.' . $field);
			}
		};
		$walk($node, 0, $prefix);
		return $found;
	}
}
