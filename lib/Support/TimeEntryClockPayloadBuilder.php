<?php

declare(strict_types=1);

/**
 * Build time-entry correction payloads from date + HH:mm clock fields (shared by
 * employee correction requests and manager direct corrections).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class TimeEntryClockPayloadBuilder
{
	private const PLAIN_TIME_PATTERN = '/^([01]?\d|2[0-3]):([0-5]\d)$/';

	/**
	 * @param array<string, mixed> $params Request body (date, startTime, endTime, breaks, …)
	 * @param int|null $minBreakMinutes Profile floor (AT 10 / DE·CH 15). null = historic 15.
	 * @return array<string, mixed>|null Proposal fragment with ISO instants, or null when clock fields absent
	 */
	public static function buildFromParams(array $params, ?int $minBreakMinutes = null): ?array
	{
		$dateParam = $params['date'] ?? null;
		$startTime = $params['startTime'] ?? null;
		$endTime = $params['endTime'] ?? null;
		if (!is_string($dateParam) || !is_string($startTime) || !is_string($endTime)) {
			return null;
		}
		$startTime = trim($startTime);
		$endTime = trim($endTime);
		if (!self::isPlainTime($startTime) || !self::isPlainTime($endTime)) {
			return null;
		}

		$baseDate = self::parseDate($dateParam);
		if ($baseDate === null) {
			return null;
		}

		$startDateTime = clone $baseDate;
		[$sh, $sm] = explode(':', $startTime, 2);
		$startDateTime->setTime((int)$sh, (int)$sm, 0);

		$endDateTime = clone $baseDate;
		[$eh, $em] = explode(':', $endTime, 2);
		$endDateTime->setTime((int)$eh, (int)$em, 0);
		if ($endDateTime <= $startDateTime) {
			$endDateTime->modify('+1 day');
		}

		$result = [
			'startTime' => $startDateTime->format('c'),
			'endTime' => $endDateTime->format('c'),
		];

		$breaks = isset($params['breaks']) && is_array($params['breaks']) ? $params['breaks'] : null;
		if ($breaks !== null) {
			if ($breaks === []) {
				$result['breaks'] = [];
			} else {
				$validBreaks = self::normalizeBreaksOnDate($baseDate, $breaks, $minBreakMinutes);
				if ($validBreaks !== []) {
					$result['breaks'] = $validBreaks;
				}
			}
		}

		return $result;
	}

	/**
	 * Merge clock-based fields into an existing proposal when present; otherwise keep ISO fields.
	 *
	 * @param array<string, mixed> $params
	 * @param array<string, mixed> $proposal
	 * @param int|null $minBreakMinutes
	 * @return array<string, mixed>
	 */
	public static function mergeIntoProposal(array $params, array $proposal, ?int $minBreakMinutes = null): array
	{
		$clock = self::buildFromParams($params, $minBreakMinutes);
		if ($clock !== null) {
			return array_merge($proposal, $clock);
		}

		if (isset($params['startTime']) && is_string($params['startTime']) && !self::isPlainTime(trim($params['startTime']))) {
			$proposal['startTime'] = $params['startTime'];
		}
		if (isset($params['endTime']) && is_string($params['endTime']) && !self::isPlainTime(trim($params['endTime']))) {
			$proposal['endTime'] = $params['endTime'];
		}
		if (isset($params['breaks']) && is_array($params['breaks'])) {
			$proposal['breaks'] = $params['breaks'];
		}

		return $proposal;
	}

	private static function isPlainTime(string $value): bool
	{
		return (bool)preg_match(self::PLAIN_TIME_PATTERN, $value);
	}

	private static function parseDate(string $dateString): ?\DateTime
	{
		$dateString = trim($dateString);
		if ($dateString === '') {
			return null;
		}

		if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $dateString, $matches)) {
			$day = (int)$matches[1];
			$month = (int)$matches[2];
			$year = (int)$matches[3];
			if (!checkdate($month, $day, $year)) {
				return null;
			}
			$parsed = \DateTime::createFromFormat('!d.m.Y', $dateString);
			return $parsed === false ? null : $parsed;
		}

		return StrictYmdDates::parseRequired($dateString);
	}

	/**
	 * @param list<array<string, mixed>> $breaks
	 * @return list<array{start: string, end: string}>
	 */
	private static function normalizeBreaksOnDate(\DateTime $baseDate, array $breaks, ?int $minBreakMinutes = null): array
	{
		$minSeconds = BreakCountable::minSeconds($minBreakMinutes);
		$validBreaks = [];
		foreach ($breaks as $break) {
			if (!is_array($break)) {
				continue;
			}
			$startKey = isset($break['start']) ? 'start' : (isset($break['start_time']) ? 'start_time' : null);
			$endKey = isset($break['end']) ? 'end' : (isset($break['end_time']) ? 'end_time' : null);
			if ($startKey === null || $endKey === null) {
				continue;
			}
			$rawStart = trim((string)$break[$startKey]);
			$rawEnd = trim((string)$break[$endKey]);
			if ($rawStart === '' || $rawEnd === '' || !self::isPlainTime($rawStart) || !self::isPlainTime($rawEnd)) {
				continue;
			}
			$breakStart = clone $baseDate;
			[$bh, $bm] = explode(':', $rawStart, 2);
			$breakStart->setTime((int)$bh, (int)$bm, 0);
			$breakEnd = clone $baseDate;
			[$eh2, $em2] = explode(':', $rawEnd, 2);
			$breakEnd->setTime((int)$eh2, (int)$em2, 0);
			if ($breakEnd < $breakStart) {
				$breakEnd->modify('+1 day');
			}
			$durationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();
			if ($durationSeconds >= $minSeconds) {
				$validBreaks[] = [
					'start' => $breakStart->format('c'),
					'end' => $breakEnd->format('c'),
				];
			}
		}

		return $validBreaks;
	}
}
