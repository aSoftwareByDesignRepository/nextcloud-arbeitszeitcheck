<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

final class LegacyUpgradeMatrix
{
	public static function load(string $path): array
	{
		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new \RuntimeException('Legacy upgrade matrix is not readable.');
		}
		$matrix = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($matrix) || $matrix['format'] !== 1) {
			throw new \RuntimeException('Unsupported legacy upgrade matrix format.');
		}
		if (($matrix['databases'] ?? null) !== ['mysql', 'pgsql']) {
			throw new \RuntimeException('Legacy upgrade matrix must cover MySQL and PostgreSQL.');
		}
		if (($matrix['serverImages'] ?? null) !== ['nextcloud:32', 'nextcloud:35']) {
			throw new \RuntimeException('Legacy upgrade matrix must cover the declared Nextcloud runtime boundaries.');
		}
		$tags = $matrix['sourceTags'] ?? null;
		if (!is_array($tags) || $tags === [] || !array_is_list($tags)) {
			throw new \RuntimeException('Legacy upgrade matrix sourceTags must be a non-empty list.');
		}
		if (count($tags) !== count(array_unique($tags, SORT_STRING))) {
			throw new \RuntimeException('Legacy upgrade matrix contains duplicate source tags.');
		}
		foreach ($tags as $tag) {
			if (!is_string($tag) || preg_match('/^v\d+\.\d+\.\d+$/D', $tag) !== 1) {
				throw new \RuntimeException('Legacy upgrade matrix contains an invalid source tag.');
			}
		}
		$sorted = $tags;
		usort($sorted, 'version_compare');
		if ($sorted !== $tags) {
			throw new \RuntimeException('Legacy upgrade matrix source tags are not semantically sorted.');
		}
		return $matrix;
	}
}
