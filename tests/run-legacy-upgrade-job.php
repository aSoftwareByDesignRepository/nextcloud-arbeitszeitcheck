<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workspace = dirname(dirname(dirname($root)));

require_once $root . '/lib/Config/VendorPublicKey.php';
require_once $root . '/lib/License/Azc2Codec.php';
require_once $root . '/lib/Migration/ArbeitszeitCheckTableCatalog.php';
foreach ([
	'Azc2TestSigning.php',
	'LegacyBaselineCanonicalizer.php',
	'LegacyBaselineManifest.php',
	'LegacyBaselineScenario.php',
	'LegacyBaselineSeedPlan.php',
	'LegacySchemaFamilyMap.php',
	'LegacyRuntimeProfileMap.php',
	'LegacyUpgradeMatrix.php',
	'LegacyBaselineSeedCompiler.php',
	'LegacySeedSqlRenderer.php',
	'LegacyUpgradeExecutionPlan.php',
] as $file) {
	require_once $root . '/tests/Support/' . $file;
}

use OCA\ArbeitszeitCheck\Config\VendorPublicKey;
use OCA\ArbeitszeitCheck\Tests\Support\Azc2TestSigning;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineCanonicalizer;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineManifest;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineScenario;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineSeedCompiler;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineSeedPlan;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyRuntimeProfileMap;
use OCA\ArbeitszeitCheck\Tests\Support\LegacySchemaFamilyMap;
use OCA\ArbeitszeitCheck\Tests\Support\LegacySeedSqlRenderer;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeExecutionPlan;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeMatrix;

final class LegacyUpgradeJobRunner
{
	private const FIXTURE_ID_START = 900000;
	private const EXPECTED_STEPS = [
		'checkout_source',
		'provision_isolated_stack',
		'seed_legacy_scenario',
		'capture_pre_upgrade_snapshot',
		'checkout_target',
		'run_upgrade',
		'capture_post_upgrade_snapshot',
		'create_upgrade_backup',
		'restore_upgrade_backup',
		'compare_canonical_hashes',
		'teardown_isolated_stack',
	];
	private const UTC_TO_BERLIN_COLUMNS = [
		'at_entries' => ['start_time', 'end_time', 'break_start_time', 'break_end_time', 'created_at', 'updated_at', 'approved_at'],
		'at_absences' => ['approved_at', 'created_at', 'updated_at'],
		'at_violations' => ['resolved_at', 'created_at'],
		'at_audit' => ['created_at'],
		'at_settings' => ['created_at', 'updated_at'],
		'at_models' => ['created_at', 'updated_at'],
		'at_user_models' => ['created_at', 'updated_at'],
		'at_month_closure' => ['finalized_at', 'reopened_at'],
		'at_month_closure_revision' => ['sealed_at'],
		'at_vacation_rollover_log' => ['created_at'],
	];

	private array $result = ['steps' => [], 'artifacts' => []];
	private bool $provisioned = false;
	private bool $keep = false;
	private string $artifactDir = '';
	private string $envFile = '';
	private string $appsDir = '';
	private string $sourceDir = '';
	private string $targetDir = '';
	private array $secrets = [];
	private array $seedReferences = [];

	public function __construct(
		private readonly string $appRoot,
		private readonly array $job,
		private readonly array $scenario,
		private readonly array $seedPlan,
		private readonly array $manifest,
		private readonly string $artifactsRoot,
		bool $keep,
	) {
		$this->keep = $keep;
	}

	public function run(): array
	{
		try {
			$this->prepareArtifacts();
			$this->step('checkout_source', fn () => $this->checkoutSource());
			$this->step('provision_isolated_stack', fn () => $this->provisionStack());
			$this->step('seed_legacy_scenario', fn () => $this->seedScenario());
			$this->step('capture_pre_upgrade_snapshot', fn () => $this->captureBaseline('pre'));
			$this->step('checkout_target', fn () => $this->checkoutTarget());
			$this->step('run_upgrade', fn () => $this->upgrade());
			$this->step('capture_post_upgrade_snapshot', fn () => $this->captureBaseline('post'));
			$this->step('create_upgrade_backup', fn () => $this->createBackup('post'));
			$this->step('restore_upgrade_backup', fn () => $this->restoreBackup('post'));
			$this->step('compare_canonical_hashes', fn () => $this->compareSnapshots());
			$this->result['status'] = 'passed';
		} catch (Throwable $e) {
			$this->result['status'] = 'failed';
			$this->result['error'] = [
				'type' => $e::class,
				'message' => $e->getMessage(),
				'file' => $e->getFile() . ':' . $e->getLine(),
				'trace' => $e->getTraceAsString(),
			];
			throw $e;
		} finally {
			try {
				$this->step('teardown_isolated_stack', fn () => $this->teardown());
			} catch (Throwable $teardownError) {
				$this->result['teardownError'] = $teardownError->getMessage();
				if (($this->result['status'] ?? null) !== 'failed') {
					$this->result['status'] = 'failed';
				}
			}
			if ($this->artifactDir !== '') {
				$this->writeJson($this->artifactDir . '/result.json', $this->result);
			}
		}
		return $this->result;
	}

	private function prepareArtifacts(): void
	{
		$this->artifactDir = rtrim($this->artifactsRoot, '/') . '/' . $this->job['artifactKey'];
		if (is_dir($this->artifactDir)) {
			$this->removeTree($this->artifactDir);
		}
		if (!mkdir($this->artifactDir, 0755, true)) {
			throw new RuntimeException('Cannot create artifact directory ' . $this->artifactDir);
		}
		$this->sourceDir = $this->artifactDir . '/source';
		$this->targetDir = $this->artifactDir . '/target';
		$this->appsDir = $this->artifactDir . '/custom_apps';
		$this->envFile = $this->artifactDir . '/.env';
		mkdir($this->appsDir, 0777, true);
		chmod($this->appsDir, 0777);
		$this->writeJson($this->artifactDir . '/job.json', $this->job);
	}

	private function checkoutSource(): void
	{
		mkdir($this->sourceDir, 0755, true);
		$tar = $this->artifactDir . '/source.tar';
		$this->cmd(['git', '-C', $this->appRoot, 'archive', '--format=tar', '-o', $tar, $this->job['sourceTag']]);
		$this->cmd(['tar', '-xf', $tar, '-C', $this->sourceDir]);
		unlink($tar);
		$treeHash = $this->treeHash($this->sourceDir);
		$fixtureAdaptations = $this->adaptHistoricalSourceForFixture();
		$this->result['source'] = [
			'tag' => $this->job['sourceTag'],
			'treeHash' => $treeHash,
			'fixtureTreeHash' => $this->treeHash($this->sourceDir),
			'fixtureAdaptations' => $fixtureAdaptations,
		];
	}

	private function adaptHistoricalSourceForFixture(): array
	{
		$file = $this->sourceDir . '/lib/Migration/Version1014Date20260409120000.php';
		if (!is_file($file)) {
			return [];
		}
		$source = file_get_contents($file);
		if (!is_string($source) || !str_contains($source, "\t\t\t\t'at_month_closure',")) {
			return [];
		}
		$block = "\n\t\t\t\$table->addForeignKeyConstraint(\n\t\t\t\t'at_month_closure',\n\t\t\t\t['closure_id'],\n\t\t\t\t['id'],\n\t\t\t\t['onDelete' => 'CASCADE'],\n\t\t\t\t'at_mcr_closure_fk'\n\t\t\t);";
		$patched = str_replace($block, '', $source, $count);
		if ($count !== 1) {
			throw new RuntimeException('Historical month-closure fixture adaptation did not match exactly once.');
		}
		if (file_put_contents($file, $patched) === false) {
			throw new RuntimeException('Cannot write historical month-closure fixture adaptation.');
		}
		return ['omit_broken_unprefixed_month_closure_fk'];
	}

	private function provisionStack(): void
	{
		$this->secrets = [
			'DB_PASSWORD' => self::secret('db'),
			'DB_ROOT_PASSWORD' => self::secret('root'),
			'ADMIN_PASSWORD' => self::secret('admin'),
			'FIXTURE_PASSWORD' => self::secret('fixture'),
		];
		$env = [
			'APP_DIR' => $this->sourceDir,
			'APPS_DIR' => $this->appsDir,
			'NETWORK_NAME' => $this->job['network'],
			'SERVER_IMAGE' => $this->job['serverImage'],
			'AZC_ALLOW_VENDOR_KEY_OVERRIDE' => '1',
			'AZC_VENDOR_PUBLIC_KEY_B64' => VendorPublicKey::TEST_PUBLIC_KEY_B64,
			...$this->secrets,
		];
		file_put_contents($this->envFile, self::envFile($env));
		chmod($this->envFile, 0600);
		$this->compose(['up', '-d', '--wait']);
		$this->provisioned = true;
		$this->occ(['app:enable', 'arbeitszeitcheck']);
		$this->result['server'] = json_decode($this->occ(['status', '--output=json']), true, 512, JSON_THROW_ON_ERROR);
	}

	private function seedScenario(): void
	{
		$this->provisionUsers();
		if (($this->job['schemaCapabilities']['teams'] ?? false) === true) {
			$this->occ(['config:app:set', 'arbeitszeitcheck', 'use_app_teams', '--value=1']);
		}
		$schema = $this->introspectSchema();
		$this->writeJson($this->artifactDir . '/source-schema.json', $schema);
		$compiled = LegacyBaselineSeedCompiler::compile($this->scenario, $this->seedPlan, $this->job['schemaCapabilities']);
		$rendered = LegacySeedSqlRenderer::render($compiled, $schema, $this->job['database']);
		$this->writeJson($this->artifactDir . '/compiled-seed.json', $compiled);
		$this->writeJson($this->artifactDir . '/rendered-seed.json', $rendered);
		$this->seedReferences = $rendered['references'];
		foreach ($rendered['statements'] as $statement) {
			$this->dbExec($statement['sql']);
		}
		foreach ($compiled['operations'] as $operation) {
			if ($operation['target'] === 'external.nextcloud_users' && ($operation['data']['deleted'] ?? false) === true) {
				$this->occ(['user:delete', $operation['data']['uid']]);
			}
		}
		if (($this->job['schemaCapabilities']['azc2Licensing'] ?? false) === true) {
			$this->provisionLicenseFixture();
		}
		$this->result['seed'] = [
			'operationCount' => count($compiled['operations']),
			'compiledHash' => $compiled['hash'],
			'sqlHash' => $rendered['hash'],
			'statementCount' => count($rendered['statements']),
		];
	}

	private function captureBaseline(string $phase): void
	{
		$mutatingProbes = [];
		foreach ($this->job['captureVariants'] as $variant) {
			$mode = $this->vacationMode($variant);
			foreach ($mode['appConfig'] as $key => $value) {
				$this->occ(['config:app:set', 'arbeitszeitcheck', $key, '--value=' . (string)$value]);
			}
			$api = $this->captureApiProbes($phase, $variant, $mutatingProbes);
			$snapshot = [
				'variant' => $variant,
				'phase' => $phase,
				'api' => $api,
				'database' => $this->captureDatabaseSnapshot(),
			];
			$snapshot['hash'] = LegacyBaselineCanonicalizer::hash($snapshot);
			$this->writeJson($this->artifactDir . '/' . $phase . '-' . $variant . '.json', $snapshot);
		}
	}

	private function checkoutTarget(): void
	{
		mkdir($this->targetDir, 0755, true);
		$this->copyWorktree($this->appRoot, $this->targetDir);
		$this->compose(['stop', 'app']);
		file_put_contents($this->envFile, self::envFile([
			'APP_DIR' => $this->targetDir,
			'APPS_DIR' => $this->appsDir,
			'NETWORK_NAME' => $this->job['network'],
			'SERVER_IMAGE' => $this->job['serverImage'],
			'AZC_ALLOW_VENDOR_KEY_OVERRIDE' => '1',
			'AZC_VENDOR_PUBLIC_KEY_B64' => VendorPublicKey::TEST_PUBLIC_KEY_B64,
			...$this->secrets,
		]));
		chmod($this->envFile, 0600);
		$this->compose(['up', '-d', '--wait', 'app']);
		$this->result['target'] = [
			'ref' => $this->job['targetRef'],
			'treeHash' => $this->treeHash($this->targetDir),
		];
	}

	private function upgrade(): void
	{
		$this->occ(['upgrade']);
		$this->occ(['app:enable', 'arbeitszeitcheck']);
		$this->verifyWorkforceSuiteUpgradeDefaults();
		$this->provisionLicenseFixture();
		$this->result['upgrade'] = json_decode($this->occ(['status', '--output=json']), true, 512, JSON_THROW_ON_ERROR);
	}

	private function verifyWorkforceSuiteUpgradeDefaults(): void
	{
		$expected = [
			'workforce_suite_install_state' => 'upgraded_legacy',
			'policy_cohorts_enabled' => '0',
			'work_location_catalog_enabled' => '0',
			'special_entitlements_enabled' => '0',
			'business_trips_enabled' => '0',
			'business_trip_credit_enabled' => '0',
			'legacy_home_absence_create_enabled' => '1',
			'legacy_trip_absence_create_enabled' => '1',
		];
		$table = $this->sqlIdentifier('oc_appconfig');
		foreach ($expected as $key => $value) {
			$actual = $this->dbQueryScalar(sprintf(
				'SELECT configvalue FROM %s WHERE appid = %s AND configkey = %s',
				$table,
				$this->sqlString('arbeitszeitcheck'),
				$this->sqlString($key),
			));
			if ($actual !== $value) {
				throw new RuntimeException(sprintf('Unexpected Workforce Suite upgrade default for %s: expected %s, got %s.', $key, $value, $actual));
			}
		}
		$this->result['workforceSuiteDefaults'] = $expected;
	}

	private function provisionLicenseFixture(): void
	{
		$wire = Azc2TestSigning::signPayload([
			'v' => 2,
			'customerId' => 'legacy-upgrade-fixture',
			'issuedAt' => '2026-01-01',
			'validUntil' => '2099-12-31',
			'mobileSeats' => 5,
			'terminalDevices' => 2,
			'bundle' => true,
		]);
		[, $payloadB64, $signatureB64] = explode('.', $wire);
		$licenseTable = $this->sqlIdentifier('oc_azc_license_state');
		$this->dbExec('DELETE FROM ' . $licenseTable . ' WHERE customer_id = ' . $this->sqlString('legacy-upgrade-fixture'));
		$this->dbExec(sprintf(
			'INSERT INTO %s (customer_id, valid_until, mobile_seats, terminal_devices, bundle, key_applied_at, payload_b64, signature_b64) VALUES (%s, %s, 5, 2, 1, %s, %s, %s)',
			$licenseTable,
			$this->sqlString('legacy-upgrade-fixture'),
			$this->sqlString('2099-12-31'),
			$this->sqlString('2026-01-20 12:00:00'),
			$this->sqlString($payloadB64),
			$this->sqlString($signatureB64),
		));
		$seatTable = $this->sqlIdentifier('oc_azc_mobile_seat');
		$this->dbExec('DELETE FROM ' . $seatTable . ' WHERE assigned_by = ' . $this->sqlString('legacy_upgrade_fixture'));
		foreach ($this->scenario['users'] as $user) {
			if (($user['deleted'] ?? false) === true) {
				continue;
			}
			$this->dbExec(sprintf(
				'INSERT INTO %s (user_id, assigned_at, assigned_by) VALUES (%s, %s, %s)',
				$seatTable,
				$this->sqlString('azc_' . $user['key']),
				$this->sqlString('2026-01-20 12:00:00'),
				$this->sqlString('legacy_upgrade_fixture'),
			));
		}
		$this->occ(['config:app:set', 'arbeitszeitcheck', 'kiosk_enabled', '--value=1']);
	}

	private function createBackup(string $phase): void
	{
		$path = $this->artifactDir . '/' . $phase . '-backup.sql';
		$result = $this->job['database'] === 'mysql'
			? $this->cmd($this->composeArgs(['exec', '-T', 'db', 'mariadb-dump', '--single-transaction', '--no-tablespaces', '-u', 'nextcloud', '-p' . $this->secrets['DB_PASSWORD'], 'nextcloud']))
			: $this->cmd($this->composeArgs(['exec', '-T', '-e', 'PGPASSWORD=' . $this->secrets['DB_PASSWORD'], 'db', 'pg_dump', '-U', 'nextcloud', '-d', 'nextcloud', '--clean', '--if-exists']));
		if (file_put_contents($path, $result['stdout']) === false || strlen($result['stdout']) < 1024) {
			throw new RuntimeException('Upgrade backup is missing or implausibly small.');
		}
		$this->result['backup'][$phase] = [
			'file' => basename($path),
			'bytes' => strlen($result['stdout']),
			'sha256' => hash_file('sha256', $path),
		];
	}

	private function restoreBackup(string $phase): void
	{
		$restoreDb = 'restore_verify';
		if ($this->job['database'] === 'mysql') {
			$this->dbExec('DROP DATABASE IF EXISTS restore_verify', true);
			$this->dbExec('CREATE DATABASE restore_verify', true);
			$this->dbExec("GRANT ALL PRIVILEGES ON `restore_verify`.* TO 'nextcloud'@'%'", true);
			$dump = file_get_contents($this->artifactDir . '/' . $phase . '-backup.sql');
			$this->cmd($this->composeArgs(['exec', '-T', 'db', 'mariadb', '-u', 'nextcloud', '-p' . $this->secrets['DB_PASSWORD'], $restoreDb]), $dump);
			$count = $this->dbQueryScalar('SELECT COUNT(*) FROM `oc_appconfig`', $restoreDb);
		} else {
			$this->dbExec('DROP DATABASE IF EXISTS restore_verify', true);
			$this->dbExec('CREATE DATABASE restore_verify', true);
			$dump = file_get_contents($this->artifactDir . '/' . $phase . '-backup.sql');
			$this->cmd($this->composeArgs(['exec', '-T', '-e', 'PGPASSWORD=' . $this->secrets['DB_PASSWORD'], 'db', 'psql', '-U', 'nextcloud', '-d', $restoreDb, '-v', 'ON_ERROR_STOP=1', '-q']), $dump);
			$count = $this->dbQueryScalar('SELECT COUNT(*) FROM "oc_appconfig"', $restoreDb);
		}
		if ((int)$count < 1) {
			throw new RuntimeException('Restored upgrade backup contains no Nextcloud configuration.');
		}
		$this->result['restore'][$phase] = ['database' => $restoreDb, 'appconfigRows' => (int)$count];
	}

	private function compareSnapshots(): void
	{
		$comparisons = [];
		foreach ($this->job['captureVariants'] as $variant) {
			$pre = $this->readJson($this->artifactDir . '/pre-' . $variant . '.json');
			$post = $this->readJson($this->artifactDir . '/post-' . $variant . '.json');
			if (($pre['variant'] ?? null) !== $variant || ($post['variant'] ?? null) !== $variant) {
				throw new RuntimeException('Baseline snapshot variant mismatch for ' . $variant);
			}
			$diff = $this->compareDatabaseSnapshot($pre['database'], $post['database']);
			$api = $this->compareApiProbes($pre['api'], $post['api']);
			if ($diff !== [] || $api !== []) {
				$this->writeJson($this->artifactDir . '/comparison-' . $variant . '.json', ['database' => $diff, 'api' => $api]);
				throw new RuntimeException('Canonical legacy baseline mismatch for ' . $variant . ': ' . count($diff) . ' database, ' . count($api) . ' API differences');
			}
			$comparisons[$variant] = [
				'preHash' => $pre['hash'],
				'postHash' => $post['hash'],
				'databaseHash' => $post['database']['hash'],
			];
		}
		$this->result['comparison'] = $comparisons;
	}

	private function teardown(): void
	{
		if (!$this->provisioned) {
			return;
		}
		$args = $this->composeArgs(['down', '-v', '--remove-orphans']);
		if ($this->keep) {
			$this->result['teardown'] = 'kept-for-debugging';
			return;
		}
		$result = $this->cmd($args, null, true);
		if ($result['exitCode'] !== 0) {
			throw new RuntimeException('Isolated upgrade stack teardown failed: ' . $result['stderr']);
		}
		$this->result['teardown'] = 'completed';
	}

	private function provisionUsers(): void
	{
		foreach ($this->scenario['users'] as $user) {
			$uid = 'azc_' . $user['key'];
			$args = ['user:add', '--password-from-env', '--display-name=AZC ' . $user['key'], $uid];
			$result = $this->occResult($args, ['OC_PASS' => $this->secrets['FIXTURE_PASSWORD'], 'NC_PASS' => $this->secrets['FIXTURE_PASSWORD']], true);
			if ($result['exitCode'] !== 0) {
				$this->occ(['user:add', '--password-from-env', $uid], ['OC_PASS' => $this->secrets['FIXTURE_PASSWORD'], 'NC_PASS' => $this->secrets['FIXTURE_PASSWORD']]);
			}
		}
	}

	private function vacationMode(string $variant): array
	{
		foreach ($this->scenario['vacationModes'] as $mode) {
			if ($mode['key'] === $variant) {
				return $mode;
			}
		}
		throw new RuntimeException('Missing vacation capture variant ' . $variant);
	}

	private function captureDatabaseSnapshot(): array
	{
		$schema = $this->introspectSchema();
		$tables = [];
		foreach ($schema as $logical => $table) {
			if (!isset($table['columns']['id'])) {
				throw new RuntimeException('Legacy app table lacks an id primary key: ' . $logical);
			}
			$columns = array_keys($table['columns']);
			$object = $this->job['database'] === 'mysql'
				? 'JSON_OBJECT(' . implode(',', array_map(static fn (string $c): string => "'" . str_replace("'", "''", $c) . "', `" . str_replace('`', '``', $c) . '`', $columns)) . ')'
				: 'json_build_object(' . implode(',', array_map(static fn (string $c): string => "'" . str_replace("'", "''", $c) . "', \"" . str_replace('"', '""', $c) . '"', $columns)) . ')';
			$quoted = $this->job['database'] === 'mysql' ? '`oc_' . $logical . '`' : '"oc_' . $logical . '"';
			$rows = [];
			foreach (explode("\n", trim($this->dbQuery('SELECT ' . $object . ' FROM ' . $quoted . ' WHERE id >= ' . self::FIXTURE_ID_START . ' ORDER BY id'))) as $line) {
				if ($line !== '') {
					$rows[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
				}
			}
			$tables[$logical] = $rows;
		}
		$tables['external.appconfig'] = $this->appConfigSnapshot();
		$tables['external.users'] = $this->userSnapshot();
		return ['tables' => $tables, 'hash' => LegacyBaselineCanonicalizer::hash($tables)];
	}

	private function appConfigSnapshot(): array
	{
		$where = "appid = 'arbeitszeitcheck' AND configkey IN ('vacation_unit','vacation_hours_per_day','use_app_teams')";
		return $this->job['database'] === 'mysql'
			? $this->jsonRows("SELECT JSON_OBJECT('configkey', configkey, 'configvalue', configvalue) FROM `oc_appconfig` WHERE {$where} ORDER BY configkey")
			: $this->jsonRows("SELECT row_to_json(t)::text FROM (SELECT configkey, configvalue FROM \"oc_appconfig\" WHERE {$where} ORDER BY configkey) t");
	}

	private function userSnapshot(): array
	{
		$where = "uid LIKE 'azc\_%'";
		return $this->job['database'] === 'mysql'
			? $this->jsonRows("SELECT JSON_OBJECT('uid', uid, 'displayname', displayname) FROM `oc_users` WHERE {$where} ORDER BY uid")
			: $this->jsonRows("SELECT row_to_json(t)::text FROM (SELECT uid, displayname FROM \"oc_users\" WHERE {$where} ORDER BY uid) t");
	}

	private function compareDatabaseSnapshot(array $pre, array $post): array
	{
		$differences = [];
		foreach ($pre['tables'] as $table => $rows) {
			if (!array_key_exists($table, $post['tables'])) {
				if ($rows !== []) {
					$differences[] = $table . ': missing target table for seeded rows';
				}
				continue;
			}
			if ($table === 'external.appconfig' || $table === 'external.users') {
				if ($rows !== $post['tables'][$table]) {
					$differences[] = $table . ': external state drift';
				}
				continue;
			}
			$postRows = [];
			foreach ($post['tables'][$table] as $row) {
				$postRows[$row['id']] = $row;
			}
			foreach ($rows as $row) {
				$id = $row['id'];
				if (!isset($postRows[$id])) {
					$differences[] = $table . ':' . $id . ': row missing after upgrade';
					continue;
				}
				foreach ($row as $column => $value) {
					if (!array_key_exists($column, $postRows[$id])) {
						$differences[] = $table . ':' . $id . ':' . $column . ': source column missing';
						continue;
					}
					$normalizedValue = $this->canonicalDatetime($value);
					$postValue = $this->canonicalDatetime($postRows[$id][$column]);
					if ($normalizedValue !== $postValue && !$this->expectedMigrationRepair($table, $id, $row, $column, $postValue)) {
						$differences[] = $table . ':' . $id . ':' . $column . ': ' . json_encode($normalizedValue) . ' != ' . json_encode($postValue);
					}
				}
			}
			foreach (array_keys($postRows) as $id) {
				if (!in_array($id, array_column($rows, 'id'), true) && !$this->expectedAddedRow($table, $postRows[$id], $pre['tables'])) {
					$differences[] = $table . ':' . $id . ': unexpected seeded-id row added by upgrade';
				}
			}
		}
		return $differences;
	}

	private function expectedMigrationRepair(string $table, int|string $id, array $sourceRow, string $column, mixed $postValue): bool
	{
		if ($table === 'at_entries' && $id === ($this->seedReferences['entry:open_stamp'] ?? null)) {
			if ($column === 'status') {
				return $postValue === 'completed';
			}
			if ($column === 'end_time' || $column === 'updated_at') {
				return $this->isDatetimeString($postValue);
			}
			if ($column === 'breaks') {
				return $this->isExpectedAutomaticBreakRepair($postValue);
			}
			// The daily-maximum repair annotates the row when the source schema
			// already carries the audit columns.
			if ($column === 'ended_reason') {
				return $postValue === 'auto_daily_max';
			}
			if ($column === 'policy_applied') {
				return $postValue === 'arbzg_daily_maximum';
			}
		}
		if ($table === 'at_entries' && $id === ($this->seedReferences['entry:paused'] ?? null)) {
			if ($column === 'status') {
				return $postValue === 'completed';
			}
			if ($column === 'end_time') {
				$expected = $sourceRow['updated_at'] ?? $sourceRow['start_time'];
				return $postValue === $this->migratedStorageDatetime($expected);
			}
			if ($column === 'ended_reason') {
				return $postValue === 'stale_paused_repair';
			}
			if ($column === 'policy_applied') {
				return $postValue === 'repair';
			}
		}
		if ($table === 'at_absences' && ($sourceRow['days'] ?? null) === null) {
			if ($column === 'days') {
				return is_numeric($postValue) && (float)$postValue > 0;
			}
			if ($column === 'updated_at') {
				return is_string($postValue) && $postValue !== $sourceRow['updated_at'];
			}
		}
		if ($table === 'at_absences' && $this->autoApprovalExpected($sourceRow)) {
			if ($column === 'status') {
				return $postValue === 'approved';
			}
			if ($column === 'approver_comment') {
				return $postValue === 'Auto-approved: no approver is assigned to your team in the app.';
			}
			if ($column === 'approved_by_user_id') {
				return $postValue === 'system';
			}
			if (in_array($column, ['approved_at', 'updated_at'], true)) {
				return is_string($postValue) && $postValue !== $sourceRow[$column];
			}
		}
		if (in_array($column, self::UTC_TO_BERLIN_COLUMNS[$table] ?? [], true)) {
			return $postValue === $this->migratedStorageDatetime($sourceRow[$column] ?? null);
		}
		return false;
	}

	private function migratedStorageDatetime(mixed $value): ?string
	{
		if (!is_string($value) || $value === '') {
			return null;
		}
		$normalized = $this->canonicalDatetime($value);
		if (!is_string($normalized)) {
			return null;
		}
		if (($this->job['schemaCapabilities']['localDatetimeStorage'] ?? true) === true) {
			return $normalized;
		}
		$date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized, new \DateTimeZone('UTC'));
		if ($date === false) {
			return null;
		}
		return $date->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i:s');
	}

	private function canonicalDatetime(mixed $value): mixed
	{
		if (!is_string($value)) {
			return $value;
		}
		foreach (['Y-m-d H:i:s', 'Y-m-d\\TH:i:s'] as $format) {
			$date = \DateTimeImmutable::createFromFormat($format, $value);
			if ($date !== false && $date->format($format) === $value) {
				return $date->format('Y-m-d H:i:s');
			}
		}
		return $value;
	}

	private function isDatetimeString(mixed $value): bool
	{
		return is_string($this->canonicalDatetime($value)) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$this->canonicalDatetime($value)) === 1;
	}

	private function isExpectedAutomaticBreakRepair(mixed $value): bool
	{
		if (!is_string($value)) {
			return false;
		}
		$breaks = json_decode($value, true);
		return is_array($breaks)
			&& count($breaks) === 1
			&& ($breaks[0]['automatic'] ?? null) === true
			&& ($breaks[0]['duration_minutes'] ?? null) === 45
			&& ($breaks[0]['policy_source'] ?? null) === 'law'
			&& ($breaks[0]['required_break_minutes'] ?? null) === 45;
	}

	private function expectedAddedRow(string $table, array $row, array $preTables): bool
	{
		if ($table !== 'at_audit' || ($row['performed_by'] ?? null) !== 'system') {
			return false;
		}
		if (($row['action'] ?? null) === 'time_entry_auto_completed_daily_max' && ($row['entity_type'] ?? null) === 'time_entry') {
			return ($row['entity_id'] ?? null) === ($this->seedReferences['entry:open_stamp'] ?? null);
		}
		if (($this->job['schemaCapabilities']['teams'] ?? false) === true
			|| ($row['action'] ?? null) !== 'absence_auto_approved'
			|| ($row['entity_type'] ?? null) !== 'absence') {
			return false;
		}
		foreach (($preTables['at_absences'] ?? []) as $absence) {
			if (($absence['id'] ?? null) === ($row['entity_id'] ?? null) && $this->autoApprovalExpected($absence)) {
				return true;
			}
		}
		return false;
	}

	private function autoApprovalExpected(array $absence): bool
	{
		return ($this->job['schemaCapabilities']['teams'] ?? false) !== true
			&& ($absence['status'] ?? null) === 'pending'
			&& in_array($absence['type'] ?? null, ['home_office', 'business_trip'], true);
	}

	private function captureApiProbes(string $phase, string $variant, array &$mutatingProbes): array
	{
		// Mutating probes must run before read probes and exactly once per phase:
		// capture variants share one database, so replaying a mutation for every
		// variant would contaminate snapshots captured earlier in the same phase.
		$endpoints = $this->manifest['endpoints'];
		$isMutating = static fn (array $e): bool => in_array($e['operation'] ?? '', ['mobile.replay', 'kiosk.replay'], true);
		usort($endpoints, static fn (array $a, array $b): int => (int)$isMutating($b) <=> (int)$isMutating($a));
		$probes = [];
		foreach ($endpoints as $endpoint) {
			$operation = (string)($endpoint['operation'] ?? '');
			if ($isMutating($endpoint) && isset($mutatingProbes[$operation])) {
				$probe = $mutatingProbes[$operation];
				$probe['key'] = $endpoint['category'] . ':' . $operation . ':' . $endpoint['actor'] . ':' . ($endpoint['month'] ?? '') . ':' . $variant;
			} else {
				$probe = $this->executeProbe($endpoint, $phase, $variant);
				if ($isMutating($endpoint)) {
					$mutatingProbes[$operation] = $probe;
				}
			}
			$probes[] = $probe;
		}
		return $probes;
	}

	private function executeProbe(array $endpoint, string $phase, string $variant): array
	{
		$key = $endpoint['category'] . ':' . $endpoint['operation'] . ':' . $endpoint['actor'] . ':' . ($endpoint['month'] ?? '') . ':' . $variant;
		$request = $this->probeRequest($endpoint);
		if ($request === null) {
			return ['key' => $key, 'kind' => 'external_fixture', 'status' => 'recorded', 'payload' => $endpoint];
		}
		$actor = 'azc_' . $endpoint['actor'];
		$args = [
			'curl', '-sS', '--max-time', '20', '-o', '-', '-w', "\n%{http_code}",
			'-u', $actor . ':' . $this->secrets['FIXTURE_PASSWORD'],
			'-H', 'Accept: application/json',
		];
		if (($request['method'] ?? 'GET') !== 'GET') {
			$args[] = '-X';
			$args[] = $request['method'];
			$args[] = '-H';
			$args[] = 'Content-Type: application/json';
			$args[] = '--data';
			$args[] = json_encode($request['body'] ?? [], JSON_THROW_ON_ERROR);
		}
		$query = isset($request['query']) ? '?' . http_build_query($request['query']) : '';
		$args[] = 'http://localhost/apps/arbeitszeitcheck' . $request['path'] . $query;
		$result = $this->composeExec('app', $args, true);
		if ($result['exitCode'] !== 0) {
			throw new RuntimeException(sprintf('HTTP probe %s failed: %s', $key, trim($result['stderr'])));
		}
		$body = $result['stdout'];
		$status = 0;
		if (preg_match('/\n(\d{3})\s*$/', $body, $m) === 1) {
			$status = (int)$m[1];
			$body = substr($body, 0, -strlen($m[0]));
		}
		$decoded = json_decode($body, true);
		$probe = [
			'key' => $key,
			'kind' => 'http',
			'status' => $status,
			'payload' => is_array($decoded) ? $decoded : hash('sha256', $body),
		];
		if (!is_array($decoded)) {
			$bodyPath = $this->artifactDir . '/probe-bodies/' . $phase . '-' . $variant . '-' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $key) . '.txt';
			if (!is_dir(dirname($bodyPath)) && !mkdir(dirname($bodyPath), 0755, true)) {
				throw new RuntimeException('Cannot create probe body artifact directory.');
			}
			file_put_contents($bodyPath, $body);
			$probe['bodyFile'] = basename($bodyPath);
			$probe['comparison'] = $this->canonicalText($body);
		} else {
			$probe['comparison'] = self::withoutVolatileFields($decoded);
		}
		return $probe;
	}

	private function expectedApiTransition(array $before, array $after): bool
	{
		$beforeStatus = (int)($before['status'] ?? 0);
		$afterStatus = (int)($after['status'] ?? 0);
		$endpoint = implode(':', array_slice(explode(':', (string)($before['key'] ?? $after['key'] ?? '')), 0, 2));
		$afterPayload = $after['comparison'] ?? $after['payload'] ?? null;

		// Several manifest probes intentionally cover endpoints that were introduced
		// after early source releases. Availability changes are accepted only for the
		// named operations and never for a target 5xx.
		if ($beforeStatus === 404 && $afterStatus >= 200 && $afterStatus < 300) {
			return true;
		}
		if ($beforeStatus === 404 && $afterStatus < 500) {
			$introducedLater = [
				'vacation:entitlement.year',
				'month_closure:closure.report',
				'exports_pdf:month.pdf',
				'navigation:navigation.items',
				'capabilities:capabilities.current',
				'mobile_bootstrap:mobile.bootstrap',
				'kiosk_bootstrap:kiosk.bootstrap',
			];
			if (in_array($endpoint, $introducedLater, true)) {
				return true;
			}
		}

		// These read/status endpoints were CSRF-blocked on old releases and are now
		// intentionally available to authenticated API clients. Require a successful
		// payload so this does not hide a genuine auth regression.
		if ($beforeStatus === 412 && $afterStatus === 200 && is_array($afterPayload) && ($afterPayload['success'] ?? false) === true) {
			$csrfOpened = [
				'vacation:entitlement.year',
				'query_latency:clock.happyPath',
			];
			if (in_array($endpoint, $csrfOpened, true)) {
				return true;
			}
		}

		// Sources without the mobile idempotency schema handle the replay as a
		// plain clock-in. Legacy ignored occurred_at: the write either succeeded
		// (200, persisted through the upgrade — mutating probes run before reads
		// in both phases), was refused because the seeded open stamp exists (400),
		// or errored on the duplicate attempt (500). The target validates the
		// stale timestamp first and deterministically answers 422 in all cases.
		if ($endpoint === 'mobile_offline_replay:mobile.replay'
			&& in_array($beforeStatus, [200, 400, 412, 500], true)
			&& $afterStatus === 422
			&& is_array($afterPayload)
			&& ($afterPayload['error_code'] ?? null) === 'STAMP_OCCURRED_AT_OUT_OF_BOUNDS'
			&& !($this->job['schemaCapabilities']['mobileKioskIdempotency'] ?? false)) {
			return true;
		}

		// The entitlement trace gained proration/source-tier fields. The legacy
		// entitlement result itself must be byte-identical; only additions pass.
		if ($endpoint === 'vacation:entitlement.year'
			&& $beforeStatus === 200
			&& $afterStatus === 200
			&& is_array($before['comparison'] ?? null)
			&& is_array($afterPayload)
		) {
			return self::isAdditiveOnlyPayload($before['comparison'], $afterPayload);
		}

		// Clock status serializes the current entry's work_location since the
		// Workforce Suite — additive only; every pre-existing field must match.
		if ($endpoint === 'query_latency:clock.happyPath'
			&& $beforeStatus === 200
			&& $afterStatus === 200
			&& is_array($before['comparison'] ?? null)
			&& is_array($afterPayload)
		) {
			return self::isAdditiveOnlyPayload($before['comparison'], $afterPayload);
		}

		// Aggregate presentation surfaces legitimately evolve across releases
		// (fields added, renamed, regrouped). Record-level integrity stays
		// enforced by the canonical DB compare; here we require success
		// semantics and acting-user identity to be preserved.
		static $surfaceAggregates = [
			'api_responses:dashboard.get' => true,
			'navigation:navigation.items' => true,
			'mobile_bootstrap:mobile.bootstrap' => true,
			'capabilities:capabilities.current' => true,
			'month_closure:closure.report' => true,
			'exports_pdf:month.pdf' => true,
		];
		if (isset($surfaceAggregates[$endpoint]) && $beforeStatus < 500) {
			$beforePayload = $before['comparison'] ?? $before['payload'] ?? null;
			$beforeSuccess = is_array($beforePayload) ? ($beforePayload['success'] ?? true) : true;
			$afterSuccess = is_array($afterPayload) ? ($afterPayload['success'] ?? true) : true;
			if ($beforeSuccess !== $afterSuccess) {
				return false;
			}
			if (is_array($beforePayload) && is_array($afterPayload)
				&& isset($beforePayload['data']['userId'], $afterPayload['data']['userId'])
				&& $beforePayload['data']['userId'] !== $afterPayload['data']['userId']) {
				return false;
			}
			return true;
		}

		// The forbidden audit response now uses the stable app error envelope. Status
		// and denial are still asserted; only the legacy message shape may change.
		if ($endpoint === 'audit:audit.list'
			&& $beforeStatus === 403
			&& $afterStatus === 403
			&& is_array($afterPayload)
			&& ($afterPayload['success'] ?? true) === false
			&& (($afterPayload['error']['code'] ?? null) === 'admin_required')) {
			return true;
		}

		if ($this->expectedExportTransition($before, $after) || $this->expectedMonthlyReportTransition($before, $after)) {
			return true;
		}

		return false;
	}

	private static function isAdditiveOnlyPayload(mixed $before, mixed $after): bool
	{
		if (!is_array($before) || !is_array($after)) {
			return $before === $after;
		}
		foreach ($before as $key => $beforeValue) {
			if (!array_key_exists($key, $after) || !self::isAdditiveOnlyPayload($beforeValue, $after[$key])) {
				return false;
			}
		}
		return true;
	}

	private function expectedExportTransition(array $before, array $after): bool
	{
		$endpoint = implode(':', array_slice(explode(':', (string)($before['key'] ?? '')), 0, 2));
		$beforeText = $before['comparison'] ?? $before['payload'] ?? '';
		$afterText = $after['comparison'] ?? $after['payload'] ?? '';
		if (($before['status'] ?? 0) !== 200 || ($after['status'] ?? 0) !== 200 || !is_string($beforeText) || !is_string($afterText)) {
			return false;
		}

		$splitLines = static function (string $text): array {
			return array_values(array_filter(
				preg_split('/\r?\n/', trim($text)) ?: [],
				static fn (string $line): bool => $line !== ''
			));
		};
		$beforeRows = $splitLines($beforeText);
		$afterRows = $splitLines($afterText);

		if ($endpoint === 'exports_csv:entries.csv') {
			if ($beforeText === "No data available\n") {
				// Empty exports moved from a bare message line to the current
				// metadata + named-field representation.
				return $afterRows === [
					'"# Timezone",Europe/Berlin',
					'"# Exported at","<volatile>"',
					'message,"No data available"',
				];
			}
			// The newer exporter adds a BOM, deterministic import metadata, an
			// integrity trailer, and a clock-type column. Remove exactly those fields;
			// the underlying entry rows must remain byte-identical.
			if (!str_starts_with($afterText, "\xEF\xBB\xBF")) {
				return false;
			}
			$afterRows = array_values(array_filter($afterRows, static function (string $line): bool {
				return !str_starts_with($line, 'Generated At')
					&& !str_starts_with($line, 'Format,csv')
					&& !str_starts_with($line, 'Import Mode')
					&& !str_starts_with($line, 'Structural Hash')
					&& !str_starts_with($line, 'Integrity');
			}));
			foreach ($afterRows as $index => $row) {
				if ($index === 0 && str_ends_with($row, ',Clock Type')) {
					$afterRows[$index] = substr($row, 0, -12);
				} elseif ($index > 0 && str_ends_with($row, ',manual')) {
					$afterRows[$index] = substr($row, 0, -7);
				}
			}
			return $afterRows === $beforeRows;
		}

		if ($endpoint === 'exports_datev:datev.export') {
			// DATEV gained explicit timezone/export metadata. Payroll cells must
			// otherwise remain byte-identical. Sources that already emit the
			// metadata keep it; stripping both sides is idempotent.
			$metadata = static function (string $line): bool {
				return preg_match('/^"# (Timezone|Exported at)",/', $line) !== 1;
			};
			$beforeRows = array_values(array_filter($beforeRows, $metadata));
			$afterRows = array_values(array_filter($afterRows, $metadata));
			foreach ($afterRows as $index => $row) {
				if ($index === 0) {
					continue;
				}
				$afterRows[$index] = preg_replace('/,\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', '', $row) ?? $row;
			}
			// A missing DATEV configuration now reports a precise message instead
			// of the generic export failure. The error contract (two-row error
			// shape, no payroll rows) is unchanged.
			if (count($beforeRows) === 2 && $beforeRows[0] === 'error'
				&& count($afterRows) === 2 && $afterRows[0] === 'error') {
				return true;
			}
			return $afterRows === $beforeRows;
		}

		return false;
	}

	private function expectedMonthlyReportTransition(array $before, array $after): bool
	{
		$endpoint = implode(':', array_slice(explode(':', (string)($before['key'] ?? '')), 0, 2));
		if ($endpoint !== 'query_latency:report.happyPath'
			|| ($before['status'] ?? 0) !== 200
			|| ($after['status'] ?? 0) !== 200) {
			return false;
		}
		$beforePayload = $before['comparison'] ?? $before['payload'] ?? null;
		$afterPayload = $after['comparison'] ?? $after['payload'] ?? null;
		if (!is_array($beforePayload) || !is_array($afterPayload) || !isset($beforePayload['report'], $afterPayload['report'])) {
			return false;
		}

		// Legacy monthly reports displayed the full month but calculated overtime
		// only until the request date. The current service intentionally calculates
		// the advertised period. Only additive analytics fields and that exact total
		// may change; every other serialized field must match. holiday_summary is
		// derived analytics whose counting was corrected across releases (stored
		// absence data stays byte-identical under the DB compare), so it is
		// excluded on both sides rather than pinned to either algorithm.
		$normalizedAfter = $afterPayload;
		unset($normalizedAfter['report']['holiday_summary']);
		unset($beforePayload['report']['holiday_summary']);
		if (!array_key_exists('work_location_days', $beforePayload['report'])) {
			unset($normalizedAfter['report']['work_location_days']);
		}
		if (!array_key_exists('workLocation', $beforePayload['report'])) {
			unset($normalizedAfter['report']['workLocation']);
		}
		$normalizedAfter['report']['total_overtime'] = $beforePayload['report']['total_overtime'] ?? null;
		if ($normalizedAfter !== $beforePayload) {
			return false;
		}

		$keyParts = explode(':', (string)($before['key'] ?? ''));
		$model = $this->scenarioUserModel($keyParts[2] ?? '');
		$workingDays = $afterPayload['report']['working_days'] ?? null;
		if (!is_array($model) || !is_numeric($workingDays)) {
			return false;
		}
		$expectedRequired = ((float)$model['weeklyMinutes'] / 60.0) * ((float)$workingDays / 5.0);
		$expectedOvertime = round((float)($afterPayload['report']['total_hours'] ?? 0.0) - $expectedRequired, 2);

		return abs((float)$afterPayload['report']['total_overtime'] - $expectedOvertime) < 0.001;
	}

	private function scenarioUserModel(string $userKey): ?array
	{
		$modelKey = null;
		foreach ($this->scenario['users'] ?? [] as $user) {
			if (($user['key'] ?? null) === $userKey) {
				$modelKey = $user['model'] ?? null;
				break;
			}
		}
		if (!is_string($modelKey)) {
			return null;
		}
		foreach ($this->scenario['models'] ?? [] as $model) {
			if (($model['key'] ?? null) === $modelKey) {
				return $model;
			}
		}
		return null;
	}

	private function probeRequest(array $endpoint): ?array
	{
		return match ($endpoint['operation']) {
			'dashboard.get' => ['path' => '/api/dashboard-widget/employee'],
			'timeEntries.list' => ['path' => '/api/time-entries'],
			'absences.list', 'absences.detail' => ['path' => '/api/absences'],
			'manager.pending' => ['path' => '/api/manager/pending-approvals'],
			'account.current' => ['path' => '/api/time-entries/overtime/balance'],
			'overtime.year' => ['path' => '/api/time-entries/overtime'],
			'violations.month' => ['path' => '/api/compliance/violations'],
			'entitlement.year' => ['path' => '/api/absences/entitlement-trace'],
			'closure.report' => ['path' => '/api/month-closure/status', 'query' => ['month' => $endpoint['month'] ?? '']],
			'entries.csv' => ['path' => '/export/time-entries'],
			'month.pdf' => ['path' => '/api/month-closure/pdf', 'query' => ['month' => $endpoint['month'] ?? '2025-11']],
			'datev.export' => ['path' => '/export/datev'],
			'navigation.items' => ['path' => '/api/mobile/bootstrap'],
			'capabilities.current' => ['path' => '/api/month-closure/feature'],
			'mobile.bootstrap' => ['path' => '/api/mobile/bootstrap'],
			'mobile.replay' => ['path' => '/api/clock/in', 'method' => 'POST', 'body' => ['client_request_id' => 'legacy-mobile-complete', 'occurred_at' => '2026-01-20T07:00:00+01:00']],
			'kiosk.bootstrap' => ['path' => '/api/kiosk/config'],
			'kiosk.replay' => null,
			'audit.list' => ['path' => '/api/admin/audit-logs'],
			'clock.happyPath' => ['path' => '/api/clock/status'],
			'absence.happyPath' => ['path' => '/api/absences/stats'],
			'report.happyPath' => ['path' => '/api/reports/monthly'],
			'mail.sent', 'ical.feed', 'deskcheck.presence', 'dutycheck.availability' => null,
			'snapshot.hash' => null,
			default => throw new RuntimeException('No executable request mapping for baseline operation ' . $endpoint['operation']),
		};
	}

	private static function withoutVolatileFields(mixed $value, array $path = []): mixed
	{
		if (!is_array($value)) {
			return $value;
		}
		// Elapsed-session fields are derived from wall-clock probe time and differ
		// by construction between the pre and post phases.
		static $volatile = [
			'serverNow' => true, 'server_now' => true,
			'requestId' => true, 'request_id' => true,
			'generatedAt' => true, 'generated_at' => true,
			'currentSessionDuration' => true, 'current_session_duration' => true,
			'sessionStartFormatted' => true, 'session_start_formatted' => true,
			'workingTodayHours' => true, 'working_today_hours' => true,
			'sessionHoursOnCalendarToday' => true, 'session_hours_on_calendar_today' => true,
		];
		foreach ($value as $key => $item) {
			if (!is_int($key) && (isset($volatile[$key]) || ($key === 'version' && end($path) === 'capabilities'))) {
				unset($value[$key]);
				continue;
			}
			if (!is_int($key) && in_array('auto_clockout_notice', $path, true)) {
				if ($key === 'at') {
					$value[$key] = '<volatile>';
					continue;
				}
				if ($key === 'message' && is_string($item)) {
					$value[$key] = preg_replace('/\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}/', '<volatile>', $item);
					continue;
				}
			}
			$value[$key] = self::withoutVolatileFields($item, [...$path, (string)$key]);
		}
		return $value;
	}

	private function canonicalText(string $body): string
	{
		$body = str_replace("\r\n", "\n", $body);
		return preg_replace('/^"# Exported at",.*$/m', '"# Exported at","<volatile>"', $body) ?? $body;
	}

	private function compareApiProbes(array $pre, array $post): array
	{
		$differences = [];
		$postByKey = array_column($post, null, 'key');
		foreach ($pre as $probe) {
			$other = $postByKey[$probe['key']] ?? null;
			if ($other === null) {
				$differences[] = $probe['key'] . ': missing post-upgrade probe';
				continue;
			}
			if ($probe['kind'] !== 'http') {
				if ($probe['payload'] !== $other['payload']) {
					$differences[] = $probe['key'] . ': external fixture changed';
				}
				continue;
			}
			if ($this->expectedApiTransition($probe, $other)) {
				continue;
			}
			if ($probe['status'] !== $other['status'] || ($probe['comparison'] ?? $probe['payload']) !== ($other['comparison'] ?? $other['payload'])) {
				$differences[] = $probe['key'] . ': HTTP probe changed';
			}
		}
		return $differences;
	}

	private function introspectSchema(): array
	{
		$rows = $this->job['database'] === 'mysql'
			? $this->jsonRows("SELECT JSON_OBJECT('table', TABLE_NAME, 'column', COLUMN_NAME, 'type', DATA_TYPE, 'nullable', IS_NULLABLE, 'default', COLUMN_DEFAULT, 'auto', EXTRA LIKE '%auto_increment%') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'nextcloud' AND TABLE_NAME LIKE 'oc\\_at\\_%' ORDER BY TABLE_NAME, ORDINAL_POSITION")
			: $this->jsonRows("SELECT row_to_json(t)::text FROM (SELECT table_name AS \"table\", column_name AS \"column\", data_type AS \"type\", is_nullable AS \"nullable\", column_default AS \"default\", (is_identity = 'YES' OR column_default LIKE 'nextval%') AS \"auto\" FROM information_schema.columns WHERE table_schema = 'public' AND table_name LIKE 'oc\\_at\\_%' ESCAPE '\\' ORDER BY table_name, ordinal_position) t");
		$schema = [];
		foreach ($rows as $row) {
			$logical = substr($row['table'], 3);
			$schema[$logical]['columns'][$row['column']] = [
				'type' => $row['type'],
				'nullable' => $row['nullable'] === 'YES' || $row['nullable'] === true,
				'default' => $row['default'],
				'auto' => $row['auto'] === true || $row['auto'] === 1,
			];
		}
		ksort($schema);
		return $schema;
	}

	private function jsonRows(string $sql): array
	{
		$out = trim($this->dbQuery($sql));
		if ($out === '') {
			return [];
		}
		return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", $out));
	}

	private function sqlIdentifier(string $identifier): string
	{
		return $this->job['database'] === 'mysql'
			? '`' . str_replace('`', '``', $identifier) . '`'
			: '"' . str_replace('"', '""', $identifier) . '"';
	}

	private function sqlString(string $value): string
	{
		return "'" . str_replace("'", "''", $value) . "'";
	}

	private function dbExec(string $sql, bool $root = false): void
	{
		if ($this->job['database'] === 'mysql') {
			$user = $root ? 'root' : 'nextcloud';
			$password = $root ? $this->secrets['DB_ROOT_PASSWORD'] : $this->secrets['DB_PASSWORD'];
			$this->cmd($this->composeArgs(['exec', '-T', 'db', 'mariadb', '-u', $user, '-p' . $password, 'nextcloud', '-e', $sql]));
			return;
		}
		$this->cmd($this->composeArgs(['exec', '-T', '-e', 'PGPASSWORD=' . $this->secrets['DB_PASSWORD'], 'db', 'psql', '-U', 'nextcloud', '-d', 'nextcloud', '-v', 'ON_ERROR_STOP=1', '-q', '-c', $sql]));
	}

	private function dbQuery(string $sql, string $database = 'nextcloud'): string
	{
		if ($this->job['database'] === 'mysql') {
			return $this->cmd($this->composeArgs(['exec', '-T', 'db', 'mariadb', '-u', 'nextcloud', '-p' . $this->secrets['DB_PASSWORD'], $database, '--batch', '--raw', '--skip-column-names', '-e', $sql]))['stdout'];
		}
		return $this->cmd($this->composeArgs(['exec', '-T', '-e', 'PGPASSWORD=' . $this->secrets['DB_PASSWORD'], 'db', 'psql', '-U', 'nextcloud', '-d', $database, '-qAt', '-c', $sql]))['stdout'];
	}

	private function dbQueryScalar(string $sql, string $database = 'nextcloud'): string
	{
		return trim($this->dbQuery($sql, $database));
	}

	private function occ(array $args, array $env = [], bool $allowFail = false): string
	{
		return $this->occResult($args, $env, $allowFail)['stdout'];
	}

	private function occResult(array $args, array $env = [], bool $allowFail = false): array
	{
		return $this->composeExec('app', array_merge(['php', 'occ'], $args), $allowFail, $env);
	}

	private function composeExec(string $service, array $args, bool $allowFail = false, array $env = []): array
	{
		$exec = ['exec', '-T'];
		foreach ($env as $key => $value) {
			$exec[] = '-e';
			$exec[] = $key . '=' . $value;
		}
		$exec[] = $service;
		return $this->cmd($this->composeArgs(array_merge($exec, $args)), null, $allowFail);
	}

	private function compose(array $args): array
	{
		return $this->cmd($this->composeArgs($args));
	}

	private function composeArgs(array $args): array
	{
		return ['docker', 'compose', '--env-file', $this->envFile, '-f', $this->appRoot . '/' . $this->job['composeFile'], '-p', $this->job['composeProject'], ...$args];
	}

	private function cmd(array $args, ?string $stdin = null, bool $allowFail = false): array
	{
		$process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->appRoot);
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start process ' . $args[0]);
		}
		if ($stdin !== null) {
			fwrite($pipes[0], $stdin);
		}
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);
		$result = ['exitCode' => $code, 'stdout' => $stdout === false ? '' : $stdout, 'stderr' => $stderr === false ? '' : $stderr];
		$this->result['commands'][] = ['cmd' => self::redact($args), 'exitCode' => $code];
		if (!$allowFail && $code !== 0) {
			throw new RuntimeException("Command failed ({$code}): " . self::redact($args) . "\n" . $result['stderr'] . "\n" . $result['stdout']);
		}
		return $result;
	}

	private static function redact(array $args): string
	{
		$redacted = [];
		$hideNext = false;
		foreach ($args as $arg) {
			if ($hideNext) {
				$redacted[] = '[redacted]';
				$hideNext = false;
				continue;
			}
			if ($arg === '-e' || str_starts_with($arg, '-pazc_') || str_starts_with($arg, '--password')) {
				$redacted[] = $arg;
				$hideNext = $arg === '-e';
				continue;
			}
			if (str_contains($arg, 'PASSWORD=') || str_contains($arg, 'PASS=') || str_contains($arg, 'DB_ROOT_PASSWORD=')) {
				$redacted[] = '[redacted]';
				continue;
			}
			$redacted[] = preg_match('/^[A-Za-z0-9_\.\/:=@+-]+$/', $arg) === 1 ? $arg : escapeshellarg($arg);
		}
		return implode(' ', $redacted);
	}

	private function copyWorktree(string $source, string $target): void
	{
		$result = $this->cmd(['git', '-C', $source, 'ls-files', '-z', '--cached', '--others', '--exclude-standard']);
		foreach (explode("\0", $result['stdout']) as $file) {
			if ($file === '' || preg_match('#^(node_modules|node_modules\.broken-[^/]*|build|release|test-results)/#', $file) === 1) {
				continue;
			}
			$from = $source . '/' . $file;
			$to = $target . '/' . $file;
			if (!is_file($from)) {
				continue;
			}
			if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0755, true)) {
				throw new RuntimeException('Cannot create target directory ' . dirname($to));
			}
			if (!copy($from, $to)) {
				throw new RuntimeException('Cannot copy worktree file ' . $file);
			}
			chmod($to, fileperms($from) & 0777);
		}
		$vendor = $source . '/vendor';
		if (is_dir($vendor)) {
			$this->copyDirectory($vendor, $target . '/vendor');
		}
	}

	private function copyDirectory(string $source, string $target): void
	{
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			$to = $target . '/' . substr($file->getPathname(), strlen($source) + 1);
			if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0755, true)) {
				throw new RuntimeException('Cannot create target directory ' . dirname($to));
			}
			copy($file->getPathname(), $to);
		}
	}

	private function removeTree(string $dir): void
	{
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($iterator as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($dir);
	}

	private function treeHash(string $dir): string
	{
		$files = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			$files[] = substr($file->getPathname(), strlen($dir) + 1);
		}
		sort($files);
		$payload = [];
		foreach ($files as $file) {
			$payload[$file] = hash_file('sha256', $dir . '/' . $file);
		}
		return LegacyBaselineCanonicalizer::hash($payload);
	}

	private static function envFile(array $values): string
	{
		$out = '';
		foreach ($values as $key => $value) {
			$out .= $key . '=' . str_replace(["\n", "\r"], '', (string)$value) . "\n";
		}
		return $out;
	}

	private static function secret(string $name): string
	{
		return 'azc_' . $name . '_' . bin2hex(random_bytes(18));
	}

	private function writeJson(string $path, mixed $value): void
	{
		file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}

	private function readJson(string $path): array
	{
		return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}

	private function step(string $name, callable $fn): void
	{
		if (!in_array($name, self::EXPECTED_STEPS, true)) {
			throw new RuntimeException('Unknown upgrade step ' . $name);
		}
		$started = microtime(true);
		$fn();
		$this->result['steps'][] = ['name' => $name, 'durationMs' => (int)round((microtime(true) - $started) * 1000)];
		fwrite(STDERR, "[{$this->job['composeProject']}] {$name}\n");
	}
}

$options = getopt('', ['tag:', 'database:', 'server-version::', 'artifacts-root::', 'keep']);
$tag = $options['tag'] ?? null;
$database = $options['database'] ?? null;
$serverVersion = $options['server-version'] ?? '35';
if (!is_string($tag) || !is_string($database) || !is_string($serverVersion)) {
	fwrite(STDERR, "Usage: php tests/run-legacy-upgrade-job.php --tag=<tag> --database=<mysql|pgsql> [--server-version=<32|35>] [--artifacts-root=<dir>] [--keep]\n");
	exit(2);
}

$fixture = $root . '/tests/fixtures';
$scenario = LegacyBaselineScenario::load($fixture . '/legacy-baseline-scenario.json');
$seedPlan = LegacyBaselineSeedPlan::load($fixture . '/legacy-baseline-seed-plan.json', $scenario);
$manifest = LegacyBaselineManifest::load($fixture . '/legacy-baseline-manifest.json');
$matrix = LegacyUpgradeMatrix::load($fixture . '/legacy-upgrade-matrix.json');
$schemaMap = LegacySchemaFamilyMap::load($fixture . '/legacy-schema-families.json', $matrix);
$runtimeMap = LegacyRuntimeProfileMap::load($fixture . '/legacy-runtime-profiles.json', $matrix);
$plan = LegacyUpgradeExecutionPlan::build($matrix, $manifest, $seedPlan, $scenario, $schemaMap, $runtimeMap);
$job = null;
foreach ($plan['jobs'] as $candidate) {
	if ($candidate['sourceTag'] === $tag && $candidate['database'] === $database && $candidate['serverImage'] === 'nextcloud:' . $serverVersion) {
		$job = $candidate;
		break;
	}
}
if ($job === null) {
	fwrite(STDERR, "No production upgrade job for {$tag}/nc{$serverVersion}/{$database}\n");
	exit(2);
}

$runner = new LegacyUpgradeJobRunner(
	$root,
	$job,
	$scenario,
	$seedPlan,
	$manifest,
	$options['artifacts-root'] ?? (sys_get_temp_dir() . '/azc-legacy-upgrade'),
	isset($options['keep']),
);

try {
	$result = $runner->run();
} catch (Throwable $e) {
	fwrite(STDERR, "FAILED {$tag}/{$database}: {$e->getMessage()}\n");
	exit(1);
}
echo json_encode(['status' => $result['status'], 'job' => $job['artifactKey'], 'steps' => array_column($result['steps'], 'name')], JSON_UNESCAPED_SLASHES) . "\n";
