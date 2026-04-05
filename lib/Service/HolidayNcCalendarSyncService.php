<?php

declare(strict_types=1);

/**
 * Syncs effective public holidays (Feiertage) into a dedicated Nextcloud calendar per user.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants as AppConstants;
use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayCalendar;
use OCA\ArbeitszeitCheck\Db\HolidayCalendarMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\Constants;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;

class HolidayNcCalendarSyncService
{
	public function __construct(
		private IManager $calendarManager,
		private HolidayCalendarMapper $holidayCalendarMapper,
		private HolidayCalendarService $holidayCalendarService,
		private UserSettingsMapper $userSettingsMapper,
		private IUserManager $userManager,
		private IFactory $l10nFactory,
		private ITimeFactory $timeFactory,
		private IURLGenerator $urlGenerator,
		private IConfig $config,
		private ?\OCA\DAV\CalDAV\CalDavBackend $calDavBackend = null,
	) {
	}

	/**
	 * Sync holidays for all users that opted in (batch; used by background job).
	 *
	 * @param int $limit Max users per run
	 * @param int $offset Pagination offset into user search
	 * @return int Number of users processed
	 */
	public function syncBatch(int $limit = 50, int $offset = 0): int
	{
		if ($this->config->getAppValue('arbeitszeitcheck', AppConstants::CONFIG_CALENDAR_SYNC_HOLIDAYS_ENABLED, '1') !== '1') {
			return 0;
		}
		$users = $this->userManager->search('', $limit, $offset);
		$n = 0;
		foreach ($users as $user) {
			try {
				$this->syncForUser($user->getUID());
				$n++;
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'Holiday calendar sync failed for user: ' . $e->getMessage(),
					['exception' => $e]
				);
			}
		}
		return $n;
	}

	public function syncForUser(string $userId): void
	{
		if ($this->config->getAppValue('arbeitszeitcheck', AppConstants::CONFIG_CALENDAR_SYNC_HOLIDAYS_ENABLED, '1') !== '1') {
			return;
		}
		if (!$this->userSettingsMapper->getBooleanSetting($userId, AppConstants::USER_SETTING_CALENDAR_SYNC_HOLIDAYS, true)) {
			$this->removeAllForUser($userId);
			return;
		}
		if (!$this->calendarManager->isEnabled()) {
			return;
		}

		$principal = 'principals/users/' . $userId;
		$calendar = $this->getOrCreateHolidaysCalendar($principal, $userId);
		if (!$calendar instanceof ICreateFromString) {
			return;
		}

		$year = (int)gmdate('Y', $this->timeFactory->getTime());
		$start = new \DateTime(($year - 1) . '-01-01');
		$end = new \DateTime(($year + 1) . '-12-31');
		$start->setTime(0, 0, 0);
		$end->setTime(0, 0, 0);

		$state = $this->holidayCalendarService->resolveStateForUser($userId);
		$holidays = $this->holidayCalendarService->getHolidaysForRange($state, $start, $end);
		$desiredIds = [];
		foreach ($holidays as $h) {
			if (isset($h['id'])) {
				$desiredIds[(int)$h['id']] = true;
			}
		}

		foreach ($this->holidayCalendarMapper->findByUserId($userId) as $row) {
			$hid = $row->getHolidayId();
			if (!isset($desiredIds[$hid])) {
				$this->deleteMappingRow($row);
			}
		}

		$calendarId = (int)$calendar->getKey();
		foreach ($holidays as $dto) {
			if (!isset($dto['id'], $dto['date']) || $dto['date'] === null) {
				continue;
			}
			$this->upsertHoliday($userId, $calendar, $calendarId, $dto);
		}
	}

	public function removeAllForUser(string $userId): void
	{
		foreach ($this->holidayCalendarMapper->findByUserId($userId) as $row) {
			$this->deleteMappingRow($row);
		}
	}

	/**
	 * @param array<string,mixed> $dto Holiday DTO from HolidayCalendarService
	 */
	private function upsertHoliday(string $userId, object $calendar, int $calendarId, array $dto): void
	{
		$holidayId = (int)$dto['id'];
		$dateStr = (string)$dto['date'];
		$existing = $this->holidayCalendarMapper->findByUserAndHolidayId($userId, $holidayId);
		$objectUri = 'arbeitszeitcheck-holiday-' . $holidayId . '.ics';

		if ($existing !== null) {
			if ($this->calDavBackend !== null) {
				try {
					$this->calDavBackend->deleteCalendarObject(
						$existing->getCalendarId(),
						$existing->getObjectUri()
					);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'Could not delete previous holiday calendar object ' . $holidayId . ': ' . $e->getMessage(),
						['exception' => $e]
					);
					return;
				}
			}
			$this->holidayCalendarMapper->delete($existing);
		}

		$start = \DateTime::createFromFormat('Y-m-d', $dateStr, new \DateTimeZone('UTC'));
		if ($start === false) {
			return;
		}
		$start->setTime(0, 0, 0);
		$endExclusive = clone $start;
		$endExclusive->modify('+1 day');

		$l10n = $this->l10nForUser($userId);
		$name = (string)($dto['name'] ?? $l10n->t('Holiday'));
		if (($dto['kind'] ?? '') === Holiday::KIND_HALF) {
			$name .= ' (' . $l10n->t('half day') . ')';
		}
		$summary = $name . ' (' . $l10n->t('ArbeitszeitCheck') . ')';

		$uid = 'arbeitszeitcheck-holiday-' . $holidayId . '@' . (parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST) ?: 'nextcloud.local');
		$ics = $this->buildAllDayIcs($uid, $start, $endExclusive, $summary);

		if (!$calendar instanceof ICreateFromString) {
			return;
		}
		try {
			$calendar->createFromString($objectUri, $ics);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Holiday calendar sync failed for holiday ' . $holidayId . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return;
		}

		$row = new HolidayCalendar();
		$row->setUserId($userId);
		$row->setHolidayId($holidayId);
		$row->setCalendarId($calendarId);
		$row->setObjectUri($objectUri);
		$row->setCreatedAt($this->timeFactory->getDateTime());
		try {
			$this->holidayCalendarMapper->insert($row);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Holiday calendar mapping insert failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			if ($this->calDavBackend !== null) {
				try {
					$this->calDavBackend->deleteCalendarObject($calendarId, $objectUri);
				} catch (\Throwable $e2) {
					\OCP\Log\logger('arbeitszeitcheck')->warning($e2->getMessage(), ['exception' => $e2]);
				}
			}
		}
	}

	private function deleteMappingRow(HolidayCalendar $row): void
	{
		if ($this->calDavBackend !== null) {
			try {
				$this->calDavBackend->deleteCalendarObject($row->getCalendarId(), $row->getObjectUri());
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'Could not delete holiday calendar object: ' . $e->getMessage(),
					['exception' => $e]
				);
			}
		}
		$this->holidayCalendarMapper->delete($row);
	}

	/**
	 * IL10N for the calendar owner (display names and event titles match user language in cron/CLI).
	 */
	private function l10nForUser(string $userId): IL10N
	{
		$user = $this->userManager->get($userId);
		$lang = $this->l10nFactory->getUserLanguage($user);

		return $this->l10nFactory->get('arbeitszeitcheck', $lang, null);
	}

	private function getOrCreateHolidaysCalendar(string $principalUri, string $userId): ?object
	{
		try {
			$filtered = $this->calendarManager->getCalendarsForPrincipal(
				$principalUri,
				[AppConstants::CALENDAR_URI_HOLIDAYS]
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
				$existing = $this->calDavBackend->getCalendarByUri($principalUri, AppConstants::CALENDAR_URI_HOLIDAYS);
				if ($existing === null) {
					$this->calDavBackend->createCalendar($principalUri, AppConstants::CALENDAR_URI_HOLIDAYS, [
						'{DAV:}displayname' => $this->l10nForUser($userId)->t('ArbeitszeitCheck public holidays'),
						'{http://apple.com/ns/ical/}calendar-color' => '#2ca58d',
						'components' => 'VEVENT',
					]);
				}
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'Could not ensure holiday calendar: ' . $e->getMessage(),
					['exception' => $e]
				);
			}
			try {
				$filtered = $this->calendarManager->getCalendarsForPrincipal(
					$principalUri,
					[AppConstants::CALENDAR_URI_HOLIDAYS]
				);
			} catch (\Throwable $e) {
				return null;
			}
			return $this->firstWritableCreateFromString($filtered);
		}
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

	private function buildAllDayIcs(string $uid, \DateTime $start, \DateTime $endExclusive, string $summary): string
	{
		$ds = $start->format('Ymd');
		$de = $endExclusive->format('Ymd');
		$stamp = gmdate('Ymd\THis\Z', $this->timeFactory->getTime());
		$eUid = $this->escapeIcsText($uid);
		$eSum = $this->escapeIcsText($summary);

		return implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Software by Design//ArbeitszeitCheck//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:' . $eUid,
			'DTSTAMP:' . $stamp,
			'DTSTART;VALUE=DATE:' . $ds,
			'DTEND;VALUE=DATE:' . $de,
			'SUMMARY:' . $eSum,
			'TRANSP:TRANSPARENT',
			'STATUS:CONFIRMED',
			'END:VEVENT',
			'END:VCALENDAR',
		]) . "\r\n";
	}

	private function escapeIcsText(string $s): string
	{
		return str_replace(['\\', ',', ';', "\n", "\r"], ['\\\\', '\\,', '\\;', '\\n', ''], $s);
	}
}
