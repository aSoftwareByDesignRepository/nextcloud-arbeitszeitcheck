<?php

declare(strict_types=1);

/**
 * Snapshot and restore ArbeitszeitCheck data before app updates.
 *
 * Snapshots are written atomically (manifest last, marked complete) under app data
 * ({@see UpgradeBackupCatalog::APPDATA_ROOT}). Restore validates integrity before any
 * mutation and wraps database writes in a transaction.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\ArbeitszeitCheck\Exception\UpgradeBackupException;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class UpgradeBackupService
{
	private const LOCK_KEY = 'arbeitszeitcheck-upgrade-backup';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly IRootFolder $rootFolder,
		private readonly IAppManager $appManager,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
	}

	public function hasDataToBackup(): bool
	{
		foreach (UpgradeBackupCatalog::BACKUP_TABLES as $table) {
			if ($this->db->tableExists($table)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{id: string, manifest: array<string, mixed>}
	 */
	public function createSnapshot(string $reason, bool $rotate = true): array
	{
		return $this->createSnapshotInternal($reason, $rotate, []);
	}

	/**
	 * @param list<string> $rotationExcludeIds
	 * @return array{id: string, manifest: array<string, mixed>}
	 */
	private function createSnapshotInternal(string $reason, bool $rotate, array $rotationExcludeIds): array
	{
		return $this->runExclusive('ArbeitszeitCheck upgrade backup', function () use ($reason, $rotate, $rotationExcludeIds): array {
			return $this->doCreateSnapshot($reason, $rotate, $rotationExcludeIds);
		});
	}

	/**
	 * @param list<string> $rotationExcludeIds
	 * @return array{id: string, manifest: array<string, mixed>}
	 */
	private function doCreateSnapshot(string $reason, bool $rotate, array $rotationExcludeIds): array
	{
		$this->purgeIncompleteSnapshotFolders();

		if (!$this->hasDataToBackup()) {
			throw new UpgradeBackupException('No ArbeitszeitCheck tables exist yet; nothing to back up.');
		}

		$id = $this->newSnapshotId();

		try {
			// Folder creation can fail (permissions, quota); keep it inside the
			// try so callers always see an UpgradeBackupException.
			$snapshotFolder = $this->getBackupRootFolder()->newFolder($id);
			$tablesFolder = $snapshotFolder->newFolder('tables');

			$tableManifest = [];
			$backedUpTables = [];
			foreach (UpgradeBackupCatalog::BACKUP_TABLES as $table) {
				if (!$this->db->tableExists($table)) {
					continue;
				}

				$rows = $this->exportTableRows($table);
				$encoded = json_encode($rows, JSON_THROW_ON_ERROR);
				$tablesFolder->newFile($table . '.json', $encoded);
				$tableManifest[$table] = [
					'rowCount' => count($rows),
					'checksum' => hash('sha256', $encoded),
				];
				$backedUpTables[] = $table;
			}

			if ($backedUpTables === []) {
				throw new UpgradeBackupException('No ArbeitszeitCheck tables exist on this instance; nothing to back up.');
			}

			$this->writeJsonFile($snapshotFolder, 'appconfig.json', $this->exportAppConfig());
			$this->writeJsonFile($snapshotFolder, 'preferences.json', $this->exportPreferences());
			$this->writeJsonFile($snapshotFolder, 'migrations.json', $this->exportMigrations());
			foreach (UpgradeBackupCatalog::APPDATA_FOLDERS as $appDataFolder) {
				$this->copyAppDataTree($appDataFolder, $snapshotFolder);
			}

			$manifest = [
				'format' => UpgradeBackupCatalog::FORMAT_VERSION,
				'appId' => UpgradeBackupCatalog::APP_ID,
				'id' => $id,
				'appVersion' => $this->appManager->getAppVersion(UpgradeBackupCatalog::APP_ID, false),
				'createdAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
				'reason' => UpgradeBackupIntegrity::normalizeReason($reason),
				'tables' => $tableManifest,
				'integrity' => hash('sha256', json_encode($tableManifest, JSON_THROW_ON_ERROR)),
				'complete' => true,
			];

			$this->writeJsonFile($snapshotFolder, 'manifest.json', $manifest);
			$this->config->setAppValue(UpgradeBackupCatalog::APP_ID, UpgradeBackupCatalog::CONFIG_LAST_SNAPSHOT_ID, $id);
			if ($rotate) {
				$this->rotateSnapshots($rotationExcludeIds);
			}
		} catch (\Throwable $e) {
			$this->deleteSnapshotFolderIfExists($id);
			if ($e instanceof UpgradeBackupException) {
				throw $e;
			}
			throw new UpgradeBackupException('Failed to create upgrade backup snapshot: ' . $e->getMessage(), 0, $e);
		}

		$this->logger->info('ArbeitszeitCheck: created pre-update backup snapshot', [
			'app' => UpgradeBackupCatalog::APP_ID,
			'snapshotId' => $id,
			'reason' => $manifest['reason'],
			'tables' => $backedUpTables,
		]);

		return ['id' => $id, 'manifest' => $manifest];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listSnapshots(): array
	{
		$snapshots = [];
		foreach ($this->listSnapshotIds() as $snapshotId) {
			try {
				$snapshots[] = $this->readManifest($this->getSnapshotFolder($snapshotId), $snapshotId);
			} catch (\Throwable $e) {
				$this->logger->warning('ArbeitszeitCheck: skipping unreadable upgrade backup folder', [
					'app' => UpgradeBackupCatalog::APP_ID,
					'folder' => $snapshotId,
					'exception' => $e,
				]);
			}
		}

		usort(
			$snapshots,
			static fn (array $a, array $b): int => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')),
		);

		return $snapshots;
	}

	public function restoreSnapshot(string $snapshotId, bool $createSafetySnapshot = true): void
	{
		UpgradeBackupIntegrity::assertSnapshotId($snapshotId);
		$folder = $this->getSnapshotFolder($snapshotId);
		$manifest = $this->readManifest($folder, $snapshotId);

		/** @var array<string, array{rowCount?: int, checksum?: string}> $tableManifest */
		$tableManifest = $manifest['tables'] ?? [];
		$this->validateSnapshotPayload($folder, $snapshotId, $manifest, $tableManifest);

		$this->runExclusive('ArbeitszeitCheck upgrade restore', function () use ($snapshotId, $folder, $tableManifest, $createSafetySnapshot): void {
			$this->doRestoreSnapshot($snapshotId, $folder, $tableManifest, $createSafetySnapshot);
		});
	}

	/**
	 * @param array<string, array{rowCount?: int, checksum?: string}> $tableManifest
	 */
	private function doRestoreSnapshot(
		string $snapshotId,
		Folder $folder,
		array $tableManifest,
		bool $createSafetySnapshot,
	): void {
		if ($createSafetySnapshot && $this->hasDataToBackup()) {
			$this->doCreateSnapshot(
				'pre-restore-' . substr($snapshotId, -12),
				rotate: false,
				rotationExcludeIds: [$snapshotId],
			);
		}

		$tables = UpgradeBackupCatalog::sortedRestoreTables(array_keys($tableManifest));
		$tablesToClear = UpgradeBackupCatalog::sortedRestoreTables(
			UpgradeBackupCatalog::existingBackupTables(fn (string $table): bool => $this->db->tableExists($table)),
		);
		$fkChecksDisabled = false;
		$pgReplicaRole = false;
		$sqliteFkDisabled = false;
		$oracleConstraintsDeferred = false;

		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL) {
			$this->db->executeStatement('SET FOREIGN_KEY_CHECKS=0');
			$fkChecksDisabled = true;
		} elseif ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
			$this->db->executeStatement('SET session_replication_role = replica');
			$pgReplicaRole = true;
		} elseif ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			$this->db->executeStatement('PRAGMA foreign_keys = OFF');
			$sqliteFkDisabled = true;
		} elseif ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_ORACLE) {
			try {
				$this->db->executeStatement('ALTER SESSION SET CONSTRAINTS = DEFERRED');
				$oracleConstraintsDeferred = true;
			} catch (\Throwable $e) {
				// best-effort: deferrable constraints are an optimization; the
				// restore falls back to insertion order when Oracle declines.
				$this->logger->warning('ArbeitszeitCheck: Oracle constraints could not be deferred for restore; relying on restore table order.', [
					'exception' => $e,
				]);
			}
		}

		$this->db->beginTransaction();
		try {
			foreach (array_reverse($tablesToClear) as $table) {
				$this->truncateTableIfExists($table);
			}

			foreach ($tables as $table) {
				$this->restoreTableRows($folder, $table, $tableManifest[$table] ?? []);
			}

			$this->restorePreferences($folder);
			$this->restoreMigrations($folder);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw new UpgradeBackupException('ArbeitszeitCheck restore failed; database changes were rolled back.', 0, $e);
		} finally {
			if ($fkChecksDisabled) {
				$this->db->executeStatement('SET FOREIGN_KEY_CHECKS=1');
			}
			if ($pgReplicaRole) {
				$this->db->executeStatement('SET session_replication_role = DEFAULT');
			}
			if ($sqliteFkDisabled) {
				$this->db->executeStatement('PRAGMA foreign_keys = ON');
			}
			if ($oracleConstraintsDeferred) {
				$this->db->executeStatement('ALTER SESSION SET CONSTRAINTS = IMMEDIATE');
			}
		}

		try {
			$this->restoreAppConfig($folder);
			$this->restoreAppDataFolders($folder);
		} catch (\Throwable $e) {
			throw new UpgradeBackupException(
				'ArbeitszeitCheck database was restored but app config or app data folders failed. '
				. 'Re-run restore after fixing storage, or restore from a full server backup.',
				0,
				$e,
			);
		}

		$this->config->setAppValue(
			UpgradeBackupCatalog::APP_ID,
			UpgradeBackupCatalog::CONFIG_LAST_SNAPSHOT_ID,
			$snapshotId,
		);

		$this->logger->warning('ArbeitszeitCheck: restored data from upgrade backup snapshot', [
			'app' => UpgradeBackupCatalog::APP_ID,
			'snapshotId' => $snapshotId,
			'tables' => $tables,
		]);
	}

	public function getLatestSnapshotId(): ?string
	{
		$snapshots = $this->listSnapshots();
		if ($snapshots === []) {
			return null;
		}

		return (string)($snapshots[0]['id'] ?? '');
	}

	private function newSnapshotId(): string
	{
		return gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
	}

	private function getAppDataPath(string ...$segments): string
	{
		$instanceId = $this->config->getSystemValueString('instanceid');
		if ($instanceId === '') {
			throw new UpgradeBackupException('Nextcloud instance id is not configured.');
		}

		$parts = array_merge(['appdata_' . $instanceId, UpgradeBackupCatalog::APP_ID], $segments);

		return implode('/', $parts);
	}

	private function getAppDataRootFolder(): Folder
	{
		try {
			$node = $this->rootFolder->get($this->getAppDataPath());
		} catch (NotFoundException) {
			$instanceId = $this->config->getSystemValueString('instanceid');
			if ($instanceId === '') {
				throw new UpgradeBackupException('Nextcloud instance id is not configured.');
			}
			$instanceFolder = $this->rootFolder->get('appdata_' . $instanceId);
			if (!$instanceFolder instanceof Folder) {
				throw new UpgradeBackupException('Nextcloud app data directory is not available.');
			}
			$node = $instanceFolder->newFolder(UpgradeBackupCatalog::APP_ID);
		}

		if (!$node instanceof Folder) {
			throw new UpgradeBackupException('ArbeitszeitCheck app data root is not a folder.');
		}

		return $node;
	}

	private function getBackupRootFolder(): Folder
	{
		$appRoot = $this->getAppDataRootFolder();
		try {
			$node = $appRoot->get(UpgradeBackupCatalog::APPDATA_ROOT);
		} catch (NotFoundException) {
			return $appRoot->newFolder(UpgradeBackupCatalog::APPDATA_ROOT);
		}

		if (!$node instanceof Folder) {
			throw new UpgradeBackupException('ArbeitszeitCheck upgrade backup root is not a folder.');
		}

		return $node;
	}

	private function getSnapshotFolder(string $snapshotId): Folder
	{
		UpgradeBackupIntegrity::assertSnapshotId($snapshotId);
		$node = $this->getBackupRootFolder()->get($snapshotId);
		if (!$node instanceof Folder) {
			throw new UpgradeBackupException('Snapshot path is not a folder.');
		}

		return $node;
	}

	private function deleteSnapshotFolderIfExists(string $snapshotId): void
	{
		try {
			$this->getSnapshotFolder($snapshotId)->delete();
		} catch (\Throwable) {
		}
	}

	/**
	 * @return list<string>
	 */
	private function listSnapshotIds(): array
	{
		try {
			$folder = $this->getBackupRootFolder();
		} catch (\Throwable) {
			return [];
		}

		$ids = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof Folder) {
				continue;
			}

			$name = $node->getName();
			if (preg_match(UpgradeBackupIntegrity::SNAPSHOT_ID_PATTERN, $name)) {
				$ids[] = $name;
			}
		}

		return $ids;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readManifest(Folder $snapshotFolder, string $snapshotId): array
	{
		/** @var array<string, mixed> $manifest */
		$manifest = json_decode($this->readFile($snapshotFolder, 'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
		UpgradeBackupIntegrity::validateManifest($manifest, $snapshotId, $manifest['tables'] ?? []);

		return $manifest;
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @param array<string, array{rowCount?: int, checksum?: string}> $tableManifest
	 */
	private function validateSnapshotPayload(
		Folder $snapshotFolder,
		string $snapshotId,
		array $manifest,
		array $tableManifest,
	): void {
		UpgradeBackupIntegrity::validateManifest($manifest, $snapshotId, $tableManifest);

		foreach (['appconfig.json', 'preferences.json', 'migrations.json'] as $requiredFile) {
			if (!$snapshotFolder->nodeExists($requiredFile)) {
				throw new UpgradeBackupException('Snapshot is missing required file: ' . $requiredFile);
			}
		}

		$tablesFolder = $snapshotFolder->get('tables');
		if (!$tablesFolder instanceof Folder) {
			throw new UpgradeBackupException('Snapshot is missing tables/ folder.');
		}

		foreach ($tableManifest as $table => $meta) {
			$content = $this->readFile($tablesFolder, $table . '.json');
			UpgradeBackupIntegrity::assertTablePayload($table, $content, $meta);
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function exportTableRows(string $table): array
	{
		if (!UpgradeBackupCatalog::isBackupTable($table)) {
			throw new UpgradeBackupException('Refusing to export unknown table: ' . $table);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table);
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * @return array<string, string>
	 */
	private function exportAppConfig(): array
	{
		$values = [];
		foreach ($this->config->getAppKeys(UpgradeBackupCatalog::APP_ID) as $key) {
			if (str_starts_with($key, 'upgrade_backup_')) {
				continue;
			}
			$values[$key] = $this->config->getAppValue(UpgradeBackupCatalog::APP_ID, $key);
		}

		return $values;
	}

	/**
	 * @return list<array{userid: string, configkey: string, configvalue: string}>
	 */
	private function exportPreferences(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('userid', 'configkey', 'configvalue')
			->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(UpgradeBackupCatalog::APP_ID)));

		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = [
				'userid' => (string)$row['userid'],
				'configkey' => (string)$row['configkey'],
				'configvalue' => (string)$row['configvalue'],
			];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function exportMigrations(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter(UpgradeBackupCatalog::APP_ID)));

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	private function copyAppDataTree(string $relativePath, Folder $destParent): void
	{
		if (!in_array($relativePath, UpgradeBackupCatalog::APPDATA_FOLDERS, true)) {
			throw new UpgradeBackupException('Refusing to copy unknown app data folder: ' . $relativePath);
		}
		UpgradeBackupIntegrity::assertAppDataFolderName($relativePath);

		try {
			$source = $this->rootFolder->get($this->getAppDataPath($relativePath));
		} catch (NotFoundException) {
			return;
		}

		if (!$source instanceof Folder) {
			return;
		}

		$leafName = basename($relativePath);
		$dest = $destParent->newFolder($leafName);
		$this->copyFolderTree($source, $dest);
	}

	private function copyFolderTree(Folder $source, Folder $dest): void
	{
		foreach ($source->getDirectoryListing() as $node) {
			$name = $node->getName();
			UpgradeBackupIntegrity::assertAppDataNodeName($name);

			if ($node instanceof Folder) {
				$this->copyFolderTree($node, $dest->newFolder($name));
				continue;
			}

			if ($node instanceof File) {
				$dest->newFile($name, $node->getContent());
			}
		}
	}

	private function restoreAppDataFolders(Folder $snapshotFolder): void
	{
		foreach (UpgradeBackupCatalog::APPDATA_FOLDERS as $folderName) {
			$this->restoreAppDataFolder($snapshotFolder, $folderName);
		}
	}

	private function restoreAppDataFolder(Folder $snapshotFolder, string $folderName): void
	{
		if (!in_array($folderName, UpgradeBackupCatalog::APPDATA_FOLDERS, true)) {
			throw new UpgradeBackupException('Refusing to restore unknown app data folder: ' . $folderName);
		}
		UpgradeBackupIntegrity::assertAppDataFolderName($folderName);

		$destPath = $this->getAppDataPath($folderName);
		try {
			$this->rootFolder->get($destPath)->delete();
		} catch (NotFoundException) {
		}

		try {
			$source = $snapshotFolder->get($folderName);
		} catch (NotFoundException) {
			return;
		}

		if (!$source instanceof Folder) {
			return;
		}

		$dest = $this->getAppDataRootFolder()->newFolder($folderName);
		$this->copyFolderTree($source, $dest);
	}

	/**
	 * @param array{checksum?: string, rowCount?: int} $meta
	 */
	private function restoreTableRows(Folder $snapshotFolder, string $table, array $meta): void
	{
		$rowCount = (int)($meta['rowCount'] ?? 0);
		if (!$this->db->tableExists($table)) {
			if ($rowCount > 0) {
				throw new UpgradeBackupException('Cannot restore table ' . $table . ': table does not exist on this instance.');
			}
			return;
		}

		$tablesFolder = $snapshotFolder->get('tables');
		if (!$tablesFolder instanceof Folder) {
			throw new UpgradeBackupException('Snapshot is missing tables/ folder.');
		}

		$content = $this->readFile($tablesFolder, $table . '.json');
		UpgradeBackupIntegrity::assertTablePayload($table, $content, $meta);

		/** @var list<array<string, mixed>> $rows */
		$rows = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		foreach ($rows as $row) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert($table);
			foreach ($row as $column => $value) {
				if (!UpgradeBackupIntegrity::isAllowedColumn((string)$column)) {
					throw new UpgradeBackupException('Snapshot contains invalid column name: ' . $column);
				}
				$qb->setValue(
					(string)$column,
					$qb->createNamedParameter($value),
				);
			}
			$qb->executeStatement();
		}
	}

	private function truncateTableIfExists(string $table): void
	{
		if (!$this->db->tableExists($table) || !UpgradeBackupCatalog::isBackupTable($table)) {
			return;
		}

		$this->db->getQueryBuilder()
			->delete($table)
			->executeStatement();
	}

	private function restoreAppConfig(Folder $snapshotFolder): void
	{
		/** @var array<string, string> $values */
		$values = json_decode($this->readFile($snapshotFolder, 'appconfig.json'), true, 512, JSON_THROW_ON_ERROR);

		foreach ($this->config->getAppKeys(UpgradeBackupCatalog::APP_ID) as $key) {
			if (str_starts_with($key, 'upgrade_backup_')) {
				continue;
			}
			$this->config->deleteAppValue(UpgradeBackupCatalog::APP_ID, $key);
		}

		foreach ($values as $key => $value) {
			if (!UpgradeBackupIntegrity::isAllowedConfigKey((string)$key)) {
				throw new UpgradeBackupException('Snapshot contains invalid app config key: ' . $key);
			}
			$this->config->setAppValue(UpgradeBackupCatalog::APP_ID, (string)$key, (string)$value);
		}
	}

	private function restorePreferences(Folder $snapshotFolder): void
	{
		/** @var list<array{userid: string, configkey: string, configvalue: string}> $rows */
		$rows = json_decode($this->readFile($snapshotFolder, 'preferences.json'), true, 512, JSON_THROW_ON_ERROR);

		$deleteQb = $this->db->getQueryBuilder();
		$deleteQb->delete('preferences')
			->where($deleteQb->expr()->eq('appid', $deleteQb->createNamedParameter(UpgradeBackupCatalog::APP_ID)))
			->executeStatement();

		foreach ($rows as $row) {
			$userId = (string)($row['userid'] ?? '');
			$configKey = (string)($row['configkey'] ?? '');
			if (!UpgradeBackupIntegrity::isAllowedPreferenceUserId($userId)) {
				throw new UpgradeBackupException('Snapshot contains invalid preference user id.');
			}
			if (!UpgradeBackupIntegrity::isAllowedPreferenceKey($configKey)) {
				throw new UpgradeBackupException('Snapshot contains invalid preference key: ' . $configKey);
			}

			$insertQb = $this->db->getQueryBuilder();
			$insertQb->insert('preferences')
				->setValue('userid', $insertQb->createNamedParameter($userId))
				->setValue('appid', $insertQb->createNamedParameter(UpgradeBackupCatalog::APP_ID))
				->setValue('configkey', $insertQb->createNamedParameter($configKey))
				->setValue('configvalue', $insertQb->createNamedParameter((string)($row['configvalue'] ?? '')))
				->executeStatement();
		}
	}

	private function restoreMigrations(Folder $snapshotFolder): void
	{
		/** @var list<array<string, mixed>> $rows */
		$rows = json_decode($this->readFile($snapshotFolder, 'migrations.json'), true, 512, JSON_THROW_ON_ERROR);

		$deleteQb = $this->db->getQueryBuilder();
		$deleteQb->delete('migrations')
			->where($deleteQb->expr()->eq('app', $deleteQb->createNamedParameter(UpgradeBackupCatalog::APP_ID)))
			->executeStatement();

		foreach ($rows as $row) {
			$insertQb = $this->db->getQueryBuilder();
			$insertQb->insert('migrations');
			foreach ($row as $column => $value) {
				if (!UpgradeBackupIntegrity::isAllowedColumn((string)$column)) {
					throw new UpgradeBackupException('Snapshot contains invalid migration column: ' . $column);
				}
				$insertQb->setValue((string)$column, $insertQb->createNamedParameter($value));
			}
			$insertQb->executeStatement();
		}
	}

	private function rotateSnapshots(array $excludeIds = []): void
	{
		$max = UpgradeBackupCatalog::clampMaxSnapshots((int)$this->config->getAppValue(
			UpgradeBackupCatalog::APP_ID,
			UpgradeBackupCatalog::CONFIG_MAX_SNAPSHOTS,
			(string)UpgradeBackupCatalog::DEFAULT_MAX_SNAPSHOTS,
		));

		$exclude = array_fill_keys($excludeIds, true);
		$snapshots = $this->listSnapshots();
		if (count($snapshots) <= $max) {
			return;
		}

		$toRemove = array_slice($snapshots, $max);
		foreach ($toRemove as $snapshot) {
			$id = (string)($snapshot['id'] ?? '');
			if ($id === '' || isset($exclude[$id])) {
				continue;
			}
			$this->deleteSnapshotFolderIfExists($id);
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function writeJsonFile(Folder $parent, string $name, array $payload): void
	{
		$parent->newFile($name, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
	}

	private function readFile(Folder $parent, string $name): string
	{
		$node = $parent->get($name);
		if (!$node instanceof File) {
			throw new UpgradeBackupException('Snapshot file missing: ' . $name);
		}

		return $node->getContent();
	}

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	private function runExclusive(string $label, callable $callback)
	{
		try {
			$this->locking->acquireLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE, $label);
		} catch (LockedException $e) {
			throw new UpgradeBackupException(
				'Another ArbeitszeitCheck backup or restore is already in progress.',
				0,
				$e,
			);
		}

		try {
			return $callback();
		} finally {
			$this->locking->releaseLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function purgeIncompleteSnapshotFolders(): void
	{
		foreach ($this->listSnapshotIds() as $snapshotId) {
			try {
				$folder = $this->getSnapshotFolder($snapshotId);
				if (!$folder->nodeExists('manifest.json')) {
					$folder->delete();
					continue;
				}

				/** @var array<string, mixed> $manifest */
				$manifest = json_decode($this->readFile($folder, 'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
				if (($manifest['complete'] ?? false) !== true) {
					$folder->delete();
				}
			} catch (\Throwable $e) {
				// best-effort: purge must continue past one unreadable snapshot;
				// the corrupt folder id is logged for manual cleanup.
				$this->logger->warning('ArbeitszeitCheck: removing corrupt upgrade backup folder', [
					'app' => UpgradeBackupCatalog::APP_ID,
					'folder' => $snapshotId,
					'exception' => $e,
				]);
				$this->deleteSnapshotFolderIfExists($snapshotId);
			}
		}
	}
}
