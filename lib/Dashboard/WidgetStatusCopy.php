<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Dashboard;

use OCP\IL10N;

/**
 * Shared, scannable copy for Nextcloud dashboard status widgets.
 *
 * Keeps titles/subtitles short so NC panel headers and item lists stay readable
 * without dumping comma-separated walls of text into halfEmptyContentMessage.
 */
final class WidgetStatusCopy {
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}

	public function statusLabel(string $status): string {
		return match ($status) {
			'active' => $this->l10n->t('Working'),
			'break' => $this->l10n->t('On Break'),
			'paused' => $this->l10n->t('Paused'),
			default => $this->l10n->t('Clocked Out'),
		};
	}

	/**
	 * Person row: "Working · 3.5 h" — no redundant Status:/Today: prefixes.
	 */
	public function personSubtitle(string $status, float $hoursToday): string {
		return $this->l10n->t('%1$s · %2$s h', [
			$this->statusLabel($status),
			$this->formatHours($hoursToday),
		]);
	}

	/**
	 * Hero title: "12 of 48 working".
	 */
	public function workingHeadline(int $working, int $total): string {
		return $this->l10n->t('%1$d of %2$d working', [$working, $total]);
	}

	/**
	 * Hero subtitle: only non-zero secondary states, joined with middots.
	 *
	 * @param array{break?:int,paused?:int,clocked_out?:int} $summary
	 * @param array{total_absent?:int,vacation?:int,sick?:int,other_absent?:int} $absence
	 */
	public function summarySubtitle(array $summary, array $absence): string {
		$bits = [];
		$break = (int)($summary['break'] ?? 0);
		$paused = (int)($summary['paused'] ?? 0);
		$out = (int)($summary['clocked_out'] ?? 0);
		$away = (int)($absence['total_absent'] ?? 0);

		if ($break > 0) {
			$bits[] = $this->l10n->t('%1$d on break', [$break]);
		}
		if ($paused > 0) {
			$bits[] = $this->l10n->t('%1$d paused', [$paused]);
		}
		if ($out > 0) {
			$bits[] = $this->l10n->n('1 out', '%n out', $out);
		}
		if ($away > 0) {
			$bits[] = $this->absenceBit($absence);
		}

		if ($bits === []) {
			return $this->l10n->t('Nobody clocked in yet');
		}

		return implode(' · ', $bits);
	}

	/**
	 * Short truncation note for halfEmptyContentMessage (never a metric dump).
	 */
	public function truncationNote(int $scopeLimit, int $directoryTotal): string {
		return $this->l10n->t(
			'Showing counts for the first %1$d of %2$d people.',
			[$scopeLimit, $directoryTotal]
		);
	}

	/**
	 * Status sort rank: working first, then break, paused, out.
	 */
	public function statusRank(string $status): int {
		return match ($status) {
			'active' => 0,
			'break' => 1,
			'paused' => 2,
			default => 3,
		};
	}

	/**
	 * @param list<array{status?:string,displayName?:string,userId?:string}> $people
	 * @return list<array{status?:string,displayName?:string,userId?:string}>
	 */
	public function sortPeopleByStatus(array $people): array {
		usort($people, function (array $a, array $b): int {
			$rank = $this->statusRank((string)($a['status'] ?? 'clocked_out'))
				<=> $this->statusRank((string)($b['status'] ?? 'clocked_out'));
			if ($rank !== 0) {
				return $rank;
			}
			return strcasecmp(
				(string)($a['displayName'] ?? $a['userId'] ?? ''),
				(string)($b['displayName'] ?? $b['userId'] ?? '')
			);
		});

		return $people;
	}

	public function formatHours(float $hours): string {
		$rounded = round($hours, 2);
		if (abs($rounded - round($rounded)) < 0.001) {
			return (string)(int)round($rounded);
		}

		return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
	}

	/**
	 * @param array{total_absent?:int,vacation?:int,sick?:int,other_absent?:int} $absence
	 */
	private function absenceBit(array $absence): string {
		$away = (int)($absence['total_absent'] ?? 0);
		$vacation = (int)($absence['vacation'] ?? 0);
		$sick = (int)($absence['sick'] ?? 0);
		$other = (int)($absence['other_absent'] ?? 0);

		$detail = [];
		if ($vacation > 0) {
			$detail[] = $this->l10n->n('1 vacation', '%n vacation', $vacation);
		}
		if ($sick > 0) {
			$detail[] = $this->l10n->n('1 sick', '%n sick', $sick);
		}
		if ($other > 0) {
			$detail[] = $this->l10n->n('1 other', '%n other', $other);
		}

		if ($detail === []) {
			return $this->l10n->n('1 away', '%n away', $away);
		}

		return sprintf(
			$this->l10n->n('1 away (%1$s)', '%n away (%1$s)', $away),
			implode(', ', $detail)
		);
	}
}
