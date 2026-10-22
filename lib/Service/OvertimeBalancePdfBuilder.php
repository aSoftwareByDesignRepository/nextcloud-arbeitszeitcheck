<?php

declare(strict_types=1);

/**
 * Printable year-to-date overtime (Saldo) PDF for the employee dashboard.
 * Uses the same balance numbers as OvertimeDisplayService / bank status.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCP\IL10N;

final class OvertimeBalancePdfBuilder
{
	/**
	 * @param array{
	 *   balance: float,
	 *   balance_label: string,
	 *   bank_enabled: bool,
	 *   bank?: array<string, mixed>,
	 *   as_of?: string,
	 *   display_name?: string,
	 *   user_id?: string
	 * } $data
	 */
	public static function build(array $data, IL10N $l): string
	{
		$displayName = trim((string)($data['display_name'] ?? ''));
		$userId = trim((string)($data['user_id'] ?? ''));
		$asOf = (string)($data['as_of'] ?? date('Y-m-d'));
		$balance = (float)($data['balance'] ?? 0.0);
		$bankEnabled = !empty($data['bank_enabled']);
		$bank = is_array($data['bank'] ?? null) ? $data['bank'] : [];

		$title = $l->t('Overtime balance (Saldo)');
		$lines = [];
		$lines[] = $l->t('Generated on %s', [$asOf]);
		if ($displayName !== '' || $userId !== '') {
			$who = $displayName !== '' ? $displayName : $userId;
			if ($displayName !== '' && $userId !== '' && $displayName !== $userId) {
				$who = $displayName . ' (' . $userId . ')';
			}
			$lines[] = $l->t('Employee: %s', [$who]);
		}
		$lines[] = '';
		$lines[] = $l->t('Worked hours minus your contract target (this year). This is an hour balance — not pay.');
		$lines[] = '';

		$sign = $balance >= 0 ? '+' : '';
		$balanceLabel = $bankEnabled
			? $l->t('Balance (after payouts)')
			: $l->t('Balance (year to date)');
		$lines[] = $balanceLabel . ': ' . $sign . number_format($balance, 2, '.', '') . ' ' . $l->t('h');

		if ($bankEnabled) {
			$lines[] = '';
			$lines[] = $l->t('Overtime bank');
			$lines[] = $l->t('Banked hours') . ': ' . number_format((float)($bank['banked_hours'] ?? 0), 2, '.', '') . ' ' . $l->t('h');
			$lines[] = $l->t('Bank maximum') . ': ' . number_format((float)($bank['bank_max_hours'] ?? 0), 2, '.', '') . ' ' . $l->t('h');
			$payout = (float)($bank['payout_eligible_hours'] ?? 0);
			if ($payout >= 0.01) {
				$lines[] = $l->t('Eligible for payout') . ': ' . number_format($payout, 2, '.', '') . ' ' . $l->t('h');
			}
			$ytdPayouts = (float)($bank['total_payouts_ytd'] ?? 0);
			if ($ytdPayouts >= 0.01) {
				$lines[] = $l->t('Already paid out this year: %s h', [number_format($ytdPayouts, 2, '.', '')]);
			}
		}

		$lines[] = '';
		$lines[] = $l->t('This PDF mirrors the balance shown on your ArbeitszeitCheck dashboard.');

		return MinimalPdfBuilder::build($title, $lines);
	}
}
