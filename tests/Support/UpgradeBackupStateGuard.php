<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

use OCA\ArbeitszeitCheck\Service\UpgradeBackupCatalog;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;

/**
 * Guards shared upgrade-backup state across integration tests.
 *
 * The snapshot rotation keeps at most CONFIG_MAX_SNAPSHOTS folders — an
 * unguarded test run evicts pre-existing (potentially real) backups and
 * overwrites CONFIG_LAST_SNAPSHOT_ID. The guard records the snapshot-id
 * set and both appconfig keys in setUp, raises the rotation ceiling to the
 * hard limit so nothing can be evicted mid-test, and in tearDown removes
 * only the snapshot folders the test created and restores the config.
 */
final class UpgradeBackupStateGuard
{
	private UpgradeBackupService $backupService;
	private IConfig $config;
	private IRootFolder $rootFolder;

	/** @var list<string> snapshot ids with readable manifests at setUp */
	private array $preExistingSnapshotIds = [];

	/**
	 * @var list<string> every on-disk snapshot dir name at setUp —
	 * includes dirs whose manifest is missing/corrupt (listSnapshots()
	 * skips those, which would wrongly delete them as "test-created").
	 */
	private array $preExistingDirNames = [];

	private ?string $previousLastSnapshotId = null;
	private ?string $previousMaxSnapshots = null;

	/** @var array<string, int> appdata folder path => owner uid at setUp */
	private array $folderOwnerUids = [];

	private ?string $snapshotRootPath = null;

	public function __construct()
	{
		$this->backupService = \OCP\Server::get(UpgradeBackupService::class);
		$this->config = \OCP\Server::get(IConfig::class);
		$this->rootFolder = \OCP\Server::get(IRootFolder::class);
	}

	public function setUp(): void
	{
		$this->preExistingSnapshotIds = $this->snapshotIds();
		// Strict '' compare: a stored '0' is a real value, not "absent" —
		// the ?: shorthand would have tearDown delete it instead of
		// restoring it.
		$last = $this->config->getAppValue(
			UpgradeBackupCatalog::APP_ID,
			UpgradeBackupCatalog::CONFIG_LAST_SNAPSHOT_ID,
			'',
		);
		$this->previousLastSnapshotId = $last === '' ? null : $last;
		$max = $this->config->getAppValue(
			UpgradeBackupCatalog::APP_ID,
			UpgradeBackupCatalog::CONFIG_MAX_SNAPSHOTS,
			'',
		);
		$this->previousMaxSnapshots = $max === '' ? null : $max;
		// Raise the rotation ceiling to the hard clamp so the test's
		// snapshots cannot evict a pre-existing backup folder mid-run —
		// guaranteed while pre-existing + test-created <=
		// MAX_SNAPSHOTS_LIMIT; an instance already AT the clamp can still
		// lose its oldest dir, which tearDown() then reports loudly.
		// NOTE: a snapshot created by a concurrent writer between setUp
		// and tearDown is not in the baseline and is deleted — the farm
		// runs single-writer; the window is the test duration.
		$this->config->setAppValue(
			UpgradeBackupCatalog::APP_ID,
			UpgradeBackupCatalog::CONFIG_MAX_SNAPSHOTS,
			(string)UpgradeBackupCatalog::MAX_SNAPSHOTS_LIMIT,
		);
		// A restore can delete+recreate configured appdata folders — when
		// the suite runs as container root the recreated dir is root-owned
		// and the www-data web process can no longer write into it. Record
		// each folder's owner uid so tearDown can repair it.
		$instanceId = $this->config->getSystemValueString('instanceid');
		$dataDir = rtrim($this->config->getSystemValueString('datadirectory'), '/');
		if ($instanceId !== '' && $dataDir !== '') {
			$appDir = $dataDir . '/appdata_' . $instanceId . '/' . UpgradeBackupCatalog::APP_ID;
			$this->snapshotRootPath = $appDir . '/' . UpgradeBackupCatalog::APPDATA_ROOT;
			$this->preExistingDirNames = $this->snapshotEntryNames();
			foreach (UpgradeBackupCatalog::APPDATA_FOLDERS as $folderName) {
				$path = $appDir . '/' . $folderName;
				// If the folder does not exist yet, the appdata app dir's
				// owner is the correct expected owner for anything a
				// restore creates inside it.
				$anchor = is_dir($path) ? $path : $appDir;
				if (is_dir($anchor)) {
					$this->folderOwnerUids[$path] = (int)fileowner($anchor);
				}
			}
		}
	}

	public function tearDown(): void
	{
		// Diff on ENTRY names, not manifests — a test-created dir whose
		// manifest is unreadable at tearDown is invisible to
		// listSnapshots() and would leak. Entries include symlinks and
		// stray files: both are removed with unlink() (never a recursive
		// delete through a link into its target).
		foreach ($this->snapshotEntryNames() as $name) {
			if (!in_array($name, $this->preExistingDirNames, true)) {
				$this->deleteSnapshotEntry($name);
			}
		}
		// Loud failure when a pre-existing VALID snapshot is gone — only
		// possible if the instance was already at the hard clamp and
		// rotation evicted the oldest dir (see setUp note). Corrupt
		// pre-existing dirs may legitimately vanish via the app's own
		// purgeIncompleteSnapshotFolders, so only manifest-validated ids
		// are asserted. The check is collected now but thrown AFTER the
		// config restore + ownership repair below — a loud failure must
		// never skip restoring shared state.
		$missing = array_diff($this->preExistingSnapshotIds, $this->snapshotIds());
		if ($this->previousLastSnapshotId === null) {
			$this->config->deleteAppValue(UpgradeBackupCatalog::APP_ID, UpgradeBackupCatalog::CONFIG_LAST_SNAPSHOT_ID);
		} else {
			$this->config->setAppValue(
				UpgradeBackupCatalog::APP_ID,
				UpgradeBackupCatalog::CONFIG_LAST_SNAPSHOT_ID,
				$this->previousLastSnapshotId,
			);
		}
		if ($this->previousMaxSnapshots === null) {
			$this->config->deleteAppValue(UpgradeBackupCatalog::APP_ID, UpgradeBackupCatalog::CONFIG_MAX_SNAPSHOTS);
		} else {
			$this->config->setAppValue(
				UpgradeBackupCatalog::APP_ID,
				UpgradeBackupCatalog::CONFIG_MAX_SNAPSHOTS,
				$this->previousMaxSnapshots,
			);
		}
		$this->repairAppDataFolderOwners();
		if ($missing !== []) {
			throw new \RuntimeException(
				'Pre-existing upgrade backup snapshot(s) destroyed by test run: '
				. implode(', ', $missing),
			);
		}
	}

	/**
	 * Restore each configured appdata folder to the owner uid it had
	 * before the test — a root-run suite leaves a recreated dir
	 * root-owned, which silently breaks web writes. Only attempts repair
	 * when the current process can actually chown (i.e. is root); a
	 * www-data-run suite never produced the wrong owner in the first
	 * place.
	 */
	private function repairAppDataFolderOwners(): void
	{
		foreach ($this->folderOwnerUids as $path => $ownerUid) {
			if (!is_dir($path)) {
				continue;
			}
			if ((int)fileowner($path) === $ownerUid) {
				continue;
			}
			if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::SELF_FIRST,
			);
			foreach ($iterator as $node) {
				@chown($node->getPathname(), $ownerUid);
			}
			@chown($path, $ownerUid);
		}
	}

	/**
	 * @return list<string>
	 */
	private function snapshotIds(): array
	{
		$ids = [];
		foreach ($this->backupService->listSnapshots() as $snapshot) {
			$id = (string)($snapshot['id'] ?? '');
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @return list<string> on-disk snapshot entry names — real dirs AND
	 * symlinks/files, incl. manifestless. Symlinks are listed separately
	 * from dirs by is_link() (is_dir follows the link): tearDown must
	 * never let a recursive delete iterate through a link into its
	 * target.
	 */
	private function snapshotEntryNames(): array
	{
		$root = $this->snapshotRootPath;
		if ($root === null || !is_dir($root)) {
			return [];
		}
		$names = [];
		foreach (scandir($root) ?: [] as $entry) {
			$diskPath = $root . '/' . $entry;
			if ($entry !== '.' && $entry !== '..'
				&& (is_link($diskPath) || file_exists($diskPath))) {
				$names[] = $entry;
			}
		}

		return $names;
	}

	private function deleteSnapshotEntry(string $name): void
	{
		$diskPath = ($this->snapshotRootPath ?? '') . '/' . $name;
		if ($this->snapshotRootPath === null) {
			return;
		}
		// Never delete through a link: is_dir follows symlinks and
		// Local::rmdir would iterate into the TARGET's contents. A link
		// (or a plain file) is removed with unlink() — the link itself,
		// never the target. A dir swapped to a link between scandir and
		// this call is caught by the same check.
		if (is_link($diskPath) || (file_exists($diskPath) && !is_dir($diskPath))) {
			@unlink($diskPath);
			return;
		}
		if (!is_dir($diskPath)) {
			return;
		}
		$instanceId = $this->config->getSystemValueString('instanceid');
		if ($instanceId === '') {
			return;
		}
		$path = implode('/', [
			'appdata_' . $instanceId,
			UpgradeBackupCatalog::APP_ID,
			UpgradeBackupCatalog::APPDATA_ROOT,
			$name,
		]);
		try {
			$this->rootFolder->get($path)->delete();
		} catch (NotFoundException) {
		} catch (\Throwable) {
		}
	}
}
