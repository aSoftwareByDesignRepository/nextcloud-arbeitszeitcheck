<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

interface TimeAccountContribution
{
	public function sourceType(): string;
	public function sourceId(): string;
	public function sourceRevision(): int;
	public function userId(): string;
	public function localDate(): string;
	public function actualMinutes(): int;
	public function creditMinutes(): int;
	public function complianceMinutes(): int;
	public function snapshotHash(): string;
}
