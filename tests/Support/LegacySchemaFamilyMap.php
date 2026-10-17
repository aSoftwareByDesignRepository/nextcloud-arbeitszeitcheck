<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

final class LegacySchemaFamilyMap
{
	private array $families = [];
	private array $byTag = [];

	private function __construct(array $families)
	{
		$this->families = $families;
		foreach ($families as $family) {
			foreach ($family['tags'] as $tag) {
				$this->byTag[$tag] = $family;
			}
		}
	}

	public static function load(string $path, array $matrix): self
	{
		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new \RuntimeException('Legacy schema family map is not readable.');
		}
		$map = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($map) || ($map['format'] ?? null) !== 1 || !is_array($map['families'] ?? null) || $map['families'] === []) {
			throw new \RuntimeException('Unsupported legacy schema family map format.');
		}
		$covered = [];
		$previousMax = 0;
		foreach ($map['families'] as $family) {
			$key = $family['key'] ?? null;
			$tags = $family['tags'] ?? null;
			$versions = $family['migrationVersions'] ?? null;
			if (!is_string($key) || !is_array($tags) || $tags === [] || !is_array($versions) || $versions === [] || !is_int($family['minMigration'] ?? null) || !is_int($family['maxMigration'] ?? null)) {
				throw new \RuntimeException('Legacy schema family is malformed.');
			}
			if ($versions !== array_values(array_unique($versions)) || $versions !== self::sorted($versions) || min($versions) !== $family['minMigration'] || max($versions) !== $family['maxMigration'] || $family['maxMigration'] <= $previousMax) {
				throw new \RuntimeException('Legacy schema families are not strictly ordered by shipped migration versions.');
			}
			$previousMax = $family['maxMigration'];
			foreach ($tags as $tag) {
				if (!is_string($tag) || isset($covered[$tag])) {
					throw new \RuntimeException('Legacy schema family contains a missing or duplicate source tag.');
				}
				$covered[$tag] = true;
			}
		}
		if (array_keys($covered) !== $matrix['sourceTags']) {
			throw new \RuntimeException('Legacy schema family map does not match the authoritative upgrade matrix.');
		}
		return new self($map['families']);
	}

	public function families(): array
	{
		return $this->families;
	}

	public function familyForTag(string $tag): array
	{
		return $this->byTag[$tag] ?? throw new \RuntimeException('No legacy schema family for source tag ' . $tag);
	}

	public function capabilitiesForTag(string $tag): array
	{
		$max = $this->familyForTag($tag)['maxMigration'];
		return [
			'teams' => $max >= 1006,
			'absenceSubstitutions' => $max >= 1002,
			'entryBreaksJson' => $max >= 1004,
			'monthClosures' => $max >= 1014,
			'localDatetimeStorage' => $max >= 1015,
			'tariffs' => $max >= 1017,
			'workDaysPerWeek' => $max >= 1019,
			'layeredVacationDefaults' => $max >= 1024,
			'azc2Licensing' => $max >= 1031,
			'vacationDurationHours' => $max >= 1037,
			'mobileKioskIdempotency' => $max >= 1045,
			'personalModels' => $max >= 1047,
			'customAbsenceTypes' => $max >= 1048,
			'workLocation' => $max >= 1049,
			'idempotencyPayloadHash' => $max >= 1050,
		];
	}

	private static function sorted(array $versions): array
	{
		$sorted = $versions;
		sort($sorted, SORT_NUMERIC);
		return $sorted;
	}
}
