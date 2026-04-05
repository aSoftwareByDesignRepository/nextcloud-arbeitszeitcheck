<?php

declare(strict_types=1);

/**
 * Writes approved absences as all-day events into a dedicated CalDAV calendar ({@see AppConstants::CALENDAR_URI_ABSENCES}).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants as AppConstants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceCalendar;
use OCA\ArbeitszeitCheck\Db\AbsenceCalendarMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\Constants;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;

class AbsenceCalendarSyncService
{
	public function __construct(
		private IManager $calendarManager,
		private AbsenceCalendarMapper $absenceCalendarMapper,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
		private IURLGenerator $urlGenerator,
		private IConfig $config,
		private ?\OCA\DAV\CalDAV\CalDavBackend $calDavBackend = null,
	) {
	}

	/**
	 * Create or replace the calendar object for an approved absence.
	 */
	public function syncApprovedAbsence(Absence $absence): void
	{
		if ($absence->getStatus() !== Absence::STATUS_APPROVED) {
			return;
		}
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		if (!$start || !$end) {
			return;
		}

		$userId = $absence->getUserId();
		if ($this->config->getAppValue('arbeitszeitcheck', AppConstants::CONFIG_CALENDAR_SYNC_ABSENCES_ENABLED, '1') !== '1') {
			return;
		}

		$principal = 'principals/users/' . $userId;
		if (!$this->calendarManager->isEnabled()) {
			return;
		}

		$calendar = $this->getOrCreateAbsenceCalendar($principal, $userId);
		if (!$calendar instanceof ICreateFromString) {
			return;
		}

		$existing = $this->absenceCalendarMapper->findByAbsenceIdOrNull($absence->getId());
		if ($existing !== null) {
			if ($this->calDavBackend !== null) {
				try {
					$this->calDavBackend->deleteCalendarObject(
						$existing->getCalendarId(),
						$existing->getObjectUri()
					);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'Could not delete previous calendar object for absence ' . $absence->getId() . ': ' . $e->getMessage(),
						['exception' => $e]
					);
					// Keep DB row in sync with CalDAV; retry on next sync (approval/date change).
					return;
				}
			}
			$this->absenceCalendarMapper->deleteByAbsenceId($absence->getId());
		}

		$uid = $this->buildUid($absence->getId());
		$summary = AbsencePrivacyPolicy::ncCalendarEventSummary($this->l10nForUser($userId));
		$ics = $this->buildAllDayIcs($uid, $start, $end, $summary);

		$objectUri = 'arbeitszeitcheck-absence-' . $absence->getId() . '.ics';
		$calendarId = (int)$calendar->getKey();
		try {
			$calendar->createFromString($objectUri, $ics);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Calendar sync failed for absence ' . $absence->getId() . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return;
		}

		$row = new AbsenceCalendar();
		$row->setAbsenceId($absence->getId());
		$row->setUserId($userId);
		$row->setCalendarId($calendarId);
		$row->setObjectUri($objectUri);
		$row->setCreatedAt($this->timeFactory->getDateTime());
		try {
			$this->absenceCalendarMapper->insert($row);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Calendar mapping insert failed for absence ' . $absence->getId() . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			if ($this->calDavBackend !== null) {
				try {
					$this->calDavBackend->deleteCalendarObject($calendarId, $objectUri);
				} catch (\Throwable $e2) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'Could not roll back calendar object after mapping failure: ' . $e2->getMessage(),
						['exception' => $e2]
					);
				}
			}
		}
	}

	/**
	 * Remove all synced absence events for a user (e.g. account deletion).
	 */
	public function removeAllMappingsForUser(string $userId): void
	{
		foreach ($this->absenceCalendarMapper->findByUserId($userId) as $row) {
			$this->removeAbsenceCalendar($row->getAbsenceId());
		}
	}

	public function removeAbsenceCalendar(int $absenceId): void
	{
		$existing = $this->absenceCalendarMapper->findByAbsenceIdOrNull($absenceId);
		if ($existing === null) {
			return;
		}
		if ($this->calDavBackend !== null) {
			try {
				$this->calDavBackend->deleteCalendarObject(
					$existing->getCalendarId(),
					$existing->getObjectUri()
				);
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'Could not delete calendar object for absence ' . $absenceId . ': ' . $e->getMessage(),
					['exception' => $e]
				);
			}
		}
		$this->absenceCalendarMapper->deleteByAbsenceId($absenceId);
	}

	/**
	 * IL10N for the calendar owner (correct language for CalDAV display names and VEVENT SUMMARY; not only default/English in cron).
	 */
	private function l10nForUser(string $userId): IL10N
	{
		$user = $this->userManager->get($userId);
		$lang = $this->l10nFactory->getUserLanguage($user);

		return $this->l10nFactory->get('arbeitszeitcheck', $lang, null);
	}

	/**
	 * Resolve or create the app-owned absence calendar; avoid writing to arbitrary shared calendars.
	 */
	private function getOrCreateAbsenceCalendar(string $principalUri, string $userId): ?object
	{
		try {
			$filtered = $this->calendarManager->getCalendarsForPrincipal(
				$principalUri,
				[AppConstants::CALENDAR_URI_ABSENCES]
			);
		} catch (\Throwable $e) {
			return null;
		}
		$calendar = $this->firstWritableCreateFromString($filtered);
		if ($calendar !== null) {
			return $calendar;
		}

		if ($this->calDavBackend !== null) {
			try {
				$existing = $this->calDavBackend->getCalendarByUri($principalUri, AppConstants::CALENDAR_URI_ABSENCES);
				if ($existing === null) {
					$this->calDavBackend->createCalendar($principalUri, AppConstants::CALENDAR_URI_ABSENCES, [
						'{DAV:}displayname' => $this->l10nForUser($userId)->t('ArbeitszeitCheck absences'),
						'{http://apple.com/ns/ical/}calendar-color' => '#0082c9',
						'components' => 'VEVENT',
					]);
				}
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'Could not ensure ArbeitszeitCheck absence calendar: ' . $e->getMessage(),
					['exception' => $e]
				);
			}
			try {
				$filtered = $this->calendarManager->getCalendarsForPrincipal(
					$principalUri,
					[AppConstants::CALENDAR_URI_ABSENCES]
				);
			} catch (\Throwable $e) {
				return null;
			}
			$calendar = $this->firstWritableCreateFromString($filtered);
			if ($calendar !== null) {
				return $calendar;
			}
		}

		\OCP\Log\logger('arbeitszeitcheck')->warning(
			'ArbeitszeitCheck absence calendar is unavailable: could not resolve or create CalDAV calendar ' . AppConstants::CALENDAR_URI_ABSENCES
		);

		return null;
	}

	/**
	 * @param list<object> $calendars
	 */
	private function firstWritableCreateFromString(array $calendars): ?object
	{
		foreach ($calendars as $cal) {
			if (!$cal instanceof ICreateFromString) {
				continue;
			}
			if (($cal->getPermissions() & Constants::PERMISSION_CREATE) !== Constants::PERMISSION_CREATE) {
				continue;
			}
			if ($cal->isDeleted()) {
				continue;
			}
			return $cal;
		}
		return null;
	}

	private function buildUid(int $absenceId): string
	{
		$host = parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST) ?: 'nextcloud.local';

		return 'arbeitszeitcheck-absence-' . $absenceId . '@' . $host;
	}

	private function buildAllDayIcs(string $uid, \DateTime $start, \DateTime $end, string $summary): string
	{
		$s = clone $start;
		$s->setTime(0, 0, 0);
		$e = clone $end;
		$e->setTime(0, 0, 0);
		// DTEND is exclusive: day after last day of absence
		$endExclusive = clone $e;
		$endExclusive->modify('+1 day');

		$ds = $s->format('Ymd');
		$de = $endExclusive->format('Ymd');
		$stamp = gmdate('Ymd\THis\Z', $this->timeFactory->getTime());

		return implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Software by Design//ArbeitszeitCheck//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:' . $this->escapeIcsText($uid),
			'DTSTAMP:' . $stamp,
			'DTSTART;VALUE=DATE:' . $ds,
			'DTEND;VALUE=DATE:' . $de,
			'SUMMARY:' . $this->escapeIcsText($summary),
			'TRANSP:OPAQUE',
			'STATUS:CONFIRMED',
			'END:VEVENT',
			'END:VCALENDAR',
		]) . "\r\n";
	}

	private function escapeIcsText(string $s): string
	{
		$s = str_replace(['\\', ',', ';', "\n", "\r"], ['\\\\', '\\,', '\\;', '\\n', ''], $s);

		return $s;
	}
}
