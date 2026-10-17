<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

final readonly class ImmutableTimeAccountContribution implements TimeAccountContribution
{
	private const SOURCE_TYPES = ['time_entry', 'absence_credit', 'business_trip'];

	public function __construct(
		private string $sourceType,
		private string $sourceId,
		private int $sourceRevision,
		private string $userId,
		private string $localDate,
		private int $actualMinutes,
		private int $creditMinutes,
		private int $complianceMinutes,
		private string $snapshotHash,
	) {
		if (!in_array($sourceType, self::SOURCE_TYPES, true)
			|| trim($sourceId) === '' || $sourceRevision < 1 || trim($userId) === ''
			|| preg_match('/^\d{4}-\d{2}-\d{2}$/D', $localDate) !== 1
			|| $actualMinutes < 0 || $creditMinutes < 0 || $complianceMinutes < 0
			|| preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) !== 1) {
			throw new \InvalidArgumentException('Invalid time-account contribution.');
		}
		if ($sourceType !== 'time_entry' && $actualMinutes !== 0) {
			throw new \InvalidArgumentException('Only time entries may contribute actual minutes.');
		}
	}

	public function sourceType(): string { return $this->sourceType; }
	public function sourceId(): string { return $this->sourceId; }
	public function sourceRevision(): int { return $this->sourceRevision; }
	public function userId(): string { return $this->userId; }
	public function localDate(): string { return $this->localDate; }
	public function actualMinutes(): int { return $this->actualMinutes; }
	public function creditMinutes(): int { return $this->creditMinutes; }
	public function complianceMinutes(): int { return $this->complianceMinutes; }
	public function snapshotHash(): string { return $this->snapshotHash; }

	public function deduplicationKey(): string
	{
		return implode(':', [$this->sourceType, $this->sourceId, $this->sourceRevision, $this->localDate]);
	}
}
