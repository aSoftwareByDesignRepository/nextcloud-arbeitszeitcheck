<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

final class LegacyBaselineManifest
{
	private const REQUIRED_CATEGORIES = [
		'api_responses',
		'time_account',
		'overtime',
		'compliance',
		'vacation',
		'absences',
		'month_closure',
		'exports_csv',
		'exports_pdf',
		'exports_datev',
		'navigation',
		'capabilities',
		'mobile_bootstrap',
		'mobile_offline_replay',
		'kiosk_bootstrap',
		'kiosk_offline_replay',
		'audit',
		'mail_delivery',
		'ical_delivery',
		'deskcheck_bridge',
		'dutycheck_bridge',
		'backup_restore',
		'query_latency',
	];

	public static function load(string $path): array
	{
		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new \RuntimeException('Legacy baseline manifest is not readable.');
		}
		$manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1) {
			throw new \RuntimeException('Unsupported legacy baseline manifest format.');
		}
		$categories = $manifest['categories'] ?? null;
		if (!is_array($categories) || $categories !== self::REQUIRED_CATEGORIES) {
			throw new \RuntimeException('Legacy baseline manifest must contain every required category in contract order.');
		}
		$endpoints = $manifest['endpoints'] ?? null;
		if (!is_array($endpoints) || $endpoints === []) {
			throw new \RuntimeException('Legacy baseline manifest requires executable endpoint probes.');
		}
		$seen = [];
		$covered = [];
		foreach ($endpoints as $endpoint) {
			$category = $endpoint['category'] ?? null;
			$operation = $endpoint['operation'] ?? null;
			$actor = $endpoint['actor'] ?? null;
			if (!is_string($category) || !in_array($category, self::REQUIRED_CATEGORIES, true) || !is_string($operation) || $operation === '' || !is_string($actor) || $actor === '') {
				throw new \RuntimeException('Legacy baseline endpoint probe is malformed.');
			}
			$key = $category . ':' . json_encode($endpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			if (isset($seen[$key])) {
				throw new \RuntimeException('Duplicate legacy baseline endpoint probe.');
			}
			$seen[$key] = true;
			$covered[$category] = true;
		}
		if (array_diff(self::REQUIRED_CATEGORIES, array_keys($covered)) !== []) {
			throw new \RuntimeException('Legacy baseline category lacks an executable probe.');
		}
		$comparisons = $manifest['comparisons'] ?? null;
		if (!is_array($comparisons) || !isset($comparisons['exclude'], $comparisons['requiredHashPayloads'])) {
			throw new \RuntimeException('Legacy baseline comparison contract is incomplete.');
		}
		$requiredHashes = $comparisons['requiredHashPayloads'];
		$missingHashes = array_diff([
			'api_responses', 'time_account', 'overtime', 'compliance', 'vacation', 'absences',
			'month_closure', 'exports_csv', 'exports_pdf', 'exports_datev',
			'mobile_offline_replay', 'kiosk_offline_replay', 'backup_restore',
		], $requiredHashes);
		if ($missingHashes !== []) {
			throw new \RuntimeException('Legacy baseline omits required hash payloads: ' . implode(', ', $missingHashes));
		}
		return $manifest;
	}
}
