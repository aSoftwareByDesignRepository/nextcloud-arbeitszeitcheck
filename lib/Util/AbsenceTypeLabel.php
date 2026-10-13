<?php

declare(strict_types=1);

/**
 * Localized absence type labels and working-day formatting for notifications and mail.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Util;

use OCP\IL10N;

final class AbsenceTypeLabel
{
	/**
	 * Human-readable label for an absence type code (same strings as absences UI).
	 * Delegates to the central catalog so every surface stays in sync.
	 */
	public static function get(IL10N $l, string $type): string
	{
		return \OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog::label($l, $type);
	}

	/**
	 * Format working days for user-facing text (supports half-days, e.g. 0.5).
	 */
	public static function formatWorkingDays(IL10N $l, float $days): string
	{
		if ($days <= 0) {
			return $l->t('no working days');
		}

		$rounded = round($days, 2);
		if (abs($rounded - round($rounded)) < 0.001) {
			$count = (int)round($rounded);
			return $l->n('%n working day', '%n working days', $count);
		}

		return $l->t('%s working days', [number_format($rounded, 1, '.', '')]);
	}
}
