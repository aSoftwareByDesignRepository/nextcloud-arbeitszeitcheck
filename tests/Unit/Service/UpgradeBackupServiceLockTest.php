<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\UpgradeBackupCatalog;
use OCA\ArbeitszeitCheck\Exception\UpgradeBackupException;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Filesystem/lock behaviour: exclusive-run mapping, snapshot listing,
 * incomplete-folder purge. Heavy folder trees are mocked.
 */
final class UpgradeBackupServiceLockTest extends TestCase
{
	private IConfig&MockObject $config;
	private IRootFolder&MockObject $rootFolder;
	private ILockingProvider&MockObject $locking;
	private UpgradeBackupService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = '') => $key === 'instanceid' ? 'iid123' : $default
		);
		$this->service = new UpgradeBackupService(
			$this->createMock(IDBConnection::class),
			$this->config,
			$this->rootFolder,
			$this->createMock(IAppManager::class),
			$this->locking,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testCreateSnapshotMapsLockContentionToBackupException(): void
	{
		$this->locking->method('acquireLock')
			->willThrowException(new LockedException('x'));
		$this->expectException(UpgradeBackupException::class);
		$this->expectExceptionMessage('already in progress');
		$this->service->createSnapshot('pre-upgrade');
	}

	public function testRunExclusiveReleasesLockWhenCallbackThrows(): void
	{
		// instanceid empty -> getAppDataPath throws inside the exclusive section
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('');
		$service = new UpgradeBackupService(
			$this->createMock(IDBConnection::class),
			$config,
			$this->rootFolder,
			$this->createMock(IAppManager::class),
			$this->locking,
			$this->createMock(LoggerInterface::class),
		);
		$this->locking->expects($this->once())->method('acquireLock');
		$this->locking->expects($this->once())->method('releaseLock');

		$this->expectException(UpgradeBackupException::class);
		$service->createSnapshot('pre-upgrade');
	}

	public function testGetLatestSnapshotIdReturnsNullWhenRootMissing(): void
	{
		$this->rootFolder->method('get')->willThrowException(new NotFoundException());
		$this->assertNull($this->service->getLatestSnapshotId());
		$this->assertSame([], $this->service->listSnapshots());
	}

	public function testGetLatestSnapshotIdReturnsNewestComplete(): void
	{
		[$backupRoot, $snapFolder] = $this->folderTreeWithOneSnapshot();
		$this->assertSame('20260102T030405Z-abcdef12', $this->service->getLatestSnapshotId());
	}

	public function testPurgeIncompleteSnapshotsDeletesNonCompleteFolders(): void
	{
		// two folders: one complete manifest, one incomplete → purge deletes the latter
		$deleted = [];
		$backupRoot = $this->createMock(Folder::class);
		$appRoot = $this->createMock(Folder::class);

		$incomplete = $this->createMock(Folder::class);
		$incomplete->method('getName')->willReturn('20260101T000000Z-11111111');
		$incomplete->method('nodeExists')->willReturn(false);
		$incomplete->method('delete')->willReturnCallback(function () use (&$deleted) {
			$deleted[] = 'incomplete';
		});

		$tables = ['at_absences' => ['rowCount' => 0, 'checksum' => 'x']];
		$manifest = json_encode([
			'format' => UpgradeBackupCatalog::FORMAT_VERSION,
			'appId' => UpgradeBackupCatalog::APP_ID,
			'id' => '20260102T030405Z-abcdef12',
			'complete' => true,
			'createdAt' => '2026-01-02T03:04:05Z',
			'tables' => $tables,
			'integrity' => hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR)),
		]);
		$manifestFile = $this->createMock(File::class);
		$manifestFile->method('getContent')->willReturn($manifest);
		$complete = $this->createMock(Folder::class);
		$complete->method('getName')->willReturn('20260102T030405Z-abcdef12');
		$complete->method('nodeExists')->willReturn(true);
		$complete->method('get')->with('manifest.json')->willReturn($manifestFile);
		$complete->method('delete')->willReturnCallback(function () use (&$deleted) {
			$deleted[] = 'complete';
		});

		$backupRoot->method('getDirectoryListing')->willReturn([$incomplete, $complete]);
		$backupRoot->method('get')->willReturnCallback(static function (string $name) use ($incomplete, $complete) {
			return $name === '20260102T030405Z-abcdef12' ? $complete : $incomplete;
		});
		$appRoot->method('get')->with(UpgradeBackupCatalog::APPDATA_ROOT)->willReturn($backupRoot);
		$this->rootFolder->method('get')->with('appdata_iid123/' . UpgradeBackupCatalog::APP_ID)
			->willReturn($appRoot);

		// createSnapshot runs purgeIncompleteSnapshotFolders first; it will fail
		// later on DB export — catch whatever comes after the purge we care about.
		try {
			$this->service->createSnapshot('pre-upgrade');
		} catch (\Throwable) {
		}

		$this->assertContains('incomplete', $deleted);
		$this->assertNotContains('complete', $deleted);
	}

	public function testPurgeSurvivesCorruptManifestAndDeletesFolder(): void
	{
		// corrupt snapshot: nodeExists true but manifest read explodes ->
		// warning logged, best-effort delete, purge continues.
		$corrupt = $this->createMock(Folder::class);
		$corrupt->method('getName')->willReturn('20260103T000000Z-22222222');
		$corrupt->method('nodeExists')->willReturn(true);
		$corrupt->method('get')->with('manifest.json')
			->willThrowException(new \RuntimeException('read exploded'));
		$corrupt->expects($this->once())->method('delete');

		$backupRoot = $this->createMock(Folder::class);
		$backupRoot->method('getDirectoryListing')->willReturn([$corrupt]);
		$backupRoot->method('get')->with('20260103T000000Z-22222222')->willReturn($corrupt);

		$appRoot = $this->createMock(Folder::class);
		$appRoot->method('get')->with(UpgradeBackupCatalog::APPDATA_ROOT)->willReturn($backupRoot);
		$this->rootFolder->method('get')->with('appdata_iid123/' . UpgradeBackupCatalog::APP_ID)
			->willReturn($appRoot);

		try {
			$this->service->createSnapshot('pre-upgrade');
		} catch (\Throwable) {
		}
	}

	/** @return array{0: Folder&MockObject, 1: Folder&MockObject} */
	private function folderTreeWithOneSnapshot(): array
	{
		$tables = ['at_absences' => ['rowCount' => 0, 'checksum' => 'x']];
		$manifest = json_encode([
			'format' => UpgradeBackupCatalog::FORMAT_VERSION,
			'appId' => UpgradeBackupCatalog::APP_ID,
			'id' => '20260102T030405Z-abcdef12',
			'complete' => true,
			'createdAt' => '2026-01-02T03:04:05Z',
			'tables' => $tables,
			'integrity' => hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR)),
		]);
		$manifestFile = $this->createMock(File::class);
		$manifestFile->method('getContent')->willReturn($manifest);

		$snap = $this->createMock(Folder::class);
		$snap->method('getName')->willReturn('20260102T030405Z-abcdef12');
		$snap->method('get')->with('manifest.json')->willReturn($manifestFile);

		$backupRoot = $this->createMock(Folder::class);
		$backupRoot->method('getDirectoryListing')->willReturn([$snap]);
		$backupRoot->method('get')->with('20260102T030405Z-abcdef12')->willReturn($snap);

		$appRoot = $this->createMock(Folder::class);
		$appRoot->method('get')->with(UpgradeBackupCatalog::APPDATA_ROOT)->willReturn($backupRoot);

		$this->rootFolder->method('get')->with('appdata_iid123/' . UpgradeBackupCatalog::APP_ID)
			->willReturn($appRoot);

		return [$backupRoot, $snap];
	}

	// ---------------------------------------------------------------
	// copyFolderTree / copyAppDataTree / restoreAppDataFolder
	// (reachable only when APPDATA_FOLDERS gains entries — pinned via
	//  reflection so the traversal guard + recursion stay provable)
	// ---------------------------------------------------------------

	public function testCopyFolderTreeCopiesNestedFilesAndRejectsTraversal(): void
	{
		$m = new \ReflectionMethod(UpgradeBackupService::class, 'copyFolderTree');
		$m->setAccessible(true);

		$file = $this->createMock(\OCP\Files\File::class);
		$file->method('getName')->willReturn('a.txt');
		$file->method('getContent')->willReturn('hello');

		$sub = $this->createMock(Folder::class);
		$sub->method('getName')->willReturn('sub');
		$sub->method('getDirectoryListing')->willReturn([$file]);

		$source = $this->createMock(Folder::class);
		$source->method('getDirectoryListing')->willReturn([$sub]);

		$destSub = $this->createMock(Folder::class);
		$written = [];
		$destSub->method('newFile')->willReturnCallback(function (string $n, $c) use (&$written) {
			$written[$n] = $c;
			return $this->createMock(\OCP\Files\File::class);
		});
		$dest = $this->createMock(Folder::class);
		$dest->method('newFolder')->with('sub')->willReturn($destSub);

		$m->invoke($this->service, $source, $dest);
		$this->assertSame(['a.txt' => 'hello'], $written);

		// traversal attempt aborts the copy
		$evil = $this->createMock(\OCP\Files\File::class);
		$evil->method('getName')->willReturn('..');
		$badSource = $this->createMock(Folder::class);
		$badSource->method('getDirectoryListing')->willReturn([$evil]);
		try {
			$m->invoke($this->service, $badSource, $dest);
			$this->fail('expected UpgradeBackupException');
		} catch (\Throwable $e) {
			$this->assertStringContainsString('Unsafe file name', $e->getMessage());
		}
	}

	public function testCopyAppDataTreeRefusesUnknownFolder(): void
	{
		$m = new \ReflectionMethod(UpgradeBackupService::class, 'copyAppDataTree');
		$m->setAccessible(true);
		try {
			$m->invoke($this->service, '../etc', $this->createMock(Folder::class));
			$this->fail('expected UpgradeBackupException');
		} catch (\Throwable $e) {
			$this->assertStringContainsString('Refusing to copy unknown app data folder', $e->getMessage());
		}
	}

	public function testRestoreAppDataFolderRefusesUnknownFolder(): void
	{
		$m = new \ReflectionMethod(UpgradeBackupService::class, 'restoreAppDataFolder');
		$m->setAccessible(true);
		try {
			$m->invoke($this->service, $this->createMock(Folder::class), 'not-allowed');
			$this->fail('expected UpgradeBackupException');
		} catch (\Throwable $e) {
			$this->assertStringContainsString('Refusing to restore unknown app data folder', $e->getMessage());
		}
	}
}