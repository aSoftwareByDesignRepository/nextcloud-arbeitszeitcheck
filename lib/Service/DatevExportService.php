<?php

declare(strict_types=1);

/**
 * DATEV export service for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\MonthClosure;
use OCA\ArbeitszeitCheck\Db\MonthClosureMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Support\DatevPremiumLohnartMap;
use OCP\IConfig;
use OCP\IL10N;

/**
 * DatevExportService for generating DATEV-compatible export files
 *
 * DATEV is a German accounting software standard. This service generates
 * ASCII format files compatible with DATEV LODAS and DATEV Lohn und Gehalt.
 *
 * Note: Organizations must configure their Beraternummer, Mandantennummer,
 * and Lohnarten mapping according to their DATEV setup.
 *
 * Premium (Zuschlag) lines are additive only: normal-hour lines stay unchanged.
 * A bucket is exported only when premiums are enabled and a Lohnart is mapped.
 */
class DatevExportService
{
	private TimeEntryMapper $timeEntryMapper;
	private IConfig $config;
	private IL10N $l10n;
	private ?PremiumSurchargeService $premiumSurchargeService;
	private ?MonthClosureMapper $monthClosureMapper;

	public function __construct(
		TimeEntryMapper $timeEntryMapper,
		IConfig $config,
		IL10N $l10n,
		?PremiumSurchargeService $premiumSurchargeService = null,
		?MonthClosureMapper $monthClosureMapper = null
	) {
		$this->timeEntryMapper = $timeEntryMapper;
		$this->config = $config;
		$this->l10n = $l10n;
		$this->premiumSurchargeService = $premiumSurchargeService;
		$this->monthClosureMapper = $monthClosureMapper;
	}

	/**
	 * Export time entries in DATEV format
	 *
	 * @param string $userId User ID to export data for
	 * @param \DateTime $startDate Start date
	 * @param \DateTime $endDate End date
	 * @return string DATEV-formatted ASCII content
	 */
	public function exportTimeEntries(string $userId, \DateTime $startDate, \DateTime $endDate): string
	{
		// Get time entries
		$entries = $this->timeEntryMapper->findByUserAndDateRange($userId, $startDate, $endDate);

		// Get DATEV configuration
		$beraternummer = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_DATEV_BERATERNUMMER, '');
		$mandantennummer = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_DATEV_MANDANTENNUMMER, '');
		$personalnummer = $this->config->getUserValue($userId, 'arbeitszeitcheck', Constants::USER_DATEV_PERSONALNUMMER, '');
		$lohnart_normal = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_DATEV_LOHNART_NORMAL, '1000'); // Default: 1000 = Normal hours
		$lohnart_ueberstunden = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_DATEV_LOHNART_UEBERSTUNDEN, '2000'); // Default: 2000 = Overtime

		// Validate required configuration
		if (empty($beraternummer) || empty($mandantennummer)) {
			throw new \Exception($this->l10n->t('DATEV configuration incomplete. Please configure Beraternummer and Mandantennummer in admin settings.'));
		}

		if (empty($personalnummer)) {
			throw new \Exception($this->l10n->t('Personalnummer not configured for user. Please set DATEV Personalnummer in user settings.'));
		}

		// Build DATEV file content
		$lines = [];

		// Header line (DATEV format: Beraternummer|Mandantennummer|Wirtschaftsjahr|Versionsnummer)
		$currentYear = (int)$startDate->format('Y');
		$lines[] = sprintf(
			'%s|%s|%d|%s',
			str_pad($beraternummer, 7, '0', STR_PAD_LEFT),
			str_pad($mandantennummer, 5, '0', STR_PAD_LEFT),
			$currentYear,
			'1' // Version number
		);

		// Process each time entry
		foreach ($entries as $entry) {
			if ($entry->getStatus() !== TimeEntry::STATUS_COMPLETED || $entry->getEndTime() === null) {
				continue; // Skip incomplete entries
			}

			$workingHours = $entry->getWorkingDurationHours();
			if ($workingHours <= 0) {
				continue; // Skip entries with no working time
			}

			$startTime = $entry->getStartTime();
			if (!$startTime) {
				continue; // Skip entries with no start time
			}
			$date = $startTime->format('Ymd'); // DATEV format: YYYYMMDD
			$hours = round($workingHours, 2);

			// Determine if this is overtime (simplified - could be enhanced with OvertimeService)
			// For now, use normal hours. Organizations can configure overtime detection separately.
			// Note: $lohnart_ueberstunden is loaded for future Saldo mapping; premiums use mapped Lohnarten.
			$lohnart = $lohnart_normal;

			// DATEV data line format:
			// Personalnummer|Datum|Lohnart|Menge|Einheit|Text
			// Personalnummer: 8 digits, left-padded with zeros
			// Datum: YYYYMMDD
			// Lohnart: 4 digits (1-8999 for regular, 9001-9999 for net additions/deductions)
			// Menge: Hours worked (decimal, max 2 decimal places)
			// Einheit: 'Std' for hours
			// Text: Description (optional, max 20 characters)

			$description = $entry->getDescription() ?? '';
			$description = mb_substr($description, 0, 20); // Limit to 20 characters

			$lines[] = sprintf(
				'%s|%s|%s|%.2f|%s|%s',
				str_pad($personalnummer, 8, '0', STR_PAD_LEFT),
				$date,
				str_pad($lohnart, 4, '0', STR_PAD_LEFT),
				$hours,
				'Std',
				$description
			);
		}

		$this->appendPremiumLines($lines, $userId, $personalnummer, $startDate, $endDate);

		// Join lines with newline (DATEV uses Windows line endings: \r\n)
		return implode("\r\n", $lines);
	}

	/**
	 * Export time entries for multiple users (for admin/HR export)
	 *
	 * @param array $userIds Array of user IDs
	 * @param \DateTime $startDate Start date
	 * @param \DateTime $endDate End date
	 * @return string DATEV-formatted ASCII content
	 */
	public function exportMultipleUsers(array $userIds, \DateTime $startDate, \DateTime $endDate): string
	{
		$allLines = [];
		$beraternummer = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_DATEV_BERATERNUMMER, '');
		$mandantennummer = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_DATEV_MANDANTENNUMMER, '');

		if (empty($beraternummer) || empty($mandantennummer)) {
			throw new \Exception($this->l10n->t('DATEV configuration incomplete. Please configure Beraternummer and Mandantennummer in admin settings.'));
		}

		// Add header once
		$currentYear = (int)$startDate->format('Y');
		$allLines[] = sprintf(
			'%s|%s|%d|%s',
			str_pad($beraternummer, 7, '0', STR_PAD_LEFT),
			str_pad($mandantennummer, 5, '0', STR_PAD_LEFT),
			$currentYear,
			'1'
		);

		// Process each user
		foreach ($userIds as $userId) {
			$personalnummer = $this->config->getUserValue($userId, 'arbeitszeitcheck', Constants::USER_DATEV_PERSONALNUMMER, '');
			if (empty($personalnummer)) {
				continue; // Skip users without Personalnummer
			}

			$entries = $this->timeEntryMapper->findByUserAndDateRange($userId, $startDate, $endDate);
			$lohnart_normal = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_DATEV_LOHNART_NORMAL, '1000');

			foreach ($entries as $entry) {
				if ($entry->getStatus() !== TimeEntry::STATUS_COMPLETED || $entry->getEndTime() === null) {
					continue;
				}

				$workingHours = $entry->getWorkingDurationHours();
				if ($workingHours <= 0) {
					continue;
				}

				$startTime = $entry->getStartTime();
				if (!$startTime) {
					continue; // Skip entries with no start time
				}
				$date = $startTime->format('Ymd');
				$hours = round($workingHours, 2);
				$description = mb_substr($entry->getDescription() ?? '', 0, 20);

				$allLines[] = sprintf(
					'%s|%s|%s|%.2f|%s|%s',
					str_pad($personalnummer, 8, '0', STR_PAD_LEFT),
					$date,
					str_pad($lohnart_normal, 4, '0', STR_PAD_LEFT),
					$hours,
					'Std',
					$description
				);
			}

			$this->appendPremiumLines($allLines, $userId, $personalnummer, $startDate, $endDate);
		}

		return implode("\r\n", $allLines);
	}

	/**
	 * Get DATEV configuration status
	 *
	 * @return array Configuration status
	 */
	public function getConfigurationStatus(): array
	{
		$beraternummer = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_DATEV_BERATERNUMMER, '');
		$mandantennummer = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_DATEV_MANDANTENNUMMER, '');

		return [
			'configured' => !empty($beraternummer) && !empty($mandantennummer),
			'beraternummer_set' => !empty($beraternummer),
			'mandantennummer_set' => !empty($mandantennummer),
			'beraternummer' => $beraternummer,
			'mandantennummer' => $mandantennummer,
			'premium_lohnart_map' => $this->loadPremiumLohnartMap(),
		];
	}

	/**
	 * Append additive premium Lohnart lines when enabled and mapped.
	 * Exports classified hours (Std), never valued_hours — payroll applies % itself.
	 *
	 * Closed months prefer the frozen month-closure premium snapshot (NN-06 / MH-09)
	 * so a later policy edit cannot drift DATEV. Open months stay live.
	 *
	 * @param list<string> $lines
	 */
	private function appendPremiumLines(
		array &$lines,
		string $userId,
		string $personalnummer,
		\DateTime $startDate,
		\DateTime $endDate
	): void {
		if ($this->premiumSurchargeService === null || !$this->premiumSurchargeService->isEnabled()) {
			return;
		}

		$map = $this->loadPremiumLohnartMap();
		if ($map === []) {
			return;
		}

		$summary = $this->resolvePremiumSummaryForExport($userId, $startDate, $endDate);
		if (empty($summary['enabled']) || !is_array($summary['buckets'] ?? null)) {
			return;
		}

		// Stamp = last inclusive calendar day of the export (not exclusive end).
		$date = (string)($summary['stamp_ymd'] ?? $endDate->format('Ymd'));
		$pn = str_pad($personalnummer, 8, '0', STR_PAD_LEFT);

		foreach ($summary['buckets'] as $bucket) {
			if (!is_array($bucket)) {
				continue;
			}
			$id = trim((string)($bucket['id'] ?? ''));
			$hours = round((float)($bucket['hours'] ?? 0), 2);
			if ($id === '' || $hours <= 0.0 || !isset($map[$id])) {
				continue;
			}
			$lohnart = $map[$id];
			$label = mb_substr((string)($bucket['label'] ?? $id), 0, 20);
			$lines[] = sprintf(
				'%s|%s|%s|%.2f|%s|%s',
				$pn,
				$date,
				str_pad($lohnart, 4, '0', STR_PAD_LEFT),
				$hours,
				'Std',
				$label
			);
		}
	}

	/**
	 * Merge month-scoped premium buckets: finalized closures → frozen snapshot
	 * for any overlap (NN-06 — never live-reclassify a sealed month).
	 * Open months stay live.
	 *
	 * Date range is half-open [start, endExclusive) — same contract as
	 * ExportController::resolveInclusiveDateRange / TimeEntryMapper.
	 *
	 * @return array{enabled?: bool, buckets?: list<array<string, mixed>>, stamp_ymd?: string}
	 */
	private function resolvePremiumSummaryForExport(
		string $userId,
		\DateTimeInterface $startDate,
		\DateTimeInterface $endDate
	): array {
		[$rangeStart, $rangeEndInclusive] = $this->halfOpenToInclusiveDayRange($startDate, $endDate);
		if ($rangeEndInclusive < $rangeStart) {
			return ['enabled' => true, 'buckets' => [], 'stamp_ymd' => $rangeStart->format('Ymd')];
		}

		$mergedHours = [];
		$mergedMeta = [];
		$cursor = $rangeStart->modify('first day of this month');
		$guard = 0;
		while ($cursor <= $rangeEndInclusive && $guard < 240) {
			$guard++;
			$monthStart = $cursor;
			$monthEnd = $cursor->modify('last day of this month');
			$overlapStart = $rangeStart > $monthStart ? $rangeStart : $monthStart;
			$overlapEnd = $rangeEndInclusive < $monthEnd ? $rangeEndInclusive : $monthEnd;
			if ($overlapStart <= $overlapEnd) {
				$frozen = $this->frozenPremiumSummaryForMonth(
					$userId,
					(int)$monthStart->format('Y'),
					(int)$monthStart->format('n')
				);
				if ($frozen !== null) {
					// Sealed month: always emit frozen full-month buckets (payroll immutable).
					// Mid-month DATEV of a sealed month is month-granular by design.
					$this->mergePremiumBuckets($mergedHours, $mergedMeta, $frozen);
				} elseif ($this->premiumSurchargeService !== null) {
					$liveStart = \DateTime::createFromImmutable($overlapStart);
					$liveEnd = \DateTime::createFromImmutable($overlapEnd);
					$live = $this->premiumSurchargeService->summariseForUser($userId, $liveStart, $liveEnd);
					if (!empty($live['enabled']) && is_array($live['buckets'] ?? null)) {
						$this->mergePremiumBuckets($mergedHours, $mergedMeta, $live['buckets']);
					}
				}
			}
			$cursor = $monthStart->modify('first day of next month');
		}

		$buckets = [];
		foreach ($mergedHours as $id => $hours) {
			if ($hours <= 0.0) {
				continue;
			}
			$buckets[] = [
				'id' => $id,
				'label' => (string)($mergedMeta[$id]['label'] ?? $id),
				'hours' => round($hours, 4),
				'rate' => (float)($mergedMeta[$id]['rate'] ?? 0),
			];
		}
		usort($buckets, static fn (array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));

		return [
			'enabled' => true,
			'buckets' => $buckets,
			'stamp_ymd' => $rangeEndInclusive->format('Ymd'),
		];
	}

	/**
	 * Convert half-open [start, endExclusive) to inclusive calendar days.
	 * Contract matches ExportController::resolveInclusiveDateRange.
	 *
	 * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
	 */
	private function halfOpenToInclusiveDayRange(
		\DateTimeInterface $startDate,
		\DateTimeInterface $endExclusive
	): array {
		$rangeStart = \DateTimeImmutable::createFromInterface($startDate)->setTime(0, 0, 0);
		$endEx = \DateTimeImmutable::createFromInterface($endExclusive)->setTime(0, 0, 0);
		$rangeEndInclusive = $endEx->modify('-1 day');
		return [$rangeStart, $rangeEndInclusive];
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	private function frozenPremiumSummaryForMonth(string $userId, int $year, int $month): ?array
	{
		if ($this->monthClosureMapper === null) {
			return null;
		}
		try {
			$row = $this->monthClosureMapper->findByUserAndMonthOptional($userId, $year, $month);
		} catch (\Throwable) {
			return null;
		}
		if ($row === null
			|| $row->getStatus() !== MonthClosure::STATUS_FINALIZED
			|| $row->getCanonicalPayload() === null) {
			return null;
		}
		$data = json_decode((string)$row->getCanonicalPayload(), true);
		if (!is_array($data)) {
			return null;
		}
		$premium = $data['premium'] ?? null;
		if (!is_array($premium) || empty($premium['enabled'])) {
			return null;
		}
		$summary = $premium['summary'] ?? null;
		if (!is_array($summary) || !is_array($summary['buckets'] ?? null)) {
			return null;
		}

		return $summary['buckets'];
	}

	/**
	 * @param array<string, float> $mergedHours
	 * @param array<string, array{label: string, rate: float}> $mergedMeta
	 * @param list<array<string, mixed>> $buckets
	 */
	private function mergePremiumBuckets(array &$mergedHours, array &$mergedMeta, array $buckets): void
	{
		foreach ($buckets as $bucket) {
			if (!is_array($bucket)) {
				continue;
			}
			$id = trim((string)($bucket['id'] ?? ''));
			$hours = (float)($bucket['hours'] ?? 0);
			if ($id === '' || $hours <= 0.0) {
				continue;
			}
			$mergedHours[$id] = ($mergedHours[$id] ?? 0.0) + $hours;
			$mergedMeta[$id] = [
				'label' => (string)($bucket['label'] ?? ($mergedMeta[$id]['label'] ?? $id)),
				'rate' => (float)($bucket['rate'] ?? ($mergedMeta[$id]['rate'] ?? 0)),
			];
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function loadPremiumLohnartMap(): array
	{
		$raw = $this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_DATEV_LOHNART_PREMIUM_MAP,
			''
		);

		return DatevPremiumLohnartMap::fromJson(is_string($raw) ? $raw : '');
	}
}
