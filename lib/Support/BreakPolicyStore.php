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
use OCP\DB\Exception as DBException;
use OCP\IDBConnection;
use OCP\Server;

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
	 * same transaction that performed lockAndRead() so both direct updates
	 * participate in the transaction and bypass the per-request config cache.
	 */
	public function write(string $json, int $version): void
	{
		$this->updateValue(Constants::CONFIG_BREAK_POLICY_JSON, $json);
		$this->updateValue(Constants::CONFIG_BREAK_POLICY_VERSION, (string)$version);
		// Direct appconfig writes bypass IAppConfig's cache invalidation — with
		// 'cache_app_config' enabled every later request would keep serving the
		// pre-write document. Clearing inside the transaction is safe: a
		// rollback restores the DB row the repopulated cache then reflects.
		// Resolved lazily: the app DI container cannot autowire OCP\IAppConfig
		// (its alias lives on the server container).
		Server::get(\OCP\IAppConfig::class)->clearCache();
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
			$qb = $this->db->getQueryBuilder();
			$qb->insert('appconfig')
				->setValue('appid', $qb->createNamedParameter(Application::APP_ID))
				->setValue('configkey', $qb->createNamedParameter($key))
				->setValue('configvalue', $qb->createNamedParameter($value));
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	private function updateValue(string $key, string $value): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('appconfig')
			->set('configvalue', $qb->createNamedParameter($value))
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)))
			->executeStatement();
	}
}
