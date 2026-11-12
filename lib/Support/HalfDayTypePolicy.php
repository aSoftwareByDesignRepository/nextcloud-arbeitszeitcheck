<?php

declare(strict_types=1);

/**
 * Half-day absence type policy (AZC-HALF-DAY-NON-VACATION).
 *
 * Single authority for the org-level `half_day_enabled_types` allowlist:
 * fail-closed config parsing, curated eligibility, save-path normalization
 * and the admin multiselect payload. The enabled list is the source of
 * truth for *new* half-day requests only — stored debits are never
 * re-evaluated against it (the flag gates entry UI, not historical truth).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCP\IConfig;
use OCP\IL10N;

final class HalfDayTypePolicy
{
	/**
	 * Valid id shape for stored type codes (built-ins and `custom_*`).
	 * Deliberately narrower than the catalog so config can never smuggle
	 * control characters into UI output or queries.
	 */
	public const ID_PATTERN = '/^[a-z0-9_]+$/';

	/**
	 * Eligible built-ins (BQ-01 full curated list, incl. legacy types which
	 * only take effect while their legacy-create flag is on).
	 * `overtime_comp` stays full-day-only (own error contract) and
	 * `parental_leave` is a status absence — never offerable.
	 */
	private const ELIGIBLE_BUILTINS = [
		Absence::TYPE_VACATION,
		Absence::TYPE_SICK_LEAVE,
		Absence::TYPE_CHILD_SICK,
		Absence::TYPE_PERSONAL_LEAVE,
		Absence::TYPE_SPECIAL_LEAVE,
		Absence::TYPE_UNPAID_LEAVE,
		Absence::TYPE_HOME_OFFICE,
		Absence::TYPE_BUSINESS_TRIP,
	];

	/** @return list<string> eligible built-in ids (before entitlement filtering). */
	public static function eligibleBuiltins(): array
	{
		return self::ELIGIBLE_BUILTINS;
	}

	/**
	 * Parse the stored JSON allowlist. Fail-closed: absent, empty-string,
	 * invalid JSON, non-list JSON, or any non-string/invalid entry returns
	 * the documented default `['vacation']` — legacy behavior is never lost
	 * to a corrupted config value. A deliberate `[]` is honored so an org
	 * can switch half-day off entirely (vacation may be removed).
	 *
	 * @return list<string>
	 */
	public static function enabledTypesFromRaw(?string $raw): array
	{
		if ($raw === null || trim($raw) === '') {
			return [Absence::TYPE_VACATION];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded) || !array_is_list($decoded)) {
			return [Absence::TYPE_VACATION];
		}
		$out = [];
		foreach ($decoded as $v) {
			if (!is_string($v)) {
				return [Absence::TYPE_VACATION];
			}
			$v = trim($v);
			if ($v === '' || preg_match(self::ID_PATTERN, $v) !== 1) {
				return [Absence::TYPE_VACATION];
			}
			if (!in_array($v, $out, true)) {
				$out[] = $v;
			}
		}
		return $out;
	}

	/** @return list<string> */
	public static function enabledTypesFromConfig(IConfig $config): array
	{
		// No explicit default: enabledTypesFromRaw(null) already resolves the
		// documented ['vacation'] fallback — single authority for the default.
		return self::enabledTypesFromRaw($config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_HALF_DAY_ENABLED_TYPES
		));
	}

	/**
	 * Whether `$type` can ever carry a half-day debit: curated built-in or
	 * an active `custom_*` row. Deactivated customs stay in the stored list
	 * (reactivation must not lose the flag) but have no effect while not
	 * creatable. Entitlement-bound types are handled by the caller's
	 * skip check, not here.
	 */
	public static function isEligibleType(string $type): bool
	{
		if (in_array($type, self::ELIGIBLE_BUILTINS, true)) {
			return true;
		}
		if (str_starts_with($type, 'custom_')) {
			return AbsenceTypeCatalog::isCreatable($type);
		}
		return false;
	}

	/**
	 * The enabled gate for new requests: type ∈ allowlist ∧ type eligible.
	 *
	 * @param list<string> $enabledTypes
	 */
	public static function isEnabledForType(string $type, array $enabledTypes): bool
	{
		return in_array($type, $enabledTypes, true) && self::isEligibleType($type);
	}

	/**
	 * Enabled types whose approved absence credits planned net hours to the
	 * flextime balance — used for the honest UI preview copy ("planned time
	 * is credited at half"); types with a different Soll effect get the
	 * neutral copy instead.
	 *
	 * @param list<string> $enabledTypes
	 * @return list<string>
	 */
	public static function creditEffectTypes(array $enabledTypes): array
	{
		$out = [];
		foreach ($enabledTypes as $type) {
			if (AbsenceTypeCatalog::sollEffect((string)$type) === AbsenceTypeCatalog::SOLL_EFFECT_CREDIT_IST) {
				$out[] = (string)$type;
			}
		}
		return $out;
	}

	/**
	 * Save-path normalization (admin settings POST): accept an array or a
	 * JSON-encoded array, drop non-string/invalid ids, dedupe, keep unknown
	 * or deactivated ids (reactivation must not silently lose the flag) and
	 * return canonical JSON for `appconfig`.
	 *
	 * @param mixed $raw
	 */
	public static function normalizeSaveValue(mixed $raw): string
	{
		$arr = is_array($raw)
			? $raw
			: (is_string($raw) ? json_decode($raw, true) : null);
		if (!is_array($arr)) {
			$arr = [];
		}
		$out = [];
		foreach ($arr as $v) {
			if (!is_string($v)) {
				continue;
			}
			$v = trim($v);
			if ($v === '' || preg_match(self::ID_PATTERN, $v) !== 1) {
				continue;
			}
			if (!in_array($v, $out, true)) {
				$out[] = $v;
			}
		}
		return json_encode($out);
	}

	/**
	 * Options for the admin multiselect, grouped for rendering.
	 * Built-ins follow the curated list; every *active* custom row is
	 * offered (deactivated rows are render-filtered). Types with an active
	 * days-unit entitlement binding are excluded — their {0.25,0.5,0.75,1}
	 * path lives in the entitlement UI, not here.
	 *
	 * @param list<string> $entitlementBoundCodes
	 * @return array{builtin: list<array{id: string, label: string}>, custom: list<array{id: string, label: string}>}
	 */
	public static function adminOptions(IL10N $l, array $entitlementBoundCodes = []): array
	{
		$labels = AbsenceTypeCatalog::labels($l);
		$builtin = [];
		foreach (self::ELIGIBLE_BUILTINS as $type) {
			if (in_array($type, $entitlementBoundCodes, true)) {
				continue;
			}
			$builtin[] = ['id' => $type, 'label' => (string)($labels[$type] ?? $type)];
		}
		$custom = [];
		foreach ($labels as $code => $label) {
			if (!str_starts_with((string)$code, 'custom_')) {
				continue;
			}
			if (!AbsenceTypeCatalog::isCreatable((string)$code)) {
				continue;
			}
			if (in_array((string)$code, $entitlementBoundCodes, true)) {
				continue;
			}
			$custom[] = ['id' => (string)$code, 'label' => (string)$label];
		}
		return ['builtin' => $builtin, 'custom' => $custom];
	}

	/**
	 * Active days-unit entitlement bindings — these types own their own
	 * fraction contract ({0.25,0.5,0.75,1} via the entitlement ledger) and
	 * must bypass the generic opt-in gate entirely (EC-14).
	 *
	 * @return list<string>
	 */
	public static function entitlementBoundDaysTypeCodes(): array
	{
		try {
			$svc = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\SpecialEntitlementService::class);
			if (!$svc->isEnabled()) {
				return [];
			}
			return $svc->daysUnitBoundAbsenceTypeCodes();
		} catch (\Throwable) {
			return [];
		}
	}
}
