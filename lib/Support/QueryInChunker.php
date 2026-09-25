<?php

declare(strict_types=1);

/**
 * Portable IN predicates that stay under Oracle/Nextcloud's 1000-expression limit.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Nextcloud's QueryBuilder logs (and Oracle rejects) any single array parameter with
 * more than 1000 values. Org-wide Outlook iCal feeds can pass up to
 * {@see Constants::ADMIN_EMPLOYEE_FILTER_MAX_SCAN} user IDs into one IN list.
 *
 * This helper splits large lists into OR-combined IN chunks so LIMIT/ORDER BY
 * remain correct in a single SQL statement.
 */
final class QueryInChunker
{
	/** Hard ceiling matching Nextcloud core / Oracle IN list limit. */
	public const MAX_EXPRESSIONS_PER_LIST = 1000;

	/**
	 * Build `field IN (...)` or `(field IN (...) OR field IN (...))` for large sets.
	 *
	 * Empty $values yields a never-matching predicate so callers can safely
	 * attach the expression without a separate empty-list branch for WHERE wiring.
	 *
	 * @param list<string|int>|array<int, string|int> $values
	 * @param int $paramType {@see IQueryBuilder::PARAM_STR_ARRAY} or {@see IQueryBuilder::PARAM_INT_ARRAY}
	 * @return mixed Expression returned by the query builder expr API
	 */
	public static function in(
		IQueryBuilder $qb,
		string $field,
		array $values,
		int $paramType,
		?int $chunkSize = null,
	): mixed {
		$normalized = self::normalizeValues($values);
		if ($normalized === []) {
			return $qb->expr()->eq(
				$qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				$qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			);
		}

		$size = self::resolveChunkSize($chunkSize);
		$parts = [];
		foreach (array_chunk($normalized, $size) as $chunk) {
			$parts[] = $qb->expr()->in(
				$field,
				$qb->createNamedParameter($chunk, $paramType),
			);
		}

		if (count($parts) === 1) {
			return $parts[0];
		}

		return $qb->expr()->orX(...$parts);
	}

	/**
	 * @param list<string|int>|array<int, string|int> $values
	 * @return list<string|int>
	 */
	public static function normalizeValues(array $values): array
	{
		$unique = [];
		foreach ($values as $value) {
			if (is_int($value)) {
				$unique[$value] = $value;
				continue;
			}
			$value = trim((string)$value);
			if ($value === '') {
				continue;
			}
			$unique[$value] = $value;
		}

		return array_values($unique);
	}

	public static function resolveChunkSize(?int $chunkSize): int
	{
		$requested = $chunkSize ?? Constants::BATCH_CHUNK_SIZE;
		if ($requested < 1) {
			$requested = Constants::BATCH_CHUNK_SIZE;
		}

		return min(self::MAX_EXPRESSIONS_PER_LIST, $requested);
	}
}
