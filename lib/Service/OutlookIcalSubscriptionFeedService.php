<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Util\Rfc5545Ics;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * RFC 5545 bulk iCalendar feed generator for Outlook subscriptions.
 *
 * Locked spec rules implemented here:
 * - approved-only event mapping is enforced by the caller (service layer).
 * - DESCRIPTION never includes Absence::getReason().
 * - TYPE_SICK_LEAVE is mapped to localized generic "Absence" (not "Sick Leave").
 * - Event titles use `{displayName} ({typeLabel})` — no colons (Thunderbird-safe).
 * - DATE range uses exclusive DTEND (end + 1 day).
 * - Stable UID: arbeitszeitcheck-absence-{absenceId}@{tenantDomain}
 */
final class OutlookIcalSubscriptionFeedService
{
	public function __construct(
		private readonly IFactory $l10nFactory,
	) {
	}

	/**
	 * @param list<Absence> $absences Already filtered for approved absences and validated limits.
	 * @param array<string, string> $displayNamesByUserId Map userId => display name.
	 * @param string|null $languageCode BCP 47 / NC language code for labels (manager locale preferred by caller).
	 */
	public function buildFeed(
		string $tenantDomain,
		array $absences,
		array $displayNamesByUserId,
		?string $languageCode = null,
	): string {
		$l10n = $this->resolveL10n($languageCode);

		// Deterministic ordering: stable updates for Outlook refresh.
		usort($absences, function (Absence $a, Absence $b): int {
			$ad = $a->getStartDate();
			$bd = $b->getStartDate();
			$adStr = $ad ? $ad->format('Y-m-d') : '';
			$bdStr = $bd ? $bd->format('Y-m-d') : '';
			if ($adStr === $bdStr) {
				return (int)$a->getId() <=> (int)$b->getId();
			}
			return strcmp($adStr, $bdStr);
		});

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//ArbeitszeitCheck//Outlook iCal Subscription//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . Rfc5545Ics::escapeText($l10n->t('Approved team absences')),
			'NAME:' . Rfc5545Ics::escapeText($l10n->t('Approved team absences')),
			'X-WR-TIMEZONE:UTC',
		];

		foreach ($absences as $absence) {
			$start = $absence->getStartDate();
			$end = $absence->getEndDate();
			if ($start === null || $end === null) {
				continue; // fail-safe: corrupt legacy rows drop silently
			}
			// Defensive guard: invalid legacy record cannot create an iCal event.
			if ($start > $end) {
				continue;
			}

			$dtStart = $start->format('Ymd');
			$dtEnd = (clone $end)->modify('+1 day')->format('Ymd');

			$updatedAt = $absence->getUpdatedAt();
			$dtStamp = (clone $updatedAt)
				->setTimezone(new \DateTimeZone('UTC'))
				->format('Ymd\THis\Z');

			$uid = 'arbeitszeitcheck-absence-' . (int)$absence->getId() . '@' . $tenantDomain;

			$userId = $absence->getUserId();
			$displayName = trim((string)($displayNamesByUserId[$userId] ?? ''));
			if ($displayName === '') {
				$displayName = trim($userId);
			}

			$typeLabel = $this->typeLabel($absence, $l10n);
			$halfDayLabel = $this->halfDayLabel($absence, $l10n);

			$summaryRaw = $this->buildEventTitle($displayName, $typeLabel, $halfDayLabel);
			$summary = Rfc5545Ics::escapeText($summaryRaw);

			// Privacy-safe: no free-text reason; keep DESCRIPTION aligned with SUMMARY.
			$description = Rfc5545Ics::escapeText($summaryRaw);
			$location = Rfc5545Ics::escapeText($displayName);

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . Rfc5545Ics::escapeText($uid);
			$lines[] = 'DTSTAMP:' . $dtStamp;
			$lines[] = 'DTSTART;VALUE=DATE:' . $dtStart;
			$lines[] = 'DTEND;VALUE=DATE:' . $dtEnd;
			$lines[] = 'SUMMARY:' . $summary;
			$lines[] = 'LOCATION:' . $location;
			$lines[] = 'DESCRIPTION:' . $description;
			$lines[] = 'STATUS:CONFIRMED';
			$lines[] = 'TRANSP:OPAQUE';
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';

		// Fold/escape are deterministic and RFC 5545-compliant.
		return Rfc5545Ics::fold($lines);
	}

	private function resolveL10n(?string $languageCode): IL10N
	{
		$lang = trim((string)$languageCode);
		if ($lang === '') {
			$lang = (string)$this->l10nFactory->findLanguage(Application::APP_ID);
		}

		return $this->l10nFactory->get(Application::APP_ID, $lang);
	}

	private function buildEventTitle(string $displayName, string $typeLabel, ?string $halfDayLabel): string
	{
		if ($halfDayLabel !== null && $halfDayLabel !== '') {
			return $displayName . ' (' . $typeLabel . ', ' . $halfDayLabel . ')';
		}

		return $displayName . ' (' . $typeLabel . ')';
	}

	private function typeLabel(Absence $absence, IL10N $l10n): string
	{
		$type = $absence->getType();
		// Health-related types stay masked in shared feeds (locked privacy mapping).
		if ($type === Absence::TYPE_SICK_LEAVE || $type === Absence::TYPE_CHILD_SICK) {
			return $l10n->t('Absence');
		}
		return AbsenceTypeCatalog::label($l10n, (string)$type);
	}

	private function halfDayLabel(Absence $absence, IL10N $l10n): ?string
	{
		$days = $absence->getDays();
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		if ($start === null || $end === null) {
			return null;
		}
		$isHalf =
			$days !== null
			&& abs((float)$days - 0.5) < 1e-9
			&& $start->format('Y-m-d') === $end->format('Y-m-d'); // locked decision: half-day is single-day

		if (!$isHalf) {
			return null;
		}

		return $l10n->t('Half day');
	}
}
