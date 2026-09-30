<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshot;
use OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshotMapper;
use OCA\ArbeitszeitCheck\Service\EntitlementSnapshotService;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class EntitlementSnapshotServiceTest extends TestCase
{
	private function fixture(?callable $upsert = null): array
	{
		$mapper = $this->createMock(EntitlementComputationSnapshotMapper::class);
		$mapper->method('upsertSnapshot')->willReturnCallback(
			$upsert ?? static fn (EntitlementComputationSnapshot $s) => $s
		);
		$locking = $this->createMock(ILockingProvider::class);
		return [new EntitlementSnapshotService($mapper, $locking), $mapper, $locking];
	}

	public function testStoreFillsTraceV1EnvelopeForLegacyTrace(): void
	{
		$captured = null;
		[$svc] = $this->fixture(function (EntitlementComputationSnapshot $s) use (&$captured) {
			$captured = $s;
			return $s;
		});

		$svc->store(
			'alice',
			2026,
			new \DateTimeImmutable('2026-06-30 12:00:00'),
			25.5,
			'layered',
			9,
			[],
			'test'
		);

		$this->assertNotNull($captured);
		$this->assertSame('alice', $captured->getUserId());
		$this->assertSame('2026', $captured->getPeriodKey());
		$this->assertSame(25.5, $captured->getEffectiveEntitlementDays());
		$trace = $captured->getCalculationTrace();
		$this->assertSame(Constants::ENTITLEMENT_ALGORITHM_VERSION, $trace['algorithm_version']);
		$this->assertSame('2026-06-30', $trace['as_of_date']);
		$this->assertFalse($trace['inputs_redacted']);
		$this->assertSame('L0', $trace['matched_layer']);
		$this->assertSame('L0', $trace['winner']['layer']);
		$this->assertSame('layered', $trace['winner']['mode']);
		$this->assertSame(25.5, $trace['winner']['days']);
		$this->assertSame(9, $trace['winner']['rule_set_id']);
		$this->assertSame([['layer' => 'L0', 'matched' => true, 'mode' => 'layered', 'days' => 25.5]], $trace['layers_evaluated']);
	}

	public function testStoreKeepsEngineSuppliedEnvelopeUntouched(): void
	{
		$captured = null;
		[$svc] = $this->fixture(function (EntitlementComputationSnapshot $s) use (&$captured) {
			$captured = $s;
			return $s;
		});
		$engineTrace = [
			'algorithm_version' => Constants::ENTITLEMENT_ALGORITHM_VERSION,
			'as_of_date' => '2026-01-15',
			'inputs_redacted' => false,
			'matched_layer' => 'L2',
			'winner' => ['layer' => 'L2', 'mode' => 'layered', 'days' => 30.0, 'rule_set_id' => 4],
			'layers_evaluated' => [['layer' => 'L2', 'matched' => true]],
		];
		$svc->store('bob', 2026, new \DateTime('2026-01-15'), 30.0, 'layered', 4, $engineTrace, 'engine', 'fp-1');
		$this->assertEquals($engineTrace, $captured->getCalculationTrace());
		$this->assertSame('fp-1', $captured->getPolicyFingerprint());
	}

	public function testStoreLegacySourceDefaultsMatchedLayer(): void
	{
		$captured = null;
		[$svc] = $this->fixture(function (EntitlementComputationSnapshot $s) use (&$captured) {
			$captured = $s;
			return $s;
		});
		$svc->store('bob', 2025, new \DateTime('2025-12-31'), 20.0, 'legacy', null, [], 'job');
		$trace = $captured->getCalculationTrace();
		$this->assertSame('legacy', $trace['matched_layer']);
		$this->assertSame('legacy', $trace['winner']['mode']);
	}

	public function testStoreAcquiresExclusiveLockAndReleasesOnException(): void
	{
		$mapper = $this->createMock(EntitlementComputationSnapshotMapper::class);
		$mapper->method('upsertSnapshot')->willThrowException(new \RuntimeException('db gone'));
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');
		$svc = new EntitlementSnapshotService($mapper, $locking);
		$this->expectException(\RuntimeException::class);
		$svc->store('alice', 2026, new \DateTime('2026-01-01'), 10.0, 'x', null, [], 't');
	}
}
