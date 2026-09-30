<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026, Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Single source of truth for date/time and timezone handling in ArbeitszeitCheck.
 *
 * ----------------------------------------------------------------------------
 * The two timezones we care about
 * ----------------------------------------------------------------------------
 *
 *  - **Storage TZ** ({@see storageTimeZone()}) — the organisation / payroll
 *    clock configured via the app setting {@see Constants::CONFIG_APP_TIMEZONE}
 *    (default `Europe/Berlin`). All naive `DATETIME` / `TIMESTAMP` columns in
 *    the `at_*` tables hold the civil `Y-m-d H:i:s` digits of an instant in
 *    this zone. Calendar-day legal boundaries (ArbZG §3, daily/weekly limits,
 *    rest periods, exports) are evaluated in this zone.
 *
 *  - **User display TZ** ({@see userDisplayTimeZone()}) — the timezone the
 *    currently logged-in user has configured in their Nextcloud personal
 *    settings ({@see IDateTimeZone}). Used **only** for rendering clock times
 *    in the UI. Never used for business rules or persistence.
 *
 * ----------------------------------------------------------------------------
 * Invariants enforced by this service
 * ----------------------------------------------------------------------------
 *
 *  1. **Writes**: every "now" persisted to a naive SQL column goes through
 *     {@see nowInStorage()} (or {@see formatForNaiveSql()} for arbitrary
 *     instants). This guarantees the persisted digits actually match the
 *     declared {@see Constants::CONFIG_APP_TIMEZONE}, regardless of PHP's
 *     `date.timezone` setting or the host's TZ env var (often `UTC` in
 *     containers).
 *  2. **Reads**: after Nextcloud's `Entity` hydration parses the naive string
 *     in PHP's default zone, callers re-bind the wall digits to storage TZ
 *     via {@see hydrateNaive()}. Mappers do this transparently.
 *  3. **API**: every datetime crossing the JSON boundary is serialised as
 *     ISO-8601 with explicit offset ({@see toIso()}). Clients receive
 *     unambiguous instants.
 *  4. **Day windows**: every "what falls into this calendar day" question is
 *     answered via {@see todayWindowInStorage()} /
 *     {@see dayWindowInStorage()} so a session that crosses midnight in the
 *     storage TZ is counted into the correct calendar day.
 *  5. **Display**: every wall-clock string shown to the user is rendered in
 *     {@see userDisplayTimeZone()}.
 *
 * Violating any of those rules is the classic source of the "I clocked in
 * and the timer shows +02:00:00 already" bug.
 */
final class TimeZoneService
{
	/** Default fallback when {@see Constants::CONFIG_APP_TIMEZONE} is invalid or missing. */
	public const DEFAULT_STORAGE_TZ = 'Europe/Berlin';

	private IConfig $config;
	private IDateTimeZone $dateTimeZone;
	private IUserSession $userSession;
	private LoggerInterface $logger;

	public function __construct(
		IConfig $config,
		IDateTimeZone $dateTimeZone,
		IUserSession $userSession,
		LoggerInterface $logger
	) {
		$this->config = $config;
		$this->dateTimeZone = $dateTimeZone;
		$this->userSession = $userSession;
		$this->logger = $logger;
	}

	// ------------------------------------------------------------------ //
	// 1. Timezone resolution                                              //
	// ------------------------------------------------------------------ //

	/**
	 * The configured organisation / storage timezone.
	 *
	 * Falls back to {@see DEFAULT_STORAGE_TZ} on any failure (invalid IANA
	 * name, missing extension) so business logic never crashes because of a
	 * misconfigured admin setting; the fallback is logged once per request.
	 */
	public function storageTimeZone(): \DateTimeZone
	{
		$name = (string)$this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_APP_TIMEZONE, self::DEFAULT_STORAGE_TZ);
		try {
			return new \DateTimeZone($name);
		} catch (\Throwable $e) {
			$this->logger->warning('Invalid app_timezone in config, falling back to ' . self::DEFAULT_STORAGE_TZ . ': ' . $name, [
				'exception' => $e,
			]);
			return new \DateTimeZone(self::DEFAULT_STORAGE_TZ);
		}
	}

	/**
	 * The name of the configured storage timezone.
	 */
	public function storageTimeZoneName(): string
	{
		return $this->storageTimeZone()->getName();
	}

	/**
	 * The timezone in which times should be rendered for the given user.
	 *
	 * Defaults to the **session** user's {@see IDateTimeZone}, which already
	 * honours their personal Nextcloud setting and falls back to the server
	 * timezone if none is configured. If no user is logged in (e.g. CLI jobs),
	 * the storage timezone is returned so background jobs default to the
	 * same clock the audit log uses.
	 *
	 * @param string|null $userId Ignored when equal to the session user; kept
	 *                            for future per-user resolution.
	 */
	public function userDisplayTimeZone(?string $userId = null): \DateTimeZone
	{
		$sessionUser = $this->userSession->getUser();
		if ($sessionUser === null) {
			return $this->storageTimeZone();
		}
		// IDateTimeZone is bound to the session user; we accept $userId only
		// to keep the interface friendly for future per-user lookups.
		try {
			return $this->dateTimeZone->getTimeZone();
		} catch (\Throwable $e) {
			$this->logger->debug('IDateTimeZone resolution failed; falling back to storage TZ', ['exception' => $e]);
			return $this->storageTimeZone();
		}
	}

	// ------------------------------------------------------------------ //
	// 2. "Now"                                                            //
	// ------------------------------------------------------------------ //

	/**
	 * "Now" as a mutable {@see \DateTime} bound to {@see storageTimeZone()}.
	 *
	 * Use this for **every** write to a naive SQL column that should hold the
	 * storage-TZ wall clock (`start_time`, `end_time`, `break_start_time`,
	 * `break_end_time`, `created_at`, `updated_at`, `approved_at`, audit
	 * `created_at`, …).
	 */
	public function nowInStorage(): \DateTime
	{
		return new \DateTime('now', $this->storageTimeZone());
	}

	/**
	 * "Now" as an immutable instant in storage TZ.
	 */
	public function nowImmutableInStorage(): \DateTimeImmutable
	{
		return new \DateTimeImmutable('now', $this->storageTimeZone());
	}

	/**
	 * Current UTC epoch seconds.
	 */
	public function currentInstant(): int
	{
		return $this->nowImmutableInStorage()->getTimestamp();
	}

	/**
	 * "Now" as an ISO-8601 string with offset (for API responses).
	 *
	 * Clients should treat the value as an absolute instant.
	 */
	public function nowAsIso(): string
	{
		return $this->toIso($this->nowImmutableInStorage());
	}

	// ------------------------------------------------------------------ //
	// 3. SQL naive datetime helpers                                       //
	// ------------------------------------------------------------------ //

	/**
	 * Re-bind a {@see \DateTime} loaded from a naive SQL datetime to storage TZ.
	 *
	 * Nextcloud's {@see \OCP\AppFramework\Db\Entity} hydrates naive `DATETIME`
	 * strings with PHP's default zone (often `UTC` in Docker), which silently
	 * shifts the encoded instant by the host vs. storage TZ offset.
	 *
	 * This method reads the wall-clock digits from $loaded and re-creates the
	 * value as a {@see \DateTime} in storage TZ so the resulting absolute
	 * instant matches the digits that were actually written.
	 *
	 * Returns the input unchanged when the wall clock cannot be parsed (which
	 * cannot happen for digits produced by {@see formatForNaiveSql()}) so this
	 * never throws on a read path.
	 */
	public function hydrateNaive(\DateTime $loaded): \DateTime
	{
		return AppLocalNaiveDateTimeNormalizer::interpretSqlNaiveAsAppTimezone($loaded, $this->storageTimeZone());
	}

	/**
	 * Re-bind a {@see \DateTime} or {@see \DateTimeImmutable} loaded from a
	 * naive SQL datetime to storage TZ. Returns the same type as the input
	 * (mutable in, mutable out; immutable in, immutable out).
	 */
	public function hydrateNaiveAny(\DateTimeInterface $loaded): \DateTimeInterface
	{
		$wall = $loaded->format('Y-m-d H:i:s');
		$tz = $this->storageTimeZone();
		if ($loaded instanceof \DateTimeImmutable) {
			$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wall, $tz);
		} else {
			$parsed = \DateTime::createFromFormat('!Y-m-d H:i:s', $wall, $tz);
		}
		return $parsed !== false ? $parsed : $loaded;
	}

	/**
	 * Format an instant for use as a bound parameter on a naive SQL column.
	 *
	 * The output is always the storage-TZ wall clock, regardless of the
	 * input's own timezone. This is the only correct way to build a range
	 * query (`start_time >= :start`) that intersects naive rows.
	 */
	public function formatForNaiveSql(\DateTimeInterface $dt): string
	{
		// Epoch re-anchor is correct for every implementor (mutable and
		// immutable alike) — the wall clock is expressed in storage TZ.
		return (new \DateTimeImmutable('@' . $dt->getTimestamp()))
			->setTimezone($this->storageTimeZone())
			->format('Y-m-d H:i:s');
	}

	// ------------------------------------------------------------------ //
	// 4. Client input parsing                                             //
	// ------------------------------------------------------------------ //

	/**
	 * Parse a date/time string received from a client (HTML form, JSON body).
	 *
	 * Rules:
	 *   - If the string contains an explicit offset / zone (`+02:00`, `Z`,
	 *     `Europe/Berlin`, etc.), it is honoured.
	 *   - Otherwise the value is treated as a wall clock in the storage TZ —
	 *     **not** PHP's default zone, **not** UTC — matching the contract for
	 *     naive SQL columns and the rest of the app.
	 *
	 * @throws \InvalidArgumentException when the value cannot be parsed.
	 */
	public function parseClientDateTime(string $value): \DateTime
	{
		return AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($value, $this->storageTimeZone());
	}

	/**
	 * Parse a strict `Y-m-d` calendar date. Calendar dates are TZ-agnostic;
	 * the returned {@see \DateTimeImmutable} is anchored at midnight in
	 * storage TZ so further calculations stay consistent with day windows.
	 *
	 * @throws \InvalidArgumentException when the value is not a valid `Y-m-d`.
	 */
	public function parseStrictDate(string $value): \DateTimeImmutable
	{
		$value = trim($value);
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->storageTimeZone());
		if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
			throw new \InvalidArgumentException('Invalid date (expected Y-m-d): ' . $value);
		}
		return $parsed;
	}

	/**
	 * Parse an ISO-8601 string (with or without offset) into a mutable
	 * {@see \DateTime}, preserving the encoded instant. The returned
	 * DateTime is **converted to storage TZ** so downstream `format('Y-m-d
	 * H:i:s')` writes the correct naive digits.
	 *
	 * @throws \InvalidArgumentException when the value cannot be parsed.
	 */
	public function fromIso(string $value): \DateTime
	{
		$value = trim($value);
		if ($value === '') {
			throw new \InvalidArgumentException('Empty ISO datetime');
		}
		try {
			$dt = new \DateTime($value);
		} catch (\Throwable $e) {
			throw new \InvalidArgumentException('Invalid ISO datetime: ' . $value, 0, $e);
		}
		$dt->setTimezone($this->storageTimeZone());
		return $dt;
	}

	// ------------------------------------------------------------------ //
	// 5. Day / month windows                                              //
	// ------------------------------------------------------------------ //

	/**
	 * Inclusive start and exclusive end of "today" in storage TZ.
	 *
	 * @return array{0:\DateTime,1:\DateTime} `[startOfDay, startOfNextDay)`,
	 *         both as mutable {@see \DateTime} in storage TZ. Suitable for
	 *         direct use as bound parameters via {@see formatForNaiveSql()}.
	 */
	public function todayWindowInStorage(): array
	{
		return $this->dayWindowInStorage($this->nowImmutableInStorage());
	}

	/**
	 * Inclusive start and exclusive end of the calendar day containing
	 * $reference, in storage TZ.
	 *
	 * @return array{0:\DateTime,1:\DateTime}
	 */
	public function dayWindowInStorage(\DateTimeInterface $reference): array
	{
		$tz = $this->storageTimeZone();
		// Normalise to the storage TZ for the wall-clock day extraction.
		if ($reference instanceof \DateTimeImmutable) {
			$normalised = $reference->setTimezone($tz);
		} elseif ($reference instanceof \DateTime) {
			$normalised = clone $reference;
			$normalised->setTimezone($tz);
		} else {
			$normalised = (new \DateTimeImmutable('@' . $reference->getTimestamp()))->setTimezone($tz);
		}
		$startWall = $normalised->format('Y-m-d') . ' 00:00:00';
		$start = \DateTime::createFromFormat('!Y-m-d H:i:s', $startWall, $tz);
		if ($start === false) {
			$start = new \DateTime($normalised->format('Y-m-d') . ' 00:00:00', $tz);
		}
		$end = (clone $start)->modify('+1 day');
		return [$start, $end];
	}

	/**
	 * Inclusive start and exclusive end of the given calendar month, in
	 * storage TZ.
	 *
	 * @param int $year  4-digit year, e.g. 2026.
	 * @param int $month 1-based month (1-12).
	 * @return array{0:\DateTime,1:\DateTime}
	 */
	public function monthWindowInStorage(int $year, int $month): array
	{
		if ($month < 1 || $month > 12) {
			throw new \InvalidArgumentException('Invalid month: ' . $month);
		}
		$tz = $this->storageTimeZone();
		$start = new \DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
		$end = (clone $start)->modify('first day of next month')->setTime(0, 0, 0);
		return [$start, $end];
	}

	/**
	 * `Y-m-d` calendar day in storage TZ of the given instant.
	 *
	 * Use this — never `format('Y-m-d')` on the raw value — to determine
	 * which calendar day a stored or hydrated time entry belongs to.
	 */
	public function dayKeyInStorage(\DateTimeInterface $instant): string
	{
		return (new \DateTimeImmutable('@' . $instant->getTimestamp()))
			->setTimezone($this->storageTimeZone())
			->format('Y-m-d');
	}

	// ------------------------------------------------------------------ //
	// 6. API / display formatting                                         //
	// ------------------------------------------------------------------ //

	/**
	 * Format any instant as ISO-8601 with explicit offset, suitable for JSON
	 * APIs. The output preserves the absolute instant: clients see the wall
	 * clock and the offset and can render in their local TZ as they wish.
	 */
	public function toIso(\DateTimeInterface $dt): string
	{
		return $dt->format(\DateTimeInterface::ATOM);
	}

	/**
	 * Convert an instant to the user's display TZ, returning a mutable copy.
	 *
	 * Use right before rendering clock times in PHP templates. Never pass
	 * the result back into storage code.
	 */
	public function toUserDisplay(\DateTimeInterface $dt, ?string $userId = null): \DateTime
	{
		$tz = $this->userDisplayTimeZone($userId);
		if ($dt instanceof \DateTime) {
			$clone = clone $dt;
		} else {
			// DateTimeImmutable — re-materialise as DateTime so templates can
			// mutate without surprising aliasing semantics.
			$clone = new \DateTime('@' . $dt->getTimestamp());
		}
		$clone->setTimezone($tz);
		return $clone;
	}

	/**
	 * Render an instant in the user's display TZ using a PHP `format()` mask.
	 *
	 * @param string $format  Any {@see \DateTimeInterface::format} mask. The
	 *                        default `d.m.Y H:i` matches the European
	 *                        convention used throughout the app.
	 * @param string|null $userId Reserved for future per-user resolution.
	 */
	public function formatForDisplay(\DateTimeInterface $dt, string $format = 'd.m.Y H:i', ?string $userId = null): string
	{
		return $this->toUserDisplay($dt, $userId)->format($format);
	}
}
