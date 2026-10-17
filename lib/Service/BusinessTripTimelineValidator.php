<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTripSegment;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;

/**
 * Segment facts validation (spec §9.5 step 2): no overlap inside the same
 * facts class, segments must stay inside the trip window, ends after starts.
 * Gaps are legal — employees are not forced to account for every minute;
 * unclassified gaps surface via `unclassifiedMinutes`, never silently zeroed.
 */
final class BusinessTripTimelineValidator
{
	/**
	 * @param list<array{startsAtUtc:\DateTimeInterface,endsAtUtc:\DateTimeInterface,segmentType:string,transportMode:?string}> $segments
	 * @throws BusinessTripException TRIP_VALIDATION_ERROR / TRIP_SEGMENT_OVERLAP
	 */
	public function validate(array $segments, \DateTimeInterface $tripStart, \DateTimeInterface $tripEnd): void
	{
		$sorted = $segments;
		usort($sorted, static fn (array $a, array $b): int => $a['startsAtUtc'] <=> $b['startsAtUtc']);

		foreach ($sorted as $i => $seg) {
			if (!in_array($seg['segmentType'], BusinessTripSegment::TYPES, true)) {
				throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'Unknown segment type.', 400, ['segments' => 'invalid_type']);
			}
			if ($seg['transportMode'] !== null && !in_array($seg['transportMode'], BusinessTripSegment::MODES, true)) {
				throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'Unknown transport mode.', 400, ['segments' => 'invalid_mode']);
			}
			if ($seg['endsAtUtc'] <= $seg['startsAtUtc']) {
				throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'A segment must end after it starts.', 400, ['segments' => 'inverted']);
			}
			if ($seg['startsAtUtc'] < $tripStart || $seg['endsAtUtc'] > $tripEnd) {
				throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'A segment lies outside the trip window.', 400, ['segments' => 'outside_trip']);
			}
			if ($i > 0) {
				$prev = $sorted[$i - 1];
				// Overlap between any two fact segments is forbidden — every
				// real minute may enter the calculation at most once.
				if ($seg['startsAtUtc'] < $prev['endsAtUtc']) {
					throw new BusinessTripException('TRIP_SEGMENT_OVERLAP', 'Segments must not overlap.', 400, ['segments' => 'overlap']);
				}
			}
		}
	}
}
