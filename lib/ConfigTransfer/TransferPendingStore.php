<?php

declare(strict_types=1);

/**
 * Pending-transfer blob store.
 *
 * Between the mandatory dry-run and the queued apply, the decrypted payload
 * must survive the request — but the admin's passphrase must never persist.
 * The payload is therefore re-wrapped with a key derived from the instance
 * `secret` (see TransferCrypto::instanceWrapKey) and stored under
 * appdata_<instance>/<app>/transfer-pending/<id>. Blobs expire after TTL;
 * every preview sweep-deletes the expired ones, and a finished apply
 * deletes its own blob — success or failure.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\ConfigTransfer;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

final class TransferPendingStore
{
	public const TTL_SECONDS = 3600;
	private const SUBDIR = 'transfer-pending';
	private const ID_PATTERN = '/^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{12}$/';

	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private string $appId,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/**
	 * @param array<string,mixed> $record must contain 'payload'
	 *                                    (the decrypted transfer payload)
	 */
	public function save(array $record): string
	{
		$record['createdAt'] = time();
		$id = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
		$wrapped = TransferCrypto::serverWrap(
			json_encode($record, JSON_THROW_ON_ERROR),
			TransferCrypto::instanceWrapKey($this->instanceSecret(), $this->appId),
		);
		$this->pendingRoot()->newFile($id, $wrapped);
		return $id;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function load(string $id): array
	{
		$this->assertId($id);
		try {
			$node = $this->pendingRoot()->get($id);
		} catch (NotFoundException $e) {
			throw new TransferException('TRANSFER_PENDING_NOT_FOUND', [], $e);
		}
		if (!$node instanceof \OCP\Files\File) {
			throw new TransferException('TRANSFER_PENDING_NOT_FOUND');
		}
		$plain = TransferCrypto::serverUnwrap(
			$node->getContent(),
			TransferCrypto::instanceWrapKey($this->instanceSecret(), $this->appId),
		);
		/** @var array<string,mixed> $record */
		// +8: the store wraps the payload in its own record envelope — the
		// payload's own depth bound was already enforced at parse time.
		$record = json_decode($plain, true, TransferFormat::MAX_JSON_DEPTH + 8, JSON_THROW_ON_ERROR);
		$age = time() - (int)($record['createdAt'] ?? 0);
		if ($age > self::TTL_SECONDS) {
			$this->delete($id);
			throw new TransferException('TRANSFER_PENDING_EXPIRED', ['ttl' => self::TTL_SECONDS]);
		}
		if (!isset($record['payload']) || !is_array($record['payload'])) {
			throw new TransferException('TRANSFER_PENDING_NOT_FOUND');
		}
		return $record;
	}

	public function delete(string $id): void
	{
		try {
			$this->assertId($id);
			$this->pendingRoot()->get($id)->delete();
		} catch (\Throwable $e) {
			// best-effort cleanup — an absent/expired blob is the goal state,
			// but a real storage failure must not vanish silently.
			$this->logger?->warning('config transfer pending blob delete failed', [
				'app' => $this->appId, 'exception' => $e,
			]);
		}
	}

	/**
	 * Delete every blob older than TTL. Called on each preview so abandoned
	 * dry-runs do not accumulate ciphertext in appdata.
	 */
	public function sweep(): int
	{
		$removed = 0;
		try {
			$root = $this->pendingRoot();
		} catch (\Throwable $e) {
			$this->logger?->warning('config transfer pending sweep cannot open store', [
				'app' => $this->appId, 'exception' => $e,
			]);
			return 0;
		}
		foreach ($root->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (!preg_match(self::ID_PATTERN, $name)) {
				continue;
			}
			$stamp = substr($name, 0, 15); // YYYYMMDDTHHMMSS
			$created = \DateTimeImmutable::createFromFormat('Ymd\THis', $stamp, new \DateTimeZone('UTC'));
			$age = $created === false ? self::TTL_SECONDS + 1 : time() - $created->getTimestamp();
			if ($age > self::TTL_SECONDS) {
				try {
					$node->delete();
					$removed++;
				} catch (\Throwable $e) {
					// cleanup only, non-fatal — a stranded expired blob is
					// retried by the next sweep; log so it stays observable.
					$this->logger?->warning('config transfer pending sweep delete failed', [
						'app' => $this->appId, 'exception' => $e,
					]);
				}
			}
		}
		return $removed;
	}

	private function assertId(string $id): void
	{
		if (!preg_match(self::ID_PATTERN, $id)) {
			throw new TransferException('TRANSFER_PENDING_NOT_FOUND');
		}
	}

	private function instanceSecret(): string
	{
		return (string)$this->config->getSystemValue('secret', '');
	}

	private function appDataRoot(): Folder
	{
		$instanceId = (string)$this->config->getSystemValue('instanceid', '');
		if ($instanceId === '') {
			throw new TransferException('TRANSFER_PREFLIGHT_FAILED', ['check' => 'instanceid']);
		}
		$path = 'appdata_' . $instanceId . '/' . $this->appId;
		try {
			$node = $this->rootFolder->get($path);
		} catch (NotFoundException) {
			$instance = $this->rootFolder->get('appdata_' . $instanceId);
			if (!$instance instanceof Folder) {
				throw new TransferException('TRANSFER_PREFLIGHT_FAILED', ['check' => 'appdata_root']);
			}
			$node = $instance->newFolder($this->appId);
		}
		if (!$node instanceof Folder) {
			throw new TransferException('TRANSFER_PREFLIGHT_FAILED', ['check' => 'appdata_root']);
		}
		return $node;
	}

	private function pendingRoot(): Folder
	{
		$app = $this->appDataRoot();
		try {
			$node = $app->get(self::SUBDIR);
		} catch (NotFoundException) {
			return $app->newFolder(self::SUBDIR);
		}
		if (!$node instanceof Folder) {
			throw new TransferException('TRANSFER_PREFLIGHT_FAILED', ['check' => 'pending_dir']);
		}
		return $node;
	}
}
