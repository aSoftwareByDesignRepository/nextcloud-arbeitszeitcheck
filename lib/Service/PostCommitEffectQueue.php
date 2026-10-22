<?php

declare(strict_types=1);

/**
 * Request-scoped buffer for side effects that cannot roll back (SMTP mail,
 * external calls). Writers that run "after commit" inside their own service
 * method may still sit inside an OUTER transaction (e.g. manager bulk
 * decisions) whose rollback can still undo the data the effect announces.
 * Routing the effect through this queue defers it until the owning
 * transaction has actually committed.
 *
 * Contract for transaction owners: call flush() only after the outermost
 * commit succeeded, and discard() on rollback/error paths.
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

final class PostCommitEffectQueue
{
	/** @var list<callable():void> */
	private array $pending = [];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Run the effect immediately when no transaction is open (the caller's
	 * own commit is final), otherwise defer it until the outer transaction
	 * owner calls flush().
	 *
	 * @param callable():void $effect
	 */
	public function runAfterCurrentCommit(callable $effect): void
	{
		if ($this->db->inTransaction()) {
			$this->pending[] = $effect;
			return;
		}
		$effect();
	}

	/**
	 * Execute every deferred effect. Best-effort: a failing effect is logged
	 * and skipped so it cannot pretend the already-committed batch failed or
	 * suppress the remaining effects.
	 */
	public function flush(): void
	{
		$pending = $this->pending;
		$this->pending = [];
		foreach ($pending as $effect) {
			try {
				$effect();
			} catch (\Throwable $e) {
				$this->logger->error(
					'Post-commit effect failed: ' . $e->getMessage(),
					['exception' => $e, 'app' => 'arbeitszeitcheck']
				);
			}
		}
	}

	/** Drop deferred effects — mandatory on rollback so nothing fires for reverted data. */
	public function discard(): void
	{
		$this->pending = [];
	}

	public function pendingCount(): int
	{
		return count($this->pending);
	}
}
