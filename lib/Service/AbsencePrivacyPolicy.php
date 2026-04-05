<?php

declare(strict_types=1);

/**
 * Central rules for absence visibility (team views, calendar sync, iCal mail).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCP\IL10N;

final class AbsencePrivacyPolicy
{
	/**
	 * Types that must not appear with exact labels in team-wide or shareable contexts
	 * (health data / special-category data under GDPR).
	 *
	 * Keep in sync with AbsenceIcalMailService (no iCal email for these types).
	 */
	public const SENSITIVE_TYPES_NO_ICAL = [
		Absence::TYPE_SICK_LEAVE,
	];

	/**
	 * Summary for managers/team calendar APIs: no free-text reason or approver comments;
	 * opaque type label for sensitive absence kinds.
	 *
	 * @return array<string, mixed>
	 */
	public static function summaryForTeamViewer(Absence $absence): array
	{
		$s = $absence->getSummary();
		unset($s['reason'], $s['approverComment']);

		if (in_array($absence->getType(), self::SENSITIVE_TYPES_NO_ICAL, true)) {
			$s['type'] = 'absence';
		}

		return $s;
	}

	/**
	 * VEVENT SUMMARY for Nextcloud Calendar: single generic title for every absence type.
	 * Never reveals sick leave, vacation, or other categories — safe when the calendar is shared.
	 * Free-text reasons must never be added to ICS (handled in the sync service).
	 */
	public static function ncCalendarEventSummary(IL10N $l10n): string
	{
		return $l10n->t('Absence') . ' (' . $l10n->t('ArbeitszeitCheck') . ')';
	}
}
