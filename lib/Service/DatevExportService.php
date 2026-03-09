<?php

declare(strict_types=1);

/**
 * DATEV export service for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
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
 */
class DatevExportService
{
	private TimeEntryMapper $timeEntryMapper;
	private IConfig $config;
	private IL10N $l10n;

	public function __construct(
		TimeEntryMapper $timeEntryMapper,
		IConfig $config,
		IL10N $l10n
	) {
		$this->timeEntryMapper = $timeEntryMapper;
		$this->config = $config;
		$this->l10n = $l10n;
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
		$beraternummer = $this->config->getAppValue('arbeitszeitcheck', 'datev_beraternummer', '');
		$mandantennummer = $this->config->getAppValue('arbeitszeitcheck', 'datev_mandantennummer', '');
		$personalnummer = $this->config->getUserValue($userId, 'arbeitszeitcheck', 'datev_personalnummer', '');
		$lohnart_normal = $this->config->getAppValue('arbeitszeitcheck', 'datev_lohnart_normal', '1000'); // Default: 1000 = Normal hours
		$lohnart_ueberstunden = $this->config->getAppValue('arbeitszeitcheck', 'datev_lohnart_ueberstunden', '2000'); // Default: 2000 = Overtime

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
		$beraternummer = $this->config->getAppValue('arbeitszeitcheck', 'datev_beraternummer', '');
		$mandantennummer = $this->config->getAppValue('arbeitszeitcheck', 'datev_mandantennummer', '');

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
			$personalnummer = $this->config->getUserValue($userId, 'arbeitszeitcheck', 'datev_personalnummer', '');
			if (empty($personalnummer)) {
				continue; // Skip users without Personalnummer
			}

			$entries = $this->timeEntryMapper->findByUserAndDateRange($userId, $startDate, $endDate);
			$lohnart_normal = $this->config->getAppValue('arbeitszeitcheck', 'datev_lohnart_normal', '1000');

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
		$beraternummer = $this->config->getAppValue('arbeitszeitcheck', 'datev_beraternummer', '');
		$mandantennummer = $this->config->getAppValue('arbeitszeitcheck', 'datev_mandantennummer', '');

		return [
			'configured' => !empty($beraternummer) && !empty($mandantennummer),
			'beraternummer_set' => !empty($beraternummer),
			'mandantennummer_set' => !empty($mandantennummer),
			'beraternummer' => $beraternummer,
			'mandantennummer' => $mandantennummer
		];
	}
}
