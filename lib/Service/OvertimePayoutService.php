<?php

declare(strict_types=1);

/**
 * Month-end payout (Auszahlung) of overtime hours above the bank cap.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\OvertimePayout;
use OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper;
use OCP\IConfig;
use OCP\IUserManager;

class OvertimePayoutService
{
	/** Safety ceiling for payroll scans (no silent truncation below this). */
	private const MAX_PAYROLL_USERS = 5000;

	public function __construct(
		private readonly OvertimeBankService $bankService,
		private readonly OvertimePayoutMapper $payoutMapper,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly IUserManager $userManager,
		private readonly UserOvertimeSettingsService $overtimeSettingsService,
		private readonly PermissionService $permissionService,
		private readonly NotificationService $notificationService,
		private readonly OvertimePayoutMailService $payoutMailService,
		private readonly IConfig $config,
	) {
	}

	/**
	 * @return array{
	 *   year: int,
	 *   month: int,
	 *   items: list<array<string, mixed>>,
	 *   summary: array{pending_count: int, paid_count: int, pending_hours: float},
	 *   meta: array{total_users_in_scope: int, users_scanned: int, truncated: bool}
	 * }
	 */
	public function listMonthOverview(int $year, int $month): array
	{
		$this->assertValidYearMonth($year, $month);

		$userIds = $this->resolvePayrollUserIds();
		$totalInScope = count($userIds);
		$existingPayouts = $this->payoutMapper->findByYearAndMonth($year, $month);
		$payoutByUser = [];
		foreach ($existingPayouts as $payout) {
			$payoutByUser[$payout->getUserId()] = $payout;
		}

		$items = [];
		$pendingCount = 0;
		$pendingHours = 0.0;
		$paidCount = count($existingPayouts);

		$scanned = 0;
		$truncated = false;
		foreach ($userIds as $userId) {
			if ($scanned >= self::MAX_PAYROLL_USERS) {
				$truncated = true;
				break;
			}
			if ($this->userManager->get($userId) === null) {
				continue;
			}
			$scanned++;

			$item = $this->buildListItem($userId, $year, $month, $payoutByUser[$userId] ?? null);
			$items[] = $item;
			if (($item['status'] ?? '') === 'pending') {
				$pendingCount++;
				$pendingHours += (float)($item['payout_eligible_hours'] ?? 0);
			}
		}

		usort($items, static function (array $a, array $b): int {
			$statusOrder = ['pending' => 0, 'paid' => 1, 'none' => 2];
			$sa = $statusOrder[$a['status'] ?? 'none'] ?? 9;
			$sb = $statusOrder[$b['status'] ?? 'none'] ?? 9;
			if ($sa !== $sb) {
				return $sa <=> $sb;
			}
			return strcasecmp((string)($a['display_name'] ?? ''), (string)($b['display_name'] ?? ''));
		});

		return [
			'year' => $year,
			'month' => $month,
			'items' => $items,
			'summary' => [
				'pending_count' => $pendingCount,
				'paid_count' => $paidCount,
				'pending_hours' => round($pendingHours, 2),
			],
			'meta' => [
				'total_users_in_scope' => $totalInScope,
				'users_scanned' => $scanned,
				'truncated' => $truncated,
			],
		];
	}

	/**
	 * Employee self-service: recorded payout history (newest first).
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function listPayoutHistoryForUser(string $userId, int $limit = 50, int $offset = 0): array
	{
		$entities = $this->payoutMapper->findByUser($userId, $limit, $offset);
		$items = [];
		foreach ($entities as $entity) {
			$row = $this->entityToArray($entity);
			$row['period'] = sprintf('%04d-%02d', $entity->getCalendarYear(), $entity->getCalendarMonth());
			$items[] = $row;
		}

		return [
			'items' => $items,
			'total' => $this->payoutMapper->countByUser($userId),
		];
	}

	/**
	 * All enabled app users in access groups (Personio-style: whole workforce in scope).
	 *
	 * @return list<string>
	 */
	private function resolvePayrollUserIds(): array
	{
		$ids = [];
		$this->userManager->callForAllUsers(function (\OCP\IUser $user) use (&$ids): void {
			if ($user->isEnabled() !== true) {
				return;
			}
			$uid = $user->getUID();
			if (!$this->permissionService->isUserAllowedByAccessGroups($uid)) {
				return;
			}
			$ids[] = $uid;
		});
		sort($ids, SORT_STRING | SORT_FLAG_CASE);

		return $ids;
	}

	/**
	 * Payroll audit export (UTF-8 CSV).
	 */
	public function buildPayrollCsv(int $year, int $month): string
	{
		$data = $this->listMonthOverview($year, $month);
		$lines = [];
		$lines[] = implode(';', [
			'user_id',
			'display_name',
			'status',
			'payout_eligible_hours',
			'hours_paid',
			'effective_balance_before',
			'effective_balance_after',
			'raw_balance',
			'bank_max_hours',
			'processed_at',
			'payout_id',
		]);

		foreach ($data['items'] as $row) {
			if (($row['status'] ?? '') === 'none') {
				continue;
			}
			$lines[] = implode(';', [
				$this->csvCell((string)($row['user_id'] ?? '')),
				$this->csvCell((string)($row['display_name'] ?? '')),
				$this->csvCell((string)($row['status'] ?? '')),
				$this->csvCell(isset($row['payout_eligible_hours']) ? number_format((float)$row['payout_eligible_hours'], 2, '.', '') : ''),
				$this->csvCell(isset($row['hours_paid']) ? number_format((float)$row['hours_paid'], 2, '.', '') : ''),
				$this->csvCell(isset($row['effective_balance_before']) ? number_format((float)$row['effective_balance_before'], 2, '.', '') : ''),
				$this->csvCell(isset($row['effective_balance_after']) ? number_format((float)$row['effective_balance_after'], 2, '.', '') : ''),
				$this->csvCell(isset($row['raw_balance']) ? number_format((float)$row['raw_balance'], 2, '.', '') : ''),
				$this->csvCell(isset($row['bank_max_hours']) ? number_format((float)$row['bank_max_hours'], 2, '.', '') : ''),
				$this->csvCell((string)($row['processed_at'] ?? '')),
				$this->csvCell(isset($row['payout_id']) ? (string)$row['payout_id'] : ''),
			]);
		}

		return "\xEF\xBB\xBF" . implode("\n", $lines) . "\n";
	}

	/**
	 * @return array{action: string, payout?: array<string, mixed>, message?: string, user_id?: string}
	 */
	public function processPayout(string $userId, int $year, int $month, string $actorUserId, bool $dryRun = false): array
	{
		$this->assertValidYearMonth($year, $month);
		$this->assertMonthEnded($year, $month);

		if (!$this->bankService->isEnabled()) {
			return ['action' => 'error', 'message' => 'overtime_bank_disabled'];
		}

		if ($this->userManager->get($userId) === null) {
			return ['action' => 'error', 'message' => 'user_not_found'];
		}

		if ($this->payoutMapper->existsForUserAndMonth($userId, $year, $month)) {
			return ['action' => 'skipped_already_paid', 'user_id' => $userId];
		}

		$snapshot = $this->bankService->getMonthEndSnapshot($userId, $year, $month);
		$eligible = (float)$snapshot['payout_eligible_hours'];

		if ($eligible < 0.01) {
			return ['action' => 'skipped_zero', 'user_id' => $userId, 'payout_eligible_hours' => $eligible];
		}

		$bankMax = (float)$snapshot['bank_max_hours'];
		$effectiveBefore = (float)$snapshot['effective_balance'];
		$effectiveAfter = round(min($effectiveBefore, $bankMax), 2);

		if ($dryRun) {
			return [
				'action' => 'dry_run',
				'user_id' => $userId,
				'payout' => [
					'hours_paid' => round($eligible, 2),
					'effective_balance_before' => $effectiveBefore,
					'effective_balance_after' => $effectiveAfter,
				],
			];
		}

		$entity = new OvertimePayout();
		$entity->setUserId($userId);
		$entity->setCalendarYear($year);
		$entity->setCalendarMonth($month);
		$entity->setHoursPaid(round($eligible, 2));
		$entity->setEffectiveBalanceBefore($effectiveBefore);
		$entity->setEffectiveBalanceAfter($effectiveAfter);
		$entity->setRawBalanceBefore((float)$snapshot['raw_balance']);
		$entity->setBankMaxHours($bankMax);
		$entity->setProcessedBy($actorUserId);
		$entity->setCreatedAt(new \DateTime());

		try {
			$saved = $this->payoutMapper->insertPayout($entity);
		} catch (\OCP\DB\Exception $e) {
			if ($this->payoutMapper->existsForUserAndMonth($userId, $year, $month)) {
				return ['action' => 'skipped_already_paid', 'user_id' => $userId];
			}
			throw $e;
		}
		$payoutArray = $this->entityToArray($saved);

		$this->auditLogMapper->logAction(
			$userId,
			'overtime_payout_processed',
			'overtime_payout',
			$saved->getId(),
			null,
			[
				'year' => $year,
				'month' => $month,
				'hours_paid' => $saved->getHoursPaid(),
				'effective_balance_before' => $saved->getEffectiveBalanceBefore(),
				'effective_balance_after' => $saved->getEffectiveBalanceAfter(),
				'bank_max_hours' => $saved->getBankMaxHours(),
				'raw_balance_before' => $saved->getRawBalanceBefore(),
			],
			$actorUserId
		);

		$this->notifyEmployeeAfterPayout($userId, $payoutArray);

		return [
			'action' => 'paid',
			'user_id' => $userId,
			'payout' => $payoutArray,
		];
	}

	/**
	 * @param list<string>|null $userIds null = all tracked users with eligible hours
	 * @return array{processed: int, skipped: int, errors: int, results: list<array<string, mixed>>}
	 */
	public function processBulkPayouts(int $year, int $month, string $actorUserId, ?array $userIds = null, bool $dryRun = false): array
	{
		$this->assertValidYearMonth($year, $month);
		$this->assertMonthEnded($year, $month);

		$targets = $userIds ?? $this->resolvePayrollUserIds();
		$processed = 0;
		$skipped = 0;
		$errors = 0;
		$results = [];

		foreach ($targets as $userId) {
			$result = $this->processPayout($userId, $year, $month, $actorUserId, $dryRun);
			$results[] = $result;
			$action = (string)($result['action'] ?? '');
			if ($action === 'paid' || $action === 'dry_run') {
				$processed++;
			} elseif (str_starts_with($action, 'skipped')) {
				$skipped++;
			} elseif ($action === 'error') {
				$errors++;
			}
		}

		return [
			'processed' => $processed,
			'skipped' => $skipped,
			'errors' => $errors,
			'results' => $results,
		];
	}

	/**
	 * @param array<string, mixed> $payout
	 */
	private function notifyEmployeeAfterPayout(string $userId, array $payout): void
	{
		if ($this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_OVERTIME_PAYOUT_NOTIFY_IN_APP, '1') === '1') {
			try {
				$this->notificationService->notifyOvertimePayout($userId, $payout);
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->warning('Overtime payout in-app notification failed', [
					'app' => 'arbeitszeitcheck',
					'user_id' => $userId,
					'exception' => $e,
				]);
			}
		}

		$user = $this->userManager->get($userId);
		if ($user !== null) {
			$this->payoutMailService->sendEmployeePayoutConfirmation($user, $payout);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildListItem(string $userId, int $year, int $month, ?OvertimePayout $existing): array
	{
		$user = $this->userManager->get($userId);
		$displayName = $user !== null ? $user->getDisplayName() : $userId;

		if ($existing !== null) {
			return [
				'user_id' => $userId,
				'display_name' => $displayName,
				'status' => 'paid',
				'payout_id' => $existing->getId(),
				'hours_paid' => round((float)$existing->getHoursPaid(), 2),
				'effective_balance_before' => round((float)$existing->getEffectiveBalanceBefore(), 2),
				'effective_balance_after' => round((float)$existing->getEffectiveBalanceAfter(), 2),
				'raw_balance_before' => round((float)$existing->getRawBalanceBefore(), 2),
				'bank_max_hours' => round((float)$existing->getBankMaxHours(), 2),
				'processed_by' => $existing->getProcessedBy(),
				'processed_at' => $existing->getCreatedAt()?->format('c'),
			];
		}

		if (!$this->bankService->isEnabled()) {
			return [
				'user_id' => $userId,
				'display_name' => $displayName,
				'status' => 'none',
				'payout_eligible_hours' => 0.0,
			];
		}

		$snapshot = $this->bankService->getMonthEndSnapshot($userId, $year, $month);
		$eligible = (float)$snapshot['payout_eligible_hours'];

		$hasStichtag = $this->overtimeSettingsService->hasTrackingFrom($userId);

		return [
			'user_id' => $userId,
			'display_name' => $displayName,
			'has_overtime_tracking_from' => $hasStichtag,
			'status' => $eligible >= 0.01 ? 'pending' : 'none',
			'payout_eligible_hours' => $eligible,
			'effective_balance' => (float)$snapshot['effective_balance'],
			'raw_balance' => (float)$snapshot['raw_balance'],
			'bank_max_hours' => (float)$snapshot['bank_max_hours'],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function entityToArray(OvertimePayout $entity): array
	{
		return [
			'id' => $entity->getId(),
			'user_id' => $entity->getUserId(),
			'calendar_year' => $entity->getCalendarYear(),
			'calendar_month' => $entity->getCalendarMonth(),
			'hours_paid' => round((float)$entity->getHoursPaid(), 2),
			'effective_balance_before' => round((float)$entity->getEffectiveBalanceBefore(), 2),
			'effective_balance_after' => round((float)$entity->getEffectiveBalanceAfter(), 2),
			'raw_balance_before' => round((float)$entity->getRawBalanceBefore(), 2),
			'bank_max_hours' => round((float)$entity->getBankMaxHours(), 2),
			'processed_by' => $entity->getProcessedBy(),
			'created_at' => $entity->getCreatedAt()?->format('c'),
		];
	}

	private function csvCell(string $value): string
	{
		// Neutralize formula injection in spreadsheet clients.
		if ($value !== '' && preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
			$value = "'" . $value;
		}
		if (str_contains($value, ';') || str_contains($value, '"') || str_contains($value, "\n")) {
			return '"' . str_replace('"', '""', $value) . '"';
		}

		return $value;
	}

	private function assertValidYearMonth(int $year, int $month): void
	{
		if ($year < 2000 || $year > 2100) {
			throw new \InvalidArgumentException('Year out of range.');
		}
		if ($month < 1 || $month > 12) {
			throw new \InvalidArgumentException('Month must be between 1 and 12.');
		}
	}

	private function assertMonthEnded(int $year, int $month): void
	{
		$lastDay = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
		$lastDay->modify('last day of this month');
		$lastDay->setTime(23, 59, 59);
		$now = new \DateTime('now');
		if ($lastDay > $now) {
			throw new \InvalidArgumentException('Payout is only allowed after the calendar month has ended.');
		}
	}
}
