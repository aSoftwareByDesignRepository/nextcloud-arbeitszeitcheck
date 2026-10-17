<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

final class LegacyRuntimeProfileMap
{
	private array $profiles = [];
	private array $byTag = [];

	private function __construct(array $profiles)
	{
		$this->profiles = $profiles;
		foreach ($profiles as $profile) {
			foreach ($profile['tags'] as $tag) {
				$this->byTag[$tag] = $profile;
			}
		}
	}

	public static function load(string $path, array $matrix): self
	{
		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new \RuntimeException('Legacy runtime profile map is not readable.');
		}
		$map = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($map) || ($map['format'] ?? null) !== 1 || !is_array($map['profiles'] ?? null) || $map['profiles'] === []) {
			throw new \RuntimeException('Unsupported legacy runtime profile map format.');
		}
		$targetMin = (int)($map['targetNextcloud']['min'] ?? 0);
		$targetMax = (int)($map['targetNextcloud']['max'] ?? 0);
		if ($targetMin <= 0 || $targetMax < $targetMin) {
			throw new \RuntimeException('Legacy runtime profile map has an invalid target Nextcloud range.');
		}
		$keys = [];
		$covered = [];
		foreach ($map['profiles'] as $profile) {
			$key = $profile['key'] ?? null;
			$sourceMin = (int)($profile['sourceMin'] ?? 0);
			$sourceMax = (int)($profile['sourceMax'] ?? 0);
			$serverVersion = (int)($profile['serverVersion'] ?? 0);
			$serverImage = $profile['serverImage'] ?? null;
			$tags = $profile['tags'] ?? null;
			if (!is_string($key) || isset($keys[$key]) || $sourceMin <= 0 || $sourceMax < $sourceMin || $serverVersion < max($sourceMin, $targetMin) || $serverVersion > min($sourceMax, $targetMax) || !is_string($serverImage) || $serverImage !== 'nextcloud:' . $serverVersion || !is_array($tags) || $tags === []) {
				throw new \RuntimeException('Legacy runtime profile is malformed or selects an unsupported server image.');
			}
			$keys[$key] = true;
			foreach ($tags as $tag) {
				if (!is_string($tag) || isset($covered[$tag])) {
					throw new \RuntimeException('Legacy runtime profile contains a missing or duplicate source tag.');
				}
				$covered[$tag] = true;
			}
		}
		if (array_keys($covered) !== $matrix['sourceTags']) {
			throw new \RuntimeException('Legacy runtime profile map does not match the authoritative upgrade matrix.');
		}
		return new self($map['profiles']);
	}

	public function profiles(): array
	{
		return $this->profiles;
	}

	public function profileForTag(string $tag): array
	{
		return $this->byTag[$tag] ?? throw new \RuntimeException('No legacy runtime profile for source tag ' . $tag);
	}
}
