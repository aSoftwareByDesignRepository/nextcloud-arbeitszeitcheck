<?php

declare(strict_types=1);

/**
 * Validation helpers for upgrade backup snapshots (ids, manifests, SQL identifiers).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Exception\UpgradeBackupException;

final class UpgradeBackupIntegrity
{
	public const SNAPSHOT_ID_PATTERN = '/^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}$/';

	public const MAX_REASON_LENGTH = 120;

	/**
	 * @param array<string, mixed> $manifest
	 * @param array<string, array{rowCount?: int, checksum?: string}> $tableManifest
	 */
	public static function validateManifest(
		array $manifest,
		string $expectedSnapshotId,
		array $tableManifest,
	): void {
		if ((int)($manifest['format'] ?? 0) !== UpgradeBackupCatalog::FORMAT_VERSION) {
			throw new UpgradeBackupException('Unsupported backup format version.');
		}

		if (($manifest['appId'] ?? '') !== UpgradeBackupCatalog::APP_ID) {
			throw new UpgradeBackupException('Snapshot app id does not match ArbeitszeitCheck.');
		}

		if (($manifest['id'] ?? '') !== $expectedSnapshotId) {
			throw new UpgradeBackupException('Snapshot manifest id does not match folder name.');
		}

		if (($manifest['complete'] ?? false) !== true) {
			throw new UpgradeBackupException('Snapshot is incomplete and cannot be restored.');
		}

		if ($tableManifest === []) {
			throw new UpgradeBackupException('Snapshot contains no table metadata.');
		}

		foreach (array_keys($tableManifest) as $table) {
			if (!UpgradeBackupCatalog::isBackupTable($table)) {
				throw new UpgradeBackupException('Snapshot references unknown table: ' . $table);
			}
			if (!self::isAllowedTableName($table)) {
				throw new UpgradeBackupException('Snapshot references invalid table name: ' . $table);
			}
		}

		$expectedIntegrity = (string)($manifest['integrity'] ?? '');
		if ($expectedIntegrity === '') {
			throw new UpgradeBackupException('Snapshot is missing integrity hash.');
		}
		$actualIntegrity = hash('sha256', json_encode($tableManifest, JSON_THROW_ON_ERROR));
		if (!hash_equals($expectedIntegrity, $actualIntegrity)) {
			throw new UpgradeBackupException('Snapshot integrity hash does not match table metadata.');
		}
	}

	public static function assertSnapshotId(string $snapshotId): void
	{
		if (!preg_match(self::SNAPSHOT_ID_PATTERN, $snapshotId)) {
			throw new UpgradeBackupException('Invalid snapshot id.');
		}
	}

	public static function normalizeReason(string $reason): string
	{
		$reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
		if ($reason === '') {
			return 'manual';
		}

		if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
			return mb_substr($reason, 0, self::MAX_REASON_LENGTH);
		}

		return $reason;
	}

	public static function isAllowedColumn(string $column): bool
	{
		return self::isAllowedTableName($column);
	}

	public static function isAllowedTableName(string $table): bool
	{
		return (bool)preg_match('/^[a-z_][a-z0-9_]*$/i', $table);
	}

	public static function isAllowedConfigKey(string $key): bool
	{
		return $key !== ''
			&& strlen($key) <= 256
			&& (bool)preg_match('/^[a-z0-9._:-]+$/i', $key);
	}

	public static function isAllowedPreferenceUserId(string $userId): bool
	{
		return $userId !== ''
			&& strlen($userId) <= 64
			&& (bool)preg_match('/^[a-z0-9._@-]+$/i', $userId);
	}

	public static function isAllowedPreferenceKey(string $key): bool
	{
		return self::isAllowedConfigKey($key);
	}

	public static function isAllowedAppDataName(string $name): bool
	{
		return $name !== ''
			&& strlen($name) <= 128
			&& (bool)preg_match('/^[a-z0-9._-]+$/i', $name)
			&& !str_contains($name, '/')
			&& !str_contains($name, '\\')
			&& $name !== '.'
			&& $name !== '..';
	}

	public static function assertAppDataFolderName(string $name): void
	{
		if (!self::isAllowedAppDataName($name)) {
			throw new UpgradeBackupException('Invalid app data folder name: ' . $name);
		}
	}

	public static function assertAppDataNodeName(string $name): void
	{
		if (!self::isAllowedAppDataName($name)) {
			throw new UpgradeBackupException('Unsafe file name in app data snapshot: ' . $name);
		}
	}

	/**
	 * @param array{checksum?: string, rowCount?: int} $meta
	 */
	public static function assertTablePayload(string $table, string $content, array $meta): void
	{
		if (!is_string($meta['checksum'] ?? null)) {
			throw new UpgradeBackupException('Snapshot table metadata is missing a checksum for ' . $table . '.');
		}

		$checksum = hash('sha256', $content);
		if (!hash_equals($meta['checksum'], $checksum)) {
			throw new UpgradeBackupException('Checksum mismatch for table ' . $table . '.');
		}

		/** @var list<array<string, mixed>> $rows */
		$rows = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

		if (isset($meta['rowCount']) && (int)$meta['rowCount'] !== count($rows)) {
			throw new UpgradeBackupException('Row count mismatch for table ' . $table . '.');
		}
	}
}
