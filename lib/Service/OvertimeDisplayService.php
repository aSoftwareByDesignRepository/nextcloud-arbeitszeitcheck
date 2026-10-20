<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

/**
 * Single source of truth for overtime balance used in traffic-light classification
 * and read-only overtime summaries (dashboard, background job, manager, widgets).
 */
class OvertimeDisplayService
{
	public function __construct(
		private readonly OvertimeService $overtimeService,
		private readonly OvertimeBankService $bankService,
		private readonly OvertimeTrafficLightService $trafficLightService,
	) {
	}

	/**
	 * Balance used for balance traffic-light thresholds (YTD).
	 * Always uses OvertimeBankService effective Saldo: raw work delta, minus
	 * bank payouts when the bank is on, plus audited adjustments (Nullung).
	 * With no payouts and no adjustments this matches legacy cumulative YTD.
	 */
	public function getYearToDateBalanceForTrafficLight(string $userId): float
	{
		$bank = $this->bankService->getBankStatus($userId);

		return (float)($bank['effective_balance'] ?? 0.0);
	}

	/**
	 * @return array{
	 *   enabled: bool,
	 *   state: string,
	 *   direction: string|null,
	 *   level: string|null,
	 *   balance: float,
	 *   thresholds: array{yellow_over: float, red_over: float, yellow_under: float, red_under: float},
	 *   bank_enabled: bool,
	 *   bank_state: string|null,
	 *   needs_attention: bool
	 * }
	 */
	public function buildTrafficLightViewModel(string $userId): array
	{
		$bank = $this->bankService->getBankStatus($userId);
		$enabled = $this->trafficLightService->isEnabled();
		if (!$enabled) {
			return [
				'enabled' => false,
				'state' => 'green',
				'direction' => null,
				'level' => null,
				'balance' => $bank['enabled']
					? (float)$bank['effective_balance']
					: $this->getYearToDateBalanceForTrafficLight($userId),
				'thresholds' => $this->trafficLightService->getThresholds(),
				'bank_enabled' => $bank['enabled'],
				'bank_state' => $bank['enabled'] ? (string)($bank['bank_state'] ?? null) : null,
				'needs_attention' => false,
			];
		}

		$balance = $this->getYearToDateBalanceForTrafficLight($userId);
		$thresholds = $this->trafficLightService->getThresholds();
		$classification = $this->trafficLightService->classify($balance, $thresholds);
		$state = (string)($classification['state'] ?? 'green');

		return [
			'enabled' => true,
			'state' => $state,
			'direction' => $classification['direction'] ?? null,
			'level' => $classification['level'] ?? null,
			'balance' => $balance,
			'thresholds' => $thresholds,
			'bank_enabled' => $bank['enabled'],
			'bank_state' => $bank['enabled'] ? (string)($bank['bank_state'] ?? null) : null,
			'needs_attention' => $state !== 'green',
		];
	}

	/**
	 * Manager / team overview: compact overtime alert for one employee.
	 *
	 * @return array{
	 *   balance: float,
	 *   balance_label: string,
	 *   traffic_light_enabled: bool,
	 *   traffic_light_state: string,
	 *   traffic_light_needs_attention: bool,
	 *   bank_enabled: bool,
	 *   bank_state: string|null,
	 *   bank_fill_percent: float|null,
	 *   payout_eligible_hours: float|null,
	 *   needs_attention: bool,
	 *   attention_reasons: list<string>
	 * }
	 */
	public function buildManagerMemberSnapshot(string $userId): array
	{
		$snapshots = $this->buildManagerMemberSnapshots([$userId]);
		return $snapshots[$userId] ?? [];
	}

	/**
	 * Member snapshots for a set of users — one bank-status batch per call
	 * instead of up to three getBankStatus computations per user.
	 *
	 * @param list<string> $userIds
	 * @return array<string, array> userId => snapshot (same shape as buildManagerMemberSnapshot)
	 */
	public function buildManagerMemberSnapshots(array $userIds): array
	{
		$userIds = array_values(array_unique($userIds));
		if (empty($userIds)) {
			return [];
		}

		$banks = $this->bankService->getBankStatusForUsers($userIds);
		$tlEnabled = $this->trafficLightService->isEnabled();
		$thresholds = $tlEnabled ? $this->trafficLightService->getThresholds() : null;

		$snapshots = [];
		foreach ($userIds as $userId) {
			$bank = $banks[$userId] ?? null;
			if ($bank === null) {
				continue;
			}
			$snapshots[$userId] = $this->assembleMemberSnapshot($bank, $tlEnabled, $thresholds);
		}
		return $snapshots;
	}

	/**
	 * Snapshot assembly from one bank status. The traffic-light balance is the
	 * bank's effective balance in every branch (getYearToDateBalanceForTrafficLight
	 * returns exactly that), so no second bank computation is needed.
	 *
	 * @param array{yellow_over: float, red_over: float, yellow_under: float, red_under: float}|null $thresholds
	 */
	private function assembleMemberSnapshot(array $bank, bool $tlEnabled, ?array $thresholds): array
	{
		$balance = (float)($bank['effective_balance'] ?? 0.0);
		$state = 'green';
		$tlNeedsAttention = false;
		if ($tlEnabled && $thresholds !== null) {
			$classification = $this->trafficLightService->classify($balance, $thresholds);
			$state = (string)($classification['state'] ?? 'green');
			$tlNeedsAttention = $state !== 'green';
		}

		$reasons = [];
		if ($tlNeedsAttention) {
			$reasons[] = 'traffic_light';
		}

		$bankState = $bank['enabled'] ? (string)($bank['bank_state'] ?? '') : '';
		if ($bank['enabled'] && in_array($bankState, ['bank_yellow', 'bank_red', 'payout_eligible'], true)) {
			$reasons[] = 'bank_fill';
		}
		if ($bank['enabled'] && (float)($bank['payout_eligible_hours'] ?? 0) >= 0.01) {
			$reasons[] = 'payout_eligible';
		}

		return [
			'balance' => $balance,
			'balance_label' => $bank['enabled'] ? 'effective' : 'cumulative',
			'traffic_light_enabled' => $tlEnabled,
			'traffic_light_state' => $state,
			'traffic_light_needs_attention' => $tlNeedsAttention,
			'bank_enabled' => (bool)$bank['enabled'],
			'bank_state' => $bank['enabled'] ? $bankState : null,
			'bank_fill_percent' => $bank['enabled'] ? (float)($bank['bank_fill_percent'] ?? 0) : null,
			'payout_eligible_hours' => $bank['enabled'] ? (float)($bank['payout_eligible_hours'] ?? 0) : null,
			'needs_attention' => $reasons !== [],
			'attention_reasons' => $reasons,
		];
	}

	/**
	 * @param list<string> $userIds
	 * @return list<array<string, mixed>>
	 */
	public function buildManagerTeamAlerts(array $userIds, int $limit = 100): array
	{
		$snapshots = $this->buildManagerMemberSnapshots($userIds);
		$alerts = [];
		$count = 0;
		foreach ($userIds as $userId) {
			if ($count >= $limit) {
				break;
			}
			$snapshot = $snapshots[$userId] ?? null;
			if ($snapshot === null || !$snapshot['needs_attention']) {
				continue;
			}
			$alerts[] = array_merge(['user_id' => $userId], $snapshot);
			$count++;
		}

		usort($alerts, static function (array $a, array $b): int {
			$prio = ['red_over' => 0, 'red_under' => 1, 'payout_eligible' => 2, 'bank_red' => 3, 'yellow_over' => 4, 'yellow_under' => 5, 'bank_yellow' => 6];
			$sa = $prio[$a['traffic_light_state'] ?? ''] ?? $prio[$a['bank_state'] ?? ''] ?? 99;
			$sb = $prio[$b['traffic_light_state'] ?? ''] ?? $prio[$b['bank_state'] ?? ''] ?? 99;
			if ($sa !== $sb) {
				return $sa <=> $sb;
			}

			return strcasecmp((string)($a['user_id'] ?? ''), (string)($b['user_id'] ?? ''));
		});

		return $alerts;
	}
}
