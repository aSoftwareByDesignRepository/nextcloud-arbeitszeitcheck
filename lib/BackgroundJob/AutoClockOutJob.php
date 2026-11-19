<?php

declare(strict_types=1);

/**
 * Planned-end automatic clock-out background job for arbeitszeitcheck
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\BusinessRuleCode;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Service\DbLockGuard;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\PlannedEndResolver;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Closes forgotten open sessions at the employee's planned end of day.
 *
 * Contract (SPEC auto-clock-out-planned-end):
 * - default OFF for existing installations; when off this job writes nothing
 * - runs hourly but only acts when the current hour in the APP timezone
 *   equals the configured cutover hour
 * - never writes "now" as end_time — only the resolved planned end
 * - every automatic closure sets ended_reason=auto_planned_end and
 *   capture_source=auto_clock_out, and always notifies the user
 * - fail-closed on every unresolvable case; a skip is never a failure
 */
class AutoClockOutJob extends TimedJob
{
	public const SKIP_PENDING_CORRECTION = 'pending_correction';
	public const SKIP_PREVIOUS_DAY_START = 'previous_day_start';
	public const SKIP_OVERLAP_CONFLICT = 'overlap_conflict';
	public const SKIP_MONTH_CLOSED = 'month_closed';
	public const SKIP_ALREADY_HANDLED = 'already_handled';

	public function __construct(
		ITimeFactory $timeFactory,
		private TimeEntryMapper $timeEntryMapper,
		private TimeTrackingService $timeTrackingService,
		private PlannedEndResolver $plannedEndResolver,
		private NotificationService $notificationService,
		private IUserManager $userManager,
		private IConfig $config,
		private LoggerInterface $logger,
		private PermissionService $permissionService,
		private TimeZoneService $timeZoneService,
		private ILockingProvider $locking
	) {
		parent::__construct($timeFactory);
		$this->setInterval(60 * 60);
	}

	/**
	 * @inheritDoc
	 */
	protected function run($argument): void
	{
		// Shared lock on the upgrade-backup key: a restore holds it
		// exclusively — app-table writes must not interleave with a
		// mid-restore truncate+reinsert. A skip is not a failure.
		try {
			DbLockGuard::acquire(
				$this->locking,
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'auto clock-out planned-end run',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping auto clock-out — upgrade backup/restore in progress.',
				['app' => 'arbeitszeitcheck'],
			);
			return;
		}
		try {
			$this->doRun();
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}

	private function doRun(): void
	{
		if ($this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_AUTO_CLOCK_OUT_ENABLED, '0') !== '1') {
			// Feature flag off (default for existing installs): zero writes.
			return;
		}

		$hour = $this->configuredHour();
		$nowApp = $this->timeZoneService->nowInStorage();
		if ((int)$nowApp->format('G') !== $hour) {
			return;
		}

		$scope = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_AUTO_CLOCK_OUT_SCOPE, Constants::AUTO_CLOCK_OUT_SCOPE_TODAY);
		if (!in_array($scope, [Constants::AUTO_CLOCK_OUT_SCOPE_TODAY, Constants::AUTO_CLOCK_OUT_SCOPE_ALL_OPEN], true)) {
			$scope = Constants::AUTO_CLOCK_OUT_SCOPE_TODAY;
		}

		// Cutover = the configured hour of TODAY in the app timezone. Wall-clock
		// construction (not "+N hours") so DST days resolve to the real hour.
		$cutover = \DateTime::createFromFormat(
			'!Y-m-d H:i:s',
			$nowApp->format('Y-m-d') . ' ' . sprintf('%02d', $hour) . ':00:00',
			$this->timeZoneService->storageTimeZone()
		);
		if ($cutover === false) {
			$this->logger->error('ArbeitszeitCheck: auto clock-out could not build cutover instant', ['app' => 'arbeitszeitcheck']);
			return;
		}
		$todayKey = $nowApp->format('Y-m-d');

		$closed = 0;
		$errors = 0;
		$skipped = [];
		$modelCache = [];

		$this->userManager->callForAllUsers(function (IUser $user) use (&$closed, &$errors, &$skipped, &$modelCache, $scope, $cutover, $todayKey) {
			$userId = $user->getUID();
			try {
				if (!$user->isEnabled()) {
					return;
				}
				if (!$this->permissionService->isUserAllowedByAccessGroups($userId)) {
					return;
				}

				$entry = $this->timeEntryMapper->findOpenEntryByUser($userId);
				if ($entry === null) {
					return;
				}

				if ($entry->getStatus() === TimeEntry::STATUS_PENDING_APPROVAL
					|| $this->timeEntryMapper->findPendingOpenSessionByUser($userId) !== null) {
					$this->countSkip($skipped, self::SKIP_PENDING_CORRECTION);
					return;
				}
				if (!in_array($entry->getStatus(), [TimeEntry::STATUS_ACTIVE, TimeEntry::STATUS_BREAK, TimeEntry::STATUS_PAUSED], true)) {
					$this->countSkip($skipped, 'invalid_state');
					return;
				}

				$start = $entry->getStartTime();
				if ($start === null) {
					$this->countSkip($skipped, PlannedEndResolver::SKIP_MISSING_START);
					return;
				}
				if ($scope === Constants::AUTO_CLOCK_OUT_SCOPE_TODAY
					&& $this->timeZoneService->dayKeyInStorage($start) !== $todayKey) {
					$this->countSkip($skipped, self::SKIP_PREVIOUS_DAY_START);
					return;
				}

				$resolution = $this->plannedEndResolver->resolve($userId, $entry, $cutover, $modelCache);
				if ($resolution['end'] === null) {
					$this->countSkip($skipped, $resolution['skip'] ?? 'unresolved');
					return;
				}
				$plannedEnd = $resolution['end'];

				if ($this->timeEntryMapper->findOverlapping($userId, $start, $plannedEnd, (int)$entry->getId()) !== []) {
					$this->countSkip($skipped, self::SKIP_OVERLAP_CONFLICT);
					return;
				}

				try {
					$updated = $this->timeTrackingService->autoClockOutAtPlannedEnd($userId, (int)$entry->getId(), $plannedEnd);
				} catch (MonthFinalizedException) {
					$this->countSkip($skipped, self::SKIP_MONTH_CLOSED);
					return;
				} catch (BusinessRuleException $e) {
					if ($e->getReasonCode() === BusinessRuleCode::NOT_CLOCKED_IN) {
						// Manual clock-out raced the job — entry already closed.
						$this->countSkip($skipped, self::SKIP_ALREADY_HANDLED);
					} else {
						$this->countSkip($skipped, 'business_rule');
						$this->logger->warning('ArbeitszeitCheck: auto clock-out rejected for user ' . $userId . ': ' . $e->getMessage(), ['app' => 'arbeitszeitcheck']);
					}
					return;
				}

				$this->afterClosed($userId, $updated);
				$closed++;
				$this->logger->info('ArbeitszeitCheck: auto clock-out applied', [
					'app' => 'arbeitszeitcheck',
					'user_id' => $userId,
					'entry_id' => $updated->getId(),
					'planned_end' => $plannedEnd->format('c'),
				]);
			} catch (\Throwable $e) {
				// One broken user must never abort the whole run.
				$errors++;
				$this->logger->error('ArbeitszeitCheck: auto clock-out failed for user ' . $userId . ': ' . $e->getMessage(), [
					'app' => 'arbeitszeitcheck',
					'exception' => $e,
				]);
			}
		});

		$this->logger->info('ArbeitszeitCheck: auto clock-out run finished', [
			'app' => 'arbeitszeitcheck',
			'closed' => $closed,
			'errors' => $errors,
			'skipped' => $skipped,
		]);
	}

	/**
	 * Configured cutover hour. Corrupt/non-numeric stored values fall back to
	 * the documented default rather than silently disabling the feature.
	 */
	private function configuredHour(): int
	{
		$raw = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_AUTO_CLOCK_OUT_HOUR, (string)Constants::AUTO_CLOCK_OUT_DEFAULT_HOUR);
		$hour = is_numeric($raw) ? (int)$raw : Constants::AUTO_CLOCK_OUT_DEFAULT_HOUR;
		return max(0, min(23, $hour));
	}

	/** @param array<string,int> $skipped */
	private function countSkip(array &$skipped, string $reason): void
	{
		$skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
	}

	/**
	 * User-facing evidence of the system intervention: notification (always,
	 * independent of notification preferences) + the status banner payload
	 * the app already renders as auto_clockout_notice.
	 */
	private function afterClosed(string $userId, TimeEntry $entry): void
	{
		$end = $entry->getEndTime();
		$date = $end !== null ? $this->timeZoneService->dayKeyInStorage($end) : null;
		$endDisplay = $end !== null ? $this->timeZoneService->formatForDisplay($end, 'H:i', $userId) : null;
		$dateDisplay = $end !== null ? $this->timeZoneService->formatForDisplay($end, 'd.m.Y', $userId) : null;

		try {
			$this->notificationService->notifyAutoClockOut($userId, [
				'id' => $entry->getId(),
				'date' => $date,
				'end_time' => $endDisplay,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('ArbeitszeitCheck: auto clock-out notification failed for user ' . $userId . ': ' . $e->getMessage(), ['app' => 'arbeitszeitcheck']);
		}

		try {
			$message = 'Your time tracking on ' . ($dateDisplay ?? '?') . ' was ended automatically at ' . ($endDisplay ?? '?') . ' (scheduled end). Please review the entry.';
			$this->config->setUserValue($userId, 'arbeitszeitcheck', 'auto_clockout_notice', json_encode([
				'message' => $message,
				'reason' => TimeEntry::ENDED_REASON_AUTO_PLANNED_END,
				'count' => 1,
				'at' => $this->timeZoneService->nowInStorage()->format('c'),
			]));
		} catch (\Throwable $e) {
			$this->logger->warning('ArbeitszeitCheck: auto clock-out notice write failed for user ' . $userId . ': ' . $e->getMessage(), ['app' => 'arbeitszeitcheck']);
		}
	}
}
