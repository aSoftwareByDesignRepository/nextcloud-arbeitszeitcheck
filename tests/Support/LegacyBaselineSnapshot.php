<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

final class LegacyBaselineSnapshot
{
	public static function capture(array $manifest, array $results, string $variant): array
	{
		if ($variant === '') {
			throw new \RuntimeException('Legacy baseline capture requires a non-empty variant.');
		}
		$endpoints = $manifest['endpoints'] ?? [];
		if (count($results) !== count($endpoints)) {
			throw new \RuntimeException('Legacy baseline capture requires one result for every manifest probe.');
		}

		$payloads = [];
		foreach ($manifest['categories'] as $category) {
			$payloads[$category] = [];
		}

		foreach ($endpoints as $index => $endpoint) {
			$result = $results[$index] ?? null;
			if (!is_array($result) || !array_key_exists('status', $result) || !array_key_exists('payload', $result)) {
				throw new \RuntimeException('Legacy baseline probe result is malformed.');
			}
			if ($result['status'] !== 200) {
				throw new \RuntimeException('Legacy baseline probe failed and cannot be captured as a passing baseline.');
			}

			$entry = [
				'operation' => $endpoint['operation'],
				'actor' => $endpoint['actor'],
				'parameters' => array_diff_key($endpoint, array_flip(['category', 'operation', 'actor'])),
				'payload' => self::removeExcludedPaths($result['payload'], $manifest['comparisons']['exclude']),
			];
			if ($endpoint['category'] === 'query_latency') {
				if (!isset($result['metrics']) || !is_int($result['metrics']['queryCount'] ?? null) || !is_numeric($result['metrics']['durationMs'] ?? null)) {
					throw new \RuntimeException('Legacy baseline latency probe lacks query and duration metrics.');
				}
				$entry['metrics'] = [
					'queryCount' => $result['metrics']['queryCount'],
					'durationMs' => (float)$result['metrics']['durationMs'],
				];
			}
			$payloads[$endpoint['category']][] = $entry;
		}

		$hashes = [];
		foreach ($payloads as $category => $payload) {
			$hashes[$category] = LegacyBaselineCanonicalizer::hash($payload);
		}

		return [
			'format' => 1,
			'scenario' => $manifest['scenario'],
			'variant' => $variant,
			'payloads' => $payloads,
			'hashes' => $hashes,
		];
	}

	public static function compare(array $expected, array $actual): array
	{
		if (($expected['format'] ?? null) !== 1 || ($actual['format'] ?? null) !== 1 || ($expected['scenario'] ?? null) !== ($actual['scenario'] ?? null) || ($expected['variant'] ?? null) !== ($actual['variant'] ?? null)) {
			throw new \RuntimeException('Legacy baseline snapshots are incompatible.');
		}

		$mismatches = [];
		foreach (array_unique(array_merge(array_keys($expected['payloads'] ?? []), array_keys($actual['payloads'] ?? []))) as $category) {
			$payload = $expected['payloads'][$category] ?? null;
			$actualPayload = $actual['payloads'][$category] ?? null;
			if (!is_array($payload) || !is_array($actualPayload) || LegacyBaselineCanonicalizer::encode($payload) !== LegacyBaselineCanonicalizer::encode($actualPayload)) {
				$mismatches[] = $category;
			}
		}

		return [
			'equal' => $mismatches === [],
			'mismatchedCategories' => $mismatches,
		];
	}

	private static function removeExcludedPaths(mixed $value, array $paths): mixed
	{
		if (!is_array($value)) {
			return $value;
		}
		foreach ($paths as $path) {
			$value = self::removePath($value, explode('.', $path));
		}
		return $value;
	}

	private static function removePath(array $value, array $segments): array
	{
		$head = array_shift($segments);
		foreach ($value as $key => $item) {
			if ((string)$key !== $head) {
				continue;
			}
			if ($segments === []) {
				unset($value[$key]);
			} elseif (is_array($item)) {
				$value[$key] = self::removePath($item, $segments);
			}
		}
		return $value;
	}
}
