<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\OvertimeDisplayService;
use OCA\ArbeitszeitCheck\Service\OvertimeNotificationMailService;
use OCA\ArbeitszeitCheck\Service\OvertimeTrafficLightService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class OvertimeTrafficLightJob extends TimedJob
{
	private const USER_VALUE_LAST_STATE = 'overtime_traffic_light_last_state';

	public function __construct(
		ITimeFactory $timeFactory,
		private OvertimeDisplayService $overtimeDisplayService,
		private OvertimeTrafficLightService $trafficLightService,
		private NotificationService $notificationService,
		private OvertimeNotificationMailService $mailService,
		private IUserManager $userManager,
		private IConfig $config,
		private PermissionService $permissionService,
		private LoggerInterface $logger,
		private ILockingProvider $locking,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void
	{
		// Shared lock on the upgrade-backup key: a restore holds it
		// exclusively (UpgradeBackupService::runExclusive) — app-table
		// scans/writes must not interleave with a mid-restore
		// truncate+reinsert. A skip is not a failure: TimedJobs re-run
		// on their interval.
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'overtime traffic-light check',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping overtime traffic-light check — upgrade backup/restore in progress.',
				['app' => 'arbeitszeitcheck'],
			);
			return;
		}
		try {
			$this->doRun($argument);
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}

	private function doRun($argument): void
	{
		if (!$this->trafficLightService->isEnabled()) {
			return;
		}

		$thresholds = $this->trafficLightService->getThresholds();
		$matrix = $this->readMatrix();
		$recipients = $this->readRecipients();
		$this->userManager->callForAllUsers(function ($user) use ($thresholds, $matrix, $recipients): void {
			$userId = $user->getUID();
			if (!$user->isEnabled() || !$this->permissionService->isUserAllowedByAccessGroups($userId)) {
				return;
			}
			try {
				$balance = $this->overtimeDisplayService->getYearToDateBalanceForTrafficLight($userId);
				$classification = $this->trafficLightService->classify($balance, $thresholds);
				$currentState = (string)$classification['state'];
				$lastState = $this->config->getUserValue($userId, 'arbeitszeitcheck', self::USER_VALUE_LAST_STATE, 'green');
				if ($currentState === $lastState || $currentState === 'green') {
					$this->config->setUserValue($userId, 'arbeitszeitcheck', self::USER_VALUE_LAST_STATE, $currentState);
					return;
				}

				$direction = (string)($classification['direction'] ?? '');
				$level = (string)($classification['level'] ?? '');
				if (!isset($matrix[$direction][$level]) || $matrix[$direction][$level] !== true) {
					$this->config->setUserValue($userId, 'arbeitszeitcheck', self::USER_VALUE_LAST_STATE, $currentState);
					return;
				}

				$payload = [
					'state' => $currentState,
					'direction' => $direction,
					'level' => $level,
					'balance' => $balance,
					'user_id' => $userId,
				];
				$this->notificationService->notifyOvertimeTrafficLight($userId, $payload);
				$this->mailService->sendTrafficLightNotification($recipients, $payload);
				$this->config->setUserValue($userId, 'arbeitszeitcheck', self::USER_VALUE_LAST_STATE, $currentState);
			} catch (\Throwable $e) {
				$this->logger->warning('Overtime traffic-light job failed for user', [
					'user_id' => $userId,
					'exception' => $e->getMessage(),
				]);
			}
		});
	}

	/**
	 * @return array<string, array<string, bool>>
	 */
	private function readMatrix(): array
	{
		$raw = json_decode($this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_OVERTIME_NOTIFICATION_MATRIX_V1, '[]'), true);
		$matrix = [];
		foreach (Constants::OVERTIME_DIRECTIONS as $direction) {
			$matrix[$direction] = [];
			$inType = (is_array($raw) && isset($raw[$direction]) && is_array($raw[$direction])) ? $raw[$direction] : [];
			foreach (Constants::OVERTIME_LEVELS as $level) {
				$matrix[$direction][$level] = ($inType[$level] ?? false) === true;
			}
		}
		return $matrix;
	}

	/**
	 * @return list<string>
	 */
	private function readRecipients(): array
	{
		$raw = (string)$this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_OVERTIME_NOTIFICATION_RECIPIENTS, '');
		$parts = explode(',', $raw);
		$unique = [];
		foreach ($parts as $part) {
			$email = strtolower(trim($part));
			if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				continue;
			}
			$unique[$email] = true;
		}
		return array_keys($unique);
	}
}

