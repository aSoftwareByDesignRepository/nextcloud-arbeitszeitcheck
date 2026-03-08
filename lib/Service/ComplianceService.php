<?php

declare(strict_types=1);

/**
 * Compliance service for German labor law (ArbZG) and GDPR requirements
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Compliance service implementing German labor law requirements
 */
class ComplianceService
{
    private TimeEntryMapper $timeEntryMapper;
    private ComplianceViolationMapper $violationMapper;
    private WorkingTimeModelMapper $workingTimeModelMapper;
    private UserWorkingTimeModelMapper $userWorkingTimeModelMapper;
    private IUserManager $userManager;
    private IL10N $l10n;
    private ?NotificationService $notificationService;

    // German public holidays by state (Bundesland)
    private const GERMAN_PUBLIC_HOLIDAYS = [
        'BW' => [ // Baden-Württemberg
            '01-01', '01-06', '03-15', '05-01', '05-29', '06-08', '06-19', '10-03', '11-01', '12-25', '12-26'
        ],
        'BY' => [ // Bayern
            '01-01', '01-06', '04-07', '05-01', '05-29', '06-08', '06-19', '08-15', '10-03', '11-01', '12-25', '12-26'
        ],
        'BE' => [ // Berlin
            '01-01', '05-01', '05-08', '10-03', '12-25', '12-26'
        ],
        'BB' => [ // Brandenburg
            '01-01', '03-08', '05-01', '05-08', '05-29', '06-08', '06-19', '10-03', '12-25', '12-26'
        ],
        'HB' => [ // Bremen
            '01-01', '05-01', '10-03', '12-25', '12-26'
        ],
        'HH' => [ // Hamburg
            '01-01', '05-01', '10-03', '12-25', '12-26'
        ],
        'HE' => [ // Hessen
            '01-01', '05-01', '06-08', '06-19', '10-03', '12-25', '12-26'
        ],
        'MV' => [ // Mecklenburg-Vorpommern
            '01-01', '05-01', '05-08', '05-29', '06-08', '06-19', '10-03', '10-31', '11-01', '12-25', '12-26'
        ],
        'NI' => [ // Niedersachsen
            '01-01', '05-01', '06-08', '06-19', '10-03', '11-01', '12-25', '12-26'
        ],
        'NW' => [ // Nordrhein-Westfalen
            '01-01', '05-01', '05-29', '06-08', '06-19', '10-03', '11-01', '12-25', '12-26'
        ],
        'RP' => [ // Rheinland-Pfalz
            '01-01', '05-01', '05-29', '06-08', '06-19', '08-15', '10-03', '11-01', '12-25', '12-26'
        ],
        'SL' => [ // Saarland
            '01-01', '05-01', '05-29', '06-08', '06-19', '08-15', '10-03', '11-01', '12-25', '12-26'
        ],
        'SN' => [ // Sachsen
            '01-01', '05-01', '05-08', '05-29', '06-08', '06-19', '10-03', '10-31', '11-01', '12-25', '12-26'
        ],
        'ST' => [ // Sachsen-Anhalt
            '01-01', '03-08', '05-01', '05-08', '05-29', '06-08', '06-19', '10-03', '10-31', '11-01', '12-25', '12-26'
        ],
        'SH' => [ // Schleswig-Holstein
            '01-01', '05-01', '05-08', '05-29', '06-08', '06-19', '10-03', '12-25', '12-26'
        ],
        'TH' => [ // Thüringen
            '01-01', '05-01', '05-08', '05-29', '06-08', '06-19', '10-03', '10-31', '11-01', '12-25', '12-26'
        ]
    ];

    public function __construct(
        TimeEntryMapper $timeEntryMapper,
        ComplianceViolationMapper $violationMapper,
        WorkingTimeModelMapper $workingTimeModelMapper,
        UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
        IUserManager $userManager,
        IL10N $l10n,
        ?NotificationService $notificationService = null
    ) {
        $this->timeEntryMapper = $timeEntryMapper;
        $this->violationMapper = $violationMapper;
        $this->workingTimeModelMapper = $workingTimeModelMapper;
        $this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
        $this->userManager = $userManager;
        $this->l10n = $l10n;
        $this->notificationService = $notificationService;
    }

    /**
     * Check compliance before clocking in
     *
     * @param string $userId
     * @return array Array of compliance issues (empty if compliant)
     */
    public function checkComplianceBeforeClockIn(string $userId): array
    {
        $issues = [];

        // Check rest period (11 hours between shifts) - CRITICAL: Always enforce (ArbZG §5)
        // This checks both completed entries (with endTime) and paused entries (with updatedAt as "end time")
        if (!$this->checkRestPeriod($userId)) {
            // Get last completed entry (with endTime)
            $lastCompletedEntry = $this->getLastCompletedEntry($userId);
            $lastEndTime = $lastCompletedEntry && $lastCompletedEntry->getEndTime() 
                ? $lastCompletedEntry->getEndTime() 
                : null;
            
            // Also check for paused entries (clocked out but not completed)
            // Use updatedAt as the "end time" for rest period calculation
            if (!$lastEndTime) {
                $allEntries = $this->timeEntryMapper->findByUser($userId);
                $lastPausedEntry = null;
                foreach ($allEntries as $entry) {
                    if ($entry->getStatus() === TimeEntry::STATUS_PAUSED && $entry->getUpdatedAt() !== null) {
                        if ($lastPausedEntry === null || $entry->getUpdatedAt() > $lastPausedEntry->getUpdatedAt()) {
                            $lastPausedEntry = $entry;
                        }
                    }
                }
                if ($lastPausedEntry && $lastPausedEntry->getUpdatedAt()) {
                    $lastEndTime = $lastPausedEntry->getUpdatedAt();
                }
            }
            
            if ($lastEndTime) {
                // Calculate when user can clock in again
                $earliestClockIn = clone $lastEndTime;
                $earliestClockIn->modify('+11 hours');
                $now = new \DateTime();
                $hoursRemaining = ($earliestClockIn->getTimestamp() - $now->getTimestamp()) / 3600;
                
                $issues[] = [
                    'type' => ComplianceViolation::TYPE_INSUFFICIENT_REST_PERIOD,
                    'severity' => ComplianceViolation::SEVERITY_ERROR,
                    'message' => $this->l10n->t(
                        'Minimum 11-hour rest period required between shifts (ArbZG §5). Your last shift ended at %s. You can clock in after %s (in %.1f hours).',
                        [
                            $lastEndTime->format('H:i'),
                            $earliestClockIn->format('H:i'),
                            max(0, $hoursRemaining)
                        ]
                    )
                ];
            } else {
                $issues[] = [
                    'type' => ComplianceViolation::TYPE_INSUFFICIENT_REST_PERIOD,
                    'severity' => ComplianceViolation::SEVERITY_ERROR,
                    'message' => $this->l10n->t('Minimum 11-hour rest period required between shifts (ArbZG §5)')
                ];
            }
        }

        // Check daily working hours limit
        if (!$this->checkDailyWorkingHoursLimit($userId)) {
            $issues[] = [
                'type' => ComplianceViolation::TYPE_DAILY_HOURS_LIMIT_EXCEEDED,
                'severity' => ComplianceViolation::SEVERITY_ERROR,
                'message' => $this->l10n->t('Daily working hours limit reached (10 hours maximum)')
            ];
        }

        // Check weekly working hours average
        if (!$this->checkWeeklyWorkingHoursLimit($userId)) {
            $issues[] = [
                'type' => ComplianceViolation::TYPE_WEEKLY_HOURS_LIMIT_EXCEEDED,
                'severity' => ComplianceViolation::SEVERITY_WARNING,
                'message' => $this->l10n->t('Weekly working hours average limit (48 hours) exceeded')
            ];
        }

        return $issues;
    }

    /**
     * Check compliance after clocking out
     *
     * @param TimeEntry $timeEntry
     * @return void
     */
    public function checkComplianceAfterClockOut(TimeEntry $timeEntry): void
    {
        $this->checkMandatoryBreaks($timeEntry);
        $this->checkExcessiveWorkingHours($timeEntry);
        $this->checkNightWork($timeEntry);
        $this->checkSundayAndHolidayWork($timeEntry);
        
        // Check 6-month average and weekly hours (ArbZG §3) - warnings to manager only
        // These are warnings, not blocking violations
        $this->checkSixMonthAverageAndWeeklyHours($timeEntry);
    }

    /**
     * Check compliance for a completed time entry (real-time check)
     * 
     * This method is called immediately when a time entry is completed (status = COMPLETED).
     * It performs all compliance checks and creates violations if necessary.
     * 
     * Based on industry best practices (Personio, Flintec, etc.), real-time compliance
     * checking ensures immediate detection of violations and proactive compliance management.
     * 
     * @param TimeEntry $timeEntry The completed time entry to check
     * @param bool $strictMode If true, throws exception on critical violations (prevents saving)
     * @return array Array of detected violations (empty if compliant)
     * @throws \Exception If strict mode is enabled and critical violations are found
     */
    public function checkComplianceForCompletedEntry(TimeEntry $timeEntry, bool $strictMode = false): array
    {
        // Only check completed entries with end time
        if ($timeEntry->getStatus() !== TimeEntry::STATUS_COMPLETED || !$timeEntry->getEndTime()) {
            return [];
        }

        $violations = [];
        $criticalViolations = [];

        // Check mandatory breaks (ArbZG §4)
        $breakViolations = $this->checkMandatoryBreaksWithResult($timeEntry);
        if (!empty($breakViolations)) {
            $violations = array_merge($violations, $breakViolations);
            $criticalViolations = array_merge($criticalViolations, array_filter($breakViolations, fn($v) => $v['severity'] === ComplianceViolation::SEVERITY_ERROR));
        }

        // Check excessive working hours (ArbZG §3)
        $hoursViolations = $this->checkExcessiveWorkingHoursWithResult($timeEntry);
        if (!empty($hoursViolations)) {
            $violations = array_merge($violations, $hoursViolations);
            $criticalViolations = array_merge($criticalViolations, array_filter($hoursViolations, fn($v) => $v['severity'] === ComplianceViolation::SEVERITY_ERROR));
        }

        // Check night work (ArbZG §6) - informational
        $this->checkNightWork($timeEntry);

        // Check Sunday and holiday work (ArbZG §9) - warnings
        $this->checkSundayAndHolidayWork($timeEntry);

        // Check 6-month average and weekly hours (ArbZG §3) - warnings to manager only
        // These are warnings, not blocking violations
        $this->checkSixMonthAverageAndWeeklyHours($timeEntry);

        // In strict mode, throw exception if critical violations found
        if ($strictMode && !empty($criticalViolations)) {
            $firstCritical = reset($criticalViolations);
            throw new \Exception($firstCritical['message']);
        }

        return $violations;
    }

    /**
     * Check 6-month average and weekly hours (ArbZG §3)
     * Sends warnings to manager if limits are exceeded (non-blocking)
     * 
     * @param TimeEntry $timeEntry
     * @return void
     */
    private function checkSixMonthAverageAndWeeklyHours(TimeEntry $timeEntry): void
    {
        if (!$timeEntry->getEndTime()) {
            return; // Only check completed entries
        }

        $userId = $timeEntry->getUserId();
        $entryDate = clone $timeEntry->getEndTime();
        $entryDate->setTime(0, 0, 0);
        $todayKey = $entryDate->format('Y-m-d');

        // Check if we already sent a warning today (to avoid spam)
        // Use a simple cache key based on date
        static $warningsSentToday = [];
        $cacheKey = $userId . '_' . $todayKey;

        // Check 6-month average (for 10-hour days)
        $workingHours = $timeEntry->getWorkingDurationHours();
        if ($workingHours !== null && $workingHours >= 8.0) {
            // Only check if working 8+ hours (approaching 10-hour limit)
            $sixMonthCheck = $this->checkSixMonthAverage($userId, $entryDate);
            if (!$sixMonthCheck['valid'] && !isset($warningsSentToday[$cacheKey . '_6month'])) {
                // Send warning to manager (non-blocking)
                if ($this->notificationService) {
                    $this->notificationService->notifyManagerWorkingTimeWarning($userId, 'six_month_average', [
                        'message' => $sixMonthCheck['message'],
                        'current_value' => $sixMonthCheck['average'],
                        'limit' => $sixMonthCheck['limit'],
                        'date' => $todayKey
                    ]);
                }
                $warningsSentToday[$cacheKey . '_6month'] = true;
            }
        }

        // Check weekly hours average
        $weeklyCheck = $this->checkWeeklyHoursAverage($userId, $entryDate);
        if (!$weeklyCheck['valid'] && !isset($warningsSentToday[$cacheKey . '_weekly'])) {
            // Send warning to manager (non-blocking)
            if ($this->notificationService) {
                $this->notificationService->notifyManagerWorkingTimeWarning($userId, 'weekly_hours', [
                    'message' => $weeklyCheck['message'],
                    'current_value' => $weeklyCheck['average'],
                    'limit' => $weeklyCheck['limit'],
                    'date' => $todayKey
                ]);
            }
            $warningsSentToday[$cacheKey . '_weekly'] = true;
        }
    }

    /**
     * Check mandatory breaks and return violations as array
     * 
     * @param TimeEntry $timeEntry
     * @return array Array of violation information
     */
    private function checkMandatoryBreaksWithResult(TimeEntry $timeEntry): array
    {
        $violations = [];
        $duration = $timeEntry->getDurationHours();
        $breakDuration = $timeEntry->getBreakDurationHours();

        if ($duration >= 6 && $breakDuration < 0.5) { // 30 minutes break required
            $violation = $this->violationMapper->createViolation(
                $timeEntry->getUserId(),
                ComplianceViolation::TYPE_MISSING_BREAK,
                $this->l10n->t('Mandatory 30-minute break missing after 6 hours of work'),
                $timeEntry->getEndTime() ?: new \DateTime(),
                $timeEntry->getId(),
                ComplianceViolation::SEVERITY_ERROR
            );
            
            $violations[] = [
                'id' => $violation->getId(),
                'type' => ComplianceViolation::TYPE_MISSING_BREAK,
                'severity' => ComplianceViolation::SEVERITY_ERROR,
                'message' => $this->l10n->t('Mandatory 30-minute break missing after 6 hours of work')
            ];
            
            // Send notification
            if ($this->notificationService) {
                $this->notificationService->notifyComplianceViolation($timeEntry->getUserId(), [
                    'id' => $violation->getId(),
                    'type' => ComplianceViolation::TYPE_MISSING_BREAK,
                    'message' => $this->l10n->t('Mandatory 30-minute break missing after 6 hours of work'),
                    'date' => ($timeEntry->getEndTime() ?: new \DateTime())->format('Y-m-d'),
                    'severity' => ComplianceViolation::SEVERITY_ERROR
                ]);
            }
        } elseif ($duration >= 9 && $breakDuration < 0.75) { // 45 minutes break required
            $violation = $this->violationMapper->createViolation(
                $timeEntry->getUserId(),
                ComplianceViolation::TYPE_MISSING_BREAK,
                $this->l10n->t('Mandatory 45-minute break missing after 9 hours of work'),
                $timeEntry->getEndTime() ?: new \DateTime(),
                $timeEntry->getId(),
                ComplianceViolation::SEVERITY_ERROR
            );
            
            $violations[] = [
                'id' => $violation->getId(),
                'type' => ComplianceViolation::TYPE_MISSING_BREAK,
                'severity' => ComplianceViolation::SEVERITY_ERROR,
                'message' => $this->l10n->t('Mandatory 45-minute break missing after 9 hours of work')
            ];
            
            // Send notification
            if ($this->notificationService) {
                $this->notificationService->notifyComplianceViolation($timeEntry->getUserId(), [
                    'id' => $violation->getId(),
                    'type' => ComplianceViolation::TYPE_MISSING_BREAK,
                    'message' => $this->l10n->t('Mandatory 45-minute break missing after 9 hours of work'),
                    'date' => ($timeEntry->getEndTime() ?: new \DateTime())->format('Y-m-d'),
                    'severity' => ComplianceViolation::SEVERITY_ERROR
                ]);
            }
        }

        return $violations;
    }

    /**
     * Check excessive working hours and return violations as array
     * 
     * @param TimeEntry $timeEntry
     * @return array Array of violation information
     */
    private function checkExcessiveWorkingHoursWithResult(TimeEntry $timeEntry): array
    {
        $violations = [];
        // Use working duration (excluding breaks) - this is the actual work time according to ArbZG
        $workingDuration = $timeEntry->getWorkingDurationHours();

        if ($workingDuration !== null && $workingDuration > 10) {
            $violation = $this->violationMapper->createViolation(
                $timeEntry->getUserId(),
                ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS,
                $this->l10n->t('Working hours exceeded 10 hours in a single day'),
                $timeEntry->getEndTime() ?: new \DateTime(),
                $timeEntry->getId(),
                ComplianceViolation::SEVERITY_ERROR
            );
            
            $violations[] = [
                'id' => $violation->getId(),
                'type' => ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS,
                'severity' => ComplianceViolation::SEVERITY_ERROR,
                'message' => $this->l10n->t('Working hours exceeded 10 hours in a single day')
            ];
            
            // Send notification
            if ($this->notificationService) {
                $this->notificationService->notifyComplianceViolation($timeEntry->getUserId(), [
                    'id' => $violation->getId(),
                    'type' => ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS,
                    'message' => $this->l10n->t('Working hours exceeded 10 hours in a single day'),
                    'date' => ($timeEntry->getEndTime() ?: new \DateTime())->format('Y-m-d'),
                    'severity' => ComplianceViolation::SEVERITY_ERROR
                ]);
            }
        }

        return $violations;
    }

    /**
     * Check if minimum rest period is met (11 hours between shifts)
     * 
     * Checks both completed entries (with endTime) and paused entries (with updatedAt as "end time")
     *
     * @param string $userId
     * @return bool
     */
    private function checkRestPeriod(string $userId): bool
    {
        // First check completed entries
        $lastCompletedEntry = $this->getLastCompletedEntry($userId);
        $lastEndTime = $lastCompletedEntry && $lastCompletedEntry->getEndTime() 
            ? $lastCompletedEntry->getEndTime() 
            : null;
        
        // Also check for paused entries (clocked out but not completed)
        // Use updatedAt as the "end time" for rest period calculation
        if (!$lastEndTime) {
            $allEntries = $this->timeEntryMapper->findByUser($userId);
            $lastPausedEntry = null;
            foreach ($allEntries as $entry) {
                if ($entry->getStatus() === TimeEntry::STATUS_PAUSED && $entry->getUpdatedAt() !== null) {
                    if ($lastPausedEntry === null || $entry->getUpdatedAt() > $lastPausedEntry->getUpdatedAt()) {
                        $lastPausedEntry = $entry;
                    }
                }
            }
            if ($lastPausedEntry && $lastPausedEntry->getUpdatedAt()) {
                $lastEndTime = $lastPausedEntry->getUpdatedAt();
            }
        }
        
        if (!$lastEndTime) {
            return true; // No previous entry to check against
        }

        $now = new \DateTime();
        $hoursSinceLastEntry = ($now->getTimestamp() - $lastEndTime->getTimestamp()) / 3600;

        return $hoursSinceLastEntry >= 11;
    }

    /**
     * Check if minimum rest period is met for a specific start time (11 hours between shifts)
     * 
     * This method is used for validating manual time entries before they are saved.
     * It checks if the provided start time violates the 11-hour rest period requirement
     * since the last completed entry's end time.
     *
     * @param string $userId
     * @param \DateTime $startTime The start time to check
     * @param int|null $excludeEntryId Optional: exclude this entry ID from the check (for updates)
     * @return array Array with 'valid' (bool) and 'message' (string) if invalid
     */
    public function checkRestPeriodForStartTime(string $userId, \DateTime $startTime, ?int $excludeEntryId = null): array
    {
        // Get all completed entries and find the one with the latest end time that is BEFORE the start time
        $allEntries = $this->timeEntryMapper->findByUser($userId);
        
        // Find the most recent completed entry that ended BEFORE the start time
        $lastCompletedEntry = null;
        foreach ($allEntries as $entry) {
            // Skip the entry being updated
            if ($excludeEntryId !== null && $entry->getId() === $excludeEntryId) {
                continue;
            }
            
            // Only consider completed entries with end time
            if ($entry->getStatus() !== TimeEntry::STATUS_COMPLETED || !$entry->getEndTime()) {
                continue;
            }
            
            // Only consider entries that ended BEFORE the new start time
            if ($entry->getEndTime() >= $startTime) {
                continue;
            }
            
            // Find the entry with the latest end time
            if ($lastCompletedEntry === null || $entry->getEndTime() > $lastCompletedEntry->getEndTime()) {
                $lastCompletedEntry = $entry;
            }
        }
        
        // If no previous entry found, rest period check is not applicable
        if (!$lastCompletedEntry || !$lastCompletedEntry->getEndTime()) {
            return ['valid' => true, 'message' => null];
        }

        $lastEndTime = $lastCompletedEntry->getEndTime();
        
        // Check if it's the same day
        $lastEndDate = $lastEndTime->format('Y-m-d');
        $startDate = $startTime->format('Y-m-d');
        
        if ($lastEndDate === $startDate) {
            // Same day: No rest period check required (it's a work interruption, not a new shift)
            return ['valid' => true, 'message' => null];
        }
        
        // Check days difference: start date - last end date
        $lastEndDateObj = new \DateTime($lastEndDate);
        $lastEndDateObj->setTime(0, 0, 0);
        $startDateObj = new \DateTime($startDate);
        $startDateObj->setTime(0, 0, 0);
        
        // Calculate difference in days
        $diff = $startDateObj->diff($lastEndDateObj);
        $daysDifference = (int)$diff->format('%r%a'); // %r for sign, %a for absolute days
        
        // If there's at least one full calendar day in between (difference >= 2), rest period is automatically met
        if ($daysDifference >= 2) {
            return ['valid' => true, 'message' => null];
        }
        
        // If difference is negative (shouldn't happen since we filtered entries above)
        if ($daysDifference < 0) {
            return ['valid' => true, 'message' => null];
        }

        // Consecutive days (difference = 1): Check 11-hour rest period (ArbZG §5)
        $hoursSinceLastEntry = ($startTime->getTimestamp() - $lastEndTime->getTimestamp()) / 3600;

        if ($hoursSinceLastEntry >= 11) {
            // Rest period is met
            return ['valid' => true, 'message' => null];
        }
        
        // Rest period not met - calculate earliest possible start time
        $earliestStartTime = clone $lastEndTime;
        $earliestStartTime->modify('+11 hours');
        $hoursStillNeeded = ($earliestStartTime->getTimestamp() - $startTime->getTimestamp()) / 3600;
        
        // Format dates for display
        $lastEndDateFormatted = $lastEndTime->format('d.m.Y');
        $earliestStartDateFormatted = $earliestStartTime->format('d.m.Y H:i');
        
        return [
            'valid' => false,
            'message' => $this->l10n->t(
                'Minimum 11-hour rest period required between shifts (ArbZG §5). Your last shift ended on %s at %s. This entry cannot start before %s (%.1f hours required).',
                [
                    $lastEndDateFormatted,
                    $lastEndTime->format('H:i'),
                    $earliestStartDateFormatted,
                    abs($hoursStillNeeded)
                ]
            )
        ];
    }

    /**
     * Check daily working hours limit (max 10 hours)
     *
     * @param string $userId
     * @return bool
     */
    private function checkDailyWorkingHoursLimit(string $userId): bool
    {
        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        $tomorrow = clone $today;
        $tomorrow->modify('+1 day');

        $todayHours = $this->timeEntryMapper->getTotalHoursByUserAndDateRange($userId, $today, $tomorrow);

        return $todayHours < 10; // Allow clocking in if under 10 hours
    }

    /**
     * Check weekly working hours average (max 48 hours over 6 months)
     *
     * @param string $userId
     * @return bool
     */
    private function checkWeeklyWorkingHoursLimit(string $userId): bool
    {
        $sixMonthsAgo = new \DateTime();
        $sixMonthsAgo->modify('-6 months');

        $totalHours = $this->timeEntryMapper->getTotalHoursByUserAndDateRange(
            $userId,
            $sixMonthsAgo,
            new \DateTime()
        );

        $weeksWorked = 26; // Approximate weeks in 6 months
        $averageWeeklyHours = $totalHours / $weeksWorked;

        return $averageWeeklyHours <= 48;
    }

    /**
     * Check 6-month average daily working hours (ArbZG §3)
     * 
     * 10-hour days are only allowed if the 6-month average does not exceed 8 hours per day.
     * 
     * @param string $userId
     * @param \DateTime $entryDate The date of the entry to check
     * @return array Array with 'valid' (bool), 'message' (string|null), 'average' (float), 'limit' (float)
     */
    private function checkSixMonthAverage(string $userId, \DateTime $entryDate): array
    {
        $sixMonthsAgo = clone $entryDate;
        $sixMonthsAgo->modify('-6 months');
        
        // Get total hours worked in the last 6 months
        $totalHours = $this->timeEntryMapper->getTotalHoursByUserAndDateRange(
            $userId,
            $sixMonthsAgo,
            $entryDate
        );
        
        // Calculate number of working days (excluding weekends and holidays)
        // For simplicity, we'll use approximate: 6 months = ~130 working days (5 days/week * 26 weeks)
        // More accurate would be to count actual working days, but this is acceptable for a warning check
        $approximateWorkingDays = 130;
        
        // Calculate average daily working hours
        $averageDailyHours = $approximateWorkingDays > 0 ? $totalHours / $approximateWorkingDays : 0;
        
        $limit = 8.0; // ArbZG §3: 6-month average must not exceed 8 hours/day for 10-hour days to be allowed
        
        if ($averageDailyHours > $limit) {
            return [
                'valid' => false,
                'message' => $this->l10n->t(
                    'Warning: 6-month average working hours (%.2f h/day) exceeds 8 hours/day. 10-hour days are only allowed if the average does not exceed 8 hours (ArbZG §3).',
                    [$averageDailyHours]
                ),
                'average' => $averageDailyHours,
                'limit' => $limit
            ];
        }
        
        return [
            'valid' => true,
            'message' => null,
            'average' => $averageDailyHours,
            'limit' => $limit
        ];
    }

    /**
     * Check weekly hours average over 6 months (ArbZG §3)
     * 
     * Average weekly working hours over 6 months must not exceed 48 hours.
     * 
     * @param string $userId
     * @param \DateTime $entryDate The date of the entry to check
     * @return array Array with 'valid' (bool), 'message' (string|null), 'average' (float), 'limit' (float)
     */
    private function checkWeeklyHoursAverage(string $userId, \DateTime $entryDate): array
    {
        $sixMonthsAgo = clone $entryDate;
        $sixMonthsAgo->modify('-6 months');
        
        // Get total hours worked in the last 6 months
        $totalHours = $this->timeEntryMapper->getTotalHoursByUserAndDateRange(
            $userId,
            $sixMonthsAgo,
            $entryDate
        );
        
        // Calculate number of weeks (approximately 26 weeks in 6 months)
        $weeks = 26;
        $averageWeeklyHours = $weeks > 0 ? $totalHours / $weeks : 0;
        
        $limit = 48.0; // ArbZG §3: Average weekly hours must not exceed 48 hours over 6 months
        
        if ($averageWeeklyHours > $limit) {
            return [
                'valid' => false,
                'message' => $this->l10n->t(
                    'Warning: 6-month average weekly working hours (%.2f h/week) exceeds 48 hours/week (ArbZG §3).',
                    [$averageWeeklyHours]
                ),
                'average' => $averageWeeklyHours,
                'limit' => $limit
            ];
        }
        
        return [
            'valid' => true,
            'message' => null,
            'average' => $averageWeeklyHours,
            'limit' => $limit
        ];
    }

    /**
     * Check mandatory breaks in time entry
     *
     * @param TimeEntry $timeEntry
     * @return void
     */
    private function checkMandatoryBreaks(TimeEntry $timeEntry): void
    {
        $duration = $timeEntry->getDurationHours();
        $breakDuration = $timeEntry->getBreakDurationHours();

        if ($duration >= 6 && $breakDuration < 0.5) { // 30 minutes break required
            $violation = $this->violationMapper->createViolation(
                $timeEntry->getUserId(),
                ComplianceViolation::TYPE_MISSING_BREAK,
                $this->l10n->t('Mandatory 30-minute break missing after 6 hours of work'),
                $timeEntry->getEndTime() ?: new \DateTime(),
                $timeEntry->getId(),
                ComplianceViolation::SEVERITY_ERROR
            );
            
            // Send notification
            if ($this->notificationService) {
                $this->notificationService->notifyComplianceViolation($timeEntry->getUserId(), [
                    'id' => $violation->getId(),
                    'type' => ComplianceViolation::TYPE_MISSING_BREAK,
                    'message' => $this->l10n->t('Mandatory 30-minute break missing after 6 hours of work'),
                    'date' => ($timeEntry->getEndTime() ?: new \DateTime())->format('Y-m-d'),
                    'severity' => ComplianceViolation::SEVERITY_ERROR
                ]);
            }
        } elseif ($duration >= 9 && $breakDuration < 0.75) { // 45 minutes break required
            $violation = $this->violationMapper->createViolation(
                $timeEntry->getUserId(),
                ComplianceViolation::TYPE_MISSING_BREAK,
                $this->l10n->t('Mandatory 45-minute break missing after 9 hours of work'),
                $timeEntry->getEndTime() ?: new \DateTime(),
                $timeEntry->getId(),
                ComplianceViolation::SEVERITY_ERROR
            );
            
            // Send notification
            if ($this->notificationService) {
                $this->notificationService->notifyComplianceViolation($timeEntry->getUserId(), [
                    'id' => $violation->getId(),
                    'type' => ComplianceViolation::TYPE_MISSING_BREAK,
                    'message' => $this->l10n->t('Mandatory 45-minute break missing after 9 hours of work'),
                    'date' => ($timeEntry->getEndTime() ?: new \DateTime())->format('Y-m-d'),
                    'severity' => ComplianceViolation::SEVERITY_ERROR
                ]);
            }
        }
    }

    /**
     * Check for excessive working hours (over 10 hours in a day)
     *
     * @param TimeEntry $timeEntry
     * @return void
     */
    private function checkExcessiveWorkingHours(TimeEntry $timeEntry): void
    {
        // Use working duration (excluding breaks) - this is the actual work time according to ArbZG
        $workingDuration = $timeEntry->getWorkingDurationHours();

        if ($workingDuration !== null && $workingDuration > 10) {
            $violation = $this->violationMapper->createViolation(
                $timeEntry->getUserId(),
                ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS,
                $this->l10n->t('Working hours exceeded 10 hours in a single day'),
                $timeEntry->getEndTime() ?: new \DateTime(),
                $timeEntry->getId(),
                ComplianceViolation::SEVERITY_ERROR
            );
            
            // Send notification
            if ($this->notificationService) {
                $this->notificationService->notifyComplianceViolation($timeEntry->getUserId(), [
                    'id' => $violation->getId(),
                    'type' => ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS,
                    'message' => $this->l10n->t('Working hours exceeded 10 hours in a single day'),
                    'date' => ($timeEntry->getEndTime() ?: new \DateTime())->format('Y-m-d'),
                    'severity' => ComplianceViolation::SEVERITY_ERROR
                ]);
            }
        }
    }

    /**
     * Check for night work (11 PM - 6 AM)
     *
     * @param TimeEntry $timeEntry
     * @return void
     */
    private function checkNightWork(TimeEntry $timeEntry): void
    {
        $startTime = $timeEntry->getStartTime();
        $endTime = $timeEntry->getEndTime();

        if (!$endTime) {
            return;
        }

        $startHour = (int)$startTime->format('G');
        $endHour = (int)$endTime->format('G');

        // Check if work spans night hours (23:00 - 06:00)
        $isNightWork = ($startHour >= 23 || $startHour <= 5) || ($endHour >= 23 || $endHour <= 5);

        if ($isNightWork) {
            $nightHours = $this->calculateNightHours($startTime, $endTime);

            $this->violationMapper->createViolation(
                $timeEntry->getUserId(),
                ComplianceViolation::TYPE_NIGHT_WORK,
                sprintf($this->l10n->t('Night work detected: %.2f hours between 11 PM and 6 AM'), $nightHours),
                $timeEntry->getEndTime(),
                $timeEntry->getId(),
                ComplianceViolation::SEVERITY_INFO
            );
        }
    }

    /**
     * Check for Sunday and holiday work
     *
     * @param TimeEntry $timeEntry
     * @return void
     */
    private function checkSundayAndHolidayWork(TimeEntry $timeEntry): void
    {
        $startTime = $timeEntry->getStartTime();
        $endTime = $timeEntry->getEndTime();

        if (!$endTime) {
            return;
        }

        // Check if work was done on Sunday
        $isSunday = (int)$startTime->format('w') === 0;

        if ($isSunday) {
            $this->violationMapper->createViolation(
                $timeEntry->getUserId(),
                ComplianceViolation::TYPE_SUNDAY_WORK,
                $this->l10n->t('Work performed on Sunday'),
                $timeEntry->getStartTime(),
                $timeEntry->getId(),
                ComplianceViolation::SEVERITY_WARNING
            );
        }

        // Check if work was done on public holiday
        $isHoliday = $this->isGermanPublicHoliday($startTime);

        if ($isHoliday) {
            $this->violationMapper->createViolation(
                $timeEntry->getUserId(),
                ComplianceViolation::TYPE_HOLIDAY_WORK,
                $this->l10n->t('Work performed on public holiday'),
                $timeEntry->getStartTime(),
                $timeEntry->getId(),
                ComplianceViolation::SEVERITY_WARNING
            );
        }
    }

    /**
     * Calculate night hours worked (between 23:00 and 06:00)
     *
     * @param \DateTime $start
     * @param \DateTime $end
     * @return float
     */
    private function calculateNightHours(\DateTime $start, \DateTime $end): float
    {
        $nightStart = clone $start;
        $nightEnd = clone $start;

        // Set night time boundaries for the start date
        $nightStart->setTime(23, 0, 0);
        $nightEnd->setTime(6, 0, 0);
        $nightEnd->modify('+1 day');

        // If the work period doesn't overlap with night hours, return 0
        if ($end <= $nightStart || $start >= $nightEnd) {
            return 0.0;
        }

        // Calculate overlap
        $overlapStart = max($start, $nightStart);
        $overlapEnd = min($end, $nightEnd);

        if ($overlapEnd <= $overlapStart) {
            return 0.0;
        }

        return ($overlapEnd->getTimestamp() - $overlapStart->getTimestamp()) / 3600;
    }

    /**
     * Check if a date is a German public holiday
     *
     * @param \DateTime $date
     * @param string|null $state German state code (e.g., 'NW' for Nordrhein-Westfalen)
     * @return bool
     */
    public function isGermanPublicHoliday(\DateTime $date, ?string $state = null): bool
    {
        $year = (int)$date->format('Y');
        $dateString = $date->format('m-d');

        // Use default state if not specified (could be configurable per user/company)
        $state = $state ?: 'NW'; // Default to Nordrhein-Westfalen

        if (!isset(self::GERMAN_PUBLIC_HOLIDAYS[$state])) {
            $state = 'NW'; // Fallback to NRW
        }

        $holidays = self::GERMAN_PUBLIC_HOLIDAYS[$state];

        // Check fixed holidays
        if (in_array($dateString, $holidays)) {
            return true;
        }

        // Check variable holidays (Easter-based)
        $variableHolidays = $this->calculateVariableHolidays($year);

        foreach ($variableHolidays as $holiday) {
            if ($holiday === $dateString) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate variable German public holidays (Easter-based)
     *
     * @param int $year
     * @return array
     */
    private function calculateVariableHolidays(int $year): array
    {
        // Calculate Easter date using Gauss algorithm (simplified)
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        $easterDate = new \DateTime("$year-$month-$day");

        // Calculate holidays based on Easter
        $goodFriday = clone $easterDate;
        $goodFriday->modify('-2 days');

        $easterMonday = clone $easterDate;
        $easterMonday->modify('+1 day');

        $ascensionDay = clone $easterDate;
        $ascensionDay->modify('+39 days');

        $whitMonday = clone $easterDate;
        $whitMonday->modify('+50 days');

        $corpusChristi = clone $easterDate;
        $corpusChristi->modify('+60 days');

        return [
            $goodFriday->format('m-d'),
            $easterMonday->format('m-d'),
            $ascensionDay->format('m-d'),
            $whitMonday->format('m-d'),
            $corpusChristi->format('m-d')
        ];
    }

    /**
     * Get last completed time entry for a user
     *
     * @param string $userId
     * @return TimeEntry|null
     */
    private function getLastCompletedEntry(string $userId): ?TimeEntry
    {
        $allEntries = $this->timeEntryMapper->findByUser($userId);
        
        // Find the most recent completed entry (by end time)
        $lastCompletedEntry = null;
        foreach ($allEntries as $entry) {
            if ($entry->getStatus() === TimeEntry::STATUS_COMPLETED && $entry->getEndTime() !== null) {
                if ($lastCompletedEntry === null || $entry->getEndTime() > $lastCompletedEntry->getEndTime()) {
                    $lastCompletedEntry = $entry;
                }
            }
        }
        
        return $lastCompletedEntry;
    }

    /**
     * Run daily compliance check for all users
     *
     * This method should be called by a Nextcloud cron job to check all users
     * for compliance violations on a daily basis.
     *
     * @return array Statistics about the compliance check
     */
    public function runDailyComplianceCheck(): array
    {
        $yesterday = new \DateTime();
        $yesterday->modify('-1 day');
        $yesterday->setTime(0, 0, 0);
        $today = new \DateTime();
        $today->setTime(0, 0, 0);

        $stats = [
            'users_checked' => 0,
            'violations_found' => 0,
            'check_date' => $yesterday->format('Y-m-d')
        ];

        // Iterate through all users
        $this->userManager->callForAllUsers(function ($user) use ($yesterday, $today, &$stats) {
            $userId = $user->getUID();
            $stats['users_checked']++;

            // Count existing violations for this user from yesterday before checks
            $violationsBefore = $this->violationMapper->findByDateRange($yesterday, $today, $userId);
            $violationCountBefore = count($violationsBefore);

            // Get all time entries from yesterday
            $entries = $this->timeEntryMapper->findByUserAndDateRange($userId, $yesterday, $today);

            // Check compliance for each completed entry
            foreach ($entries as $entry) {
                if ($entry->getStatus() === TimeEntry::STATUS_COMPLETED && $entry->getEndTime() !== null) {
                    // Check if violations already exist for this entry
                    $hasExistingViolation = false;
                    foreach ($violationsBefore as $existing) {
                        if ($existing->getTimeEntryId() === $entry->getId()) {
                            $hasExistingViolation = true;
                            break;
                        }
                    }

                    // Only check if no violations exist yet for this entry
                    if (!$hasExistingViolation) {
                        $this->checkMandatoryBreaks($entry);
                        $this->checkExcessiveWorkingHours($entry);
                        $this->checkNightWork($entry);
                        $this->checkSundayAndHolidayWork($entry);
                    }
                }
            }

            // Check for weekly hours limit violations
            $weeklyHoursCheck = $this->checkWeeklyWorkingHoursLimit($userId);
            if (!$weeklyHoursCheck) {
                // Create violation if not already exists for this period
                $weekStart = clone $yesterday;
                $weekStart->modify('monday this week');
                $weekEnd = clone $weekStart;
                $weekEnd->modify('+7 days');

                $existingWeeklyViolations = $this->violationMapper->findByDateRange($weekStart, $weekEnd, $userId);
                $hasWeeklyViolation = false;
                foreach ($existingWeeklyViolations as $existing) {
                    if ($existing->getViolationType() === ComplianceViolation::TYPE_WEEKLY_HOURS_LIMIT_EXCEEDED) {
                        $hasWeeklyViolation = true;
                        break;
                    }
                }

                if (!$hasWeeklyViolation) {
                    $this->violationMapper->createViolation(
                        $userId,
                        ComplianceViolation::TYPE_WEEKLY_HOURS_LIMIT_EXCEEDED,
                        $this->l10n->t('Weekly working hours average limit (48 hours) exceeded over the last 6 months'),
                        $yesterday,
                        null,
                        ComplianceViolation::SEVERITY_WARNING
                    );
                }
            }

            // Count violations after checks to see how many were created
            $violationsAfter = $this->violationMapper->findByDateRange($yesterday, $today, $userId);
            $violationCountAfter = count($violationsAfter);
            $newViolations = $violationCountAfter - $violationCountBefore;
            $stats['violations_found'] += $newViolations;
        });

        return $stats;
    }

    /**
     * Get compliance status for a user
     *
     * @param string $userId
     * @return array{compliant: bool, score: int, violation_count: int, critical_violations: int, warning_violations: int, info_violations: int, has_data: bool, last_check: \DateTime}
     */
    public function getComplianceStatus(string $userId): array
    {
        $unresolvedViolations = $this->violationMapper->findByUser($userId, false);

        $critical = 0;
        $warning = 0;
        $info = 0;
        foreach ($unresolvedViolations as $violation) {
            switch ($violation->getSeverity()) {
                case ComplianceViolation::SEVERITY_ERROR:
                    $critical++;
                    break;
                case ComplianceViolation::SEVERITY_WARNING:
                    $warning++;
                    break;
                case ComplianceViolation::SEVERITY_INFO:
                    $info++;
                    break;
            }
        }

        $compliant = empty($unresolvedViolations);

        // Score: 100 = perfect, reduced by severity-weighted violations (max -100)
        $score = 100;
        $score -= min(100, ($critical * 25) + ($warning * 10) + ($info * 5));

        // Check if we have analyzable data (time entries exist)
        $timeEntryCount = $this->timeEntryMapper->countByUser($userId);
        $hasData = $timeEntryCount > 0;

        return [
            'compliant' => $compliant,
            'score' => max(0, $score),
            'violation_count' => count($unresolvedViolations),
            'critical_violations' => $critical,
            'warning_violations' => $warning,
            'info_violations' => $info,
            'has_data' => $hasData,
            'last_check' => new \DateTime()
        ];
    }

    /**
     * Generate compliance report for a date range
     *
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @param string|null $userId
     * @return array
     */
    public function generateComplianceReport(\DateTime $startDate, \DateTime $endDate, ?string $userId = null): array
    {
        $violations = $this->violationMapper->findByDateRange($startDate, $endDate, $userId);

        $report = [
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d')
            ],
            'total_violations' => count($violations),
            'violations_by_type' => [],
            'violations_by_severity' => [],
            'violations_by_user' => [],
            'compliance_rate' => 0,
            'generated_at' => new \DateTime()
        ];

        foreach ($violations as $violation) {
            // Group by type
            $type = $violation->getViolationType();
            if (!isset($report['violations_by_type'][$type])) {
                $report['violations_by_type'][$type] = 0;
            }
            $report['violations_by_type'][$type]++;

            // Group by severity
            $severity = $violation->getSeverity();
            if (!isset($report['violations_by_severity'][$severity])) {
                $report['violations_by_severity'][$severity] = 0;
            }
            $report['violations_by_severity'][$severity]++;

            // Group by user
            $user = $violation->getUserId();
            if (!isset($report['violations_by_user'][$user])) {
                $report['violations_by_user'][$user] = 0;
            }
            $report['violations_by_user'][$user]++;
        }

        return $report;
    }
}