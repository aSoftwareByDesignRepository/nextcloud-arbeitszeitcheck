<?php

declare(strict_types=1);

/**
 * Localized labels for compliance violation types and severities.
 *
 * Single source of truth — the compliance dashboard, the standalone
 * violations page and the filter selects all render through here so the
 * wording can never drift between surfaces. The client-side l10n map in
 * compliance-violations.js mirrors these strings for AJAX-rendered rows.
 *
 * @copyright Copyright (c) 2026, Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCP\IL10N;

final class ViolationLabels {
	/**
	 * Human-readable label for a violation type key. Unknown keys fall back
	 * to the raw key (same contract the old inline maps used) so an
	 * unexpected DB value never renders blank.
	 */
	public static function type(IL10N $l, string $typeKey): string {
		return match ($typeKey) {
			ComplianceViolation::TYPE_MISSING_BREAK => $l->t('Missing break'),
			ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS => $l->t('Excessive working hours'),
			ComplianceViolation::TYPE_INSUFFICIENT_REST_PERIOD => $l->t('Insufficient rest period'),
			ComplianceViolation::TYPE_DAILY_HOURS_LIMIT_EXCEEDED => $l->t('Daily hours limit exceeded'),
			ComplianceViolation::TYPE_WEEKLY_HOURS_LIMIT_EXCEEDED => $l->t('Weekly hours limit exceeded'),
			ComplianceViolation::TYPE_WEEKLY_ABSOLUTE_HOURS_EXCEEDED => $l->t('Absolute weekly hours maximum exceeded'),
			ComplianceViolation::TYPE_NIGHT_WORK => $l->t('Night work'),
			ComplianceViolation::TYPE_SUNDAY_WORK => $l->t('Sunday work'),
			ComplianceViolation::TYPE_HOLIDAY_WORK => $l->t('Holiday work'),
			ComplianceViolation::TYPE_MISSING_PRESENCE => $l->t('Required presence not covered'),
			default => $typeKey,
		};
	}

	/**
	 * Human-readable label for a severity key.
	 */
	public static function severity(IL10N $l, string $severityKey): string {
		return match ($severityKey) {
			ComplianceViolation::SEVERITY_ERROR => $l->t('High'),
			ComplianceViolation::SEVERITY_WARNING => $l->t('Medium'),
			ComplianceViolation::SEVERITY_INFO => $l->t('Low'),
			default => $severityKey,
		};
	}

	/**
	 * Badge CSS variant for a severity key (see css/common/badges.css).
	 */
	public static function severityBadge(string $severityKey): string {
		return match ($severityKey) {
			ComplianceViolation::SEVERITY_ERROR => 'error',
			ComplianceViolation::SEVERITY_WARNING => 'warning',
			default => 'primary',
		};
	}

	/**
	 * Ordered type-key → label map for <select> options.
	 *
	 * @return array<string, string>
	 */
	public static function typeMap(IL10N $l): array {
		$map = [];
		foreach (ComplianceViolation::TYPES as $typeKey) {
			$map[$typeKey] = self::type($l, $typeKey);
		}
		return $map;
	}
}
