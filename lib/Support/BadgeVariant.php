<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\TimeEntry;

/**
 * Maps domain status values to unified badge CSS variants (badge--*, azc-badge--*).
 *
 * Variants pair with css/common/badges.css token setters for WCAG 2.1 AA contrast.
 */
final class BadgeVariant
{
	public const SUCCESS = 'success';
	public const WARNING = 'warning';
	public const DANGER = 'danger';
	public const ERROR = 'error';
	public const PRIMARY = 'primary';
	public const INFO = 'info';
	public const NEUTRAL = 'neutral';
	public const SECONDARY = 'secondary';
	public const PAST_RECORD = 'past-record';

	public static function forTimeEntryStatus(string $status): string
	{
		return match ($status) {
			TimeEntry::STATUS_COMPLETED => self::SUCCESS,
			TimeEntry::STATUS_ACTIVE => self::PRIMARY,
			TimeEntry::STATUS_BREAK,
			TimeEntry::STATUS_PAUSED,
			TimeEntry::STATUS_PENDING_APPROVAL => self::WARNING,
			TimeEntry::STATUS_REJECTED => self::ERROR,
			default => self::SECONDARY,
		};
	}

	/**
	 * GH #43 — list badge tone for a work-location mark.
	 * home/external get a real badge; office/unknown fall back to secondary
	 * (callers usually render a muted label instead).
	 */
	public static function forWorkLocation(?string $location): string
	{
		return match ($location) {
			'home' => self::INFO,
			'external' => self::NEUTRAL,
			default => self::SECONDARY,
		};
	}

	public static function forAbsenceStatus(string $status): string
	{
		return match ($status) {
			Absence::STATUS_APPROVED => self::SUCCESS,
			Absence::STATUS_PENDING,
			Absence::STATUS_SUBSTITUTE_PENDING => self::WARNING,
			Absence::STATUS_REJECTED,
			Absence::STATUS_SUBSTITUTE_DECLINED => self::ERROR,
			Absence::STATUS_CANCELLED => self::SECONDARY,
			default => self::SECONDARY,
		};
	}

	public static function forClockStatus(string $status): string
	{
		return match ($status) {
			'active' => self::SUCCESS,
			'break' => self::WARNING,
			'clocked_out' => self::SECONDARY,
			default => self::SECONDARY,
		};
	}

	public static function forComplianceSeverity(string $severity): string
	{
		return match ($severity) {
			'error' => self::ERROR,
			'warning' => self::WARNING,
			default => self::PRIMARY,
		};
	}

	public static function forTariffRuleSetStatus(string $status): string
	{
		return match ($status) {
			'active' => self::SUCCESS,
			'draft' => self::WARNING,
			'retired' => self::SECONDARY,
			default => self::SECONDARY,
		};
	}

	public static function forOvertimePayoutStatus(string $status): string
	{
		return match ($status) {
			'paid' => self::SUCCESS,
			'pending' => self::WARNING,
			default => self::SECONDARY,
		};
	}

	public static function forMonthClosureStatus(string $status): string
	{
		return match ($status) {
			'finalized' => self::SUCCESS,
			'open' => self::WARNING,
			default => self::NEUTRAL,
		};
	}

	public static function forBooleanEnabled(bool $enabled): string
	{
		return $enabled ? self::SUCCESS : self::ERROR;
	}
}
