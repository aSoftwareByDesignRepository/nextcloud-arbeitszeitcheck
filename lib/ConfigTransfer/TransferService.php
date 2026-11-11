<?php

declare(strict_types=1);

/**
 * The single transfer pipeline — export → encrypted container → validate →
 * dry-run report → atomic apply (merge | replace). The web controller and the
 * occ commands are thin front-ends over this service; there is no second
 * implementation to drift.
 *
 * Guarantees implemented here (spec §4/§9/§10):
 *  - always-encrypted container; secrets additionally redacted inside it
 *  - dry run is mandatory — no apply path that skips the report
 *  - per-value validation through the app's own settings validators (hooks)
 *  - uid/gid refs existence-checked; unresolved rows skipped + reported
 *  - id remapping across declared depends_on chains incl. late (self/cyclic) FKs
 *  - whole apply inside ONE DB transaction → no half-applied imports ever
 *  - exclusive mutex; replace mode needs system admin + maintenance + typed
 *    confirm + automatic safety snapshot before the first DELETE
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\ConfigTransfer;

use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

final class TransferService
{
	public const MODE_MERGE = 'merge';
	public const MODE_REPLACE = 'replace';
	public const REPLACE_TOKEN = 'REPLACE';
	public const UI_MAX_FILE_BYTES = 26214400; // 25 MiB UI upload ceiling

	private const LOCK_SUFFIX = '-config-transfer';
	private const MAX_VALUE_DISPLAY = 120;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IRootFolder $rootFolder,
		private readonly IAppManager $appManager,
		private readonly ILockingProvider $locking,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly AbstractTransferManifest $manifest,
		private readonly ConfigTransferHooks $hooks,
		private readonly TransferPendingStore $pendingStore,
		private readonly TransferReceiptStore $receiptStore,
		private readonly string $appId,
		private readonly ?IAppConfig $appConfig = null,
	) {
	}

	// ─────────────────────────────── export ──────────────────────────────

	/**
	 * Build the sealed `checkapp-config-transfer/2` container bytes.
	 */
	public function exportContainer(string $passphrase, bool $includeSecrets = false): string
	{
		$this->assertManifestSane();
		TransferCrypto::assertPassphraseStrength($passphrase);

		$config = [];
		$references = [];
		foreach ($this->manifest->keys() as $key => $spec) {
			if (in_array($spec['class'], ['internal', 'env'], true)) {
				continue;
			}
			if (!$this->keyExists($key, $spec)) {
				continue; // unset on source → untouched on target
			}
			if ($spec['class'] === 'secret' && !$includeSecrets) {
				$config[$key] = TransferFormat::REDACTED_MARKER;
			} else {
				$config[$key] = $this->readKeyValue($key, $spec) ?? '';
			}
			if ($spec['refs'] !== null) {
				$references[$key] = $spec['refs']['kind'];
			}
		}

		$tables = [];
		foreach ($this->manifest->transferableTablesSorted() as $table) {
			if (!$this->db->tableExists($table)) {
				continue;
			}
			$spec = $this->manifest->tableSpec($table);
			$rows = null;
			foreach ([true, false] as $orderById) {
				try {
					$qb = $this->db->getQueryBuilder();
					$qb->select('*')->from($table);
					if ($orderById) {
						$qb->orderBy('id', 'ASC');
					}
					$result = $qb->executeQuery();
					$rows = $result->fetchAll();
					$result->closeCursor();
					break;
				} catch (\Throwable $e) {
					if (!$orderById) {
						throw new TransferException('TRANSFER_APPLY_FAILED', ['step' => 'export_table', 'table' => $table], $e);
					}
				}
			}
			if ($rows === null) {
				throw new TransferException('TRANSFER_APPLY_FAILED', ['step' => 'export_table', 'table' => $table]);
			}
			if (count($rows) > TransferFormat::MAX_TABLE_ROWS) {
				throw new TransferException('TRANSFER_FILE_TOO_LARGE', ['table' => $table]);
			}
			$secretCols = array_fill_keys($spec['secret_columns'], 0);
			$envCols = array_fill_keys($spec['env_columns'], 0);
			$outRows = [];
			foreach ($rows as $row) {
				$out = [];
				foreach ($row as $col => $value) {
					$col = (string)$col;
					if (isset($spec['secret_columns']) && in_array($col, $spec['secret_columns'], true)) {
						if ($value !== null && $value !== '') {
							$out[$col] = TransferFormat::REDACTED_MARKER;
							$secretCols[$col]++;
						} else {
							$out[$col] = null;
						}
						continue;
					}
					if (in_array($col, $spec['env_columns'], true)) {
						if ($value !== null && $value !== '') {
							$envCols[$col]++;
						}
						$out[$col] = null;
						continue;
					}
					$out[$col] = $value;
				}
				$outRows[] = $out;
			}
			$tables[$table] = [
				'class' => $spec['class'],
				'natural_key' => $spec['natural_key'],
				'columns' => $this->columnTypeFamilies($table),
				'secret_redacted' => array_filter($secretCols),
				'env_dropped' => array_filter($envCols),
				'rows' => $outRows,
			];
		}

		$appdata = $this->exportAppData();

		$payload = [
			'config' => $config,
			'references' => $references,
			'tables' => $tables,
			'appdata' => $appdata,
			'source_instance' => (string)$this->config->getSystemValue('instanceid', ''),
			'source_url' => $this->urlGenerator->getBaseUrl(),
			'secrets_included' => $includeSecrets,
		];
		$payload['checksum'] = TransferFormat::checksum($payload);
		$payload = ['checksum' => $payload['checksum']] + $payload;

		$header = [
			'format' => TransferFormat::FORMAT,
			'app' => $this->appId,
			'app_version' => (string)$this->appManager->getAppVersion($this->appId, false),
			'schema_version' => $this->manifest->schemaVersion(),
			'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'source_instance' => $payload['source_instance'],
		];
		$sealed = TransferCrypto::seal(
			TransferFormat::compress(json_encode($payload, JSON_THROW_ON_ERROR)),
			$passphrase,
			TransferFormat::aadFor($header),
		);
		$envelope = $header + $sealed;

		$this->auditSafely('export', $this->currentActor(), [
			'app' => $this->appId,
			'keys' => count($config),
			'tables' => count($tables),
			'checksum' => $payload['checksum'],
			'secretsIncluded' => $includeSecrets,
		]);
		$this->logger->info($this->appId . ': config transfer export produced', [
			'app' => $this->appId,
			'keys' => count($config),
			'tables' => count($tables),
		]);

		return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
	}

	// ──────────────────────────── dry run / plan ─────────────────────────

	/**
	 * Web path: decrypt + validate + build plan + persist pending blob.
	 *
	 * @return array{pendingId:string,report:array<string,mixed>}
	 */
	public function preview(string $bytes, string $passphrase, string $mode, string $actor): array
	{
		if (strlen($bytes) > self::UI_MAX_FILE_BYTES) {
			throw new TransferException('TRANSFER_FILE_TOO_LARGE', ['limit' => self::UI_MAX_FILE_BYTES]);
		}
		$this->assertMode($mode);
		$decoded = $this->decryptAndValidate($bytes, $passphrase);
		$this->pendingStore->sweep();
		$pendingId = $this->pendingStore->save([
			'payload' => $decoded['payload'],
			'header' => $decoded['header'],
			'mode' => $mode,
			'actor' => $actor,
		]);
		$report = $this->buildPlan($decoded['payload'], $decoded['header'], $mode, $actor)['report'];
		return ['pendingId' => $pendingId, 'report' => $report];
	}

	/**
	 * occ path: decrypt + validate + plan, without persisting a pending blob.
	 *
	 * @return array{header:array,payload:array,report:array}
	 */
	public function planForFile(string $bytes, string $passphrase, string $mode, string $actor): array
	{
		$this->assertMode($mode);
		$decoded = $this->decryptAndValidate($bytes, $passphrase);
		$plan = $this->buildPlan($decoded['payload'], $decoded['header'], $mode, $actor);
		return ['header' => $decoded['header'], 'payload' => $decoded['payload'], 'report' => $plan['report'], 'plan' => $plan];
	}

	/**
	 * @return array{header:array<string,mixed>,payload:array<string,mixed>}
	 */
	private function decryptAndValidate(string $bytes, string $passphrase): array
	{
		$parsed = TransferFormat::parseEnvelope($bytes, $this->appId, $this->manifest->schemaVersion());
		$plaintext = TransferCrypto::open(
			['kdf' => $parsed['header']['kdf'], 'cipher' => $parsed['header']['cipher'], 'payload_b64' => $parsed['payload_b64']],
			$passphrase,
			$parsed['aad'],
		);
		$payload = TransferFormat::parsePayload(TransferFormat::decompressCapped($plaintext));
		return ['header' => $parsed['header'], 'payload' => $payload];
	}

	// ─────────────────────────────── apply ───────────────────────────────

	/**
	 * Web/job path: load the pending blob and run the atomic apply.
	 *
	 * @return array<string,mixed> receipt
	 */
	public function applyPending(string $pendingId, string $actor, bool $replaceConfirmed = false, bool $backupLockHeld = false): array
	{
		$record = $this->pendingStore->load($pendingId);
		$mode = (string)($record['mode'] ?? self::MODE_MERGE);
		$payload = $record['payload'];
		$header = is_array($record['header'] ?? null) ? $record['header'] : [];
		try {
			$receipt = $this->applyPayload($payload, $header, $mode, $actor, $replaceConfirmed, $backupLockHeld);
		} finally {
			$this->pendingStore->delete($pendingId);
		}
		return $receipt;
	}

	/**
	 * Queued-job entry: identical pipeline, actor from job args. The job
	 * passes $backupLockHeld = true — it already holds the shared
	 * upgrade-backup lock for the duration of the apply.
	 */
	public function runApplyJob(string $pendingId, string $actor, bool $replaceConfirmed, bool $backupLockHeld = false): void
	{
		try {
			$this->applyPending($pendingId, $actor, $replaceConfirmed, $backupLockHeld);
		} catch (\Throwable $e) {
			$this->logger->error($this->appId . ': config transfer apply job failed', [
				'app' => $this->appId, 'pendingId' => $pendingId, 'exception' => $e,
			]);
		}
	}

	/**
	 * The atomic apply — used by web job, occ --apply, and tests.
	 *
	 * @return array<string,mixed> receipt
	 */
	public function applyPayload(array $payload, array $header, string $mode, string $actor, bool $replaceConfirmed, bool $backupLockHeld = false): array
	{
		$this->assertManifestSane();
		$this->assertMode($mode);

		// ── permission gates (re-checked here — never trust the caller) ──
		if ($mode === self::MODE_REPLACE) {
			if ($actor !== '' && !$this->groupManager->isAdmin($actor)) {
				throw new TransferException('TRANSFER_ADMIN_REQUIRED');
			}
			if (!$replaceConfirmed) {
				throw new TransferException('TRANSFER_CONFIRM_REQUIRED');
			}
			$this->assertModeRunnable($mode);
		}

		// ── exclusive mutex — one transfer at a time, whoever runs it ──
		$lockKey = $this->appId . self::LOCK_SUFFIX;
		try {
			$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, 'config transfer apply');
		} catch (LockedException $e) {
			$state = $this->receiptStore->jobState() ?? [];
			throw new TransferException('TRANSFER_IN_PROGRESS', [
				'actor' => (string)($state['actor'] ?? ''),
				'startedAt' => (string)($state['startedAt'] ?? ''),
			], $e);
		}

		// Shared upgrade-backup lock (family lock contract): a restore holds
		// the same key exclusively while it truncates+reinserts app tables —
		// an apply must never interleave with that. The queued job holds it
		// for the whole run ($backupLockHeld) so the lock is never acquired
		// twice; every other caller (occ --apply, tests) acquires it here.
		$backupLockAcquired = false;
		if (!$backupLockHeld) {
			try {
				$this->locking->acquireLock(
					UpgradeBackupService::LOCK_KEY,
					ILockingProvider::LOCK_SHARED,
					'config transfer apply',
				);
				$backupLockAcquired = true;
			} catch (LockedException $e) {
				$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				throw new TransferException('TRANSFER_BACKUP_IN_PROGRESS', [], $e);
			}
		}

		$startedAt = time();
		$this->receiptStore->setJobState('running', 'validating', 10, [
			'actor' => $actor, 'mode' => $mode, 'startedAt' => $startedAt,
		]);

		$snapshotId = null;
		$receipt = [
			'id' => gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)),
			'at' => gmdate('Y-m-d\TH:i:s\Z'),
			'actor' => $actor,
			'mode' => $mode,
			'result' => 'failed',
			'checksum' => (string)($payload['checksum'] ?? ''),
			'exportedAt' => (string)($header['exported_at'] ?? $payload['exported_at'] ?? ''),
			'snapshotId' => null,
			'counts' => [],
			'error' => null,
		];

		try {
			// ── plan (re-built at apply time — target state may have moved) ──
			$plan = $this->buildPlan($payload, $header, $mode, $actor);
			$this->assertPlanApplicable($plan);

			// ── pre-flight (FS-8/FS-12) ──
			$this->receiptStore->setJobState('running', 'preflight', 15, ['actor' => $actor, 'startedAt' => $startedAt]);
			$this->preflight($payload, $mode);

			// ── replace: safety snapshot BEFORE the first DELETE (FS-2) ──
			if ($mode === self::MODE_REPLACE) {
				$this->receiptStore->setJobState('running', 'snapshot', 20, ['actor' => $actor, 'startedAt' => $startedAt]);
				$snapshotId = $this->hooks->createSafetySnapshot('pre-transfer-replace-' . $receipt['id']);
				$receipt['snapshotId'] = $snapshotId;
			}

			$baselineDangling = $this->danglingReferenceCount();

			$this->db->beginTransaction();
			try {
				$this->receiptStore->setJobState('running', 'config', 40, ['actor' => $actor, 'startedAt' => $startedAt]);
				$this->applyConfigPlan($plan);

				$this->receiptStore->setJobState('running', 'tables', 60, ['actor' => $actor, 'startedAt' => $startedAt]);
				$this->applyTablePlan($plan, $mode);

				$this->receiptStore->setJobState('running', 'verify', 85, ['actor' => $actor, 'startedAt' => $startedAt]);
				$afterDangling = $this->danglingReferenceCount();
				if ($afterDangling > $baselineDangling) {
					throw new TransferException('TRANSFER_APPLY_FAILED', [
						'step' => 'referential_integrity',
						'before' => $baselineDangling,
						'after' => $afterDangling,
					]);
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			$receipt['result'] = 'applied';
			$receipt['counts'] = $plan['report']['counts'];
			$this->receiptStore->setJobState('done', 'finished', 100, ['actor' => $actor, 'startedAt' => $startedAt]);
			$this->auditSafely('import', $actor, [
				'mode' => $mode,
				'result' => 'applied',
				'checksum' => $receipt['checksum'],
				'counts' => $receipt['counts'],
				'snapshotId' => $snapshotId,
			]);
			$this->logger->warning($this->appId . ': config transfer import applied', [
				'app' => $this->appId, 'mode' => $mode, 'counts' => $receipt['counts'],
			]);
		} catch (\Throwable $e) {
			$receipt['error'] = $e instanceof TransferException ? $e->getMessage() : 'TRANSFER_APPLY_FAILED';
			$this->receiptStore->setJobState('failed', 'failed', 100, [
				'actor' => $actor, 'startedAt' => $startedAt, 'errorCode' => $receipt['error'],
			]);
			$this->auditSafely('import', $actor, [
				'mode' => $mode,
				'result' => 'failed',
				'error' => $receipt['error'],
				'checksum' => $receipt['checksum'],
			]);
			$this->logger->error($this->appId . ': config transfer import rolled back — nothing was changed', [
				'app' => $this->appId, 'mode' => $mode, 'exception' => $e,
			]);
			if ($e instanceof TransferException) {
				throw $e;
			}
			throw new TransferException('TRANSFER_APPLY_FAILED', [], $e);
		} finally {
			// Receipt persistence must never strand the exclusive lock — a
			// failing appconfig write would otherwise block all future
			// transfers until the lock TTL expired.
			try {
				$this->receiptStore->addReceipt($receipt);
			} catch (\Throwable $receiptError) {
				$this->logger->error($this->appId . ': config transfer receipt write failed', [
					'app' => $this->appId, 'exception' => $receiptError,
				]);
			}
			if ($backupLockAcquired) {
				$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
			}
			$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}

		return $receipt;
	}

	/**
	 * `occ <app>:config:reconcile` — re-run the mirrored-key writer with the
	 * current stored values so a raw `occ config:import` can be healed.
	 *
	 * @return array<string,int>
	 */
	public function reconcile(): array
	{
		$mirrored = [];
		foreach ($this->manifest->keys() as $key => $spec) {
			if ($spec['class'] === 'mirrored' && $this->keyExists($key, $spec)) {
				$mirrored[$key] = $this->readKeyValue($key, $spec) ?? '';
			}
		}
		if ($mirrored !== []) {
			$this->hooks->writeMirrored($mirrored);
		}
		$this->auditSafely('reconcile', $this->currentActor(), ['keys' => count($mirrored)]);
		return ['mirroredKeys' => count($mirrored)];
	}

	/**
	 * @return array{job:?array,lastReceipt:?array,receipts:list<array>}
	 */
	public function status(): array
	{
		return [
			'job' => $this->receiptStore->jobState(),
			'lastReceipt' => $this->receiptStore->lastReceipt(),
			'receipts' => $this->receiptStore->receipts(),
		];
	}

	// ─────────────────────────── plan building ───────────────────────────

	/**
	 * Compute the full apply plan + human report. Pure w.r.t. writes: reads
	 * target state, never mutates. Used by preview AND by applyPayload.
	 *
	 * @return array{report:array,configWrites:array<string,string>,mirroredWrites:array<string,string>,tablePlan:array}
	 */
	private function buildPlan(array $payload, array $header, string $mode, string $actor): array
	{
		$report = [
			'mode' => $mode,
			'file' => [
				'app' => (string)($header['app'] ?? $this->appId),
				'app_version' => (string)($header['app_version'] ?? ''),
				'schema_version' => (int)($header['schema_version'] ?? 0),
				'exported_at' => (string)($header['exported_at'] ?? ''),
				'source_instance' => (string)($payload['source_instance'] ?? ''),
				'checksum' => (string)($payload['checksum'] ?? ''),
			],
			'warnings' => [],
			'security' => [],
			'unresolved' => [],
			'secrets' => [],
			'envBound' => [],
			'config' => ['added' => [], 'changed' => [], 'unchanged' => [], 'skipped' => [], 'invalid' => [], 'unknown' => []],
			'tables' => [],
			'tablesUnknown' => [],
			'counts' => ['configApplied' => 0, 'rowsInserted' => 0, 'rowsUpdated' => 0, 'rowsSkipped' => 0, 'rowsDeleted' => 0, 'refsUnresolved' => 0],
			'verdict' => 'changes',
		];

		// ── warnings (FS-4) ──
		$sourceInstance = (string)($payload['source_instance'] ?? '');
		if ($sourceInstance !== '' && $sourceInstance === (string)$this->config->getSystemValue('instanceid', '')) {
			$report['warnings'][] = ['code' => 'same_instance', 'detail' => $sourceInstance];
		}
		$exportedAt = strtotime((string)($header['exported_at'] ?? '')) ?: 0;
		$lastImport = $this->receiptStore->lastImportAt();
		if ($exportedAt > 0 && $lastImport > 0 && $exportedAt < $lastImport) {
			$report['warnings'][] = ['code' => 'stale_file', 'detail' => (string)$header['exported_at']];
		}
		if ($mode === self::MODE_REPLACE && !$this->config->getSystemValueBool('maintenance')) {
			$report['warnings'][] = ['code' => 'maintenance_required', 'detail' => 'occ maintenance:mode --on'];
		}

		// ── config plan ──
		$configWrites = [];
		$mirroredWrites = [];
		$securityKeys = array_fill_keys($this->hooks->securityRelevantKeys(), true);
		$sourceUrl = trim((string)($payload['source_url'] ?? ''));

		$fileConfig = is_array($payload['config'] ?? null) ? $payload['config'] : [];
		foreach ($fileConfig as $key => $value) {
			$key = (string)$key;
			$spec = $this->manifest->keySpec($key);
			if ($spec === null) {
				$report['config']['unknown'][] = $key;
				continue;
			}
			if (in_array($spec['class'], ['internal', 'env'], true)) {
				$report['config']['skipped'][] = ['key' => $key, 'reason' => 'internal'];
				continue;
			}
			if ($spec['class'] === 'secret') {
				if (TransferFormat::isRedacted($value)) {
					$report['secrets'][] = [
						'key' => $key,
						'state' => $this->keyExists($key, $spec) ? 'untouched' : 'pending',
						'section' => $this->hooks->settingsSectionForKey($key),
					];
					continue;
				}
				// include-secrets export → a real secret value: it may be
				// applied like any other value (inside the encrypted flow).
				if (!is_string($value)) {
					$report['config']['invalid'][] = ['key' => $key, 'code' => 'BAD_TYPE'];
					continue;
				}
			}
			if (!is_string($value) && !TransferFormat::isRedacted($value)) {
				$report['config']['invalid'][] = ['key' => $key, 'code' => 'BAD_TYPE'];
				continue;
			}

			// ref resolution for ref-bearing classes
			$unresolvedRefs = $this->unresolvedConfigRefs($key, (string)$value, $spec);
			if ($unresolvedRefs !== []) {
				foreach ($unresolvedRefs as $ref) {
					$report['unresolved'][] = [
						'scope' => 'config', 'key' => $key,
						'type' => $ref['type'], 'value' => $ref['value'],
						'path' => $ref['path'] ?? null,
						'action' => $spec['unresolved'] === 'keep' ? 'keep' : 'skip_key',
					];
					$report['counts']['refsUnresolved']++;
				}
				if ($spec['unresolved'] !== 'keep') {
					$report['config']['skipped'][] = ['key' => $key, 'reason' => 'unresolved_refs'];
					continue;
				}
			}

			$errorCode = $this->hooks->validateValue($key, (string)$value);
			if ($errorCode !== null) {
				$report['config']['invalid'][] = ['key' => $key, 'code' => $errorCode];
				continue;
			}
			try {
				$normalized = $this->hooks->normalizeValue($key, (string)$value);
			} catch (\Throwable) {
				// validateValue passed but the normaliser still rejected —
				// report as invalid, never crash the whole preview.
				$report['config']['invalid'][] = ['key' => $key, 'code' => 'NORMALIZE_FAILED'];
				continue;
			}

			$exists = $this->keyExists($key, $spec);
			$current = $exists ? $this->readKeyValue($key, $spec) : null;
			if (!$exists) {
				$report['config']['added'][] = $key;
			} elseif ($current === $normalized) {
				$report['config']['unchanged'][] = $key;
			} else {
				// Secret values never appear in the report (NN-01 covers the
				// file; the diff display is the same leak channel).
				$isSecret = $spec['class'] === 'secret';
				$from = $isSecret ? '•••' : $this->displayValue($current);
				$to = $isSecret ? '•••' : $this->displayValue($normalized);
				$report['config']['changed'][] = [
					'key' => $key,
					'from' => $from,
					'to' => $to,
				];
				if (isset($securityKeys[$key]) || $spec['security']) {
					$report['security'][] = [
						'kind' => 'config',
						'key' => $key,
						'detail' => $from . ' → ' . $to,
					];
				}
			}
			if ($sourceUrl !== '' && str_contains((string)$value, $sourceUrl)) {
				$report['warnings'][] = ['code' => 'stale_url', 'detail' => $key];
			}

			if ($spec['class'] === 'mirrored') {
				$mirroredWrites[$key] = $normalized;
			} else {
				$configWrites[$key] = $normalized;
			}
		}

		// mirrored batch validated atomically
		if ($mirroredWrites !== []) {
			$err = $this->hooks->validateMirrored($mirroredWrites);
			if ($err !== null) {
				foreach (array_keys($mirroredWrites) as $key) {
					$report['config']['invalid'][] = ['key' => $key, 'code' => $err];
				}
				$mirroredWrites = [];
			}
		}

		// ── table plan ──
		$tablePlan = [];
		$wipeTables = [];
		$fileTables = is_array($payload['tables'] ?? null) ? $payload['tables'] : [];
		$idMaps = []; // table => oldId => newTargetId (planned)
		foreach ($this->manifest->transferableTablesSorted() as $table) {
			$spec = $this->manifest->tableSpec($table);
			$fileTable = $fileTables[$table] ?? null;
			$entry = [
				'strategy' => $spec['strategy'],
				'insert' => 0, 'update' => 0, 'skip' => 0, 'delete' => 0,
				'unresolved' => 0, 'filtered' => 0, 'envDropped' => 0, 'secretsRedacted' => 0,
				'skipped' => false, 'skipReason' => null,
			];
			if ($fileTable === null || !is_array($fileTable)) {
				$entry['skipped'] = true;
				$entry['skipReason'] = 'not_in_file';
				$report['tables'][$table] = $entry;
				$tablePlan[$table] = ['spec' => $spec, 'rows' => []];
				continue;
			}
			if ($spec['strategy'] === 'wipe-load' && $mode !== self::MODE_REPLACE) {
				$entry['skipped'] = true;
				$entry['skipReason'] = 'replace_only';
				$report['tables'][$table] = $entry;
				$tablePlan[$table] = ['spec' => $spec, 'rows' => []];
				continue;
			}
			if (!$this->db->tableExists($table)) {
				$entry['skipped'] = true;
				$entry['skipReason'] = 'missing_table';
				$report['tables'][$table] = $entry;
				$tablePlan[$table] = ['spec' => $spec, 'rows' => []];
				continue;
			}
			if ($mode === self::MODE_REPLACE) {
				$entry['delete'] = $this->rowCount($table);
				$report['counts']['rowsDeleted'] += $entry['delete'];
				$report['security'][] = [
					'kind' => 'replace_delete', 'key' => $table,
					'detail' => (string)$entry['delete'],
				];
				// The wipe set is EXACTLY what the report counts — a table
				// absent from the file is never silently emptied.
				$wipeTables[] = $table;
			}
			if ($spec['strategy'] === 'seed-if-empty' && $mode !== self::MODE_REPLACE && $this->rowCount($table) > 0) {
				$entry['skipped'] = true;
				$entry['skipReason'] = 'target_not_empty';
				$report['tables'][$table] = $entry;
				$tablePlan[$table] = ['spec' => $spec, 'rows' => []];
				continue;
			}
			foreach (($fileTable['env_dropped'] ?? []) as $col => $n) {
				$entry['envDropped'] += (int)$n;
				$report['envBound'][] = ['table' => $table, 'column' => (string)$col, 'rows' => (int)$n];
			}
			foreach (($fileTable['secret_redacted'] ?? []) as $col => $n) {
				$entry['secretsRedacted'] += (int)$n;
			}

			$idMap = [];
			$rows = is_array($fileTable['rows'] ?? null) ? $fileTable['rows'] : [];
			foreach ($rows as $rowIndex => $row) {
				if (!is_array($row)) {
					$entry['skip']++;
					continue;
				}
				$result = $this->planRow($table, $spec, $row, $idMaps, $idMap, $mode, $report);
				if ($result['action'] === 'skip') {
					$entry['skip']++;
					$entry['unresolved']++;
				} else {
					$entry[$result['action']]++;
					// Rows without an id cannot be FK targets — never let a
					// '?' placeholder collide and resolve a child to it.
					if ($result['sourceId'] !== '?') {
						$idMap[$result['sourceId']] = $result['targetId'] !== null
							? self::pkScalar($result['targetId'])
							: ('new:' . $rowIndex);
					}
				}
				$entry['filtered'] += $result['filtered'];
				$report['counts']['refsUnresolved'] += $result['unresolved'];
				$tablePlan[$table]['rows'][] = $result;
			}
			$idMaps[$table] = $idMap;
			$report['tables'][$table] = $entry;
			$tablePlan[$table]['spec'] = $spec;
		}
		foreach (array_keys($fileTables) as $unknown) {
			if ($this->manifest->tableSpec((string)$unknown) === null
				|| !in_array($this->manifest->tableSpec((string)$unknown)['class'], AbstractTransferManifest::TABLE_CLASSES_TRANSFERABLE, true)) {
				$report['tablesUnknown'][] = (string)$unknown;
			}
		}

		$report['counts']['configApplied'] = count($configWrites) + count($mirroredWrites);
		foreach ($report['tables'] as $t => $entry) {
			$report['counts']['rowsInserted'] += $entry['insert'];
			$report['counts']['rowsUpdated'] += $entry['update'];
			$report['counts']['rowsSkipped'] += $entry['skip'];
		}
		if ($report['config']['added'] === [] && $report['config']['changed'] === []
			&& $report['counts']['rowsInserted'] === 0 && $report['counts']['rowsUpdated'] === 0
			&& $report['counts']['rowsDeleted'] === 0) {
			$report['verdict'] = 'no_changes';
		}

		return [
			'report' => $report,
			'configWrites' => $configWrites,
			'mirroredWrites' => $mirroredWrites,
			'tablePlan' => $tablePlan,
			'wipeTables' => $wipeTables,
		];
	}

	/**
	 * Plan one table row: resolve uid/gid/json-list refs and FK availability,
	 * decide insert|update|skip, produce the processed row payload.
	 *
	 * @param array<string,int|string> $idMaps completed maps of previous tables
	 * @param array<int|string,int|string> $idMap  map being built for this table
	 * @return array{action:string,sourceId:int|string,targetId:array<string,mixed>|null,row:array|null,fkFix:array<string,string>,lateFk:array,filtered:int,unresolved:int,reason:?string}
	 */
	private function planRow(string $table, array $spec, array $row, array $idMaps, array $idMap, string $mode, array &$report): array
	{
		$sourceId = $row['id'] ?? null;
		$out = ['action' => 'skip', 'sourceId' => $sourceId ?? '?', 'targetId' => null, 'row' => null, 'fkFix' => [], 'lateFk' => [], 'filtered' => 0, 'unresolved' => 0, 'reason' => null];
		$lookupRow = $row;

		// ── uid/gid scalar refs ──
		foreach ($spec['uid_ref'] as $col) {
			$val = trim((string)($row[$col] ?? ''));
			if ($val === '') {
				$row[$col] = in_array($col, $spec['uid_ref_nullable'], true) ? null : $row[$col];
				continue;
			}
			if (!$this->userManager->userExists($val)) {
				$out['unresolved']++;
				$report['unresolved'][] = ['scope' => 'table', 'table' => $table, 'row' => $sourceId, 'type' => 'uid', 'value' => $val, 'action' => 'skip_row'];
				return $out;
			}
		}
		foreach ($spec['gid_ref'] as $col) {
			$val = trim((string)($row[$col] ?? ''));
			if ($val === '') {
				continue;
			}
			if (!$this->groupManager->groupExists($val)) {
				$out['unresolved']++;
				$report['unresolved'][] = ['scope' => 'table', 'table' => $table, 'row' => $sourceId, 'type' => 'gid', 'value' => $val, 'action' => 'skip_row'];
				return $out;
			}
		}

		// ── json uid lists: ghosts filtered + reported (row kept) ──
		foreach ($spec['json_uid_list'] as $col) {
			$raw = $row[$col] ?? null;
			if ($raw === null || $raw === '' ) {
				continue;
			}
			$list = json_decode((string)$raw, true);
			if (!is_array($list)) {
				$out['unresolved']++;
				$report['unresolved'][] = ['scope' => 'table', 'table' => $table, 'row' => $sourceId, 'type' => 'uid', 'value' => '(malformed ' . $col . ')', 'action' => 'skip_row'];
				return $out;
			}
			$kept = [];
			foreach ($list as $uid) {
				$uid = (string)$uid;
				if ($uid === '') {
					continue;
				}
				if ($this->userManager->userExists($uid)) {
					$kept[] = $uid;
				} else {
					$out['filtered']++;
					$report['unresolved'][] = ['scope' => 'table', 'table' => $table, 'row' => $sourceId, 'type' => 'uid', 'value' => $uid, 'action' => 'filtered'];
				}
			}
			$row[$col] = json_encode(array_values($kept), JSON_THROW_ON_ERROR);
		}

		// ── FK columns (pre-insert) ──
		// The stored row keeps the SOURCE id — applyTablePlan rewrites it
		// through the real old→new map. Only the natural-key lookup row
		// carries the planned value (real target id for existing parents,
		// 'new:N' placeholder for rows this file will insert).
		foreach ($spec['fk'] as $col => $target) {
			$val = $row[$col] ?? null;
			if ($val === null || $val === '' || $val === 0 || $val === '0') {
				$row[$col] = null;
				$lookupRow[$col] = null;
				continue;
			}
			$mapped = $idMaps[$target][$val]
				?? (($target === $table && isset($idMap[$val])) ? $idMap[$val] : null);
			if ($mapped === null) {
				// unresolved → skip row + report (transitively unresolved when
				// the parent row was itself skipped → no map entry exists)
				$out['unresolved']++;
				$report['unresolved'][] = ['scope' => 'table', 'table' => $table, 'row' => $sourceId, 'type' => 'fk', 'value' => $target . '#' . $val, 'action' => 'skip_row'];
				return $out;
			}
			$out['fkFix'][$col] = $target;
			$lookupRow[$col] = $mapped;
		}

		// ── late FKs: resolved after all tables load ──
		foreach ($spec['fk_late'] as $col => $target) {
			$val = $row[$col] ?? null;
			if ($val === null || $val === '' || $val === 0 || $val === '0') {
				$row[$col] = null;
				continue;
			}
			$out['lateFk'][$col] = ['target' => $target, 'sourceId' => $val];
			$row[$col] = null; // written by the second pass
		}

		// ── never write file/id/secret/env/generator columns ──
		foreach ($spec['secret_columns'] as $col) {
			unset($row[$col]); // NULL on insert, untouched on update
		}
		foreach ($spec['env_columns'] as $col) {
			unset($row[$col]);
		}

		// ── upsert decision ──
		if ($mode === self::MODE_MERGE && $spec['strategy'] === 'upsert-natural-key') {
			$existingPk = $this->findByNaturalKey($table, $spec['natural_key'], $spec['pk'], $lookupRow);
			if ($existingPk !== null) {
				$out['action'] = 'update';
				$out['targetId'] = $existingPk;
				$out['row'] = $row;
				return $out;
			}
		}
		$out['action'] = 'insert';
		$out['row'] = $row;
		return $out;
	}

	// ─────────────────────────── plan execution ──────────────────────────

	private function applyConfigPlan(array $plan): void
	{
		foreach ($plan['configWrites'] as $key => $value) {
			$spec = $this->manifest->keySpec($key);
			if (($spec['class'] ?? '') === 'secret') {
				// MH-15: secrets land via the sensitive appconfig API so core
				// `occ config:list` masks them like every other credential.
				$this->writeAppSecret($key, $value);
			} else {
				$this->writeAppString($key, $value);
			}
		}
		if ($plan['mirroredWrites'] !== []) {
			$this->hooks->writeMirrored($plan['mirroredWrites']);
		}
	}

	private function applyTablePlan(array $plan, string $mode): void
	{
		$idMaps = [];
		$lateFixes = [];

		if ($mode === self::MODE_REPLACE) {
			// Wipe ONLY the tables the dry run counted — a table absent from
			// the file is never silently emptied (report honesty, NN-04).
			foreach ($this->manifest->transferableTablesReverseSorted() as $table) {
				if (!in_array($table, $plan['wipeTables'] ?? [], true)) {
					continue;
				}
				if ($this->db->tableExists($table)) {
					$this->db->getQueryBuilder()->delete($table)->executeStatement();
				}
			}
		}

		foreach ($this->manifest->transferableTablesSorted() as $table) {
			$tp = $plan['tablePlan'][$table] ?? null;
			if ($tp === null || ($tp['rows'] ?? []) === []) {
				$idMaps[$table] = [];
				continue;
			}
			$spec = $tp['spec'];
			$generated = $this->generatedColumnsOf($table);
			foreach ($tp['rows'] as $rowPlan) {
				$sourceId = $rowPlan['sourceId'];
				if ($rowPlan['action'] === 'skip') {
					continue;
				}
				$row = $rowPlan['row'];
				unset($row['id']);
				foreach ($generated as $gcol => $_) {
					unset($row[$gcol]);
				}
				// Rewrite declared FK columns through the REAL old→new maps —
				// the row still carries source ids (plan validated they all
				// resolve; a missing entry here means state drifted → fail).
				foreach (($rowPlan['fkFix'] ?? []) as $col => $target) {
					$srcVal = $row[$col] ?? null;
					if ($srcVal === null) {
						continue;
					}
					if (!isset($idMaps[$target][$srcVal])) {
						throw new TransferException('TRANSFER_APPLY_FAILED', [
							'step' => 'fk_remap', 'table' => $table, 'column' => (string)$col,
						]);
					}
					$row[$col] = $idMaps[$target][$srcVal];
				}
				if ($rowPlan['action'] === 'update') {
					$targetPk = $rowPlan['targetId']; // pk map from findByNaturalKey
					// Pure key-mapping tables (natural key covers every
					// non-pk column) have nothing to SET — the matched row
					// already carries the right identity; empty-SET UPDATEs
					// are a syntax error on strict engines (MariaDB 1064).
					$sets = [];
					foreach ($row as $col => $value) {
						if (in_array((string)$col, $spec['natural_key'], true)) {
							continue; // natural key cols already match by definition
						}
						$sets[(string)$col] = $value;
					}
					if ($sets !== []) {
						$qb = $this->db->getQueryBuilder();
						$qb->update($table);
						foreach ($targetPk as $pkCol => $pkVal) {
							if ($pkVal === null) {
								$qb->andWhere($qb->expr()->isNull((string)$pkCol));
							} else {
								$qb->andWhere($qb->expr()->eq((string)$pkCol, $qb->createNamedParameter($pkVal)));
							}
						}
						foreach ($sets as $col => $value) {
							$qb->set((string)$col, $qb->createNamedParameter($value));
						}
						$qb->executeStatement();
					}
					if ($sourceId !== '?') {
						$idMaps[$table][$sourceId] = self::pkScalar($targetPk);
					}
				} else {
					$qb = $this->db->getQueryBuilder();
					$qb->insert($table);
					foreach ($row as $col => $value) {
						$qb->setValue((string)$col, $qb->createNamedParameter($value));
					}
					$qb->executeStatement();
					$newId = (int)$this->db->lastInsertId($table);
					if ($sourceId !== '?') {
						$idMaps[$table][$sourceId] = $newId;
					}
					$rowWhere = $spec['pk'] === ['id']
						? ['id' => $newId]
						: array_intersect_key($row, array_flip($spec['pk']));
					foreach ($rowPlan['lateFk'] as $col => $fix) {
						$lateFixes[] = ['table' => $table, 'where' => $rowWhere, 'col' => $col, 'target' => $fix['target'], 'sourceId' => $fix['sourceId']];
					}
					continue;
				}
				foreach ($rowPlan['lateFk'] as $col => $fix) {
					$lateFixes[] = ['table' => $table, 'where' => $targetPk, 'col' => $col, 'target' => $fix['target'], 'sourceId' => $fix['sourceId']];
				}
			}
		}

		// second pass: late FK resolution (self + cyclic references)
		foreach ($lateFixes as $fix) {
			$newRef = $idMaps[$fix['target']][$fix['sourceId']] ?? null;
			$qb = $this->db->getQueryBuilder();
			$qb->update($fix['table'])
				->set($fix['col'], $qb->createNamedParameter($newRef));
			foreach ($fix['where'] as $wCol => $wVal) {
				if ($wVal === null) {
					$qb->andWhere($qb->expr()->isNull((string)$wCol));
				} else {
					$qb->andWhere($qb->expr()->eq((string)$wCol, $qb->createNamedParameter($wVal)));
				}
			}
			$qb->executeStatement();
		}
	}

	// ─────────────────────────── helpers ─────────────────────────────────

	/**
	 * @return list<array{type:string,value:string,path:?string}>
	 */
	private function unresolvedConfigRefs(string $key, string $value, array $spec): array
	{
		$refs = $spec['refs'];
		if ($refs === null) {
			return [];
		}
		$out = [];
		switch ($refs['kind']) {
			case 'uid':
				if (!$this->userManager->userExists($value)) {
					$out[] = ['type' => 'uid', 'value' => $value, 'path' => null];
				}
				break;
			case 'gid':
				if (!$this->groupManager->groupExists($value)) {
					$out[] = ['type' => 'gid', 'value' => $value, 'path' => null];
				}
				break;
			case 'uid_list_json':
			case 'gid_list_json':
				$list = json_decode($value, true);
				if (!is_array($list)) {
					$out[] = ['type' => $refs['kind'] === 'uid_list_json' ? 'uid' : 'gid', 'value' => '(malformed json)', 'path' => null];
					break;
				}
				foreach ($list as $i => $id) {
					$id = (string)$id;
					if ($id === '') {
						continue;
					}
					$exists = $refs['kind'] === 'uid_list_json'
						? $this->userManager->userExists($id)
						: $this->groupManager->groupExists($id);
					if (!$exists) {
						$out[] = ['type' => $refs['kind'] === 'uid_list_json' ? 'uid' : 'gid', 'value' => $id, 'path' => '$[' . $i . ']'];
					}
				}
				break;
			case 'json_paths':
				$decoded = json_decode($value, true);
				if (!is_array($decoded)) {
					$out[] = ['type' => 'uid', 'value' => '(malformed json)', 'path' => '$'];
					break;
				}
				foreach (($refs['uid_paths'] ?? []) as $path) {
					foreach (AbstractTransferManifest::extractJsonRefs($decoded, (string)$path) as $ref) {
						if (!$this->userManager->userExists($ref['value'])) {
							$out[] = ['type' => 'uid', 'value' => $ref['value'], 'path' => $ref['path']];
						}
					}
				}
				foreach (($refs['gid_paths'] ?? []) as $path) {
					foreach (AbstractTransferManifest::extractJsonRefs($decoded, (string)$path) as $ref) {
						if (!$this->groupManager->groupExists($ref['value'])) {
							$out[] = ['type' => 'gid', 'value' => $ref['value'], 'path' => $ref['path']];
						}
					}
				}
				break;
		}
		return $out;
	}

	/**
	 * Locate the target row matching the file row's natural key and return
	 * its pk map (spec 'pk' columns => values). Composite-pk tables without
	 * an `id` column work identically — the pk map is the update address.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findByNaturalKey(string $table, array $naturalKey, array $pkCols, array $row): ?array
	{
		if ($naturalKey === [] || $pkCols === []) {
			return null;
		}
		foreach ($naturalKey as $col) {
			// A 'new:N' placeholder can never match an existing target row —
			// short-circuit instead of comparing it (strict-typing engines
			// would also reject 'new:N' against an int column).
			if (is_string($row[$col] ?? null) && str_starts_with((string)$row[$col], 'new:')) {
				return null;
			}
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select(...array_map('strval', $pkCols))->from($table);
		foreach ($naturalKey as $col) {
			$val = $row[$col] ?? null;
			if ($val === null) {
				// col = NULL never matches — NULL natural-key columns need IS NULL
				$qb->andWhere($qb->expr()->isNull((string)$col));
			} else {
				$qb->andWhere($qb->expr()->eq((string)$col, $qb->createNamedParameter($val)));
			}
		}
		$qb->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetch();
		$result->closeCursor();
		if ($found === false) {
			return null;
		}
		$pk = [];
		foreach ($pkCols as $col) {
			$pk[(string)$col] = $found[$col] ?? null;
		}
		return $pk;
	}

	/**
	 * Scalar form of a pk map for id-map storage — single-column pks (the
	 * `id` default every fk-target table has) stay plain values so child FK
	 * rewrites keep working; composite pks are never single-col FK targets.
	 *
	 * @param array<string,mixed> $pk
	 */
	private static function pkScalar(array $pk): mixed
	{
		return count($pk) === 1 ? reset($pk) : $pk;
	}

	private function rowCount(string $table): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))->from($table);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['c'] ?? 0);
	}

	/**
	 * Count dangling references across all declared fk columns. Used as a
	 * baseline before apply and as the post-apply proof that the import did
	 * not add orphans (pre-existing damage elsewhere must not brick imports).
	 */
	private function danglingReferenceCount(): int
	{
		$total = 0;
		foreach ($this->manifest->tables() as $table => $spec) {
			if (!in_array($spec['class'], AbstractTransferManifest::TABLE_CLASSES_TRANSFERABLE, true)) {
				continue;
			}
			if (!$this->db->tableExists($table)) {
				continue;
			}
			foreach (array_merge($spec['fk'], $spec['fk_late']) as $col => $target) {
				if (!$this->db->tableExists((string)$target)) {
					continue;
				}
				try {
					$qb = $this->db->getQueryBuilder();
					$qb->select($qb->func()->count('c.' . (string)$col, 'dangling'))
						->from($table, 'c')
						->leftJoin('c', (string)$target, 'p', $qb->expr()->eq('c.' . (string)$col, 'p.id'))
						->where($qb->expr()->isNotNull('c.' . (string)$col))
						->andWhere($qb->expr()->isNull('p.id'));
					$result = $qb->executeQuery();
					$row = $result->fetch();
					$result->closeCursor();
					$total += (int)($row['dangling'] ?? 0);
				} catch (\Throwable) {
					// a column the engine cannot inspect must not abort the check
				}
			}
		}
		return $total;
	}

	/**
	 * FS-8/FS-12 pre-flight. Throws on any failure — zero writes have happened.
	 */
	private function preflight(array $payload, string $mode): void
	{
		if (!$this->appManager->isEnabledForUser($this->appId)) {
			throw new TransferException('TRANSFER_PREFLIGHT_FAILED', ['check' => 'app_enabled']);
		}
		// DB write probe: real write inside a rolled-back transaction —
		// a no-op SELECT cannot prove writability.
		try {
			$this->db->beginTransaction();
			$probe = 'transfer_probe_' . bin2hex(random_bytes(6));
			$qb = $this->db->getQueryBuilder();
			$qb->insert('appconfig')
				->setValue('appid', $qb->createNamedParameter($this->appId))
				->setValue('configkey', $qb->createNamedParameter($probe))
				->setValue('configvalue', $qb->createNamedParameter('1'));
			$qb->executeStatement();
			$this->db->rollBack();
		} catch (\Throwable $e) {
			try {
				$this->db->rollBack();
			} catch (\Throwable) {
			}
			throw new TransferException('TRANSFER_PREFLIGHT_FAILED', ['check' => 'db_write'], $e);
		}
		// free disk ≥ 2× payload
		$payloadBytes = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
		$free = @disk_free_space((string)$this->config->getSystemValue('datadirectory', '/tmp'));
		if ($free !== false && $free < 2 * $payloadBytes) {
			throw new TransferException('TRANSFER_PREFLIGHT_FAILED', [
				'check' => 'disk', 'free' => $free, 'needed' => 2 * $payloadBytes,
			]);
		}
		// column compat: every file-declared table+column must exist on target
		// with a compatible type family — refuse BEFORE the first write (FS-12).
		$fileTables = is_array($payload['tables'] ?? null) ? $payload['tables'] : [];
		foreach ($fileTables as $table => $meta) {
			$spec = $this->manifest->tableSpec((string)$table);
			if ($spec === null || !in_array($spec['class'], AbstractTransferManifest::TABLE_CLASSES_TRANSFERABLE, true)) {
				continue;
			}
			if (!$this->db->tableExists((string)$table)) {
				continue; // skip-at-plan handles it
			}
			$targetCols = $this->columnTypeFamilies((string)$table);
			foreach ((array)($meta['columns'] ?? []) as $col => $fileFamily) {
				if ($col === 'id') {
					continue;
				}
				if (!isset($targetCols[(string)$col])) {
					throw new TransferException('TRANSFER_SCHEMA_INCOMPATIBLE', [
						'table' => (string)$table, 'column' => (string)$col, 'reason' => 'missing_column',
					]);
				}
				if ($targetCols[(string)$col] !== (string)$fileFamily) {
					throw new TransferException('TRANSFER_SCHEMA_INCOMPATIBLE', [
						'table' => (string)$table, 'column' => (string)$col,
						'fileType' => (string)$fileFamily, 'targetType' => $targetCols[(string)$col],
					]);
				}
			}
		}
	}

	/**
	 * Column type families via Doctrine schema — `int|string|...` per column,
	 * used both for export metadata and FS-12 compat checks.
	 *
	 * @return array<string,string>
	 */
	private function columnTypeFamilies(string $table): array
	{
		try {
			$schema = $this->db->createSchema();
			$prefix = (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
			$tbl = null;
			foreach ([$prefix . $table, $table] as $name) {
				if ($schema->hasTable($name)) {
					$tbl = $schema->getTable($name);
					break;
				}
			}
			if ($tbl === null) {
				return [];
			}
			$out = [];
			foreach ($tbl->getColumns() as $col) {
				$out[$col->getName()] = self::typeFamily($col->getType()->getName());
			}
			return $out;
		} catch (\Throwable) {
			return [];
		}
	}

	private static function typeFamily(string $typeName): string
	{
		return match (true) {
			in_array($typeName, ['smallint', 'integer', 'bigint']) => 'int',
			in_array($typeName, ['float', 'decimal']) => 'float',
			$typeName === 'boolean' => 'bool',
			in_array($typeName, ['text']) => 'text',
			str_starts_with($typeName, 'datetime') || in_array($typeName, ['date', 'time', 'date_immutable', 'time_immutable'], true) => 'datetime',
			in_array($typeName, ['binary', 'blob']) => 'blob',
			$typeName === 'json' => 'json',
			default => 'string',
		};
	}

	/**
	 * Generated columns (e.g. MySQL GENERATED ALWAYS) are recomputed by the
	 * engine — writing them is an SQL error. Same proven detection as
	 * UpgradeBackupService::generatedColumnsOf.
	 *
	 * @return array<string,true>
	 */
	private function generatedColumnsOf(string $table): array
	{
		$physical = ((string)$this->config->getSystemValue('dbtableprefix', 'oc_')) . $table;
		$out = [];
		try {
			if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
				$rows = $this->db->executeQuery(
					"SELECT a.attname FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid"
					. " JOIN pg_namespace n ON n.oid = c.relnamespace"
					. " WHERE c.relname = :tbl AND n.nspname = current_schema()"
					. " AND a.attnum > 0 AND NOT a.attisdropped AND a.attgenerated <> ''",
					['tbl' => $physical],
				)->fetchAll();
				foreach ($rows as $r) {
					$out[(string)$r['attname']] = true;
				}
				return $out;
			}
			if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
				return $out;
			}
			$safe = str_replace('`', '``', $physical);
			foreach ($this->db->executeQuery("SHOW COLUMNS FROM `{$safe}`")->fetchAll() as $col) {
				if (str_contains(strtoupper((string)($col['Extra'] ?? '')), 'GENERATED')) {
					$out[(string)$col['Field']] = true;
				}
			}
		} catch (\Throwable) {
			return $out;
		}
		return $out;
	}

	/**
	 * appdata folder transfer (NH-01): only folders declared in the manifest
	 * with a byte budget; over-budget fails loudly, never silently truncates.
	 *
	 * @return array<string,list<array{path:string,size:int,b64:string}>>
	 */
	private function exportAppData(): array
	{
		$out = [];
		foreach ($this->manifest->appDataFolders() as $folder => $budget) {
			$files = [];
			$total = 0;
			$base = $this->appDataSubdir((string)$folder);
			if ($base === null) {
				continue;
			}
			$this->collectFiles($base, (string)$folder, $files, $total, (int)$budget);
			$out[(string)$folder] = $files;
		}
		return $out;
	}

	private function collectFiles(\OCP\Files\Folder $folder, string $prefix, array &$files, int &$total, int $budget): void
	{
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof \OCP\Files\Folder) {
				$this->collectFiles($node, $prefix . '/' . $node->getName(), $files, $total, $budget);
				continue;
			}
			if ($node instanceof \OCP\Files\File) {
				$content = $node->getContent();
				$total += strlen($content);
				if ($total > $budget || $total > TransferFormat::MAX_APPDATA_BYTES) {
					throw new TransferException('TRANSFER_FILE_TOO_LARGE', ['folder' => $prefix, 'budget' => $budget]);
				}
				$files[] = [
					'path' => $prefix . '/' . $node->getName(),
					'size' => strlen($content),
					'b64' => base64_encode($content),
				];
			}
		}
	}

	private function appDataSubdir(string $name): ?\OCP\Files\Folder
	{
		// The pack cannot depend on app classes — inline the same allowlist.
		if ($name === '' || !preg_match('/^[a-z0-9._-]+$/i', $name)
			|| str_contains($name, '/') || str_contains($name, '\\')
			|| $name === '.' || $name === '..') {
			return null;
		}
		$instanceId = (string)$this->config->getSystemValue('instanceid', '');
		if ($instanceId === '') {
			return null;
		}
		try {
			$node = $this->rootFolder->get('appdata_' . $instanceId . '/' . $this->appId . '/' . $name);
		} catch (\Throwable) {
			return null;
		}
		return $node instanceof \OCP\Files\Folder ? $node : null;
	}

	private function assertPlanApplicable(array $plan): void
	{
		// Invalid values are never written (spec NN-08) but they do not block
		// the apply — the report shows them, the rest applies.
		if (($plan['report']['config']['invalid'] ?? []) !== []) {
			$this->logger->warning($this->appId . ': config transfer skipping invalid values', [
				'invalid' => $plan['report']['config']['invalid'],
			]);
		}
	}

	private function assertManifestSane(): void
	{
		$problems = $this->manifest->validate();
		if ($problems !== []) {
			throw new TransferException('TRANSFER_NO_MANIFEST', ['problems' => $problems]);
		}
	}

	private function assertMode(string $mode): void
	{
		if (!in_array($mode, [self::MODE_MERGE, self::MODE_REPLACE], true)) {
			throw new TransferException('TRANSFER_FILE_INVALID');
		}
	}

	/**
	 * Mode recorded in a pending blob — the apply gate must follow the
	 * record, not whatever mode the client re-posts (fail-closed either way,
	 * but this also refuses early when the blob is missing/expired).
	 */
	public function pendingMode(string $pendingId): string
	{
		$record = $this->pendingStore->load($pendingId);
		$mode = (string)($record['mode'] ?? self::MODE_MERGE);
		$this->assertMode($mode);
		return $mode;
	}

	/**
	 * Environment gate for a mode — currently: replace requires maintenance
	 * mode. Checked at queue time (controller) AND re-checked inside
	 * applyPayload (the job runs later — the flag may have flipped between
	 * the two). Failing early at queue time keeps the refusal honest: a job
	 * refused here dies before any job-state/receipt write, which would
	 * otherwise leave the status surface showing a stale previous run.
	 */
	public function assertModeRunnable(string $mode): void
	{
		if ($mode === self::MODE_REPLACE && !$this->config->getSystemValueBool('maintenance')) {
			throw new TransferException('TRANSFER_MAINTENANCE_REQUIRED');
		}
	}

	/**
	 * Audit must never flip an outcome — a throwing audit backend gets
	 * logged and swallowed; the receipt still tells the truth.
	 *
	 * @param array<string,mixed> $details
	 */
	private function auditSafely(string $action, string $actor, array $details): void
	{
		try {
			$this->hooks->auditEvent($action, $actor, $details);
		} catch (\Throwable $e) {
			$this->logger->warning($this->appId . ': config transfer audit event failed', [
				'app' => $this->appId, 'action' => $action, 'exception' => $e,
			]);
		}
	}

	/**
	 * Whether the current instance holds a value for the key — appconfig
	 * keys via getAppKeys, store-backed keys via their backing row/field.
	 */
	private function keyExists(string $key, array $spec): bool
	{
		if (($spec['store'] ?? null) === null) {
			return in_array($key, $this->config->getAppKeys($this->appId), true);
		}
		return $this->readKeyValue($key, $spec) !== null;
	}

	/**
	 * Read the current value of a manifest key. Store-backed keys
	 * (`'store'` spec — config-in-table apps) read from their backing
	 * table instead of appconfig:
	 *   kind 'kv'   → SELECT value_col WHERE key_col = :key
	 *   kind 'json' → field json_key of the singleton doc row json_col@id=row_id
	 *                 (non-scalar field values return as a JSON string)
	 * Writes never use this path — store-backed keys are class 'mirrored'
	 * and flow through the app's central validated writer (writeMirrored).
	 */
	private function readKeyValue(string $key, array $spec): ?string
	{
		$store = $spec['store'] ?? null;
		if ($store === null) {
			return $this->config->getAppValue($this->appId, $key, '');
		}
		$table = (string)($store['table'] ?? '');
		if ($table === '' || !$this->db->tableExists($table)) {
			return null;
		}
		$kind = (string)($store['kind'] ?? '');
		if ($kind === 'kv') {
			$qb = $this->db->getQueryBuilder();
			$qb->select((string)$store['value_col'])->from($table)
				->where($qb->expr()->eq((string)$store['key_col'], $qb->createNamedParameter($key)));
			$result = $qb->executeQuery();
			$val = $result->fetchOne();
			$result->closeCursor();
			return $val === false || $val === null ? null : (string)$val;
		}
		if ($kind === 'json') {
			$qb = $this->db->getQueryBuilder();
			$qb->select((string)$store['json_col'])->from($table)
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$store['row_id'])));
			$result = $qb->executeQuery();
			$doc = $result->fetchOne();
			$result->closeCursor();
			if ($doc === false || $doc === null) {
				return null;
			}
			$decoded = json_decode((string)$doc, true);
			$field = (string)$store['json_key'];
			if (!is_array($decoded) || !array_key_exists($field, $decoded) || $decoded[$field] === null) {
				return null;
			}
			$val = $decoded[$field];
			return is_string($val) ? $val : (string)json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}
		return null;
	}

	private function writeAppString(string $key, string $value): void
	{
		try {
			$this->config->setAppValue($this->appId, $key, $value);
		} catch (\OCP\Exceptions\AppConfigTypeConflictException) {
			if ($this->appConfig === null) {
				throw new TransferException('TRANSFER_APPLY_FAILED', ['step' => 'config_write', 'key' => $key]);
			}
			$this->appConfig->setValueString($this->appId, $key, $value);
		}
	}

	/**
	 * Sensitive-flagged write for secret-class keys (MH-15). A key that was
	 * previously stored non-sensitive must be deleted first — the platform
	 * refuses flag changes on existing rows (AppConfigTypeConflictException).
	 */
	private function writeAppSecret(string $key, string $value): void
	{
		if ($this->appConfig === null) {
			$this->logger->warning($this->appId . ': IAppConfig unavailable — secret ' . $key . ' stored without sensitive flag');
			$this->writeAppString($key, $value);
			return;
		}
		try {
			$this->appConfig->setValueString($this->appId, $key, $value, false, true);
		} catch (\OCP\Exceptions\AppConfigTypeConflictException) {
			$this->appConfig->deleteKey($this->appId, $key);
			$this->appConfig->setValueString($this->appId, $key, $value, false, true);
		}
	}

	private function displayValue(mixed $value): string
	{
		$s = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
		$s = (string)$s;
		return mb_strlen($s) > self::MAX_VALUE_DISPLAY ? mb_substr($s, 0, self::MAX_VALUE_DISPLAY) . '…' : $s;
	}

	private function currentActor(): string
	{
		try {
			$user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
			return $user === null ? 'cli' : $user->getUID();
		} catch (\Throwable) {
			return 'cli';
		}
	}
}
