<?php

declare(strict_types=1);

/**
 * PostCommitEffectQueue contract: external effects (SMTP mail) must run
 * inline only when no transaction is open; inside an outer transaction they
 * are deferred until the owner calls flush(), and discard() on rollback
 * drops them so they can never describe reverted data.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\PostCommitEffectQueue;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PostCommitEffectQueueTest extends TestCase
{
	private IDBConnection $db;
	private LoggerInterface $logger;

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function queue(): PostCommitEffectQueue
	{
		return new PostCommitEffectQueue($this->db, $this->logger);
	}

	public function testRunsInlineWhenNoTransactionOpen(): void
	{
		$this->db->method('inTransaction')->willReturn(false);
		$ran = false;
		$this->queue()->runAfterCurrentCommit(static function () use (&$ran): void {
			$ran = true;
		});
		$this->assertTrue($ran);
	}

	public function testDefersWhileTransactionOpenUntilFlush(): void
	{
		$this->db->method('inTransaction')->willReturn(true);
		$queue = $this->queue();
		$order = [];
		$queue->runAfterCurrentCommit(static function () use (&$order): void {
			$order[] = 'effect';
		});
		$this->assertSame([], $order, 'effect must not run while the outer transaction is still open');
		$this->assertSame(1, $queue->pendingCount());
		$order[] = 'commit';
		$queue->flush();
		$this->assertSame(['commit', 'effect'], $order, 'deferred effect must run after the owning commit');
		$this->assertSame(0, $queue->pendingCount());
	}

	public function testDiscardDropsDeferredEffects(): void
	{
		$this->db->method('inTransaction')->willReturn(true);
		$queue = $this->queue();
		$ran = false;
		$queue->runAfterCurrentCommit(static function () use (&$ran): void {
			$ran = true;
		});
		$queue->discard();
		$queue->flush();
		$this->assertFalse($ran, 'discarded effects must never fire after rollback');
	}

	public function testFlushIsBestEffortAcrossFailingEffects(): void
	{
		$this->db->method('inTransaction')->willReturn(true);
		$queue = $this->queue();
		$secondRan = false;
		$queue->runAfterCurrentCommit(static function (): void {
			throw new \RuntimeException('smtp down');
		});
		$queue->runAfterCurrentCommit(static function () use (&$secondRan): void {
			$secondRan = true;
		});
		$this->logger->expects($this->once())->method('error');
		$queue->flush();
		$this->assertTrue($secondRan, 'a failing effect must not suppress later effects');
	}

	public function testFlushDoesNotReRunEffects(): void
	{
		$this->db->method('inTransaction')->willReturn(true);
		$queue = $this->queue();
		$count = 0;
		$queue->runAfterCurrentCommit(static function () use (&$count): void {
			$count++;
		});
		$queue->flush();
		$queue->flush();
		$this->assertSame(1, $count);
	}
}
