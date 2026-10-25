<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Migration;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Repair\SchemaTableCatalog;
use OCA\ArbeitszeitCheck\Repair\UninstallDropTables;

/**
 * Active schema tables required for a healthy install (excludes legacy tables dropped by later migrations).
 *
 * Both lists delegate to the generated {@see SchemaTableCatalog} — the
 * single source of truth refreshed by `sync-uninstall`. Referencing a
 * dedicated new class (rather than a member added to a pre-existing one)
 * keeps upgrade-path code safe when the old release is already loaded.
 * {@see UninstallDropTables::TABLES} still lists every table ever created
 * for complete uninstall cleanup.
 */
final class ArbeitszeitCheckTableCatalog
{
	public const APP_ID = Application::APP_ID;

	/** Tables removed intentionally by {@see Version1012Date20260406120000} — must not block upgrades. */
	private const LEGACY_DROPPED_TABLES = SchemaTableCatalog::DROPPED_LEGACY_TABLES;

	/** @var list<string> */
	public const TABLES = SchemaTableCatalog::EXPECTED_TABLES;

	public static function isLegacyDroppedTable(string $table): bool
	{
		return in_array($table, self::LEGACY_DROPPED_TABLES, true);
	}
}
