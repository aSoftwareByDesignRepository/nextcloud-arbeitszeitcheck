<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

use OCA\ArbeitszeitCheck\Service\UpgradeBackupCatalog;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;

/**
 * Integration-test guard for the pre-migration backup repair step.
 *
 * The repair step writes a real snapshot under appdata and updates the
 * `upgrade_backup_last_snapshot_id` registry. The guard records the
 * pre-test state and removes snapshots the test created, so the suite
 * leaves no backup folders behind and cannot rotate away real backups.
 */
final class UpgradeBackupStateGuard
{
	/** @var list<string> */
	private array $snapshotsBefore = [];

	private ?string $lastSnapshotIdBefore = null;

	public function setUp(): void
	{
		$service = \OC::$server->get(UpgradeBackupService::class);
		foreach ($service->listSnapshots() as $snapshot) {
			$this->snapshotsBefore[] = (string)($snapshot['id'] ?? '');
		}
		$this->lastSnapshotIdBefore = $service->getLatestSnapshotId();
	}

	public function tearDown(): void
	{
		$service = \OC::$server->get(UpgradeBackupService::class);
		foreach ($service->listSnapshots() as $snapshot) {
			$id = (string)($snapshot['id'] ?? '');
			if ($id === '' || in_array($id, $this->snapshotsBefore, true)) {
				continue;
			}
			$this->deleteSnapshotFolder($id);
		}

		$config = \OC::$server->get(IConfig::class);
		if ($this->lastSnapshotIdBefore === null) {
			$config->deleteAppValue(UpgradeBackupCatalog::APP_ID, UpgradeBackupCatalog::CONFIG_LAST_SNAPSHOT_ID);
		} else {
			$config->setAppValue(
				UpgradeBackupCatalog::APP_ID,
				UpgradeBackupCatalog::CONFIG_LAST_SNAPSHOT_ID,
				$this->lastSnapshotIdBefore,
			);
		}
	}

	private function deleteSnapshotFolder(string $snapshotId): void
	{
		$config = \OC::$server->get(IConfig::class);
		$instanceId = $config->getSystemValueString('instanceid');
		if ($instanceId === '') {
			return;
		}

		$path = 'appdata_' . $instanceId . '/' . UpgradeBackupCatalog::APP_ID
			. '/' . UpgradeBackupCatalog::APPDATA_ROOT . '/' . $snapshotId;
		try {
			$node = \OC::$server->get(IRootFolder::class)->get($path);
			if ($node instanceof Folder) {
				$node->delete();
			}
		} catch (\Throwable) {
			// best-effort cleanup — a leftover test snapshot must not fail the suite
		}
	}
}
