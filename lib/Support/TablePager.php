<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

/**
 * Page window for a table footer: total, page size, and a short page list.
 * Sizes are a fixed set so a query cannot ask for the whole table.
 */
final class TablePager {
	public const DEFAULT_SIZE = 25;

	/** @var list<int> */
	public const SIZES = [10, 25, 50, 100];

	public const ELLIPSIS = 'ellipsis';

	public static function paramName(string $raw, string $fallback): string {
		if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,40}$/', $raw) === 1) {
			return $raw;
		}
		return $fallback;
	}

	public static function perPage(mixed $raw): int {
		if (is_int($raw) && in_array($raw, self::SIZES, true)) {
			return $raw;
		}
		if (is_string($raw) && preg_match('/^(10|25|50|100)$/', $raw) === 1) {
			return (int)$raw;
		}
		return self::DEFAULT_SIZE;
	}

	public static function pageCount(int $total, int $perPage): int {
		if ($total <= 0 || $perPage <= 0) {
			return 1;
		}
		return (int)ceil($total / $perPage);
	}

	public static function page(mixed $raw, int $pageCount): int {
		$pageCount = max(1, $pageCount);
		$page = 1;
		if (is_int($raw) && $raw >= 1) {
			$page = $raw;
		} elseif (is_string($raw) && preg_match('/^[1-9]\d*$/', $raw) === 1) {
			$page = (int)$raw;
		}
		return min($page, $pageCount);
	}

	/**
	 * @return list<int|string> page numbers, or ELLIPSIS
	 */
	public static function items(int $page, int $pages): array {
		$pages = max(1, $pages);
		$page = min(max(1, $page), $pages);
		if ($pages <= 7) {
			return range(1, $pages);
		}
		if ($page <= 4) {
			return [1, 2, 3, 4, self::ELLIPSIS, $pages];
		}
		if ($page >= $pages - 3) {
			return [1, self::ELLIPSIS, $pages - 3, $pages - 2, $pages - 1, $pages];
		}
		return [1, self::ELLIPSIS, $page - 1, $page, $page + 1, self::ELLIPSIS, $pages];
	}

	/**
	 * @param array<string, mixed> $filters
	 * @param list<string> $allowKeys
	 */
	public static function query(
		array $filters,
		int $page,
		int $perPage,
		array $allowKeys = ['start_date', 'end_date', 'type', 'status'],
		string $pageKey = 'page',
		string $sizeKey = 'per_page',
	): string {
		$pageKey = self::paramName($pageKey, 'page');
		$sizeKey = self::paramName($sizeKey, 'per_page');
		$params = [];
		foreach ($allowKeys as $key) {
			if (!is_string($key) || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,40}$/', $key) !== 1) {
				continue;
			}
			if ($key === $pageKey || $key === $sizeKey) {
				continue;
			}
			$value = $filters[$key] ?? null;
			if (!is_string($value) || $value === '' || preg_match('/[\r\n]/', $value) === 1 || strlen($value) > 200) {
				continue;
			}
			$params[$key] = $value;
		}
		if ($perPage !== self::DEFAULT_SIZE) {
			$params[$sizeKey] = (string)$perPage;
		}
		if ($page > 1) {
			$params[$pageKey] = (string)$page;
		}
		return http_build_query($params);
	}

	/**
	 * @return array{total: int, page: int, perPage: int, pages: int, offset: int, items: list<int|string>}
	 */
	public static function state(int $total, mixed $pageRaw, mixed $perPageRaw): array {
		$total = max(0, $total);
		$perPage = self::perPage($perPageRaw);
		$pages = self::pageCount($total, $perPage);
		$page = self::page($pageRaw, $pages);
		return [
			'total' => $total,
			'page' => $page,
			'perPage' => $perPage,
			'pages' => $pages,
			'offset' => ($page - 1) * $perPage,
			'items' => self::items($page, $pages),
		];
	}
}
