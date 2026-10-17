<?php

declare(strict_types=1);

/**
 * Server-authoritative work-location catalog (Workforce Policy Suite, Phase 3).
 *
 * Replaces the fixed {@see \OCA\ArbeitszeitCheck\Support\WorkLocation} enum as
 * the authority for which codes may be written to `at_entries.work_location`.
 * The built-in codes keep their constants for BC; custom rows follow the
 * `custom_` prefix convention shared with custom absence types.
 *
 * Degrades to the three built-ins when the catalog table is unavailable
 * (pre-migration installs, CLI/repair contexts, unit tests without a DB) —
 * same fail-safe pattern as {@see \OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog}.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\WorkLocationDefinition;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper;
use OCA\ArbeitszeitCheck\Support\WorkLocation;
use Psr\Log\LoggerInterface;

final class WorkLocationCatalog
{
	public const CATEGORIES = ['office', 'home', 'external', 'mobile', 'travel', 'other'];
	public const COLORS = ['info', 'primary', 'success', 'warning', 'neutral'];
	public const ICONS = [
		'building', 'home', 'car', 'map-pin', 'train', 'plane', 'factory',
		'warehouse', 'laptop', 'globe', 'briefcase', 'store',
	];

	public const LABEL_I18N_MAX_ENTRIES = 12;
	public const LABEL_MAX_LENGTH = 80;
	public const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';
	public const CUSTOM_CODE_PREFIX = 'custom_';

	/**
	 * Seed rows for {@see \OCA\ArbeitszeitCheck\Repair\SeedWorkLocationCatalog}
	 * and the in-memory fallback when the catalog table is unavailable.
	 *
	 * @var list<array{code:string,baseLabel:string,category:string,color:string,icon:string}>
	 */
	public const BUILTIN_SEEDS = [
		['code' => WorkLocation::OFFICE, 'baseLabel' => 'Büro', 'category' => 'office', 'color' => 'primary', 'icon' => 'building'],
		['code' => WorkLocation::HOME, 'baseLabel' => 'Homeoffice', 'category' => 'home', 'color' => 'success', 'icon' => 'home'],
		['code' => WorkLocation::EXTERNAL, 'baseLabel' => 'Außentermin', 'category' => 'external', 'color' => 'warning', 'icon' => 'car'],
	];

	/** @var array<string, WorkLocationDefinition>|null */
	private ?array $cache = null;

	public function __construct(
		private readonly WorkLocationDefinitionMapper $definitionMapper,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * All definitions indexed by code, including inactive rows. Built-in rows
	 * are synthesized in memory when the table is missing or unseeded.
	 *
	 * @return array<string, WorkLocationDefinition>
	 */
	public function all(): array
	{
		if ($this->cache !== null) {
			return $this->cache;
		}
		$rows = [];
		try {
			$rows = $this->definitionMapper->findAllIndexed();
		} catch (\Throwable $e) {
			// Table missing (pre-migration) or DB failure — serve built-ins so
			// the legacy enum behavior is preserved, but keep the failure loud.
			$this->logger->warning(
				'ArbeitszeitCheck: work-location catalog unavailable — serving built-ins only',
				['exception' => $e->getMessage()],
			);
		}
		foreach (self::BUILTIN_SEEDS as $seed) {
			if (!isset($rows[$seed['code']])) {
				$rows[$seed['code']] = $this->synthesizeBuiltIn($seed);
			}
		}
		ksort($rows);
		return $this->cache = $rows;
	}

	/**
	 * @return array<string, WorkLocationDefinition> Active (creatable) rows only.
	 */
	public function active(): array
	{
		return array_filter(
			$this->all(),
			static fn (WorkLocationDefinition $d): bool => (bool)$d->getActive(),
		);
	}

	public function get(string $code): ?WorkLocationDefinition
	{
		return $this->all()[$code] ?? null;
	}

	public function isKnown(string $code): bool
	{
		return isset($this->all()[$code]);
	}

	/**
	 * Creatable for new writes: known and active. Inactive rows stay `isKnown`
	 * so historical entries keep a resolvable label and category.
	 */
	public function isCreatable(string $code): bool
	{
		$def = $this->all()[$code] ?? null;
		return $def !== null && (bool)$def->getActive();
	}

	/**
	 * Catalog-aware replacement for {@see WorkLocation::normalize()}.
	 * `null`/`''` mean "not provided"; anything else must normalize to a
	 * *known* code or throw — callers map that to `WORK_LOCATION_INVALID`.
	 *
	 * @throws \InvalidArgumentException on non-empty unknown/malformed input
	 */
	public function normalize(mixed $raw): ?string
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		if (!is_string($raw)) {
			throw new \InvalidArgumentException('Invalid work location');
		}
		$value = strtolower(trim($raw));
		if ($value === '') {
			return null;
		}
		if (!preg_match(self::CODE_PATTERN, $value) || !$this->isKnown($value)) {
			throw new \InvalidArgumentException('Invalid work location');
		}
		return $value;
	}

	/**
	 * Same as {@see normalize()} but also requires the code to be active —
	 * for mutation boundaries (`WORK_LOCATION_INACTIVE` mapping).
	 *
	 * @throws \InvalidArgumentException
	 */
	public function normalizeCreatable(mixed $raw): string
	{
		$code = $this->normalize($raw);
		if ($code === null || !$this->isCreatable($code)) {
			throw new \InvalidArgumentException('Invalid work location');
		}
		return $code;
	}

	/**
	 * Whether the code parses but resolves to an inactive definition — lets
	 * controllers distinguish 400 INVALID from 400 INACTIVE.
	 */
	public function isInactiveCode(mixed $raw): bool
	{
		if (!is_string($raw)) {
			return false;
		}
		$value = strtolower(trim($raw));
		return $value !== ''
			&& preg_match(self::CODE_PATTERN, $value) === 1
			&& $this->isKnown($value)
			&& !$this->isCreatable($value);
	}

	/**
	 * Unknown-safe display label: stored locale map → base label → raw code.
	 * Never throws — legacy/custom codes on rolled-back or degraded servers
	 * must still render.
	 */
	public function labelFor(string $code, ?string $locale = null): string
	{
		$def = $this->all()[$code] ?? null;
		if ($def === null) {
			return $code;
		}
		if ($locale !== null) {
			$map = self::decodeLabelMap($def->getLabelI18n());
			if (isset($map[$locale])) {
				return $map[$locale];
			}
			// Tolerate region suffixes (`de_DE` → `de`).
			$base = strtolower((string)preg_replace('/[_-].*$/', '', $locale));
			if (isset($map[$base])) {
				return $map[$base];
			}
		}
		$base = (string)$def->getBaseLabel();
		return $base !== '' ? $base : $code;
	}

	public function categoryOf(string $code): ?string
	{
		$def = $this->all()[$code] ?? null;
		$cat = $def !== null ? (string)$def->getReportingCategory() : null;
		return ($cat !== null && in_array($cat, self::CATEGORIES, true)) ? $cat : null;
	}

	/**
	 * Canonical code for an admin-provided custom location: non-built-in codes
	 * get the `custom_` prefix (mirrors `custom_*` absence types), so a
	 * user-created `halberstadt` is stored as `custom_halberstadt`.
	 */
	public static function customCodeFor(string $raw): string
	{
		$value = strtolower(trim($raw));
		if (str_starts_with($value, self::CUSTOM_CODE_PREFIX)) {
			return $value;
		}
		return self::CUSTOM_CODE_PREFIX . $value;
	}

	/**
	 * Validates a raw admin-provided code stem (before prefixing).
	 */
	public static function isValidCodeStem(string $raw): bool
	{
		$value = strtolower(trim($raw));
		return $value !== ''
			&& !in_array($value, WorkLocation::ALL, true)
			&& preg_match(self::CODE_PATTERN, $value) === 1;
	}

	/**
	 * @return array<string, string> locale => label
	 */
	public static function decodeLabelMap(?string $json): array
	{
		if ($json === null || trim($json) === '') {
			return [];
		}
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return [];
		}
		$map = [];
		foreach ($decoded as $locale => $label) {
			if (is_string($locale) && is_string($label) && $label !== '') {
				$map[$locale] = $label;
			}
		}
		return $map;
	}

	/**
	 * Encodes a locale map for `label_i18n`; caps entries/length per spec and
	 * drops malformed keys/values rather than failing the whole write.
	 *
	 * @param array<string, string> $map
	 */
	public static function encodeLabelMap(array $map): ?string
	{
		$clean = [];
		foreach ($map as $locale => $label) {
			if (count($clean) >= self::LABEL_I18N_MAX_ENTRIES) {
				break;
			}
			if (!is_string($locale) || !preg_match('/^[a-z]{2}([_-][a-zA-Z]{2,4})?$/', $locale)) {
				continue;
			}
			$label = trim((string)$label);
			if ($label === '') {
				continue;
			}
			$clean[$locale] = mb_substr($label, 0, self::LABEL_MAX_LENGTH);
		}
		return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
	}

	public function resetCache(): void
	{
		$this->cache = null;
	}

	private function synthesizeBuiltIn(array $seed): WorkLocationDefinition
	{
		$def = new WorkLocationDefinition();
		$def->setCode($seed['code']);
		$def->setBaseLabel($seed['baseLabel']);
		$def->setReportingCategory($seed['category']);
		$def->setColor($seed['color']);
		$def->setIcon($seed['icon']);
		$def->setActive(true);
		$def->setBuiltIn(true);
		$def->setVersion(1);
		return $def;
	}
}
