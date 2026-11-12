<?php

declare(strict_types=1);

/**
 * Transfer receipts + apply progress state — appconfig `internal` keys.
 *
 *   transfer_receipts        JSON list, newest first, capped at MAX_RECEIPTS
 *   transfer_job_state       JSON object {status, phase, percent, pendingId,
 *                            actor, startedAt, finishedAt, errorCode}
 *   transfer_last_import_at  unix ts — feeds the stale-file warning (FS-4)
 *
 * These are the only transfer bookkeeping keys and they are `internal`
 * class — they never leave the instance (spec NN-06).
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\ConfigTransfer;

use OCP\IConfig;

final class TransferReceiptStore
{
	public const KEY_RECEIPTS = 'transfer_receipts';
	public const KEY_JOB_STATE = 'transfer_job_state';
	public const KEY_LAST_IMPORT_AT = 'transfer_last_import_at';
	public const MAX_RECEIPTS = 10;

	public function __construct(
		private IConfig $config,
		private string $appId,
	) {
	}

	/**
	 * @param array<string,mixed> $receipt
	 */
	public function addReceipt(array $receipt): void
	{
		$receipts = $this->receipts();
		array_unshift($receipts, $receipt);
		$receipts = array_slice($receipts, 0, self::MAX_RECEIPTS);
		$this->config->setAppValue($this->appId, self::KEY_RECEIPTS, json_encode($receipts, JSON_THROW_ON_ERROR));
		if (($receipt['result'] ?? '') === 'applied') {
			$this->config->setAppValue($this->appId, self::KEY_LAST_IMPORT_AT, (string)time());
		}
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function receipts(): array
	{
		$raw = $this->config->getAppValue($this->appId, self::KEY_RECEIPTS, '[]');
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
	}

	public function lastReceipt(): ?array
	{
		return $this->receipts()[0] ?? null;
	}

	public function lastImportAt(): int
	{
		return (int)$this->config->getAppValue($this->appId, self::KEY_LAST_IMPORT_AT, '0');
	}

	// ── apply progress ──────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $extra
	 */
	public function setJobState(string $status, string $phase, int $percent, array $extra = []): void
	{
		$state = [
			'status' => $status,           // idle|queued|running|done|failed
			'phase' => $phase,
			'percent' => max(0, min(100, $percent)),
			'updatedAt' => time(),
		] + $extra;
		$this->config->setAppValue($this->appId, self::KEY_JOB_STATE, json_encode($state, JSON_THROW_ON_ERROR));
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function jobState(): ?array
	{
		$raw = $this->config->getAppValue($this->appId, self::KEY_JOB_STATE, '');
		if ($raw === '') {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}

	public function clearJobState(): void
	{
		$this->config->deleteAppValue($this->appId, self::KEY_JOB_STATE);
	}

	public function internalKeyNames(): array
	{
		return [self::KEY_RECEIPTS, self::KEY_JOB_STATE, self::KEY_LAST_IMPORT_AT];
	}
}
