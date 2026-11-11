<?php

declare(strict_types=1);

/**
 * Per-app hooks the shared transfer engine calls. An app that does not
 * implement this contract fails the manifest gate — the shared pack cannot
 * guess where each app's settings validators and permission writers live.
 *
 * Deliberate deviations from the spec sketch, both security-hardening:
 *   - writeMirrored() takes the WHOLE mirrored key set, not one key — a
 *     policy like "restricted + allowlists" must land atomically or a
 *     per-key order could briefly open or slam the access door (azc #46 class).
 *   - normalizeValue() exists alongside validateValue() so the value stored
 *     on the target is byte-identical to what the app's own settings API
 *     would have stored (same normaliser, proven by the parity contract test).
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\ConfigTransfer;

interface ConfigTransferHooks
{
	/**
	 * Transfer permission gate (spec AS-02): every transfer endpoint calls
	 * this first — it MUST throw when $uid lacks the app's door-admin role
	 * (export/dry-run/merge import) — the engine additionally requires a
	 * Nextcloud system admin for replace mode itself. The exception type is
	 * the app's choice; the controller collapses ALL failures into the
	 * family's uniform {success:false,error:"NOT_FOUND"} payload
	 * (api-security §1 — no existence oracle).
	 */
	public function requireTransferAdmin(string $uid): void;

	/**
	 * Per-key semantic validation — MUST delegate to the same validators the
	 * app's settings API uses. Returns an error code or null when valid.
	 * Import grants no validation bypass (spec MH-12 / NN-08).
	 */
	public function validateValue(string $key, string $value): ?string;

	/**
	 * Normalise a validated value to the exact form the settings API would
	 * persist. Default apps may `return $value`.
	 */
	public function normalizeValue(string $key, string $value): string;

	/**
	 * Dry-run-only validation of the full mirrored key set (e.g. "restricted
	 * door with empty allowlist is illegal"). Error code or null.
	 *
	 * @param array<string,string> $keyValues mirrored key => value
	 */
	public function validateMirrored(array $keyValues): ?string;

	/**
	 * Write path for `mirrored` keys — e.g. a permission/policy service that
	 * reconciles platform state (the NC app-restriction mirror, role caches).
	 * NEVER raw setAppValue (spec MH-06 / NN-02). Receives ALL mirrored
	 * key=>value pairs from the file in one call — write them atomically.
	 *
	 * @param array<string,string> $keyValues
	 */
	public function writeMirrored(array $keyValues): void;

	/**
	 * Keys whose change counts as "security-relevant" for report
	 * highlighting (access door, allowlists, admin grants, …).
	 *
	 * @return list<string>
	 */
	public function securityRelevantKeys(): array;

	/**
	 * Create a full-instance safety snapshot before a Replace apply wipes
	 * tables (spec FS-2). Delegate to the app's UpgradeBackupService (or
	 * equivalent). Returns the snapshot id or null when the app has none.
	 */
	public function createSafetySnapshot(string $reason): ?string;

	/**
	 * Settings-section slug the report links to for "set it on this
	 * instance" hints (secrets pending, unresolved refs). Null = no link.
	 */
	public function settingsSectionForKey(string $key): ?string;

	/**
	 * Audit seam (spec MH-08): every export/import/apply/reconcile event is
	 * handed to the app's audit facility (actor, action, counts, checksum).
	 * Apps without an audit log delegate to their logger — never silent.
	 *
	 * @param array<string,mixed> $details
	 */
	public function auditEvent(string $action, string $actor, array $details): void;
}
