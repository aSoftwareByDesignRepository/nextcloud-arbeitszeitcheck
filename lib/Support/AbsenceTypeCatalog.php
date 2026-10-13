<?php

declare(strict_types=1);

/**
 * Single source of truth for absence types.
 *
 * The type set used to be duplicated across ~15 lists/maps (validation, labels,
 * mail/iCal/PDF builders, admin settings, statistics). Every consumer must read
 * the enumeration and the per-type metadata from here so adding a type can no
 * longer silently miss a call site.
 *
 * Two kinds of types compose here:
 *
 * - **Built-ins** ({@see self::ORDER}): code-level semantics (crediting, guards,
 *   stats grouping) and labels translated through the static l10n catalogs.
 * - **Custom types** (`custom_*` rows in `at_custom_absence`): administrator-
 *   defined "weitere betriebliche Fehlzeiten". Labels come from the DB row
 *   (base `label` + optional per-locale `label_i18n` map); semantics are
 *   data-driven (`soll_credit`, `max_days`). Inactive rows stay in `all()`/
 *   `labels()` so historical absences keep a resolvable label, but are
 *   excluded from `creatable*()` so no new request can use them.
 *
 * The custom-type lookup is lazily loaded and request-cached via
 * {@see Server::get()} (same pattern as `AbsenceService`). Any failure —
 * pre-migration installs, CLI repair contexts, unit tests without a DB —
 * degrades to built-ins only. Admin write paths must call
 * {@see resetCustomTypeCache()} after committing.
 *
 * Semantics live elsewhere by design: which types receive the opt-in planned-hours
 * credit is owned by {@see \OCA\ArbeitszeitCheck\Service\PaidAbsencePlannedHoursCreditService}
 * (built-ins) plus each custom row's `soll_credit` flag, and `overtime_comp`
 * deliberately stays outside that list — its Soll gap is the overtime-balance
 * debit ("Freizeitausgleich").
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\CustomAbsenceType;
use OCA\ArbeitszeitCheck\Db\CustomAbsenceTypeMapper;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Server;

final class AbsenceTypeCatalog
{
	/**
	 * Canonical enumeration order — also the UI dropdown order.
	 * `child_sick` sits next to `sick_leave` (same family); `overtime_comp` is
	 * appended after the historical types so existing option order is untouched.
	 * Active custom types render after the built-ins, ordered by code.
	 *
	 * @var list<string>
	 */
	public const ORDER = [
		Absence::TYPE_VACATION,
		Absence::TYPE_SICK_LEAVE,
		Absence::TYPE_CHILD_SICK,
		Absence::TYPE_PERSONAL_LEAVE,
		Absence::TYPE_PARENTAL_LEAVE,
		Absence::TYPE_SPECIAL_LEAVE,
		Absence::TYPE_UNPAID_LEAVE,
		Absence::TYPE_HOME_OFFICE,
		Absence::TYPE_BUSINESS_TRIP,
		Absence::TYPE_OVERTIME_COMP,
	];

	/**
	 * Per-type request-length caps in calendar days.
	 * Missing/null entries fall back to {@see Constants::MAX_ABSENCE_DAYS}.
	 *
	 * @var array<string, int>
	 */
	private const MAX_DAYS = [
		Absence::TYPE_VACATION => 30,
		Absence::TYPE_PERSONAL_LEAVE => 5,
		Absence::TYPE_PARENTAL_LEAVE => 1095, // ~3 years
		Absence::TYPE_SPECIAL_LEAVE => 30,
		Absence::TYPE_OVERTIME_COMP => 30,
	];

	/**
	 * Built-in calendar color families — kept in sync with the JS
	 * `getAbsenceTypeModifierClass` switch in arbeitszeitcheck-main.js.
	 *
	 * @var array<string, string>
	 */
	private const BUILTIN_COLORS = [
		Absence::TYPE_VACATION => 'success',
		Absence::TYPE_SICK_LEAVE => 'error',
		Absence::TYPE_CHILD_SICK => 'info',
		Absence::TYPE_PERSONAL_LEAVE => 'warning',
		Absence::TYPE_PARENTAL_LEAVE => 'warning',
		Absence::TYPE_SPECIAL_LEAVE => 'warning',
		Absence::TYPE_UNPAID_LEAVE => 'warning',
		Absence::TYPE_HOME_OFFICE => 'info',
		Absence::TYPE_BUSINESS_TRIP => 'primary',
		Absence::TYPE_OVERTIME_COMP => 'primary-light',
	];

	/**
	 * Built-in calendar icon names — kept in sync with the JS
	 * `getAbsenceTypeIcon` switch in arbeitszeitcheck-main.js.
	 *
	 * @var array<string, string>
	 */
	private const BUILTIN_ICONS = [
		// Mirrors getAbsenceIconName() in arbeitszeitcheck-main.js exactly —
		// built-ins without an explicit JS branch fall back to 'calendar-off'.
		Absence::TYPE_VACATION => 'calendar-heart',
		Absence::TYPE_SICK_LEAVE => 'circle-alert',
		Absence::TYPE_CHILD_SICK => 'shield-check',
		Absence::TYPE_PERSONAL_LEAVE => 'calendar-off',
		Absence::TYPE_PARENTAL_LEAVE => 'calendar-off',
		Absence::TYPE_SPECIAL_LEAVE => 'calendar-off',
		Absence::TYPE_UNPAID_LEAVE => 'calendar-off',
		Absence::TYPE_HOME_OFFICE => 'users',
		Absence::TYPE_BUSINESS_TRIP => 'activity',
		Absence::TYPE_OVERTIME_COMP => 'coffee',
	];

	/** @var array<string, CustomAbsenceType>|null Request-scoped cache, code => row (incl. inactive). */
	private static ?array $customCache = null;

	private static bool $customLoaded = false;

	/** @return list<string> All valid type keys: built-ins, then every custom code. */
	public static function all(): array
	{
		return array_merge(self::ORDER, array_keys(self::customTypes()));
	}

	/**
	 * Valid for *display/validation of existing records*: built-in or an
	 * existing custom row (even when deactivated — history must keep working).
	 */
	public static function isValid(string $type): bool
	{
		return in_array($type, self::ORDER, true) || isset(self::customTypes()[$type]);
	}

	/**
	 * Valid for *new requests*: built-in or an active custom row.
	 */
	public static function isCreatable(string $type): bool
	{
		if (in_array($type, self::ORDER, true)) {
			return true;
		}
		$custom = self::customTypes()[$type] ?? null;
		return $custom !== null && (bool)$custom->getActive();
	}

	public static function isCustom(string $type): bool
	{
		return str_starts_with($type, CustomAbsenceType::CODE_PREFIX);
	}

	/**
	 * Localized display label (canonical Title-Case variant used by selects,
	 * badges, mails, iCal and PDFs).
	 */
	public static function label(IL10N $l, string $type): string
	{
		// Unknown/legacy values fall back to the raw code — the pinned contract
		// of every former `?? $type` map (SubstituteControllerTest). Sensitive
		// masking (sick types → "Absence" in the iCal feed) is done explicitly
		// at the call site, not by this helper.
		return self::labels($l)[$type] ?? $type;
	}

	/**
	 * Localized label for a single type, resolving custom labels against the
	 * current user's language. Falls back to the raw code for unknown values.
	 */
	public static function labelForUser(IL10N $l, string $type): string
	{
		return self::labels($l, self::userLocale())[$type] ?? $type;
	}

	/**
	 * Self-contained display label for API payloads (entity summaries have no
	 * IL10N). Built-ins translate through the requester's language; custom
	 * types resolve via their stored locale map. Degrades to the raw code.
	 */
	public static function resolvedLabel(string $type): string
	{
		if (isset(self::customTypes()[$type])) {
			return self::customTypes()[$type]->resolveLabel(self::userLocale());
		}
		try {
			$l = Server::get(IFactory::class)->get(Application::APP_ID);
			$map = self::labels($l, self::userLocale());
			return $map[$type] ?? $type;
		} catch (\Throwable $e) {
			return $type;
		}
	}

	/** @return array<string, string> Ordered map type => localized label, incl. all custom rows. */
	public static function labels(IL10N $l, ?string $locale = null): array
	{
		$locale = $locale ?? self::userLocale();
		$labels = [
			Absence::TYPE_VACATION => $l->t('Vacation'),
			Absence::TYPE_SICK_LEAVE => $l->t('Sick Leave'),
			Absence::TYPE_CHILD_SICK => $l->t('Sick Child'),
			Absence::TYPE_PERSONAL_LEAVE => $l->t('Personal Leave'),
			Absence::TYPE_PARENTAL_LEAVE => $l->t('Parental Leave'),
			Absence::TYPE_SPECIAL_LEAVE => $l->t('Special Leave'),
			Absence::TYPE_UNPAID_LEAVE => $l->t('Unpaid Leave'),
			Absence::TYPE_HOME_OFFICE => $l->t('Home Office'),
			Absence::TYPE_BUSINESS_TRIP => $l->t('Business Trip'),
			Absence::TYPE_OVERTIME_COMP => $l->t('Compensatory Time Off'),
		];
		foreach (self::customTypes() as $code => $custom) {
			$labels[$code] = $custom->resolveLabel($locale);
		}
		return $labels;
	}

	/** @return array<string, string> Ordered map type => localized label for creation selects (active custom only). */
	public static function creatableLabels(IL10N $l, ?string $locale = null): array
	{
		$labels = self::labels($l, $locale);
		foreach (self::customTypes() as $code => $custom) {
			if (!(bool)$custom->getActive()) {
				unset($labels[$code]);
			}
		}
		return $labels;
	}

	/**
	 * Maximum calendar days allowed for one request of this type.
	 */
	public static function maxCalendarDays(string $type): int
	{
		if (isset(self::MAX_DAYS[$type])) {
			return self::MAX_DAYS[$type];
		}
		$custom = self::customTypes()[$type] ?? null;
		if ($custom !== null && $custom->getMaxDays() > 0) {
			return $custom->getMaxDays();
		}
		return Constants::MAX_ABSENCE_DAYS;
	}

	/**
	 * Whether the type joins the opt-in planned-hours Soll credit when the
	 * feature is enabled. Built-in creditable types are fixed; custom types
	 * follow their `soll_credit` flag.
	 */
	public static function isSollCreditable(string $type): bool
	{
		if (in_array($type, [Absence::TYPE_SICK_LEAVE, Absence::TYPE_CHILD_SICK], true)) {
			return true;
		}
		$custom = self::customTypes()[$type] ?? null;
		return $custom !== null && (bool)$custom->getSollCredit();
	}

	/**
	 * Calendar color family (maps to the chip CSS classes).
	 */
	public static function color(string $type): string
	{
		if (isset(self::BUILTIN_COLORS[$type])) {
			return self::BUILTIN_COLORS[$type];
		}
		$custom = self::customTypes()[$type] ?? null;
		$color = $custom !== null ? (string)$custom->getColor() : 'warning';
		return in_array($color, CustomAbsenceType::COLORS, true) ? $color : 'warning';
	}

	/**
	 * Calendar icon name.
	 */
	public static function icon(string $type): string
	{
		if (isset(self::BUILTIN_ICONS[$type])) {
			return self::BUILTIN_ICONS[$type];
		}
		$custom = self::customTypes()[$type] ?? null;
		$icon = $custom !== null ? (string)$custom->getIcon() : 'calendar';
		return in_array($icon, CustomAbsenceType::ICONS, true) ? $icon : 'calendar';
	}

	/**
	 * Types counted under "other absences" in user statistics
	 * (everything except vacation and sick_leave, which have own counters).
	 *
	 * @return list<string>
	 */
	public static function statsOtherTypes(): array
	{
		return array_values(array_filter(
			self::all(),
			static fn (string $t): bool => $t !== Absence::TYPE_VACATION && $t !== Absence::TYPE_SICK_LEAVE,
		));
	}

	/**
	 * Invalidates the request-scoped custom-type cache. Must be called by the
	 * admin write path after create/update/deactivate commits, and by tests.
	 */
	public static function resetCustomTypeCache(): void
	{
		self::$customLoaded = false;
		self::$customCache = null;
	}

	/**
	 * @return array<string, CustomAbsenceType> code => row, all custom types incl. inactive.
	 */
	private static function customTypes(): array
	{
		if (self::$customLoaded) {
			return self::$customCache ?? [];
		}
		self::$customLoaded = true;
		self::$customCache = [];
		try {
			$mapper = Server::get(CustomAbsenceTypeMapper::class);
			foreach ($mapper->findAll() as $row) {
				self::$customCache[$row->getCode()] = $row;
			}
		} catch (\Throwable $e) {
			// Table missing (pre-migration), CLI/repair context, or unit tests
			// without a DB — degrade to built-ins only, but leave a trace so a
			// real DB failure after the migration is not invisible.
			self::$customCache = [];
			try {
				Server::get(\Psr\Log\LoggerInterface::class)->warning(
					'ArbeitszeitCheck: custom absence types unavailable — serving built-ins only',
					['exception' => $e->getMessage()],
				);
			} catch (\Throwable) {
				// Logger unavailable in this bootstrap context — nothing more to do.
			}
		}
		return self::$customCache;
	}

	private static function userLocale(): ?string
	{
		try {
			return Server::get(IFactory::class)->getUserLanguage();
		} catch (\Throwable $e) {
			return null;
		}
	}
}
