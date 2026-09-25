<?php

declare(strict_types=1);

/**
 * Human-readable month-closure PDF: sections, tables, PDF metadata, multi-page.
 * User-controlled strings go through MinimalPdfBuilder::escapePdfText.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\MonthClosure;
use OCP\IL10N;

final class MonthClosurePdfDocumentBuilder
{
	private const PAGE_W = 612.0;

	private const PAGE_H = 792.0;

	private const MARGIN_X = 48.0;

	private const MARGIN_BOTTOM = 72.0;

	private const MARGIN_TOP_FIRST = 48.0;

	private const FOOTER_Y = 34.0;

	private const FONT_BODY = 9.0;

	private const FONT_SMALL = 7.0;

	private const FONT_SECTION = 11.0;

	private const FONT_TITLE = 15.0;

	private const LINE_BODY = 11.5;

	private const LINE_SMALL = 8.5;

	private const LINE_SECTION = 14.0;

	/** Body text width for integrity note (approx. chars per line at 9pt). */
	private const INTEGRITY_WRAP = 98;

	/** @var list<string> */
	private array $pageBuffers = [];

	private string $buf = '';

	private float $y = 0.0;

	private IL10N $l;

	private string $pdfLangTag = 'en-US';

	private string $footerPeriod = '';

	private string $footerHashShort = '';

	/**
	 * @param array<string, mixed> $snap
	 */
	public static function build(array $snap, MonthClosure $row, string $displayName, string $userId, IL10N $l, string $finalizedByDisplay): string
	{
		$doc = new self();
		$doc->l = $l;
		$lang = $l->getLanguageCode();
		$doc->pdfLangTag = (str_starts_with(strtolower($lang), 'de')) ? 'de-DE' : 'en-US';
		$doc->run($snap, $row, $displayName, $userId, $finalizedByDisplay);

		return $doc->assemblePdf($snap, $displayName);
	}

	/**
	 * @param array<string, mixed> $snap
	 */
	private function run(array $snap, MonthClosure $row, string $displayName, string $userId, string $finalizedByDisplay): void
	{
		$this->newPage(true);
		$ym = (int)($snap['month'] ?? 0);
		$yy = (int)($snap['year'] ?? 0);
		$periodLabel = sprintf('%04d-%02d', $yy, $ym);
		$this->footerPeriod = $periodLabel;
		$h = (string)($row->getSnapshotHash() ?? '');
		$this->footerHashShort = $h !== '' ? (mb_substr($h, 0, 16) . '...') : '-';

		$this->text(self::MARGIN_X, $this->y, 2, self::FONT_TITLE, $this->l->t('month_closure_pdf_title', [$periodLabel]));
		$this->y -= self::FONT_TITLE + 10;
		$this->text(self::MARGIN_X, $this->y, 1, self::FONT_BODY, $this->l->t('month_closure_pdf_subtitle'));
		$this->y -= self::LINE_BODY + 16;

		$this->section($this->l->t('month_closure_pdf_section_identification'));
		$pStart = (string)($snap['period']['start'] ?? '');
		$pEnd = (string)($snap['period']['end'] ?? '');
		$pairs = [
			[$this->l->t('month_closure_pdf_label_name_id'), $displayName . ' (' . $userId . ')'],
			[$this->l->t('month_closure_pdf_label_period'), $pStart . ' - ' . $pEnd],
			[$this->l->t('month_closure_pdf_label_snapshot_hash'), (string)($row->getSnapshotHash() ?? '')],
			[$this->l->t('month_closure_pdf_label_prev_hash'), ($row->getPrevSnapshotHash() !== null && $row->getPrevSnapshotHash() !== '') ? (string)$row->getPrevSnapshotHash() : $this->l->t('month_closure_pdf_none_parentheses')],
			[$this->l->t('month_closure_pdf_label_version'), (string)$row->getVersion()],
			[$this->l->t('month_closure_pdf_label_schema'), (string)($snap['schema'] ?? '')],
		];
		$at = $row->getFinalizedAt();
		if ($at !== null) {
			$utc = clone $at;
			$utc->setTimezone(new \DateTimeZone('UTC'));
			$pairs[] = [$this->l->t('month_closure_pdf_label_finalized_at_utc'), $utc->format('Y-m-d\TH:i:s\Z')];
		}
		if ($finalizedByDisplay !== '') {
			$pairs[] = [$this->l->t('month_closure_pdf_label_finalized_by'), $finalizedByDisplay];
		}
		$this->keyValues($pairs);

		$rep = $snap['report'] ?? [];
		if (!is_array($rep)) {
			$rep = [];
		}
		$this->ensureSpace(100);
		$this->section($this->l->t('month_closure_pdf_section_summary'));
		$rows = [
			[$this->l->t('month_closure_pdf_label_total_hours'), $this->fmtNum($rep['total_hours'] ?? null) . ' ' . $this->l->t('month_closure_pdf_hours_suffix')],
			[$this->l->t('month_closure_pdf_label_break_hours'), $this->fmtNum($rep['total_break_hours'] ?? null) . ' ' . $this->l->t('month_closure_pdf_hours_suffix')],
			[$this->l->t('month_closure_pdf_label_working_days'), (string)($rep['working_days'] ?? '-')],
			[$this->l->t('month_closure_pdf_label_overtime'), $this->fmtNum($rep['total_overtime'] ?? null) . ' ' . $this->l->t('month_closure_pdf_hours_suffix')],
			[$this->l->t('month_closure_pdf_label_violations'), (string)($rep['violations_count'] ?? '0')],
		];
		$hol = $rep['holiday_summary'] ?? null;
		if (is_array($hol)) {
			$rows[] = [
				$this->l->t('month_closure_pdf_label_holidays'),
				$this->l->t('month_closure_pdf_holidays_detail', [
					$this->fmtNum($hol['holiday_days'] ?? null),
					$this->fmtNum($hol['holiday_work_hours'] ?? null),
				]),
			];
		}
		$this->keyValues($rows);

		$bank = $snap['overtime_bank'] ?? null;
		if (is_array($bank) && !empty($bank['enabled'])) {
			$this->renderOvertimeBankSection($bank);
		}

		$premium = $snap['premium'] ?? null;
		if (is_array($premium) && !empty($premium['enabled'])) {
			$this->renderPremiumSection($premium);
		}

		$entries = $snap['time_entries'] ?? [];
		if (!is_array($entries)) {
			$entries = [];
		}
		usort($entries, static function ($a, $b) {
			return strcmp((string)($a['start'] ?? ''), (string)($b['start'] ?? ''));
		});

		$this->ensureSpace(90);
		$this->section($this->l->t('month_closure_pdf_section_time_entries'));
		$headers = [
			$this->l->t('month_closure_pdf_col_nr'),
			$this->l->t('month_closure_pdf_col_date'),
			$this->l->t('month_closure_pdf_col_from'),
			$this->l->t('month_closure_pdf_col_to'),
			$this->l->t('month_closure_pdf_col_status'),
			$this->l->t('month_closure_pdf_col_kind'),
			$this->l->t('month_closure_pdf_col_breaks'),
			$this->l->t('month_closure_pdf_col_remark'),
		];
		$widths = [22.0, 54.0, 40.0, 40.0, 64.0, 44.0, 86.0, 166.0];
		$data = [];
		if ($entries === []) {
			$data[] = ['-', $this->l->t('month_closure_pdf_no_entries'), '-', '-', '-', '-', '-', '-'];
		} else {
			$i = 0;
			foreach ($entries as $e) {
				if (!is_array($e)) {
					continue;
				}
				$i++;
				$data[] = [
					(string)$i,
					$this->entryDateShort($e['start'] ?? null),
					$this->fmtClock($e['start'] ?? null),
					$this->fmtClock($e['end'] ?? null),
					$this->labelTimeEntryStatus((string)($e['status'] ?? '')),
					!empty($e['is_manual']) ? $this->l->t('month_closure_pdf_kind_manual') : $this->l->t('month_closure_pdf_kind_auto'),
					$this->pausenCell($e),
					$this->truncate((string)($e['description'] ?? ''), 320),
				];
			}
		}
		$this->table($headers, $widths, $data);

		$absences = $snap['absences'] ?? [];
		if (!is_array($absences)) {
			$absences = [];
		}
		$this->ensureSpace(90);
		$this->section($this->l->t('month_closure_pdf_section_absences'));
		$h2 = [
			$this->l->t('month_closure_pdf_col_nr'),
			$this->l->t('Type'),
			$this->l->t('Start Date'),
			$this->l->t('End Date'),
			$this->l->t('Days'),
			$this->l->t('Status'),
		];
		$w2 = [28.0, 100.0, 84.0, 84.0, 40.0, 172.0];
		$d2 = [];
		if ($absences === []) {
			$d2[] = ['-', '-', '-', '-', '-', $this->l->t('month_closure_pdf_no_entries')];
		} else {
			$j = 0;
			foreach ($absences as $a) {
				if (!is_array($a)) {
					continue;
				}
				$j++;
				$d2[] = [
					(string)$j,
					$this->labelAbsenceType((string)($a['type'] ?? '-')),
					(string)($a['start_date'] ?? '-'),
					(string)($a['end_date'] ?? '-'),
					(string)($a['days'] ?? '-'),
					$this->labelAbsenceStatus((string)($a['status'] ?? '-')),
				];
			}
		}
		$this->table($h2, $w2, $d2);

		$this->ensureSpace(80);
		$this->section($this->l->t('month_closure_pdf_section_integrity'));
		$this->integrityNote();

		$this->flushPage();
		$this->applyPageFooters();
	}

	private function labelTimeEntryStatus(string $code): string
	{
		return match ($code) {
			'completed' => $this->l->t('Completed'),
			'active' => $this->l->t('Active'),
			'break' => $this->l->t('Break'),
			'paused' => $this->l->t('Paused'),
			'pending_approval' => $this->l->t('Pending Approval'),
			'rejected' => $this->l->t('Rejected'),
			'' => '-',
			default => $code,
		};
	}

	private function labelAbsenceType(string $type): string
	{
		return match ($type) {
			'vacation' => $this->l->t('Vacation'),
			'sick_leave' => $this->l->t('Sick leave'),
			'personal_leave' => $this->l->t('Personal Leave'),
			'parental_leave' => $this->l->t('Parental leave'),
			'special_leave' => $this->l->t('Special leave'),
			'unpaid_leave' => $this->l->t('Unpaid leave'),
			'home_office' => $this->l->t('Home office'),
			'business_trip' => $this->l->t('Business trip'),
			default => $type,
		};
	}

	/**
	 * @param array<string, mixed> $bank
	 */
	private function renderOvertimeBankSection(array $bank): void
	{
		$this->ensureSpace(120);
		$this->section($this->l->t('month_closure_pdf_section_overtime_bank'));

		$h = $this->l->t('month_closure_pdf_hours_suffix');
		$rows = [
			[$this->l->t('month_closure_pdf_label_ot_bank_max'), $this->fmtNum($bank['bank_max_hours'] ?? null) . ' ' . $h],
			[$this->l->t('month_closure_pdf_label_ot_raw_eom'), $this->fmtNum($bank['raw_balance_eom'] ?? null) . ' ' . $h],
			[$this->l->t('month_closure_pdf_label_ot_effective_eom'), $this->fmtNum($bank['effective_balance_eom'] ?? null) . ' ' . $h],
			[$this->l->t('month_closure_pdf_label_ot_banked_eom'), $this->fmtNum($bank['banked_hours_eom'] ?? null) . ' ' . $h],
			[$this->l->t('month_closure_pdf_label_ot_payout_eligible_eom'), $this->fmtNum($bank['payout_eligible_eom'] ?? null) . ' ' . $h],
		];

		$payout = $bank['payout_record'] ?? null;
		$supplementary = $bank['supplementary_payout'] ?? null;
		if (is_array($payout)) {
			$rows[] = [$this->l->t('month_closure_pdf_label_ot_payout_status'), $this->l->t('month_closure_pdf_ot_payout_recorded_at_closure')];
			$rows[] = [$this->l->t('month_closure_pdf_label_ot_hours_paid'), $this->fmtNum($payout['hours_paid'] ?? null) . ' ' . $h];
			$rows[] = [$this->l->t('month_closure_pdf_label_ot_payout_id'), (string)($payout['id'] ?? '-')];
			$rows[] = [$this->l->t('month_closure_pdf_label_ot_processed_by'), (string)($payout['processed_by'] ?? '-')];
		} elseif (is_array($supplementary)) {
			$rows[] = [$this->l->t('month_closure_pdf_label_ot_payout_status'), $this->l->t('month_closure_pdf_ot_payout_recorded_after_closure')];
			$rows[] = [$this->l->t('month_closure_pdf_label_ot_hours_paid'), $this->fmtNum($supplementary['hours_paid'] ?? null) . ' ' . $h];
			$rows[] = [$this->l->t('month_closure_pdf_label_ot_payout_id'), (string)($supplementary['id'] ?? '-')];
			$rows[] = [$this->l->t('month_closure_pdf_label_ot_payout_at'), (string)($supplementary['created_at'] ?? '-')];
		} else {
			$rows[] = [$this->l->t('month_closure_pdf_label_ot_payout_status'), $this->l->t('month_closure_pdf_ot_payout_none')];
		}

		$this->keyValues($rows);
	}

	/**
	 * Frozen hour-premium snapshot (orthogonal to Saldo / Auszahlung).
	 *
	 * @param array<string, mixed> $premium
	 */
	private function renderPremiumSection(array $premium): void
	{
		$this->ensureSpace(140);
		$this->section($this->l->t('month_closure_pdf_section_premium'));

		$h = $this->l->t('month_closure_pdf_hours_suffix');
		$summary = is_array($premium['summary'] ?? null) ? $premium['summary'] : [];
		$rows = [
			[$this->l->t('month_closure_pdf_label_premium_policy_version'), (string)(int)($premium['policy_version'] ?? 0)],
			[$this->l->t('month_closure_pdf_label_premium_classified'), $this->fmtNum($summary['total_classified_hours'] ?? 0) . ' ' . $h],
			[$this->l->t('month_closure_pdf_label_premium_valued'), $this->fmtNum($summary['total_valued_hours'] ?? 0) . ' ' . $h],
			[$this->l->t('month_closure_pdf_label_premium_note'), $this->l->t('month_closure_pdf_premium_orthogonal_note')],
		];
		$this->keyValues($rows);

		$buckets = is_array($summary['buckets'] ?? null) ? $summary['buckets'] : [];
		$headers = [
			$this->l->t('month_closure_pdf_col_premium_category'),
			$this->l->t('month_closure_pdf_col_premium_hours'),
			$this->l->t('month_closure_pdf_col_premium_rate'),
			$this->l->t('month_closure_pdf_col_premium_valued'),
		];
		$widths = [200.0, 90.0, 90.0, 136.0];
		$data = [];
		if ($buckets === []) {
			$data[] = [
				$this->l->t('month_closure_pdf_premium_no_buckets'),
				'-',
				'-',
				'-',
			];
		} else {
			foreach ($buckets as $bucket) {
				if (!is_array($bucket)) {
					continue;
				}
				$rate = (float)($bucket['rate'] ?? 0);
				$pct = (string)(int)round($rate * 100) . '%';
				$data[] = [
					$this->truncate((string)($bucket['label'] ?? $bucket['id'] ?? '-'), 80),
					$this->fmtNum($bucket['hours'] ?? 0),
					$pct,
					$this->fmtNum($bucket['valued_hours'] ?? 0),
				];
			}
		}
		$this->ensureSpace(70);
		$this->table($headers, $widths, $data);
	}

	private function labelAbsenceStatus(string $status): string
	{
		return match ($status) {
			'pending' => $this->l->t('Pending'),
			'substitute_pending' => $this->l->t('Substitute pending'),
			'substitute_declined' => $this->l->t('Substitute declined'),
			'approved' => $this->l->t('Approved'),
			'rejected' => $this->l->t('Rejected'),
			'cancelled' => $this->l->t('Cancelled'),
			default => $status,
		};
	}

	private function newPage(bool $first): void
	{
		if ($this->buf !== '') {
			$this->pageBuffers[] = $this->buf;
			$this->buf = '';
		}
		$this->y = $first ? (self::PAGE_H - self::MARGIN_TOP_FIRST) : (self::PAGE_H - 56.0);
		if (!$first) {
			$this->text(self::MARGIN_X, $this->y, 2, self::FONT_SECTION, $this->l->t('month_closure_pdf_continuation'));
			$this->y -= self::LINE_SECTION + 8;
		}
	}

	private function flushPage(): void
	{
		if ($this->buf !== '') {
			$this->pageBuffers[] = $this->buf;
			$this->buf = '';
		}
	}

	private function applyPageFooters(): void
	{
		$n = count($this->pageBuffers);
		for ($i = 0; $i < $n; $i++) {
			$footerText = $this->l->t('month_closure_pdf_footer', [
				(string)($i + 1),
				(string)$n,
				$this->footerPeriod,
				$this->footerHashShort,
			]);
			if (mb_strlen($footerText) > 118) {
				$footerText = mb_substr($footerText, 0, 115) . '...';
			}
			$footerStream = $this->escapedStreamText(self::MARGIN_X, self::FOOTER_Y, 1, 8.0, $footerText);
			$this->pageBuffers[$i] .= "\n" . $footerStream;
		}
	}

	private function escapedStreamText(float $x, float $y, int $face, float $size, string $s): string
	{
		$esc = MinimalPdfBuilder::escapePdfText($s);

		return sprintf("BT /F%d %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $face, $size, $x, $y, $esc);
	}

	private function section(string $title): void
	{
		$this->ensureSpace(self::LINE_SECTION + 24);
		$this->text(self::MARGIN_X, $this->y, 2, self::FONT_SECTION, $title);
		// No full-width stroke: horizontal rules were drawn at Y coordinates that could
		// intersect wrapped label/value lines or table rows in some viewers (false "strikethrough").
		$this->y -= self::LINE_SECTION + 12;
	}

	/**
	 * @param list<array{0:string,1:string}> $pairs
	 */
	private function keyValues(array $pairs): void
	{
		$labelX = self::MARGIN_X;
		$valueX = self::MARGIN_X + 168.0;
		$valueW = self::PAGE_W - self::MARGIN_X - $valueX;

		foreach ($pairs as $pair) {
			$lk = (int)max(8, floor(160 / 4.6));
			$vk = (int)max(8, floor($valueW / 4.6));
			$lLines = $this->wrap($pair[0] . ':', $lk);
			$vLines = $this->wrap($pair[1], $vk);
			$n = max(count($lLines), count($vLines));
			$h = $n * self::LINE_BODY + 4;
			$this->ensureSpace($h);
			$top = $this->y;
			for ($i = 0; $i < $n; $i++) {
				$yy = $top - $i * self::LINE_BODY;
				if (isset($lLines[$i])) {
					$this->text($labelX, $yy, 2, self::FONT_BODY, $lLines[$i]);
				}
				if (isset($vLines[$i])) {
					$this->text($valueX, $yy, 1, self::FONT_BODY, $vLines[$i]);
				}
			}
			$this->y -= $n * self::LINE_BODY + 6;
		}
	}

	/**
	 * @param list<string> $headers
	 * @param list<float> $widths
	 * @param list<list<string>> $rows
	 */
	private function table(array $headers, array $widths, array $rows): void
	{
		$xs = [self::MARGIN_X];
		foreach ($widths as $w) {
			$xs[] = $xs[count($xs) - 1] + $w;
		}
		$this->tableRow($xs, $widths, $headers, true);
		// Whitespace under header instead of a ruled line (avoids lines through row text).
		$this->y -= 5;
		foreach ($rows as $r) {
			$this->tableRow($xs, $widths, $r, false);
		}
	}

	/**
	 * @param list<float> $xs
	 * @param list<float> $widths
	 * @param list<string> $cells
	 */
	private function tableRow(array $xs, array $widths, array $cells, bool $header): void
	{
		$f = $header ? 2 : 1;
		$maxLines = 1;
		/** @var list<list<string>> $wrap */
		$wrap = [];
		foreach ($cells as $i => $cell) {
			$mw = (int)max(4, floor(($widths[$i] ?? 30) / 4.6));
			$wrap[$i] = $this->wrap((string)$cell, $mw);
			$maxLines = max($maxLines, count($wrap[$i]));
		}
		$h = $maxLines * self::LINE_BODY + 8;
		$this->ensureSpace($h);
		$top = $this->y;
		for ($ln = 0; $ln < $maxLines; $ln++) {
			$yy = $top - $ln * self::LINE_BODY;
			foreach ($wrap as $i => $lines) {
				if (!isset($lines[$ln])) {
					continue;
				}
				$this->text($xs[$i] + 1, $yy, $f, self::FONT_BODY, $lines[$ln]);
			}
		}
		$this->y -= $maxLines * self::LINE_BODY + 8;
	}

	/**
	 * Short prose only: full canonical JSON is not embedded (avoids unreadable wraps and page breaks mid-dump).
	 */
	private function integrityNote(): void
	{
		$raw = $this->l->t('month_closure_pdf_integrity_note');
		$blocks = preg_split('/\R\s*\R/u', $raw) ?: [];
		foreach ($blocks as $block) {
			$block = trim((string)$block);
			if ($block === '') {
				continue;
			}
			foreach ($this->wrap($block, self::INTEGRITY_WRAP) as $line) {
				$this->ensureSpace(self::LINE_BODY + 2);
				$this->text(self::MARGIN_X, $this->y, 1, self::FONT_BODY, $line);
				$this->y -= self::LINE_BODY;
			}
			$this->y -= 6;
		}
	}

	private function text(float $x, float $y, int $face, float $size, string $s): void
	{
		$this->buf .= $this->escapedStreamText($x, $y, $face, $size, $s);
	}

	private function ensureSpace(float $need): void
	{
		if ($this->y - $need < self::MARGIN_BOTTOM) {
			$this->newPage(false);
		}
	}

	/**
	 * @return list<string>
	 */
	private function wrap(string $text, int $maxChars): array
	{
		$text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
		if ($maxChars < 4) {
			$maxChars = 40;
		}
		if (mb_strlen($text) <= $maxChars) {
			return [$text];
		}
		$words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$out = [];
		$line = '';
		foreach ($words as $w) {
			$trial = $line === '' ? $w : $line . ' ' . $w;
			if (mb_strlen($trial) <= $maxChars) {
				$line = $trial;
			} else {
				if ($line !== '') {
					$out[] = $line;
				}
				if (mb_strlen($w) > $maxChars) {
					for ($o = 0; $o < mb_strlen($w); $o += $maxChars) {
						$out[] = mb_substr($w, $o, $maxChars);
					}
					$line = '';
				} else {
					$line = $w;
				}
			}
		}
		if ($line !== '') {
			$out[] = $line;
		}

		return $out === [] ? [''] : $out;
	}

	private function fmtNum($v): string
	{
		if ($v === null) {
			return '-';
		}

		return is_float($v) || is_int($v) ? (string)round((float)$v, 2) : (string)$v;
	}

	/**
	 * Both clock and date helpers consume ATOM strings ({@see DateTimeInterface::ATOM})
	 * produced upstream by `format('c')`. ATOM carries the **storage timezone**
	 * offset, which is the authoritative wall clock for the organisation
	 * (`Constants::CONFIG_APP_TIMEZONE`). For the finalized month-closure PDF
	 * — the legal, immutable record — that is the correct frame of reference;
	 * we never re-render finalized values in a user-specific display TZ.
	 */
	private function entryDateShort($atom): string
	{
		if ($atom === null || $atom === '') {
			return '-';
		}
		try {
			$d = new \DateTimeImmutable((string)$atom);

			return $d->format('d.m.Y');
		} catch (\Throwable $e) {
			return '-';
		}
	}

	private function fmtClock($atom): string
	{
		if ($atom === null || $atom === '') {
			return '-';
		}
		try {
			$d = new \DateTimeImmutable((string)$atom);

			return $d->format('H:i');
		} catch (\Throwable $e) {
			return '-';
		}
	}

	/**
	 * @param array<string, mixed> $e
	 */
	private function pausenCell(array $e): string
	{
		$b = $e['breaks'] ?? null;
		if ($b === null || $b === '') {
			return '-';
		}
		if (is_string($b)) {
			$d = json_decode($b, true);
			$b = is_array($d) ? $d : [];
		}
		if (!is_array($b) || $b === []) {
			return '-';
		}
		$parts = [];
		$idx = 0;
		foreach ($b as $seg) {
			if (!is_array($seg)) {
				continue;
			}
			$idx++;
			$fs = isset($seg['start']) ? $this->fmtClock($seg['start']) : '?';
			$fe = isset($seg['end']) ? $this->fmtClock($seg['end']) : '?';
			$parts[] = $idx . '. ' . $fs . '-' . $fe;
		}

		return $parts === [] ? '-' : implode('; ', $parts);
	}

	private function truncate(string $s, int $maxChars): string
	{
		if (mb_strlen($s) <= $maxChars) {
			return $s;
		}

		return mb_substr($s, 0, $maxChars - 1) . '...';
	}

	/**
	 * @param array<string, mixed> $snap
	 */
	private function assemblePdf(array $snap, string $displayName): string
	{
		$streams = $this->pageBuffers;
		if ($streams === []) {
			$streams = [''];
		}
		$p = count($streams);
		$baseFont = 2 * $p + 3;
		$infoObj = $baseFont + 2;
		$totalObj = $infoObj;

		$infoTitle = $this->l->t('month_closure_pdf_title', [sprintf('%04d-%02d', (int)($snap['year'] ?? 0), (int)($snap['month'] ?? 0))]) . ' - ' . $displayName;
		$infoTitleEsc = MinimalPdfBuilder::escapePdfText($infoTitle);
		$langEsc = MinimalPdfBuilder::escapePdfText($this->pdfLangTag);

		$objects = [];
		$objects[] = '<< /Type /Catalog /Pages 2 0 R /Lang (' . $langEsc . ') >>';
		$kids = [];
		for ($i = 0; $i < $p; $i++) {
			$kids[] = (3 + 2 * $i) . ' 0 R';
		}
		$objects[] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $p . ' >>';

		$res = '<< /Font << /F1 ' . $baseFont . ' 0 R /F2 ' . ($baseFont + 1) . ' 0 R >> >>';
		for ($i = 0; $i < $p; $i++) {
			$contObj = 4 + 2 * $i;
			$len = strlen($streams[$i]);
			$objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents ' . $contObj . ' 0 R /Resources ' . $res . ' >>';
			$objects[] = "<< /Length {$len} >>\nstream\n" . $streams[$i] . "\nendstream";
		}

		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$objects[] = '<< /Title (' . $infoTitleEsc . ') /Creator (ArbeitszeitCheck) /Producer (ArbeitszeitCheck MonthClosure) >>';

		$pdf = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $i => $obj) {
			$offsets[$i + 1] = strlen($pdf);
			$pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
		}
		$xrefPos = strlen($pdf);
		$pdf .= "xref\n0 " . ($totalObj + 1) . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ($n = 1; $n <= $totalObj; $n++) {
			$pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
		}
		$pdf .= "trailer\n<< /Size " . ($totalObj + 1) . " /Root 1 0 R /Info {$infoObj} 0 R >>\n";
		$pdf .= "startxref\n{$xrefPos}\n%%EOF";

		return $pdf;
	}
}
