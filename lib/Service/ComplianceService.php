<?php

declare(strict_types=1);

/**
 * Compliance service for DACH working-time law (DE ArbZG, AT AZG/ARG, CH ArG)
 * and GDPR requirements. Country-specific limits come from LaborLawProfile
 * (instance country, or optional per-user labor_law_country override — E-9).
 * Explicitly configured admin values still override profile defaults.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Support\BreakPolicyResolver;
use OCA\ArbeitszeitCheck\Support\BreakSplitValidator;
use OCA\ArbeitszeitCheck\Support\LaborLawProfile;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\SundayWorkPolicy;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Compliance service implementing working-time law requirements (DE/AT)
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
    private HolidayService $holidayCalendarService;
    private IConfig $config;
    private PermissionService $permissionService;
    private TimeZoneService $timeZoneService;
    private DailyWorkingHoursCalculator $dailyWorkingHoursCalculator;
    private LaborLawProfileFactory $lawProfileFactory;
    private ?BreakPolicyResolver $breakPolicyResolver;
    private ?AbsenceMapper $absenceMapper;

    public function __construct(
        TimeEntryMapper $timeEntryMapper,
        ComplianceViolationMapper $violationMapper,
        WorkingTimeModelMapper $workingTimeModelMapper,
        UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
        IUserManager $userManager,
        IL10N $l10n,
        ?NotificationService $notificationService,
        HolidayService $holidayCalendarService,
        IConfig $config,
        PermissionService $permissionService,
        TimeZoneService $timeZoneService,
        DailyWorkingHoursCalculator $dailyWorkingHoursCalculator,
        LaborLawProfileFactory $lawProfileFactory,
        ?BreakPolicyResolver $breakPolicyResolver = null,
        ?AbsenceMapper $absenceMapper = null,
    ) {
        $this->timeEntryMapper = $timeEntryMapper;
        $this->violationMapper = $violationMapper;
        $this->workingTimeModelMapper = $workingTimeModelMapper;
        $this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
        $this->userManager = $userManager;
        $this->l10n = $l10n;
        $this->notificationService = $notificationService;
        $this->holidayCalendarService = $holidayCalendarService;
        $this->config = $config;
        $this->permissionService = $permissionService;
        $this->timeZoneService = $timeZoneService;
        $this->dailyWorkingHoursCalculator = $dailyWorkingHoursCalculator;
        $this->lawProfileFactory = $lawProfileFactory;
        $this->breakPolicyResolver = $breakPolicyResolver;
        $this->absenceMapper = $absenceMapper;
    }

    private function breakPolicy(): BreakPolicyResolver
    {
        if ($this->breakPolicyResolver === null) {
            try {
                $this->breakPolicyResolver = \OCP\Server::get(BreakPolicyResolver::class);
            } catch (\Throwable) {
                $this->breakPolicyResolver = new BreakPolicyResolver(
                    $this->config,
                    $this->lawProfileFactory,
                    \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
                    $this->userWorkingTimeModelMapper,
                    $this->workingTimeModelMapper,
                    $this->timeEntryMapper,
                    $this->timeZoneService,
                    $this->l10n,
                );
            }
        }
        return $this->breakPolicyResolver;
    }

    private function absenceMapper(): ?AbsenceMapper
    {
        if ($this->absenceMapper === null) {
            try {
                $this->absenceMapper = \OCP\Server::get(AbsenceMapper::class);
            } catch (\Throwable) {
                return null;
            }
        }
        return $this->absenceMapper;
    }

    private function profile(?string $userId = null): LaborLawProfile
    {
        return $this->lawProfileFactory->getProfile($userId);
    }

    /**
     * Render a stored DateTime as HH:MM in the affected user's display TZ.
     * Falls back to the storage TZ when no user is known so the value still
     * reflects the persisted civil time, never the container's UTC offset.
     */
    private function displayClock(\DateTimeInterface $dt, ?string $userId = null): string
    {
        return $this->timeZoneService->formatForDisplay($dt, 'H:i', $userId);
    }

    /**
     * Render a stored DateTime as `d.m.Y` in the affected user's display TZ.
     */
    private function displayDate(\DateTimeInterface $dt, ?string $userId = null): string
    {
        return $this->timeZoneService->formatForDisplay($dt, 'd.m.Y', $userId);
    }

    private function getMaxDailyHours(?string $userId = null): float
    {
        $default = (string)$this->profile($userId)->dailyMaxHoursDefault;

        return max(1.0, min(24.0, (float)$this->config->getAppValue('arbeitszeitcheck', 'max_daily_hours', $default)));
    }

    private function getMinRestPeriod(?string $userId = null): float
    {
        $default = (string)$this->profile($userId)->minRestHoursDefault;

        return max(1.0, min(24.0, (float)$this->config->getAppValue('arbeitszeitcheck', 'min_rest_period', $default)));
    }

    /**
     * First break tier whose exclusive threshold the **calendar-day** net hours
     * crossed but whose required break minutes (and AZG split patterns, when
     * configured) are not met by countable breaks that day — or null when compliant.
     * break minutes (and AZG split patterns, when configured) are not met —
     * or null when the entry is compliant.
     *
     * @return array{afterHours: float, breakMinutes: int, counted_minutes: int, missing_minutes: int, min_break_minutes: int}|null
     */
    private function findViolatedBreakTier(TimeEntry $timeEntry): ?array
    {
        $userId = $timeEntry->getUserId();
        $profile = $this->profile($userId);
        $timeEntry->setCountableMinBreakMinutes($profile->minBreakMinutes);
        $peak = $this->dailyWorkingHoursCalculator->describePeakWorkingDay(
            $userId,
            $timeEntry,
            $timeEntry->getEndTime(),
            $timeEntry->getId(),
        );
        $tier = $profile->matchingBreakTier($peak['hours']);
        if ($tier === null) {
            return null;
        }

        $portions = $this->dailyWorkingHoursCalculator->countableBreakPortionsOnCalendarDay(
            $userId,
            $peak['dayStart'],
            $peak['dayEnd'],
            $timeEntry,
            $profile->minBreakMinutes,
            $timeEntry->getId(),
        );
        $ok = BreakSplitValidator::meetsRequirement(
            $portions,
            (int)$tier['breakMinutes'],
            $profile->allowedBreakSplitPatterns,
        );
        if ($ok) {
            return null;
        }

        // B2: make the block explainable — how much counted and how much is
        // still missing under the countable-break floor.
        $counted = array_sum($portions);
        return $tier + [
            'counted_minutes' => $counted,
            'missing_minutes' => max(0, (int)$tier['breakMinutes'] - $counted),
            'min_break_minutes' => (int)$profile->minBreakMinutes,
        ];
    }

    /**
     * Structured detail map for the §4 save-block response (B2-MH-02) —
     * additive to the human message so clients can render field-level errors.
     *
     * @return array{required_break_minutes: int, counted_break_minutes: int, missing_break_minutes: int, min_break_minutes: int}|null
     */
    public function breakBlockDetailsForCompletedEntry(TimeEntry $timeEntry): ?array
    {
        if ($timeEntry->getStatus() !== TimeEntry::STATUS_COMPLETED || $timeEntry->getEndTime() === null) {
            return null;
        }
        $tier = $this->findViolatedBreakTier($timeEntry);
        if ($tier === null) {
            return null;
        }
        return [
            'required_break_minutes' => (int)$tier['breakMinutes'],
            'counted_break_minutes' => (int)$tier['counted_minutes'],
            'missing_break_minutes' => (int)$tier['missing_minutes'],
            'min_break_minutes' => (int)$tier['min_break_minutes'],
        ];
    }

    private function missingBreakMessage(array $tier, ?string $userId = null): string
    {
        // %2$s (not %2$d): CH ArG Art. 15 uses a 5.5 h threshold — (int)5.5 === 5
        // would lie to employees and auditors. Keep the legacy %2$d msgid unused
        // but present in l10n for one release (parallel keys).
        $base = $this->l10n->t(
            'Mandatory %1$d-minute break missing after %2$s hours of work (%3$s)',
            [
                (int)$tier['breakMinutes'],
                LaborLawProfile::formatHoursLabel((float)$tier['afterHours']),
                $this->profile($userId)->lawLabel('breaks'),
            ]
        );
        // B2-MH-01: explain *why* a recorded break did not count.
        if (isset($tier['counted_minutes'], $tier['missing_minutes'], $tier['min_break_minutes'])) {
            $base .= ' ' . $this->l10n->t(
                'Recorded break: %1$d minutes — %2$d minutes still missing (only breaks of at least %3$d minutes count; the break remains legally required — pay crediting only changes remuneration).',
                [
                    (int)$tier['counted_minutes'],
                    (int)$tier['missing_minutes'],
                    (int)$tier['min_break_minutes'],
                ]
            );
        }
        return $base;
    }

    /**
     * Check compliance before clocking in
     *
     * @param string $userId
     * @return array Array of compliance issues (empty if compliant)
     */
    public function checkComplianceBeforeClockIn(string $userId, ?\DateTimeInterface $at = null): array
    {
        $issues = [];

        // Check rest period (11 hours between shifts) - CRITICAL: Always enforce (ArbZG §5)
        $restEvaluation = $this->evaluateRestPeriodForClockIn($userId, $at);
        if (!$restEvaluation['valid']) {
            $issues[] = [
                'type' => ComplianceViolation::TYPE_INSUFFICIENT_REST_PERIOD,
                'severity' => ComplianceViolation::SEVERITY_ERROR,
                'message' => $restEvaluation['message'],
                'details' => is_array($restEvaluation['details'] ?? null) ? $restEvaluation['details'] : [],
            ];
        }

        // Check daily working hours limit
        if (!$this->checkDailyWorkingHoursLimit($userId)) {
            $maxDaily = (int)$this->getMaxDailyHours($userId);
            $issues[] = [
                'type' => ComplianceViolation::TYPE_DAILY_HOURS_LIMIT_EXCEEDED,
                'severity' => ComplianceViolation::SEVERITY_ERROR,
                'message' => $this->l10n->t('Daily working hours limit reached (%d hours maximum)', [$maxDaily])
            ];
        }

        // Check weekly working hours average (skipped when the country has no
        // averaging rule — CH weeklyAvgMaxHours = null).
        $profile = $this->profile($userId);
        if ($profile->weeklyAvgMaxHours !== null && !$this->checkWeeklyWorkingHoursLimit($userId)) {
            $issues[] = [
                'type' => ComplianceViolation::TYPE_WEEKLY_HOURS_LIMIT_EXCEEDED,
                'severity' => ComplianceViolation::SEVERITY_WARNING,
                'message' => $this->l10n->t(
                    'Weekly working hours average limit (%1$d hours) exceeded (%2$s)',
                    [(int)$profile->weeklyAvgMaxHours, $profile->lawLabel('weekly')]
                )
            ];
        }

        // Absolute weekly maximum (AT 60 h / CH 45|50). Soft warn at clock-in —
        // do not block stamping (same posture as the weekly average), but surface
        // the legal cap so employees and auditors see it before more hours accrue.
        if ($profile->weeklyAbsoluteMaxHours !== null && !$this->checkAbsoluteWeeklyHoursLimit($userId)) {
            $issues[] = [
                'type' => ComplianceViolation::TYPE_WEEKLY_ABSOLUTE_HOURS_EXCEEDED,
                'severity' => ComplianceViolation::SEVERITY_WARNING,
                'message' => $this->l10n->t(
                    'Absolute weekly working hours maximum (%1$d hours) already exceeded (%2$s)',
                    [(int)$profile->weeklyAbsoluteMaxHours, $profile->lawLabel('weekly')]
                ),
            ];
        }

        return $issues;
    }

    /**
     * Check compliance after clocking out.
     *
     * Each individual rule is wrapped in its own try/catch so that a defect in one
     * check (e.g. a malformed translation or an unexpected DB state) cannot stop the
     * remaining checks from running. The caller is also expected to invoke this
     * method OUTSIDE of any clock-out transaction so that — in the worst case — a
     * compliance failure can never roll back the user's clock-out itself.
     *
     * @param TimeEntry $timeEntry
     * @return void
     */
    public function checkComplianceAfterClockOut(TimeEntry $timeEntry): void
    {
        // Ordered list of (label, callable). The label is used for log correlation
        // and never user-visible.
        $checks = [
            'mandatory_breaks'             => fn() => $this->checkMandatoryBreaks($timeEntry),
            'excessive_working_hours'      => fn() => $this->checkExcessiveWorkingHours($timeEntry),
            'night_work'                   => fn() => $this->checkNightWork($timeEntry),
            'sunday_and_holiday_work'      => fn() => $this->checkSundayAndHolidayWork($timeEntry),
            'six_month_and_weekly_average' => fn() => $this->checkSixMonthAverageAndWeeklyHours($timeEntry),
            // Organisational break policy + presence windows — warnings only.
            'break_policy'                 => fn() => $this->checkPolicyViolationsWithResult($timeEntry),
            'presence_windows'             => fn() => $this->presenceViolationsForEntryDays($timeEntry, true),
        ];

        foreach ($checks as $name => $check) {
            try {
                $check();
            } catch (\Throwable $e) {
                \OCP\Log\logger('arbeitszeitcheck')->error(
                    'Compliance check "' . $name . '" failed for entry ' . (int)$timeEntry->getId() . ': ' . $e->getMessage(),
                    [
                        'exception' => $e,
                        'user_id'   => $timeEntry->getUserId(),
                        'entry_id'  => $timeEntry->getId(),
                        'check'     => $name,
                    ]
                );
                // Intentionally swallow: continue with the remaining checks so that
                // one buggy check never silences the others.
            }
        }
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
    public function checkComplianceForCompletedEntry(TimeEntry $timeEntry, bool $strictMode = false, bool $persistViolations = true): array
    {
        // Only check completed entries with end time
        if ($timeEntry->getStatus() !== TimeEntry::STATUS_COMPLETED || !$timeEntry->getEndTime()) {
            return [];
        }

        $violations = [];
        $criticalViolations = [];

        // Check mandatory breaks (ArbZG §4)
        $breakViolations = $this->checkMandatoryBreaksWithResult($timeEntry, $persistViolations);
        if (!empty($breakViolations)) {
            $violations = array_merge($violations, $breakViolations);
            $criticalViolations = array_merge($criticalViolations, array_filter($breakViolations, fn($v) => $v['severity'] === ComplianceViolation::SEVERITY_ERROR));
        }

        // Check excessive working hours (ArbZG §3)
        $hoursViolations = $this->checkExcessiveWorkingHoursWithResult($timeEntry, $persistViolations);
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

        // Organisational break policy + mandatory presence windows.
        // WARNING-only path — never added to $criticalViolations so it can
        // never feed the statutory save-blocking gate (NN-10).
        try {
            $policyViolations = $this->checkPolicyViolationsWithResult($timeEntry, $persistViolations);
            if (!empty($policyViolations)) {
                $violations = array_merge($violations, $policyViolations);
            }
        } catch (\Throwable $e) {
            \OCP\Log\logger('arbeitszeitcheck')->error(
                'Break-policy check failed for entry ' . (int)$timeEntry->getId() . ': ' . $e->getMessage(),
                ['exception' => $e, 'user_id' => $timeEntry->getUserId()]
            );
        }
        try {
            $presenceViolations = $this->presenceViolationsForEntryDays($timeEntry, $persistViolations);
            if (!empty($presenceViolations)) {
                $violations = array_merge($violations, $presenceViolations);
            }
        } catch (\Throwable $e) {
            \OCP\Log\logger('arbeitszeitcheck')->error(
                'Presence-window check failed for entry ' . (int)$timeEntry->getId() . ': ' . $e->getMessage(),
                ['exception' => $e, 'user_id' => $timeEntry->getUserId()]
            );
        }

        // In strict mode, throw exception if critical violations found
        if ($strictMode && !empty($criticalViolations)) {
            $firstCritical = reset($criticalViolations);
            throw new \Exception($firstCritical['message']);
        }

        return $violations;
    }

    /**
     * Pre-save compliance gate for employee/API completed entries (no violation rows written).
     *
     * ArbZG §4 mandatory breaks are always blocking for portal saves.
     * Additional critical checks run when strict mode is enabled.
     *
     * @return list<string> Human-readable blocking messages (empty if savable)
     */
    public function blockingIssuesForCompletedEntry(TimeEntry $timeEntry, bool $strictMode = false): array
    {
        if ($timeEntry->getStatus() !== TimeEntry::STATUS_COMPLETED || $timeEntry->getEndTime() === null) {
            return [];
        }

        $issues = [];
        $tier = $this->findViolatedBreakTier($timeEntry);
        if ($tier !== null) {
            $issues[] = $this->missingBreakMessage($tier, $timeEntry->getUserId());
        }

        return $issues;
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

        // Daily-average gate (DE only: ArbZG §3 allows 10-hour days only while
        // the averaging-window daily average stays ≤ 8 h). Countries without
        // that rule (AT/CH) have dailyAvgMaxHours = null and skip this check.
        $profile = $this->profile($userId);
        $dailyAvgLimit = $profile->dailyAvgMaxHours;
        $workingHours = $timeEntry->getWorkingDurationHours();
        if ($dailyAvgLimit !== null && $workingHours !== null && $workingHours >= $dailyAvgLimit) {
            // Only check when the day approaches the daily maximum
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

        // Absolute weekly maximum (AT: 60 h; CH: 45/50; DE: null skips).
        // Persist a first-class violation for the calendar week (deduped) and
        // notify the manager once per day — soft path must not be notify-only.
        if ($profile->weeklyAbsoluteMaxHours !== null) {
            $absoluteCheck = $this->checkAbsoluteWeeklyHours($userId, $entryDate);
            if (!$absoluteCheck['valid']) {
                $this->ensureAbsoluteWeeklyViolation(
                    $userId,
                    $entryDate,
                    (string)$absoluteCheck['message'],
                    null
                );
                if (!isset($warningsSentToday[$cacheKey . '_weekabs'])) {
                    if ($this->notificationService) {
                        $this->notificationService->notifyManagerWorkingTimeWarning($userId, 'weekly_hours_absolute', [
                            'message' => $absoluteCheck['message'],
                            'current_value' => $absoluteCheck['average'],
                            'limit' => $absoluteCheck['limit'],
                            'date' => $todayKey
                        ]);
                    }
                    $warningsSentToday[$cacheKey . '_weekabs'] = true;
                }
            }
        }

        // Check weekly hours average (DE/AT). Countries without an averaging
        // window (CH) set weeklyAvgMaxHours = null and skip.
        if ($profile->weeklyAvgMaxHours !== null) {
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
    }

    /**
     * Check mandatory breaks and return violations as array
     * 
     * @param TimeEntry $timeEntry
     * @return array Array of violation information
     */
    private function checkMandatoryBreaksWithResult(TimeEntry $timeEntry, bool $persistViolations = true): array
    {
        // Break requirements per country profile (DE ArbZG §4: 9h→45min, 6h→30min;
        // AT AZG §11: 6h→30min). Highest violated tier only.
        $tier = $this->findViolatedBreakTier($timeEntry);
        if ($tier === null) {
            return [];
        }

        $message = $this->missingBreakMessage($tier, $timeEntry->getUserId());
        if (!$persistViolations) {
            return [[
                'type' => ComplianceViolation::TYPE_MISSING_BREAK,
                'severity' => ComplianceViolation::SEVERITY_ERROR,
                'message' => $message,
            ]];
        }

        $violation = $this->violationMapper->createViolation(
            $timeEntry->getUserId(),
            ComplianceViolation::TYPE_MISSING_BREAK,
            $message,
            $timeEntry->getEndTime() ?: new \DateTime(),
            $timeEntry->getId(),
            ComplianceViolation::SEVERITY_ERROR
        );

        // Send notification
        if ($this->notificationService) {
            $this->notificationService->notifyComplianceViolation($timeEntry->getUserId(), [
                'id' => $violation->getId(),
                'type' => ComplianceViolation::TYPE_MISSING_BREAK,
                'message' => $message,
                'date' => ($timeEntry->getEndTime() ?: new \DateTime())->format('Y-m-d'),
                'severity' => ComplianceViolation::SEVERITY_ERROR
            ]);
        }

        return [[
            'id' => $violation->getId(),
            'type' => ComplianceViolation::TYPE_MISSING_BREAK,
            'severity' => ComplianceViolation::SEVERITY_ERROR,
            'message' => $message,
        ]];
    }

    // ------------------------------------------------------------------ //
    // Organisational break policy (non-statutory — warn, never block)      //
    // ------------------------------------------------------------------ //

    /**
     * Policy break shortfall for the entry's peak day — quantity beyond the
     * statutory requirement, counted at the effective (policy) floor.
     *
     * Returns null when the day meets the effective requirement or no
     * org/model rule tightens the law.
     *
     * @return array{required: int, actual: int, law_required: int, source: string|null}|null
     */
    private function policyQuantityShortfall(TimeEntry $timeEntry): ?array
    {
        if ($timeEntry->getStartTime() === null || $timeEntry->getEndTime() === null) {
            return null;
        }
        $userId = $timeEntry->getUserId();
        $resolver = $this->breakPolicy();
        $profile = $this->profile($userId);

        $peak = $this->dailyWorkingHoursCalculator->describePeakWorkingDay(
            $userId,
            $timeEntry,
            $timeEntry->getEndTime(),
            $timeEntry->getId(),
        );
        $workSeconds = (int)round($peak['hours'] * 3600);

        $detailed = $resolver->requiredBreakDetailed($workSeconds, $userId);
        $effectiveRequired = $detailed['minutes'];
        $lawRequired = $profile->requiredBreakMinutes($peak['hours']);
        if ($effectiveRequired <= $lawRequired || $effectiveRequired <= 0) {
            return null; // nothing beyond statute → no policy violation
        }

        // Countability uses the policy floor (a 10-minute portion need not
        // count toward a 20-minute org rule) — this is deliberately NOT the
        // statutory TimeEntry::validate() floor, which stays law-only.
        $portions = $this->dailyWorkingHoursCalculator->countableBreakPortionsOnCalendarDay(
            $userId,
            $peak['dayStart'],
            $peak['dayEnd'],
            $timeEntry,
            $resolver->effectiveFloor($userId),
            $timeEntry->getId(),
        );
        $actual = array_sum($portions);
        if ($actual >= $effectiveRequired) {
            return null;
        }

        return [
            'required' => $effectiveRequired,
            'actual' => $actual,
            'law_required' => $lawRequired,
            'source' => $detailed['source'],
        ];
    }

    /**
     * break_warning payload for save responses — pure dry-run, never persists.
     * Preserved additive contract: {required, actual, policy, window}.
     */
    public function breakWarningForEntry(TimeEntry $timeEntry): ?array
    {
        if ($timeEntry->getStatus() !== TimeEntry::STATUS_COMPLETED || $timeEntry->getEndTime() === null) {
            return null;
        }
        $resolver = $this->breakPolicy();
        if (!$resolver->isPolicyActive($timeEntry->getUserId())) {
            return null;
        }

        $quantity = $this->policyQuantityShortfall($timeEntry);
        $windowShortfalls = $resolver->windowViolations($timeEntry->getUserId(), $timeEntry);
        if ($quantity === null && $windowShortfalls === []) {
            return null;
        }

        $warning = [
            'required' => $quantity !== null ? $quantity['required'] : null,
            'actual' => $quantity !== null ? $quantity['actual'] : null,
            'policy' => $quantity !== null ? ($quantity['source'] ?? 'tier') : 'window',
            'window' => $windowShortfalls !== [] ? $windowShortfalls[0] : null,
        ];
        if ($quantity === null && $windowShortfalls !== []) {
            $warning['required'] = $windowShortfalls[0]['required_minutes'];
            $warning['actual'] = $windowShortfalls[0]['covered_minutes'];
        }
        return $warning;
    }

    /**
     * Policy violations for a completed entry — org/model tier quantity beyond
     * statute + required-mode break windows. Persisted as SEVERITY_WARNING
     * rows (never error, never blocking — NN-10 two-gate separation), deduped
     * by unresolved (timeEntryId, type).
     *
     * @return list<array<string, mixed>> violation summaries
     */
    public function checkPolicyViolationsWithResult(TimeEntry $timeEntry, bool $persistViolations = true): array
    {
        if ($timeEntry->getStatus() !== TimeEntry::STATUS_COMPLETED || $timeEntry->getEndTime() === null) {
            return [];
        }
        $resolver = $this->breakPolicy();
        $userId = $timeEntry->getUserId();
        if (!$resolver->isPolicyActive($userId)) {
            return [];
        }

        $out = [];

        $quantity = $this->policyQuantityShortfall($timeEntry);
        if ($quantity !== null) {
            $sourceLabel = $quantity['source'] === BreakPolicyResolver::SOURCE_MODEL
                ? $this->l10n->t('working time model rule')
                : $this->l10n->t('organisational break policy');
            $message = $this->l10n->t(
                'Break below organisational rule: %1$d of required %2$d min taken (%3$s)',
                [$quantity['actual'], $quantity['required'], $sourceLabel]
            );
            $out[] = $this->recordPolicyViolation(
                $timeEntry,
                ComplianceViolation::TYPE_MISSING_BREAK,
                $message,
                $persistViolations,
            );
        }

        foreach ($resolver->windowViolations($userId, $timeEntry) as $window) {
            $message = $this->l10n->t(
                'Required break window %1$s–%2$s on %3$s not met: %4$d of %5$d min taken inside the window',
                [
                    $window['start'],
                    $window['end'],
                    $this->displayDate(
                        new \DateTime($window['day'] . ' 12:00:00', $this->timeZoneService->storageTimeZone()),
                        $userId
                    ),
                    $window['covered_minutes'],
                    $window['required_minutes'],
                ]
            );
            $out[] = $this->recordPolicyViolation(
                $timeEntry,
                ComplianceViolation::TYPE_MISSING_BREAK,
                $message,
                $persistViolations,
            );
        }

        return array_values(array_filter($out));
    }

    /**
     * Persist a WARNING-severity policy violation unless an unresolved row of
     * the same type already exists for the entry (dedup contract).
     *
     * @return array<string, mixed>|null summary or null when deduped
     */
    private function recordPolicyViolation(
        TimeEntry $timeEntry,
        string $type,
        string $message,
        bool $persistViolations,
    ): ?array {
        $summary = [
            'type' => $type,
            'severity' => ComplianceViolation::SEVERITY_WARNING,
            'message' => $message,
        ];
        if (!$persistViolations) {
            return $summary;
        }

        $entryId = $timeEntry->getId();
        if ($entryId !== null) {
            foreach ($this->violationMapper->findByTimeEntryId($entryId) as $existing) {
                if ($existing->getViolationType() === $type && !$existing->isResolved()) {
                    return null; // already flagged — no duplicate row, no re-notify
                }
            }
        }

        $violation = $this->violationMapper->createViolation(
            $timeEntry->getUserId(),
            $type,
            $message,
            $timeEntry->getEndTime() ?: new \DateTime(),
            $entryId,
            ComplianceViolation::SEVERITY_WARNING
        );
        $summary['id'] = $violation->getId();

        if ($this->notificationService) {
            $this->notificationService->notifyComplianceViolation($timeEntry->getUserId(), [
                'id' => $violation->getId(),
                'type' => $type,
                'message' => $message,
                'date' => ($timeEntry->getEndTime() ?: new \DateTime())->format('Y-m-d'),
                'severity' => ComplianceViolation::SEVERITY_WARNING,
            ]);
        }

        return $summary;
    }

    // ------------------------------------------------------------------ //
    // Mandatory presence windows (working-time model)                      //
    // ------------------------------------------------------------------ //

    /**
     * Evaluate mandatory presence windows for one user on one calendar day.
     * Creates `missing_presence` WARNING violations — day-level, never
     * blocking, deduped per unresolved (day, type).
     *
     * Skips: days without any work entry, days without a model assignment or
     * presence windows, public holidays, and org presence_exemptions ranges.
     *
     * @return list<array<string, mixed>> violation summaries
     */
    public function evaluatePresenceForDay(string $userId, \DateTime $dayRef, bool $persistViolations = true): array
    {
        $resolver = $this->breakPolicy();
        $tz = $this->timeZoneService->storageTimeZone();
        [$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($dayRef);
        $dayKey = $dayStart->format('Y-m-d');

        $model = $resolver->modelForUser($userId);
        if ($model === null) {
            return [];
        }
        $windows = $resolver->presenceWindows($userId, (int)$dayStart->format('N'));
        if ($windows === []) {
            return [];
        }
        if ($resolver->presenceExempt($dayKey)) {
            return [];
        }
        try {
            if ($this->holidayCalendarService->isHolidayForUser($userId, $dayStart)) {
                return [];
            }
        } catch (\Throwable) {
            // holiday lookup failure must never suppress presence evaluation
        }

        $workIntervals = $resolver->dayWork($userId, $dayStart, $dayEnd);
        if ($workIntervals === []) {
            return []; // no entry → no presence violation
        }

        $absenceCreditMinutes = $this->approvedAbsenceCreditMinutes($userId, $dayStart, $dayEnd, $model);

        $violations = [];
        foreach ($windows as $window) {
            $interval = $resolver->canonicalWindow($dayKey, [
                'start' => $window['start'],
                'end' => $window['end'],
            ]);
            if ($interval === null) {
                continue; // DST-nonexistent window — does not apply that day
            }
            [$wStart, $wEnd] = $interval;
            $covered = $this->intervalOverlapSeconds($workIntervals, $wStart, $wEnd);
            if ($absenceCreditMinutes >= $this->plannedDayMinutes($model, (int)$dayStart->format('N'))) {
                $covered = $wEnd - $wStart; // full-day approved absence covers the window
            } elseif ($absenceCreditMinutes > 0) {
                // Partial absence credits from the window start (documented
                // approximation — absences carry no time-of-day).
                $creditEnd = min($wEnd, $wStart + $absenceCreditMinutes * 60);
                $covered += max(0, $creditEnd - $wStart);
            }
            $required = $wEnd - $wStart;
            if ($covered < $required) {
                $missing = (int)ceil(($required - $covered) / 60);
                $message = $this->l10n->t(
                    'Mandatory presence %1$s–%2$s on %3$s not covered: %4$d min missing',
                    [
                        $window['start'],
                        $window['end'],
                        $this->displayDate($dayStart, $userId),
                        $missing,
                    ]
                );
                $violations[] = [
                    'type' => ComplianceViolation::TYPE_MISSING_PRESENCE,
                    'severity' => ComplianceViolation::SEVERITY_WARNING,
                    'message' => $message,
                    'day' => $dayKey,
                    'window' => $window,
                ];
            }
        }

        if ($violations === [] || !$persistViolations) {
            return $violations;
        }

        // Dedup: one unresolved missing_presence row per day — re-evaluations
        // never stack (idempotent re-run after corrections).
        $existing = $this->violationMapper->findByDateRange($dayStart, $dayEnd, $userId, false);
        $hasUnresolved = false;
        foreach ($existing as $row) {
            if ($row->getViolationType() === ComplianceViolation::TYPE_MISSING_PRESENCE) {
                $hasUnresolved = true;
                break;
            }
        }
        if ($hasUnresolved) {
            return $violations;
        }

        foreach ($violations as &$v) {
            $row = $this->violationMapper->createViolation(
                $userId,
                ComplianceViolation::TYPE_MISSING_PRESENCE,
                $v['message'],
                $dayStart,
                null,
                ComplianceViolation::SEVERITY_WARNING
            );
            $v['id'] = $row->getId();
            if ($this->notificationService) {
                $this->notificationService->notifyComplianceViolation($userId, [
                    'id' => $row->getId(),
                    'type' => ComplianceViolation::TYPE_MISSING_PRESENCE,
                    'message' => $v['message'],
                    'date' => $dayKey,
                    'severity' => ComplianceViolation::SEVERITY_WARNING,
                ]);
            }
        }

        return $violations;
    }

    /**
     * Run presence evaluation for every calendar day an entry touches.
     *
     * @return list<array<string, mixed>>
     */
    private function presenceViolationsForEntryDays(TimeEntry $timeEntry, bool $persistViolations): array
    {
        $start = $timeEntry->getStartTime();
        $end = $timeEntry->getEndTime();
        $userId = $timeEntry->getUserId();
        if ($start === null || $end === null) {
            return [];
        }

        $tz = $this->timeZoneService->storageTimeZone();
        $cursor = (new \DateTimeImmutable('@' . $start->getTimestamp()))->setTimezone($tz);
        $last = (new \DateTimeImmutable('@' . ($end->getTimestamp() - 1)))->setTimezone($tz);

        $violations = [];
        while ($cursor->format('Y-m-d') <= $last->format('Y-m-d')) {
            $dayViolations = $this->evaluatePresenceForDay(
                $userId,
                \DateTime::createFromImmutable($cursor),
                $persistViolations
            );
            $violations = array_merge($violations, $dayViolations);
            $cursor = $cursor->modify('+1 day');
        }
        return $violations;
    }

    /**
     * Approved-absence credit (minutes) for the calendar day.
     * Full-day absence ⇒ ≥ planned-day minutes (covers any window); partial
     * absence ⇒ proportional credit anchored at each window start.
     */
    private function approvedAbsenceCreditMinutes(string $userId, \DateTime $dayStart, \DateTime $dayEnd, \OCA\ArbeitszeitCheck\Db\WorkingTimeModel $model): int
    {
        $mapper = $this->absenceMapper();
        if ($mapper === null) {
            return 0;
        }
        $credited = 0.0;
        try {
            $absences = $mapper->findByUserAndDateRange($userId, $dayStart, $dayEnd);
        } catch (\Throwable) {
            return 0;
        }
        $dayKey = $dayStart->format('Y-m-d');
        foreach ($absences as $absence) {
            if ($absence->getStatus() !== Absence::STATUS_APPROVED) {
                continue;
            }
            $aStart = $absence->getStartDate();
            $aEnd = $absence->getEndDate();
            if ($aStart === null || $aEnd === null) {
                continue;
            }
            if ($aStart->format('Y-m-d') > $dayKey || $aEnd->format('Y-m-d') < $dayKey) {
                continue;
            }
            $spanDays = max(1, (int)$aStart->diff($aEnd)->days + 1);
            $durationHours = $absence->getDurationHours();
            if ($durationHours !== null && $durationHours > 0) {
                $credited += ($durationHours * 60.0) / $spanDays;
                continue;
            }
            $days = $absence->getDays();
            if ($days !== null && $days > 0) {
                $planned = $this->plannedDayMinutes($model, (int)$dayStart->format('N'));
                $credited += ($days / $spanDays) * max(1, $planned);
            }
        }
        return (int)round($credited);
    }

    /**
     * Planned net minutes for the model on the ISO weekday (1=Mon … 7=Sun).
     */
    private function plannedDayMinutes(\OCA\ArbeitszeitCheck\Db\WorkingTimeModel $model, int $weekdayIso): int
    {
        static $dayNames = [1 => 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $schedule = $model->getWeekdaySchedule();
        if ($schedule !== null) {
            $net = $schedule->netHoursForWeekday($dayNames[$weekdayIso] ?? 'mon');
            if ($net > 0) {
                return (int)round($net * 60);
            }
        }
        $daily = (float)$model->getDailyHours();
        if ($daily > 0) {
            return (int)round($daily * 60);
        }
        $weekly = (float)$model->getWeeklyHours();
        $days = (float)$model->getWorkDaysPerWeek();
        if ($weekly > 0 && $days > 0) {
            return (int)round(($weekly / $days) * 60);
        }
        return 8 * 60; // conservative fallback mirrors default working hours
    }

    /** @param list<array{0:int,1:int}> $intervals */
    private function intervalOverlapSeconds(array $intervals, int $start, int $end): int
    {
        $sum = 0;
        foreach ($intervals as $iv) {
            $sum += max(0, min($iv[1], $end) - max($iv[0], $start));
        }
        return $sum;
    }

    /**
     * Check excessive working hours and return violations as array
     *
     * @param TimeEntry $timeEntry
     * @return array Array of violation information
     */
    private function checkExcessiveWorkingHoursWithResult(TimeEntry $timeEntry, bool $persistViolations = true): array
    {
        $violations = [];
        foreach ($this->createExcessiveHoursViolationsForEntry($timeEntry, $persistViolations) as $recorded) {
            $violations[] = $recorded['summary'];
        }

        return $violations;
    }

    /**
     * ArbZG §3: flag each calendar day (storage TZ) whose total exceeds the daily maximum.
     *
     * @return list<array{summary: array<string, mixed>, violation: ComplianceViolation}>
     */
    private function createExcessiveHoursViolationsForEntry(TimeEntry $timeEntry, bool $persistViolations = true): array
    {
        if ($timeEntry->getStartTime() === null || $timeEntry->getEndTime() === null) {
            return [];
        }

        $userId = $timeEntry->getUserId();
        $maxDaily = $this->getMaxDailyHours($userId);
        $exceedingDays = $this->dailyWorkingHoursCalculator->findAllCalendarDaysExceedingMaximum(
            $userId,
            $timeEntry,
            $maxDaily,
        );

        if ($exceedingDays === []) {
            return [];
        }

        $recorded = [];
        foreach ($exceedingDays as $day) {
            [$dayStart] = $this->timeZoneService->dayWindowInStorage(
                new \DateTime($day['date'] . ' 12:00:00', $this->timeZoneService->storageTimeZone())
            );

            if ($persistViolations && $this->excessiveHoursViolationExistsForCalendarDay($userId, $dayStart)) {
                continue;
            }

            $message = $this->l10n->t(
                'Working hours on %1$s exceeded %2$d hours (%3$.1f h on that calendar day, %4$s)',
                [
                    $this->displayDate($dayStart, $userId),
                    (int)$maxDaily,
                    $day['hours'],
                    $this->profile($userId)->lawLabel('daily'),
                ]
            );

            if (!$persistViolations) {
                $recorded[] = [
                    'summary' => [
                        'type' => ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS,
                        'severity' => ComplianceViolation::SEVERITY_ERROR,
                        'message' => $message,
                    ],
                    'violation' => null,
                ];
                continue;
            }

            $violation = $this->violationMapper->createViolation(
                $userId,
                ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS,
                $message,
                $dayStart,
                $timeEntry->getId(),
                ComplianceViolation::SEVERITY_ERROR
            );

            $summary = [
                'id' => $violation->getId(),
                'type' => ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS,
                'severity' => ComplianceViolation::SEVERITY_ERROR,
                'message' => $message,
            ];

            if ($this->notificationService) {
                $this->notificationService->notifyComplianceViolation($userId, [
                    'id' => $violation->getId(),
                    'type' => ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS,
                    'message' => $message,
                    'date' => $day['date'],
                    'severity' => ComplianceViolation::SEVERITY_ERROR,
                ]);
            }

            $recorded[] = ['summary' => $summary, 'violation' => $violation];
        }

        return $recorded;
    }

    /**
     * Avoid duplicate ERROR rows when batch checks touch several entries on the same day.
     */
    private function excessiveHoursViolationExistsForCalendarDay(string $userId, \DateTime $dayStart): bool
    {
        $dayEnd = (clone $dayStart)->modify('+1 day');
        foreach ($this->violationMapper->findByDateRange($dayStart, $dayEnd, $userId) as $existing) {
            if ($existing->getViolationType() === ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS
                && !$existing->isResolved()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if minimum rest period is met (11 hours between shifts, ArbZG §5).
     *
     * Uses targeted DB queries instead of a full user-entry scan to keep clock-in fast.
     *
     * @param string $userId
     * @return bool
     */
    private function checkRestPeriod(string $userId): bool
    {
        return $this->evaluateRestPeriodForClockIn($userId)['valid'];
    }

    /**
     * Evaluate ArbZG §5 / AZG / ArG rest for clock-in at "now" in the organisation storage TZ.
     *
     * Critical invariants:
     *  - "Now" is always {@see TimeZoneService::nowInStorage()} (never PHP default TZ).
     *  - The rest anchor is the latest completed end_time strictly before now.
     *    Entries with end_time in the future (bad manual rows / planned days) must
     *    never block clock-in — they are not an ended shift yet.
     *  - Same-calendar-day geteilte Arbeitszeit (morning block then afternoon Kommen)
     *    may skip the cross-shift rest gate via {@see isIntradayWorkInterruption} —
     *    identical rule to {@see checkRestPeriodForStartTime}. Overnight Wachdienst
     *    (start day &lt; end day) never qualifies, so 22:00→06:00 then Kommen at 10:00
     *    still requires elapsed Ruhezeit.
     *  - Short breaks inside an open session should still use Pause, not Gehen→Kommen;
     *    the error message says so when rest is blocked.
     *  - User-facing times always include the calendar date so "04:00" cannot be
     *    mistaken for a time that already passed today when it is tomorrow.
     *
     * @return array{valid: bool, message: string|null, lastEndTime: ?\DateTime, earliestClockIn: ?\DateTime, details?: array<string, mixed>}
     */
    private function evaluateRestPeriodForClockIn(string $userId, ?\DateTimeInterface $at = null): array
    {
        if ($at instanceof \DateTime) {
            $now = clone $at;
        } elseif ($at instanceof \DateTimeInterface) {
            $now = \DateTime::createFromInterface($at);
        } else {
            $now = $this->timeZoneService->nowInStorage();
        }
        $minRest = $this->getMinRestPeriod($userId);

        // Prefer the last completed entry so we can apply the same intraday-split
        // gate as manual entries (needs start + end, not only the end instant).
        $lastCompleted = $this->timeEntryMapper->findLastCompletedBeforeTime($userId, $now);
        $lastEndTime = $lastCompleted?->getEndTime();
        $lastStartTime = $lastCompleted?->getStartTime();

        if ($lastEndTime !== null
            && $lastStartTime instanceof \DateTimeInterface
            && $this->isIntradayWorkInterruption($lastStartTime, $lastEndTime, $now)
        ) {
            return [
                'valid' => true,
                'message' => null,
                'lastEndTime' => $lastEndTime,
                'earliestClockIn' => null,
                'details' => [
                    'intraday_split' => true,
                    'min_rest_hours' => (int)$minRest,
                ],
            ];
        }

        if ($lastEndTime === null) {
            // No completed end before now — fall back to paused-session anchor.
            $lastEndTime = $this->resolveRestPeriodAnchor($userId, $now);
        }

        if ($lastEndTime === null) {
            return [
                'valid' => true,
                'message' => null,
                'lastEndTime' => null,
                'earliestClockIn' => null,
            ];
        }

        $hoursSinceLastEntry = ($now->getTimestamp() - $lastEndTime->getTimestamp()) / 3600.0;
        if ($hoursSinceLastEntry >= $minRest) {
            return [
                'valid' => true,
                'message' => null,
                'lastEndTime' => $lastEndTime,
                'earliestClockIn' => null,
            ];
        }

        $earliestClockIn = $this->addRestHours($lastEndTime, $minRest);
        $hoursRemaining = ($earliestClockIn->getTimestamp() - $now->getTimestamp()) / 3600.0;
        $lawLabel = $this->profile($userId)->lawLabel('rest');
        $lastEndDate = $this->displayDate($lastEndTime, $userId);
        $lastEndClock = $this->displayClock($lastEndTime, $userId);
        $earliestDisplay = $this->timeZoneService->formatForDisplay($earliestClockIn, 'd.m.Y H:i', $userId);
        $hoursRemainingSafe = max(0.0, $hoursRemaining);

        return [
            'valid' => false,
            'message' => $this->l10n->t(
                'Minimum %1$d-hour rest period required between shifts (%2$s). Your last shift ended on %3$s at %4$s. You can clock in after %5$s (in %6$.1f hours). For a short break in the same session, use Pause instead of clocking out. Morning and afternoon blocks on the same calendar day (split shift) are allowed when the previous block did not run overnight.',
                [
                    (int)$minRest,
                    $lawLabel,
                    $lastEndDate,
                    $lastEndClock,
                    $earliestDisplay,
                    $hoursRemainingSafe,
                ]
            ),
            'lastEndTime' => $lastEndTime,
            'earliestClockIn' => $earliestClockIn,
            'details' => [
                'min_rest_hours' => (int)$minRest,
                'law_label' => $lawLabel,
                'last_end_date' => $lastEndDate,
                'last_end_clock' => $lastEndClock,
                'earliest_clock_in' => $earliestDisplay,
                'hours_remaining' => round($hoursRemainingSafe, 1),
                'intraday_split' => false,
            ],
        ];
    }

    /**
     * Latest shift-end instant that may anchor an ArbZG §5 rest check at $asOf.
     *
     * Prefers the newest completed entry with end_time < $asOf (so future-dated
     * completed rows cannot poison the check). Falls back to a recent paused
     * entry's updatedAt when it is also not after $asOf.
     */
    private function resolveRestPeriodAnchor(string $userId, \DateTime $asOf): ?\DateTime
    {
        $lastCompleted = $this->timeEntryMapper->findLastCompletedBeforeTime($userId, $asOf);
        $lastEndTime = $lastCompleted?->getEndTime();
        if ($lastEndTime !== null) {
            return $lastEndTime;
        }

        $minRest = $this->getMinRestPeriod($userId);
        $lookbackHours = max(48, (int)ceil($minRest * 2));
        $lastPaused = $this->timeEntryMapper->findLastPausedWithinHours($userId, $lookbackHours);
        $pausedAt = $lastPaused?->getUpdatedAt();
        if ($pausedAt !== null && $pausedAt->getTimestamp() <= $asOf->getTimestamp()) {
            return $pausedAt;
        }

        return null;
    }

    /**
     * Add a fractional rest period to an instant using whole seconds (avoids
     * truncating e.g. 11.5 h down to 11 h via (int) cast on modify('+N hours')).
     */
    private function addRestHours(\DateTime $from, float $hours): \DateTime
    {
        $earliest = clone $from;
        $seconds = (int)round(max(0.0, $hours) * 3600.0);
        $earliest->modify('+' . $seconds . ' seconds');
        return $earliest;
    }

    /**
     * True when a completed block and the next start are a same-calendar-day
     * split (geteilte Arbeitszeit) that did not cross midnight.
     *
     * Storage-TZ wall dates: lastStart, lastEnd, and nextStart must share one
     * Y-m-d. Overnight shifts (start day &lt; end day) never qualify — otherwise
     * Wachdienst 22:00→06:00 could clear Ruhezeit for a 10:00 start.
     */
    private function isIntradayWorkInterruption(
        \DateTimeInterface $lastStart,
        \DateTimeInterface $lastEnd,
        \DateTimeInterface $nextStart
    ): bool {
        $startDay = $lastStart->format('Y-m-d');
        $endDay = $lastEnd->format('Y-m-d');
        $nextDay = $nextStart->format('Y-m-d');
        return $startDay === $endDay && $endDay === $nextDay;
    }

    /**
     * Check if minimum rest period is met for a specific start time (ArbZG §5)
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
        // Single indexed query: most-recent completed entry ending before $startTime.
        $lastCompletedEntry = $this->timeEntryMapper->findLastCompletedBeforeTime($userId, $startTime, $excludeEntryId);

        // If no previous completed entry found, rest period check is not applicable.
        if ($lastCompletedEntry === null || $lastCompletedEntry->getEndTime() === null) {
            return ['valid' => true, 'message' => null];
        }

        $lastEndTime = $lastCompletedEntry->getEndTime();
        $lastStartTime = $lastCompletedEntry->getStartTime();

        // Intraday geteilte Arbeitszeit (two blocks same calendar day) may skip the
        // cross-shift rest gate — but ONLY when the previous block itself did not
        // cross midnight. Overnight Wachdienst ending after 00:00 must still enforce
        // elapsed Ruhezeit before the next start on that morning (ArbZG §5).
        if ($lastStartTime instanceof \DateTimeInterface
            && $this->isIntradayWorkInterruption($lastStartTime, $lastEndTime, $startTime)
        ) {
            return ['valid' => true, 'message' => null];
        }
        
        // Check days difference: start date - last end date
        $lastEndDate = $lastEndTime->format('Y-m-d');
        $startDate = $startTime->format('Y-m-d');
        $lastEndDateObj = new \DateTime($lastEndDate);
        $lastEndDateObj->setTime(0, 0, 0);
        $startDateObj = new \DateTime($startDate);
        $startDateObj->setTime(0, 0, 0);
        
		// Calculate calendar day distance between the two dates (normalised to midnight).
		// $startDateObj->diff($lastEndDateObj) goes FROM startDate TO lastEndDate.
		// Because startDate > lastEndDate (we only consider entries ending before startTime)
		// the diff is always negative: -1 means "exactly the next calendar day", -2 means
		// "two calendar days apart", etc.
		$diff = $startDateObj->diff($lastEndDateObj);
		$daysDifference = (int)$diff->format('%r%a'); // negative when startDate > lastEndDate

		// Two or more full calendar days apart: 11 h rest is guaranteed regardless of clock time.
		// Example: last end = Jan 15, new start = Jan 17 → daysDifference = -2 → always valid.
		if ($daysDifference <= -2) {
			return ['valid' => true, 'message' => null];
		}

		// Exactly one calendar day apart (daysDifference == -1) or any unexpected positive value:
		// fall through to the exact timestamp check below.
		// Example: last end = Jan 15 23:30, new start = Jan 16 00:30 → only 1 h rest → must fail.

        $minRest = $this->getMinRestPeriod($userId);
        $hoursSinceLastEntry = ($startTime->getTimestamp() - $lastEndTime->getTimestamp()) / 3600;

        if ($hoursSinceLastEntry >= $minRest) {
            return ['valid' => true, 'message' => null];
        }
        
        $earliestStartTime = $this->addRestHours($lastEndTime, $minRest);
        $hoursStillNeeded = ($earliestStartTime->getTimestamp() - $startTime->getTimestamp()) / 3600;
        
        // Format the user-facing message in the affected user's display TZ so
        // employees in non-storage zones (rare but legal) see their own clock.
        $lastEndDateFormatted = $this->displayDate($lastEndTime, $userId);
        $earliestStartDateFormatted = $this->timeZoneService->formatForDisplay($earliestStartTime, 'd.m.Y H:i', $userId);

        return [
            'valid' => false,
            'message' => $this->l10n->t(
                'Minimum %1$d-hour rest period required between shifts (%2$s). Your last shift ended on %3$s at %4$s. This entry cannot start before %5$s (%6$.1f hours required).',
                [
                    (int)$minRest,
                    $this->profile($userId)->lawLabel('rest'),
                    $lastEndDateFormatted,
                    $this->displayClock($lastEndTime, $userId),
                    $earliestStartDateFormatted,
                    abs($hoursStillNeeded),
                ]
            ),
            'earliestStartTime' => $earliestStartTime,
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
        $todayHours = $this->dailyWorkingHoursCalculator->getWorkingHoursForToday($userId);
        return $todayHours < $this->getMaxDailyHours($userId);
    }

    /**
     * Check weekly working hours average over the country's averaging window
     * (DE: 48 h over 26 weeks per ArbZG §3; AT: 48 h over 17 weeks per AZG §9).
     *
     * @param string $userId
     * @return bool
     */
    private function checkWeeklyWorkingHoursLimit(string $userId): bool
    {
        $profile = $this->profile($userId);
        if ($profile->weeklyAvgMaxHours === null || $profile->avgWindowWeeks <= 0) {
            return true;
        }
        $now = $this->timeZoneService->nowInStorage();
        $windowStart = (clone $now)->modify(sprintf('-%d days', $profile->avgWindowWeeks * 7));

        $totalHours = $this->timeEntryMapper->getTotalHoursByUserAndDateRange(
            $userId,
            $windowStart,
            $now
        );

        $averageWeeklyHours = $totalHours / $profile->avgWindowWeeks;

        return $averageWeeklyHours <= $profile->weeklyAvgMaxHours;
    }

    /**
     * Absolute calendar-week hour cap (AT/CH). True when under the limit or when
     * the profile has no absolute weekly rule (DE).
     */
    private function checkAbsoluteWeeklyHoursLimit(string $userId): bool
    {
        $profile = $this->profile($userId);
        if ($profile->weeklyAbsoluteMaxHours === null) {
            return true;
        }
        $now = $this->timeZoneService->nowInStorage();
        $check = $this->checkAbsoluteWeeklyHours($userId, $now);

        return $check['valid'];
    }

    /**
     * Persist at most one unresolved absolute-weekly violation per calendar week.
     * Race-safe enough for production: unique-ish by (user, type, week window)
     * via the pre-read; duplicate inserts are acceptable noise, not double-count
     * of hours (the hours query is the source of truth).
     */
    private function ensureAbsoluteWeeklyViolation(
        string $userId,
        \DateTime $referenceDate,
        string $message,
        ?int $timeEntryId = null,
    ): void {
        $weekStart = (clone $referenceDate)->modify('monday this week')->setTime(0, 0, 0);
        $weekEnd = (clone $weekStart)->modify('+7 days');

        $existing = $this->violationMapper->findByDateRange($weekStart, $weekEnd, $userId);
        foreach ($existing as $row) {
            if ($row->getViolationType() === ComplianceViolation::TYPE_WEEKLY_ABSOLUTE_HOURS_EXCEEDED
                && !$row->getResolved()) {
                return;
            }
        }

        $violationDate = (clone $referenceDate)->setTime(0, 0, 0);
        $this->violationMapper->createViolation(
            $userId,
            ComplianceViolation::TYPE_WEEKLY_ABSOLUTE_HOURS_EXCEEDED,
            $message,
            $violationDate,
            $timeEntryId,
            ComplianceViolation::SEVERITY_WARNING
        );
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
        $profile = $this->profile($userId);
        $limit = $profile->dailyAvgMaxHours;
        if ($limit === null) {
            // Country without a daily-average rule (AT) — callers gate on the
            // profile, this is a defensive second gate.
            return ['valid' => true, 'message' => null, 'average' => 0.0, 'limit' => 0.0];
        }

        $windowStart = clone $entryDate;
        $windowStart->modify(sprintf('-%d days', $profile->avgWindowWeeks * 7));

        // Get total hours worked inside the averaging window
        $totalHours = $this->timeEntryMapper->getTotalHoursByUserAndDateRange(
            $userId,
            $windowStart,
            $entryDate
        );

        // Approximate working days (5-day week, e.g. 26 weeks × 5 = 130 for DE).
        // Counting actual working days would be more precise, but this is a
        // non-blocking manager warning.
        $approximateWorkingDays = $profile->approximateWorkingDays();

        // Calculate average daily working hours
        $averageDailyHours = $approximateWorkingDays > 0 ? $totalHours / $approximateWorkingDays : 0;

        if ($averageDailyHours > $limit) {
            return [
                'valid' => false,
                'message' => $this->l10n->t(
                    'Warning: average working hours over %1$d weeks (%2$.2f h/day) exceed %3$d hours/day. Longer days are only allowed if the average stays within the limit (%4$s).',
                    [
                        $profile->avgWindowWeeks,
                        $averageDailyHours,
                        (int)$limit,
                        $profile->lawLabel('dailyAvg'),
                    ]
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
        $profile = $this->profile($userId);
        $limit = $profile->weeklyAvgMaxHours;
        if ($limit === null || $profile->avgWindowWeeks <= 0) {
            return ['valid' => true, 'message' => null, 'average' => 0.0, 'limit' => 0.0];
        }

        $windowStart = clone $entryDate;
        $windowStart->modify(sprintf('-%d days', $profile->avgWindowWeeks * 7));

        // Get total hours worked inside the averaging window
        $totalHours = $this->timeEntryMapper->getTotalHoursByUserAndDateRange(
            $userId,
            $windowStart,
            $entryDate
        );

        $weeks = $profile->avgWindowWeeks;
        $averageWeeklyHours = $weeks > 0 ? $totalHours / $weeks : 0;

        if ($averageWeeklyHours > $limit) {
            return [
                'valid' => false,
                'message' => $this->l10n->t(
                    'Warning: average weekly working hours over %1$d weeks (%2$.2f h/week) exceed %3$d hours/week (%4$s).',
                    [
                        $weeks,
                        $averageWeeklyHours,
                        (int)$limit,
                        $profile->lawLabel('weekly'),
                    ]
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
     * Absolute weekly maximum for countries that define one (AT: 60 h per
     * AZG §9). Sums the calendar week (Monday 00:00 … entry date end-of-day)
     * containing the entry. Never called for countries with
     * weeklyAbsoluteMaxHours = null (DE).
     *
     * @return array{valid: bool, message: string|null, average: float, limit: float}
     */
    private function checkAbsoluteWeeklyHours(string $userId, \DateTime $entryDate): array
    {
        $profile = $this->profile($userId);
        $limit = $profile->weeklyAbsoluteMaxHours;
        if ($limit === null) {
            return ['valid' => true, 'message' => null, 'average' => 0.0, 'limit' => 0.0];
        }

        $weekStart = (clone $entryDate)->modify('monday this week')->setTime(0, 0, 0);
        $weekEnd = (clone $entryDate)->setTime(23, 59, 59);

        $weekHours = $this->timeEntryMapper->getTotalHoursByUserAndDateRange(
            $userId,
            $weekStart,
            $weekEnd
        );

        if ($weekHours > $limit) {
            return [
                'valid' => false,
                'message' => $this->l10n->t(
                    'Warning: working hours in the current week (%1$.2f h) exceed the absolute weekly maximum of %2$d hours (%3$s).',
                    [
                        $weekHours,
                        (int)$limit,
                        $profile->lawLabel('weekly'),
                    ]
                ),
                'average' => $weekHours,
                'limit' => $limit
            ];
        }

        return [
            'valid' => true,
            'message' => null,
            'average' => $weekHours,
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
        $this->checkMandatoryBreaksWithResult($timeEntry, true);
    }

    /**
     * Check for excessive working hours (over 10 hours in a day)
     *
     * @param TimeEntry $timeEntry
     * @return void
     */
    private function checkExcessiveWorkingHours(TimeEntry $timeEntry): void
    {
        $this->createExcessiveHoursViolationsForEntry($timeEntry);
    }

    /**
     * Check for night work inside the country's night window
     * (DE: 23:00 – 06:00 per ArbZG §6; AT: 22:00 – 05:00 per AZG §12b).
     *
     * Authoritative source of truth is {@see calculateNightHours()}: if the actual
     * intersection with the night window is positive we record an INFO violation,
     * otherwise we skip it (boundary cases like just-before-window or
     * just-after-window shifts must not produce a "0.00 hours" violation).
     *
     * @param TimeEntry $timeEntry
     * @return void
     */
    private function checkNightWork(TimeEntry $timeEntry): void
    {
        $startTime = $timeEntry->getStartTime();
        $endTime = $timeEntry->getEndTime();

        if (!$endTime || !$startTime) {
            return;
        }

        $nightHours = $this->calculateNightHours($startTime, $endTime, $timeEntry->getUserId());

        if ($nightHours <= 0.0) {
            return;
        }

        $profile = $this->profile($timeEntry->getUserId());

        // CRITICAL: pass $nightHours as a parameter to t() so the L10NString carries
        // the value into its internal vsprintf(). Calling sprintf() on the OUTSIDE of
        // a parameterless t() corrupts the placeholder pipeline and triggers a
        // ValueError in OC\L10N\L10NString::__toString() (see issue triggered on
        // /api/clock/out for late-night shifts).
        $this->violationMapper->createViolation(
            $timeEntry->getUserId(),
            ComplianceViolation::TYPE_NIGHT_WORK,
            $this->l10n->t(
                'Night work detected: %1$.2f hours between %2$s and %3$s (%4$s)',
                [
                    $nightHours,
                    sprintf('%02d:00', $profile->nightWindowStartHour),
                    sprintf('%02d:00', $profile->nightWindowEndHour),
                    $profile->lawLabel('night'),
                ]
            ),
            $timeEntry->getEndTime(),
            $timeEntry->getId(),
            ComplianceViolation::SEVERITY_INFO
        );
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

        if (!$endTime || !$startTime) {
            return;
        }

        $userId = $timeEntry->getUserId();
        $entryId = $timeEntry->getId();

        // Every calendar day touched by the shift must be evaluated. Shifts that
        // start on Saturday and end on Sunday must still flag Sunday work; the old
        // logic only inspected the start date and missed that case.
        $cursor = (clone $startTime)->setTime(0, 0, 0);
        $lastCalendarDay = (clone $endTime)->setTime(0, 0, 0);

        while ($cursor <= $lastCalendarDay) {
            // $cursor is always normalized to 00:00:00 of the calendar day under test.
            $occurredAt = $startTime > $cursor ? clone $startTime : clone $cursor;

            if ((int)$cursor->format('w') === 0) {
                if (!$this->isSundayWorkAllowedForUser($userId, $cursor)) {
                    $this->violationMapper->createViolation(
                        $userId,
                        ComplianceViolation::TYPE_SUNDAY_WORK,
                        $this->l10n->t(
                            'Work performed on Sunday (%s)',
                            [$this->profile($userId)->lawLabel('sundayHoliday')]
                        ),
                        $occurredAt,
                        $entryId,
                        ComplianceViolation::SEVERITY_WARNING
                    );
                }
            }

            $isHoliday = false;
            try {
                $isHoliday = $this->holidayCalendarService->isHolidayForUser(
                    $userId,
                    clone $cursor
                );
            } catch (\Throwable) {
                // If holiday lookup fails, we fall back to "not a holiday" to avoid false positives.
            }

            if ($isHoliday) {
                $this->violationMapper->createViolation(
                    $userId,
                    ComplianceViolation::TYPE_HOLIDAY_WORK,
                    $this->l10n->t(
                        'Work performed on public holiday (%s)',
                        [$this->profile($userId)->lawLabel('sundayHoliday')]
                    ),
                    $occurredAt,
                    $entryId,
                    ComplianceViolation::SEVERITY_WARNING
                );
            }

            $cursor->modify('+1 day');
        }
    }

    /**
     * True when the user's assigned working-time model opts into Sunday work
     * (explicit allow_sunday_work or weekday_schedule Sunday work day).
     */
    private function isSundayWorkAllowedForUser(string $userId, \DateTimeInterface $onDate): bool
    {
        try {
            $date = \DateTime::createFromInterface($onDate);
            $assignment = $this->userWorkingTimeModelMapper->findByUserAndDate($userId, $date);
            if ($assignment === null) {
                $assignment = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
            }
            if ($assignment === null) {
                return false;
            }
            $model = $this->workingTimeModelMapper->find((int)$assignment->getWorkingTimeModelId());
            return SundayWorkPolicy::isAllowed($model->getBreakRulesArray());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Calculate the total hours worked inside the country's night window
     * (DE: 23:00 – 06:00; AT: 22:00 – 05:00 — from the labor-law profile).
     *
     * Each "night window" is the half-open interval
     * [day X <windowStart>, day X+1 <windowEnd>).
     * A work span can intersect with multiple night windows:
     *   - the previous night that bleeds into "today" (e.g. a 02:00–04:00 shift
     *     belongs entirely to the night that started the previous evening),
     *   - the upcoming night (e.g. a 22:00–02:00 shift),
     *   - and — for unusually long shifts — additional ones in between.
     *
     * The previous implementation only considered the night window starting on the
     * shift's start date, which incorrectly returned 0 for early-morning shifts that
     * fell entirely inside the prior night.
     *
     * @param \DateTime $start Inclusive start of the work span.
     * @param \DateTime $end   Exclusive end of the work span.
     * @return float Hours of work that fell inside any night window (≥ 0).
     */
    private function calculateNightHours(\DateTime $start, \DateTime $end, ?string $userId = null): float
    {
        if ($end <= $start) {
            return 0.0;
        }

        $profile = $this->profile($userId);
        $windowStartHour = $profile->nightWindowStartHour;
        $windowEndHour = $profile->nightWindowEndHour;

        // Iterate from the calendar day BEFORE $start through $end so we never miss
        // the previous-night window for early-morning shifts. For typical shifts
        // (≤ 24h) this loop runs at most three times.
        $totalSeconds = 0;
        $iter = (clone $start)->setTime(0, 0, 0)->modify('-1 day');
        $stopDay = (clone $end)->setTime(0, 0, 0);

        while ($iter <= $stopDay) {
            $windowStart = (clone $iter)->setTime($windowStartHour, 0, 0);
            $windowEnd = (clone $iter)->modify('+1 day')->setTime($windowEndHour, 0, 0);

            $overlapStart = max($start, $windowStart);
            $overlapEnd = min($end, $windowEnd);

            if ($overlapEnd > $overlapStart) {
                $totalSeconds += $overlapEnd->getTimestamp() - $overlapStart->getTimestamp();
            }

            $iter->modify('+1 day');
        }

        return $totalSeconds / 3600;
    }

    /**
     * Check if a date is a public holiday in the given region.
     *
     * @param \DateTime $date
     * @param string|null $state Optional region code (e.g. 'NW', 'AT-W'). When
     *        omitted, the instance default region applies. The method name is
     *        kept for API compatibility; it handles all supported countries.
     * @return bool
     */
    public function isGermanPublicHoliday(\DateTime $date, ?string $state = null): bool
    {
        $checkDate = (clone $date)->setTime(0, 0, 0);

        if ($state !== null && $state !== '') {
            return $this->holidayCalendarService->isHolidayForState($state, $checkDate);
        }

        // Legacy-style call without explicit region: the empty string is
        // normalised by HolidayService to the country-aware default region.
        $defaultState = $this->config->getAppValue('arbeitszeitcheck', 'german_state', '');

        return $this->holidayCalendarService->isHolidayForState($defaultState, $checkDate);
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
        // Calendar day boundaries in organisation storage TZ (never PHP default / container UTC).
        [$todayStart] = $this->timeZoneService->todayWindowInStorage();
        $yesterday = (clone $todayStart)->modify('-1 day');
        $today = clone $todayStart;

        $stats = [
            'users_checked' => 0,
            'violations_found' => 0,
            'check_date' => $yesterday->format('Y-m-d')
        ];

        // Iterate through all users
        $this->userManager->callForAllUsers(function ($user) use ($yesterday, $today, &$stats) {
            $userId = $user->getUID();
            if (!$this->permissionService->isUserAllowedByAccessGroups($userId)) {
                return;
            }
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
                        try {
                            $this->checkPolicyViolationsWithResult($entry);
                        } catch (\Throwable $e) {
                            \OCP\Log\logger('arbeitszeitcheck')->error(
                                'Daily break-policy check failed for entry ' . (int)$entry->getId() . ': ' . $e->getMessage(),
                                ['exception' => $e, 'user_id' => $userId]
                            );
                        }
                    }
                }
            }

            // Mandatory presence windows — day-level, deduped, independent of
            // the per-entry skip above (a resolved statutory flag must not
            // suppress presence evaluation).
            try {
                $this->evaluatePresenceForDay($userId, clone $yesterday);
            } catch (\Throwable $e) {
                \OCP\Log\logger('arbeitszeitcheck')->error(
                    'Daily presence check failed for user ' . $userId . ': ' . $e->getMessage(),
                    ['exception' => $e]
                );
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
                        $this->l10n->t(
                            'Weekly working hours average limit (%1$d hours) exceeded over the last %2$d weeks (%3$s)',
                            [
                                (int)$this->profile($userId)->weeklyAvgMaxHours,
                                $this->profile($userId)->avgWindowWeeks,
                                $this->profile($userId)->lawLabel('weekly'),
                            ]
                        ),
                        $yesterday,
                        null,
                        ComplianceViolation::SEVERITY_WARNING
                    );
                }
            }

            // Absolute weekly maximum (AT 60 / CH 45|50) — first-class for CH
            // where there is no averaging window at all.
            $absoluteCheck = $this->checkAbsoluteWeeklyHours($userId, $yesterday);
            if (!$absoluteCheck['valid']) {
                $this->ensureAbsoluteWeeklyViolation(
                    $userId,
                    $yesterday,
                    (string)$absoluteCheck['message'],
                    null
                );
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
        $hasData = $this->timeEntryMapper->countByUser($userId) > 0;

        return $this->buildComplianceStatus($unresolvedViolations, $hasData);
    }

    /**
     * Compliance status for a set of users — two batched queries total
     * (violations + entry counts) instead of two queries per user.
     *
     * @param list<string> $userIds
     * @return array<string, array> userId => status (same shape as getComplianceStatus)
     */
    public function getComplianceStatusForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique($userIds));
        if (empty($userIds)) {
            return [];
        }

        $violationsByUser = [];
        foreach ($this->violationMapper->findByUserIdsAndDateRange($userIds, null, null, null, null, false) as $violation) {
            $violationsByUser[$violation->getUserId()][] = $violation;
        }

        $entryCounts = $this->timeEntryMapper->countByUserIds($userIds);

        $statuses = [];
        foreach ($userIds as $userId) {
            $statuses[$userId] = $this->buildComplianceStatus(
                $violationsByUser[$userId] ?? [],
                ($entryCounts[$userId] ?? 0) > 0
            );
        }
        return $statuses;
    }

    /**
     * @param ComplianceViolation[] $unresolvedViolations
     */
    private function buildComplianceStatus(array $unresolvedViolations, bool $hasData): array
    {
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
        $score -= min(
			\OCA\ArbeitszeitCheck\Constants::COMPLIANCE_SCORE_MAX_DEDUCTION,
			($critical * \OCA\ArbeitszeitCheck\Constants::COMPLIANCE_SCORE_CRITICAL_WEIGHT)
			+ ($warning * \OCA\ArbeitszeitCheck\Constants::COMPLIANCE_SCORE_WARNING_WEIGHT)
			+ ($info * \OCA\ArbeitszeitCheck\Constants::COMPLIANCE_SCORE_INFO_WEIGHT)
		);

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
    public function generateComplianceReport(\DateTime $startDate, \DateTime $endDate, ?string $userId = null, ?array $userIds = null): array
    {
        // The request/report end date is inclusive; `date` is day-grained, so
        // the exclusive upper bound is end + 1 day.
        $endExclusive = (clone $endDate)->modify('+1 day');
        if ($userIds !== null) {
            // Scoped multi-user report (manager scope / org / explicit list).
            $violations = $this->violationMapper->findByUserIdsAndDateRange(
                $userIds,
                $startDate,
                $endExclusive
            );
        } else {
            $violations = $this->violationMapper->findByDateRange($startDate, $endExclusive, $userId);
        }

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