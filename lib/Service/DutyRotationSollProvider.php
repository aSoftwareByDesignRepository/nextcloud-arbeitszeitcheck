<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Constants;
use OCP\IConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AZC consumer for DutyCheck effective target hours (G2).
 *
 * Resolves Soll via in-process facade only — NEVER SQL on dc_*.
 * On miss / exception → null/false so callers keep the legacy static model.
 *
 * AS-12 interim: month closure exists ({@see MonthClosureService::isMonthFinalized}).
 * Live facade is used only for open months. When a day/range falls in a finalized
 * (sealed) month, methods return null so overtime/vacation keep the sealed-path /
 * static model. A dedicated Soll-basis snapshot at seal time is not stored yet.
 */
class DutyRotationSollProvider
{
	/** @var object|null|false false = unresolved, null = unavailable */
	private object|null|false $facade = false;

	/**
	 * Lazy MonthClosure cache. Must NOT be resolved in the DI factory —
	 * MonthClosureService → ReportingService → OvertimeService → this class
	 * forms a cycle; eager query during construction stack-overflows PHP and
	 * 503s the entire Nextcloud request (sibling apps included).
	 */
	private ?MonthClosureService $monthClosureLazy = null;
	private bool $monthClosureResolveAttempted = false;

	public function __construct(
		private readonly IConfig $config,
		private readonly ContainerInterface $container,
		private readonly ?MonthClosureService $monthClosure = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/** G2 — org opt-in for rotation Soll (default off). */
	public function isEnabledForOrg(): bool
	{
		return $this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_DUTY_ROTATION_SOLL_ENABLED,
			Constants::CONFIG_DUTY_ROTATION_SOLL_DEFAULT,
		) === '1';
	}

	/**
	 * G2 + facade available + non-null week target for the ISO week of $date.
	 * Sealed months → false (AS-12 interim).
	 */
	public function isEffectiveForUser(string $userId, DateTimeInterface $date): bool
	{
		if (!$this->isEnabledForOrg()) {
			return false;
		}
		if ($this->isDayInSealedMonth($userId, $date)) {
			return false;
		}
		$week = $this->safeGetWeekTarget($userId, $date);
		return $week !== null;
	}

	/**
	 * Sum of day targets over the inclusive range (minutes→hours).
	 * Null on miss, exception, G2 off, or any sealed day in range (AS-12 interim).
	 */
	public function requiredHoursForDateRange(
		string $userId,
		DateTimeInterface $start,
		DateTimeInterface $end,
	): ?float {
		if (!$this->isEnabledForOrg()) {
			return null;
		}
		$startDay = $this->asDay($start);
		$endDay = $this->asDay($end);
		if ($endDay < $startDay) {
			$tmp = $startDay;
			$startDay = $endDay;
			$endDay = $tmp;
		}

		$cursor = DateTimeImmutable::createFromMutable($startDay);
		$endImmutable = DateTimeImmutable::createFromMutable($endDay);
		$totalMinutes = 0;
		$any = false;

		while ($cursor <= $endImmutable) {
			if ($this->isDayInSealedMonth($userId, $cursor)) {
				$this->logMiss('sealed_month', $userId);
				return null;
			}
			$day = $this->safeGetDayTarget($userId, $cursor);
			if ($day === null) {
				return null;
			}
			$totalMinutes += max(0, (int) ($day->netMinutes ?? 0));
			$any = true;
			$cursor = $cursor->modify('+1 day');
		}

		return $any ? round($totalMinutes / 60.0, 4) : null;
	}

	/**
	 * Per-day net hours for vacation debit. Null → legacy weekday schedule.
	 */
	public function dayNetHoursForUser(string $userId, DateTimeInterface $date): ?float
	{
		if (!$this->isEnabledForOrg()) {
			return null;
		}
		if ($this->isDayInSealedMonth($userId, $date)) {
			$this->logMiss('sealed_month', $userId);
			return null;
		}
		$day = $this->safeGetDayTarget($userId, $date);
		if ($day === null) {
			return null;
		}
		return round(max(0, (int) ($day->netMinutes ?? 0)) / 60.0, 4);
	}

	/**
	 * Basis string from week DTO: rotation_pattern|published_roster|open_roster.
	 * Null when not effective.
	 */
	public function getWeekTargetBasis(string $userId, DateTimeInterface $date): ?string
	{
		$week = $this->safeGetWeekTarget($userId, $date);
		if ($week === null) {
			return null;
		}
		$basis = (string) ($week->basis ?? '');
		return $basis !== '' ? $basis : null;
	}

	/** Planwoche label from week DTO; null when not effective. */
	public function getWeekTargetLabel(string $userId, DateTimeInterface $date): ?string
	{
		$week = $this->safeGetWeekTarget($userId, $date);
		if ($week === null) {
			return null;
		}
		$label = (string) ($week->weekLabel ?? '');
		return $label !== '' ? $label : null;
	}

	/**
	 * Prefer constructor injection (unit tests); otherwise resolve once from
	 * the container after the DI graph has finished building.
	 */
	private function resolveMonthClosure(): ?MonthClosureService
	{
		if ($this->monthClosure !== null) {
			return $this->monthClosure;
		}
		if ($this->monthClosureResolveAttempted) {
			return $this->monthClosureLazy;
		}
		$this->monthClosureResolveAttempted = true;
		try {
			$resolved = $this->container->get(MonthClosureService::class);
			$this->monthClosureLazy = $resolved instanceof MonthClosureService ? $resolved : null;
		} catch (Throwable) {
			$this->monthClosureLazy = null;
		}
		return $this->monthClosureLazy;
	}

	private function isDayInSealedMonth(string $userId, DateTimeInterface $date): bool
	{
		$closure = $this->resolveMonthClosure();
		if ($closure === null) {
			return false;
		}
		try {
			$year = (int) $date->format('Y');
			$month = (int) $date->format('n');
			return $closure->isMonthFinalized($userId, $year, $month);
		} catch (Throwable) {
			return false;
		}
	}

	private function safeGetWeekTarget(string $userId, DateTimeInterface $date): ?object
	{
		$facade = $this->resolveFacade();
		if ($facade === null) {
			return null;
		}
		try {
			if (!$this->facadeRotationEnabled($facade, $userId)) {
				return null;
			}
			$monday = $this->isoMonday($date);
			/** @var callable $fn */
			$fn = [$facade, 'getWeekTarget'];
			$result = $fn($userId, $monday);
			return is_object($result) ? $result : null;
		} catch (Throwable $e) {
			$this->logMiss('exception', $userId, $e);
			return null;
		}
	}

	private function safeGetDayTarget(string $userId, DateTimeInterface $date): ?object
	{
		$facade = $this->resolveFacade();
		if ($facade === null) {
			return null;
		}
		try {
			if (!$this->facadeRotationEnabled($facade, $userId)) {
				return null;
			}
			$day = $this->asDay($date);
			$immutable = DateTimeImmutable::createFromMutable($day);
			/** @var callable $fn */
			$fn = [$facade, 'getDayTarget'];
			$result = $fn($userId, $immutable);
			return is_object($result) ? $result : null;
		} catch (Throwable $e) {
			$this->logMiss('exception', $userId, $e);
			return null;
		}
	}

	private function facadeRotationEnabled(object $facade, string $userId): bool
	{
		try {
			if (method_exists($facade, 'isSollFromDutyEnabledForUser')) {
				return (bool) $facade->isSollFromDutyEnabledForUser($userId);
			}
			if (!method_exists($facade, 'isRotationEnabledForOrg')) {
				return true;
			}
			return (bool) $facade->isRotationEnabledForOrg($userId);
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * Resolve DutyCheck facade without a hard composer dependency.
	 * Cross-app: probe AZC container, then DutyCheck app container, then server.
	 * Never SQL on dc_* (COMPOSABILITY / Argus).
	 */
	private function resolveFacade(): ?object
	{
		if ($this->facade !== false) {
			return $this->facade;
		}

		$ids = [
			'dutycheck.effective_target_hours_facade',
			'OCA\\DutyCheck\\Service\\Contract\\EffectiveTargetHoursFacade',
			'OCA\\DutyCheck\\Service\\EffectiveTargetHoursFacadeService',
		];

		/** @var list<ContainerInterface> $containers */
		$containers = [$this->container];
		try {
			if (class_exists('OCA\\DutyCheck\\AppInfo\\Application')) {
				/** @var object $dutyApp */
				$dutyApp = \OCP\Server::get('OCA\\DutyCheck\\AppInfo\\Application');
				if (method_exists($dutyApp, 'getContainer')) {
					$containers[] = $dutyApp->getContainer();
				}
			}
		} catch (Throwable) {
			// DutyCheck not installed / not booted — fall through.
		}
		try {
			$containers[] = \OCP\Server::get(ContainerInterface::class);
		} catch (Throwable) {
			// ignore
		}

		foreach ($containers as $c) {
			foreach ($ids as $id) {
				try {
					$resolved = $c->get($id);
					if (is_object($resolved)) {
						$this->facade = $resolved;
						return $this->facade;
					}
				} catch (Throwable) {
					// try next
				}
			}
		}

		$this->facade = null;
		$this->logMiss('facade_unavailable', '');
		return null;
	}

	private function isoMonday(DateTimeInterface $date): DateTimeImmutable
	{
		$d = $date instanceof DateTimeImmutable
			? $date->setTime(0, 0, 0)
			: DateTimeImmutable::createFromMutable((clone \DateTime::createFromInterface($date))->setTime(0, 0, 0));
		$dow = (int) $d->format('N');
		if ($dow !== 1) {
			$d = $d->modify('-' . ($dow - 1) . ' days');
		}
		return $d;
	}

	private function asDay(DateTimeInterface $d): \DateTime
	{
		$dt = $d instanceof \DateTime ? clone $d : new \DateTime($d->format('Y-m-d'));
		$dt->setTime(0, 0, 0);
		return $dt;
	}

	private function logMiss(string $reason, string $userId, ?Throwable $e = null): void
	{
		if ($this->logger === null) {
			return;
		}
		$ctx = [
			'app' => Application::APP_ID,
			'reason' => $reason,
			'userHash' => $userId !== '' ? hash('sha256', $userId) : null,
		];
		if ($e !== null) {
			$ctx['exception'] = $e;
		}
		$this->logger->info('azc.soll.facade_miss', $ctx);
	}
}
