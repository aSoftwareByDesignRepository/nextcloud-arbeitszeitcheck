<?php

declare(strict_types=1);

/**
 * Persistence seam for the organisation-wide break-policy document.
 *
 * Reads through OCP\AppFramework\Services\IAppConfig are served from a
 * per-request cache — fine for display, but a wrong merge base for writes:
 * a concurrent admin save may commit after that cache was populated, which
 * is how a plain read-modify-write silently reverts the other admin's
 * fields. Writers must instead serialise on the stored row itself:
 *
 *   atomic(function () use ($store) {
 *       $store->ensureRowsExist();
 *       [$raw, $version] = $store->lockAndRead();   // SELECT ... FOR UPDATE
 *       // merge + validate + write, all inside the transaction
 *   });
 *
 * The row lock makes concurrent saves queue instead of overwriting each
 * other, and keeps the document and its monotonic revision consistent.
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Constants;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IDBConnection;

class BreakPolicyStore
{
	public function __construct(
		private IAppConfig $appConfig,
		private IDBConnection $db,
	) {
	}

	/**
	 * Seed both appconfig rows when absent so lockAndRead() always has a
	 * row to lock. insertIfNotExist is idempotent — racing first-time saves
	 * converge on the winner's row; the loser then locks that row normally.
	 */
	public function ensureRowsExist(): void
	{
		$this->seedIfMissing(Constants::CONFIG_BREAK_POLICY_JSON, '');
		$this->seedIfMissing(Constants::CONFIG_BREAK_POLICY_VERSION, '0');
	}

	/**
	 * Locked read of the policy document and its revision. MUST be called
	 * inside a database transaction (forUpdate() is meaningless otherwise).
	 *
	 * @return array{0: ?string, 1: int} raw stored JSON (null when the row
	 *         is absent or empty) and the current revision
	 */
	public function lockAndRead(): array
	{
		$raw = $this->readLocked(Constants::CONFIG_BREAK_POLICY_JSON);
		$version = $this->readLocked(Constants::CONFIG_BREAK_POLICY_VERSION);
		return [
			$raw === null || trim($raw) === '' ? null : $raw,
			$version === null ? 0 : (int)$version,
		];
	}

	/**
	 * Persist the merged document and its new revision. Call inside the
	 * same transaction that performed lockAndRead() — setAppValueString
	 * participates in it because it shares the connection.
	 */
	public function write(string $json, int $version): void
	{
		$this->appConfig->setAppValueString(Constants::CONFIG_BREAK_POLICY_JSON, $json);
		$this->appConfig->setAppValueString(Constants::CONFIG_BREAK_POLICY_VERSION, (string)$version);
	}

	/**
	 * @internal overridable for tests — a locked read of one appconfig row.
	 */
	protected function readLocked(string $key): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('configvalue')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)))
			->forUpdate();
		$value = $qb->executeQuery()->fetchOne();
		return $value === false ? null : (string)$value;
	}

	private function seedIfMissing(string $key, string $value): void
	{
		if ($this->appConfig->hasAppKey($key)) {
			return;
		}
		try {
			$this->db->insertIfNotExist('appconfig', [
				'appid' => Application::APP_ID,
				'configkey' => $key,
				'configvalue' => $value,
				'configtype' => \OCP\IAppConfig::VALUE_STRING,
			], ['appid', 'configkey']);
		} catch (\Throwable) {
			// A concurrent first save won the insert — the row exists either
			// way, which is all lockAndRead() needs.
		}
	}
}
